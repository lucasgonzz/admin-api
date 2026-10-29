<?php

namespace App\Console\Commands;

use App\Services\SupportAttentionNotificationService;
use Illuminate\Console\Command;

/** Red de seguridad para avisos cuya escritura o encolado falló en el request de soporte. */
class NotificarAtencionDeSoporte extends Command
{
    protected $signature = 'support:notify-attention';
    protected $description = 'Recupera los avisos push de tickets que necesitan revisión o iniciar una misión.';

    public function handle(SupportAttentionNotificationService $service): int
    {
        $service->conciliar();
        return 0;
    }
}
