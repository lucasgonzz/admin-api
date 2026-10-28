<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Http\Request;

/**
 * Las ventas de un cliente (misión ventas-y-comisiones, 28/10/2026): la pestaña Ventas del modal
 * del cliente, que reemplaza a la pestaña Licencias. Alta (con sus cuotas), edición, baja y agregar
 * una cuota a una venta. La gestión de cada cuota (pago, edición, baja) sigue por las rutas de
 * licencias de siempre, que ahora también mantienen el total de la venta.
 *
 * Toda la lógica vive en `VentaService`; acá se valida la forma del request, se verifica que la
 * venta sea del cliente de la ruta (404 si no) y se devuelve la lista entera: la pestaña se redibuja
 * completa y no tiene que reconciliar una fila.
 *
 * Los errores de negocio (`ValidationException` del servicio) salen como el 422 estándar de
 * Laravel, `{message, errors}`, igual que el resto de las rutas del panel.
 */
class ClientVentaController extends Controller
{
    /**
     * Regla de validación de un mes 'YYYY-MM'.
     */
    const REGLA_PERIODO = 'regex:/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Las ventas del cliente con sus cuotas y comisión, las cuotas sueltas (sin venta), los
     * vendedores, los productos con su etiqueta, los precios de lista y los defaults del formulario.
     *
     * @param  int|string   $clientId
     * @param  VentaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json($clientId, VentaService $service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($service->lista_del_cliente($client));
    }

    /**
     * Alta de una venta con sus cuotas (`origen = manual`). Devuelve 201 con la lista entera y,
     * además, la venta creada en `venta`.
     *
     * @param  Request      $request  Ver `VentaService::armar()`.
     * @param  int|string   $clientId
     * @param  VentaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, $clientId, VentaService $service)
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate(VentaService::reglas_de_alta());

        $venta = $service->crear($client, $validated, $request->user(), Venta::ORIGEN_MANUAL);

        return response()->json(array_merge(
            ['venta' => $service->venta_para_json($venta)],
            $service->lista_del_cliente($client)
        ), 201);
    }

    /**
     * Edición de los datos de una venta (no de sus cuotas). Recalcula la comisión.
     *
     * @param  Request      $request  {productos?, descripcion?, fecha?, moneda?, vendedor_admin_id?, comision_porcentaje?, observacion?}
     * @param  int|string   $clientId
     * @param  int|string   $ventaId
     * @param  VentaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, $ventaId, VentaService $service)
    {
        $client = Client::findOrFail($clientId);
        $venta = $this->venta_del_cliente($client, $ventaId);

        $validated = $request->validate(VentaService::reglas_de_edicion());

        $venta = $service->editar($venta, $validated);

        return response()->json(array_merge(
            ['venta' => $service->venta_para_json($venta)],
            $service->lista_del_cliente($client)
        ));
    }

    /**
     * Baja de una venta con sus cuotas. 422 si ya tiene pagos de comisión registrados.
     *
     * @param  int|string   $clientId
     * @param  int|string   $ventaId
     * @param  VentaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($clientId, $ventaId, VentaService $service)
    {
        $client = Client::findOrFail($clientId);
        $venta = $this->venta_del_cliente($client, $ventaId);

        $service->eliminar($venta);

        return response()->json($service->lista_del_cliente($client));
    }

    /**
     * Agrega una cuota a la venta: se suma al total de la venta y la comisión se recalcula. La
     * moneda es la de la venta si no viene otra.
     *
     * @param  Request      $request  {monto, vencimiento?, periodo?, observacion?, moneda?}
     * @param  int|string   $clientId
     * @param  int|string   $ventaId
     * @param  VentaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_cuota_json(Request $request, $clientId, $ventaId, VentaService $service)
    {
        $client = Client::findOrFail($clientId);
        $venta = $this->venta_del_cliente($client, $ventaId);

        $validated = $request->validate([
            'monto'       => ['required', 'numeric', 'min:0', 'max:999999999'],
            'moneda'      => ['nullable', 'string', 'in:USD,ARS'],
            'vencimiento' => ['nullable', 'date'],
            'periodo'     => ['nullable', 'string', self::REGLA_PERIODO],
            'observacion' => ['nullable', 'string'],
        ]);

        $venta = $service->agregar_cuota($venta, $validated);

        return response()->json(array_merge(
            ['venta' => $service->venta_para_json($venta)],
            $service->lista_del_cliente($client)
        ), 201);
    }

    /**
     * La venta de la ruta, verificando que sea del cliente de la ruta: una venta de otro cliente es
     * 404, igual que una que no existe.
     *
     * @param Client     $client
     * @param int|string $ventaId
     *
     * @return Venta
     */
    private function venta_del_cliente(Client $client, $ventaId): Venta
    {
        return Venta::where('client_id', $client->id)->findOrFail($ventaId);
    }
}
