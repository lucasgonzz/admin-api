<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Mail\ImplementacionMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Lo que cambió en `POST claude/implementations/{id}/mail` por la revisión independiente del 5/10/2026 (misión
 * `implementar-cliente`):
 *
 *  - BAJO-3. El dry-run no dice `listo` si el mailer `admin` no tiene credencial: el real daría `estado: error`. Dice cuál es la
 *    falta en `mailer`.
 *  - MEDIO-1. Si el mail SALIÓ y no se pudo anotar, la respuesta es 200 con `enviado: true` y un `aviso`: nunca un 500 ni un error
 *    que invite a mandarlo dos veces.
 *  - D-08. `datos.arca` (booleano) del hito `listo`: lo valida el endpoint y saca del mail la línea de la facturación electrónica.
 */
class AjustesDelMailPorClaudeTest extends BaseDeImplementaciones
{
    /** Casilla del dueño en la ficha. */
    const CASILLA = 'dueno@ejemplo.test';

    /**
     * Sin mails reales, con la URL del formulario y la tabla de mails limpia.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion');
        DB::table('implementation_mails')->delete();
    }

    /**
     * Un cliente con casilla y sus dos APIs, y su implementación.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['email' => self::CASILLA]);
        $cliente = $this->crear_las_dos_apis($cliente, 'panchito');

        return ['cliente' => $cliente, 'implementacion' => $this->crear_implementacion($cliente)];
    }

    /**
     * El POST del mail.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function mail(Implementation $implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/mail', $cuerpo, $this->headers());
    }

    /**
     * Pone el mailer `admin` sin credencial, como el .env de producción hasta que se la cargan.
     *
     * @return void
     */
    private function sin_credencial(): void
    {
        config(['mail.mailers.admin.transport' => 'smtp', 'mail.mailers.admin.username' => '', 'mail.mailers.admin.password' => '']);
    }

    /* ------------------------------------------------------------------------------------------
     | BAJO-3: el dry-run y la credencial
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin credencial en el mailer `admin`, el dry-run NO dice `listo` y explica qué falta; con credencial (o con un transporte que
     * no autentica) dice `listo`.
     *
     * @return void
     */
    public function test_el_dry_run_no_dice_listo_si_el_mailer_no_tiene_credencial(): void
    {
        $e = $this->escenario();

        $this->sin_credencial();

        $sin = $this->mail($e['implementacion'], ['hito' => 'bienvenida']);

        $sin->assertStatus(200);
        $sin->assertJsonPath('listo', false);
        $sin->assertJsonPath('mailer.listo', false);
        $this->assertStringContainsString('MAIL_ADMIN_USERNAME', (string) $sin->json('mailer.falta'));
        $this->assertStringContainsString('MAIL_ADMIN_USERNAME', (string) $sin->json('nota'));
        $this->assertSame([], $sin->json('faltan'), 'No falta ningún dato del mail: lo que falta es la credencial del mailer.');

        config(['mail.mailers.admin.transport' => 'array']);

        $con = $this->mail($e['implementacion'], ['hito' => 'bienvenida']);

        $con->assertJsonPath('listo', true);
        $con->assertJsonPath('mailer.listo', true);
        $con->assertJsonPath('mailer.falta', null);
    }

    /* ------------------------------------------------------------------------------------------
     | MEDIO-1: el mail salió y no se pudo anotar
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El mail sale, anotarlo falla: 200 con `enviado: true`, `estado: enviado` y un `aviso` (no reenviar). Ni un 500 ni un error.
     *
     * @return void
     */
    public function test_si_el_mail_salio_y_no_se_pudo_anotar_la_respuesta_es_enviado_con_aviso(): void
    {
        $e = $this->escenario();

        ImplementationMail::saving(function () {
            throw new \RuntimeException('Connection lost: la base se cayó');
        });

        $respuesta = $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('enviado', true);
        $respuesta->assertJsonPath('estado', 'enviado');
        $respuesta->assertJsonPath('error', null);
        $this->assertStringContainsString('NO lo reenvíes', (string) $respuesta->json('aviso'));

        Mail::assertSent(ImplementacionMail::class, 1);
    }

    /**
     * El camino normal no trae `aviso`.
     *
     * @return void
     */
    public function test_el_camino_normal_no_trae_aviso(): void
    {
        $e = $this->escenario();

        $respuesta = $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('enviado', true);
        $this->assertArrayNotHasKey('aviso', $respuesta->json());
    }

    /* ------------------------------------------------------------------------------------------
     | D-08: arca
     |----------------------------------------------------------------------------------------- */

    /**
     * `datos.arca` lo valida el endpoint (un booleano, solo en `listo`) y, en false, saca la línea de ARCA del mail del dry-run.
     *
     * @return void
     */
    public function test_arca_false_saca_la_linea_de_la_facturacion_electronica(): void
    {
        $e = $this->escenario();

        $datos = ['resumen' => ['articulos' => 100, 'con_foto' => 90, 'clientes' => 10]];

        $con = $this->mail($e['implementacion'], ['hito' => 'listo', 'datos' => $datos]);

        $con->assertStatus(200);
        $this->assertStringContainsString('Conectamos la facturación electrónica con ARCA.', $con->json('html'));

        $sin = $this->mail($e['implementacion'], ['hito' => 'listo', 'datos' => array_merge($datos, ['arca' => false])]);

        $sin->assertStatus(200);
        $this->assertStringNotContainsString('ARCA', $sin->json('html'));
        $this->assertStringContainsString('Soporte por WhatsApp', $sin->json('html'));

        $invalido = $this->mail($e['implementacion'], ['hito' => 'listo', 'datos' => array_merge($datos, ['arca' => 'no'])]);

        $invalido->assertStatus(422);
        $invalido->assertJsonPath('motivo', 'faltan_datos');
        $this->assertArrayHasKey('arca', $invalido->json('errores'));
    }
}
