<?php

namespace App\Events;

use App\Models\AdminTaskNotification;
use App\Support\BroadcastGuard;
use App\Support\BroadcastPayloadBudget;
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
     * 🔴 Va acá, en el evento, y no en cada sitio de llamada: así queda cubierto todo emisor,
     * incluidos los que se escriban después.
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
     * Payload del evento: el `notification_id` siempre, y la notificación con su tarea si entra.
     *
     * Se evita serializar el `todos` completo de la tarea para mantenerse bajo el límite de
     * ~10 KB de Pusher Channels, pero eso solo achica: `title` y `content` son texto libre y
     * pueden crecer sin techo. Por eso el payload pasa por
     * {@see \App\Support\BroadcastPayloadBudget} y, si no entra, se emite **sin la clave
     * `notification`** (mismo criterio que LeadSuggestionCreated y SupportTicketUpdated).
     *
     * 🔴 Compatible hacia atrás: `notification` conserva nombre y forma, y `notification_id` se
     * **suma**. Una admin-spa vieja recibe la notificación en el caso normal; la nueva, cuando
     * no vino, recarga los avisos pendientes por API.
     *
     * @return array{notification_id: int, notification?: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        // Cargar la notificación fresca junto con los datos mínimos de su tarea.
        $notification = AdminTaskNotification::query()
            ->where('id', $this->notification_id)
            ->with([
                'task' => function ($query) {
                    $query->select(
                        'id',
                        'title',
                        'content',
                        'created_via',
                        'lead_id',
                        'created_by_admin_id',
                        'created_at'
                    )->with('created_by_admin:id,name');
                },
            ])
            ->first();

        // El id nunca se recorta: sin él, un payload recortado sería un aviso que el
        // consumidor no puede ni identificar ni ir a buscar.
        return BroadcastPayloadBudget::ajustar(
            [
                'notification_id' => $this->notification_id,
                'notification'    => $notification,
            ],
            'notification',
            'AdminTaskNotificationCreated'
        );
    }
}
