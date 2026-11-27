<?php

namespace Tests\Feature\Missions;

use App\Models\MissionAnswer;
use App\Models\MissionQuestion;
use App\Models\MissionTracking;
use App\Models\MissionTrackingEvent;
use App\Services\Missions\MissionTrackingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA1 a CA5 y CA9 de la misión dotin-misiones-admin: el estado que publica la Gamer en `PUT claude/missions/{slug}`,
 * su frescura, la evidencia de «terminada», los eventos y la reconciliación de la pregunta abierta.
 *
 * El reloj se congela en el 26/11/2026 12:00 ART: el Admin estampa `reportado_at` con su propia hora y la frescura
 * se calcula contra ella. Las fechas se comparan en hora de Argentina (`H:i`).
 */
class SeguimientoDeMisionTest extends TestCase
{
    use DatabaseTransactions;
    use MissionFixture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->misiones_preparar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->misiones_soltar_reloj();

        parent::tearDown();
    }

    /**
     * La fila de seguimiento de una misión.
     *
     * @param string $slug Slug.
     *
     * @return MissionTracking|null
     */
    private function fila($slug)
    {
        return MissionTracking::where('mission_key', 'cc:' . $slug)->first();
    }

    /**
     * Una fecha de la base como `H:i` de Argentina (null si está vacía).
     *
     * @param mixed $fecha Atributo datetime.
     *
     * @return string|null
     */
    private function hora_de($fecha)
    {
        return $fecha === null ? null : Carbon::parse($fecha)->setTimezone('America/Argentina/Buenos_Aires')->format('H:i');
    }

    /**
     * Los valores por defecto de `config/missions.php` (el deploy no toca el `.env`).
     *
     * @return void
     */
    public function test_la_configuracion_tiene_sus_valores_por_defecto(): void
    {
        $this->assertSame(15, config('missions.reporte_al_dia_min'));
        $this->assertSame(60, config('missions.reporte_sin_senal_min'));
        $this->assertSame(20, config('missions.actividad_real_max_min'));
        $this->assertSame('', config('missions.answer_admin_emails'));
        $this->assertSame(20, config('missions.pending_limit'));
        $this->assertSame(10, config('missions.eventos_en_detalle'));
    }

    /**
     * CA1: el alta crea la fila (`en_cola`, `reportado_at` = ahora); el mismo cuerpo y un `seq` menor no cambian nada.
     *
     * @return void
     */
    public function test_alta_idempotente_y_seq_viejo(): void
    {
        $alta = $this->misiones_put('demo', ['seq' => 10]);
        $alta->assertStatus(200);
        $this->assertSame('cc:demo', $alta->json('mission_key'));
        $this->assertTrue($alta->json('aplicado'));
        $this->assertTrue($alta->json('creada'));
        $this->assertSame(10, $alta->json('seq'));
        $this->assertSame('en_cola', $alta->json('estado'));

        $fila = $this->fila('demo');
        $this->assertNotNull($fila);
        $this->assertSame('en_cola', $fila->estado);
        $this->assertSame('12:00', $this->hora_de($fila->reportado_at));
        $this->assertSame('lucas-pc', $fila->host);
        $this->assertSame(self::$pedido_de_misiones, $fila->pedido_id);

        // El mismo cuerpo otra vez (mismo seq): no se aplica.
        $repetida = $this->misiones_put('demo', ['seq' => 10], '2026-11-26 12:05:00');
        $repetida->assertStatus(200);
        $this->assertFalse($repetida->json('aplicado'));
        $this->assertSame('seq_viejo', $repetida->json('motivo'));
        $this->assertSame(10, $repetida->json('seq_actual'));

        // Un seq menor con otro estado: tampoco, y la fila queda como estaba.
        $vieja = $this->misiones_put('demo', ['seq' => 9, 'estado' => 'activa']);
        $vieja->assertStatus(200);
        $this->assertFalse($vieja->json('aplicado'));
        $this->assertSame('seq_viejo', $vieja->json('motivo'));

        $this->assertSame(1, MissionTracking::where('mission_key', 'cc:demo')->count());
        $fila = $this->fila('demo');
        $this->assertSame('en_cola', $fila->estado);
        $this->assertSame(10, (int) $fila->seq);
        $this->assertSame('12:00', $this->hora_de($fila->reportado_at), 'Un PUT no aplicado no refresca reportado_at.');
    }

    /**
     * CA2: `fase_desde` sólo cambia cuando cambia la fase; un latido con la misma fase la conserva.
     *
     * @return void
     */
    public function test_entrada_en_etapa_se_conserva_en_latido(): void
    {
        $this->misiones_put('etapa', ['seq' => 10, 'estado' => 'activa'])->assertStatus(200);

        $plan = $this->misiones_put('etapa', ['seq' => 11, 'estado' => 'activa', 'fase' => 'plan', 'fase_desde' => $this->misiones_iso('2026-11-26 11:40:00')], '2026-11-26 12:00:00');
        $plan->assertStatus(200);
        $fila = $this->fila('etapa');
        $this->assertSame('plan', $fila->fase);
        $this->assertSame('plan', $fila->etapa);
        $this->assertSame('11:40', $this->hora_de($fila->fase_desde));
        $this->assertSame('11:40', $this->hora_de(Carbon::parse($plan->json('fase_desde'))));

        // Latido: misma fase, `fase_desde` nuevo en el cuerpo; el Admin conserva el de la entrada en la fase.
        $this->misiones_put('etapa', ['seq' => 12, 'estado' => 'activa', 'fase' => 'plan', 'fase_desde' => $this->misiones_iso('2026-11-26 12:10:00')], '2026-11-26 12:10:00')
            ->assertStatus(200);
        $fila = $this->fila('etapa');
        $this->assertSame('11:40', $this->hora_de($fila->fase_desde), 'Un latido con la misma fase no mueve fase_desde.');
        $this->assertSame('12:10', $this->hora_de($fila->reportado_at), 'Pero sí refresca reportado_at.');

        // Cambia la fase: cambia la entrada y la etapa.
        $this->misiones_put('etapa', ['seq' => 13, 'estado' => 'activa', 'fase' => 'construccion', 'fase_desde' => $this->misiones_iso('2026-11-26 12:20:00')], '2026-11-26 12:20:00')
            ->assertStatus(200);
        $fila = $this->fila('etapa');
        $this->assertSame('construccion', $fila->fase);
        $this->assertSame('construccion', $fila->etapa);
        $this->assertSame('12:20', $this->hora_de($fila->fase_desde));
    }

    /**
     * La etapa sale del mapa fase -> etapa del reporter.
     *
     * @return void
     */
    public function test_etapa_de_cada_fase(): void
    {
        $esperadas = [
            'contexto' => 'plan', 'plan' => 'plan', 'tests-rojo' => 'construccion', 'construccion' => 'construccion',
            'revision-1' => 'revision', 'revision-2' => 'revision', 'interfaz' => 'revision', 'merge-base' => 'tests',
            'suite-final' => 'tests', 'revision-merge' => 'merge', 'merge-local' => 'merge', 'informe' => 'cierre',
            'entregada' => 'cierre', 'fase-rara' => null,
        ];
        foreach ($esperadas as $fase => $etapa) {
            $this->misiones_put('mapa', ['estado' => 'activa', 'fase' => $fase])->assertStatus(200);
            $this->assertSame($etapa, $this->fila('mapa')->etapa, 'Etapa de la fase ' . $fase);
        }
    }

    /**
     * CA2 (estado): `estado_desde` sólo cambia cuando cambia el estado; un estado inválido es 422.
     *
     * @return void
     */
    public function test_estado_desde_se_conserva(): void
    {
        $this->misiones_put('estado', ['estado' => 'activa', 'estado_desde' => $this->misiones_iso('2026-11-26 11:00:00')])->assertStatus(200);
        $this->assertSame('11:00', $this->hora_de($this->fila('estado')->estado_desde));

        // Mismo estado con otro `estado_desde` en el cuerpo: se conserva el de la entrada.
        $this->misiones_put('estado', ['estado' => 'activa', 'estado_desde' => $this->misiones_iso('2026-11-26 12:05:00')], '2026-11-26 12:05:00')->assertStatus(200);
        $this->assertSame('11:00', $this->hora_de($this->fila('estado')->estado_desde));

        // Cambia el estado sin `estado_desde`: se toma la hora del Admin.
        $this->misiones_put('estado', ['estado' => 'pausada_tecnica', 'motivo' => 'senal_9'], '2026-11-26 12:30:00')->assertStatus(200);
        $fila = $this->fila('estado');
        $this->assertSame('pausada_tecnica', $fila->estado);
        $this->assertSame('12:30', $this->hora_de($fila->estado_desde));
        $this->assertSame('senal_9', $fila->motivo);

        $this->misiones_put('estado', ['estado' => 'trabajando'])->assertStatus(422);
        $this->assertSame('pausada_tecnica', $this->fila('estado')->estado);
    }

    /**
     * El contrato del PUT: lo que falta o viene mal es 422; los textos largos se recortan; la ruta exige un slug válido.
     *
     * @return void
     */
    public function test_validaciones_del_put(): void
    {
        $malos = [
            ['seq' => 0], ['seq' => 'uno'], ['host' => null], ['origen' => 'marte'], ['pedido_id' => 'sin-formato'],
            ['hilo' => 'no-es-un-hilo'], ['fase' => 'Fase Con Espacios'], ['repos' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k']],
        ];
        foreach ($malos as $cambio) {
            $this->misiones_put('valida', $cambio)->assertStatus(422);
        }
        $this->assertNull($this->fila('valida'), 'Nada de lo inválido crea la fila.');

        // Los textos largos se recortan a su largo (no son 422).
        $largo = str_repeat('x', 700);
        $this->misiones_put('valida', ['ultimo_avance' => $largo, 'pendiente' => $largo, 'titulo' => str_repeat('t', 300)])->assertStatus(200);
        $fila = $this->fila('valida');
        $this->assertSame(500, mb_strlen($fila->ultimo_avance));
        $this->assertSame(500, mb_strlen($fila->pendiente));
        $this->assertSame(120, mb_strlen($fila->titulo));

        // Un slug fuera del formato no entra por la ruta.
        $this->putJson('/api/claude/missions/Slug%20Malo!', $this->misiones_cuerpo('malo'), $this->misiones_headers())->assertStatus(404);
    }

    /**
     * CA3: los cinco ejemplos de frescura, medidos contra el reloj 26/11/2026 12:00 ART.
     *
     * @return void
     */
    public function test_frescura_cinco_casos(): void
    {
        $servicio = app(MissionTrackingService::class);

        // [slug, hora del reporte, estado, actividad real, vigencia, estado_visible, minutos sin reporte, minutos sin actividad]
        $casos = [
            ['f1', '2026-11-26 11:50:00', 'activa', '2026-11-26 11:55:00', 'al_dia', 'activa', 10, 5],
            ['f2', '2026-11-26 11:44:00', 'activa', '2026-11-26 11:50:00', 'desactualizada', 'activa', 16, 10],
            ['f3', '2026-11-26 10:59:00', 'activa', '2026-11-26 10:58:00', 'sin_senal', 'activa_sin_senal', 61, 62],
            ['f4', '2026-11-26 11:58:00', 'activa', '2026-11-26 11:39:00', 'al_dia', 'activa_sin_senal', 2, 21],
            ['f5', '2026-11-23 12:00:00', 'terminada', null, 'al_dia', 'terminada', 4320, null],
        ];

        foreach ($casos as $caso) {
            list($slug, $reporte, $estado, $actividad, $vigencia, $visible, $sin_reporte, $sin_actividad) = $caso;

            $cambios = ['estado' => $estado, 'ultima_actividad_real_at' => $actividad === null ? null : $this->misiones_iso($actividad)];
            if ($estado === 'terminada') {
                $cambios['evidencia'] = $this->misiones_evidencia();
            }
            $this->misiones_put($slug, $cambios, $reporte)->assertStatus(200);

            // Se mide siempre a las 12:00.
            $this->misiones_congelar('2026-11-26 12:00:00');
            $bloque = $servicio->serializar($this->fila($slug), Carbon::now(), false);

            $this->assertSame($vigencia, $bloque['vigencia'], $slug . ' vigencia');
            $this->assertSame($visible, $bloque['estado_visible'], $slug . ' estado_visible');
            $this->assertSame($estado, $bloque['estado'], $slug . ' estado');
            $this->assertSame($sin_actividad, $bloque['minutos_sin_actividad'], $slug . ' minutos_sin_actividad');
            if ($estado !== 'terminada') {
                $this->assertSame($sin_reporte, $bloque['minutos_sin_reporte'], $slug . ' minutos_sin_reporte');
            }
            $this->assertSame('admin', $bloque['fuente']);
        }
    }

    /**
     * Una misión `activa` que nunca reportó actividad real no figura como «activa»: figura sin señal.
     *
     * @return void
     */
    public function test_activa_sin_actividad_real_es_activa_sin_senal(): void
    {
        $this->misiones_put('muda', ['estado' => 'activa', 'ultima_actividad_real_at' => null])->assertStatus(200);

        $bloque = app(MissionTrackingService::class)->serializar($this->fila('muda'), Carbon::now(), false);

        $this->assertSame('activa_sin_senal', $bloque['estado_visible']);
        $this->assertNull($bloque['minutos_sin_actividad']);
    }

    /**
     * El bloque `seguimiento` trae las claves del contrato (§2.2.4) y los eventos sólo en el detalle.
     *
     * @return void
     */
    public function test_bloque_seguimiento_con_las_claves_del_contrato(): void
    {
        $this->misiones_put('bloque', ['estado' => 'activa', 'fase' => 'construccion', 'ultimo_avance' => 'Va por la unidad 2', 'pendiente' => 'Falta la unidad 3'])->assertStatus(200);
        $servicio = app(MissionTrackingService::class);

        $sin_eventos = $servicio->serializar($this->fila('bloque'), Carbon::now(), false);
        $con_eventos = $servicio->serializar($this->fila('bloque'), Carbon::now(), true);

        $claves = [
            'fuente', 'estado', 'estado_visible', 'estado_desde', 'minutos_en_estado', 'fase', 'etapa', 'fase_desde', 'minutos_en_fase',
            'ultimo_avance', 'pendiente', 'motivo', 'proximo_paso', 'ejecucion_n', 'intentos_tecnicos', 'arranque_confirmado_at',
            'ultima_actividad_real_at', 'reportado_at', 'vigencia', 'minutos_sin_reporte', 'minutos_sin_actividad', 'pregunta',
            'evidencia', 'terminada_at',
        ];
        foreach ($claves as $clave) {
            $this->assertArrayHasKey($clave, $sin_eventos, 'Falta la clave ' . $clave);
        }
        $this->assertArrayNotHasKey('eventos', $sin_eventos);
        $this->assertArrayHasKey('eventos', $con_eventos);
        $this->assertSame('Va por la unidad 2', $sin_eventos['ultimo_avance']);
        $this->assertNull($sin_eventos['pregunta']);
    }

    /**
     * CA4: `terminada` sin evidencia es 422 `terminada_sin_evidencia`; con merges o archivos válidos, 200 y `terminada_at`.
     *
     * @return void
     */
    public function test_terminada_exige_evidencia(): void
    {
        $sin = $this->misiones_put('cierre', ['estado' => 'terminada']);
        $sin->assertStatus(422);
        $this->assertSame('terminada_sin_evidencia', $sin->json('codigo'));
        $this->assertNull($this->fila('cierre'), 'El rechazo no crea la fila.');

        $vacia = $this->misiones_put('cierre', ['estado' => 'terminada', 'evidencia' => ['merges' => []]]);
        $vacia->assertStatus(422);
        $this->assertSame('terminada_sin_evidencia', $vacia->json('codigo'));

        $con_merge = $this->misiones_put('cierre', ['estado' => 'terminada', 'evidencia' => $this->misiones_evidencia()]);
        $con_merge->assertStatus(200);
        $this->assertSame('terminada', $con_merge->json('estado'));
        $fila = $this->fila('cierre');
        $this->assertSame('12:00', $this->hora_de($fila->terminada_at));
        $this->assertSame($this->misiones_evidencia()['merges'], $fila->evidencia['merges']);

        $con_archivo = $this->misiones_put('cierre-b', ['estado' => 'terminada', 'evidencia' => ['archivos' => [['ruta' => 'trabajo/e2e/resultado.txt', 'sha256' => str_repeat('a', 64)]]]]);
        $con_archivo->assertStatus(200);
        $this->assertNotNull($this->fila('cierre-b')->terminada_at);
    }

    /**
     * CA5: los eventos son idempotentes por `event_uid` (201 y después 200 duplicado) y la misión tiene que existir.
     *
     * @return void
     */
    public function test_eventos_idempotentes(): void
    {
        $this->misiones_put('eventos', ['estado' => 'activa'])->assertStatus(200);

        $cuerpo = ['event_uid' => 'evento-0001-abcdef', 'tipo' => 'arranque_confirmado', 'estado' => 'activa', 'fase' => 'plan', 'texto' => 'Arrancó de verdad', 'ocurrido_at' => $this->misiones_iso('2026-11-26 11:59:00')];

        $primero = $this->postJson('/api/claude/missions/eventos/events', $cuerpo, $this->misiones_headers());
        $primero->assertStatus(201);
        $this->assertSame('arranque_confirmado', $primero->json('event.tipo'));

        $segundo = $this->postJson('/api/claude/missions/eventos/events', $cuerpo, $this->misiones_headers());
        $segundo->assertStatus(200);
        $this->assertTrue($segundo->json('duplicado'));
        $this->assertSame(1, MissionTrackingEvent::where('event_uid', 'evento-0001-abcdef')->count());

        $inexistente = $this->postJson('/api/claude/missions/no-existe/events', array_merge($cuerpo, ['event_uid' => 'evento-0002-abcdef']), $this->misiones_headers());
        $inexistente->assertStatus(404);
        $this->assertSame('mision_inexistente', $inexistente->json('codigo'));

        $this->postJson('/api/claude/missions/eventos/events', array_merge($cuerpo, ['event_uid' => 'evento-0003-abcdef', 'tipo' => 'inventado']), $this->misiones_headers())->assertStatus(422);
        $this->postJson('/api/claude/missions/eventos/events', array_merge($cuerpo, ['event_uid' => 'x']), $this->misiones_headers())->assertStatus(422);
    }

    /**
     * CA9: un PUT con `pregunta_abierta: null` cierra la pregunta abierta y rechaza su respuesta registrada.
     *
     * @return void
     */
    public function test_pregunta_abierta_null_cierra_y_rechaza(): void
    {
        $uid = $this->misiones_con_pregunta('recon');
        $respuesta = $this->misiones_sembrar_respuesta('recon', $uid);

        // Latido que NO cierra: la pregunta sigue siendo la abierta.
        $this->misiones_put('recon', ['estado' => 'esperando_decision', 'pregunta_abierta' => ['question_uid' => $uid, 'revision' => 1]])->assertStatus(200);
        $this->assertSame('respondida', MissionQuestion::where('question_uid', $uid)->first()->estado);
        $this->assertSame('registrada', $respuesta->fresh()->estado);

        // La Gamer ya no tiene pregunta abierta: se cierra todo lo pendiente.
        $this->misiones_put('recon', ['estado' => 'activa', 'pregunta_abierta' => null])->assertStatus(200);

        $pregunta = MissionQuestion::where('question_uid', $uid)->first();
        $this->assertSame('cerrada', $pregunta->estado);
        $this->assertSame('cerrada_por_ejecutor', $pregunta->cierre_motivo);
        $this->assertSame('rechazada', $respuesta->fresh()->estado);
        $this->assertSame('cerrada', $respuesta->fresh()->rechazo_motivo);
        $this->assertNull($this->fila('recon')->pregunta_abierta_uid);
    }

    /**
     * Sin la clave de la Gamer todo el bloque `claude/missions/*` es 401 (fail-closed), también con una clave mala.
     *
     * @return void
     */
    public function test_sin_clave_401(): void
    {
        $this->misiones_put('demo')->assertStatus(200);
        $uid = $this->misiones_uuid();
        $id  = $this->misiones_uuid();

        $sin_clave = ['Accept' => 'application/json'];
        $mala      = ['Accept' => 'application/json', 'X-Claude-Task-Key' => 'clave-equivocada'];

        foreach ([$sin_clave, $mala] as $encabezados) {
            $this->putJson('/api/claude/missions/demo', $this->misiones_cuerpo('demo'), $encabezados)->assertStatus(401);
            $this->postJson('/api/claude/missions/demo/events', [], $encabezados)->assertStatus(401);
            $this->postJson('/api/claude/missions/demo/questions', [], $encabezados)->assertStatus(401);
            $this->getJson('/api/claude/missions/answers/pending?host=lucas-pc', $encabezados)->assertStatus(401);
            $this->postJson('/api/claude/missions/answers/1/claim', ['claim_token' => $id], $encabezados)->assertStatus(401);
            $this->getJson('/api/claude/missions/demo/questions/' . $uid . '/answer', $encabezados)->assertStatus(401);
            $this->postJson('/api/claude/missions/answers/1/applied', ['claim_token' => $id, 'ejecucion_n' => 1], $encabezados)->assertStatus(401);
            $this->postJson('/api/claude/missions/answers/1/reject', ['motivo' => 'otro'], $encabezados)->assertStatus(401);
        }

        // Sin la clave configurada en el servidor tampoco pasa nada.
        config(['services.claude_task_ingest.key' => '']);
        $this->putJson('/api/claude/missions/demo', $this->misiones_cuerpo('demo'), ['Accept' => 'application/json', 'X-Claude-Task-Key' => ''])->assertStatus(401);
    }
}
