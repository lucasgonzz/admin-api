<?php

namespace App\Services;

use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use phpseclib3\Net\SSH2;

/**
 * Servicio para leer y escribir variables .env de un cliente vía SSH.
 *
 * La conexión se abre POR ClientApi, no una sola vez en el constructor: el servidor y la credencial
 * dependen del hosting_type de esa API, y un lote de clientes puede mezclar hosting compartido con
 * VPS. La convención de rutas y el tipo de credencial NO se resuelven acá: los resuelve
 * ClientApiPathResolver, que es el único lugar donde vive esa regla y del que ya dependen
 * DeploymentService y AfipCertificadosClientesCommand.
 *
 * Historia del bug que motivó este diseño (22/8/2026): el constructor cargaba fijo la credencial
 * 'shared_hosting' y get_api_path() hardcodeaba el prefijo de Hostinger, ignorando hosting_type y
 * vps_path. Contra un cliente de VPS eso no fallaba: escribía en un path inexistente del servidor
 * compartido, write_env_vars() lo creaba con `echo >>` y el endpoint devolvía éxito. Por eso ahora
 * escribir sobre un .env que no existe es una excepción, nunca un archivo nuevo.
 */
class EnvSshService
{
    /**
     * Resuelve path raíz y tipo de credencial de cada ClientApi según su hosting_type.
     *
     * @var ClientApiPathResolver
     */
    private $path_resolver;

    /**
     * Sesión SSH activa (phpseclib), o null si no hay ninguna abierta.
     *
     * @var SSH2|null
     */
    private $ssh;

    /**
     * Tipo de credencial de la sesión abierta ('shared_hosting' | 'vps'), o null si no hay sesión.
     *
     * Se guarda para no reconectar cuando el próximo cliente del lote vive en el mismo servidor.
     *
     * @var string|null
     */
    private $connected_credential_type;

    /**
     * Instancia el resolver de rutas. No abre ninguna conexión ni carga credenciales todavía.
     */
    public function __construct()
    {
        $this->path_resolver = new ClientApiPathResolver();
    }

    /**
     * Abre una sesión SSH al servidor donde vive esa ClientApi, según su hosting_type.
     *
     * Si ya hay una sesión abierta contra el mismo tipo de servidor, la reutiliza: un lote de
     * clientes del mismo hosting se resuelve con una sola conexión.
     *
     * @param  ClientApi  $client_api
     * @return void
     * @throws \RuntimeException Si no hay credencial cargada para ese tipo o si son rechazadas.
     */
    public function connect_for(ClientApi $client_api): void
    {
        $this->connect_to($this->path_resolver->credential_type($client_api));
    }

    /**
     * Abre una sesión SSH contra un tipo de servidor, sin pasar por una ClientApi.
     *
     * Es la puerta para los flujos que traen su propio path y no lo derivan de una ClientApi —
     * InstallationService y EcommerceInstallationService escriben el .env de un sistema que se está
     * instalando, con rutas que resuelven ellos. Se expone explícito a propósito: antes la
     * credencial se cargaba fija en el constructor y ningún llamador declaraba contra qué servidor
     * estaba trabajando, que es como el flujo de VPS terminó escribiendo en el hosting compartido.
     *
     * @param  string  $credential_type  'shared_hosting' | 'vps'
     * @return void
     * @throws \RuntimeException Si no hay credencial cargada para ese tipo o si son rechazadas.
     */
    public function connect_to(string $credential_type): void
    {
        /* Sesión ya abierta contra el mismo servidor: se reutiliza. */
        if ($this->ssh !== null && $this->connected_credential_type === $credential_type) {
            return;
        }

        /* Cambió el tipo de servidor (o no había sesión): se cierra la anterior y se abre la nueva. */
        $this->disconnect();

        $credential = ClientSshCredential::where('type', $credential_type)->first();

        if ($credential === null) {
            throw new \RuntimeException(
                "No hay credencial SSH cargada para hosting '{$credential_type}'. "
                . 'Completala en el admin antes de operar el .env de este cliente.'
            );
        }

        $ssh = new SSH2($credential->host, (int) $credential->port);

        $logged_in = $ssh->login($credential->username, $credential->password);

        if (! $logged_in) {
            throw new \RuntimeException(
                "No se pudo conectar por SSH a '{$credential_type}': credenciales rechazadas."
            );
        }

        $this->ssh                       = $ssh;
        $this->connected_credential_type = $credential_type;
    }

    /**
     * Cierra la sesión SSH si está abierta.
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }

        $this->connected_credential_type = null;
    }

    /**
     * Devuelve el directorio raíz de la API del cliente en su servidor.
     *
     * Delega en ClientApiPathResolver: shared_hosting lleva el prefijo de Hostinger, vps es un path
     * absoluto bajo /home/api-{vps_path}/. Se expone público porque los endpoints lo usan para
     * reportar dónde estuvieron mirando cuando algo falla.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si es un VPS sin vps_path configurado.
     */
    public function get_api_path(ClientApi $client_api): string
    {
        return $this->path_resolver->resolve($client_api);
    }

    /**
     * Lee y parsea el .env de esa ClientApi.
     *
     * @param  ClientApi  $client_api
     * @return array<string, string>  KEY => valor, sin comillas envolventes.
     * @throws \RuntimeException Si el .env no existe o no puede leerse.
     */
    public function read_env_for(ClientApi $client_api): array
    {
        return $this->parse_env($this->read_env_raw_for($client_api));
    }

    /**
     * Lee el contenido crudo del .env de esa ClientApi, tal cual está en el servidor.
     *
     * Se expone aparte de read_env_for() porque el contenido crudo es lo que se hashea para detectar
     * si el archivo cambió entre una previsualización y su aplicación. Hashear el array parseado no
     * serviría: dos archivos distintos (comentarios, orden, comillas) parsean igual.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si el .env no existe o no puede leerse.
     */
    public function read_env_raw_for(ClientApi $client_api): string
    {
        $this->connect_for($client_api);

        return $this->read_env_raw($this->path_resolver->resolve($client_api));
    }

    /**
     * Lee y parsea el .env que está en un path dado, sobre la conexión ya abierta.
     *
     * @param  string  $api_path  Directorio raíz de la API en el servidor.
     * @return array<string, string>
     * @throws \RuntimeException Si no hay conexión abierta o el .env no existe.
     */
    public function read_env(string $api_path): array
    {
        return $this->parse_env($this->read_env_raw($api_path));
    }

    /**
     * Lee el contenido crudo del .env que está en un path dado, sobre la conexión ya abierta.
     *
     * @param  string  $api_path  Directorio raíz de la API en el servidor.
     * @return string
     * @throws \RuntimeException Si no hay conexión abierta o el .env no existe.
     */
    public function read_env_raw(string $api_path): string
    {
        $this->assert_connected();

        $env_file = $api_path . '/.env';

        $this->assert_env_file_exists($env_file);

        $raw_output = $this->ssh->exec('cat ' . escapeshellarg($env_file));

        if ($raw_output === false) {
            throw new \RuntimeException("No se pudo leer el archivo .env en: {$env_file}");
        }

        return (string) $raw_output;
    }

    /**
     * Hash sha256 del contenido actual del .env de esa ClientApi.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si el .env no existe o no puede leerse.
     */
    public function env_hash_for(ClientApi $client_api): string
    {
        return hash('sha256', $this->read_env_raw_for($client_api));
    }

    /**
     * Copia el .env del cliente a un backup con marca de tiempo, antes de modificarlo.
     *
     * @param  ClientApi  $client_api
     * @param  string     $timestamp  Marca AAAAMMDDHHMM que nombra el backup.
     * @return string  Path del backup creado.
     * @throws \RuntimeException Si el .env no existe o el backup no queda escrito.
     */
    public function backup_env_for(ClientApi $client_api, string $timestamp): string
    {
        $this->connect_for($client_api);

        $env_file = $this->env_file_path($client_api);

        $this->assert_env_file_exists($env_file);

        $backup_file = $env_file . '.bak-' . $timestamp;

        $this->ssh->exec('cp ' . escapeshellarg($env_file) . ' ' . escapeshellarg($backup_file));

        /*
         * Se verifica que el backup haya quedado escrito. `cp` puede fallar por permisos o disco
         * lleno sin que phpseclib lo reporte, y un backup que no existe es peor que no tenerlo:
         * hace creer que hay a dónde volver.
         */
        if (! $this->file_exists($backup_file)) {
            throw new \RuntimeException("No se pudo crear el backup del .env en: {$backup_file}");
        }

        return $backup_file;
    }

    /**
     * Escribe o actualiza variables en el .env de esa ClientApi.
     *
     * Para cada variable: si la key existe, reemplaza la línea con sed; si no existe, la agrega al
     * final. El archivo tiene que existir de antemano — ver assert_env_file_exists().
     *
     * @param  ClientApi  $client_api
     * @param  array<string, string>  $vars_to_update  KEY => nuevo valor, sin comillas.
     * @return void
     * @throws \RuntimeException Si el .env no existe.
     */
    public function write_env_vars_for(ClientApi $client_api, array $vars_to_update): void
    {
        $this->connect_for($client_api);

        $this->write_env_vars($this->path_resolver->resolve($client_api), $vars_to_update);
    }

    /**
     * Escribe o actualiza variables en el .env que está en un path dado, sobre la conexión abierta.
     *
     * @param  string  $api_path  Directorio raíz de la API en el servidor.
     * @param  array<string, string>  $vars_to_update  KEY => nuevo valor, sin comillas.
     * @return void
     * @throws \RuntimeException Si no hay conexión abierta o el .env no existe.
     */
    public function write_env_vars(string $api_path, array $vars_to_update): void
    {
        $this->assert_connected();

        $env_file = $api_path . '/.env';

        $this->assert_env_file_exists($env_file);

        foreach ($vars_to_update as $key => $value) {
            /*
             * Formatea el valor antes de escribirlo: si contiene espacios u otros caracteres
             * especiales, lo envuelve en comillas dobles. Sin esto, phpdotenv rechaza el archivo
             * completo (ej: MAIL_FROM_NAME=ComercioCity Sistemas → "unexpected whitespace") y
             * cualquier comando artisan del cliente falla.
             */
            $formatted_value = $this->format_env_value((string) $value);

            /* Escapa el valor ya formateado para usarlo como reemplazo en sed. */
            $escaped_value = $this->escape_sed_replacement($formatted_value);

            /*
             * Verifica si la key existe en el .env del cliente.
             * grep -q no produce output pero retorna exit code 0 si existe, 1 si no.
             */
            $grep_cmd    = 'grep -q ' . escapeshellarg('^' . $key . '=') . ' ' . escapeshellarg($env_file) . ' && echo "EXISTS" || echo "NOT_EXISTS"';
            $grep_result = trim((string) $this->ssh->exec($grep_cmd));

            if ($grep_result === 'EXISTS') {
                /* La key existe: reemplaza la línea completa con sed, delimitador | por las rutas. */
                $sed_cmd = 'sed -i ' . escapeshellarg('s|^' . $key . '=.*|' . $key . '=' . $escaped_value . '|') . ' ' . escapeshellarg($env_file);
                $this->ssh->exec($sed_cmd);
            } else {
                /* La key no existe: la agrega al final del archivo (que sí existe, ya se verificó). */
                $append_cmd = 'echo ' . escapeshellarg($key . '=' . $formatted_value) . ' >> ' . escapeshellarg($env_file);
                $this->ssh->exec($append_cmd);
            }
        }
    }

    /**
     * Path completo del archivo .env de esa ClientApi.
     *
     * @param  ClientApi  $client_api
     * @return string
     */
    private function env_file_path(ClientApi $client_api): string
    {
        return $this->path_resolver->resolve($client_api) . '/.env';
    }

    /**
     * Corta la operación si no hay una sesión SSH abierta.
     *
     * Antes el servicio conectaba solo, contra la credencial de hosting compartido que cargaba el
     * constructor. Eso es justamente lo que hacía que un flujo de VPS escribiera en el servidor
     * equivocado sin que nadie lo pidiera. Ahora conectar es explícito: connect_for() cuando hay una
     * ClientApi de por medio, connect_to() cuando el llamador trae su propio path.
     *
     * @return void
     * @throws \RuntimeException Si no hay conexión abierta.
     */
    private function assert_connected(): void
    {
        if ($this->ssh !== null) {
            return;
        }

        throw new \RuntimeException(
            'No hay una sesión SSH abierta. Llamá a connect_for($client_api) o a '
            . 'connect_to($credential_type) antes de leer o escribir un .env.'
        );
    }

    /**
     * Corta la operación si el .env destino no existe en el servidor.
     *
     * 🔴 Esto NO es una validación defensiva de más: es el arreglo del bug del 22/8/2026. Sin este
     * chequeo, write_env_vars() creaba el archivo con `echo KEY=valor >> path/.env`. Combinado con
     * un path mal resuelto, eso dejaba un .env huérfano en el servidor equivocado y devolvía éxito
     * — el peor de los dos mundos: no se aplicó el cambio y quedó basura sin que nadie se entere.
     *
     * @param  string  $env_file
     * @return void
     * @throws \RuntimeException Si el archivo no existe.
     */
    private function assert_env_file_exists(string $env_file): void
    {
        if ($this->file_exists($env_file)) {
            return;
        }

        throw new \RuntimeException(
            "No existe el archivo .env en: {$env_file}. "
            . 'Se aborta sin escribir: crear un .env desde acá dejaría el archivo en el servidor '
            . 'equivocado. Revisá hosting_type, path y vps_path de esta API en el admin.'
        );
    }

    /**
     * Consulta si un archivo existe en el servidor remoto.
     *
     * @param  string  $remote_path
     * @return bool
     */
    private function file_exists(string $remote_path): bool
    {
        $cmd = 'test -f ' . escapeshellarg($remote_path) . ' && echo "EXISTS" || echo "NOT_EXISTS"';

        return trim((string) $this->ssh->exec($cmd)) === 'EXISTS';
    }

    /**
     * Parsea el contenido de un .env en un array asociativo.
     *
     * Ignora líneas vacías y comentarios, y stripea comillas envolventes para normalizar la
     * comparación contra los valores guardados en el admin.
     *
     * @param  string  $raw_content
     * @return array<string, string>
     */
    private function parse_env(string $raw_content): array
    {
        $env_vars = [];

        foreach (explode("\n", $raw_content) as $line) {
            /* Normaliza la línea eliminando espacios y retornos de carro. */
            $trimmed = trim($line);

            /* Ignora líneas vacías y comentarios. */
            if ($trimmed === '' || strncmp($trimmed, '#', 1) === 0) {
                continue;
            }

            /* Solo procesa líneas con el formato KEY=value. */
            $equals_pos = strpos($trimmed, '=');
            if ($equals_pos === false) {
                continue;
            }

            /* Extrae clave y valor; el valor puede contener '=' en el texto. */
            $key   = trim(substr($trimmed, 0, $equals_pos));
            $value = substr($trimmed, $equals_pos + 1);

            $env_vars[$key] = $this->strip_env_quotes($value);
        }

        return $env_vars;
    }

    /**
     * Elimina comillas envolventes simples o dobles de un valor de variable .env.
     *
     * @param  string  $value
     * @return string
     */
    private function strip_env_quotes(string $value): string
    {
        $value = trim($value);

        /* Stripea comillas dobles envolventes. */
        if (strlen($value) >= 2 && strncmp($value, '"', 1) === 0 && substr($value, -1) === '"') {
            return substr($value, 1, -1);
        }

        /* Stripea comillas simples envolventes. */
        if (strlen($value) >= 2 && strncmp($value, "'", 1) === 0 && substr($value, -1) === "'") {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Formatea un valor para que phpdotenv pueda parsear la línea resultante.
     *
     * phpdotenv rechaza el archivo completo si un valor sin comillas contiene espacios. Caso real:
     * MAIL_FROM_NAME=ComercioCity Sistemas rompía todo comando artisan del cliente con
     * "Encountered unexpected whitespace".
     *
     * @param  string  $value
     * @return string
     */
    private function format_env_value(string $value): string
    {
        /* Valor vacío: se escribe vacío, sin comillas. */
        if ($value === '') {
            return '';
        }

        /* Valores simples no necesitan comillas. */
        if (preg_match('/^[A-Za-z0-9_.\-\/:@]+$/', $value) === 1) {
            return $value;
        }

        /* Escapa backslashes primero para no doble-escapar las comillas. */
        $escaped = str_replace('\\', '\\\\', $value);

        /* Escapa comillas dobles internas. */
        $escaped = str_replace('"', '\\"', $escaped);

        return '"' . $escaped . '"';
    }

    /**
     * Escapa un valor para usarlo como reemplazo en sed con delimitador `|`.
     *
     * @param  string  $value
     * @return string
     */
    private function escape_sed_replacement(string $value): string
    {
        /* Primero escapa backslashes para no doble-escapar los demás. */
        $value = str_replace('\\', '\\\\', $value);

        /* Escapa el delimitador | de sed. */
        $value = str_replace('|', '\\|', $value);

        /* Escapa & que en sed replacement significa "el match completo". */
        $value = str_replace('&', '\\&', $value);

        return $value;
    }
}
