<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Formaliza la promoción de un Lead a Client de producción y crea las tareas automáticas.
 *
 * Diferencia con RunUserSetupService:
 * - Este servicio solo crea/actualiza el perfil del Client en admin-api y genera las tareas.
 * - NO dispara el setup remoto en el empresa-api del cliente.
 * - El setup remoto continúa siendo responsabilidad de RunUserSetupService.
 *
 * Flujo:
 * 1. Si no está en estado 'cerrado_ganado', lo promueve.
 * 2. Crea o actualiza el Client en admin-api con los datos del lead (sin api_url; va en el perfil Client).
 * 3. Si el Client se creó por primera vez:
 *    a. Genera las tareas automáticas del proceso 'lead_a_cliente'.
 *    b. Crea dos ClientApis (producción y paralela) con subdominios sugeridos por Claude.
 *    c. Setea active_client_api_id apuntando a la ClientApi de producción (la sin "2").
 */
class PromoteLeadToClientService
{
    /**
     * @param RunUserSetupService         $run_user_setup_service  Para crear/actualizar el Client.
     * @param TaskFromTemplatesService    $task_service            Para crear tareas automáticas.
     * @param SubdomainSuggestionService  $subdomain_service       Para sugerir subdominio vía Claude.
     */
    public function __construct(
        protected RunUserSetupService        $run_user_setup_service,
        protected TaskFromTemplatesService   $task_service,
        protected SubdomainSuggestionService $subdomain_service
    ) {}

    /**
     * Ejecuta la promoción del Lead a Client y genera las tareas del proceso 'lead_a_cliente'.
     *
     * Si el Client es nuevo, también crea las dos ClientApis iniciales (producción y paralela)
     * y setea active_client_api_id apuntando a la de producción.
     *
     * @param  Lead   $lead                Lead a promover.
     * @param  Admin  $creator             Admin autenticado que dispara la acción.
     * @param  string $suggested_subdomain Subdominio propuesto por el operador desde la UI.
     *                                     Si está vacío se genera llamando a Claude.
     * @return Lead                        El Lead refrescado tras la promoción.
     */
    public function run(Lead $lead, Admin $creator, string $suggested_subdomain = ''): Lead
    {
        // Marcar el lead como cerrado_ganado si aún no lo está (sin tocar api_url del lead).
        if ($lead->status !== 'cerrado_ganado') {
            $lead->update([
                'status'            => 'cerrado_ganado',
                'user_setup_status' => 'pendiente',
            ]);
            $lead->refresh();
        }

        // Determinar si el Client ya existía antes de este proceso.
        $is_new_client = is_null($lead->promoted_client_id);

        // Crear o sincronizar el Client; la api_url se carga después en el perfil del cliente.
        $client = $this->run_user_setup_service->ensure_production_client($lead, '');

        // Si el Client se creó por primera vez, generar tareas y crear ClientApis iniciales.
        if ($is_new_client && $client instanceof Client) {
            Log::info('PromoteLeadToClientService: creando tareas automáticas para lead_a_cliente.', [
                'lead_id'   => $lead->id,
                'client_id' => $client->id,
            ]);
            $this->task_service->create_from_templates('lead_a_cliente', $creator);

            /* Resolver el subdominio: usar el sugerido por el operador o pedir uno a Claude. */
            $slug = $this->resolve_subdomain($lead, $suggested_subdomain);

            /* Crear las dos ClientApis y asignar la activa. */
            $this->create_initial_client_apis($client, $slug);
        }

        return $lead->refresh();
    }

    /**
     * Resuelve el subdominio a usar para las ClientApis del cliente nuevo.
     *
     * Prioridad:
     * 1. Si el operador envió un suggested_subdomain no vacío, usarlo directamente.
     * 2. Llamar a SubdomainSuggestionService::suggest() con el company_name del lead.
     * 3. Fallback: Str::slug del company_name truncado a 20 chars.
     *
     * @param  Lead   $lead                Lead del que se toma el company_name.
     * @param  string $suggested_subdomain Valor enviado por el operador (puede ser vacío).
     * @return string                      Slug final a usar en las URLs.
     */
    private function resolve_subdomain(Lead $lead, string $suggested_subdomain): string
    {
        /* Opción 1: el operador ya confirmó el subdominio desde la UI. */
        $cleaned = trim($suggested_subdomain);
        if ($cleaned !== '') {
            return $cleaned;
        }

        /* Opción 2: pedirle a Claude Haiku que sugiera uno basado en el nombre de empresa. */
        $company_name = trim((string) ($lead->company_name ?? ''));

        try {
            return $this->subdomain_service->suggest($company_name);
        } catch (\Throwable $exception) {
            Log::warning('PromoteLeadToClientService: SubdomainSuggestionService falló, usando fallback.', [
                'lead_id' => $lead->id,
                'error'   => $exception->getMessage(),
            ]);
        }

        /* Opción 3: fallback simple con Str::slug. */
        return substr(Str::slug($company_name ?: 'cliente'), 0, 20);
    }

    /**
     * Crea las dos ClientApis estándar para un cliente nuevo y setea la activa.
     *
     * ClientApi 1 — producción principal (sin sufijo "2").
     * ClientApi 2 — paralela para actualizaciones (con sufijo "2").
     *
     * Después de crear ambas, actualiza active_client_api_id del Client apuntando a la 1.
     *
     * @param  Client $client Cliente recién creado al que se asignan las APIs.
     * @param  string $slug   Subdominio base (ej: "hb", "lamartina").
     * @return void
     */
    private function create_initial_client_apis(Client $client, string $slug): void
    {
        /* ClientApi 1: producción — el slug sin sufijo. */
        $production_api = ClientApi::create([
            'client_id'    => $client->id,
            'url'          => "https://api-{$slug}.comerciocity.com",
            'path'         => "{$slug}/api",
            'spa_url'      => "https://{$slug}.comerciocity.com",
            'hosting_type' => 'shared_hosting',
        ]);

        /* ClientApi 2: paralela para actualización — el slug con sufijo "2". */
        ClientApi::create([
            'client_id'    => $client->id,
            'url'          => "https://api-{$slug}2.comerciocity.com",
            'path'         => "{$slug}2/api",
            'spa_url'      => "https://{$slug}2.comerciocity.com",
            'hosting_type' => 'shared_hosting',
        ]);

        /* Asignar la API de producción como activa del cliente. */
        $client->update(['active_client_api_id' => $production_api->id]);

        Log::info('PromoteLeadToClientService: ClientApis creadas.', [
            'client_id'            => $client->id,
            'slug'                 => $slug,
            'active_client_api_id' => $production_api->id,
        ]);
    }
}
