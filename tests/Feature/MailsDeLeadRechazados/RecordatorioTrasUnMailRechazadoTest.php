<?php

namespace Tests\Feature\MailsDeLeadRechazados;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * El efecto concreto de "un mail fallido no cuenta como contacto hecho": el recordatorio de la demo.
 *
 * `SendDemoReminders::build_reminder_content_directa()` le escribe al lead POR WHATSAPP, y su texto depende de
 * una sola cosa: si `demo_mail_sent_at` tiene fecha.
 *
 *   - con fecha:  "los accesos a tu demo de ComercioCity están en el mail que te mandamos";
 *   - sin fecha:  "el acceso es el link que te pasé más arriba en este chat... si querés tenerlo también en el
 *                 mail, pasame tu correo".
 *
 * Con la carta de acceso RECHAZADA por el servidor (un 550 en el RCPT TO, que SwiftMailer no convierte en
 * excepción) `demo_mail_sent_at` quedaba con fecha, y el recordatorio le mentía al lead: lo mandaba a buscar a
 * su casilla un mail que nunca llegó, en el momento exacto en que tenía que estar entrando a la demo. Con el
 * arreglo la marca queda vacía y el recordatorio toma la rama correcta: le vuelve a pedir el correo.
 *
 * Los dos tests recorren el camino real de punta a punta: la IA aplica el paquete (que manda la carta por el
 * mailer por defecto, contra un servidor SMTP de verdad) y después corre el comando de recordatorios.
 *
 * El molde del recordatorio es `RecordatorioDemoDirectaTest`.
 */
class RecordatorioTrasUnMailRechazadoTest extends BaseDeMailsDeLead
{
    /** El "ahora": la demo arrancó a las 10:10 (ventana de seis horas) y estamos a las 10:45. */
    const AHORA = '2026-09-08 10:45:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));

        // Nada sale a ningún lado: ni la IA ni el comando.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_SILENCIO_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
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
     * Un WhatsApp de mentira que guarda lo que el comando intentó mandar. El comando lo recibe del contenedor.
     *
     * @return WhatsappSendService
     */
    private function espiar_whatsapp(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            /**
             * Guarda lo que el comando intentó mandar por WhatsApp en vez de mandarlo de verdad.
             *
             * @param string      $to                        Teléfono del lead.
             * @param string      $body                      Texto del mensaje.
             * @param string|null $context                   No se usa.
             * @param bool        $skip_failure_notification No se usa.
             *
             * @return string|null Un id de mensaje inventado.
             */
            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }

            /**
             * Las plantillas no se miran en este test: devuelve un id inventado sin mandar nada.
             *
             * @param string               $to
             * @param string               $template_name
             * @param array<string, mixed> $variables
             * @param string               $language_code
             * @param string|null          $context
             *
             * @return string|null Un id de mensaje inventado.
             */
            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                return 'wamid.plantilla';
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Lead de la dinámica nueva con la demo asignada, en plena ventana (arrancó a las 10:10), sin la carta enviada
     * y con las automatizaciones prendidas: el candidato a recibir el recordatorio.
     *
     * @return Lead
     */
    private function lead_en_plena_ventana(): Lead
    {
        return $this->con_un_mensaje_entrante($this->crear_lead_de_la_dinamica_nueva([
            'demo_date'                     => '2026-09-08',
            'demo_start_time'               => '10:10',
            'demo_end_time'                 => '16:10',
            'demo_flexible'                 => true,
            'automatizaciones_demo_activas' => true,
            'auto_recordatorio_demo'        => true,
            'recordatorio_demo_enviado'     => false,
        ]));
    }

    /**
     * El hilo queda como a los 35 minutos de la última charla: pasa la ventana de silencio del recordatorio
     * (30 minutos) y la ventana de 24 hs de Meta sigue abierta. Y la sugerencia que dejó la IA ya no está
     * pendiente (es lo que pasa cuando el mensaje se envía): con una pendiente el lead no es candidato.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function dejar_la_charla_en_silencio(Lead $lead): void
    {
        $hace_40_minutos = Carbon::now()->subMinutes(40);

        LeadMessage::query()->where('lead_id', $lead->id)->update([
            'created_at' => $hace_40_minutos,
            'updated_at' => $hace_40_minutos,
        ]);

        // 🔴 Por el query builder y no con `$lead->update(...)`: la instancia que tiene el test dice `false` desde que se
        // creó, y Eloquent no escribe un atributo que no cambió. La IA dejó `true` en la base, en OTRA instancia del lead.
        Lead::query()->where('id', $lead->id)->update(['tiene_sugerencia_pendiente' => false]);
    }

    /**
     * 🔴 El centro: el servidor RECHAZA la carta de acceso. El recordatorio NO le dice al lead que los accesos
     * están en el mail (no llegó nada): le dice que el acceso es el link del chat y le vuelve a pedir el correo.
     *
     * @return void
     */
    public function test_con_la_carta_rechazada_el_recordatorio_no_le_miente_al_lead(): void
    {
        $lead = $this->lead_en_plena_ventana();

        $this->el_servidor_rechaza();

        // El lead pide que le reenvíen la carta, y el servidor la rechaza.
        $this->aprobar($lead, [], $this->final_actions(['reenviar_mail_demo' => true]));

        $this->dejar_la_charla_en_silencio($lead);

        $espia = $this->espiar_whatsapp();

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'El lead está en plena ventana: tenía que salir el recordatorio.');

        $texto = $espia->textos[0]['body'];

        $this->assertStringNotContainsString('están en el mail que te mandamos', $texto, 'La carta no llegó: no se le puede decir que está en el mail.');
        $this->assertStringContainsString('el link que te pasé más arriba', $texto);
        $this->assertStringContainsString('pasame tu correo', $texto);
    }

    /**
     * El control: la carta SALE (el servidor la acepta). El recordatorio dice lo de siempre: los accesos están
     * en el mail. Sin esto, un arreglo que dejara `demo_mail_sent_at` siempre vacío pasaría el test de arriba.
     *
     * @return void
     */
    public function test_con_la_carta_enviada_el_recordatorio_dice_que_los_accesos_estan_en_el_mail(): void
    {
        $lead = $this->lead_en_plena_ventana();

        $this->el_servidor_acepta();

        $this->aprobar($lead, [], $this->final_actions(['reenviar_mail_demo' => true]));

        $this->dejar_la_charla_en_silencio($lead);

        $espia = $this->espiar_whatsapp();

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);

        $texto = $espia->textos[0]['body'];

        $this->assertStringContainsString('están en el mail que te mandamos', $texto);
        $this->assertStringNotContainsString('pasame tu correo', $texto);
    }
}
