<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\EnvTemplate;
use App\Services\EnvSshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de la plantilla base de variables .env del sistema.
 *
 * Permite leer, actualizar y comparar las variables .env del template contra
 * los valores reales en producción de cada cliente vía SSH.
 */
class EnvTemplateController extends Controller
{
    /**
     * Devuelve todas las variables del template ordenadas por grupo y orden.
     *
     * @return JsonResponse  { models: EnvTemplate[] }
     */
    public function index(): JsonResponse
    {
        /* Trae todas las variables ordenadas por grupo y sort_order para la UI. */
        $templates = EnvTemplate::orderBy('group')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['models' => $templates]);
    }

    /**
     * Crea una nueva variable en la plantilla .env.
     *
     * La key se normaliza a mayúsculas y se valida que no exista previamente.
     * Devuelve la lista completa actualizada para que el frontend se re-sincronice.
     *
     * @param  Request  $request  { key, value, group, is_common, is_manual_on_create, notes, sort_order }
     * @return JsonResponse  { models: EnvTemplate[] }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'key'                 => 'required|string|max:120|unique:env_templates,key',
            'value'               => 'nullable|string',
            'group'               => 'required|string|max:80',
            'is_common'           => 'required|boolean',
            'is_manual_on_create' => 'required|boolean',
            'notes'               => 'nullable|string',
            'sort_order'          => 'required|integer',
        ]);

        /* Normaliza la key a mayúsculas y sin espacios antes de crear. */
        EnvTemplate::create([
            'key'                 => strtoupper(trim($request->input('key'))),
            'value'               => $request->input('value') ?: null,
            'group'               => trim($request->input('group')),
            'is_common'           => (bool) $request->input('is_common'),
            'is_manual_on_create' => (bool) $request->input('is_manual_on_create'),
            'notes'               => $request->input('notes') ?: null,
            'sort_order'          => (int) $request->input('sort_order'),
        ]);

        /* Devuelve la lista completa ordenada para que el frontend refleje el nuevo registro. */
        $templates = EnvTemplate::orderBy('group')->orderBy('sort_order')->get();

        return response()->json(['models' => $templates], 201);
    }

    /**
     * Actualiza masivamente las variables del template en una sola llamada.
     *
     * Recibe un array `items[]` con los campos editables de cada variable.
     * Actualiza cada una por ID y devuelve la lista completa actualizada.
     *
     * @param  Request  $request  { items: [{ id, value, is_common, is_manual_on_create, notes, group, sort_order }] }
     * @return JsonResponse  { models: EnvTemplate[] }
     */
    public function bulk_update(Request $request): JsonResponse
    {
        $request->validate([
            'items'                    => 'required|array',
            'items.*.id'               => 'required|integer|exists:env_templates,id',
            'items.*.value'            => 'nullable|string',
            'items.*.is_common'        => 'required|boolean',
            'items.*.is_manual_on_create' => 'required|boolean',
            'items.*.notes'            => 'nullable|string',
            'items.*.group'            => 'nullable|string|max:80',
            'items.*.sort_order'       => 'required|integer',
        ]);

        /* Procesa cada item del array y actualiza su registro en BD. */
        foreach ($request->input('items') as $item) {
            $template = EnvTemplate::findOrFail($item['id']);

            /* Actualiza únicamente los campos editables desde la UI. */
            $template->value             = $item['value'] ?? null;
            $template->is_common         = (bool) $item['is_common'];
            $template->is_manual_on_create = (bool) $item['is_manual_on_create'];
            $template->notes             = $item['notes'] ?? null;
            $template->group             = $item['group'] ?? null;
            $template->sort_order        = (int) $item['sort_order'];
            $template->save();
        }

        /* Devuelve la lista completa actualizada para que el frontend refleje los cambios. */
        $templates = EnvTemplate::orderBy('group')->orderBy('sort_order')->get();

        return response()->json(['models' => $templates]);
    }

    /**
     * Compara las variables del template marcadas como is_common contra el .env real del cliente.
     *
     * Lee el .env del cliente vía SSH y detecta variables con valores distintos al template base.
     * Si hay error de SSH, devuelve diffs vacíos con mensaje descriptivo (HTTP 200, no crítico).
     *
     * @param  Request  $request  { client_api_id: int }
     * @param  Client   $client   Cliente resuelto por route model binding.
     * @return JsonResponse  { diffs: [{ key, template_value, client_value }] } o { diffs: [], error: string }
     */
    public function check_diff(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'client_api_id' => 'required|integer',
        ]);

        /* Resuelve el ClientApi validando que pertenezca al cliente del parámetro de ruta. */
        $client_api = ClientApi::where('id', $request->input('client_api_id'))
            ->where('client_id', $client->id)
            ->first();

        if (! $client_api) {
            return response()->json([
                'diffs' => [],
                'error' => 'La API seleccionada no pertenece a este cliente.',
            ]);
        }

        /* Obtiene el path del .env del cliente en el hosting. */
        $env_ssh_service = new EnvSshService();
        $api_path        = $env_ssh_service->get_api_path($client_api);

        /* Lee el .env del cliente vía SSH. En caso de error, devuelve warning no bloqueante. */
        try {
            $client_env = $env_ssh_service->read_env($api_path);
        } catch (\Throwable $e) {
            return response()->json([
                'diffs' => [],
                'error' => 'No se pudo leer el .env del cliente: ' . $e->getMessage(),
            ]);
        } finally {
            $env_ssh_service->disconnect();
        }

        /* Recupera solo las variables del template marcadas como comunes. */
        $common_templates = EnvTemplate::where('is_common', true)->get();

        /* Compara cada variable común contra el valor en el .env real del cliente. */
        $diffs = [];
        foreach ($common_templates as $template) {
            /* Valor en el template base (trimmed). */
            $template_value = trim((string) ($template->value ?? ''));

            /* Valor en el .env real del cliente (trimmed); vacío si no existe la key. */
            $client_value = isset($client_env[$template->key])
                ? trim((string) $client_env[$template->key])
                : '';

            /* Solo reporta diferencia si los valores no coinciden (case-sensitive). */
            if ($template_value !== $client_value) {
                $diffs[] = [
                    'key'            => $template->key,
                    'template_value' => $template_value,
                    'client_value'   => $client_value,
                ];
            }
        }

        return response()->json(['diffs' => $diffs]);
    }

    /**
     * Compara variables comunes del template contra el .env real de TODAS las APIs del cliente.
     *
     * Para cada ClientApi del cliente, ejecuta la misma lógica que check_diff
     * y devuelve los resultados agrupados por client_api_id.
     *
     * Si una API falla (SSH error), devuelve sus diffs vacíos con un campo error.
     *
     * @param  Client  $client  Cliente resuelto por route model binding.
     * @return JsonResponse  {
     *   results: [
     *     { client_api_id, api_url, diffs: [{ key, template_value, client_value }], error: string|null }
     *   ]
     * }
     */
    public function check_diff_all(Client $client): JsonResponse
    {
        /* Recupera solo las variables del template marcadas como comunes. */
        $common_templates = EnvTemplate::where('is_common', true)->get();

        /* Obtiene todas las APIs del cliente para iterar sobre ellas. */
        $client_apis = $client->client_apis;

        /* Instancia el servicio SSH compartido para todas las conexiones. */
        $env_ssh_service = new EnvSshService();

        /* Acumula los resultados por API para devolver al frontend. */
        $results = [];

        foreach ($client_apis as $client_api) {
            /* Determina el path del .env del cliente en el hosting. */
            $api_path = $env_ssh_service->get_api_path($client_api);

            try {
                /* Lee el .env real del cliente vía SSH. */
                $client_env = $env_ssh_service->read_env($api_path);

                /* Compara cada variable común contra el valor en el .env real. */
                $diffs = [];
                foreach ($common_templates as $template) {
                    /* Valor en el template base (trimmed). */
                    $template_value = trim((string) ($template->value ?? ''));

                    /* Valor en el .env real del cliente (trimmed); vacío si no existe la key. */
                    $client_value = isset($client_env[$template->key])
                        ? trim((string) $client_env[$template->key])
                        : '';

                    /* Solo reporta diferencia si los valores no coinciden (case-sensitive). */
                    if ($template_value !== $client_value) {
                        $diffs[] = [
                            'key'            => $template->key,
                            'template_value' => $template_value,
                            'client_value'   => $client_value,
                        ];
                    }
                }

                $results[] = [
                    'client_api_id' => $client_api->id,
                    'api_url'       => $client_api->url,
                    'diffs'         => $diffs,
                    'error'         => null,
                ];
            } catch (\Throwable $e) {
                /* Si hay error SSH en esta API, reporta el error sin bloquear las demás. */
                $results[] = [
                    'client_api_id' => $client_api->id,
                    'api_url'       => $client_api->url,
                    'diffs'         => [],
                    'error'         => 'SSH error: ' . $e->getMessage(),
                ];
            }
        }

        $env_ssh_service->disconnect();

        return response()->json(['results' => $results]);
    }

    /**
     * Aplica variables seleccionadas del template al .env de TODAS las APIs del cliente.
     *
     * Para cada ClientApi del cliente, escribe las variables solicitadas vía SSH.
     * Devuelve cuántas APIs fueron actualizadas y cuáles fallaron.
     *
     * @param  Request  $request  { keys: string[] }
     * @param  Client   $client   Cliente resuelto por route model binding.
     * @return JsonResponse  { updated_apis: int, failed_apis: [{ api_url, error }] }
     */
    public function apply_diff_all(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'keys'   => 'required|array',
            'keys.*' => 'required|string',
        ]);

        /* Obtiene los templates para las keys solicitadas, indexados por key. */
        $keys_to_apply = $request->input('keys');
        $templates     = EnvTemplate::whereIn('key', $keys_to_apply)->get()->keyBy('key');

        /* Construye el array KEY => valor_del_template para escribir en cada .env. */
        $vars_to_write = [];
        foreach ($keys_to_apply as $key) {
            if ($templates->has($key)) {
                $vars_to_write[$key] = (string) ($templates[$key]->value ?? '');
            }
        }

        /* Si no hay variables válidas, responde inmediatamente sin abrir SSH. */
        if (empty($vars_to_write)) {
            return response()->json(['updated_apis' => 0, 'failed_apis' => []]);
        }

        /* Escribe las variables en el .env de cada API del cliente. */
        $client_apis     = $client->client_apis;
        $env_ssh_service = new EnvSshService();

        /* Contador de APIs actualizadas correctamente. */
        $updated_apis = 0;

        /* Acumula errores por API para reportarlos al frontend. */
        $failed_apis = [];

        foreach ($client_apis as $client_api) {
            $api_path = $env_ssh_service->get_api_path($client_api);
            try {
                $env_ssh_service->write_env_vars($api_path, $vars_to_write);
                $updated_apis++;
            } catch (\Throwable $e) {
                $failed_apis[] = [
                    'api_url' => $client_api->url,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        $env_ssh_service->disconnect();

        return response()->json([
            'updated_apis' => $updated_apis,
            'failed_apis'  => $failed_apis,
        ]);
    }

    /**
     * Aplica las variables seleccionadas del template al .env real del cliente vía SSH.
     *
     * Toma los valores del template para las keys recibidas y los escribe en el .env del cliente.
     * Devuelve la lista de keys efectivamente actualizadas.
     *
     * @param  Request  $request  { client_api_id: int, keys: string[] }
     * @param  Client   $client   Cliente resuelto por route model binding.
     * @return JsonResponse  { updated: string[] }
     */
    public function apply_diff(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'client_api_id' => 'required|integer',
            'keys'          => 'required|array',
            'keys.*'        => 'required|string',
        ]);

        /* Resuelve el ClientApi validando que pertenezca al cliente del parámetro de ruta. */
        $client_api = ClientApi::where('id', $request->input('client_api_id'))
            ->where('client_id', $client->id)
            ->firstOrFail();

        /* Recupera los templates para las keys solicitadas. */
        $keys_to_apply = $request->input('keys');
        $templates     = EnvTemplate::whereIn('key', $keys_to_apply)->get()->keyBy('key');

        /* Construye el array KEY => valor_del_template para escribir en el .env del cliente. */
        $vars_to_write = [];
        foreach ($keys_to_apply as $key) {
            if ($templates->has($key)) {
                /* Usa el valor del template (sin comillas, como está almacenado en BD). */
                $vars_to_write[$key] = (string) ($templates[$key]->value ?? '');
            }
        }

        if (empty($vars_to_write)) {
            return response()->json(['updated' => []]);
        }

        /* Escribe las variables en el .env del cliente vía SSH. */
        $env_ssh_service = new EnvSshService();
        $api_path        = $env_ssh_service->get_api_path($client_api);

        $env_ssh_service->write_env_vars($api_path, $vars_to_write);
        $env_ssh_service->disconnect();

        return response()->json(['updated' => array_keys($vars_to_write)]);
    }
}
