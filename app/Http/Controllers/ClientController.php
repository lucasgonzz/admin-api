<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Client;
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

        return response()->json(['model' => $this->fullModel('client', $id)], 200);
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
