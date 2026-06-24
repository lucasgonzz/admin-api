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
 * Dispara el análisis IA y la importación de la Etapa 4 (Migración de datos).
 *
 * Se encola cuando el admin avanza manualmente de la Etapa 3 (recolección de archivos)
 * a la Etapa 4 (migración de datos).
 *
 * Antes de llamar a process_files(), copia el data de archivos clasificados en la
 * Etapa 3 al registro de la Etapa 4, ya que ImplementationImportService opera sobre
 * el stage_number 4.
 *
 * Reemplaza a ProcessImplementationStage5Import en el nuevo esquema de numeración.
 */
class ProcessImplementationStage4Import implements ShouldQueue
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
     * Copia los archivos clasificados del stage 3 (archivos) al stage 4 (migración)
     * y luego llama a process_files() para iniciar el análisis IA.
     *
     * @param ImplementationImportService $import_service Servicio de importación IA.
     *
     * @return void
     */
    public function handle(ImplementationImportService $import_service): void
    {
        // Cargar la implementación por ID.
        $implementation = Implementation::find($this->implementation_id);

        if ($implementation === null) {
            Log::channel('daily')->warning('ProcessImplementationStage4Import: implementación no encontrada.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        // Stage 3 (recolección de archivos): fuente de los datos clasificados.
        $stage_3 = ImplementationStage::where('implementation_id', $this->implementation_id)
            ->where('stage_number', 3)
            ->first();

        if ($stage_3 === null) {
            Log::channel('daily')->warning('ProcessImplementationStage4Import: stage 3 no encontrado para copiar datos.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        // Stage 4 (migración de datos): destino del análisis IA.
        $stage_4 = ImplementationStage::where('implementation_id', $this->implementation_id)
            ->where('stage_number', 4)
            ->first();

        if ($stage_4 === null) {
            Log::channel('daily')->warning('ProcessImplementationStage4Import: stage 4 no encontrado.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        // Datos clasificados acumulados en la etapa 3 (archivos).
        $data_3 = is_array($stage_3->data) ? $stage_3->data : [];

        // Campos de archivos clasificados a copiar al stage 4.
        $file_fields = [
            'articles_files',
            'clients_files',
            'suppliers_files',
            'files_confirmed_complete',
            'unclassified_files',
        ];

        // Data actual del stage 4 (puede estar vacío o parcialmente inicializado).
        $data_4 = is_array($stage_4->data) ? $stage_4->data : [];

        // Copiar los campos de archivos del stage 3 al stage 4 para que process_files() los encuentre.
        foreach ($file_fields as $field) {
            if (array_key_exists($field, $data_3)) {
                $data_4[$field] = $data_3[$field];
            }
        }

        // Inicializar current_question como 'analyzing' para que los mensajes entrantes
        // durante el análisis reciban la respuesta de espera correcta.
        $data_4['current_question'] = 'analyzing';

        $stage_4->data = $data_4;
        $stage_4->save();

        Log::channel('daily')->info('ProcessImplementationStage4Import: datos de archivos copiados de etapa 3 a etapa 4.', [
            'implementation_id' => $this->implementation_id,
        ]);

        // Llamar a process_files() que ahora leerá el data del stage 4.
        $import_service->process_files($implementation);
    }
}
