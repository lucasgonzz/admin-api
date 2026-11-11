<?php

namespace Tests\Feature\ImplementationFollowups;

use App\Models\ImplementationFollowup;
use App\Models\ImplementationFollowupEvent;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * La tanda de arreglos de la revisión independiente del seguimiento de implementaciones (11/11/2026): racha de
 * fallas, frenos estructurales, tickets que atiende Lucas, implementación pausada, el reloj que ve al equipo, tope de
 * omisiones, `aviso_a_lucas` en el envío, horario del envío, fusión de hitos, el aviso del incierto, la renovación
 * liviana y el aviso anotado en su evento.
 *
 * Reloj fijo el miércoles 11/11/2026 a las 11:00 de Argentina.
 */
class ArreglosDeLaRevisionDelSeguimientoTest extends BaseDeSeguimientos
{
    const BASE = '/api/claude/support-codex/implementation-followups/';
    const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * @param ImplementationFollowup $followup
     * @param string                 $token
     * @param array<string, mixed>   $extra
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function send(ImplementationFollowup $followup, string $token, array $extra = [])
    {
        return $this->postJson(self::BASE . $followup->id . '/send', array_merge([
            'lease_token'  => $token,
            'operation_id' => (string) Str::uuid(),
            'body'         => 'Hola, ¿pudiste cargar tu primera compra? Te dejo el video y si querés la hacemos juntos.',
        ], $extra), $this->headers());
    }

    /**
     * @param ImplementationFollowup $followup
     *
     * @return string
     */
    private function token(ImplementationFollowup $followup): string
    {
        return (string) $this->claim($followup)->assertOk()->json('lease_token');
    }

    /**
     * @param ImplementationFollowup $followup
     * @param string                 $token
     * @param string                 $motivo
     *
     * @return void
     */
    private function omitir(ImplementationFollowup $followup, string $token, string $motivo): void
    {
        $this->postJson(self::BASE . $followup->id . '/postpone', ['lease_token' => $token, 'tipo' => 'omitir', 'motivo' => $motivo], $this->headers())
            ->assertOk();
    }

    /**
     * @param string $cuando
     *
     * @return void
     */
    private function reloj(string $cuando): void
    {
        Carbon::setTestNow(Carbon::parse($cuando, self::ZONA));
    }

    /**
     * @return array<int, string>
     */
    private function motivos_de_los_avisos(): array
    {
        return array_map(function ($aviso) {
            return $aviso['data']['motivo'];
        }, $this->avisos());
    }

    /**
     * @param ImplementationFollowup $followup
     *
     * @return string
     */
    private function proximo(ImplementationFollowup $followup): string
    {
        return $followup->refresh()->next_due_at->setTimezone(self::ZONA)->format('Y-m-d H:i');
    }

    // ── 1. Racha de fallas ───────────────────────────────────────────────────────────────────────

    /**
     * La primera falla de envío corre una hora sin aviso; la segunda seguida corre +2 hábiles con UN aviso, anotado
     * en su evento `fallido`.
     *
     * @return void
     */
    public function test_dos_fallas_de_envio_seguidas_corren_dos_habiles_y_avisan_una_vez(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $this->espia->confirma = false;

        $this->send($followup, $this->token($followup))->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame('2026-11-11 12:00', $this->proximo($followup));
        $this->assertCount(0, $this->avisos());
        $this->assertNull($followup->lease_token, 'el failed suelta la reserva');

        $this->reloj('2026-11-11 12:05:00');
        $this->send($followup, $this->token($followup))->assertOk()->assertJson(['status' => 'failed']);

        $this->assertSame('2026-11-13 10:00', $this->proximo($followup));
        $this->assertSame(['fallas'], $this->motivos_de_los_avisos());
        $ultimo = $this->eventos($followup, ImplementationFollowupEvent::KIND_FALLIDO)->last();
        $this->assertSame('fallas', $ultimo->data['aviso_a_lucas']['motivo']);
    }

    /**
     * Un `release` con error entra en la racha: el primero no cambia nada, el segundo seguido corre +2 con aviso.
     *
     * @return void
     */
    public function test_release_con_error_entra_en_la_racha(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $antes = $this->proximo($followup);

        $this->postJson(self::BASE . $followup->id . '/release', ['lease_token' => $this->token($followup), 'error' => 'Codex no abrió la conversación'], $this->headers())->assertOk();
        $this->assertSame($antes, $this->proximo($followup));
        $this->assertCount(0, $this->avisos());

        $this->postJson(self::BASE . $followup->id . '/release', ['lease_token' => $this->token($followup), 'error' => 'Codex no abrió la conversación otra vez'], $this->headers())->assertOk();
        $this->assertSame('2026-11-13 10:00', $this->proximo($followup));
        $this->assertSame(['fallas'], $this->motivos_de_los_avisos());
    }

    /**
     * Una omisión corta la racha: después de una falla y una omisión, la siguiente falla es la primera otra vez.
     *
     * @return void
     */
    public function test_una_omision_corta_la_racha_de_fallas(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $this->espia->confirma = false;

        $this->send($followup, $this->token($followup))->assertOk()->assertJson(['status' => 'failed']);
        $this->reloj('2026-11-11 12:05:00');
        $this->omitir($followup, $this->token($followup), 'La importación es nuestra.');

        $this->reloj('2026-11-13 10:30:00');
        $this->send($followup, $this->token($followup))->assertOk()->assertJson(['status' => 'failed']);

        $this->assertSame('2026-11-13 11:30', $this->proximo($followup), 'primera falla de una racha nueva: una hora');
        $this->assertCount(0, $this->avisos());
    }

    // ── 2. Frenos estructurales ──────────────────────────────────────────────────────────────────

    /**
     * Sin teléfono: pending no lo ofrece, lo frena con `data.motivo = sin_contacto` y UN aviso; si el cliente lo
     * reactiva escribiendo y vuelve a caer por lo mismo, no se avisa de nuevo.
     *
     * @return void
     */
    public function test_sin_contacto_se_frena_con_un_solo_aviso(): void
    {
        [$client, $implementation] = $this->escenario();
        $client->phone = '';
        $client->save();
        $followup = $this->seguimiento($client, $implementation);

        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));
        $this->assertSame('frenado', $followup->refresh()->status);
        $frenado = $this->eventos($followup, ImplementationFollowupEvent::KIND_FRENADO)->first();
        $this->assertSame('sin_contacto', $frenado->data['motivo']);
        $this->assertSame(['frenado'], $this->motivos_de_los_avisos());

        /* Escribe (por el sistema), se reactiva, y al vencer vuelve a frenarse por lo mismo sin otro aviso. */
        $this->el_cliente_escribe($client, now()->subMinutes(5));
        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);
        $this->assertSame('activo', $followup->refresh()->status);

        $this->reloj('2026-11-13 10:30:00');
        $this->getJson(self::BASE . 'pending', $this->headers())->assertOk();
        $this->assertSame('frenado', $followup->refresh()->status);
        $this->assertCount(2, $this->eventos($followup, ImplementationFollowupEvent::KIND_FRENADO));
        $this->assertCount(1, $this->avisos(), 'mismo motivo: no se repite el aviso');
    }

    /**
     * Implementación en modo automático: nace frenado (`respuesta_al_hilo`) con aviso, no se ofrece y el claim da 409.
     *
     * @return void
     */
    public function test_implementacion_automatica_nace_frenada(): void
    {
        $client = $this->crear_cliente('Automática');
        $this->llevar_a_la_etapa($this->crear_implementacion($client, ['automation_mode' => 'auto']), 3);

        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);

        $followup = ImplementationFollowup::where('client_id', $client->id)->first();
        $this->assertSame('frenado', $followup->status);
        $this->assertSame('respuesta_al_hilo', $this->eventos($followup, ImplementationFollowupEvent::KIND_FRENADO)->first()->data['motivo']);
        $this->assertSame(['frenado'], $this->motivos_de_los_avisos());

        $this->reloj('2026-11-16 11:00:00');
        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));
        $this->claim($followup)->assertStatus(409)->assertJson(['error' => 'no_vencido']);
    }

    /**
     * Cliente dado de baja y sin etapa cargada: frenados con su motivo.
     *
     * @return void
     */
    public function test_cliente_inactivo_y_sin_etapa_se_frenan(): void
    {
        [$baja, $impl1] = $this->escenario();
        $de_baja = $this->seguimiento($baja, $impl1);
        $baja->is_active = false;
        $baja->save();

        [$sin, $impl2] = $this->escenario();
        $sin_etapa = $this->seguimiento($sin, $impl2);
        \App\Models\ImplementationStage::where('implementation_id', $impl2->id)->where('stage_number', 6)->delete();

        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);

        $this->assertSame('cliente_inactivo', $this->eventos($de_baja, ImplementationFollowupEvent::KIND_FRENADO)->first()->data['motivo']);
        $this->assertSame('sin_etapa', $this->eventos($sin_etapa, ImplementationFollowupEvent::KIND_FRENADO)->first()->data['motivo']);
    }

    // ── 3. Tickets que atiende Lucas ─────────────────────────────────────────────────────────────

    /**
     * Un ticket abierto con la respuesta automática apagada, o con el caso en revisión manual, saca al cliente de
     * pending y del claim nuevo (409 no_vencido, razon lucas_atendiendo), sin cambiarle el estado.
     *
     * @return void
     */
    public function test_ticket_que_atiende_lucas_excluye_sin_cambiar_estado(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => $client->phone, 'opened_at' => now()->subDays(3), 'claude_auto_reply' => false,
        ]);

        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));
        $this->claim($followup)->assertStatus(409)->assertJson(['error' => 'no_vencido', 'razon' => 'lucas_atendiendo']);
        $this->assertSame('activo', $followup->refresh()->status);

        /* Con la respuesta automática prendida pero el caso en manual_review: igual. */
        $ticket->claude_auto_reply = true;
        $ticket->save();
        SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'manual_review', 'review_reason' => 'Lucas lo atiende']);

        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));

        /* Lucas cierra el ticket: vuelve a ofrecerse. */
        $ticket->status = 'closed';
        $ticket->save();
        $ids = array_column($this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'), 'id');
        $this->assertSame([(int) $followup->id], $ids);
        $this->assertCount(0, $this->avisos());
    }

    // ── 4. Implementación pausada ────────────────────────────────────────────────────────────────

    /**
     * La implementación pausada en el admin pausa el seguimiento (sin fecha) y no se ofrece; al volver a
     * `in_progress` se reanuda a +2 hábiles.
     *
     * @return void
     */
    public function test_implementacion_pausada_pausa_y_reanuda(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $implementation->status = 'paused';
        $implementation->save();

        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);
        $followup->refresh();
        $this->assertSame('pausado', $followup->status);
        $this->assertNull($followup->paused_until);
        $this->assertSame('implementación pausada en el admin', $followup->pause_reason);
        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));

        /* Otra corrida no la reanuda sola (no tiene paused_until). */
        $this->reloj('2026-11-16 11:00:00');
        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);
        $this->assertSame('pausado', $followup->refresh()->status);

        $implementation->status = 'in_progress';
        $implementation->save();
        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);

        $this->assertSame('activo', $followup->refresh()->status);
        $this->assertNull($followup->pause_reason);
        $this->assertSame('2026-11-18 10:00', $this->proximo($followup));
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_REANUDADO));
    }

    // ── 5. El reloj ve al equipo ─────────────────────────────────────────────────────────────────

    /**
     * Un mensaje del equipo de ayer corre el seguimiento a 2 hábiles desde ese mensaje y no lo ofrece; no toca el
     * contador. Uno de hace una semana no cambia nada.
     *
     * @return void
     */
    public function test_el_reloj_ve_al_equipo(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['unanswered_count' => 1]);
        $this->escribe_el_equipo($client, Carbon::parse('2026-11-10 16:00', self::ZONA));

        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));
        $this->assertSame('2026-11-12 10:00', $this->proximo($followup));
        $this->assertSame(1, (int) $followup->unanswered_count, 'el contador solo lo reinicia un entrante del cliente');

        [$otro, $otra] = $this->escenario();
        $viejo = $this->seguimiento($otro, $otra);
        $this->escribe_el_equipo($otro, Carbon::parse('2026-11-04 16:00', self::ZONA));

        $ids = array_column($this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'), 'id');
        $this->assertSame([(int) $viejo->id], $ids);
    }

    /**
     * Un mensaje del equipo que no le llegó al cliente (`not_received`) no corre el reloj.
     *
     * @return void
     */
    public function test_un_mensaje_del_equipo_que_no_llego_no_corre_el_reloj(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $this->escribe_el_equipo($client, now()->subHour(), 'not_received');

        $ids = array_column($this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'), 'id');
        $this->assertSame([(int) $followup->id], $ids);
    }

    /**
     * Un mensaje saliente del equipo en un ticket (cerrado) del cliente.
     *
     * @param \App\Models\Client $client
     * @param Carbon             $cuando
     * @param string|null        $estado remote_delivery_status.
     *
     * @return void
     */
    private function escribe_el_equipo($client, Carbon $cuando, ?string $estado = null): void
    {
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'closed', 'source' => 'whatsapp',
            'whatsapp_phone' => $client->phone, 'opened_at' => $cuando->copy()->subDay(), 'closed_at' => $cuando->copy()->addHour(),
        ]);
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Te cargamos los artículos', 'delivered_at' => $cuando, 'remote_delivery_status' => $estado,
        ]);
        $mensaje->created_at = $cuando;
        $mensaje->save();
    }

    // ── 6. Tope de omisiones ─────────────────────────────────────────────────────────────────────

    /**
     * Dos omisiones seguidas: al tercer vencimiento se frena (`omisiones`) con UN aviso que lleva los motivos.
     *
     * @return void
     */
    public function test_dos_omisiones_seguidas_frenan_al_tercer_vencimiento(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);

        $this->omitir($followup, $this->token($followup), 'La importación la estamos haciendo nosotros.');
        $this->reloj('2026-11-13 11:00:00');
        $this->omitir($followup, $this->token($followup), 'ARCA sigue de nuestro lado.');

        $this->reloj('2026-11-17 11:00:00');
        $this->assertSame([], $this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'));

        $this->assertSame('frenado', $followup->refresh()->status);
        $frenado = $this->eventos($followup, ImplementationFollowupEvent::KIND_FRENADO)->first();
        $this->assertSame('omisiones', $frenado->data['motivo']);
        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('ARCA sigue de nuestro lado', $avisos[0]['body']);
        $this->assertStringContainsString('La importación la estamos haciendo nosotros', $avisos[0]['body']);
        $this->assertSame('frenado', $frenado->data['aviso_a_lucas']['motivo'], 'el aviso queda en el evento frenado');
    }

    /**
     * Un enviado en el medio corta las omisiones.
     *
     * @return void
     */
    public function test_un_enviado_corta_las_omisiones(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);

        $this->omitir($followup, $this->token($followup), 'Primera omisión.');
        $this->reloj('2026-11-13 11:00:00');
        $this->send($followup, $this->token($followup))->assertOk()->assertJson(['status' => 'sent']);
        $this->reloj('2026-11-17 11:00:00');
        $this->omitir($followup, $this->token($followup), 'Segunda omisión.');

        $this->reloj('2026-11-19 11:00:00');
        $ids = array_column($this->getJson(self::BASE . 'pending', $this->headers())->assertOk()->json('followups'), 'id');
        $this->assertSame([(int) $followup->id], $ids);
    }

    // ── 7, 8 y 10. Envío ─────────────────────────────────────────────────────────────────────────

    /**
     * `aviso_a_lucas` en el envío: después de asentar, un push con ese texto, anotado en el evento `enviado`.
     *
     * @return void
     */
    public function test_send_con_aviso_a_lucas(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);

        $this->send($followup, $this->token($followup), ['aviso_a_lucas' => 'El cliente espera que le carguemos las fotos del proveedor.'])
            ->assertOk()->assertJson(['status' => 'sent']);

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('pedido_de_martin', $avisos[0]['data']['motivo']);
        $this->assertSame('El cliente espera que le carguemos las fotos del proveedor.', $avisos[0]['body']);
        $this->assertStringContainsString($client->resolve_display_name(), $avisos[0]['title']);
        $enviado = $this->eventos($followup, ImplementationFollowupEvent::KIND_ENVIADO)->first();
        $this->assertSame('pedido_de_martin', $enviado->data['aviso_a_lucas']['motivo']);

        /* Largo de más: 422. */
        [$otro, $otra] = $this->escenario();
        $segundo = $this->seguimiento($otro, $otra);
        $this->send($segundo, $this->token($segundo), ['aviso_a_lucas' => str_repeat('a', 501)])->assertStatus(422);
    }

    /**
     * Fuera de lun–vie 09:30–19:00: 409 `fuera_de_horario`, sin mandar y sin soltar la reserva.
     *
     * @return void
     */
    public function test_send_fuera_de_horario(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $this->reloj('2026-11-11 17:58:00');
        $token = $this->token($followup);

        $this->reloj('2026-11-11 19:01:00');
        $this->send($followup, $token)->assertStatus(409)->assertJson(['error' => 'fuera_de_horario']);
        $this->assertCount(0, $this->espia->plantillas);
        $this->assertNotNull($followup->refresh()->lease_token, 'la reserva sigue: la suelta el monitor');

        /* 18:05 ya no está en el horario de pending (10–18) pero sí en el de envío (09:30–19:00). */
        $this->reloj('2026-11-11 18:05:00');
        $this->send($followup, $token)->assertOk()->assertJson(['status' => 'sent']);
    }

    /**
     * El aviso del envío incierto dice que el monitor lo bloquea localmente hasta 3 días hábiles.
     *
     * @return void
     */
    public function test_el_aviso_del_incierto_menciona_el_bloqueo_local(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $this->espia->explota = true;

        $this->send($followup, $this->token($followup))->assertOk()->assertJson(['status' => 'unknown']);

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('envio_incierto', $avisos[0]['data']['motivo']);
        $this->assertStringContainsString('3 días hábiles', $avisos[0]['body']);
        $this->assertStringContainsString('seguimientos\\inciertos\\' . $followup->id . '.json', $avisos[0]['body']);
        $this->assertSame('2026-11-13 10:00', $this->proximo($followup));
    }

    // ── 9. Hitos fusionados ──────────────────────────────────────────────────────────────────────

    /**
     * Una medición nueva no borra las claves guardadas que no vinieron.
     *
     * @return void
     */
    public function test_milestones_fusiona_por_clave(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $token = $this->token($followup);
        $hito = function ($cantidad) {
            return ['cantidad' => $cantidad, 'primero_at' => null, 'ultimo_at' => null];
        };

        $this->postJson(self::BASE . $followup->id . '/milestones', [
            'lease_token' => $token, 'medido_at' => '2026-11-11T11:00:00-03:00', 'hitos' => ['venta' => $hito(2), 'compra' => $hito(0)],
        ], $this->headers())->assertOk();

        $respuesta = $this->postJson(self::BASE . $followup->id . '/milestones', [
            'lease_token' => $token, 'medido_at' => '2026-11-11T11:05:00-03:00', 'hitos' => ['compra' => $hito(1)],
        ], $this->headers())->assertOk();

        $this->assertSame(['compra'], $respuesta->json('nuevos'));
        $hitos = $followup->refresh()->hitos;
        $this->assertSame(2, $hitos['venta']['cantidad'], 'venta no vino y se conserva');
        $this->assertSame(1, $hitos['compra']['cantidad']);
        $this->assertNotContains('venta', $respuesta->json('obligatorios_pendientes'));
    }

    // ── 11. Renovación liviana ───────────────────────────────────────────────────────────────────

    /**
     * Con el token y `sin_contexto: true` renueva sin armar el contexto; sin el campo, como siempre.
     *
     * @return void
     */
    public function test_renovacion_liviana(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $token = $this->token($followup);

        $liviana = $this->postJson(self::BASE . $followup->id . '/claim', ['lease_token' => $token, 'sin_contexto' => true], $this->headers())->assertOk();
        $this->assertSame($token, $liviana->json('lease_token'));
        $this->assertNotEmpty($liviana->json('run_id'));
        $this->assertNotEmpty($liviana->json('lease_expires_at'));
        $this->assertArrayNotHasKey('context', $liviana->json());

        $completa = $this->claim($followup, $token)->assertOk();
        $this->assertArrayHasKey('context', $completa->json());
    }

    // ── 12. El aviso en su evento ────────────────────────────────────────────────────────────────

    /**
     * El aviso de un postpone queda en SU evento (el `omitido`), no en el último que haya.
     *
     * @return void
     */
    public function test_el_aviso_del_postpone_queda_en_su_evento(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);

        $this->postJson(self::BASE . $followup->id . '/postpone', [
            'lease_token' => $this->token($followup), 'tipo' => 'omitir', 'motivo' => 'Falta que el equipo cargue las fotos.', 'avisar_a_lucas' => true,
        ], $this->headers())->assertOk();

        $omitido = $this->eventos($followup, ImplementationFollowupEvent::KIND_OMITIDO)->first();
        $this->assertSame('omitido', $omitido->data['aviso_a_lucas']['motivo']);
    }
}
