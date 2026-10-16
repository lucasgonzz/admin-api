<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadOrigenService;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A un lead que vino de la web no se le manda su página como presentación (pedido de Lucas del
 * 13/10/2026, segunda tanda de M6c): «enviar la página solo debería hacerse si el lead vino del
 * anuncio que lo lleva a WhatsApp; si ya viene de la landing no hay que volver a enviársela».
 *
 * Lo que se protege:
 *
 *   1) El bloque DEMO DIRECTA de un lead `landing` NO le pide al agente pasar la página en la
 *      primera respuesta, y le dice que el link va recién al aceptar la demo, como el acceso.
 *   2) El de un lead de anuncio, de Instagram, sin origen reconocido o sin origen calculado sigue
 *      diciendo lo de siempre, byte a byte.
 *   3) La aceptación de la demo de un lead `landing` sigue llevando el link de la página: la guarda
 *      que retiene un «sí» sin el link no cambió para él.
 */
class PaginaDelLeadDeLaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de los casos que pasan por la generación: un martes a media mañana. */
    const AHORA = '2026-09-08 10:00:00';

    /** El renglón de siempre, tal cual estaba en el bloque antes de este cambio. */
    const REGLA_DE_SIEMPRE = "  - En tu PRIMERA respuesta al lead (la que reconoce lo que conto y le pide el nombre), pasale el link de su\n"
        . "    pagina como mensaje aparte (separado con ---), presentandola como algo para que vaya viendo lo que hacemos.\n"
        . "    Sin ofrecer la demo todavia, salvo que el lead la pida.\n";

    /** El encabezado de siempre de la página, sin el link (que va pegado atrás). */
    const ENCABEZADO_DE_SIEMPRE = '  Su pagina (copiar textual; se puede pasar en cualquier momento): ';

    /** El encabezado de la página para un lead de la web, sin el link. */
    const ENCABEZADO_DE_LA_WEB = '  Su pagina (copiar textual; es el acceso a su demo: va en el mensaje del si a la demo o si el la pide, no como presentacion): ';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        Mail::fake();

        config(['services.admin_spa.url' => 'https://admin.test']);

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | 1 y 2. El bloque DEMO DIRECTA
     |----------------------------------------------------------------------------------------- */

    /**
     * (1) Lead de la web: el bloque no le pide pasar la página en la primera respuesta, le dice que
     * NO se la pase como presentación y que el link va al aceptar la demo, como el acceso. La página
     * sigue en el bloque (el agente la necesita para la aceptación) y la regla de la aceptación, con
     * los dos links, sigue igual.
     *
     * @return void
     */
    public function test_al_lead_de_la_web_no_se_le_pide_pasar_la_pagina_en_la_primera_respuesta(): void
    {
        /* Con una instancia libre: sin ninguna, el bloque no pide el mail ni pasa links (otra rama). */
        $this->crear_demo(1);

        $lead     = $this->crear_lead_con_origen(LeadOrigenService::ORIGEN_LANDING);
        $contexto = $this->contexto_de_demo_directa($lead);

        $this->assertStringContainsString('DEMO DIRECTA', $contexto);
        $this->assertStringNotContainsString('En tu PRIMERA respuesta al lead', $contexto);
        $this->assertStringNotContainsString('presentandola como algo para que vaya viendo', $contexto);
        $this->assertStringContainsString('VINO DE LA WEB', $contexto);
        $this->assertStringContainsString('NO le pases el link de su pagina como', $contexto);
        $this->assertStringContainsString('va recien cuando acepta la demo', $contexto);
        $this->assertStringContainsString('el acceso a su demo', $contexto);
        $this->assertStringContainsString('esto pisa lo que digan los recursos', $contexto);

        /* El encabezado de la página tampoco dice "en cualquier momento": es el acceso a su demo. */
        $this->assertStringContainsString(self::ENCABEZADO_DE_LA_WEB . $lead->demo_experiencia_url . "\n", $contexto);
        $this->assertStringNotContainsString('se puede pasar en cualquier momento', $contexto);

        /* La página sigue en el bloque, y la aceptación sigue mandando los dos links. */
        $this->assertStringContainsString((string) $lead->demo_experiencia_url, $contexto);
        $this->assertStringContainsString('Cuando acepta hacerla ahora', $contexto);
        $this->assertStringContainsString('links de arriba (la pagina', $contexto);
    }

    /**
     * (2) Cualquier otro origen —anuncio, Instagram, desconocido, o sin calcular— recibe el renglón
     * de siempre, byte a byte, y nada del de la web.
     *
     * @dataProvider origenes_que_reciben_la_pagina
     *
     * @param string|null $origen
     *
     * @return void
     */
    public function test_al_lead_que_no_vino_de_la_web_se_le_sigue_pidiendo_la_pagina($origen): void
    {
        $lead     = $this->crear_lead_con_origen($origen);
        $contexto = $this->contexto_de_demo_directa($lead);

        $this->assertStringContainsString(self::REGLA_DE_SIEMPRE, $contexto);
        $this->assertStringContainsString(self::ENCABEZADO_DE_SIEMPRE . $lead->demo_experiencia_url . "\n", $contexto);
        $this->assertStringNotContainsString('VINO DE LA WEB', $contexto);
        $this->assertStringNotContainsString('es el acceso a su demo', $contexto);
        $this->assertSame(self::REGLA_DE_SIEMPRE, LeadOrigenService::regla_de_la_pagina_al_primer_contacto($lead));
        $this->assertSame(
            self::ENCABEZADO_DE_SIEMPRE . 'https://x.test/p' . "\n",
            LeadOrigenService::encabezado_de_la_pagina($lead, 'https://x.test/p')
        );
    }

    /**
     * (2-bis) El camino real: el lead nace sin origen, su PRIMER mensaje es la frase del botón de la
     * web y el origen lo escribe el observer con un UPDATE (no viene en el alta). El bloque que arma
     * el agente con el lead leído de la base, como lo lee el job de la sugerencia, ya es el de la web.
     *
     * @return void
     */
    public function test_el_primer_mensaje_con_la_frase_de_la_web_cambia_el_bloque(): void
    {
        /* Las frases del «sí» por defecto (que incluyen la de la web): la base de testing puede traer
           una lista guardada de una verificación anterior. */
        AdminSetting::whereIn('key', [
            LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTO,
            LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS,
        ])->delete();

        $lead = $this->crear_lead_con_origen(null);
        $this->assertNull($lead->origen);

        $this->crear_mensaje_del_lead($lead, LeadOrigenService::FRASE_LANDING);

        /* El modelo en memoria no se entera del UPDATE del observer: el job lee el lead de la base. */
        $this->assertNull($lead->origen);
        $de_la_base = Lead::query()->findOrFail($lead->id);
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $de_la_base->origen);

        $contexto = $this->contexto_de_demo_directa($de_la_base);

        $this->assertStringNotContainsString(self::REGLA_DE_SIEMPRE, $contexto);
        $this->assertStringNotContainsString('En tu PRIMERA respuesta al lead', $contexto);
        $this->assertStringContainsString('VINO DE LA WEB', $contexto);
        $this->assertStringContainsString(self::ENCABEZADO_DE_LA_WEB . $de_la_base->demo_experiencia_url . "\n", $contexto);
        /* Y como es el último mensaje del lead y es la frase exacta, el bloque lo marca como un sí. */
        $this->assertStringContainsString('es un SI a la demo', $contexto);
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public function origenes_que_reciben_la_pagina(): array
    {
        return [
            'anuncio de WhatsApp' => [LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP],
            'desconocido'         => [LeadOrigenService::ORIGEN_DESCONOCIDO],
            'Instagram'           => [LeadOrigenService::ORIGEN_INSTAGRAM],
            'sin calcular'        => [null],
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | 3. La aceptación sigue llevando el link
     |----------------------------------------------------------------------------------------- */

    /**
     * (3a) El «sí» de un lead de la web CON el link de la página como acceso: la guarda de la
     * aceptación no lo retiene por la página, y el texto que queda para mandar lleva el link.
     *
     * @return void
     */
    public function test_la_aceptacion_del_lead_de_la_web_con_el_link_no_se_retiene_por_la_pagina(): void
    {
        $lead = $this->crear_lead_con_origen(LeadOrigenService::ORIGEN_LANDING, 'calificado');
        $this->crear_mensaje_del_lead($lead, 'Dale, hagámosla ahora.');
        $url  = (string) $lead->demo_experiencia_url;
        $this->assertNotSame('', $url);

        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Dale, te la dejo lista en diez minutos. Acá tenés el acceso a tu demo: ' . $url . ' y la tienda: https://tienda-1.test',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => 'Aceptó la demo.',
            'agendar_demo'     => true,
        ]);
        $this->crear_demo(1);

        $mensaje = (new LeadAiService())->generate_suggestion($lead->fresh(), false);

        $this->assertTrue((bool) ($mensaje->pending_actions['agendar_demo']['ahora'] ?? false), 'La aceptación tiene que agendar la demo.');
        $this->assertStringContainsString($url, (string) $mensaje->content, 'El «sí» de un lead de la web lleva el link de su página.');
        $this->assertStringNotContainsString('no trae el link de la página', (string) $mensaje->ai_reasoning);
    }

    /**
     * (3b) El «sí» de un lead de la web SIN el link de la página se retiene igual que el de
     * cualquier lead: a la aceptación el link le hace falta, venga de donde venga.
     *
     * @return void
     */
    public function test_la_aceptacion_del_lead_de_la_web_sin_el_link_se_retiene(): void
    {
        $lead = $this->crear_lead_con_origen(LeadOrigenService::ORIGEN_LANDING, 'calificado');
        $this->crear_mensaje_del_lead($lead, 'Dale, hagámosla ahora.');

        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Dale, te la dejo lista en diez minutos. Acá tenés la tienda: https://tienda-1.test',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => 'Aceptó la demo.',
            'agendar_demo'     => true,
        ]);
        $this->crear_demo(1);

        $mensaje = (new LeadAiService())->generate_suggestion($lead->fresh(), false);

        $this->assertTrue((bool) ($mensaje->pending_actions['requiere_verificacion'] ?? false));
        $this->assertStringContainsString('no trae el link de la página', (string) $mensaje->ai_reasoning);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Lead de la dinámica nueva, con link de página (teléfono + URL del admin-spa) y el origen
     * pedido. El origen va en el alta: así el observer del primer mensaje no lo recalcula.
     *
     * @param string|null $origen
     * @param string      $status
     *
     * @return Lead
     */
    private function crear_lead_con_origen(?string $origen, string $status = 'contactado'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '549351' . random_int(1000000, 9999999);
        $lead->status       = $status;
        $lead->origen       = $origen;
        $lead->save();

        /* Después del save: el hook `creating` estampa la dinámica por defecto. */
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Mensaje entrante del lead.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function crear_mensaje_del_lead(Lead $lead, string $texto): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'lead';
        $mensaje->status                = 'enviado';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = false;
        $mensaje->content               = $texto;
        $mensaje->save();

        return $mensaje;
    }

    /**
     * El bloque DEMO DIRECTA, por reflexión (el método es privado).
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
     * Fake de la API de Anthropic, prompt base, system base y el recurso `demo_agenda` de la
     * dinámica nueva con el marcador de la demo directa: lo que `generate_suggestion()` necesita
     * para llegar hasta la llamada sin salir a ningún lado (mismo armado que DemoDirectaTest).
     *
     * @param array<string, mixed> $respuesta_del_modelo JSON que "devuelve" el modelo.
     *
     * @return void
     */
    private function sembrar_entorno_del_agente(array $respuesta_del_modelo): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode($respuesta_del_modelo, JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => 'System base de prueba.',
            'synced_at' => AppTime::now(),
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => "# Agenda de la demo\n\n## DEMO DIRECTA\n\nOfrecé \"te la puedo tener lista en diez minutos\" y devolvé agendar_demo: {\"ahora\": true}.\nMarcador: " . LeadAiService::MARCADOR_DEMO_DIRECTA . "\n",
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * Instancia de demo con tienda propia (`https://tienda-N.test`).
     *
     * @param int $n
     *
     * @return Demo
     */
    private function crear_demo(int $n): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-' . $n . '.test';
        $demo->erp_api_url       = 'https://demo-' . $n . '-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-' . $n . '.test';
        $demo->ecommerce_api_url = 'https://tienda-' . $n . '-api.test';
        $demo->save();

        return $demo;
    }
}
