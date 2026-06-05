<?php

namespace App\Jobs;

use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Services\ImplementationImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispara el análisis IA y la importación de la Etapa 5 (Migración de datos).
 *
 * Se encola cuando el admin avanza manualmente de la Etapa 4 a la Etapa 5.
 * Antes de llamar a process_files(), copia el data de archivos clasificados en la
 * Etapa 4 al registro de la Etapa 5, ya que ImplementationImportService opera sobre
 * el stage_number 5.
 */
class ProcessImplementationStage5Import implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID de la implementación cuyos archivos se procesarán.
     */
    private $implementation_id;

    /**
     * @param int $implementation_id ID de la implementación.
     */
    public function __construct(int $implementation_id)
    {
        $this->implementation_id = $implementation_id;
    }

    /**
     * Copia los archivos clasificados del stage 4 al stage 5 y luego llama a process_files().
     *
     * @param ImplementationImportService $import_service Servicio de importación IA.
     *
     * @return void
     */
    public function handle(ImplementationImportService $import_service): void
    {
        // Cargar la implementación.
        $implementation = Implementation::find($this->implementation_id);

        if ($implementation === null) {
            Log::channel('daily')->warning('ProcessImplementationStage5Import: implementación no encontrada.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        // Stage 4 (recolección de archivos): fuente de los datos clasificados.
        $stage_4 = ImplementationStage::where('implementation_id', $this->implementation_id)
            ->where('stage_number', 4)
            ->first();

        if ($stage_4 === null) {
            Log::channel('daily')->warning('ProcessImplementationStage5Import: stage 4 no encontrado para copiar datos.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        // Stage 5 (migración de datos): destino del análisis IA.
        $stage_5 = ImplementationStage::where('implementation_id', $this->implementation_id)
            ->where('stage_number', 5)
            ->first();

        if ($stage_5 === null) {
            Log::channel('daily')->warning('ProcessImplementationStage5Import: stage 5 no encontrado.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        // Datos clasificados acumulados en la etapa 4.
        $data_4 = is_array($stage_4->data) ? $stage_4->data : [];

        // Campos de archivos clasificados a copiar al stage 5.
        $file_fields = [
            'articles_files',
            'clients_files',
            'suppliers_files',
            'files_confirmed_complete',
            'unclassified_files',
        ];

        // Data actual del stage 5 (puede estar vacío o parcialmente inicializado).
        $data_5 = is_array($stage_5->data) ? $stage_5->data : [];

        // Copiar los campos de archivos del stage 4 al stage 5 para que process_files() los encuentre.
        foreach ($file_fields as $field) {
            if (array_key_exists($field, $data_4)) {
                $data_5[$field] = $data_4[$field];
            }
        }

        // Inicializar current_question como 'analyzing' para que los mensajes entrantes
        // durante el análisis reciban la respuesta de espera correcta.
        $data_5['current_question'] = 'analyzing';

        $stage_5->data = $data_5;
        $stage_5->save();

        Log::channel('daily')->info('ProcessImplementationStage5Import: datos de archivos copiados de etapa 4 a etapa 5.', [
            'implementation_id' => $this->implementation_id,
        ]);

        // Llamar a process_files() que ahora leerá el data del stage 5.
        $import_service->process_files($implementation);
    }
}
