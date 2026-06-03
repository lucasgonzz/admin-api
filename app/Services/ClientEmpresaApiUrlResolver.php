<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;

/**
 * Resuelve la URL base del empresa-api de un cliente para llamadas salientes (admin-sync, etc.).
 *
 * Prioridad: API destino del upgrade → API activa del cliente → primera ClientApi → clients.api_url (legacy).
 */
class ClientEmpresaApiUrlResolver
{
    /**
     * Ruta relativa del endpoint publish-version en empresa-api.
     */
    const PUBLISH_VERSION_PATH = 'api/admin-sync/publish-version';

    /**
     * Ruta relativa para actualizar default_version / api_url tras deployment.
     */
    const UPDATE_DEFAULT_VERSION_PATH = 'api/admin-sync/update-default-version';

    /**
     * Devuelve la URL base del empresa-api (sin slash final) o cadena vacía si no hay URL válida.
     *
     * @param  Client  $client
     * @param  ClientVersionUpgrade|null  $upgrade
     * @return string
     */
    public function resolve_base_url(Client $client, ?ClientVersionUpgrade $upgrade = null): string
    {
        $client->loadMissing('active_client_api', 'client_apis');

        if ($upgrade !== null) {
            $upgrade->loadMissing('target_client_api');
        }

        $candidates = [];

        if ($upgrade !== null && $upgrade->target_client_api instanceof ClientApi) {
            $candidates[] = $upgrade->target_client_api->url;
        }

        if ($client->active_client_api instanceof ClientApi) {
            $candidates[] = $client->active_client_api->url;
        }

        foreach ($client->client_apis as $client_api) {
            $candidates[] = $client_api->url;
        }

        $candidates[] = $client->api_url;

        foreach ($candidates as $candidate_url) {
            $normalized = $this->normalize_base_url($candidate_url);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * URL completa hacia un path de admin-sync (p. ej. publish-version).
     *
     * @param  Client  $client
     * @param  string  $path  Ruta sin slash inicial (ej. api/admin-sync/publish-version)
     * @param  ClientVersionUpgrade|null  $upgrade
     * @return string  Vacío si no se pudo resolver la base
     */
    public function admin_sync_url(Client $client, string $path, ?ClientVersionUpgrade $upgrade = null): string
    {
        $base_url = $this->resolve_base_url($client, $upgrade);
        if ($base_url === '') {
            return '';
        }

        return $base_url . '/' . ltrim($path, '/');
    }

    /**
     * Normaliza y valida que la URL sea absoluta (http/https) con host resoluble.
     *
     * @param  string|null  $url
     * @return string
     */
    protected function normalize_base_url(?string $url): string
    {
        $url = rtrim(trim((string) $url), '/');
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            return '';
        }

        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return '';
        }

        return $url;
    }
}
