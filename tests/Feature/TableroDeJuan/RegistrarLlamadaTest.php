<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\AdminTask;
use App\Models\Lead;
use App\Models\PipelineActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CA3, CA4, CA5 y CA6 de la etapa E1 (misión embudo-juan-pipeline): «Registrar llamada», el botón con el que Juan deja
 * asentado qué habló con el lead (`POST lead/{id}/llamadas`).
 *
 * Es un test de endpoint real: con el admin logueado por Sanctum, contra las rutas y la base. Qué se protege:
 *  1. CA3 — Si no atendió, la tarjeta va a «Volver a llamar» con la fecha dada; sin fecha (o con una fecha pasada) es
 *     422 con `errors.proxima_llamada_at` y NO queda nada guardado.
 *  2. CA4 — Cada resultado tiene su columna, su próxima acción y su efecto sobre el estado del lead (tabla de §2.1.3 del
 *     plan): `sigue_solo` agenda el día 5 de la prueba, `implementacion_acordada` pasa al lead a `implementacion_pedida`,
 *     `pago_la_mitad` a `en_implementacion`, `no_le_interesa` lo cierra perdido con el motivo, `ya_contrato` lo lleva a
 *     «Cliente» y le deja una tarea a Lucas (el estado NO cambia: lo cambia Lucas cuando registra el contrato).
 *  3. CA5 — La llamada cierra las tareas del embudo de ESE lead (`created_via` que empieza con `embudo_`) y no toca las
 *     demás; deja una nota con canal `call` en el historial; un lead sin tarjeta la obtiene; y dos POST seguidos no
 *     crean dos tarjetas.
 *  4. CA6 — «Cotizado» y la última llamada viajan en la tarjeta del tablero.
 *
 * El «doble clic» real (dos requests simultáneos) no se puede reproducir en un test de un solo proceso: lo que se prueba
 * es el efecto que el candado tiene que garantizar, que dos POST seguidos dejen UNA tarjeta y dos llamadas.
 *
 * Hoy están EN ROJO: la ruta `POST lead/{id}/llamadas`, la tabla `lead_llamadas` y el seeder del tablero no existen.
 */
class RegistrarLlamadaTest extends BaseDelTableroDeJuan
{
    /** @var \App\Models\Admin El admin que llama (Lucas, un admin común: la ruta es para todos los admins). */
    private $quien_llama;

    /**
     * Siembra el tablero, crea al admin que llama y lo loguea.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();

        $this->quien_llama = $this->crear_admin_del_tablero('Lucas');
        $this->loguear_a($this->quien_llama);
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * Registra una llamada con el payload que manda la SPA.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $payload
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function llamar(Lead $lead, array $payload)
    {
        return $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', $payload);
    }

    /**
     * Cuántas llamadas hay guardadas del lead.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function cantidad_de_llamadas(Lead $lead): int
    {
        return (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count();
    }

    /**
     * Un lead con su tarjeta ya en «Interesados» (el caso normal: Juan llama a alguien que ya está en el tablero).
     *
     * @param string $estado Estado del lead.
     *
     * @return array{0: Lead, 1: \App\Models\PipelineOpportunity}
     */
    private function lead_con_tarjeta(string $estado = 'link_enviado'): array
    {
        $lead = $this->crear_lead_del_tablero($estado);

        return [$lead, $this->dar_de_alta_en($lead, 'interesados')];
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — no atendió
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. No atendió: 201, una fila en `lead_llamadas`, la tarjeta en «Volver a llamar» con la fecha dada como próxima
     * acción manual, y el estado del lead sin cambio.
     *
     * @return void
     */
    public function test_no_atendio_mueve_a_volver_a_llamar(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('link_enviado');

        $respuesta = $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-25 11:00', 'notas' => 'No contestó, dejé mensaje.']);

        $respuesta->assertStatus(201);
        $this->assertSame(1, $this->cantidad_de_llamadas($lead), 'Queda una fila en lead_llamadas.');

        $fila = DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->first();
        $this->assertFalse((bool) $fila->atendio);
        $this->assertSame((int) $this->quien_llama->id, (int) $fila->admin_id, 'Queda registrado quién llamó.');
        $this->assertSame('No contestó, dejé mensaje.', $fila->notas);
        $this->assertSame('2026-11-25 11:00:00', Carbon::parse($fila->proxima_llamada_at)->format('Y-m-d H:i:s'));

        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjeta), 'La tarjeta va a «Volver a llamar».');
        $this->assertSame('2026-11-25 11:00:00', $this->proxima_accion_de($tarjeta), 'La próxima acción es la fecha dada.');
        $this->assertSame('manual', (string) $tarjeta->fresh()->next_action_source);
        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'No atender no cambia el estado del lead.');

        /* La respuesta trae la llamada y la tarjeta actualizada (forma de PipelinePresenter). */
        $this->assertSame((int) $fila->id, (int) $respuesta->json('llamada.id'));
        $this->assertSame((int) $tarjeta->id, (int) $respuesta->json('oportunidad.id'));
        $this->assertSame('Volver a llamar', $respuesta->json('oportunidad.stage.name'));
    }

    /**
     * CA3. Si no atendió, el `resultado` que mande la pantalla se ignora (aunque sea «no le interesa» sin motivo): la
     * llamada se registra igual y no cierra al lead.
     *
     * @return void
     */
    public function test_no_atendio_ignora_el_resultado(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('contactado');

        $this->llamar($lead, ['atendio' => false, 'resultado' => 'no_le_interesa', 'proxima_llamada_at' => '2026-11-26 09:30'])
            ->assertStatus(201);

        $fila = DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->first();
        $this->assertNull($fila->resultado, 'Sin atender no hay resultado.');
        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'El resultado ignorado no cierra al lead.');
    }

    /**
     * CA3. Dos llamadas seguidas sin atender: la segunda NO falla por «ya está en esa etapa»; sólo corre la próxima acción.
     *
     * @return void
     */
    public function test_dos_llamadas_seguidas_sin_atender(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('contactado');

        $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-25 11:00'])->assertStatus(201);
        $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-27 16:00'])->assertStatus(201);

        $this->assertSame(2, $this->cantidad_de_llamadas($lead));
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'Sigue habiendo una sola tarjeta.');
        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('2026-11-27 16:00:00', $this->proxima_accion_de($tarjeta), 'La segunda fecha reemplaza a la primera.');
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — cada resultado
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. `sigue_solo` con prueba en curso: la tarjeta va a «En prueba por su cuenta» y la próxima acción es el día 5 de
     * su prueba a las 10:00 (inicio 20/11 10:00 → 24/11 10:00), con la nota «Llamada 2 (día 5)».
     *
     * @return void
     */
    public function test_resultado_sigue_solo_con_prueba(): void
    {
        $cliente = $this->crear_cliente_del_tablero([
            'estado'        => 'prueba',
            'trial_days'    => 7,
            'trial_ends_at' => Carbon::parse('2026-11-27 10:00:00', self::$ZONA_DEL_TABLERO),
        ]);
        $lead = $this->crear_lead_del_tablero('prueba_activa', ['promoted_client_id' => $cliente->id]);
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Quiere probar solo unos días.'])
            ->assertStatus(201);

        $this->assertSame('en_prueba_sola', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('2026-11-24 10:00:00', $this->proxima_accion_de($tarjeta), 'Día 5 de la prueba (inicio 2026-11-20 10:00 + 4 días), a las 10:00.');
        $this->assertStringContainsString('Llamada 2 (día 5)', (string) $tarjeta->fresh()->next_action_note);
        $this->assertSame('prueba_activa', (string) $lead->fresh()->status, 'Seguir solo no cambia el estado.');
    }

    /**
     * CA4. `sigue_solo` sin prueba: la próxima acción es dentro de 4 días a las 10:00 (hoy lunes 23/11 → viernes 27/11).
     *
     * @return void
     */
    public function test_resultado_sigue_solo_sin_prueba(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('link_enviado');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'sigue_solo'])->assertStatus(201);

        $this->assertSame('en_prueba_sola', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('2026-11-27 10:00:00', $this->proxima_accion_de($tarjeta), 'Sin prueba: ahora + 4 días, a las 10:00.');
        $this->assertStringContainsString('Llamada 2 (día 5)', (string) $tarjeta->fresh()->next_action_note);
    }

    /**
     * CA4. `implementacion_acordada`: la tarjeta va a «Implementación acordada» con la fecha dada (o, sin fecha, dentro de
     * 2 días a las 10:00) y el lead pasa a `implementacion_pedida`, con la tarea del embudo creada una sola vez.
     *
     * @return void
     */
    public function test_resultado_implementacion_acordada(): void
    {
        /* Con fecha. */
        list($lead, $tarjeta) = $this->lead_con_tarjeta('link_enviado');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'implementacion_acordada', 'proxima_llamada_at' => '2026-11-26 15:00'])
            ->assertStatus(201);

        $this->assertSame('implementacion_acordada', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('2026-11-26 15:00:00', $this->proxima_accion_de($tarjeta));
        $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status, 'Desde link_enviado el lead pasa a implementacion_pedida.');
        $this->assertSame(
            1,
            AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_impl_pedida')->count(),
            'El cambio de estado deja la tarea del embudo, una sola (idempotente).'
        );

        /* Sin fecha: ahora + 2 días a las 10:00. */
        list($otro, $otra_tarjeta) = $this->lead_con_tarjeta('contactado');

        $this->llamar($otro, ['atendio' => true, 'resultado' => 'implementacion_acordada'])->assertStatus(201);

        $this->assertSame('implementacion_acordada', $this->clave_de_la_columna($otra_tarjeta));
        $this->assertSame('2026-11-25 10:00:00', $this->proxima_accion_de($otra_tarjeta), 'Sin fecha: ahora + 2 días, a las 10:00.');
    }

    /**
     * CA4. `implementacion_acordada` pasa a `implementacion_pedida` desde los estados tempranos y desde `prueba_activa`, y
     * NO toca un estado que no es de esa lista.
     *
     * @return void
     */
    public function test_implementacion_acordada_cambia_el_estado_solo_desde_los_estados_de_la_lista(): void
    {
        foreach (['nuevo', 'contactado', 'calificado', 'link_enviado', 'prueba_activa'] as $estado) {
            list($lead) = $this->lead_con_tarjeta($estado);
            $this->llamar($lead, ['atendio' => true, 'resultado' => 'implementacion_acordada'])->assertStatus(201);
            $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status, 'Desde «' . $estado . '» pasa a implementacion_pedida.');
        }

        list($ajeno) = $this->lead_con_tarjeta('demo_realizada');
        $this->llamar($ajeno, ['atendio' => true, 'resultado' => 'implementacion_acordada'])->assertStatus(201);
        $this->assertSame('demo_realizada', (string) $ajeno->fresh()->status, 'Un estado fuera de la lista no se pisa.');
    }

    /**
     * CA4. `pago_la_mitad`: la tarjeta va a «En implementación» sin próxima acción y el lead pasa a `en_implementacion`.
     *
     * @return void
     */
    public function test_resultado_pago_la_mitad(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('implementacion_pedida');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'pago_la_mitad', 'cotizado' => 'Gestión $628.400, mitad $314.200'])
            ->assertStatus(201);

        $this->assertSame('en_implementacion', $this->clave_de_la_columna($tarjeta));
        $this->assertNull($this->proxima_accion_de($tarjeta), 'En implementación no lleva próxima acción.');
        $this->assertSame('en_implementacion', (string) $lead->fresh()->status, 'El lead pasa al estado nuevo `en_implementacion`.');
    }

    /**
     * CA4. `volver_a_llamar` con fecha: «Volver a llamar» con esa fecha; sin fecha, 422 y nada guardado.
     *
     * @return void
     */
    public function test_resultado_volver_a_llamar(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('contactado');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'volver_a_llamar'])->assertStatus(422);
        $this->assertSame(0, $this->cantidad_de_llamadas($lead), 'Sin fecha no se guarda nada.');
        $this->assertSame('interesados', $this->clave_de_la_columna($tarjeta));

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => '2026-12-01 10:00'])
            ->assertStatus(201);

        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('2026-12-01 10:00:00', $this->proxima_accion_de($tarjeta));
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'No cambia el estado.');
    }

    /**
     * CA4. `no_le_interesa` con motivo «Precio»: «No avanza» con `lost_reason = Precio` y el lead `cerrado_perdido`; sin
     * motivo, o con un motivo que no es de los del pipeline, 422.
     *
     * @return void
     */
    public function test_resultado_no_le_interesa(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('link_enviado');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'no_le_interesa'])->assertStatus(422);
        $this->llamar($lead, ['atendio' => true, 'resultado' => 'no_le_interesa', 'motivo' => 'Porque sí'])->assertStatus(422);
        $this->assertSame(0, $this->cantidad_de_llamadas($lead), 'Sin un motivo válido no se guarda nada.');
        $this->assertSame('link_enviado', (string) $lead->fresh()->status);

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'no_le_interesa', 'motivo' => 'Precio', 'notas' => 'Le pareció caro.'])
            ->assertStatus(201);

        $this->assertSame('no_avanza', $this->clave_de_la_columna($tarjeta));
        $this->assertSame('Precio', (string) $tarjeta->fresh()->lost_reason);
        $this->assertNotNull($tarjeta->fresh()->closed_at, 'La tarjeta queda cerrada.');
        $this->assertSame('cerrado_perdido', (string) $lead->fresh()->status, 'El funnel cierra al lead como perdido.');
    }

    /**
     * CA4. `ya_contrato`: la tarjeta va a «Cliente», el estado del lead NO cambia y se crea una tarea para Lucas
     * «Registrar el contrato de <empresa>» con `created_via = tablero_ya_contrato`.
     *
     * @return void
     */
    public function test_resultado_ya_contrato(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('implementacion_pedida');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'ya_contrato', 'notas' => 'Dice que ya le pagó a Lucas.'])
            ->assertStatus(201);

        $this->assertSame('cerrado', $this->clave_de_la_columna($tarjeta), 'La tarjeta va a «Cliente».');
        $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status, 'El estado lo cambia Lucas cuando registra el contrato, no Juan.');

        $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'tablero_ya_contrato')->get();
        $this->assertCount(1, $tareas, 'Se crea una tarea para registrar el contrato.');
        $this->assertStringContainsString('Registrar el contrato de', (string) $tareas[0]->title);
        $this->assertStringContainsString((string) $lead->company_name, (string) $tareas[0]->title);
        $this->assertFalse((bool) $tareas[0]->is_done, 'La tarea para Lucas queda abierta.');
    }

    // ------------------------------------------------------------------------------------------
    // Validaciones
    // ------------------------------------------------------------------------------------------

    /**
     * Los pedidos inválidos: nombre del caso => [payload, campo del que viene el error].
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function pedidos_invalidos_provider(): array
    {
        return [
            'sin saber si atendió'            => [['notas' => 'Hola'], 'atendio'],
            'no atendió y sin fecha'          => [['atendio' => false], 'proxima_llamada_at'],
            'no atendió y fecha pasada'       => [['atendio' => false, 'proxima_llamada_at' => '2026-11-22 09:00'], 'proxima_llamada_at'],
            'atendió y sin resultado'         => [['atendio' => true], 'resultado'],
            'resultado que no existe'         => [['atendio' => true, 'resultado' => 'se_enamoro'], 'resultado'],
            'volver a llamar sin fecha'       => [['atendio' => true, 'resultado' => 'volver_a_llamar'], 'proxima_llamada_at'],
            'no le interesa sin motivo'       => [['atendio' => true, 'resultado' => 'no_le_interesa'], 'motivo'],
            'no le interesa con otro motivo'  => [['atendio' => true, 'resultado' => 'no_le_interesa', 'motivo' => 'Motivo inventado'], 'motivo'],
            'notas de más de 5000 caracteres' => [['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => str_repeat('a', 5001)], 'notas'],
            'cotizado de más de 255'          => [['atendio' => true, 'resultado' => 'sigue_solo', 'cotizado' => str_repeat('9', 256)], 'cotizado'],
        ];
    }

    /**
     * CA3 y CA4. Cada pedido inválido da 422 con el error en `errors.<campo>` y NO deja rastro: ni fila, ni movimiento de
     * la tarjeta, ni cambio de estado, ni tareas.
     *
     * @dataProvider pedidos_invalidos_provider
     *
     * @param array<string, mixed> $payload
     * @param string               $campo_con_error
     *
     * @return void
     */
    public function test_validaciones_422(array $payload, string $campo_con_error): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('link_enviado');
        $tareas_antes = AdminTask::query()->count();

        $respuesta = $this->llamar($lead, $payload);

        $respuesta->assertStatus(422);
        $this->assertIsArray($respuesta->json('errors'), 'El 422 trae `errors` con el campo que falló.');
        $this->assertArrayHasKey($campo_con_error, $respuesta->json('errors'), 'El error viene en errors.' . $campo_con_error . '.');

        $this->assertSame(0, $this->cantidad_de_llamadas($lead), 'Un pedido inválido no guarda la llamada.');
        $this->assertSame('interesados', $this->clave_de_la_columna($tarjeta), 'No mueve la tarjeta.');
        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'No cambia el estado del lead.');
        $this->assertSame($tareas_antes, AdminTask::query()->count(), 'No crea ni cierra tareas.');
    }

    // ------------------------------------------------------------------------------------------
    // CA5 — efectos colaterales
    // ------------------------------------------------------------------------------------------

    /**
     * CA5. La llamada cierra las tareas abiertas del embudo de ese lead (`embudo_prueba_creada` y `embudo_impl_pedida`),
     * con `done_by_admin_id` = quien llamó, y NO toca una tarea común del mismo lead ni una tarea del embudo de otro lead.
     *
     * @return void
     */
    public function test_cierra_tareas_del_embudo_y_no_otras(): void
    {
        list($lead) = $this->lead_con_tarjeta('contactado');
        $otro_lead  = $this->crear_lead_del_tablero('contactado');

        $de_prueba   = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'embudo_prueba_creada']);
        $de_impl     = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'embudo_impl_pedida']);
        $comun       = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'admin']);
        $del_otro    = $this->crear_tarea(['lead_id' => $otro_lead->id, 'created_via' => 'embudo_prueba_creada']);

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => '2026-11-30 10:00'])
            ->assertStatus(201);

        foreach ([$de_prueba, $de_impl] as $tarea) {
            $tarea = $tarea->fresh();
            $this->assertTrue((bool) $tarea->is_done, 'La tarea ' . $tarea->created_via . ' queda hecha.');
            $this->assertNotNull($tarea->done_at);
            $this->assertSame((int) $this->quien_llama->id, (int) $tarea->done_by_admin_id, 'La cierra quien llamó.');
        }

        $this->assertFalse((bool) $comun->fresh()->is_done, 'Una tarea común del mismo lead no se toca.');
        $this->assertNull($comun->fresh()->done_by_admin_id);
        $this->assertFalse((bool) $del_otro->fresh()->is_done, 'La tarea del embudo de OTRO lead no se toca.');
    }

    /**
     * CA5. Con `pago_la_mitad`, `ya_contrato` y `no_le_interesa` también se cierran las tareas del embudo (y la tarea que
     * crea `ya_contrato` para Lucas NO es del embudo: queda abierta).
     *
     * @return void
     */
    public function test_los_resultados_de_cierre_tambien_cierran_las_tareas_del_embudo(): void
    {
        $casos = [
            'pago_la_mitad'  => ['resultado' => 'pago_la_mitad'],
            'ya_contrato'    => ['resultado' => 'ya_contrato'],
            'no_le_interesa' => ['resultado' => 'no_le_interesa', 'motivo' => 'No es el momento'],
        ];

        foreach ($casos as $nombre => $extra) {
            list($lead) = $this->lead_con_tarjeta('implementacion_pedida');
            $tarea = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'embudo_impl_pedida']);

            $this->llamar($lead, array_merge(['atendio' => true], $extra))->assertStatus(201);

            $this->assertTrue((bool) $tarea->fresh()->is_done, 'Con «' . $nombre . '» se cierra la tarea del embudo.');
            $this->assertSame((int) $this->quien_llama->id, (int) $tarea->fresh()->done_by_admin_id);
        }
    }

    /**
     * CA5. La llamada deja una nota en el historial de la tarjeta, con canal `call`, que dice qué se habló y cuánto se cotizó.
     *
     * @return void
     */
    public function test_deja_la_nota_con_canal_call(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('contactado');

        $this->llamar($lead, [
            'atendio'   => true,
            'resultado' => 'sigue_solo',
            'notas'     => 'Tiene dos sucursales y factura con ARCA.',
            'cotizado'  => 'Gestión $628.400',
        ])->assertStatus(201);

        $notas = PipelineActivity::query()->where('opportunity_id', $tarjeta->id)->where('type', 'note')->where('channel', 'call')->get();

        $this->assertCount(1, $notas, 'Queda una actividad `note` con canal `call`.');
        $this->assertStringContainsString('Llamada:', (string) $notas[0]->body);
        $this->assertStringContainsString('Tiene dos sucursales y factura con ARCA.', (string) $notas[0]->body);
        $this->assertStringContainsString('Cotizado: Gestión $628.400', (string) $notas[0]->body);
    }

    /**
     * CA5. Un lead sin tarjeta la obtiene: se crea en «Interesados» y después se mueve a donde corresponde.
     *
     * @return void
     */
    public function test_sin_tarjeta_la_crea(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');
        $this->assertCount(0, $this->tarjetas_del_lead($lead), 'Precondición: el lead no tiene tarjeta.');

        $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-25 11:00'])->assertStatus(201);

        $tarjetas = $this->tarjetas_del_lead($lead);
        $this->assertCount(1, $tarjetas, 'La llamada crea la tarjeta.');
        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjetas[0]), 'Y la deja donde corresponde.');

        $tipos = PipelineActivity::query()->where('opportunity_id', $tarjetas[0]->id)->pluck('type')->all();
        $this->assertContains('created', $tipos, 'Entró en Interesados (actividad created)…');
        $this->assertContains('stage_change', $tipos, '…y después se movió (actividad stage_change).');
    }

    /**
     * CA5. Dos registros seguidos del mismo lead sin tarjeta dejan UNA tarjeta y dos llamadas (el candado del lead).
     *
     * @return void
     */
    public function test_doble_post_una_tarjeta(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');

        $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-25 11:00'])->assertStatus(201);
        $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-25 11:00'])->assertStatus(201);

        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'El doble clic no duplica la tarjeta.');
        $this->assertSame(2, $this->cantidad_de_llamadas($lead), 'Cada registro es una llamada.');
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — «Cotizado» en la tarjeta
    // ------------------------------------------------------------------------------------------

    /**
     * CA6. Después de una llamada con `cotizado`, la tarjeta del tablero (`GET pipelines/{id}/opportunities`) trae el texto
     * exacto y la última llamada; una llamada posterior sin cotizado NO borra lo cotizado; y las tarjetas de clientes traen
     * `cotizado: null`.
     *
     * @return void
     */
    public function test_cotizado_en_la_tarjeta(): void
    {
        list($lead, $tarjeta) = $this->lead_con_tarjeta('implementacion_pedida');
        $cliente = $this->crear_cliente_del_tablero();
        $this->dar_de_alta_en($cliente, 'interesados');

        $texto = 'Gestión $628.400, mitad $314.200';
        $this->llamar($lead, ['atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => '2026-11-26 10:00', 'cotizado' => $texto])
            ->assertStatus(201);

        $tablero = $this->getJson('/api/admin/pipelines/' . $this->pipeline_de_leads()->id . '/opportunities');
        $tablero->assertStatus(200);
        $por_lead = $this->tarjetas_por_sujeto($tablero->json('opportunities'));

        $this->assertSame($texto, $por_lead['lead'][(int) $lead->id]['cotizado'], 'La tarjeta trae lo cotizado, con el texto exacto.');
        $this->assertSame('volver_a_llamar', $por_lead['lead'][(int) $lead->id]['ultima_llamada']['resultado']);
        $this->assertTrue((bool) $por_lead['lead'][(int) $lead->id]['ultima_llamada']['atendio']);
        $this->assertSame('Volver a llamar', $por_lead['lead'][(int) $lead->id]['ultima_llamada']['resultado_label']);
        $this->assertNotEmpty($por_lead['lead'][(int) $lead->id]['ultima_llamada']['fecha']);

        /* Las tarjetas de clientes traen la clave, en null. */
        $tarjeta_del_cliente = $por_lead['client'][(int) $cliente->id];
        $this->assertArrayHasKey('cotizado', $tarjeta_del_cliente, 'La clave `cotizado` viaja siempre.');
        $this->assertNull($tarjeta_del_cliente['cotizado']);

        /* Una llamada después, sin cotizado: lo cotizado sigue siendo lo último que se cotizó. */
        $this->llamar($lead, ['atendio' => false, 'proxima_llamada_at' => '2026-11-27 10:00'])->assertStatus(201);

        $tablero  = $this->getJson('/api/admin/pipelines/' . $this->pipeline_de_leads()->id . '/opportunities');
        $por_lead = $this->tarjetas_por_sujeto($tablero->json('opportunities'));
        $this->assertSame($texto, $por_lead['lead'][(int) $lead->id]['cotizado'], 'Lo último cotizado no se borra con una llamada sin cotizar.');
        $this->assertFalse((bool) $por_lead['lead'][(int) $lead->id]['ultima_llamada']['atendio'], 'La última llamada es la de «no atendió».');
    }

    /**
     * Indexa las tarjetas del tablero por tipo de sujeto y por id (`lead` / `client`).
     *
     * @param array<int, array<string, mixed>> $tarjetas Lo que devuelve `opportunities`.
     *
     * @return array{lead: array<int, array<string, mixed>>, client: array<int, array<string, mixed>>}
     */
    private function tarjetas_por_sujeto(array $tarjetas): array
    {
        $indice = ['lead' => [], 'client' => []];

        foreach ($tarjetas as $tarjeta) {
            if (! empty($tarjeta['lead_id'])) {
                $indice['lead'][(int) $tarjeta['lead_id']] = $tarjeta;
            }
            if (! empty($tarjeta['client_id'])) {
                $indice['client'][(int) $tarjeta['client_id']] = $tarjeta;
            }
        }

        return $indice;
    }

    // ------------------------------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------------------------------

    /**
     * `GET lead/{id}/llamadas` devuelve las últimas 20 llamadas del lead (no las de otro lead), en `models`.
     *
     * @return void
     */
    public function test_el_listado_trae_las_ultimas_20(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');
        $otro = $this->crear_lead_del_tablero('contactado');

        for ($numero = 1; $numero <= 22; $numero++) {
            $this->insertar_llamada($lead, [
                'notas'      => 'LLAMADA-' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT),
                'created_at' => Carbon::now()->subDays(30 - $numero)->format('Y-m-d H:i:s'),
            ]);
        }
        $this->insertar_llamada($otro, ['notas' => 'LLAMADA-DEL-OTRO']);

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id . '/llamadas');
        $respuesta->assertStatus(200);

        $notas = array_column($respuesta->json('models'), 'notas');
        $this->assertCount(20, $notas, 'Trae las últimas 20.');
        $this->assertContains('LLAMADA-22', $notas, 'La más nueva está.');
        $this->assertNotContains('LLAMADA-01', $notas, 'Las dos más viejas quedan afuera.');
        $this->assertNotContains('LLAMADA-02', $notas);
        $this->assertNotContains('LLAMADA-DEL-OTRO', $notas, 'No mezcla llamadas de otro lead.');
    }
}
