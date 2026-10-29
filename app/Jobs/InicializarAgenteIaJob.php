<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\IaApp\IaDirectorioService;
use App\Services\IaApp\IaEmpresaCliente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cuando un cliente queda con «Agentes de IA» activa, le deja creada la memoria de contexto del dueño y lo
 * anota en el directorio del login de la app (misión cruzada `app-ia-y-memoria`, 29/10/2026, plan §4 A.3):
 *
 *  1. `PUT api/admin-sync/agente-contexto/inicializar` (contrato C1.3, idempotente).
 *  2. `IaDirectorioService::sincronizar()` (contrato C1.2).
 *
 * Lo despacha `ClientAgentesIaService` al confirmar un PUT que dejó la extensión activa. Es best-effort: un
 * 404 (empresa con una versión vieja) o un 409 (extensión apagada de nuevo) se anotan y listo; solo la falta
 * de conexión se reintenta.
 *
 * 🔴 Se despacha con `->onConnection('database')` (mismo motivo que `SyncClientSessionLockJob`): con la
 * conexión default `sync` correría adentro del request que activó la extensión, sumándole hasta dos timeouts
 * de 15 s. Lo levanta el `queue:work database --stop-when-empty` que el scheduler corre cada minuto (cola
 * `default`).
 */
class InicializarAgenteIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Ruta del contrato C1.3. */
    const RUTA_INICIALIZAR = 'api/admin-sync/agente-contexto/inicializar';

    /**
     * Tres vueltas: la de ahora y dos reintentos si el sistema del cliente no contestó.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Segundos entre vueltas.
     *
     * @var array<int, int>
     */
    public $backoff = [60, 600];

    /**
     * 🔴 El id y no el modelo: entre el despacho y la corrida el cliente puede cambiar de frente.
     *
     * @var int
     */
    private $client_id;

    /**
     * @param Client|int $client
     */
    public function __construct($client)
    {
        $this->client_id = (int) ($client instanceof Client ? $client->id : $client);
    }

    /**
     * @return int
     */
    public function client_id()
    {
        return $this->client_id;
    }

    /**
     * @param IaEmpresaCliente    $empresa
     * @param IaDirectorioService $directorio
     *
     * @return void
     */
    public function handle(IaEmpresaCliente $empresa, IaDirectorioService $directorio)
    {
        $client = Client::find($this->client_id);

        if ($client === null) {
            Log::warning('InicializarAgenteIaJob: el cliente ya no existe.', ['client_id' => $this->client_id]);

            return;
        }

        $respuesta = $empresa->pedir($client, 'PUT', self::RUTA_INICIALIZAR, [], 15);
        $status    = $respuesta['status'];

        if ($status === null && $respuesta['motivo'] === 'sin_conexion' && $this->attempts() < $this->tries) {
            // El sistema del cliente no contestó: se vuelve a probar más tarde (memoria y directorio juntos).
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        Log::info('InicializarAgenteIaJob: memoria del agente.', [
            'client_id' => (int) $client->id,
            'status'    => $status,
            'motivo'    => $respuesta['motivo'],
            'creada'    => is_array($respuesta['json']) && isset($respuesta['json']['creada']) ? (bool) $respuesta['json']['creada'] : null,
        ]);

        if ($status === 404 || $status === 405) {
            // Empresa con una versión que todavía no tiene la memoria: se ignora (plan §4 A.3).
            Log::info('InicializarAgenteIaJob: el sistema del cliente no tiene la memoria del agente (versión vieja).', [
                'client_id' => (int) $client->id,
            ]);
        }

        try {
            $directorio->sincronizar($client);
        } catch (\Throwable $e) {
            Log::warning('InicializarAgenteIaJob: falló la sincronización del directorio.', [
                'client_id' => (int) $client->id,
                'error'     => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
