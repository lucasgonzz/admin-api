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
     * Ruta relativa del endpoint de branding (logo/color/nombre) en empresa-api.
     */
    const BRANDING_PATH = 'api/admin-sync/branding';

    /**
     * Devuelve la URL base del empresa-api (sin slash final) o cadena vacía si no hay URL válida.
     * Cada candidato se normaliza con su hosting_type asociado, agregando /public en shared_hosting si corresponde.
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

        // Lista de candidatos: cada uno es un array [url, hosting_type]
        $candidates = [];

        // Prioridad 1: API destino del upgrade
        if ($upgrade !== null && $upgrade->target_client_api instanceof ClientApi) {
            $hosting_type = $upgrade->target_client_api->hosting_type ?? 'shared_hosting';
            $candidates[] = [$upgrade->target_client_api->url, $hosting_type];
        }

        // Prioridad 2: API activa del cliente
        if ($client->active_client_api instanceof ClientApi) {
            $hosting_type = $client->active_client_api->hosting_type ?? 'shared_hosting';
            $candidates[] = [$client->active_client_api->url, $hosting_type];
        }

        // Prioridad 3: Cada ClientApi del cliente
        foreach ($client->client_apis as $client_api) {
            $hosting_type = $client_api->hosting_type ?? 'shared_hosting';
            $candidates[] = [$client_api->url, $hosting_type];
        }

        // Prioridad 4: clients.api_url (legacy, sin ClientApi detras)
        // Se trata como VPS porque es un valor histórico cargado a mano y no sabemos
        // con qué convención se guardó; mejor no agregar /public a un legacy.
        $candidates[] = [$client->api_url, 'vps'];

        foreach ($candidates as [$candidate_url, $hosting_type]) {
            $normalized = $this->normalize_api_base_url($candidate_url, $hosting_type);
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
     * Normaliza y valida la URL, agregando /public en hosting compartido si corresponde.
     *
     * Realiza trim, rtrim de slash, validación de esquema http/https y host.
     * En shared_hosting o null: agrega /public si la URL no termina ya en /public.
     * En vps: devuelve sin modificar (no agrega /public a valores legacy).
     *
     * @param  string|null  $url
     * @param  string|null  $hosting_type  'shared_hosting', 'vps' o null (default shared_hosting)
     * @return string  URL normalizada o cadena vacía si no es válida
     */
    public function normalize_api_base_url(?string $url, ?string $hosting_type = null): string
    {
        // Normalizar y validar usando la lógica base
        $url = $this->normalize_base_url($url);
        if ($url === '') {
            return '';
        }

        // Default a shared_hosting si no se especifica
        if ($hosting_type === null) {
            $hosting_type = 'shared_hosting';
        }

        // En shared_hosting: agregar /public si no termina ya en él
        if ($hosting_type === 'shared_hosting') {
            if (substr($url, -7) !== '/public') {
                $url .= '/public';
            }
        }

        // En vps: devolver sin modificar

        return $url;
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
