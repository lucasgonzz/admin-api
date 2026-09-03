<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionSeeder;
use App\Services\VersionItemSanitizer;
use Illuminate\Http\Request;

class VersionSeederController extends Controller
{
    function store(Request $request, $versionId) {
        $version = Version::findOrFail($versionId);
        /* Mismo saneamiento que la ingesta de Claude: la regla vive en un solo lugar. */
        $seeder_class = $this->sanear_seeder_class_o_fallar($request->input('seeder_class'));
        $nextOrder = (int) (VersionSeeder::query()
            ->where('version_id', $version->id)
            ->max('execution_order') ?? -1) + 1;
        $seeder  = VersionSeeder::create([
            'version_id' => $version->id,
            'seeder_class' => $seeder_class,
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
        $seeder_class = $this->sanear_seeder_class_o_fallar($request->input('seeder_class'));
        $seeder->update([
            'seeder_class' => $seeder_class,
            'description' => $request->input('description'),
            'execution_order' => (int) $request->input('execution_order', 0),
            'is_required' => $request->boolean('is_required', false),
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_database'),
        ]);
        $this->syncRestrictedClientsFromRequest($seeder, $request);
        return redirect(route('versions.show', $versionId) . '#tab-seeders')->with('success', 'Seeder actualizado.');
    }

    /**
     * Saca el namespace por defecto del seeder_class, o corta con 422 si no se puede guardar.
     *
     * Un seeder_class con namespace llega al hosting con las barras comidas y muere con
     * "Target class does not exist": es lo que volteo el upgrade 75 de masquito el 3/9/2026.
     *
     * @param  string|null  $valor
     * @return string
     */
    private function sanear_seeder_class_o_fallar($valor): string
    {
        $motivo = VersionItemSanitizer::motivo_de_rechazo_de_seeder($valor);
        if ($motivo !== null) {
            abort(422, $motivo);
        }

        return VersionItemSanitizer::sanear_seeder_class($valor);
    }

    function destroy($versionId, $id) {
        $seeder = VersionSeeder::where('version_id', $versionId)->findOrFail($id);
        $seeder->delete();
        return redirect(route('versions.show', $versionId) . '#tab-seeders')->with('success', 'Seeder eliminado.');
    }
}
