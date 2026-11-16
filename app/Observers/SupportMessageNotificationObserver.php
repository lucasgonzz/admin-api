<?php
namespace App\Observers;
use App\Models\SupportMessage;
use App\Services\SupportMessageNotificationService;
use Illuminate\Support\Facades\Log;
class SupportMessageNotificationObserver
{
    public function created(SupportMessage $message): void
    {
        try {
            app(SupportMessageNotificationService::class)->registrar($message);
        } catch (\Throwable $exception) {
            Log::error('Soporte: no se pudo registrar el aviso del mensaje.', ['message_id' => $message->id]);
        }
    }
}
