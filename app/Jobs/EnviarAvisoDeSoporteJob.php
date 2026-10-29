<?php

namespace App\Jobs;

use App\Services\SupportAttentionNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** El request de soporte solo registra el cambio; la llamada a Apple/Google sale del worker. */
class EnviarAvisoDeSoporteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 90;
    public $backoff = 30;
    public $ticket_id;
    public $generation;

    public function __construct(int $ticket_id, int $generation)
    {
        $this->ticket_id = $ticket_id;
        $this->generation = $generation;
        $this->connection = 'database';
        $this->afterCommit = true;
    }

    public function handle(SupportAttentionNotificationService $service): void
    {
        $service->enviar($this->ticket_id, $this->generation);
    }
}
