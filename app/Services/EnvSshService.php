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
 * escribir sobre un .env que no existe es una excepción, conectar es explícito, y toda escritura
 * se verifica releyendo el archivo.
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
     * ⚠️ Supone que hay UNA credencial por tipo, que es como está modelado hoy
     * (client_ssh_credentials es un catálogo con una fila por tipo). El día que haya dos VPS, esta
     * caché escribiría los .env de todos los clientes de VPS en el mismo servidor. Si se agrega un
     * segundo servidor del mismo tipo, la caché tiene que pasar a ser por credencial, no por tipo.
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
     * Si ya hay una sesión abierta contra el mismo tipo de servidor, la reutiliza.
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
     * @return array<string, string>  KEY => valor, ya desescapado.
     * @throws \RuntimeException Si el .env no existe o no puede leerse.
     */
    public function read_env_for(ClientApi $client_api): array
    {
        return $this->parse_env_content($this->read_env_raw_for($client_api));
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
        return $this->parse_env_content($this->read_env_raw($api_path));
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

        $raw_output = $this->ssh->exec('cat ' . $this->escape_remote_arg($env_file));

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
     * Crea el .env vacío si todavía no existe, para un sistema que se está instalando de cero.
     *
     * 🔴 Es la ÚNICA vía por la que este servicio crea un .env, y existe para el flujo de
     * instalación, donde el archivo legítimamente no está todavía. Se llama explícito y por su
     * nombre; write_env_vars() nunca crea nada. La diferencia importa: el bug del 22/8/2026 fue
     * exactamente una escritura que creaba el archivo en silencio, en el servidor equivocado.
     *
     * Crea el archivo sobre LA MISMA sesión que después va a escribir, que es lo que evita que el
     * touch caiga en un servidor y la escritura en otro.
     *
     * @param  ClientApi  $client_api
     * @return void
     * @throws \RuntimeException Si el archivo no puede crearse.
     */
    public function ensure_env_file_for(ClientApi $client_api): void
    {
        $this->connect_for($client_api);

        $env_file = $this->path_resolver->resolve($client_api) . '/.env';

        if ($this->file_exists($env_file)) {
            return;
        }

        $this->exec_remoto('touch ' . $this->escape_remote_arg($env_file), "crear el .env en {$env_file}");

        if (! $this->file_exists($env_file)) {
            throw new \RuntimeException("No se pudo crear el archivo .env en: {$env_file}");
        }
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

        $env_file = $this->path_resolver->resolve($client_api) . '/.env';

        $this->assert_env_file_exists($env_file);

        $backup_file = $env_file . '.bak-' . $timestamp;

        $this->exec_remoto(
            'cp ' . $this->escape_remote_arg($env_file) . ' ' . $this->escape_remote_arg($backup_file),
            "crear el backup del .env en {$backup_file}"
        );

        /*
         * Se verifica que el backup haya quedado escrito. Un backup que no existe es peor que no
         * tenerlo: hace creer que hay a dónde volver.
         */
        if (! $this->file_exists($backup_file)) {
            throw new \RuntimeException("No se pudo crear el backup del .env en: {$backup_file}");
        }

        return $backup_file;
    }

    /**
     * Escribe o actualiza variables en el .env de esa ClientApi.
     *
     * @param  ClientApi  $client_api
     * @param  array<string, string>  $vars_to_update  KEY => nuevo valor, sin comillas.
     * @return void
     * @throws \RuntimeException Si el .env no existe o la escritura no quedó aplicada.
     */
    public function write_env_vars_for(ClientApi $client_api, array $vars_to_update): void
    {
        $this->connect_for($client_api);

        $this->write_env_vars($this->path_resolver->resolve($client_api), $vars_to_update);
    }

    /**
     * Escribe o actualiza variables en el .env que está en un path dado, sobre la conexión abierta.
     *
     * Para cada variable: si la key existe, reemplaza la línea con sed; si no, la agrega al final.
     * Al terminar RELEE el archivo y verifica que cada variable haya quedado con el valor pedido.
     *
     * 🔴 La verificación final no es un lujo. `sed -i` necesita crear un temporal y renombrar en el
     * directorio: falla por permisos, por cuota de Hostinger agotada o por disco lleno. Sin releer,
     * una escritura fallida se reportaba como aplicada, y el llamador borraba el valor que quería
     * escribir creyendo que ya estaba en el servidor.
     *
     * @param  string  $api_path  Directorio raíz de la API en el servidor.
     * @param  array<string, string>  $vars_to_update  KEY => nuevo valor, sin comillas.
     * @return void
     * @throws \RuntimeException Si no hay conexión, el .env no existe, o la escritura no quedó.
     */
    public function write_env_vars(string $api_path, array $vars_to_update): void
    {
        $this->assert_connected();

        $env_file = $api_path . '/.env';

        $this->assert_env_file_exists($env_file);

        foreach ($vars_to_update as $key => $value) {
            $this->assert_valor_escribible((string) $key, (string) $value);
        }

        foreach ($vars_to_update as $key => $value) {
            /*
             * Formatea el valor para que phpdotenv pueda parsear la línea resultante. Sin esto, un
             * valor con espacios rechaza el archivo COMPLETO (caso real:
             * MAIL_FROM_NAME=ComercioCity Sistemas → "Encountered unexpected whitespace") y hace
             * fallar cualquier comando artisan del cliente.
             */
            $formatted_value = $this->format_env_value((string) $value);

            /*
             * Verifica si la key existe en el .env del cliente.
             * grep -q no produce output pero retorna exit code 0 si existe, 1 si no.
             */
            $grep_cmd    = 'grep -q ' . $this->escape_remote_arg('^' . $key . '=') . ' ' . $this->escape_remote_arg($env_file) . ' && echo "EXISTS" || echo "NOT_EXISTS"';
            $grep_result = trim((string) $this->ssh->exec($grep_cmd));

            if ($grep_result === 'EXISTS') {
                /* La key existe: reemplaza la línea completa con sed, delimitador | por las rutas. */
                $escaped_value = $this->escape_sed_replacement($formatted_value);

                $sed_cmd = 'sed -i ' . $this->escape_remote_arg('s|^' . $key . '=.*|' . $key . '=' . $escaped_value . '|') . ' ' . $this->escape_remote_arg($env_file);

                $this->exec_remoto($sed_cmd, "actualizar {$key} en {$env_file}");
            } else {
                /* La key no existe: la agrega al final del archivo (que sí existe, ya se verificó). */
                $append_cmd = 'printf ' . $this->escape_remote_arg('%s\n') . ' ' . $this->escape_remote_arg($key . '=' . $formatted_value) . ' >> ' . $this->escape_remote_arg($env_file);

                $this->exec_remoto($append_cmd, "agregar {$key} en {$env_file}");
            }
        }

        $this->assert_escritura_aplicada($env_file, $vars_to_update);
    }

    /**
     * Parsea el contenido de un .env en un array asociativo.
     *
     * Es la inversa de format_env_value(): desescapa lo que esa función escapa, así un valor
     * escrito por este servicio se relee idéntico. Sin eso, un valor con comillas o barras nunca
     * volvía a coincidir consigo mismo, la previsualización mostraba un valor con barras que el
     * archivo no tiene, y "este valor ya está puesto" no se detectaba nunca.
     *
     * Se expone público porque EnvBulkChangeService necesita parsear el mismo contenido crudo que
     * hashea, y dos parsers separados para el mismo formato divergen sin que nadie lo note.
     *
     * @param  string  $raw_content
     * @return array<string, string>
     */
    public function parse_env_content(string $raw_content): array
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

            $env_vars[$key] = $this->unformat_env_value($value);
        }

        return $env_vars;
    }

    /**
     * Formatea un valor para escribirlo en el .env sin romper el parseo de phpdotenv.
     *
     * Reglas, derivadas del autómata de Dotenv\Parser\EntryParser (phpdotenv v5):
     * - Comillas simples: todo literal, SIN escapes. No se puede representar un `'` adentro.
     * - Comillas dobles: sólo `\"`, `\\`, `\$` y los escapes de espacio (\n, \t...) son válidos;
     *   cualquier otra secuencia con barra hace fallar el archivo ENTERO. Un `$` sin escapar
     *   interpola otra variable.
     *
     * De ahí las tres ramas:
     * - Valor simple → sin comillas.
     * - Valor sin comilla simple → comillas simples. Es lo más seguro: literal exacto, sin
     *   interpolación de `$` y sin escapes que puedan fallar.
     * - Valor con comilla simple → comillas dobles, escapando sólo `\`, `"` y `$`.
     *
     * @param  string  $value
     * @return string
     */
    public function format_env_value(string $value): string
    {
        /* Valor vacío: se escribe vacío, sin comillas. */
        if ($value === '') {
            return '';
        }

        /* Valores simples no necesitan comillas. */
        if (preg_match('/^[A-Za-z0-9_.\-\/:@]+$/', $value) === 1) {
            return $value;
        }

        /* Sin comillas simples adentro: se envuelve en comillas simples y queda literal. */
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }

        /* Con comillas simples adentro: comillas dobles, escapando lo único que phpdotenv acepta. */
        $escaped = str_replace('\\', '\\\\', $value);
        $escaped = str_replace('"', '\\"', $escaped);
        $escaped = str_replace('$', '\\$', $escaped);

        return '"' . $escaped . '"';
    }

    /**
     * Inversa de format_env_value(): devuelve el valor real a partir de lo escrito en el archivo.
     *
     * @param  string  $raw_value  Lo que está después del `=` en la línea del .env.
     * @return string
     */
    private function unformat_env_value(string $raw_value): string
    {
        $value = trim($raw_value);

        if (strlen($value) < 2) {
            return $value;
        }

        $primera = substr($value, 0, 1);
        $ultima  = substr($value, -1);

        /* Comillas simples: literal, sin ningún escape que deshacer. */
        if ($primera === "'" && $ultima === "'") {
            return substr($value, 1, -1);
        }

        /* Comillas dobles: se deshacen los tres escapes que este servicio produce. */
        if ($primera === '"' && $ultima === '"') {
            $interior = substr($value, 1, -1);

            $interior = str_replace('\\"', '"', $interior);
            $interior = str_replace('\\$', '$', $interior);
            $interior = str_replace('\\\\', '\\', $interior);

            return $interior;
        }

        return $value;
    }

    /**
     * Corta la operación si el valor no se puede escribir como una línea de .env.
     *
     * Una variable de entorno es de una sola línea. Un valor con salto de línea parte el comando
     * de sed en dos y lo hace abortar — y ese es un input perfectamente posible cuando el payload
     * lo arma un modelo transcribiendo lo que alguien dijo en voz alta, o un copy-paste de una API
     * key que se trajo el newline del final.
     *
     * @param  string  $key
     * @param  string  $value
     * @return void
     * @throws \RuntimeException Si el valor tiene saltos de línea.
     */
    private function assert_valor_escribible(string $key, string $value): void
    {
        if (strpos($value, "\n") === false && strpos($value, "\r") === false) {
            return;
        }

        throw new \RuntimeException(
            "El valor de {$key} tiene un salto de línea y una variable de .env es de una sola línea. "
            . 'Se aborta sin escribir.'
        );
    }

    /**
     * Relee el .env y verifica que cada variable haya quedado con el valor que se pidió escribir.
     *
     * @param  string  $env_file
     * @param  array<string, string>  $vars_to_update
     * @return void
     * @throws \RuntimeException Si alguna variable no quedó escrita.
     */
    private function assert_escritura_aplicada(string $env_file, array $vars_to_update): void
    {
        $raw_output = $this->ssh->exec('cat ' . $this->escape_remote_arg($env_file));

        $env_actual = $this->parse_env_content((string) $raw_output);

        $no_aplicadas = [];

        foreach ($vars_to_update as $key => $value) {
            if (! isset($env_actual[$key]) || $env_actual[$key] !== (string) $value) {
                $no_aplicadas[] = $key;
            }
        }

        if (count($no_aplicadas) === 0) {
            return;
        }

        throw new \RuntimeException(
            'La escritura no quedó aplicada en ' . $env_file . ' para: ' . implode(', ', $no_aplicadas)
            . '. Revisá permisos y espacio en el servidor del cliente. El .env quedó como estaba.'
        );
    }

    /**
     * Ejecuta un comando remoto y falla si devuelve un exit status distinto de cero.
     *
     * phpseclib devuelve el stdout del comando; el exit status hay que pedirlo aparte. Sin mirarlo,
     * un `sed` que no pudo escribir por permisos se ve exactamente igual que uno que funcionó.
     *
     * @param  string  $command
     * @param  string  $descripcion  Qué se estaba intentando, para el mensaje de error.
     * @return string  stdout del comando.
     * @throws \RuntimeException Si el comando falla.
     */
    private function exec_remoto(string $command, string $descripcion): string
    {
        $output = $this->ssh->exec($command);

        $exit_status = $this->ssh->getExitStatus();

        /* getExitStatus() devuelve false si el servidor no lo reportó: ahí no se puede afirmar nada. */
        if ($exit_status !== false && $exit_status !== 0) {
            throw new \RuntimeException(
                "Falló al {$descripcion} (exit {$exit_status}): " . trim((string) $output)
            );
        }

        return (string) $output;
    }

    /**
     * Corta la operación si no hay una sesión SSH abierta.
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
     * chequeo, write_env_vars() creaba el archivo. Combinado con un path mal resuelto, eso dejaba
     * un .env huérfano en el servidor equivocado y devolvía éxito — el peor de los dos mundos: no
     * se aplicó el cambio y quedó basura sin que nadie se entere.
     *
     * Para el flujo de instalación, que sí necesita crear el archivo, está ensure_env_file_for().
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
        $cmd = 'test -f ' . $this->escape_remote_arg($remote_path) . ' && echo "EXISTS" || echo "NOT_EXISTS"';

        return trim((string) $this->ssh->exec($cmd)) === 'EXISTS';
    }

    /**
     * Escapa un argumento para el shell del servidor REMOTO, que siempre es POSIX.
     *
     * 🔴 No se usa escapeshellarg(): esa función escapa según el sistema donde corre PHP, no según
     * el del otro lado. En Windows emite comillas DOBLES, y el `sh` remoto expande `$`, backticks y
     * barras adentro de comillas dobles. Como admin-api también corre local sobre WAMP, un valor
     * con `$(...)` aplicado desde ahí se ejecutaría en el servidor del cliente.
     *
     * Comillas simples POSIX: todo literal, y la única comilla simple se cierra, se escapa y se
     * reabre.
     *
     * @param  string  $value
     * @return string
     */
    private function escape_remote_arg(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
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
