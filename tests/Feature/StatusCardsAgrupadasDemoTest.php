<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /lead/status-cards` con la tarjeta "Demo" agrupada (misión demo-v2-estados-automaticos,
 * 4/9/2026): reemplaza la tarjeta individual `demo_agendada` por una que suma
 * demo_agendada + demo_pendiente_de_ingreso + demo_en_curso bajo un solo total rojo.
 */
class StatusCardsAgrupadasDemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        return Admin::create([
            'name'     => 'Admin de prueba',
            'email'    => 'admin-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Las 4 tarjetas, en orden, con la del medio agrupada.
     */
    public function test_devuelve_cuatro_tarjetas_en_orden_con_la_demo_agrupada(): void
    {
        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/lead/status-cards')
            ->assertStatus(200);

        $cards = $response->json('cards');

        $this->assertCount(4, $cards);
        $this->assertSame('calificado', $cards[0]['value']);
        $this->assertSame('solicita_disponibilidad', $cards[1]['value']);
        $this->assertSame('demo', $cards[2]['value']);
        $this->assertSame('closer_activo', $cards[3]['value']);

        $this->assertSame('Demo', $cards[2]['text']);
        $this->assertSame('#dc3545', $cards[2]['color']);
        $this->assertSame(
            ['demo_agendada', 'demo_pendiente_de_ingreso', 'demo_en_curso'],
            $cards[2]['slugs']
        );
    }

    /**
     * El total de la tarjeta "Demo" es la suma exacta de los tres sub-estados, sin contar leads de
     * otros estados (incluido demo_pendiente_de_terminar, que queda AFUERA del grupo a propósito).
     */
    public function test_el_total_de_la_tarjeta_demo_suma_los_tres_sub_estados(): void
    {
        $this->crear_lead('demo_agendada');
        $this->crear_lead('demo_agendada');
        $this->crear_lead('demo_pendiente_de_ingreso');
        $this->crear_lead('demo_en_curso');
        // No deben contar:
        $this->crear_lead('calificado');
        $this->crear_lead('demo_pendiente_de_terminar');
        $this->crear_lead('closer_activo');

        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/lead/status-cards')
            ->assertStatus(200);

        $demo_card = collect($response->json('cards'))->firstWhere('value', 'demo');

        $this->assertSame(4, $demo_card['total']);
    }

    /**
     * `sin_responder` de la tarjeta agrupada cuenta leads con mensajes sin responder en
     * CUALQUIERA de los tres sub-estados, sumados bajo un solo número.
     */
    public function test_sin_responder_de_la_tarjeta_demo_suma_los_tres_sub_estados(): void
    {
        $con_pendiente_1 = $this->crear_lead('demo_agendada');
        LeadMessage::create([
            'lead_id' => $con_pendiente_1->id, 'sender' => 'lead', 'status' => 'enviado',
            'content' => 'Hola, tengo una duda', 'is_followup' => false,
        ]);

        $con_pendiente_2 = $this->crear_lead('demo_en_curso');
        LeadMessage::create([
            'lead_id' => $con_pendiente_2->id, 'sender' => 'lead', 'status' => 'enviado',
            'content' => '¿Cómo hago esto?', 'is_followup' => false,
        ]);

        // Sin mensajes sin responder: no debe sumar.
        $this->crear_lead('demo_pendiente_de_ingreso');

        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/lead/status-cards')
            ->assertStatus(200);

        $demo_card = collect($response->json('cards'))->firstWhere('value', 'demo');

        $this->assertSame(2, $demo_card['sin_responder']);
    }
}
