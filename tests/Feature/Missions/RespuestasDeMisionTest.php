<?php

namespace Tests\Feature\Missions;

use App\Models\MissionAnswer;
use App\Models\MissionQuestion;
use App\Models\MissionTracking;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA8 de la misión dotin-misiones-admin: cómo consume la Gamer las respuestas (pending, claim, answer, applied,
 * reject) con la clave `X-Claude-Task-Key`.
 *
 * Las respuestas se siembran directo en la base: acá no se prueba quién las registra (eso es del Admin y de Dotin)
 * sino la máquina de estados `registrada -> reclamada -> aplicada` y `registrada|reclamada -> rechazada`, la
 * aplicación idempotente y que el aviso `pending` nunca lleve el contenido de la respuesta.
 */
class RespuestasDeMisionTest extends TestCase
{
    use DatabaseTransactions;
    use MissionFixture;

    /** Texto que el Admin guarda y que NUNCA tiene que aparecer en `pending`. */
    const TEXTO_SECRETO = 'texto-libre-de-lucas-que-no-viaja-en-el-aviso';

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
     * Una misión con pregunta y una respuesta registrada.
     *
     * @param string               $slug    Slug de la misión.
     * @param array<string, mixed> $cambios Cambios sobre la respuesta sembrada.
     * @param array<string, mixed> $estado  Cambios sobre el estado de la misión (p. ej. `host`).
     *
     * @return array{0: string, 1: MissionAnswer} [uid de la pregunta, respuesta]
     */
    private function con_respuesta($slug, array $cambios = [], array $estado = [])
    {
        $uid       = $this->misiones_con_pregunta($slug, 'ordinaria', $estado);
        $respuesta = $this->misiones_sembrar_respuesta($slug, $uid, array_merge(['opcion' => 'A', 'texto' => self::TEXTO_SECRETO], $cambios));

        return [$uid, $respuesta];
    }

    /**
     * `POST claude/missions/answers/{id}/claim`.
     *
     * @param int    $id    Id de la respuesta.
     * @param string $token Token de reclamo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function reclamar($id, $token)
    {
        return $this->postJson('/api/claude/missions/answers/' . $id . '/claim', ['claim_token' => $token], $this->misiones_headers());
    }

    /**
     * `POST claude/missions/answers/{id}/applied`.
     *
     * @param int    $id        Id de la respuesta.
     * @param string $token     Token de reclamo.
     * @param int    $ejecucion Número de ejecución que la aplicó.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function aplicar($id, $token, $ejecucion = 2)
    {
        return $this->postJson('/api/claude/missions/answers/' . $id . '/applied', ['claim_token' => $token, 'ejecucion_n' => $ejecucion], $this->misiones_headers());
    }

    /**
     * `POST claude/missions/answers/{id}/reject`.
     *
     * @param int                  $id     Id de la respuesta.
     * @param array<string, mixed> $cuerpo Cuerpo (`motivo`, `detalle`).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function rechazar($id, array $cuerpo)
    {
        return $this->postJson('/api/claude/missions/answers/' . $id . '/reject', $cuerpo, $this->misiones_headers());
    }

    /**
     * El aviso `pending` lista la respuesta sin `opcion` ni `texto`, filtra por host y respeta el orden por id.
     *
     * @return void
     */
    public function test_pending_sin_contenido(): void
    {
        list(, $primera) = $this->con_respuesta('pend-a');
        list(, $segunda) = $this->con_respuesta('pend-b');
        list(, $otra_pc) = $this->con_respuesta('pend-c', [], ['host' => 'otra-pc']);

        $respuesta = $this->getJson('/api/claude/missions/answers/pending?host=lucas-pc', $this->misiones_headers());
        $respuesta->assertStatus(200);

        $ids = array_column($respuesta->json('answers'), 'id');
        $this->assertSame([$primera->id, $segunda->id], $ids, 'Sólo las del host pedido y por id ascendente.');
        $this->assertNotContains($otra_pc->id, $ids);

        $item = $respuesta->json('answers.0');
        $this->assertSame('cc:pend-a', $item['mission_key']);
        $this->assertSame('pend-a', $item['slug']);
        $this->assertSame(1, (int) $item['revision']);
        $this->assertSame('registrada', $item['estado']);
        foreach (['id', 'answer_uid', 'mission_key', 'slug', 'question_uid', 'revision', 'estado', 'created_at'] as $clave) {
            $this->assertArrayHasKey($clave, $item, 'Falta ' . $clave);
        }
        $this->assertArrayNotHasKey('opcion', $item);
        $this->assertArrayNotHasKey('texto', $item);
        $this->assertStringNotContainsString(self::TEXTO_SECRETO, $respuesta->getContent());

        // Sin `host` salen las de todas las PC.
        $todas = $this->getJson('/api/claude/missions/answers/pending', $this->misiones_headers())->json('answers');
        $this->assertContains($otra_pc->id, array_column($todas, 'id'));

        // Las aplicadas y las rechazadas dejan de ser pendientes.
        $this->rechazar($segunda->id, ['motivo' => 'obsoleta'])->assertStatus(200);
        $quedan = array_column($this->getJson('/api/claude/missions/answers/pending?host=lucas-pc', $this->misiones_headers())->json('answers'), 'id');
        $this->assertSame([$primera->id], $quedan);
    }

    /**
     * `claim`: 200 y `reclamada`; el mismo token otra vez es 200; otro token es 409 `reclamada_por_otro`; la que no existe, 404.
     *
     * @return void
     */
    public function test_claim_mismo_token_y_otro_token(): void
    {
        list(, $respuesta) = $this->con_respuesta('claim');
        $token = $this->misiones_uuid();

        $primero = $this->reclamar($respuesta->id, $token);
        $primero->assertStatus(200);
        $this->assertSame('reclamada', $primero->json('answer.estado'));
        $this->assertStringNotContainsString(self::TEXTO_SECRETO, $primero->getContent(), 'El claim tampoco lleva el texto.');
        $this->assertSame('reclamada', $respuesta->fresh()->estado);
        $this->assertSame($token, $respuesta->fresh()->claim_token);

        $mismo = $this->reclamar($respuesta->id, $token);
        $mismo->assertStatus(200);
        $this->assertSame('reclamada', $mismo->json('answer.estado'));

        $otro = $this->reclamar($respuesta->id, $this->misiones_uuid());
        $otro->assertStatus(409);
        $this->assertSame('reclamada_por_otro', $otro->json('codigo'));
        $this->assertSame($token, $respuesta->fresh()->claim_token, 'El token original no se pisa.');

        $this->reclamar(999999999, $token)->assertStatus(404);
        $this->reclamar($respuesta->id, 'no-es-uuid')->assertStatus(422);
    }

    /**
     * `GET claude/missions/{slug}/questions/{uid}/answer`: trae la pregunta y la respuesta con opción y texto.
     *
     * @return void
     */
    public function test_answer_trae_contenido(): void
    {
        list($uid, $respuesta) = $this->con_respuesta('contenido', ['opcion' => 'B']);

        $ver = $this->getJson('/api/claude/missions/contenido/questions/' . $uid . '/answer', $this->misiones_headers());
        $ver->assertStatus(200);

        $this->assertSame($uid, $ver->json('question.question_uid'));
        $this->assertSame(1, (int) $ver->json('question.revision'));
        $this->assertSame('ordinaria', $ver->json('question.tipo'));
        $this->assertCount(2, $ver->json('question.opciones'));
        $this->assertSame('A', $ver->json('question.recomendada'));

        $this->assertSame($respuesta->id, $ver->json('answer.id'));
        $this->assertSame($respuesta->answer_uid, $ver->json('answer.answer_uid'));
        $this->assertSame('cc:contenido', $ver->json('answer.mission_key'));
        $this->assertSame($uid, $ver->json('answer.question_uid'));
        $this->assertSame('B', $ver->json('answer.opcion'));
        $this->assertSame('No, frená', $ver->json('answer.opcion_texto'));
        $this->assertSame(self::TEXTO_SECRETO, $ver->json('answer.texto'));
        $this->assertSame('registrada', $ver->json('answer.estado'));
        $this->assertSame('admin', $ver->json('answer.canal'));
        $this->assertSame($respuesta->admin_id, $ver->json('answer.admin.id'));
        $this->assertNotEmpty($ver->json('answer.admin.nombre'));

        // Una pregunta que es de otra misión: 409.
        $this->misiones_put('otra-mision', ['estado' => 'activa'])->assertStatus(200);
        $ajena = $this->getJson('/api/claude/missions/otra-mision/questions/' . $uid . '/answer', $this->misiones_headers());
        $ajena->assertStatus(409);
        $this->assertSame('pregunta_ajena', $ajena->json('codigo'));

        // Una pregunta sin respuesta vigente: 404 `sin_respuesta`.
        $sin = $this->misiones_con_pregunta('sin-resp');
        $vacia = $this->getJson('/api/claude/missions/sin-resp/questions/' . $sin . '/answer', $this->misiones_headers());
        $vacia->assertStatus(404);
        $this->assertSame('sin_respuesta', $vacia->json('codigo'));
    }

    /**
     * `applied`: la primera vez pasa a `aplicada` y cierra la pregunta; la segunda es 200 `ya_aplicada` y NO mueve `applied_at`.
     *
     * @return void
     */
    public function test_applied_idempotente_conserva_applied_at(): void
    {
        list($uid, $respuesta) = $this->con_respuesta('aplica');
        $token = $this->misiones_uuid();
        $this->reclamar($respuesta->id, $token)->assertStatus(200);

        $this->misiones_congelar('2026-11-26 12:00:00');
        $primera = $this->aplicar($respuesta->id, $token, 2);
        $primera->assertStatus(200);
        $this->assertFalse($primera->json('ya_aplicada'));
        $this->assertSame('aplicada', $primera->json('answer.estado'));

        $fila = $respuesta->fresh();
        $this->assertSame('aplicada', $fila->estado);
        $this->assertSame('12:00', $fila->applied_at->setTimezone('America/Argentina/Buenos_Aires')->format('H:i'));
        $this->assertSame(2, (int) $fila->ejecucion_n);
        $this->assertSame('aplicada', MissionQuestion::where('question_uid', $uid)->first()->estado);
        $this->assertNull(MissionTracking::where('mission_key', 'cc:aplica')->first()->pregunta_abierta_uid);

        // Segunda vez, media hora después: sigue aplicada con la misma hora.
        $this->misiones_congelar('2026-11-26 12:30:00');
        $segunda = $this->aplicar($respuesta->id, $token, 3);
        $segunda->assertStatus(200);
        $this->assertTrue($segunda->json('ya_aplicada'));
        $this->assertSame('12:00', $respuesta->fresh()->applied_at->setTimezone('America/Argentina/Buenos_Aires')->format('H:i'), 'applied_at no se mueve.');
        $this->assertSame(2, (int) $respuesta->fresh()->ejecucion_n);

        // Otro token no puede aplicar la ya aplicada.
        $otro = $this->aplicar($respuesta->id, $this->misiones_uuid());
        $otro->assertStatus(409);
        $this->assertSame('token_distinto', $otro->json('codigo'));
    }

    /**
     * `applied` sin haber reclamado es 409 `sin_reclamar` y la respuesta sigue registrada.
     *
     * @return void
     */
    public function test_applied_sin_claim_409(): void
    {
        list(, $respuesta) = $this->con_respuesta('sinclaim');

        $intento = $this->aplicar($respuesta->id, $this->misiones_uuid());
        $intento->assertStatus(409);
        $this->assertSame('sin_reclamar', $intento->json('codigo'));
        $this->assertSame('registrada', $respuesta->fresh()->estado);
    }

    /**
     * `reject`: deja `rechazada`, cierra la pregunta con el motivo, es idempotente y valida el motivo.
     *
     * @return void
     */
    public function test_reject_cierra_pregunta(): void
    {
        list($uid, $respuesta) = $this->con_respuesta('rechaza');

        $this->rechazar($respuesta->id, ['motivo' => 'inventado'])->assertStatus(422);

        $rechazo = $this->rechazar($respuesta->id, ['motivo' => 'obsoleta', 'detalle' => 'La pregunta cambió de revisión']);
        $rechazo->assertStatus(200);
        $this->assertSame('rechazada', $rechazo->json('answer.estado'));

        $fila = $respuesta->fresh();
        $this->assertSame('rechazada', $fila->estado);
        $this->assertSame('obsoleta', $fila->rechazo_motivo);
        $this->assertSame('La pregunta cambió de revisión', $fila->rechazo_detalle);
        $pregunta = MissionQuestion::where('question_uid', $uid)->first();
        $this->assertSame('cerrada', $pregunta->estado);
        $this->assertSame('obsoleta', $pregunta->cierre_motivo);

        // Rechazar de nuevo: 200 idempotente.
        $this->rechazar($respuesta->id, ['motivo' => 'obsoleta'])->assertStatus(200);
        $this->assertSame('rechazada', $respuesta->fresh()->estado);

        // Una rechazada no se puede reclamar.
        $reclamo = $this->reclamar($respuesta->id, $this->misiones_uuid());
        $reclamo->assertStatus(409);
        $this->assertSame('rechazada', $reclamo->json('codigo'));
    }

    /**
     * `applied` después de `reject` es 409 y `reject` después de `applied` es 409 `ya_aplicada`; reclamar una aplicada también.
     *
     * @return void
     */
    public function test_applied_tras_reject_409(): void
    {
        list(, $rechazada) = $this->con_respuesta('tras-reject');
        $token = $this->misiones_uuid();
        $this->reclamar($rechazada->id, $token)->assertStatus(200);
        $this->rechazar($rechazada->id, ['motivo' => 'cerrada'])->assertStatus(200);

        $this->aplicar($rechazada->id, $token)->assertStatus(409);
        $this->assertSame('rechazada', $rechazada->fresh()->estado);

        list(, $aplicada) = $this->con_respuesta('tras-applied');
        $otro_token = $this->misiones_uuid();
        $this->reclamar($aplicada->id, $otro_token)->assertStatus(200);
        $this->aplicar($aplicada->id, $otro_token)->assertStatus(200);

        $rechazo = $this->rechazar($aplicada->id, ['motivo' => 'obsoleta']);
        $rechazo->assertStatus(409);
        $this->assertSame('ya_aplicada', $rechazo->json('codigo'));

        $reclamo = $this->reclamar($aplicada->id, $this->misiones_uuid());
        $reclamo->assertStatus(409);
        $this->assertSame('ya_aplicada', $reclamo->json('codigo'));
        $this->assertSame('aplicada', $aplicada->fresh()->estado);
    }

    /**
     * La máquina completa: una sola aplicación aunque se repitan claim, answer y applied (reinicios de la Gamer).
     *
     * @return void
     */
    public function test_maquina_de_estados_completa_una_sola_aplicacion(): void
    {
        list($uid, $respuesta) = $this->con_respuesta('maquina');
        $token = $this->misiones_uuid();

        // La Gamer se cae y repite cada paso.
        $this->reclamar($respuesta->id, $token)->assertStatus(200);
        $this->reclamar($respuesta->id, $token)->assertStatus(200);
        $this->getJson('/api/claude/missions/maquina/questions/' . $uid . '/answer', $this->misiones_headers())->assertStatus(200);
        $this->getJson('/api/claude/missions/maquina/questions/' . $uid . '/answer', $this->misiones_headers())->assertStatus(200);

        $this->misiones_congelar('2026-11-26 12:00:00');
        $this->assertFalse($this->aplicar($respuesta->id, $token, 2)->json('ya_aplicada'));
        $this->misiones_congelar('2026-11-26 12:45:00');
        $this->assertTrue($this->aplicar($respuesta->id, $token, 2)->json('ya_aplicada'));
        $this->assertTrue($this->aplicar($respuesta->id, $token, 2)->json('ya_aplicada'));

        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->where('estado', 'aplicada')->count());
        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->count());
        $this->assertSame('12:00', $respuesta->fresh()->applied_at->setTimezone('America/Argentina/Buenos_Aires')->format('H:i'));

        // Y después de aplicada, la respuesta sigue consultable (`aplicada`) por quien reinicia.
        $ver = $this->getJson('/api/claude/missions/maquina/questions/' . $uid . '/answer', $this->misiones_headers());
        $ver->assertStatus(200);
        $this->assertSame('aplicada', $ver->json('answer.estado'));
    }
}
