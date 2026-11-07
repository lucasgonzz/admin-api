<?php

namespace App\Services\Prueba;

use App\Helpers\AdminApiPublicUrl;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\DemoIndustriaCatalogo;
use App\Services\ImplementationSettings;
use App\Services\LeadDemoFormMapper;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Contrato C1 (admin → empresa): el demo setup de una PRUEBA.
 *
 * Es `POST {erp}/api/admin-sync/demo-setup`, el mismo endpoint y el mismo payload que arma
 * `RunDemoSetupService::build_payload()` para una demo, más tres campos nuevos y opcionales que M3
 * agrega del lado de empresa (un empresa viejo los ignora):
 *
 *  - `password` (string ≥ 8): empresa lo hashea y se lo pone al dueño.
 *  - `prueba` `{ dias, vence_at, video_url, whatsapp_soporte }`: prende el modo prueba.
 *  - `rubro`: el slug de `demo_industrias.json` (viaja también como `demo_industria`, el nombre que
 *    empresa ya lee; M3 confirma cuál conserva).
 *
 * 🔴 Se le pega al host del POOL (`api-prueba-NN`), que ya tiene certificado desde el armado: el
 * alias `api-<slug>` recién tiene el suyo al final de `ArmarPruebaJob`. Laravel no mira el `Host` para
 * `admin-sync`, así que da lo mismo.
 *
 * Sin `->retry()` y con el techo de 900 s, por los mismos motivos escritos en `RunDemoSetupService`:
 * reintentar un 500 vuelve a disparar un `migrate:fresh`, y un timeout no es un fallo.
 */
class PruebaDemoSetupService
{
    /** El ERP contestó bien. */
    const RESULTADO_OK = 'ok';

    /** Se venció la espera: la corrida puede seguir viva del otro lado. */
    const RESULTADO_SIN_CONFIRMAR = 'sin_confirmar';

    /** El ERP contestó un error. */
    const RESULTADO_FALLIDO = 'fallido';

    /**
     * Dispara el demo setup y devuelve `['resultado' => ..., 'mensaje' => ...]`. No tira: el llamador
     * decide qué hacer con `sin_confirmar` y con `fallido`.
     *
     * @param string $password La contraseña en claro del dueño (viene cifrada en la instancia).
     *
     * @return array<string, string>
     */
    public function disparar(TrialInstance $instancia, Lead $lead, Client $client, string $password): array
    {
        $payload = $this->armar_payload($instancia, $lead, $client, $password);
        $url     = $instancia->erp_api_url_del_pool() . '/api/admin-sync/demo-setup';

        $headers = ['Accept' => 'application/json'];
        $clave   = trim((string) $client->api_key);
        if ($clave !== '') {
            $headers['X-Admin-Api-Key'] = $clave;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('services.prueba.demo_setup_timeout', 900))
                ->post($url, $payload);

            if ($response->successful()) {
                return ['resultado' => self::RESULTADO_OK, 'mensaje' => ''];
            }

            if ($response->status() === 409) {
                return [
                    'resultado' => self::RESULTADO_SIN_CONFIRMAR,
                    'mensaje'   => 'La instancia ya tenía un demo setup corriendo (HTTP 409).',
                ];
            }

            return [
                'resultado' => self::RESULTADO_FALLIDO,
                'mensaje'   => 'HTTP ' . $response->status() . ': ' . substr(
                    ImplementationSettings::tapar_credenciales_de_demo((string) $response->body()),
                    0,
                    2000
                ),
            ];
        } catch (ConnectionException $e) {
            Log::warning('PruebaDemoSetupService: sin respuesta del demo setup (timeout o conexión caída).', [
                'instancia' => $instancia->nombre,
                'lead_id'   => $lead->id,
                'error'     => ImplementationSettings::tapar_credenciales_de_demo($e->getMessage()),
            ]);

            return [
                'resultado' => self::RESULTADO_SIN_CONFIRMAR,
                'mensaje'   => 'Sin respuesta del demo setup (timeout). La corrida puede seguir viva en la instancia.',
            ];
        } catch (\Throwable $e) {
            return [
                'resultado' => self::RESULTADO_FALLIDO,
                'mensaje'   => 'Excepción: ' . ImplementationSettings::tapar_credenciales_de_demo($e->getMessage()),
            ];
        }
    }

    /**
     * El payload de C1. Público para que un test lo mire sin HTTP.
     *
     * @return array<string, mixed>
     */
    public function armar_payload(TrialInstance $instancia, Lead $lead, Client $client, string $password): array
    {
        $respuestas = LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO;
        $hosts      = $instancia->hosts_del_slug();
        $rubro      = DemoIndustriaCatalogo::normalizar($lead->demo_industria);
        $vence_at   = $client->trial_ends_at instanceof Carbon
            ? $client->trial_ends_at->copy()->setTimezone('America/Argentina/Buenos_Aires')
            : Carbon::now('America/Argentina/Buenos_Aires')->addDays((int) ($client->trial_days ?: PruebaSettings::dias_default()));

        $payload = [
            // Datos visibles del User dueño (los mismos nombres que consume DemoSetupHelper::run).
            'name'          => trim((string) $lead->contact_name),
            'company_name'  => trim((string) $lead->company_name),
            'doc_number'    => $lead->doc_number,
            'email'         => trim((string) $lead->email),
            // La URL PÚBLICA de la tienda (la que ve un comprador), ya con el alias del negocio.
            'online'        => isset($hosts['tienda_spa']) ? 'https://' . $hosts['tienda_spa'] : null,
            'business_type' => trim((string) $lead->business_type),

            'iva_included'                 => (bool) $lead->iva_included,
            'redondear_centenas_en_vender' => (bool) $lead->redondear_centenas_en_vender,
            'ask_amount_in_vender'         => (bool) $lead->ask_amount_in_vender,
            'usan_cuentas_corrientes'      => (bool) $respuestas['usa_cuentas_corrientes_clientes'],
            'use_deposits'                 => (bool) $respuestas['usa_depositos'],
            'address_1'                    => $lead->address_1,
            'address_2'                    => $lead->address_2,
            'address_3'                    => $lead->address_3,
            'use_price_lists'              => ($respuestas['tipo_precios'] === 'listas'),
            'price_type_1'                 => null,
            'price_type_2'                 => null,
            'price_type_3'                 => null,
            'ventas_con_fecha_de_entrega'  => (bool) $lead->ventas_con_fecha_de_entrega,
            'cajas'                        => (bool) $lead->cajas,
            'usar_codigos_de_barra'        => (bool) $lead->usar_codigos_de_barra,
            'codigos_de_barra_por_defecto' => (bool) $lead->codigos_de_barra_por_defecto,
            'consultora_de_precios'        => (bool) $lead->consultora_de_precios,
            'imagenes'                     => (bool) $lead->imagenes,
            'produccion'                   => (bool) $lead->produccion,

            'google_cuota' => ImplementationSettings::get_google_cuota_demo(),

            // Token de ingreso directo (sesión iniciada) y su vencimiento: el de la prueba vence con ella.
            'demo_ingreso_token'           => $lead->demo_ingreso_token,
            'demo_ingreso_token_expira_at' => $lead->demo_ingreso_token_expira_at
                ? $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s')
                : null,

            // El canal de hitos (C2): el mismo de la demo.
            'demo_eventos_token' => $lead->demo_eventos_token,
            'demo_eventos_url'   => AdminApiPublicUrl::api('demo-eventos'),

            // La industria, con los dos nombres (ver docblock de la clase).
            'demo_industria' => $rubro,
            'rubro'          => $rubro,

            // Lo nuevo de C1.
            'password' => $password,
            'prueba'   => [
                'dias'             => (int) ($client->trial_days ?: PruebaSettings::dias_default()),
                'vence_at'         => $vence_at->toIso8601String(),
                'video_url'        => PruebaSettings::video_bienvenida_url(),
                'whatsapp_soporte' => PruebaSettings::whatsapp_soporte(),
            ],
        ];

        $google_api_key = ImplementationSettings::get_google_api_key_demo();
        if ($google_api_key !== '') {
            $payload['google_custom_search_api_key'] = $google_api_key;
        }

        $serper_api_key = ImplementationSettings::get_serper_api_key_demo();
        if ($serper_api_key !== '') {
            $payload['serper_api_key'] = $serper_api_key;
        }

        return $payload;
    }
}
