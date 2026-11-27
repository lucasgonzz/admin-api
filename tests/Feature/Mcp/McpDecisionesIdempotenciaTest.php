<?php

namespace Tests\Feature\Mcp;

use App\Models\Admin;
use App\Models\MissionAnswer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Missions\MissionFixture;
use Tests\TestCase;

/**
 * Hallazgo de la prueba E2E real de dotin-misiones-admin: `answer_mission_question` rechazaba una `idempotency_key` que no
 * era un UUID, y un cliente como ChatGPT/Dotin puede mandar cualquier texto (la respuesta de Lucas se perdía).
 *
 * Contrato: la clave libre mide de 8 a 120 caracteres; el `answer_uid` sale como UUID v5 determinístico de
 * (mission_id, question_id, idempotency_key), así la misma clave es la misma respuesta; un UUID válido se usa tal cual.
 */
class McpDecisionesIdempotenciaTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;
    use MissionFixture;

    const RECURSO = 'https://api-admin.test/api/mcp/decisiones';

    /** @var string Token de decisiones. */
    private $token;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->misiones_preparar();
        $this->preparar_mcp();
        $decisor = $this->admin_de_mcp();
        config(['mcp.decisiones_enabled' => true, 'mcp.decisiones_resource' => self::RECURSO, 'mcp.decisiones_admin_emails' => $decisor->email]);

        $client_id = $this->registrar_cliente_de_chatgpt();
        list($verifier, $challenge) = $this->par_pkce();
        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge, ['resource' => self::RECURSO, 'scope' => 'missions.answer']),
            $decisor->email,
            self::$clave_del_admin
        );
        $codigo = $this->consulta_del_redirect($respuesta)['code'];
        $this->token = (string) $this->canjear_codigo($client_id, $codigo, $verifier)->json('access_token');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->soltar_reloj();

        parent::tearDown();
    }

    /**
     * Llama la herramienta y devuelve el `result`.
     *
     * @param array<string, mixed> $argumentos Argumentos.
     *
     * @return array<string, mixed>
     */
    private function responder(array $argumentos)
    {
        $r = $this->postJson('/api/mcp/decisiones', $this->rpc('tools/call', ['name' => 'answer_mission_question', 'arguments' => $argumentos]), [
            'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $this->token,
        ]);
        $r->assertStatus(200);

        return (array) $r->json('result');
    }

    /**
     * Argumentos válidos con la clave que se indique.
     *
     * @param string $slug  Misión.
     * @param string $uid   Pregunta.
     * @param string $clave `idempotency_key`.
     * @param string $opcion Opción.
     *
     * @return array<string, mixed>
     */
    private function args($slug, $uid, $clave, $opcion = 'A')
    {
        return ['mission_id' => 'cc:' . $slug, 'question_id' => $uid, 'revision' => 1, 'option' => $opcion, 'idempotency_key' => $clave];
    }

    /**
     * Una clave libre (no UUID) registra la respuesta (201 equivalente) y la repetida es la misma respuesta `duplicada`.
     *
     * @return void
     */
    public function test_clave_libre_registra_y_repetida_es_duplicada(): void
    {
        $uid = $this->misiones_con_pregunta('libre');

        $primera = $this->responder($this->args('libre', $uid, 'dotin-respuesta-0001'));
        $this->assertNotTrue($primera['isError'] ?? false, json_encode($primera));
        $this->assertTrue($primera['structuredContent']['ok']);
        $this->assertFalse($primera['structuredContent']['duplicada']);
        $this->assertSame('registrada', $primera['structuredContent']['answer']['estado']);

        $segunda = $this->responder($this->args('libre', $uid, 'dotin-respuesta-0001'));
        $this->assertNotTrue($segunda['isError'] ?? false, json_encode($segunda));
        $this->assertTrue($segunda['structuredContent']['duplicada']);
        $this->assertSame($primera['structuredContent']['answer']['id'], $segunda['structuredContent']['answer']['id']);
        $this->assertSame($primera['structuredContent']['answer']['answer_uid'], $segunda['structuredContent']['answer']['answer_uid']);
        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * Otra clave libre sobre la misma pregunta ya respondida es `ya_respondida`.
     *
     * @return void
     */
    public function test_otra_clave_libre_es_ya_respondida(): void
    {
        $uid = $this->misiones_con_pregunta('otra');
        $this->assertNotTrue($this->responder($this->args('otra', $uid, 'clave libre uno'))['isError'] ?? false);

        $otra = $this->responder($this->args('otra', $uid, 'clave libre dos', 'B'));
        $this->assertTrue($otra['isError']);
        $this->assertSame('ya_respondida', $otra['structuredContent']['codigo']);
        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * El `answer_uid` derivado es un UUID v5 determinístico y depende de misión, pregunta y clave.
     *
     * @return void
     */
    public function test_el_answer_uid_es_uuid_v5_deterministico(): void
    {
        $uid = $this->misiones_con_pregunta('v5');
        $resultado = $this->responder($this->args('v5', $uid, 'texto-libre-cualquiera'));
        $answer_uid = $resultado['structuredContent']['answer']['answer_uid'];

        $esperado = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'cc:v5|' . $uid . '|texto-libre-cualquiera')->toString();
        $this->assertSame($esperado, $answer_uid);
        $this->assertSame(5, (int) substr($answer_uid, 14, 1));
    }

    /**
     * Un UUID válido se usa tal cual (compatibilidad con lo que ya andaba).
     *
     * @return void
     */
    public function test_un_uuid_sigue_igual(): void
    {
        $uid  = $this->misiones_con_pregunta('uuid');
        $llave = $this->misiones_uuid();

        $resultado = $this->responder($this->args('uuid', $uid, $llave));
        $this->assertNotTrue($resultado['isError'] ?? false, json_encode($resultado));
        $this->assertSame($llave, $resultado['structuredContent']['answer']['answer_uid']);
    }

    /**
     * Una clave de menos de 8 o de más de 120 caracteres es un error de argumentos (no un 500).
     *
     * @return void
     */
    public function test_largo_de_la_clave_fuera_de_rango_es_error(): void
    {
        $uid = $this->misiones_con_pregunta('largo');

        foreach (['corta', str_repeat('x', 121)] as $clave) {
            $r = $this->responder($this->args('largo', $uid, $clave));
            $this->assertTrue($r['isError'], 'Clave de ' . strlen($clave) . ' caracteres.');
            $this->assertSame('argumentos_invalidos', $r['structuredContent']['codigo']);
        }
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());

        // Los límites exactos entran.
        $this->assertNotTrue($this->responder($this->args('largo', $uid, str_repeat('y', 120)))['isError'] ?? false);
    }
}
