<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Client;
use App\Models\Version;
use App\Services\VersionNestedJsonSync;
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
        $data = $this->extract_data($request);
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
        $data = $this->extract_data($request);
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

    protected function extract_data(Request $request) {
        $data = $request->only('version', 'title', 'description', 'status');
        if ($data['status'] === 'published' && !$request->filled('published_at')) {
            $data['published_at'] = now();
        } elseif ($request->filled('published_at')) {
            $data['published_at'] = $request->input('published_at');
        }
        return $data;
    }

    // --- API JSON (admin-spa) ---

    /**
     * Listado paginado o completo.
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
        $q = Version::query()->withAll()->orderBy('id', 'desc');
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
        $data = ModelPropertiesHelper::attributes_for_create($request, 'version');
        if (isset($data['status']) && $data['status'] === 'published' && empty($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }
        $version = Version::create($data);
        (new VersionNestedJsonSync())->sync_from_request($version, $request);

        return response()->json(['model' => $this->fullModel('version', $version->id)], 201);
    }

    public function update_json(Request $request, $id)
    {
        $version = Version::findOrFail($id);
        ModelPropertiesHelper::set_from_request($version, $request, 'version');
        $version->refresh();
        if ($version->status === 'published' && ! $version->published_at) {
            $version->published_at = now();
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
