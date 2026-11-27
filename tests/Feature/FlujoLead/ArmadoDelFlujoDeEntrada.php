<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageVariant;
use App\Models\SyncedGithubFile;
use App\Models\TrialInstance;
use App\Models\WhatsappConfig;
use App\Services\LeadAiService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\Prueba\PruebaSettings;
use App\Services\WhatsappProtocolService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Database\Seeders\MessageVariantLandingSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Armado común de los tests de aceptación de E2 de la misión embudo-whatsapp-precios (flujo de entrada de
 * Martín, estados del embudo y tarea del embudo).
 *
 * No es un test (el nombre no termina en `Test`, PHPUnit no lo corre solo): lo usan por `use`
 * `FlujoDeEntradaNuevoTest`, `PuntosObligatoriosDelFlujoTest`, `EstadosDelEmbudoTest` y
 * `TareaParaElVendedorTest`. Es un trait y no una clase base porque `TareaParaElVendedorTest` tiene que
 * extender `Prueba\BaseDePruebaGratuita` (para el alta de la prueba) y los demás extienden `TestCase`.
 *
 * Qué deja resuelto:
 *  - El reloj fijo, las colas y el mail falsos, la config de WhatsApp en modo prueba (ningún envío sale de la
 *    máquina) y las perillas del onboarding con valores explícitos, porque la base del slot puede tener otros.
 *  - La IA simulada: un único `Http::fake` con un stub para `api.anthropic.com` que va sacando respuestas de
 *    una cola (`fakear_ia`) y otro que atiende todo lo demás. Va en UN solo `Http::fake` porque los stubs de
 *    Laravel se acumulan y gana el primero que responde: un `Http::fake()` suelto antes taparía a la IA.
 *  - Los textos literales de Lucas (§9 del informe, PLAN §2.2) con los precios por defecto de E1, para que cada
 *    test compare contra el texto y no contra una copia del código bajo prueba.
 *  - Un espía de `WhatsappSendService` que guarda cada texto y se registra en el contenedor, así los jobs
 *    (`app()->call([$job, 'handle'])`) lo usan sin que el test conozca la firma de `handle`.
 *
 * 🔴 Las claves de `admin_settings` van como literales y no como constantes de `LeadFlujoEntradaService`: esa
 * clase todavía no existe, y un test que muere en el `setUp` por una constante faltante falla por el motivo
 * equivocado. Son las del PLAN §2.2 (contrato): si la implementación las nombra distinto, el test lo marca.
 */
trait ArmadoDelFlujoDeEntrada
{
    /** Instante fijo de los tests (hora de Argentina). */
    protected static $AHORA_DEL_FLUJO = '2026-11-23 12:00:00';

    /** @var string Secreto con el que se firman los webhooks de Kapso de estos tests. */
    protected static $SECRETO_DEL_FLUJO = 'firma-local-flujo-entrada';

    /** @var array<int, array<string, mixed>> Respuestas pendientes de la IA simulada, en orden. */
    protected $cola_ia = [];

    /** @var array<int, string> Cuerpos de lo que se le mandó a la IA simulada, en orden. */
    protected $pedidos_a_la_ia = [];

    // ------------------------------------------------------------------------------------------
    // Armado del entorno
    // ------------------------------------------------------------------------------------------

    /**
     * Deja el entorno de un test del flujo: reloj, colas, mail, HTTP simulado, WhatsApp en modo prueba y las
     * perillas del onboarding. El interruptor del flujo queda como pide `$prendido`.
     *
     * @param bool $prendido true = `lead_whatsapp_flujo_entrada_activo` en '1'; false = en '0'.
     * @param bool $con_http_generico true registra además un stub que atiende cualquier otra URL (los tests que
     *                                extienden BaseDePruebaGratuita ya traen los suyos y pasan false).
     * @param bool $charla true = «charla antes de la demo» prendida (`lead_whatsapp_charla_antes_de_la_demo_activa` en
     *                     '1'); false (default) = apagada ('0'), que es el comportamiento de antes de la misión
     *                     leads-charla-antes-de-la-demo: los tests que ya usaban este armado siguen probando ese camino.
     *
     * @return void
     */
    protected function preparar_flujo_de_entrada(bool $prendido = true, bool $con_http_generico = true, bool $charla = false): void
    {
        Carbon::setTestNow(Carbon::parse(self::$AHORA_DEL_FLUJO, 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        Mail::fake();
        $this->fakear_http_del_flujo($con_http_generico);

        /* Perillas explícitas: la base del slot puede tener otros valores guardados. */
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

        /* Textos de la entrada sin pisar y sin precargados: arrancan en los defaults del PLAN §2.2. */
        foreach (['llegada_1', 'llegada_2', 'respuesta_1', 'respuesta_2'] as $clave) {
            AdminSetting::set('lead_whatsapp_flujo_' . $clave, '');
        }
        AdminSetting::set('lead_whatsapp_flujo_textos_precargados', '');
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', $prendido ? '1' : '0');

        /* Interruptor de la «charla antes de la demo» (misión leads-charla-antes-de-la-demo). Va como literal y no como
           constante de `LeadFlujoEntradaService` porque la constante todavía no existe en este commit (y un `setUp`
           que muere por una constante faltante rompe todo por el motivo equivocado). Default de producción: '1';
           default de este armado: '0', para no cambiar lo que afirman los 24 archivos que lo usan. */
        AdminSetting::set('lead_whatsapp_charla_antes_de_la_demo_activa', $charla ? '1' : '0');

        /* La fuente de precios: sin valor guardado rige el default del pedido (PreciosVigentesSettings). */
        AdminSetting::query()->where('key', 'precios_vigentes')->delete();

        /* El alta pide una instancia libre del pool (lead de la web): el pool del slot puede no tener ninguna. */
        TrialInstance::create([
            'nombre' => 'fe-' . Str::lower(Str::random(6)), 'estado' => TrialInstance::ESTADO_LIBRE,
            'erp_vps_path' => 'prueba-sintetica', 'ecommerce_vps_path' => 'tienda-sintetica',
        ]);

        /* Variantes del onboarding de hoy (el camino con el interruptor apagado las usa). */
        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME)->update(['active' => false]);
        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING)->delete();
        (new MessageVariantLandingSeeder())->run();
        MessageVariant::create([
            'slug' => 'test_welcome_generico_flujo', 'name' => 'Genérica del flujo',
            'message_type' => LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME,
            'body' => 'Hola {nombre}! Soy Martín. ¿A qué se dedica el negocio?', 'active' => true, 'delay_seconds' => null,
        ]);

        /* WhatsApp de Kapso en modo prueba: `send_text` devuelve un id simulado y no sale a la red. */
        WhatsappConfig::query()->update(['is_active' => false]);
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::$SECRETO_DEL_FLUJO;
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();
    }

    /**
     * Suelta el reloj fijo. Se llama desde el `tearDown` del test.
     *
     * @return void
     */
    protected function desarmar_flujo_de_entrada(): void
    {
        Carbon::setTestNow();
    }

    /**
     * Registra el HTTP simulado: la IA (`api.anthropic.com`) sale de `$this->cola_ia` y, si se pide, todo lo
     * demás (Meta, Graph, etc.) contesta 200 vacío. Un solo `Http::fake` para respetar el orden de los stubs.
     *
     * @param bool $con_http_generico Si registra el stub que atiende cualquier otra URL.
     *
     * @return void
     */
    protected function fakear_http_del_flujo(bool $con_http_generico): void
    {
        $stubs = [
            'api.anthropic.com/*' => function ($request) {
                $this->pedidos_a_la_ia[] = (string) $request->body();

                if (empty($this->cola_ia)) {
                    return Http::response(['error' => 'La IA simulada no tiene más respuestas en la cola del test.'], 500);
                }

                return Http::response(array_shift($this->cola_ia), 200);
            },
        ];

        if ($con_http_generico) {
            $stubs['*'] = Http::response(['ok' => true], 200);
        }

        Http::fake($stubs);
    }

    /**
     * Pone en la cola de la IA simulada los paquetes que va a devolver el agente, en orden.
     *
     * @param array<int, array<string, mixed>> $paquetes Cada paquete es el JSON que devuelve el modelo.
     *
     * @return void
     */
    protected function fakear_ia(array $paquetes): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->cola_ia         = [];
        $this->pedidos_a_la_ia = [];

        foreach ($paquetes as $paquete) {
            $this->cola_ia[] = [
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => json_encode($paquete, JSON_UNESCAPED_UNICODE)]],
            ];
        }
    }

    /**
     * Paquete mínimo del agente.
     *
     * @param string               $mensaje Texto sugerido (puede llevar líneas `---` entre burbujas).
     * @param string               $estado Estado sugerido.
     * @param string               $tipo `tipo_respuesta` del contrato de respaldo.
     * @param array<int, string>   $fuentes `fuentes_kb`.
     * @param array<string, mixed> $extra Claves que se suman o pisan (p. ej. `requiere_verificacion`).
     *
     * @return array<string, mixed>
     */
    protected function paquete_ia(string $mensaje, string $estado, string $tipo = 'conversacional', array $fuentes = [], array $extra = []): array
    {
        return array_merge([
            'mensaje_sugerido' => $mensaje,
            'estado_sugerido'  => $estado,
            'razonamiento'     => 'Razonamiento de prueba.',
            'tipo_respuesta'   => $tipo,
            'fuentes_kb'       => $fuentes,
        ], $extra);
    }

    /**
     * Deja el system prompt y el system base que `build_system_prompt()` exige para llegar hasta la llamada.
     *
     * @return void
     */
    protected function sembrar_protocolo_de_leads(): void
    {
        AiSystemPrompt::create([
            'contenido' => 'System prompt de prueba.', 'descripcion' => 'Fila mínima para que build_system_prompt() no tire.', 'activa' => true,
        ]);

        SyncedGithubFile::create([
            'key' => WhatsappProtocolService::SYSTEM_BASE_KEY, 'repo_path' => 'agentes/lead/recursos/README.md',
            'content' => 'System base de prueba. Completá fuentes_kb con los recursos que pediste.', 'synced_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // Textos literales (PLAN §2.2 y §9 del informe), con los precios por defecto de E1
    // ------------------------------------------------------------------------------------------

    /**
     * @return string Mensaje 1 de la llegada (`llegada_1`).
     */
    protected function texto_llegada_1(): string
    {
        return '¡Hola! Soy Martín, de ComercioCity, una plataforma de gestión con sistema, tienda online y agentes de IA para comercios.';
    }

    /**
     * @return string Mensaje 2 de la llegada (`llegada_2`) con `prueba_dias_default = 7`.
     */
    protected function texto_llegada_2(): string
    {
        return 'Lo podés probar gratis 7 días con tu propio negocio. Comentame, ¿qué rubro trabajás y más o menos cuántos productos manejás?';
    }

    /**
     * @return string Mensaje 1 de la respuesta (`respuesta_1`) con la implementación de gestión: $628.400.
     */
    protected function texto_respuesta_1(): string
    {
        return "Tenés dos formas de arrancar:\n"
            . "\n"
            . "1️⃣ Por tu cuenta, gratis: creás tu prueba en 2 minutos y subís tu Excel con un video que te guía.\n"
            . "\n"
            . '2️⃣ Te lo dejamos armado nosotros: nos pasás tu Excel, las listas, páginas web o los PDF de tus proveedores y te cargamos productos, precios, fotos y descripciones. También contás con desarrollos a medida de por vida. Todo esto con un pago único de $628.400; podés arrancar con la mitad para probar completamente la plataforma con toda tu información ya cargada.';
    }

    /**
     * @return string Mensaje 2 de la respuesta (`respuesta_2`): la página, no el link de la prueba.
     */
    protected function texto_respuesta_2(): string
    {
        return 'También te dejamos nuestra página para que veas más acerca del servicio y testimonios de nuestros clientes: comerciocity.com';
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures de leads y mensajes
    // ------------------------------------------------------------------------------------------

    /**
     * @return string Un teléfono al azar con forma de móvil argentino.
     */
    protected function telefono_al_azar(): string
    {
        return '+54911' . random_int(10000000, 99999999);
    }

    /**
     * Lead mínimo en el estado pedido.
     *
     * @param string      $estado Estado del lead.
     * @param string|null $telefono Teléfono (al azar si es null).
     *
     * @return Lead
     */
    protected function crear_lead_de_flujo(string $estado = 'nuevo', ?string $telefono = null): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->phone        = $telefono !== null ? $telefono : $this->telefono_al_azar();
        $lead->status       = $estado;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Avanza el reloj fijo, para que los mensajes de un test queden ordenados en el tiempo.
     *
     * @param int $segundos Segundos a sumar.
     *
     * @return void
     */
    protected function avanzar(int $segundos = 60): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($segundos));
    }

    /**
     * Mensaje entrante del lead, ya guardado.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    protected function mensaje_del_lead(Lead $lead, string $texto): LeadMessage
    {
        return LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'content' => $texto,
            'is_status_event' => false, 'status' => 'enviado', 'sent_at' => now(),
            'whatsapp_message_id' => 'wamid.flujo.' . Str::random(12),
        ]);
    }

    /**
     * Mensaje saliente del sistema/agente.
     *
     * @param Lead        $lead
     * @param string      $texto
     * @param string|null $kind `system_message_kind` (null = mensaje común del agente).
     * @param string      $status `enviado` (salió), `sugerido` (pendiente) o `rechazado`.
     *
     * @return LeadMessage
     */
    protected function mensaje_de_sistema(Lead $lead, string $texto, ?string $kind = null, string $status = 'enviado'): LeadMessage
    {
        return LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'content' => $texto,
            'is_status_event' => false, 'status' => $status, 'system_message_kind' => $kind,
            'sent_at' => $status === 'enviado' ? now() : null,
            'whatsapp_message_id' => $status === 'enviado' ? 'wamid.flujo.' . Str::random(12) : null,
        ]);
    }

    /**
     * Deja en el hilo los dos mensajes de la llegada como si ya hubieran salido (con su `system_message_kind`).
     *
     * @param Lead $lead
     *
     * @return void
     */
    protected function dejar_entregado_el_paso_1(Lead $lead): void
    {
        $this->mensaje_de_sistema($lead, $this->texto_llegada_1(), LeadWhatsappOnboardingService::KIND_WELCOME);
        $this->mensaje_de_sistema($lead, $this->texto_llegada_2(), 'whatsapp_entrada_2');
    }

    /**
     * Los mensajes del sistema del lead (no eventos de estado), en orden de creación.
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function mensajes_de_sistema(Lead $lead)
    {
        return LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'sistema')
            ->where('is_status_event', false)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------------------------
    // Webhook, jobs y espía de WhatsApp
    // ------------------------------------------------------------------------------------------

    /**
     * Pega al webhook real de Kapso con el cuerpo firmado como lo firma Kapso.
     *
     * @param string $telefono Teléfono del lead.
     * @param string $texto Texto que escribe.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_kapso(string $telefono, string $texto)
    {
        $body = json_encode([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Contacto de prueba'],
            'message'      => [
                'id' => 'wamid.flujo.' . bin2hex(random_bytes(8)), 'from' => $telefono, 'type' => 'text',
                'text' => ['body' => $texto], 'timestamp' => (string) now()->getTimestamp(),
            ],
        ]);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::$SECRETO_DEL_FLUJO),
        ], $body);
    }

    /**
     * Espía de `WhatsappSendService`: guarda cada texto que se manda y devuelve un id simulado. Queda registrado
     * en el contenedor, así los jobs y servicios que lo piden por tipo reciben el espía.
     *
     * @return WhatsappSendService
     */
    protected function espiar_whatsapp(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, string>> Cada texto enviado: `to` y `body`, en orden. */
            public $textos = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.flujo.espia.' . bin2hex(random_bytes(8));
            }

            public function last_send_was_transient(): bool
            {
                return false;
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Cuerpos de los textos que mandó el espía, en orden.
     *
     * @param WhatsappSendService $espia
     *
     * @return array<int, string>
     */
    protected function cuerpos_enviados($espia): array
    {
        return array_column($espia->textos, 'body');
    }

    /**
     * Los jobs de una clase que quedaron encolados (con `Queue::fake()`).
     *
     * @param string $clase Nombre de clase del job.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function jobs_encolados(string $clase)
    {
        return Queue::pushed($clase);
    }

    /**
     * Corre un job encolado resolviendo sus dependencias por el contenedor (así no importa la firma de `handle`).
     *
     * @param object $job Job tal como quedó en la cola falsa.
     *
     * @return void
     */
    protected function correr_job($job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * Exige que exista una clase del código de producción; si no, el test falla con un mensaje claro en vez de
     * un «Class not found».
     *
     * @param string $clase Nombre completo de la clase.
     * @param string $para Qué criterio la necesita.
     *
     * @return void
     */
    protected function exigir_clase(string $clase, string $para): void
    {
        $this->assertTrue(class_exists($clase), "Falta la clase {$clase} ({$para}).");
    }

    /**
     * El contexto completo del turno del agente (`build_user_content`, protegido: se llama por una subclase).
     *
     * @param Lead $lead
     *
     * @return string
     */
    protected function contexto_del_turno(Lead $lead): string
    {
        $servicio = new class extends LeadAiService {
            public function contexto(Lead $lead): string
            {
                return $this->build_user_content($lead, false, '', null, false);
            }
        };

        return $servicio->contexto($lead);
    }

    /**
     * Deja a todos los admins de la base de testing sin ningún rol de asignación, para que la regla de a quién va
     * la tarea del embudo se pruebe con los admins que crea cada test (se revierte con la transacción).
     *
     * @return void
     */
    protected function limpiar_roles_de_admins(): void
    {
        Admin::query()->update(['es_setter' => false, 'es_vendedor' => false, 'solo_leads' => false]);
    }

    /**
     * Crea un admin con los roles pedidos.
     *
     * @param string               $nombre
     * @param array<string, bool>  $roles Claves entre `es_setter`, `es_vendedor` y `solo_leads`.
     *
     * @return Admin
     */
    protected function crear_admin_con_roles(string $nombre, array $roles): Admin
    {
        $admin           = new Admin();
        $admin->name     = $nombre;
        $admin->email    = 'flujo-' . Str::lower(Str::random(8)) . '@test.local';
        $admin->password = bcrypt('secret');
        foreach ($roles as $rol => $valor) {
            $admin->{$rol} = (bool) $valor;
        }
        $admin->save();

        return $admin;
    }
}
