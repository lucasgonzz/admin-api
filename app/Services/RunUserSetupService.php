<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Crea o actualiza el {@see Client} de producción desde datos del lead y dispara
 * UserSetupHelper::run en el empresa-api (POST a Client.api_url del perfil de cliente).
 */
class RunUserSetupService
{
    /**
     * Ejecuta el user-setup remotamente y actualiza los campos de trazabilidad
     * del Lead (user_setup_status / user_setup_last_error / user_setup_last_run_at).
     *
     * @param Lead $lead Debe estar en status cerrado_ganado y el Client vinculado debe tener api_url
     *
     * @return Lead El mismo Lead refrescado
     */
    public function run(Lead $lead)
    {
        if ($lead->status !== 'cerrado_ganado') {
            return $this->mark_failed($lead, 'Primero promové el lead a cliente (estado cerrado ganado).');
        }

        /**
         * Client de admin-api vinculado al lead: se crea aquí si aún no existe.
         */
        $client = $this->ensure_production_client($lead, '');

        /**
         * Base URL del empresa-api: prioriza api_url del perfil Client (no del lead).
         */
        $production_api_url = $this->resolve_production_api_url($lead, $client);
        if ($production_api_url === '') {
            return $this->mark_failed(
                $lead,
                'Cargá la API URL en el perfil del cliente (Clientes) antes de ejecutar user setup.'
            );
        }

        if (!$client->is_active) {
            return $this->mark_failed($lead, 'El Client de producción está inactivo.');
        }

        $client->refresh();

        $lead->update([
            'user_setup_status'      => 'ejecutandose',
            'user_setup_last_run_at' => now(),
            'user_setup_last_error'  => null,
        ]);

        $payload = $this->build_payload($lead, $client);
        if (empty($payload['user_id'])) {
            return $this->mark_failed($lead, 'No se pudo asignar el user_id ComercioCity (bloque) para el setup.');
        }

        try {
            $response = Http::withHeaders([
                    'Accept' => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15) * 20)
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($production_api_url . '/api/admin-sync/user-setup', $payload);

            if ($response->successful()) {
                $lead->update([
                    'user_setup_status'     => 'exitoso',
                    'user_setup_last_error' => null,
                ]);

                return $lead->refresh();
            }

            return $this->mark_failed(
                $lead,
                'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500)
            );
        } catch (\Throwable $e) {
            Log::error('RunUserSetupService@run error: ' . $e->getMessage(), [
                'lead_id'   => $lead->id,
                'client_id' => $client->id,
            ]);

            return $this->mark_failed($lead, 'Excepción: ' . $e->getMessage());
        }
    }

    /**
     * Crea el Client la primera vez (contact_name → name, company_name → company_name,
     * claves aleatorias) o sincroniza datos si ya estaba vinculado.
     * Método público para permitir su uso desde servicios que crean el perfil de Client
     * sin ejecutar el setup remoto del ERP (ej. PromoteLeadToClientService).
     *
     * @param Lead   $lead
     * @param string $production_api_url URL normalizada sin slash final; vacío = no sobrescribir api_url del Client
     *
     * @return Client
     */
    public function ensure_production_client(Lead $lead, string $production_api_url = '')
    {
        // Normalizar URL recibida; cadena vacía indica que no se debe pisar api_url en updates.
        $production_api_url = rtrim(trim($production_api_url), '/');
        /**
         * Nombre del contacto para el registro Client (fallback si viene vacío).
         */
        $client_name = trim((string) $lead->contact_name);
        if ($client_name === '') {
            $client_name = 'Cliente';
        }

        /**
         * Razón social opcional en Client.company_name.
         */
        $company_name = trim((string) $lead->company_name);
        $company_name = $company_name === '' ? null : $company_name;

        /**
         * Servicio de bloques ComercioCity (múltiplos de 100).
         */
        $allocator = app(UserIdBlockAllocatorService::class);

        if ($lead->promoted_client_id) {
            /**
             * Client existente: alineamos datos con el lead por si se editó después.
             */
            $client = Client::findOrFail($lead->promoted_client_id);
            $this->ensure_client_comercio_city_user_id($lead, $client, $allocator);

            // Datos de contacto del lead; api_url solo si se pasó explícitamente.
            $update_data = [
                'name'         => $client_name,
                'company_name' => $company_name,
                'is_active'    => true,
            ];
            if ($production_api_url !== '') {
                $update_data['api_url'] = $production_api_url;
            }
            $client->update($update_data);

            return $client->refresh();
        }

        /**
         * Nuevo Client: mismo criterio que suggest_next_block_start (leads + clients + bloques).
         */
        $comercio_city_user_id = $allocator->suggest_next_block_start();
        $lead->update(['user_id' => (string) $comercio_city_user_id]);
        $allocator->reserve_block_for_lead($lead, $comercio_city_user_id, 'user_setup');

        /**
         * Base para slug único (empresa o nombre de contacto).
         */
        $slug_base = Str::slug($company_name ?: $client_name);
        $slug = $this->unique_client_slug($slug_base);

        /**
         * Claves para integrar admin-api ↔ empresa-api (mismo patrón que ClientController).
         */
        $api_key = Str::random(40);
        $inbound_api_key = Str::random(40);

        $client = Client::create([
            'name'            => $client_name,
            'company_name'    => $company_name,
            'user_id'         => $comercio_city_user_id,
            'slug'            => $slug,
            'api_url'         => $production_api_url,
            'api_key'         => $api_key,
            'inbound_api_key' => $inbound_api_key,
            'is_active'       => true,
            'phone'           => $lead->phone ?? null,
        ]);

        $lead->update(['promoted_client_id' => $client->id]);

        $allocator->attach_client_to_lead_block($lead->id, $client->id);

        return $client;
    }

    /**
     * Genera un slug único en clients (sufijo numérico si hace falta).
     *
     * @param string $base
     *
     * @return string
     */
    protected function unique_client_slug(string $base)
    {
        if ($base === '') {
            $base = 'cliente';
        }

        $slug = $base;
        $i = 2;

        while (Client::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    /**
     * Garantiza Client.user_id y lead.user_id alineados con la política de bloques.
     *
     * @param Lead                       $lead
     * @param Client                     $client
     * @param UserIdBlockAllocatorService $allocator
     *
     * @return void
     */
    protected function ensure_client_comercio_city_user_id(Lead $lead, Client $client, UserIdBlockAllocatorService $allocator)
    {
        if ($client->user_id !== null && $client->user_id > 0) {
            if ((string) $lead->user_id !== (string) $client->user_id) {
                $lead->update(['user_id' => (string) $client->user_id]);
                $allocator->reserve_block_for_lead($lead, (int) $client->user_id, 'user_setup_sync');
            }

            return;
        }

        if ($lead->user_id !== null && $lead->user_id !== '' && is_numeric($lead->user_id)) {
            $uid = (int) $lead->user_id;
            $client->update(['user_id' => $uid]);
            $allocator->reserve_block_for_lead($lead, $uid, 'user_setup_backfill');

            return;
        }

        $comercio_city_user_id = $allocator->suggest_next_block_start();
        $lead->update(['user_id' => (string) $comercio_city_user_id]);
        $allocator->reserve_block_for_lead($lead, $comercio_city_user_id, 'user_setup_backfill');
        $client->update(['user_id' => $comercio_city_user_id]);
    }

    /**
     * Arma el payload que se envía al endpoint admin-sync/user-setup del empresa-api.
     *
     * @param Lead   $lead
     * @param Client $client
     *
     * @return array<string, mixed>
     */
    protected function build_payload(Lead $lead, Client $client)
    {
        /**
         * ID del User en empresa-api: fuente Client.user_id (asignado con allocator al crear el Client).
         */
        $user_id_for_erp = (int) ($client->user_id ?? 0);
        if ($user_id_for_erp <= 0 && $lead->user_id !== null && $lead->user_id !== '' && is_numeric($lead->user_id)) {
            $user_id_for_erp = (int) $lead->user_id;
        }

        return [
            'user_id'       => $user_id_for_erp,
            'user_name'     => $this->resolve_user_name_for_erp($lead),
            'company_name'  => $lead->company_name,
            'doc_number'    => $lead->doc_number,
            'email'         => $lead->email,
            'phone'         => $lead->phone,
            'total_a_pagar' => $lead->total_a_pagar,

            'business_type' => $lead->business_type,

            'iva_included'                 => (bool) $lead->iva_included,
            'ask_amount_in_vender'         => (bool) $lead->ask_amount_in_vender,
            'redondear_centenas_en_vender' => (bool) $lead->redondear_centenas_en_vender,
            'omitir_cuentas_corrientes'    => (bool) $lead->omitir_cuentas_corrientes,

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
     * Nombre visible del User en empresa-api: user_name del lead si existe; si no, contacto o empresa.
     *
     * @param Lead $lead
     *
     * @return string
     */
    protected function resolve_user_name_for_erp(Lead $lead)
    {
        $candidates = [
            trim((string) ($lead->user_name ?? '')),
            trim((string) ($lead->contact_name ?? '')),
            trim((string) ($lead->company_name ?? '')),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Cliente';
    }

    /**
     * Resuelve la URL del empresa-api productivo: primero Client.api_url, luego lead.api_url (legacy).
     *
     * @param Lead   $lead
     * @param Client $client
     *
     * @return string URL sin slash final, o cadena vacía si no hay URL configurada.
     */
    protected function resolve_production_api_url(Lead $lead, Client $client)
    {
        $client_url = rtrim(trim((string) ($client->api_url ?? '')), '/');
        if ($client_url !== '') {
            return $client_url;
        }

        // Compatibilidad con leads promovidos por el flujo antiguo que guardaban api_url en el lead.
        return rtrim(trim((string) ($lead->api_url ?? '')), '/');
    }

    /**
     * Marca el Lead como fallido con el motivo dado y devuelve el lead refrescado.
     *
     * @param Lead   $lead
     * @param string $reason
     *
     * @return Lead
     */
    protected function mark_failed(Lead $lead, string $reason)
    {
        $lead->update([
            'user_setup_status'     => 'fallido',
            'user_setup_last_error' => $reason,
        ]);

        return $lead->refresh();
    }
}
