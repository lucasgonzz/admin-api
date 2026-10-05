<?php

namespace App\Jobs;

use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Services\ImplementationUserSetupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica la configuración del formulario en el sistema del cliente (el "user setup") que pidió
 * `POST claude/implementations/{id}/user-setup`, y deja el resultado en la implementación.
 *
 * 🔴 ESTO LE VACÍA LA BASE AL CLIENTE. Del otro lado el setup arranca con `migrate:fresh --force` y
 * después siembra todo. Por eso este camino NO tiene "forzar" (el panel sí): el endpoint solo lo pide si
 * `user_setup_executed_at` está vacío, y este job es quien lo llena al terminar bien. Una vez lleno, ni
 * Claude ni nadie lo vuelve a correr por acá.
 *
 * POR QUÉ ES UN JOB. El panel llama a `trigger_user_setup()` DENTRO del request, con el techo de 15 s de
 * `services.client_api.timeout`, y un setup tarda minutos (medido ~565 s para el demo setup, que hace lo
 * mismo). En la cola `database` se puede esperar con calma: la llamada va con 600 s
 * (`TIMEOUT_DE_LA_LLAMADA`, el parámetro opcional de `trigger_user_setup()`) y el job con 900.
 *
 * Qué deja, en `stage 2 data.user_setup` (el endpoint escribe `en_curso` antes de despachar):
 *   - éxito: `estado: ok`, `terminado_at`, y además `user_setup_executed_at` (el candado) y la acción
 *     `user_setup` en `data.actions[]` de la etapa 2 (con `canal: claude`), que es la huella que lee el
 *     checklist del panel;
 *   - error: `estado: error`, `terminado_at` y el `error` con el motivo. No se registra la acción ni se
 *     llena el candado: el motivo queda ahí para decidir qué hacer.
 *
 * 🔴 UN ERROR NO SIGNIFICA QUE NO CORRIÓ. Si la llamada se corta por timeout o por la red, empresa-api
 * sigue corriendo el setup (el endpoint usa `ignore_user_abort`) y puede terminar bien después de que acá
 * se lo dio por fallado. Por eso el motivo de esos errores lo dice textual: antes de reintentar hay que
 * mirar el sistema del cliente, porque un reintento le hace OTRO `migrate:fresh`. Y un 409 de empresa-api
 * ("hay otro setup corriendo") es un error y NO se reintenta.
 *
 * `$timeout = 900` por debajo del `retry_after` de la conexión `database` (2400): ver `config/queue.php`.
 */
class EjecutarUserSetupDeImplementacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Techo de la llamada HTTP a empresa-api, en segundos. Tiene que quedar por debajo de `$timeout`
     * (900) con margen para escribir el resultado: si la llamada pudiera durar lo mismo que el job, el
     * worker lo cortaría antes de dejar el estado.
     */
    const TIMEOUT_DE_LA_LLAMADA = 600;

    /**
     * Tiempo máximo de ejecución del job, en segundos. 🔴 Por debajo de `retry_after` (2400).
     *
     * @var int
     */
    public $timeout = 900;

    /**
     * Sin reintentos automáticos: un segundo intento sería otro `migrate:fresh`.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id de la implementación.
     *
     * @var int
     */
    private $implementation_id;

    /**
     * @param int|Implementation $implementation La implementación (o su id).
     */
    public function __construct($implementation)
    {
        $this->implementation_id = $implementation instanceof Implementation ? (int) $implementation->id : (int) $implementation;
    }

    /**
     * Corre el user setup y deja el resultado.
     *
     * Nunca deja escapar una excepción de la llamada: cualquier cosa que salga mal queda escrita como
     * `error` en la implementación, que es donde se mira, y el job termina bien. (Un job que revienta
     * con `tries = 1` solo llena `failed_jobs` de ruido y no agrega nada que el estado no diga.)
     *
     * @return void
     */
    public function handle()
    {
        $implementation = Implementation::find($this->implementation_id);

        if ($implementation === null) {
            return;
        }

        try {
            $resultado = (new ImplementationUserSetupService())->trigger_user_setup($implementation, self::TIMEOUT_DE_LA_LLAMADA);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('EjecutarUserSetupDeImplementacionJob: excepción inesperada.', [
                'implementation_id' => $this->implementation_id,
                'error'             => $e->getMessage(),
            ]);

            $resultado = ['ok' => false, 'message' => 'Excepción al aplicar la configuración: ' . $e->getMessage()];
        }

        $this->terminar($resultado['ok'], (string) $resultado['message']);
    }

    /**
     * Último recurso: el worker dio por fallado este job y `handle()` no llegó a escribir el resultado
     * (el `$timeout`, un `SIGALRM` que mata el proceso, o `MaxAttemptsExceededException`).
     *
     * Sin esto el registro se quedaba en `en_curso` y `user-setup` frenaba con 409 para siempre. Solo
     * actúa si SIGUE en `en_curso`: un resultado que `handle()` ya escribió no se pisa.
     *
     * @param \Throwable $e Motivo del fallo.
     *
     * @return void
     */
    public function failed(\Throwable $e)
    {
        $this->terminar(false, 'El job se cortó antes de terminar: ' . $e->getMessage(), true);
    }

    /**
     * Escribe el resultado en la implementación.
     *
     * Todo adentro de UNA transacción con la implementación bloqueada: el candado
     * (`user_setup_executed_at`), el registro de la etapa 2 y la acción tienen que quedar los tres o
     * ninguno, y `failed()` no puede pisar a `handle()` ni al revés.
     *
     * @param bool   $ok                 true = empresa-api aplicó la configuración.
     * @param string $mensaje            El motivo (en error) o la confirmación (en éxito).
     * @param bool   $solo_si_en_curso   true = no tocar nada si el registro ya no está `en_curso`
     *                                   (lo usa `failed()`).
     *
     * @return void
     */
    private function terminar($ok, $mensaje, $solo_si_en_curso = false)
    {
        DB::transaction(function () use ($ok, $mensaje, $solo_si_en_curso) {
            $implementation = Implementation::query()->whereKey($this->implementation_id)->lockForUpdate()->first();

            if ($implementation === null) {
                return;
            }

            $etapa = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 2)->lockForUpdate()->first();

            if ($etapa === null) {
                return;
            }

            $datos    = is_array($etapa->data) ? $etapa->data : [];
            $registro = isset($datos['user_setup']) && is_array($datos['user_setup']) ? $datos['user_setup'] : [];

            if ($solo_si_en_curso && (isset($registro['estado']) ? $registro['estado'] : null) !== 'en_curso') {
                return;
            }

            $ahora = now()->toISOString();

            $registro['estado']       = $ok ? 'ok' : 'error';
            $registro['terminado_at'] = $ahora;
            $registro['error']        = $ok ? null : $this->explicar_el_error($mensaje);

            if ($ok) {
                $implementation->user_setup_executed_at = now();
                $implementation->save();

                $acciones            = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];
                $acciones[]          = ['action' => 'user_setup', 'stage' => 2, 'at' => $ahora, 'canal' => 'claude', 'origen' => 'claude'];
                $datos['actions']    = $acciones;
            }

            $datos['user_setup'] = $registro;
            $etapa->data         = $datos;
            $etapa->save();
        });
    }

    /**
     * El motivo de un error, con lo que hay que saber antes de decidir qué hacer.
     *
     * 🔴 Un error de conexión o de timeout NO prueba que el setup no haya corrido: empresa-api lo sigue
     * ejecutando aunque acá se corte la espera. Y un 409 es "ya hay un setup corriendo": ni se reintenta
     * ni es un fallo del setup. Los dos casos se reconocen por el texto que arma
     * `ImplementationUserSetupService::trigger_user_setup()`.
     *
     * @param string $mensaje El mensaje que devolvió el servicio.
     *
     * @return string
     */
    private function explicar_el_error($mensaje)
    {
        if (strpos($mensaje, 'status 409') !== false) {
            return $mensaje . ' — Ya hay un setup corriendo del otro lado: NO se reintenta. Esperá a que termine y mirá el sistema del cliente.';
        }

        if (strpos($mensaje, 'Error de conexión') !== false) {
            return $mensaje . ' — OJO: el setup pudo haber seguido corriendo (o haber terminado) del otro lado aunque acá se cortó la espera. '
                . 'Mirá el sistema del cliente ANTES de reintentar: un reintento le vuelve a vaciar la base (migrate:fresh).';
        }

        return $mensaje;
    }
}
