<?php

namespace Tests\Fakes;

use phpseclib3\Net\SFTP;

/**
 * Doble de la sesión SFTP de `DeploymentService` (misión `un-solo-frente`, 14/10/2026).
 *
 * El despliegue EN EL LUGAR escribe por SFTP el archivo de opciones de `mysqldump` con las
 * credenciales de la base del cliente: es la única forma de que la contraseña llegue al servidor
 * sin pasar por la línea de comandos (que `exec_ssh_session()` loguea entera en
 * `deployment_logs`). Este doble guarda lo que se escribió, con qué permisos y en qué orden, para
 * que un test pueda afirmar las dos cosas a la vez: que el archivo tiene la contraseña (si no,
 * `mysqldump` no entra) y que el log y los comandos no la tienen.
 *
 * También sirve para la subida del zip de la API (`sftp_upload_file()`) y para la reposición de
 * certificados de AFIP: `is_file()` contesta true, así esa etapa ve los certificados "ya estaban" y
 * no sube nada.
 *
 * Se inyecta con el seam `DeploymentService::open_sftp_session()`.
 *
 * PHP 7.4.
 */
class SftpDelDeploymentFake extends SFTP
{
    /**
     * Contenido de cada archivo escrito: ruta remota => contenido.
     *
     * @var array<string, string>
     */
    public $archivos = [];

    /**
     * Permisos aplicados con chmod(): ruta remota => modo (entero).
     *
     * @var array<string, int>
     */
    public $permisos = [];

    /**
     * Registro ordenado de operaciones (`put <ruta>`, `chmod <modo> <ruta>`, `delete <ruta>`).
     *
     * @var array<int, string>
     */
    public $operaciones = [];

    /**
     * Si es true, todo put() falla (simula cuota agotada o permiso denegado).
     *
     * @var bool
     */
    public $falla_put = false;

    /**
     * No conecta: login() no se llama nunca.
     */
    public function __construct()
    {
        parent::__construct('127.0.0.1', 1);
    }

    /**
     * @param  string         $remote_file
     * @param  string         $data
     * @param  int            $mode
     * @param  int            $start
     * @param  int            $local_start
     * @param  callable|null  $progressCallback
     * @return bool
     */
    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $this->operaciones[] = 'put ' . $remote_file;

        if ($this->falla_put) {
            return false;
        }

        if ($mode & self::SOURCE_LOCAL_FILE) {
            $data = (string) file_get_contents((string) $data);
        }

        $this->archivos[(string) $remote_file] = (string) $data;

        return true;
    }

    /**
     * @param  string  $path
     * @param  bool    $recursive
     * @return int|false
     */
    public function filesize($path, $recursive = false)
    {
        return isset($this->archivos[$path]) ? strlen($this->archivos[$path]) : false;
    }

    /**
     * @param  string  $filename
     * @return array<string, mixed>|false
     */
    public function stat($filename)
    {
        return isset($this->archivos[$filename]) ? ['size' => strlen($this->archivos[$filename])] : false;
    }

    /**
     * @param  int     $mode
     * @param  string  $filename
     * @param  bool    $recursive
     * @return bool
     */
    public function chmod($mode, $filename, $recursive = false)
    {
        $this->permisos[(string) $filename] = (int) $mode;
        $this->operaciones[]                = 'chmod ' . decoct((int) $mode) . ' ' . $filename;

        return true;
    }

    /**
     * Los certificados de AFIP "ya estaban": provision() no sube nada.
     *
     * @param  string  $path
     * @return bool
     */
    public function is_file($path)
    {
        return true;
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function is_dir($path)
    {
        return true;
    }

    /**
     * @param  string  $dir
     * @param  int     $mode
     * @param  bool    $recursive
     * @return bool
     */
    public function mkdir($dir, $mode = -1, $recursive = false)
    {
        return true;
    }

    /**
     * @param  string  $path
     * @param  bool    $recursive
     * @return bool
     */
    public function delete($path, $recursive = true)
    {
        $this->operaciones[] = 'delete ' . $path;
        unset($this->archivos[$path]);

        return true;
    }

    /**
     * @return void
     */
    public function disconnect()
    {
        // Sin socket no hay nada que cerrar.
    }

    /**
     * El destructor del padre llama a disconnect() sobre un socket que nunca existió.
     */
    public function __destruct()
    {
    }
}
