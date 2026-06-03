<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP reutilizable hacia api.kapso.ai con TLS configurable (WAMP / producción).
 */
class KapsoHttpClient
{
    /**
     * Arma un PendingRequest con headers opcionales y verificación SSL según config/services.kapso.
     *
     * @param string|null $api_key        Clave X-API-Key; null si la petición no la requiere.
     * @param int         $timeout        Segundos de timeout.
     * @param bool        $json_headers   false para multipart (upload media).
     *
     * @return PendingRequest
     */
    public static function make(?string $api_key = null, int $timeout = 15, bool $json_headers = true): PendingRequest
    {
        $http = Http::timeout($timeout);

        if ($api_key !== null && $api_key !== '') {
            $headers = ['X-API-Key' => $api_key];
            if ($json_headers) {
                $headers['Content-Type'] = 'application/json';
                $headers['Accept'] = 'application/json';
            }
            $http = $http->withHeaders($headers);
        }

        $verify_ssl = (bool) config('services.kapso.verify_ssl', true);
        $ca_bundle  = config('services.kapso.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
