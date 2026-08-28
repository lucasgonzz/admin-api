<?php

namespace Tests\Unit;

use App\Services\Afip\AfipCertificateProvisionService;
use PHPUnit\Framework\TestCase;
use phpseclib3\Net\SFTP;

/**
 * Doble de SFTP que simula el servidor de un cliente en memoria.
 *
 * Se escribe a mano en vez de usar un mock de PHPUnit porque el destructor de SSH2 corre al
 * destruirse el objeto y revienta cuando el constructor real no llegó a ejecutarse
 * ("Use of undefined constant NET_SSH2_DISCONNECT_BY_APPLICATION"). Acá el constructor y el
 * destructor quedan vacíos a propósito: nunca se abre un socket.
 */
class SftpDeMentira extends SFTP
{
    /** @var array<int, string> Rutas que el cliente ya tiene antes de empezar. */
    public $existentes = [];

    /** @var array<int, string> Rutas subidas durante la prueba. */
    public $subidos = [];

    /** @var array<int, string> Directorios creados durante la prueba. */
    public $directorios = [];

    /** @var int|null Fuerza el tamaño que devuelve el servidor, para simular una subida truncada. */
    public $tamano_forzado = null;

    /** @var bool Deja constancia de que reponer_en_api() cerró la sesión. */
    public $desconectado = false;

    /** @var array<string, int> Tamaño real de cada ruta subida. */
    private $tamanos = [];

    public function __construct()
    {
        // Sin socket: es un doble de prueba.
    }

    public function __destruct()
    {
        // Sin desconexión: no hay conexión que cerrar.
    }

    public function is_file($path)
    {
        return in_array($path, $this->existentes, true) || in_array($path, $this->subidos, true);
    }

    public function is_dir($path)
    {
        return in_array($path, $this->directorios, true);
    }

    public function mkdir($dir, $mode = -1, $recursive = false)
    {
        $this->directorios[] = $dir;

        return true;
    }

    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $this->subidos[] = $remote_file;
        $this->tamanos[$remote_file] = $mode === self::SOURCE_LOCAL_FILE ? (int) filesize($data) : strlen($data);

        return true;
    }

    public function filesize($path, $recursive = false)
    {
        if ($this->tamano_forzado !== null) {
            return $this->tamano_forzado;
        }

        return isset($this->tamanos[$path]) ? $this->tamanos[$path] : false;
    }

    public function chmod($mode, $filename, $recursive = false)
    {
        return true;
    }

    /**
     * El disconnect() real de SSH2 toca propiedades que este doble nunca inicializó (el
     * constructor está vacío a propósito), así que se sobrescribe para dejar solo la marca.
     */
    public function disconnect()
    {
        $this->desconectado = true;
    }
}

/**
 * Cubre la instalación de los certificados de AFIP en el servidor de un cliente.
 *
 * No abre SSH ni SFTP real, y no toca los certificados reales del disco: el servicio recibe una
 * raíz de storage/ temporal.
 */
class AfipCertificateProvisionServiceTest extends TestCase
{
    /**
     * Raíz temporal que hace de storage/ del admin.
     *
     * @var string
     */
    private $storage_base;

    /**
     * Directorio de la API del cliente, tal como lo arma ClientApiPathResolver.
     *
     * @var string
     */
    private $api_path = 'domains/comerciocity.com/public_html/colman/api';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage_base = sys_get_temp_dir() . '/afip_provision_' . uniqid();
        mkdir($this->storage_base . '/app/afip/production', 0777, true);
        mkdir($this->storage_base . '/app/afip/testing', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->borrar_recursivo($this->storage_base);

        parent::tearDown();
    }

    /**
     * Deja los cuatro archivos origen escritos en la raíz temporal.
     *
     * @return void
     */
    private function sembrar_origenes(): void
    {
        $this->sembrar_produccion();
        file_put_contents($this->storage_base . '/app/afip/testing/comerciocity.crt', '-----BEGIN CERTIFICATE-----homo');
        file_put_contents($this->storage_base . '/app/afip/testing/comerciocity.key', '-----BEGIN PRIVATE KEY-----homo');
    }

    /**
     * Deja solo el par de producción, para el caso de un admin cargado a medias.
     *
     * @return void
     */
    private function sembrar_produccion(): void
    {
        file_put_contents($this->storage_base . '/app/afip/production/comerciocity.crt', '-----BEGIN CERTIFICATE-----prod');
        file_put_contents($this->storage_base . '/app/afip/production/comerciocity.key', '-----BEGIN PRIVATE KEY-----prod');
    }

    /**
     * @param  string  $ruta
     * @return void
     */
    private function borrar_recursivo(string $ruta): void
    {
        if (! is_dir($ruta)) {
            return;
        }

        foreach (scandir($ruta) as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $hijo = $ruta . '/' . $entrada;
            if (is_dir($hijo)) {
                $this->borrar_recursivo($hijo);
            } else {
                unlink($hijo);
            }
        }

        rmdir($ruta);
    }

    /**
     * Callable de log que descarta las líneas: lo que se verifica es el resultado, no el texto.
     *
     * @return callable
     */
    private function log_mudo(): callable
    {
        return function (string $linea, string $nivel) {
        };
    }

    /**
     * Las rutas destino son el contrato con empresa-api: son los defaults del bloque `afip` de su
     * config/services.php. Si allá cambian, este test es el que tiene que romper.
     */
    public function test_las_rutas_destino_son_las_que_espera_empresa_api(): void
    {
        $this->assertSame(
            [
                'storage/app/afip/production/cert.crt',
                'storage/app/afip/production/privada.key',
                'storage/app/afip/testing/afip_cert.pem',
                'storage/app/afip/testing/afip_private.key',
            ],
            AfipCertificateProvisionService::rutas_destino()
        );
    }

    public function test_instala_los_cuatro_archivos_cuando_el_cliente_no_tiene_ninguno(): void
    {
        $this->sembrar_origenes();

        $sftp = new SftpDeMentira();
        $service = new AfipCertificateProvisionService($this->storage_base);

        $resultado = $service->provision($sftp, $this->api_path, $this->log_mudo());

        $this->assertCount(4, $resultado['instalados']);
        $this->assertEmpty($resultado['ya_estaban']);
        $this->assertEmpty($resultado['errores']);
        $this->assertEmpty($resultado['faltantes_en_admin']);

        // Se subieron a las rutas del contrato, no a otras.
        $this->assertSame(
            [
                $this->api_path . '/storage/app/afip/production/cert.crt',
                $this->api_path . '/storage/app/afip/production/privada.key',
                $this->api_path . '/storage/app/afip/testing/afip_cert.pem',
                $this->api_path . '/storage/app/afip/testing/afip_private.key',
            ],
            $sftp->subidos
        );
    }

    public function test_nunca_pisa_un_archivo_que_el_cliente_ya_tiene(): void
    {
        $this->sembrar_origenes();

        $sftp = new SftpDeMentira();
        $sftp->existentes = array_map(function ($ruta) {
            return $this->api_path . '/' . $ruta;
        }, AfipCertificateProvisionService::rutas_destino());

        $service = new AfipCertificateProvisionService($this->storage_base);
        $resultado = $service->provision($sftp, $this->api_path, $this->log_mudo());

        $this->assertCount(4, $resultado['ya_estaban']);
        $this->assertEmpty($resultado['instalados']);
        // Lo que importa de verdad: no se escribió nada en el servidor del cliente.
        $this->assertSame([], $sftp->subidos);
    }

    public function test_completa_solo_lo_que_le_falta_al_cliente(): void
    {
        $this->sembrar_origenes();

        $sftp = new SftpDeMentira();
        // El cliente ya tiene el certificado de producción; los otros tres no.
        $sftp->existentes = [$this->api_path . '/storage/app/afip/production/cert.crt'];

        $service = new AfipCertificateProvisionService($this->storage_base);
        $resultado = $service->provision($sftp, $this->api_path, $this->log_mudo());

        $this->assertSame(['cert_production'], $resultado['ya_estaban']);
        $this->assertSame(['key_production', 'cert_testing', 'key_testing'], $resultado['instalados']);
    }

    public function test_reporta_los_que_faltan_en_el_admin_sin_lanzar(): void
    {
        // Solo el par de producción: los de homologación no están cargados en el admin.
        $this->sembrar_produccion();

        $sftp = new SftpDeMentira();
        $service = new AfipCertificateProvisionService($this->storage_base);

        $resultado = $service->provision($sftp, $this->api_path, $this->log_mudo());

        $this->assertSame(['cert_production', 'key_production'], $resultado['instalados']);
        $this->assertSame(['cert_testing', 'key_testing'], $resultado['faltantes_en_admin']);
        $this->assertEmpty($resultado['errores']);
    }

    public function test_una_subida_truncada_se_reporta_como_error(): void
    {
        $this->sembrar_origenes();

        $sftp = new SftpDeMentira();
        // El servidor devuelve menos bytes de los que se mandaron: el certificado quedaría ilegible
        // y eso se vería recién al facturar.
        $sftp->tamano_forzado = 3;

        $service = new AfipCertificateProvisionService($this->storage_base);
        $resultado = $service->provision($sftp, $this->api_path, $this->log_mudo());

        $this->assertCount(4, $resultado['errores']);
        $this->assertEmpty($resultado['instalados']);
    }

    public function test_auditar_no_escribe_nada_y_separa_presentes_de_faltantes(): void
    {
        $sftp = new SftpDeMentira();
        $sftp->existentes = [
            $this->api_path . '/storage/app/afip/production/cert.crt',
            $this->api_path . '/storage/app/afip/production/privada.key',
        ];

        $service = new AfipCertificateProvisionService($this->storage_base);
        $auditoria = $service->auditar($sftp, $this->api_path);

        $this->assertSame(['cert_production', 'key_production'], $auditoria['presentes']);
        $this->assertSame(['cert_testing', 'key_testing'], $auditoria['faltantes']);
        $this->assertSame([], $sftp->subidos);
        $this->assertSame([], $sftp->directorios);
    }

    /**
     * Recolecta las líneas de log en un array, para los tests que verifican QUÉ se logueó.
     *
     * @param  array<int, array<string, string>>  $lineas  Se llena por referencia.
     * @return callable
     */
    private function log_espia(array &$lineas): callable
    {
        return function (string $linea, string $nivel) use (&$lineas) {
            $lineas[] = ['linea' => $linea, 'nivel' => $nivel];
        };
    }

    /**
     * ¿Alguna línea de ese nivel contiene ese texto?
     *
     * @param  array<int, array<string, string>>  $lineas
     * @param  string  $nivel
     * @param  string  $texto
     * @return bool
     */
    private function hay_linea(array $lineas, string $nivel, string $texto): bool
    {
        foreach ($lineas as $registro) {
            if ($registro['nivel'] === $nivel && strpos($registro['linea'], $texto) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * El envoltorio compartido hace lo mismo que hacía el cuerpo inline de DeploymentService —
     * instalar lo que falte— y además cierra la sesión que abrió.
     *
     * Lo del cierre no es cosmético: los pipelines llaman a esto en el medio de una corrida larga y
     * después siguen usando SSH. Una sesión SFTP colgada se paga más tarde y en otro lado.
     */
    public function test_reponer_en_api_instala_los_faltantes_y_cierra_la_sesion(): void
    {
        $this->sembrar_origenes();

        $sftp = new SftpDeMentira();
        $service = new AfipCertificateProvisionService($this->storage_base);

        $service->reponer_en_api(
            function () use ($sftp) {
                return $sftp;
            },
            $this->api_path,
            $this->log_mudo()
        );

        $this->assertSame(
            [
                $this->api_path . '/storage/app/afip/production/cert.crt',
                $this->api_path . '/storage/app/afip/production/privada.key',
                $this->api_path . '/storage/app/afip/testing/afip_cert.pem',
                $this->api_path . '/storage/app/afip/testing/afip_private.key',
            ],
            $sftp->subidos
        );

        $this->assertTrue($sftp->desconectado, 'reponer_en_api() tiene que cerrar la sesion SFTP que abrio.');
    }

    /**
     * 🔴 El candado de la política: esto NUNCA propaga.
     *
     * Los tres pipelines que lo llaman ya subieron el código y están a mitad de camino. Si alguien
     * saca el try/catch, un SFTP caído o un admin sin certificados cargados pasa a cortar una
     * actualización de producción por la mitad, que es bastante peor que el certificado faltante.
     */
    public function test_reponer_en_api_no_propaga_si_no_se_puede_abrir_la_sesion(): void
    {
        $this->sembrar_origenes();

        $lineas = [];
        $service = new AfipCertificateProvisionService($this->storage_base);

        $service->reponer_en_api(
            function () {
                throw new \RuntimeException('SFTP caído');
            },
            $this->api_path,
            $this->log_espia($lineas)
        );

        $this->assertTrue(
            $this->hay_linea($lineas, 'warning', 'SFTP caído'),
            'Tiene que quedar constancia del motivo, con nivel warning, en el log de la corrida.'
        );
    }

    /**
     * El envoltorio sigue llamando a loguear_resultado() y no solo a provision().
     *
     * La diferencia entre las dos cosas es invisible salvo que se mire el log — que es exactamente
     * el caso de uso: si el admin no tiene los certificados cargados, la corrida termina bien igual
     * y lo único que avisa es esta línea.
     */
    public function test_reponer_en_api_avisa_cuando_el_admin_no_tiene_los_certificados(): void
    {
        // Solo el par de producción: los de homologación no están cargados en el admin.
        $this->sembrar_produccion();

        $sftp = new SftpDeMentira();
        $lineas = [];
        $service = new AfipCertificateProvisionService($this->storage_base);

        $service->reponer_en_api(
            function () use ($sftp) {
                return $sftp;
            },
            $this->api_path,
            $this->log_espia($lineas)
        );

        $this->assertTrue(
            $this->hay_linea($lineas, 'warning', 'cert_testing, key_testing'),
            'La linea de warning tiene que nombrar los que faltan en el admin.'
        );
    }

    public function test_faltantes_en_admin_lista_lo_que_no_esta_cargado(): void
    {
        $service = new AfipCertificateProvisionService($this->storage_base);

        $this->assertSame(
            ['cert_production', 'key_production', 'cert_testing', 'key_testing'],
            $service->faltantes_en_admin()
        );

        $this->sembrar_origenes();

        $this->assertSame([], $service->faltantes_en_admin());
    }
}
