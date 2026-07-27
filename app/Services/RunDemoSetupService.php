<?php

namespace App\Services;

use App\Models\Lead;
use App\Services\ImplementationSettings;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
         * Se normaliza con la regla idempotente de /public en shared_hosting.
         */
        $resolver = new ClientEmpresaApiUrlResolver();
        $erp_api_url = $resolver->normalize_demo_api_base_url($demo->erp_api_url);
        if ($erp_api_url === '') {
            return $this->mark_failed($lead, 'La demo asignada no tiene ERP API URL configurada.');
        }

        // Marcamos el arranque para que el panel muestre el intento en curso
        $lead->update([
            'demo_setup_status' => 'ejecutandose',
            'demo_setup_last_run_at' => now(),
            'demo_setup_last_error' => null,
        ]);

        // Emitimos el token de ingreso ANTES de armar el payload: viaja dentro de él y así
        // el retry de la llamada HTTP de más abajo manda siempre el mismo valor (idempotente).
        $this->emitir_token_de_ingreso($lead);

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
        $payload = [
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

            // Cuota diaria de Google Custom Search para el User de la demo. A diferencia de la
            // API key, esto no es un secreto y tiene un default sano (100), así que se manda
            // siempre (sin condición), a diferencia de google_custom_search_api_key más abajo.
            'google_cuota'                 => ImplementationSettings::get_google_cuota_demo(),

            // Token de ingreso directo a la demo (sesión ya iniciada) y su vencimiento.
            // Se emite una única vez en emitir_token_de_ingreso(), antes de armar este payload.
            'demo_ingreso_token'            => $lead->demo_ingreso_token,
            'demo_ingreso_token_expira_at'  => $lead->demo_ingreso_token_expira_at
                ? $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s')
                : null,
        ];

        // La API key de Google solo viaja si está cargada en admin. Si está vacía no se manda
        // el campo, y empresa-api usa la constante de fallback que tiene en DemoSetupHelper.
        // Se lee la key de DEMO (distinta de la de clientes reales) para no compartir cuota.
        $google_api_key = ImplementationSettings::get_google_api_key_demo();
        if ($google_api_key !== '') {
            $payload['google_custom_search_api_key'] = $google_api_key;
        }

        return $payload;
    }

    /**
     * Genera y persiste el token de ingreso directo a la demo para el Lead dado.
     *
     * Se llama una sola vez por corrida de run(), antes de armar el payload: como la llamada
     * HTTP tiene retry automático, generar el token en la respuesta de empresa-api produciría
     * un valor distinto en cada reintento. Generándolo acá, en admin-api, y mandándolo dentro
     * del payload, el reintento manda siempre el mismo valor (operación idempotente).
     *
     * Asimetría intencional de almacenamiento: acá en admin-api se guarda el token EN CLARO
     * (demo_ingreso_token), porque el admin necesita poder reconstruir el link para reenviarlo
     * por WhatsApp. Del lado de empresa-api (prompt 02 de este grupo) solo se guarda el hash.
     *
     * El vencimiento sale de demo_date + demo_end_time + gracia_minutos_post. Como
     * demo_end_time es un string libre de 32 caracteres (puede venir vacío o con formato raro),
     * el cálculo va envuelto en try/catch con un fallback fijo de 4 horas: la expiración nunca
     * puede quedar en null, porque es el único control de seguridad real de este link (viaja
     * por WhatsApp y es inherentemente compartible).
     *
     * @param Lead $lead Lead al que se le emite el token
     *
     * @return string El token en claro recién generado
     */
    protected function emitir_token_de_ingreso(Lead $lead)
    {
        // Token de 64 caracteres, no es de un solo uso: vale durante toda la ventana de vigencia.
        $token = Str::random(64);

        // Intentamos calcular el vencimiento real a partir de la fecha/hora de fin de la demo.
        $expira_at = null;
        try {
            if (!is_null($lead->demo_date) && !empty($lead->demo_end_time)) {
                $expira_at = Carbon::parse($lead->demo_date->format('Y-m-d') . ' ' . $lead->demo_end_time)
                    ->addMinutes(LeadDemoSettings::get_gracia_minutos_post());
            }
        } catch (\Throwable $e) {
            // demo_end_time vino con formato inválido: seguimos al fallback de abajo, sin excepción visible.
            $expira_at = null;
        }

        // Fallback obligatorio: nunca dejar el token sin vencimiento.
        if (is_null($expira_at)) {
            $expira_at = Carbon::now()->addHours(4);
        }

        $lead->update([
            'demo_ingreso_token' => $token,
            'demo_ingreso_token_expira_at' => $expira_at,
            'demo_ingreso_token_revocado_at' => null,
        ]);

        return $token;
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
