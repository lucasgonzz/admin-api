<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Services\ClientContratoService;
use App\Services\LicenciaCuotaService;
use Illuminate\Http\Request;

/**
 * Las cuotas de la licencia de un cliente (misión modulo-cobranzas, 18/9/2026): la pestaña
 * Licencias del modal del cliente. Alta, edición, pago (entero o parcial), baja y la generación
 * desde el contrato. La lógica vive en `LicenciaCuotaService` y `ClientContratoService`.
 *
 * Toda respuesta que cambia algo devuelve la lista completa y el resumen: la pestaña se redibuja
 * entera y no tiene que reconciliar una fila.
 */
class ClientLicenciaController extends Controller
{
    /**
     * Regla de validación de un mes 'YYYY-MM'.
     */
    const REGLA_PERIODO = 'regex:/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Las cuotas del cliente (por número), el resumen por moneda y lo que dice el contrato de la
     * licencia (para el botón "Generar cuotas desde el contrato" y el encabezado).
     *
     * @param  int|string           $clientId
     * @param  LicenciaCuotaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json($clientId, LicenciaCuotaService $service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json(array_merge($this->lista_y_resumen($client, $service), [
            'contrato' => [
                'precio_licencia'         => $client->contract_precio_licencia,
                'currency'                => $client->contract_currency,
                'financiacion'            => is_array($client->contract_financiacion) ? $client->contract_financiacion : [],
                'fecha_primer_pago_unico' => $client->contract_fecha_primer_pago_unico
                    ? $client->contract_fecha_primer_pago_unico->format('Y-m-d')
                    : null,
            ],
        ]));
    }

    /**
     * Alta de una cuota.
     *
     * @param  Request              $request  {monto, moneda?, vencimiento?, periodo?, observacion?}
     * @param  int|string           $clientId
     * @param  LicenciaCuotaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, $clientId, LicenciaCuotaService $service)
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate([
            'monto'       => ['required', 'numeric', 'min:0'],
            'moneda'      => ['nullable', 'string', 'in:' . implode(',', LicenciaCuota::MONEDAS)],
            'vencimiento' => ['nullable', 'date'],
            'periodo'     => ['nullable', 'string', self::REGLA_PERIODO],
            'numero'      => ['nullable', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string'],
        ]);

        $cuota = $service->crear($client, $validated);

        return response()->json(array_merge(
            ['cuota' => $service->cuota_para_json($cuota)],
            $this->lista_y_resumen($client, $service)
        ), 201);
    }

    /**
     * Edición de una cuota (datos pactados y, opcionalmente, el estado a mano).
     *
     * @param  Request              $request  {monto?, moneda?, vencimiento?, periodo?, numero?, observacion?, estado?}
     * @param  int|string           $clientId
     * @param  int|string           $cuotaId
     * @param  LicenciaCuotaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, $cuotaId, LicenciaCuotaService $service)
    {
        $client = Client::findOrFail($clientId);
        $cuota = LicenciaCuota::where('client_id', $client->id)->findOrFail($cuotaId);

        $validated = $request->validate([
            'monto'       => ['nullable', 'numeric', 'min:0'],
            'moneda'      => ['nullable', 'string', 'in:' . implode(',', LicenciaCuota::MONEDAS)],
            'vencimiento' => ['nullable', 'date'],
            'periodo'     => ['nullable', 'string', self::REGLA_PERIODO],
            'numero'      => ['nullable', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string'],
            'estado'      => ['nullable', 'string', 'in:' . implode(',', LicenciaCuota::ESTADOS)],
        ]);

        $cuota = $service->editar($cuota, $validated);

        return response()->json(array_merge(
            ['cuota' => $service->cuota_para_json($cuota)],
            $this->lista_y_resumen($client, $service)
        ));
    }

    /**
     * Registra un pago sobre la cuota (se acumula sobre lo ya pagado).
     *
     * @param  Request              $request  {monto_pagado, fecha_pago?, observacion?, completa?}
     * @param  int|string           $clientId
     * @param  int|string           $cuotaId
     * @param  LicenciaCuotaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function registrar_pago_json(Request $request, $clientId, $cuotaId, LicenciaCuotaService $service)
    {
        $client = Client::findOrFail($clientId);
        $cuota = LicenciaCuota::where('client_id', $client->id)->findOrFail($cuotaId);

        $validated = $request->validate([
            'monto_pagado' => ['required', 'numeric', 'min:0'],
            'fecha_pago'   => ['nullable', 'date'],
            'observacion'  => ['nullable', 'string'],
            'completa'     => ['nullable', 'boolean'],
        ]);

        $cuota = $service->registrar_pago(
            $cuota,
            (float) $validated['monto_pagado'],
            $validated['fecha_pago'] ?? null,
            /* Null o vacío = "sin nota nueva", no "borrá la nota": el form de pago del SPA manda null cuando
             * el operador no escribe nada, y la cuota puede traer la observación importada de la planilla
             * ("PAGO $436.000 restan $500.000"). Para borrar una nota está Editar. */
            isset($validated['observacion']) && trim((string) $validated['observacion']) !== '' ? (string) $validated['observacion'] : null,
            ! empty($validated['completa'])
        );

        return response()->json(array_merge(
            ['cuota' => $service->cuota_para_json($cuota)],
            $this->lista_y_resumen($client, $service)
        ));
    }

    /**
     * Baja de una cuota.
     *
     * @param  int|string           $clientId
     * @param  int|string           $cuotaId
     * @param  LicenciaCuotaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($clientId, $cuotaId, LicenciaCuotaService $service)
    {
        $client = Client::findOrFail($clientId);
        $cuota = LicenciaCuota::where('client_id', $client->id)->findOrFail($cuotaId);

        $service->eliminar($cuota);

        return response()->json($this->lista_y_resumen($client, $service));
    }

    /**
     * Genera las cuotas desde el contrato del cliente. 422 si ya tiene cuotas (importadas o
     * cargadas a mano): lo que hay es la verdad y no se duplica.
     *
     * @param  int|string            $clientId
     * @param  ClientContratoService $contrato_service
     * @param  LicenciaCuotaService  $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function desde_contrato_json($clientId, ClientContratoService $contrato_service, LicenciaCuotaService $service)
    {
        $client = Client::findOrFail($clientId);

        if (LicenciaCuota::where('client_id', $client->id)->exists()) {
            return response()->json([
                'message' => 'Este cliente ya tiene cuotas cargadas. Borralas antes de volver a generarlas desde el contrato.',
            ], 422);
        }

        $creadas = $contrato_service->generar_cuotas_desde_contrato($client);

        if ($creadas === 0) {
            return response()->json([
                'message' => 'El contrato de este cliente no tiene financiación ni precio de licencia: no hay cuotas que generar.',
            ], 422);
        }

        return response()->json(array_merge(
            ['creadas' => $creadas],
            $this->lista_y_resumen($client, $service)
        ));
    }

    /**
     * @param Client               $client
     * @param LicenciaCuotaService $service
     *
     * @return array{cuotas: array, resumen: array}
     */
    private function lista_y_resumen(Client $client, LicenciaCuotaService $service): array
    {
        return [
            'cuotas'  => $service->cuotas($client),
            'resumen' => $service->resumen($client),
        ];
    }
}
