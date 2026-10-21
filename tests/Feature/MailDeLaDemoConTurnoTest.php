<?php

namespace Tests\Feature;

use App\Mail\Helpers\LeadDemoAccesoMailHelper;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\WhatsappConfig;
use App\Services\AsistenteDemoLeadService;
use App\Services\DemoDirectaService;
use App\Services\DemoTurnoTexto;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El mail de la demo con el TURNO, la instancia completa y el asistente (misión
 * mail-demo-turno-y-asistente, 16/10/2026).
 *
 * Lo que estos tests protegen, en el orden en que importa:
 *
 *  1. **Que la hora del mail sea la del turno asignado** (la misma que el agente le dijo por el chat y
 *     que muestra la página), no un reloj propio: un mail con una hora supuesta es peor que ninguno.
 *  2. **Que el asistente NO se prometa si su número dedicado no está listo.** El principal conserva
 *     la conversación comercial y el asistente se prueba en otro chat.
 *  3. **Que el mail viejo siga saliendo** para un lead sin turno (reenvío tardío) y para un llamador
 *     que arme el Mailable con los siete argumentos de antes.
 *  4. Que el mail diga qué es la instancia: sistema de gestión, tienda online y sistema de agentes.
 */
class MailDeLaDemoConTurnoTest extends TestCase
{
    use DatabaseTransactions;

    /** Zona de la demo. */
    const TZ = DemoDirectaService::TZ;

    /**
     * Fija el reloj en un viernes 16/10/2026 a las 15:00 (zona de la demo) y limpia lo que deja.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-16 15:00:00', self::TZ));

        // Lo que define cuándo vence un turno y cuánto se reserva.
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Demo de prueba con (o sin) tienda online.
     *
     * @param string $ecommerce_spa_url URL de la tienda, o '' para una instancia sin tienda.
     * @param string $api_key           Clave de la demo, o '' para una instancia sin clave.
     *
     * @return Demo
     */
    private function crear_demo(string $ecommerce_spa_url = 'https://demo-tienda.test', string $api_key = 'clave-de-la-demo'): Demo
    {
        $demo                    = new Demo();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = $ecommerce_spa_url;
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->api_key           = $api_key;
        $demo->save();

        return $demo;
    }

    /**
     * Lead con su demo asignada y el turno cargado.
     *
     * @param Demo        $demo   Instancia asignada.
     * @param string|null $fecha  Día del turno (Y-m-d).
     * @param string|null $inicio Hora de inicio ("HH:MM").
     * @param string|null $fin    Hora de fin ("HH:MM").
     * @param string|null $nombre Nombre completo del contacto.
     * @param string      $telefono Teléfono del lead.
     *
     * @return Lead
     */
    private function crear_lead(Demo $demo, ?string $fecha = '2026-10-16', ?string $inicio = '15:10', ?string $fin = '17:10', ?string $nombre = 'Guillermo González', string $telefono = '+5493417778899'): Lead
    {
        $lead                  = new Lead();
        $lead->phone           = $telefono;
        $lead->email           = 'guillermo@ejemplo.test';
        $lead->contact_name    = $nombre;
        $lead->status          = 'demo_agendada';
        $lead->demo_id         = $demo->id;
        $lead->demo_date       = $fecha;
        $lead->demo_start_time = $inicio;
        $lead->demo_end_time   = $fin;
        $lead->save();

        return $lead;
    }

    /**
     * Deja el número del asistente completo y listo para atender (el estado que tendrá cuando Lucas
     * lo dé de alta de verdad).
     *
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return WhatsappConfig
     */
    private function dejar_listo_el_asistente(array $campos = []): WhatsappConfig
    {
        $config = new WhatsappConfig();
        $config->forceFill(array_merge([
            'proposito'       => WhatsappConfig::PROPOSITO_ASISTENTE,
            'kapso_api_key'   => 'clave-kapso-del-asistente',
            'phone_number_id' => '5550001112223',
            'webhook_secret'  => 'secreto-del-asistente',
            'display_phone'   => '+1 201 555 0123',
            'is_active'       => true,
            'test_mode'       => false,
        ], $campos));
        $config->save();

        return $config;
    }

    /**
     * (1) El mail dice el día y la hora del turno asignado: asunto, tarjeta y recomendaciones.
     *
     * @return void
     */
    public function test_el_mail_dice_el_dia_y_la_hora_del_turno_asignado(): void
    {
        $lead = $this->crear_lead($this->crear_demo());

        $mail = LeadDemoAccesoMailHelper::build($lead);
        $html = $mail->render();

        $this->assertSame('Tu demo de ComercioCity — hoy a las 15:10', (string) $mail->subject);

        $this->assertStringContainsString('Tu demo está reservada.', $html);
        $this->assertStringContainsString('Hoy, viernes 16 de octubre', $html);
        $this->assertStringContainsString('15:10', $html);
        $this->assertStringContainsString('17:10', $html);
        $this->assertStringContainsString('reservada para vos dos horas', $html, 'Dos horas, en letras y de la ventana real (15:10 a 17:10).');
        $this->assertStringContainsString('menos de una hora', $html);
        $this->assertStringNotContainsString('2 hs', $html);
        $this->assertStringNotContainsString('2hs', $html);
    }

    /**
     * (1b) Un turno de mañana o de otro día se anuncia con su día, no con "hoy".
     *
     * @return void
     */
    public function test_un_turno_de_manana_y_de_otro_dia_dicen_su_dia(): void
    {
        $demo = $this->crear_demo();

        $manana = DemoTurnoTexto::de_lead($this->crear_lead($demo, '2026-10-17', '10:00', '12:00'));
        $this->assertSame('mañana a las 10:00', $manana['cuando_para_el_asunto']);
        $this->assertFalse($manana['es_hoy']);

        $lunes = DemoTurnoTexto::de_lead($this->crear_lead($demo, '2026-10-19', '09:30', '11:30'));
        $this->assertSame('el lunes 19 de octubre a las 09:30', $lunes['cuando_para_el_asunto']);
        $this->assertSame('lunes 19 de octubre', $lunes['fecha_larga']);
    }

    /**
     * (1c) Un turno recortado por la medianoche no promete "dos horas": solo dice hasta cuándo.
     *
     * @return void
     */
    public function test_un_turno_recortado_por_la_medianoche_no_promete_dos_horas(): void
    {
        $lead = $this->crear_lead($this->crear_demo(), '2026-10-16', '22:40', '23:59');

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringContainsString('hasta las 23:59', $html);
        $this->assertStringNotContainsString('reservada para vos dos horas', $html);
    }

    /**
     * (3) Sin turno legible, o con el turno ya terminado, el mail sale como siempre: llaves, sin hora
     * inventada y con el asunto de antes.
     *
     * @return void
     */
    public function test_sin_turno_vigente_el_mail_no_inventa_una_hora(): void
    {
        $demo = $this->crear_demo();

        $sin_fin   = $this->crear_lead($demo, '2026-10-16', '15:10', null);
        $vencido   = $this->crear_lead($demo, '2026-10-15', '15:10', '17:10');
        $sin_fecha = $this->crear_lead($demo, null);

        foreach ([$sin_fin, $vencido, $sin_fecha] as $lead) {
            $this->assertNull(DemoTurnoTexto::de_lead($lead));

            $mail = LeadDemoAccesoMailHelper::build($lead);
            $html = $mail->render();

            $this->assertSame('Tus llaves de acceso a ComercioCity, Guillermo', (string) $mail->subject);
            $this->assertStringContainsString('Estas son tus llaves.', $html);
            $this->assertStringNotContainsString('Tu turno', $html);
            $this->assertStringNotContainsString('Tu demo está reservada.', $html);
            // La instancia completa se explica igual: es contenido, no turno.
            $this->assertStringContainsString('Sistema de agentes', $html);
        }
    }

    /**
     * (4) El mail explica que es una instancia completa: gestión, tienda online y sistema de agentes.
     *
     * @return void
     */
    public function test_el_mail_explica_que_es_una_instancia_completa(): void
    {
        $html = LeadDemoAccesoMailHelper::build($this->crear_lead($this->crear_demo()))->render();

        $this->assertStringContainsString('una instancia completa de la plataforma', $html);
        $this->assertStringContainsString('Sistema de gestión', $html);
        $this->assertStringContainsString('Tienda online', $html);
        $this->assertStringContainsString('Sistema de agentes', $html);
        $this->assertStringContainsString('Ver la tienda', $html);
        $this->assertStringContainsString('Entrar a la demo', $html);
    }

    /**
     * (4b) Una instancia sin tienda no muestra la fila de la tienda ni su botón.
     *
     * @return void
     */
    public function test_sin_tienda_no_aparece_la_fila_de_la_tienda(): void
    {
        $html = LeadDemoAccesoMailHelper::build($this->crear_lead($this->crear_demo('')))->render();

        $this->assertStringNotContainsString('Tienda online', $html);
        $this->assertStringNotContainsString('Ver la tienda', $html);
        $this->assertStringContainsString('Sistema de gestión', $html);
        $this->assertStringContainsString('Sistema de agentes', $html);
    }

    /**
     * (2) Sin el número dedicado listo, el mail no ofrece el asistente: ni el bloque, ni
     * el enlace, ni la frase suelta en la fila de agentes.
     *
     * @return void
     */
    public function test_sin_el_numero_listo_el_mail_no_promete_el_asistente(): void
    {
        $lead = $this->crear_lead($this->crear_demo());

        // Ni fila, ni fila a medias (alta del panel sin registrar el webhook).
        foreach ([null, ['webhook_secret' => null]] as $campos) {
            if ($campos !== null) {
                $this->dejar_listo_el_asistente($campos);
            }

            $html = LeadDemoAccesoMailHelper::build($lead)->render();

            $this->assertStringNotContainsString('Asistente personal', $html);
            $this->assertStringNotContainsString('Probalo desde tu WhatsApp', $html);
            $this->assertStringNotContainsString('wa.me', $html);
            $this->assertStringNotContainsString('asistente personal', $html);
            $this->assertStringContainsString('Sistema de agentes', $html);
        }
    }

    /**
     * (2b) Con el número listo, el mail muestra el bloque con el número y un enlace que abre el chat.
     *
     * @return void
     */
    public function test_con_el_numero_listo_el_mail_explica_como_probar_el_asistente(): void
    {
        $this->dejar_listo_el_asistente();
        $lead = $this->crear_lead($this->crear_demo());

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringContainsString('Probalo desde tu WhatsApp', $html);
        $this->assertStringContainsString('+1 201 555 0123', $html);
        $this->assertStringContainsString('en un chat separado del equipo comercial', $html);
        $this->assertStringContainsString('desde tu número de WhatsApp', $html);
        $this->assertStringNotContainsString('al mismo número de ComercioCity con el que coordinaste la demo', $html);
        $this->assertStringContainsString('href="https://wa.me/12015550123?text=Hola%2C%20estoy%20en%20mi%20demo%20de%20ComercioCity"', $html);
        $this->assertStringContainsString('Mientras dure tu turno', $html);
        $this->assertStringContainsString('un asistente', $html);
    }

    /**
     * (2c) El interruptor propio apaga el bloque aunque el número esté listo: es la marcha atrás sin
     * tocar nada más.
     *
     * @return void
     */
    public function test_el_interruptor_apaga_el_bloque_aunque_el_numero_este_listo(): void
    {
        $this->dejar_listo_el_asistente();
        AdminSetting::set(AsistenteDemoLeadService::KEY_ACTIVO, '0');

        $html = LeadDemoAccesoMailHelper::build($this->crear_lead($this->crear_demo()))->render();

        $this->assertFalse(AsistenteDemoLeadService::disponible());
        $this->assertStringNotContainsString('Probalo desde tu WhatsApp', $html);
        $this->assertStringNotContainsString('wa.me', $html);
    }

    /**
     * (2d) 🔴 Con el número listo pero SIN turno vigente (un reenvío cuando el turno ya terminó), el mail
     * no ofrece el asistente: el número le contestaría "no tenés una demo activa".
     *
     * @return void
     */
    public function test_sin_turno_vigente_el_mail_no_ofrece_el_asistente(): void
    {
        $this->dejar_listo_el_asistente();
        $lead = $this->crear_lead($this->crear_demo(), '2026-10-15', '15:10', '17:10');

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringContainsString('Estas son tus llaves.', $html);
        $this->assertStringNotContainsString('Probalo desde tu WhatsApp', $html);
        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('asistente personal', $html);
    }

    /**
     * (2e) 🔴 Un lead con teléfono de otro país no recibe la oferta del asistente: el envío normaliza el
     * destino como argentino y no se le podría contestar.
     *
     * @return void
     */
    public function test_a_un_lead_de_otro_pais_no_se_le_ofrece_el_asistente(): void
    {
        $this->dejar_listo_el_asistente();
        $lead = $this->crear_lead($this->crear_demo(), '2026-10-16', '15:10', '17:10', 'Guillermo González', '+12015550123');

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringContainsString('Tu demo está reservada.', $html);
        $this->assertStringNotContainsString('Probalo desde tu WhatsApp', $html);
        $this->assertStringNotContainsString('wa.me', $html);
    }

    /**
     * (2f) 🔴 Sobre una instancia sin clave (`demos.api_key` vacía) el asistente no puede atender: el mail no lo
     * promete.
     *
     * @return void
     */
    public function test_con_la_demo_sin_clave_el_mail_no_ofrece_el_asistente(): void
    {
        $this->dejar_listo_el_asistente();
        $lead = $this->crear_lead($this->crear_demo('https://demo-tienda.test', ''));

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringNotContainsString('Probalo desde tu WhatsApp', $html);
        $this->assertStringNotContainsString('wa.me', $html);
    }

    /**
     * (1d) El turno vence como vence el resto del ciclo de la demo (fin + gracia): a las 17:15 el turno de
     * 15:10 a 17:10 sigue vigente (gracia de 10 minutos → 17:20) y a las 17:25 ya no.
     *
     * @return void
     */
    public function test_el_turno_vence_con_la_misma_regla_que_el_resto_de_la_demo(): void
    {
        $lead = $this->crear_lead($this->crear_demo());

        $this->assertNotNull(DemoTurnoTexto::de_lead($lead, Carbon::parse('2026-10-16 17:15:00', self::TZ)));
        $this->assertNull(DemoTurnoTexto::de_lead($lead, Carbon::parse('2026-10-16 17:25:00', self::TZ)));
    }

    /**
     * Un nombre de lead hostil sale escapado: nada de HTML ni comillas sin escapar en el cuerpo.
     *
     * @return void
     */
    public function test_el_nombre_del_lead_sale_escapado(): void
    {
        $lead = $this->crear_lead($this->crear_demo(), '2026-10-16', '15:10', '17:10', '<img src=x onerror=alert(1)> "Guille"');

        $mail = LeadDemoAccesoMailHelper::build($lead);
        $html = $mail->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    /**
     * Sin turno y sin tienda el mail habla de UN acceso (no promete un segundo que no está).
     *
     * @return void
     */
    public function test_sin_turno_y_sin_tienda_habla_de_un_solo_acceso(): void
    {
        $lead = $this->crear_lead($this->crear_demo(''), null);

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringContainsString('Este acceso abre la demo', $html);
        $this->assertStringNotContainsString('Son dos accesos', $html);
    }

    /**
     * (3b) Un llamador viejo que arma el Mailable con los siete argumentos de antes obtiene el mail de
     * siempre, sin turno y sin asistente.
     *
     * @return void
     */
    public function test_el_mailable_con_los_siete_argumentos_de_antes_sigue_funcionando(): void
    {
        $mail = new \App\Mail\LeadDemoAccesoMail(
            'Guillermo',
            'https://admin.test/experiencia/123',
            'https://tienda.test',
            'https://wa.me/5493444622139',
            '',
            'Equipo ComercioCity',
            'Fundador'
        );

        $html = $mail->render();

        $this->assertSame('Tus llaves de acceso a ComercioCity, Guillermo', (string) $mail->subject);
        $this->assertStringContainsString('Estas son tus llaves.', $html);
        $this->assertStringNotContainsString('Probalo desde tu WhatsApp', $html);
        $this->assertFalse($mail->mostrar_asistente);
    }

    /**
     * El mail no deja variables sin resolver ni etiquetas de Blade a la vista, en ninguna variante.
     *
     * @return void
     */
    public function test_el_mail_no_deja_restos_de_plantilla(): void
    {
        $this->dejar_listo_el_asistente();
        $lead = $this->crear_lead($this->crear_demo());

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('@if', $html);
        $this->assertStringNotContainsString('@endif', $html);
        $this->assertStringNotContainsString('$turno', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'Un solo titular principal.');
    }
}
