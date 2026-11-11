<?php

namespace Tests\Feature\ImplementationFollowups;

use App\Models\AdminSetting;
use App\Models\ImplementationFollowup;
use App\Models\ImplementationFollowupEvent;
use App\Models\ImplementationStage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ImplementationFollowups\ImplementationFollowupService;
use App\Services\Prueba\PruebaCicloService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Los endpoints que consume el monitor de Martín (`claude/support-codex/implementation-followups/*` y
 * `support-codex/tickets/{id}/implementation-followup`), por las rutas reales: pending, claim, milestones, send,
 * postpone, release, la pausa desde un ticket y la clave `seguimiento_implementacion` del contexto del ticket.
 *
 * El envío real pasa por `ImplementationSupportWhatsappService` y `SupportWhatsappOpenerService`: lo único
 * sustituido es `WhatsappSendService` (Kapso/Meta).
 */
class MonitorDelSeguimientoDeImplementacionesTest extends BaseDeSeguimientos
{
    const BASE = '/api/claude/support-codex/implementation-followups/';

    /**
     * Un seguimiento vencido y reclamado: [client, implementation, followup, token, respuesta del claim].
     *
     * @return array
     */
    private function reclamado(): array
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $claim    = $this->claim($followup)->assertOk();

        return [$client, $implementation, $followup, (string) $claim->json('lease_token'), $claim];
    }

    /**
     * POST .../send.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     * @param string                 $token    La reserva.
     * @param array<string, mixed>   $extra    Para pisar.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function send(ImplementationFollowup $followup, string $token, array $extra = [])
    {
        return $this->postJson(self::BASE . $followup->id . '/send', array_merge([
            'lease_token'  => $token,
            'operation_id' => (string) Str::uuid(),
            'body'         => 'Hola Ana, ¿pudiste hacer tu primera venta? Te dejo el video de cómo se hace y si querés la hacemos juntos.',
        ], $extra), $this->headers());
    }

    // ── pending ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Lista solo los vencidos y activos, dentro del horario; fuera de horario la lista viene vacía con
     * `en_horario: false`.
     *
     * @return void
     */
    public function test_pending_solo_vencidos_y_en_horario(): void
    {
        [$client, $implementation] = $this->escenario();
        $vencido = $this->seguimiento($client, $implementation);

        [$otro, $otra_impl] = $this->escenario();
        $this->seguimiento($otro, $otra_impl, ['next_due_at' => now()->addDay()]);

        [$tercero, $tercera] = $this->escenario();
        $this->seguimiento($tercero, $tercera, ['status' => 'pausado', 'paused_until' => now()->addDays(3)]);

        [$cuarto, $cuarta] = $this->escenario();
        $this->seguimiento($cuarto, $cuarta, ['unanswered_count' => 2]);

        $respuesta = $this->getJson(self::BASE . 'pending', $this->headers())->assertOk();

        $this->assertTrue($respuesta->json('enabled'));
        $this->assertTrue($respuesta->json('en_horario'));
        $ids = array_column($respuesta->json('followups'), 'id');
        $this->assertSame([(int) $vencido->id], $ids);

        $item = $respuesta->json('followups.0');
        $this->assertSame((int) $client->id, $item['client_id']);
        $this->assertSame((int) $implementation->id, $item['implementation_id']);
        $this->assertSame('implementation_followup', $item['reason']);
        $this->assertNotEmpty($item['client_name']);
        $this->assertNotEmpty($respuesta->json('server_time'));

        /* A las 18:30 ya no. */
        Carbon::setTestNow(Carbon::parse('2026-11-11 18:30:00', 'America/Argentina/Buenos_Aires'));
        $fuera = $this->getJson(self::BASE . 'pending', $this->headers())->assertOk();
        $this->assertFalse($fuera->json('en_horario'));
        $this->assertSame([], $fuera->json('followups'));
    }

    /**
     * Excluye al cliente con un ticket que Martín tiene reservado o con un entrante esperándolo, y al que está en
     * prueba; y el kill switch devuelve `enabled: false` sin nada.
     *
     * @return void
     */
    public function test_pending_excluye_martin_con_el_cliente_prueba_y_kill_switch(): void
    {
        [$con_ticket, $impl1] = $this->escenario();
        $this->seguimiento($con_ticket, $impl1, ['next_due_at' => now()->subDays(2)]);
        $ticket = $this->el_cliente_escribe($con_ticket, now()->subDays(3), 'open');
        $this->reservar_el_ticket($ticket);

        [$en_prueba, $impl2] = $this->escenario(6, ['estado' => PruebaCicloService::CLIENTE_PRUEBA]);
        $this->seguimiento($en_prueba, $impl2);

        [$libre, $impl3] = $this->escenario();
        $ofrecido = $this->seguimiento($libre, $impl3);

        $ids = array_column($this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'), 'id');
        $this->assertSame([(int) $ofrecido->id], $ids);

        AdminSetting::set(ImplementationFollowupService::SETTING_ENABLED, '0');
        AdminSetting::flush_memo();

        $apagado = $this->getJson(self::BASE . 'pending', $this->headers())->assertOk();
        $this->assertFalse($apagado->json('enabled'));
        $this->assertSame([], $apagado->json('followups'));
    }

    /**
     * Si el cliente escribió desde la última corrida, pending ya no lo ofrece (aplica la regla del contacto).
     *
     * @return void
     */
    public function test_pending_aplica_el_contacto_reciente(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['last_client_contact_at' => now()->subDays(5)]);
        $this->el_cliente_escribe($client, now()->subMinutes(20));

        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));
        $this->assertSame('2026-11-13 10:00', $followup->refresh()->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
    }

    // ── claim ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Reserva nueva con su contexto; un segundo claim sin token es 409 reservado; con el token renueva (mismo token
     * y run_id); con otro token, 409 reservado.
     *
     * @return void
     */
    public function test_claim_reserva_contexto_409_y_renovacion(): void
    {
        [$client, $implementation, $followup, $token, $claim] = $this->reclamado();

        $this->assertSame(64, strlen($token));
        $this->assertTrue(Str::isUuid((string) $claim->json('run_id')));
        $this->assertNotEmpty($claim->json('lease_expires_at'));

        $contexto = $claim->json('context');
        $this->assertSame((int) $followup->id, $contexto['seguimiento']['id']);
        $this->assertSame('activo', $contexto['seguimiento']['status']);
        $this->assertSame(2, $contexto['seguimiento']['intervalo_dias_habiles']);
        $this->assertSame(2, $contexto['seguimiento']['max_sin_respuesta']);
        $this->assertSame(0, $contexto['seguimiento']['sin_respuesta']);
        $this->assertArrayHasKey('eventos', $contexto['seguimiento']);
        $this->assertSame(ImplementationFollowupService::HITOS_OBLIGATORIOS, $contexto['seguimiento']['obligatorios_pendientes']);
        $this->assertSame((int) $client->id, $contexto['client']['id']);
        $this->assertSame((int) $client->user_id, $contexto['client']['user_id']);
        $this->assertSame($client->slug, $contexto['client']['slug']);
        $this->assertSame((int) $implementation->id, $contexto['implementation']['sistema']['id']);
        $this->assertNull($contexto['implementation']['sistema']['form_link'], 'form_link nunca viaja en el seguimiento');
        $this->assertArrayHasKey('ventana', $contexto['contacto']);
        $this->assertFalse($contexto['contacto']['ventana']['open']);
        $this->assertNotNull($contexto['contacto']['nombre']);
        $this->assertSame([], $contexto['historial']);
        $this->assertFalse($contexto['martin_ocupado']);

        $this->claim($followup)->assertStatus(409)->assertJson(['error' => 'reservado']);

        $antes = $followup->refresh()->lease_expires_at;
        Carbon::setTestNow(now()->addMinutes(5));
        $renovado = $this->claim($followup, $token)->assertOk();
        $this->assertSame($token, $renovado->json('lease_token'));
        $this->assertSame($claim->json('run_id'), $renovado->json('run_id'));
        $this->assertTrue($followup->refresh()->lease_expires_at->greaterThan($antes));

        $this->claim($followup, Str::random(64))->assertStatus(409)->assertJson(['error' => 'reservado']);
    }

    /**
     * El historial trae los últimos tickets del cliente con sus mensajes (la forma de GET .../history).
     *
     * @return void
     */
    public function test_claim_trae_el_historial_de_todos_los_tickets(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['last_client_contact_at' => now()->subDays(4)]);
        $viejo = $this->el_cliente_escribe($client, now()->subDays(4), 'closed', 'Esta semana estoy complicado');

        $contexto = $this->claim($followup)->assertOk()->json('context');

        $this->assertCount(1, $contexto['historial']);
        $this->assertSame((int) $viejo->id, $contexto['historial'][0]['id']);
        $this->assertSame('Esta semana estoy complicado', $contexto['historial'][0]['messages'][0]['body']);
        $this->assertSame('user', $contexto['historial'][0]['messages'][0]['sender_type']);
    }

    /**
     * 409 no_vencido: no venció, o el cliente acaba de escribir (la regla del contacto corre el próximo).
     *
     * @return void
     */
    public function test_claim_no_vencido(): void
    {
        [$client, $implementation] = $this->escenario();
        $futuro = $this->seguimiento($client, $implementation, ['next_due_at' => now()->addDay()]);
        $this->claim($futuro)->assertStatus(409)->assertJson(['error' => 'no_vencido']);

        [$otro, $otra] = $this->escenario();
        $escribio = $this->seguimiento($otro, $otra, ['last_client_contact_at' => now()->subDays(5)]);
        $this->el_cliente_escribe($otro, now()->subMinutes(5));
        $this->claim($escribio)->assertStatus(409)->assertJson(['error' => 'no_vencido']);
        $this->assertTrue($escribio->refresh()->next_due_at->isFuture(), 'el contacto quedó guardado aunque el claim se rechazó');
    }

    // ── milestones ───────────────────────────────────────────────────────────────────────────────

    /**
     * Guarda los hitos, devuelve los nuevos y los obligatorios pendientes; evento `hitos` solo si cambió algo.
     *
     * @return void
     */
    public function test_milestones_nuevos_y_obligatorios_pendientes(): void
    {
        [, , $followup, $token] = $this->reclamado();

        $hitos = [
            'ingreso'              => ['cantidad' => 0, 'primero_at' => null, 'ultimo_at' => '2026-11-10T09:30:00-03:00'],
            'articulos_importados' => ['cantidad' => 702, 'primero_at' => '2026-11-09T10:00:00-03:00', 'ultimo_at' => '2026-11-09T10:00:00-03:00'],
            'venta'                => ['cantidad' => 0, 'primero_at' => null, 'ultimo_at' => null],
            'clave_nueva'          => ['cantidad' => 3, 'primero_at' => null, 'ultimo_at' => null],
        ];

        $primera = $this->postJson(self::BASE . $followup->id . '/milestones', [
            'lease_token' => $token, 'medido_at' => '2026-11-11T11:00:00-03:00', 'hitos' => $hitos,
        ], $this->headers())->assertOk();

        $this->assertTrue($primera->json('guardado'));
        $this->assertSame(['articulos_importados', 'clave_nueva'], $primera->json('nuevos'));
        $this->assertSame(ImplementationFollowupService::HITOS_OBLIGATORIOS, $primera->json('obligatorios_pendientes'));
        $this->assertSame(702, $followup->refresh()->hitos['articulos_importados']['cantidad']);
        $this->assertSame(3, $followup->hitos['clave_nueva']['cantidad'], 'las claves desconocidas se guardan');
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_HITOS));

        /* La misma medición otra vez: sin evento nuevo. */
        $this->postJson(self::BASE . $followup->id . '/milestones', [
            'lease_token' => $token, 'medido_at' => '2026-11-11T11:05:00-03:00', 'hitos' => $hitos,
        ], $this->headers())->assertOk()->assertJson(['nuevos' => []]);
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_HITOS));

        /* La primera venta: nueva, y sale de los obligatorios pendientes. */
        $hitos['venta'] = ['cantidad' => 1, 'primero_at' => '2026-11-11T10:40:00-03:00', 'ultimo_at' => '2026-11-11T10:40:00-03:00'];
        $tercera = $this->postJson(self::BASE . $followup->id . '/milestones', [
            'lease_token' => $token, 'medido_at' => '2026-11-11T11:10:00-03:00', 'hitos' => $hitos,
        ], $this->headers())->assertOk();
        $this->assertSame(['venta'], $tercera->json('nuevos'));
        $this->assertNotContains('venta', $tercera->json('obligatorios_pendientes'));
        $this->assertCount(2, $this->eventos($followup, ImplementationFollowupEvent::KIND_HITOS));
        $this->assertSame(['venta'], $this->eventos($followup, ImplementationFollowupEvent::KIND_HITOS)->last()->data['nuevos']);

        /* Sin la reserva: 409 reservado. */
        $this->postJson(self::BASE . $followup->id . '/milestones', [
            'lease_token' => Str::random(64), 'medido_at' => '2026-11-11T11:10:00-03:00', 'hitos' => $hitos,
        ], $this->headers())->assertStatus(409)->assertJson(['error' => 'reservado']);
    }

    // ── send ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * El camino feliz con la ventana cerrada: sale por plantilla con «Martín, asistente técnico» como operador, por
     * el ticket del contacto; suma uno sin respuesta, corre el próximo +2 hábiles, evento `enviado` con el texto,
     * acción `seguimiento` en la etapa y la reserva suelta. Ningún Admin real cambia de nombre.
     *
     * @return void
     */
    public function test_send_sent_con_plantilla(): void
    {
        [$client, $implementation, $followup, $token] = $this->reclamado();
        $nombre_del_admin = $this->admin->name;
        $operacion = (string) Str::uuid();

        $respuesta = $this->send($followup, $token, ['operation_id' => $operacion])->assertOk();

        $this->assertSame('sent', $respuesta->json('status'));
        $this->assertTrue($respuesta->json('used_template'));
        $this->assertFalse($respuesta->json('repetida'));
        $ticket_id = $respuesta->json('ticket_id');
        $this->assertNotNull($ticket_id);
        $this->assertNotNull($respuesta->json('message_id'));

        $this->assertCount(1, $this->espia->plantillas);
        $this->assertCount(0, $this->espia->textos);
        $this->assertSame('Martín, asistente técnico', $this->espia->plantillas[0]['variables'][1]);
        $this->assertSame($nombre_del_admin, $this->admin->refresh()->name, 'el Admin real no se renombra');

        $ticket = SupportTicket::find($ticket_id);
        $this->assertSame((int) $client->id, (int) $ticket->client_id);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket_id)->where('sender_type', 'admin')->count());

        $followup->refresh();
        $this->assertSame(1, (int) $followup->unanswered_count);
        $this->assertNotNull($followup->last_sent_at);
        $this->assertSame('2026-11-13 10:00', $followup->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $this->assertNull($followup->lease_token);
        $this->assertNull($followup->lease_expires_at);

        $enviado = $this->eventos($followup, ImplementationFollowupEvent::KIND_ENVIADO);
        $this->assertCount(1, $enviado);
        $this->assertSame((int) $ticket_id, (int) $enviado->first()->support_ticket_id);
        $this->assertStringContainsString('primera venta', $enviado->first()->detail);
        $this->assertSame('martin', $enviado->first()->source);

        $etapa   = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 6)->first();
        $accion  = collect($etapa->data['actions'])->firstWhere('action', 'seguimiento');
        $this->assertNotNull($accion, 'la acción seguimiento quedó en la etapa actual');
        $this->assertSame('whatsapp', $accion['canal']);
        $this->assertSame($operacion, $accion['operation_id']);

        /* Idempotente: la misma operation_id devuelve lo guardado sin mandar de nuevo (aunque la reserva ya se soltó). */
        $repetida = $this->send($followup, $token, ['operation_id' => $operacion])->assertOk();
        $this->assertSame('sent', $repetida->json('status'));
        $this->assertTrue($repetida->json('repetida'));
        $this->assertSame($ticket_id, $repetida->json('ticket_id'));
        $this->assertCount(1, $this->espia->plantillas);
        $this->assertSame(1, (int) $followup->refresh()->unanswered_count);

        /* Otra operation_id sin reserva: 409 reservado. */
        $this->send($followup, $token)->assertStatus(409)->assertJson(['error' => 'reservado']);
    }

    /**
     * Con la ventana abierta sale como texto libre.
     *
     * @return void
     */
    public function test_send_con_la_ventana_abierta_va_como_texto_libre(): void
    {
        [$client, $implementation] = $this->escenario();
        /* Un entrante de hace 3 horas en un ticket cerrado: abre la ventana; el contacto ya está visto. */
        $this->el_cliente_escribe($client, now()->subHours(3));
        $followup = $this->seguimiento($client, $implementation, ['last_client_contact_at' => now()->subHours(3)]);
        $token = (string) $this->claim($followup)->assertOk()->json('lease_token');

        $respuesta = $this->send($followup, $token)->assertOk();

        $this->assertSame('sent', $respuesta->json('status'));
        $this->assertFalse($respuesta->json('used_template'));
        $this->assertCount(1, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
    }

    /**
     * Un envío que Meta rechaza: `failed`, evento `fallido`, no suma sin respuesta, suelta la reserva y no se
     * reofrece en la misma hora.
     *
     * @return void
     */
    public function test_send_failed(): void
    {
        [, , $followup, $token] = $this->reclamado();
        $this->espia->confirma = false;

        $respuesta = $this->send($followup, $token)->assertOk();

        $this->assertSame('failed', $respuesta->json('status'));
        $followup->refresh();
        $this->assertSame(0, (int) $followup->unanswered_count);
        $this->assertNull($followup->lease_token);
        $this->assertTrue($followup->next_due_at->greaterThanOrEqualTo(now()->addMinutes(59)));
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_FALLIDO));
        $this->assertCount(0, $this->eventos($followup, ImplementationFollowupEvent::KIND_ENVIADO));
    }

    /**
     * 409 cliente_escribio: el cliente escribió después del claim. No se manda nada, se suelta la reserva y se
     * reinicia el reloj.
     *
     * @return void
     */
    public function test_send_cliente_escribio(): void
    {
        [$client, , $followup, $token] = $this->reclamado();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->el_cliente_escribe($client, now()->subSeconds(30));

        $this->send($followup, $token)->assertStatus(409)->assertJson(['error' => 'cliente_escribio', 'motivo' => 'cliente_escribio']);

        $this->assertCount(0, $this->espia->plantillas);
        $this->assertCount(0, $this->espia->textos);
        $followup->refresh();
        $this->assertNull($followup->lease_token);
        $this->assertTrue($followup->next_due_at->isFuture());
    }

    /**
     * 423 martin_ocupado: el ticket destino tiene una reserva de Martín.
     *
     * @return void
     */
    public function test_send_martin_ocupado(): void
    {
        [$client, , $followup, $token] = $this->reclamado();

        /* El ticket abierto del contacto (el que reusaría el opener), sin entrante pendiente, con reserva de Martín. */
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => $client->phone, 'opened_at' => now()->subDays(10),
        ]);
        $this->reservar_el_ticket($ticket);

        $this->send($followup, $token)->assertStatus(423)->assertJson(['error' => 'martin_ocupado']);
        $this->assertCount(0, $this->espia->plantillas);
    }

    /**
     * 422 de validación: largo de más de 550, vacío, operation_id que no es uuid.
     *
     * @return void
     */
    public function test_send_422_validacion(): void
    {
        [, , $followup, $token] = $this->reclamado();

        $this->send($followup, $token, ['body' => str_repeat('a', 551)])->assertStatus(422);
        $this->send($followup, $token, ['body' => '   '])->assertStatus(422);
        $this->send($followup, $token, ['operation_id' => 'no-es-uuid'])->assertStatus(422);

        $this->assertCount(0, $this->espia->plantillas);
        $this->assertNotNull($followup->refresh()->lease_token, 'la reserva sigue');
    }

    /**
     * Con la implementación `completed` (puesta en marcha) se registra en la última etapa.
     *
     * @return void
     */
    public function test_send_con_la_implementacion_cerrada_registra_en_la_ultima_etapa(): void
    {
        [$client, $implementation] = $this->escenario(7);
        $implementation->status = 'completed';
        $implementation->completed_at = now()->subDay();
        $implementation->save();
        $followup = $this->seguimiento($client, $implementation);
        $token = (string) $this->claim($followup)->assertOk()->json('lease_token');

        $this->send($followup, $token)->assertOk()->assertJson(['status' => 'sent']);

        $etapa = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 7)->first();
        $this->assertNotNull(collect($etapa->data['actions'] ?? [])->firstWhere('action', 'seguimiento'));
    }

    // ── postpone y release ───────────────────────────────────────────────────────────────────────

    /**
     * pausa: pausado hasta la fecha, próximo el primer hábil después, evento, reserva suelta y no_aplican sumados.
     *
     * @return void
     */
    public function test_postpone_pausa_con_no_aplican(): void
    {
        [, , $followup, $token] = $this->reclamado();

        $respuesta = $this->postJson(self::BASE . $followup->id . '/postpone', [
            'lease_token' => $token, 'tipo' => 'pausa', 'hasta' => '2026-11-20',
            'motivo' => 'Dijo que esta semana no puede, que lo retomamos después del 20.',
            'no_aplican' => ['venta_cc', 'pago_cc'],
        ], $this->headers())->assertOk();

        $this->assertTrue($respuesta->json('ok'));
        $this->assertSame('pausado', $respuesta->json('seguimiento.status'));
        $this->assertSame('2026-11-20', $respuesta->json('seguimiento.pausado_hasta'));
        $this->assertSame(['venta_cc', 'pago_cc'], $respuesta->json('seguimiento.hitos_no_aplican'));

        $followup->refresh();
        $this->assertSame('2026-11-23 10:00', $followup->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $this->assertStringContainsString('esta semana no puede', $followup->pause_reason);
        $this->assertNull($followup->lease_token);
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_PAUSADO));
        $this->assertCount(0, $this->avisos());
    }

    /**
     * omitir: +2 hábiles, no cuenta como sin respuesta, evento `omitido`; con avisar_a_lucas sale el push.
     *
     * @return void
     */
    public function test_postpone_omitir_con_aviso(): void
    {
        [, , $followup, $token] = $this->reclamado();

        $this->postJson(self::BASE . $followup->id . '/postpone', [
            'lease_token' => $token, 'tipo' => 'omitir', 'motivo' => 'La importación está de nuestro lado hace 3 días.',
            'avisar_a_lucas' => true,
        ], $this->headers())->assertOk()->assertJson(['ok' => true, 'seguimiento' => ['status' => 'activo', 'sin_respuesta' => 0]]);

        $followup->refresh();
        $this->assertSame('2026-11-13 10:00', $followup->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_OMITIDO));
        $this->assertCount(1, $this->avisos());
        $this->assertStringContainsString('importación', $this->avisos()[0]['body']);
    }

    /**
     * pausa con fecha pasada: 422; sin `hasta`: 422.
     *
     * @return void
     */
    public function test_postpone_pausa_requiere_fecha_futura(): void
    {
        [, , $followup, $token] = $this->reclamado();

        $this->postJson(self::BASE . $followup->id . '/postpone', [
            'lease_token' => $token, 'tipo' => 'pausa', 'hasta' => '2026-11-10', 'motivo' => 'x',
        ], $this->headers())->assertStatus(422)->assertJson(['error' => 'fecha_pasada']);

        $this->postJson(self::BASE . $followup->id . '/postpone', [
            'lease_token' => $token, 'tipo' => 'pausa', 'motivo' => 'x',
        ], $this->headers())->assertStatus(422);

        $this->assertSame('activo', $followup->refresh()->status);
    }

    /**
     * release suelta la reserva sin cambiar nada; con error, evento `fallido`.
     *
     * @return void
     */
    public function test_release(): void
    {
        [, , $followup, $token] = $this->reclamado();
        $antes = $followup->refresh()->next_due_at;

        $this->postJson(self::BASE . $followup->id . '/release', ['lease_token' => $token, 'error' => 'La conversación terminó sin enviar ni posponer.'], $this->headers())
            ->assertOk()->assertJson(['released' => true]);

        $followup->refresh();
        $this->assertNull($followup->lease_token);
        $this->assertEquals($antes, $followup->next_due_at);
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_FALLIDO));

        $this->postJson(self::BASE . $followup->id . '/release', ['lease_token' => $token], $this->headers())
            ->assertStatus(409)->assertJson(['error' => 'reservado']);
    }

    // ── desde un ticket y el contexto del ticket ─────────────────────────────────────────────────

    /**
     * Pausa y reanuda desde un ticket con la reserva del ticket; sin reserva 423; sin seguimiento 404 sin_seguimiento.
     *
     * @return void
     */
    public function test_pausa_y_reanuda_desde_el_ticket(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['unanswered_count' => 1]);
        $ticket = $this->el_cliente_escribe($client, now()->subMinutes(10), 'open', 'Esta semana no puedo, el lunes arrancamos');
        $token  = $this->reservar_el_ticket($ticket);
        $ruta   = '/api/claude/support-codex/tickets/' . $ticket->id . '/implementation-followup';

        $this->postJson($ruta, ['lease_token' => Str::random(64), 'accion' => 'pausar', 'hasta' => '2026-11-13', 'motivo' => 'x'], $this->headers())
            ->assertStatus(423);

        $pausa = $this->postJson($ruta, ['lease_token' => $token, 'accion' => 'pausar', 'hasta' => '2026-11-13', 'motivo' => 'Esta semana no puede'], $this->headers())
            ->assertOk();
        $this->assertTrue($pausa->json('registrado'));
        $this->assertSame('pausado', $pausa->json('seguimiento.status'));
        $this->assertSame('2026-11-16 10:00', $followup->refresh()->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $evento = $this->eventos($followup, ImplementationFollowupEvent::KIND_PAUSADO)->first();
        $this->assertSame((int) $ticket->id, (int) $evento->support_ticket_id);

        $reanuda = $this->postJson($ruta, ['lease_token' => $token, 'accion' => 'reanudar', 'motivo' => 'Ya está, arranquemos'], $this->headers())
            ->assertOk();
        $this->assertSame('activo', $reanuda->json('seguimiento.status'));
        $this->assertNull($reanuda->json('seguimiento.pausado_hasta'));
        $this->assertSame(0, $reanuda->json('seguimiento.sin_respuesta'));
        $this->assertSame('2026-11-13 10:00', $followup->refresh()->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));

        /* Un cliente sin seguimiento: 404 sin_seguimiento. */
        $otro = $this->crear_cliente('Sin seguimiento');
        $ticket_otro = $this->el_cliente_escribe($otro, now()->subMinutes(5), 'open');
        $token_otro = $this->reservar_el_ticket($ticket_otro);
        $this->postJson('/api/claude/support-codex/tickets/' . $ticket_otro->id . '/implementation-followup', [
            'lease_token' => $token_otro, 'accion' => 'pausar', 'hasta' => '2026-11-13', 'motivo' => 'x',
        ], $this->headers())->assertStatus(404)->assertJson(['error' => 'sin_seguimiento']);
    }

    /**
     * El contexto del ticket trae `seguimiento_implementacion` (sin eventos) o null.
     *
     * @return void
     */
    public function test_el_contexto_del_ticket_trae_el_seguimiento(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['hitos' => ['venta' => ['cantidad' => 1, 'primero_at' => null, 'ultimo_at' => null]]]);
        $ticket = $this->el_cliente_escribe($client, now()->subMinutes(10), 'open');

        $bloque = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers())->assertOk()->json('ticket.seguimiento_implementacion');

        $this->assertSame((int) $followup->id, $bloque['id']);
        $this->assertSame('activo', $bloque['status']);
        $this->assertSame(1, $bloque['hitos']['venta']['cantidad']);
        $this->assertArrayNotHasKey('eventos', $bloque);
        $this->assertNotContains('venta', $bloque['obligatorios_pendientes']);

        $otro = $this->crear_cliente('Sin seguimiento');
        $ticket_otro = $this->el_cliente_escribe($otro, now()->subMinutes(5), 'open');
        $contexto = $this->getJson('/api/claude/support-codex/tickets/' . $ticket_otro->id, $this->headers())->assertOk()->json('ticket');
        $this->assertArrayHasKey('seguimiento_implementacion', $contexto);
        $this->assertNull($contexto['seguimiento_implementacion']);
    }

    /**
     * Kill switch: claim y send dan 409 deshabilitado.
     *
     * @return void
     */
    public function test_kill_switch_frena_claim_y_send(): void
    {
        [, , $followup, $token] = $this->reclamado();

        AdminSetting::set(ImplementationFollowupService::SETTING_ENABLED, '0');
        AdminSetting::flush_memo();

        $this->send($followup, $token)->assertStatus(409)->assertJson(['error' => 'deshabilitado']);
        $this->claim($followup, $token)->assertStatus(409)->assertJson(['error' => 'deshabilitado']);
        $this->assertCount(0, $this->espia->plantillas);
    }
}
