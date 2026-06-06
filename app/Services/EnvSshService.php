<?php

namespace App\Services;

use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use phpseclib3\Net\SSH2;

/**
 * Servicio para leer y escribir variables .env de un cliente en hosting compartido vía SSH.
 *
 * Reutiliza ClientSshCredential (tipo shared_hosting) y phpseclib3\Net\SSH2,
 * siguiendo el mismo patrón de conexión que DeploymentService.
 */
class EnvSshService
{
    /**
     * Credenciales SSH de hosting compartido (únicas por sistema admin).
     *
     * @var ClientSshCredential
     */
    private $credential;

    /**
     * Sesión SSH activa (phpseclib). Se instancia al conectar.
     *
     * @var SSH2|null
     */
    private $ssh;

    /**
     * Carga las credenciales shared_hosting.
     * Lanza excepción si no existen (igual que DeploymentService).
     */
    public function __construct()
    {
        $this->credential = ClientSshCredential::where('type', 'shared_hosting')->firstOrFail();
    }

    /**
     * Abre una conexión SSH al servidor de hosting compartido.
     *
     * @return void
     * @throws \RuntimeException Si las credenciales son rechazadas.
     */
    public function connect(): void
    {
        /* Abre nueva sesión SSH usando las credenciales del hosting compartido. */
        $this->ssh = new SSH2($this->credential->host, (int) $this->credential->port);

        /* Intenta autenticar con usuario y contraseña. */
        $logged_in = $this->ssh->login($this->credential->username, $this->credential->password);

        if (! $logged_in) {
            throw new \RuntimeException('No se pudo conectar por SSH: credenciales rechazadas.');
        }
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
    }

    /**
     * Lee y parsea el archivo .env del cliente en el path dado.
     *
     * Conecta por SSH, ejecuta `cat {api_path}/.env` y parsea el resultado línea a línea.
     * Ignora líneas de comentario (#) y líneas vacías.
     * Stripea comillas simples y dobles del valor para normalizar la comparación.
     *
     * @param  string  $api_path  Path absoluto/relativo al directorio raíz de la API del cliente.
     * @return array<string, string>  Array asociativo KEY => value (sin comillas).
     * @throws \RuntimeException Si el archivo no existe o no puede leerse.
     */
    public function read_env(string $api_path): array
    {
        /* Asegura que haya conexión SSH activa. */
        if (! $this->ssh) {
            $this->connect();
        }

        /* Lee el contenido del archivo .env del cliente vía SSH. */
        $raw_output = $this->ssh->exec('cat ' . escapeshellarg($api_path . '/.env'));

        if ($raw_output === false) {
            throw new \RuntimeException("No se pudo leer el archivo .env en: {$api_path}/.env");
        }

        /* Parsea el contenido línea a línea en un array asociativo. */
        $env_vars = [];

        foreach (explode("\n", $raw_output) as $line) {
            /* Normaliza la línea eliminando espacios y retornos de carro. */
            $trimmed = trim($line);

            /* Ignora líneas vacías y comentarios. */
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
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

            /* Stripea comillas dobles y simples envolventes del valor (ej: "valor" → valor). */
            $value = $this->strip_env_quotes($value);

            $env_vars[$key] = $value;
        }

        return $env_vars;
    }

    /**
     * Escribe o actualiza variables en el archivo .env del cliente vía SSH.
     *
     * Para cada variable:
     * - Si la key ya existe en el .env: usa sed para reemplazar la línea.
     * - Si la key no existe: la agrega al final del archivo.
     *
     * El delimitador de sed es `|` para evitar conflictos con `/` en rutas.
     * El valor se escapa para evitar que caracteres especiales rompan el comando sed.
     *
     * @param  string  $api_path       Path absoluto/relativo al directorio raíz de la API.
     * @param  array<string, string>  $vars_to_update  Array KEY => nuevo_valor (sin comillas).
     * @return void
     * @throws \RuntimeException Si hay error en la ejecución SSH.
     */
    public function write_env_vars(string $api_path, array $vars_to_update): void
    {
        /* Asegura que haya conexión SSH activa. */
        if (! $this->ssh) {
            $this->connect();
        }

        /* Path completo del archivo .env del cliente. */
        $env_file = $api_path . '/.env';

        foreach ($vars_to_update as $key => $value) {
            /* Escapa el valor nuevo para usarlo de forma segura como reemplazo en sed. */
            $escaped_value = $this->escape_sed_replacement($value);

            /*
             * Verifica si la key existe en el .env del cliente.
             * grep -q no produce output pero retorna exit code 0 si existe, 1 si no.
             */
            $grep_cmd    = 'grep -q ' . escapeshellarg('^' . $key . '=') . ' ' . escapeshellarg($env_file) . ' && echo "EXISTS" || echo "NOT_EXISTS"';
            $grep_result = trim((string) $this->ssh->exec($grep_cmd));

            if ($grep_result === 'EXISTS') {
                /*
                 * La key existe: reemplaza la línea completa usando sed con delimitador |.
                 * Formato: sed -i "s|^KEY=.*|KEY=nuevo_valor|" /path/.env
                 */
                $sed_cmd = 'sed -i ' . escapeshellarg('s|^' . $key . '=.*|' . $key . '=' . $escaped_value . '|') . ' ' . escapeshellarg($env_file);
                $this->ssh->exec($sed_cmd);
            } else {
                /*
                 * La key no existe: la agrega al final del archivo.
                 * Formato: echo "KEY=valor" >> /path/.env
                 */
                $append_cmd = 'echo ' . escapeshellarg($key . '=' . $value) . ' >> ' . escapeshellarg($env_file);
                $this->ssh->exec($append_cmd);
            }
        }
    }

    /**
     * Devuelve el path absoluto de la API del cliente en el hosting compartido.
     *
     * Replica la misma lógica de DeploymentService::get_api_path() para mantener consistencia:
     * el path del ClientApi es relativo al home (ej: "empresa/api"), y se antepone
     * el prefijo estándar del hosting compartido.
     *
     * @param  ClientApi  $client_api  API del cliente registrada en admin.
     * @return string  Path absoluto en el servidor de hosting.
     */
    public function get_api_path(ClientApi $client_api): string
    {
        /* Prefijo estándar del hosting compartido (igual que DeploymentService). */
        return 'domains/comerciocity.com/public_html/' . $client_api->path;
    }

    /**
     * Elimina comillas envolventes simples o dobles de un valor de variable .env.
     *
     * Ejemplos:
     * - "smtp.gmail.com" → smtp.gmail.com
     * - 'valor con espacios' → valor con espacios
     * - sin_comillas → sin_comillas
     *
     * @param  string  $value  Valor crudo leído del .env (puede tener comillas).
     * @return string  Valor sin comillas envolventes.
     */
    private function strip_env_quotes(string $value): string
    {
        $value = trim($value);

        /* Stripea comillas dobles envolventes. */
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        /* Stripea comillas simples envolventes. */
        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Escapa un valor para usarlo de forma segura como reemplazo en un comando sed.
     *
     * El delimitador de sed es `|`, por lo que se deben escapar:
     * - `|` → `\|` (delimitador de sed)
     * - `\` → `\\` (backslash de escape)
     * - `&` → `\&` (en sed el & en el reemplazo significa "matched string")
     *
     * @param  string  $value  Valor original a escribir en el .env.
     * @return string  Valor escapado para sed.
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
