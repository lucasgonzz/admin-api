<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionSeeder;
use Illuminate\Http\Request;

class VersionSeederController extends Controller
{
    function store(Request $request, $versionId) {
        $version = Version::findOrFail($versionId);
        $nextOrder = (int) (VersionSeeder::query()
            ->where('version_id', $version->id)
            ->max('execution_order') ?? -1) + 1;
        $seeder  = VersionSeeder::create([
            'version_id' => $version->id,
            'seeder_class' => $request->input('seeder_class'),
            'description' => $request->input('description'),
            'execution_order' => $nextOrder,
            'is_required' => $request->boolean('is_required', true),
            /* Default per_database si no se envía run_scope desde el formulario Blade */
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_database'),
        ]);
        $this->syncRestrictedClientsFromRequest($seeder, $request);
        return redirect(route('versions.show', $version->id) . '#tab-seeders')->with('success', 'Seeder agregado.');
    }

    function update(Request $request, $versionId, $id) {
        $seeder = VersionSeeder::where('version_id', $versionId)->findOrFail($id);
        $seeder->update([
            'seeder_class' => $request->input('seeder_class'),
            'description' => $request->input('description'),
            'execution_order' => (int) $request->input('execution_order', 0),
            'is_required' => $request->boolean('is_required', false),
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_database'),
        ]);
        $this->syncRestrictedClientsFromRequest($seeder, $request);
        return redirect(route('versions.show', $versionId) . '#tab-seeders')->with('success', 'Seeder actualizado.');
    }

    function destroy($versionId, $id) {
        $seeder = VersionSeeder::where('version_id', $versionId)->findOrFail($id);
        $seeder->delete();
        return redirect(route('versions.show', $versionId) . '#tab-seeders')->with('success', 'Seeder eliminado.');
    }
}
