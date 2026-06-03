<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionNotification;
use Illuminate\Http\Request;

class VersionNotificationController extends Controller
{
    function store(Request $request, $versionId) {
        $version = Version::findOrFail($versionId);
        $n = VersionNotification::create([
            'version_id' => $version->id,
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);
        $this->syncRestrictedClientsFromRequest($n, $request);
        return redirect()->route('versions.show', $version->id)->with('success', 'Notificación agregada.');
    }

    function update(Request $request, $versionId, $id) {
        $notification = VersionNotification::where('version_id', $versionId)->findOrFail($id);
        $notification->update([
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_active' => $request->boolean('is_active', false),
        ]);
        $this->syncRestrictedClientsFromRequest($notification, $request);
        return redirect()->route('versions.show', $versionId)->with('success', 'Notificación actualizada.');
    }

    function destroy($versionId, $id) {
        $notification = VersionNotification::where('version_id', $versionId)->findOrFail($id);
        $notification->delete();
        return redirect()->route('versions.show', $versionId)->with('success', 'Notificación eliminada.');
    }
}
