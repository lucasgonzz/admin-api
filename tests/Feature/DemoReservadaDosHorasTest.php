<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Database\Seeders\DemoVentanaDeDosHorasSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La reserva de la demo pasa a DOS HORAS y el agente lo dice con esas palabras (misión
 * demo-dos-horas-y-experiencia, 13/10/2026).
 *
 * Tres cosas, que son tres piezas de un mismo cambio:
 *
 *  1. Los defaults: `demo_ventana_extendida_max_horas` 6 → 2 y `demo_bloqueo_real_minutos`
 *     180 → 120, con `demo_duracion_minutos` quieto en 60 (mide otra cosa y no se tocó).
 *  2. El seeder que los fija en una base que ya tiene las filas escritas, que es el único caso que
 *     importa en producción.
 *  3. La frase que `LeadAiService::build_demo_directa_context()` le pone al agente: los dos números
 *     salen de las settings, van en LETRAS, y la hora de fin es la real del turno.
 *
 * Lo que NO se prueba acá y vive en otro archivo: que `instancia_libre()` descarte la instancia con
 * un vecino (`DemoDirectaTest`) y que el no-show conserve el turno (`CheckDemoIngresoTimeoutTest`).
 */
class DemoReservadaDosHorasTest extends TestCase
{
    use DatabaseTransactions;

    /** El "ahora" de los casos de la frase: un martes a las 10 en punto. */
    const AHORA = '2026-10-13 10:00:00';

    /** Zona horaria de todo el ciclo de demo. */
    const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* 1 — los defaults                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Sin nada escrito en `admin_settings`, los getters devuelven los valores de esta misión: la
     * reserva en 2 horas y el bloqueo real en 120 minutos (los dos son el mismo lapso, a propósito:
     * el techo de la ventana y el piso del bloqueo tienen que cerrar juntos).
     *
     * Y `demo_duracion_minutos` sigue en 60: es lo que gobierna el check de fin, el hold del closer,
     * el resumen y la grilla de la dinámica actual. Se afirma acá justamente porque es lo que NO
     * cambió, y confundirlos sería mover el ciclo entero de la demo.
     *
     * @return void
     */
    public function test_los_defaults_son_dos_horas_y_ciento_veinte_minutos(): void
    {
        AdminSetting::where('key', LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS)->delete();
        AdminSetting::where('key', LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS)->delete();
        AdminSetting::where('key', LeadDemoSettings::KEY_DURACION_MINUTOS)->delete();

        $this->assertSame(2, LeadDemoSettings::get_ventana_extendida_max_horas(), 'La reserva de la instancia son dos horas (era 6).');
        $this->assertSame(120, LeadDemoSettings::get_bloqueo_real_minutos(), 'El bloqueo real son 120 minutos (era 180): las mismas dos horas.');
        $this->assertSame(60, LeadDemoSettings::get_duracion_minutos(), 'La duración NO se tocó: sigue en 60.');
    }

    /**
     * El default nuevo se ve en la ventana que de verdad se le arma al lead: un "sí" a las 10:00
     * arranca a las 10:10 y termina a las 12:10, no a las 16:10.
     *
     * @return void
     */
    public function test_la_ventana_de_la_demo_directa_dura_dos_horas(): void
    {
        AdminSetting::where('key', LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS)->delete();
        Carbon::setTestNow(Carbon::parse(self::AHORA, self::TZ));

        $ventana = (new \App\Services\DemoDirectaService())->ventana_desde();

        $this->assertSame('10:10', $ventana['inicio']->format('H:i'));
        $this->assertSame('12:10', $ventana['fin']->format('H:i'));
        $this->assertTrue($ventana['cabe']);
    }

    /* ------------------------------------------------------------------ */
    /* 2 — el seeder de producción                                           */
    /* ------------------------------------------------------------------ */

    /**
     * El caso real: una base con las filas viejas escritas (6 horas y 180 minutos), que es
     * exactamente donde cambiar el default en código no alcanza. El seeder las pisa, y correrlo dos
     * veces deja lo mismo (idempotente). `demo_duracion_minutos` queda como estaba.
     *
     * @return void
     */
    public function test_el_seeder_fija_los_dos_valores_y_es_idempotente(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '180');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');

        $this->seed(DemoVentanaDeDosHorasSeeder::class);

        $this->assertSame('2', (string) AdminSetting::get(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS));
        $this->assertSame('120', (string) AdminSetting::get(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS));
        $this->assertSame('60', (string) AdminSetting::get(LeadDemoSettings::KEY_DURACION_MINUTOS), 'El seeder no puede tocar la duración.');

        /* Segunda corrida: mismo resultado, y una sola fila por clave. */
        $this->seed(DemoVentanaDeDosHorasSeeder::class);

        $this->assertSame('2', (string) AdminSetting::get(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS));
        $this->assertSame('120', (string) AdminSetting::get(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS));
        $this->assertSame(1, AdminSetting::where('key', LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS)->count());
        $this->assertSame(1, AdminSetting::where('key', LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS)->count());
    }

    /* ------------------------------------------------------------------ */
    /* 3 — la frase del agente                                               */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 Con la demo YA asignada, la frase dice "dos horas" y "hasta las 12:00", que es el
     * `demo_end_time` real del turno — no un número escrito a mano.
     *
     * Y NO puede decir "2 hs": `LeadMessage::texto_menciona_una_hora()` retiene para revisión humana
     * el mensaje que nombra una hora con el sufijo pegado al número, así que un "2 hs" copiado al
     * mensaje del lead lo frenaría. Tampoco puede quedar la frase vieja ("una hora disponible"), que
     * le pedía al lead reservarse una hora mientras el sistema le reservaba seis.
     *
     * @return void
     */
    public function test_la_frase_usa_las_settings_y_el_fin_real_de_la_demo_asignada(): void
    {
        $this->fijar_settings_de_la_mision();
        Carbon::setTestNow(Carbon::parse(self::AHORA, self::TZ));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $lead->status          = 'demo_agendada';
        $lead->demo_id         = $demo->id;
        $lead->demo_date       = '2026-10-13';
        $lead->demo_start_time = '10:00';
        $lead->demo_end_time   = '12:00';
        $lead->demo_flexible   = true;
        $lead->save();

        $bloque = $this->contexto_de_demo_directa($lead->refresh());

        $this->assertStringContainsString('dos horas', $bloque, 'El tope de la reserva va en letras y sale de la setting.');
        $this->assertStringContainsString('menos de una hora', $bloque, 'La duración que se comunica sale de get_duracion_minutos().');
        $this->assertStringContainsString('hasta las 12:00', $bloque, 'La hora de fin es la del turno real.');
        $this->assertStringContainsString('para que la recorras tranquilo', $bloque);

        $this->assertStringNotContainsString('2 hs', $bloque, 'Un "2 hs" en el mensaje al lead lo retiene para revisión humana.');
        $this->assertStringNotContainsString('2hs', $bloque);
        $this->assertStringNotContainsString('2 horas', $bloque, 'Las horas van en letras, nunca en dígitos.');
        $this->assertStringNotContainsString('una hora disponible', $bloque, 'La frase vieja pedía al lead reservarse una hora: salió.');

        /* La línea de estado que ya existía se mantiene: no la reemplaza la frase nueva. */
        $this->assertStringContainsString('arranca a las 10:00 y queda abierta hasta las 12:00', $bloque);
    }

    /**
     * Sin demo asignada todavía, la hora sale de la ventana PREVISTA (`elegir_prevista()['fin']`):
     * un "sí" a las 10:00 se reserva hasta las 12:10.
     *
     * @return void
     */
    public function test_sin_demo_asignada_la_hora_sale_de_la_ventana_prevista(): void
    {
        $this->fijar_settings_de_la_mision();
        Carbon::setTestNow(Carbon::parse(self::AHORA, self::TZ));

        $this->crear_demo();
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $bloque = $this->contexto_de_demo_directa($lead);

        $this->assertStringContainsString('dos horas', $bloque);
        $this->assertStringContainsString('hasta las 12:10', $bloque, 'Es el fin de la ventana prevista: 10:10 + dos horas.');
        $this->assertStringNotContainsString('2 hs', $bloque);
    }

    /**
     * Sin ninguna instancia cargada no hay turno ni previsión, así que no hay hora: la frase se arma
     * sin ella. 🔴 Nunca se inventa una hora para completar la oración.
     *
     * @return void
     */
    public function test_sin_turno_ni_prevista_la_frase_va_sin_hora(): void
    {
        $this->fijar_settings_de_la_mision();
        Carbon::setTestNow(Carbon::parse(self::AHORA, self::TZ));

        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $bloque = $this->contexto_de_demo_directa($lead);

        $this->assertStringContainsString('dos horas, para que la recorras tranquilo', $bloque, 'Sin hora real, la frase cierra sin la hora.');
        $this->assertStringNotContainsString('hasta las ', $bloque);
    }

    /**
     * Cerca de la medianoche la ventana se recorta a las 23:59 (`ventana_desde()`): un "sí" a las
     * 22:30 se reserva de 22:40 a 23:59, una hora y diecinueve minutos. Decir "dos horas" ahí sería
     * falso, así que la frase dice sólo hasta cuándo (15/10/2026).
     *
     * @return void
     */
    public function test_con_la_ventana_recortada_por_la_medianoche_no_promete_dos_horas(): void
    {
        $this->fijar_settings_de_la_mision();
        Carbon::setTestNow(Carbon::parse('2026-10-13 22:30:00', self::TZ));

        $this->crear_demo();
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $bloque = $this->contexto_de_demo_directa($lead);

        $this->assertStringContainsString('la tenés reservada hasta las 23:59, para que la recorras tranquilo', $bloque);
        $this->assertStringNotContainsString('reservada dos horas', $bloque, 'La ventana recortada dura menos de dos horas.');
    }

    /**
     * Y la frase sigue a la setting: con la reserva en 3 horas dice "tres horas", no "dos".
     *
     * @return void
     */
    public function test_la_frase_acompana_a_la_setting(): void
    {
        $this->fijar_settings_de_la_mision();
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '3');
        Carbon::setTestNow(Carbon::parse(self::AHORA, self::TZ));

        $this->crear_demo();
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $bloque = $this->contexto_de_demo_directa($lead);

        $this->assertStringContainsString('tres horas', $bloque);
        $this->assertStringNotContainsString('dos horas', $bloque);
        $this->assertStringContainsString('hasta las 13:10', $bloque);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Las settings del ciclo de demo en los valores de esta misión, escritas explícitamente: estos
     * tests miden la FRASE, no el default (eso lo mide
     * test_los_defaults_son_dos_horas_y_ciento_veinte_minutos()).
     *
     * @return void
     */
    private function fijar_settings_de_la_mision(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
    }

    /**
     * El bloque DEMO DIRECTA del contexto del agente. El método es privado porque nadie fuera del
     * armado del prompt tiene por qué llamarlo; se lo invoca por reflexión, como ya hacen otros
     * tests de este repo con los armadores de contexto.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto_de_demo_directa(Lead $lead): string
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'build_demo_directa_context');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(new LeadAiService(), $lead);
    }

    /**
     * Una instancia de demo con sus cuatro URLs.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-reserva.test';
        $demo->erp_api_url       = 'https://demo-reserva-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-reserva.test';
        $demo->ecommerce_api_url = 'https://tienda-reserva-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead de la dinámica nueva con teléfono (de ahí sale la clave corta de su página, sin la cual
     * el bloque devuelve el aviso de intervención humana en vez del contexto).
     *
     * @return Lead
     */
    private function crear_lead_de_la_dinamica_nueva(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '5493511234567';
        $lead->status       = 'calificado';
        $lead->save();

        /* Después del save: el hook `creating` estampa la dinámica por defecto. */
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }
}
