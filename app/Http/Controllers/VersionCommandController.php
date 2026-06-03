<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionCommand;
use Illuminate\Http\Request;

class VersionCommandController extends Controller
{
    function store(Request $request, $versionId) {
        $version = Version::findOrFail($versionId);
        $nextOrder = (int) (VersionCommand::query()
            ->where('version_id', $version->id)
            ->max('execution_order') ?? -1) + 1;
        $command = VersionCommand::create([
            'version_id' => $version->id,
            'command' => $request->input('command'),
            'description' => $request->input('description'),
            'execution_order' => $nextOrder,
            'is_required' => $request->boolean('is_required', true),
            'run_manually' => $request->boolean('run_manually', false),
            /* Default per_user si no se envía run_scope desde el formulario Blade */
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_user'),
        ]);
        $this->syncRestrictedClientsFromRequest($command, $request);
        return redirect(route('versions.show', $version->id) . '#tab-commands')->with('success', 'Comando agregado.');
    }

    function update(Request $request, $versionId, $id) {
        $command = VersionCommand::where('version_id', $versionId)->findOrFail($id);
        $command->update([
            'command' => $request->input('command'),
            'description' => $request->input('description'),
            'execution_order' => (int) $request->input('execution_order', 0),
            'is_required' => $request->boolean('is_required', false),
            'run_manually' => $request->boolean('run_manually', false),
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_user'),
        ]);
        $this->syncRestrictedClientsFromRequest($command, $request);
        return redirect(route('versions.show', $versionId) . '#tab-commands')->with('success', 'Comando actualizado.');
    }

    function destroy($versionId, $id) {
        $command = VersionCommand::where('version_id', $versionId)->findOrFail($id);
        $command->delete();
        return redirect(route('versions.show', $versionId) . '#tab-commands')->with('success', 'Comando eliminado.');
    }
}
