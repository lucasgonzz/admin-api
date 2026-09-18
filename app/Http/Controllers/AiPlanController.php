<?php

namespace App\Http\Controllers;

use App\Models\AiPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ABM JSON (Sanctum) de los paquetes de IA de ComercioCity (misión foto-sucursal-y-asistente-
 * configurable, 17/9/2026): el catálogo de planes que después se le asigna a cada cliente y viaja a
 * su instancia.
 *
 * El CRUD del catálogo vive acá; la asignación de un paquete a un cliente y el push a su instancia
 * (que dispara `ClientAiPlanSyncService`) se agregan como métodos por-cliente en esta misma clase.
 *
 * 🔴 **El borrado es LÓGICO, no físico** (ver `destroy_json`): un paquete puede estar asignado a
 * clientes (`clients.ai_plan_id`) y no hay FK física que lo impida, así que un DELETE real dejaría
 * clientes apuntando a un id que ya no existe. La baja lógica lo saca del ABM y del selector, pero
 * lo conserva para los clientes que lo tengan.
 */
class AiPlanController extends Controller
{
    /**
     * Lista TODOS los paquetes, activos e inactivos, para el ABM.
     *
     * Se devuelven también los inactivos a propósito: el ABM tiene que poder verlos y reactivarlos.
     * El selector de la ficha del cliente filtra por `activo` del lado del front. Orden por `orden`
     * y, a igual orden, por nombre, que es como se presentan.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json()
    {
        $planes = AiPlan::query()
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return response()->json(['ai_plans' => $planes]);
    }

    /**
     * Crea un paquete.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $datos = $this->validar($request);

        $plan = AiPlan::create($datos);

        return response()->json(['ai_plan' => $plan], 201);
    }

    /**
     * Edita un paquete.
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $plan = AiPlan::findOrFail($id);

        $datos = $this->validar($request, (int) $plan->id);

        $plan->update($datos);

        return response()->json(['ai_plan' => $plan]);
    }

    /**
     * Baja LÓGICA de un paquete: lo deja `activo = false` (ver el docblock de la clase).
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $plan = AiPlan::findOrFail($id);

        $plan->activo = false;
        $plan->save();

        return response()->json(['ai_plan' => $plan]);
    }

    /**
     * Valida el cuerpo de un alta/edición.
     *
     * El `nombre` es único (es lo que hace idempotente al seeder y lo que evita dos "Básico"): en la
     * edición se ignora el propio id. Los dos topes son nullable —null o 0 = sin tope— y enteros.
     *
     * @param Request  $request
     * @param int|null $ignorar_id Id a excluir de la regla `unique` en la edición.
     *
     * @return array<string, mixed>
     */
    private function validar(Request $request, $ignorar_id = null)
    {
        $regla_nombre = Rule::unique('ai_plans', 'nombre');

        if ($ignorar_id !== null) {
            $regla_nombre = $regla_nombre->ignore($ignorar_id);
        }

        return $request->validate([
            'nombre'                     => ['required', 'string', 'max:255', $regla_nombre],
            'precio_usd'                 => ['required', 'numeric', 'min:0'],
            'tope_tokens_mensual'        => ['nullable', 'integer', 'min:0'],
            'tope_interacciones_diarias' => ['nullable', 'integer', 'min:0'],
            'activo'                     => ['boolean'],
            'orden'                      => ['nullable', 'integer'],
        ]);
    }
}
