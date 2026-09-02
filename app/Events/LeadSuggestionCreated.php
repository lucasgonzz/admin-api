<?php

namespace App\Events;

use App\Models\Lead;
use App\Support\BroadcastGuard;
use App\Support\BroadcastPayloadBudget;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento emitido cuando se crea automáticamente una sugerencia pendiente para un lead.
 *
 * Cubre dos orígenes:
 *  - {@see \App\Services\LeadAiService}: sugerencia de seguimiento generada por Claude.
 *  - {@see \App\Console\Commands\SendDemoReminders}: recordatorio pre-demo hardcodeado.
 *
 * El canal `leads.admins` es escuchado por cualquier operador conectado en admin-spa
 * para actualizar la fila del lead en la tabla sin recargar la página.
 */
class LeadSuggestionCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Identificador del lead cuya sugerencia fue creada.
     *
     * Se prefiere el id sobre el modelo completo para no serializar el objeto
     * en el constructor; se carga la versión más fresca en {@see broadcastWith}.
     *
     * @var int
     */
    public $lead_id;

    /**
     * @param int $lead_id Identificador del lead actualizado.
     */
    public function __construct(int $lead_id)
    {
        $this->lead_id = $lead_id;
    }

    /**
     * Emite el evento sin poder voltear a quien lo emitió.
     *
     * Sobreescribe el `dispatch()` de {@see \Illuminate\Foundation\Events\Dispatchable} para
     * que la emisión pase por {@see \App\Support\BroadcastGuard}. El motivo está escrito
     * entero en esa clase; en corto: este evento se dispara **después** de persistir la
     * sugerencia, y en producción una falla de Pusher acá hizo que la pantalla informara
     * «No se pudo generar la sugerencia» sobre una sugerencia que sí se había generado.
     *
     * 🔴 Va acá, en el evento, y no en cada sitio de llamada a propósito: así queda cubierto
     * todo emisor —los que ya existen y los que se escriban después— sin que nadie se tenga
     * que acordar de envolver el dispatch.
     *
     * @return void
     */
    public static function dispatch()
    {
        BroadcastGuard::emitir(new static(...func_get_args()));
    }

    /**
     * Solo emite si el lead sigue existiendo en la base de datos.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return Lead::query()->where('id', $this->lead_id)->exists();
    }

    /**
     * Canal compartido escuchado por todos los operadores de admin-spa.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('leads.admins'),
        ];
    }

    /**
     * Nombre del evento para Echo (.LeadSuggestionCreated).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'LeadSuggestionCreated';
    }

    /**
     * Payload del evento: el `lead_id` siempre, y el lead con sus relaciones si entra.
     *
     * 🔴 Lo que decía este docblock hasta el 2/9/2026 —que excluir `messages` alcanzaba para
     * quedar bajo el límite de ~10 KB de Pusher Channels— **lo desmintió la producción**: el
     * `Lead` tiene 144 columnas y acá viaja con cinco relaciones, así que el payload crece con
     * el lead y hay leads que no entran. El broadcast reventó con
     * «The data content of this event exceeds the allowed maximum (10240 bytes)».
     *
     * Por eso el payload pasa por {@see \App\Support\BroadcastPayloadBudget}: si el JSON no
     * entra en el presupuesto, se emite **sin la clave `lead`**.
     *
     * 🔴 Compatible hacia atrás en las dos direcciones, y por eso `lead` no se renombra ni se
     * saca del contrato: `lead_id` se **suma**. Una admin-spa vieja contra esta API sigue
     * recibiendo `lead` en el caso normal y se comporta igual que siempre; en el caso grande
     * pierde ese refresco puntual —pero hoy, en ese mismo caso, no recibe **nada**, porque el
     * broadcast entero explota. La admin-spa nueva usa `lead` si vino y, si no, refresca la
     * fila por API con `lead_id`.
     *
     * @return array{lead_id: int, lead?: \App\Models\Lead|null}
     */
    public function broadcastWith(): array
    {
        // Cargar el lead con relaciones necesarias para la tabla, excluyendo messages.
        $lead = Lead::query()
            ->where('id', $this->lead_id)
            ->with([
                'target_client',
                'promoted_client',
                'created_by_admin',
                'demo',
                'personalized_demo_videos',
            ])
            ->first();

        // El id va primero y nunca se recorta: es el único dato que le garantiza al
        // consumidor poder reconstruir el resto por API cuando el modelo no entra.
        return BroadcastPayloadBudget::ajustar(
            [
                'lead_id' => $this->lead_id,
                'lead'    => $lead,
            ],
            'lead',
            'LeadSuggestionCreated'
        );
    }
}
