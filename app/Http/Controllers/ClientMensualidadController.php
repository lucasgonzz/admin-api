<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Afip\AfipFacturacionService;
use App\Services\ClientMensualidadService;
use Illuminate\Http\Request;

/**
 * API JSON para consultar y actualizar la mensualidad de un Client desde admin
 * (prompt 329), y para emitir su Factura C contra AFIP/WSFE (prompt 331). El
 * cálculo del total es autónomo: no llama a la empresa-api del cliente (esa
 * sincronización opcional vive aparte en el prompt 335). Toda la lógica de
 * cálculo vive en ClientMensualidadService y toda la lógica de facturación
 * vive en AfipFacturacionService; este controller solo valida el request y
 * arma la respuesta.
 */
class ClientMensualidadController extends Controller
{
    /**
     * Devuelve el snapshot de mensualidad de un cliente (inputs + total
     * calculado + datos fiscales + desglose por línea).
     *
     * @param  int|string               $clientId
     * @param  ClientMensualidadService $service   Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($clientId, ClientMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($service->estado($client));
    }

    /**
     * Actualiza los inputs de mensualidad de un cliente, recalcula el total
     * con la misma fórmula que empresa-api y devuelve el snapshot actualizado.
     *
     * @param  Request                 $request
     * @param  int|string               $clientId
     * @param  ClientMensualidadService $service   Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, ClientMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        // Validación acotada de los inputs de mensualidad y datos fiscales del receptor.
        $validated = $request->validate([
            'precio_plan' => ['required', 'numeric', 'min:0'],
            'precio_por_cuenta' => ['required', 'numeric', 'min:0'],
            'cantidad_empleados' => ['required', 'integer', 'min:0'],
            'tiene_ecommerce' => ['boolean'],
            'tiene_mercado_libre' => ['boolean'],
            'tiene_tienda_nube' => ['boolean'],
            'precio_ecommerce' => ['nullable', 'numeric', 'min:0'],
            'precio_mercado_libre' => ['nullable', 'numeric', 'min:0'],
            'precio_tienda_nube' => ['nullable', 'numeric', 'min:0'],
            'payment_expired_at' => ['nullable', 'date'],
            'afip_cuit' => ['nullable', 'string'],
            'afip_razon_social' => ['nullable', 'string'],
            'afip_condicion_iva' => ['nullable', 'string'],
            'afip_domicilio' => ['nullable', 'string'],
        ]);

        $service->guardar($client, $validated);

        return response()->json($service->estado($client));
    }

    /**
     * Emite la Factura C de la mensualidad de un cliente contra AFIP (WSFE)
     * para el período indicado (prompt 331). Si el período ya fue autorizado
     * anteriormente, no vuelve a emitir: devuelve el registro existente con
     * `ya_facturado = true`.
     *
     * @param  Request                $request
     * @param  int|string             $clientId
     * @param  AfipFacturacionService $service   Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function emitir_factura_json(Request $request, $clientId, AfipFacturacionService $service)
    {
        $client = Client::findOrFail($clientId);

        // Período a facturar: por default, el mes/año actual ('YYYY-MM').
        $periodo = $request->input('periodo', date('Y-m'));

        $resultado = $service->emitir($client, $periodo);

        return response()->json($resultado);
    }
}
