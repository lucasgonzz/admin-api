<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET/PUT settings/lead-demo` (panel de Cuenta), dos cosas del 11/9/2026:
 *
 *  1. El campo nuevo `bloqueo_real_minutos` (default 180) — separado a propósito de
 *     `duracion_minutos`, que sigue siendo lo único que se comunica al lead.
 *  2. Regresión del hallazgo de paso: `recordatorio_silencio_minutos`, `demo_directa_no_show_minutos`
 *     y `check_ingreso_silencio_minutos` (misión demo-agendado-directo, 10/9/2026) ya los mandaba
 *     admin-spa pero el validador los descartaba en silencio -- ahora se persisten de verdad.
 */
class LeadDemoSettingsBloqueoRealTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'lead-demo-settings-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Payload PUT completo y válido, con los overrides que cada test necesite.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload_valido(array $overrides = []): array
    {
        return array_merge([
            'duracion_minutos'                    => 60,
            'setup_minutos_antes'                 => 15,
            'gracia_minutos_post'                 => 10,
            'recordatorio_minutos_antes'          => 15,
            'recordatorio_manana_hora'            => '09:00',
            'check_ingreso_minutos_post'           => 10,
            'resumen_minutos_antes_fin'            => 10,
            'duracion_llamada_closer_minutos'      => 30,
            'frecuencia_slots_minutos'             => 30,
            'llamada_debe_terminar_en_horario'     => false,
            'ingreso_timeout_minutos'              => 15,
            'fin_seguimiento_minutos'              => 10,
            'fin_timeout_minutos'                  => 25,
            'pendiente_ingreso_horas_timeout'      => 24,
            'pendiente_terminar_timeout_minutos'   => 120,
        ], $overrides);
    }

    /**
     * GET sin nada guardado todavía: el default de bloqueo_real_minutos es 180.
     *
     * @return void
     */
    public function test_get_devuelve_el_default_de_bloqueo_real_en_180(): void
    {
        $this->autenticar();

        $respuesta = $this->getJson('/api/admin/settings/lead-demo');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('bloqueo_real_minutos', 180);
        $respuesta->assertJsonPath('duracion_minutos', 60);
    }

    /**
     * PUT persiste bloqueo_real_minutos de verdad (no solo lo devuelve en el JSON): se relee con un
     * GET aparte para confirmar que quedó en `admin_settings`, no en una respuesta que miente.
     *
     * @return void
     */
    public function test_put_persiste_bloqueo_real_minutos(): void
    {
        $this->autenticar();

        $respuesta = $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['bloqueo_real_minutos' => 240]));

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('bloqueo_real_minutos', 240);
        $this->assertSame('240', AdminSetting::get(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS));

        $relectura = $this->getJson('/api/admin/settings/lead-demo');
        $relectura->assertJsonPath('bloqueo_real_minutos', 240);
    }

    /**
     * Sin mandar bloqueo_real_minutos (SPA viejo): el PUT no falla y el valor guardado no se toca —
     * mismo criterio "isset" que el resto de los campos opcionales de esta clase.
     *
     * @return void
     */
    public function test_put_sin_bloqueo_real_minutos_no_rompe_y_no_lo_borra(): void
    {
        $this->autenticar();
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '200');

        $payload = $this->payload_valido();
        unset($payload['bloqueo_real_minutos']);

        $respuesta = $this->putJson('/api/admin/settings/lead-demo', $payload);

        $respuesta->assertStatus(200);
        $this->assertSame('200', AdminSetting::get(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS), 'Un PUT sin la clave no puede borrar el valor ya guardado.');
    }

    /**
     * 🔴 Regresión del hallazgo de paso: los tres campos de la misión demo-agendado-directo que el
     * validador descartaba en silencio ahora se persisten de verdad.
     *
     * @return void
     */
    public function test_put_persiste_los_tres_campos_que_antes_se_descartaban_en_silencio(): void
    {
        $this->autenticar();

        $respuesta = $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'recordatorio_silencio_minutos'    => 45,
            'demo_directa_no_show_minutos'     => 90,
            'check_ingreso_silencio_minutos'   => 20,
        ]));

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('recordatorio_silencio_minutos', 45);
        $respuesta->assertJsonPath('demo_directa_no_show_minutos', 90);
        $respuesta->assertJsonPath('check_ingreso_silencio_minutos', 20);

        $this->assertSame('45', AdminSetting::get(LeadDemoSettings::KEY_RECORDATORIO_SILENCIO_MINUTOS));
        $this->assertSame('90', AdminSetting::get(LeadDemoSettings::KEY_DEMO_DIRECTA_NO_SHOW_MINUTOS));
        $this->assertSame('20', AdminSetting::get(LeadDemoSettings::KEY_CHECK_INGRESO_SILENCIO_MINUTOS));
    }
}
