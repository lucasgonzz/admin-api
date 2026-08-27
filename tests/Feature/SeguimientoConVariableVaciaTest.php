<?php

namespace Tests\Feature;

use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadFollowupService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un seguimiento nunca sale con `{{1}}` vacío, y el hilo muestra exactamente lo que salió.
 *
 * Hasta el 27/8/2026 `send_followup_via_template()` armaba las variables a ciegas:
 * `[$lead->contact_name ?? '']`. El `??` parecía un fallback y no lo era —solo atrapa null, y el
 * campo puede venir `''`—, así que a Meta le viajaba un parámetro de texto vacío. Para Meta eso
 * ES un parámetro que falta: `(#131008) Required parameter is missing`. 2.933 seguimientos
 * perdidos sobre 159 leads entre julio y agosto de 2026.
 *
 * El envío se sustituye a nivel WhatsappSendService, así que no se toca la red: lo que se mide es
 * lo que el servicio decide mandar, y que el texto guardado en la conversación coincida con eso.
 */
class SeguimientoConVariableVaciaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Evita que cualquier salida a la red se concrete.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Lead de prueba con el nombre que pida cada caso.
     *
     * @param string|null $contact_name Nombre del contacto (null, '' o '   ' para los casos sin nombre).
     *
     * @return Lead
     */
    private function crear_lead(?string $contact_name): Lead
    {
        $lead               = new Lead();
        $lead->phone        = '+5493417778899';
        $lead->contact_name = $contact_name;
        $lead->status       = 'nuevo';
        $lead->save();

        return $lead;
    }

    /**
     * Plantilla de seguimiento con el cuerpo que pida cada caso.
     *
     * @param string $body_template Texto aprobado en Meta, con o sin {{n}}.
     * @param string $template_name Nombre de la plantilla en Meta.
     *
     * @return FollowupTemplate
     */
    private function crear_plantilla(string $body_template, string $template_name = 'cc_seg_prueba_d1'): FollowupTemplate
    {
        $template                = new FollowupTemplate();
        $template->estado        = 'nuevo';
        $template->dia_numero    = 1;
        $template->template_name = $template_name;
        $template->language_code = 'es_AR';
        $template->body_template = $body_template;
        $template->activa        = true;
        $template->save();

        return $template;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra qué se le pidió mandar.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Plantillas que se pidió enviar. */
            public $envios = [];

            /**
             * @param string      $to
             * @param string      $template_name
             * @param array       $variables
             * @param string      $language_code
             * @param string|null $context
             *
             * @return string|null
             */
            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                ];

                return 'wamid.ENVIADO' . count($this->envios);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Texto que quedó registrado en la conversación del lead para el seguimiento.
     *
     * @param Lead $lead Lead destinatario.
     *
     * @return string
     */
    private function texto_en_el_hilo(Lead $lead): string
    {
        $message = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($message, 'El seguimiento tenía que quedar registrado en el hilo.');

        return (string) $message->content;
    }

    /**
     * Lead sin nombre: sale el reemplazo genérico, jamás un string vacío.
     *
     * @return void
     */
    public function test_un_lead_sin_nombre_manda_el_saludo_generico()
    {
        $espia    = $this->espiar_sender();
        $lead     = $this->crear_lead(null);
        $template = $this->crear_plantilla('Hola {{1}}! Te escribo de ComercioCity.');

        app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 1);

        $this->assertCount(1, $espia->envios);
        $this->assertSame([LeadFollowupService::NOMBRE_GENERICO], $espia->envios[0]['variables']);
        $this->assertNotSame([''], $espia->envios[0]['variables']);
    }

    /**
     * Un nombre de puros espacios es tan inutilizable como null: también va el genérico.
     *
     * @return void
     */
    public function test_un_nombre_de_puros_espacios_tambien_manda_el_generico()
    {
        $espia    = $this->espiar_sender();
        $lead     = $this->crear_lead('   ');
        $template = $this->crear_plantilla('Hola {{1}}! Te escribo de ComercioCity.');

        app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 1);

        $this->assertSame([LeadFollowupService::NOMBRE_GENERICO], $espia->envios[0]['variables']);
    }

    /**
     * Con nombre cargado no cambia nada: sale el nombre del lead.
     *
     * @return void
     */
    public function test_un_lead_con_nombre_manda_su_nombre()
    {
        $espia    = $this->espiar_sender();
        $lead     = $this->crear_lead('Marina');
        $template = $this->crear_plantilla('Hola {{1}}! Te escribo de ComercioCity.');

        app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 1);

        $this->assertSame(['Marina'], $espia->envios[0]['variables']);
    }

    /**
     * Plantilla sin {{1}}: no viaja ninguna variable, así send_template() no arma el componente
     * `body` para una plantilla que no lo pide (Meta rechaza el envío si sobra un parámetro).
     *
     * @return void
     */
    public function test_una_plantilla_sin_placeholder_no_manda_variables()
    {
        $espia    = $this->espiar_sender();
        $lead     = $this->crear_lead('Marina');
        $template = $this->crear_plantilla('Quedamos a disposición por cualquier consulta.');

        app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 1);

        $this->assertSame([], $espia->envios[0]['variables']);
    }

    /**
     * El texto guardado en el hilo es EXACTAMENTE el que recibió el lead.
     *
     * Antes el envío mandaba `''` y el hilo renderizaba `Hola !`: la conversación mostraba un
     * texto que nadie recibió nunca.
     *
     * @return void
     */
    public function test_el_hilo_muestra_el_mismo_texto_que_se_envio()
    {
        $espia    = $this->espiar_sender();
        $lead     = $this->crear_lead(null);
        $template = $this->crear_plantilla('Hola {{1}}! Te escribo de ComercioCity.');

        app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 1);

        $enviado = str_replace('{{1}}', $espia->envios[0]['variables'][0], (string) $template->body_template);

        $this->assertSame($enviado, $this->texto_en_el_hilo($lead));
        $this->assertStringNotContainsString('Hola !', $this->texto_en_el_hilo($lead));
        $this->assertStringContainsString(LeadFollowupService::NOMBRE_GENERICO, $this->texto_en_el_hilo($lead));
    }

    /**
     * Con nombre, el hilo también refleja el texto real enviado.
     *
     * @return void
     */
    public function test_el_hilo_muestra_el_nombre_cuando_el_lead_lo_tiene()
    {
        $this->espiar_sender();
        $lead     = $this->crear_lead('Marina');
        $template = $this->crear_plantilla('Hola {{1}}! Te escribo de ComercioCity.');

        app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 1);

        $this->assertSame('Hola Marina! Te escribo de ComercioCity.', $this->texto_en_el_hilo($lead));
    }
}
