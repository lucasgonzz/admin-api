<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\DemoIngresoTokenService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `DemoIngresoTokenService::calcular_expiracion()` es el único lugar que calcula hasta cuándo sirve
 * el link de reingreso a la demo (decisión de Lucas, 11/9/2026): el vencimiento pasa a ser el mayor
 * entre "fin nominal + gracia" (lo que ya existía) e "inicio + bloqueo_real_minutos" (nuevo, 180 por
 * defecto). El motivo: `demo_duracion_minutos` sigue siendo la única que se comunica al lead ("una
 * hora"), así que no se podía tocar sin cambiar también lo que el lead escucha — de ahí la clave
 * nueva y separada.
 *
 * Cálculo puro sobre atributos de un `Lead` sin persistir (mismo patrón que DemoPathResolverTest):
 * `calcular_expiracion()` no toca la base a través del Lead, solo lee sus atributos castёados.
 */
class DemoIngresoTokenServiceBloqueoRealTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '180');
    }

    /**
     * Lead sin persistir con fecha/horario de demo.
     *
     * @param string|null $fecha
     * @param string|null $inicio
     * @param string|null $fin
     *
     * @return Lead
     */
    private function lead(?string $fecha, ?string $inicio, ?string $fin): Lead
    {
        $lead                   = new Lead();
        $lead->demo_date        = $fecha;
        $lead->demo_start_time  = $inicio;
        $lead->demo_end_time    = $fin;

        return $lead;
    }

    /**
     * El caso central del pedido: demo nominal de 15:00 a 16:00 (una hora, gracia 10). Antes el
     * link vencía a las 16:10; con el bloqueo real en 180 minutos tiene que valer hasta las 18:00
     * (15:00 + 180), bien más allá de lo que se le comunica al lead.
     *
     * @return void
     */
    public function test_el_vencimiento_llega_a_inicio_mas_bloqueo_real_no_a_fin_mas_gracia(): void
    {
        $lead = $this->lead('2026-09-08', '15:00', '16:00');

        $expira_at = (new DemoIngresoTokenService())->calcular_expiracion($lead);

        $this->assertSame(
            '2026-09-08 18:00:00',
            $expira_at->format('Y-m-d H:i:s'),
            'Con bloqueo_real=180, el vencimiento tiene que ser inicio (15:00) + 180 minutos, no fin (16:00) + gracia (10).'
        );
    }

    /**
     * Un `demo_end_time` extendido a mano (demo_flexible con rango amplio) más allá de las 3 horas
     * se sigue respetando: el piso del bloqueo real nunca ACORTA un fin manual más generoso.
     *
     * @return void
     */
    public function test_un_fin_manual_mas_alla_del_bloqueo_real_no_se_acorta(): void
    {
        $lead = $this->lead('2026-09-08', '15:00', '23:00');

        $expira_at = (new DemoIngresoTokenService())->calcular_expiracion($lead);

        $this->assertSame(
            '2026-09-08 23:10:00',
            $expira_at->format('Y-m-d H:i:s'),
            'El fin manual (23:00) + gracia (10) es mayor que inicio + bloqueo_real (18:00): tiene que ganar el manual.'
        );
    }

    /**
     * Sin fecha ni horario cargados (el Lead no tiene demo asignada todavía), el fallback fijo de 4
     * horas se mantiene intacto: la reestructuración del cálculo no lo puede haber roto.
     *
     * @return void
     */
    public function test_sin_fecha_ni_horario_sigue_cayendo_al_fallback_de_cuatro_horas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));

        $lead = $this->lead(null, null, null);

        $expira_at = (new DemoIngresoTokenService())->calcular_expiracion($lead);

        $this->assertSame('2026-09-08 16:00:00', $expira_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    /**
     * Con fecha y fin cargados pero sin `demo_start_time` (dato inconsistente, no debería pasar en
     * producción pero el método no puede romper): el piso del bloqueo real no se puede calcular sin
     * inicio, así que el vencimiento sale solo de fin + gracia, igual que antes de este cambio.
     *
     * @return void
     */
    public function test_sin_hora_de_inicio_el_vencimiento_sale_solo_de_fin_mas_gracia(): void
    {
        $lead = $this->lead('2026-09-08', null, '16:00');

        $expira_at = (new DemoIngresoTokenService())->calcular_expiracion($lead);

        $this->assertSame('2026-09-08 16:10:00', $expira_at->format('Y-m-d H:i:s'));
    }
}
