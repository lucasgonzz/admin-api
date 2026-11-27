<?php

namespace Tests\Feature\Missions;

use App\Models\MissionAnswer;
use App\Models\MissionQuestion;
use App\Models\MissionTrackingEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA7 de la misión dotin-misiones-admin: Lucas responde una decisión ORDINARIA desde el Admin
 * (`POST api/admin/local/missions/{slug}/questions/{uid}/answer`, sesión humana).
 *
 * Cubre el registro, la idempotencia por `answer_uid`, cada 409/422 del contrato y los límites de quién puede
 * responder: una pregunta sensible nunca se registra por acá, un agente del panel no responde, el perfil «sólo leads»
 * queda afuera y, con lista de emails, sólo los de la lista.
 */
class RespuestaDesdeElAdminTest extends TestCase
{
    use DatabaseTransactions;
    use MissionFixture;

    /** Texto libre de las pruebas. */
    const TEXTO = 'Dale, pero primero corré los tests de MCP';

    /** @var \App\Models\Admin Quien responde. */
    private $lucas;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->misiones_preparar();
        $this->lucas = $this->misiones_admin();
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
     * Un cuerpo de respuesta válido.
     *
     * @param array<string, mixed> $cambios Cambios.
     *
     * @return array<string, mixed>
     */
    private function cuerpo(array $cambios = [])
    {
        return array_merge(['revision' => 1, 'opcion' => 'A', 'texto' => null, 'answer_uid' => $this->misiones_uuid()], $cambios);
    }

    /**
     * Registrar la opción A de una ordinaria abierta: 201, respuesta `registrada`, pregunta `respondida` y un evento sin texto.
     *
     * @return void
     */
    public function test_registra_ordinaria(): void
    {
        $uid    = $this->misiones_con_pregunta('resp');
        $cuerpo = $this->cuerpo(['texto' => self::TEXTO]);

        $respuesta = $this->misiones_responder($this->lucas, 'resp', $uid, $cuerpo);
        $respuesta->assertStatus(201);
        $this->assertSame('registrada', $respuesta->json('answer.estado'));
        $this->assertSame('A', $respuesta->json('answer.opcion'));
        $this->assertSame($cuerpo['answer_uid'], $respuesta->json('answer.answer_uid'));

        $fila = MissionAnswer::where('answer_uid', $cuerpo['answer_uid'])->first();
        $this->assertNotNull($fila);
        $this->assertSame('admin', $fila->canal);
        $this->assertSame($this->lucas->id, (int) $fila->admin_id);
        $this->assertSame('cc:resp', $fila->mission_key);
        $this->assertSame(self::TEXTO, $fila->texto);

        $pregunta = MissionQuestion::where('question_uid', $uid)->first();
        $this->assertSame('respondida', $pregunta->estado);
        $this->assertNotNull($pregunta->answered_at);

        $evento = MissionTrackingEvent::where('mission_key', 'cc:resp')->where('tipo', 'respuesta_registrada')->first();
        $this->assertNotNull($evento, 'Queda un evento respuesta_registrada.');
        $this->assertStringNotContainsString(self::TEXTO, (string) $evento->texto, 'El evento no lleva el texto de la respuesta.');

        // Sólo texto, sin opción, también es una respuesta.
        $solo_texto_uid = $this->misiones_con_pregunta('resp-texto');
        $this->misiones_responder($this->lucas, 'resp-texto', $solo_texto_uid, $this->cuerpo(['opcion' => null, 'texto' => 'Hacelo distinto']))->assertStatus(201);
    }

    /**
     * El mismo `answer_uid` con el mismo contenido: 200, misma respuesta y ninguna fila nueva.
     *
     * @return void
     */
    public function test_answer_uid_idempotente(): void
    {
        $uid    = $this->misiones_con_pregunta('idem');
        $cuerpo = $this->cuerpo();

        $primera = $this->misiones_responder($this->lucas, 'idem', $uid, $cuerpo);
        $primera->assertStatus(201);

        $segunda = $this->misiones_responder($this->lucas, 'idem', $uid, $cuerpo);
        $segunda->assertStatus(200);
        $this->assertTrue($segunda->json('duplicada'));
        $this->assertSame($primera->json('answer.id'), $segunda->json('answer.id'));
        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * El mismo `answer_uid` con otro contenido es 409 `answer_uid_reusado` y no cambia la respuesta.
     *
     * @return void
     */
    public function test_answer_uid_reusado_409(): void
    {
        $uid    = $this->misiones_con_pregunta('reuso');
        $cuerpo = $this->cuerpo(['opcion' => 'A']);
        $this->misiones_responder($this->lucas, 'reuso', $uid, $cuerpo)->assertStatus(201);

        $otra = $this->misiones_responder($this->lucas, 'reuso', $uid, array_merge($cuerpo, ['opcion' => 'B']));
        $otra->assertStatus(409);
        $this->assertSame('answer_uid_reusado', $otra->json('codigo'));
        $this->assertSame('A', MissionAnswer::where('answer_uid', $cuerpo['answer_uid'])->first()->opcion);
    }

    /**
     * Otro `answer_uid` sobre una pregunta ya respondida es 409 `ya_respondida`.
     *
     * @return void
     */
    public function test_ya_respondida_409(): void
    {
        $uid = $this->misiones_con_pregunta('yaresp');
        $this->misiones_responder($this->lucas, 'yaresp', $uid, $this->cuerpo())->assertStatus(201);

        $segunda = $this->misiones_responder($this->lucas, 'yaresp', $uid, $this->cuerpo(['opcion' => 'B']));
        $segunda->assertStatus(409);
        $this->assertSame('ya_respondida', $segunda->json('codigo'));
        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * Responder una revisión que ya no es la vigente es 409 `revision_vieja`.
     *
     * @return void
     */
    public function test_revision_vieja_409(): void
    {
        $uid = $this->misiones_con_pregunta('revvieja');
        $this->misiones_post_pregunta('revvieja', $uid, ['revision' => 2, 'pregunta' => 'Pregunta corregida'])->assertStatus(200);

        $vieja = $this->misiones_responder($this->lucas, 'revvieja', $uid, $this->cuerpo(['revision' => 1]));
        $vieja->assertStatus(409);
        $this->assertSame('revision_vieja', $vieja->json('codigo'));
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());

        // Con la revisión vigente sí.
        $this->misiones_responder($this->lucas, 'revvieja', $uid, $this->cuerpo(['revision' => 2]))->assertStatus(201);
    }

    /**
     * Una pregunta cerrada, reemplazada o aplicada es 409 `pregunta_cerrada`.
     *
     * @return void
     */
    public function test_pregunta_cerrada_409(): void
    {
        $vieja = $this->misiones_con_pregunta('cerr');
        $this->misiones_post_pregunta('cerr', $this->misiones_uuid(), ['pregunta' => 'La nueva'])->assertStatus(201);
        $this->assertSame('reemplazada', MissionQuestion::where('question_uid', $vieja)->first()->estado);

        $reemplazada = $this->misiones_responder($this->lucas, 'cerr', $vieja, $this->cuerpo());
        $reemplazada->assertStatus(409);
        $this->assertSame('pregunta_cerrada', $reemplazada->json('codigo'));

        $cerrada = $this->misiones_con_pregunta('cerr-b');
        $this->misiones_put('cerr-b', ['estado' => 'activa', 'pregunta_abierta' => null])->assertStatus(200);
        $this->misiones_responder($this->lucas, 'cerr-b', $cerrada, $this->cuerpo())->assertStatus(409);

        $aplicada = $this->misiones_con_pregunta('cerr-c');
        MissionQuestion::where('question_uid', $aplicada)->update(['estado' => 'aplicada']);
        $this->misiones_responder($this->lucas, 'cerr-c', $aplicada, $this->cuerpo())->assertStatus(409);
        $this->assertSame(0, MissionAnswer::count());
    }

    /**
     * Una pregunta de otra misión es 409 `pregunta_ajena`; una misión o una pregunta que no existen son 404.
     *
     * @return void
     */
    public function test_ajena_409(): void
    {
        $uid = $this->misiones_con_pregunta('duena');
        $this->misiones_put('otra', ['estado' => 'esperando_decision'])->assertStatus(200);

        $ajena = $this->misiones_responder($this->lucas, 'otra', $uid, $this->cuerpo());
        $ajena->assertStatus(409);
        $this->assertSame('pregunta_ajena', $ajena->json('codigo'));

        $sin_mision = $this->misiones_responder($this->lucas, 'no-existe', $uid, $this->cuerpo());
        $sin_mision->assertStatus(404);
        $this->assertSame('mision_inexistente', $sin_mision->json('codigo'));

        $sin_pregunta = $this->misiones_responder($this->lucas, 'otra', $this->misiones_uuid(), $this->cuerpo());
        $sin_pregunta->assertStatus(404);
        $this->assertSame('pregunta_inexistente', $sin_pregunta->json('codigo'));
    }

    /**
     * Una pregunta sensible nunca se registra por el Admin: 403 `requiere_ok_personal` y ninguna fila.
     *
     * @return void
     */
    public function test_sensible_403(): void
    {
        $uid = $this->misiones_con_pregunta('sensi', 'sensible');

        $respuesta = $this->misiones_responder($this->lucas, 'sensi', $uid, $this->cuerpo());
        $respuesta->assertStatus(403);
        $this->assertSame('requiere_ok_personal', $respuesta->json('codigo'));
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());
        $this->assertSame('abierta', MissionQuestion::where('question_uid', $uid)->first()->estado);
    }

    /**
     * Una opción que no existe es 422 `opcion_invalida`; sin opción ni texto, 422 `respuesta_vacia`.
     *
     * @return void
     */
    public function test_opcion_invalida_422(): void
    {
        $uid = $this->misiones_con_pregunta('invalida');

        $mala = $this->misiones_responder($this->lucas, 'invalida', $uid, $this->cuerpo(['opcion' => 'Z']));
        $mala->assertStatus(422);
        $this->assertSame('opcion_invalida', $mala->json('codigo'));

        $vacia = $this->misiones_responder($this->lucas, 'invalida', $uid, $this->cuerpo(['opcion' => null, 'texto' => null]));
        $vacia->assertStatus(422);
        $this->assertSame('respuesta_vacia', $vacia->json('codigo'));

        $this->misiones_responder($this->lucas, 'invalida', $uid, $this->cuerpo(['texto' => str_repeat('x', 1001)]))->assertStatus(422);
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());
        $this->assertSame('abierta', MissionQuestion::where('question_uid', $uid)->first()->estado);
    }

    /**
     * Un agente del panel (`es_agente`) no responde: 403 y ninguna fila.
     *
     * @return void
     */
    public function test_agente_403(): void
    {
        $uid    = $this->misiones_con_pregunta('agente');
        $agente = $this->misiones_admin(['es_agente' => 1]);

        $this->misiones_responder($agente, 'agente', $uid, $this->cuerpo())->assertStatus(403);
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * El perfil «sólo leads» queda afuera (lo corta el middleware del perfil) y sin login es 401.
     *
     * @return void
     */
    public function test_sin_login_401(): void
    {
        $uid = $this->misiones_con_pregunta('login');

        $this->misiones_responder(null, 'login', $uid, $this->cuerpo())->assertStatus(401);

        $solo_leads = $this->misiones_admin(['solo_leads' => 1]);
        $this->misiones_responder($solo_leads, 'login', $uid, $this->cuerpo())->assertStatus(403);
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * Con `missions.answer_admin_emails` sólo responden los de la lista (403 `no_autorizado` para el resto).
     *
     * @return void
     */
    public function test_email_fuera_de_lista_403(): void
    {
        $uid     = $this->misiones_con_pregunta('lista');
        $de_lista = $this->misiones_admin(['email' => 'de-lista-' . uniqid() . '@test.local']);
        config(['missions.answer_admin_emails' => $de_lista->email . ',otro@x.com']);

        $fuera = $this->misiones_responder($this->lucas, 'lista', $uid, $this->cuerpo());
        $fuera->assertStatus(403);
        $this->assertSame('no_autorizado', $fuera->json('codigo'));
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());

        $this->misiones_responder($de_lista, 'lista', $uid, $this->cuerpo())->assertStatus(201);
    }

    /**
     * La respuesta registrada queda a la vista de la Gamer en `pending` (sin el contenido) y el Admin no la aplica solo.
     *
     * @return void
     */
    public function test_la_respuesta_queda_pendiente_para_la_gamer(): void
    {
        $uid = $this->misiones_con_pregunta('pendiente');
        $this->misiones_responder($this->lucas, 'pendiente', $uid, $this->cuerpo(['texto' => self::TEXTO]))->assertStatus(201);

        $pending = $this->getJson('/api/claude/missions/answers/pending?host=lucas-pc', $this->misiones_headers());
        $pending->assertStatus(200);
        $this->assertSame([$uid], array_column($pending->json('answers'), 'question_uid'));
        $this->assertStringNotContainsString(self::TEXTO, $pending->getContent());
    }
}
