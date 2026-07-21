<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints del pipeline de instalación/actualización del ecommerce (tienda-spa + tienda-api).
 *
 * Espeja a `ClientInstallationController`/`DeploymentController` (empresa) pero para
 * `ClientEcommerce`: dispara instalaciones desde cero y actualizaciones (siempre última de master)
 * en un job de cola (`RunEcommerceInstallationJob`), y expone estado/logs de cada corrida para el
 * polling del panel de admin-spa.
 *
 * Sin lógica de negocio acá (regla del proyecto): toda la orquestación del pipeline vive en
 * `EcommerceInstallationService`/`EcommerceDeploymentService` y en el job de cola.
 */
class EcommerceInstallationController extends BaseController
{
    /**
     * Lista todas las corridas de instalación/actualización de ecommerce (todos los clientes),
     * equivalente a `ClientInstallationController::index_all()`.
     *
     * @return JsonResponse  { models: ClientEcommerceInstallation[] }
     */
    public function index_json(): JsonResponse
    {
        $installations = ClientEcommerceInstallation::withAll()
            ->orderByDesc('id')
            ->get();

        return response()->json(['models' => $installations]);
    }

    /**
     * Estado del ecommerce de un cliente junto con sus corridas (instalación/actualización),
     * para el modal de gestión del panel.
     *
     * @param  ClientEcommerce  $client_ecommerce  Resuelta por route model binding.
     * @return JsonResponse  { model: ClientEcommerce }
     */
    public function show_json(ClientEcommerce $client_ecommerce): JsonResponse
    {
        $client_ecommerce->load(['client', 'installations' => function ($query) {
            $query->orderByDesc('id');
        }]);

        return response()->json(['model' => $client_ecommerce]);
    }

    /**
     * Dispara una instalación desde cero (`mode = 'install'`) para una tienda ya creada.
     *
     * @param  ClientEcommerce  $client_ecommerce  Resuelta por route model binding.
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (422)
     */
    public function start_install_json(ClientEcommerce $client_ecommerce): JsonResponse
    {
        // No permitir solapar con una corrida ya en curso de esta misma tienda.
        $conflict_response = $this->assert_no_running_installation($client_ecommerce->id);
        if ($conflict_response !== null) {
            return $conflict_response;
        }

        $installation = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $client_ecommerce->id,
            'mode'                => 'install',
            'status'              => 'pendiente',
        ]);

        // Despacha el job en background (cola por defecto del sistema).
        RunEcommerceInstallationJob::dispatch($installation->uuid);

        return response()->json([
            'model' => $this->fullModel('client_ecommerce_installation', $installation->id),
        ], 201);
    }

    /**
     * Dispara una actualización (`mode = 'update'`) del ecommerce ya configurado de un cliente.
     *
     * Trivial por diseño (pedido de Lucas): recibe solo el cliente, resuelve su `ClientEcommerce`
     * ya configurado y siempre usa la última versión de la rama `master` — no recibe versión/tag.
     *
     * @param  Request  $request  { client_id }
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (404/422)
     */
    public function start_update_json(Request $request): JsonResponse
    {
        // client_id es el único dato que necesita el panel para disparar una actualización.
        $client_id = $request->input('client_id');
        if (empty($client_id)) {
            return response()->json(['error' => 'Falta client_id.'], 422);
        }

        $client = Client::find($client_id);
        if ($client === null) {
            return response()->json(['error' => 'Cliente no encontrado.'], 404);
        }

        $client_ecommerce = $client->client_ecommerce;
        if ($client_ecommerce === null) {
            return response()->json([
                'error' => 'El cliente no tiene una tienda (ecommerce) configurada.',
            ], 422);
        }

        // Configuración mínima requerida para poder actualizar: la tienda ya tiene que estar
        // instalada (una actualización no crea nada desde cero).
        $missing_fields = [];
        foreach (['domain', 'spa_url', 'api_url'] as $field) {
            if (empty($client_ecommerce->{$field})) {
                $missing_fields[] = $field;
            }
        }
        if (! empty($missing_fields)) {
            return response()->json([
                'error' => 'La tienda del cliente no está configurada todavía (faltan: '
                    . implode(', ', $missing_fields) . '). Instalala primero antes de actualizar.',
            ], 422);
        }

        $conflict_response = $this->assert_no_running_installation($client_ecommerce->id);
        if ($conflict_response !== null) {
            return $conflict_response;
        }

        $installation = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $client_ecommerce->id,
            'mode'                => 'update',
            'status'              => 'pendiente',
        ]);

        RunEcommerceInstallationJob::dispatch($installation->uuid);

        return response()->json([
            'model' => $this->fullModel('client_ecommerce_installation', $installation->id),
        ], 201);
    }

    /**
     * Líneas de log de una corrida ordenadas por `created_at`, para el polling del panel.
     *
     * @param  ClientEcommerceInstallation  $installation  Resuelta por route model binding.
     * @return JsonResponse  { status: string, models: EcommerceDeploymentLog[] }
     */
    public function logs_json(ClientEcommerceInstallation $installation): JsonResponse
    {
        $logs = $installation->logs()->orderBy('created_at')->get();

        return response()->json([
            'status' => $installation->status,
            'models' => $logs,
        ]);
    }

    /**
     * Valida que no haya otra corrida en curso ('instalando') para la misma tienda: instalación y
     * actualización comparten el mismo pipeline SSH/SFTP y no deben solaparse.
     *
     * @param  int  $client_ecommerce_id
     * @return JsonResponse|null  Respuesta 422 si hay conflicto; null si se puede continuar.
     */
    protected function assert_no_running_installation(int $client_ecommerce_id): ?JsonResponse
    {
        $already_running = ClientEcommerceInstallation::where('client_ecommerce_id', $client_ecommerce_id)
            ->where('status', 'instalando')
            ->exists();

        if (! $already_running) {
            return null;
        }

        return response()->json([
            'error' => 'Ya hay una corrida de instalación/actualización en curso para esta tienda.',
        ], 422);
    }
}
