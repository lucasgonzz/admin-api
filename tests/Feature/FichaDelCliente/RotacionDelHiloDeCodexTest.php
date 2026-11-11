<?php

namespace Tests\Feature\FichaDelCliente;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rotación del hilo de Codex de un ticket (misión cruzada `ficha-del-cliente`, 11/11/2026): cuando la conversación
 * de Martín pasa los ~380.000 tokens, el monitor abre una nueva y la vincula con `claim` + `rotate_thread` + el
 * `thread_id` nuevo, con la reserva vigente de la misma ejecución. Sin el flag, otro hilo sigue siendo 409
 * `thread_conflict`. El contexto anuncia `capabilities.thread_rotation`.
 */
class RotacionDelHiloDeCodexTest extends TestCase
{
    use DatabaseTransactions;
    use AndamiajeDeLaFicha;

    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'rotacion-hilo-test', 'Accept' => 'application/json'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'rotacion-hilo-test']);
        Carbon::setTestNow(Carbon::parse('2026-11-11 13:00:00'));
        Http::fake();
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-11 12:00:00');
        AdminSetting::flush_memo();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        AdminSetting::flush_memo();
        parent::tearDown();
    }

    /**
     * POST .../claim.
     *
     * @param SupportTicket        $ticket El ticket.
     * @param array<string, mixed> $extra  Campos a sumar.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function claim(SupportTicket $ticket, array $extra = [])
    {
        $ultimo = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();

        return $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/claim', array_merge([
            'worker_id' => 'pc-lucas', 'expected_message_id' => $ultimo->id,
        ], $extra), $this->headers);
    }

    /**
     * Un ticket con un entrante, ya tomado por una ejecución con su hilo: [ticket, token, execution_id].
     *
     * @return array
     */
    private function tomado(): array
    {
        $ticket = $this->ticket($this->cliente());
        $this->mensaje($ticket, 'No puedo vender.');
        $ejecucion = (string) Str::uuid();

        $toma = $this->claim($ticket, ['execution_id' => $ejecucion])->assertOk();
        $token = (string) $toma->json('lease_token');
        $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'thread_id' => 'hilo-viejo'])->assertOk();
        $this->assertSame('hilo-viejo', $this->caso($ticket)->thread_id);

        return [$ticket, $token, $ejecucion];
    }

    /**
     * @param SupportTicket $ticket El ticket.
     *
     * @return SupportCodexCase
     */
    private function caso(SupportTicket $ticket): SupportCodexCase
    {
        return SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
    }

    /**
     * Con la reserva vigente de la misma ejecución, rota: el hilo nuevo queda en el caso y el viejo en
     * previous_thread_id con la hora. Repetir con el mismo hilo no cambia nada. El contexto anuncia la capacidad.
     *
     * @return void
     */
    public function test_rota_con_la_reserva_vigente_de_la_misma_ejecucion(): void
    {
        [$ticket, $token, $ejecucion] = $this->tomado();

        $rotado = $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'thread_id' => 'hilo-nuevo', 'rotate_thread' => true])->assertOk();
        $this->assertSame($token, $rotado->json('lease_token'));
        $this->assertSame('hilo-nuevo', $rotado->json('ticket.thread_id'));
        $this->assertTrue($rotado->json('ticket.capabilities.thread_rotation'));

        $caso = $this->caso($ticket);
        $this->assertSame('hilo-nuevo', $caso->thread_id);
        $this->assertSame('hilo-viejo', $caso->previous_thread_id);
        $this->assertNotNull($caso->thread_rotated_at);

        /* Reintento con el mismo hilo: nada cambia. */
        $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'thread_id' => 'hilo-nuevo', 'rotate_thread' => true])->assertOk();
        $this->assertSame('hilo-viejo', $this->caso($ticket)->previous_thread_id);

        /* Y después de rotar, el hilo nuevo es el vinculado: otro sin el flag es 409. */
        $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'thread_id' => 'hilo-tercero'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'thread_conflict: El ticket ya está vinculado a otra conversación de Codex.']);

        $this->assertTrue($this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers)->assertOk()->json('ticket.capabilities.thread_rotation'));
    }

    /**
     * Sin rotate_thread el comportamiento es el de siempre: otro hilo sobre un caso vinculado es 409
     * thread_conflict y el hilo no cambia.
     *
     * @return void
     */
    public function test_sin_el_flag_sigue_el_409(): void
    {
        [$ticket, $token, $ejecucion] = $this->tomado();

        $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'thread_id' => 'hilo-nuevo'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'thread_conflict: El ticket ya está vinculado a otra conversación de Codex.']);
        $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'thread_id' => 'hilo-nuevo', 'rotate_thread' => false])
            ->assertStatus(409);

        $caso = $this->caso($ticket);
        $this->assertSame('hilo-viejo', $caso->thread_id);
        $this->assertNull($caso->previous_thread_id);
    }

    /**
     * Rechazos: sin token o con otro (423 invalid_lease), con la reserva vencida (423), otro worker (423), otra
     * ejecución o sin execution_id (409 operation_conflict), sin thread_id (422). En ninguno cambia el hilo.
     *
     * @return void
     */
    public function test_rechaza_sin_reserva_o_con_otra_ejecucion(): void
    {
        [$ticket, $token, $ejecucion] = $this->tomado();
        $rotar = ['thread_id' => 'hilo-nuevo', 'rotate_thread' => true];

        $this->claim($ticket, array_merge($rotar, ['execution_id' => $ejecucion]))->assertStatus(423);
        $this->claim($ticket, array_merge($rotar, ['lease_token' => Str::random(64), 'execution_id' => $ejecucion]))->assertStatus(423);
        $this->claim($ticket, array_merge($rotar, ['lease_token' => $token, 'execution_id' => $ejecucion, 'worker_id' => 'otra-pc']))->assertStatus(423);
        $this->claim($ticket, array_merge($rotar, ['lease_token' => $token, 'execution_id' => (string) Str::uuid()]))
            ->assertStatus(409)->assertJsonFragment(['message' => 'operation_conflict: La renovación pertenece a otra ejecución.']);
        $this->claim($ticket, array_merge($rotar, ['lease_token' => $token]))
            ->assertStatus(409)->assertJsonFragment(['message' => 'operation_conflict: La rotación del hilo pertenece a otra ejecución.']);
        $this->claim($ticket, ['lease_token' => $token, 'execution_id' => $ejecucion, 'rotate_thread' => true])
            ->assertStatus(422)->assertJsonFragment(['message' => 'thread_required: Para rotar el hilo hace falta el thread_id de la conversación nueva.']);
        $this->claim($ticket, array_merge($rotar, ['lease_token' => $token, 'execution_id' => $ejecucion, 'rotate_thread' => 'tal vez']))->assertStatus(422);

        SupportCodexCase::where('support_ticket_id', $ticket->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->claim($ticket, array_merge($rotar, ['lease_token' => $token, 'execution_id' => $ejecucion]))
            ->assertStatus(423)->assertJsonFragment(['message' => 'invalid_lease: Rotar el hilo exige la reserva vigente de esta misma atención.']);

        $caso = $this->caso($ticket);
        $this->assertSame('hilo-viejo', $caso->thread_id);
        $this->assertNull($caso->previous_thread_id);
    }
}
