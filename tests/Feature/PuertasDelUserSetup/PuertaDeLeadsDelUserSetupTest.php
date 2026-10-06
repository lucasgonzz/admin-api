<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Exceptions\UserSetupBloqueadoException;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Lead;
use App\Services\RunUserSetupService;

/**
 * Puerta 2 del user setup: el botón "Crear sistema" de LEADS (`RunUserSetupService::run()`), misión `puertas-del-user-setup`, 6/10/2026.
 *
 * 🔴 Esta puerta le pega al MISMO endpoint remoto que la de implementaciones (`admin-sync/user-setup`, que hace `migrate:fresh`) y no
 * chequeaba nada más que `status === 'cerrado_ganado'`: se podía volver a apretar tras un `exitoso`, y `ensure_production_client()`
 * PISABA el nombre, la razón social y `is_active` del cliente antes de decidir nada. Ahora pasa por el mismo candado que las demás
 * puertas, ANTES de tocar nada:
 *  - si el sistema del cliente ya opera o ya se configuró (un sistema vivo, el user setup de este lead —o del lead del que salió el
 *    cliente— ya aplicado o en vuelo, una implementación que ya lo aplicó o lo tiene en curso) NO se llama a nadie y no se escribe
 *    NADA: ni el estado del lead (un `exitoso` no puede pasar a `fallido` por un intento frenado: es la señal que leen los demás
 *    candados) ni el cliente;
 *  - `fallido` y `pendiente` siguen pudiendo reintentar: es el flujo de `/instalar-cliente`;
 *  - la ruta web redirige al lead con el motivo; la API (`POST lead/{id}/run-user-setup`, la que usa la skill) contesta 422 con
 *    `bloqueado: true` y los `chequeos`, claves aditivas.
 */
class PuertaDeLeadsDelUserSetupTest extends BaseDeLasPuertasDelUserSetup
{
    /** El pedido que sale cuando el user setup SÍ se aplica por esta puerta (no normaliza la URL: es lo que hace hoy). */
    const PEDIDO = 'POST https://api-panchito.ejemplo.test/api/admin-sync/user-setup';

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Un lead cerrado ganado, promovido a un cliente con su API activa en una URL `.test` (la forma en que lo deja `PromoteLeadToClientService`).
     * El cliente nace INACTIVO y con un nombre que el lead no tiene: así se nota si `ensure_production_client()` lo pisó.
     *
     * @param array<string, mixed> $atributos_del_lead Atributos a sumar o pisar del lead (`user_setup_status`, `status`...).
     *
     * @return array{lead: Lead, cliente: Client}
     */
    private function lead_listo(array $atributos_del_lead = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['company_name' => self::NEGOCIO, 'is_active' => false]);
        $cliente = $this->crear_las_dos_apis($cliente, 'panchito');

        ClientApi::where('id', $cliente->active_client_api_id)->update(['url' => self::URL_API]);

        $lead = $this->crear_lead(array_merge([
            'status'             => 'cerrado_ganado',
            'promoted_client_id' => $cliente->id,
            'user_setup_status'  => 'pendiente',
        ], $atributos_del_lead));

        return ['lead' => $lead, 'cliente' => $cliente->refresh()];
    }

    /**
     * El POST de la API del panel (el que usa la skill `/instalar-cliente`).
     *
     * @param Lead $lead El lead.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function apretar_el_boton_de_la_api(Lead $lead)
    {
        return $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/lead/' . $lead->id . '/run-user-setup');
    }

    /**
     * Los datos del cliente que `ensure_production_client()` pisa: tienen que quedar como estaban si el candado frenó.
     *
     * @param Client $cliente El cliente.
     *
     * @return array<string, mixed>
     */
    private function datos_del_cliente(Client $cliente): array
    {
        $fresco = Client::find($cliente->id);

        return ['name' => $fresco->name, 'company_name' => $fresco->company_name, 'is_active' => (bool) $fresco->is_active];
    }

    /* ------------------------------------------------------------------------------------------
     | Lo que frena el candado
     |----------------------------------------------------------------------------------------- */

    /**
     * (a) 🔴 Con el user setup ya `exitoso`: 422 `bloqueado`, NINGÚN pedido, el estado del lead SIGUE `exitoso` y el cliente no se toca
     * (nombre, razón social e `is_active` intactos). Antes se podía volver a apretar y el setup corría otra vez.
     *
     * @return void
     */
    public function test_con_el_user_setup_exitoso_no_llama_y_no_toca_ni_al_lead_ni_al_cliente(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo(['user_setup_status' => 'exitoso', 'user_setup_last_error' => null]);
        $antes   = $this->datos_del_cliente($e['cliente']);

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('bloqueado', true);
        $this->assertStringStartsWith('No se creó el sistema: ', $respuesta->json('message'));
        $this->assertSame([], $sistema->pedidos, 'El botón de leads volvió a llamar al sistema de un cliente que ya está configurado.');

        $this->assertSame('exitoso', $e['lead']->refresh()->user_setup_status, 'Un intento frenado no puede cambiar el estado del lead: es la señal de los demás candados.');
        $this->assertNull($e['lead']->user_setup_last_error);
        $this->assertSame($antes, $this->datos_del_cliente($e['cliente']), 'Un intento frenado le pisó datos al cliente.');
    }

    /**
     * El 422 trae lo mismo que `claude/*`: `message`, `model`, `bloqueado: true` y los chequeos que fallaron, cada uno con `chequeo`,
     * `ok: false` y `detalle`. Claves aditivas: la skill ya trata un no-200 con `message`.
     *
     * @return void
     */
    public function test_el_422_trae_el_mensaje_el_modelo_y_los_chequeos_que_fallaron(): void
    {
        $this->falsear_el_sistema_del_cliente();
        $e = $this->lead_listo(['user_setup_status' => 'exitoso']);

        $this->agregar_un_sistema_vivo($e['cliente']);

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('model.id', $e['lead']->id);

        $nombres = array_column($respuesta->json('chequeos'), 'chequeo');
        $this->assertSame(['sin_sistema_vivo', 'lead_sin_user_setup'], $nombres);

        foreach ($respuesta->json('chequeos') as $chequeo) {
            $this->assertFalse($chequeo['ok']);
            $this->assertNotSame('', $chequeo['detalle']);
        }

        $this->assertStringContainsString('(sin_sistema_vivo y lead_sin_user_setup)', $respuesta->json('message'));
    }

    /**
     * (b) 🔴 Un cliente con actualizaciones registradas (`client_version_upgrades`) es un sistema vivo: bloqueado, aunque el lead esté `pendiente`.
     *
     * @return void
     */
    public function test_con_un_sistema_vivo_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo();
        $antes   = $this->datos_del_cliente($e['cliente']);

        $this->agregar_un_sistema_vivo($e['cliente']);

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('bloqueado', true);
        $this->assertSame(['sin_sistema_vivo'], array_column($respuesta->json('chequeos'), 'chequeo'));
        $this->assertSame([], $sistema->pedidos);
        $this->assertSame('pendiente', $e['lead']->refresh()->user_setup_status);
        $this->assertSame($antes, $this->datos_del_cliente($e['cliente']));
    }

    /**
     * (c) 🔴 Con una implementación del cliente que ya aplicó el user setup (candado lleno): bloqueado. Esa puerta llama al mismo endpoint y
     * no escribe el candado de este camino.
     *
     * @return void
     */
    public function test_con_una_implementacion_que_ya_aplico_el_user_setup_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo();

        $implementacion = $this->crear_implementacion($e['cliente']);
        $this->llenar_el_candado($implementacion);

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('bloqueado', true);
        $this->assertSame(['implementacion_sin_user_setup'], array_column($respuesta->json('chequeos'), 'chequeo'));
        $this->assertStringContainsString('ya aplicó el user setup', $respuesta->json('chequeos.0.detalle'));
        $this->assertSame([], $sistema->pedidos);
        $this->assertSame('pendiente', $e['lead']->refresh()->user_setup_status);
    }

    /**
     * Con una implementación que tiene el user setup EN CURSO (el job de `claude/*` está corriendo): bloqueado. Uno colgado (más de 45
     * minutos sin señal) no cuenta, igual que en `claude/*`: ahí hay que resolverlo, no esperarlo.
     *
     * @return void
     */
    public function test_con_una_implementacion_con_el_user_setup_en_curso_no_llama_y_si_esta_colgado_pasa(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo();

        $implementacion = $this->crear_implementacion($e['cliente']);
        $this->escribir_data_de_la_etapa($implementacion, 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(5)->toISOString(), 'terminado_at' => null, 'error' => null]]);

        $this->apretar_el_boton_de_la_api($e['lead'])->assertStatus(422)->assertJsonPath('bloqueado', true);
        $this->assertSame([], $sistema->pedidos);

        /* El mismo registro, pero sin señal hace una hora y media: se da por colgado y no frena. */
        $this->escribir_data_de_la_etapa($implementacion, 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(90)->toISOString(), 'terminado_at' => null, 'error' => null]]);

        $this->apretar_el_boton_de_la_api($e['lead'])->assertStatus(200);
        $this->assertSame([self::PEDIDO], $sistema->pedidos);
    }

    /**
     * (d) 🔴 `ejecutandose` (la llamada está en vuelo, o se cortó sin terminar) y `sin_confirmar` (salió y no se sabe cómo terminó) bloquean:
     * volver a apretar es otro `migrate:fresh` sobre algo que pudo haber corrido.
     *
     * @return void
     */
    public function test_con_el_user_setup_ejecutandose_o_sin_confirmar_no_llama(): void
    {
        foreach (['ejecutandose', 'sin_confirmar'] as $estado) {
            $sistema = $this->falsear_el_sistema_del_cliente();
            $e       = $this->lead_listo(['user_setup_status' => $estado]);

            $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

            $respuesta->assertStatus(422);
            $respuesta->assertJsonPath('bloqueado', true);
            $this->assertStringContainsString('"' . $estado . '"', $respuesta->json('chequeos.0.detalle'), $estado);
            $this->assertSame([], $sistema->pedidos, 'Llamó con el user setup en ' . $estado . '.');
            $this->assertSame($estado, $e['lead']->refresh()->user_setup_status);
        }
    }

    /**
     * Si el cliente salió de MÁS DE UN lead, el user setup aplicado por el último lead promovido también frena a este.
     *
     * @return void
     */
    public function test_el_user_setup_del_ultimo_lead_promovido_al_cliente_tambien_frena(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo();

        /* Otro lead (más nuevo) promovido al mismo cliente, con el setup ya aplicado. */
        $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('El user setup del lead del que salió este cliente', $respuesta->json('chequeos.0.detalle'));
        $this->assertSame([], $sistema->pedidos);
    }

    /**
     * 🔴 Aun sin cliente promovido (se va a crear al aplicar el setup) el estado del PROPIO lead frena: un `exitoso` no se pisa, y no se
     * crea ningún cliente por un intento frenado.
     *
     * @return void
     */
    public function test_un_lead_sin_cliente_promovido_con_el_setup_exitoso_tampoco_llama_ni_crea_el_cliente(): void
    {
        $sistema  = $this->falsear_el_sistema_del_cliente();
        $lead     = $this->crear_lead(['status' => 'cerrado_ganado', 'user_setup_status' => 'exitoso']);
        $clientes = Client::count();

        $respuesta = $this->apretar_el_boton_de_la_api($lead);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('bloqueado', true);
        $this->assertSame([], $sistema->pedidos);
        $this->assertSame($clientes, Client::count(), 'Un intento frenado creó un cliente.');
        $this->assertNull($lead->refresh()->promoted_client_id);
        $this->assertSame('exitoso', $lead->user_setup_status);
    }

    /**
     * 🔴 Un lead que dice `exitoso` pero ya NO está en cerrado ganado no pasa a `fallido`: el candado va antes que el chequeo del estado
     * del lead, porque `mark_failed()` pisaría la señal que leen los demás candados.
     *
     * @return void
     */
    public function test_un_lead_exitoso_que_ya_no_esta_cerrado_ganado_no_pasa_a_fallido(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo(['status' => 'closer_activo', 'user_setup_status' => 'exitoso']);

        $this->apretar_el_boton_de_la_api($e['lead'])->assertStatus(422)->assertJsonPath('bloqueado', true);

        $this->assertSame([], $sistema->pedidos);
        $this->assertSame('exitoso', $e['lead']->refresh()->user_setup_status);
    }

    /**
     * El servicio lanza `UserSetupBloqueadoException` con los chequeos que fallaron (es lo que atrapan los dos controladores).
     *
     * @return void
     */
    public function test_el_servicio_lanza_la_excepcion_con_los_chequeos(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo(['user_setup_status' => 'exitoso']);

        try {
            app(RunUserSetupService::class)->run($e['lead']);

            $this->fail('Tenía que lanzar UserSetupBloqueadoException.');
        } catch (UserSetupBloqueadoException $bloqueo) {
            $this->assertSame(['lead_sin_user_setup'], array_column($bloqueo->chequeos(), 'chequeo'));
            $this->assertStringContainsString('migrate:fresh', $bloqueo->getMessage());
        }

        $this->assertSame([], $sistema->pedidos);
    }

    /* ------------------------------------------------------------------------------------------
     | Lo que NO frena: el flujo de /instalar-cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * (e) `fallido` y `pendiente` SIGUEN pudiendo reintentar: es el flujo de `/instalar-cliente`. El pedido sale al destino de siempre y
     * el lead queda `exitoso`.
     *
     * @return void
     */
    public function test_fallido_y_pendiente_siguen_pudiendo_reintentar(): void
    {
        foreach (['fallido', 'pendiente'] as $estado) {
            $sistema = $this->falsear_el_sistema_del_cliente();
            $e       = $this->lead_listo(['user_setup_status' => $estado, 'user_setup_last_error' => $estado === 'fallido' ? 'HTTP 500: se cortó' : null]);

            $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

            $respuesta->assertStatus(200);
            $this->assertSame([self::PEDIDO], $sistema->pedidos, $estado);
            $this->assertSame('exitoso', $e['lead']->refresh()->user_setup_status, $estado);
        }
    }

    /**
     * (f) La primera corrida (el flujo normal): lead `pendiente`, nada de lo que frena. 200 con el modelo, `exitoso`, y el pedido sale.
     *
     * @return void
     */
    public function test_la_primera_corrida_sigue_andando_como_siempre(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo();

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('model.user_setup_status', 'exitoso');
        $this->assertArrayNotHasKey('bloqueado', $respuesta->json());
        $this->assertSame([self::PEDIDO], $sistema->pedidos);
    }

    /**
     * Un fallo REAL del sistema del cliente (500) sigue marcando el lead `fallido` con el motivo y devolviendo 422 SIN `bloqueado`: no es
     * lo mismo que un intento frenado por el candado.
     *
     * @return void
     */
    public function test_un_fallo_real_sigue_siendo_fallido_y_no_es_un_bloqueo(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente(500);
        $e       = $this->lead_listo();

        $respuesta = $this->apretar_el_boton_de_la_api($e['lead']);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('bloqueado', $respuesta->json());
        $this->assertStringStartsWith('No se pudo crear el sistema: ', $respuesta->json('message'));
        $this->assertSame([self::PEDIDO], $sistema->pedidos);
        $this->assertSame('fallido', $e['lead']->refresh()->user_setup_status);
    }

    /* ------------------------------------------------------------------------------------------
     | La ruta web
     |----------------------------------------------------------------------------------------- */

    /**
     * (g) La ruta web (`leads.run_user_setup`) vuelve al lead con el motivo en `error`, sin llamar y sin tocar nada.
     *
     * @return void
     */
    public function test_la_ruta_web_redirige_al_lead_con_el_motivo(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo(['user_setup_status' => 'exitoso']);
        $antes   = $this->datos_del_cliente($e['cliente']);

        $respuesta = $this->actingAs($this->crear_admin())->post(route('leads.run_user_setup', $e['lead']->id));

        $respuesta->assertRedirect(route('leads.show', $e['lead']->id));
        $respuesta->assertSessionHas('error');
        $this->assertStringStartsWith('No se creó el sistema: ', session('error'));
        $this->assertStringContainsString('lead_sin_user_setup', session('error'));
        $this->assertSame([], $sistema->pedidos);
        $this->assertSame('exitoso', $e['lead']->refresh()->user_setup_status);
        $this->assertSame($antes, $this->datos_del_cliente($e['cliente']));
    }

    /**
     * La ruta web, cuando nada frena, sigue andando como siempre: redirige con el `success` y el pedido sale.
     *
     * @return void
     */
    public function test_la_ruta_web_sin_bloqueo_sigue_andando_como_siempre(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->lead_listo();

        $respuesta = $this->actingAs($this->crear_admin())->post(route('leads.run_user_setup', $e['lead']->id));

        $respuesta->assertRedirect(route('leads.show', $e['lead']->id));
        $respuesta->assertSessionHas('success', 'Sistema real creado correctamente.');
        $this->assertSame([self::PEDIDO], $sistema->pedidos);
    }
}
