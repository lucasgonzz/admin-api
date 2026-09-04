<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `leads:check-demo-ingreso-timeout`, reescrito en la misión demo-v2-estados-automaticos
 * (4/9/2026) para leer `demo_agendada` directo (ya no hay estado intermedio `ingresando_demo`)
 * y medir el timeout desde `demo_start_time`, sin el corrimiento por último mensaje del lead que
 * tenía sentido cuando el comando viejo mandaba un mensaje de WhatsApp.
 */
class CheckDemoIngresoTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Lead con demo agendada, listo para ser candidato del comando (todos los gates en su
     * posición "pasa"), salvo lo que el test pise después.
     *
     * @param Carbon $demo_datetime Momento de inicio de la demo.
     *
     * @return Lead
     */
    private function crear_lead_candidato(Carbon $demo_datetime): Lead
    {
        $lead                            = new Lead();
        $lead->uuid                      = (string) Str::uuid();
        $lead->contact_name              = 'Lead de prueba';
        $lead->status                    = 'demo_agendada';
        $lead->demo_experiencia          = Lead::EXPERIENCIA_ACTUAL;
        $lead->demo_date                 = $demo_datetime->copy();
        $lead->demo_start_time           = $demo_datetime->format('H:i');
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_check_ingreso_demo   = true;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->demo_ingreso_confirmado   = false;
        $lead->demo_no_ingreso_notificado = false;
        $lead->demo_flexible             = false;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Deja el timeout de ingreso en un valor previsible.
     *
     * @param int $minutos
     *
     * @return void
     */
    private function fijar_timeout(int $minutos): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_INGRESO_TIMEOUT_MINUTOS, (string) $minutos);
    }

    /**
     * Vencido el timeout desde demo_start_time, sin confirmar: pasa a demo_pendiente_de_ingreso,
     * libera la reserva del closer (no-op sin closer_hold_event_id) y marca el anti-duplicado.
     */
    public function test_timeout_vencido_pasa_a_pendiente_de_ingreso(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);

        // 20 minutos después del inicio: el timeout de 15 ya venció.
        Carbon::setTestNow($inicio->copy()->addMinutes(20));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status);
        $this->assertTrue((bool) $lead->demo_no_ingreso_notificado);
    }

    /**
     * Todavía dentro de la ventana: no se toca.
     */
    public function test_dentro_de_la_ventana_no_se_toca(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);

        // 5 minutos después del inicio: todavía adentro del timeout de 15.
        Carbon::setTestNow($inicio->copy()->addMinutes(5));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_agendada', $lead->status);
        $this->assertFalse((bool) $lead->demo_no_ingreso_notificado);
    }

    /**
     * Ventana extendida (demo_flexible + demo_experiencia='nueva'): excluido del timeout aunque
     * esté larguísimo pasado el horario nominal, igual que antes de esta misión.
     */
    public function test_ventana_extendida_queda_excluida(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        $lead->demo_flexible     = true;
        $lead->demo_experiencia  = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addHours(3));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_agendada', $lead->status);
    }

    /**
     * 🔴 `demo_flexible` es una columna PREEXISTENTE (2/7/2026) que significa "no reservar ventana
     * de closer" y que Lucas marca a mano desde el panel -- no es lo mismo que la ventana extendida
     * de la dinámica nueva. Un lead de la dinámica ACTUAL con ese checkbox marcado tiene que seguir
     * cayendo en el timeout igual que uno sin marcar: la exclusión pide las DOS condiciones
     * (demo_flexible Y demo_experiencia='nueva'), no alcanza con la primera sola. Mismo caso que
     * protegía el extinto CheckDemoIngress, re-cubierto acá sobre el mecanismo nuevo.
     */
    public function test_flexible_manual_de_un_lead_actual_no_queda_excluido(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        $lead->demo_flexible    = true;
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addMinutes(20));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status);
    }

    /**
     * Un lead ya en demo_en_curso (ya entró de verdad, lo puso el evento demo.ingreso) ni siquiera
     * es candidato: el filtro por status = 'demo_agendada' lo saca de la query desde el vamos.
     */
    public function test_lead_ya_en_demo_en_curso_no_es_candidato(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        $lead->status                  = 'demo_en_curso';
        $lead->demo_ingreso_confirmado = true;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addHours(2));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status);
    }
}
