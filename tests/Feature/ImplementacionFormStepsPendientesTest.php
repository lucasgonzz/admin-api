<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Tests\Feature\ClaudeImplementaciones\BaseDeImplementaciones;

/**
 * `GET claude/support-codex/implementation-form-steps/pending` (misión `monitor-pasos-implementar-auto`,
 * 16/11/2026): lista de solo lectura de implementaciones de sistema con formulario completo, user setup sin
 * correr y cliente con ticket de soporte abierto.
 */
class ImplementacionFormStepsPendientesTest extends BaseDeImplementaciones
{
    const RUTA = '/api/claude/support-codex/implementation-form-steps/pending';

    /**
     * Prende el soporte de Codex (el interruptor que refleja `enabled`).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-01-01 00:00:00');
        AdminSetting::flush_memo();
    }

    /**
     * Crea un ticket de soporte del cliente.
     *
     * @param Client $client  Cliente dueño.
     * @param string $status  Estado del ticket.
     * @param string $mensaje Fecha del último mensaje del cliente.
     *
     * @return SupportTicket
     */
    protected function crear_ticket(Client $client, $status = 'open', $mensaje = '2026-11-16 09:00:00')
    {
        return SupportTicket::create([
            'client_id'              => $client->id,
            'client_user_id'         => 0,
            'status'                 => $status,
            'source'                 => 'whatsapp',
            'whatsapp_phone'         => $client->phone,
            'opened_at'              => Carbon::parse($mensaje)->subMinute(),
            'last_client_message_at' => Carbon::parse($mensaje),
        ]);
    }

    /**
     * Cliente con implementación con formulario enviado y ticket abierto.
     *
     * @param string $nombre     Nombre del cliente.
     * @param string $formulario Fecha de envío del formulario.
     * @param array  $extra      Atributos extra de la implementación.
     *
     * @return array [Client, Implementation, SupportTicket]
     */
    protected function caso($nombre, $formulario = '2026-11-16 10:20:00', array $extra = [])
    {
        $cliente = $this->crear_cliente($nombre);
        $impl    = $this->crear_implementacion($cliente, array_merge(['form_submitted_at' => $formulario], $extra));
        $ticket  = $this->crear_ticket($cliente);

        return [$cliente, $impl, $ticket];
    }

    /**
     * Ids de implementación de la respuesta.
     *
     * @param \Illuminate\Testing\TestResponse $r Respuesta.
     *
     * @return array<int, int>
     */
    protected function ids($r)
    {
        return array_map('intval', array_column($r->json('items'), 'implementation_id'));
    }

    /** @return void */
    public function test_lista_el_caso_feliz_con_el_contrato_exacto()
    {
        list($cliente, $impl, $ticket) = $this->caso('Feliz');
        SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'ready', 'thread_id' => 'hilo-abc']);

        $r = $this->getJson(self::RUTA, $this->headers())->assertOk();

        $this->assertTrue($r->json('enabled'));
        $this->assertNotEmpty($r->json('generated_at'));
        $item = collect($r->json('items'))->firstWhere('implementation_id', $impl->id);
        $this->assertNotNull($item);
        $this->assertSame('sistema', $item['type']);
        $this->assertSame($cliente->id, $item['client_id']);
        $this->assertSame($cliente->name, $item['client_name']);
        $this->assertSame(1, $item['current_stage']);
        $this->assertNull($item['user_setup_executed_at']);
        $this->assertSame($ticket->id, $item['ticket_id']);
        $this->assertSame('hilo-abc', $item['ticket_thread_id']);
        $this->assertSame(Carbon::parse($impl->form_submitted_at)->getTimestamp(), Carbon::parse($item['form_submitted_at'])->getTimestamp());
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $item['form_submitted_at']);
        $this->assertSame(
            ['implementation_id', 'type', 'client_id', 'client_name', 'form_submitted_at', 'current_stage', 'user_setup_executed_at', 'ticket_id', 'ticket_thread_id'],
            array_keys($item)
        );
    }

    /** @return void */
    public function test_thread_id_es_null_si_el_caso_no_tiene_hilo()
    {
        list(, $impl) = $this->caso('SinHilo');

        $r    = $this->getJson(self::RUTA, $this->headers())->assertOk();
        $item = collect($r->json('items'))->firstWhere('implementation_id', $impl->id);

        $this->assertNull($item['ticket_thread_id']);
    }

    /** @return void */
    public function test_excluye_user_setup_ya_ejecutado()
    {
        list(, $impl) = $this->caso('ConSetup', '2026-11-16 10:20:00', ['user_setup_executed_at' => '2026-11-16 11:00:00']);

        $this->assertNotContains($impl->id, $this->ids($this->getJson(self::RUTA, $this->headers())->assertOk()));
    }

    /** @return void */
    public function test_excluye_implementacion_cerrada_o_pausada()
    {
        list(, $cerrada) = $this->caso('Cerrada', '2026-11-16 10:20:00', ['status' => 'completed']);
        list(, $pausada) = $this->caso('Pausada', '2026-11-16 10:20:00', ['status' => 'paused']);

        $ids = $this->ids($this->getJson(self::RUTA, $this->headers())->assertOk());

        $this->assertNotContains($cerrada->id, $ids);
        $this->assertNotContains($pausada->id, $ids);
    }

    /** @return void */
    public function test_excluye_sin_formulario()
    {
        $cliente = $this->crear_cliente('SinForm');
        $impl    = $this->crear_implementacion($cliente);
        $this->crear_ticket($cliente);

        $this->assertNotContains($impl->id, $this->ids($this->getJson(self::RUTA, $this->headers())->assertOk()));
    }

    /** @return void */
    public function test_excluye_cliente_sin_ticket_abierto()
    {
        $sin = $this->crear_cliente('SinTicket');
        $a   = $this->crear_implementacion($sin, ['form_submitted_at' => '2026-11-16 10:20:00']);

        $cer = $this->crear_cliente('TicketCerrado');
        $b   = $this->crear_implementacion($cer, ['form_submitted_at' => '2026-11-16 10:20:00']);
        $this->crear_ticket($cer, 'closed');

        $ids = $this->ids($this->getJson(self::RUTA, $this->headers())->assertOk());

        $this->assertNotContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    /** @return void */
    public function test_con_varios_tickets_abiertos_elige_el_de_mensaje_mas_reciente()
    {
        list($cliente, $impl, $viejo) = $this->caso('DosTickets');
        $nuevo = $this->crear_ticket($cliente, 'open', '2026-11-16 12:00:00');

        $item = collect($this->getJson(self::RUTA, $this->headers())->assertOk()->json('items'))->firstWhere('implementation_id', $impl->id);

        $this->assertSame($nuevo->id, $item['ticket_id']);
        $this->assertNotSame($viejo->id, $item['ticket_id']);
    }

    /** @return void */
    public function test_ordena_por_form_submitted_at_ascendente()
    {
        list(, $tarde)      = $this->caso('Tarde', '2026-11-16 15:00:00');
        list(, $temprano)   = $this->caso('Temprano', '2026-11-16 08:00:00');
        list(, $medio)      = $this->caso('Medio', '2026-11-16 12:00:00');

        $ids = $this->ids($this->getJson(self::RUTA, $this->headers())->assertOk());
        $ids = array_values(array_intersect($ids, [$tarde->id, $temprano->id, $medio->id]));

        $this->assertSame([$temprano->id, $medio->id, $tarde->id], $ids);
    }

    /** @return void */
    public function test_since_filtra_inclusive_y_limit_recorta()
    {
        list(, $a) = $this->caso('A', '2030-01-01 08:00:00');
        list(, $b) = $this->caso('B', '2030-01-01 09:00:00');
        list(, $c) = $this->caso('C', '2030-01-01 10:00:00');

        $r = $this->getJson(self::RUTA . '?since=' . urlencode('2030-01-01T09:00:00-03:00'), $this->headers())->assertOk();
        // 09:00 -03:00 = 12:00 UTC; las filas se guardan en la zona de la app, así que se compara contra lo guardado.
        $esperado = Implementation::whereIn('id', [$a->id, $b->id, $c->id])
            ->where('form_submitted_at', '>=', Carbon::parse('2030-01-01T09:00:00-03:00'))->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();
        $this->assertSame($esperado, array_values(array_intersect($this->ids($r), [$a->id, $b->id, $c->id])));

        $r2 = $this->getJson(self::RUTA . '?limit=1', $this->headers())->assertOk();
        $this->assertCount(1, $r2->json('items'));
    }

    /**
     * Un `since` en UTC (sufijo Z, como lo manda el monitor) se compara en la zona de la app: 15:00Z = 12:00 en
     * Buenos Aires, así que un formulario enviado a las 12:30 locales entra y uno de las 11:30 no.
     *
     * @return void
     */
    public function test_since_en_utc_se_convierte_a_la_zona_de_la_app()
    {
        list(, $antes) = $this->caso('Antes', '2030-01-01 11:30:00');
        list(, $despues) = $this->caso('Despues', '2030-01-01 12:30:00');

        $r = $this->getJson(self::RUTA . '?since=2030-01-01T15:00:00Z', $this->headers())->assertOk();
        $ids = $this->ids($r);
        $this->assertContains($despues->id, $ids);
        $this->assertNotContains($antes->id, $ids);
    }

    /** @return void */
    public function test_valida_parametros_con_422()
    {
        $this->getJson(self::RUTA . '?limit=0', $this->headers())->assertStatus(422)->assertJsonStructure(['error', 'errores']);
        $this->getJson(self::RUTA . '?limit=21', $this->headers())->assertStatus(422);
        $this->getJson(self::RUTA . '?limit=abc', $this->headers())->assertStatus(422);
        $this->getJson(self::RUTA . '?since=no-es-fecha', $this->headers())->assertStatus(422);
        $this->getJson(self::RUTA . '?limit=20', $this->headers())->assertOk();
    }

    /** @return void */
    public function test_sin_clave_o_con_clave_incorrecta_rechaza()
    {
        $this->getJson(self::RUTA)->assertStatus(401);
        $this->getJson(self::RUTA, ['X-Claude-Task-Key' => 'incorrecta'])->assertStatus(401);
    }

    /** @return void */
    public function test_con_soporte_apagado_devuelve_enabled_false_e_items_vacios()
    {
        $this->caso('Apagado');
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '0');
        AdminSetting::flush_memo();

        $r = $this->getJson(self::RUTA, $this->headers())->assertOk();

        $this->assertFalse($r->json('enabled'));
        $this->assertSame([], $r->json('items'));
    }
}
