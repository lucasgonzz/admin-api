<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientNotificationRead;
use App\Models\VersionNotification;
use Carbon\Carbon;

class RegisterNotificationReadService
{
    /**
     * Registra de forma idempotente una lectura reportada por un API cliente.
     * Devuelve la fila (creada o existente) o null si falta información.
     */
    public function register(Client $client, array $payload): ?ClientNotificationRead
    {
        $clientUuid = $payload['client_uuid'] ?? null;
        $notificationUuid = $payload['notification_admin_uuid'] ?? null;
        $readAt = $payload['read_at'] ?? null;

        // No usar empty($clientUserId): en PHP empty(0) y empty('0') son true y un id válido dejaría de persistir.
        if (empty($clientUuid) || empty($notificationUuid) || ! array_key_exists('client_user_id', $payload)
            || $payload['client_user_id'] === null || $payload['client_user_id'] === '') {
            return null;
        }
        $clientUserId = $payload['client_user_id'];

        if ($client->uuid !== $clientUuid) {
            return null;
        }

        $notification = VersionNotification::where('uuid', $notificationUuid)->first();
        if (is_null($notification)) {
            return null;
        }

        $read = ClientNotificationRead::firstOrNew([
            'client_id' => $client->id,
            'version_notification_id' => $notification->id,
            'client_user_id' => (int) $clientUserId,
        ]);

        if (!$read->exists) {
            $read->read_at = $readAt ? Carbon::parse($readAt) : now();
            $read->client_user_name = $payload['client_user_name'] ?? null;
            $read->client_user_email = $payload['client_user_email'] ?? null;
            $read->save();
        }

        return $read;
    }
}
