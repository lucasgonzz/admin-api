<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImplementationFormSubmit;
use App\Models\Implementation;
use App\Models\ImplementationPaymentMethodOption;
use App\Models\ImplementationStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints públicos del formulario de configuración de implementación.
 *
 * El cliente accede mediante un link único con form_token (UUID v4) sin necesitar
 * autenticación. Los tres endpoints permiten: leer el estado actual del formulario,
 * guardar parcialmente los datos (autoguardado) y enviar definitivamente el formulario.
 *
 * Las respuestas del formulario web se persisten en implementation_stages.data
 * bajo la clave `form_responses`, separadas del flujo legacy por WhatsApp.
 */
class ImplementationFormController extends Controller
{
    /**
     * Clave dentro de stage->data donde se guardan las respuestas del formulario web.
     */
    private const FORM_RESPONSES_KEY = 'form_responses';

    /**
     * Claves válidas del formulario web (questions.js en admin-spa).
     * Se usan para leer autoguardados legados guardados en el nivel raíz de data.
     *
     * @var array<int, string>
     */
    private const FORM_FIELD_KEYS = [
        'price_mode',
        'price_lists',
        'costos_en_dolares',
        'cotizar_precios_en_dolares',
        'ventas_en_dolares',
        'stock_mode',
        'deposit_names',
        'payment_discounts',
        'apply_iva',
        'ask_quantity',
        'default_cuenta_corriente',
        'company_name',
        'address_company',
        'facebook',
        'instagram',
        'email',
        'doc_number',
        'logo_url',
        'employees',
        'migration_responsible',
        '_current_section',
    ];

    /**
     * Devuelve el estado actual del formulario para el token dado.
     *
     * Si el token no existe → 404.
     * Si el formulario ya fue enviado → { submitted: true }.
     * Si aún no fue enviado → { submitted: false, client_name: string, form_data: object }.
     *
     * @param string $token Token UUID v4 del formulario (form_token en implementations).
     *
     * @return JsonResponse
     */
    public function show(string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)
            ->with(['client', 'stages'])
            ->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // Si el formulario ya fue enviado, responder con flag simple (no exponer datos).
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['submitted' => true], 200);
        }

        // Nombre del cliente para personalizar el formulario en el frontend.
        $client_name = $implementation->client
            ? $implementation->client->resolve_display_name()
            : '';

        // Datos actuales del stage 1 (etapa de recolección de información de la empresa).
        $stage = $this->find_stage_1($implementation);

        // Respuestas parciales del formulario web (autoguardados previos) o vacío.
        $form_data = $this->read_form_responses($stage);

        // Precargar el email del lead (el que se uso para agendar la demo) si el cliente todavia no cargo uno.
        $has_email = is_array($form_data) && isset($form_data['email']) && trim((string) $form_data['email']) !== '';
        if (! $has_email && $implementation->client !== null) {
            $lead = \App\Models\Lead::where('promoted_client_id', $implementation->client->id)->first();
            if ($lead !== null) {
                $lead_email = trim((string) ($lead->email ?? ''));
                if ($lead_email !== '') {
                    // $form_data puede ser un stdClass vacio (se serializa como {}); normalizar a array para inyectar.
                    $form_data = is_array($form_data) ? $form_data : array();
                    $form_data['email'] = $lead_email;
                }
            }
        }

        // Opciones de métodos de pago disponibles en el select del formulario.
        $payment_method_options = ImplementationPaymentMethodOption::orderBy('position')
            ->get(['key', 'label']);

        return response()->json([
            'submitted'                => false,
            'client_name'              => $client_name,
            'form_data'                => $form_data,
            'payment_method_options'   => $payment_method_options,
        ], 200);
    }

    /**
     * Autoguardado parcial: mergea los campos enviados sobre form_responses del stage 1.
     *
     * Acepta `fields` o `form_data` en el body (compatibilidad con versiones del SPA).
     * Rechaza el guardado si el formulario ya fue enviado definitivamente.
     *
     * @param Request $request Petición con respuestas del formulario.
     * @param string  $token   Token UUID v4 del formulario.
     *
     * @return JsonResponse { saved_at: string } o error 422.
     */
    public function save(Request $request, string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // No permitir modificaciones si el formulario ya fue enviado.
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['message' => 'El formulario ya fue enviado y no puede modificarse.'], 422);
        }

        // Campos a mergear recibidos en el body.
        $fields = $this->resolve_request_fields($request);

        if ($fields === null) {
            return response()->json(['message' => 'El payload debe incluir fields o form_data como objeto.'], 422);
        }

        // Obtener el stage 1 para leer/actualizar su data.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            return response()->json(['message' => 'Etapa del formulario no encontrada.'], 404);
        }

        $this->persist_form_responses($stage, $fields);

        Log::channel('daily')->info('ImplementationFormController@save: autoguardado del formulario.', [
            'implementation_id' => $implementation->id,
            'field_count'       => count($fields),
        ]);

        // Devolver timestamp de guardado para que el frontend muestre "Guardado hace X segundos".
        return response()->json([
            'saved_at' => now()->toIso8601String(),
        ], 200);
    }

    /**
     * Envío definitivo del formulario de configuración.
     *
     * Flujo:
     * 1. Mergea los campos finales en form_responses del stage 1.
     * 2. Marca form_submitted_at en la implementación.
     * 3. Despacha el job ProcessImplementationFormSubmit con el delay configurado.
     *
     * @param Request $request Petición con respuestas del formulario.
     * @param string  $token   Token UUID v4 del formulario.
     *
     * @return JsonResponse { success: true } o error 422.
     */
    public function submit(Request $request, string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // Rechazar reenvíos: el formulario solo puede enviarse una vez.
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['message' => 'El formulario ya fue enviado.'], 422);
        }

        // Campos finales recibidos en el envío.
        $fields = $this->resolve_request_fields($request);

        if ($fields === null) {
            return response()->json(['message' => 'El payload debe incluir fields o form_data como objeto.'], 422);
        }

        // Obtener el stage 1 para mergear los datos finales.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->error('ImplementationFormController@submit: stage 1 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);

            return response()->json(['message' => 'Etapa del formulario no encontrada.'], 404);
        }

        $this->persist_form_responses($stage, $fields);

        // Registrar el timestamp de envío definitivo para bloquear futuros intentos.
        $implementation->form_submitted_at = now();
        $implementation->save();

        // Traducir las respuestas del formulario a setup_data + empleados + responsable de migración.
        // Best-effort: un fallo acá no debe impedir que el formulario quede registrado como enviado.
        try {
            (new \App\Services\ImplementationFormMapper())->apply($implementation);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('ImplementationFormController@submit: fallo el mapeo del formulario.', [
                'implementation_id' => $implementation->id,
                'error'             => $exception->getMessage(),
            ]);
        }

        // Despachar el job que procesará el formulario con el delay configurado en settings.
        ProcessImplementationFormSubmit::dispatch($implementation->id);

        Log::channel('daily')->info('ImplementationFormController@submit: formulario enviado.', [
            'implementation_id'  => $implementation->id,
            'form_submitted_at'  => $implementation->form_submitted_at->toIso8601String(),
        ]);

        return response()->json(['success' => true], 200);
    }

    /**
     * Sube el logo del negocio y lo persiste en form_responses.
     *
     * A diferencia de save()/submit(), este endpoint no espera al envío del formulario:
     * FieldImagen.vue lo llama apenas el cliente elige el archivo, para no perder la imagen
     * si cierra la pestaña antes de terminar el resto de las preguntas.
     *
     * Reglas (mismo criterio que save()/submit()):
     * - Token inválido → 404.
     * - Formulario ya enviado → 422 (no se puede seguir editando).
     * - Archivo ausente, no-imagen (solo jpeg/png/webp) o mayor a 5 MB → 422 con mensaje claro.
     * - Éxito: guarda el archivo en el disco `public`, persiste `logo_url` en form_responses
     *   (sobrescribe uno anterior sin borrar el archivo viejo: son archivos de implementación,
     *   no un catálogo con volumen) y lo devuelve.
     *
     * @param Request $request Debe incluir el archivo en `logo`.
     * @param string  $token   Token UUID v4 del formulario.
     *
     * @return JsonResponse { logo_url: string } o error 404/422.
     */
    public function upload_logo(Request $request, string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // No permitir subir un logo si el formulario ya fue enviado (mismo criterio que save()/submit()).
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['message' => 'El formulario ya fue enviado y no puede modificarse.'], 422);
        }

        // El SPA sube el archivo en el campo `logo`.
        $uploaded_file = $request->file('logo');

        if ($uploaded_file === null || ! $uploaded_file->isValid()) {
            return response()->json(['message' => 'No se recibió un archivo de imagen válido.'], 422);
        }

        // Mime real del archivo (mismo criterio que LeadController@send_direct_image_json: si finfo
        // no lo pudo determinar y devuelve application/octet-stream, se usa el mime que reportó el
        // cliente siempre que efectivamente sea una imagen).
        $mime = strtolower((string) $uploaded_file->getMimeType());

        if ($mime === 'application/octet-stream') {
            $client_mime = strtolower((string) $uploaded_file->getClientMimeType());
            if (strpos($client_mime, 'image/') === 0) {
                $mime = $client_mime;
            }
        }

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

        if (! in_array($mime, $allowed_mimes, true)) {
            return response()->json([
                'message' => 'El logo tiene que ser una imagen JPEG, PNG o WEBP (se recibió: ' . $mime . ').',
            ], 422);
        }

        // Tope de 5 MB.
        $max_bytes = 5 * 1024 * 1024;

        if ($uploaded_file->getSize() > $max_bytes) {
            return response()->json(['message' => 'El logo supera el tamaño máximo permitido (5 MB).'], 422);
        }

        // Extensión derivada del mime real, no de lo que mandó el navegador.
        switch ($mime) {
            case 'image/png':
                $ext = 'png';
                break;
            case 'image/webp':
                $ext = 'webp';
                break;
            case 'image/jpeg':
            default:
                $ext = 'jpg';
                break;
        }

        // Etapa 1: mismo lugar donde save()/submit() guardan el resto de las respuestas.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->error('ImplementationFormController@upload_logo: stage 1 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);

            return response()->json(['message' => 'Etapa del formulario no encontrada.'], 404);
        }

        // Guardado con timestamp + hash corto para no pisar el logo de otra implementación ni un
        // reintento del mismo cliente (mismo patrón que LeadController@send_direct_audio_json).
        $directory   = 'implementation_logos/' . $implementation->id;
        $stored_name = 'logo_' . now()->format('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $stored_path = $directory . '/' . $stored_name;

        Storage::disk('public')->put($stored_path, file_get_contents($uploaded_file->getRealPath()));

        // URL pública estándar de Laravel: config/filesystems.php ya arma disks.public.url con
        // APP_URL + /storage, no hay que inventar otro armado.
        $logo_url = Storage::disk('public')->url($stored_path);

        // Persistir en form_responses, reusando el mismo método que save()/submit() (sobrescribe si
        // ya había un logo cargado antes).
        $this->persist_form_responses($stage, ['logo_url' => $logo_url]);

        Log::channel('daily')->info('ImplementationFormController@upload_logo: logo subido.', [
            'implementation_id' => $implementation->id,
            'stored_path'       => $stored_path,
        ]);

        return response()->json(['logo_url' => $logo_url], 200);
    }

    /**
     * Busca la etapa 1 de una implementación ya cargada con relación stages.
     *
     * @param Implementation $implementation
     *
     * @return ImplementationStage|null
     */
    private function find_stage_1(Implementation $implementation): ?ImplementationStage
    {
        return $implementation->stages->first(function ($stage) {
            return (int) $stage->stage_number === 1;
        });
    }

    /**
     * Extrae el array de respuestas del formulario desde el body de la petición.
     * Acepta `fields` (contrato actual) o `form_data` (compatibilidad SPA anterior).
     *
     * @param Request $request
     *
     * @return array<string, mixed>|null null si ninguna clave es un objeto válido.
     */
    private function resolve_request_fields(Request $request): ?array
    {
        if ($request->has('fields')) {
            $fields = $request->input('fields');

            return is_array($fields) ? $fields : null;
        }

        if ($request->has('form_data')) {
            $form_data = $request->input('form_data');

            return is_array($form_data) ? $form_data : null;
        }

        // Sin clave reconocida: tratar como autoguardado vacío (no romper compatibilidad).
        return [];
    }

    /**
     * Lee las respuestas del formulario web desde el stage 1.
     * Prioriza data.form_responses; si no existe, busca claves legadas en el raíz.
     *
     * @param ImplementationStage|null $stage
     *
     * @return array<string, mixed>
     */
    private function read_form_responses(?ImplementationStage $stage): array
    {
        if ($stage === null || ! is_array($stage->data)) {
            return [];
        }

        $stage_data = $stage->data;

        if (isset($stage_data[self::FORM_RESPONSES_KEY]) && is_array($stage_data[self::FORM_RESPONSES_KEY])) {
            // Forzar objeto vacío en lugar de array vacío para que JSON lo serialice como {} no como []
            $responses = $stage_data[self::FORM_RESPONSES_KEY];
            return empty($responses) ? new \stdClass() : $responses;
        }

        $legacy = $this->extract_legacy_form_fields($stage_data);
        return empty($legacy) ? new \stdClass() : $legacy;
    }

    /**
     * Migra respuestas guardadas en el nivel raíz de stage->data (formato anterior).
     *
     * @param array<string, mixed> $stage_data
     *
     * @return array<string, mixed>
     */
    private function extract_legacy_form_fields(array $stage_data): array
    {
        $legacy_fields = [];

        foreach (self::FORM_FIELD_KEYS as $field_key) {
            if (array_key_exists($field_key, $stage_data)) {
                $legacy_fields[$field_key] = $stage_data[$field_key];
            }
        }

        return $legacy_fields;
    }

    /**
     * Mergea respuestas nuevas en stage->data.form_responses sin pisar el flujo WhatsApp.
     *
     * @param ImplementationStage     $stage
     * @param array<string, mixed>    $fields
     *
     * @return void
     */
    private function persist_form_responses(ImplementationStage $stage, array $fields): void
    {
        $stage_data = is_array($stage->data) ? $stage->data : [];

        $form_responses = isset($stage_data[self::FORM_RESPONSES_KEY]) && is_array($stage_data[self::FORM_RESPONSES_KEY])
            ? $stage_data[self::FORM_RESPONSES_KEY]
            : $this->extract_legacy_form_fields($stage_data);

        $stage_data[self::FORM_RESPONSES_KEY] = array_merge($form_responses, $fields);

        $stage->data = $stage_data;
        $stage->save();
    }
}
