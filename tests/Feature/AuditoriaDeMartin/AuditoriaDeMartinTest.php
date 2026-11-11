<?php

namespace Tests\Feature\AuditoriaDeMartin;

use App\Models\Admin;
use App\Models\ImplementationFollowup;
use App\Models\SupportCodexActivity;
use App\Models\SupportCodexPlan;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\FichaDelCliente\AndamiajeDeLaFicha;
use Tests\TestCase;

/**
 * La auditoría de Martín (misión cruzada `auditoria-de-martin`, 11/11/2026): la ingesta de la actividad del monitor
 * (`POST claude/support-codex/activity`: tanda, idempotencia, ticket o seguimiento inexistente, validación), el plan por
 * ejecución (`PUT claude/support-codex/plans/{run_id}`, upsert), la lectura del panel agrupada por atención
 * (`GET admin/support-ticket/{id}/martin-activity`) y los dos contadores del ticket en la bandeja.
 */
class AuditoriaDeMartinTest extends TestCase
{
    use DatabaseTransactions;
    use AndamiajeDeLaFicha;

    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'auditoria-martin-test', 'Accept' => 'application/json'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'auditoria-martin-test']);
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
     * @return Admin
     */
    private function admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas ' . Str::random(4);
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Un item de actividad del ticket.
     *
     * @param SupportTicket        $ticket El ticket.
     * @param array<string, mixed> $extra  Campos a pisar.
     *
     * @return array<string, mixed>
     */
    private function item(SupportTicket $ticket, array $extra = []): array
    {
        return array_merge([
            'support_ticket_id' => $ticket->id, 'run_id' => 'run-1', 'kind' => 'lectura', 'command' => 'sql',
            'summary' => 'Consultó la base: SELECT id FROM articles LIMIT 5', 'detail' => ['filas' => 5, 'truncado' => false],
            'ok' => true, 'occurred_at' => '2026-11-11T12:00:00-03:00',
        ], $extra);
    }

    /**
     * @param array<int, array<string, mixed>> $items Los items.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function actividad(array $items)
    {
        return $this->postJson('/api/claude/support-codex/activity', ['items' => $items], $this->headers);
    }

    /**
     * @param string               $run_id La ejecución.
     * @param array<string, mixed> $body   El cuerpo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function plan(string $run_id, array $body)
    {
        return $this->putJson('/api/claude/support-codex/plans/' . $run_id, $body, $this->headers);
    }

    /**
     * Un seguimiento del cliente.
     *
     * @param \App\Models\Client $client El cliente.
     *
     * @return ImplementationFollowup
     */
    private function seguimiento($client): ImplementationFollowup
    {
        $followup            = new ImplementationFollowup();
        $followup->client_id = $client->id;
        $followup->status    = ImplementationFollowup::STATUS_ACTIVO;
        $followup->save();

        return $followup;
    }

    // ── actividad ───────────────────────────────────────────────────────────────────────────────

    /**
     * Una tanda con acciones de un ticket y de un seguimiento: se guardan todas, con el client_id derivado (nunca del
     * pedido), el detail como JSON, ok y la hora en la de Argentina.
     *
     * @return void
     */
    public function test_alta_de_una_tanda(): void
    {
        $client   = $this->cliente();
        $ticket   = $this->ticket($client);
        $followup = $this->seguimiento($this->cliente('Seguido'));

        $this->actividad([
            $this->item($ticket, ['client_id' => 999999]),
            $this->item($ticket, ['kind' => 'registro', 'command' => 'ficha', 'summary' => 'Ficha: agregó 1 entrada (decision)', 'detail' => null, 'occurred_at' => '2026-11-11T15:05:30Z']),
            ['implementation_followup_id' => $followup->id, 'run_id' => 'run-seg', 'kind' => 'envio', 'command' => 'seguimiento-enviar',
                'summary' => 'Respondió al cliente (estado sent)', 'ok' => false, 'occurred_at' => '2026-11-11 12:30:00'],
        ])->assertOk()->assertExactJson(['guardados' => 3, 'repetidos' => 0]);

        $filas = SupportCodexActivity::query()->whereIn('run_id', ['run-1', 'run-seg'])->orderBy('id')->get();
        $this->assertCount(3, $filas);
        $this->assertSame([(int) $client->id, (int) $client->id, (int) $followup->client_id], $filas->pluck('client_id')->all());
        $this->assertSame(['filas' => 5, 'truncado' => false], $filas[0]->detail);
        $this->assertNull($filas[1]->detail);
        $this->assertSame('2026-11-11 12:05:30', $filas[1]->occurred_at->format('Y-m-d H:i:s'), 'La hora UTC se guarda en la de Argentina.');
        $this->assertNull($filas[2]->support_ticket_id);
        $this->assertSame((int) $followup->id, $filas[2]->implementation_followup_id);
        $this->assertFalse($filas[2]->ok);
        $this->assertSame('envio', $filas[2]->kind);
    }

    /**
     * Idempotencia por (run_id, command, occurred_at, summary): reenviar la tanda no duplica; la misma hora con otro huso
     * es la misma; un repetido dentro de la tanda cuenta como repetido; cambiar el summary o el run_id es otra acción.
     *
     * @return void
     */
    public function test_idempotencia(): void
    {
        $ticket = $this->ticket($this->cliente());
        $tanda  = [$this->item($ticket), $this->item($ticket, ['command' => 'logs', 'summary' => 'Leyó el log de la API del sistema (200 líneas)'])];

        $this->actividad($tanda)->assertOk()->assertExactJson(['guardados' => 2, 'repetidos' => 0]);
        $this->actividad($tanda)->assertOk()->assertExactJson(['guardados' => 0, 'repetidos' => 2]);

        $this->actividad([
            $this->item($ticket, ['occurred_at' => '2026-11-11T15:00:00Z', 'ok' => false, 'detail' => ['otra' => 1]]),
            $this->item($ticket, ['summary' => 'Consultó la base: SELECT 1']),
            $this->item($ticket, ['summary' => 'Consultó la base: SELECT 1']),
            $this->item($ticket, ['run_id' => 'run-2']),
        ])->assertOk()->assertExactJson(['guardados' => 2, 'repetidos' => 2]);

        $this->assertSame(4, SupportCodexActivity::where('support_ticket_id', $ticket->id)->count());
        $this->assertTrue(SupportCodexActivity::where('support_ticket_id', $ticket->id)->where('command', 'sql')->where('run_id', 'run-1')->orderBy('id')->first()->ok, 'El repetido no pisa el original.');
    }

    /**
     * Un ticket o un seguimiento que no existe: 422 con el item señalado y no se guarda NADA de la tanda.
     *
     * @return void
     */
    public function test_ticket_o_seguimiento_inexistente(): void
    {
        $ticket = $this->ticket($this->cliente());

        $this->actividad([$this->item($ticket), $this->item($ticket, ['support_ticket_id' => 999999999, 'summary' => 'Otro'])])
            ->assertStatus(422)
            ->assertJsonPath('error', 'parámetros inválidos')
            ->assertJsonStructure(['errores' => ['items.1.support_ticket_id']]);

        $this->actividad([['implementation_followup_id' => 999999999, 'run_id' => 'r', 'kind' => 'envio', 'command' => 'seguimiento-enviar', 'summary' => 'x', 'ok' => true, 'occurred_at' => '2026-11-11T12:00:00-03:00']])
            ->assertStatus(422)
            ->assertJsonStructure(['errores' => ['items.0.implementation_followup_id']]);

        $this->assertSame(0, SupportCodexActivity::where('support_ticket_id', $ticket->id)->count());
    }

    /**
     * Validación: sin clave 401; tanda vacía o de más de 50; summary de 301; detail de más de 2 KB; kind inventado;
     * sin ticket ni seguimiento; run_id con barra; ok y occurred_at inválidos. Todo 422 con la forma de claude/*.
     *
     * @return void
     */
    public function test_validacion(): void
    {
        $ticket = $this->ticket($this->cliente());

        $this->postJson('/api/claude/support-codex/activity', ['items' => [$this->item($ticket)]], ['Accept' => 'application/json'])->assertStatus(401);
        $this->actividad([])->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos');
        $this->postJson('/api/claude/support-codex/activity', [], $this->headers)->assertStatus(422)->assertJsonStructure(['errores' => ['items']]);

        $muchos = [];
        for ($i = 0; $i < 51; $i++) {
            $muchos[] = $this->item($ticket, ['summary' => 'Acción ' . $i]);
        }
        $this->actividad($muchos)->assertStatus(422)->assertJsonStructure(['errores' => ['items']]);

        $justo = array_slice($muchos, 0, 50);
        $this->actividad($justo)->assertOk()->assertJsonPath('guardados', 50);

        $casos = [
            'items.0.summary'                    => ['summary' => str_repeat('a', 301)],
            'items.0.kind'                       => ['kind' => 'borrado'],
            'items.0.run_id'                     => ['run_id' => 'run/1'],
            'items.0.ok'                         => ['ok' => 'quizas'],
            'items.0.occurred_at'                => ['occurred_at' => 'ayer a la tarde'],
            'items.0.command'                    => ['command' => str_repeat('c', 41)],
            'items.0.detail'                     => ['detail' => ['texto' => str_repeat('x', 2100)]],
        ];
        foreach ($casos as $clave => $pisar) {
            $this->actividad([$this->item($ticket, $pisar)])->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos')->assertJsonStructure(['errores' => [$clave]]);
        }

        $sin_destino = $this->item($ticket);
        unset($sin_destino['support_ticket_id']);
        $this->actividad([$sin_destino])->assertStatus(422)->assertJsonStructure(['errores' => ['items.0.support_ticket_id', 'items.0.implementation_followup_id']]);

        $this->assertSame(300, mb_strlen(str_repeat('ñ', 300)));
        $this->actividad([$this->item($ticket, ['summary' => str_repeat('ñ', 300)])])->assertOk()->assertJsonPath('guardados', 1);
        $this->assertSame(51, SupportCodexActivity::where('support_ticket_id', $ticket->id)->count());
    }

    // ── plan ────────────────────────────────────────────────────────────────────────────────────

    /**
     * El plan: el primer PUT lo crea, el segundo lo reemplaza entero (una sola fila por run_id); sin pasos vale con
     * lista vacía; validación de topes, estados, ticket inexistente y run_id.
     *
     * @return void
     */
    public function test_plan_upsert_por_run_id(): void
    {
        $ticket = $this->ticket($this->cliente());
        $run    = 'a1b2c3d4-0000-4000-8000-' . Str::random(12);

        $this->plan($run, ['support_ticket_id' => $ticket->id, 'objetivo' => 'Arreglar el precio de la lista', 'pasos' => [
            ['texto' => 'Leer el contexto', 'estado' => 'hecho'],
            ['texto' => 'Consultar la base', 'estado' => 'en_curso'],
        ]])->assertOk()->assertExactJson(['ok' => true]);

        $this->plan($run, ['support_ticket_id' => $ticket->id, 'objetivo' => 'Arreglar el precio de la lista', 'pasos' => [
            ['texto' => 'Leer el contexto', 'estado' => 'hecho'],
            ['texto' => 'Consultar la base', 'estado' => 'hecho'],
            ['texto' => 'Escalar a Lucas', 'estado' => 'descartado'],
        ]])->assertOk();

        $planes = SupportCodexPlan::where('run_id', $run)->get();
        $this->assertCount(1, $planes);
        $this->assertSame((int) $ticket->id, $planes[0]->support_ticket_id);
        $this->assertSame(['hecho', 'hecho', 'descartado'], array_column($planes[0]->pasos, 'estado'));

        $this->plan($run, ['support_ticket_id' => $ticket->id, 'pasos' => []])->assertOk();
        $this->assertNull(SupportCodexPlan::where('run_id', $run)->value('objetivo'));
        $this->assertSame([], SupportCodexPlan::where('run_id', $run)->first()->pasos);

        $dieciseis = array_fill(0, 16, ['texto' => 'Paso', 'estado' => 'pendiente']);
        $this->plan($run, ['support_ticket_id' => $ticket->id, 'pasos' => $dieciseis])->assertStatus(422)->assertJsonStructure(['errores' => ['pasos']]);
        $this->plan($run, ['support_ticket_id' => $ticket->id, 'pasos' => [['texto' => str_repeat('p', 201), 'estado' => 'hecho']]])->assertStatus(422)->assertJsonStructure(['errores' => ['pasos.0.texto']]);
        $this->plan($run, ['support_ticket_id' => $ticket->id, 'pasos' => [['texto' => 'Paso', 'estado' => 'listo']]])->assertStatus(422)->assertJsonStructure(['errores' => ['pasos.0.estado']]);
        $this->plan($run, ['support_ticket_id' => $ticket->id, 'objetivo' => str_repeat('o', 301), 'pasos' => []])->assertStatus(422)->assertJsonStructure(['errores' => ['objetivo']]);
        $this->plan($run, ['support_ticket_id' => $ticket->id])->assertStatus(422)->assertJsonStructure(['errores' => ['pasos']]);
        $this->plan($run, ['pasos' => []])->assertStatus(422)->assertJsonStructure(['errores' => ['support_ticket_id']]);
        $this->plan($run, ['support_ticket_id' => 999999999, 'pasos' => []])->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos')->assertJsonStructure(['errores' => ['support_ticket_id']]);
        $this->putJson('/api/claude/support-codex/plans/' . $run, ['support_ticket_id' => $ticket->id, 'pasos' => []], ['Accept' => 'application/json'])->assertStatus(401);
        $this->plan(str_repeat('r', 65), ['support_ticket_id' => $ticket->id, 'pasos' => []])->assertStatus(404);

        $this->assertSame([], SupportCodexPlan::where('run_id', $run)->first()->pasos, 'Los rechazos no tocan el plan guardado.');

        $followup = $this->seguimiento($this->cliente('Seguido'));
        $this->plan('run-seg-' . Str::random(6), ['implementation_followup_id' => $followup->id, 'objetivo' => 'Seguimiento', 'pasos' => [['texto' => 'Escribirle', 'estado' => 'pendiente']]])->assertOk();
    }

    // ── panel ───────────────────────────────────────────────────────────────────────────────────

    /**
     * El panel: una atención por run_id, la más nueva primero, cada una con su plan (o sin plan), sus acciones en orden
     * de ocurrencia y su resumen (una escritura rechazada cuenta como error); un plan sin acciones también aparece;
     * las acciones de otro ticket no. Sin sesión 401; ticket inexistente 404; sin nada, la lista vacía.
     *
     * @return void
     */
    public function test_el_panel_agrupa_por_atencion(): void
    {
        $client = $this->cliente();
        $ticket = $this->ticket($client);
        $otro   = $this->ticket($client);
        $ruta   = '/api/admin/support-ticket/' . $ticket->id . '/martin-activity';

        $this->actividad([
            /* Atención vieja (ayer), en desorden a propósito. */
            $this->item($ticket, ['run_id' => 'vieja', 'command' => 'responder', 'kind' => 'envio', 'summary' => 'Respondió al cliente (acción responder)', 'detail' => ['estado' => 'sent'], 'occurred_at' => '2026-11-10T10:20:00-03:00']),
            $this->item($ticket, ['run_id' => 'vieja', 'command' => 'contexto', 'kind' => 'consulta', 'summary' => 'Leyó el contexto del ticket', 'detail' => null, 'occurred_at' => '2026-11-10T10:00:00-03:00']),
            /* Atención nueva (hoy). */
            $this->item($ticket, ['run_id' => 'nueva', 'command' => 'contexto', 'kind' => 'consulta', 'summary' => 'Leyó el contexto del ticket', 'detail' => null, 'occurred_at' => '2026-11-11T11:00:00-03:00']),
            $this->item($ticket, ['run_id' => 'nueva', 'occurred_at' => '2026-11-11T11:01:00-03:00']),
            $this->item($ticket, ['run_id' => 'nueva', 'command' => 'logs', 'summary' => 'Leyó el log de la API del sistema (300 líneas)', 'detail' => ['lineas' => 300], 'occurred_at' => '2026-11-11T11:02:00-03:00']),
            $this->item($ticket, ['run_id' => 'nueva', 'command' => 'ficha', 'kind' => 'registro', 'summary' => 'Ficha: agregó 1 entrada (decision)', 'detail' => null, 'occurred_at' => '2026-11-11T11:03:00-03:00']),
            $this->item($ticket, ['run_id' => 'nueva', 'command' => 'reparar-config', 'kind' => 'escritura', 'summary' => 'Reparar la variable MAIL_HOST: rechazado (sin_permiso)', 'ok' => false, 'detail' => null, 'occurred_at' => '2026-11-11T11:04:00-03:00']),
            $this->item($ticket, ['run_id' => 'nueva', 'command' => 'plan', 'kind' => 'plan', 'summary' => 'Declaró el plan (3 pasos)', 'detail' => null, 'occurred_at' => '2026-11-11T11:00:30-03:00']),
            /* Otro ticket: no aparece. */
            $this->item($otro, ['run_id' => 'ajena']),
        ])->assertOk()->assertJsonPath('guardados', 9);
        $this->plan('nueva', ['support_ticket_id' => $ticket->id, 'objetivo' => 'Ver por qué no sale la factura', 'pasos' => [
            ['texto' => 'Leer el contexto', 'estado' => 'hecho'],
            ['texto' => 'Mirar el log', 'estado' => 'hecho'],
            ['texto' => 'Reparar la config', 'estado' => 'descartado'],
        ]])->assertOk();
        /* Un plan sin acciones (la actividad no llegó todavía): aparece, ubicado por su fecha (ahora = el más nuevo). */
        $this->plan('solo-plan', ['support_ticket_id' => $ticket->id, 'objetivo' => 'Recién empieza', 'pasos' => [['texto' => 'Leer el contexto', 'estado' => 'en_curso']]])->assertOk();

        $this->getJson($ruta)->assertStatus(401);

        $admin     = $this->admin();
        $respuesta = $this->actingAs($admin, 'sanctum')->getJson($ruta)->assertOk();

        $this->assertSame(8, $respuesta->json('total_acciones'));
        $this->assertSame(['solo-plan', 'nueva', 'vieja'], array_column($respuesta->json('atenciones'), 'run_id'));

        $solo = $respuesta->json('atenciones.0');
        $this->assertSame([], $solo['acciones']);
        $this->assertSame('Recién empieza', $solo['objetivo']);
        $this->assertSame([['texto' => 'Leer el contexto', 'estado' => 'en_curso']], $solo['pasos']);
        $this->assertSame(['lecturas' => 0, 'escrituras' => 0, 'registros' => 0, 'envios' => 0, 'consultas' => 0, 'errores' => 0], $solo['resumen']);
        $this->assertSame('ticket', $solo['origen']);

        $nueva = $respuesta->json('atenciones.1');
        $this->assertSame('Ver por qué no sale la factura', $nueva['objetivo']);
        $this->assertSame(['hecho', 'hecho', 'descartado'], array_column($nueva['pasos'], 'estado'));
        $this->assertSame(['contexto', 'plan', 'sql', 'logs', 'ficha', 'reparar-config'], array_column($nueva['acciones'], 'command'));
        $this->assertSame('2026-11-11T11:00:00-03:00', $nueva['desde']);
        $this->assertSame('2026-11-11T11:04:00-03:00', $nueva['hasta']);
        $this->assertSame(['lecturas' => 2, 'escrituras' => 0, 'registros' => 1, 'envios' => 0, 'consultas' => 1, 'errores' => 1], $nueva['resumen']);
        $this->assertSame('ticket', $nueva['origen']);
        $this->assertSame(['kind', 'command', 'summary', 'detail', 'ok', 'occurred_at'], array_keys($nueva['acciones'][2]));
        $this->assertSame(['filas' => 5, 'truncado' => false], $nueva['acciones'][2]['detail']);
        $this->assertFalse($nueva['acciones'][5]['ok']);

        $vieja = $respuesta->json('atenciones.2');
        $this->assertNull($vieja['objetivo']);
        $this->assertSame([], $vieja['pasos']);
        $this->assertSame(['contexto', 'responder'], array_column($vieja['acciones'], 'command'));
        $this->assertSame(['lecturas' => 0, 'escrituras' => 0, 'registros' => 0, 'envios' => 1, 'consultas' => 1, 'errores' => 0], $vieja['resumen']);
        $this->assertSame(['estado' => 'sent'], $vieja['acciones'][1]['detail']);

        $this->assertSame(['run_id', 'origen', 'desde', 'hasta', 'objetivo', 'pasos', 'acciones', 'resumen'], array_keys($nueva));

        $vacio = $this->ticket($client);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $vacio->id . '/martin-activity')
            ->assertOk()->assertExactJson(['atenciones' => [], 'total_acciones' => 0]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/999999999/martin-activity')->assertStatus(404);
    }

    // ── contadores de la bandeja ────────────────────────────────────────────────────────────────

    /**
     * El listado y el show del ticket suman martin_activity_count (todas las acciones) y martin_writes_count (las
     * escrituras en el sistema del cliente que salieron bien; los registro no), sin una consulta aparte por ticket; un ticket sin actividad trae 0 y 0.
     *
     * @return void
     */
    public function test_contadores_en_el_ticket(): void
    {
        $client = $this->cliente();
        $con    = $this->ticket($client);
        $sin    = $this->ticket($client);

        $this->actividad([
            $this->item($con),
            $this->item($con, ['command' => 'ficha', 'kind' => 'registro', 'summary' => 'Ficha: agregó 1 entrada']),
            $this->item($con, ['command' => 'importar-excel', 'kind' => 'escritura', 'summary' => 'Importó la lista de precios (120 filas)']),
            $this->item($con, ['command' => 'reparar-config', 'kind' => 'escritura', 'summary' => 'Rechazado', 'ok' => false]),
        ])->assertOk()->assertJsonPath('guardados', 4);

        $admin = $this->admin();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $listado = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk();
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $por_id = collect($listado->json('models'))->keyBy('id');
        $this->assertSame(4, $por_id[$con->id]['martin_activity_count']);
        $this->assertSame(1, $por_id[$con->id]['martin_writes_count']);
        $this->assertSame(0, $por_id[$sin->id]['martin_activity_count']);
        $this->assertSame(0, $por_id[$sin->id]['martin_writes_count']);

        $aparte = array_filter($consultas, function ($consulta) {
            return stripos($consulta['query'], 'support_codex_activities') !== false
                && stripos($consulta['query'], 'support_tickets') === false;
        });
        $this->assertSame([], array_values($aparte), 'Los contadores van como subconsulta del listado, no como consulta aparte.');

        $show = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $con->id)->assertOk();
        $this->assertSame(4, $show->json('model.martin_activity_count'));
        $this->assertSame(1, $show->json('model.martin_writes_count'));
        $this->assertSame((int) $con->id, $show->json('model.id'));
        $this->assertNotNull($show->json('model.client_id'), 'El select sigue trayendo las columnas del ticket.');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $sin->id)->assertOk()
            ->assertJsonPath('model.martin_activity_count', 0)->assertJsonPath('model.martin_writes_count', 0);
    }

    /**
     * `registro` (lo interno del admin) se acepta, suma en `resumen.registros` y NO cuenta como escritura en
     * `martin_writes_count`; `escritura` queda para lo que se escribe en el sistema del cliente.
     *
     * @return void
     */
    public function test_kind_registro(): void
    {
        $ticket = $this->ticket($this->cliente());

        $this->actividad([
            $this->item($ticket, ['run_id' => 'reg', 'command' => 'ficha', 'kind' => 'registro', 'summary' => 'Ficha: agregó 2 entradas']),
            $this->item($ticket, ['run_id' => 'reg', 'command' => 'titular', 'kind' => 'registro', 'summary' => 'Tituló el ticket']),
            $this->item($ticket, ['run_id' => 'reg', 'command' => 'paso', 'kind' => 'registro', 'summary' => 'Preparó un paso', 'ok' => false]),
            $this->item($ticket, ['run_id' => 'reg', 'command' => 'importar-imagenes', 'kind' => 'escritura', 'summary' => 'Importó 30 imágenes']),
        ])->assertOk()->assertJsonPath('guardados', 4);
        $this->actividad([$this->item($ticket, ['kind' => 'interno'])])->assertStatus(422)->assertJsonStructure(['errores' => ['items.0.kind']]);

        $admin = $this->admin();
        $vista = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $ticket->id . '/martin-activity')->assertOk();
        $this->assertSame(['lecturas' => 0, 'escrituras' => 1, 'registros' => 2, 'envios' => 0, 'consultas' => 0, 'errores' => 1], $vista->json('atenciones.0.resumen'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $ticket->id)->assertOk()
            ->assertJsonPath('model.martin_activity_count', 4)
            ->assertJsonPath('model.martin_writes_count', 1);
    }

    /**
     * Una atención del seguimiento proactivo cuyo envío cayó en el ticket aparece ENTERA en el panel del ticket (con
     * las acciones que solo traen implementation_followup_id y su plan), marcada `origen: "seguimiento"`; los contadores
     * del ticket cuentan lo mismo que `total_acciones`. Otra atención del mismo seguimiento que no tocó el ticket no
     * aparece.
     *
     * @return void
     */
    public function test_la_atencion_del_seguimiento_aparece_entera_en_el_ticket(): void
    {
        $client   = $this->cliente();
        $ticket   = $this->ticket($client);
        $followup = $this->seguimiento($client);
        $base     = ['implementation_followup_id' => $followup->id, 'run_id' => 'seg-1', 'detail' => null, 'ok' => true];

        $this->actividad([
            $this->item($ticket, ['run_id' => 'tic-1', 'occurred_at' => '2026-11-11T09:00:00-03:00']),
            array_merge($base, ['kind' => 'consulta', 'command' => 'seguimiento-contexto', 'summary' => 'Leyó el contexto del seguimiento', 'occurred_at' => '2026-11-11T10:00:00-03:00']),
            array_merge($base, ['kind' => 'registro', 'command' => 'nota-implementacion', 'summary' => 'Dejó una nota en la implementación', 'occurred_at' => '2026-11-11T10:01:00-03:00']),
            array_merge($base, ['support_ticket_id' => $ticket->id, 'kind' => 'envio', 'command' => 'seguimiento-enviar', 'summary' => 'Le escribió al cliente (estado sent)', 'occurred_at' => '2026-11-11T10:02:00-03:00']),
            /* Otra atención del seguimiento, que no tocó el ticket. */
            array_merge($base, ['run_id' => 'seg-2', 'kind' => 'consulta', 'command' => 'seguimiento-contexto', 'summary' => 'Leyó el contexto del seguimiento', 'occurred_at' => '2026-11-11T11:00:00-03:00']),
        ])->assertOk()->assertJsonPath('guardados', 5);
        $this->plan('seg-1', ['implementation_followup_id' => $followup->id, 'objetivo' => 'Retomar la carga de artículos', 'pasos' => [['texto' => 'Escribirle', 'estado' => 'hecho']]])->assertOk();

        $admin = $this->admin();
        $vista = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $ticket->id . '/martin-activity')->assertOk();

        $this->assertSame(['seg-1', 'tic-1'], array_column($vista->json('atenciones'), 'run_id'));
        $this->assertSame(['seguimiento', 'ticket'], array_column($vista->json('atenciones'), 'origen'));
        $this->assertSame(['seguimiento-contexto', 'nota-implementacion', 'seguimiento-enviar'], array_column($vista->json('atenciones.0.acciones'), 'command'));
        $this->assertSame('Retomar la carga de artículos', $vista->json('atenciones.0.objetivo'));
        $this->assertSame(['lecturas' => 0, 'escrituras' => 0, 'registros' => 1, 'envios' => 1, 'consultas' => 1, 'errores' => 0], $vista->json('atenciones.0.resumen'));
        $this->assertSame(4, $vista->json('total_acciones'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $ticket->id)->assertOk()
            ->assertJsonPath('model.martin_activity_count', 4)
            ->assertJsonPath('model.martin_writes_count', 0);
        $fila = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk()->json('models'))->firstWhere('id', $ticket->id);
        $this->assertSame(4, $fila['martin_activity_count']);
    }

    /**
     * Defensa en profundidad: corridas de 15 a 22 dígitos (con o sin espacios o guiones) quedan como `[número oculto]`
     * en el summary, en los textos del detail (a cualquier profundidad) y en el plan; 14 dígitos o un número que no es
     * texto, no. Dos summaries que solo difieren en el número oculto son la misma acción.
     *
     * @return void
     */
    public function test_enmascara_los_numeros_largos(): void
    {
        $ticket = $this->ticket($this->cliente());

        $this->actividad([$this->item($ticket, [
            'run_id' => 'mask', 'summary' => 'Consultó la base: SELECT * FROM cards WHERE n = 4509 9535 6623 3704 OR cbu = \'0170099220000067797370\' OR ean = 77912345678901',
            'detail' => ['filas' => 1234567890123456, 'nota' => 'tarjeta 4509-9535-6623-3704', 'dentro' => ['otra' => 'cbu 0170099220000067797370']],
        ])])->assertOk()->assertJsonPath('guardados', 1);

        $fila = SupportCodexActivity::where('run_id', 'mask')->first();
        $this->assertSame('Consultó la base: SELECT * FROM cards WHERE n = [número oculto] OR cbu = \'[número oculto]\' OR ean = 77912345678901', $fila->summary);
        /* assertEquals: la columna json de MySQL reordena las claves. */
        $this->assertEquals(['filas' => 1234567890123456, 'nota' => 'tarjeta [número oculto]', 'dentro' => ['otra' => 'cbu [número oculto]']], $fila->detail);

        $this->actividad([$this->item($ticket, [
            'run_id' => 'mask', 'summary' => 'Consultó la base: SELECT * FROM cards WHERE n = 5555 5555 5555 4444 OR cbu = \'0000000000000000000000\' OR ean = 77912345678901',
        ])])->assertOk()->assertExactJson(['guardados' => 0, 'repetidos' => 1]);

        $this->plan('mask', ['support_ticket_id' => $ticket->id, 'objetivo' => 'Ver el pago de la tarjeta 4509953566233704', 'pasos' => [['texto' => 'Buscar el CBU 0170099220000067797370', 'estado' => 'hecho']]])->assertOk();
        $plan = SupportCodexPlan::where('run_id', 'mask')->first();
        $this->assertSame('Ver el pago de la tarjeta [número oculto]', $plan->objetivo);
        $this->assertSame('Buscar el CBU [número oculto]', $plan->pasos[0]['texto']);
    }

    // ── catálogo ────────────────────────────────────────────────────────────────────────────────

    /**
     * Las dos rutas del monitor están en el catálogo como escrituras con sus frenos y parámetros.
     *
     * @return void
     */
    public function test_el_catalogo_declara_las_rutas_del_monitor(): void
    {
        $respuesta = $this->getJson('/api/claude/catalog?seccion=endpoints', $this->headers)->assertOk();
        $rutas     = collect($respuesta->json('endpoints'))->keyBy(function ($endpoint) {
            return $endpoint['metodo'] . ' ' . $endpoint['ruta'];
        });

        foreach (['POST claude/support-codex/activity', 'PUT claude/support-codex/plans/{run_id}'] as $clave) {
            $this->assertTrue($rutas->has($clave), 'Falta ' . $clave . ' en el catálogo.');
            $this->assertTrue((bool) $rutas[$clave]['escribe']);
            $this->assertNotEmpty($rutas[$clave]['frenos']);
            $this->assertNotEmpty($rutas[$clave]['parametros']);
        }
    }
}
