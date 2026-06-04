<?php

namespace App\Services;

use App\Events\ImplementationImportStatusUpdated;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\WhatsappConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Importación asistida por IA (Etapa 4): analiza Excels vía empresa-api y ejecuta la carga.
 *
 * Descarga archivos desde Kapso, llama a admin-sync/ai-excel-import en el empresa-api del cliente
 * y coordina mensajes al responsable de migración y al admin asignado.
 */
class ImplementationImportService
{
    /**
     * Ruta relativa de análisis de Excel en empresa-api (admin-sync).
     */
    const ANALYZE_PATH = 'api/admin-sync/ai-excel-import/analyze';

    /**
     * Ruta relativa de importación de Excel en empresa-api (admin-sync).
     */
    const IMPORT_PATH = 'api/admin-sync/ai-excel-import/import';

    /**
     * Categorías de la Etapa 4 con su clave de archivos, modelo remoto y etiqueta en español.
     *
     * @var array<string, array{files_key: string, model: string, label: string, label_plural: string}>
     */
    const CATEGORY_CONFIG = [
        'articles' => [
            'files_key'    => 'articles_files',
            'model'        => 'article',
            'label'        => 'Productos',
            'label_plural' => 'productos',
        ],
        'clients' => [
            'files_key'    => 'clients_files',
            'model'        => 'client',
            'label'        => 'Clientes',
            'label_plural' => 'clientes',
        ],
        'suppliers' => [
            'files_key'    => 'suppliers_files',
            'model'        => 'provider',
            'label'        => 'Proveedores',
            'label_plural' => 'proveedores',
        ],
    ];

    /**
     * Propiedades de sistema relevantes para el resumen al cliente (artículos).
     *
     * @var array<string, string>
     */
    const ARTICLE_PROPERTY_LABELS = [
        'nombre'              => 'Nombre',
        'codigo_de_barras'    => 'Código',
        'codigo_de_proveedor' => 'Código de proveedor',
        'sku'                 => 'SKU',
        'precio'              => 'Precio',
        'costo'               => 'Costo',
        'stock_actual'        => 'Stock',
        'descripcion'         => 'Descripción',
        'categoria'           => 'Categoría',
        'marca'               => 'Marca',
    ];

    /**
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @var ImplementationConversationService
     */
    protected $conversation_service;

    /**
     * @param ClientEmpresaApiUrlResolver|null         $api_url_resolver
     * @param ImplementationConversationService|null $conversation_service
     */
    public function __construct(
        ?ClientEmpresaApiUrlResolver $api_url_resolver = null,
        ?ImplementationConversationService $conversation_service = null
    ) {
        $this->api_url_resolver       = $api_url_resolver ?? new ClientEmpresaApiUrlResolver();
        $this->conversation_service   = $conversation_service ?? new ImplementationConversationService();
    }

    /**
     * Procesa los archivos acumulados en el stage 4 tras el debounce: analiza y pide confirmación.
     *
     * @param Implementation $implementation Implementación con stage 4 en progreso.
     *
     * @return void
     */
    public function process_files(Implementation $implementation): void
    {
        // Stage 4 y data persistido (archivos por categoría).
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationImportService::process_files: stage 4 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($stage->data) ? $stage->data : [];

        // Teléfono del responsable de migración para mensajes salientes.
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('ImplementationImportService::process_files: contact_phone vacío.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        // Archivos sin clasificar: pedir aclaración y no continuar con el análisis.
        $unclassified = is_array($data['unclassified_files'] ?? null) ? $data['unclassified_files'] : [];

        if (count($unclassified) > 0) {
            $count = count($unclassified);
            $label = $count === 1 ? '1 archivo' : "{$count} archivos";

            $this->conversation_service->send_stage_4_outbound(
                $implementation,
                "Recibí {$label} pero no me quedó claro si son de productos, clientes o proveedores. "
                . '¿Me podés aclarar de qué es cada uno?'
            );

            return;
        }

        /** Cliente con API activa y api_key para empresa-api. */
        $implementation->loadMissing('client.active_client_api', 'client.client_apis');
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if (! $client instanceof Client) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::process_files: cliente no encontrado.'
            );

            return;
        }

        /** user_id del owner en empresa-api (requerido por admin-sync). */
        $owner_user_id = $this->resolve_owner_user_id($client);

        if ($owner_user_id === null) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::process_files: no se pudo resolver user_id del owner del sistema.'
            );

            return;
        }

        /** Inicializar import_status en pending para categorías con archivos (no skipped). */
        $import_status = is_array($data['import_status'] ?? null) ? $data['import_status'] : [];

        foreach (self::CATEGORY_CONFIG as $category => $config) {
            $files_key   = $config['files_key'];
            $files_value = $data[$files_key] ?? null;

            if ($files_value === 'skipped') {
                continue;
            }

            if (! is_array($files_value) || count($files_value) === 0) {
                continue;
            }

            if (! isset($import_status[$category]) || ! is_array($import_status[$category])) {
                $import_status[$category] = [
                    'status'      => 'pending',
                    'error'       => null,
                    'imported_at' => null,
                ];
            } else {
                $import_status[$category]['status']      = 'pending';
                $import_status[$category]['error']       = null;
                $import_status[$category]['imported_at'] = null;
            }
        }

        $data['import_status'] = $import_status;
        $stage->data           = $data;
        $stage->save();

        /** Resultado acumulado del análisis por categoría. */
        $analysis_result = [];
        $had_analysis_error = false;

        foreach (self::CATEGORY_CONFIG as $category => $config) {
            $files_key = $config['files_key'];
            $files_value = $data[$files_key] ?? null;

            // Categoría omitida por el cliente.
            if ($files_value === 'skipped') {
                continue;
            }

            if (! is_array($files_value) || count($files_value) === 0) {
                continue;
            }

            // Analizar cada archivo de la categoría y fusionar el último mapeo válido.
            $category_analysis = $this->analyze_files($implementation, $category, $files_value, $client, $owner_user_id);

            if ($category_analysis === null) {
                $had_analysis_error = true;
                continue;
            }

            $analysis_result[$category] = $category_analysis;
        }

        if ($had_analysis_error && count($analysis_result) === 0) {
            return;
        }

        // Mensaje de resumen con columnas detectadas y pedido de confirmación.
        $summary_message = $this->build_analysis_summary_message($analysis_result, $data);

        $this->conversation_service->send_stage_4_outbound($implementation, $summary_message);

        // Persistir resultado y estado de pregunta para la confirmación del cliente.
        $data['analysis_result']   = $analysis_result;
        $data['current_question']  = 'confirm_analysis';
        $stage->data               = $data;
        $stage->save();
    }

    /**
     * Analiza uno o más archivos de una categoría llamando a empresa-api.
     *
     * @param Implementation $implementation
     * @param string         $category         articles | clients | suppliers
     * @param array<int, array<string, mixed>> $files Registros con url, filename, type.
     * @param Client|null    $client           Cliente (opcional si ya está en implementation).
     * @param int|null       $owner_user_id    Owner en empresa-api (opcional).
     *
     * @return array<string, mixed>|null Resultado agregado de la categoría o null si falló todo.
     */
    public function analyze_files(
        Implementation $implementation,
        string $category,
        array $files,
        ?Client $client = null,
        ?int $owner_user_id = null
    ): ?array {
        if (! isset(self::CATEGORY_CONFIG[$category])) {
            Log::channel('daily')->error('ImplementationImportService::analyze_files: categoría inválida.', [
                'category' => $category,
            ]);

            return null;
        }

        $config = self::CATEGORY_CONFIG[$category];

        $implementation->loadMissing('client.active_client_api', 'client.client_apis');
        $client = $client ?? $implementation->client ?? Client::find($implementation->client_id);

        if (! $client instanceof Client) {
            $this->log_and_notify_admin($implementation, 'ImplementationImportService::analyze_files: cliente no encontrado.');

            return null;
        }

        $owner_user_id = $owner_user_id ?? $this->resolve_owner_user_id($client);

        if ($owner_user_id === null) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::analyze_files: user_id del owner no disponible.'
            );

            return null;
        }

        /** URL POST de análisis en el empresa-api del cliente. */
        $analyze_url = $this->api_url_resolver->admin_sync_url($client, self::ANALYZE_PATH);

        if ($analyze_url === '') {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::analyze_files: URL de empresa-api no configurada.'
            );

            return null;
        }

        if (empty($client->api_key)) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::analyze_files: el cliente no tiene api_key.'
            );

            return null;
        }

        /** Último análisis exitoso de la categoría (si hay varios archivos, el último prevalece). */
        $merged_result = null;
        $files_analyzed = 0;

        foreach ($files as $file_index => $file_record) {
            if (! is_array($file_record)) {
                continue;
            }

            /** Ruta temporal local del Excel descargado desde Kapso. */
            $temp_relative_path = $this->download_file_to_temp($implementation, $file_record);

            if ($temp_relative_path === null) {
                continue;
            }

            $temp_full_path = storage_path('app/' . $temp_relative_path);

            try {
                /** Nombre original para el multipart (extensión coherente con el archivo). */
                $original_filename = trim((string) ($file_record['filename'] ?? 'import.xlsx'));
                if ($original_filename === '') {
                    $original_filename = 'import.xlsx';
                }

                /** Petición multipart al endpoint de análisis. */
                $response = Http::withHeaders([
                        'X-Admin-Api-Key' => $client->api_key,
                        'Accept'          => 'application/json',
                    ])
                    ->timeout((int) config('services.client_api.timeout', 60))
                    ->retry((int) config('services.client_api.retries', 1), 500)
                    ->attach(
                        'excel_file',
                        file_get_contents($temp_full_path),
                        $original_filename
                    )
                    ->post($analyze_url, [
                        'user_id' => $owner_user_id,
                        'model'   => $config['model'],
                    ]);

                if (! $response->successful()) {
                    $this->handle_http_error(
                        $implementation,
                        'analyze',
                        $category,
                        $response,
                        $analyze_url
                    );
                    continue;
                }

                /** Mapeo de columnas y ruta persistida en empresa-api para el import posterior. */
                $column_mapping = $response->json('column_mapping', []);
                if (! is_array($column_mapping)) {
                    $column_mapping = [];
                }

                $merged_result = [
                    'column_mapping'      => $column_mapping,
                    'excel_path'          => (string) $response->json('excel_path', ''),
                    'provider_id'         => $response->json('provider_id'),
                    'provider_confidence' => $response->json('provider_confidence'),
                    'record_count'        => $this->estimate_record_count($column_mapping),
                    'source_filename'     => $original_filename,
                    'file_index'          => $file_index,
                ];
                $files_analyzed++;
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('ImplementationImportService::analyze_files: excepción.', [
                    'implementation_id' => $implementation->id,
                    'category'          => $category,
                    'message'           => $exception->getMessage(),
                ]);
                $this->conversation_service->notify_assigned_admin_for_implementation(
                    $implementation,
                    '⚠️ Error al analizar Excel de implementación ('
                    . $config['label']
                    . '): '
                    . $exception->getMessage()
                );
            } finally {
                Storage::delete($temp_relative_path);
            }
        }

        if ($merged_result === null) {
            return null;
        }

        $merged_result['files_analyzed'] = $files_analyzed;

        return $merged_result;
    }

    /**
     * Ejecuta la importación confirmada en empresa-api para cada categoría analizada.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    public function execute_import(Implementation $implementation): void
    {
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationImportService::execute_import: stage 4 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($stage->data) ? $stage->data : [];
        $analysis_result = is_array($data['analysis_result'] ?? null) ? $data['analysis_result'] : [];

        $implementation->loadMissing('client.active_client_api', 'client.client_apis');
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if (! $client instanceof Client) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::execute_import: cliente no encontrado.'
            );

            return;
        }

        $owner_user_id = $this->resolve_owner_user_id($client);

        if ($owner_user_id === null) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::execute_import: user_id del owner no disponible.'
            );

            return;
        }

        $import_url = $this->api_url_resolver->admin_sync_url($client, self::IMPORT_PATH);

        if ($import_url === '' || empty($client->api_key)) {
            $this->log_and_notify_admin(
                $implementation,
                'ImplementationImportService::execute_import: URL o api_key de empresa-api no disponibles.'
            );

            return;
        }

        /** Contadores para el mensaje final al cliente. */
        $imported_counts = [
            'articles'  => 0,
            'clients'   => 0,
            'suppliers' => 0,
        ];

        foreach (self::CATEGORY_CONFIG as $category => $config) {
            $files_key = $config['files_key'];

            if (($data[$files_key] ?? null) === 'skipped') {
                continue;
            }

            if (! isset($analysis_result[$category]) || ! is_array($analysis_result[$category])) {
                continue;
            }

            $category_analysis = $analysis_result[$category];
            $excel_path = trim((string) ($category_analysis['excel_path'] ?? ''));

            if ($excel_path === '') {
                Log::channel('daily')->error('ImplementationImportService::execute_import: excel_path vacío.', [
                    'implementation_id' => $implementation->id,
                    'category'          => $category,
                ]);
                continue;
            }

            $column_mapping = is_array($category_analysis['column_mapping'] ?? null)
                ? $category_analysis['column_mapping']
                : [];

            $columns = $this->build_columns_payload($column_mapping);

            $this->set_import_status($stage, $implementation, $data, $category, 'importing');

            try {
                $response = Http::withHeaders([
                        'X-Admin-Api-Key' => $client->api_key,
                        'Accept'          => 'application/json',
                    ])
                    ->timeout((int) config('services.client_api.timeout', 120))
                    ->retry((int) config('services.client_api.retries', 1), 500)
                    ->post($import_url, [
                        'excel_path'      => $excel_path,
                        'columns'         => $columns,
                        'user_id'         => $owner_user_id,
                        'model'           => $config['model'],
                        'create_and_edit' => true,
                        'start_row'       => 2,
                        'finish_row'      => 1000,
                        'provider_id'     => $category_analysis['provider_id'] ?? null,
                        'registrar_art_cre' => true,
                        'registrar_art_act' => true,
                    ]);

                if ($response->successful()) {
                    $record_count = (int) ($category_analysis['record_count'] ?? 0);
                    $imported_counts[$category] = $record_count > 0 ? $record_count : 1;

                    $this->set_import_status(
                        $stage,
                        $implementation,
                        $data,
                        $category,
                        'success',
                        null,
                        now()->toISOString()
                    );

                    Log::channel('daily')->info('ImplementationImportService::execute_import: importación OK.', [
                        'implementation_id' => $implementation->id,
                        'category'          => $category,
                        'excel_path'        => $excel_path,
                    ]);
                    continue;
                }

                $error_message = 'Error HTTP ' . $response->status() . ' al importar ' . $config['label'];
                $this->set_import_status($stage, $implementation, $data, $category, 'failed', $error_message);

                $this->handle_http_error(
                    $implementation,
                    'import',
                    $category,
                    $response,
                    $import_url
                );
            } catch (\Throwable $exception) {
                $error_message = $exception->getMessage();
                $this->set_import_status($stage, $implementation, $data, $category, 'failed', $error_message);

                Log::channel('daily')->error('ImplementationImportService::execute_import: excepción.', [
                    'implementation_id' => $implementation->id,
                    'category'          => $category,
                    'message'           => $error_message,
                ]);
                $this->conversation_service->notify_assigned_admin_for_implementation(
                    $implementation,
                    '⚠️ Error al importar Excel ('
                    . $config['label']
                    . ') en implementación #'
                    . $implementation->id
                    . ': '
                    . $error_message
                );
            }
        }

        // Mensaje de éxito al responsable de migración.
        $success_message = $this->build_import_success_message($imported_counts, $data);

        $this->conversation_service->send_stage_4_outbound($implementation, $success_message);

        // Marcar etapa completada: el mensaje al cliente ya fue enviado arriba.
        $data['import_success_notified'] = true;
        $data['current_question']        = 'completed';
        $data['completed']               = true;
        $stage->data                     = $data;
        $stage->save();

        $this->conversation_service->finish_stage_4_after_import($implementation, $data);
    }

    /**
     * Actualiza import_status en stage.data, persiste y emite evento Pusher.
     *
     * @param ImplementationStage       $stage
     * @param Implementation            $implementation
     * @param array<string, mixed>      $data           Data del stage (se actualiza por referencia).
     * @param string                    $category       articles | clients | suppliers.
     * @param string                    $status         pending | importing | success | failed.
     * @param string|null               $error          Mensaje de error si aplica.
     * @param string|null               $imported_at    ISO8601 al completar con éxito.
     *
     * @return void
     */
    protected function set_import_status(
        ImplementationStage $stage,
        Implementation $implementation,
        array &$data,
        string $category,
        string $status,
        ?string $error = null,
        ?string $imported_at = null
    ): void {
        $import_status = is_array($data['import_status'] ?? null) ? $data['import_status'] : [];

        $category_entry = is_array($import_status[$category] ?? null)
            ? $import_status[$category]
            : [
                'status'      => 'pending',
                'error'       => null,
                'imported_at' => null,
            ];

        $category_entry['status'] = $status;
        $category_entry['error']  = $error;

        if ($imported_at !== null) {
            $category_entry['imported_at'] = $imported_at;
        } elseif ($status !== 'success') {
            $category_entry['imported_at'] = null;
        }

        $import_status[$category] = $category_entry;
        $data['import_status']    = $import_status;
        $stage->data              = $data;
        $stage->save();

        event(new ImplementationImportStatusUpdated(
            $implementation->id,
            $category,
            $status,
            $error
        ));
    }

    /**
     * Resuelve el user_id del owner del sistema en empresa-api.
     *
     * @param Client $client
     *
     * @return int|null
     */
    protected function resolve_owner_user_id(Client $client): ?int
    {
        $client->loadMissing('active_client_api', 'client_apis');

        // Prioridad: API activa con user_id explícito.
        if ($client->active_client_api instanceof ClientApi) {
            $active_user_id = $client->active_client_api->user_id ?? null;
            if ($active_user_id !== null && (int) $active_user_id > 0) {
                return (int) $active_user_id;
            }
        }

        // Fallback: primera ClientApi del cliente.
        $first_api = $client->client_apis->first();
        if ($first_api instanceof ClientApi) {
            $api_user_id = $first_api->user_id ?? null;
            if ($api_user_id !== null && (int) $api_user_id > 0) {
                return (int) $api_user_id;
            }
        }

        // Respaldo operativo: user_id del bloque ComercioCity en la tabla clients.
        if ($client->user_id !== null && (int) $client->user_id > 0) {
            return (int) $client->user_id;
        }

        return null;
    }

    /**
     * Descarga un archivo desde la URL de Kapso y lo guarda en storage/temp/.
     *
     * @param Implementation              $implementation
     * @param array<string, mixed>        $file_record
     *
     * @return string|null Ruta relativa dentro del disk local (storage/app) o null si falla.
     */
    protected function download_file_to_temp(Implementation $implementation, array $file_record): ?string
    {
        $url = trim((string) ($file_record['url'] ?? ''));

        if ($url === '') {
            Log::channel('daily')->error('ImplementationImportService: archivo sin URL de Kapso.', [
                'implementation_id' => $implementation->id,
                'filename'          => $file_record['filename'] ?? '',
            ]);
            $this->conversation_service->notify_assigned_admin_for_implementation(
                $implementation,
                '⚠️ Un archivo de la Etapa 4 no tiene URL de descarga; revisá el webhook de Kapso.'
            );

            return null;
        }

        try {
            /** API key de Kapso para URLs que lo requieren. */
            $kapso_api_key = '';
            $config = WhatsappConfig::query()->orderBy('id')->first();
            if ($config !== null) {
                $kapso_api_key = trim((string) ($config->kapso_api_key ?? ''));
            }

            $http = $kapso_api_key !== ''
                ? KapsoHttpClient::make($kapso_api_key, (int) config('services.client_api.timeout', 60))
                : KapsoHttpClient::make(null, (int) config('services.client_api.timeout', 60));

            $response = $http->withHeaders(['Accept' => '*/*'])->get($url);

            // Reintento sin API key si la URL ya es pública o firmada.
            if (! $response->successful() && $kapso_api_key !== '') {
                $response = KapsoHttpClient::make(null, (int) config('services.client_api.timeout', 60))
                    ->get($url);
            }

            if (! $response->successful()) {
                Log::channel('daily')->error('ImplementationImportService: fallo descarga Kapso.', [
                    'implementation_id' => $implementation->id,
                    'url'               => $url,
                    'status'            => $response->status(),
                ]);
                $this->conversation_service->notify_assigned_admin_for_implementation(
                    $implementation,
                    '⚠️ No se pudo descargar un Excel de la Etapa 4 (HTTP ' . $response->status() . ').'
                );

                return null;
            }

            $filename = trim((string) ($file_record['filename'] ?? 'import.xlsx'));
            if ($filename === '') {
                $filename = 'import.xlsx';
            }

            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $temp_relative = 'temp/impl_' . $implementation->id . '_' . time() . '_' . $safe_name;

            Storage::put($temp_relative, $response->body());

            return $temp_relative;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('ImplementationImportService: excepción al descargar archivo.', [
                'implementation_id' => $implementation->id,
                'message'           => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Arma el mensaje de resumen de columnas detectadas para pedir confirmación al cliente.
     *
     * @param array<string, mixed> $analysis_result
     * @param array<string, mixed> $stage_data
     *
     * @return string
     */
    protected function build_analysis_summary_message(array $analysis_result, array $stage_data): string
    {
        $lines = [
            'Listo, analicé los archivos. Esto es lo que detecté:',
            '',
        ];

        foreach (self::CATEGORY_CONFIG as $category => $config) {
            if (($stage_data[$config['files_key']] ?? null) === 'skipped') {
                continue;
            }

            if (! isset($analysis_result[$category])) {
                continue;
            }

            $category_analysis = $analysis_result[$category];
            $record_count = (int) ($category_analysis['record_count'] ?? 0);
            $count_label = $record_count > 0 ? (string) $record_count : 'varios';

            $lines[] = "{$config['label']} ({$count_label} registros):";
            $lines[] = '';

            $property_lines = $this->format_detected_columns(
                is_array($category_analysis['column_mapping'] ?? null)
                    ? $category_analysis['column_mapping']
                    : []
            );

            if (count($property_lines) === 0) {
                $lines[] = '(No se detectaron columnas mapeables)';
            } else {
                foreach ($property_lines as $property_line) {
                    $lines[] = $property_line;
                }
            }

            $lines[] = '';
        }

        $lines[] = '¿Es correcto? Si hay alguna columna mal identificada avisame y la corregimos.';

        return implode("\n", $lines);
    }

    /**
     * Formatea las columnas mapeadas como líneas "Nombre ✅" para el resumen.
     *
     * @param array<int, array<string, mixed>> $column_mapping
     *
     * @return array<int, string>
     */
    protected function format_detected_columns(array $column_mapping): array
    {
        $lines = [];
        $seen_properties = [];

        foreach ($column_mapping as $mapping_item) {
            if (! is_array($mapping_item)) {
                continue;
            }

            $system_property = $mapping_item['system_property'] ?? null;

            if ($system_property === null || $system_property === '') {
                continue;
            }

            $property_key = (string) $system_property;

            if (isset($seen_properties[$property_key])) {
                continue;
            }

            $seen_properties[$property_key] = true;

            $label = self::ARTICLE_PROPERTY_LABELS[$property_key] ?? ucfirst(str_replace('_', ' ', $property_key));
            $lines[] = "{$label} ✅";
        }

        return $lines;
    }

    /**
     * Transforma column_mapping al objeto columns que espera InitExcelImport.
     *
     * @param array<int, array<string, mixed>> $column_mapping
     *
     * @return array<string, int>
     */
    protected function build_columns_payload(array $column_mapping): array
    {
        $columns = [];

        foreach ($column_mapping as $array_position => $mapping_item) {
            if (! is_array($mapping_item)) {
                continue;
            }

            $system_property = $mapping_item['system_property'] ?? null;

            if ($system_property === null || $system_property === '') {
                continue;
            }

            $property_key = $this->normalize_system_property_key((string) $system_property);

            $column_position = $array_position;
            if (isset($mapping_item['excel_column_index']) && is_numeric($mapping_item['excel_column_index'])) {
                $column_position = (int) $mapping_item['excel_column_index'];
            }

            $columns[$property_key] = $column_position;
        }

        // Respaldo: descripcion como nombre si no hay columna nombre.
        if (! isset($columns['nombre']) && isset($columns['descripcion'])) {
            $columns['nombre'] = $columns['descripcion'];
            unset($columns['descripcion']);
        }

        return $columns;
    }

    /**
     * Normaliza alias de propiedades al contrato del importador.
     *
     * @param string $system_property
     *
     * @return string
     */
    protected function normalize_system_property_key(string $system_property): string
    {
        $aliases = [
            'codigo_proveedor' => 'codigo_de_proveedor',
            'codigo_barras'    => 'codigo_de_barras',
        ];

        if (isset($aliases[$system_property])) {
            return $aliases[$system_property];
        }

        return $system_property;
    }

    /**
     * Estima cantidad de registros a partir del mapeo (heurística cuando la API no devuelve filas).
     *
     * @param array<int, array<string, mixed>> $column_mapping
     *
     * @return int
     */
    protected function estimate_record_count(array $column_mapping): int
    {
        $mapped = 0;

        foreach ($column_mapping as $mapping_item) {
            if (! is_array($mapping_item)) {
                continue;
            }

            $system_property = $mapping_item['system_property'] ?? null;
            if ($system_property !== null && $system_property !== '') {
                $mapped++;
            }
        }

        if ($mapped === 0) {
            return 0;
        }

        // Heurística conservadora hasta que empresa-api exponga el conteo real de filas.
        return max($mapped * 10, 1);
    }

    /**
     * Arma el mensaje de cierre tras importaciones exitosas.
     *
     * @param array<string, int>   $imported_counts
     * @param array<string, mixed> $stage_data
     *
     * @return string
     */
    protected function build_import_success_message(array $imported_counts, array $stage_data): string
    {
        $parts = [];

        if (($stage_data['articles_files'] ?? null) !== 'skipped' && $imported_counts['articles'] > 0) {
            $parts[] = $imported_counts['articles'] . ' productos';
        }

        if (($stage_data['clients_files'] ?? null) !== 'skipped' && $imported_counts['clients'] > 0) {
            $parts[] = $imported_counts['clients'] . ' clientes';
        }

        if (($stage_data['suppliers_files'] ?? null) !== 'skipped' && $imported_counts['suppliers'] > 0) {
            $parts[] = $imported_counts['suppliers'] . ' proveedores';
        }

        if (count($parts) === 0) {
            return '¡Listo! Tu información ya está cargada en el sistema. 🎉';
        }

        $summary = implode(', ', $parts);

        return "¡Listo! Tu información ya está cargada en el sistema. {$summary} importados correctamente. 🎉";
    }

    /**
     * Registra error HTTP y notifica al admin sin relanzar la excepción.
     *
     * @param Implementation $implementation
     * @param string         $operation      analyze | import
     * @param string         $category
     * @param Response       $response
     * @param string         $url
     *
     * @return void
     */
    protected function handle_http_error(
        Implementation $implementation,
        string $operation,
        string $category,
        Response $response,
        string $url
    ): void {
        $config = self::CATEGORY_CONFIG[$category] ?? ['label' => $category];
        $body_snippet = mb_substr((string) $response->body(), 0, 300);

        Log::channel('daily')->error('ImplementationImportService: error HTTP en ' . $operation, [
            'implementation_id' => $implementation->id,
            'category'          => $category,
            'status'            => $response->status(),
            'url'               => $url,
            'body'              => $body_snippet,
        ]);

        $this->conversation_service->notify_assigned_admin_for_implementation(
            $implementation,
            '⚠️ Error HTTP '
            . $response->status()
            . ' al '
            . $operation
            . ' Excel ('
            . $config['label']
            . ') en implementación #'
            . $implementation->id
            . '.'
        );
    }

    /**
     * Log en daily + notificación al admin asignado.
     *
     * @param Implementation $implementation
     * @param string         $message
     *
     * @return void
     */
    protected function log_and_notify_admin(Implementation $implementation, string $message): void
    {
        Log::channel('daily')->error($message, [
            'implementation_id' => $implementation->id,
        ]);

        $this->conversation_service->notify_assigned_admin_for_implementation(
            $implementation,
            '⚠️ ' . $message
        );
    }
}
