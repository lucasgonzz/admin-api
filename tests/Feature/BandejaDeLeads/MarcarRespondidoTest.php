<?php

namespace Tests\Feature\BandejaDeLeads;

use App\Events\LeadConversationUpdated;
use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadConversationAiState;
use App\Services\LeadPendingReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * El botón «no necesita respuesta»: `POST admin/lead/{id}/marcar-respondido` y lo que la marca
 * `leads.atendido_hasta_message_id` hace en el resto del sistema (misión flujo-lead-landing-closer,
 * decisión D6 de Lucas, 17/10/2026).
 *
 * Dos mitades:
 *
 * 1. El endpoint: sin `message_id` marca hasta el último mensaje del lead; con `message_id`, hasta
 *    ese (que tiene que ser un mensaje del lead de ESE lead: 422 si no); `deshacer` limpia; emite
 *    `LeadConversationUpdated` con solo el `lead_id`; devuelve la fila.
 *
 * 2. La marca adentro de la definición única de «sin responder»: la fila deja de ser amarilla, la
 *    tarjeta deja de contarla, `?sin_responder=1` deja de traerla y el scheduler de la IA no programa
 *    una sugerencia. Y un mensaje NUEVO del lead vuelve a contar solo. Acá también vive el test que
 *    `RevisionDeLeadsEnSqlYEnPhpCoincidenTest` no puede dar: ese prueba que el SQL y el PHP coinciden;
 *    este fija CUÁL tiene que ser el veredicto con la marca puesta, y recién después que coinciden.
 */
class MarcarRespondidoTest extends TestCase
{
    use DatabaseTransactions;

    /** @var Admin */
    private $admin;

    /**
     * Base sin leads (las tarjetas y `?sin_responder=1` son globales), admin autenticado y la cache
     * limpia: el scheduler de la IA guarda su token de debounce en cache y con el driver `file` un
     * resto de otra corrida cambia lo que se mide (ver SugerenciaBorradaEnPlenoEnvioTest).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LeadMessage::query()->delete();
        Lead::query()->delete();

        Cache::flush();

        $this->admin = Admin::create([
            'name'     => 'Setter de prueba',
            'email'    => 'marcar-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    /**
     * Lead mínimo.
     *
     * @param string               $nombre
     * @param array<string, mixed> $campos
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, array $campos = []): Lead
    {
        return Lead::create(array_merge([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ], $campos));
    }

    /**
     * Mensaje del hilo. Por defecto: entrante del lead.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $campos
     *
     * @return LeadMessage
     */
    private function crear_mensaje(Lead $lead, array $campos = []): LeadMessage
    {
        $base = [
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'content'         => 'Hola, quiero ver el sistema',
            'status'          => 'enviado',
            'kind'            => 'text',
            'is_status_event' => false,
            'is_error'        => false,
            'sent_at'         => now(),
        ];

        return LeadMessage::create(array_merge($base, $campos));
    }

    /**
     * Saliente que efectivamente llegó al lead.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $campos
     *
     * @return LeadMessage
     */
    private function crear_saliente_entregado(Lead $lead, array $campos = []): LeadMessage
    {
        return $this->crear_mensaje($lead, array_merge([
            'sender'              => 'setter',
            'content'             => 'Te paso los planes',
            'whatsapp_message_id' => 'wamid.' . strtoupper(bin2hex(random_bytes(8))),
        ], $campos));
    }

    /**
     * Pega al endpoint.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $body
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function marcar(Lead $lead, array $body = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/marcar-respondido', $body);
    }

    /**
     * La fila del lead en la lista de chats.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function fila_de(Lead $lead): array
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/chats/' . $lead->id);
        $response->assertStatus(200);

        return $response->json('data');
    }

    /**
     * `sin_responder` de la tarjeta `calificado`.
     *
     * @return int
     */
    private function sin_responder_en_la_tarjeta_calificado(): int
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/status-cards');
        $response->assertStatus(200);

        foreach ($response->json('cards') as $card) {
            if ($card['value'] === 'calificado') {
                return (int) $card['sin_responder'];
            }
        }

        $this->fail('No vino la tarjeta calificado.');
    }

    /**
     * Ids que devuelve `GET admin/lead?sin_responder=1`.
     *
     * @return array<int, int>
     */
    private function ids_del_listado_sin_responder(): array
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead?sin_responder=1');
        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('models'), 'id'));
    }

    /**
     * Sin `message_id`, marca hasta el ÚLTIMO mensaje del lead y devuelve la fila ya sin amarillo.
     *
     * @return void
     */
    public function test_sin_message_id_marca_hasta_el_ultimo_mensaje_del_lead(): void
    {
        $lead = $this->crear_lead('Dijo gracias');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead);
        $this->crear_mensaje($lead, ['content' => 'Gracias']);
        $ultimo = $this->crear_mensaje($lead, ['content' => "\u{1F44D}"]);

        $this->assertSame('amarillo', $this->fila_de($lead)['atencion'], 'Antes: el lead habló último.');

        $response = $this->marcar($lead);
        $response->assertStatus(200);

        $this->assertSame(['data'], array_keys($response->json()));
        $fila = $response->json('data');

        $this->assertSame((int) $ultimo->id, $fila['atendido_hasta_message_id']);
        $this->assertNull($fila['atencion']);
        $this->assertNull($fila['atencion_motivo']);
        $this->assertSame((int) $ultimo->id, (int) $lead->fresh()->atendido_hasta_message_id, 'La marca quedó en la base.');
    }

    /**
     * Con `message_id`, marca hasta ESE mensaje: los posteriores del lead siguen sin responder.
     *
     * @return void
     */
    public function test_con_message_id_marca_hasta_ese_mensaje(): void
    {
        $lead    = $this->crear_lead('Dos mensajes');
        $primero = $this->crear_mensaje($lead, ['content' => 'Gracias']);
        $this->crear_mensaje($lead, ['content' => '¿Y el precio?']);

        $response = $this->marcar($lead, ['message_id' => $primero->id]);
        $response->assertStatus(200);

        $this->assertSame((int) $primero->id, $response->json('data.atendido_hasta_message_id'));
        $this->assertSame('amarillo', $response->json('data.atencion'), 'El segundo mensaje sigue esperando respuesta.');
    }

    /**
     * `deshacer: true` limpia la marca y la fila vuelve a ser amarilla.
     *
     * @return void
     */
    public function test_deshacer_limpia_la_marca(): void
    {
        $lead = $this->crear_lead('Se arrepintió');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);

        $this->marcar($lead)->assertStatus(200);
        $this->assertNull($this->fila_de($lead)['atencion']);

        $response = $this->marcar($lead, ['deshacer' => true]);
        $response->assertStatus(200);

        $this->assertNull($response->json('data.atendido_hasta_message_id'));
        $this->assertSame('amarillo', $response->json('data.atencion'));
        $this->assertNull($lead->fresh()->atendido_hasta_message_id);
    }

    /**
     * Un `message_id` que no es un mensaje del lead de ESE lead da 422 y no escribe nada: ni el de
     * otro lead, ni un saliente nuestro, ni uno inexistente.
     *
     * @return void
     */
    public function test_un_message_id_ajeno_da_422_y_no_escribe_nada(): void
    {
        $lead = $this->crear_lead('El lead');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $saliente = $this->crear_saliente_entregado($lead);

        $otro        = $this->crear_lead('Otro lead');
        $del_otro    = $this->crear_mensaje($otro, ['content' => 'Hola']);

        $this->marcar($lead, ['message_id' => $del_otro->id])->assertStatus(422)->assertJsonValidationErrors(['message_id']);
        $this->marcar($lead, ['message_id' => $saliente->id])->assertStatus(422)->assertJsonValidationErrors(['message_id']);
        $this->marcar($lead, ['message_id' => 999999999])->assertStatus(422)->assertJsonValidationErrors(['message_id']);
        $this->marcar($lead, ['message_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['message_id']);

        $this->assertNull($lead->fresh()->atendido_hasta_message_id, 'Ningún 422 escribió la marca.');
        $this->assertNull($otro->fresh()->atendido_hasta_message_id);
    }

    /**
     * Un lead sin mensajes propios no tiene nada que marcar: 422.
     *
     * @return void
     */
    public function test_sin_mensajes_del_lead_da_422(): void
    {
        $lead = $this->crear_lead('Solo le escribimos nosotros');
        $this->crear_saliente_entregado($lead);

        $this->marcar($lead)->assertStatus(422);
        $this->assertNull($lead->fresh()->atendido_hasta_message_id);
    }

    /**
     * Un lead inexistente: 404.
     *
     * @return void
     */
    public function test_lead_inexistente_da_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/lead/999999999/marcar-respondido', [])
            ->assertStatus(404);
    }

    /**
     * Emite `LeadConversationUpdated` con el payload mínimo: solo el `lead_id` (el canal es público).
     *
     * @return void
     */
    public function test_emite_lead_conversation_updated_con_solo_el_lead_id(): void
    {
        Event::fake([LeadConversationUpdated::class]);

        $lead = $this->crear_lead('Avisa a las pestañas');
        $this->crear_mensaje($lead, ['content' => 'Gracias']);

        $this->marcar($lead)->assertStatus(200);

        Event::assertDispatched(LeadConversationUpdated::class, function (LeadConversationUpdated $evento) use ($lead) {
            return $evento->lead_id === (int) $lead->id
                && $evento->lead_message_id === null
                && $evento->is_status_update === false
                && $evento->delivery_status === null;
        });
    }

    /**
     * 🔴 El efecto de la marca en las cuatro superficies que comparten la definición única: la fila
     * deja de ser amarilla, la tarjeta deja de contarla, `?sin_responder=1` deja de traerla, y un
     * mensaje NUEVO del lead las vuelve a prender a las cuatro.
     *
     * @return void
     */
    public function test_la_marca_saca_al_lead_del_amarillo_de_las_tarjetas_y_del_filtro_y_un_mensaje_nuevo_lo_devuelve(): void
    {
        $lead = $this->crear_lead('Gracias y chau');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead);
        $this->crear_mensaje($lead, ['content' => 'Gracias']);

        // Antes: pide atención en las tres superficies.
        $this->assertSame('amarillo', $this->fila_de($lead)['atencion']);
        $this->assertSame(1, $this->sin_responder_en_la_tarjeta_calificado());
        $this->assertSame([(int) $lead->id], $this->ids_del_listado_sin_responder());

        $this->marcar($lead)->assertStatus(200);

        // Después: en ninguna.
        $this->assertNull($this->fila_de($lead)['atencion'], 'La fila dejó de ser amarilla.');
        $this->assertSame(0, $this->sin_responder_en_la_tarjeta_calificado(), 'La tarjeta dejó de contarla.');
        $this->assertSame([], $this->ids_del_listado_sin_responder(), '?sin_responder=1 dejó de traerla.');

        // Un mensaje nuevo del lead (id mayor que la marca) vuelve a contar solo, sin tocar la marca.
        $this->crear_mensaje($lead, ['content' => 'Una cosa más: ¿tienen facturación?']);

        $fila = $this->fila_de($lead);
        $this->assertSame('amarillo', $fila['atencion'], 'El mensaje nuevo vuelve a pedir respuesta.');
        $this->assertNotNull($fila['atendido_hasta_message_id'], 'La marca vieja sigue ahí: no hizo falta limpiarla.');
        $this->assertSame(1, $this->sin_responder_en_la_tarjeta_calificado());
        $this->assertSame([(int) $lead->id], $this->ids_del_listado_sin_responder());
    }

    /**
     * 🔴 El scheduler de la IA no programa una sugerencia para un mensaje dado por atendido.
     *
     * `LeadAiSuggestionScheduler::schedule_after_lead_inbound()` corta en
     * `LeadConversationAiState::has_unanswered_lead_messages()`, que es el gemelo PHP de la cláusula.
     * Se cuentan los tres cajones del BusFake porque el scheduler elige el canal según la demora
     * configurada (ver SugerenciaBorradaEnPlenoEnvioTest::despachos_de_generacion()).
     *
     * @return void
     */
    public function test_el_scheduler_de_la_ia_no_programa_sugerencia_para_un_mensaje_marcado(): void
    {
        Bus::fake([GenerateLeadAiSuggestionJob::class]);

        $lead = $this->crear_lead('Con IA', ['claude_auto_reply' => true]);
        $this->crear_mensaje($lead, ['content' => 'Hola']);
        $this->crear_saliente_entregado($lead, ['sender' => 'sistema', 'content' => 'Soy Martín']);
        $this->crear_mensaje($lead, ['content' => 'Dale, gracias']);

        $this->marcar($lead)->assertStatus(200);

        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);

        $this->assertSame(0, $this->despachos_de_generacion(), 'Con la marca puesta no hay nada que contestar: no se programa la IA.');

        // Sin la marca, el mismo hilo sí programa.
        $this->marcar($lead, ['deshacer' => true])->assertStatus(200);

        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);

        $this->assertGreaterThanOrEqual(1, $this->despachos_de_generacion(), 'Sin la marca el scheduler vuelve a programar.');
    }

    /**
     * Despachos del job de generación, en los tres cajones del BusFake.
     *
     * @return int
     */
    private function despachos_de_generacion(): int
    {
        return Bus::dispatched(GenerateLeadAiSuggestionJob::class)->count()
            + Bus::dispatchedSync(GenerateLeadAiSuggestionJob::class)->count()
            + Bus::dispatchedAfterResponse(GenerateLeadAiSuggestionJob::class)->count();
    }

    /**
     * 🔴 El VEREDICTO con la marca puesta, escenario por escenario, y en los DOS lados (SQL y PHP).
     *
     * `RevisionDeLeadsEnSqlYEnPhpCoincidenTest` prueba que el scope y el oráculo PHP coinciden; no
     * prueba que lo que dicen sea lo correcto. Esto fija lo correcto:
     *
     *   - marcado hasta el último mensaje del lead → NO requiere revisión (ni en SQL ni en PHP);
     *   - marcado hasta un mensaje anterior, con otro del lead después → SÍ (el posterior sigue
     *     sin responder);
     *   - marcado y después contestado y después escribió de nuevo → SÍ (la marca vieja no blanquea
     *     lo nuevo);
     *   - marca sobre un mensaje reaccionado desde el panel (redundante) → NO, igual que antes;
     *   - la marca NO tapa un error de envío (razón B): sigue requiriendo revisión.
     *
     * Y al final, la paridad: el conjunto del scope SQL y el del oráculo PHP tienen que ser el mismo.
     *
     * @return void
     */
    public function test_el_veredicto_con_la_marca_puesta_en_sql_y_en_php(): void
    {
        $esperado = [];

        // 1. Marcado hasta el último: no.
        $lead = $this->crear_lead('Marcado hasta el ultimo');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $ultimo = $this->crear_mensaje($lead, ['content' => 'Gracias']);
        $lead->update(['atendido_hasta_message_id' => $ultimo->id]);
        $esperado[$lead->id] = ['lead' => $lead, 'requiere' => false];

        // 2. Marcado hasta uno anterior, con otro del lead después: sí.
        $lead    = $this->crear_lead('Marcado hasta uno anterior');
        $primero = $this->crear_mensaje($lead, ['content' => 'Gracias']);
        $this->crear_mensaje($lead, ['content' => '¿Y el precio?']);
        $lead->update(['atendido_hasta_message_id' => $primero->id]);
        $esperado[$lead->id] = ['lead' => $lead, 'requiere' => true];

        // 3. Marcado, contestado, y escribió de nuevo: sí.
        $lead    = $this->crear_lead('Marcado y escribio de nuevo');
        $primero = $this->crear_mensaje($lead, ['content' => 'Gracias']);
        $lead->update(['atendido_hasta_message_id' => $primero->id]);
        $this->crear_saliente_entregado($lead);
        $this->crear_mensaje($lead, ['content' => 'Una cosa más']);
        $esperado[$lead->id] = ['lead' => $lead, 'requiere' => true];

        // 4. Marca sobre un mensaje que además tiene reacción del panel: no (ya no contaba).
        $lead    = $this->crear_lead('Marcado y reaccionado');
        $mensaje = $this->crear_mensaje($lead, ['content' => 'Gracias', 'admin_reaction_emoji' => "\u{1F44D}", 'admin_reaction_at' => now()]);
        $lead->update(['atendido_hasta_message_id' => $mensaje->id]);
        $esperado[$lead->id] = ['lead' => $lead, 'requiere' => false];

        // 5. La marca no tapa un error de envío sin resolver (razón B): sí.
        $lead    = $this->crear_lead('Marcado con error de envio');
        $mensaje = $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'content' => 'Error de envío', 'is_status_event' => true, 'is_error' => true, 'sent_at' => null]);
        $lead->update(['atendido_hasta_message_id' => $mensaje->id]);
        $esperado[$lead->id] = ['lead' => $lead, 'requiere' => true];

        // 6. Control sin marca, sin responder: sí (la columna en null no filtra nada).
        $lead = $this->crear_lead('Sin marca');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $esperado[$lead->id] = ['lead' => $lead, 'requiere' => true];

        $service = new LeadPendingReviewService();
        $ids_sql = array_map('intval', Lead::query()->requiereRevision()->pluck('id')->all());
        sort($ids_sql);

        $ids_php = [];
        foreach ($esperado as $id => $caso) {
            $fresco = $caso['lead']->fresh()->load('messages');

            $en_php = $service->lead_requiere_revision($fresco);
            $en_sql = in_array((int) $id, $ids_sql, true);

            $this->assertSame($caso['requiere'], $en_sql, 'SQL, escenario "' . $caso['lead']->contact_name . '": el veredicto esperado es ' . ($caso['requiere'] ? 'sí' : 'no') . '.');
            $this->assertSame($caso['requiere'], $en_php, 'PHP, escenario "' . $caso['lead']->contact_name . '": el veredicto esperado es ' . ($caso['requiere'] ? 'sí' : 'no') . '.');

            if ($en_php) {
                $ids_php[] = (int) $id;
            }
        }

        sort($ids_php);
        $this->assertSame($ids_php, $ids_sql, 'Paridad: el scope SQL y el oráculo PHP devuelven el mismo conjunto, con la marca adentro.');
    }

    /**
     * El gemelo PHP del gate de la IA, directo: con la marca en el último mensaje del lead no hay
     * nada sin responder; con la marca en uno anterior, sí.
     *
     * @return void
     */
    public function test_has_unanswered_lead_messages_respeta_la_marca(): void
    {
        $lead    = $this->crear_lead('Gemelo PHP');
        $primero = $this->crear_mensaje($lead, ['content' => 'Gracias']);
        $segundo = $this->crear_mensaje($lead, ['content' => '¿Y el precio?']);

        $this->assertTrue(LeadConversationAiState::has_unanswered_lead_messages($lead->fresh()->load('messages')));

        $lead->update(['atendido_hasta_message_id' => $primero->id]);
        $this->assertTrue(LeadConversationAiState::has_unanswered_lead_messages($lead->fresh()->load('messages')), 'El segundo sigue sin responder.');

        $lead->update(['atendido_hasta_message_id' => $segundo->id]);
        $this->assertFalse(LeadConversationAiState::has_unanswered_lead_messages($lead->fresh()->load('messages')), 'Marcado hasta el último: nada sin responder.');
    }
}
