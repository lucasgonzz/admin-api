<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `leads:check-pagina-sin-demo` (misión experiencia-landing, 11/9/2026): el único seguimiento al lead
 * que abrió su página de experiencia sin turno y, pasadas N horas, no pidió la demo ni volvió a
 * escribir. Texto libre dentro de la ventana de 24 hs, un solo envío por lead para siempre.
 */
class CheckPaginaSinDemoTest extends TestCase
{
    use DatabaseTransactions;

    /** El "ahora" de todos los casos. */
    const AHORA = '2026-09-11 14:00:00';

    /** Texto esperado con el primer nombre del lead de prueba. */
    const TEXTO_ESPERADO = '¡Hola Guillermo! Vi que le pegaste una mirada a tu página... Si querés, te preparo la demo con la configuración de tu negocio y en diez minutos la tenés lista. ¿Arrancamos?';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        AdminSetting::set(LeadDemoSettings::KEY_PAGINA_SEGUIMIENTO_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_CHECK_INGRESO_SILENCIO_MINUTOS, '10');
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
     * (1) Abrió la página hace 130 minutos, el último mensaje del hilo es nuestro, silencio de más
     *     de 10 minutos y ventana abierta: sale el texto con el primer nombre, queda en el hilo como
     *     seguimiento y se marca. Una segunda corrida no lo vuelve a mandar.
     *
     * @return void
     */
    public function test_manda_una_sola_vez_y_marca_al_lead(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(200));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(190));
        $this->apertura($lead, Carbon::now()->subMinutes(130));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('5493519999999', preg_replace('/\D+/', '', $espia->textos[0]['to']));
        $this->assertSame(self::TEXTO_ESPERADO, $espia->textos[0]['body']);

        $lead->refresh();
        $this->assertNotNull($lead->pagina_seguimiento_enviado_at);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'status'              => 'enviado',
            'is_followup'         => 1,
            'content'             => self::TEXTO_ESPERADO,
            'whatsapp_message_id' => 'wamid.texto.1',
        ]);

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);
        $this->assertCount(1, $espia->textos, 'Un solo envío por lead, para siempre.');
    }

    /**
     * (2) Antes de los N minutos no sale, y sin ninguna apertura tampoco (llegar al final sin haber
     *     registrado la apertura no es candidato).
     *
     * @return void
     */
    public function test_antes_del_umbral_o_sin_apertura_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();

        $reciente = $this->crear_lead();
        $this->mensaje($reciente, 'lead', Carbon::now()->subMinutes(200));
        $this->mensaje($reciente, 'sistema', Carbon::now()->subMinutes(190));
        $this->apertura($reciente, Carbon::now()->subMinutes(30));

        $sin_apertura = $this->crear_lead('5493519999998');
        $this->mensaje($sin_apertura, 'lead', Carbon::now()->subMinutes(200));
        $this->mensaje($sin_apertura, 'sistema', Carbon::now()->subMinutes(190));
        $this->evento($sin_apertura, 'pagina_final_sin_turno', Carbon::now()->subMinutes(130));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertNull($reciente->refresh()->pagina_seguimiento_enviado_at);
        $this->assertNull($sin_apertura->refresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * (3) Con el CTA tocado no sale: ya pidió la demo por WhatsApp y el agente está en eso.
     *
     * @return void
     */
    public function test_con_el_cta_tocado_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(200));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(190));
        $this->apertura($lead, Carbon::now()->subMinutes(130));
        $this->evento($lead, 'cta_demo_tocado', Carbon::now()->subMinutes(125));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertNull($lead->refresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * (4) Si el lead escribió DESPUÉS de abrir la página, la conversación siguió por otro lado: no
     *     se lo interrumpe, aunque ya le hayamos contestado y haya silencio.
     *
     * @return void
     */
    public function test_con_un_entrante_posterior_a_la_apertura_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(200));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(190));
        $this->apertura($lead, Carbon::now()->subMinutes(130));
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(100));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(90));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertNull($lead->refresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * (5) 🔴 Si el último mensaje real del hilo es del lead (la pelota es nuestra), no se lo pisa; y
     *     si hubo conversación en los últimos 10 minutos, se posterga. En ninguno de los dos se marca.
     *
     * @return void
     */
    public function test_con_la_pelota_nuestra_o_conversacion_reciente_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();

        $sin_contestar = $this->crear_lead();
        $this->mensaje($sin_contestar, 'sistema', Carbon::now()->subMinutes(300));
        $this->mensaje($sin_contestar, 'lead', Carbon::now()->subMinutes(200));
        $this->apertura($sin_contestar, Carbon::now()->subMinutes(130));

        $reciente = $this->crear_lead('5493519999997');
        $this->mensaje($reciente, 'lead', Carbon::now()->subMinutes(200));
        $this->apertura($reciente, Carbon::now()->subMinutes(130));
        $this->mensaje($reciente, 'setter', Carbon::now()->subMinutes(4));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertNull($sin_contestar->refresh()->pagina_seguimiento_enviado_at);
        $this->assertNull($reciente->refresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * (6) Fuera de la ventana de 24 hs de Meta no sale (texto libre, sin plantilla) y no se marca.
     *
     * @return void
     */
    public function test_fuera_de_la_ventana_de_24_horas_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead();
        $this->mensaje($lead, 'lead', Carbon::now()->subHours(30));
        $this->mensaje($lead, 'sistema', Carbon::now()->subHours(29));
        $this->apertura($lead, Carbon::now()->subMinutes(130));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertNull($lead->refresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * (7) No son candidatos: con turno asignado, en otro estado del pipeline, con la dinámica actual
     *     o marcado como "no recibe mensajes".
     *
     * @return void
     */
    public function test_con_turno_otro_estado_dinamica_actual_o_sin_recibir_mensajes_no_es_candidato(): void
    {
        $espia = $this->espiar_whatsapp();

        $con_turno                  = $this->crear_lead();
        $con_turno->demo_id         = 1;
        $con_turno->demo_date       = '2026-09-11';
        $con_turno->demo_start_time = '16:00';
        $con_turno->save();

        $nuevo         = $this->crear_lead('5493519999996');
        $nuevo->status = 'nuevo';
        $nuevo->save();

        $actual                   = $this->crear_lead('5493519999995');
        $actual->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $actual->save();

        $bloqueado                        = $this->crear_lead('5493519999994');
        $bloqueado->no_recibe_mensajes_at = Carbon::now()->subDay();
        $bloqueado->save();

        foreach ([$con_turno, $nuevo, $actual, $bloqueado] as $lead) {
            $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(200));
            $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(190));
            $this->apertura($lead, Carbon::now()->subMinutes(130));
        }

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
    }

    /**
     * (8) Si el envío falla (send_text devuelve null) se marca igual, para no reintentar en loop, y
     *     el hilo muestra el mensaje sin id de WhatsApp. Sin nombre, el saludo es "¡Hola!".
     *
     * @return void
     */
    public function test_si_el_envio_falla_se_marca_igual_y_no_se_reintenta(): void
    {
        $espia         = $this->espiar_whatsapp(true);
        $lead          = $this->crear_lead();
        $lead->contact_name = null;
        $lead->save();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(200));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(190));
        $this->apertura($lead, Carbon::now()->subMinutes(130));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertStringStartsWith('¡Hola! Vi que le pegaste una mirada', $espia->textos[0]['body']);

        $lead->refresh();
        $this->assertNotNull($lead->pagina_seguimiento_enviado_at);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'is_followup'         => 1,
            'whatsapp_message_id' => null,
        ]);

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);
        $this->assertCount(1, $espia->textos, 'Un envío fallido no se reintenta.');
    }

    /**
     * @param bool $falla Si true, send_text() simula un fallo (devuelve null).
     *
     * @return WhatsappSendService
     */
    private function espiar_whatsapp(bool $falla = false): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            /** @var bool Si true, send_text() simula un fallo (devuelve null). */
            public $falla = false;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body, 'context' => $context];

                if ($this->falla) {
                    $this->last_send_error = 'Fallo simulado';

                    return null;
                }

                return 'wamid.texto.' . count($this->textos);
            }
        };
        $espia->falla = $falla;

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Lead de la dinámica nueva, contactado, sin demo asignada.
     *
     * @param string $telefono
     *
     * @return Lead
     */
    private function crear_lead(string $telefono = '5493519999999'): Lead
    {
        $lead                             = new Lead();
        $lead->uuid                       = (string) Str::uuid();
        $lead->contact_name               = 'Guillermo González';
        $lead->company_name               = 'Ferretería de prueba';
        $lead->phone                      = $telefono;
        $lead->status                     = 'contactado';
        $lead->demo_experiencia           = Lead::EXPERIENCIA_NUEVA;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * @param Lead   $lead
     * @param Carbon $cuando
     *
     * @return DemoEventoRecibido
     */
    private function apertura(Lead $lead, Carbon $cuando): DemoEventoRecibido
    {
        return $this->evento($lead, 'pagina_abierta_sin_turno', $cuando);
    }

    /**
     * @param Lead   $lead
     * @param string $nombre
     * @param Carbon $cuando
     *
     * @return DemoEventoRecibido
     */
    private function evento(Lead $lead, string $nombre, Carbon $cuando): DemoEventoRecibido
    {
        return DemoEventoRecibido::create([
            'lead_id'     => $lead->id,
            'uuid'        => (string) Str::uuid(),
            'nombre'      => $nombre,
            'clip_id'     => null,
            'ocurrido_at' => $cuando,
            'datos'       => null,
        ]);
    }

    /**
     * @param Lead   $lead
     * @param string $sender
     * @param Carbon $cuando
     *
     * @return LeadMessage
     */
    private function mensaje(Lead $lead, string $sender, Carbon $cuando): LeadMessage
    {
        $mensaje                      = new LeadMessage();
        $mensaje->lead_id             = $lead->id;
        $mensaje->sender              = $sender;
        $mensaje->status              = 'enviado';
        $mensaje->is_followup         = false;
        $mensaje->content             = 'Mensaje de ' . $sender;
        $mensaje->whatsapp_message_id = $sender === 'lead' ? null : 'wamid.previo.' . Str::random(6);
        $mensaje->created_at          = $cuando;
        $mensaje->updated_at          = $cuando;
        $mensaje->save();

        return $mensaje;
    }
}
