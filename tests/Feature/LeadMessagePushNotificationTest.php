<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Lead;
use App\Services\LeadMessagePushNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Elección de canal al llegar un mensaje de un lead con la campanita activa.
 *
 * La regla (decisión de Lucas, 11/8/2026): push a los admins suscritos que tengan device
 * registrado; WhatsApp SOLO a los que no tienen ninguno. No son dos canales en paralelo.
 *
 * El envío real (push service de Apple, API de Meta) está sustituido por los métodos
 * protegidos del servicio: acá se verifica la decisión de a quién le va cada canal, que
 * es lo que el código decide. Que el push efectivamente llegue al teléfono depende de las
 * claves VAPID de producción y de Apple, y es prueba manual.
 */
class LeadMessagePushNotificationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea un admin con teléfono cargado (condición para el WhatsApp de respaldo).
     *
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin               = new Admin();
        $admin->name         = 'Admin de prueba';
        $admin->email        = $email;
        $admin->password     = bcrypt('secret');
        $admin->phone_number = '5493416000000';
        $admin->save();

        return $admin;
    }

    /**
     * Crea un lead mínimo.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->save();

        return $lead;
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

    /**
     * Servicio con los dos envíos externos sustituidos por registradores de llamadas.
     *
     * @return LeadMessagePushNotificationService
     */
    private function servicio_espia(): LeadMessagePushNotificationService
    {
        return new class extends LeadMessagePushNotificationService {
            /** @var array<int, array<string, mixed>> Pushes que se habrían enviado. */
            public $pushes = [];

            /** @var array<int, array<string, mixed>> Envíos de WhatsApp que se habrían hecho. */
            public $whatsapps = [];

            protected function send_push(int $admin_id, string $title, string $body, array $data): void
            {
                $this->pushes[] = [
                    'admin_id' => $admin_id,
                    'title'    => $title,
                    'body'     => $body,
                    'data'     => $data,
                ];
            }

            protected function send_whatsapp_fallback(\App\Models\Lead $lead, string $content, array $admin_ids): void
            {
                $this->whatsapps[] = [
                    'lead_id'   => (int) $lead->id,
                    'content'   => $content,
                    'admin_ids' => $admin_ids,
                ];
            }
        };
    }

    /**
     * Admin suscrito CON device registrado: recibe push y no recibe WhatsApp.
     *
     * @return void
     */
    public function test_admin_con_device_recibe_push_y_no_whatsapp()
    {
        $admin = $this->crear_admin('push-con-device@test.local');
        $lead  = $this->crear_lead();
        $lead->notification_admins()->attach($admin->id);
        $this->registrar_device($admin);

        $servicio = $this->servicio_espia();
        $servicio->notify($lead, 'Hola, quiero saber el precio');

        $this->assertCount(1, $servicio->pushes, 'No se envió el push al admin con device registrado.');
        $this->assertSame((int) $admin->id, $servicio->pushes[0]['admin_id']);
        $this->assertSame('Juana Pérez', $servicio->pushes[0]['title']);
        $this->assertSame('Hola, quiero saber el precio', $servicio->pushes[0]['body']);
        $this->assertSame(['url' => '/leads?lead_id=' . $lead->id], $servicio->pushes[0]['data']);

        $this->assertCount(0, $servicio->whatsapps, 'Se mandó WhatsApp a un admin que tiene device registrado.');
    }

    /**
     * Admin suscrito SIN ningún device: no hay push posible, va el WhatsApp de respaldo.
     *
     * @return void
     */
    public function test_admin_sin_device_recibe_whatsapp()
    {
        $admin = $this->crear_admin('push-sin-device@test.local');
        $lead  = $this->crear_lead();
        $lead->notification_admins()->attach($admin->id);

        $servicio = $this->servicio_espia();
        $servicio->notify($lead, 'Hola, quiero saber el precio');

        $this->assertCount(0, $servicio->pushes, 'Se intentó un push a un admin sin device registrado.');
        $this->assertCount(1, $servicio->whatsapps, 'No se mandó el WhatsApp de respaldo.');
        $this->assertSame([(int) $admin->id], $servicio->whatsapps[0]['admin_ids']);
    }

    /**
     * Con los dos tipos de admin suscritos al mismo lead, cada uno recibe lo suyo:
     * el WhatsApp de respaldo se dirige únicamente al que no tiene device.
     *
     * @return void
     */
    public function test_el_whatsapp_de_respaldo_va_solo_al_admin_sin_device()
    {
        $con_device = $this->crear_admin('push-mixto-con@test.local');
        $sin_device = $this->crear_admin('push-mixto-sin@test.local');

        $lead = $this->crear_lead();
        $lead->notification_admins()->attach([$con_device->id, $sin_device->id]);
        $this->registrar_device($con_device);

        $servicio = $this->servicio_espia();
        $servicio->notify($lead, 'Consulta del lead');

        $this->assertCount(1, $servicio->pushes);
        $this->assertSame((int) $con_device->id, $servicio->pushes[0]['admin_id']);

        $this->assertCount(1, $servicio->whatsapps);
        $this->assertSame([(int) $sin_device->id], $servicio->whatsapps[0]['admin_ids']);
    }

    /**
     * El preview del cuerpo se corta en 120 caracteres, igual que en el aviso por WhatsApp.
     *
     * @return void
     */
    public function test_el_cuerpo_del_push_se_corta_en_120_caracteres()
    {
        $admin = $this->crear_admin('push-preview@test.local');
        $lead  = $this->crear_lead();
        $lead->notification_admins()->attach($admin->id);
        $this->registrar_device($admin);

        $servicio = $this->servicio_espia();
        $servicio->notify($lead, str_repeat('x', 500));

        $this->assertCount(1, $servicio->pushes);
        $this->assertSame(120, mb_strlen($servicio->pushes[0]['body']));
    }

    /**
     * Sin admins suscritos al lead no se notifica por ningún canal.
     *
     * @return void
     */
    public function test_lead_sin_admins_suscritos_no_notifica_nada()
    {
        $lead = $this->crear_lead();

        $servicio = $this->servicio_espia();
        $servicio->notify($lead, 'Mensaje suelto');

        $this->assertCount(0, $servicio->pushes);
        $this->assertCount(0, $servicio->whatsapps);
    }
}
