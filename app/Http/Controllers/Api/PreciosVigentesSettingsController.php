<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PreciosVigentesSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Expone GET y PUT de la fuente única de precios vigentes para la pantalla de Cuenta → «Precios vigentes»
 * del admin-spa (misión embudo-whatsapp-precios, etapa E1).
 *
 * Controlador fino: toda la lectura, la validación y la persistencia viven en `PreciosVigentesSettings`,
 * igual que `CotizadorSettingsController` con `CotizadorSettings`. El contrato JSON es el de §2.6 del PLAN.
 *
 * 🔴 No usa `$request->validate()`: las reglas (rangos encadenados, montos, decimales) no se expresan con
 * las reglas de Laravel y tienen que ser las MISMAS para lo que llega por PUT y para lo que se lee de la
 * base, así que las ejecuta el servicio. El 422 sale con el formato de Laravel (`message` + `errors`).
 */
class PreciosVigentesSettingsController extends Controller
{
    /**
     * Devuelve los precios vigentes (el contrato de §2.6, con `actualizado_en` y `actualizado_por`).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(PreciosVigentesSettings::to_array(), 200);
    }

    /**
     * Guarda el contrato entero y devuelve lo guardado. Con un valor inválido responde 422 con un
     * mensaje legible y los errores por campo, y no guarda nada.
     *
     * @param Request $request Cuerpo con el contrato de §2.6 (sin `actualizado_*`, que sella el servidor).
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $guardado = PreciosVigentesSettings::persist_from_request(
                $request->all(),
                $request->user() ? (int) $request->user()->id : null
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => PreciosVigentesSettings::mensaje_de_errores($e->errors()),
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json($guardado, 200);
    }
}
