<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servicio que centraliza la reemision y revocacion manual (desde el panel del lead) del token
 * de ingreso directo a la demo, avisando a la instancia (empresa-api) via el endpoint
 * admin-sync/demo-token para que quede en sincronia con lo que admin-api guarda en el Lead.
 *
 * Tambien centraliza el calculo de vencimiento del token, extraido de
 * RunDemoSetupService::emitir_token_de_ingreso() (que antes lo duplicaba) para que el setup
 * inicial y la reemision manual usen exactamente la misma logica y no se desincronicen
 * (grupo 233, prompt 05).
 */
class DemoIngresoTokenService
{
    /**
     * Resolver de URL de ERP API de la demo, reutilizado del mismo patron que
     * RunDemoSetupService/PublishVersionService.
     *
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para tests.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver ?? new ClientEmpresaApiUrlResolver();
    }

    /**
     * Calcula la fecha/hora de vencimiento del token de ingreso para el lead dado.
     *
     * El vencimiento sale de demo_date + demo_end_time + gracia_minutos_post. Como
     * demo_end_time es un string libre (puede venir vacio o con formato raro), el calculo va
     * envuelto en try/catch con un fallback fijo de 4 horas: la expiracion nunca puede quedar
     * en null, porque es el unico control de seguridad real de este link (viaja por WhatsApp y
     * es inherentemente compartible).
     *
     * @param Lead $lead Lead sobre el que se calcula la expiracion
     *
     * @return Carbon Fecha/hora de vencimiento, nunca null
     */
    public function calcular_expiracion(Lead $lead)
    {
        // Intentamos calcular el vencimiento real a partir de la fecha/hora de fin de la demo.
        $expira_at = null;
        try {
            if (!is_null($lead->demo_date) && !empty($lead->demo_end_time)) {
                $expira_at = Carbon::parse($lead->demo_date->format('Y-m-d') . ' ' . $lead->demo_end_time)
                    ->addMinutes(LeadDemoSettings::get_gracia_minutos_post());
            }
        } catch (\Throwable $e) {
            // demo_end_time vino con formato invalido: seguimos al fallback de abajo, sin excepcion visible.
            $expira_at = null;
        }

        // Fallback obligatorio: nunca dejar el token sin vencimiento.
        if (is_null($expira_at)) {
            $expira_at = Carbon::now()->addHours(4);
        }

        return $expira_at;
    }

    /**
     * Reemite el token de ingreso a la demo del lead (por ejemplo, si se reagendo el turno o el
     * lead perdio el link): genera un token nuevo, lo persiste en el Lead y avisa a la instancia
     * (empresa-api) para que reemplace el hash guardado ahi.
     *
     * Si el aviso a la instancia falla, se revierte el Lead al token/expiracion anteriores:
     * dejar en admin-api un token que la instancia no conoce genera un link que no anda y nadie
     * sabe por que.
     *
     * @param Lead $lead Lead al que se le reemite el token
     *
     * @return Lead El mismo Lead refrescado con el token nuevo
     *
     * @throws \RuntimeException Si no se pudo avisar a la instancia (el Lead ya quedo revertido)
     */
    public function reemitir(Lead $lead)
    {
        $lead->loadMissing('demo');
        $demo = $lead->demo;

        if (is_null($demo)) {
            throw new \RuntimeException('El lead no tiene demo asignada.');
        }

        // Backup de los valores actuales del Lead, por si hay que revertir tras un aviso fallido.
        $token_anterior    = $lead->demo_ingreso_token;
        $expira_anterior   = $lead->demo_ingreso_token_expira_at;
        $revocado_anterior = $lead->demo_ingreso_token_revocado_at;

        // Token de 64 caracteres, no es de un solo uso: vale durante toda la ventana de vigencia.
        $token_nuevo = Str::random(64);
        $expira_at   = $this->calcular_expiracion($lead);

        // Persistimos el token nuevo ANTES de avisar a la instancia: si el aviso falla, revertimos
        // explicitamente a los valores de backup de arriba.
        $lead->update([
            'demo_ingreso_token' => $token_nuevo,
            'demo_ingreso_token_expira_at' => $expira_at,
            'demo_ingreso_token_revocado_at' => null,
        ]);

        try {
            $this->avisar_instancia($demo, [
                'accion' => 'guardar',
                'token' => $token_nuevo,
                'expira_at' => $expira_at->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Revertir: dejar el Lead exactamente como estaba antes de intentar reemitir.
            $lead->update([
                'demo_ingreso_token' => $token_anterior,
                'demo_ingreso_token_expira_at' => $expira_anterior,
                'demo_ingreso_token_revocado_at' => $revocado_anterior,
            ]);

            Log::error('DemoIngresoTokenService::reemitir error: ' . $e->getMessage(), [
                'lead_id' => $lead->id,
                'demo_id' => $demo->id,
            ]);

            throw new \RuntimeException('No se pudo avisar a la instancia: ' . $e->getMessage());
        }

        return $lead->refresh();
    }

    /**
     * Revoca el token de ingreso a la demo del lead (por ejemplo, si se compartió donde no
     * debia): marca `demo_ingreso_token_revocado_at` en el Lead y avisa a la instancia para que
     * revoque todos los tokens vigentes del usuario demo (hay uno solo por instancia).
     *
     * @param Lead $lead Lead al que se le revoca el token
     *
     * @return Lead El mismo Lead refrescado
     *
     * @throws \RuntimeException Si no se pudo avisar a la instancia
     */
    public function revocar(Lead $lead)
    {
        $lead->loadMissing('demo');
        $demo = $lead->demo;

        if (is_null($demo)) {
            throw new \RuntimeException('El lead no tiene demo asignada.');
        }

        $lead->update(['demo_ingreso_token_revocado_at' => now()]);

        try {
            $this->avisar_instancia($demo, ['accion' => 'revocar']);
        } catch (\Throwable $e) {
            Log::error('DemoIngresoTokenService::revocar error: ' . $e->getMessage(), [
                'lead_id' => $lead->id,
                'demo_id' => $demo->id,
            ]);

            throw new \RuntimeException('No se pudo avisar a la instancia: ' . $e->getMessage());
        }

        return $lead->refresh();
    }

    /**
     * Hace el POST a admin-sync/demo-token de la instancia (empresa-api) de la demo dada.
     *
     * Timeout corto (10-15s, via config services.client_api.timeout): a diferencia del setup
     * (RunDemoSetupService, timeout x20 por las migraciones/seeders), esto es solo un update de
     * una fila, no hay motivo para esperar minutos.
     *
     * Sobre el header X-Admin-Api-Key: se manda solo si hay una clave disponible para esta
     * instancia. La demo no tiene una fila de `clients` con api_key cargada (a diferencia de
     * PublishVersionService, que si la tiene via $client->api_key), asi que hoy el POST sale
     * siempre sin header. Eso es intencional: no se inventa una clave nueva ni se agrega config,
     * y la proteccion real es que la ruta ya vive dentro del grupo con middleware admin.api.key
     * en empresa-api, hoy desactivado por services.admin_api.require_api_key = false (decision
     * de Lucas, 27/7/2026). Falta de clave no es un error: nunca se debe frenar el
     * reemitir/revocar por esto.
     *
     * @param \App\Models\Demo $demo    Demo asignada al lead (dueña de la ERP API a avisar)
     * @param array<string, mixed> $payload Body a enviar (accion + datos segun el caso)
     *
     * @return void
     *
     * @throws \RuntimeException Si la URL no esta configurada o la respuesta no es exitosa
     */
    protected function avisar_instancia($demo, array $payload)
    {
        $erp_api_url = $this->api_url_resolver->normalize_demo_api_base_url($demo->erp_api_url);
        if ($erp_api_url === '') {
            throw new \RuntimeException('La demo asignada no tiene ERP API URL configurada.');
        }

        $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])
            ->timeout((int) config('services.client_api.timeout', 15))
            ->retry((int) config('services.client_api.retries', 2), 500)
            ->post($erp_api_url . '/api/admin-sync/demo-token', $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500));
        }
    }
}
