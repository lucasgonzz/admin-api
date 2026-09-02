<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Lead;
use App\Services\LeadNotificationAudienceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ruteo de las notificaciones de la conversación de un lead entre el closer y el setter.
 *
 * LAS TRES REGLAS (decisión de Lucas, 2/9/2026):
 *   1. Mensaje entrante común -> del dueño del lead según su estado: closer si está en
 *      closer_activo, setter en cualquier otro caso. Solo dispara si alguien tiene la campanita.
 *   2. Mensaje que requiere verificación -> SIEMPRE a los setters, aunque el lead esté con el closer.
 *   3. Escalado a humano -> SIEMPRE a los setters, esté el lead donde esté.
 *
 * Se prueba el resolver directo y no a través de los tres servicios porque es él quien toma la
 * decisión: los servicios solo eligen el canal (push o WhatsApp según haya device), y eso ya está
 * cubierto por LeadMessagePushNotificationTest y LeadMessageWhatsappFallbackTest. Meter el envío en
 * el medio agregaría dos espías y no probaría nada más sobre el ruteo.
 */
class RuteoDePushPorRolTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja la base sin ningún admin con rol antes de cada test.
     *
     * 🔴 IMPRESCINDIBLE. La suite corre contra la base MySQL real del slot (admin_testing_s11), que
     * tiene admins sembrados con flags puestos. Sin esto, cualquier assertSame sobre la lista de
     * destinatarios es una lotería que depende de qué haya en esa base. Va adentro de la
     * transacción de DatabaseTransactions y se revierte al terminar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Admin::query()->update(['es_setter' => false, 'is_closer' => false]);
    }

    /**
     * Crea un admin con el rol pedido.
     *
     * @param string $email
     * @param array<string, bool> $roles Ej. ['es_setter' => true]
     *
     * @return Admin
     */
    private function crear_admin(string $email, array $roles = []): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin ' . $email;
        $admin->email    = $email;
        $admin->password = bcrypt('secret');

        foreach ($roles as $columna => $valor) {
            $admin->{$columna} = $valor;
        }

        $admin->save();

        return $admin;
    }

    /**
     * Crea un lead en el estado pedido.
     *
     * @param string $status Cadena vacía para el caso "sin estado". No acepta null: la columna
     *                       `status` de `leads` es NOT NULL, así que un lead sin estado en
     *                       producción solo puede tener cadena vacía.
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'contactado'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /* ---------------------------------------------------------------------------------------
     * Regla 1 — mensaje entrante común: manda el estado del lead
     * ------------------------------------------------------------------------------------ */

    /**
     * Lead en closer_activo: el mensaje es del closer, no del setter.
     *
     * @return void
     */
    public function test_mensaje_entrante_de_lead_en_closer_activo_va_al_closer()
    {
        $closer = $this->crear_admin('ruteo-closer@test.local', ['is_closer' => true]);
        $setter = $this->crear_admin('ruteo-setter@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('closer_activo');
        $lead->notification_admins()->attach($setter->id);

        $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        $this->assertContains((int) $closer->id, $ids, 'El closer no recibió el mensaje de su lead.');
    }

    /**
     * Lead en cualquier otro estado: el mensaje es del setter y el closer no se entera.
     *
     * @return void
     */
    public function test_mensaje_entrante_de_lead_fuera_del_tramo_del_closer_va_al_setter()
    {
        $closer = $this->crear_admin('ruteo-closer2@test.local', ['is_closer' => true]);
        $setter = $this->crear_admin('ruteo-setter2@test.local', ['es_setter' => true]);

        /* Se prueban dos estados bien separados del pipeline para que el test no dependa de uno
         * puntual: uno del arranque y uno del tramo de la demo. */
        foreach (['contactado', 'demo_agendada'] as $status) {
            $lead = $this->crear_lead($status);
            $lead->notification_admins()->attach($setter->id);

            $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

            $this->assertContains((int) $setter->id, $ids, "El setter no recibió el mensaje del lead en {$status}.");
            $this->assertNotContains((int) $closer->id, $ids, "El closer recibió un mensaje de un lead en {$status}, que no es suyo.");
        }
    }

    /**
     * Lead sin estado: cae al setter, que es el default seguro.
     *
     * @return void
     */
    public function test_mensaje_entrante_de_lead_sin_estado_va_al_setter()
    {
        $setter = $this->crear_admin('ruteo-setter3@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('');
        $lead->notification_admins()->attach($setter->id);

        $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        $this->assertContains((int) $setter->id, $ids);
    }

    /**
     * 🔴 Sin campanita no se notifica a nadie, aunque haya roles marcados.
     *
     * Es el pedido explícito de Lucas: el rol reparte, pero NO genera avisos donde antes no había.
     * Si este test se pone en verde aflojándolo, el volumen de notificaciones se dispara.
     *
     * @return void
     */
    public function test_sin_campanita_el_mensaje_entrante_no_notifica_a_nadie()
    {
        $this->crear_admin('ruteo-closer4@test.local', ['is_closer' => true]);
        $this->crear_admin('ruteo-setter4@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('closer_activo');

        $this->assertSame([], LeadNotificationAudienceResolver::for_mensaje_entrante($lead));
    }

    /* ---------------------------------------------------------------------------------------
     * Reglas 2 y 3 — verificación y escalado: siempre el setter, sin mirar el estado
     * ------------------------------------------------------------------------------------ */

    /**
     * Verificación con el lead ya en manos del closer: la aprueba el setter igual.
     *
     * @return void
     */
    public function test_verificacion_va_al_setter_aunque_el_lead_este_en_closer_activo()
    {
        $closer = $this->crear_admin('ruteo-closer5@test.local', ['is_closer' => true]);
        $setter = $this->crear_admin('ruteo-setter5@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('closer_activo');

        $ids = LeadNotificationAudienceResolver::for_verificacion($lead);

        $this->assertContains((int) $setter->id, $ids, 'El setter no recibió la verificación.');
        $this->assertNotContains((int) $closer->id, $ids, 'El closer recibió una verificación que no le toca aprobar.');
    }

    /**
     * Escalado con el lead ya en manos del closer: vuelve al setter igual.
     *
     * @return void
     */
    public function test_escalado_va_al_setter_aunque_el_lead_este_en_closer_activo()
    {
        $closer = $this->crear_admin('ruteo-closer6@test.local', ['is_closer' => true]);
        $setter = $this->crear_admin('ruteo-setter6@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('closer_activo');

        $ids = LeadNotificationAudienceResolver::for_escalado($lead);

        $this->assertContains((int) $setter->id, $ids, 'El setter no recibió el escalado.');
        $this->assertNotContains((int) $closer->id, $ids, 'El closer recibió un escalado que no le corresponde.');
    }

    /**
     * Verificación y escalado disparan SIN campanita: no dependen de que nadie esté suscrito.
     *
     * @return void
     */
    public function test_verificacion_y_escalado_notifican_sin_campanita()
    {
        $setter = $this->crear_admin('ruteo-setter7@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('demo_agendada');

        $this->assertContains((int) $setter->id, LeadNotificationAudienceResolver::for_verificacion($lead));
        $this->assertContains((int) $setter->id, LeadNotificationAudienceResolver::for_escalado($lead));
    }

    /* ---------------------------------------------------------------------------------------
     * Regla 4 — la campanita prendida notifica siempre
     * ------------------------------------------------------------------------------------ */

    /**
     * El suscrito por campanita entra aunque no tenga ningún rol y el lead sea del closer.
     *
     * @return void
     */
    public function test_el_suscrito_por_campanita_se_suma_al_grupo_de_rol()
    {
        $closer   = $this->crear_admin('ruteo-closer8@test.local', ['is_closer' => true]);
        $curioso  = $this->crear_admin('ruteo-curioso@test.local');

        $lead = $this->crear_lead('closer_activo');
        $lead->notification_admins()->attach($curioso->id);

        $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        $this->assertContains((int) $closer->id, $ids, 'Falta el dueño por rol.');
        $this->assertContains((int) $curioso->id, $ids, 'La campanita prendida tiene que notificar siempre.');
    }

    /**
     * Un admin que es destinatario por rol Y está en la campanita aparece una sola vez.
     *
     * @return void
     */
    public function test_un_admin_que_es_setter_y_esta_en_la_campanita_aparece_una_sola_vez()
    {
        $setter = $this->crear_admin('ruteo-setter9@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('contactado');
        $lead->notification_admins()->attach($setter->id);

        $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        $repetidos = array_keys($ids, (int) $setter->id, true);

        $this->assertCount(1, $repetidos, 'El admin recibiría dos push por el mismo mensaje.');
    }

    /* ---------------------------------------------------------------------------------------
     * Regla 5 — fallback anti-silencio
     * ------------------------------------------------------------------------------------ */

    /**
     * Sin ningún closer marcado, el mensaje de un lead en closer_activo cae a los setters.
     *
     * Es el escenario más probable en producción del día 1: is_closer se seteó por una migración
     * con id hardcodeado, así que puede no haber ningún closer marcado en esa base.
     *
     * @return void
     */
    public function test_sin_ningun_closer_el_mensaje_en_closer_activo_cae_a_los_setters()
    {
        $setter = $this->crear_admin('ruteo-setter10@test.local', ['es_setter' => true]);

        $lead = $this->crear_lead('closer_activo');
        $lead->notification_admins()->attach($setter->id);

        $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        $this->assertContains((int) $setter->id, $ids, 'Sin closer marcado, el aviso tiene que caer al setter.');
    }

    /**
     * Sin closers ni setters, queda el que prendió la campanita.
     *
     * @return void
     */
    public function test_sin_closers_ni_setters_queda_el_suscrito_por_campanita()
    {
        $curioso = $this->crear_admin('ruteo-curioso2@test.local');

        $lead = $this->crear_lead('closer_activo');
        $lead->notification_admins()->attach($curioso->id);

        $ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        $this->assertSame([(int) $curioso->id], $ids);
    }

    /**
     * Sin roles y sin campanita no se manda nada. Es el único silencio legítimo.
     *
     * @return void
     */
    public function test_sin_roles_y_sin_campanita_no_se_manda_nada()
    {
        $lead = $this->crear_lead('contactado');

        $this->assertSame([], LeadNotificationAudienceResolver::for_mensaje_entrante($lead));
    }

    /* ---------------------------------------------------------------------------------------
     * El agujero anti-silencio que el ruteo destapó
     * ------------------------------------------------------------------------------------ */

    /**
     * 🔴 Un setter sin device y sin campanita tiene que llegar al WhatsApp de respaldo.
     *
     * Es el modo de falla que motivó tocar LeadMessageNotificationWhatsappService: el setter es
     * destinatario por rol, no tiene device (así que el push no le sirve) y no está en la pivot de
     * la campanita. Si el fallback siguiera filtrando por la pivot, no recibiría NADA por ningún
     * canal — y nada en el sistema lo denunciaría.
     *
     * @return void
     */
    public function test_un_setter_sin_device_y_sin_campanita_entra_en_el_fallback_de_whatsapp()
    {
        $setter = $this->crear_admin('ruteo-setter11@test.local', ['es_setter' => true]);
        $setter->phone_number = '5493416000001';
        $setter->save();

        /* Alguien con la campanita para que el aviso dispare, con device para que no se mezcle
         * con el fallback que estamos midiendo. */
        $suscrito = $this->crear_admin('ruteo-suscrito@test.local');
        $this->registrar_device($suscrito);

        $lead = $this->crear_lead('contactado');
        $lead->notification_admins()->attach($suscrito->id);

        $servicio = new class extends \App\Services\LeadMessagePushNotificationService {
            /** @var array<int, int> Admin ids que habrían recibido el WhatsApp de respaldo. */
            public $whatsapp_ids = [];

            protected function send_push(int $admin_id, string $title, string $body, array $data): void
            {
                /* Sin efecto: el envío real necesita las claves VAPID y salir a la red. */
            }

            protected function send_whatsapp_fallback(Lead $lead, string $content, array $admin_ids): void
            {
                $this->whatsapp_ids = $admin_ids;
            }
        };

        $servicio->notify($lead, 'Mensaje del lead');

        $this->assertContains(
            (int) $setter->id,
            $servicio->whatsapp_ids,
            'El setter sin device quedó sin push y sin WhatsApp: silencio total.'
        );
    }

    /**
     * Registra un device push para el admin.
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
        $sub->endpoint_hash = hash('sha256', $endpoint);
        $sub->p256dh        = 'p';
        $sub->auth          = 'a';
        $sub->save();
    }
}
