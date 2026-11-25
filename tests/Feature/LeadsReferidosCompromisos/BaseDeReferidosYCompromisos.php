<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\WhatsappSendService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * Base común de los tests de la misión leads-referidos-compromisos (etapa E1: leads de otros medios; las etapas
 * siguientes la extienden con lo suyo).
 *
 * Extiende `BaseDelTableroDeJuan` (pipeline «Leads» sembrado con el tablero de Juan, Juan creado con el perfil
 * «Sólo Leads», reloj clavado en el lunes 23/11/2026 a las 12:00 de Argentina, protocolo de leads sembrado, IA
 * simulada) y le suma:
 *  - El webhook de Kapso listo para firmarse (`preparar_flujo_de_entrada`, con el flujo de entrada APAGADO: los
 *    tests que lo necesitan lo prenden), colas y mail falsos.
 *  - Un HTTP cerrado: se reemplaza la fábrica de `Http` por una nueva, con la IA simulada y un último stub que
 *    anota cualquier pedido que no esté previsto. Laravel 8 no trae `Http::preventStrayRequests()`, así que
 *    este es el equivalente: el `tearDown` falla si algo intentó salir a OpenAI, DeepSeek, Meta u otro host.
 *  - Un espía de `WhatsappSendService` que guarda cada plantilla y cada texto que el código quiso mandar, sin
 *    que salga nada de la máquina.
 *  - Helpers de fixtures: el cliente que refiere, el cuerpo del alta de un lead de otro medio, plantillas de
 *    Meta registradas, el interruptor del motor de compromisos y la verificación de que existan las columnas.
 *
 * 🔴 Las claves de `admin_settings` van como literales y no como constantes de las clases de producción: esas
 * clases todavía no existen y un test que muere en el `setUp` por una constante faltante falla por el motivo
 * equivocado. Son las del PLAN §2 (contrato).
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDeReferidosYCompromisos extends BaseDelTableroDeJuan
{
    /** Clave de ingesta de las rutas `claude/*` en estos tests. */
    const CLAVE_DE_CLAUDE = 'clave-de-prueba-referidos-compromisos';

    /** @var object Espía de WhatsApp: `plantillas` y `textos` con lo que el código quiso mandar. */
    protected $envios;

    /** @var array<int, string> URLs de los pedidos HTTP que nadie previó (tienen que quedar vacías). */
    protected $pedidos_http_sin_stub = [];

    /**
     * Deja el entorno: webhook firmable, HTTP cerrado, espía de WhatsApp, tablero de Juan sembrado y clave de Claude.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* Fábrica de HTTP nueva: se descartan los stubs generosos de las bases padre (el '*' que contesta 200 a todo
           taparía justamente lo que este equivalente de preventStrayRequests tiene que denunciar). */
        Http::swap(new HttpFactory());

        /* Webhook de Kapso firmado, colas y mail falsos, perillas del onboarding y la IA simulada (Anthropic).
           Flujo de entrada apagado: los tests que lo necesitan lo prenden. */
        $this->preparar_flujo_de_entrada(false, false);

        /* Último stub: todo lo que no tiene dueño se anota y devuelve 500, así un envío real nunca "funciona". */
        Http::fake([
            '*' => function ($request) {
                $this->pedidos_http_sin_stub[] = $request->method() . ' ' . $request->url();

                return Http::response(['error' => 'pedido HTTP sin stub en el test'], 500);
            },
        ]);

        $this->envios = $this->espiar_envios_de_whatsapp();

        $this->sembrar_el_tablero();

        config(['services.claude_task_ingest.key' => self::CLAVE_DE_CLAUDE]);
    }

    /**
     * Falla el test si algo intentó salir a la red sin que el test lo hubiera previsto.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $ajenos = $this->pedidos_http_sin_stub;
        $this->pedidos_http_sin_stub = [];

        $this->desarmar_flujo_de_entrada();

        parent::tearDown();

        $this->assertSame([], $ajenos, 'Salió a la red algo que el test no previó (OpenAI/DeepSeek/Meta/otro).');
    }

    // ------------------------------------------------------------------------------------------
    // Espía de WhatsApp
    // ------------------------------------------------------------------------------------------

    /**
     * Registra en el contenedor un `WhatsappSendService` que no envía nada y anota lo que se le pidió.
     * `plantillas`: cada una con `to`, `template`, `variables`, `language`. `textos`: cada uno con `to` y `body`.
     *
     * @return object El espía (propiedades públicas `plantillas` y `textos`).
     */
    protected function espiar_envios_de_whatsapp()
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Plantillas pedidas, en orden. */
            public $plantillas = [];

            /** @var array<int, array<string, string>> Textos pedidos, en orden. */
            public $textos = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template' => $template_name, 'variables' => $variables, 'language' => $language_code];

                return 'wamid.referidos.plantilla.' . bin2hex(random_bytes(6));
            }

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.referidos.texto.' . bin2hex(random_bytes(6));
            }

            public function last_send_was_transient(): bool
            {
                return false;
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /**
     * Falla con un mensaje claro si la migración de E1 todavía no existe (es el motivo del rojo de lo que
     * escribe o lee `referido_por_client_id`, `referido_por_texto` o `contexto_previo`).
     *
     * @return void
     */
    protected function exigir_columnas_de_referido(): void
    {
        foreach (['referido_por_client_id', 'referido_por_texto', 'contexto_previo'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('leads', $columna),
                'Falta la columna leads.' . $columna . ' (migración add_referido_to_leads_table, E1).'
            );
        }
    }

    /**
     * El cliente que refiere (el «cliente CF» de los criterios): su nombre lleva «CF».
     *
     * @return Client
     */
    protected function crear_cliente_que_refiere(): Client
    {
        return $this->crear_cliente_del_tablero(['name' => 'Cliente CF', 'company_name' => 'CF Ferretería SRL']);
    }

    /**
     * Cuerpo del alta de un lead de otro medio (el caso de Ro, referida por un cliente), con lo que se quiera pisar.
     *
     * @param array<string, mixed> $cambios Pisan los defaults.
     *
     * @return array<string, mixed>
     */
    protected function cuerpo_de_alta_externa(array $cambios = []): array
    {
        return array_merge([
            'origen'         => 'referido',
            'contact_name'   => 'Ro',
            'phone'          => '+54 9 3484 52-1016',
            'contexto_previo' => 'Ro tiene un local que usa el SIAP, está cansada de cargar todo dos veces y quiere probar algo más simple.',
            'estado_inicial' => 'nuevo',
        ], $cambios);
    }

    /**
     * Teléfono móvil argentino único para un caso (para no chocar entre casos de un mismo test).
     *
     * @return string Con formato de teléfono tipeado a mano.
     */
    protected function telefono_tipeado_unico(): string
    {
        return '+54 9 351 ' . random_int(100, 999) . '-' . random_int(1000, 9999);
    }

    /**
     * El lead más reciente con ese teléfono tipeado (se compara por los dígitos).
     *
     * @param string $telefono Teléfono tal como se mandó.
     *
     * @return Lead|null
     */
    protected function lead_por_telefono_tipeado(string $telefono): ?Lead
    {
        $digitos = preg_replace('/\D+/', '', $telefono);

        return Lead::query()->where('phone', 'like', '%' . substr($digitos, -8))->orderByDesc('id')->first();
    }

    /**
     * Registra una plantilla de Meta como la deja la sincronización: activa y con el estado de Meta que se pida.
     *
     * @param string $nombre      `template_name`.
     * @param string $meta_status `APPROVED`, `PENDING`, `REJECTED`…
     * @param bool   $activa
     *
     * @return FollowupTemplate
     */
    protected function registrar_plantilla(string $nombre, string $meta_status = 'APPROVED', bool $activa = true): FollowupTemplate
    {
        return FollowupTemplate::create([
            'template_name' => $nombre,
            'estado'        => 'manual_referido',
            'dia_numero'    => 1,
            'body_template' => 'Hola {{1}}! Te escribo de parte de {{2}}. ¿Querés que te cuente cómo funciona ComercioCity?',
            'language_code' => 'es_AR',
            'activa'        => $activa,
            'meta_status'   => $meta_status,
        ]);
    }

    /**
     * Prende o apaga el motor de compromisos (lo usan las etapas siguientes; E1 lo deja como está: apagado).
     *
     * @param bool $prendido
     *
     * @return void
     */
    protected function motor_de_compromisos(bool $prendido): void
    {
        AdminSetting::set('lead_compromisos_activo', $prendido ? '1' : '0');
    }

    /**
     * Mensajes del hilo del lead que son eventos de estado con ese `system_message_kind`.
     *
     * @param Lead   $lead
     * @param string $kind
     *
     * @return \Illuminate\Support\Collection
     */
    protected function eventos_del_hilo(Lead $lead, string $kind)
    {
        return LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('is_status_event', true)
            ->where('system_message_kind', $kind)
            ->orderBy('id')
            ->get();
    }
}
