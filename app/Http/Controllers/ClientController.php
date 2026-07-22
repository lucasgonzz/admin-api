<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\Version;
use App\Services\SubdomainSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientController extends BaseController
{
    function index() {
        $clients = Client::withAll()->orderBy('name')->get();
        return view('clients.index', compact('clients'));
    }

    function create() {
        return view('clients.create');
    }

    function store(Request $request) {
        $data = $this->extract_data($request);
        if (empty($data['slug'])) {
            $data['slug'] = null;
        }
        if (empty($data['api_key'])) {
            $data['api_key'] = Str::random(40);
        }
        if (empty($data['inbound_api_key'])) {
            $data['inbound_api_key'] = Str::random(40);
        }
        $client = Client::create($data);
        return redirect()->route('clients.show', $client->id)->with('success', 'Cliente creado.');
    }

    function show($id) {
        $client = Client::withAll()->findOrFail($id);
        $upgrades = $client->upgrades()->with('from_version', 'to_version', 'created_by_admin')->get();
        $reads = $client->notification_reads()
                        ->with('version_notification.version')
                        ->orderBy('read_at', 'desc')
                        ->get();
        $versions = Version::where('status', 'published')->orderBy('id', 'desc')->get();
        return view('clients.show', compact('client', 'upgrades', 'reads', 'versions'));
    }

    function edit($id) {
        $client = Client::findOrFail($id);
        return view('clients.edit', compact('client'));
    }

    function update(Request $request, $id) {
        $client = Client::findOrFail($id);
        $data = $this->extract_data($request);
        $client->update($data);
        return redirect()->route('clients.show', $client->id)->with('success', 'Cliente actualizado.');
    }

    function destroy($id) {
        $client = Client::findOrFail($id);
        $client->delete();
        return redirect()->route('clients.index')->with('success', 'Cliente eliminado.');
    }

    protected function extract_data(Request $request) {
        // Slug opcional: cadena vacía se persiste como NULL.
        $slug = $request->input('slug');
        if ($slug === '' || $slug === null) {
            $slug = null;
        }

        return [
            'name' => $request->input('name'),
            'company_name' => $request->input('company_name'),
            'slug' => $slug,
            'api_url' => rtrim($request->input('api_url'), '/'),
            'api_key' => $request->input('api_key'),
            'inbound_api_key' => $request->input('inbound_api_key'),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    // --- API JSON (admin-spa) ---

    public function index_json(Request $request)
    {
        $per = (int) $request->input('per_page', 100);
        if ($per < 1) {
            $per = 20;
        }
        if ($per > 200) {
            $per = 200;
        }
        $q = Client::query()->withAll()->orderBy('id', 'desc');
        if ($request->has('page')) {
            $models = $q->paginate($per);
        } else {
            $models = $q->get();
        }

        return response()->json(['models' => $models], 200);
    }

    public function show_json($id)
    {
        $m = $this->fullModel('client', $id);
        if (! $m) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $m], 200);
    }

    public function store_json(Request $request)
    {
        $data = ModelPropertiesHelper::attributes_for_create($request, 'client');
        if (array_key_exists('slug', $data) && ($data['slug'] === '' || $data['slug'] === null)) {
            $data['slug'] = null;
        }
        if (empty($data['api_key'] ?? null)) {
            $data['api_key'] = Str::random(40);
        }
        if (empty($data['inbound_api_key'] ?? null)) {
            $data['inbound_api_key'] = Str::random(40);
        }
        if (isset($data['api_url']) && is_string($data['api_url'])) {
            $data['api_url'] = rtrim($data['api_url'], '/');
        }
        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }
        $client = Client::create($data);
        $this->update_relations_created('client', $client->id, $request->input('childrens'));
        ModelPropertiesHelper::validate_from_has_many($client, $request, 'client');
        $this->sync_ecommerce_urls_from_request($client, $request);

        return response()->json(['model' => $this->fullModel('client', $client->id)], 201);
    }

    public function update_json(Request $request, $id)
    {
        $client = Client::findOrFail($id);
        ModelPropertiesHelper::set_from_request($client, $request, 'client');
        if ($request->has('api_url') && is_string($request->input('api_url'))) {
            $client->api_url = rtrim($request->input('api_url'), '/');
            $client->save();
        }
        $this->sync_ecommerce_urls_from_request($client, $request);

        return response()->json(['model' => $this->fullModel('client', $id)], 200);
    }

    /**
     * Crea o actualiza el ClientEcommerce del cliente a partir de las claves
     * `ecommerce_spa_url` / `ecommerce_api_url` del request (modal de tienda
     * online en admin-spa). No usa ModelPropertiesHelper porque esas claves
     * no son columnas de `clients` sino de la relación `client_ecommerce`.
     *
     * @param  Client   $client   Cliente al que pertenece (o va a pertenecer) la tienda.
     * @param  Request  $request  Request original de store_json/update_json.
     * @return void
     */
    protected function sync_ecommerce_urls_from_request(Client $client, Request $request)
    {
        // Si el request no trae ninguna de las dos claves, no es el flujo del
        // modal de tienda online: no se toca nada (cualquier otro flujo que
        // guarde un cliente sin mandarlas no debe crear/modificar la tienda).
        if (! $request->has('ecommerce_spa_url') && ! $request->has('ecommerce_api_url')) {
            return;
        }

        $spa_url = ClientEcommerce::normalize_url($request->input('ecommerce_spa_url'));
        $api_url = ClientEcommerce::normalize_url($request->input('ecommerce_api_url'));

        // Si vino solo la URL del SPA, la de la API se completa sola con la
        // convención del hosting ({spa_url}/api) sin que Lucas la tenga que escribir a mano.
        if ($spa_url !== '' && $api_url === '') {
            $api_url = $spa_url.'/api';
        }

        $existing = $client->client_ecommerce()->first();

        if ($spa_url === '' && $api_url === '') {
            // Las dos vinieron vacías: si el cliente no tiene tienda, no se crea una
            // por esto. Si ya la tiene, se limpian las URLs pero se conserva el
            // resto (domain, paths, status) por si se vuelven a cargar después.
            if ($existing) {
                $existing->spa_url = null;
                $existing->api_url = null;
                $existing->save();
            }

            return;
        }

        // Al menos una vino con valor: se crea o reutiliza el ClientEcommerce del cliente.
        $ecommerce = ClientEcommerce::firstOrNew(['client_id' => $client->id]);
        $is_new = ! $ecommerce->exists;

        $ecommerce->spa_url = $spa_url;
        $ecommerce->api_url = $api_url;
        if ($is_new) {
            $ecommerce->status = 'pending';
        }

        // Decisión explícita (22/7/2026, grupo 188): domain se re-deriva de
        // spa_url cada vez que llega una spa_url con valor, incluso pisando el
        // dominio que el cliente haya confirmado por WhatsApp en la etapa 1 de
        // implementación. La URL que se carga acá en el admin es la que
        // realmente quedó instalada y es la que manda. No "arreglar" esto
        // creyendo que es un bug: es a propósito.
        if ($spa_url !== '') {
            $ecommerce->domain = ClientEcommerce::domain_from_url($spa_url);
            // spa_path/api_path se limpian antes de derivar: resolve_spa_path()
            // y resolve_api_path() priorizan la columna si ya tiene valor (para
            // permitir pisar un caso especial a mano en la base), así que si no
            // se limpian acá el path viejo nunca se recalcularía al cambiar el dominio.
            $ecommerce->spa_path = null;
            $ecommerce->api_path = null;
        }
        // Si spa_url vino vacía pero ya había un domain cargado, se conserva (no se borra).

        $ecommerce->spa_path = $ecommerce->resolve_spa_path();
        $ecommerce->api_path = $ecommerce->resolve_api_path();

        $ecommerce->save();
    }

    public function destroy_json($id)
    {
        $client = Client::findOrFail($id);
        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * Sugiere un subdominio corto para un cliente usando Claude Haiku.
     *
     * Recibe { company_name } y delega en SubdomainSuggestionService.
     * La lógica de IA y fallback vive en el servicio; este método solo
     * valida el input y formatea la respuesta.
     *
     * @param  Request                    $request   Debe incluir company_name (string).
     * @param  SubdomainSuggestionService $service   Servicio inyectado por Laravel IoC.
     * @return \Illuminate\Http\JsonResponse         { subdomain: string }
     */
    public function suggest_subdomain_json(Request $request, SubdomainSuggestionService $service)
    {
        /* Nombre de empresa: obligatorio para poder generar el subdominio. */
        $company_name = trim((string) $request->input('company_name', ''));
        if ($company_name === '') {
            return response()->json(['message' => 'El campo company_name es obligatorio.'], 422);
        }

        /* Delegar la sugerencia en el servicio (incluye fallback si Claude falla). */
        $subdomain = $service->suggest($company_name);

        return response()->json(['subdomain' => $subdomain], 200);
    }
}
