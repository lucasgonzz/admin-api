<?php

namespace App\Events;

use App\Models\Lead;
use App\Support\BroadcastGuard;
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
     * todo emisor que use `dispatch()` —los que ya existen y los que se escriban después— sin
     * que nadie se tenga que acordar de envolverlo.
     *
     * ⚠️ Lo que este override NO cubre, y conviene saberlo antes de confiar de más: los otros
     * emisores del trait `Dispatchable` —`dispatchIf()`, `dispatchUnless()` y `broadcast()`—
     * llaman a `event()` / `broadcast()` por su cuenta y pasan de largo el guard. Hoy no los
     * usa nadie con este evento (verificado el 2/9/2026), y `broadcast()` además devuelve un
     * `PendingBroadcast` encadenable, así que taparlo con un guard que se traga la falla le
     * cambiaría el contrato al llamador. Si algún día hace falta uno de esos, se envuelve el
     * sitio de llamada o se resuelve acá con el dato en la mano — no se asume cubierto.
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
     * Payload del evento: **solo el id del lead**, y nada más.
     *
     * 🔴 ACÁ NO SE VUELVE A METER EL MODELO, y el motivo de fondo no es el tamaño (decisión de
     * Lucas, 2/9/2026):
     *
     * 1. **El canal es público.** `broadcastOn()` devuelve un `Channel`, no un
     *    `PrivateChannel`, y la clave de Pusher está horneada en el bundle de admin-spa. Ese
     *    canal lo puede escuchar cualquiera que abra la SPA, sin estar logueado. Mandar el
     *    `Lead` entero por ahí era publicar teléfono, mail, `notes`, `call_summary` y
     *    `demo_summary` de cada lead a quien se suscriba. Un payload chico no arregla eso: lo
     *    único que lo arregla es que el dato no viaje.
     * 2. **El tamaño.** El `Lead` tiene 144 columnas y viajaba con cinco relaciones. Medido el
     *    2/9/2026 sobre un lead con la demo resuelta: **23221 bytes** contra los 10240 que
     *    admite Pusher Channels. El broadcast reventaba entero con «The data content of this
     *    event exceeds the allowed maximum (10240 bytes)».
     * 3. **La consulta.** Cargar el modelo era una query por evento para un dato que la SPA
     *    igual tiene que poder ir a buscar sola.
     *
     * El id alcanza: admin-spa refresca la fila por API, que es el mismo camino que
     * {@see LeadConversationUpdated} —el evento que más se dispara— usa desde siempre. Y la
     * API sí verifica quién pide qué; el canal, no.
     *
     * 🔴 Un dato que sale por acá sale sin autenticación de ningún tipo. Si alguna vez hace
     * falta mandar algo más que el id, primero se pasa el canal a privado.
     *
     * @return array{lead_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->lead_id,
        ];
    }
}
