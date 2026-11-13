<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\MessageVariant;
use App\Models\TrialInstance;
use App\Models\WhatsappConfig;
use App\Services\ContractPreciosService;
use App\Services\LeadAiService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadOrigenService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Database\Seeders\MessageVariantLandingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La oferta de la prueba gratis al lead que llega desde la WEB (misión martin-link-prueba-landing,
 * 13/11/2026): en vez del auto landing y la variante que le pregunta el rubro, Martín le manda dos
 * mensajes con la prueba gratis y el link para crearla. Después, el agente sigue sin calificar ni
 * empujar la demo, y no le reenvía el link al que ya tiene su prueba.
 */
class OfertaDePruebaAlLeadDeLaLandingTest extends TestCase
{
    use DatabaseTransactions;

    const AHORA = '2026-11-13 12:00:00';

    /** Los dos mensajes tal como los propuso Lucas el 13/11/2026 (con los precios de hoy). */
    const MENSAJE_1_DE_LUCAS = "¡Hola! Soy Martín, de ComercioCity 👋\n"
        . "\n"
        . "Lo mejor es que lo pruebes con tu propio negocio: tenés 7 días gratis, sin tarjeta, con el sistema de gestión, la tienda online y los agentes de IA funcionando.\n"
        . "\n"
        . "Si te gusta y seguís, el sistema de gestión y la implementación son gratis: te cargamos tus productos nosotros y pagás solo el abono mensual, desde $27.950 (este mes, con 50 % off los primeros 3 meses).";

    const MENSAJE_2_DE_LUCAS = "Tu prueba la creás desde acá, en 2 minutos 👇\n"
        . "https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin\n"
        . "\n"
        . "Ponés tu nombre, tu WhatsApp, el nombre del negocio y una contraseña, y entrás en el momento.\n"
        . "\n"
        . "Cualquier duda me escribís por acá. Y si antes de arrancar querés saber si te sirve para algo puntual de tu negocio, preguntame ahora y lo vemos.";

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        Http::fake();
        Mail::fake();

        /* Settings explícitas: la base del slot puede tener otros valores guardados. */
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS, '60');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING, '10');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING, '¡Hola! 👋 Enseguida Martín se suma a la conversación.');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_WITHOUT_NAME, '¡Hola! 👋 Un asesor comercial se pondrá en contacto a la brevedad.');
        AdminSetting::set(LeadOfertaDePruebaService::KEY_ACTIVA, '1');
        AdminSetting::set(LeadOfertaDePruebaService::KEY_MENSAJE_1, '');
        AdminSetting::set(LeadOfertaDePruebaService::KEY_MENSAJE_2, '');
        AdminSetting::set(LeadOfertaDePruebaService::KEY_LINK, '');
        AdminSetting::set(PruebaSettings::KEY_DIAS_DEFAULT, '7');
        AdminSetting::set(PruebaSettings::KEY_MAX_ACTIVAS, '1000');
        /* El alta pide una instancia libre: el pool del slot puede no tener ninguna. */
        TrialInstance::create(['nombre' => 'ol-' . Str::lower(Str::random(6)), 'estado' => TrialInstance::ESTADO_LIBRE,
            'erp_vps_path' => 'prueba-sintetica', 'ecommerce_vps_path' => 'tienda-sintetica']);

        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME)->update(['active' => false]);
        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING)->delete();
        (new MessageVariantLandingSeeder())->run();
        MessageVariant::create([
            'slug'          => 'test_welcome_generico_oferta',
            'name'          => 'Genérica de prueba',
            'message_type'  => LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME,
            'body'          => 'Hola {nombre}! Soy Martín. ¿A qué se dedica el negocio?',
            'active'        => true,
            'delay_seconds' => null,
        ]);
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
     * (1) La frase de la web: sin auto, sin variante, job a los 10 s con la oferta; y el job manda los
     *     dos mensajes de Lucas, en orden, con su `system_message_kind`.
     *
     * @return void
     */
    public function test_la_frase_de_la_web_manda_la_oferta_de_la_prueba_en_dos_mensajes(): void
    {
        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_welcome_and_schedule_presentation($lead, null, LeadOrigenService::FRASE_LANDING);

        $this->assertCount(0, $espia->textos, 'Sin auto: el primer mensaje de Martín ya saluda.');
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count());
        $this->assertNull($lead->fresh()->welcome_variant_id, 'La oferta no usa variante A/B.');

        $job = $this->job_encolado();
        $this->assertTrue($job->es_oferta_de_prueba());
        $this->assertTrue(Carbon::parse($job->delay)->equalTo(now()->addSeconds(10)));

        $job->handle($servicio);

        $this->assertCount(2, $espia->textos);
        $this->assertSame(self::MENSAJE_1_DE_LUCAS, $espia->textos[0]['body']);
        $this->assertSame(self::MENSAJE_2_DE_LUCAS, $espia->textos[1]['body']);

        $mensajes = LeadMessage::where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertCount(2, $mensajes);
        $this->assertSame('sistema', $mensajes[0]->sender);
        $this->assertSame(LeadWhatsappOnboardingService::KIND_WELCOME, $mensajes[0]->system_message_kind);
        $this->assertSame(self::MENSAJE_1_DE_LUCAS, $mensajes[0]->content);
        $this->assertSame(LeadWhatsappOnboardingService::KIND_PRUEBA_LINK, $mensajes[1]->system_message_kind);
        $this->assertSame(self::MENSAJE_2_DE_LUCAS, $mensajes[1]->content);

        $this->assertTrue($servicio->has_welcome_been_sent($lead), 'El contactado determinístico mira la presentación.');
        $this->assertTrue(LeadOfertaDePruebaService::ya_se_ofrecio($lead));
    }

    /**
     * (2) Las tres frases de la web (la vieja, la de M6a y la nueva de la prueba) son origen `landing`
     *     y disparan la oferta; también con un saludo adelante o algo escrito atrás (contiene). La de
     *     Instagram sigue siendo Instagram y no dispara la oferta.
     *
     * @return void
     */
    public function test_las_frases_de_la_web_viejas_y_la_nueva_son_landing_y_disparan_la_oferta(): void
    {
        $this->assertSame('Hola, quiero probar ComercioCity', LeadOrigenService::FRASE_LANDING_PRUEBA);

        $de_la_web = [
            LeadOrigenService::FRASE_LANDING,
            LeadOrigenService::FRASE_LANDING_VIEJA,
            LeadOrigenService::FRASE_LANDING_PRUEBA,
            '¡Buenas! Hola, quiero probar ComercioCity',
            'hola quiero probar comerciocity!!',
            LeadOrigenService::FRASE_LANDING_PRUEBA . ', tengo una ferretería',
            LeadOrigenService::FRASE_LANDING . ', pero la semana que viene',
        ];
        foreach ($de_la_web as $texto) {
            $this->assertSame(LeadOrigenService::ORIGEN_LANDING, LeadOrigenService::origen_por_frase($texto), $texto);
            $this->assertTrue(LeadOfertaDePruebaService::aplica_al_primer_mensaje($this->crear_lead_nuevo(), $texto), $texto);
        }

        /* La frase nueva también es la de un botón (exacta), como las otras. */
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadOrigenService::FRASE_LANDING_PRUEBA));

        $this->assertSame(LeadOrigenService::ORIGEN_INSTAGRAM, LeadOrigenService::origen_por_frase(LeadOrigenService::FRASE_INSTAGRAM));
        $this->assertFalse(LeadOfertaDePruebaService::aplica_al_primer_mensaje($this->crear_lead_nuevo(), LeadOrigenService::FRASE_INSTAGRAM));
        $this->assertFalse(LeadOfertaDePruebaService::aplica_al_primer_mensaje($this->crear_lead_nuevo(), 'Hola, tengo una ferretería y quiero info'));
        $this->assertFalse(LeadOfertaDePruebaService::aplica_al_primer_mensaje($this->crear_lead_nuevo(), null));

        /* «Quiero probar» no es un «sí a la demo»: no entra en las frases del agente. */
        foreach (LeadOrigenService::frases_de_cta() as $frase) {
            $this->assertNotSame(LeadOrigenService::normalizar(LeadOrigenService::FRASE_LANDING_PRUEBA), LeadOrigenService::normalizar($frase));
        }
    }

    /**
     * (3) El lead que escribe la frase nueva nace con origen `landing` (el observer del primer mensaje).
     *
     * @return void
     */
    public function test_el_primer_mensaje_con_la_frase_nueva_deja_al_lead_con_origen_landing(): void
    {
        $lead = $this->crear_lead_nuevo();
        LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'content' => LeadOrigenService::FRASE_LANDING_PRUEBA,
            'is_status_event' => false, 'status' => 'enviado', 'sent_at' => now(),
            'whatsapp_message_id' => 'wamid.oferta.' . Str::random(10),
        ]);

        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->fresh()->origen);
    }

    /**
     * (4) Instagram, el mensaje libre, el interruptor apagado y el pool sin lugar siguen con el
     *     onboarding de la demo, byte a byte como hasta hoy.
     *
     * @return void
     */
    public function test_lo_que_no_es_la_web_o_no_puede_recibir_la_oferta_sigue_como_hasta_hoy(): void
    {
        /* Instagram: auto landing + variante welcome_landing. */
        $this->assertOnboardingDeLaDemo(LeadOrigenService::FRASE_INSTAGRAM, '¡Hola! 👋 Enseguida Martín se suma a la conversación.', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING);

        /* Mensaje libre: auto genérico + variante welcome. */
        $this->assertOnboardingDeLaDemo('Hola, tengo una ferretería', '¡Hola! 👋 Un asesor comercial se pondrá en contacto a la brevedad.', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME);

        /* Interruptor apagado: la web vuelve al onboarding landing de la demo. */
        AdminSetting::set(LeadOfertaDePruebaService::KEY_ACTIVA, '0');
        $this->assertOnboardingDeLaDemo(LeadOrigenService::FRASE_LANDING, '¡Hola! 👋 Enseguida Martín se suma a la conversación.', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING);
        AdminSetting::set(LeadOfertaDePruebaService::KEY_ACTIVA, '1');

        /* Pool sin lugar: el alta rechazaría, así que tampoco se manda el link. */
        TrialInstance::create(['nombre' => 'oa-' . Str::lower(Str::random(6)), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => 'prueba-sintetica', 'ecommerce_vps_path' => 'tienda-sintetica']);
        AdminSetting::set(PruebaSettings::KEY_MAX_ACTIVAS, (string) max(1, PruebaPoolService::activas()));
        $this->assertFalse(PruebaPoolService::hay_capacidad());
        $this->assertOnboardingDeLaDemo(LeadOrigenService::FRASE_LANDING_PRUEBA, '¡Hola! 👋 Enseguida Martín se suma a la conversación.', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING);
    }

    /**
     * (5) Idempotencia: el segundo armado (la «recuperación» de la simulación) y el segundo job no
     *     duplican nada; y si el lead ya tiene su prueba cuando corre el job, no se manda nada.
     *
     * @return void
     */
    public function test_no_se_duplica_y_no_se_manda_al_que_ya_tiene_su_prueba(): void
    {
        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_welcome_and_schedule_presentation($lead, null, LeadOrigenService::FRASE_LANDING_PRUEBA);
        $servicio->send_presentation_message($lead, null, true);
        $servicio->send_presentation_message($lead, null, true);
        $servicio->send_welcome_and_schedule_presentation($lead, null, LeadOrigenService::FRASE_LANDING_PRUEBA);

        $this->assertCount(2, $espia->textos);
        $this->assertSame(2, LeadMessage::where('lead_id', $lead->id)->count());
        Queue::assertPushed(SendLeadPresentationMessageJob::class, 1);

        /* El que creó su prueba en los segundos de la demora. */
        $otro = $this->crear_lead_nuevo();
        $servicio->send_welcome_and_schedule_presentation($otro, null, LeadOrigenService::FRASE_LANDING);
        /* Como lo deja el alta de la prueba (por query builder): `prueba_activa`. El job lee el lead
           fresco y no manda nada. */
        Lead::query()->whereKey($otro->id)->update(['status' => LeadPipelineStatus::PRUEBA_ACTIVA]);
        $this->assertTrue(LeadOfertaDePruebaService::tiene_prueba($otro->fresh()));
        $this->job_encolado_de($otro)->handle($servicio);
        $this->assertSame(0, LeadMessage::where('lead_id', $otro->id)->where('sender', 'sistema')->count());

        /* Y al que ya la tiene no se le arma la oferta. */
        $this->assertFalse(LeadOfertaDePruebaService::aplica_al_primer_mensaje($otro->fresh(), LeadOrigenService::FRASE_LANDING));
    }

    /**
     * (6) El precio y la promo salen de ContractPreciosService, y los textos se pueden pisar por
     *     admin_settings conservando los placeholders.
     *
     * @return void
     */
    public function test_el_precio_sale_del_plan_y_el_texto_se_puede_pisar_por_ajuste(): void
    {
        $esperado = '$' . number_format(round(ContractPreciosService::abono_con_descuento_inicial(
            (float) ContractPreciosService::PLANES_GESTION['inicial']['abono']
        )), 0, ',', '.');
        $this->assertSame($esperado, LeadOfertaDePruebaService::precio_desde());
        $this->assertSame(' (este mes, con ' . ContractPreciosService::DESCUENTO_INICIAL['porcentaje'] . ' % off los primeros '
            . ContractPreciosService::DESCUENTO_INICIAL['meses'] . ' meses)', LeadOfertaDePruebaService::promo());

        AdminSetting::set(LeadOfertaDePruebaService::KEY_MENSAJE_1, 'Probalo {dias_prueba} días. Desde {precio_desde}{promo}.');
        AdminSetting::set(LeadOfertaDePruebaService::KEY_MENSAJE_2, 'Entrá: {link_prueba}');
        AdminSetting::set(LeadOfertaDePruebaService::KEY_LINK, 'https://comerciocity.com/prueba?utm_source=whatsapp');

        $mensajes = LeadOfertaDePruebaService::mensajes();
        $this->assertSame('Probalo 7 días. Desde ' . $esperado . LeadOfertaDePruebaService::promo() . '.', $mensajes[0]);
        $this->assertSame('Entrá: https://comerciocity.com/prueba?utm_source=whatsapp', $mensajes[1]);
    }

    /**
     * (7) El agente: al lead al que se le hizo la oferta le llega el bloque PRUEBA GRATIS AL FINAL del
     *     contexto (pisa la demo), con el link; al que no, nada; al que ya tiene su prueba, el bloque
     *     que le prohíbe reenviar el link.
     *
     * @return void
     */
    public function test_el_agente_recibe_el_bloque_de_la_prueba_al_final_del_contexto(): void
    {
        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);

        $sin_oferta = $this->crear_lead_nuevo();
        $this->assertSame('', LeadOfertaDePruebaService::bloque_para_el_agente($sin_oferta));
        $this->assertStringNotContainsString('PRUEBA GRATIS', $this->contexto_del_turno($sin_oferta));

        $lead = $this->crear_lead_nuevo();
        $this->mensaje_del_lead($lead, LeadOrigenService::FRASE_LANDING);
        $servicio->send_presentation_message($lead, null, true);
        $this->mensaje_del_lead($lead, '¿Y la tienda online también la puedo probar?');

        $contexto = $this->contexto_del_turno($lead->fresh());
        $bloque   = strpos($contexto, 'PRUEBA GRATIS (lead de la web');
        $this->assertNotFalse($bloque);
        $this->assertGreaterThan(strpos($contexto, 'Conversación del lead:'), $bloque);
        $this->assertStringEndsWith('¿Qué respuesta sugerís y en qué estado debería quedar el lead?', $contexto);
        $this->assertStringContainsString('https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin', substr($contexto, $bloque));
        $this->assertStringContainsString('NO le pidas datos para calificarlo', substr($contexto, $bloque));
        $this->assertStringContainsString('NO devuelvas agendar_demo', substr($contexto, $bloque));
        $this->assertStringContainsString('pida EXPLICITAMENTE una demo, una llamada o una videollamada', substr($contexto, $bloque));

        Lead::query()->whereKey($lead->id)->update(['status' => LeadPipelineStatus::PRUEBA_ACTIVA]);
        $bloque_con_prueba = LeadOfertaDePruebaService::bloque_para_el_agente($lead->fresh());
        $this->assertStringContainsString('YA CREO SU PRUEBA GRATIS', $bloque_con_prueba);
        $this->assertStringNotContainsString('comerciocity.com/prueba', $bloque_con_prueba);
    }

    /**
     * (9) Si el envío del link falla (el 409 de Kapso de dos envíos seguidos), se reintenta una vez; y
     *     si el job se cayó después del primer mensaje, su reintento manda solo el del link.
     *
     * @return void
     */
    public function test_si_falla_el_link_se_reintenta_y_el_reintento_del_job_manda_solo_lo_que_falta(): void
    {
        /* El 409 de Kapso en el segundo envío: el primer intento del link falla y el reintento sale. */
        $espia    = $this->espiar_whatsapp(['falla_el_intento' => [2]]);
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_presentation_message($lead, null, true);

        $this->assertCount(3, $espia->textos, 'Mensaje 1, intento fallido del link y reintento.');
        $this->assertSame(self::MENSAJE_2_DE_LUCAS, $espia->textos[2]['body']);
        $link = LeadMessage::where('lead_id', $lead->id)->where('system_message_kind', LeadWhatsappOnboardingService::KIND_PRUEBA_LINK)->firstOrFail();
        $this->assertNotNull($link->whatsapp_message_id, 'El reintento dejó el id de WhatsApp.');
        $this->assertSame(2, LeadMessage::where('lead_id', $lead->id)->count());

        /* El job se cayó después del primer mensaje: el reintento manda SOLO el del link. */
        $espia2    = $this->espiar_whatsapp();
        $servicio2 = new LeadWhatsappOnboardingService($espia2);
        $otro      = $this->crear_lead_nuevo();
        $servicio2->persist_and_send_system_message($otro, self::MENSAJE_1_DE_LUCAS, LeadWhatsappOnboardingService::KIND_WELCOME);
        $servicio2->send_presentation_message($otro->fresh(), null, true);
        $servicio2->send_presentation_message($otro->fresh(), null, true);

        $this->assertSame([self::MENSAJE_1_DE_LUCAS, self::MENSAJE_2_DE_LUCAS], array_column($espia2->textos, 'body'));
    }

    /**
     * (10) El bloque se calla cuando el lead ya pidió la demo o la tiene: ahí manda ese flujo. Y un
     *      texto guardado sin el link no puede reemplazar al mensaje del link.
     *
     * @return void
     */
    public function test_el_bloque_se_calla_cuando_pidio_la_demo_y_el_override_sin_link_cae_al_de_siempre(): void
    {
        $servicio = new LeadWhatsappOnboardingService($this->espiar_whatsapp());
        $lead     = $this->crear_lead_nuevo();
        $servicio->send_presentation_message($lead, null, true);

        foreach (['contactado', 'calificado'] as $estado) {
            Lead::query()->whereKey($lead->id)->update(['status' => $estado]);
            $this->assertStringContainsString('PRUEBA GRATIS (lead de la web', LeadOfertaDePruebaService::bloque_para_el_agente($lead->fresh()), $estado);
        }
        foreach (['solicita_disponibilidad', 'demo_agendada', 'demo_en_curso', 'demo_realizada', 'closer_activo', 'cerrado_perdido'] as $estado) {
            Lead::query()->whereKey($lead->id)->update(['status' => $estado]);
            $this->assertSame('', LeadOfertaDePruebaService::bloque_para_el_agente($lead->fresh()), $estado);
        }

        $con_demo = $lead->fresh();
        $con_demo->status  = 'contactado';
        $con_demo->demo_id = 999999;
        $this->assertSame('', LeadOfertaDePruebaService::bloque_para_el_agente($con_demo), 'Con demo asignada.');

        /* El primer mensaje del botón no cuenta como pedido de demo, y el link va completo. */
        Lead::query()->whereKey($lead->id)->update(['status' => 'contactado']);
        $bloque = LeadOfertaDePruebaService::bloque_para_el_agente($lead->fresh());
        $this->assertStringContainsString('NO cuenta como pedido de demo', $bloque);
        $this->assertStringContainsString('nunca "comerciocity.com" a secas', $bloque);
        $this->assertStringContainsString('no le pidas el nombre', $bloque);

        AdminSetting::set(LeadOfertaDePruebaService::KEY_MENSAJE_2, 'Creala desde la web, cuando quieras.');
        $this->assertSame(self::MENSAJE_2_DE_LUCAS, LeadOfertaDePruebaService::mensajes()[1]);
        AdminSetting::set(LeadOfertaDePruebaService::KEY_MENSAJE_1, '   ');
        $this->assertSame(self::MENSAJE_1_DE_LUCAS, LeadOfertaDePruebaService::mensajes()[0]);
    }

    /**
     * (11) Sin ninguna instancia libre ni armándose, el alta rechazaría: no se manda el link.
     *
     * @return void
     */
    public function test_sin_instancia_libre_en_el_pool_no_se_ofrece_el_link(): void
    {
        TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])
            ->update(['estado' => TrialInstance::ESTADO_FALLIDA]);
        $this->assertSame(0, PruebaPoolService::libres_o_creando());

        $this->assertFalse(LeadOfertaDePruebaService::aplica_al_primer_mensaje($this->crear_lead_nuevo(), LeadOrigenService::FRASE_LANDING));
    }

    /**
     * (8) Punta a punta por el webhook real: el lead nuevo que toca el botón de la web (con el código de
     *     atribución atrás) no recibe el auto y queda con el job de la oferta; el job manda los dos
     *     mensajes. Ningún envío sale de la máquina (Http::fake + test_mode).
     *
     * @return void
     */
    public function test_webhook_real_del_lead_de_la_web_encola_la_oferta(): void
    {
        WhatsappConfig::query()->update(['is_active' => false]);
        WhatsappConfig::create(['kapso_api_key' => 'fixture-local', 'phone_number_id' => '1234567890', 'webhook_secret' => 'firma-local-oferta', 'is_active' => true, 'test_mode' => true]);

        $phone = '+54911' . random_int(10000000, 99999999);
        $texto = LeadOrigenService::FRASE_LANDING_PRUEBA . ' [CC:' . str_repeat('a', 32) . ']';
        $body  = json_encode([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $phone, 'contact_name' => 'Contacto de prueba'],
            'message'      => ['id' => 'wamid.oferta.' . bin2hex(random_bytes(8)), 'from' => $phone, 'type' => 'text', 'text' => ['body' => $texto], 'timestamp' => (string) now()->getTimestamp()],
        ]);
        $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, 'firma-local-oferta')], $body)->assertStatus(200);

        $lead = Lead::query()->where('phone', $phone)->firstOrFail();
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen);
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->where('sender', 'sistema')->count(), 'Sin auto.');

        $job = $this->job_encolado();
        $this->assertTrue($job->es_oferta_de_prueba());

        $espia = $this->espiar_whatsapp();
        $job->handle(new LeadWhatsappOnboardingService($espia));
        $this->assertSame([self::MENSAJE_1_DE_LUCAS, self::MENSAJE_2_DE_LUCAS], array_column($espia->textos, 'body'));
        $this->assertSame($phone, $espia->textos[0]['to']);
    }

    /**
     * Arma el onboarding con `$texto` y verifica que fue el de la demo: el auto `$auto`, un job sin
     * oferta y una variante del tipo `$tipo_variante` asignada.
     *
     * @param string $texto
     * @param string $auto
     * @param string $tipo_variante
     *
     * @return void
     */
    private function assertOnboardingDeLaDemo(string $texto, string $auto, string $tipo_variante): void
    {
        Queue::fake();
        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_welcome_and_schedule_presentation($lead, null, $texto);

        $this->assertCount(1, $espia->textos, $texto);
        $this->assertSame($auto, $espia->textos[0]['body'], $texto);
        $job = $this->job_encolado();
        $this->assertFalse($job->es_oferta_de_prueba(), $texto);
        $variante = MessageVariant::find($lead->fresh()->welcome_variant_id);
        $this->assertNotNull($variante, $texto);
        $this->assertSame($tipo_variante, $variante->message_type, $texto);
    }

    /**
     * @return SendLeadPresentationMessageJob El único job de presentación encolado.
     */
    private function job_encolado(): SendLeadPresentationMessageJob
    {
        $jobs = Queue::pushed(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs);

        return $jobs->first();
    }

    /**
     * @param Lead $lead
     *
     * @return SendLeadPresentationMessageJob El job de presentación encolado para ese lead.
     */
    private function job_encolado_de(Lead $lead): SendLeadPresentationMessageJob
    {
        $propiedad = new \ReflectionProperty(SendLeadPresentationMessageJob::class, 'lead_id');
        $propiedad->setAccessible(true);

        $jobs = Queue::pushed(SendLeadPresentationMessageJob::class, function (SendLeadPresentationMessageJob $job) use ($propiedad, $lead) {
            return (int) $propiedad->getValue($job) === (int) $lead->id;
        });
        $this->assertCount(1, $jobs);

        return $jobs->first();
    }

    /**
     * El contexto completo del turno del agente (build_user_content), sin la demo directa.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto_del_turno(Lead $lead): string
    {
        $service = new class extends LeadAiService {
            public function contexto(Lead $lead): string
            {
                return $this->build_user_content($lead, false, '', null, false);
            }
        };

        return $service->contexto($lead);
    }

    /**
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function mensaje_del_lead(Lead $lead, string $texto): LeadMessage
    {
        return LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'content' => $texto,
            'is_status_event' => false, 'status' => 'enviado', 'sent_at' => now(),
            'whatsapp_message_id' => 'wamid.oferta.' . Str::random(12),
        ]);
    }

    /**
     * @return Lead
     */
    private function crear_lead_nuevo(): Lead
    {
        return Lead::create([
            'phone'  => '+54911' . random_int(10000000, 99999999),
            'status' => 'nuevo',
        ]);
    }

    /**
     * @return WhatsappSendService Espía: guarda cada texto, no sale nada a Kapso.
     */
    private function espiar_whatsapp(array $opciones = []): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            /** @var array<int, int> Números de intento (desde 1) que devuelven null, como un 409. */
            public $falla_el_intento = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                if (in_array(count($this->textos), $this->falla_el_intento, true)) {
                    return null;
                }

                return 'wamid.oferta.texto.' . bin2hex(random_bytes(8));
            }
        };
        $espia->falla_el_intento = isset($opciones['falla_el_intento']) ? $opciones['falla_el_intento'] : [];

        return $espia;
    }
}
