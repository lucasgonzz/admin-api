<?php

namespace App\Jobs;

use App\Models\LeadEventoMeta;
use App\Services\MetaConversionesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda a Meta UNA conversión de un lead de anuncio (misión origen-del-lead-y-conversiones-meta,
 * 13/10/2026).
 *
 * 🔴 **Por qué existe.** El registro del evento vive en el observer de `Lead::saved`, que corre
 * adentro del request que cambió el estado del lead (el webhook de Kapso, el envío de una sugerencia,
 * la promoción a cliente). Ahí adentro, una llamada HTTP a Meta con su timeout alargaría ese request,
 * y un fallo subiría por el `save()`. El job saca la llamada de ahí; el estado queda en la fila de
 * `lead_eventos_meta`.
 */
class EnviarEventoMetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Conexión de cola, explícita.
     *
     * 🔴 Explícita y no la default por el mismo motivo que `EnviarAvisoDeActualizacionJob`: si
     * `QUEUE_CONNECTION` no está seteada cae a `sync`, y en `sync` la llamada a Meta saldría INLINE
     * adentro del `save()` que este job vino a proteger. Al job lo corre el worker
     * `queue:work database --stop-when-empty` que el scheduler dispara cada minuto; si no corre, la
     * señal es la fila que se queda en `pendiente`.
     */
    const CONEXION_DE_COLA = 'database';

    /**
     * Un solo intento: los reintentos los hace el conciliador (`leads:meta-capi-conciliar`), que
     * respeta el tope de intentos de la fila y la vigencia de siete días de Meta.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Techo del job: una sola llamada HTTP con su propio timeout (15 s por defecto).
     *
     * @var int
     */
    public $timeout = 60;

    /**
     * Id de la fila de `lead_eventos_meta`. Viaja el id y no el modelo: lo que importa es el estado
     * de la fila cuando el worker la toma.
     *
     * @var int
     */
    private $lead_evento_meta_id;

    /**
     * @param int $lead_evento_meta_id
     */
    public function __construct(int $lead_evento_meta_id)
    {
        $this->lead_evento_meta_id = $lead_evento_meta_id;

        /* Va acá y no como `public $connection = ...` porque PHP 7.4 no deja redeclarar la
           propiedad del trait `Queueable` con otro valor inicial (mismo criterio que
           EnviarAvisoDeActualizacionJob). */
        $this->connection = self::CONEXION_DE_COLA;
    }

    /**
     * Manda el evento.
     *
     * 🔴 No deja escapar nada: el resultado —incluido el fracaso— queda escrito en la fila, que es
     * de donde lo levanta el conciliador.
     *
     * @param MetaConversionesService $service Inyectado por el contenedor.
     *
     * @return void
     */
    public function handle(MetaConversionesService $service): void
    {
        try {
            $evento = LeadEventoMeta::query()->find($this->lead_evento_meta_id);

            if ($evento === null) {
                Log::channel('daily')->warning('EnviarEventoMetaJob: el evento ya no existe.', [
                    'lead_evento_meta_id' => $this->lead_evento_meta_id,
                ]);

                return;
            }

            $service->enviar($evento);
        } catch (\Throwable $exception) {
            $motivo = $service->sin_token($exception->getMessage());

            Log::channel('daily')->error('EnviarEventoMetaJob: se cayó el envío.', [
                'lead_evento_meta_id' => $this->lead_evento_meta_id,
                'error'               => $motivo,
            ]);

            try {
                $service->anotar_caida($this->lead_evento_meta_id, 'Se cayó el envío: ' . $motivo);
            } catch (\Throwable $ignorado) {
                // Si ni siquiera se puede escribir la fila, el log de arriba es todo lo que queda.
            }
        }
    }
}
