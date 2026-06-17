<?php

namespace App\Console\Commands;

use App\Services\AgentPromptSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza desde GitHub todos los archivos editables (identidad, system prompt,
 * protocolo de WhatsApp) y los persiste en BD vía {@see AgentPromptSyncService}.
 *
 * Ejecuta exactamente la misma lógica que el botón "Sincronizar desde GitHub" del
 * admin. Registrado en el scheduler cada 10 minutos como red de seguridad: nunca
 * lanza excepción si algún archivo falla, para no tirar abajo el scheduler.
 */
class SyncAgentPrompts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agent-prompts:sync';

    /**
     * @var string
     */
    protected $description = 'Sincroniza desde GitHub identidad, system prompt y protocolo de WhatsApp a la BD';

    /**
     * Ejecuta la sincronización y loguea el resultado por archivo.
     *
     * @param AgentPromptSyncService $service Servicio que descarga y persiste los archivos
     * @return int Código de salida (siempre 0: un fallo de archivo no debe tumbar el scheduler)
     */
    public function handle(AgentPromptSyncService $service): int
    {
        try {
            $results = $service->sync();

            foreach ($results as $result) {
                if ($result['ok']) {
                    $this->info("OK  {$result['file']}");
                } else {
                    // Se loguea como warning pero no se propaga: el scheduler debe seguir vivo.
                    $this->warn("ERR {$result['file']}: {$result['error']}");
                    Log::warning('agent-prompts:sync — archivo no sincronizado', [
                        'file'  => $result['file'],
                        'error' => $result['error'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Cualquier excepción inesperada se captura para no afectar otros jobs del scheduler.
            Log::error('agent-prompts:sync — excepción inesperada durante la sincronización', [
                'message' => $e->getMessage(),
            ]);
            $this->error('Excepción durante la sincronización: '.$e->getMessage());
        }

        return 0;
    }
}
