<?php

namespace Tests\Feature\Mcp;

use App\Models\Admin;
use App\Models\MissionAnswer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Missions\MissionFixture;
use Tests\TestCase;

/**
 * CA11 de la misión dotin-misiones-admin: el conector de DECISIONES (`/api/mcp/decisiones`, scope `missions.answer`).
 *
 * Nace apagado (`mcp.decisiones_enabled`) y con lista de emails vacía (nadie). Prendido y con el email en la lista,
 * el flujo OAuth con el recurso de decisiones da un token que ejecuta `answer_mission_question` (canal `mcp`,
 * `admin_id` = dueño del token). El token de lectura no sirve ahí y el de decisiones no sirve en `/api/mcp`.
 * Cada caso de «apagado» lleva antes su control «prendido»: un 404 que ya existía hoy no puede pasar el test.
 */
class McpDecisionesTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;
    use MissionFixture;

    /** Recurso (audiencia) del endpoint de decisiones en los tests. */
    const RECURSO = 'https://api-admin.test/api/mcp/decisiones';

    /** Ruta del endpoint de decisiones. */
    const RUTA = '/api/mcp/decisiones';

    /** @var Admin Admin autorizado a conectar decisiones (está en `mcp.decisiones_admin_emails`). */
    private $decisor;

    /**
     * Fija la configuración (consulta y decisiones) y deja un admin autorizado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->misiones_preparar();
        $this->preparar_mcp();
        $this->decisor = $this->admin_de_mcp();
        $this->preparar_decisiones(true);
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
     * Fija `mcp.decisiones_*`.
     *
     * @param bool $prendido Valor de `mcp.decisiones_enabled`.
     *
     * @return void
     */
    private function preparar_decisiones($prendido)
    {
        config([
            'mcp.decisiones_enabled'      => (bool) $prendido,
            'mcp.decisiones_resource'     => self::RECURSO,
            'mcp.decisiones_admin_emails' => $this->decisor->email,
        ]);
    }

    /**
     * El flujo completo (registro, consentimiento, canje) para el perfil de decisiones.
     *
     * @param Admin $admin Admin que autoriza.
     *
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    private function token_de_decisiones(Admin $admin)
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list($verifier, $challenge) = $this->par_pkce();

        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge, ['resource' => self::RECURSO, 'scope' => 'missions.answer']),
            $admin->email,
            self::$clave_del_admin
        );
        $respuesta->assertStatus(302);
        $consulta = $this->consulta_del_redirect($respuesta);
        $this->assertArrayHasKey('code', $consulta, 'El consentimiento de decisiones tendría que emitir un código: ' . json_encode($consulta));

        $canje = $this->canjear_codigo($client_id, (string) $consulta['code'], $verifier);
        $canje->assertStatus(200);

        return ['access_token' => (string) $canje->json('access_token'), 'refresh_token' => (string) $canje->json('refresh_token'), 'client_id' => $client_id];
    }

    /**
     * Un POST JSON-RPC al endpoint de decisiones.
     *
     * @param string|null $access_token Token (null = sin Authorization).
     * @param mixed       $cuerpo       Mensaje JSON-RPC.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function decisiones_post($access_token, $cuerpo)
    {
        $encabezados = ['Accept' => 'application/json, text/event-stream'];
        if ($access_token !== null) {
            $encabezados['Authorization'] = 'Bearer ' . $access_token;
        }

        return $this->postJson(self::RUTA, $cuerpo, $encabezados);
    }

    /**
     * Llama `answer_mission_question` y devuelve el `result` (afirmando HTTP 200 y sin error JSON-RPC).
     *
     * @param string               $access_token Token de decisiones.
     * @param array<string, mixed> $argumentos   Argumentos de la herramienta.
     *
     * @return array<string, mixed>
     */
    private function responder_por_mcp($access_token, array $argumentos)
    {
        $respuesta = $this->decisiones_post($access_token, $this->rpc('tools/call', ['name' => 'answer_mission_question', 'arguments' => $argumentos]));
        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('error'), 'tools/call no debería dar error JSON-RPC: ' . $respuesta->getContent());

        return (array) $respuesta->json('result');
    }

    /**
     * Los argumentos de una respuesta válida.
     *
     * @param string               $slug    Slug de la misión.
     * @param string               $uid     UUID de la pregunta.
     * @param array<string, mixed> $cambios Cambios.
     *
     * @return array<string, mixed>
     */
    private function argumentos($slug, $uid, array $cambios = [])
    {
        return array_merge(['mission_id' => 'cc:' . $slug, 'question_id' => $uid, 'revision' => 1, 'option' => 'A', 'idempotency_key' => $this->misiones_uuid()], $cambios);
    }

    /**
     * CA11: por defecto está apagado; apagado, el endpoint y sus metadatos dan 404 (con el control prendido antes).
     *
     * @return void
     */
    public function test_apagado_por_defecto_404(): void
    {
        // Control: prendido, el endpoint pide token (401) y los metadatos existen (200).
        $this->decisiones_post(null, $this->rpc('tools/list'))->assertStatus(401);
        $this->getJson('/.well-known/oauth-protected-resource/api/mcp/decisiones')->assertStatus(200);

        // Apagado: 404 en ambos y también en GET/DELETE del endpoint.
        config(['mcp.decisiones_enabled' => false]);
        $this->decisiones_post(null, $this->rpc('tools/list'))->assertStatus(404);
        $this->getJson('/.well-known/oauth-protected-resource/api/mcp/decisiones')->assertStatus(404);
        $this->getJson(self::RUTA)->assertStatus(404);

        // El conector de consulta no se entera.
        $this->mcp_post(null, $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * El valor por defecto del interruptor en el archivo de configuración es `false` y la lista de emails es vacía.
     *
     * @return void
     */
    public function test_los_defaults_de_la_configuracion(): void
    {
        $archivo = require base_path('config/mcp.php');

        $this->assertSame(false, $archivo['decisiones_enabled']);
        $this->assertSame('', $archivo['decisiones_admin_emails']);
        $this->assertSame('https://api-admin.comerciocity.com/api/mcp/decisiones', $archivo['decisiones_resource']);
    }

    /**
     * Apagado, autorizar con el recurso o el scope de decisiones no existe (`invalid_target` / `invalid_scope`) y el scope
     * no se anuncia; prendido, abre la pantalla y se anuncia.
     *
     * @return void
     */
    public function test_scope_desconocido_apagado(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();
        $con_recurso = $this->parametros_de_autorizacion($client_id, $challenge, ['resource' => self::RECURSO, 'scope' => 'missions.answer']);
        $solo_scope  = $this->parametros_de_autorizacion($client_id, $challenge, ['scope' => 'missions.answer']);

        // Control prendido.
        $this->get('/mcp/oauth/authorize?' . http_build_query($con_recurso))->assertStatus(200);
        $anunciados = (array) $this->getJson('/.well-known/oauth-authorization-server')->json('scopes_supported');
        $this->assertContains('missions.answer', $anunciados);
        $this->assertContains('comerciocity.read', $anunciados);

        // Apagado.
        config(['mcp.decisiones_enabled' => false]);
        $respuesta = $this->get('/mcp/oauth/authorize?' . http_build_query($con_recurso));
        $respuesta->assertStatus(302);
        $consulta = $this->consulta_del_redirect($respuesta);
        $this->assertSame('invalid_target', $consulta['error'] ?? null);
        $this->assertArrayNotHasKey('code', $consulta);

        $respuesta = $this->get('/mcp/oauth/authorize?' . http_build_query($solo_scope));
        $respuesta->assertStatus(302);
        $this->assertContains($this->consulta_del_redirect($respuesta)['error'] ?? null, ['invalid_scope', 'invalid_target']);

        $anunciados = (array) $this->getJson('/.well-known/oauth-authorization-server')->json('scopes_supported');
        $this->assertNotContains('missions.answer', $anunciados);
        $this->assertContains('comerciocity.read', $anunciados);
    }

    /**
     * El flujo OAuth con el recurso de decisiones da un token; el catálogo es sólo `answer_mission_question` (no de sólo lectura)
     * y al ejecutarla queda la respuesta con canal `mcp` y el admin del token.
     *
     * @return void
     */
    public function test_flujo_oauth_y_respuesta(): void
    {
        $uid   = $this->misiones_con_pregunta('decide');
        $token = $this->token_de_decisiones($this->decisor)['access_token'];

        $lista = $this->decisiones_post($token, $this->rpc('tools/list'));
        $lista->assertStatus(200);
        $herramientas = (array) $lista->json('result.tools');
        $this->assertSame(['answer_mission_question'], array_column($herramientas, 'name'));
        $definicion = $herramientas[0];
        $this->assertFalse($definicion['annotations']['readOnlyHint']);
        $this->assertFalse($definicion['annotations']['destructiveHint']);
        $this->assertTrue($definicion['annotations']['idempotentHint']);
        $this->assertFalse($definicion['annotations']['openWorldHint']);
        $this->assertEqualsCanonicalizing(['mission_id', 'question_id', 'revision', 'idempotency_key'], $definicion['inputSchema']['required']);
        foreach (['mission_id', 'question_id', 'revision', 'option', 'text', 'idempotency_key'] as $propiedad) {
            $this->assertArrayHasKey($propiedad, (array) $definicion['inputSchema']['properties'], 'Falta la propiedad ' . $propiedad);
        }

        $inicio = $this->decisiones_post($token, $this->rpc('initialize', ['protocolVersion' => '2025-06-18']));
        $inicio->assertStatus(200);
        $this->assertStringContainsString('answer_mission_question', (string) $inicio->json('result.instructions'), 'El endpoint se presenta con sus propias instrucciones.');

        $argumentos = $this->argumentos('decide', $uid, ['text' => 'Dale, seguí']);
        $resultado  = $this->responder_por_mcp($token, $argumentos);
        $this->assertNotTrue($resultado['isError'] ?? false, json_encode($resultado));

        $datos = $resultado['structuredContent'];
        $this->assertTrue($datos['ok']);
        $this->assertFalse($datos['duplicada']);
        $this->assertSame('cc:decide', $datos['answer']['mission_key']);
        $this->assertSame($uid, $datos['answer']['question_uid']);
        $this->assertSame(1, $datos['answer']['revision']);
        $this->assertSame('A', $datos['answer']['opcion']);
        $this->assertSame('registrada', $datos['answer']['estado']);
        $this->assertNotEmpty($datos['aviso']);

        $fila = MissionAnswer::where('question_uid', $uid)->first();
        $this->assertSame('mcp', $fila->canal);
        $this->assertSame($this->decisor->id, (int) $fila->admin_id);
        $this->assertSame($argumentos['idempotency_key'], $fila->answer_uid);
        $this->assertSame('Dale, seguí', $fila->texto);

        // El slug pelado también vale como mission_id.
        $otro_uid = $this->misiones_con_pregunta('decide-slug');
        $this->assertTrue($this->responder_por_mcp($token, $this->argumentos('decide-slug', $otro_uid, ['mission_id' => 'decide-slug']))['structuredContent']['ok']);
    }

    /**
     * Con la lista vacía NADIE puede conectar decisiones; con lista, sólo los de la lista (403 y ningún código emitido).
     *
     * @return void
     */
    public function test_lista_vacia_nadie(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();
        $parametros = $this->parametros_de_autorizacion($client_id, $challenge, ['resource' => self::RECURSO, 'scope' => 'missions.answer']);

        // Control: con la lista que fija el setUp, el decisor entra.
        $this->enviar_consentimiento($parametros, $this->decisor->email, self::$clave_del_admin)->assertStatus(302);

        // Lista vacía: nadie, ni siquiera el que antes entraba.
        config(['mcp.decisiones_admin_emails' => '']);
        $this->enviar_consentimiento($parametros, $this->decisor->email, self::$clave_del_admin)->assertStatus(403);

        // Lista con otro: el decisor tampoco.
        config(['mcp.decisiones_admin_emails' => 'otro-' . uniqid() . '@test.local']);
        $this->enviar_consentimiento($parametros, $this->decisor->email, self::$clave_del_admin)->assertStatus(403);

        // Perfiles restringidos: aunque estén en la lista, no conectan.
        $agente = $this->admin_de_mcp(['es_agente' => 1]);
        config(['mcp.decisiones_admin_emails' => $agente->email]);
        $this->enviar_consentimiento($parametros, $agente->email, self::$clave_del_admin)->assertStatus(403);
    }

    /**
     * El token de lectura no sirve en `/api/mcp/decisiones` (audiencia distinta, 401).
     *
     * @return void
     */
    public function test_token_lectura_401_en_decisiones(): void
    {
        $lectura = $this->token_para($this->decisor)['access_token'];

        // Control: sirve en el endpoint de consulta.
        $this->mcp_post($lectura, $this->rpc('tools/list'))->assertStatus(200);

        $this->decisiones_post($lectura, $this->rpc('tools/list'))->assertStatus(401);
        $this->decisiones_post($lectura, $this->rpc('tools/call', ['name' => 'answer_mission_question', 'arguments' => new \stdClass()]))->assertStatus(401);
    }

    /**
     * El token de decisiones no sirve en `/api/mcp` (401) y el catálogo de consulta no trae `answer_mission_question`.
     *
     * @return void
     */
    public function test_token_decisiones_401_en_consulta(): void
    {
        $decisiones = $this->token_de_decisiones($this->decisor)['access_token'];

        // Control: sirve en su endpoint.
        $this->decisiones_post($decisiones, $this->rpc('tools/list'))->assertStatus(200);

        $this->mcp_post($decisiones, $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * El catálogo de consulta no tiene `answer_mission_question` y llamarla ahí es «herramienta desconocida».
     *
     * @return void
     */
    public function test_catalogo_consulta_sin_answer(): void
    {
        $lectura = $this->token_para($this->decisor)['access_token'];

        $lista = $this->mcp_post($lectura, $this->rpc('tools/list'));
        $lista->assertStatus(200);
        $nombres = array_column((array) $lista->json('result.tools'), 'name');
        $this->assertCount(8, $nombres);
        $this->assertNotContains('answer_mission_question', $nombres);
        foreach ((array) $lista->json('result.tools') as $herramienta) {
            $this->assertTrue($herramienta['annotations']['readOnlyHint'], $herramienta['name'] . ' sigue siendo de sólo lectura.');
        }

        // El catálogo de lectura anuncia el filtro nuevo de `list_missions` y habla del seguimiento y de la vigencia.
        $herramientas = [];
        foreach ((array) $lista->json('result.tools') as $herramienta) {
            $herramientas[$herramienta['name']] = $herramienta;
        }
        $filtro = (array) $herramientas['list_missions']['inputSchema']['properties'];
        $this->assertArrayHasKey('seguimiento', $filtro);
        $this->assertEqualsCanonicalizing(
            ['en_cola', 'activa', 'activa_sin_senal', 'pausada_tecnica', 'esperando_decision', 'terminada', 'detenida', 'desactualizada'],
            $filtro['seguimiento']['enum']
        );
        $this->assertStringContainsString('seguimiento', $herramientas['get_mission']['description']);
        $this->assertStringContainsString('vigencia', $herramientas['get_mission']['description']);

        $llamada = $this->mcp_post($lectura, $this->rpc('tools/call', ['name' => 'answer_mission_question', 'arguments' => new \stdClass()]));
        $llamada->assertStatus(200);
        $this->assertSame(-32602, $llamada->json('error.code'));
    }

    /**
     * Una pregunta sensible no se responde por el conector: `isError` con `requiere_ok_personal` y ninguna fila.
     *
     * @return void
     */
    public function test_sensible_error(): void
    {
        $uid   = $this->misiones_con_pregunta('delicada', 'sensible');
        $token = $this->token_de_decisiones($this->decisor)['access_token'];

        $resultado = $this->responder_por_mcp($token, $this->argumentos('delicada', $uid));

        $this->assertTrue($resultado['isError']);
        $this->assertFalse($resultado['structuredContent']['ok']);
        $this->assertSame('requiere_ok_personal', $resultado['structuredContent']['codigo']);
        $this->assertNotEmpty($resultado['structuredContent']['mensaje']);
        $this->assertSame(0, MissionAnswer::where('question_uid', $uid)->count());
    }

    /**
     * La `idempotency_key` repetida devuelve la misma respuesta (`duplicada`); una revisión vieja o argumentos faltantes son `isError`.
     *
     * @return void
     */
    public function test_idempotency_key(): void
    {
        $uid   = $this->misiones_con_pregunta('idem');
        $token = $this->token_de_decisiones($this->decisor)['access_token'];
        $argumentos = $this->argumentos('idem', $uid);

        $primera = $this->responder_por_mcp($token, $argumentos);
        $this->assertNotTrue($primera['isError'] ?? false, json_encode($primera));
        $this->assertFalse($primera['structuredContent']['duplicada']);

        $segunda = $this->responder_por_mcp($token, $argumentos);
        $this->assertNotTrue($segunda['isError'] ?? false, json_encode($segunda));
        $this->assertTrue($segunda['structuredContent']['duplicada']);
        $this->assertSame($primera['structuredContent']['answer']['id'], $segunda['structuredContent']['answer']['id']);
        $this->assertSame(1, MissionAnswer::where('question_uid', $uid)->count());

        // Otra clave sobre la misma pregunta ya respondida: error con su código.
        $otra = $this->responder_por_mcp($token, $this->argumentos('idem', $uid, ['option' => 'B']));
        $this->assertTrue($otra['isError']);
        $this->assertSame('ya_respondida', $otra['structuredContent']['codigo']);

        // Revisión vieja.
        $uid_b = $this->misiones_con_pregunta('idem-b');
        $this->misiones_post_pregunta('idem-b', $uid_b, ['revision' => 2, 'pregunta' => 'Corregida'])->assertStatus(200);
        $vieja = $this->responder_por_mcp($token, $this->argumentos('idem-b', $uid_b, ['revision' => 1]));
        $this->assertTrue($vieja['isError']);
        $this->assertSame('revision_vieja', $vieja['structuredContent']['codigo']);

        // Faltan argumentos obligatorios.
        $sin_clave = $this->responder_por_mcp($token, ['mission_id' => 'cc:idem', 'question_id' => $uid, 'revision' => 1, 'option' => 'A']);
        $this->assertTrue($sin_clave['isError']);
    }
}
