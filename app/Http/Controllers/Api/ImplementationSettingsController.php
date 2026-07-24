<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\ImplementationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del flujo de implementaciones: admin asignado por defecto,
 * tiempo de espera para confirmar lista de empleados (Etapa 1) y tiempo de
 * espera para procesar archivos recibidos (Etapa 4).
 *
 * Expone endpoints para que el panel de Account pueda leer y actualizar
 * los settings de implementación almacenados en admin_settings.
 */
class ImplementationSettingsController extends Controller
{
    /**
     * Retorna el admin actualmente configurado como responsable de implementaciones.
     *
     * Devuelve admin_id (int o null) para pre-seleccionar el valor en el select del frontend.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        // Leer el ID del admin configurado; convertir a entero o null si no existe.
        $raw_value = AdminSetting::get('implementation_assigned_admin_id');
        $admin_id  = ($raw_value !== null && (int) $raw_value > 0) ? (int) $raw_value : null;

        return response()->json(['admin_id' => $admin_id], 200);
    }

    /**
     * Actualiza el admin asignado por defecto a nuevas implementaciones.
     *
     * Valida que el admin_id exista en la tabla admins antes de persistir.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // El admin_id debe existir en la tabla admins.
            'admin_id' => 'required|integer|exists:admins,id',
        ]);

        // Persistir o actualizar el setting con el nuevo ID de admin.
        AdminSetting::set('implementation_assigned_admin_id', (string) $validated['admin_id']);

        return response()->json(['admin_id' => (int) $validated['admin_id']], 200);
    }

    /**
     * Retorna la cantidad de segundos de espera configurada para procesar archivos
     * recibidos en la Etapa 4 (debounce de múltiples archivos).
     *
     * @return JsonResponse
     */
    public function get_file_wait(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 15.
        $seconds = ImplementationSettings::get_file_wait_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de espera antes de procesar archivos
     * recibidos en la Etapa 4.
     *
     * Valida que el valor esté entre 1 y 120 segundos para evitar configuraciones extremas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_file_wait(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Mínimo 1 segundo para que el debounce tenga sentido; máximo 120 para no bloquear demasiado.
            'seconds' => 'required|integer|min:1|max:120',
        ]);

        // Persistir o actualizar el setting con el nuevo valor.
        AdminSetting::set('implementation_file_wait_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }

    /**
     * Retorna la cantidad de segundos de espera configurada para preguntar al cliente
     * si terminó de pasar empleados en la Etapa 1 (debounce de confirmación).
     *
     * @return JsonResponse
     */
    public function get_employees_wait(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 30.
        $seconds = ImplementationSettings::get_employees_wait_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de espera antes de preguntar al cliente
     * si terminó de pasar empleados en la Etapa 1.
     *
     * Valida que el valor esté entre 1 y 120 segundos para evitar configuraciones extremas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_employees_wait(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Mínimo 1 segundo para que el debounce tenga sentido; máximo 120 para no bloquear demasiado.
            'seconds' => 'required|integer|min:1|max:120',
        ]);

        // Persistir o actualizar el setting con el nuevo valor.
        AdminSetting::set('implementation_employees_wait_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }

    /**
     * Retorna la cantidad de segundos de delay post-formulario configurada.
     *
     * Es el tiempo entre que el cliente envía el formulario y el primer contacto automático por WhatsApp.
     *
     * @return JsonResponse { seconds: int }
     */
    public function get_form_contact_delay(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 60.
        $seconds = ImplementationSettings::get_form_contact_delay_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de delay entre el envío del formulario
     * y el primer contacto automático por WhatsApp.
     *
     * @param Request $request
     *
     * @return JsonResponse { seconds: int }
     */
    public function update_form_contact_delay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Al menos 1 segundo; sin límite superior estricto (puede ser minutos).
            'seconds' => 'required|integer|min:1|max:3600',
        ]);

        // Persistir o actualizar el setting.
        AdminSetting::set('implementation_form_contact_delay_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }

    /**
     * Retorna la URL base del formulario de configuración configurada.
     *
     * @return JsonResponse { url: string }
     */
    public function get_form_url(): JsonResponse
    {
        // Leer la URL guardada; devuelve cadena vacía si no está configurada.
        $url = ImplementationSettings::get_form_url();

        return response()->json(['url' => $url], 200);
    }

    /**
     * Actualiza la URL base del formulario de configuración en admin-spa.
     *
     * La URL completa para el cliente es: {url}/{form_token}.
     *
     * @param Request $request
     *
     * @return JsonResponse { url: string }
     */
    public function update_form_url(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // La URL puede estar vacía (para limpiar el setting) o ser una URL válida.
            'url' => 'nullable|string|max:500',
        ]);

        // Persistir o actualizar el setting con la nueva URL.
        $url = (string) ($validated['url'] ?? '');
        AdminSetting::set('implementation_form_url', $url);

        return response()->json(['url' => $url], 200);
    }

    /**
     * Retorna la cuota de Google configurada por defecto para nuevos usuarios reales.
     *
     * @return JsonResponse
     */
    public function get_google_cuota_default(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a 100.
        $cuota = ImplementationSettings::get_google_cuota_default();

        return response()->json(['cuota' => $cuota], 200);
    }

    /**
     * Actualiza la cuota de Google por defecto para nuevos usuarios reales.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_google_cuota_default(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Sin límite superior estricto: es un contador de uso, no un booleano ni un rango acotado.
            'cuota' => 'required|integer|min:0',
        ]);

        AdminSetting::set('implementation_google_cuota_default', (string) $validated['cuota']);

        return response()->json(['cuota' => (int) $validated['cuota']], 200);
    }

    /**
     * Retorna la API key de Google Custom Search configurada para clientes reales.
     *
     * No se enmascara el valor: el panel de admin es interno y hace falta poder ver
     * qué key está cargada.
     *
     * @return JsonResponse { api_key: string }
     */
    public function get_google_api_key_default(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a cadena vacía.
        $api_key = ImplementationSettings::get_google_api_key_default();

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Actualiza la API key de Google Custom Search para clientes reales.
     *
     * Las API keys de Google Custom Search tienen forma "AIza" + 35 caracteres alfanuméricos
     * (incluyendo "-" y "_"). La regex está para evitar el error más caro posible: guardar
     * una key mal copiada (cortada, con un espacio pegado, o la key de otro servicio de
     * Google) y enterarse recién cuando un cliente nuevo no puede buscar imágenes.
     *
     * Se acepta null / cadena vacía como forma explícita de borrar la setting y volver
     * al fallback hardcodeado en empresa-api (en ese caso se guarda '').
     *
     * @param Request $request
     *
     * @return JsonResponse { api_key: string }
     */
    public function update_google_api_key_default(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // "present" permite null/'' explícito para borrar; si viene con contenido, debe matchear el formato de Google.
            'api_key' => ['present', 'nullable', 'string', 'max:100', 'regex:/^AIza[0-9A-Za-z\-_]{35}$/'],
        ]);

        // Cadena vacía si vino null (borra la setting y vuelve al fallback de empresa-api).
        $api_key = (string) ($validated['api_key'] ?? '');
        AdminSetting::set('implementation_google_api_key_default', $api_key);

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Retorna la API key de Google Custom Search configurada para demos.
     *
     * Separada de la de clientes reales a propósito: la cuota diaria de Custom Search
     * es por key, y mezclarlas haría que las demos consuman la cuota de los clientes que pagan.
     *
     * @return JsonResponse { api_key: string }
     */
    public function get_google_api_key_demo(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a cadena vacía.
        $api_key = ImplementationSettings::get_google_api_key_demo();

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Actualiza la API key de Google Custom Search para demos.
     *
     * Misma validación y semántica de borrado que update_google_api_key_default().
     *
     * @param Request $request
     *
     * @return JsonResponse { api_key: string }
     */
    public function update_google_api_key_demo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // "present" permite null/'' explícito para borrar; si viene con contenido, debe matchear el formato de Google.
            'api_key' => ['present', 'nullable', 'string', 'max:100', 'regex:/^AIza[0-9A-Za-z\-_]{35}$/'],
        ]);

        // Cadena vacía si vino null (borra la setting y vuelve al fallback de empresa-api).
        $api_key = (string) ($validated['api_key'] ?? '');
        AdminSetting::set('implementation_google_api_key_demo', $api_key);

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Retorna la cuota de Google configurada por defecto para nuevos usuarios de demo.
     *
     * @return JsonResponse
     */
    public function get_google_cuota_demo(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a 100.
        $cuota = ImplementationSettings::get_google_cuota_demo();

        return response()->json(['cuota' => $cuota], 200);
    }

    /**
     * Actualiza la cuota de Google por defecto para nuevos usuarios de demo.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_google_cuota_demo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Sin límite superior estricto: es un contador de uso, no un booleano ni un rango acotado.
            'cuota' => 'required|integer|min:0',
        ]);

        AdminSetting::set('implementation_google_cuota_demo', (string) $validated['cuota']);

        return response()->json(['cuota' => (int) $validated['cuota']], 200);
    }
}
