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
                /* 🔴 SIN `->retry()`, por el mismo argumento que ya está escrito largo en
                 * `RunDemoSetupService::run()`, y no se repone. `UserSetupHelper::run()` del otro
                 * lado también arranca con un `migrate:fresh`: en Laravel 8, con `tries > 1` una
                 * respuesta NO exitosa se relanza (`PendingRequest::send`, línea 702 del vendor),
                 * o sea que un error del armado vuelve a hacer el POST y le re-dispara la
                 * operación destructiva entera 500 ms después, sobre la base de un cliente de
                 * PRODUCCIÓN. `CLIENT_API_RETRIES` no está en el `.env`, así que hasta hoy esto
                 * corría con los 2 intentos del default.
                 *
                 * Y hay un segundo daño, el que destapó la misión cruzada del 25/8/2026: desde
                 * esta misión el endpoint puede contestar 409 ("ya hay una corrida viva"). Con
                 * reintentos, ese 409 se convertía en `RequestException` y caía al
                 * `catch (\Throwable)` como `Excepción: ...` — se perdía el motivo real justo
                 * cuando es lo único que explica qué pasó. Con `tries = 1` la respuesta no exitosa
                 * vuelve por el camino normal y la maneja el `if` de acá abajo. */
                ->post($production_api_url . '/api/admin-sync/user-setup', $payload);

            if ($response->successful()) {
                $lead->update([
                    'user_setup_status'     => 'exitoso',
                    'user_setup_last_error' => null,
                ]);

                return $lead->refresh();
            }

            /* 🔴 409 = el empresa-api ya tiene un setup corriendo sobre esa instancia, y NO tocó
             * la base para decírnoslo (toma un candado con `flock` antes de llamar al helper).
             * Eso no es un fallo del armado: es la confirmación de que hay otra corrida viva.
             * Marcarlo `fallido` invitaría a volver a apretar el botón encima de esa corrida, que
             * es exactamente la secuencia que vacía una base a mitad de camino.
             *
             * `sin_confirmar` cabe sin migración (`user_setup_status` es un string(20)) y ningún
             * lector actual se rompe: `LeadController:364` y `:1488` comparan contra `'exitoso'`,
             * y el badge de `leads/show.blade.php` cae a `badge-light` mostrando el texto crudo del
             * estado. admin-spa no lee esta columna.
             *
             * Compatibilidad hacia atrás: un empresa-api anterior al 22/8/2026 nunca devuelve 409,
             * así que para esas instancias esta rama simplemente no se usa. */
            if ($response->status() === 409) {
                Log::info('RunUserSetupService: la instancia ya tenía un user setup corriendo (HTTP 409).', [
                    'lead_id'   => $lead->id,
                    'client_id' => $client->id,
                ]);

                return $this->mark_sin_confirmar(
                    $lead,
                    'Ya hay un setup corriendo en la instancia del cliente. No se disparó otro; '
                    . 'esperá a que termine el que está en curso.'
                );
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

        // Obtener la última versión publicada para asignarla al cliente.
        $latest_version = \App\Models\Version::where('status', 'published')
            ->orderByDesc('id')
            ->first();

        $client = Client::create([
            'name'               => $client_name,
            'company_name'       => $company_name,
            'user_id'            => $comercio_city_user_id,
            'slug'               => $slug,
            'api_url'            => $production_api_url,
            'api_key'            => $api_key,
            'inbound_api_key'    => $inbound_api_key,
            'is_active'          => true,
            'phone'              => $lead->phone ?? null,
            'current_version_id' => $latest_version ? $latest_version->id : null,
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

        $payload = [
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
            'google_cuota'                 => ImplementationSettings::get_google_cuota_default(),
        ];

        // La API key de Google solo viaja si está cargada en admin. Si está vacía no se manda
        // el campo, y empresa-api usa la constante de fallback que tiene en UserSetupHelper.
        $google_api_key = ImplementationSettings::get_google_api_key_default();
        if ($google_api_key !== '') {
            $payload['google_custom_search_api_key'] = $google_api_key;
        }

        return $payload;
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
     * Resuelve la URL del empresa-api productivo del cliente.
     *
     * Orden de prioridad (de más a menos confiable):
     *   1. $client->active_client_api->url — la ClientApi activa del cliente, fuente actual
     *      de verdad (tabla client_apis + clients.active_client_api_id).
     *   2. $client->api_url — columna legacy en clients, se mantiene por compatibilidad
     *      con clientes que todavía no migraron a client_apis.
     *   3. $lead->api_url — legacy del flujo viejo de promoción de leads.
     *
     * Devuelve la primera no vacía. PHP 7.4: no se usa el operador nullsafe (?->),
     * se valida con !== null antes de acceder a ->url.
     *
     * @param Lead   $lead
     * @param Client $client
     *
     * @return string URL sin slash final, o cadena vacía si no hay URL configurada.
     */
    protected function resolve_production_api_url(Lead $lead, Client $client)
    {
        // 1) ClientApi activa del cliente (relación active_client_api definida en Client.php).
        $active_client_api = $client->active_client_api;
        if ($active_client_api !== null) {
            $active_api_url = rtrim(trim((string) ($active_client_api->url ?? '')), '/');
            if ($active_api_url !== '') {
                return $active_api_url;
            }
        }

        // 2) Columna legacy en clients.api_url.
        $client_url = rtrim(trim((string) ($client->api_url ?? '')), '/');
        if ($client_url !== '') {
            return $client_url;
        }

        // 3) Compatibilidad con leads promovidos por el flujo antiguo que guardaban api_url en el lead.
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

    /**
     * Deja el Lead en `sin_confirmar`: el armado NO falló, pero tampoco se sabe que haya terminado.
     *
     * Hoy tiene un solo llamador —el HTTP 409 de una instancia que ya tenía una corrida viva— y a
     * propósito no se le agregó la rama de `ConnectionException` que sí tiene
     * `RunDemoSetupService`: el user-setup corre contra un cliente de producción, por un botón
     * manual, y ampliar su máquina de estados más allá de lo que esta misión necesita es trabajo
     * que nadie midió. Si algún día hace falta, el estado ya está y el lugar es éste.
     *
     * @param Lead   $lead
     * @param string $reason Motivo, en castellano, que se muestra en el panel.
     *
     * @return Lead
     */
    protected function mark_sin_confirmar(Lead $lead, string $reason)
    {
        $lead->update([
            'user_setup_status'     => RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            'user_setup_last_error' => $reason,
        ]);

        return $lead->refresh();
    }
}
