<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    /**
     * 🔴 La carrera real que arregla esta misión (demo-v2-estados-automaticos, 4/9/2026): el
     * comando arma sus candidatos en memoria con un solo `->get()`, y recién en el loop hace
     * llamadas de red (Google Calendar + WhatsApp) que pueden tardar segundos entre un candidato y
     * el siguiente. Si en el medio llega el evento real `demo.ingreso`
     * (`DemoEventosController::avanzar_pipeline_por_ingreso_real()`, un request HTTP totalmente
     * aparte) y avanza a ESTE MISMO lead a `demo_en_curso`, el UPDATE del comando tiene que
     * detectarlo y saltear el lead -- sin pisarlo de vuelta a `demo_pendiente_de_ingreso` y sin
     * disparar la notificación de "no ingresó" sobre un lead que sí está adentro.
     *
     * Se simula la carrera con un `DB::listen()`: apenas el comando ejecuta el SELECT que arma la
     * colección de candidatos (el lead todavía en memoria con status `demo_agendada`), pero ANTES
     * de que el loop llegue a su propio UPDATE, se aplica por fuera el mismo cambio que aplicaría
     * `avanzar_pipeline_por_ingreso_real()` sobre la fila real. Es la ventana exacta que describe
     * el bug: la colección en memoria queda vieja, la base ya no.
     */
    public function test_carrera_con_ingreso_real_no_pisa_el_avance(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        // Experiencia nueva porque es la única que puede llegar a demo_en_curso vía el evento real
        // demo.ingreso (usa_experiencia_demo_nueva()) -- demo_flexible sigue en false, así que
        // igual queda dentro de la ventana normal del timeout (no la extendida) y es candidato.
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addMinutes(20));

        $carrera_ya_aplicada = false;
        DB::listen(function ($query) use ($lead, &$carrera_ya_aplicada) {
            if ($carrera_ya_aplicada) {
                return;
            }
            $sql = strtolower($query->sql);
            if (strpos($sql, 'select') !== 0 || strpos($sql, 'from `leads`') === false) {
                return;
            }

            // Este es el SELECT de candidatos: el lead ya está en memoria con status
            // demo_agendada. Antes de que el loop del comando llegue a su UPDATE, otro camino
            // completamente aparte (el evento real de ingreso) avanza la fila de verdad.
            $carrera_ya_aplicada = true;
            DB::table('leads')->where('id', $lead->id)->update([
                'status'                     => 'demo_en_curso',
                'demo_ingreso_confirmado'    => true,
                'demo_ingreso_confirmado_at' => Carbon::now(),
            ]);
        });

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status, 'El comando no puede pisar de vuelta a demo_pendiente_de_ingreso un lead que ya entró de verdad.');
        $this->assertFalse((bool) $lead->demo_no_ingreso_notificado, 'No corresponde marcar el anti-duplicado de "no ingresó" sobre un lead que sí ingresó.');
    }
}
