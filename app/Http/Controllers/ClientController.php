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
     * `ecommerce_spa_url` / `ecommerce_api_url` / `ecommerce_spa_path` /
     * `ecommerce_api_path` del request (modal de tienda online en admin-spa).
     * No usa ModelPropertiesHelper porque esas claves no son columnas de
     * `clients` sino de la relación `client_ecommerce`.
     *
     * Las dos claves de path son opcionales (misión ecommerce-paths-subcarpeta): describen la
     * carpeta física del hosting donde está instalada la tienda, relativa a `domains/`, para el
     * caso en que no coincida con la derivación por dominio. Vacías = derivación de siempre.
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

        // Paths de instalación cargados a mano en el modal (opcionales, relativos a domains/).
        // Se distingue "la clave vino" de "la clave vino vacía": vacía = volver a derivar;
        // ausente = este flujo no los administra y no se tocan.
        $has_spa_path_key = $request->has('ecommerce_spa_path');
        $has_api_path_key = $request->has('ecommerce_api_path');
        $spa_path_input   = $has_spa_path_key
            ? ClientEcommerce::normalize_hosting_path($request->input('ecommerce_spa_path'))
            : '';
        $api_path_input   = $has_api_path_key
            ? ClientEcommerce::normalize_hosting_path($request->input('ecommerce_api_path'))
            : '';

        // Si vino solo la URL del SPA, la de la API se completa sola con la
        // convención del hosting ({spa_url}/api) sin que Lucas la tenga que escribir a mano.
        if ($spa_url !== '' && $api_url === '') {
            $api_url = $spa_url.'/api';
        }

        $existing = $client->client_ecommerce()->first();

        // Un path de instalación con valor alcanza para que este guardado cuente como "del modal
        // de tienda", aunque las dos URLs vengan vacías.
        //
        // POR QUÉ (defecto encontrado en el chequeo independiente de la misión
        // ecommerce-paths-subcarpeta): el `return` de abajo se ejecutaba ANTES de los bloques que
        // aplican los paths, así que a un cliente nuevo al que se le cargaban SOLO los dos paths
        // se le perdían en silencio, sin ningún error. Y el hint del modal invitaba explícitamente
        // a hacer eso ("Cargá la URL de la tienda o los paths de instalación").
        $hay_path_cargado = $spa_path_input !== '' || $api_path_input !== '';

        if ($spa_url === '' && $api_url === '' && ! $hay_path_cargado) {
            // Las dos vinieron vacías y no hay paths: si el cliente no tiene tienda, no se crea una
            // por esto. Si ya la tiene, se limpian las URLs pero se conserva el
            // resto (domain, paths, status) por si se vuelven a cargar después.
            if ($existing) {
                $existing->spa_url = null;
                $existing->api_url = null;
                $existing->save();
            }

            return;
        }

        // Al menos una URL vino con valor, o vino un path cargado a mano: se crea o reutiliza el
        // ClientEcommerce del cliente.
        $ecommerce = ClientEcommerce::firstOrNew(['client_id' => $client->id]);
        $is_new = ! $ecommerce->exists;

        $ecommerce->spa_url = $spa_url;
        $ecommerce->api_url = $api_url;

        // Guardado que trae solo paths: las URLs se escriben en null, no en cadena vacía, que es
        // exactamente lo que dejaba el bloque de arriba antes de esta corrección. "Sin URL
        // cargada" se representa con null en estas dos columnas y no se cambia por esto.
        if ($spa_url === '' && $api_url === '') {
            $ecommerce->spa_url = null;
            $ecommerce->api_url = null;
        }

        if ($is_new) {
            $ecommerce->status = 'pending';
        }

        if ($spa_url !== '') {
            // 🔴 EL ORDEN DE ESTAS DOS COSAS IMPORTA Y NO ES CASUAL: primero se decide qué paths
            // limpiar y RECIÉN DESPUÉS se pisa `domain`. manual_spa_path() compara lo guardado
            // contra la derivación del dominio ACTUAL (el viejo). Si se pisara `domain` antes, el
            // path derivado del dominio viejo dejaría de coincidir con la derivación del nuevo, el
            // sistema lo tomaría por "cargado a mano" y no se recalcularía nunca más. Si estás
            // reordenando esto para que quede "más prolijo", pará.

            // Decisión explícita (22/7/2026, grupo 188): domain se re-deriva de spa_url cada vez
            // que llega una spa_url con valor, incluso pisando el dominio que el cliente haya
            // confirmado por WhatsApp en la etapa 1 de implementación. La URL que se carga acá en
            // el admin es la que realmente quedó instalada y es la que manda. No "arreglar" esto
            // creyendo que es un bug: es a propósito.
            //
            // Y por eso mismo se limpian los paths antes de derivar: resolve_spa_path() y
            // resolve_api_path() priorizan la columna si ya tiene valor, así que si no se
            // limpiaran acá el path viejo nunca se recalcularía al cambiar el dominio.
            //
            // EXCEPCIÓN (misión ecommerce-paths-subcarpeta): solo se limpia el path que HOY es
            // derivado. Un path cargado a mano describe una carpeta física que no tiene por qué
            // tener relación con el host de la URL pública — es exactamente el caso de una tienda
            // servida desde tienda.comerciocity.store pero instalada en
            // comerciocity.store/public_html/tienda/spa — así que cambiar el dominio no puede
            // moverla de lugar.
            if ($ecommerce->manual_spa_path() === '') {
                $ecommerce->spa_path = null;
            }
            if ($ecommerce->manual_api_path() === '') {
                $ecommerce->api_path = null;
            }

            $ecommerce->domain = ClientEcommerce::domain_from_url($spa_url);
        }
        // Si spa_url vino vacía pero ya había un domain cargado, se conserva (no se borra).

        // Los paths que mandó el modal mandan sobre todo lo anterior, incluida la limpieza de
        // arriba. Clave presente y vacía = el usuario borró el campo = volver a derivar.
        if ($has_spa_path_key) {
            $ecommerce->spa_path = $spa_path_input !== '' ? $spa_path_input : null;
        }
        if ($has_api_path_key) {
            $ecommerce->api_path = $api_path_input !== '' ? $api_path_input : null;
        }

        // La columna guarda siempre el path EFECTIVO (cargado a mano o derivado), igual que antes
        // de esta misión: así se puede mirar la base y ver dónde está instalada cada tienda.
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
