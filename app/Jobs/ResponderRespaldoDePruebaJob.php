<?php

namespace App\Jobs;

use App\Services\Prueba\PruebaRespaldoSoporteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * El respaldo automático de un ticket de prueba gratuita cuando Martín está caído (A4 de la misión cruzada
 * prueba-ayuda-unificada-en-soporte, 10/11/2026). Se despacha con demora al recibir el entrante del lead y, al
 * correr, decide todo `PruebaRespaldoSoporteService::responder()` (con todas sus guardas).
 *
 * Un solo intento: reintentar una respuesta automática minutos después ya no sirve, y el servicio es idempotente
 * por entrante igual.
 */
class ResponderRespaldoDePruebaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout = 120;

    /** @var int */
    public $ticket_id;

    /** @var int El entrante que programó el respaldo. */
    public $message_id;

    /**
     * @param int $ticket_id
     * @param int $message_id
     */
    public function __construct(int $ticket_id, int $message_id)
    {
        $this->ticket_id  = $ticket_id;
        $this->message_id = $message_id;
    }

    /**
     * @param PruebaRespaldoSoporteService $respaldo
     *
     * @return void
     */
    public function handle(PruebaRespaldoSoporteService $respaldo): void
    {
        $resultado = $respaldo->responder($this->ticket_id, $this->message_id);

        Log::channel('daily')->info('PruebaRespaldoSoporte: resultado del respaldo.', [
            'ticket_id'  => $this->ticket_id,
            'message_id' => $this->message_id,
            'estado'     => $resultado['estado'],
        ]);
    }
}
