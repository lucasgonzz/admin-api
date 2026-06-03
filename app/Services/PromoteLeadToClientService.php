<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

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
 * 3. Si el Client se creó por primera vez, dispara la creación de tareas automáticas.
 */
class PromoteLeadToClientService
{
    /**
     * @param RunUserSetupService    $run_user_setup_service  Para crear/actualizar el Client.
     * @param TaskFromTemplatesService $task_service           Para crear tareas automáticas.
     */
    public function __construct(
        protected RunUserSetupService     $run_user_setup_service,
        protected TaskFromTemplatesService $task_service
    ) {}

    /**
     * Ejecuta la promoción del Lead a Client y genera las tareas del proceso 'lead_a_cliente'.
     *
     * @param  Lead  $lead    Lead a promover.
     * @param  Admin $creator Admin autenticado que dispara la acción.
     * @return Lead  El Lead refrescado tras la promoción.
     */
    public function run(Lead $lead, Admin $creator): Lead
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

        // Si el Client se creó por primera vez, generar las tareas automáticas del proceso.
        if ($is_new_client && $client instanceof Client) {
            Log::info('PromoteLeadToClientService: creando tareas automáticas para lead_a_cliente.', [
                'lead_id'   => $lead->id,
                'client_id' => $client->id,
            ]);
            $this->task_service->create_from_templates('lead_a_cliente', $creator);
        }

        return $lead->refresh();
    }
}
