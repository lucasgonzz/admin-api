<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\TrialInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * A6.1 (misión prueba-e2e-campana-imperio, 14/11/2026): el admin le habla al sistema de un negocio EN PRUEBA sin
 * pasar por el DNS.
 *
 * Una prueba vive en el MISMO VPS que el admin. Cuando se le asigna el alias (`api-<slug>.comerciocity.com`, etc.),
 * su A record se crea en ese momento y el resolvedor local del VPS puede seguir contestando NXDOMAIN hasta 10
 * minutos (caché negativa del dominio): en esa ventana toda llamada del admin al empresa-api del negocio fallaba con
 * «cURL error 6: Could not resolve host» (caso real: cliente 85, ticket 125, 14/11 16:43). Como el destino es esta
 * misma máquina, la IP se conoce de antemano: se le pasa a cURL con `CURLOPT_RESOLVE` (`host:puerto:ip`).
 *
 * 🔴 `CURLOPT_RESOLVE` solo precarga la caché de DNS de cURL para ese host y puerto: la URL, el header `Host`, el
 * SNI y la verificación del certificado siguen usando el NOMBRE del host. No se apaga ni se cambia ninguna
 * verificación TLS.
 *
 * 🔴 Solo aplica si el host de la URL es uno de los hosts (del pool o del alias) de una `TrialInstance` no borrada
 * de ESE cliente, y si la IP del VPS está configurada y es una IP. En cualquier otro caso devuelve `[]` y
 * `aplicar()` devuelve el pedido intacto: para un cliente que paga (shared de Hostinger o VPS con dominio propio)
 * la llamada sale exactamente igual que antes. Si algo falla al averiguarlo, también: nunca rompe una llamada.
 */
class PruebaResolucionLocal
{
    /**
     * Opciones de Guzzle para resolver el host de `$url` a la IP del VPS, o `[]` si no corresponde.
     *
     * @param Client|null $client Cliente dueño del sistema al que se llama.
     * @param string      $url    URL completa del pedido.
     *
     * @return array<string, mixed> `['curl' => [CURLOPT_RESOLVE => ['host:443:ip']]]` o `[]`.
     */
    public function opciones(?Client $client, string $url): array
    {
        if ($client === null || ! $client->exists || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $ip = trim((string) config('services.hostinger.vps_ip', ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        $partes = parse_url($url);
        $host   = is_array($partes) ? strtolower((string) ($partes['host'] ?? '')) : '';
        $scheme = is_array($partes) ? strtolower((string) ($partes['scheme'] ?? '')) : '';
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return [];
        }
        $puerto = isset($partes['port']) ? (int) $partes['port'] : ($scheme === 'https' ? 443 : 80);

        try {
            if (! in_array($host, $this->hosts_de_prueba((int) $client->id), true)) {
                return [];
            }
        } catch (\Throwable $e) {
            // Sin poder averiguarlo, el comportamiento de siempre.
            Log::warning('PruebaResolucionLocal: no se pudo leer la instancia de prueba del cliente.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }

        return ['curl' => [CURLOPT_RESOLVE => [$host . ':' . $puerto . ':' . $ip]]];
    }

    /**
     * Devuelve el pedido con la resolución local si corresponde; si no, el MISMO pedido sin tocar.
     *
     * @param PendingRequest $pedido
     * @param Client|null    $client
     * @param string         $url
     *
     * @return PendingRequest
     */
    public function aplicar(PendingRequest $pedido, ?Client $client, string $url): PendingRequest
    {
        $opciones = $this->opciones($client, $url);

        return $opciones === [] ? $pedido : $pedido->withOptions($opciones);
    }

    /**
     * Los hosts (pool y alias) de las instancias no borradas del cliente, en minúsculas.
     *
     * @param int $client_id
     *
     * @return array<int, string>
     */
    protected function hosts_de_prueba(int $client_id): array
    {
        if ($client_id <= 0) {
            return [];
        }

        $hosts = [];
        $instancias = TrialInstance::where('client_id', $client_id)
            ->where('estado', '!=', TrialInstance::ESTADO_BORRADA)
            ->get(['id', 'nombre', 'alias_slug']);

        foreach ($instancias as $instancia) {
            // Se valida antes de pedir los hosts: PruebaNombres tira con un nombre o un slug inválido.
            if (PruebaNombres::es_nombre_valido($instancia->nombre)) {
                $hosts = array_merge($hosts, array_values(PruebaNombres::hosts_del_pool($instancia->nombre)));
            }
            $slug = trim((string) $instancia->alias_slug);
            if ($slug !== '' && PruebaNombres::es_slug_valido($slug)) {
                $hosts = array_merge($hosts, array_values(PruebaNombres::hosts_del_slug($slug)));
            }
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }
}
