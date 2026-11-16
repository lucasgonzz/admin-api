<?php
namespace App\Jobs;
use App\Services\SupportMessageNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class EnviarAvisoDeMensajeDeSoporteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 3;
    public $timeout = 90;
    public $backoff = 30;
    public $message_id;
    public function __construct(int $message_id)
    {
        $this->message_id = $message_id;
        $this->connection = 'database';
        $this->afterCommit = true;
    }
    public function handle(SupportMessageNotificationService $service): void
    {
        $service->enviar($this->message_id);
    }
}
