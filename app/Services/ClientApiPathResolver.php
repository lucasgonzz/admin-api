<?php

namespace App\Services;

use App\Models\ClientApi;

/**
 * Resuelve el directorio raíz de la API de un cliente en su servidor, según el hosting_type.
 *
 * Único lugar donde vive esa regla. Estaba adentro de DeploymentService, que es donde nació, pero
 * ahora también la necesita el comando que audita los certificados de AFIP de los clientes ya
 * instalados. Dos copias de la misma convención de rutas es exactamente lo que después diverge sin
 * que nadie lo note, así que se extrajo acá y DeploymentService delega.
 */
class ClientApiPathResolver
{
    /**
     * Directorio raíz de la API del cliente en su servidor.
     *
     * - shared_hosting: prefijo de Hostinger + path relativo de la API
     *   (ej: domains/comerciocity.com/public_html/colman/api).
     * - vps: path absoluto construido como /home/api-{vps_path}/empresa-api.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si es un VPS sin vps_path configurado.
     */
    public function resolve(ClientApi $client_api): string
    {
        $hosting_type = $client_api->hosting_type ?? 'shared_hosting';

        if ($hosting_type === 'vps') {
            $vps_path = trim((string) ($client_api->vps_path ?? ''));
            if ($vps_path === '') {
                throw new \RuntimeException(
                    'La ClientApi tiene hosting_type=vps pero no tiene vps_path configurado. '
                    . 'Completá el campo vps_path antes de deployar.'
                );
            }

            return '/home/api-' . $vps_path . '/empresa-api';
        }

        return 'domains/comerciocity.com/public_html/' . $client_api->path;
    }

    /**
     * Tipo de credencial SSH/SFTP con la que se llega a esa API.
     *
     * @param  ClientApi  $client_api
     * @return string  'shared_hosting' | 'vps'
     */
    public function credential_type(ClientApi $client_api): string
    {
        return ($client_api->hosting_type ?? 'shared_hosting') === 'vps' ? 'vps' : 'shared_hosting';
    }
}
