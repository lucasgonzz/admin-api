<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Services\ClientEmpresaApiUrlResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Contrato C3 (admin → empresa): los días de la prueba.
 *
 *  - `POST {erp}/api/admin-sync/prueba/vencimiento` `{ "vence_at": "ISO" | null }` → 200 `{ "vence_at" }`.
 *    `null` saca el vencimiento (contrató).
 *  - `GET {erp}/api/admin-sync/prueba/estado` → 200 `{ vence_at, vencida, semilla_presente, hitos }`.
 *
 * Los hace M3 en empresa. 🔴 Un empresa viejo contesta 404 y el admin lo TOLERA: la fecha queda
 * escrita en el admin igual, con `estado = sin_ruta` para que la pantalla lo diga. Nunca tira.
 */
class PruebaVencimientoService
{
    const ESTADO_OK           = 'ok';
    const ESTADO_SIN_RUTA     = 'sin_ruta';
    const ESTADO_SIN_URL      = 'sin_url';
    const ESTADO_SIN_API_KEY  = 'sin_api_key';
    const ESTADO_ERROR        = 'error';

    /** @var ClientEmpresaApiUrlResolver */
    private $resolver;

    public function __construct(?ClientEmpresaApiUrlResolver $resolver = null)
    {
        $this->resolver = $resolver ?: new ClientEmpresaApiUrlResolver();
    }

    /**
     * Le dice al ERP cuándo vence la prueba (o que ya no vence).
     *
     * @return array<string, mixed> `['estado' => ..., 'mensaje' => ..., 'vence_at' => ...]`
     */
    public function enviar_vencimiento(Client $client, ?Carbon $vence_at): array
    {
        $cuerpo = ['vence_at' => $vence_at === null ? null : $vence_at->copy()->setTimezone('America/Argentina/Buenos_Aires')->toIso8601String()];

        return $this->llamar($client, 'POST', 'prueba/vencimiento', $cuerpo);
    }

    /**
     * El estado de la prueba según el ERP (C3 GET), o el motivo por el que no se pudo leer.
     *
     * @return array<string, mixed>
     */
    public function estado(Client $client): array
    {
        return $this->llamar($client, 'GET', 'prueba/estado', []);
    }

    /**
     * Empresa conserva el consumo y devuelve el saldo actualizado al cambiar el cupo total.
     */
    public function enviar_cupo_imagenes(Client $client, int $tope): array
    {
        return $this->llamar($client, 'POST', 'prueba/cupo-imagenes', ['tope' => $tope]);
    }

    public static function leer_cupo_imagenes(array $cuerpo): ?array
    {
        $imagenes = $cuerpo['imagenes'] ?? null;
        if (! is_array($imagenes)) {
            return null;
        }

        $cupo = [];
        foreach (['tope', 'usadas', 'restantes'] as $campo) {
            if (! isset($imagenes[$campo]) || ! is_int($imagenes[$campo]) || $imagenes[$campo] < 0) {
                return null;
            }
            $cupo[$campo] = $imagenes[$campo];
        }

        return $cupo;
    }

    /**
     * @return array<string, mixed>
     */
    private function llamar(Client $client, string $metodo, string $ruta, array $cuerpo): array
    {
        $clave = trim((string) $client->api_key);
        if ($clave === '') {
            return ['estado' => self::ESTADO_SIN_API_KEY, 'mensaje' => 'El cliente no tiene api_key cargada en el admin.'];
        }

        /* `admin_sync_url()` devuelve la RAÍZ del ERP más la ruta: el `api/admin-sync/` va acá, como en los otros
           llamadores (`EnviarMensajeAlAsistenteJob::RUTA_MENSAJES`). */
        try {
            $url = $this->resolver->admin_sync_url($client, 'api/admin-sync/' . ltrim($ruta, '/'));
        } catch (\Throwable $e) {
            $url = '';
        }
        if ($url === '') {
            return ['estado' => self::ESTADO_SIN_URL, 'mensaje' => 'El cliente no tiene una URL de empresa-api resoluble.'];
        }

        try {
            $pendiente = Http::withHeaders(['Accept' => 'application/json', 'X-Admin-Api-Key' => $clave])
                ->timeout((int) config('services.prueba.erp_timeout', 20));

            $response = $metodo === 'GET' ? $pendiente->get($url) : $pendiente->post($url, $cuerpo);
        } catch (\Throwable $e) {
            Log::warning('PruebaVencimientoService: no se llegó al ERP.', ['client_id' => $client->id, 'ruta' => $ruta, 'error' => $e->getMessage()]);

            return ['estado' => self::ESTADO_ERROR, 'mensaje' => 'No se llegó al sistema del cliente: ' . $e->getMessage()];
        }

        if ($response->status() === 404) {
            return ['estado' => self::ESTADO_SIN_RUTA, 'mensaje' => 'La versión del sistema del cliente todavía no tiene la ruta ' . $ruta . ' (se aplica al actualizarlo).'];
        }

        if (! $response->successful()) {
            return ['estado' => self::ESTADO_ERROR, 'mensaje' => 'El sistema del cliente respondió HTTP ' . $response->status() . '.', 'status' => $response->status()];
        }

        $json = $response->json();

        return array_merge(['estado' => self::ESTADO_OK, 'mensaje' => ''], is_array($json) ? $json : []);
    }
}
