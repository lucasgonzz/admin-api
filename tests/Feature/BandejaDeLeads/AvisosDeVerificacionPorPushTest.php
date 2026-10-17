<?php

namespace Tests\Feature\BandejaDeLeads;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadVerificacionAgendamientoNotificationService;
use App\Services\LeadVerificacionWhatsappService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * «Cuando algo espera mi ok: push al teléfono, no WhatsApp» (Lucas, misión flujo-lead-landing-closer,
 * 17/10/2026).
 *
 * Los dos avisos de «sugerencia esperando aprobación» —el del tramo de agenda
 * (`LeadVerificacionAgendamientoNotificationService`) y el de fuera del tramo
 * (`LeadVerificacionWhatsappService`)— eligen el canal igual: PUSH al admin que tiene un dispositivo
 * registrado, y WHATSAPP solo al que no tiene ninguno (y tiene el flag de opt-in y teléfono). Nunca
 * los dos al mismo admin. Hasta esta misión el primero mandaba push Y WhatsApp a quien tuviera el
 * flag, y el segundo mandaba SOLO WhatsApp y ningún push.
 *
 * Los envíos externos están sustituidos (el push por el método protegido, el WhatsApp a nivel
 * `WhatsappSendService`): acá se mide la DECISIÓN de a quién le va cada canal, que es lo que decide
 * el código. La audiencia del push (setters + campanita) ya la prueba RuteoDePushPorRolTest.
 */
class AvisosDeVerificacionPorPushTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja la base sin roles, sin flags y sin devices: estos tests cuentan destinatarios exactos y
     * cualquier admin real del slot con `es_setter`, un flag o un device los contaminaría. Todo
     * adentro de la transacción del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Admin::query()->update([
            'es_setter'                                 => false,
            'is_closer'                                 => false,
            'notify_verificacion_whatsapp'              => false,
            'notify_verificacion_agendamiento_whatsapp' => false,
        ]);
        AdminPushSubscription::query()->delete();
    }

    /**
     * Admin con teléfono cargado y los atributos pedidos.
     *
     * @param string               $email
     * @param array<string, mixed> $atributos
     *
     * @return Admin
     */
    private function crear_admin(string $email, array $atributos = []): Admin
    {
        $admin               = new Admin();
        $admin->name         = 'Admin ' . $email;
        $admin->email        = $email;
        $admin->password     = bcrypt('secret');
        $admin->phone_number = '549341' . random_int(1000000, 9999999);

        foreach ($atributos as $campo => $valor) {
            $admin->{$campo} = $valor;
        }

        $admin->save();

        return $admin;
    }

    /**
     * Registra un device de push para el admin.
     *
     * @param Admin $admin
     *
     * @return void
     */
    private function registrar_device(Admin $admin): void
    {
        $endpoint = 'https://web.push.apple.com/' . str_repeat('A', 300) . $admin->id;

        $sub                = new AdminPushSubscription();
        $sub->admin_id      = $admin->id;
        $sub->endpoint      = $endpoint;
        $sub->endpoint_hash = AdminPushSubscription::hash_endpoint($endpoint);
        $sub->p256dh        = 'p';
        $sub->auth          = 'a';
        $sub->save();
    }

    /**
     * Lead con una sugerencia esperando aprobación.
     *
     * @return array{0: Lead, 1: LeadMessage}
     */
    private function lead_con_sugerencia_pendiente(): array
    {
        $lead = Lead::create([
            'contact_name' => 'Juana Pérez',
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);

        $sugerencia = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Propuesta esperando el ok',
            'status'                => 'sugerido',
            'kind'                  => 'text',
            'requiere_verificacion' => true,
            'is_status_event'       => false,
            'is_error'              => false,
        ]);

        return [$lead, $sugerencia];
    }

    /**
     * Servicio de envío de WhatsApp sustituido: registra los teléfonos destino en vez de llamar a Meta.
     *
     * @return WhatsappSendService
     */
    private function sender_espia(): WhatsappSendService
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
     * El servicio de fuera del tramo con el push sustituido.
     *
     * @param WhatsappSendService $sender
     *
     * @return LeadVerificacionWhatsappService
     */
    private function servicio_fuera_del_tramo(WhatsappSendService $sender): LeadVerificacionWhatsappService
    {
        return new class($sender) extends LeadVerificacionWhatsappService {
            /** @var array<int, int> Admins a los que se habría mandado el push. */
            public $pushes = [];

            protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
            {
                $this->pushes[] = $admin_id;
            }
        };
    }

    /**
     * El servicio del tramo de agenda con el push sustituido.
     *
     * @param WhatsappSendService $sender
     *
     * @return LeadVerificacionAgendamientoNotificationService
     */
    private function servicio_de_agenda(WhatsappSendService $sender): LeadVerificacionAgendamientoNotificationService
    {
        return new class($sender) extends LeadVerificacionAgendamientoNotificationService {
            /** @var array<int, int> Admins a los que se habría mandado el push. */
            public $pushes = [];

            protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
            {
                $this->pushes[] = $admin_id;
            }
        };
    }

    /**
     * 🔴 Fuera del tramo: el setter con device recibe PUSH (hasta hoy no recibía ninguno) y NO el
     * WhatsApp, aunque tenga el flag.
     *
     * @return void
     */
    public function test_fuera_del_tramo_el_setter_con_device_recibe_push_y_no_whatsapp(): void
    {
        $setter = $this->crear_admin('fuera-con-device@test.local', ['es_setter' => true, 'notify_verificacion_whatsapp' => true]);
        $this->registrar_device($setter);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_fuera_del_tramo($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([(int) $setter->id], $servicio->pushes, 'El push le llega al setter con device.');
        $this->assertSame([], $sender->destinatarios, 'Con device no sale el WhatsApp, aunque tenga el flag.');
        $this->assertSame([$setter->name], $avisados);
    }

    /**
     * Fuera del tramo: el setter SIN device y con el flag recibe el WhatsApp de respaldo, y ningún push.
     *
     * @return void
     */
    public function test_fuera_del_tramo_el_setter_sin_device_recibe_el_whatsapp_de_respaldo(): void
    {
        $setter = $this->crear_admin('fuera-sin-device@test.local', ['es_setter' => true, 'notify_verificacion_whatsapp' => true]);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_fuera_del_tramo($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([], $servicio->pushes, 'Sin device no hay push.');
        $this->assertSame([$setter->phone_number], $sender->destinatarios, 'El WhatsApp de respaldo le llega a quien no tiene device.');
        $this->assertSame([$setter->name], $avisados);
    }

    /**
     * Fuera del tramo: el flag sigue siendo la condición del respaldo. Un setter sin device y SIN el
     * flag no recibe WhatsApp (y sin device tampoco push).
     *
     * @return void
     */
    public function test_fuera_del_tramo_sin_el_flag_no_hay_whatsapp_de_respaldo(): void
    {
        $this->crear_admin('fuera-sin-flag@test.local', ['es_setter' => true, 'notify_verificacion_whatsapp' => false]);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_fuera_del_tramo($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([], $servicio->pushes);
        $this->assertSame([], $sender->destinatarios, 'El flag es opt-in y sigue mandando sobre el respaldo.');
        $this->assertSame([], $avisados);
    }

    /**
     * Fuera del tramo, los dos a la vez: cada admin recibe UN solo canal, el que le corresponde.
     *
     * @return void
     */
    public function test_fuera_del_tramo_cada_admin_recibe_un_solo_canal(): void
    {
        $con_device = $this->crear_admin('fuera-mixto-con@test.local', ['es_setter' => true, 'notify_verificacion_whatsapp' => true]);
        $this->registrar_device($con_device);
        $sin_device = $this->crear_admin('fuera-mixto-sin@test.local', ['es_setter' => true, 'notify_verificacion_whatsapp' => true]);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_fuera_del_tramo($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([(int) $con_device->id], $servicio->pushes);
        $this->assertSame([$sin_device->phone_number], $sender->destinatarios);

        sort($avisados);
        $esperados = [$con_device->name, $sin_device->name];
        sort($esperados);
        $this->assertSame($esperados, $avisados, 'Los dos quedan registrados como avisados, cada uno por su canal.');
    }

    /**
     * Fuera del tramo: el suscrito por campanita (no setter) con device también recibe el push —es la
     * misma audiencia que el aviso de agendamiento.
     *
     * @return void
     */
    public function test_fuera_del_tramo_la_campanita_recibe_el_push(): void
    {
        $setter    = $this->crear_admin('fuera-setter@test.local', ['es_setter' => true]);
        $this->registrar_device($setter);
        $campanita = $this->crear_admin('fuera-campanita@test.local');
        $this->registrar_device($campanita);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();
        $lead->notification_admins()->attach($campanita->id);

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_fuera_del_tramo($sender);

        $servicio->notify($lead, $sugerencia);

        $pushes = $servicio->pushes;
        sort($pushes);
        $esperados = [(int) $setter->id, (int) $campanita->id];
        sort($esperados);

        $this->assertSame($esperados, $pushes, 'Setter y campanita: la misma audiencia que el aviso de agendamiento.');
        $this->assertSame([], $sender->destinatarios);
    }

    /**
     * 🔴 Tramo de agenda: el setter con device y con el flag recibe el push y YA NO el WhatsApp
     * (hasta hoy recibía los dos).
     *
     * @return void
     */
    public function test_en_el_tramo_el_setter_con_device_recibe_push_y_ya_no_whatsapp(): void
    {
        $setter = $this->crear_admin('agenda-con-device@test.local', ['es_setter' => true, 'notify_verificacion_agendamiento_whatsapp' => true]);
        $this->registrar_device($setter);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_de_agenda($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([(int) $setter->id], $servicio->pushes, 'El push queda como estaba.');
        $this->assertSame([], $sender->destinatarios, 'El WhatsApp ya no sale para quien tiene device.');
        $this->assertSame([$setter->name], $avisados);
    }

    /**
     * Tramo de agenda: el setter SIN device y con el flag recibe el WhatsApp de respaldo.
     *
     * @return void
     */
    public function test_en_el_tramo_el_setter_sin_device_recibe_el_whatsapp_de_respaldo(): void
    {
        $setter = $this->crear_admin('agenda-sin-device@test.local', ['es_setter' => true, 'notify_verificacion_agendamiento_whatsapp' => true]);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_de_agenda($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([], $servicio->pushes);
        $this->assertSame([$setter->phone_number], $sender->destinatarios);
        $this->assertSame([$setter->name], $avisados);
    }

    /**
     * Tramo de agenda: sin el flag de agendamiento no hay WhatsApp de respaldo, aunque no tenga device.
     * Y el flag del OTRO motivo (`notify_verificacion_whatsapp`) no cuenta acá.
     *
     * @return void
     */
    public function test_en_el_tramo_sin_el_flag_de_agendamiento_no_hay_whatsapp(): void
    {
        $this->crear_admin('agenda-sin-flag@test.local', ['es_setter' => true, 'notify_verificacion_whatsapp' => true]);

        [$lead, $sugerencia] = $this->lead_con_sugerencia_pendiente();

        $sender   = $this->sender_espia();
        $servicio = $this->servicio_de_agenda($sender);

        $avisados = $servicio->notify($lead, $sugerencia);

        $this->assertSame([], $servicio->pushes);
        $this->assertSame([], $sender->destinatarios, 'El flag de agendamiento es la condición del respaldo; el otro flag no aplica.');
        $this->assertSame([], $avisados);
    }

    /**
     * Las firmas públicas se mantienen: `notify(Lead, LeadMessage): array` en los dos servicios, con
     * los constructores que ya usan `LeadAiService`, `LeadFollowupService` y `LeadSuggestionSendService`.
     *
     * @return void
     */
    public function test_las_firmas_publicas_no_cambiaron(): void
    {
        $fuera  = new \ReflectionMethod(LeadVerificacionWhatsappService::class, 'notify');
        $agenda = new \ReflectionMethod(LeadVerificacionAgendamientoNotificationService::class, 'notify');

        foreach ([$fuera, $agenda] as $metodo) {
            $this->assertTrue($metodo->isPublic());
            $this->assertSame('array', (string) $metodo->getReturnType());
            $this->assertSame(['lead', 'message'], array_map(function ($p) {
                return $p->getName();
            }, $metodo->getParameters()));
        }

        /* Los constructores: el de agenda acepta null (LeadSuggestionSendService lo instancia sin argumentos). */
        $this->assertInstanceOf(LeadVerificacionAgendamientoNotificationService::class, new LeadVerificacionAgendamientoNotificationService());
        $this->assertInstanceOf(LeadVerificacionWhatsappService::class, new LeadVerificacionWhatsappService(new WhatsappSendService()));
    }
}
