<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Version;
use App\Services\PublishVersionService;
use Illuminate\Http\Request;

class PublishVersionController extends Controller
{
    public function fromVersion(Request $request, $versionId, PublishVersionService $service)
    {
        $version = Version::with('notifications')->findOrFail($versionId);
        $client = Client::findOrFail($request->input('client_id'));
        $upgrade = $service->publish($client, $version, $request->input('notes'));

        return $this->redirectWithResult($upgrade, route('versions.show', $version->id));
    }

    public function fromClient(Request $request, $clientId, PublishVersionService $service)
    {
        $client = Client::findOrFail($clientId);
        $version = Version::with('notifications')->findOrFail($request->input('version_id'));
        $upgrade = $service->publish($client, $version, $request->input('notes'));

        return $this->redirectWithResult($upgrade, route('clients.show', $client->id));
    }

    protected function redirectWithResult($upgrade, $back)
    {
        if ($upgrade->status === 'success') {
            return redirect($back)->with('success', 'Versión publicada correctamente al cliente.');
        }
        return redirect($back)->with('error', 'No se pudo publicar: ' . $upgrade->notes);
    }
}
