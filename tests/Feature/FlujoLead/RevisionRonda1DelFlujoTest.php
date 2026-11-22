<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\KnowledgeGroundingGate;
use App\Services\LeadAiService;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadSuggestionSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 1 de revisión de E2 (misión embudo-whatsapp-precios; DECISIONES-E2 puntos 9, 10, 10-bis y 11).
 *
 * Un test por caso que reprodujo el revisor, más un lead POSTERIOR al encendido que sí entra al flujo:
 *  - Caso 1 (punto 9): `link_enviado` y `implementacion_pedida` no retroceden con un `estado_sugerido` de antes.
 *  - Caso 2 (puntos 10 y 10-bis): sólo están en el flujo los leads creados desde que se prendió el interruptor (sello
 *    `lead_whatsapp_flujo_entrada_activado_en`) y sin demo asignada.
 *  - Caso 3 (punto 11): literal o IA, nunca los dos: con una sugerencia de la IA todavía sin enviar no se toma el literal.
 */
class RevisionRonda1DelFlujoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Clave del sello de encendido del flujo (DECISIONES-E2 punto 10). */
    private const SELLO = 'lead_whatsapp_flujo_entrada_activado_en';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    /**
     * Caso 1. Lead `contactado` con el paso 1 entregado: la sugerencia con el link lo pasa a `link_enviado`; al turno siguiente
     * la IA (que con el bloque de la demo directa pide `calificado`) NO lo baja: ni en el mensaje ni al enviarlo.
     *
     * @return void
     */
    public function test_link_enviado_no_retrocede_con_el_turno_siguiente_de_la_ia(): void
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Dale, quiero probar');
        $sugerencia = $this->crear_sugerencia_de_flujo($lead, 'Acá: ' . LeadOfertaDePruebaService::link());
        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($sugerencia);
        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Precondición: el link lo pasó a link_enviado.');

        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Listo, ahora lo veo a la noche');
        $this->fakear_ia([$this->paquete_ia('Dale, cualquier duda me escribís.', 'calificado')]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'La IA no puede bajar al lead de link_enviado.');
        $this->assertEmpty($mensaje->suggested_lead_status, 'El mensaje no tiene que prometer un cambio de estado hacia atrás.');

        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($mensaje->fresh());
        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Tampoco al enviar.');
    }

    /**
     * Caso 1 (bis). Mismo trato para `implementacion_pedida`: no baja a los estados de antes, y sí puede avanzar.
     *
     * @return void
     */
    public function test_implementacion_pedida_no_retrocede_y_si_puede_avanzar(): void
    {
        $lead = $this->crear_lead_de_flujo('implementacion_pedida');
        $this->mensaje_del_lead($lead, 'Prefiero que me lo carguen');
        $this->fakear_ia([$this->paquete_ia('Quedamos así, hoy te llama Juan.', 'contactado')]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status);
        $this->assertEmpty($mensaje->suggested_lead_status);

        /* Avanzar sí: pedir la demo explícitamente sigue el flujo de la demo. */
        $this->assertSame(
            'solicita_disponibilidad',
            LeadFlujoEntradaService::estado_sin_retroceso($lead->fresh(), 'solicita_disponibilidad')
        );
    }

    /**
     * Caso 2. Un lead `calificado` anterior al encendido (con la bienvenida de hoy y dos idas y vueltas) no está en el flujo:
     * no se le deben puntos, no recibe el bloque, la IA no le agrega `llegada_2` y un «Dale» no dispara el literal.
     *
     * @return void
     */
    public function test_un_lead_anterior_al_encendido_no_entra_al_flujo(): void
    {
        $this->sellar_el_encendido_hace_una_hora();

        $viejo = $this->crear_lead_de_flujo('calificado');
        DB::table('leads')->where('id', $viejo->id)->update(['created_at' => Carbon::now()->subDays(3)]);
        $this->mensaje_del_lead($viejo, 'Hola, quiero info');
        $this->mensaje_de_sistema($viejo, 'Hola! Soy Martín, del equipo de ComercioCity. ¿A qué se dedica tu empresa?', 'whatsapp_welcome');
        $this->avanzar(60);
        $this->mensaje_del_lead($viejo, 'Una ferretería');
        $this->mensaje_de_sistema($viejo, 'Genial, ¿cuántas personas trabajan?');
        $this->avanzar(60);
        $this->mensaje_del_lead($viejo, 'Somos tres');

        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($viejo->fresh()), 'No se le deben puntos.');
        $this->assertSame('', LeadFlujoEntradaService::bloque_para_el_agente($viejo->fresh()), 'No recibe el bloque del flujo.');

        $this->fakear_ia([$this->paquete_ia('Perfecto, con tres usuarios te sirve el plan Intermedio.', 'calificado')]);
        $mensaje = app(LeadAiService::class)->generate_suggestion($viejo->fresh(), false);
        $this->assertStringNotContainsString($this->texto_llegada_2(), (string) $mensaje->content, 'La IA no le agrega llegada_2.');

        $this->avanzar(60);
        $this->postear_kapso((string) $viejo->phone, 'Dale')->assertStatus(200);
        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), '«Dale» no dispara el literal.');
    }

    /**
     * Caso 2 (bis). Un lead con demo asignada no está en el flujo, aunque sea posterior al encendido (mismo criterio que el bloque).
     *
     * @return void
     */
    public function test_un_lead_con_demo_no_esta_en_el_flujo(): void
    {
        $this->sellar_el_encendido_hace_una_hora();

        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->assertNotEmpty(LeadFlujoEntradaService::puntos_debidos($lead->fresh()), 'Precondición: sin demo sí entra.');

        DB::table('leads')->where('id', $lead->id)->update(['demo_id' => 1]);
        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($lead->fresh()));
    }

    /**
     * Caso 2 (ter). El lead POSTERIOR al encendido sí entra: se le deben los puntos del paso 1 y recibe el bloque.
     *
     * @return void
     */
    public function test_un_lead_posterior_al_encendido_si_entra(): void
    {
        $this->sellar_el_encendido_hace_una_hora();

        $nuevo = $this->crear_lead_de_flujo('nuevo');
        $this->mensaje_del_lead($nuevo, 'che, ¿en qué consiste ComercioCity?');

        $this->assertSame(['presentacion', 'prueba_y_rubro'], LeadFlujoEntradaService::puntos_debidos($nuevo->fresh()));
        $this->assertStringContainsString('PUNTOS OBLIGATORIOS DE ESTE TURNO', LeadFlujoEntradaService::bloque_para_el_agente($nuevo->fresh()));
    }

    /**
     * Caso 2 (cuarto). Prender el interruptor por el PUT de Cuenta (false → true) sella el encendido; guardar otra vez con el
     * flujo ya prendido no lo renueva; apagar y volver a prender sí.
     *
     * @return void
     */
    public function test_el_put_sella_al_prender_y_no_al_guardar_de_nuevo(): void
    {
        AdminSetting::query()->whereIn('key', [self::SELLO, LeadFlujoEntradaService::KEY_ACTIVO])->delete();
        $admin = $this->crear_admin_con_roles('Admin de Cuenta', []);
        $payload = [
            'auto_message_with_name' => 'Hola {nombre}!', 'auto_message_without_name' => 'Hola!',
            'welcome_message_with_name' => 'Bienvenido {nombre}!', 'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds' => 60, 'ai_suggestion_delay_seconds' => 60,
            'verificacion_agendamiento_auto_send_delay_minutes' => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => true,
        ];
        $endpoint = '/api/admin/settings/lead-whatsapp-onboarding';

        $this->actingAs($admin, 'sanctum')->putJson($endpoint, array_merge($payload, ['flujo_entrada_activo' => true]))->assertStatus(200);
        $primero = AdminSetting::get(self::SELLO);
        $this->assertNotEmpty($primero, 'Al prender se sella el encendido.');

        $this->avanzar(600);
        $this->actingAs($admin, 'sanctum')->putJson($endpoint, array_merge($payload, ['flujo_entrada_activo' => true]))->assertStatus(200);
        $this->assertSame($primero, AdminSetting::get(self::SELLO), 'Guardar con el flujo ya prendido no renueva el sello.');

        $this->actingAs($admin, 'sanctum')->putJson($endpoint, array_merge($payload, ['flujo_entrada_activo' => false]))->assertStatus(200);
        $this->assertSame($primero, AdminSetting::get(self::SELLO), 'Apagar no borra el sello.');

        $this->avanzar(600);
        $this->actingAs($admin, 'sanctum')->putJson($endpoint, array_merge($payload, ['flujo_entrada_activo' => true]))->assertStatus(200);
        $this->assertNotSame($primero, AdminSetting::get(self::SELLO), 'Volver a prender renueva el sello.');
    }

    /**
     * Caso 3. Lead `contactado` con el paso 1 entregado y el escudo de verificación prendido pregunta «¿Cuánto sale?»: la IA deja
     * una sugerencia RETENIDA con las dos formas. Su «dale» siguiente NO encola el literal (el lead recibiría las dos formas dos
     * veces): sigue el camino normal, la sugerencia vieja se descarta y se agenda la IA.
     *
     * @return void
     */
    public function test_con_una_sugerencia_retenida_no_se_toma_el_literal(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(120);

        $this->postear_kapso($telefono, 'Ferretería, 4000. ¿Cuánto sale?')->assertStatus(200);
        $this->fakear_ia([$this->paquete_ia('El plan Inicial sale $55.900 por mes.', 'contactado', KnowledgeGroundingGate::TIPO_AFIRMACION, ['precios_vigentes'])]);
        $retenida = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);
        $this->assertSame('sugerido', (string) $retenida->status);
        $this->assertStringContainsString('Tenés dos formas de arrancar', (string) $retenida->content, 'Precondición: la retenida ya trae las dos formas.');

        $this->avanzar(60);
        $this->postear_kapso($telefono, 'dale')->assertStatus(200);

        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No se toma el literal.');
        $this->assertNull(LeadMessage::query()->find($retenida->id), 'La sugerencia vieja queda descartada.');
        $this->assertGreaterThanOrEqual(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class)->count(), 'Sigue el camino normal: se agenda la IA.');
    }

    // ------------------------------------------------------------------------------------------
    // Helpers propios
    // ------------------------------------------------------------------------------------------

    /**
     * Deja el sello de encendido una hora antes del «ahora» del test.
     *
     * @return void
     */
    private function sellar_el_encendido_hace_una_hora(): void
    {
        AdminSetting::set(self::SELLO, Carbon::now()->subHour()->toIso8601String());
    }

    /**
     * Sugerencia pendiente de aprobación para el lead.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function crear_sugerencia_de_flujo(Lead $lead, string $texto): LeadMessage
    {
        return LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'content' => $texto,
            'status' => 'sugerido', 'is_followup' => false, 'is_status_event' => false,
            'requiere_verificacion' => false, 'suggested_lead_status' => null,
        ]);
    }
}
