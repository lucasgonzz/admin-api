<?php

namespace Tests\Feature\FichaDelCliente;

use App\Models\ClientEmployee;
use App\Models\ClientSupportContext;
use App\Models\ClientSupportMemoryEntry;
use App\Models\SupportTicket;
use App\Services\ClientMemory\ClientSupportMemoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La ficha del cliente desde el monitor de Martín (misión cruzada `ficha-del-cliente`, 11/11/2026):
 * `POST tickets/{id}/client-memory` (agregar / reemplazar / archivar, todo o nada, `entrada_ajena`, `ficha_llena`,
 * `dato_sensible`, reserva), el bloque `ficha_cliente` del contexto (orden, tope, `ficha_operativa`), la búsqueda en
 * todo el historial, `history?ticket_id=`, el título y resumen del ticket y el índice de tickets.
 */
class FichaDelClienteDesdeElMonitorTest extends TestCase
{
    use DatabaseTransactions;
    use AndamiajeDeLaFicha;

    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'ficha-cliente-test', 'Accept' => 'application/json'];

    /** @var string */
    private $base = '/api/claude/support-codex/tickets/';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'ficha-cliente-test']);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-11 13:00:00'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param SupportTicket        $ticket      El ticket.
     * @param string               $token       La reserva.
     * @param array<int, mixed>    $operaciones Las operaciones.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function ficha(SupportTicket $ticket, string $token, array $operaciones)
    {
        return $this->postJson($this->base . $ticket->id . '/client-memory', ['lease_token' => $token, 'operaciones' => $operaciones], $this->headers);
    }

    // ── operaciones ─────────────────────────────────────────────────────────────────────────────

    /**
     * Agregar, reemplazar y archivar: la vieja queda reemplazada con la nueva apuntándola, la archivada con su
     * motivo, todo con source martin y el ticket de origen; la respuesta trae el bloque con las vigentes.
     *
     * @return void
     */
    public function test_agregar_reemplazar_y_archivar(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client);
        $token  = $this->reservar($ticket);

        $alta = $this->ficha($ticket, $token, [
            ['accion' => 'agregar', 'categoria' => 'decision', 'texto' => 'Los precios van a la lista Mayorista.'],
            ['accion' => 'agregar', 'categoria' => 'compromiso', 'texto' => 'Arrancamos la carga el lunes 17.', 'vigente_hasta' => '2026-11-17'],
        ])->assertOk()->assertJsonPath('ok', true);

        [$decision, $compromiso] = array_column($alta->json('resultados'), 'id');
        $this->assertSame(['agregar', 'agregar'], array_column($alta->json('resultados'), 'accion'));
        $this->assertSame(2, $alta->json('ficha_cliente.total_vigentes'));

        $guardada = ClientSupportMemoryEntry::find($compromiso);
        $this->assertSame('martin', $guardada->source);
        $this->assertSame((int) $ticket->id, $guardada->support_ticket_id);
        $this->assertSame('2026-11-17', $guardada->vigente_hasta->format('Y-m-d'));

        $cambio = $this->ficha($ticket, $token, [
            ['accion' => 'reemplazar', 'id' => $decision, 'texto' => 'Los precios van a la lista Minorista desde el 11/11.'],
            ['accion' => 'archivar', 'id' => $compromiso, 'motivo' => 'Se cumplió: la carga arrancó.'],
        ])->assertOk();

        $nueva = ClientSupportMemoryEntry::find($cambio->json('resultados.0.id'));
        $this->assertSame('vigente', $nueva->status);
        $this->assertSame($decision, $nueva->replaces_id);
        $this->assertSame('decision', $nueva->categoria, 'Sin categoría, hereda la de la vieja.');
        $this->assertSame($decision, $cambio->json('resultados.0.replaces_id'));
        $this->assertSame('reemplazada', ClientSupportMemoryEntry::find($decision)->status);
        $this->assertSame('Los precios van a la lista Mayorista.', ClientSupportMemoryEntry::find($decision)->texto, 'La vieja no se edita en el lugar.');
        $this->assertSame('archivada', ClientSupportMemoryEntry::find($compromiso)->status);
        $this->assertSame('Se cumplió: la carga arrancó.', ClientSupportMemoryEntry::find($compromiso)->archived_reason);

        $this->assertSame([(int) $nueva->id], array_column($cambio->json('ficha_cliente.entradas'), 'id'));
        $this->assertSame(1, $cambio->json('ficha_cliente.total_vigentes'));
    }

    /**
     * Todo o nada: si una operación del pedido falla (acá, un id de otro cliente: 422 entrada_ajena), no queda
     * escrita ninguna de las anteriores. Un id que no existe es el mismo 422.
     *
     * @return void
     */
    public function test_todo_o_nada_y_entrada_ajena(): void
    {
        $client = $this->cliente();
        $otro   = $this->cliente('Otro Negocio');
        $ajena  = $this->entrada($otro, 'Del otro cliente');
        $propia = $this->entrada($client, 'Prefiere audios.', ['categoria' => 'preferencia']);
        $ticket = $this->ticket($client);
        $token  = $this->reservar($ticket);

        $this->ficha($ticket, $token, [
            ['accion' => 'agregar', 'categoria' => 'negocio', 'texto' => 'Fía a clientes conocidos.'],
            ['accion' => 'archivar', 'id' => $propia->id, 'motivo' => 'Ya no'],
            ['accion' => 'reemplazar', 'id' => $ajena->id, 'texto' => 'Pisado'],
        ])->assertStatus(422)->assertJsonPath('error', 'entrada_ajena')->assertJsonPath('motivo', 'entrada_ajena');

        $this->assertCount(1, $this->entradas($client), 'No quedó la agregada.');
        $this->assertSame('vigente', $propia->fresh()->status, 'No quedó el archivo.');
        $this->assertSame('Del otro cliente', $ajena->fresh()->texto);
        $this->assertSame('vigente', $ajena->fresh()->status);

        $this->ficha($ticket, $token, [['accion' => 'archivar', 'id' => 999999999, 'motivo' => 'x']])
            ->assertStatus(422)->assertJsonPath('error', 'entrada_ajena');

        /* Una ya reemplazada no se vuelve a reemplazar con otro texto. */
        $vieja = $this->entrada($client, 'Texto viejo', ['status' => 'reemplazada']);
        $this->ficha($ticket, $token, [['accion' => 'reemplazar', 'id' => $vieja->id, 'texto' => 'Otro']])
            ->assertStatus(422)->assertJsonPath('error', 'entrada_no_vigente');
    }

    /**
     * Tope de 60 vigentes: la 61 es 422 ficha_llena sin escribir nada; reemplazar con la ficha llena sigue andando
     * (no suma vigentes).
     *
     * @return void
     */
    public function test_ficha_llena(): void
    {
        $client = $this->cliente();
        for ($numero = 1; $numero <= ClientSupportMemoryService::TOPE_VIGENTES; $numero++) {
            $this->entrada($client, 'Entrada ' . $numero);
        }
        $ticket = $this->ticket($client);
        $token  = $this->reservar($ticket);

        $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'otro', 'texto' => 'La 61']])
            ->assertStatus(422)->assertJsonPath('error', 'ficha_llena')->assertJsonPath('tope', 60);
        $this->assertCount(60, $this->entradas($client));

        $primera = $this->entradas($client)->first();
        $this->ficha($ticket, $token, [['accion' => 'reemplazar', 'id' => $primera->id, 'texto' => 'Consolidada: entradas 1 a 3.']])->assertOk();
        $this->assertCount(60, $this->entradas($client));
    }

    /**
     * Lo que el historial oculta como secreto (lo que sigue a «contraseña» / «clave») no entra: 422 dato_sensible, sin
     * escribir nada.
     *
     * @return void
     */
    public function test_dato_sensible(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client);
        $token  = $this->reservar($ticket);

        foreach (['La contraseña es Perro123', 'Su clave: 4455', 'Clave fiscal nueva Gato99'] as $texto) {
            $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'otro', 'texto' => $texto]])
                ->assertStatus(422)->assertJsonPath('error', 'dato_sensible');
        }
        $this->assertCount(0, $this->entradas($client));

        $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'negocio', 'texto' => 'Factura A y tiene dos sucursales.']])->assertOk();
    }

    /**
     * Sin la reserva vigente del ticket: 423 invalid_lease (la validación de implementation-note). Ticket inexistente:
     * 404. La validación del cuerpo: 422 con la forma de claude/*.
     *
     * @return void
     */
    public function test_reserva_y_validacion(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client);
        $alta   = [['accion' => 'agregar', 'categoria' => 'decision', 'texto' => 'Algo']];

        $this->ficha($ticket, Str::random(64), $alta)->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
        $token = $this->reservar($ticket);
        $this->ficha($ticket, Str::random(64), $alta)->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
        $this->assertCount(0, $this->entradas($client));

        $vencido = $this->ticket($client);
        $token_vencido = $this->reservar($vencido);
        \App\Models\SupportCodexCase::where('support_ticket_id', $vencido->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->ficha($vencido, $token_vencido, $alta)->assertStatus(423);

        $this->postJson($this->base . '999999999/client-memory', ['lease_token' => $token, 'operaciones' => $alta], $this->headers)->assertStatus(404);

        $this->ficha($ticket, $token, [])->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos');
        $this->ficha($ticket, $token, array_fill(0, 11, $alta[0]))->assertStatus(422);
        $this->ficha($ticket, $token, [['accion' => 'agregar', 'texto' => 'Sin categoría']])->assertStatus(422);
        $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'inventada', 'texto' => 'x']])->assertStatus(422);
        $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'otro', 'texto' => str_repeat('a', 601)]])->assertStatus(422);
        $this->ficha($ticket, $token, [['accion' => 'archivar', 'id' => 1]])->assertStatus(422);
        $this->ficha($ticket, $token, [['accion' => 'borrar', 'id' => 1]])->assertStatus(422);
        $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'otro', 'texto' => 'x', 'vigente_hasta' => '17/11/2026']])->assertStatus(422);
        $this->postJson($this->base . $ticket->id . '/client-memory', ['operaciones' => $alta], $this->headers)->assertStatus(422);
        $this->assertCount(0, $this->entradas($client));

        $this->postJson($this->base . $ticket->id . '/client-memory', ['lease_token' => $token, 'operaciones' => $alta])->assertStatus(401);
    }

    /**
     * Reintentos inofensivos: el mismo agregar, el mismo reemplazo y archivar una archivada devuelven la entrada que ya
     * estaba con ya_estaba=true, sin filas nuevas.
     *
     * @return void
     */
    public function test_los_reintentos_no_duplican(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client);
        $token  = $this->reservar($ticket);
        $alta   = ['accion' => 'agregar', 'categoria' => 'preferencia', 'texto' => 'Los avisos al número de Gastón.'];

        $primera = $this->ficha($ticket, $token, [$alta])->assertOk()->json('resultados.0.id');
        $this->ficha($ticket, $token, [$alta])->assertOk()->assertJsonPath('resultados.0.id', $primera)->assertJsonPath('resultados.0.ya_estaba', true);

        $reemplazo = ['accion' => 'reemplazar', 'id' => $primera, 'texto' => 'Los avisos al número de Marta.'];
        $nueva = $this->ficha($ticket, $token, [$reemplazo])->assertOk()->json('resultados.0.id');
        $this->ficha($ticket, $token, [$reemplazo])->assertOk()->assertJsonPath('resultados.0.id', $nueva)->assertJsonPath('resultados.0.ya_estaba', true);

        $archivo = ['accion' => 'archivar', 'id' => $nueva, 'motivo' => 'Cambió de empleada.'];
        $this->ficha($ticket, $token, [$archivo])->assertOk();
        $this->ficha($ticket, $token, [$archivo])->assertOk()->assertJsonPath('resultados.0.ya_estaba', true);

        $this->assertSame(2, ClientSupportMemoryEntry::where('client_id', $client->id)->count());
    }

    /**
     * Un ticket sin cliente: 409 sin_cliente.
     *
     * @return void
     */
    public function test_ticket_sin_cliente(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client, 'open', 0, null, ['client_id' => 0]);
        $token  = $this->reservar($ticket);

        $this->ficha($ticket, $token, [['accion' => 'agregar', 'categoria' => 'otro', 'texto' => 'x']])
            ->assertStatus(409)->assertJsonPath('error', 'sin_cliente');
    }

    // ── el bloque ficha_cliente ──────────────────────────────────────────────────────────────────

    /**
     * El bloque del contexto: orden por categoría (compromiso, decisión, problema, preferencia, implementación,
     * negocio, otro) y la más nueva primero; vencida; solo las vigentes; la ficha operativa de Lucas; viaja también
     * cuando el contacto es un empleado. client_context sigue estando.
     *
     * @return void
     */
    public function test_el_bloque_en_el_contexto_del_ticket(): void
    {
        $client = $this->cliente();
        $otro   = $this->cliente('Otro');
        $this->entrada($otro, 'De otro cliente', ['categoria' => 'decision']);

        $otro_tipo  = $this->entrada($client, 'Algo suelto', ['categoria' => 'otro']);
        $negocio    = $this->entrada($client, 'Tiene dos sucursales', ['categoria' => 'negocio']);
        $problema   = $this->entrada($client, 'El lector no leía: se cambió el modo', ['categoria' => 'problema']);
        $decision1  = $this->entrada($client, 'Decisión vieja', ['categoria' => 'decision']);
        $decision2  = $this->entrada($client, 'Decisión nueva', ['categoria' => 'decision']);
        $vencido    = $this->entrada($client, 'Manda la lista el 10', ['categoria' => 'compromiso', 'vigente_hasta' => '2026-11-10']);
        $hoy        = $this->entrada($client, 'Llamarlo hoy', ['categoria' => 'compromiso', 'vigente_hasta' => '2026-11-11']);
        $preferencia = $this->entrada($client, 'Prefiere audios', ['categoria' => 'preferencia']);
        $this->entrada($client, 'Reemplazada', ['categoria' => 'decision', 'status' => 'reemplazada']);
        $this->entrada($client, 'Archivada', ['categoria' => 'decision', 'status' => 'archivada']);
        ClientSupportContext::create(['client_id' => $client->id, 'ficha_operativa' => 'Prosa de Lucas', 'notas_internas' => 'Nota que no viaja']);

        $ticket = $this->ticket($client);
        $bloque = $this->getJson($this->base . $ticket->id, $this->headers)->assertOk()->json('ticket.ficha_cliente');

        $this->assertSame(
            [$hoy->id, $vencido->id, $decision2->id, $decision1->id, $problema->id, $preferencia->id, $negocio->id, $otro_tipo->id],
            array_column($bloque['entradas'], 'id')
        );
        $this->assertSame(8, $bloque['total_vigentes']);
        $this->assertFalse($bloque['truncada']);
        $this->assertSame('Prosa de Lucas', $bloque['ficha_operativa']);
        $this->assertFalse($bloque['entradas'][0]['vencida'], 'Hoy todavía vale.');
        $this->assertTrue($bloque['entradas'][1]['vencida']);
        $this->assertSame('2026-11-10', $bloque['entradas'][1]['vigente_hasta']);
        $this->assertSame(['id', 'categoria', 'texto', 'vigente_hasta', 'vencida', 'source', 'support_ticket_id', 'updated_at'], array_keys($bloque['entradas'][0]));
        $this->assertStringNotContainsString('Nota que no viaja', (string) json_encode($bloque, JSON_UNESCAPED_UNICODE));

        /* Un empleado (sin can_query_system): el bloque viaja igual. */
        $empleado = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Brisa', 'phone' => '+5493419998888']);
        $del_empleado = $this->ticket($client, 'open', 0, $empleado->id);
        $contexto = $this->getJson($this->base . $del_empleado->id, $this->headers)->assertOk()->json('ticket');
        $this->assertSame(8, $contexto['ficha_cliente']['total_vigentes']);
        $this->assertArrayHasKey('client_context', $contexto);

        /* Sin entradas ni ficha operativa: el bloque viene vacío, no null. */
        $vacio = $this->getJson($this->base . $this->ticket($otro)->id, $this->headers)->assertOk()->json('ticket.ficha_cliente');
        $this->assertSame(1, $vacio['total_vigentes']);
        $this->assertNull($vacio['ficha_operativa']);
    }

    /**
     * El tope de ~12.000 caracteres: con 40 entradas de 590 caracteres no entran todas; truncada=true y
     * total_vigentes las cuenta todas. Las que entran son las primeras del orden.
     *
     * @return void
     */
    public function test_el_bloque_respeta_el_tope(): void
    {
        $client = $this->cliente();
        $ids = [];
        for ($numero = 1; $numero <= 40; $numero++) {
            $ids[] = $this->entrada($client, str_pad('Entrada ' . $numero . ' ', 590, 'x'), ['categoria' => $numero <= 5 ? 'compromiso' : 'otro'])->id;
        }

        $bloque = $this->getJson($this->base . $this->ticket($client)->id, $this->headers)->assertOk()->json('ticket.ficha_cliente');

        $this->assertTrue($bloque['truncada']);
        $this->assertSame(40, $bloque['total_vigentes']);
        $this->assertLessThan(40, count($bloque['entradas']));
        $this->assertGreaterThan(10, count($bloque['entradas']));
        $this->assertLessThanOrEqual(12000, mb_strlen((string) json_encode($bloque['entradas'], JSON_UNESCAPED_UNICODE)));
        $this->assertSame(array_reverse(array_slice($ids, 0, 5)), array_slice(array_column($bloque['entradas'], 'id'), 0, 5), 'Los compromisos primero, el más nuevo arriba.');
    }

    // ── búsqueda en el historial ────────────────────────────────────────────────────────────────

    /**
     * history-search: coincidencias en TODOS los tickets del cliente (también el actual y los de hace mucho, más de
     * cinco), sin borradores ni otros clientes; el fragmento recortado y saneado; la lista de todos los tickets con
     * su primer mensaje; los comodines de LIKE no comodinean.
     *
     * @return void
     */
    public function test_buscar_en_todo_el_historial(): void
    {
        $client = $this->cliente();
        $otro   = $this->cliente('Otro');
        $this->mensaje($this->ticket($otro, 'closed', 2), 'La impresora fiscal de otro');

        $viejo = $this->ticket($client, 'closed', 300);
        $this->mensaje($viejo, 'Hola, la impresora fiscal no imprime los tickets');
        $this->mensaje($viejo, str_repeat('Contexto largo. ', 30) . 'Reinstalamos el driver de la impresora y quedó. Tu clave: Perro123', 'admin');
        for ($dias = 200; $dias >= 190; $dias -= 2) {
            $this->mensaje($this->ticket($client, 'closed', $dias), 'Consulta de ' . $dias . ' días');
        }
        $actual = $this->ticket($client, 'open', 0);
        $this->mensaje($actual, 'Otra vez la IMPRESORA no anda');
        $this->mensaje($actual, 'Borrador: la impresora', 'admin', true);

        $respuesta = $this->getJson($this->base . $actual->id . '/history-search?q=impresora', $this->headers)->assertOk();

        $coincidencias = $respuesta->json('coincidencias');
        $this->assertSame([(int) $actual->id, (int) $viejo->id, (int) $viejo->id], array_column($coincidencias, 'ticket_id'));
        $this->assertSame(['user', 'admin', 'user'], array_column($coincidencias, 'sender_type'));
        $this->assertSame(['ticket_id', 'message_id', 'created_at', 'sender_type', 'fragmento'], array_keys($coincidencias[0]));
        $equipo = $coincidencias[1]['fragmento'];
        $this->assertStringStartsWith('…', $equipo);
        $this->assertStringContainsString('driver de la impresora', $equipo);
        $this->assertStringContainsString('[oculta]', $equipo);
        $this->assertStringNotContainsString('Perro123', $this->cuerpo($respuesta));
        $this->assertStringNotContainsString('Borrador', $this->cuerpo($respuesta));
        $this->assertStringNotContainsString('de otro', $this->cuerpo($respuesta));

        $tickets = $respuesta->json('tickets');
        $this->assertSame(8, $respuesta->json('total_tickets'));
        $this->assertCount(8, $tickets);
        $this->assertSame((int) $actual->id, $tickets[0]['id']);
        $this->assertSame((int) $viejo->id, $tickets[7]['id']);
        $this->assertSame('Hola, la impresora fiscal no imprime los tickets', $tickets[7]['primer_mensaje']);
        $this->assertSame(2, $tickets[7]['mensajes']);
        foreach (['id', 'status', 'opened_at', 'closed_at', 'mensajes', 'primer_mensaje'] as $clave) {
            $this->assertArrayHasKey($clave, $tickets[0]);
        }

        /* limite, sin coincidencias y comodines. */
        $this->getJson($this->base . $actual->id . '/history-search?q=impresora&limite=1', $this->headers)->assertOk()->assertJsonCount(1, 'coincidencias');
        $this->getJson($this->base . $actual->id . '/history-search?q=' . urlencode('%%'), $this->headers)->assertOk()->assertJsonCount(0, 'coincidencias');
        $this->getJson($this->base . $actual->id . '/history-search?q=' . urlencode('i_presora'), $this->headers)->assertOk()->assertJsonCount(0, 'coincidencias');

        /* Validación y ticket inexistente. */
        $this->getJson($this->base . $actual->id . '/history-search?q=a', $this->headers)->assertStatus(422);
        $this->getJson($this->base . $actual->id . '/history-search?q=' . str_repeat('a', 101), $this->headers)->assertStatus(422);
        $this->getJson($this->base . $actual->id . '/history-search?q=impresora&limite=41', $this->headers)->assertStatus(422);
        $this->getJson($this->base . $actual->id . '/history-search', $this->headers)->assertStatus(422);
        $this->getJson($this->base . '999999999/history-search?q=impresora', $this->headers)->assertStatus(404);
    }

    /**
     * Un empleado busca solo en SUS tickets (la regla de previous_tickets); el dueño, en todos.
     *
     * @return void
     */
    public function test_buscar_respeta_la_visibilidad_del_empleado(): void
    {
        $client   = $this->cliente();
        $empleado = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Brisa', 'phone' => '+5493419998888']);

        $this->mensaje($this->ticket($client, 'closed', 9), 'El dueño pregunta por la caja');
        $suyo = $this->ticket($client, 'closed', 8, $empleado->id);
        $this->mensaje($suyo, 'La empleada pregunta por la caja');
        $actual = $this->ticket($client, 'open', 0, $empleado->id);

        $respuesta = $this->getJson($this->base . $actual->id . '/history-search?q=caja', $this->headers)->assertOk();
        $this->assertSame([(int) $suyo->id], array_column($respuesta->json('coincidencias'), 'ticket_id'));
        $this->assertSame([(int) $actual->id, (int) $suyo->id], array_column($respuesta->json('tickets'), 'id'));

        $del_dueno = $this->ticket($client, 'open', 0);
        $this->getJson($this->base . $del_dueno->id . '/history-search?q=caja', $this->headers)->assertOk()->assertJsonCount(2, 'coincidencias');
    }

    /**
     * history?ticket_id=N: solo ese ticket del cliente, con sus últimos 60 mensajes reales, título y resumen. Uno de
     * otro cliente o no visible para el empleado: 404; el actual: 422; un id no entero: 422. Sin el parámetro, lo de
     * siempre.
     *
     * @return void
     */
    public function test_history_de_un_ticket_puntual(): void
    {
        $client = $this->cliente();
        $viejo  = $this->ticket($client, 'closed', 400, null, ['name' => 'Impresora fiscal', 'summary' => 'Se reinstaló el driver.']);
        for ($numero = 1; $numero <= 65; $numero++) {
            $this->mensaje($viejo, 'Mensaje ' . $numero, $numero % 2 === 0 ? 'admin' : 'user');
        }
        $this->mensaje($viejo, 'Borrador', 'admin', true);
        for ($dias = 50; $dias >= 44; $dias--) {
            $this->ticket($client, 'closed', $dias);
        }
        $actual = $this->ticket($client);

        $respuesta = $this->getJson($this->base . $actual->id . '/history?ticket_id=' . $viejo->id, $this->headers)->assertOk();
        $this->assertCount(1, $respuesta->json('tickets'));
        $uno = $respuesta->json('tickets.0');
        $this->assertSame((int) $viejo->id, $uno['id']);
        $this->assertCount(60, $uno['messages']);
        $this->assertSame('Mensaje 6', $uno['messages'][0]['body']);
        $this->assertSame('Mensaje 65', $uno['messages'][59]['body']);
        $this->assertSame('Impresora fiscal', $uno['titulo']);
        $this->assertSame('Se reinstaló el driver.', $uno['resumen']);

        /* Sin el parámetro: los 5 anteriores (el viejo no está), 30 mensajes, sin titulo ni resumen. */
        $siempre = $this->getJson($this->base . $actual->id . '/history', $this->headers)->assertOk();
        $this->assertCount(5, $siempre->json('tickets'));
        $this->assertNotContains((int) $viejo->id, array_column($siempre->json('tickets'), 'id'));
        $this->assertArrayNotHasKey('titulo', $siempre->json('tickets.0'));

        $otro = $this->ticket($this->cliente('Otro'), 'closed', 3);
        $this->getJson($this->base . $actual->id . '/history?ticket_id=' . $otro->id, $this->headers)->assertStatus(404)->assertJsonPath('error', 'ticket_ajeno');
        $this->getJson($this->base . $actual->id . '/history?ticket_id=999999999', $this->headers)->assertStatus(404);
        $this->getJson($this->base . $actual->id . '/history?ticket_id=' . $actual->id, $this->headers)->assertStatus(422)->assertJsonPath('error', 'es_el_ticket_actual');
        $this->getJson($this->base . $actual->id . '/history?ticket_id=abc', $this->headers)->assertStatus(422);

        $empleado = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Brisa', 'phone' => '+5493419998888']);
        $del_empleado = $this->ticket($client, 'open', 0, $empleado->id);
        $this->getJson($this->base . $del_empleado->id . '/history?ticket_id=' . $viejo->id, $this->headers)->assertStatus(404);
    }

    // ── título, resumen e índice ─────────────────────────────────────────────────────────────────

    /**
     * title: guarda name + summary con name_source martin; un nombre puesto por Lucas desde la bandeja no se pisa
     * (titulo_conservado, solo el resumen); reenviar el mismo nombre desde la bandeja no lo vuelve de Lucas.
     *
     * @return void
     */
    public function test_titulo_y_resumen(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client, 'open', 0, null, ['name' => 'Nombre automático viejo']);
        $token  = $this->reservar($ticket);
        $ruta   = $this->base . $ticket->id . '/title';

        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => 'Cartel de pago de la mensualidad', 'resumen' => 'El cartel salía con la cuota paga.'], $this->headers)
            ->assertOk()->assertJson(['ok' => true, 'titulo_conservado' => false, 'titulo' => 'Cartel de pago de la mensualidad', 'resumen' => 'El cartel salía con la cuota paga.']);
        $ticket->refresh();
        $this->assertSame('Cartel de pago de la mensualidad', $ticket->name);
        $this->assertSame('El cartel salía con la cuota paga.', $ticket->summary);
        $this->assertSame('martin', $ticket->name_source);
        $this->assertNotNull($ticket->summary_updated_at);

        /* Sin resumen: solo el título; el resumen anterior queda. */
        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => 'Cartel de la mensualidad'], $this->headers)->assertOk();
        $this->assertSame('El cartel salía con la cuota paga.', $ticket->fresh()->summary);

        /* La bandeja reenvía el mismo nombre al cambiar otra cosa: sigue siendo de Martín. */
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/support-ticket/' . $ticket->id, ['name' => 'Cartel de la mensualidad', 'assigned_admin_id' => null])->assertOk();
        $this->assertSame('martin', $ticket->fresh()->name_source);

        /* Lucas lo renombra: ahora es suyo y Martín no lo pisa. */
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/support-ticket/' . $ticket->id, ['name' => 'Mensualidad Gastón'])->assertOk();
        $this->assertSame('lucas', $ticket->fresh()->name_source);

        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => 'Otro título', 'resumen' => 'Resuelto: era la fecha de vencimiento.'], $this->headers)
            ->assertOk()->assertJson(['ok' => true, 'titulo_conservado' => true, 'titulo' => 'Mensualidad Gastón']);
        $this->assertSame('Mensualidad Gastón', $ticket->fresh()->name);
        $this->assertSame('Resuelto: era la fecha de vencimiento.', $ticket->fresh()->summary);

        /* Vaciarlo desde la bandeja se lo devuelve a Martín. */
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/support-ticket/' . $ticket->id, ['name' => ''])->assertOk();
        $this->assertNull($ticket->fresh()->name_source);

        /* Frenos. */
        $this->postJson($ruta, ['lease_token' => Str::random(64), 'titulo' => 'Algo válido'], $this->headers)->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => 'ab'], $this->headers)->assertStatus(422);
        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => str_repeat('a', 81)], $this->headers)->assertStatus(422);
        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => 'Algo válido', 'resumen' => str_repeat('a', 601)], $this->headers)->assertStatus(422);
        $this->postJson($ruta, ['lease_token' => $token, 'titulo' => 'Clave: 1234'], $this->headers)->assertStatus(422)->assertJsonPath('error', 'dato_sensible');
    }

    /**
     * indice_tickets: TODOS los tickets del cliente (más de cinco) visibles para el contacto, el más nuevo primero,
     * con el actual marcado, título y resumen (≤ 200); un empleado ve solo los suyos. El contexto suma summary.
     * previous_tickets no cambia.
     *
     * @return void
     */
    public function test_indice_de_tickets(): void
    {
        $client   = $this->cliente();
        $empleado = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Brisa', 'phone' => '+5493419998888']);
        $ids = [];
        for ($dias = 30; $dias >= 23; $dias--) {
            $ids[] = $this->ticket($client, 'closed', $dias, null, ['name' => 'Ticket de hace ' . $dias, 'summary' => str_repeat('r', 250)])->id;
        }
        $del_empleado = $this->ticket($client, 'closed', 5, $empleado->id, ['name' => 'Del empleado']);
        $this->mensaje($del_empleado, 'Hola');
        $actual = $this->ticket($client, 'open', 0, null, ['summary' => 'Resumen del actual']);

        $contexto = $this->getJson($this->base . $actual->id, $this->headers)->assertOk()->json('ticket');
        $indice   = $contexto['indice_tickets'];

        $this->assertSame(10, $indice['total']);
        $this->assertFalse($indice['truncado']);
        $this->assertSame(array_merge([(int) $actual->id, (int) $del_empleado->id], array_reverse($ids)), array_column($indice['tickets'], 'id'));
        $this->assertTrue($indice['tickets'][0]['actual']);
        $this->assertFalse($indice['tickets'][1]['actual']);
        $this->assertNull($indice['tickets'][0]['titulo']);
        $this->assertSame('Del empleado', $indice['tickets'][1]['titulo']);
        $this->assertSame(1, $indice['tickets'][1]['mensajes']);
        $this->assertSame(200, mb_strlen($indice['tickets'][2]['resumen']));
        $this->assertSame(['id', 'titulo', 'resumen', 'status', 'opened_at', 'closed_at', 'mensajes', 'actual'], array_keys($indice['tickets'][0]));
        $this->assertSame('Resumen del actual', $contexto['summary']);
        $this->assertCount(5, $contexto['previous_tickets']);
        $this->assertSame(['id', 'status', 'opened_at', 'closed_at', 'mensajes'], array_keys($contexto['previous_tickets'][0]));

        $suyo = $this->ticket($client, 'open', 0, $empleado->id);
        $del_suyo = $this->getJson($this->base . $suyo->id, $this->headers)->assertOk()->json('ticket.indice_tickets');
        $this->assertSame([(int) $suyo->id, (int) $del_empleado->id], array_column($del_suyo['tickets'], 'id'));
    }

    /**
     * El tope de ~10.000 caracteres del índice: con 60 tickets de título y resumen largos, truncado=true, total = 60,
     * y los que entran son los más nuevos.
     *
     * @return void
     */
    public function test_el_indice_respeta_el_tope(): void
    {
        $client = $this->cliente();
        for ($numero = 1; $numero <= 60; $numero++) {
            $this->ticket($client, 'closed', 100 - $numero, null, ['name' => str_pad('Título ' . $numero, 80, 't'), 'summary' => str_repeat('r', 600)]);
        }
        $actual = $this->ticket($client);

        $indice = $this->getJson($this->base . $actual->id, $this->headers)->assertOk()->json('ticket.indice_tickets');

        $this->assertTrue($indice['truncado']);
        $this->assertSame(61, $indice['total']);
        $this->assertLessThan(61, count($indice['tickets']));
        $this->assertLessThanOrEqual(10000, mb_strlen((string) json_encode($indice['tickets'], JSON_UNESCAPED_UNICODE)));
        $this->assertSame((int) $actual->id, $indice['tickets'][0]['id']);
    }

    /**
     * @return \App\Models\Admin
     */
    private function admin()
    {
        $admin           = new \App\Models\Admin();
        $admin->name     = 'Lucas ' . Str::random(4);
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * @param \Illuminate\Testing\TestResponse $respuesta La respuesta.
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

