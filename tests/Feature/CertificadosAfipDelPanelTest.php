<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Afip\AfipCertificateProvisionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Carga de los certificados de AFIP desde el panel del admin.
 *
 * Son los archivos que después se instalan solos en cada cliente al instalar o actualizar su
 * sistema (AfipCertificateProvisionService), así que si no se pueden cargar acá el resto de la
 * cadena no sirve para nada.
 *
 * El servicio se bindea con una raíz de storage/ temporal: los tests no pueden tocar —ni menos
 * pisar— los certificados reales del servidor.
 */
class CertificadosAfipDelPanelTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Raíz temporal que hace de storage/ del admin durante la prueba.
     *
     * @var string
     */
    private $storage_base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage_base = sys_get_temp_dir() . '/afip_panel_' . uniqid();
        mkdir($this->storage_base, 0777, true);

        $this->app->bind(AfipCertificateProvisionService::class, function () {
            return new AfipCertificateProvisionService($this->storage_base);
        });
    }

    protected function tearDown(): void
    {
        $this->borrar_recursivo($this->storage_base);

        parent::tearDown();
    }

    /**
     * Borra el directorio temporal de la prueba. Best-effort a propósito: si Windows todavía tiene
     * tomado alguno de los archivos que acaba de mover el request, eso no es motivo para marcar el
     * test como fallado — lo que se está probando es el endpoint, no la limpieza.
     *
     * @param  string  $ruta
     * @return void
     */
    private function borrar_recursivo(string $ruta): void
    {
        clearstatcache();

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
                @unlink($hijo);
            }
        }

        @rmdir($ruta);
    }

    /**
     * Admin autenticable con Sanctum.
     *
     * @param  string  $email
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Archivo con pinta de certificado PEM.
     *
     * @param  string  $nombre
     * @return UploadedFile
     */
    private function archivo_pem(string $nombre): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $nombre,
            "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n"
        );
    }

    public function test_sin_autenticar_no_se_pueden_ver_ni_subir_certificados(): void
    {
        $this->getJson('/api/admin/comerciocity-afip-config/certificados')->assertStatus(401);
        $this->postJson('/api/admin/comerciocity-afip-config/certificados')->assertStatus(401);
    }

    public function test_el_estado_arranca_con_los_cuatro_sin_cargar(): void
    {
        $admin = $this->crear_admin('afip-estado@test.local');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/comerciocity-afip-config/certificados');

        $response->assertStatus(200);

        $archivos = $response->json('archivos');
        $this->assertCount(4, $archivos);

        foreach ($archivos as $archivo) {
            $this->assertFalse($archivo['cargado'], $archivo['clave'] . ' no debería estar cargado.');
            $this->assertNull($archivo['bytes']);
        }
    }

    public function test_subir_el_certificado_de_produccion_lo_deja_cargado_fuera_del_document_root(): void
    {
        $admin = $this->crear_admin('afip-subida@test.local');

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/comerciocity-afip-config/certificados',
            ['cert_production' => $this->archivo_pem('comerciocity.crt')]
        );

        $response->assertStatus(200);
        $this->assertSame(['cert_production'], $response->json('guardados'));

        // Queda en storage/, nunca en public/: es un secreto, no un logo.
        $destino = $this->storage_base . DIRECTORY_SEPARATOR . 'app/afip/production/comerciocity.crt';
        $this->assertFileExists($destino);
        $this->assertStringContainsString('BEGIN CERTIFICATE', file_get_contents($destino));

        $archivos = collect($response->json('archivos'))->keyBy('clave');
        $this->assertTrue($archivos['cert_production']['cargado']);
        $this->assertFalse($archivos['key_production']['cargado']);
    }

    public function test_se_pueden_subir_los_cuatro_de_una(): void
    {
        $admin = $this->crear_admin('afip-cuatro@test.local');

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/comerciocity-afip-config/certificados',
            [
                'cert_production' => $this->archivo_pem('comerciocity.crt'),
                'key_production'  => $this->archivo_pem('comerciocity.key'),
                'cert_testing'    => $this->archivo_pem('homo.crt'),
                'key_testing'     => $this->archivo_pem('homo.key'),
            ]
        );

        $response->assertStatus(200);
        $this->assertCount(4, $response->json('guardados'));

        foreach ($response->json('archivos') as $archivo) {
            $this->assertTrue($archivo['cargado'], $archivo['clave'] . ' tendría que haber quedado cargado.');
        }
    }

    public function test_un_archivo_que_no_es_pem_se_rechaza_y_no_se_guarda(): void
    {
        $admin = $this->crear_admin('afip-basura@test.local');

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/comerciocity-afip-config/certificados',
            ['cert_production' => UploadedFile::fake()->createWithContent('cualquiera.pdf', '%PDF-1.4 no soy un certificado')]
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('cert_production', $response->json('rechazados'));
        $this->assertFileDoesNotExist($this->storage_base . DIRECTORY_SEPARATOR . 'app/afip/production/comerciocity.crt');
    }

    public function test_un_post_sin_archivos_devuelve_422(): void
    {
        $admin = $this->crear_admin('afip-vacio@test.local');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/comerciocity-afip-config/certificados', [])
            ->assertStatus(422);
    }
}
