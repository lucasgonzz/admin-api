<?php

namespace Tests\Feature\PanelAgent;

use App\Jobs\EnviarAvisoDeActualizacionJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión admin-control-total, ronda 2 (rechazo de la revisión 1): lo que las tres miradas encontraron.
 *
 *  - H1: el motivo con acentos (y cualquier header que se guarda en la auditoría) no puede romper el INSERT
 *        ni dejar el panel en 503. Contrato: UTF-8 percent-encoded; un cliente viejo con latin-1 crudo no rompe.
 *  - H2: el código de confirmación es de UN solo uso (15 minutos): el mismo código dos veces no ejecuta dos veces.
 *  - H3: la categoría se decide por el efecto real: `PUT update/{id}` (aviso al dueño) es terceros y
 *        `PUT client/{id}` con `api_url` (destino de la api_key) frena como credenciales.
 *
 * Archivo nuevo: los tests del commit de aceptación a9c05a24 no se tocan. Todos los datos los crea el test
 * (base de testing + `DatabaseTransactions`); la auditoría se escribe de verdad (no se mockea el logger).
 */
class FrenosDelAgenteRonda2Test extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /** @var int Contador para que cada upgrade de prueba tenga versiones distintas. */
    private $contador_de_versiones = 0;

    /**
     * Cierra el reloj congelado que algunos tests fijan con `Carbon::setTestNow`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // H1 — motivo con acentos
    // ---------------------------------------------------------------------------------------------

    /**
     * H1 — El motivo llega UTF-8 percent-encoded (contrato del MCP): responde 200 y se guarda decodificado.
     *
     * @return void
     */
    public function test_motivo_percent_encoded_con_acentos_se_guarda_decodificado(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();

        $motivo    = 'desactivación — pedida por Lucas';
        $respuesta = $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['is_active' => false], [
            'X-Agente-Motivo' => rawurlencode($motivo),
        ]);

        $respuesta->assertOk();
        $this->assertSame($motivo, $this->ultima_auditoria_de($agente)->reason, 'El motivo queda decodificado, con acento y raya.');
        $this->assertSame(0, (int) DB::table('clients')->where('id', $cliente->id)->value('is_active'));
    }

    /**
     * H1 — Un cliente viejo manda el motivo en latin-1 crudo (byte \xF3): no es 503 y se guarda UTF-8 válido.
     *
     * @return void
     */
    public function test_motivo_con_byte_latin1_crudo_no_rompe_la_auditoria(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();

        $respuesta = $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['is_active' => false], [
            'X-Agente-Motivo' => "desactivaci\xF3n pedida",
        ]);

        $respuesta->assertOk();
        $fila = $this->ultima_auditoria_de($agente);
        $this->assertNotNull($fila, 'La auditoría se abrió.');
        $this->assertTrue(mb_check_encoding((string) $fila->reason, 'UTF-8'), 'El motivo guardado es UTF-8 válido.');
        $this->assertSame('desactivación pedida', $fila->reason, 'El latin-1 se convierte a UTF-8.');
    }

    /**
     * H1 — Sin saltos ni caracteres de control, y recortado a 500 caracteres (no bytes).
     *
     * @return void
     */
    public function test_motivo_sin_controles_y_recortado_a_500_caracteres(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();

        // Con saltos de línea y un NUL codificados.
        $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['is_active' => false], [
            'X-Agente-Motivo' => rawurlencode("línea uno\r\nlínea dos\x00fin"),
        ])->assertOk();
        $motivo = (string) $this->ultima_auditoria_de($agente)->reason;
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $motivo), 'Sin caracteres de control ni saltos.');
        $this->assertTrue($this->contiene($motivo, 'línea uno'));
        $this->assertTrue($this->contiene($motivo, 'línea dos'));

        // Un motivo larguísimo de caracteres de dos bytes se recorta por caracteres.
        $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['is_active' => true], [
            'X-Agente-Motivo' => rawurlencode(str_repeat('ñ', 700)),
        ])->assertOk();
        $largo = (string) $this->ultima_auditoria_de($agente)->reason;
        $this->assertSame(500, mb_strlen($largo), 'Se recorta a 500 caracteres.');
        $this->assertTrue(mb_check_encoding($largo, 'UTF-8'));
    }

    /**
     * H1 — Un GET con bytes inválidos en un header y en la query también queda auditado, con todo UTF-8 válido.
     *
     * @return void
     */
    public function test_headers_y_query_con_bytes_invalidos_dejan_una_fila_valida(): void
    {
        $agente = $this->crear_agente();
        $token  = $this->token_de($agente);

        $respuesta = $this->pedir_como($token, 'GET', '/api/admin/me?filtro=%F3%FF', [], [
            'X-Agente-Cliente' => "claude-c\xF3digo",
            'X-Agente-Motivo'  => "revisi\xF3n",
        ]);

        $respuesta->assertOk();
        $fila = $this->ultima_auditoria_de($agente);
        $this->assertNotNull($fila, 'El GET queda auditado aunque los headers traigan bytes latin-1.');
        foreach ((array) $fila as $columna => $valor) {
            if (is_string($valor)) {
                $this->assertTrue(mb_check_encoding($valor, 'UTF-8'), 'La columna ' . $columna . ' es UTF-8 válido.');
            }
        }
        $this->assertSame('claude-código', $fila->agent_client);
    }

    // ---------------------------------------------------------------------------------------------
    // H2 — confirmación de un solo uso
    // ---------------------------------------------------------------------------------------------

    /**
     * H2 — El mismo código dos veces: la primera ejecuta, la segunda es 428 con un preview nuevo y
     * `confirmacion_usada: true`.
     *
     * @return void
     */
    public function test_el_mismo_codigo_dos_veces_ejecuta_una_sola_vez(): void
    {
        $agente   = $this->crear_agente();
        $token    = $this->token_de($agente);
        $variante = $this->crear_variante();
        $uri      = '/api/admin/message-variant/' . $variante->id;

        $preview = $this->pedir_como($token, 'DELETE', $uri);
        $preview->assertStatus(428);
        $this->assertNotTrue($preview->json('confirmacion_usada'), 'El primer preview no dice «usada».');
        $codigo = $preview->json('codigo_confirmacion');
        $this->assertSame(16, strlen($codigo));

        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $codigo])->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $variante->id]);

        $segunda = $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $codigo]);
        $segunda->assertStatus(428);
        $segunda->assertJsonPath('confirmacion_usada', true);
        $segunda->assertJsonPath('code', 'agente_confirmacion_requerida');
        $this->assertNotSame($codigo, $segunda->json('codigo_confirmacion'), 'El preview nuevo trae otro código.');
    }

    /**
     * H2 — Una operación de plata frenada, con el mismo código dos veces, se ejecuta UNA sola vez (conteo real).
     *
     * @return void
     */
    public function test_una_operacion_de_plata_con_el_mismo_codigo_no_se_repite(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();
        $uri     = '/api/admin/client/' . $cliente->id . '/ai-image-plan';
        $cuerpo  = ['ai_image_plan_id' => null, 'ai_image_quality' => 'high'];

        // Cuenta cuántas veces se guarda el cliente: es la huella de que la operación corrió.
        $ejecuciones = 0;
        Client::saved(function () use (&$ejecuciones) {
            $ejecuciones++;
        });

        $preview = $this->pedir_como($token, 'PUT', $uri, $cuerpo);
        $preview->assertStatus(428);
        $preview->assertJsonPath('categoria', 'plata');
        $this->assertSame(0, $ejecuciones, 'El preview no ejecuta nada.');
        $codigo = $preview->json('codigo_confirmacion');

        $this->pedir_como($token, 'PUT', $uri, $cuerpo, ['X-Agente-Confirmar' => $codigo])->assertOk();
        $this->assertSame(1, $ejecuciones, 'Con el código corre una vez.');

        $repetida = $this->pedir_como($token, 'PUT', $uri, $cuerpo, ['X-Agente-Confirmar' => $codigo]);
        $repetida->assertStatus(428);
        $repetida->assertJsonPath('confirmacion_usada', true);
        $this->assertSame(1, $ejecuciones, 'El reintento con el mismo código no vuelve a ejecutar.');
    }

    /**
     * H2 — Cada preview (428 o dry run) emite un código NUEVO, aunque el pedido sea idéntico.
     *
     * @return void
     */
    public function test_dos_previews_del_mismo_pedido_dan_codigos_distintos(): void
    {
        $agente   = $this->crear_agente();
        $token    = $this->token_de($agente);
        $variante = $this->crear_variante();
        $uri      = '/api/admin/message-variant/' . $variante->id;

        $uno = $this->pedir_como($token, 'DELETE', $uri)->json('codigo_confirmacion');
        $dos = $this->pedir_como($token, 'DELETE', $uri)->json('codigo_confirmacion');
        $dry = $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Dry-Run' => 'si'])->json('codigo_confirmacion');

        $this->assertNotSame($uno, $dos);
        $this->assertNotSame($uno, $dry);
        $this->assertNotSame($dos, $dry);

        // Y todos sirven (cada uno una vez): el segundo código también ejecuta mientras no se use.
        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $dos])->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $variante->id]);
    }

    /**
     * H2 — El código vale 15 minutos: a los 14 sirve; a los 16 es 428 y no ejecuta.
     *
     * @return void
     */
    public function test_el_codigo_vale_15_minutos(): void
    {
        $agente = $this->crear_agente();
        $token  = $this->token_de($agente);

        $vencida = $this->crear_variante();
        $uri_v   = '/api/admin/message-variant/' . $vencida->id;
        $codigo_v = $this->pedir_como($token, 'DELETE', $uri_v)->json('codigo_confirmacion');

        $vigente = $this->crear_variante();
        $uri_ok  = '/api/admin/message-variant/' . $vigente->id;
        $codigo_ok = $this->pedir_como($token, 'DELETE', $uri_ok)->json('codigo_confirmacion');

        // A los 16 minutos el primero ya no sirve.
        Carbon::setTestNow(Carbon::now()->addMinutes(16));
        $this->pedir_como($token, 'DELETE', $uri_v, [], ['X-Agente-Confirmar' => $codigo_v])->assertStatus(428);
        $this->assertDatabaseHas('message_variants', ['id' => $vencida->id]);
        Carbon::setTestNow();

        // A los 14 minutos el segundo todavía sirve.
        Carbon::setTestNow(Carbon::now()->addMinutes(14));
        $this->pedir_como($token, 'DELETE', $uri_ok, [], ['X-Agente-Confirmar' => $codigo_ok])->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $vigente->id]);
    }

    /**
     * H2 — El texto del preview dice que el código vale una sola vez y 15 minutos.
     *
     * @return void
     */
    public function test_el_preview_explica_que_el_codigo_es_de_un_solo_uso(): void
    {
        $agente   = $this->crear_agente();
        $token    = $this->token_de($agente);
        $variante = $this->crear_variante();

        $texto = (string) $this->pedir_como($token, 'DELETE', '/api/admin/message-variant/' . $variante->id)->json('como_confirmar');

        $this->assertTrue($this->contiene($texto, 'una sola vez'), 'Dice que vale una sola vez.');
        $this->assertTrue($this->contiene($texto, '15 minutos'), 'Dice cuánto dura.');
    }

    // ---------------------------------------------------------------------------------------------
    // H3 — clasificación por efecto real
    // ---------------------------------------------------------------------------------------------

    /**
     * H3 (a) — `PUT update/{id}` es terceros: sin código es 428 y no encola el aviso al dueño; con código sí (una vez).
     *
     * @return void
     */
    public function test_put_update_es_terceros_y_no_encola_el_aviso_sin_codigo(): void
    {
        Queue::fake();

        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $upgrade = $this->crear_upgrade();
        $uri     = '/api/admin/update/' . $upgrade->id;

        $sin_codigo = $this->pedir_como($token, 'PUT', $uri, ['status' => 'terminada']);
        $sin_codigo->assertStatus(428);
        $sin_codigo->assertJsonPath('categoria', 'terceros');
        $this->assertSame('pendiente', $upgrade->fresh()->status, 'El upgrade no cambió de estado.');
        Queue::assertNotPushed(EnviarAvisoDeActualizacionJob::class);

        $codigo = $sin_codigo->json('codigo_confirmacion');
        $this->pedir_como($token, 'PUT', $uri, ['status' => 'terminada'], ['X-Agente-Confirmar' => $codigo])->assertOk();
        $this->assertSame('terminada', $upgrade->fresh()->status);
        Queue::assertPushed(EnviarAvisoDeActualizacionJob::class, 1);
    }

    /**
     * H3 (b) — `PUT client/{id}` con `api_url` frena como credenciales y la URL no cambia; con sólo `is_active` sigue libre (CA2).
     *
     * @return void
     */
    public function test_put_client_con_api_url_frena_y_con_is_active_sigue_libre(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();
        $uri     = '/api/admin/client/' . $cliente->id;

        $frenada = $this->pedir_como($token, 'PUT', $uri, ['api_url' => 'https://destino-ajeno.test']);
        $frenada->assertStatus(428);
        $frenada->assertJsonPath('categoria', 'credenciales');
        $this->assertSame('https://empresa-agente.test', $cliente->fresh()->api_url, 'api_url no cambió.');

        // Con el código se ejecuta.
        $this->pedir_como($token, 'PUT', $uri, ['api_url' => 'https://destino-ajeno.test'], [
            'X-Agente-Confirmar' => $frenada->json('codigo_confirmacion'),
        ])->assertOk();
        $this->assertSame('https://destino-ajeno.test', $cliente->fresh()->api_url);

        // Sólo is_active: libre, sin código (CA2 intacto).
        $this->pedir_como($token, 'PUT', $uri, ['is_active' => false])->assertOk();
        $this->assertSame(0, (int) $cliente->fresh()->is_active);
    }

    /**
     * H3 (b) — El destino de la api_key también se frena cuando se cambia por la API activa.
     *
     * @return void
     */
    public function test_put_client_con_active_client_api_id_frena(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();
        $api     = $this->crear_api_de_cliente($cliente);

        $respuesta = $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['active_client_api_id' => $api->id]);

        $respuesta->assertStatus(428);
        $respuesta->assertJsonPath('categoria', 'credenciales');
    }

    /**
     * H3 — El catálogo muestra `campos_frenados`, `PUT update/{id}` como terceros y la nota de Meta en `PUT lead/{id}`.
     *
     * @return void
     */
    public function test_el_catalogo_muestra_campos_frenados_y_las_reclasificaciones(): void
    {
        $agente = $this->crear_agente();
        $token  = $this->token_de($agente);

        $operaciones = $this->pedir_como($token, 'GET', '/api/admin/agente/operaciones')->assertOk()->json('operaciones');

        $buscar = function (string $metodo, string $ruta) use ($operaciones) {
            foreach ($operaciones as $operacion) {
                if ($operacion['metodo'] === $metodo && $operacion['ruta'] === $ruta) {
                    return $operacion;
                }
            }

            return null;
        };

        $cliente = $buscar('PUT', 'client/{id}');
        $this->assertNotNull($cliente);
        $this->assertSame('escritura', $cliente['categoria'], 'Sin los campos frenados sigue siendo escritura libre.');
        $this->assertSame('credenciales', $cliente['campos_frenados']['api_url'] ?? null);

        $update = $buscar('PUT', 'update/{id}');
        $this->assertNotNull($update);
        $this->assertSame('terceros', $update['categoria']);
        $this->assertTrue($update['frenada']);

        $lead = $buscar('PUT', 'lead/{id}');
        $this->assertNotNull($lead);
        $this->assertSame('escritura', $lead['categoria'], 'La conversión a Meta es el comportamiento normal del panel.');
        $this->assertTrue($this->contiene($lead['nota'], 'Meta'), 'La nota avisa que el cambio de status reporta a Meta.');
    }

    // ---------------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------------

    /**
     * Crea un upgrade `pendiente` con cliente, API destino y dos versiones publicadas.
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(): ClientVersionUpgrade
    {
        $this->contador_de_versiones += 2;

        $from = $this->crear_version('8.' . $this->contador_de_versiones . '.0');
        $to   = $this->crear_version('8.' . $this->contador_de_versiones . '.1');

        $cliente = $this->crear_cliente(null, [
            'company_name'       => 'Empresa de prueba',
            'slug'              => 'cliente-ronda2-' . Str::random(8),
            'inbound_api_key'   => 'clave-inbound',
            'current_version_id' => $from->id,
        ]);

        $api               = new ClientApi();
        $api->client_id    = $cliente->id;
        $api->url          = 'https://api-destino.ejemplo.test';
        $api->path         = 'ejemplo/' . Str::random(6);
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return ClientVersionUpgrade::create([
            'client_id'            => $cliente->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $api->id,
        ]);
    }

    /**
     * Versión publicada del catálogo.
     *
     * @param  string $codigo Número de versión.
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = 'published';
        $version->published_at = now();
        $version->save();

        return $version;
    }
}
