<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Client;
use App\Models\DemoChatConversacion;
use App\Models\DemoChatMensaje;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportTicket;
use App\Services\LeadVerificacionAgendamientoNotificationService;
use App\Services\LeadVerificacionWhatsappService;
use App\Services\PendingApprovalPushService;
use App\Services\SupportAiSuggestionDraftService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base común de los tests de la misión push-mensajes-por-aprobar (admin-api): «que la app me envíe una push
 * notification cuando haya cualquier tipo de mensaje esperando mi verificación» (Lucas, 26/11/2026).
 *
 * Qué arma:
 *  - El reloj fijo con `Carbon::setTestNow` en {@see self::INICIO}. Los tests lo mueven con `pasar_a()` /
 *    `reloj_desde()`. Casi todos corren el comando 30 segundos después del alta del mensaje (pasa el asentamiento de
 *    20 s del PLAN §2.4), salvo que el test diga otra cosa.
 *  - La base sin roles, sin flags de WhatsApp y sin devices: los tests cuentan destinatarios exactos y cualquier admin
 *    real del slot con `es_setter`, un flag o un device los contaminaría. Todo adentro de la transacción del test.
 *  - 🔴 Un ESPÍA de `PendingApprovalPushService` ligado SIEMPRE en el contenedor: sobrescribe `send_push()` (y
 *    `table_ready()`), registra cada llamada y devuelve lo que el test pida. Minishlink no pasa por `Http::fake`: sin
 *    el espía, el test saldría a la red real del push service de Apple o Google.
 *  - Helpers de fixtures con datos reales del esquema: admins con y sin device, leads, mensajes por aprobar, tickets de
 *    soporte con su borrador, chat de la demo, y los espías de los dos servicios de verificación ya existentes.
 *
 * 🔴 Nunca `Schema::drop` ni DDL en estos tests: en MySQL un DDL hace commit implícito, rompe la transacción del test y
 * toca la base compartida. «Sin la tabla» se simula con el seam `table_ready()` del espía.
 *
 * Estos tests referencian clases, tabla y comando que todavía no existen (`PendingApprovalPushService`,
 * `PendingApprovalNotification`, `pending_approval_notifications`, `pending-approvals:notify`): hoy están EN ROJO.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDePushPorAprobar extends TestCase
{
    use DatabaseTransactions;

    /** Momento en que arranca cada test (hora de la aplicación): viernes 27/11/2026 a las 12:00. */
    const INICIO = '2026-11-27 12:00:00';

    /** Tabla del registro idempotente de avisos (PLAN §2.3). */
    const TABLA = 'pending_approval_notifications';

    /** Texto estándar de un mensaje del agente que espera el ok. */
    const TEXTO_POR_APROBAR = 'Hola Juana, te cuento cómo seguimos con la demo del sistema. ¿Te sirve el jueves?';

    /** @var object Espía de `PendingApprovalPushService` ligado en el contenedor. */
    protected $espia;

    /**
     * Deja el entorno: reloj, base limpia de roles y devices, URL del SPA y el espía ligado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pasar_a('+0 seconds');

        Admin::query()->update([
            'es_setter'                                 => false,
            'is_closer'                                 => false,
            'is_default_support_owner'                  => false,
            'notify_support_escalation_whatsapp'        => false,
            'notify_verificacion_whatsapp'              => false,
            'notify_verificacion_agendamiento_whatsapp' => false,
        ]);
        AdminPushSubscription::query()->delete();

        config(['services.admin_spa.url' => 'https://admin.test']);

        $this->espia = self::nuevo_espia();
        $this->app->instance(PendingApprovalPushService::class, $this->espia);
    }

    /**
     * Suelta el reloj clavado para no contaminar a los demás tests del proceso.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // El espía del servicio (también lo usa CompromisoConAvisoPorAprobarTest, que extiende otra base)
    // ------------------------------------------------------------------------------------------

    /**
     * Subclase espía de `PendingApprovalPushService`: sobrescribe los dos seams (`send_push()` y `table_ready()`).
     *
     * Propiedades públicas con las que los tests la manejan:
     *  - `llamadas`: TODAS las llamadas a `send_push()` (incluidas las que tiraron), cada una `[admin_id, title, body, data,
     *    accepted_hashes]`.
     *  - `enviados`: las llamadas que NO tiraron (el «push» que salió).
     *  - `respuestas`: cola de respuestas para las próximas llamadas, en orden. Cada elemento es `'tirar'` (lanza una
     *    excepción) o un array con el formato de `AdminPushNotificationService::send_to_admin_with_result`. Vacía = éxito.
     *  - `siempre`: si vale `'tirar'`, TODAS las llamadas lanzan, sin consumir la cola.
     *  - `tabla_lista`: lo que contesta `table_ready()` (false simula el código desplegado antes de la migración).
     *
     * @return object Instancia de una subclase anónima de `PendingApprovalPushService`.
     */
    public static function nuevo_espia()
    {
        return new class extends PendingApprovalPushService {
            /** @var array<int, array<string, mixed>> Todas las llamadas al seam. */
            public $llamadas = [];

            /** @var array<int, array<string, mixed>> Las llamadas que no tiraron. */
            public $enviados = [];

            /** @var array<int, mixed> Cola de respuestas para las próximas llamadas. */
            public $respuestas = [];

            /** @var string|null `'tirar'` para que toda llamada lance. */
            public $siempre = null;

            /** @var bool Respuesta de `table_ready()`. */
            public $tabla_lista = true;

            /**
             * Registra la llamada y devuelve (o lanza) lo que pidió el test, sin salir a la red.
             *
             * @param int                  $admin_id
             * @param string               $title
             * @param string               $body
             * @param array<string, mixed> $data
             * @param array<int, string>   $accepted_hashes
             *
             * @return array<string, mixed>
             */
            protected function send_push(int $admin_id, string $title, string $body, array $data, array $accepted_hashes): array
            {
                $llamada = [
                    'admin_id'        => $admin_id,
                    'title'           => $title,
                    'body'            => $body,
                    'data'            => $data,
                    'accepted_hashes' => $accepted_hashes,
                ];
                $this->llamadas[] = $llamada;

                $accion = $this->siempre;
                if ($accion === null && ! empty($this->respuestas)) {
                    $accion = array_shift($this->respuestas);
                }

                if ($accion === 'tirar') {
                    throw new \RuntimeException('Falla simulada del transporte de push.');
                }

                $this->enviados[] = $llamada;

                if (is_array($accion)) {
                    return $accion;
                }

                return [
                    'accepted_endpoint_hashes' => [hash('sha256', 'device-' . $admin_id)],
                    'failed_endpoint_hashes'   => [],
                    'complete'                 => true,
                ];
            }

            /**
             * Simula que la tabla existe o no, sin tocar el esquema.
             *
             * @return bool
             */
            protected function table_ready(): bool
            {
                return $this->tabla_lista;
            }
        };
    }

    // ------------------------------------------------------------------------------------------
    // Reloj y comando
    // ------------------------------------------------------------------------------------------

    /**
     * Mueve el reloj a `INICIO` más el desplazamiento (por ejemplo `+30 seconds`, `+3 minutes`).
     *
     * @param string $desplazamiento Texto aceptado por `DateTime::modify`.
     *
     * @return void
     */
    protected function pasar_a(string $desplazamiento): void
    {
        Carbon::setTestNow(Carbon::parse(self::INICIO)->modify($desplazamiento));
    }

    /**
     * Mueve el reloj a una base cualquiera más el desplazamiento (los tests que encadenan escenarios).
     *
     * @param Carbon $base
     * @param string $desplazamiento
     *
     * @return void
     */
    protected function reloj_desde(Carbon $base, string $desplazamiento): void
    {
        Carbon::setTestNow($base->copy()->modify($desplazamiento));
    }

    /**
     * Corre el comando que el scheduler corre cada minuto y verifica que SIEMPRE salga con 0.
     *
     * @return void
     */
    protected function correr_el_comando(): void
    {
        $this->assertArrayHasKey('pending-approvals:notify', Artisan::all(), 'Falta el comando pending-approvals:notify.');
        $this->assertSame(0, Artisan::call('pending-approvals:notify'), 'El comando sale siempre con código 0.');
    }

    // ------------------------------------------------------------------------------------------
    // Admins y devices
    // ------------------------------------------------------------------------------------------

    /**
     * Admin con teléfono cargado y los atributos pedidos.
     *
     * @param string               $nombre
     * @param array<string, mixed> $atributos
     *
     * @return Admin
     */
    protected function crear_admin(string $nombre, array $atributos = []): Admin
    {
        $admin               = new Admin();
        $admin->name         = $nombre;
        $admin->email        = uniqid('pa-', true) . '@test.local';
        $admin->password     = bcrypt('secret');
        $admin->phone_number = '549341' . random_int(1000000, 9999999);

        foreach ($atributos as $campo => $valor) {
            $admin->{$campo} = $valor;
        }

        $admin->save();

        return $admin;
    }

    /**
     * Registra un device de push para el admin y devuelve el hash de su endpoint.
     *
     * @param Admin  $admin
     * @param string $sufijo Para tener más de un device por admin.
     *
     * @return string Hash del endpoint.
     */
    public static function registrar_device(Admin $admin, string $sufijo = 'a'): string
    {
        $endpoint = 'https://web.push.apple.test/' . $sufijo . '/' . $admin->id;

        $sub                = new AdminPushSubscription();
        $sub->admin_id      = $admin->id;
        $sub->endpoint      = $endpoint;
        $sub->endpoint_hash = AdminPushSubscription::hash_endpoint($endpoint);
        $sub->p256dh        = 'clave-falsa';
        $sub->auth          = 'auth-falso';
        $sub->save();

        return $sub->endpoint_hash;
    }

    /**
     * Un setter con device registrado (el destinatario normal de los avisos de leads).
     *
     * @param string $nombre
     *
     * @return Admin
     */
    protected function crear_setter_con_device(string $nombre = 'Setter'): Admin
    {
        $setter = $this->crear_admin($nombre, ['es_setter' => true]);
        self::registrar_device($setter);

        return $setter;
    }

    // ------------------------------------------------------------------------------------------
    // Leads y sus mensajes
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead con nombre de contacto.
     *
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Lead
     */
    protected function crear_lead(array $atributos = []): Lead
    {
        return Lead::create(array_merge([
            'contact_name' => 'Juana Pérez',
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ], $atributos));
    }

    /**
     * Un mensaje de sistema `sugerido` con verificación pendiente (el caso canónico de PLAN §2.2).
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return LeadMessage
     */
    protected function crear_mensaje_por_aprobar(Lead $lead, array $atributos = []): LeadMessage
    {
        return LeadMessage::create(array_merge([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => self::TEXTO_POR_APROBAR,
            'status'                => 'sugerido',
            'kind'                  => 'text',
            'requiere_verificacion' => true,
            'is_status_event'       => false,
            'is_error'              => false,
        ], $atributos));
    }

    /**
     * Cambia el `created_at` de un mensaje de lead (sin pasar por Eloquent, que lo pisaría con el reloj).
     *
     * @param LeadMessage $mensaje
     * @param Carbon      $momento
     *
     * @return void
     */
    protected function fechar_mensaje_de_lead(LeadMessage $mensaje, Carbon $momento): void
    {
        $fecha = $momento->format('Y-m-d H:i:s');
        DB::table('lead_messages')->where('id', (int) $mensaje->id)->update(['created_at' => $fecha, 'updated_at' => $fecha]);
    }

    // ------------------------------------------------------------------------------------------
    // Soporte
    // ------------------------------------------------------------------------------------------

    /**
     * Un ticket de soporte de un cliente, con «verificar mensajes» prendido.
     *
     * @param array<string, mixed> $cliente Pisa los datos del cliente (por ejemplo `es_ensayo`).
     * @param array<string, mixed> $ticket  Pisa los datos del ticket.
     *
     * @return SupportTicket
     */
    protected function crear_ticket_de_soporte(array $cliente = [], array $ticket = []): SupportTicket
    {
        $client = Client::create(array_merge([
            'name'      => 'Golden Brace',
            'phone'     => '+549341' . random_int(1000000, 9999999),
            'is_active' => true,
        ], $cliente));

        return SupportTicket::create(array_merge([
            'client_id'                      => $client->id,
            'client_user_id'                 => 0,
            'client_user_name'               => 'Inés',
            'status'                         => 'open',
            'source'                         => 'whatsapp',
            'opened_at'                      => now(),
            'requiere_verificacion_mensajes' => true,
        ], $ticket));
    }

    /**
     * Crea el borrador del agente de soporte por la misma puerta que el sistema (`create_draft`).
     *
     * @param SupportTicket $ticket
     * @param string        $texto
     * @param int           $demora_de_envio_automatico Segundos hasta el envío automático (0 = espera aprobación).
     *
     * @return \App\Models\SupportMessage
     */
    protected function crear_borrador_de_soporte(SupportTicket $ticket, string $texto = 'Hola Inés, ya revisamos lo que nos contás.', int $demora_de_envio_automatico = 0)
    {
        return app(SupportAiSuggestionDraftService::class)->create_draft($ticket, $texto, $demora_de_envio_automatico);
    }

    // ------------------------------------------------------------------------------------------
    // Chat de la demo
    // ------------------------------------------------------------------------------------------

    /**
     * Un mensaje del asistente en el chat de la demo de un lead, con la respuesta automática apagada.
     *
     * @param Lead                 $lead
     * @param string               $estado    `borrador` (espera el ok) o `publicado`.
     * @param array<string, mixed> $atributos Pisan los campos del mensaje.
     *
     * @return DemoChatMensaje
     */
    protected function crear_mensaje_del_chat_de_la_demo(Lead $lead, string $estado = 'borrador', array $atributos = []): DemoChatMensaje
    {
        $chat = DemoChatConversacion::firstOrCreate(
            ['lead_id' => $lead->id],
            ['auto_responder' => false, 'estado' => 'listo']
        );

        return $chat->mensajes()->create(array_merge([
            'rol'       => 'asistente',
            'origen'    => 'ia',
            'estado'    => $estado,
            'contenido' => 'Te muestro cómo se carga una venta en el sistema.',
            'videos'    => [],
        ], $atributos));
    }

    // ------------------------------------------------------------------------------------------
    // Contenido y registro
    // ------------------------------------------------------------------------------------------

    /**
     * El cuerpo del push según PLAN §2.7: texto sin espacios de más, saltos de línea y espacios repetidos colapsados a uno,
     * recortado a 140 con «…»; vacío = el texto fijo.
     *
     * @param string $texto
     *
     * @return string
     */
    protected function cuerpo_esperado(string $texto): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', trim($texto)));
        if ($limpio === '') {
            return 'Hay un mensaje esperando tu aprobación.';
        }

        return mb_strimwidth($limpio, 0, 140, '…');
    }

    /**
     * La fila del registro de avisos de un mensaje, o null si no hay.
     *
     * @param string $tipo `lead_message`, `support_message` o `demo_chat_message`.
     * @param int    $id
     *
     * @return object|null
     */
    protected function fila_de(string $tipo, int $id)
    {
        return DB::table(self::TABLA)->where('source_key', $tipo . ':' . $id)->first();
    }

    /**
     * El estado de la fila de un mensaje, o null si no tiene fila.
     *
     * @param string $tipo
     * @param int    $id
     *
     * @return string|null
     */
    protected function estado_de(string $tipo, int $id): ?string
    {
        $fila = $this->fila_de($tipo, $id);

        return $fila === null ? null : (string) $fila->status;
    }

    /**
     * Los ids de admin de la columna JSON `admin_ids` de una fila, como enteros.
     *
     * @param object $fila
     *
     * @return array<int, int>
     */
    protected function admin_ids_de($fila): array
    {
        $ids = json_decode((string) $fila->admin_ids, true);

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Ids de admin a los que el espía mandó push, ordenados.
     *
     * @return array<int, int>
     */
    protected function admins_pusheados(): array
    {
        $ids = array_map(function ($llamada) {
            return (int) $llamada['admin_id'];
        }, $this->espia->enviados);
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------------------------------
    // Espías de los dos servicios de verificación que ya existen
    // ------------------------------------------------------------------------------------------

    /**
     * Servicio de envío de WhatsApp sustituido: registra los teléfonos destino en vez de llamar a Meta.
     *
     * @return WhatsappSendService
     */
    protected function sender_espia(): WhatsappSendService
    {
        return new class extends WhatsappSendService {
            /** @var array<int, string> Teléfonos a los que se habría enviado la plantilla. */
            public $destinatarios = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->destinatarios[] = $to;

                return 'wamid.test';
            }
        };
    }

    /**
     * El servicio de fuera del tramo con el push sustituido (registra `[admin_id, title, body, data]`).
     *
     * @param WhatsappSendService $sender
     *
     * @return LeadVerificacionWhatsappService
     */
    protected function servicio_fuera_del_tramo(WhatsappSendService $sender): LeadVerificacionWhatsappService
    {
        return new class($sender) extends LeadVerificacionWhatsappService {
            /** @var array<int, array<string, mixed>> Pushes que se habrían mandado. */
            public $pushes = [];

            protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
            {
                $this->pushes[] = ['admin_id' => $admin_id, 'title' => $titulo, 'body' => $cuerpo, 'data' => $data];
            }
        };
    }

    /**
     * El servicio del tramo de agenda con el push sustituido (registra `[admin_id, title, body, data]`).
     *
     * @param WhatsappSendService $sender
     *
     * @return LeadVerificacionAgendamientoNotificationService
     */
    protected function servicio_de_agenda(WhatsappSendService $sender): LeadVerificacionAgendamientoNotificationService
    {
        return new class($sender) extends LeadVerificacionAgendamientoNotificationService {
            /** @var array<int, array<string, mixed>> Pushes que se habrían mandado. */
            public $pushes = [];

            protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
            {
                $this->pushes[] = ['admin_id' => $admin_id, 'title' => $titulo, 'body' => $cuerpo, 'data' => $data];
            }
        };
    }
}
