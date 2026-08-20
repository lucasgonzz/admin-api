<?php

namespace App\Services\Afip;

use Illuminate\Http\UploadedFile;
use phpseclib3\Net\SFTP;

/**
 * Instala los certificados de AFIP en el servidor de un cliente de empresa.
 *
 * Por qué existe: el 26/7/2026 el commit `ec6e164a` de empresa-api sacó los certificados de
 * `public/afip/` (estaban dentro del document root y commiteados) y los movió a
 * `storage/app/afip/`, agregándolos al .gitignore. Desde entonces:
 *
 *   - el ZIP de instalación (InstallationService::step_upload_api) se arma del clon de git, así que
 *     nace sin ellos;
 *   - el ZIP de actualización (DeploymentService::step_upload_api) excluye `storage/*` a propósito,
 *     así que tampoco los repone nunca.
 *
 * docs/afip.md de empresa-api dejó documentado que copiarlos era "un paso manual de deploy". Ese
 * paso no lo hacía nadie y el cliente quedaba sin poder facturar. Este servicio lo automatiza: el
 * servidor del admin es la fuente de verdad y los deja instalados por SFTP.
 *
 * Relación con DeploymentService::sync_afip_certificates(): son complementarios y corren en ese
 * orden. El sync arrastra lo que el cliente YA tenía de la carpeta de versión anterior a la nueva
 * (y así respeta un certificado propio o rotado a mano); este servicio recién después completa lo
 * que siga faltando. Un cliente que nunca tuvo los archivos no tiene de dónde sincronizarlos, y
 * ese es justamente el caso que quedaba roto.
 *
 * Estrictamente no destructivo por default: nunca pisa un archivo que ya existe en el cliente.
 */
class AfipCertificateProvisionService
{
    /**
     * Los cuatro archivos que empresa-api espera, con su origen en el servidor del admin.
     *
     * - `origen`: ruta relativa a storage_path() del admin. Son los mismos archivos que el admin ya
     *   usa para facturar sus propias mensualidades (ver AfipWsaaService::define()): un único
     *   certificado de ComercioCity para todo (decisión de Lucas, 20/8/2026).
     * - `destino`: ruta relativa al directorio raíz de la API del cliente. Son los defaults del
     *   bloque `afip` de config/services.php de empresa-api, y ese es el contrato entre los dos
     *   sistemas: si allá cambian los defaults, hay que cambiarlos acá.
     *
     * `storage/app/afip/wsaa/` no está en esta lista a propósito: AfipWSAAHelper::define() lo crea
     * solo la primera vez que se factura.
     *
     * @var array<string, array<string, string>>
     */
    const ARCHIVOS = [
        'cert_production' => [
            'origen'   => 'app/afip/production/comerciocity.crt',
            'destino'  => 'storage/app/afip/production/cert.crt',
            'etiqueta' => 'Certificado de producción',
        ],
        'key_production' => [
            'origen'   => 'app/afip/production/comerciocity.key',
            'destino'  => 'storage/app/afip/production/privada.key',
            'etiqueta' => 'Clave privada de producción',
        ],
        'cert_testing' => [
            'origen'   => 'app/afip/testing/comerciocity.crt',
            'destino'  => 'storage/app/afip/testing/afip_cert.pem',
            'etiqueta' => 'Certificado de homologación',
        ],
        'key_testing' => [
            'origen'   => 'app/afip/testing/comerciocity.key',
            'destino'  => 'storage/app/afip/testing/afip_private.key',
            'etiqueta' => 'Clave privada de homologación',
        ],
    ];

    /**
     * Rutas destino (relativas al directorio de la API del cliente) de los cuatro archivos.
     *
     * InstallationService las suma a las rutas que verifica antes de dar una instalación por
     * completa: si faltan, la instalación se marca fallida en vez de entregarse sin facturación.
     *
     * @return array<int, string>
     */
    public static function rutas_destino(): array
    {
        $rutas = [];
        foreach (self::ARCHIVOS as $definicion) {
            $rutas[] = $definicion['destino'];
        }

        return $rutas;
    }

    /**
     * Raíz de storage/ del admin, de donde salen los archivos origen.
     *
     * @var string
     */
    private $storage_base;

    /**
     * @param  string|null  $storage_base  Solo para tests: raíz alternativa de storage/. En
     *                                     producción siempre es storage_path(), y los tests la
     *                                     cambian para no tocar los certificados reales del disco.
     */
    public function __construct($storage_base = null)
    {
        $this->storage_base = $storage_base !== null ? rtrim($storage_base, '/\\') : storage_path();
    }

    /**
     * Ruta absoluta, en el servidor del admin, del archivo origen de una clave.
     *
     * @param  string  $clave  Clave de self::ARCHIVOS
     * @return string
     */
    public function ruta_origen(string $clave): string
    {
        return $this->storage_base . DIRECTORY_SEPARATOR . self::ARCHIVOS[$clave]['origen'];
    }

    /**
     * Estado de los cuatro archivos en el servidor del admin (para el panel).
     *
     * @return array<int, array<string, mixed>>
     */
    public function estado_en_admin(): array
    {
        $estado = [];

        foreach (self::ARCHIVOS as $clave => $definicion) {
            $ruta = $this->ruta_origen($clave);
            $existe = is_file($ruta);

            $estado[] = [
                'clave'         => $clave,
                'etiqueta'      => $definicion['etiqueta'],
                'destino'       => $definicion['destino'],
                'cargado'       => $existe,
                'bytes'         => $existe ? (int) filesize($ruta) : null,
                'modificado_at' => $existe ? date('c', filemtime($ruta)) : null,
            ];
        }

        return $estado;
    }

    /**
     * Claves cuyo archivo origen todavía no fue cargado en el servidor del admin.
     *
     * @return array<int, string>
     */
    public function faltantes_en_admin(): array
    {
        $faltantes = [];

        foreach (array_keys(self::ARCHIVOS) as $clave) {
            if (! is_file($this->ruta_origen($clave))) {
                $faltantes[] = $clave;
            }
        }

        return $faltantes;
    }

    /**
     * Guarda un archivo subido desde el panel como origen de una de las cuatro claves.
     *
     * Se persiste fuera del document root (storage/, no public/) porque son secretos: una clave
     * privada de AFIP descargable por HTTP es exactamente el problema que se arregló sacándolos
     * de public/afip/ en empresa-api.
     *
     * @param  string  $clave  Clave de self::ARCHIVOS
     * @param  UploadedFile  $archivo
     * @return void
     */
    public function guardar_origen(string $clave, UploadedFile $archivo): void
    {
        $destino = $this->ruta_origen($clave);
        $directorio = dirname($destino);

        if (! is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }

        $archivo->move($directorio, basename($destino));
        @chmod($destino, 0600);
    }

    /**
     * Mira, sin escribir nada, cuáles de los cuatro archivos tiene el cliente.
     *
     * @param  SFTP  $sftp  Sesión ya abierta contra el servidor del cliente
     * @param  string  $api_path  Directorio raíz de la API del cliente
     * @return array<string, array<int, string>>
     */
    public function auditar(SFTP $sftp, string $api_path): array
    {
        $resultado = ['presentes' => [], 'faltantes' => []];

        foreach (self::ARCHIVOS as $clave => $definicion) {
            $destino = $this->ruta_destino_absoluta($api_path, $definicion['destino']);
            if ($sftp->is_file($destino)) {
                $resultado['presentes'][] = $clave;
            } else {
                $resultado['faltantes'][] = $clave;
            }
        }

        return $resultado;
    }

    /**
     * Instala en el cliente los archivos que le falten.
     *
     * @param  SFTP  $sftp  Sesión ya abierta contra el servidor del cliente
     * @param  string  $api_path  Directorio raíz de la API del cliente
     * @param  callable  $log  function(string $linea, string $nivel): void
     * @param  bool  $pisar  true solo si se quiere sobrescribir lo que el cliente ya tenga
     * @return array<string, array<int, string>>
     */
    public function provision(SFTP $sftp, string $api_path, callable $log, bool $pisar = false): array
    {
        $resultado = [
            'instalados'         => [],
            'ya_estaban'         => [],
            'faltantes_en_admin' => [],
            'errores'            => [],
        ];

        foreach (self::ARCHIVOS as $clave => $definicion) {
            $origen  = $this->ruta_origen($clave);
            $destino = $this->ruta_destino_absoluta($api_path, $definicion['destino']);

            if (! $pisar && $sftp->is_file($destino)) {
                $resultado['ya_estaban'][] = $clave;
                continue;
            }

            // El origen se chequea DESPUÉS de mirar el destino: si el cliente ya lo tiene, que el
            // admin no lo tenga cargado no es un problema para este cliente.
            if (! is_file($origen)) {
                $resultado['faltantes_en_admin'][] = $clave;
                continue;
            }

            $directorio_remoto = dirname($destino);
            if (! $sftp->is_dir($directorio_remoto)) {
                $sftp->mkdir($directorio_remoto, -1, true);
            }

            if (! $sftp->put($destino, $origen, SFTP::SOURCE_LOCAL_FILE)) {
                $resultado['errores'][] = $definicion['etiqueta'] . ': no se pudo subir por SFTP.';
                continue;
            }

            // Verificación de tamaño: un put que devuelve true pero deja el archivo truncado
            // produce un certificado ilegible, y eso se vería recién al facturar.
            $bytes_local  = (int) filesize($origen);
            $bytes_remoto = $sftp->filesize($destino);
            if ($bytes_remoto === false || (int) $bytes_remoto !== $bytes_local) {
                $resultado['errores'][] = $definicion['etiqueta']
                    . ': subido incompleto (' . var_export($bytes_remoto, true) . ' bytes en el cliente'
                    . ' contra ' . $bytes_local . ' en el admin).';
                continue;
            }

            // 0644 y no 0640: en hosting compartido el usuario SSH no siempre es el mismo con el que
            // corre PHP, y un 0640 dejaría el certificado ilegible para la aplicación. El archivo
            // está fuera del document root, así que no queda expuesto por HTTP.
            $sftp->chmod(0644, $destino);

            $resultado['instalados'][] = $clave;
            $log($definicion['etiqueta'] . ' instalado en ' . $definicion['destino'], 'info');
        }

        return $resultado;
    }

    /**
     * Traduce el resultado de provision() a líneas de log legibles, con el nivel que corresponde.
     * Comparte formato entre instalación y actualización.
     *
     * @param  array<string, array<int, string>>  $resultado  Salida de provision()
     * @param  callable  $log  function(string $linea, string $nivel): void
     * @return void
     */
    public function loguear_resultado(array $resultado, callable $log): void
    {
        if (! empty($resultado['errores'])) {
            $log('Certificados AFIP: ' . implode(' ', $resultado['errores']), 'error');
        }

        if (! empty($resultado['faltantes_en_admin'])) {
            $log(
                'Faltan certificados AFIP en el servidor del admin (' . implode(', ', $resultado['faltantes_en_admin'])
                . '). Subilos desde Configuración AFIP del panel: sin ellos el cliente no puede facturar.',
                'warning'
            );
        }

        if (! empty($resultado['instalados'])) {
            $log(
                'Certificados AFIP instalados en el cliente: ' . implode(', ', $resultado['instalados']),
                'success'
            );
        }

        if (empty($resultado['instalados']) && empty($resultado['errores']) && empty($resultado['faltantes_en_admin'])) {
            $log('Certificados AFIP: el cliente ya los tenía todos, no se tocó ninguno.', 'info');
        }
    }

    /**
     * Une el directorio de la API del cliente con la ruta relativa de un destino.
     *
     * En shared_hosting `$api_path` es relativo al home del usuario SSH (`domains/...`), en VPS es
     * absoluto. En los dos casos SFTP lo resuelve igual.
     *
     * @param  string  $api_path
     * @param  string  $ruta_relativa
     * @return string
     */
    private function ruta_destino_absoluta(string $api_path, string $ruta_relativa): string
    {
        return rtrim($api_path, '/') . '/' . $ruta_relativa;
    }
}
