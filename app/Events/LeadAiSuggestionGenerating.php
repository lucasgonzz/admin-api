<?php

namespace App\Events;

use App\Models\Lead;
use App\Support\BroadcastGuard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica que un job o pedido manual empezó a consultar a Claude para un lead.
 */
class LeadAiSuggestionGenerating implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Id del lead en consulta a Claude.
     */
    public $lead_id;

    /**
     * @param int $lead_id
     */
    public function __construct(int $lead_id)
    {
        $this->lead_id = $lead_id;
    }

    /**
     * Emite el evento sin poder voltear a quien lo emitió.
     *
     * Es el aviso que prende el spinner del botón de sugerencia: puro adorno de UI. Que una
     * caída de Pusher acá deje sin generar una sugerencia que el operador pidió sería exactamente
     * al revés de como tiene que funcionar. El evento es `ShouldBroadcastNow` —la llamada es
     * síncrona— y se emite justo antes de un bloque que consulta a Claude.
     *
     * ⚠️ Cubre `dispatch()`, no los otros emisores del trait `Dispatchable`. El detalle está en
     * {@see LeadAiSuggestionFinished::dispatch()}.
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
        return 'LeadAiSuggestionGenerating';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->lead_id,
        ];
    }
}
