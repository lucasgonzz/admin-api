<?php

namespace App\Console\Commands;

use App\Services\SupportCodexHealthService;
use Illuminate\Console\Command;

class CheckSupportCodexHealth extends Command
{
    protected $signature = 'support:check-codex-health {--prueba-correo : Envía una prueba a Lucas sin tocar el estado del monitor}';
    protected $description = 'Avisa por correo cuando el monitor de soporte deja de confirmar su funcionamiento';

    public function handle(SupportCodexHealthService $service): int
    {
        if ($this->option('prueba-correo')) {
            $service->test_mail();
            $this->info('Correo de prueba aceptado por el transporte; no se modificó el incidente.');
        } else {
            $service->check();
        }
        return 0;
    }
}
