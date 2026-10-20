<?php

namespace App\Jobs;

use App\Services\DemoChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDemoChatReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $conversacion_id;
    public $generacion;
    public $tries = 1;
    public $timeout = 120;

    public function __construct(int $conversacion_id, int $generacion)
    {
        $this->conversacion_id = $conversacion_id;
        $this->generacion = $generacion;
    }

    public function handle(DemoChatService $service): void
    {
        $service->generar($this->conversacion_id, $this->generacion);
    }

    public function failed($exception): void
    {
        // También cubre un timeout duro del worker: el hilo no puede quedar pensando para siempre.
        \App\Models\DemoChatConversacion::where('id', $this->conversacion_id)
            ->where('generacion', $this->generacion)->whereIn('estado', ['esperando', 'pensando'])
            ->update(['estado' => 'error', 'ultimo_error' => 'La respuesta tardó demasiado. Volvé a sugerir.',
                'revision' => \Illuminate\Support\Facades\DB::raw('revision + 1')]);
    }
}
