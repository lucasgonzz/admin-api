<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service responsable de disparar DemoSetupHelper::run en la ERP API
 * de la demo asignada al Lead, vía el endpoint admin-sync/demo-setup.
 *
 * Mismo patrón de llamada HTTP que PublishVersionService para mantener
 * consistencia en admin-api (timeout/retries configurables por services.php,
 * sin autenticación adicional para este flujo, logging en caso de excepción).
 */
class RunDemoSetupService
{
    /**
     * Ejecuta la demo remotamente y actualiza los campos de trazabilidad
     * del Lead (demo_setup_status / demo_setup_last_error / demo_setup_last_run_at).
     *
     * @param Lead $lead Prospecto con demo seteada
     *
     * @return Lead El mismo Lead refrescado
     */
    public function run(Lead $lead)
    {
        $lead->loadMissing('demo');
        $demo = $lead->demo;

        // Precondición: debe existir demo asignada para tomar su ERP API.
        if (is_null($demo)) {
            return $this->mark_failed($lead, 'El lead no tiene demo asignada.');
        }

        /**
         * URL de ERP API de la demo asignada al lead.
         * Se normaliza para evitar doble slash al concatenar path.
         */
        $erp_api_url = rtrim((string) $demo->erp_api_url, '/');
        if ($erp_api_url === '') {
            return $this->mark_failed($lead, 'La demo asignada no tiene ERP API URL configurada.');
        }

        // Marcamos el arranque para que el panel muestre el intento en curso
        $lead->update([
            'demo_setup_status' => 'ejecutandose',
            'demo_setup_last_run_at' => now(),
            'demo_setup_last_error' => null,
        ]);

        $payload = $this->build_payload($lead);

        try {
            $response = Http::withHeaders([
                    'Accept'          => 'application/json',
                ])
                // El timeout default es bajo; el setup puede tardar minutos entre migraciones y seeders
                ->timeout((int) config('services.client_api.timeout', 15) * 20)
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($erp_api_url . '/api/admin-sync/demo-setup', $payload);

            if ($response->successful()) {
                $lead->update([
                    'demo_setup_status' => 'exitoso',
                    'demo_setup_last_error' => null,
                ]);

                return $lead->refresh();
            }

            return $this->mark_failed(
                $lead,
                'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500)
            );
        } catch (\Throwable $e) {
            Log::error('RunDemoSetupService@run error: ' . $e->getMessage(), [
                'lead_id'   => $lead->id,
                'demo_id'   => $demo->id,
            ]);

            return $this->mark_failed($lead, 'Excepción: ' . $e->getMessage());
        }
    }

    /**
     * Arma el array que se envía al endpoint admin-sync/demo-setup del
     * empresa-api destino. Replica los nombres de campos que consume
     * DemoSetupHelper::run.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    protected function build_payload(Lead $lead)
    {
        return [
            // Datos visibles del User
            'name'          => $lead->contact_name,
            'company_name'  => $lead->company_name,
            'doc_number'    => $lead->doc_number,
            'email'         => $lead->email,
            'online'        => $lead->demo->ecommerce_api_url,

            // Tipo de negocio requerido por el helper
            'business_type' => $lead->business_type,

            // Flags booleanos del setup
            'iva_included'                 => (bool) $lead->iva_included,
            'redondear_centenas_en_vender' => (bool) $lead->redondear_centenas_en_vender,
            'ask_amount_in_vender'         => (bool) $lead->ask_amount_in_vender,

            // Nota: el helper original usa "usan_cuentas_corrientes" invertido;
            // en el Lead lo guardamos como "omitir_cuentas_corrientes" para que
            // sea coherente con user/setup.blade.php. Aquí traducimos.
            'usan_cuentas_corrientes'      => !((bool) $lead->omitir_cuentas_corrientes),

            'use_deposits'                 => (bool) $lead->use_deposits,
            'address_1'                    => $lead->address_1,
            'address_2'                    => $lead->address_2,
            'address_3'                    => $lead->address_3,

            'use_price_lists'              => (bool) $lead->use_price_lists,
            'price_type_1'                 => $lead->price_type_1,
            'price_type_2'                 => $lead->price_type_2,
            'price_type_3'                 => $lead->price_type_3,

            'ventas_con_fecha_de_entrega'  => (bool) $lead->ventas_con_fecha_de_entrega,
            'cajas'                        => (bool) $lead->cajas,
            'usar_codigos_de_barra'        => (bool) $lead->usar_codigos_de_barra,
            'codigos_de_barra_por_defecto' => (bool) $lead->codigos_de_barra_por_defecto,
            'consultora_de_precios'        => (bool) $lead->consultora_de_precios,
            'imagenes'                     => (bool) $lead->imagenes,
            'produccion'                   => (bool) $lead->produccion,
        ];
    }

    /**
     * Helper interno que marca el Lead como fallido con el motivo dado
     * y devuelve el lead refrescado.
     *
     * @param Lead   $lead
     * @param string $reason
     *
     * @return Lead
     */
    protected function mark_failed(Lead $lead, string $reason)
    {
        $lead->update([
            'demo_setup_status' => 'fallido',
            'demo_setup_last_error' => $reason,
        ]);

        return $lead->refresh();
    }
}
