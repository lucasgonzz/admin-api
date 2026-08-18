<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Client;
use App\Models\Version;
use App\Services\VersionNestedJsonSync;
use App\Services\VersionNumberComparator;
use Illuminate\Http\Request;

class VersionController extends BaseController
{
    function index() {
        $versions = Version::orderBy('id', 'desc')->withCount('notifications', 'seeders', 'commands', 'manual_tasks')->get();
        return view('versions.index', compact('versions'));
    }

    function create() {
        return view('versions.create');
    }

    function store(Request $request) {
        $this->validate_version_payload($request, true);
        // Alta: `is_hotfix` siempre se autocalcula, sin override posible.
        $data = $this->extract_data($request, false);
        $version = Version::create($data);
        return redirect()->route('versions.show', $version->id)
                         ->with('success', 'Versión creada.');
    }

    function show($id) {
        $version = Version::withAll()->findOrFail($id);
        $clients = Client::where('is_active', true)->orderBy('name')->get();

        return view('versions.show', compact('version', 'clients'));
    }

    function edit($id) {
        $version = Version::findOrFail($id);
        return view('versions.edit', compact('version'));
    }

    function update(Request $request, $id) {
        $version = Version::findOrFail($id);
        $this->validate_version_payload($request, true, $version);
        // Edición: el admin puede forzar `is_hotfix` con el checkbox del form.
        $data = $this->extract_data($request, true);
        $version->update($data);
        return redirect()->route('versions.show', $version->id)
                         ->with('success', 'Versión actualizada.');
    }

    function destroy($id) {
        $version = Version::findOrFail($id);
        $version->delete();
        return redirect()->route('versions.index')
                         ->with('success', 'Versión eliminada.');
    }

    /**
     * @param  Request  $request
     * @param  bool  $allow_override  `true` solo en los caminos de EDICIÓN (ver `resolve_is_hotfix`).
     * @return array<string, mixed>
     */
    protected function extract_data(Request $request, bool $allow_override = false) {
        $data = $request->only('version', 'title', 'description', 'status');
        if ($data['status'] === 'published' && !$request->filled('published_at')) {
            $data['published_at'] = now();
        } elseif ($request->filled('published_at')) {
            $data['published_at'] = $request->input('published_at');
        }
        $data['is_hotfix'] = $this->resolve_is_hotfix($request, $data['version'], $allow_override);
        return $data;
    }

    /**
     * Valida el código de versión en los cuatro caminos de entrada (Blade y JSON).
     * `$required = false` para los caminos de actualización, donde el campo puede no venir.
     *
     * 🔴 Excepción para versiones legacy: si `$version_actual` viene (caminos de EDICIÓN) y
     * el código que llega es IDÉNTICO al ya persistido, no se exige el regex. Si no, un
     * código viejo que no cumple el formato nuevo (por ejemplo "3.3", cargado antes de que
     * existiera esta validación) bloquearía para siempre la edición del título, la
     * descripción o el estado de esa versión, aunque nadie esté tocando el código. Si el
     * admin SÍ cambia el código, el regex estricto se aplica igual que siempre.
     *
     * @param  Request  $request
     * @param  bool  $required
     * @param  Version|null  $version_actual  Fila ya persistida, solo en edición.
     * @return void
     */
    protected function validate_version_payload(Request $request, bool $required = true, ?Version $version_actual = null): void
    {
        $regla_base = ['string', 'max:30'];

        $sin_cambios = $version_actual !== null
            && $request->has('version')
            && (string) $request->input('version') === (string) $version_actual->version;

        if (! $sin_cambios) {
            $regla_base[] = 'regex:' . VersionNumberComparator::VALID_REGEX;
        }

        $request->validate(
            ['version' => array_merge($required ? ['required'] : ['sometimes', 'required'], $regla_base)],
            ['version.regex' => 'El código de versión debe tener al menos 3 componentes numéricos separados por puntos (ej. 3.3.1 o 3.3.1.2).']
        );
    }

    /**
     * Resuelve `is_hotfix`.
     *
     * 🔴 El override manual SOLO existe en los caminos de EDICIÓN (`$allow_override = true`).
     * En el ALTA se autocalcula siempre y se ignora cualquier `is_hotfix` que venga en el
     * request: el modal genérico de creación del SPA (`common-vue`) inicializa el draft con
     * TODAS las propiedades declaradas y su valor por defecto, así que el POST de alta
     * siempre trae `is_hotfix` en `false`. Decidir por presencia de la clave hacía que toda
     * versión creada desde el SPA quedara en `is_hotfix = false` sin importar el código.
     * En el alta no hay checkbox en ninguna de las dos interfaces, así que no hay override
     * que perder.
     *
     * @param  Request  $request
     * @param  string  $version_code
     * @param  bool  $allow_override
     * @return bool
     */
    protected function resolve_is_hotfix(Request $request, string $version_code, bool $allow_override = false): bool
    {
        if ($allow_override && $request->has('is_hotfix')) {
            return $request->boolean('is_hotfix');
        }
        return VersionNumberComparator::isHotfix($version_code);
    }

    // --- API JSON (admin-spa) ---

    /**
     * Listado paginado o completo.
     *
     * Query `for_select=1`: respuesta liviana (id, version, title, status) para selects
     * del SPA (p. ej. current_version_id en Client) sin eager load de seeders/comandos.
     */
    public function index_json(Request $request)
    {
        $per = (int) $request->input('per_page', 100);
        if ($per < 1) {
            $per = 20;
        }
        if ($per > 200) {
            $per = 200;
        }

        /** Selectores relacionales del SPA: evitar withAll() en el listado completo. */
        $for_select = $request->boolean('for_select');

        if ($for_select) {
            $q = Version::query()
                ->select(['id', 'version', 'title', 'status', 'published_at'])
                ->orderBy('id', 'desc');
        } else {
            $q = Version::query()->withAll()->orderBy('id', 'desc');
        }

        if ($request->has('page')) {
            $models = $q->paginate($per);
        } else {
            $models = $q->get();
        }

        return response()->json(['models' => $models], 200);
    }

    public function show_json($id)
    {
        $m = $this->fullModel('version', $id);
        if (! $m) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $m], 200);
    }

    public function store_json(Request $request)
    {
        $this->validate_version_payload($request, true);
        $data = ModelPropertiesHelper::attributes_for_create($request, 'version');
        if (isset($data['status']) && $data['status'] === 'published' && empty($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }
        // Alta: `is_hotfix` siempre autocalculado, se ignora lo que mande el SPA.
        $data['is_hotfix'] = $this->resolve_is_hotfix($request, $data['version'], false);
        $version = Version::create($data);
        (new VersionNestedJsonSync())->sync_from_request($version, $request);

        return response()->json(['model' => $this->fullModel('version', $version->id)], 201);
    }

    public function update_json(Request $request, $id)
    {
        $version = Version::findOrFail($id);
        $this->validate_version_payload($request, false, $version);
        ModelPropertiesHelper::set_from_request($version, $request, 'version');
        $version->refresh();
        if ($version->status === 'published' && ! $version->published_at) {
            $version->published_at = now();
            $version->save();
        }
        if (! $request->has('is_hotfix')) {
            $version->is_hotfix = VersionNumberComparator::isHotfix($version->version);
            $version->save();
        }
        (new VersionNestedJsonSync())->sync_from_request($version, $request);
        $version = $this->fullModel('version', $id);

        return response()->json(['model' => $version], 200);
    }

    public function destroy_json($id)
    {
        $version = Version::findOrFail($id);
        $version->delete();

        return response()->json(null, 204);
    }
}
