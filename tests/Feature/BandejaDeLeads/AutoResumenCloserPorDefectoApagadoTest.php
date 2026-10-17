<?php

namespace Tests\Feature\BandejaDeLeads;

use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El resumen automático para el closer arranca apagado (misión flujo-lead-landing-closer, decisión
 * D7 de Lucas, 17/10/2026; migración 2026_10_17_200100_auto_resumen_closer_default_false).
 *
 * Hasta esa migración `leads.auto_resumen_closer` nacía en `true` y `leads:generate-demo-summary`
 * generaba el resumen solo. Ahora es a pedido: el default de la columna es `false`, y el toggle por
 * lead sigue existiendo para quien lo quiera prendido.
 */
class AutoResumenCloserPorDefectoApagadoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Un lead nuevo nace con el resumen automático apagado.
     *
     * @return void
     */
    public function test_un_lead_nuevo_nace_con_el_resumen_automatico_apagado(): void
    {
        $lead = Lead::create([
            'contact_name' => 'Lead nuevo',
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'nuevo',
        ]);

        $this->assertFalse($lead->fresh()->auto_resumen_closer, 'El default de la columna tiene que ser false (D7).');
    }

    /**
     * El default vive en el esquema, no solo en el modelo: un INSERT que no nombre la columna
     * (un seeder, un script) también la deja en 0.
     *
     * @return void
     */
    public function test_el_default_de_la_columna_es_cero(): void
    {
        $columna = DB::select("SHOW COLUMNS FROM leads LIKE 'auto_resumen_closer'");

        $this->assertCount(1, $columna, 'La columna existe.');
        $this->assertSame('0', (string) $columna[0]->Default, 'El DEFAULT del esquema es 0.');
    }

    /**
     * El toggle por lead sigue andando: quien lo quiera prendido lo prende.
     *
     * @return void
     */
    public function test_el_toggle_por_lead_sigue_pudiendo_prenderlo(): void
    {
        $lead = Lead::create([
            'contact_name'        => 'Quiere el resumen',
            'phone'               => '54911' . random_int(1000000, 9999999),
            'status'              => 'demo_agendada',
            'auto_resumen_closer' => true,
        ]);

        $this->assertTrue($lead->fresh()->auto_resumen_closer);
    }
}
