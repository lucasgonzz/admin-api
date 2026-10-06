<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Http\Controllers\Api\ImplementationController;
use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Models\Implementation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Lo que encontró la revisión independiente del alta, el estado y el install, y se corrigió antes del merge (misión
 * `implementar-cliente`, 5/10/2026):
 *
 *  - H1. El install instala la ÚLTIMA versión publicada pero nadie actualizaba `clients.current_version_id`: la primera
 *    actualización del cliente partía de la versión con la que se promovió el lead y generaba seeders y comandos de versiones
 *    que la instalación ya trae. Ahora el job, con la instalación REAL terminada, alinea la versión del cliente.
 *  - H4. El chequeo del subdominio no detectaba colisiones cruzadas entre un SPA y una API: pedir `api-x` cuando existe el cliente
 *    `x` (su API es `api-x`) pasaba, y los dos terminaban como subdominios repetidos en Hostinger.
 *  - H6. Con la real completada y el esqueleto fallido, `install` daba "ya está instalado" sin mencionar el esqueleto.
 *  - H7. Borrar una implementación desde el panel dejaba huérfanas sus filas de `implementation_mails`.
 */
class AjustesDelAltaYDelInstallPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Sin clave de Anthropic (la sugerencia usa el fallback), un admin creador de la promoción y sin red: el dry-run del install
     * consulta `version-activa` del cliente, y acá nada sale.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => '']);
        config(['services.claude_task_ingest.default_creator_admin_id' => null]);

        $this->crear_admin();

        Http::fake(['*' => Http::response([], 404)]);
    }

    /* ------------------------------------------------------------------------------------------
     | H1: la versión del cliente queda en la que se instaló
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con la instalación REAL `completada`, `clients.current_version_id` pasa a la versión instalada.
     *
     * @return void
     */
    public function test_la_instalacion_deja_al_cliente_en_la_version_instalada(): void
    {
        $vieja = $this->crear_version();
        $nueva = $this->crear_version();

        $cliente = $this->crear_cliente('Panchito Gómez', ['current_version_id' => $vieja->id]);
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $real      = $this->crear_instalacion($cliente, ['kind' => 'completa', 'status' => 'completada', 'version_id' => $nueva->id]);
        $esqueleto = $this->crear_instalacion($cliente, ['kind' => 'esqueleto', 'status' => 'completada', 'version_id' => $nueva->id]);

        (new EjecutarInstalacionDeImplementacionJob([$real->uuid, $esqueleto->uuid]))->alinear_la_version_del_cliente();

        $this->assertSame((int) $nueva->id, (int) $cliente->refresh()->current_version_id);
    }

    /**
     * Si la real no terminó bien, o no trae versión, o solo terminó el esqueleto, el cliente queda como estaba: una instalación
     * que no anduvo no cambia la versión de nadie.
     *
     * @return void
     */
    public function test_si_la_real_no_termino_bien_no_se_toca_la_version(): void
    {
        $vieja = $this->crear_version();
        $nueva = $this->crear_version();

        $casos = [
            'la real falló'           => [['kind' => 'completa', 'status' => 'fallida', 'version_id' => $nueva->id], null],
            'la real sigue instalando' => [['kind' => 'completa', 'status' => 'instalando', 'version_id' => $nueva->id], null],
            'la real no trae versión' => [['kind' => 'completa', 'status' => 'completada', 'version_id' => null], null],
            'solo terminó el esqueleto' => [['kind' => 'completa', 'status' => 'fallida', 'version_id' => $nueva->id], ['kind' => 'esqueleto', 'status' => 'completada', 'version_id' => $nueva->id]],
        ];

        foreach ($casos as $nombre => [$atributos_real, $atributos_esqueleto]) {
            $cliente = $this->crear_cliente('Panchito Gómez', ['current_version_id' => $vieja->id]);
            $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

            $uuids = [$this->crear_instalacion($cliente, $atributos_real)->uuid];

            if ($atributos_esqueleto !== null) {
                $uuids[] = $this->crear_instalacion($cliente, $atributos_esqueleto)->uuid;
            }

            (new EjecutarInstalacionDeImplementacionJob($uuids))->alinear_la_version_del_cliente();

            $this->assertSame((int) $vieja->id, (int) $cliente->refresh()->current_version_id, $nombre);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | H4: el subdominio no choca con ningún host de ninguna ClientApi, sea SPA o API
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Pedir `api-equis` cuando existe el cliente `equis` (su API es `api-equis`) choca; y al revés, pedir `quino` cuando existe un
     * cliente cuyo SPA se llama `api-quino`. Uno libre pasa.
     *
     * @return void
     */
    public function test_el_subdominio_choca_con_un_host_de_otro_cliente_sin_importar_el_rol(): void
    {
        $equis = $this->crear_cliente('Cliente Equis');
        $this->crear_las_dos_apis($equis->refresh(), 'equis');

        $raro = $this->crear_cliente('Cliente Raro');
        $this->crear_las_dos_apis($raro->refresh(), 'api-quino');

        $lead = $this->crear_lead(['contact_name' => 'Rosa Fernández', 'company_name' => 'Almacén Rosa']);

        foreach (['api-equis' => $equis->id, 'quino' => $raro->id] as $pedido => $cliente_que_lo_usa) {
            $respuesta = $this->postJson('/api/claude/implementations', ['lead_id' => $lead->id, 'subdominio' => $pedido], $this->headers());

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('subdominio.valido', false);
            $this->assertStringContainsString('ya lo usa el cliente ' . $cliente_que_lo_usa, (string) $respuesta->json('subdominio.motivo'), $pedido);

            $real = $this->postJson('/api/claude/implementations', ['lead_id' => $lead->id, 'subdominio' => $pedido, 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa'], $this->headers());
            $real->assertStatus(422);
        }

        $libre = $this->postJson('/api/claude/implementations', ['lead_id' => $lead->id, 'subdominio' => 'rosa-libre'], $this->headers());

        $libre->assertStatus(200);
        $libre->assertJsonPath('subdominio.valido', true);
    }

    /* ------------------------------------------------------------------------------------------
     | H6: install nombra el esqueleto fallido
     |----------------------------------------------------------------------------------------- */

    /**
     * Con la real completada y el esqueleto del subdominio hermano fallido, `install` dice que el sistema ya está instalado Y nombra
     * el esqueleto fallido, que por acá no se puede reintentar solo.
     *
     * @return void
     */
    public function test_install_nombra_el_esqueleto_fallido(): void
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_instalacion($cliente, ['kind' => 'completa', 'status' => 'completada']);
        $esqueleto = $this->crear_instalacion($cliente, ['kind' => 'esqueleto', 'status' => 'fallida']);

        $respuesta = $this->postJson('/api/claude/implementations/' . $implementacion->id . '/install', [], $this->headers());

        $respuesta->assertStatus(200);

        $previas = null;
        foreach ($respuesta->json('chequeos') as $chequeo) {
            if ($chequeo['chequeo'] === 'instalaciones_previas') {
                $previas = $chequeo;
            }
        }

        $this->assertNotNull($previas, 'El dry-run no trae el chequeo instalaciones_previas.');
        $this->assertFalse($previas['ok']);
        $this->assertStringContainsString('ya está instalado', $previas['detalle']);
        $this->assertStringContainsString('esqueleto', $previas['detalle']);
        $this->assertStringContainsString((string) $esqueleto->id, $previas['detalle']);
        $this->assertStringContainsString('fallido', $previas['detalle']);
    }

    /* ------------------------------------------------------------------------------------------
     | H7: borrar una implementación limpia sus mails de hito
     |----------------------------------------------------------------------------------------- */

    /**
     * Borrar una implementación desde el panel borra sus filas de `implementation_mails` y no toca las de otra.
     *
     * @return void
     */
    public function test_borrar_una_implementacion_limpia_sus_mails_de_hito(): void
    {
        $cliente_a = $this->crear_cliente('Panchito Gómez');
        $cliente_b = $this->crear_cliente('Rosa Fernández');
        $impl_a    = $this->crear_implementacion($cliente_a);
        $impl_b    = $this->crear_implementacion($cliente_b);

        foreach ([$impl_a, $impl_b] as $impl) {
            DB::table('implementation_mails')->insert([
                'implementation_id' => $impl->id,
                'hito'              => 'bienvenida',
                'email'             => 'dueno@ejemplo.test',
                'asunto'            => 'Arrancamos con la implementación de tu sistema',
                'estado'            => 'enviado',
                'enviado_at'        => now(),
                'reenvios'          => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        app(ImplementationController::class)->destroy($impl_a);

        $this->assertNull(Implementation::find($impl_a->id));
        $this->assertSame(0, DB::table('implementation_mails')->where('implementation_id', $impl_a->id)->count());
        $this->assertSame(1, DB::table('implementation_mails')->where('implementation_id', $impl_b->id)->count(), 'No se toca el registro de otra implementación.');
    }
}
