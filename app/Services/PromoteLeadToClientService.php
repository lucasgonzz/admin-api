<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Lead;
use App\Services\Prueba\PruebaCicloService;
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
    /** @var RunUserSetupService */
    protected $run_user_setup_service;

    /** @var TaskFromTemplatesService */
    protected $task_service;

    /** @var SubdomainSuggestionService */
    protected $subdomain_service;

    /**
     * @param RunUserSetupService         $run_user_setup_service  Para crear/actualizar el Client.
     * @param TaskFromTemplatesService    $task_service            Para crear tareas automáticas.
     * @param SubdomainSuggestionService  $subdomain_service       Para sugerir subdominio vía Claude.
     */
    public function __construct(
        RunUserSetupService $run_user_setup_service,
        TaskFromTemplatesService $task_service,
        SubdomainSuggestionService $subdomain_service
    ) {
        $this->run_user_setup_service = $run_user_setup_service;
        $this->task_service           = $task_service;
        $this->subdomain_service      = $subdomain_service;
    }

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
        /* 🔴 Un lead con la prueba gratuita en curso o vencida (misión prueba-alta-instancia, 3/11/2026,
           decisión D6): CONTRATA. Ya tiene su Client (`estado = prueba`), sus dos ClientApi y su instancia en
           el VPS, que ES su sistema definitivo. Acá no se crea nada ni se corre ningún user setup remoto (un
           `migrate:fresh` le vaciaría lo que cargó en la prueba): el cliente pasa a `activo`, el ERP se entera
           de que ya no vence (C3), la instancia se despierta si dormía y el lead queda `cerrado_ganado`. Las
           tareas del proceso `lead_a_cliente` sí se crean, como en toda promoción, y las cuotas del contrato las
           genera `ClientContratoService` igual que siempre (M5 pone los planes). */
        $client_en_prueba = $lead->promoted_client_id ? Client::find($lead->promoted_client_id) : null;
        if (PruebaCicloService::esta_en_prueba($client_en_prueba)) {
            return $this->contratar_prueba($lead, $client_en_prueba, $creator);
        }

        // Marcar el lead como cerrado_ganado si aún no lo está (sin tocar api_url del lead).
        if ($lead->status !== 'cerrado_ganado') {
            $cambios = ['status' => 'cerrado_ganado'];

            // 🔴 El estado del user setup solo vuelve a `pendiente` si el setup NUNCA corrió. Un lead que salió de `cerrado_ganado`
            // (un arrastre equivocado en el pipeline, un cambio de estado) y se vuelve a promover conserva `ejecutandose`, `exitoso` o
            // `sin_confirmar`: esa columna es la señal que leen los candados del user setup (`lead_sin_user_setup`) para no volver a vaciar
            // el sistema de un cliente que ya se configuró. Pisarla con `pendiente` le reabría la puerta de leads a un `migrate:fresh`.
            if (! in_array((string) $lead->user_setup_status, UserSetupCandadoService::ESTADOS_DEL_LEAD_QUE_YA_CONFIGURARON, true)) {
                $cambios['user_setup_status'] = 'pendiente';
            }

            $lead->update($cambios);
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
     * La promoción de un cliente en PRUEBA (D6): sin user setup, sin ClientApis nuevas, sin reinstalar.
     *
     * Orden: primero el cliente (`PruebaCicloService::contratar()`: estado, instancia, C3) y recién después el
     * lead a `cerrado_ganado`. Así el observer de Meta, que corre en el `save()` del lead, ya ve al cliente
     * `activo` y registra el `Purchase` (mientras estaba en prueba no contaba como compra).
     */
    private function contratar_prueba(Lead $lead, Client $client, Admin $creator): Lead
    {
        $resultado = app(PruebaCicloService::class)->contratar($client);

        $cambios = ['status' => 'cerrado_ganado'];
        /* El user setup NO se corre en una prueba, pero la columna tiene que decir que el sistema ya está
           configurado: es la señal que leen los candados (`lead_sin_user_setup`) para no volver a vaciar un
           sistema en uso. `exitoso` es lo que deja una instalación terminada. */
        if (! in_array((string) $lead->user_setup_status, UserSetupCandadoService::ESTADOS_DEL_LEAD_QUE_YA_CONFIGURARON, true)) {
            $cambios['user_setup_status'] = 'exitoso';
        }
        $lead->update($cambios);

        try {
            $this->task_service->create_from_templates('lead_a_cliente', $creator);
        } catch (\Throwable $e) {
            Log::error('PromoteLeadToClientService: no se pudieron crear las tareas al contratar una prueba.', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        /* El contrato viaja con el cliente, como en una promoción común (misión modulo-cobranzas): se copia
           y se generan las cuotas desde su financiación. Atrapa y loguea adentro. */
        app(ClientContratoService::class)->inicializar_desde_lead($lead, $client);

        Log::info('PromoteLeadToClientService: cliente en prueba contratado.', [
            'lead_id'   => $lead->id,
            'client_id' => $client->id,
            'c3'        => isset($resultado['estado']) ? $resultado['estado'] : null,
        ]);

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
