<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Lead;
use App\Services\ClientContratoService;
use App\Services\CobranzasMensualidadService;
use App\Services\LeadContractPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El contrato de un cliente ya promovido (misión modulo-cobranzas, 18/9/2026): verlo, editarlo y
 * generar el PDF, con las mismas 17 columnas `contract_*` que tiene el lead más los meses entre
 * actualizaciones por IPC.
 *
 * Es la pestaña Contrato del modal del cliente. El PDF sale por el mismo `LeadContractPdfService`
 * del lead, que desde esta misión acepta un `Client`.
 */
class ClientContratoController extends Controller
{
    /**
     * El contrato del cliente, el lead del que vino (si se sabe) y el resumen de la actualización
     * de precios (que depende de `contract_meses_actualizacion`).
     *
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $cobranzas
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($clientId, CobranzasMensualidadService $cobranzas)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($this->respuesta($client, $cobranzas));
    }

    /**
     * Edita el contrato del cliente. Mismas claves que `build_contract_payload()` de la pestaña
     * del lead en admin-spa, más `contract_meses_actualizacion`. Solo toca las claves presentes.
     *
     * @param  Request                     $request
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $cobranzas
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, CobranzasMensualidadService $cobranzas)
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate([
            'contract_client_name'               => ['nullable', 'string', 'max:255'],
            'contract_client_razon_social'       => ['nullable', 'string', 'max:255'],
            'contract_client_cuit'               => ['nullable', 'string', 'max:255'],
            'contract_currency'                  => ['nullable', 'string', 'max:255'],
            'contract_precio_licencia'           => ['nullable', 'string', 'max:255'],
            'contract_fecha_emision'             => ['nullable', 'date'],
            'contract_fecha_primer_pago_unico'   => ['nullable', 'date'],
            'contract_financiacion'              => ['nullable', 'array'],
            'contract_clausulas_particulares'    => ['nullable', 'array'],
            'contract_mensualidad_moneda'        => ['nullable', 'string', 'max:255'],
            'contract_mensualidad_base'          => ['nullable', 'string', 'max:255'],
            'contract_usuarios_incluidos'        => ['nullable', 'integer', 'min:0'],
            'contract_usuarios_extra'            => ['nullable', 'integer', 'min:0'],
            'contract_precio_usuario_extra'      => ['nullable', 'string', 'max:255'],
            'contract_perfiles_ecommerce'        => ['nullable', 'integer', 'min:0'],
            'contract_precio_perfil_ecommerce'   => ['nullable', 'string', 'max:255'],
            'contract_fecha_primer_pago_mensual' => ['nullable', 'date'],
            'contract_meses_actualizacion'       => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        /* Los montos viajan como string (así los guarda el lead: "1.500", "USD 100") y los
         * enteros como número; el `??` no sirve porque un null explícito tiene que borrar. Solo
         * se escriben las claves que vinieron, para que un guardado parcial no vacíe el resto. */
        foreach ($validated as $campo => $valor) {
            $client->{$campo} = $valor;
        }

        $client->save();

        return response()->json($this->respuesta($client->refresh(), $cobranzas));
    }

    /**
     * El PDF del contrato del cliente. Mismo patrón que `LeadController@generate_contract_json`:
     * `incluir_firma` en el body (default true), binario `application/pdf`, y un 422 con el
     * motivo si dompdf no pudo.
     *
     * @param  Request    $request  {incluir_firma?}
     * @param  int|string $clientId
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function pdf(Request $request, $clientId)
    {
        $client = Client::findOrFail($clientId);

        // Si estampa la firma del PRESTADOR en esta generación.
        $incluir_firma = $request->boolean('incluir_firma', true);

        try {
            $pdf_content = LeadContractPdfService::generate($client, $incluir_firma);
        } catch (\Throwable $error) {
            Log::error('ClientContratoController@pdf error: ' . $error->getMessage(), [
                'client_id' => $client->id,
            ]);

            return response()->json([
                'message' => 'No se pudo generar el contrato: ' . $error->getMessage(),
            ], 422);
        }

        return response($pdf_content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="contrato_cliente_' . $client->id . '.pdf"',
        ]);
    }

    /**
     * La respuesta común del GET y del PUT.
     *
     * @param Client                      $client
     * @param CobranzasMensualidadService $cobranzas
     *
     * @return array<string, mixed>
     */
    private function respuesta(Client $client, CobranzasMensualidadService $cobranzas): array
    {
        $contrato = [];
        foreach (ClientContratoService::CAMPOS_CONTRATO as $campo) {
            $valor = $client->{$campo};

            // Las fechas van como 'YYYY-MM-DD' para el input type="date"; el cast las trae como Carbon.
            if ($valor instanceof \DateTimeInterface) {
                $valor = $valor->format('Y-m-d');
            }

            $contrato[$campo] = $valor;
        }

        $contrato['contract_meses_actualizacion'] = $client->contract_meses_actualizacion !== null
            ? (int) $client->contract_meses_actualizacion
            : ClientContratoService::MESES_ACTUALIZACION_DEFAULT;
        $contrato['contract_copiado_desde_lead_at'] = $client->contract_copiado_desde_lead_at
            ? $client->contract_copiado_desde_lead_at->toDateTimeString()
            : null;

        /** El lead del que se promovió este cliente, si existe. */
        $lead_id = Lead::where('promoted_client_id', $client->id)->orderBy('id')->value('id');

        return [
            'contrato'              => $contrato,
            'lead_id'               => $lead_id !== null ? (int) $lead_id : null,
            'resumen_actualizacion' => $cobranzas->resumen_actualizacion($client),
        ];
    }
}
