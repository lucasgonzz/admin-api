<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los frenos del cambio de estado de leads por Claude (`claude/leads/{id}/status` y
 * `claude/leads/status-batch`).
 *
 * Mismo criterio que el test de envío de plantillas: lo que se verifica acá es sobre todo
 * "NO se cambió nada". Un lote de estados mal armado no manda un WhatsApp, pero mueve leads
 * REALES de tramo del pipeline — un lead pasado a `en_pausa` por error deja de recibir
 * seguimientos y desaparece de las tarjetas del panel, y nadie se entera hasta que lo busca.
 * Los frenos son la única cosa entre un armado equivocado y 200 leads movidos.
 */
class CambioDeEstadoDeLeadPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /**
     * Setea la clave de ingesta en config para que el middleware fail-closed deje pasar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Crea un lead en el estado pedido.
     *
     * @param string $nombre
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, string $status = 'contactado'): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = $nombre;
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * El endpoint de un solo lead cambia el estado y deja el evento en la conversación.
     *
     * @return void
     */
    public function test_cambia_el_estado_de_un_lead_y_registra_el_evento()
    {
        $lead = $this->crear_lead('Rita', 'closer_activo');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/status', [
                'status' => 'en_pausa',
                'motivo' => 'congelado hace 60 dias sin gestion de cierre',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['cambio' => true, 'status_anterior' => 'closer_activo', 'status' => 'en_pausa']);
        $this->assertSame('en_pausa', (string) $lead->fresh()->status);

        // El evento queda en el hilo, marcado como evento de estado (no ensucia last_message_at).
        $evento = LeadMessage::where('lead_id', $lead->id)->where('is_status_event', true)->latest('id')->first();
        $this->assertNotNull($evento, 'Tendría que haber quedado el evento del cambio de estado.');
        $this->assertStringContainsString('congelado hace 60 dias', (string) $evento->content);
        $this->assertSame(LeadMessage::SENT_VIA_CLAUDE, (string) $evento->sent_via);
    }

    /**
     * Pasar a un estado terminal apaga los flags de seguimiento, igual que el pase automático a
     * En Pausa: si no, el lead queda pidiendo una acción que ya no corresponde.
     *
     * @return void
     */
    public function test_al_pasar_a_estado_terminal_apaga_los_flags_de_seguimiento()
    {
        $lead = $this->crear_lead('Omar', 'calificado');
        $lead->requiere_seguimiento = true;
        $lead->tiene_sugerencia_pendiente = true;
        $lead->tiene_seguimiento_sin_ver = true;
        $lead->pendiente_revision_at = now();
        $lead->save();

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/status', ['status' => 'cerrado_perdido'])
            ->assertStatus(200);

        $fresco = $lead->fresh();
        $this->assertSame('cerrado_perdido', (string) $fresco->status);
        $this->assertFalse((bool) $fresco->requiere_seguimiento);
        $this->assertFalse((bool) $fresco->tiene_sugerencia_pendiente);
        $this->assertFalse((bool) $fresco->tiene_seguimiento_sin_ver);
        $this->assertNull($fresco->pendiente_revision_at);
    }

    /**
     * Un slug que no existe en el catálogo no mueve nada.
     *
     * @return void
     */
    public function test_un_estado_inventado_no_cambia_nada()
    {
        $lead = $this->crear_lead('Nadia', 'contactado');

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/status', ['status' => 'super_calificado'])
            ->assertStatus(422);

        $this->assertSame('contactado', (string) $lead->fresh()->status);
    }

    /**
     * `cerrado_ganado` no se asigna desde acá: cuelga de la promoción a Client.
     *
     * @return void
     */
    public function test_no_se_puede_marcar_cerrado_ganado()
    {
        $lead = $this->crear_lead('Ivan', 'closer_activo');

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/status', ['status' => 'cerrado_ganado'])
            ->assertStatus(422);

        $this->assertSame('closer_activo', (string) $lead->fresh()->status);
    }

    /**
     * Un lead que ya está en `cerrado_ganado` tampoco se mueve de ahí.
     *
     * @return void
     */
    public function test_un_lead_ganado_no_se_mueve()
    {
        $lead = $this->crear_lead('Sonia', 'cerrado_ganado');

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/status', ['status' => 'en_pausa'])
            ->assertStatus(422);

        $this->assertSame('cerrado_ganado', (string) $lead->fresh()->status);
    }

    /**
     * El lote simula por defecto: sin `dry_run` explícito no escribe ningún lead.
     *
     * @return void
     */
    public function test_el_lote_simula_por_defecto_y_no_escribe_nada()
    {
        $uno = $this->crear_lead('Bruno', 'closer_activo');
        $dos = $this->crear_lead('Carla', 'solicita_disponibilidad');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', [
                'cambios' => [
                    ['lead_id' => $uno->id, 'status' => 'en_pausa'],
                    ['lead_id' => $dos->id, 'status' => 'en_pausa'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson(['dry_run' => true, 'cambiarian' => 2]);
        $this->assertSame('closer_activo', (string) $uno->fresh()->status);
        $this->assertSame('solicita_disponibilidad', (string) $dos->fresh()->status);
        $this->assertSame(0, LeadMessage::whereIn('lead_id', [$uno->id, $dos->id])->count());
    }

    /**
     * Con `dry_run=false` pero sin la confirmación exacta, cero cambios.
     *
     * @return void
     */
    public function test_sin_confirm_count_exacto_no_cambia_nada()
    {
        $uno = $this->crear_lead('Delia', 'closer_activo');
        $dos = $this->crear_lead('Elias', 'closer_activo');

        $cambios = [
            ['lead_id' => $uno->id, 'status' => 'en_pausa'],
            ['lead_id' => $dos->id, 'status' => 'en_pausa'],
        ];

        // Sin confirm_count.
        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', ['cambios' => $cambios, 'dry_run' => false])
            ->assertStatus(422);

        // Con un confirm_count que no coincide.
        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', [
                'cambios'       => $cambios,
                'dry_run'       => false,
                'confirm_count' => 1,
                'confirm_token' => 'lo-que-sea',
            ])
            ->assertStatus(422);

        $this->assertSame('closer_activo', (string) $uno->fresh()->status);
        $this->assertSame('closer_activo', (string) $dos->fresh()->status);
    }

    /**
     * El `confirm_token` ata la confirmación al conjunto exacto: si la lista cambia entre la
     * simulación y el envío real, no se aplica nada aunque el conteo siga dando igual.
     *
     * @return void
     */
    public function test_el_confirm_token_no_sirve_para_otro_conjunto()
    {
        $uno = $this->crear_lead('Fabio', 'closer_activo');
        $dos = $this->crear_lead('Gilda', 'closer_activo');
        $tres = $this->crear_lead('Hugo', 'closer_activo');

        $simulacion = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', [
                'cambios' => [
                    ['lead_id' => $uno->id, 'status' => 'en_pausa'],
                    ['lead_id' => $dos->id, 'status' => 'en_pausa'],
                ],
            ])->json();

        // Mismo conteo (2), pero se cambió UNO de los destinatarios.
        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', [
                'cambios' => [
                    ['lead_id' => $uno->id, 'status' => 'en_pausa'],
                    ['lead_id' => $tres->id, 'status' => 'en_pausa'],
                ],
                'dry_run'       => false,
                'confirm_count' => 2,
                'confirm_token' => $simulacion['confirm_token'],
            ])
            ->assertStatus(422);

        $this->assertSame('closer_activo', (string) $uno->fresh()->status);
        $this->assertSame('closer_activo', (string) $tres->fresh()->status);
    }

    /**
     * El camino completo: simulación, confirmación con su token, y recién ahí los leads se mueven,
     * cada uno a su propio estado destino.
     *
     * @return void
     */
    public function test_el_lote_confirmado_mueve_cada_lead_a_su_estado()
    {
        $uno = $this->crear_lead('Irma', 'closer_activo');
        $dos = $this->crear_lead('Julio', 'en_pausa');

        $cambios = [
            ['lead_id' => $uno->id, 'status' => 'en_pausa', 'motivo' => 'sin gestion hace dos meses'],
            ['lead_id' => $dos->id, 'status' => 'cerrado_perdido', 'motivo' => 'se despidio'],
        ];

        $simulacion = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', ['cambios' => $cambios])->json();

        $this->assertSame(2, $simulacion['cambiarian']);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', array_merge([
                'cambios'       => $cambios,
                'dry_run'       => false,
                'confirm_count' => 2,
                'confirm_token' => $simulacion['confirm_token'],
            ]));

        $response->assertStatus(200);
        $response->assertJson(['dry_run' => false, 'cambiados' => 2, 'fallidos' => 0]);
        $this->assertSame('en_pausa', (string) $uno->fresh()->status);
        $this->assertSame('cerrado_perdido', (string) $dos->fresh()->status);
    }

    /**
     * Un lead que ya está en el estado destino se omite, no se cuenta como cambio y no deja evento:
     * es lo que hace seguro reintentar un lote a medio aplicar.
     *
     * @return void
     */
    public function test_un_lead_que_ya_esta_en_el_destino_se_omite()
    {
        $lead = $this->crear_lead('Kira', 'en_pausa');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', [
                'cambios' => [['lead_id' => $lead->id, 'status' => 'en_pausa']],
            ]);

        $response->assertStatus(200);
        $response->assertJson(['cambiarian' => 0]);
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count());
    }

    /**
     * Un slug inventado en UNA fila aborta el lote entero: es un error de armado, no un lead
     * salteable. Si se omitiera esa fila y se aplicaran las demás, el lote quedaría a medias sin
     * que nada lo denuncie.
     *
     * @return void
     */
    public function test_un_slug_invalido_en_una_fila_aborta_el_lote_entero()
    {
        $uno = $this->crear_lead('Lucia', 'closer_activo');
        $dos = $this->crear_lead('Mario', 'closer_activo');

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', [
                'cambios' => [
                    ['lead_id' => $uno->id, 'status' => 'en_pausa'],
                    ['lead_id' => $dos->id, 'status' => 'estado_que_no_existe'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame('closer_activo', (string) $uno->fresh()->status);
        $this->assertSame('closer_activo', (string) $dos->fresh()->status);
    }

    /**
     * El tope duro por llamada corre antes de tocar la base.
     *
     * @return void
     */
    public function test_el_lote_no_puede_pasarse_del_tope()
    {
        $cambios = [];
        for ($i = 1; $i <= 201; $i++) {
            $cambios[] = ['lead_id' => $i, 'status' => 'en_pausa'];
        }

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/status-batch', ['cambios' => $cambios])
            ->assertStatus(422)
            ->assertJson(['max_batch' => 200, 'recibidos' => 201]);
    }
}
