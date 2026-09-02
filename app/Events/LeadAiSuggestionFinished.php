<?php

namespace App\Events;

use App\Models\Lead;
use App\Support\BroadcastGuard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica que terminó (éxito o error) una consulta a Claude para sugerencia IA de un lead.
 */
class LeadAiSuggestionFinished implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Id del lead cuya consulta finalizó.
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
     * 🔴 ESTE ES EL PEOR CASO DE LOS SEIS, y por eso vale escribirlo acá: en
     * `LeadController@request_ai_suggestion_json` este evento se emite adentro de un `finally`.
     * Una excepción en un `finally` de PHP **reemplaza el return pendiente**: si Pusher se cae
     * justo ahí, una sugerencia generada, persistida y ya devuelta con 200 se convierte en un
     * 500. El aviso no solo informa mal el resultado — lo cambia.
     *
     * El evento es `ShouldBroadcastNow`, así que la llamada a Pusher es síncrona y corre en el
     * request. El guard la aísla: se loguea y se sigue.
     *
     * ⚠️ Cubre `dispatch()`. En Laravel 8.83 —que es la versión vendorizada acá, verificado el
     * 2/9/2026 en `Illuminate\Foundation\Events\Dispatchable`— `dispatchIf()`, `dispatchUnless()`
     * y `broadcast()` llaman a `event()` / `broadcast()` por su cuenta y NO pasan por este
     * override. (En Laravel 9+ las dos primeras delegan en `static::dispatch`, pero acá no.)
     * Hoy no las usa nadie con este evento.
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
        return 'LeadAiSuggestionFinished';
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
