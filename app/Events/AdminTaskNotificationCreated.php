<?php

namespace App\Events;

use App\Models\AdminTaskNotification;
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
     * Payload del evento: la notificación con su tarea, sin serializar el `todos`
     * completo de la tarea para mantenerse bajo el límite de ~10 KB de Pusher Channels
     * (mismo criterio que LeadSuggestionCreated).
     *
     * @return array{notification: array<string, mixed>|null}
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

        return [
            'notification' => $notification,
        ];
    }
}
