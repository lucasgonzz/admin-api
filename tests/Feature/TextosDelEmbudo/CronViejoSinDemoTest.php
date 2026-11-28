<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadFollowupService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CA31 y CA32 de la etapa E3 (misión embudo-juan-pipeline).
 *
 *  - CA31 (`test_instruccion_de_juan`): el bloque del agente de leads dice que lo llama Juan y que «la llamada no tiene costo»; ya no dice
 *    «sin cobrar», que se leía como «la implementación es gratis».
 *  - CA32: el cron viejo (`leads:check-followups`) no le manda a un lead del flujo nuevo una plantilla de seguimiento de demo
 *    (`cc_seg_calificado_d1` y compañía). Un lead creado antes de encender el flujo sigue recibiéndola como hoy.
 *
 * Hoy están EN ROJO: el bloque dice «sin cobrar» y el cron sólo salta a los leads de los estados con pasos del embudo.
 */
class CronViejoSinDemoTest extends TestCase
{
    use DatabaseTransactions;

    /** Horas hacia atrás con las que se fecha el lead y su único mensaje: bien pasadas las 24 de la regla. */
    const HORAS_ATRAS = 30;

    /** @var WhatsappSendService Espía de envíos. */
    private $espia;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $envios = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                return 'wamid.ESPIA.' . uniqid('', true);
            }
        };
        $this->app->instance(WhatsappSendService::class, $this->espia);

        FollowupRule::query()->updateOrCreate(['estado' => 'calificado'], ['horas_espera' => 24, 'max_followups' => 1, 'activa' => true]);
        FollowupTemplate::query()->where('estado', 'calificado')->update(['activa' => false]);
        $plantilla                = new FollowupTemplate();
        $plantilla->estado        = 'calificado';
        $plantilla->dia_numero    = 1;
        $plantilla->template_name = 'cc_seg_calificado_d1';
        $plantilla->language_code = 'es_AR';
        $plantilla->body_template = 'Hola {{1}}! Te escribo de ComercioCity.';
        $plantilla->activa        = true;
        $plantilla->save();
    }

    /**
     * Un lead `calificado` creado hace 30 horas, con un solo saliente de bienvenida y sin respuesta.
     *
     * @return Lead
     */
    private function lead_calificado_sin_respuesta(): Lead
    {
        $lead               = new Lead();
        $lead->phone        = '+5493417778800';
        $lead->contact_name = 'Marina';
        $lead->status       = 'calificado';
        $lead->created_at   = AppTime::now()->subHours(self::HORAS_ATRAS);
        $lead->save();

        $mensaje             = new LeadMessage();
        $mensaje->lead_id    = $lead->id;
        $mensaje->sender     = 'sistema';
        $mensaje->content    = 'Hola Marina! Bienvenida a ComercioCity.';
        $mensaje->status     = 'enviado';
        $mensaje->created_at = AppTime::now()->subHours(self::HORAS_ATRAS)->addMinute();
        $mensaje->save();

        return $lead->fresh();
    }

    /**
     * Prende el flujo de entrada desde un momento.
     *
     * @param \Carbon\Carbon $desde
     *
     * @return void
     */
    private function prender_el_flujo($desde): void
    {
        AdminSetting::set(LeadFlujoEntradaService::KEY_ACTIVO, '1');
        AdminSetting::set(LeadFlujoEntradaService::KEY_ACTIVADO_EN, $desde->toIso8601String());
        /* Este test fija el bloque de las dos formas y el link (el de antes de la charla): se prueba con la charla apagada (misión leads-charla-antes-de-la-demo). */
        AdminSetting::set(LeadFlujoEntradaService::KEY_CHARLA, '0');
    }

    /**
     * CA32. Un lead creado DESPUÉS de encender el flujo no recibe plantillas de seguimiento de demo: `embudo`, sin mensajes ni envíos.
     *
     * @return void
     */
    public function test_un_lead_del_flujo_no_recibe_plantillas_de_demo(): void
    {
        $this->prender_el_flujo(AppTime::now()->subDays(5));
        $lead = $this->lead_calificado_sin_respuesta();
        $antes = LeadMessage::query()->where('lead_id', $lead->id)->count();

        $resultado = app(LeadFollowupService::class)->process_single_lead($lead);

        $this->assertSame('embudo', $resultado);
        $this->assertSame($antes, LeadMessage::query()->where('lead_id', $lead->id)->count(), 'No queda ningún mensaje nuevo en el hilo.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', $lead->id)->where('is_followup', true)->count());
        $this->assertCount(0, $this->espia->envios, 'Ni sale una plantilla cc_seg_*.');
        $this->assertFalse((bool) $lead->fresh()->tiene_sugerencia_pendiente);
    }

    /**
     * CA32. El mismo lead creado ANTES del encendido: como hoy.
     *
     * @return void
     */
    public function test_un_lead_anterior_al_flujo_sigue_como_hoy(): void
    {
        $this->prender_el_flujo(AppTime::now()->subDay());
        $lead = $this->lead_calificado_sin_respuesta();

        $resultado = app(LeadFollowupService::class)->process_single_lead($lead);

        $this->assertNotSame('embudo', $resultado);
        $this->assertCount(1, $this->espia->envios, 'Recibe su plantilla de siempre.');
        $this->assertSame('cc_seg_calificado_d1', $this->espia->envios[0]['template_name']);
    }

    /**
     * CA31. La instrucción de Juan en el bloque del agente: «la llamada no tiene costo», nunca «sin cobrar».
     *
     * @return void
     */
    public function test_instruccion_de_juan(): void
    {
        $this->prender_el_flujo(AppTime::now()->subDay());
        $lead = new Lead();
        $lead->phone        = '+5493417778811';
        $lead->contact_name = 'Rosa';
        $lead->status       = 'contactado';
        $lead->save();

        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($lead->fresh());

        $this->assertNotSame('', $bloque, 'Precondición: el bloque del flujo está.');
        $this->assertStringContainsString('lo llama Juan', $bloque);
        $this->assertStringContainsString('la llamada no tiene costo', $bloque);
        $this->assertStringNotContainsString('sin cobrar', $bloque);
    }
}
