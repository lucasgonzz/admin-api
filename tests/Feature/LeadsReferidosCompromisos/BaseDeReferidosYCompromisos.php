<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadFlujoEntradaService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * Base común de los tests de la misión leads-referidos-compromisos (etapa E1: leads de otros medios; etapa E2: motor
 * de compromisos; la etapa E3 la extiende con lo suyo).
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
 *  - (E2) Los helpers del motor de compromisos: prender y apagar el motor y sus tres interruptores, ventana de 24 h
 *    abierta o cerrada, el fake del proveedor de IA (respuestas en orden, texto crudo o falla HTTP), el embudo
 *    prendido, la tabla `lead_compromisos` leída por query builder, el comando `leads:compromisos`, el alta de un
 *    compromiso por el panel y un negocio en prueba con la bienvenida ya enviada (para el empujón).
 *
 * 🔴 CALENDARIO. Todas las fechas de los tests de E2 son del calendario REAL de 2026 (lunes 23/11, martes 24/11,
 * miércoles 25/11, jueves 26/11, viernes 27/11, sábado 28/11, lunes 30/11, martes 1/12, jueves 3/12). Las fechas de
 * ejemplo de los criterios del PLAN (CA9, CA14, CA15) traen los días de la semana corridos un día (son del
 * calendario de 2025): acá se respeta el NOMBRE del día de la semana, que es lo que dice Lucas, no el número.
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

    /** Cuerpo de la respuesta que recibe un pedido HTTP que ningún stub atendió (lo reconoce `pedidos_sin_stub()`). */
    const MARCA_DE_PEDIDO_SIN_STUB = 'pedido HTTP sin stub en el test';

    /** @var bool El test de control del guardián ya comprobó que denuncia y se hace cargo del pedido sin stub que provocó. */
    protected $guardian_comprobado = false;

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

        /* Último stub: todo lo que no tiene dueño devuelve 500 con una marca, así un envío real nunca "funciona".
           🔴 NO anota nada desde acá: Laravel 8 invoca TODOS los stubs de un pedido (`buildStubHandler` hace `map->__invoke`) y se
           queda con la primera respuesta que no es null, así que este callback corre también para los pedidos que atiende otro
           stub (la IA simulada) y los denunciaría como ajenos. Los pedidos sin dueño se detectan en el `tearDown` por la
           RESPUESTA que recibieron (la de este stub), con `pedidos_sin_stub()`. */
        Http::fake([
            '*' => function ($request) {
                return Http::response(['error' => self::MARCA_DE_PEDIDO_SIN_STUB], 500);
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
        $ajenos = $this->guardian_comprobado ? [] : $this->pedidos_sin_stub();

        $this->desarmar_flujo_de_entrada();

        parent::tearDown();

        $this->assertSame([], $ajenos, 'Salió a la red algo que el test no previó (OpenAI/DeepSeek/Meta/otro).');
    }

    /**
     * Los pedidos HTTP de este test que ningún stub atendió: los que recibieron la respuesta del stub `'*'` (la marca
     * `MARCA_DE_PEDIDO_SIN_STUB`). Un pedido que atendió otro stub (la IA simulada) no cuenta, aunque el `'*'` también se invoque.
     *
     * @return array<int, string> `MÉTODO url` de cada uno; vacío si todo tuvo dueño.
     */
    protected function pedidos_sin_stub(): array
    {
        $ajenos = [];
        foreach (Http::recorded() as $par) {
            $cuerpo = $par[1]->json();
            if (is_array($cuerpo) && isset($cuerpo['error']) && $cuerpo['error'] === self::MARCA_DE_PEDIDO_SIN_STUB) {
                $ajenos[] = $par[0]->method() . ' ' . $par[0]->url();
            }
        }

        return $ajenos;
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

    // ------------------------------------------------------------------------------------------
    // E2 — el motor de compromisos: interruptores, tabla, comando y reloj
    // ------------------------------------------------------------------------------------------

    /** Hora (de Argentina) a la que sale un compromiso que sólo trae el día, para `llamar` y `escribir`. */
    const HORA_POR_DEFECTO_DEL_COMPROMISO = '10:00:00';

    /**
     * Prende el motor de compromisos y, si se pide, la detección con IA y el envío automático. Los que no se
     * piden quedan como están (la base de testing los trae apagados).
     *
     * @param array<string, bool> $ademas Claves `deteccion` y `envio_automatico`.
     *
     * @return void
     */
    protected function prender_el_motor(array $ademas = []): void
    {
        $this->motor_de_compromisos(true);

        foreach (['deteccion' => 'lead_compromisos_deteccion_ia', 'envio_automatico' => 'lead_compromisos_envio_automatico'] as $nombre => $clave) {
            if (array_key_exists($nombre, $ademas)) {
                AdminSetting::set($clave, $ademas[$nombre] ? '1' : '0');
            }
        }
    }

    /**
     * Falla con un mensaje claro si la migración de E2 todavía no existe (es el motivo del rojo de todo lo que
     * escribe o lee `lead_compromisos`).
     *
     * @return void
     */
    protected function exigir_tabla_de_compromisos(): void
    {
        $this->assertTrue(
            Schema::hasTable('lead_compromisos'),
            'Falta la tabla lead_compromisos (migración create_lead_compromisos_table, E2).'
        );
    }

    /**
     * Falla con un mensaje claro si el comando del motor todavía no existe.
     *
     * @return void
     */
    protected function exigir_comando_de_compromisos(): void
    {
        $this->assertArrayHasKey('leads:compromisos', Artisan::all(), 'Falta el comando leads:compromisos (E2).');
    }

    /**
     * Corre una vez el comando del motor (el que el scheduler corre cada minuto).
     *
     * @return int Código de salida.
     */
    protected function correr_el_comando_de_compromisos(): int
    {
        $this->exigir_comando_de_compromisos();

        return Artisan::call('leads:compromisos');
    }

    /**
     * Las filas de `lead_compromisos` de un lead, por query builder (así el test no depende del nombre del modelo),
     * en orden de creación.
     *
     * @param Lead        $lead
     * @param string|null $status Si se pasa, sólo las que están en ese status.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function compromisos_de(Lead $lead, ?string $status = null)
    {
        $this->exigir_tabla_de_compromisos();

        $consulta = DB::table('lead_compromisos')->where('lead_id', (int) $lead->id)->orderBy('id');
        if ($status !== null) {
            $consulta->where('status', $status);
        }

        return $consulta->get();
    }

    /**
     * El compromiso vigente del lead (como mucho hay uno) o null.
     *
     * @param Lead $lead
     *
     * @return object|null
     */
    protected function compromiso_vigente_de(Lead $lead)
    {
        return $this->compromisos_de($lead, 'vigente')->first();
    }

    /**
     * Una fila de `lead_compromisos` por id, releída de la base.
     *
     * @param int $id
     *
     * @return object
     */
    protected function compromiso(int $id)
    {
        $this->exigir_tabla_de_compromisos();
        $fila = DB::table('lead_compromisos')->where('id', $id)->first();
        $this->assertNotNull($fila, 'El compromiso #' . $id . ' tiene que existir.');

        return $fila;
    }

    /**
     * Una fecha guardada, como `Y-m-d H:i:s` de la hora de Argentina (el formato de los criterios).
     *
     * @param mixed $valor
     *
     * @return string
     */
    protected function fecha_ar($valor): string
    {
        return Carbon::parse($valor, self::$ZONA_DEL_TABLERO)->format('Y-m-d H:i:s');
    }

    /**
     * Mueve el reloj a un momento de Argentina (azúcar de `clavar_el_reloj`).
     *
     * @param string $momento `Y-m-d H:i:s`.
     *
     * @return void
     */
    protected function ir_a(string $momento): void
    {
        $this->clavar_el_reloj($momento);
    }

    // ------------------------------------------------------------------------------------------
    // E2 — leads, hilo y ventana de 24 h
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead conversando (`contactado`) con nombre y empresa de caso, teléfono único y su hilo sin mensajes.
     *
     * @param array<string, mixed> $atributos Pisan los defaults (por ejemplo `no_recibe_mensajes_at`).
     *
     * @return Lead
     */
    protected function lead_de_ro(array $atributos = []): Lead
    {
        return $this->crear_lead_del_tablero('contactado', array_merge([
            'contact_name' => 'Ro',
            'company_name' => 'Ferretería de Ro',
        ], $atributos));
    }

    /**
     * Un mensaje del lead, fechado en el momento dado (así la ventana de 24 h y la huella de un compromiso
     * se pueden armar sin mover el reloj).
     *
     * @param Lead   $lead
     * @param string $texto
     * @param string $momento `Y-m-d H:i:s` de Argentina.
     *
     * @return LeadMessage
     */
    protected function entrante_a_las(Lead $lead, string $texto, string $momento): LeadMessage
    {
        $mensaje = $this->mensaje_del_lead($lead, $texto);
        $this->fechar_mensaje($mensaje, $momento);

        return $mensaje->fresh();
    }

    /**
     * Un mensaje saliente ya enviado por una persona del equipo, fechado en el momento dado.
     *
     * @param Lead   $lead
     * @param string $texto
     * @param string $momento `Y-m-d H:i:s` de Argentina.
     *
     * @return LeadMessage
     */
    protected function saliente_a_las(Lead $lead, string $texto, string $momento): LeadMessage
    {
        $mensaje = $this->mensaje_de_sistema($lead, $texto);
        $this->fechar_mensaje($mensaje, $momento);

        return $mensaje->fresh();
    }

    /**
     * Pone `created_at` (y `sent_at`) de un mensaje en un momento dado.
     *
     * @param LeadMessage $mensaje
     * @param string      $momento
     *
     * @return void
     */
    private function fechar_mensaje(LeadMessage $mensaje, string $momento): void
    {
        $fecha = Carbon::parse($momento, self::$ZONA_DEL_TABLERO)->format('Y-m-d H:i:s');
        DB::table('lead_messages')->where('id', (int) $mensaje->id)->update(['created_at' => $fecha, 'updated_at' => $fecha, 'sent_at' => $fecha]);
    }

    /**
     * Los mensajes que el motor de compromisos dejó en el hilo del lead (`system_message_kind = lead_compromiso`).
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Support\Collection
     */
    protected function mensajes_del_motor(Lead $lead)
    {
        return LeadMessage::query()->where('lead_id', (int) $lead->id)->where('system_message_kind', 'lead_compromiso')->orderBy('id')->get();
    }

    /**
     * Cualquier mensaje del equipo o del sistema que SALIÓ (o se intentó sacar) al lead: sin eventos de estado.
     *
     * @param Lead $lead
     *
     * @return int
     */
    protected function cantidad_de_salientes(Lead $lead): int
    {
        return LeadMessage::query()->where('lead_id', (int) $lead->id)->where('sender', '!=', 'lead')->where('is_status_event', false)->count();
    }

    /**
     * Las tareas que el motor dejó para el lead (`created_via` = `embudo_compromiso`), en orden de creación.
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Support\Collection
     */
    protected function tareas_del_motor(Lead $lead)
    {
        return AdminTask::query()->where('lead_id', (int) $lead->id)->where('created_via', 'embudo_compromiso')->orderBy('id')->get();
    }

    /**
     * Ids de los admins asignados a una tarea (por el pivote), como enteros.
     *
     * @param AdminTask $tarea
     *
     * @return array<int, int>
     */
    protected function asignados_de(AdminTask $tarea): array
    {
        return $tarea->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    // ------------------------------------------------------------------------------------------
    // E2 — la IA de los compromisos (proveedor simulado)
    // ------------------------------------------------------------------------------------------

    /**
     * Deja la IA de los compromisos simulada: las respuestas del proveedor salen en orden, una por cada llamada
     * que haga el código (el servicio hace UNA llamada por método). Se fuerza Claude como proveedor y se carga una
     * clave de mentira, así el pedido sale a `api.anthropic.com`, que es el stub de esta base; una cola vacía
     * contesta HTTP 500 (el caso «se cayó la IA»).
     *
     * 🔴 Contrato que fijan los tests (el PLAN no lo escribe): lo que el modelo devuelve para `detectar` y `reevaluar` es
     * un JSON (un array acá) y para `redactar` es el TEXTO del mensaje, sin JSON, como en `LeadReminderComposer`.
     *
     * @param array<int, array<string, mixed>|string> $respuestas Un array se manda como JSON; un string, tal cual
     *                                                           (para probar el JSON roto o el texto de un mensaje).
     *
     * @return void
     */
    protected function fakear_la_ia_de_compromisos(array $respuestas): void
    {
        config(['services.lead_ai.proveedor' => 'anthropic', 'services.anthropic.api_key' => 'clave-de-prueba']);

        $this->cola_ia         = [];
        $this->pedidos_a_la_ia = [];

        foreach ($respuestas as $respuesta) {
            $texto = is_array($respuesta) ? json_encode($respuesta, JSON_UNESCAPED_UNICODE) : (string) $respuesta;

            $this->cola_ia[] = ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $texto]]];
        }
    }

    /**
     * La IA que no contesta (HTTP 500): la cola queda vacía.
     *
     * @return void
     */
    protected function fakear_la_ia_caida(): void
    {
        $this->fakear_la_ia_de_compromisos([]);
    }

    /**
     * Cuántas veces se llamó a la IA simulada desde el último `fakear_la_ia_de_compromisos`.
     *
     * @return int
     */
    protected function llamadas_a_la_ia(): int
    {
        return count($this->pedidos_a_la_ia);
    }

    /**
     * Un mensaje de Martín que cumple todas las reglas del validador (sin cifras, sin links, sin plata, sin
     * delatar nada) y retoma lo último que dijo el lead.
     *
     * @return string
     */
    protected function texto_valido_de_martin(): string
    {
        return 'Ro, te escribo como quedamos para retomar lo del local y la carga doble que te cansaba. ¿Pudiste mirar cómo lo vas a probar?';
    }

    // ------------------------------------------------------------------------------------------
    // E2 — armar compromisos por las puertas reales
    // ------------------------------------------------------------------------------------------

    /**
     * Da de alta un compromiso por el panel (`POST admin/lead/{id}/compromisos`), con el admin dado (por defecto el
     * operador de la base), y devuelve la fila creada.
     *
     * 🔴 Contrato que fijan los tests: el cuerpo lleva `vence_at` (`AAAA-MM-DD HH:MM`, o sólo `AAAA-MM-DD`), `accion`
     * (`llamar|escribir|esperar`), `resumen` y, opcional, `cita_del_lead`. Responde 201 con `model.id`.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $cuerpo Pisa los defaults.
     * @param Admin|null           $como   Quién lo pide (null = el operador de la base).
     *
     * @return object La fila de `lead_compromisos` recién creada.
     */
    protected function crear_compromiso_por_el_panel(Lead $lead, array $cuerpo = [], ?Admin $como = null)
    {
        $this->exigir_tabla_de_compromisos();
        $this->loguear_a($como !== null ? $como : $this->admin);

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/compromisos', array_merge([
            'vence_at' => '2026-11-27 10:00',
            'accion'   => 'llamar',
            'resumen'  => 'Quedaron en hablar el viernes para ver cómo sigue con la prueba.',
        ], $cuerpo));
        $respuesta->assertStatus(201);

        return $this->compromiso((int) $respuesta->json('model.id'));
    }

    /**
     * Registra una llamada por el panel (`POST admin/lead/{id}/llamadas`) como el admin dado.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $cuerpo
     * @param Admin|null           $como   Quién la registra (null = Juan).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function registrar_llamada_por_el_panel(Lead $lead, array $cuerpo, ?Admin $como = null)
    {
        $this->loguear_a($como !== null ? $como : $this->juan);

        return $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', $cuerpo);
    }

    /**
     * Prende el flujo de entrada y el embudo: sella el encendido y deja pasar un minuto, así los leads que se crean
     * después están «en el flujo» y reciben pasos de seguimiento.
     *
     * @return void
     */
    protected function prender_el_embudo(): void
    {
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '1');
        LeadFlujoEntradaService::sellar_encendido();
        $this->avanzar(60);
    }

    /**
     * Un paso del embudo escrito a mano (`lead_scheduled_messages`), pendiente y de texto libre.
     *
     * @param Lead   $lead
     * @param string $paso    `paso_embudo` (por ejemplo `conversando_22h`).
     * @param string $estado  `estado_lead_al_programar`.
     * @param string $cuando  Hora de Argentina a la que sale.
     * @param string $texto
     *
     * @return \App\Models\LeadScheduledMessage
     */
    protected function insertar_paso_del_embudo(Lead $lead, string $paso, string $estado, string $cuando, string $texto)
    {
        return \App\Models\LeadScheduledMessage::create([
            'lead_id'                  => (int) $lead->id,
            'scheduled_send_at'        => Carbon::parse($cuando, self::$ZONA_DEL_TABLERO),
            'mode'                     => \App\Models\LeadScheduledMessage::MODE_TEXTO_LIBRE,
            'content'                  => $texto,
            'template_name'            => null,
            'template_language'        => null,
            'template_variables'       => null,
            'cancel_if_lead_replies'   => false,
            'baseline_lead_message_id' => null,
            'status'                   => \App\Models\LeadScheduledMessage::STATUS_PENDIENTE,
            'created_by_admin_id'      => null,
            'paso_embudo'              => $paso,
            'estado_lead_al_programar' => $estado,
        ]);
    }

    /**
     * Un negocio en su prueba gratuita, con la bienvenida de ayer ya enviada y las plantillas de la prueba aprobadas:
     * el punto de partida de `PruebaWhatsappService::empujar` (el empujón de cada día). El lead de la prueba está
     * promovido al cliente. Con el motor apagado, el empujón sale (control); con un compromiso vigente, no.
     *
     * @return array{0: Client, 1: Lead}
     */
    protected function negocio_en_prueba_con_bienvenida(): array
    {
        foreach (\App\Services\PruebaPlantillasService::PLANTILLAS as $nombre => $definicion) {
            FollowupTemplate::create([
                'template_name' => $nombre, 'estado' => \App\Services\PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
                'body_template' => $definicion['body'], 'language_code' => 'es_AR', 'activa' => true, 'meta_status' => 'APPROVED',
                'meta_category' => 'UTILITY', 'uso' => $definicion['uso'], 'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
            ]);
        }

        $negocio = $this->crear_cliente_del_tablero(['name' => 'Marcela Gómez', 'company_name' => 'Ferretería de Marcela', 'phone' => '+5493415558811']);
        $negocio->estado        = \App\Services\Prueba\PruebaCicloService::CLIENTE_PRUEBA;
        $negocio->trial_days    = 7;
        $negocio->trial_ends_at = Carbon::now()->addDays(5)->addMinutes(30);
        $negocio->save();

        $lead = $this->crear_lead_del_tablero('contactado', [
            'contact_name' => 'Marcela Gómez', 'company_name' => 'Ferretería de Marcela', 'phone' => '+5493415558811',
            'promoted_client_id' => (int) $negocio->id, 'demo_experiencia' => Lead::EXPERIENCIA_NUEVA,
        ]);

        \App\Models\PruebaWhatsappEnvio::create([
            'client_id' => (int) $negocio->id, 'lead_id' => (int) $lead->id, 'plantilla' => \App\Services\PruebaPlantillasService::BIENVENIDA,
            'hito' => null, 'estado' => \App\Models\PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia' => Carbon::now(\App\Services\PruebaWhatsappService::ZONA)->subDay()->toDateString(), 'enviado_at' => Carbon::now()->subDay(),
        ]);

        return [$negocio->fresh(), $lead->fresh()];
    }
}
