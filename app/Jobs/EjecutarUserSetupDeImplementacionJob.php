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
 * mismo). En la cola `database` se puede esperar con calma: la llamada va con 1200 s
 * (`TIMEOUT_DE_LA_LLAMADA`, el parámetro opcional de `trigger_user_setup()`) y el job con 1500. Los
 * 565 s medidos son de un setup SANO sobre una base chica: con un cliente con catálogo, un proxy lento o
 * un servidor cargado el margen de 600 s se quedaba corto, y un timeout acá no frena el setup del otro lado
 * (ver "Un error no significa que no corrió"): solo deja el registro en `error` sobre algo que sí corrió.
 *
 * 🔴 EL JOB NO ES RE-EJECUTABLE: LLEVA UN TOKEN. El worker puede entregar el mismo job dos veces (el
 * `retry_after` de la cola, un worker que murió con el job reservado) y el endpoint puede despachar un
 * intento nuevo mientras uno viejo sigue en la cola. Cada despacho lleva como token el `iniciado_at` del
 * registro que lo despachó, y el job solo llama al cliente si, bajo `lockForUpdate` de la implementación:
 *   - el candado (`user_setup_executed_at`) está vacío, y
 *   - el registro de la etapa 2 sigue diciendo `en_curso` con ESE token.
 * Si no, sale SIN llamar al cliente y lo deja dicho en el log. Al terminar vuelve a mirar lo mismo bajo
 * lock: si mientras la llamada estaba en vuelo se despachó otro intento (otro token) o el candado se llenó
 * (alguien conciliado), NO pisa el estado. Un job viejo que arranca tarde se descarta solo; por eso volver
 * a intentar es seguro.
 *
 * Qué deja, en `stage 2 data.user_setup` (el endpoint escribe `en_curso` antes de despachar):
 *   - éxito: `estado: ok`, `terminado_at`, y además `user_setup_executed_at` (el candado) y la acción
 *     `user_setup` en `data.actions[]` de la etapa 2 (con `canal: claude`), que es la huella que lee el
 *     checklist del panel;
 *   - error: `estado: error`, `terminado_at`, el `error` con el motivo y `puede_haber_corrido` (true/false).
 *     No se registra la acción ni se llena el candado: el motivo queda ahí para decidir qué hacer.
 *
 * 🔴 UN ERROR NO SIGNIFICA QUE NO CORRIÓ. Si la llamada se corta por timeout o por la red, si un proxy
 * contesta 502/503/504/524 (cortó la espera pero el origen sigue), o si el worker mata el job, empresa-api
 * sigue corriendo el setup (el endpoint usa `ignore_user_abort`) y puede terminar bien después de que acá se
 * lo dio por fallado. Por eso el motivo de esos errores lo dice textual y manda a mirar si el dueño existe
 * en el sistema del cliente ANTES de reintentar —un reintento le hace OTRO `migrate:fresh`—: si existe,
 * se concilia (`conciliar: true`, que no vuelve a correr nada); si no, se reintenta (`reintentar: true`).
 * Un 409 de empresa-api ("hay otro setup corriendo") tampoco se reintenta. Lo único de lo que se sabe que
 * NO corrió es un rechazo 4xx (el pedido ni se ejecutó) o que el servicio ni llegó a llamar.
 *
 * `$timeout = 1500` por debajo del `retry_after` de la conexión `database` (2400): ver `config/queue.php`.
 */
class EjecutarUserSetupDeImplementacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Techo de la llamada HTTP a empresa-api, en segundos. Tiene que quedar por debajo de `$timeout`
     * (1500) con margen para escribir el resultado: si la llamada pudiera durar lo mismo que el job, el
     * worker lo cortaría antes de dejar el estado.
     */
    const TIMEOUT_DE_LA_LLAMADA = 1200;

    /**
     * Qué hacer ANTES de reintentar un setup que pudo haber corrido. Va al final del motivo de todos los
     * errores que no prueban que no corrió, para que el que lo lea (una sesión de Claude, o Lucas) sepa los
     * dos caminos sin tener que ir a buscar el endpoint.
     */
    const ANTES_DE_REINTENTAR = 'ANTES de reintentar, mirá si el dueño existe en el sistema del cliente: si existe, el setup corrió '
        . '—conciliá con `conciliar: true` (marca el user setup como aplicado SIN volver a correrlo)—; si no existe, reintentá con '
        . '`reintentar: true` (un reintento le vuelve a vaciar la base: migrate:fresh).';

    /**
     * Tiempo máximo de ejecución del job, en segundos. 🔴 Por debajo de `retry_after` (2400).
     *
     * @var int
     */
    public $timeout = 1500;

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
     * El `iniciado_at` del registro que despachó este job: lo que lo identifica como EL intento vigente.
     *
     * @var string
     */
    private $token;

    /**
     * @param int|Implementation $implementation La implementación (o su id).
     * @param string             $iniciado_at    El `iniciado_at` del registro `en_curso` que lo despachó. No tiene
     *                                           default a propósito: un job sin token no puede saber si le toca, y
     *                                           uno que no sabe si le toca no tiene que correr.
     */
    public function __construct($implementation, $iniciado_at)
    {
        $this->implementation_id = $implementation instanceof Implementation ? (int) $implementation->id : (int) $implementation;
        $this->token             = (string) $iniciado_at;
    }

    /**
     * Corre el user setup y deja el resultado.
     *
     * Nunca deja escapar una excepción de la llamada: cualquier cosa que salga mal queda escrita como
     * `error` en la implementación, que es donde se mira, y el job termina bien. (Un job que revienta
     * con `tries = 1` solo llena `failed_jobs` de ruido y no agrega nada que el estado no diga.)
     *
     * Antes de llamar al cliente toma el turno (`tomar_el_turno()`): si no le toca, no hace nada.
     *
     * @return void
     */
    public function handle()
    {
        if (! $this->tomar_el_turno()) {
            return;
        }

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
     * Sin esto el registro se quedaba en `en_curso` y `user-setup` frenaba con 409 hasta darse por colgado.
     * Solo actúa si el registro SIGUE en `en_curso` y es el de ESTE token: un resultado que `handle()` ya
     * escribió, o un intento más nuevo, no se pisan. Y el motivo dice que el setup PUDO HABER CORRIDO: la
     * llamada pudo estar en vuelo cuando el worker mató el proceso.
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
     * ¿Le toca a este job llamar al cliente? Lo decide bajo `lockForUpdate` de la implementación y, si no,
     * deja dicho en el log por qué se descarta.
     *
     * Le toca solo si el candado está vacío Y el registro de la etapa 2 sigue diciendo `en_curso` con el
     * token de este job. El lock se suelta al volver (la llamada dura minutos y no se puede tener una
     * transacción abierta todo ese tiempo): lo que cierra la carrera del final es `terminar()`, que vuelve
     * a mirar lo mismo bajo lock antes de escribir.
     *
     * @return bool
     */
    private function tomar_el_turno()
    {
        return DB::transaction(function () {
            $implementation = Implementation::query()->whereKey($this->implementation_id)->lockForUpdate()->first();

            if ($implementation === null) {
                $this->dejar_dicho('la implementación ya no existe: se descarta el job sin llamar al cliente.');

                return false;
            }

            if ($implementation->user_setup_executed_at !== null) {
                $this->dejar_dicho('el user setup ya figura aplicado (user_setup_executed_at lleno): se descarta el job sin llamar al cliente.');

                return false;
            }

            $etapa = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 2)->lockForUpdate()->first();

            $registro = $this->registro_de($etapa);

            if ((isset($registro['estado']) ? $registro['estado'] : null) !== 'en_curso') {
                $this->dejar_dicho('el registro del user setup no está en_curso (dice "' . (isset($registro['estado']) ? $registro['estado'] : 'nada')
                    . '"): se descarta el job sin llamar al cliente.');

                return false;
            }

            if ((isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : '') !== $this->token) {
                $this->dejar_dicho('el registro es de otro intento (el token del job no coincide con su iniciado_at): se descarta el job '
                    . 'viejo sin llamar al cliente.');

                return false;
            }

            return true;
        });
    }

    /**
     * Escribe el resultado en la implementación.
     *
     * Todo adentro de UNA transacción con la implementación bloqueada: el candado
     * (`user_setup_executed_at`), el registro de la etapa 2 y la acción tienen que quedar los tres o
     * ninguno, y `failed()` no puede pisar a `handle()` ni al revés.
     *
     * 🔴 No escribe nada si, mientras la llamada estaba en vuelo, el candado se llenó o el registro pasó a
     * ser de otro intento (otro `iniciado_at`): lo que quedó escrito es más nuevo que lo que este job sabe.
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

            if ($implementation->user_setup_executed_at !== null) {
                $this->dejar_dicho('el candado se llenó mientras este job corría: no se pisa el estado (resultado de este job: '
                    . ($ok ? 'ok' : 'error') . ').');

                return;
            }

            $etapa = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 2)->lockForUpdate()->first();

            if ($etapa === null) {
                return;
            }

            $datos    = is_array($etapa->data) ? $etapa->data : [];
            $registro = isset($datos['user_setup']) && is_array($datos['user_setup']) ? $datos['user_setup'] : [];

            if ((isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : '') !== $this->token) {
                $this->dejar_dicho('el registro pasó a ser de otro intento mientras este job corría (el token ya no coincide): no se pisa el '
                    . 'estado (resultado de este job: ' . ($ok ? 'ok' : 'error') . ').');

                return;
            }

            if ($solo_si_en_curso && (isset($registro['estado']) ? $registro['estado'] : null) !== 'en_curso') {
                return;
            }

            $ahora = now()->toISOString();

            $registro['estado']       = $ok ? 'ok' : 'error';
            $registro['terminado_at'] = $ahora;

            if ($ok) {
                $registro['error'] = null;
                unset($registro['puede_haber_corrido']);

                $implementation->user_setup_executed_at = now();
                $implementation->save();

                $acciones            = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];
                $acciones[]          = ['action' => 'user_setup', 'stage' => 2, 'at' => $ahora, 'canal' => 'claude', 'origen' => 'claude'];
                $datos['actions']    = $acciones;
            } else {
                $clasificado = $this->explicar_el_error($mensaje, $solo_si_en_curso);

                $registro['error']               = $clasificado['texto'];
                $registro['puede_haber_corrido'] = $clasificado['puede_haber_corrido'];
            }

            $datos['user_setup'] = $registro;
            $etapa->data         = $datos;
            $etapa->save();
        });
    }

    /**
     * El motivo de un error, con lo que hay que saber antes de decidir qué hacer, y si el setup PUDO HABER
     * CORRIDO del otro lado.
     *
     * 🔴 La pregunta que importa de un error es si el `migrate:fresh` del otro lado llegó a correr, porque
     * un reintento se lo hace otra vez. Casi ningún error lo prueba:
     *   - 502, 503, 504 y 524 son del proxy o del balanceador del cliente: cortaron la espera, pero el
     *     origen sigue corriendo el setup (empresa-api usa `ignore_user_abort`);
     *   - un error de conexión o de timeout es lo mismo;
     *   - `failed()` (el worker mató el job) también: la llamada pudo estar en vuelo;
     *   - cualquier otro 5xx, un 500 incluido, puede ser el sembrado que se cortó DESPUÉS de que el
     *     `migrate:fresh` vació la base: el sistema queda a medias;
     *   - un 408 es un timeout, y un 409 es "hay otro setup corriendo (o ya corrió)".
     * Lo único de lo que se sabe que NO corrió es un 4xx (empresa-api rechazó el pedido antes de ejecutar
     * nada: sin credencial, sin la ruta, validación) o que el servicio ni llegó a llamar (sin cliente, sin
     * `client_api` activa). Ante un texto que no se reconoce se asume que pudo haber corrido: equivocarse
     * para ese lado cuesta una mirada al sistema del cliente; para el otro, otro `migrate:fresh`.
     *
     * Los casos se reconocen por el texto que arma `ImplementationUserSetupService::trigger_user_setup()`.
     *
     * @param string $mensaje                El mensaje que devolvió el servicio (o el de `failed()`).
     * @param bool   $cortado_por_el_worker  true = lo escribe `failed()`: el worker mató el job.
     *
     * @return array{texto: string, puede_haber_corrido: bool}
     */
    private function explicar_el_error($mensaje, $cortado_por_el_worker = false)
    {
        $espera_cortada = 'OJO: el setup pudo haber seguido corriendo (o haber terminado) del otro lado aunque acá se cortó la espera. ';

        if ($cortado_por_el_worker) {
            return ['texto' => $mensaje . ' — ' . $espera_cortada . self::ANTES_DE_REINTENTAR, 'puede_haber_corrido' => true];
        }

        if (strpos($mensaje, 'No se encontró el cliente') !== false || strpos($mensaje, 'todavía no tiene una client_api activa') !== false
            || strpos($mensaje, ImplementationUserSetupService::PREFIJO_BLOQUEADO) === 0) {
            return ['texto' => $mensaje . ' — El setup no llegó a salir: no corrió.', 'puede_haber_corrido' => false];
        }

        if (preg_match('/\(status (\d{3})\)/', $mensaje, $coincidencia) === 1) {
            $status = (int) $coincidencia[1];

            if ($status === 409) {
                return [
                    'texto' => $mensaje . ' — Ya hay un setup corriendo (o que ya corrió) del otro lado: NO se reintenta. Esperá a que termine y '
                        . 'mirá si el dueño existe en el sistema del cliente: si existe, conciliá con `conciliar: true`.',
                    'puede_haber_corrido' => true,
                ];
            }

            if (in_array($status, [502, 503, 504, 524], true)) {
                return [
                    'texto' => $mensaje . ' — El proxy del sistema del cliente cortó la espera (status ' . $status . '), pero el origen puede seguir '
                        . 'corriendo el setup. ' . $espera_cortada . self::ANTES_DE_REINTENTAR,
                    'puede_haber_corrido' => true,
                ];
            }

            if ($status >= 400 && $status < 500 && $status !== 408) {
                return [
                    'texto' => $mensaje . ' — El sistema del cliente rechazó el pedido antes de ejecutar nada: el setup no corrió. Corregí la causa '
                        . 'y reintentá con `reintentar: true`.',
                    'puede_haber_corrido' => false,
                ];
            }

            return [
                'texto' => $mensaje . ' — El sistema del cliente respondió con un error: el setup pudo haber corrido a medias (migrate:fresh vacía '
                    . 'la base antes de sembrar, y un corte en el medio la deja a medias). ' . self::ANTES_DE_REINTENTAR,
                'puede_haber_corrido' => true,
            ];
        }

        return ['texto' => $mensaje . ' — ' . $espera_cortada . self::ANTES_DE_REINTENTAR, 'puede_haber_corrido' => true];
    }

    /**
     * El registro del user setup de una etapa 2, o un array vacío.
     *
     * @param ImplementationStage|null $etapa La etapa 2.
     *
     * @return array<string, mixed>
     */
    private function registro_de($etapa)
    {
        if ($etapa === null || ! is_array($etapa->data)) {
            return [];
        }

        return isset($etapa->data['user_setup']) && is_array($etapa->data['user_setup']) ? $etapa->data['user_setup'] : [];
    }

    /**
     * Deja dicho en el log por qué este job no hizo (o no escribió) lo que se esperaba de él.
     *
     * @param string $motivo Qué pasó.
     *
     * @return void
     */
    private function dejar_dicho($motivo)
    {
        Log::channel('daily')->warning('EjecutarUserSetupDeImplementacionJob: ' . $motivo, [
            'implementation_id' => $this->implementation_id,
            'token'             => $this->token,
        ]);
    }
}
