<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Services\LeadMessageNotificationWhatsappService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El filtro por admin del WhatsApp de respaldo.
 *
 * Este test existe por un motivo concreto: el resto de la suite sustituye el envío del
 * WhatsApp, así que nadie ejercitaba la consulta real. Y la consulta tiene una trampa —
 * `notification_admins()` es un belongsToMany contra la pivot `lead_admin_notifications`,
 * que TAMBIÉN tiene una columna `admin_id`; un `whereIn` sin calificar con la tabla puede
 * tirar "Column 'admin_id' in where clause is ambiguous" recién en producción.
 *
 * Acá el envío se sustituye a nivel WhatsappSendService, así que la consulta que decide
 * a quién se le manda es la de producción, sin tocar la red.
 */
class LeadMessageWhatsappFallbackTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea un admin con teléfono cargado.
     *
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin               = new Admin();
        $admin->name         = 'Admin ' . $email;
        $admin->email        = $email;
        $admin->password     = bcrypt('secret');
        $admin->phone_number = '549341' . random_int(1000000, 9999999);
        $admin->save();

        return $admin;
    }

    /**
     * Servicio de envío sustituido: registra los destinatarios en vez de llamar a Meta.
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
     * Con la lista de admins, la consulta filtra por esos admins y no explota por ambigüedad.
     *
     * @return void
     */
    public function test_con_lista_de_admins_solo_notifica_a_esos()
    {
        $uno = $this->crear_admin('fallback-uno@test.local');
        $dos = $this->crear_admin('fallback-dos@test.local');

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->save();
        $lead->notification_admins()->attach([$uno->id, $dos->id]);

        $sender   = $this->sender_espia();
        $servicio = new LeadMessageNotificationWhatsappService($sender);

        $servicio->notify($lead, 'Mensaje del lead', [(int) $dos->id]);

        $this->assertSame(
            [$dos->phone_number],
            $sender->destinatarios,
            'El filtro por admin no se aplicó: el WhatsApp salió a quien no correspondía.'
        );
    }

    /**
     * Sin lista, se mantiene el comportamiento histórico: todos los admins suscritos.
     *
     * @return void
     */
    public function test_sin_lista_notifica_a_todos_los_suscritos()
    {
        $uno = $this->crear_admin('fallback-todos-uno@test.local');
        $dos = $this->crear_admin('fallback-todos-dos@test.local');

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->save();
        $lead->notification_admins()->attach([$uno->id, $dos->id]);

        $sender   = $this->sender_espia();
        $servicio = new LeadMessageNotificationWhatsappService($sender);

        $servicio->notify($lead, 'Mensaje del lead');

        $destinatarios = $sender->destinatarios;
        sort($destinatarios);
        $esperados = [$uno->phone_number, $dos->phone_number];
        sort($esperados);

        $this->assertSame($esperados, $destinatarios);
    }

    /**
     * Un admin que NO está suscrito al lead no recibe nada, aunque venga en la lista.
     *
     * La lista acota, no agrega: la suscripción al lead sigue mandando.
     *
     * @return void
     */
    public function test_la_lista_no_alcanza_a_un_admin_no_suscrito()
    {
        $suscrito    = $this->crear_admin('fallback-suscrito@test.local');
        $no_suscrito = $this->crear_admin('fallback-no-suscrito@test.local');

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->save();
        $lead->notification_admins()->attach($suscrito->id);

        $sender   = $this->sender_espia();
        $servicio = new LeadMessageNotificationWhatsappService($sender);

        $servicio->notify($lead, 'Mensaje del lead', [(int) $no_suscrito->id]);

        $this->assertSame([], $sender->destinatarios);
    }
}
