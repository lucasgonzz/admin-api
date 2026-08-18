<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishVersionService
{
    /**
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @param  ClientEmpresaApiUrlResolver|null  $api_url_resolver
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver ?? new ClientEmpresaApiUrlResolver();
    }

    /**
     * Sincroniza un upgrade existente al API del cliente.
     * Llamado desde el flujo de Actualizaciones en el paso "sistema_configurado".
     * No crea un nuevo registro: trabaja sobre el upgrade ya existente.
     */
    public function syncExisting(ClientVersionUpgrade $upgrade): ClientVersionUpgrade
    {
        $upgrade->loadMissing('client', 'to_version');

        $client  = $upgrade->client;
        $version = $upgrade->to_version;

        if (!$client->is_active) {
            return $this->markSyncFailed($upgrade, 'El cliente está inactivo.');
        }

        if ($version->status !== 'published') {
            return $this->markSyncFailed($upgrade, 'La versión no está en status "published".');
        }

        $sync_url = $this->api_url_resolver->admin_sync_url(
            $client,
            ClientEmpresaApiUrlResolver::PUBLISH_VERSION_PATH,
            $upgrade
        );

        if ($sync_url === '') {
            return $this->markSyncFailed(
                $upgrade,
                'No hay URL válida del empresa-api. Configure una ClientApi (URL con http/https) '
                . 'y asígnela como API activa o como API destino del upgrade.'
            );
        }

        if (empty($client->api_key)) {
            return $this->markSyncFailed(
                $upgrade,
                'El cliente no tiene api_key configurada (debe coincidir con ADMIN_API_INBOUND_KEY en empresa-api).'
            );
        }

        $payload = $this->buildPayload($upgrade, $version);

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($sync_url, $payload);

            if ($response->successful()) {
                $upgrade->update([
                    'status'                 => 'terminada',
                    'synced_at'              => now(),
                    'sistema_configurado_at' => $upgrade->sistema_configurado_at ?? now(),
                    'finished_at'            => now(),
                ]);
                $client->update(['current_version_id' => $version->id]);
                return $upgrade;
            }

            return $this->markSyncFailed(
                $upgrade,
                'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500)
            );
        } catch (\Throwable $e) {
            Log::error('PublishVersionService::syncExisting error: ' . $e->getMessage(), [
                'upgrade_id' => $upgrade->id,
                'client_id'  => $client->id,
                'version_id' => $version->id,
            ]);
            return $this->markSyncFailed($upgrade, 'Excepción: ' . $e->getMessage());
        }
    }

    /**
     * Crea un nuevo upgrade y lo sincroniza al cliente.
     * Conservado para compatibilidad; el flujo principal pasa a usar syncExisting().
     *
     * 🔴 Este camino (publicación directa desde la vista de una versión) NO tiene paso de
     * confirmación humana: nunca lo tuvo. Como `buildPayload()` ahora lee EXCLUSIVAMENTE
     * de la pivot `client_version_upgrade_versions`, si no la llenamos acá el payload
     * viajaría con `notifications: []` siempre. Se preserva el comportamiento histórico
     * (todo el rango), ahora calculado en orden semántico y solo entre versiones
     * publicadas, en vez de agregarle un paso de confirmación que nadie pidió.
     */
    public function publish(Client $client, Version $version, ?string $notes = null): ClientVersionUpgrade
    {
        $upgrade = ClientVersionUpgrade::create([
            'client_id'           => $client->id,
            'from_version_id'     => $client->current_version_id,
            'to_version_id'       => $version->id,
            'status'              => 'pendiente',
            'notes'               => $notes,
            'created_by_admin_id' => Auth::id(),
        ]);

        $fromVersion = $client->current_version;
        $toVersion   = $version;

        $candidates = VersionPathService::candidatesBetween($fromVersion, $toVersion);
        $upgrade->confirmed_versions()->sync($candidates->pluck('id')->all());

        return $this->syncExisting($upgrade);
    }

    /**
     * Arma el JSON hacia empresa-api: metadatos de la versión destino y notificaciones del
     * conjunto YA CONFIRMADO por el admin (pivot `client_version_upgrade_versions`), no de un
     * recálculo del rango por `id`. La FORMA del payload no cambia, solo de dónde sale el dato.
     */
    protected function buildPayload(ClientVersionUpgrade $upgrade, Version $toVersion): array
    {
        $notifications = [];
        $forClientId   = (int) $upgrade->client_id;

        $upgrade->loadMissing('confirmed_versions');
        $confirmed = VersionPathService::sortSemantically($upgrade->confirmed_versions);

        foreach (VersionPathService::aggregatedNotifications($confirmed, $forClientId) as $notification) {
            $notifications[] = [
                'uuid'       => $notification->uuid,
                'title'      => $notification->title,
                'body'       => $notification->body,
                // 🔴 Fórmula histórica (por `id` de versión), a propósito. La variante
                // posicional (`positionalNotificationSortOrder`) reinicia la posición en 1
                // en cada upgrade, así que dos actualizaciones sucesivas del mismo cliente
                // emitirían sort_order solapados. Por `id` es monótono y único a lo largo
                // del tiempo, y el contrato con empresa-api se mantiene compatible.
                'sort_order' => VersionPathService::globalNotificationSortOrder(
                    (int) $notification->version->id,
                    (int) $notification->sort_order
                ),
                'is_active'  => (bool) $notification->is_active,
            ];
        }

        return [
            'version' => [
                'uuid'         => $toVersion->uuid,
                'version'      => $toVersion->version,
                'title'        => $toVersion->title,
                'description'  => $toVersion->description,
                'published_at' => optional($toVersion->published_at)->toIso8601String(),
            ],
            'notifications' => $notifications,
        ];
    }

    protected function markSyncFailed(ClientVersionUpgrade $upgrade, string $reason): ClientVersionUpgrade
    {
        $upgrade->update([
            'notes' => trim(($upgrade->notes ? $upgrade->notes . ' | ' : '') . $reason),
        ]);
        return $upgrade;
    }

    /** @deprecated Usar markSyncFailed() */
    protected function markFailed(ClientVersionUpgrade $upgrade, string $reason): ClientVersionUpgrade
    {
        return $this->markSyncFailed($upgrade, $reason);
    }
}
