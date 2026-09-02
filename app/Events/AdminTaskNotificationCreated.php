<?php

namespace App\Events;

use App\Models\AdminTaskNotification;
use App\Support\BroadcastGuard;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento emitido cuando se crea un aviso in-app de asignación de tarea para un admin.
 *
 * Se escucha en un canal privado por admin (`admin.{admin_id}`) para que solo el
 * destinatario reciba el aviso en tiempo real si está conectado. Si no lo está, el
 * aviso queda igual persistido en admin_task_notifications y aparece al recargar.
 */
class AdminTaskNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Identificador de la notificación creada.
     *
     * Se prefiere el id sobre el modelo completo para no serializarlo en el
     * constructor; se carga la versión fresca (con su tarea) en {@see broadcastWith}.
     *
     * @var int
     */
    public $notification_id;

    /**
     * Id del admin destinatario, resuelto en el constructor para poder armar el
     * nombre del canal privado sin volver a consultar la base en broadcastOn().
     *
     * @var int|null
     */
    public $admin_id;

    /**
     * @param int $notification_id Id de la notificación recién creada.
     */
    public function __construct(int $notification_id)
    {
        $this->notification_id = $notification_id;

        // Resolver el admin_id ahora para poder armar el canal privado más abajo,
        // incluso si la notificación fuera borrada antes de que se despache el evento.
        $notification = AdminTaskNotification::find($notification_id);
        $this->admin_id = $notification ? $notification->admin_id : null;
    }

    /**
     * Emite el evento sin poder voltear a quien lo emitió.
     *
     * Sobreescribe el `dispatch()` de {@see \Illuminate\Foundation\Events\Dispatchable} para
     * que la emisión pase por {@see \App\Support\BroadcastGuard}. La notificación ya está
     * persistida cuando se llega acá: si Pusher falla, el admin la ve igual al recargar, y una
     * excepción de Pusher no puede voltear la creación de la tarea. El porqué completo está en
     * esa clase.
     *
     * 🔴 Va acá, en el evento, y no en cada sitio de llamada: así queda cubierto todo emisor
     * que use `dispatch()`, incluidos los que se escriban después.
     *
     * ⚠️ No cubre `dispatchIf()`, `dispatchUnless()` ni `broadcast()` del trait `Dispatchable`:
     * esos llaman a `event()` / `broadcast()` por su cuenta. Hoy no los usa nadie con este
     * evento (verificado el 2/9/2026). El detalle largo está en
     * {@see LeadSuggestionCreated::dispatch()}.
     *
     * @return void
     */
    public static function dispatch()
    {
        BroadcastGuard::emitir(new static(...func_get_args()));
    }

    /**
     * Solo emite si la notificación sigue existiendo (pudo borrarse la tarea entre
     * medio) y si se pudo resolver el admin destinatario.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return $this->admin_id !== null
            && AdminTaskNotification::query()->where('id', $this->notification_id)->exists();
    }

    /**
     * Canal privado del admin destinatario: solo él puede escucharlo (ver routes/channels.php).
     *
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.' . $this->admin_id),
        ];
    }

    /**
     * Nombre del evento para Echo (.AdminTaskNotificationCreated).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'AdminTaskNotificationCreated';
    }

    /**
     * Payload del evento: **solo el id de la notificación**, y nada más.
     *
     * Este evento es el único de los tres que viaja por un canal **privado** (`admin.{id}`,
     * autorizado en routes/channels.php), así que el argumento de exposición que sacó el modelo
     * de {@see LeadSuggestionCreated} y {@see SupportTicketUpdated} acá pesa menos. Va igual
     * solo con el id, por decisión de Lucas del 2/9/2026, y por dos razones que sí aplican:
     * `title` y `content` de la tarea son texto libre sin techo —el límite de 10240 bytes de
     * Pusher quedaba en manos de quien escribiera la tarea— y cargar el modelo era una consulta
     * por evento para un dato que admin-spa igual sabe ir a buscar sola.
     *
     * ⚠️ Sacar la consulta de acá es seguro y conviene decir por qué: ni `broadcastWhen()` ni
     * `broadcastOn()` dependían de ella. El `admin_id` que arma el nombre del canal se resuelve
     * en el **constructor**, con su propia consulta, justamente para que el canal exista aunque
     * la notificación se borre entre medio. Esa consulta se queda.
     *
     * admin-spa recarga los avisos pendientes con el mismo GET del montaje.
     *
     * @return array{notification_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notification_id,
        ];
    }
}
