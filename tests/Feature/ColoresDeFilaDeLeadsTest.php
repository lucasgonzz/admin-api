<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los dos colores de la grilla de leads, tal como los definió Lucas el 2/9/2026:
 *
 * - **AMARILLO** (`row_warning`): hay alguien esperando una respuesta. O porque el lead habló
 *   último y no le llegó nada, o porque hay un mensaje por verificar listo para salir.
 * - **ROJO**: hubo un error de entrega — del sistema o de Meta — y por lo tanto se puede
 *   REINTENTAR. Un lead marcado como "ya no recibe mensajes" queda afuera: eso no se reintenta y
 *   pintarlo sería ruido permanente.
 *
 * Por qué existe este test y no alcanza con los de la tarjeta: el amarillo lo calcula
 * `scopeWithUnreadLeadMessagesCount` y la tarjeta lo calcula `scopeRequiereRevision`. Son dos
 * consultas distintas sobre la misma idea, y hasta el 2/9 no coincidían — la tarjeta contaba 2 y la
 * grilla no pintaba ninguna fila. Acá se fija que el amarillo cubra el mismo "sin responder" que
 * cuenta la tarjeta.
 */
class ColoresDeFilaDeLeadsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin autenticado para pegarle al listado.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        return Admin::create([
            'name'     => 'Setter de prueba',
            'email'    => 'colores-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Lead mínimo, en un estado activo cualquiera.
     *
     * @param string $nombre
     *
     * @return Lead
     */
    private function crear_lead(string $nombre): Lead
    {
        return Lead::create([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);
    }

    /**
     * Mensaje del hilo. Por defecto, entrante del lead.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $campos
     *
     * @return LeadMessage
     */
    private function crear_mensaje(Lead $lead, array $campos = []): LeadMessage
    {
        return LeadMessage::create(array_merge([
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'content'         => '¿Cuánto sale?',
            'status'          => 'enviado',
            'kind'            => 'text',
            'is_status_event' => false,
            'is_error'        => false,
            'sent_at'         => now(),
        ], $campos));
    }

    /**
     * Saliente que efectivamente salió por WhatsApp.
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
     * Trae el lead del listado real de la grilla, con los flags de fila ya calculados.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function fila(Lead $lead): array
    {
        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/lead?per_page=200');
        $response->assertStatus(200);

        foreach ($response->json('models') as $fila) {
            if ((int) $fila['id'] === (int) $lead->id) {
                return $fila;
            }
        }

        $this->fail('El lead #' . $lead->id . ' no vino en el listado.');
    }

    /**
     * 🔴 El caso que reportó Lucas: el lead habló último, nadie le contestó, y la fila tiene que
     * salir amarilla. Antes solo se ponía amarilla si había una sugerencia por verificar.
     *
     * @return void
     */
    public function test_el_lead_que_hablo_ultimo_y_no_tuvo_respuesta_va_en_amarillo(): void
    {
        $lead = $this->crear_lead('Sin responder');
        $this->crear_mensaje($lead);

        $this->assertTrue((bool) $this->fila($lead)['row_warning']);
    }

    /**
     * Contestado de verdad (el saliente salió por WhatsApp): la fila deja de estar amarilla.
     *
     * @return void
     */
    public function test_contestado_de_verdad_apaga_el_amarillo(): void
    {
        $lead = $this->crear_lead('Contestado');
        $this->crear_mensaje($lead);
        $this->crear_saliente_entregado($lead);

        $this->assertFalse((bool) $this->fila($lead)['row_warning']);
    }

    /**
     * Una respuesta que NO salió no apaga nada: el lead sigue esperando.
     *
     * @return void
     */
    public function test_una_respuesta_que_no_salio_deja_la_fila_amarilla(): void
    {
        $lead = $this->crear_lead('Respuesta que no salio');
        $this->crear_mensaje($lead);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'content' => 'Te paso los planes']);

        $this->assertTrue((bool) $this->fila($lead)['row_warning']);
    }

    /**
     * La otra mitad del amarillo, que ya existía: hay un mensaje por verificar esperando salir.
     *
     * @return void
     */
    public function test_una_sugerencia_por_verificar_tambien_va_en_amarillo(): void
    {
        $lead = $this->crear_lead('Con sugerencia por verificar');
        $this->crear_mensaje($lead);
        $this->crear_saliente_entregado($lead);
        $this->crear_mensaje($lead, [
            'sender'                => 'sistema',
            'status'                => 'sugerido',
            'content'               => 'Te propongo el jueves',
            'requiere_verificacion' => true,
        ]);

        $this->assertTrue((bool) $this->fila($lead)['row_warning']);
    }

    /**
     * El rojo: un error de entrega sin resolver cuenta, y la marca de inalcanzable lo apaga.
     *
     * `failed_send_count` es el número que la grilla usa para el rojo; lo que este test fija es que
     * el error se cuente y que la marca sea lo que decide si esa fila se pinta.
     *
     * @return void
     */
    public function test_la_entrega_fallida_cuenta_y_la_marca_de_inalcanzable_saca_del_rojo(): void
    {
        $lead = $this->crear_lead('Entrega fallida');
        $this->crear_saliente_entregado($lead, ['whatsapp_delivery_status' => 'fallido']);

        $fila = $this->fila($lead);
        $this->assertSame(1, (int) $fila['failed_send_count'], 'La entrega fallida tiene que contar para el rojo.');
        $this->assertNull($fila['no_recibe_mensajes_at'], 'Sin marcar, el lead sigue siendo candidato al rojo.');

        /* Se lo marca como que ya no recibe mensajes: la grilla deja de pintarlo. */
        $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->putJson('/api/admin/lead/' . $lead->id . '/toggle-no-recibe-mensajes')
            ->assertStatus(200);

        $fila = $this->fila($lead);
        $this->assertNotNull($fila['no_recibe_mensajes_at'], 'La marca tiene que quedar puesta.');
        $this->assertSame(1, (int) $fila['failed_send_count'], 'El error no se borra: lo que cambia es que deja de pintar.');
    }

    /**
     * La marca es un toggle: un número puede volver a andar.
     *
     * @return void
     */
    public function test_la_marca_de_inalcanzable_se_puede_levantar(): void
    {
        $lead = $this->crear_lead('Recuperado');
        $admin = $this->admin_autenticado();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/lead/' . $lead->id . '/toggle-no-recibe-mensajes')
            ->assertStatus(200);
        $this->assertNotNull($lead->fresh()->no_recibe_mensajes_at);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/lead/' . $lead->id . '/toggle-no-recibe-mensajes')
            ->assertStatus(200);
        $this->assertNull($lead->fresh()->no_recibe_mensajes_at);
    }

    /**
     * Marcar deja rastro en la conversación, como cualquier acción administrativa del panel, y el
     * rastro es un evento de estado (no ensucia last_message_at ni el badge de sin leer).
     *
     * @return void
     */
    public function test_marcar_deja_el_evento_en_la_conversacion(): void
    {
        $lead = $this->crear_lead('Con rastro');

        $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->putJson('/api/admin/lead/' . $lead->id . '/toggle-no-recibe-mensajes', ['motivo' => 'Bloqueó el número'])
            ->assertStatus(200);

        $evento = LeadMessage::where('lead_id', $lead->id)->where('is_status_event', true)->latest('id')->first();
        $this->assertNotNull($evento, 'Tendría que quedar el evento del marcado.');
        $this->assertStringContainsString('ya no recibe mensajes', (string) $evento->content);
        $this->assertSame('Bloqueó el número', (string) $lead->fresh()->no_recibe_mensajes_motivo);
    }

    /**
     * 🔴 La grilla y la tarjeta tienen que hablar del mismo conjunto.
     *
     * La tarjeta cuenta razón A (sin responder) + razón B (error sin resolver). La grilla pinta
     * amarillo la razón A y rojo la razón B. Si un lead cuenta en la tarjeta, tiene que tener
     * ALGÚN color: lo contrario es el defecto que Lucas reportó — "dice 2 y veo una sola fila".
     *
     * @return void
     */
    public function test_todo_lead_que_cuenta_en_la_tarjeta_tiene_algun_color(): void
    {
        $sin_responder = $this->crear_lead('Cuenta por razon A');
        $this->crear_mensaje($sin_responder);

        $con_error = $this->crear_lead('Cuenta por razon B');
        $this->crear_saliente_entregado($con_error, ['whatsapp_delivery_status' => 'fallido']);

        /* Y el que está marcado como inalcanzable no cuenta ni pinta: si contara sin pintar,
           volvería exactamente el "dice 2 y veo una sola fila". */
        $inalcanzable = $this->crear_lead('Marcado como inalcanzable');
        $this->crear_saliente_entregado($inalcanzable, ['whatsapp_delivery_status' => 'fallido']);
        $inalcanzable->update(['no_recibe_mensajes_at' => now()]);

        $ids_tarjeta = Lead::query()->requiereRevision(true)->pluck('id')->all();

        $this->assertNotContains(
            $inalcanzable->id,
            $ids_tarjeta,
            'Un lead marcado como inalcanzable no puede contar en la tarjeta: la grilla no lo pinta.'
        );

        foreach ([$sin_responder, $con_error] as $lead) {
            $this->assertContains($lead->id, $ids_tarjeta, 'El lead #' . $lead->id . ' tiene que contar en la tarjeta.');

            $fila = $this->fila($lead);
            $tiene_color = ((bool) $fila['row_warning']) || ((int) $fila['failed_send_count'] > 0);
            $this->assertTrue(
                $tiene_color,
                'El lead #' . $lead->id . ' cuenta en la tarjeta pero la grilla no lo pinta de ningún color.'
            );
        }
    }
}
