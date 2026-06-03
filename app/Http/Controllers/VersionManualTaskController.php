<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionManualTask;
use Illuminate\Http\Request;

class VersionManualTaskController extends Controller
{
    function store(Request $request, $versionId) {
        $version = Version::findOrFail($versionId);
        $task  = VersionManualTask::create([
            'version_id' => $version->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'execution_order' => (int) $request->input('execution_order', 0),
            'is_required' => $request->boolean('is_required', true),
        ]);
        $this->syncRestrictedClientsFromRequest($task, $request);
        return redirect()->route('versions.show', $version->id)->with('success', 'Tarea manual agregada.');
    }

    function update(Request $request, $versionId, $id) {
        $task = VersionManualTask::where('version_id', $versionId)->findOrFail($id);
        $task->update([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'execution_order' => (int) $request->input('execution_order', 0),
            'is_required' => $request->boolean('is_required', false),
        ]);
        $this->syncRestrictedClientsFromRequest($task, $request);
        return redirect()->route('versions.show', $versionId)->with('success', 'Tarea manual actualizada.');
    }

    function destroy($versionId, $id) {
        $task = VersionManualTask::where('version_id', $versionId)->findOrFail($id);
        $task->delete();
        return redirect()->route('versions.show', $versionId)->with('success', 'Tarea manual eliminada.');
    }
}
