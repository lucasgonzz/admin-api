<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\WhatsappConfig;
use App\Services\ImplementationActionService;
use App\Services\ImplementationBroadcastService;
use App\Services\ImplementationConversationService;
use App\Services\KapsoHttpClient;
use App\Services\WhatsappInboundMediaService;
use App\Services\WhatsappSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Acciones y listado del flujo de implementación guiada de clientes.
 */
class ImplementationController extends Controller
{
    /**
     * Lista todas las implementaciones ordenadas por updated_at descendente.
     *
     * Carga relaciones: client, stages y stages.config para mostrar el nombre de etapa
     * desde la tabla de configuración maestra.
     *
     * Agrega el campo virtual `ready_to_advance` (bool) en cada ítem:
     * true cuando current_stage < 8 y la etapa activa tiene status === 'completed',
     * lo que indica que el admin puede presionar "Avanzar etapa".
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Listado completo con relaciones necesarias para el panel izquierdo.
        $implementations = Implementation::query()
            ->with(['client', 'stages', 'stages.config'])
            ->orderBy('updated_at', 'desc')
            ->get();

        // Calcular ready_to_advance para cada implementación y anexarlo como atributo virtual.
        $implementations->each(function ($impl) {
            // Solo puede avanzar si aún hay etapas por delante (current_stage < 8).
            $ready = false;

            if ($impl->current_stage < 8) {
                // Buscar la etapa cuyo número coincide con la etapa actual.
                $current_stage_record = $impl->stages->first(function ($stage) use ($impl) {
                    return $stage->stage_number == $impl->current_stage;
                });

                // Si esa etapa completó su conversación automática, está lista para avanzar.
                if ($current_stage_record && $current_stage_record->status === 'completed') {
                    $ready = true;
                }
            }

            // Appender virtual: el frontend lo leerá como impl.ready_to_advance.
            $impl->ready_to_advance = $ready;
        });

        return response()->json(['models' => $implementations], 200);
    }

    /**
     * Devuelve la cantidad de implementaciones `in_progress` que están listas para avanzar.
     *
     * Una implementación está lista para avanzar cuando:
     * - Su status es 'in_progress'
     * - current_stage < 8
     * - La etapa con stage_number === current_stage tiene status === 'completed'
     *
     * Este endpoint es consumido por el Nav al inicializarse para obtener el conteo inicial
     * del badge de "implementaciones listas para avanzar".
     *
     * @return JsonResponse { count: int }
     */
    public function ready_to_advance_count(): JsonResponse
    {
        // Traer solo las implementaciones activas que pueden avanzar (current_stage < 8).
        $implementations = Implementation::query()
            ->where('status', 'in_progress')
            ->where('current_stage', '<', 8)
            ->with('stages')
            ->get();

        // Contar cuántas tienen la etapa actual completada (esperando al admin).
        $count = 0;

        $implementations->each(function ($impl) use (&$count) {
            $current_stage_record = $impl->stages->first(function ($stage) use ($impl) {
                return $stage->stage_number == $impl->current_stage;
            });

            if ($current_stage_record && $current_stage_record->status === 'completed') {
                $count++;
            }
        });

        return response()->json(['count' => $count], 200);
    }

    /**
     * Detalle completo de una implementación, incluyendo mensajes ordenados por sent_at.
     *
     * @param Implementation $implementation Implementación cargada por route model binding.
     *
     * @return JsonResponse
     */
    public function show(Implementation $implementation): JsonResponse
    {
        // Cargar todas las relaciones requeridas para el panel de detalle.
        $implementation->load(['client', 'stages', 'stages.config', 'messages']);

        // Resumen legible de las respuestas del formulario web de la Etapa 1 (para el panel).
        $mapper = new \App\Services\ImplementationFormMapper();
        $form   = $mapper->read_form_responses($implementation);

        $implementation->form_responses = $form;
        $implementation->form_summary   = $mapper->build_summary($form);

        return response()->json(['model' => $implementation], 200);
    }

    /**
     * Devuelve el JSON `data` del stage 4 de una implementación (archivos, análisis, import_status).
     *
     * @param Implementation $implementation Implementación cargada por route model binding.
     *
     * @return JsonResponse { data: object|null }
     */
    public function get_stage4_data(Implementation $implementation): JsonResponse
    {
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            return response()->json(['data' => null], 200);
        }

        $stage_data = is_array($stage->data) ? $stage->data : null;

        return response()->json(['data' => $stage_data], 200);
    }

    /**
     * Proxy de descarga de un archivo de la Etapa 4.
     *
     * Descarga el archivo desde la URL de Kapso/WhatsApp almacenada en el stage.data
     * usando KapsoHttpClient (con o sin API key según configuración) y lo devuelve
     * directamente al browser como descarga, evitando exponer las URLs firmadas de Kapso
     * al cliente y resolviendo posibles restricciones de CORS o expiración de URLs.
     *
     * Query params:
     *   - category: articles | clients | suppliers
     *   - index:    índice del archivo dentro del array de esa categoría (default 0)
     *
     * @param Implementation $implementation Implementación cargada por route model binding.
     * @param Request        $request        Petición con category e index.
     *
     * @return Response|JsonResponse
     */
    public function stage4_file_download(Implementation $implementation, Request $request)
    {
        // Validar categoría recibida; evitar acceso a claves arbitrarias del stage.data.
        $category = (string) ($request->input('category', ''));
        $index    = (int) ($request->input('index', 0));

        $valid_categories = ['articles', 'clients', 'suppliers'];

        if (! in_array($category, $valid_categories, true)) {
            return response()->json(['message' => 'Categoría inválida.'], 422);
        }

        // Buscar la etapa 4 de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null || ! is_array($stage->data)) {
            return response()->json(['message' => 'Etapa 4 no encontrada.'], 404);
        }

        // Clave del array de archivos para la categoría solicitada.
        $files_key = $category . '_files';
        $files     = $stage->data[$files_key] ?? null;

        if (! is_array($files) || ! array_key_exists($index, $files)) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        $file_record = $files[$index];
        $url         = trim((string) ($file_record['url'] ?? ''));
        $filename    = trim((string) ($file_record['filename'] ?? 'archivo'));

        if ($filename === '') {
            $filename = 'archivo';
        }

        if ($url === '') {
            return response()->json(['message' => 'El archivo no tiene URL de descarga disponible.'], 404);
        }

        return $this->proxy_file_download_response($url, $filename);
    }

    /**
     * Proxy de descarga de un archivo adjunto en un mensaje de la conversación.
     *
     * Parsea implementation_messages.body (formato kapso.content con URL y nombre)
     * y descarga el archivo desde Kapso sin exponer la URL firmada al browser.
     *
     * @param Implementation         $implementation Implementación dueña del hilo.
     * @param ImplementationMessage  $message        Mensaje con adjunto en el body.
     *
     * @return Response|JsonResponse
     */
    public function message_file_download(
        Implementation $implementation,
        ImplementationMessage $message
    ) {
        // Verificar que el mensaje pertenece a la implementación solicitada.
        if ((int) $message->implementation_id !== (int) $implementation->id) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        $media_service = new WhatsappInboundMediaService();
        $attachment      = $media_service->parse_attachment_from_message_body((string) $message->body);

        if ($attachment === null) {
            return response()->json(['message' => 'El mensaje no contiene un archivo descargable.'], 404);
        }

        return $this->proxy_file_download_response($attachment['url'], $attachment['filename']);
    }

    /**
     * Descarga un archivo remoto (Kapso) y lo devuelve como attachment al browser.
     *
     * @param string $url      URL firmada o pública del archivo en Kapso.
     * @param string $filename Nombre sugerido para Content-Disposition.
     *
     * @return Response|JsonResponse
     */
    private function proxy_file_download_response(string $url, string $filename)
    {
        $url = trim($url);

        if ($url === '') {
            return response()->json(['message' => 'URL de descarga no disponible.'], 404);
        }

        if ($filename === '') {
            $filename = 'archivo';
        }

        // Obtener la API key de Kapso desde la configuración de WhatsApp.
        $kapso_api_key = '';
        $whatsapp_config = WhatsappConfig::query()->orderBy('id')->first();

        if ($whatsapp_config !== null) {
            $kapso_api_key = trim((string) ($whatsapp_config->kapso_api_key ?? ''));
        }

        // Primer intento: con la API key si está disponible (URLs de Kapso que la requieren).
        $timeout     = (int) config('services.client_api.timeout', 60);
        $http_client = KapsoHttpClient::make($kapso_api_key !== '' ? $kapso_api_key : null, $timeout, false);
        $remote      = $http_client->withHeaders(['Accept' => '*/*'])->get($url);

        // Segundo intento sin API key (URLs públicas firmadas).
        if (! $remote->successful() && $kapso_api_key !== '') {
            $remote = KapsoHttpClient::make(null, $timeout, false)
                ->withHeaders(['Accept' => '*/*'])
                ->get($url);
        }

        if (! $remote->successful()) {
            return response()->json(['message' => 'No se pudo descargar el archivo desde el origen.'], 502);
        }

        // Detectar el Content-Type; si el origen no lo informa, deducir por extensión.
        $content_type = $remote->header('Content-Type') ?: $this->mime_type_from_filename($filename);

        // Sanear el nombre de archivo para el header Content-Disposition.
        $safe_filename = str_replace(['"', '\\'], ['_', '_'], $filename);

        return response($remote->body(), 200, [
            'Content-Type'        => $content_type,
            'Content-Disposition' => 'attachment; filename="' . $safe_filename . '"',
            'Cache-Control'       => 'no-cache, no-store',
        ]);
    }

    /**
     * Devuelve un MIME type básico inferido desde la extensión del nombre de archivo.
     *
     * Se usa cuando el servidor de origen no devuelve un Content-Type confiable.
     *
     * @param string $filename Nombre del archivo con extensión.
     *
     * @return string
     */
    private function mime_type_from_filename(string $filename): string
    {
        // Extensión en minúsculas para comparación normalizada.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $map = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt'  => 'text/plain',
            'zip'  => 'application/zip',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Avanza manualmente a la siguiente etapa de la implementación.
     *
     * Flujo:
     *  1. Marca la etapa actual como 'completed' con completed_at = now().
     *  2. Incrementa current_stage en 1.
     *  3. Si current_stage > 8 → marca implementación como 'completed'.
     *  4. Si no → marca nueva etapa como 'in_progress' con started_at = now().
     *  5. Dispara acciones automáticas vía ImplementationConversationService::handle_stage_advance.
     *
     * @param Implementation $implementation Implementación a avanzar (route model binding).
     *
     * @return JsonResponse
     */
    public function advance_stage(Implementation $implementation): JsonResponse
    {
        // Registro de la etapa actual que se va a cerrar.
        $current_stage_record = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', $implementation->current_stage)
            ->first();

        // Completar la etapa activa si existe.
        if ($current_stage_record) {
            $current_stage_record->status       = 'completed';
            $current_stage_record->completed_at = now();
            $current_stage_record->save();
        }

        // Número de la etapa siguiente.
        $next_stage = $implementation->current_stage + 1;
        $implementation->current_stage = $next_stage;

        if ($next_stage > 8) {
            // La implementación finalizó: todas las 8 etapas cubiertas.
            $implementation->status       = 'completed';
            $implementation->completed_at = now();
        } else {
            // Activar la etapa siguiente poniendo en marcha su cronómetro.
            $next_stage_record = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', $next_stage)
                ->first();

            if ($next_stage_record) {
                $next_stage_record->status     = 'in_progress';
                $next_stage_record->started_at = now();
                $next_stage_record->save();
            }
        }

        $implementation->save();

        // Acciones automáticas al activar etapas con lógica de conversación (2 a 8).
        if ($next_stage >= 2 && $next_stage <= 8) {
            $conversation_service = new ImplementationConversationService();
            $conversation_service->handle_stage_advance($implementation, $next_stage);
        }

        // Devolver modelo fresco con todas las relaciones del panel de detalle.
        return response()->json([
            'model' => $implementation->fresh()->load(['stages', 'stages.config', 'client', 'messages']),
        ], 200);
    }

    /**
     * Inicia la implementación de un cliente: crea el registro y las 8 etapas.
     *
     * @param Client $client Cliente destino (route model binding).
     *
     * @return JsonResponse
     */
    public function start(Client $client): JsonResponse
    {
        // Un cliente solo puede tener una implementación activa en el sistema.
        if ($client->implementation()->exists()) {
            return response()->json([
                'message' => 'Este cliente ya tiene una implementación iniciada.',
            ], 422);
        }

        /**
         * Admin asignado por defecto leído del setting global.
         * Se convierte a entero; si es 0 o no existe se guarda como null.
         */
        $assigned_admin_id = (int) AdminSetting::get('implementation_assigned_admin_id', 0) ?: null;

        // Modo de automatización por defecto para implementaciones nuevas ('manual' | 'auto').
        // Se lee de un setting global para poder reactivar la automatización sin deploy (prompt 342).
        $automation_mode = (string) AdminSetting::get('implementation_automation_mode', 'manual');

        if ($automation_mode !== 'auto') {
            $automation_mode = 'manual';
        }

        /** Implementación creada con etapa 1 en curso. */
        $implementation = DB::transaction(function () use ($client, $assigned_admin_id, $automation_mode) {
            $implementation = Implementation::create([
                'client_id'          => $client->id,
                'status'             => 'in_progress',
                'current_stage'      => 1,
                'started_at'         => now(),
                'assigned_admin_id'  => $assigned_admin_id,
                'automation_mode'    => $automation_mode,
            ]);

            // Crear las ocho etapas en estado pendiente.
            for ($stage_number = 1; $stage_number <= 8; $stage_number++) {
                ImplementationStage::create([
                    'implementation_id' => $implementation->id,
                    'stage_number'        => $stage_number,
                    'status'              => 'pending',
                ]);
            }

            // Activar la etapa 1.
            ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', 1)
                ->update([
                    'status'     => 'in_progress',
                    'started_at' => now(),
                ]);

            return $implementation;
        });

        // Generar token único (UUID v4) para acceso público al formulario de configuración.
        // Se genera fuera de la transacción para evitar colisiones de unique constraint.
        $implementation->form_token = \Illuminate\Support\Str::uuid()->toString();
        $implementation->save();

        // Plantilla de bienvenida por WhatsApp: best-effort, no bloquea la respuesta JSON.
        // En modo manual la presentación la envía Martín desde el panel (prompt 343).
        if ($implementation->is_automated()) {
            try {
                (new ImplementationConversationService())->send_welcome_template($implementation);
            } catch (\Throwable $exception) {
                Log::error('ImplementationController@start: fallo envío plantilla bienvenida.', [
                    'implementation_id' => $implementation->id,
                    'error'             => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'model' => $implementation->load(['stages', 'client']),
        ], 201);
    }

    /**
     * Elimina una implementación y toda su información asociada.
     *
     * Las tablas implementation_stages e implementation_messages tienen
     * onDelete cascade sobre implementations; al borrar el registro padre
     * se eliminan etapas, mensajes y datos JSON de cada etapa.
     *
     * @param Implementation $implementation Implementación a eliminar (route model binding).
     *
     * @return JsonResponse
     */
    public function destroy(Implementation $implementation): JsonResponse
    {
        DB::transaction(function () use ($implementation) {
            $implementation->delete();
        });

        return response()->json(['message' => 'Implementación eliminada.'], 200);
    }

    /**
     * Simula un mensaje entrante del cliente sin pasar por WhatsApp.
     *
     * Persiste el mensaje en implementation_messages con direction='inbound',
     * emite el evento Pusher para actualizar la UI en tiempo real y luego
     * invoca el mismo flujo de procesamiento que el webhook real.
     *
     * Útil para testing del agente de implementación sin necesidad de enviar
     * mensajes reales por WhatsApp.
     *
     * @param Request        $request        Petición con campo 'content' (texto del mensaje).
     * @param Implementation $implementation Implementación destino (route model binding).
     *
     * @return JsonResponse { model: ImplementationMessage } con código 201, o 422 si el content es vacío.
     */
    public function simulate_inbound(Request $request, Implementation $implementation): JsonResponse
    {
        // Validar que el contenido no esté vacío antes de procesar.
        $content = trim((string) $request->input('content', ''));

        if ($content === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        // Persistir el mensaje entrante simulado en la tabla de mensajes de la implementación.
        $message = ImplementationMessage::create([
            'implementation_id'   => $implementation->id,
            'stage_number'        => $implementation->current_stage,
            'direction'           => 'inbound',
            // Teléfono remitente (necesario para la ventana de 24 h): el del dueño en esta simulación.
            'phone'               => (string) ($implementation->client->phone ?? ''),
            'body'                => $content,
            'whatsapp_message_id' => null,
            'sent_at'             => now(),
        ]);

        // Emitir evento Pusher para que la UI lo reciba en tiempo real sin recargar.
        ImplementationBroadcastService::emit_message_received(
            (int) $implementation->id,
            (int) $message->id
        );

        // Procesar el mensaje como si viniera del webhook de WhatsApp real.
        $parsed = [
            'from' => (string) ($implementation->client->phone ?? ''),
            'type' => 'text',
            'body' => $content,
        ];

        (new ImplementationConversationService())->handle($implementation, $parsed);

        return response()->json(['model' => $message], 201);
    }

    /**
     * Envía un mensaje de texto saliente manual al cliente por WhatsApp.
     *
     * Permite al operador enviar un mensaje desde el panel admin sin pasar por
     * el flujo automático del agente. El mensaje se persiste en implementation_messages
     * con direction='outbound' y se emite evento Pusher para la UI.
     *
     * @param Request        $request        Petición con campo 'content' (texto del mensaje).
     * @param Implementation $implementation Implementación destino (route model binding).
     *
     * @return JsonResponse { model: ImplementationMessage } con código 201, o 422 si hay error de validación.
     */
    public function send_message(Request $request, Implementation $implementation): JsonResponse
    {
        // Validar que el contenido no esté vacío.
        $content = trim((string) $request->input('content', ''));

        if ($content === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        // Obtener el cliente asociado a la implementación para leer el teléfono.
        $client = $implementation->client ?? Client::find($implementation->client_id);
        $phone  = trim((string) ($client->phone ?? ''));

        if ($phone === '') {
            return response()->json(['message' => 'El cliente no tiene teléfono cargado.'], 422);
        }

        // Enviar el mensaje de texto por WhatsApp usando el servicio de envío.
        $whatsapp_send_service = new WhatsappSendService();
        $whatsapp_message_id   = $whatsapp_send_service->send_text($phone, $content);

        // Persistir el mensaje saliente con el ID de WhatsApp obtenido.
        $message = ImplementationMessage::create([
            'implementation_id'   => $implementation->id,
            'stage_number'        => $implementation->current_stage,
            'direction'           => 'outbound',
            // Teléfono destino: necesario para calcular la ventana de 24 h por persona.
            'phone'               => $phone,
            'body'                => $content,
            'whatsapp_message_id' => $whatsapp_message_id,
            'sent_at'             => now(),
        ]);

        // Emitir evento Pusher para actualizar la UI en tiempo real.
        ImplementationBroadcastService::emit_message_received(
            (int) $implementation->id,
            (int) $message->id
        );

        return response()->json(['model' => $message], 201);
    }

    /**
     * Estado completo del panel de acciones manuales de una implementación: las 6 acciones
     * con su destinatario, si la ventana de 24 h está abierta y cuándo se ejecutó cada una
     * por última vez.
     *
     * @param Implementation $implementation Implementación destino (route model binding).
     *
     * @return JsonResponse
     */
    public function actions_state(Implementation $implementation): JsonResponse
    {
        $state = (new ImplementationActionService())->state($implementation);

        return response()->json($state, 200);
    }

    /**
     * Preview de una acción manual: qué se va a enviar, a quién, y si hace falta plantilla.
     *
     * @param Request        $request        Petición con `stage` opcional en la query string (solo 'progreso').
     * @param Implementation $implementation Implementación destino (route model binding).
     * @param string         $action         Clave de la acción (ver ImplementationActionService::ACTIONS).
     *
     * @return JsonResponse
     */
    public function action_preview(Request $request, Implementation $implementation, string $action): JsonResponse
    {
        // Etapa opcional (solo aplica a 'progreso'); null si no vino en la query.
        $stage = $request->has('stage') ? (int) $request->query('stage') : null;

        try {
            $preview = (new ImplementationActionService())->preview($implementation, $action, $stage);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($preview, 200);
    }

    /**
     * Ejecuta una acción manual: envía el mensaje (o corre el UserSetup) y registra la ejecución.
     *
     * Devuelve además el modelo fresco de la implementación para que el panel se refresque
     * sin necesidad de otra llamada.
     *
     * @param Request        $request        Petición con `content` (texto editado, opcional) y `stage` (opcional).
     * @param Implementation $implementation Implementación destino (route model binding).
     * @param string         $action         Clave de la acción (ver ImplementationActionService::ACTIONS).
     *
     * @return JsonResponse
     */
    public function action_execute(Request $request, Implementation $implementation, string $action): JsonResponse
    {
        // Texto editado por el admin (opcional) y etapa seleccionada (opcional, solo 'progreso').
        $content = $request->input('content');
        $stage   = $request->has('stage') ? (int) $request->input('stage') : null;

        try {
            $result = (new ImplementationActionService())->execute($implementation, $action, $content, $stage);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'result' => $result,
            'model'  => $implementation->fresh()->load(['stages', 'stages.config', 'client', 'messages']),
        ], 200);
    }

    /**
     * Cambia el modo de automatización de una implementación ('manual' | 'auto').
     *
     * @param Request        $request        Petición con el campo 'automation_mode'.
     * @param Implementation $implementation Implementación destino.
     *
     * @return JsonResponse
     */
    public function update_automation_mode(Request $request, Implementation $implementation): JsonResponse
    {
        // Solo se aceptan los dos valores válidos; cualquier otro valor se rechaza.
        $mode = (string) $request->input('automation_mode', '');

        if (! in_array($mode, ['manual', 'auto'], true)) {
            return response()->json(['message' => 'Modo inválido. Valores permitidos: manual, auto.'], 422);
        }

        $implementation->automation_mode = $mode;
        $implementation->save();

        return response()->json(['model' => $implementation->fresh()], 200);
    }
}
