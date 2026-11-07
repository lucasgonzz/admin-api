<?php

namespace App\Http\Controllers;

use App\Services\DemoChatService;
use App\Services\ManualRepositoryService;
use Illuminate\Http\Request;

class PruebaChatController extends Controller
{
    public function index(Request $request, DemoChatService $service)
    {
        $lead = $request->attributes->get('prueba_chat_lead');

        return response()->json($service->snapshot($lead, false, $lead->promoted_client_id));
    }

    public function manual(Request $request, ManualRepositoryService $manual)
    {
        $datos = $request->validate([
            'capacidad' => ['sometimes', 'required', 'string', 'in:1'],
            'ruta' => ['sometimes', 'required', 'prohibited_if:capacidad,1', 'string', 'max:240',
                'regex:~\Amanual_sistema/(?:[\pL\pN_-][\pL\pN\pM_. -]*/)*[\pL\pN_-][\pL\pN\pM_. -]*\.md\z~u'],
        ]);

        if (isset($datos['capacidad'])) {
            return response()->json(['disponible' => true]);
        }

        if (! isset($datos['ruta'])) {
            $indice = $manual->file_list_cacheada();
            if (trim($indice) === '' || $indice === ManualRepositoryService::FALLBACK_LISTA_NO_DISPONIBLE
                || $indice === ManualRepositoryService::FALLBACK_SIN_ARCHIVOS) {
                return response()->json(['disponible' => false]);
            }

            return response()->json(['disponible' => true, 'indice' => mb_substr($indice, 0, 22000)]);
        }

        try {
            $contenido = $manual->get_file($datos['ruta']);
        } catch (\Exception $e) {
            return response()->json(['disponible' => false]);
        }

        if (trim($contenido) === '') {
            return response()->json(['disponible' => false]);
        }

        return response()->json(['disponible' => true, 'contenido' => mb_substr($contenido, 0, 18000)]);
    }

    public function iniciar(Request $request, DemoChatService $service)
    {
        $lead = $request->attributes->get('prueba_chat_lead');
        $service->iniciar($lead, $lead->promoted_client_id);

        return response()->json($service->snapshot($lead, false, $lead->promoted_client_id));
    }

    public function mensaje(Request $request, DemoChatService $service)
    {
        $datos = $request->validate(['contenido' => 'required|string|max:4000', 'cliente_uuid' => 'required|uuid']);
        $lead = $request->attributes->get('prueba_chat_lead');
        $service->recibir($lead, trim($datos['contenido']), $datos['cliente_uuid'], null, $lead->promoted_client_id);

        return response()->json($service->snapshot($lead, false, $lead->promoted_client_id), 202);
    }
}
