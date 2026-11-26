<?php

namespace App\Jobs;

use App\Models\LeadLlamadaGrabacion;
use App\Services\TranscripcionDeLlamadaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Transcribe la grabación de una llamada de Juan (misión leads-referidos-compromisos, etapa E3).
 *
 * Lo encola {@see \App\Services\LeadLlamadaGrabacionService} cuando la subida se completa (o cuando alguien toca «Reintentar») en la
 * cola `transcripciones` de la conexión `database`, que tiene su propio worker en el scheduler (`app/Console/Kernel.php`): una llamada
 * de 40 minutos tarda varios minutos y no puede demorar a los demás jobs.
 *
 * 🔴 `$timeout = 1500` (25 minutos): una llamada larga son varios tramos, cada uno con su pedido a OpenAI de hasta 3 minutos y un
 * reintento. Tiene que quedar DEBAJO del `retry_after` de la conexión `database` (2400 en `config/queue.php`): si no, el job se
 * vuelve a ofrecer mientras sigue corriendo. `$tries = 1`: si se corta, la grabación queda en `error` (ver {@see failed()}) y la
 * persona decide con «Reintentar»; reintentar solo repetiría un gasto de OpenAI sin que nadie lo sepa.
 *
 * El servicio nunca lanza por un problema de la grabación (lo deja en `error` con un texto claro); lo que sí puede escapar es un
 * error del propio proceso (memoria, la base), y ahí actúa `failed()`.
 */
class TranscribirGrabacionDeLlamadaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Tiempo máximo del job, en segundos (25 minutos). */
    public $timeout = 1500;

    /** Un solo intento (ver el docblock de la clase). */
    public $tries = 1;

    /**
     * @var int Id de la grabación (`lead_llamada_grabaciones.id`).
     */
    private $grabacion_id;

    /**
     * @var int Número de intento de la grabación con el que se encoló (`lead_llamada_grabaciones.intento`). Si la grabación ya va por
     *          otro intento (alguien tocó «Reintentar»), este job es viejo y no toca nada: ni transcribe ni la pasa a `error`.
     */
    private $intento;

    /**
     * @var bool true = sólo volver a resumir una grabación ya transcripta (no se vuelve a pagar la transcripción).
     */
    private $solo_resumen;

    /**
     * @param int  $grabacion_id
     * @param int  $intento      Intento de la grabación al encolar.
     * @param bool $solo_resumen Sólo el resumen y lo que depende de él.
     */
    public function __construct(int $grabacion_id, int $intento = 0, bool $solo_resumen = false)
    {
        $this->grabacion_id = $grabacion_id;
        $this->intento      = $intento;
        $this->solo_resumen = $solo_resumen;

        // Cola propia y conexión `database` explícita: con la conexión por defecto en `sync` correría adentro del pedido del usuario.
        $this->onConnection('database');
        $this->onQueue(LeadLlamadaGrabacion::COLA);
    }

    /**
     * Transcribe y resume la grabación.
     *
     * @param TranscripcionDeLlamadaService $servicio
     *
     * @return void
     */
    public function handle(TranscripcionDeLlamadaService $servicio): void
    {
        $servicio->transcribir($this->grabacion_id, $this->intento, $this->solo_resumen);
    }

    /**
     * El worker mató el job (tiempo agotado) o explotó: la grabación no puede quedar «transcribiendo» para siempre.
     *
     * No se guarda el mensaje de la excepción (podría traer rutas del servidor): sólo la clase, en el log, y un texto claro en la
     * grabación para Juan.
     *
     * @param \Throwable $excepcion
     *
     * @return void
     */
    public function failed(\Throwable $excepcion): void
    {
        Log::channel('daily')->warning('TranscribirGrabacionDeLlamadaJob: el job falló.', [
            'grabacion_id' => $this->grabacion_id,
            'error'        => get_class($excepcion),
        ]);

        // Un resumen que se cortó no cambia el estado de la grabación (sigue «lista», sólo sin resumen).
        if ($this->solo_resumen) {
            return;
        }

        // Sólo si el intento coincide: un job viejo reofrecido no pisa a uno nuevo.
        LeadLlamadaGrabacion::query()
            ->whereKey($this->grabacion_id)
            ->where('intento', $this->intento)
            ->whereIn('status', [LeadLlamadaGrabacion::STATUS_PENDIENTE, LeadLlamadaGrabacion::STATUS_TRANSCRIBIENDO])
            ->update([
                'status'      => LeadLlamadaGrabacion::STATUS_ERROR,
                'error_text'  => 'El proceso de transcripción se cortó antes de terminar (tiempo agotado o error del servidor). Tocá «Reintentar».',
                'finished_at' => \App\Helpers\AppTime::now(),
                'updated_at'  => \App\Helpers\AppTime::now(),
            ]);
    }
}
