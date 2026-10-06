<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Models\Admin;
use App\Models\ClientInstallation;
use App\Models\Implementation;
use App\Services\ImplementationConversationService;
use App\Services\WhatsappSendService;

/**
 * Puerta 1 del user setup: el MODO AUTOMÁTICO de la conversación (misión `puertas-del-user-setup`, 6/10/2026).
 *
 * 🔴 `ImplementationConversationService::handle_stage_advance(…, 2)` con `automation_mode = 'auto'` le pegaba al endpoint
 * `admin-sync/user-setup` del sistema del cliente —que hace `migrate:fresh`— sin mirar nada, e ignoraba el resultado (ni siquiera
 * llenaba el candado de la implementación). Es la puerta que disparó el incidente de Panchito (5/10/2026, en un test). Ahora, ANTES
 * de llamar, evalúa los nueve chequeos de `claude/implementations/{id}/user-setup` (acá no hay nadie mirando: vale el criterio más
 * conservador) y:
 *  - si alguno falla NO llama: deja el rastro en la etapa 2 (`data.user_setup_automatico`, a propósito distinto de `data.user_setup`,
 *    que es del job de `claude/*`), loguea y suma una línea al aviso que ya recibe el admin asignado;
 *  - si todo pasa, llama (con el punto de llamada protegido) y, si salió bien, CIERRA el candado y registra la acción.
 *
 * Consecuencia que se declara: en un cliente NUEVO la instalación todavía no existe al entrar a la etapa 2 (la crea
 * `ensure_client_installation()` justo después), así que el modo automático no aplica solo el user setup. El resto del flujo (crear
 * la instalación, avisar al admin) sigue igual, y el modo manual no cambia.
 */
class ModoAutomaticoDelUserSetupTest extends BaseDeLasPuertasDelUserSetup
{
    /** Teléfono del admin asignado de las pruebas. */
    const TELEFONO_DEL_ADMIN = '+5493415550001';

    /**
     * El `phone` del admin: el aviso lo lee de ahí (ver `con_admin_asignado()`). Se quita el listener al terminar cada test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Admin::getEventDispatcher()->forget('eloquent.retrieved: ' . Admin::class);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Helpers
     |----------------------------------------------------------------------------------------- */

    /**
     * Un WhatsappSendService que anota lo que se le manda y no sale a ningún lado.
     *
     * @return WhatsappSendService Con `enviados`: lista de `['to' => ..., 'body' => ...]`.
     */
    private function whatsapp_que_anota()
    {
        return new class extends WhatsappSendService {
            /** @var array<int, array<string, string>> */
            public $enviados = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->enviados[] = ['to' => $to, 'body' => $body];

                return 'wamid-de-prueba-' . count($this->enviados);
            }
        };
    }

    /**
     * Le asigna a la implementación un admin con teléfono.
     *
     * 🔴 `notify_assigned_admin()` lee `$admin->phone`, pero la tabla `admins` tiene `phone_number` y no `phone`: en producción ese aviso
     * no sale nunca (hallazgo de esta misión, fuera de su alcance). Para poder mirar QUÉ se le avisaría, el test le pone `phone` al admin
     * en memoria cuando se lo lee (evento `retrieved`); no toca la base ni el código del servicio.
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return void
     */
    private function con_admin_asignado(Implementation $implementacion): void
    {
        $admin = $this->crear_admin();

        $implementacion->assigned_admin_id = $admin->id;
        $implementacion->save();

        Admin::retrieved(function ($leido) {
            $leido->setAttribute('phone', self::TELEFONO_DEL_ADMIN);
        });
    }

    /**
     * Avanza la implementación a la etapa 2 por el servicio, como lo hace el panel y el envío del formulario.
     *
     * @param Implementation          $implementacion La implementación (ya en la etapa 2).
     * @param WhatsappSendService|null $whatsapp       El envío de WhatsApp (por defecto uno que anota).
     *
     * @return void
     */
    private function avanzar_a_la_etapa_2(Implementation $implementacion, $whatsapp = null): void
    {
        $servicio = new ImplementationConversationService($whatsapp !== null ? $whatsapp : $this->whatsapp_que_anota());

        $servicio->handle_stage_advance($implementacion->refresh(), 2);
    }

    /**
     * El rastro del modo automático en la etapa 2.
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return array<string, mixed>|null Null si no hay rastro.
     */
    private function rastro(Implementation $implementacion): ?array
    {
        $data = $this->data_de_la_etapa($implementacion->refresh(), 2);

        return isset($data['user_setup_automatico']) ? $data['user_setup_automatico'] : null;
    }

    /**
     * Los nombres de los chequeos que frenaron, según el rastro.
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return array<int, string>
     */
    private function motivos_del_rastro(Implementation $implementacion): array
    {
        $rastro = $this->rastro($implementacion);

        $this->assertNotNull($rastro, 'No quedó el rastro de user_setup_automatico en la etapa 2.');

        return array_column($rastro['motivos'], 'chequeo');
    }

    /* ------------------------------------------------------------------------------------------
     | Lo que frena el candado
     |----------------------------------------------------------------------------------------- */

    /**
     * (a) 🔴 Un cliente NUEVO no tiene instalación al entrar a la etapa 2 (la crea `ensure_client_installation()` después): el modo
     * automático NO llama, deja el rastro `no_aplicado` con el motivo, y el resto del flujo sigue (la instalación se crea).
     *
     * @return void
     */
    public function test_con_un_cliente_nuevo_sin_instalacion_no_llama_y_el_resto_del_flujo_sigue(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto', 'instalacion' => false]);

        $this->assertSame(0, ClientInstallation::where('client_id', $e['cliente']->id)->count());

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertSame([], $sistema->pedidos, 'El modo automático llamó al sistema del cliente sin instalación.');
        $this->assertSame(['instalacion_completada'], $this->motivos_del_rastro($e['implementacion']));
        $this->assertSame('no_aplicado', $this->rastro($e['implementacion'])['estado']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);

        /* El resto del flujo no cambia: la instalación se crea para que aparezca en el módulo de Instalaciones. */
        $this->assertSame(1, ClientInstallation::where('client_id', $e['cliente']->id)->where('status', 'pendiente')->count());
    }

    /**
     * (b) 🔴 Con la instalación completada pero un sistema vivo (el cliente ya tiene actualizaciones registradas): no llama.
     *
     * @return void
     */
    public function test_con_un_sistema_vivo_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $this->agregar_un_sistema_vivo($e['cliente']);

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertSame([], $sistema->pedidos, 'El modo automático le pegó al sistema de un cliente que ya tiene un sistema vivo.');
        $this->assertSame(['sin_sistema_vivo'], $this->motivos_del_rastro($e['implementacion']));
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * (c) 🔴 Con el user setup ya aplicado por el camino de LEADS (`exitoso`): no llama.
     *
     * @return void
     */
    public function test_con_el_user_setup_del_lead_exitoso_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertSame([], $sistema->pedidos);
        $this->assertSame(['lead_sin_user_setup'], $this->motivos_del_rastro($e['implementacion']));
    }

    /**
     * (d) 🔴 Con el candado de la implementación lleno (el user setup ya se aplicó): no llama, y no lo vuelve a estampar.
     *
     * @return void
     */
    public function test_con_el_candado_lleno_no_llama_ni_lo_vuelve_a_estampar(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $impl   = $this->llenar_el_candado($e['implementacion']);
        $antes  = $impl->user_setup_executed_at->toDateTimeString();

        $this->avanzar_a_la_etapa_2($impl);

        $this->assertSame([], $sistema->pedidos);
        $this->assertSame(['sin_aplicar_antes'], $this->motivos_del_rastro($impl));
        $this->assertSame($antes, $impl->refresh()->user_setup_executed_at->toDateTimeString());
    }

    /**
     * Con una instalación completada ANTERIOR al arranque de la implementación (un sistema que ya existía): no llama.
     *
     * @return void
     */
    public function test_con_una_instalacion_anterior_a_la_implementacion_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $impl = $this->arrancar_la_implementacion_despues_de_la_instalacion($e['implementacion']);

        $this->avanzar_a_la_etapa_2($impl);

        $this->assertSame([], $sistema->pedidos);
        $this->assertSame(['instalacion_de_esta_implementacion'], $this->motivos_del_rastro($impl));
    }

    /**
     * Sin formulario enviado no llama (precondición del plan estricto): el payload no tendría datos reales. La etapa 1 puede estar
     * `completed` (alguien apretó "Avanzar etapa") sin que el cliente haya cargado nada: eso NO cuenta como formulario enviado.
     *
     * @return void
     */
    public function test_sin_formulario_enviado_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto', 'formulario' => false, 'sin_datos_del_formulario' => true]);

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertSame([], $sistema->pedidos);
        $this->assertSame(['formulario_enviado'], $this->motivos_del_rastro($e['implementacion']));
    }

    /* ------------------------------------------------------------------------------------------
     | Lo que NO frena: todo en orden
     |----------------------------------------------------------------------------------------- */

    /**
     * (e) Con todo en orden (la instalación es posterior a la implementación, etapa 2, formulario enviado, sin señales de un sistema
     * que ya opera): LLAMA, a la URL normalizada, y CIERRA el candado: `user_setup_executed_at` queda lleno y la acción `user_setup`
     * registrada con el canal `automatico`. Antes de la misión el resultado se ignoraba.
     *
     * @return void
     */
    public function test_con_todo_en_orden_llama_y_cierra_el_candado(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);

        $impl = $e['implementacion']->refresh();
        $this->assertNotNull($impl->user_setup_executed_at, 'El modo automático aplicó el setup pero no cerró el candado.');

        $acciones = array_values(array_filter($this->data_de_la_etapa($impl, 2)['actions'], function ($accion) {
            return $accion['action'] === 'user_setup';
        }));
        $this->assertCount(1, $acciones);
        $this->assertSame('automatico', $acciones[0]['canal']);
        $this->assertSame(2, $acciones[0]['stage']);

        $this->assertSame('aplicado', $this->rastro($impl)['estado']);
    }

    /**
     * 🔴 Una vez cerrado el candado, avanzar otra vez a la etapa 2 NO vuelve a llamar (antes el resultado se ignoraba y el botón
     * del panel o `claude/*` podían aplicarlo de nuevo encima).
     *
     * @return void
     */
    public function test_una_vez_cerrado_el_candado_no_se_vuelve_a_llamar(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $this->avanzar_a_la_etapa_2($e['implementacion']);
        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertCount(1, $sistema->pedidos, 'El segundo avance volvió a llamar al sistema del cliente.');
    }

    /**
     * Si el sistema del cliente contesta con error, el candado NO se llena (no se aplicó) y el rastro dice `error` con el motivo.
     *
     * @return void
     */
    public function test_si_el_sistema_del_cliente_contesta_error_no_cierra_el_candado(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente(500);
        $e       = $this->escenario(['automation_mode' => 'auto']);

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertCount(1, $sistema->pedidos);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        $this->assertSame('error', $this->rastro($e['implementacion'])['estado']);
        $this->assertStringContainsString('status 500', $this->rastro($e['implementacion'])['error']);
    }

    /**
     * (f) En modo MANUAL no cambia nada: no llama, no deja rastro, no toca el candado, y la instalación que ya había sigue siendo una.
     *
     * @return void
     */
    public function test_en_modo_manual_no_llama_y_no_cambia_nada(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'manual']);

        $this->avanzar_a_la_etapa_2($e['implementacion']);

        $this->assertSame([], $sistema->pedidos);
        $this->assertNull($this->rastro($e['implementacion']), 'El modo manual no tiene que dejar rastro del modo automático.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        $this->assertSame(1, ClientInstallation::where('client_id', $e['cliente']->id)->count());
    }

    /* ------------------------------------------------------------------------------------------
     | El aviso al admin asignado
     |----------------------------------------------------------------------------------------- */

    /**
     * (g) El aviso que ya recibía el admin asignado ("lista para instalar") lleva UNA línea más cuando la configuración no se aplicó sola:
     * que NO se aplicó, y por qué. Es un solo mensaje, no dos.
     *
     * @return void
     */
    public function test_el_aviso_al_admin_asignado_lleva_el_motivo(): void
    {
        $this->falsear_el_sistema_del_cliente();
        $whatsapp = $this->whatsapp_que_anota();
        $e        = $this->escenario(['automation_mode' => 'auto', 'instalacion' => false]);

        $this->con_admin_asignado($e['implementacion']);
        $this->avanzar_a_la_etapa_2($e['implementacion'], $whatsapp);

        $this->assertCount(1, $whatsapp->enviados, 'Tiene que ser UN solo mensaje al admin.');
        $this->assertSame(self::TELEFONO_DEL_ADMIN, $whatsapp->enviados[0]['to']);

        $mensaje = $whatsapp->enviados[0]['body'];
        $this->assertStringContainsString(self::NEGOCIO . ' lista para instalar. Etapa 2: instalación del sistema.', $mensaje);
        $this->assertStringContainsString('NO se aplicó sola (instalacion_completada)', $mensaje);
        $this->assertStringContainsString('desde el panel', $mensaje);
    }

    /**
     * Con todo en orden el aviso es el de siempre, sin la línea del motivo.
     *
     * @return void
     */
    public function test_con_todo_en_orden_el_aviso_es_el_de_siempre(): void
    {
        $this->falsear_el_sistema_del_cliente();
        $whatsapp = $this->whatsapp_que_anota();
        $e        = $this->escenario(['automation_mode' => 'auto']);

        $this->con_admin_asignado($e['implementacion']);
        $this->avanzar_a_la_etapa_2($e['implementacion'], $whatsapp);

        $this->assertCount(1, $whatsapp->enviados);
        $this->assertSame('🛠️ ' . self::NEGOCIO . ' lista para instalar. Etapa 2: instalación del sistema.', $whatsapp->enviados[0]['body']);
    }

    /* ------------------------------------------------------------------------------------------
     | Las dos entradas a handle_stage_advance(…, 2)
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El botón "Avanzar etapa" del panel (`POST implementation/{id}/advance-stage`) también pasa por el candado: una implementación
     * `auto` que avanza de la etapa 1 a la 2 con un cliente que ya tiene un sistema vivo no llama.
     *
     * @return void
     */
    public function test_avanzar_la_etapa_desde_el_panel_tambien_pasa_por_el_candado(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['automation_mode' => 'auto', 'etapa' => 1]);

        $this->agregar_un_sistema_vivo($e['cliente']);

        $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $e['implementacion']->id . '/advance-stage')
            ->assertStatus(200);

        $this->assertSame(2, (int) $e['implementacion']->refresh()->current_stage);
        $this->assertSame([], $sistema->pedidos, 'El avance desde el panel le pegó al sistema de un cliente que ya opera.');
        $this->assertSame(['sin_sistema_vivo'], $this->motivos_del_rastro($e['implementacion']));
    }

    /**
     * 🔴 La OTRA entrada a `handle_stage_advance(…, 2)`: el envío del formulario por el cliente (`handle_form_submitted()`) en modo
     * automático avanza solo a la etapa 2, y tampoco llama si el cliente ya tiene un sistema vivo.
     *
     * @return void
     */
    public function test_el_envio_del_formulario_en_modo_automatico_tambien_pasa_por_el_candado(): void
    {
        $sistema  = $this->falsear_el_sistema_del_cliente();
        $whatsapp = $this->whatsapp_que_anota();
        $e        = $this->escenario(['automation_mode' => 'auto', 'etapa' => 1]);

        $this->agregar_un_sistema_vivo($e['cliente']);

        (new ImplementationConversationService($whatsapp))->handle_form_submitted($e['implementacion']->refresh());

        $this->assertSame(2, (int) $e['implementacion']->refresh()->current_stage, 'El formulario enviado no avanzó la implementación a la etapa 2.');
        $this->assertSame([], $sistema->pedidos, 'El envío del formulario le pegó al sistema de un cliente que ya opera.');
        $this->assertSame(['sin_sistema_vivo'], $this->motivos_del_rastro($e['implementacion']));
    }
}
