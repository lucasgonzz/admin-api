<?php

namespace App\Events;

use App\Models\Lead;
use App\Support\BroadcastGuard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento Pusher cuando cambia la conversación WhatsApp de un lead (mensaje nuevo o lectura).
 *
 * admin-spa escucha en `leads.admins` para actualizar tabla, conversación abierta y badge del menú.
 *
 * El payload es mínimo (solo IDs + unread_total) para no superar el límite de 10KB de Pusher.
 * El frontend hace un GET al recibir el evento para cargar los datos actualizados del lead y mensaje.
 */
class LeadConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Lead afectado.
     */
    public $lead_id;

    /**
     * @var int|null Mensaje recién creado (opcional).
     */
    public $lead_message_id;

    /**
     * True cuando el evento es solo una actualización de estado de entrega WhatsApp (entregado/leído/fallido).
     * El frontend usa este flag para omitir refresco de badges y fila de la grilla de leads.
     *
     * @var bool
     */
    public $is_status_update;

    /**
     * Estado de entrega de WhatsApp que originó el broadcast ('entregado', 'leido' o 'fallido').
     * `null` cuando el evento no es una actualización de estado (mensaje nuevo, etc.).
     *
     * @var string|null
     */
    public $delivery_status;

    /**
     * @param int      $lead_id
     * @param int|null $lead_message_id
     * @param bool     $is_status_update True solo para broadcasts de cambio de estado de entrega WhatsApp.
     * @param string|null $delivery_status Estado de entrega WhatsApp puntual ('entregado'/'leido'/'fallido').
     */
    public function __construct(int $lead_id, ?int $lead_message_id = null, bool $is_status_update = false, ?string $delivery_status = null)
    {
        $this->lead_id          = $lead_id;
        $this->lead_message_id  = $lead_message_id;
        $this->is_status_update = $is_status_update;
        $this->delivery_status  = $delivery_status;
    }

    /**
     * Emite el evento sin poder voltear a quien lo emitió.
     *
     * 🔴 POR QUÉ ESTE EVENTO TAMBIÉN, aunque su payload sea chico y nunca haya chocado con el
     * límite de Pusher: el guard **no protege contra el tamaño, protege contra la emisión**.
     * Este es el que más se dispara de los cuatro —uno por mensaje— y es `ShouldBroadcastNow`,
     * o sea que la llamada a Pusher es SÍNCRONA y corre adentro del `try` de quien lo emite.
     * Si Pusher se cae, la excepción sube y marca como fallido un mensaje que sí salió y sí
     * quedó registrado. Eso ya lo sabían los dos sitios de `ClaudeLeadsOutboundController`, que
     * envolvían la llamada en su propio try/catch con el comentario puesto — pero esa
     * protección vivía en el llamador, así que solo cubría a los llamadores que se acordaron.
     *
     * Con el guard acá, la clase entera queda cubierta: un emisor nuevo nace protegido. Los
     * try/catch de los llamadores se dejan como están —no molestan y documentan el porqué en el
     * lugar donde se lee—, pero ya no son lo único que separa una caída de Pusher de un mensaje
     * reportado como fallido.
     *
     * ⚠️ Cubre `dispatch()` y nada más: `dispatchIf()`, `dispatchUnless()` y `broadcast()` del
     * trait `Dispatchable` llaman a `event()` / `broadcast()` por su cuenta. El detalle está en
     * {@see LeadSuggestionCreated::dispatch()}.
     *
     * @return void
     */
    public static function dispatch()
    {
        BroadcastGuard::emitir(new static(...func_get_args()));
    }

    /**
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return Lead::query()->where('id', $this->lead_id)->exists();
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('leads.admins'),
        ];
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'LeadConversationUpdated';
    }

    /**
     * Payload mínimo, y acá «mínimo» es literal: nada de esto crece con el modelo.
     *
     * 🔴 Este evento es el único de los de leads que nunca chocó con el límite de 10240 bytes
     * de Pusher Channels, y la razón vale escribirla: manda **solo ids, un booleano y un slug
     * corto**, ninguno de los cuales crece con el lead ni con lo que escriba un cliente. Un
     * payload es seguro por su forma, no porque a alguien le haya parecido chico: el docblock
     * de `LeadSuggestionCreated` afirmaba que excluir `messages` alcanzaba, y la producción lo
     * desmintió el 2/9/2026 (23221 bytes medidos con un lead con la demo resuelta).
     *
     * 🔴 Desde el 2/9/2026 esta forma —solo ids— es la de los cuatro eventos, y no por tamaño
     * sino porque `leads.admins` es un canal **público**: la clave de Pusher está horneada en el
     * bundle de admin-spa, así que todo lo que viaje por acá lo puede leer cualquiera que se
     * suscriba. No se le agrega un modelo a este payload. Si alguna vez hiciera falta, primero
     * se pasa el canal a privado.
     *
     * El total de no leídos es per-usuario, por lo que NO viaja en el evento (el canal
     * `leads.admins` es compartido): cada cliente hace GET /lead/unread-badges para obtener su
     * propio total al recibir el evento.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id'          => $this->lead_id,
            'lead_message_id'  => $this->lead_message_id,
            // El frontend omite refresco de badges/grilla cuando este flag es true.
            'is_status_update' => $this->is_status_update,
            // Estado de entrega puntual (string corto, no compromete el límite de 10KB).
            'delivery_status'  => $this->delivery_status,
        ];
    }
}
