<?php

namespace Tests\Feature;

use App\Events\AdminTaskNotificationCreated;
use App\Events\LeadAiSuggestionFinished;
use App\Events\LeadAiSuggestionGenerating;
use App\Events\LeadConversationUpdated;
use App\Events\LeadSuggestionCreated;
use App\Events\SupportTicketUpdated;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Client;
use App\Models\Lead;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Los eventos que van por Pusher mandan IDS Y NADA MÁS.
 *
 * 🔴 QUÉ IMPIDE ESTE TEST, que es lo único que importa de él: que alguien vuelva a meter el
 * modelo adentro del payload. Es fácil que parezca una mejora —«total, así la SPA no tiene que
 * hacer un GET»— y no lo es:
 *
 * 1. **Los canales son públicos.** `leads.admins` y `support.admins` se declaran con `Channel`,
 *    no con `PrivateChannel`, y la clave de Pusher está horneada en el bundle de admin-spa. Se
 *    suscribe cualquiera, logueado o no. El `Lead` que viajaba hasta el 2/9/2026 llevaba
 *    adentro `phone`, `email`, `notes`, `call_summary` y `demo_summary`; el `SupportTicket`,
 *    `client_user_email`, `whatsapp_phone` y el último mensaje del cliente. Eso salía sin
 *    ninguna autenticación. El id no dice nada de nadie, y la API —que sí verifica quién pide
 *    qué— es la que sirve el resto.
 * 2. **El tamaño lo fijaba alguien de afuera.** Medición del 2/9/2026 sobre el lead Juan, con
 *    la demo resuelta: el payload viejo de `LeadSuggestionCreated` (el `Lead` con sus cinco
 *    relaciones) pesaba **23221 bytes** contra los **10240** que admite Pusher Channels. El
 *    broadcast reventaba entero con «The data content of this event exceeds the allowed
 *    maximum (10240 bytes)», y como el evento es `ShouldBroadcastNow` la excepción subía hasta
 *    el `catch` del controlador: la pantalla dijo «No se pudo generar la sugerencia» sobre una
 *    sugerencia que existía, estaba guardada y se veía en esa misma pantalla.
 *
 * Un payload de ids no crece nunca, no expone nada y no necesita una consulta por evento.
 *
 * La segunda mitad del arreglo también se fija acá: {@see \App\Support\BroadcastGuard} — un
 * aviso que falla no puede voltear la operación que ya terminó.
 */
class LosEventosDeBroadcastMandanSoloIdsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead con las columnas grandes cargadas: las que hasta el 2/9/2026 viajaban por el canal
     * público. Se llenan a propósito para que el test falle si alguna vuelve al payload.
     *
     * @return Lead
     */
    private function crear_lead_con_datos_personales(): Lead
    {
        $lead = Lead::create([
            'contact_name' => 'Juan de prueba',
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);

        $lead->notes         = 'Nota interna del setter que no va a un canal público.';
        $lead->call_summary  = 'Resumen de la llamada con el closer.';
        $lead->demo_summary  = 'Resumen de lo que el lead recorrió en la demo.';
        $lead->save();

        return $lead;
    }

    /**
     * Cliente mínimo para colgarle un ticket.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = '+5493411111111';
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Admin mínimo.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        return Admin::create([
            'name'     => 'Admin de prueba',
            'email'    => 'broadcast-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * `LeadSuggestionCreated` manda el `lead_id` y nada más.
     *
     * @return void
     */
    public function test_lead_suggestion_created_manda_solo_el_lead_id()
    {
        $lead = $this->crear_lead_con_datos_personales();

        $payload = (new LeadSuggestionCreated((int) $lead->id))->broadcastWith();

        /* Igualdad estricta y no `assertArrayHasKey`: lo que se está fijando es que NO haya
         * ninguna otra clave, que es exactamente lo que se pierde si alguien "mejora" el evento
         * agregándole el modelo de vuelta. */
        $this->assertSame(['lead_id' => (int) $lead->id], $payload);

        /* Y las columnas puntuales que se estaban publicando, nombradas una por una: si mañana
         * aparece una clave nueva con el teléfono adentro, el assertSame de arriba ya lo caza,
         * pero esto deja escrito POR QUÉ importa. */
        $json = json_encode($payload);
        $this->assertStringNotContainsString((string) $lead->phone, $json, 'El teléfono del lead no sale por un canal público.');
        $this->assertStringNotContainsString('Nota interna del setter', $json);
        $this->assertStringNotContainsString('Resumen de la llamada', $json);
    }

    /**
     * `SupportTicketUpdated` manda el `support_ticket_id` y nada más.
     *
     * @return void
     */
    public function test_support_ticket_updated_manda_solo_el_support_ticket_id()
    {
        $client = $this->crear_cliente();

        $ticket = SupportTicket::create([
            'client_id'         => $client->id,
            'client_user_id'    => 0,
            'client_user_email' => 'contacto-del-cliente@test.local',
            'status'            => 'open',
            'source'            => 'whatsapp',
            'whatsapp_phone'    => '+5493415559999',
            'opened_at'         => now(),
        ]);

        $payload = (new SupportTicketUpdated((int) $ticket->id))->broadcastWith();

        $this->assertSame(['support_ticket_id' => (int) $ticket->id], $payload);

        $json = json_encode($payload);
        $this->assertStringNotContainsString('contacto-del-cliente@test.local', $json, 'El mail del contacto no sale por un canal público.');
        $this->assertStringNotContainsString('+5493415559999', $json);
    }

    /**
     * `AdminTaskNotificationCreated` manda el `notification_id` y nada más, **y sigue sabiendo
     * a qué canal privado va**.
     *
     * Esta segunda mitad es la que hay que mirar: el `admin_id` que arma el nombre del canal se
     * resuelve en el constructor, con su propia consulta, y NO salía de la consulta que se sacó
     * de `broadcastWith()`. Si alguien mueve esa resolución al payload, `broadcastOn()` se queda
     * sin destinatario y el aviso no le llega a nadie — sin que nada tire error.
     *
     * @return void
     */
    public function test_admin_task_notification_created_manda_solo_el_notification_id()
    {
        $admin = $this->crear_admin();

        $task = AdminTask::create([
            'title'               => 'Tarea de prueba',
            'content'             => 'Contenido de la tarea',
            'created_by_admin_id' => $admin->id,
        ]);

        $notification = AdminTaskNotification::create([
            'admin_task_id' => $task->id,
            'admin_id'      => $admin->id,
            'seen_at'       => null,
        ]);

        $evento  = new AdminTaskNotificationCreated((int) $notification->id);
        $payload = $evento->broadcastWith();

        $this->assertSame(['notification_id' => (int) $notification->id], $payload);

        $this->assertTrue($evento->broadcastWhen(), 'El evento tiene que seguir emitiéndose.');

        $canales = $evento->broadcastOn();
        $this->assertSame(
            'private-admin.' . $admin->id,
            $canales[0]->name,
            'El canal privado del destinatario no puede depender de la consulta que se sacó del payload.'
        );
    }

    /**
     * `LeadConversationUpdated` ya mandaba solo ids: acá se fija que siga así.
     *
     * Es el que más se dispara —uno por mensaje— y el único de los cuatro que nunca chocó con
     * el límite de Pusher, justamente porque nada de lo que manda crece.
     *
     * @return void
     */
    public function test_lead_conversation_updated_manda_solo_escalares()
    {
        $lead = $this->crear_lead_con_datos_personales();

        $payload = (new LeadConversationUpdated((int) $lead->id, 77, true, 'fallido'))->broadcastWith();

        $this->assertSame(
            ['lead_id', 'lead_message_id', 'is_status_update', 'delivery_status'],
            array_keys($payload),
            'El payload de este evento no gana claves nuevas sin que alguien lo mire.'
        );

        /* Ningún valor puede ser un modelo ni un array: en cuanto lo sea, el payload empieza a
         * crecer con el dato y a publicar lo que el dato tenga adentro. */
        foreach ($payload as $clave => $valor) {
            $this->assertTrue(
                $valor === null || is_scalar($valor),
                'La clave "' . $clave . '" tiene que ser un escalar; un modelo acá crece y expone.'
            );
        }
    }

    /**
     * Una falla al emitir NO puede voltear la operación que ya terminó (`LeadSuggestionCreated`).
     *
     * Es la segunda mitad del defecto de producción: el evento se dispara al final de
     * `LeadAiService::generate_suggestion()`, con la sugerencia ya persistida, y la excepción de
     * Pusher subía hasta el `catch` del controlador.
     *
     * La falla se fuerza con un listener que tira excepción —la misma vía por la que `event()`
     * propaga cualquier problema de emisión— y se verifica que `dispatch()` no la deje salir.
     * 🔴 Si alguien "simplifica" el `dispatch()` del evento sacándole el BroadcastGuard, este
     * test se pone rojo.
     *
     * @return void
     */
    public function test_una_falla_al_emitir_no_se_propaga_a_quien_disparo_el_evento()
    {
        Event::listen(LeadSuggestionCreated::class, function () {
            throw new \RuntimeException('Pusher error: The data content of this event exceeds the allowed maximum (10240 bytes)');
        });

        $lead = $this->crear_lead_con_datos_personales();

        LeadSuggestionCreated::dispatch((int) $lead->id);

        /* Llegar hasta acá ya es el resultado: la excepción quedó adentro del guard. */
        $this->assertTrue(true, 'El dispatch no puede propagar la falla del aviso.');
    }

    /**
     * Lo mismo para `LeadConversationUpdated`, que es el que más se dispara.
     *
     * El payload de este evento nunca creció, pero el guard no protege contra el tamaño: protege
     * contra la emisión. Si Pusher se cae, la excepción marcaba como fallido un mensaje que sí
     * salió y sí quedó registrado.
     *
     * @return void
     */
    public function test_una_falla_al_emitir_la_conversacion_tampoco_se_propaga()
    {
        Event::listen(LeadConversationUpdated::class, function () {
            throw new \RuntimeException('Pusher error: connection refused');
        });

        $lead = $this->crear_lead_con_datos_personales();

        LeadConversationUpdated::dispatch((int) $lead->id, null);

        $this->assertTrue(true, 'El mensaje ya salió: una caída de Pusher no puede volver atrás.');
    }

    /**
     * Y lo mismo para el par que prende y apaga el spinner de la sugerencia.
     *
     * 🔴 `LeadAiSuggestionFinished` es el peor caso de todos y por eso tiene test propio: se
     * emite adentro de un `finally` —en `LeadController@request_ai_suggestion_json`, en
     * `@resume_with_claude_json` y en `GenerateLeadAiSuggestionJob`— y una excepción en un
     * `finally` de PHP **reemplaza el return pendiente**. Sin guard, una caída de Pusher no
     * informaba mal el resultado: lo cambiaba, convirtiendo en 500 un pedido que había
     * terminado bien y ya tenía su sugerencia guardada.
     *
     * @return void
     */
    public function test_los_avisos_del_spinner_de_sugerencia_tampoco_voltean_la_operacion()
    {
        Event::listen(LeadAiSuggestionGenerating::class, function () {
            throw new \RuntimeException('Pusher error: connection refused');
        });
        Event::listen(LeadAiSuggestionFinished::class, function () {
            throw new \RuntimeException('Pusher error: connection refused');
        });

        $lead = $this->crear_lead_con_datos_personales();

        /* Se emiten como los emite el controlador: primero el de "arrancó", después el del
         * `finally`. Si cualquiera de los dos propagara, este test no llegaría al assert. */
        LeadAiSuggestionGenerating::dispatch((int) $lead->id);
        LeadAiSuggestionFinished::dispatch((int) $lead->id);

        $this->assertTrue(true, 'Un aviso de spinner no puede voltear la generación de la sugerencia.');
    }
}
