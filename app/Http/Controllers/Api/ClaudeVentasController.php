<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Las ventas de un cliente desde Claude/ChatGPT (misión ventas-y-comisiones, 28/10/2026):
 *
 *  - `GET claude/clients/{id}/ventas` — exactamente el mismo JSON que la pestaña Ventas del admin
 *    (`GET client/{id}/ventas`): ventas con cuotas y comisión, cuotas sueltas, vendedores,
 *    productos, precios y los defaults del formulario.
 *  - `POST claude/clients/{id}/ventas` — el alta, con el mismo payload que el admin y los frenos del
 *    bloque `claude/*` (el patrón de `ClaudeClientOpsController::update_schedule_json`):
 *      1. `dry_run` por defecto true: devuelve la venta que se crearía —con sus cuotas y su
 *         comisión, armada por el MISMO `VentaService::armar()` que después guarda— sin escribir.
 *      2. Con `dry_run=false`, `confirm_client_name` tiene que coincidir con el nombre del cliente
 *         (recorte y minúsculas); el error no revela el nombre correcto.
 *    La venta nace con `origen = claude`.
 *
 * 🔴 Escribe plata en la cuenta de un cliente real (lo que debe y la comisión del vendedor), pero
 * no toca el sistema de ningún negocio ni le manda nada a nadie: peligrosidad `baja` en el catálogo.
 *
 * Los errores salen con la forma única del bloque: `{"error": "..."}` con 404 o 422.
 */
class ClaudeVentasController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Mensajes de validación en español para las reglas del alta que no cubre el trait
     * (`numeric`, `distinct`, `required_with`): sin esto, esas tres contestarían en inglés.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required'      => 'El parámetro :attribute es obligatorio.',
            'required_with' => 'El parámetro :attribute es obligatorio cuando viene :values.',
            'exists'        => 'El :attribute que mandaste no existe.',
            'array'         => 'El parámetro :attribute tiene que ser una lista.',
            'date'          => 'El parámetro :attribute tiene que ser una fecha válida.',
            'integer'       => 'El parámetro :attribute tiene que ser un número entero.',
            'numeric'       => 'El parámetro :attribute tiene que ser un número.',
            'boolean'       => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'        => 'El parámetro :attribute tiene que ser texto.',
            'distinct'      => 'El parámetro :attribute está repetido.',
            'in'            => 'El parámetro :attribute tiene un valor que no está permitido. Mirá '
                . $this->ayuda_del_schema() . ' para ver los válidos.',
            'min'           => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'           => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Las ventas del cliente: el mismo JSON que `GET client/{id}/ventas`.
     *
     * @param int|string   $id
     * @param VentaService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json($id, VentaService $service)
    {
        $client = Client::find((int) $id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        return response()->json($service->lista_del_cliente($client));
    }

    /**
     * Alta de una venta, con dry_run por defecto y confirm_client_name para escribir.
     *
     * @param Request      $request El payload de `VentaService::armar()` + `dry_run?` + `confirm_client_name?`.
     * @param int|string   $id
     * @param VentaService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, $id, VentaService $service)
    {
        $client = Client::find((int) $id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        /* La forma: las mismas reglas que el alta del admin, más los dos frenos. `confirm_client_name`
           es nullable acá y obligatorio más abajo, cuando dry_run es false: un dry-run tiene que poder
           previsualizar sin saber el nombre todavía. */
        $invalido = $this->validar_o_422($request, array_merge(VentaService::reglas_de_alta(), [
            'dry_run'             => ['nullable', 'boolean'],
            'confirm_client_name' => ['nullable', 'string', 'max:190'],
        ]));
        if ($invalido !== null) {
            return $invalido;
        }

        /* Solo las claves del alta (sin los frenos), con la misma semántica que en el admin: una
           clave presente con null es "null explícito" (p. ej. vendedor_admin_id = sin vendedor). */
        $datos = [];
        foreach (array_keys(VentaService::reglas_de_alta()) as $clave) {
            if (strpos($clave, '.') === false && $request->exists($clave)) {
                $datos[$clave] = $request->input($clave);
            }
        }

        /* Las reglas de negocio (suma de cuotas, importe de desarrollo/otro) las aplica el servicio
           tanto en el dry_run como en el real: un dry_run que pasa predice un alta que pasa. */
        try {
            $venta = $service->armar($client, $datos, null, Venta::ORIGEN_CLAUDE);
        } catch (ValidationException $e) {
            return $this->error_422('La venta no cierra. No se escribió nada.', [
                'client_id' => (int) $client->id,
                'detalles'  => $e->validator->errors()->all(),
            ]);
        }

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'  => true,
                'escribio' => false,
                'client'   => [
                    'id'   => (int) $client->id,
                    'uuid' => (string) $client->uuid,
                    'name' => (string) $client->name,
                ],
                'venta'    => $service->venta_para_json($venta),
                'nota'     => 'No se escribió NADA. Esta es la venta que se crearía, con sus cuotas y la comisión del '
                    . 'vendedor. Para crearla de verdad, repetí la misma llamada con dry_run=false y '
                    . 'confirm_client_name con el nombre exacto del cliente.',
            ], 200);
        }

        /* Freno 2: el nombre. Con `dry_run=false` ya no es opcional. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        try {
            $venta = $service->crear($client, $datos, null, Venta::ORIGEN_CLAUDE);
        } catch (ValidationException $e) {
            return $this->error_422('La venta no cierra. No se escribió nada.', [
                'client_id' => (int) $client->id,
                'detalles'  => $e->validator->errors()->all(),
            ]);
        }

        return response()->json(array_merge([
            'dry_run'  => false,
            'escribio' => true,
            'venta'    => $service->venta_para_json($venta),
        ], $service->lista_del_cliente($client)), 201);
    }
}
