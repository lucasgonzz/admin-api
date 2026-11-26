<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Models\LeadCompromiso;
use App\Models\LeadLlamada;
use App\Models\LeadLlamadaGrabacion;
use App\Models\LeadScheduledMessage;
use App\Models\PipelineOpportunity;
use App\Services\Pipelines\PipelineOpportunityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribe la grabación de una llamada de Juan, la resume y, si en la llamada se acordó un día de contacto, deja el compromiso
 * (misión leads-referidos-compromisos, etapa E3). Lo corre {@see \App\Jobs\TranscribirGrabacionDeLlamadaJob} en la cola
 * `transcripciones`.
 *
 * Camino de una grabación `pendiente`:
 *  1. Pasa a `transcribiendo`. Sin clave de OpenAI (`OPENAI_TRANSCRIPTION_API_KEY`) queda en `error` con ese nombre.
 *  2. CON ffmpeg ({@see FfmpegLocator::path()}): {@see CortadorDeAudioEnTramos} la parte en tramos de unos 10 minutos (mp3 chico). SIN
 *     ffmpeg (el VPS del admin no lo tiene hasta que se lo instale): si el formato es uno de los que OpenAI acepta y pesa hasta
 *     24 MB (el tope de la API es 25) se manda ENTERA; si no, `error` con «hace falta ffmpeg en el servidor…». «Reintentar» anda en
 *     cuanto se lo instale: no hay que tocar nada más.
 *  3. Cada tramo va a `/v1/audio/transcriptions` (modelo de la config, español) con UN reintento; si el segundo también falla, `error`
 *     «Falló el tramo N de M» y no se guarda una transcripción a medias. Los textos se unen con un salto de párrafo y se guarda la
 *     transcripción COMPLETA (sin el tope de 20.000 caracteres de `lead_llamadas`).
 *  4. La IA resume ({@see LeadCompromisoIaService::resumir_transcripcion()}): si se cae, la transcripción igual queda (`lista`, sin
 *     resumen). El resumen va a la grabación y a una nota de la tarjeta de Juan; el compromiso, a {@see derivar_el_compromiso()}.
 *
 * 🔴 NADA SALE A WHATSAPP desde acá: este servicio sólo habla con OpenAI y con la IA de resumen. Si de la llamada sale un compromiso
 * «escribir», lo ejecuta el comando `leads:compromisos` a la hora acordada, con todos sus frenos, y siempre como sugerencia
 * (`sin_envio_automatico`): un mensaje a un lead nunca nace de lo que entendió una IA de un audio.
 *
 * 🔴 PRIVACIDAD: el audio y la transcripción son datos personales. Este servicio sólo loguea ids, cantidades y códigos HTTP; nunca el
 * texto, el resumen, el nombre del archivo ni el teléfono del lead.
 *
 * Los problemas de la grabación (sin clave, sin ffmpeg, un tramo que falla) NO tiran excepción: la dejan en `error` con un texto
 * claro. Sólo escapa una falla del propio proceso (que el job convierte en `error` con {@see \App\Jobs\TranscribirGrabacionDeLlamadaJob::failed()}).
 */
class TranscripcionDeLlamadaService
{
    /** Endpoint de transcripción de OpenAI. */
    const URL_DE_OPENAI = 'https://api.openai.com/v1/audio/transcriptions';

    /** Segundos máximos de cada pedido a OpenAI (un tramo de 10 minutos tarda entre 10 y 60). */
    const TIMEOUT_POR_PEDIDO_SEGUNDOS = 180;

    /** Tope del archivo que se manda entero cuando no hay ffmpeg: 24 MB (OpenAI acepta 25 MB; queda margen). */
    const LIMITE_SIN_FFMPEG_BYTES = 25165824;

    /** Formatos que OpenAI acepta tal cual (guía de speech-to-text). El resto necesita pasar por ffmpeg. */
    const EXTENSIONES_NATIVAS = ['flac', 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'ogg', 'wav', 'webm'];

    /** Segundos de espera antes de reintentar un tramo al que OpenAI le dijo «muchos pedidos» (429). */
    const ESPERA_POR_429_SEGUNDOS = 5;

    /**
     * Transcribe una grabación `pendiente`. Si ya no está pendiente (la tomó otro worker, se borró), no hace nada.
     *
     * @param int $grabacion_id
     *
     * @return void
     */
    public function transcribir(int $grabacion_id): void
    {
        $grabacion = $this->tomar($grabacion_id);
        if ($grabacion === null) {
            return;
        }

        try {
            // Una transcripción que ya quedó guardada (el job se cortó durante el resumen) no se vuelve a pagar.
            $texto = (string) $grabacion->transcripcion;
            if (trim($texto) === '') {
                $texto = $this->transcribir_el_audio($grabacion);
            }

            $this->resumir_y_cerrar($grabacion->fresh(), $texto);
        } catch (\DomainException $e) {
            // Un problema esperado de la grabación, con el texto que se le muestra a Juan.
            $this->dejar_en_error($grabacion->id, $e->getMessage());
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('TranscripcionDeLlamadaService: falló una transcripción.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
            $this->dejar_en_error($grabacion->id, 'La transcripción falló por un error del servidor. Tocá «Reintentar»; si se repite, avisá al equipo técnico.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // Tomar la grabación
    // ------------------------------------------------------------------------------------------

    /**
     * Pasa la grabación de `pendiente` a `transcribiendo` (con candado, así dos workers no la toman a la vez).
     *
     * @param int $grabacion_id
     *
     * @return LeadLlamadaGrabacion|null Null si no existe o no estaba pendiente.
     */
    private function tomar(int $grabacion_id)
    {
        return DB::transaction(function () use ($grabacion_id) {
            $grabacion = LeadLlamadaGrabacion::query()->whereKey($grabacion_id)->lockForUpdate()->first();
            if ($grabacion === null || (string) $grabacion->status !== LeadLlamadaGrabacion::STATUS_PENDIENTE) {
                return null;
            }

            $grabacion->status      = LeadLlamadaGrabacion::STATUS_TRANSCRIBIENDO;
            $grabacion->started_at  = AppTime::now();
            $grabacion->finished_at = null;
            $grabacion->error_text  = null;
            $grabacion->save();

            return $grabacion;
        });
    }

    // ------------------------------------------------------------------------------------------
    // Transcribir el audio
    // ------------------------------------------------------------------------------------------

    /**
     * Manda el audio a OpenAI (entero o en tramos) y guarda la transcripción completa.
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return string La transcripción completa.
     *
     * @throws \DomainException Con el texto para Juan si no se pudo.
     */
    private function transcribir_el_audio(LeadLlamadaGrabacion $grabacion): string
    {
        $clave = trim((string) config('services.openai_transcription.api_key'));
        if ($clave === '') {
            throw new \DomainException('Falta la clave de OpenAI en el servidor (OPENAI_TRANSCRIPTION_API_KEY). Cuando se cargue, tocá «Reintentar».');
        }

        $disco = Storage::disk('local');
        if ((string) $grabacion->ruta === '' || ! $disco->exists((string) $grabacion->ruta)) {
            throw new \DomainException('El archivo de la grabación ya no está en el servidor: hay que subirlo de nuevo.');
        }
        $ruta_absoluta = $disco->path((string) $grabacion->ruta);

        // Cortador y localizador del contenedor (los tests los reemplazan).
        $localizador = app(FfmpegLocator::class);
        $cortador    = null;
        $duracion    = null;
        if ($localizador->path() !== null) {
            $cortador = app(CortadorDeAudioEnTramos::class);
            try {
                $tramos = $cortador->cortar($ruta_absoluta, (string) $grabacion->extension);
            } catch (\RuntimeException $e) {
                // El cortador sólo lanza textos pensados para Juan (sin datos de la llamada).
                throw new \DomainException($e->getMessage() . ' Cuando esté resuelto, tocá «Reintentar».');
            }
            $duracion = isset($cortador->ultima_duracion) ? $cortador->ultima_duracion : null;
            if ($tramos === []) {
                throw new \DomainException('No se pudo partir el audio en tramos. Tocá «Reintentar».');
            }
        } else {
            $this->exigir_que_entre_sin_ffmpeg($grabacion);
            $tramos = [$ruta_absoluta];
        }

        try {
            $textos = [];
            $total  = count($tramos);
            foreach ($tramos as $posicion => $ruta_del_tramo) {
                // Sin ffmpeg se manda el original: su nombre de envío conserva la extensión real (OpenAI detecta el formato por ahí).
                $nombre   = $cortador !== null ? basename($ruta_del_tramo) : 'audio.' . $grabacion->extension;
                $textos[] = $this->transcribir_un_tramo($clave, $ruta_del_tramo, $nombre, $posicion + 1, $total);
            }
        } finally {
            if ($cortador !== null) {
                $cortador->limpiar($tramos);
            }
        }

        // Se unen en orden con un salto de párrafo; un tramo sin voz (silencio) vuelve vacío y no suma nada.
        $texto = implode("\n\n", array_values(array_filter($textos, function ($parte) {
            return $parte !== '';
        })));
        if ($texto === '') {
            throw new \DomainException('OpenAI no devolvió texto: el audio parece no tener voz. Revisá el archivo.');
        }

        // Se guarda YA, antes de resumir: si el resumen se corta, lo pagado no se pierde.
        $grabacion->transcripcion      = $texto;
        $grabacion->tramos             = count($tramos);
        $grabacion->modelo             = $this->modelo();
        $grabacion->duracion_segundos  = $duracion;
        $grabacion->save();

        return $texto;
    }

    /**
     * Sin ffmpeg el archivo sólo se puede mandar entero: tiene que ser de un formato que OpenAI acepta y entrar en 24 MB.
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return void
     *
     * @throws \DomainException Con el texto para Juan si no entra.
     */
    private function exigir_que_entre_sin_ffmpeg(LeadLlamadaGrabacion $grabacion): void
    {
        if (! in_array((string) $grabacion->extension, self::EXTENSIONES_NATIVAS, true)) {
            throw new \DomainException('El formato .' . $grabacion->extension . ' no lo acepta OpenAI y hace falta ffmpeg en el servidor para convertirlo. Cuando se instale, tocá «Reintentar».');
        }

        if ((int) $grabacion->bytes_recibidos > self::LIMITE_SIN_FFMPEG_BYTES) {
            throw new \DomainException('El archivo pesa más de 24 MB y hace falta ffmpeg en el servidor para partirlo en tramos. Cuando se instale, tocá «Reintentar».');
        }
    }

    /**
     * Transcribe UN tramo con OpenAI, con un reintento.
     *
     * @param string $clave   Clave de OpenAI.
     * @param string $ruta    Archivo del tramo.
     * @param string $nombre  Nombre con el que viaja (la extensión le dice el formato a OpenAI).
     * @param int    $numero  Número del tramo (desde 1).
     * @param int    $total   Cuántos tramos hay.
     *
     * @return string El texto del tramo (vacío si no había voz).
     *
     * @throws \DomainException Si el segundo intento también falla.
     */
    private function transcribir_un_tramo(string $clave, string $ruta, string $nombre, int $numero, int $total): string
    {
        // Último código HTTP visto (0 = ni llegó respuesta): sólo para el mensaje.
        $codigo = 0;

        for ($intento = 1; $intento <= 2; $intento++) {
            $resultado = $this->pedir_la_transcripcion($clave, $ruta, $nombre);
            if ($resultado['texto'] !== null) {
                return $resultado['texto'];
            }
            $codigo = $resultado['http'];

            if ($intento === 1 && $codigo === 429) {
                sleep(self::ESPERA_POR_429_SEGUNDOS);
            }
        }

        Log::channel('daily')->warning('TranscripcionDeLlamadaService: un tramo falló dos veces.', ['tramo' => $numero, 'de' => $total, 'http' => $codigo]);

        $detalle = $codigo > 0 ? ' (OpenAI respondió ' . $codigo . ')' : ' (no hubo respuesta de OpenAI)';
        if ($total === 1) {
            $extra = $codigo >= 400 && $codigo < 500 && $codigo !== 429
                ? ' Si la llamada es larga, hace falta ffmpeg en el servidor para partirla en tramos.'
                : '';

            throw new \DomainException('Falló la transcripción del audio' . $detalle . '.' . $extra . ' Tocá «Reintentar».');
        }

        throw new \DomainException('Falló el tramo ' . $numero . ' de ' . $total . $detalle . '. Tocá «Reintentar».');
    }

    /**
     * Un pedido a `/v1/audio/transcriptions`. Nunca lanza.
     *
     * @param string $clave
     * @param string $ruta
     * @param string $nombre
     *
     * @return array{texto: string|null, http: int} `texto` es null si el pedido falló; `http` es el código (0 si no llegó respuesta).
     */
    private function pedir_la_transcripcion(string $clave, string $ruta, string $nombre): array
    {
        $recurso = @fopen($ruta, 'rb');
        if ($recurso === false) {
            return ['texto' => null, 'http' => 0];
        }

        try {
            $respuesta = Http::withToken($clave)
                ->timeout(self::TIMEOUT_POR_PEDIDO_SEGUNDOS)
                ->withOptions(['connect_timeout' => 10])
                ->attach('file', $recurso, $nombre)
                ->post(self::URL_DE_OPENAI, [
                    'model'           => $this->modelo(),
                    'language'        => 'es',
                    'response_format' => 'json',
                ]);

            if (! $respuesta->successful()) {
                return ['texto' => null, 'http' => (int) $respuesta->status()];
            }

            $texto = $respuesta->json('text');

            return is_string($texto) ? ['texto' => trim($texto), 'http' => (int) $respuesta->status()] : ['texto' => null, 'http' => (int) $respuesta->status()];
        } catch (\Throwable $e) {
            // Sin conexión, timeout o respuesta rota: el llamador lo reintenta una vez. Nada de esto se loguea (puede traer rutas).
            return ['texto' => null, 'http' => 0];
        } finally {
            if (is_resource($recurso)) {
                fclose($recurso);
            }
        }
    }

    /**
     * Modelo de transcripción de la configuración.
     *
     * @return string
     */
    private function modelo(): string
    {
        return (string) config('services.openai_call_transcription.model', 'gpt-4o-transcribe');
    }

    // ------------------------------------------------------------------------------------------
    // Resumir y cerrar
    // ------------------------------------------------------------------------------------------

    /**
     * Resume la transcripción con la IA, deja la nota en la tarjeta y el compromiso (si corresponde) y marca la grabación `lista`.
     * Todo lo que depende del resumen ocurre en UNA transacción junto con el cierre: si algo falla, no queda una nota o un compromiso
     * sueltos, y la grabación igual termina `lista` con su transcripción.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param string               $texto     Transcripción completa.
     *
     * @return void
     */
    private function resumir_y_cerrar(LeadLlamadaGrabacion $grabacion, string $texto): void
    {
        $lead    = Lead::query()->find((int) $grabacion->lead_id);
        $llamada = LeadLlamada::query()->find((int) $grabacion->lead_llamada_id);
        if ($lead === null || $llamada === null) {
            // El lead o la llamada se borraron mientras tanto: no hay a quién colgarle nada.
            return;
        }

        $resumen = app(LeadCompromisoIaService::class)->resumir_transcripcion($lead, $llamada, $texto);
        if (! $resumen['ok']) {
            Log::channel('daily')->info('TranscripcionDeLlamadaService: la IA no resumió la grabación.', ['grabacion_id' => $grabacion->id, 'motivo' => $resumen['motivo']]);
        }

        try {
            DB::transaction(function () use ($grabacion, $lead, $llamada, $resumen) {
                $propuesto     = null;
                $compromiso_id = null;

                if ($resumen['ok']) {
                    // La nota y el compromiso son agregados: si uno falla se loguea y el resumen se guarda igual (cada uno en su propio
                    // punto de guardado, así lo que falló no deja nada a medias).
                    try {
                        $this->dejar_la_nota($grabacion, $lead, $llamada, $resumen);
                    } catch (\Throwable $e) {
                        Log::channel('daily')->warning('TranscripcionDeLlamadaService: no se pudo dejar la nota en la tarjeta.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
                    }

                    if ($resumen['compromiso'] !== null) {
                        try {
                            $derivado      = $this->derivar_el_compromiso($grabacion, $lead, $llamada, $resumen['compromiso']);
                            $propuesto     = $derivado['propuesto'];
                            $compromiso_id = $derivado['compromiso_id'];
                        } catch (\Throwable $e) {
                            Log::channel('daily')->warning('TranscripcionDeLlamadaService: no se pudo derivar el compromiso.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
                        }
                    }
                }

                $this->cerrar($grabacion->id, $resumen['ok'] ? $resumen['resumen'] : null, $propuesto, $compromiso_id);
            });
        } catch (\Throwable $e) {
            // Lo que depende del resumen se deshizo; la transcripción (lo valioso) queda y la grabación se cierra sin resumen.
            Log::channel('daily')->warning('TranscripcionDeLlamadaService: no se pudo guardar el resumen.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
            $this->cerrar($grabacion->id, null, null, null);
        }
    }

    /**
     * Marca la grabación `lista` con lo que salió del resumen.
     *
     * @param int         $grabacion_id
     * @param string|null $resumen
     * @param string|null $propuesto
     * @param int|null    $compromiso_id
     *
     * @return void
     */
    private function cerrar(int $grabacion_id, $resumen, $propuesto, $compromiso_id): void
    {
        LeadLlamadaGrabacion::query()->whereKey($grabacion_id)->update([
            'status'               => LeadLlamadaGrabacion::STATUS_LISTA,
            'error_text'           => null,
            'resumen'              => $resumen,
            'compromiso_propuesto' => $propuesto,
            'compromiso_id'        => $compromiso_id,
            'finished_at'          => AppTime::now(),
            'updated_at'           => AppTime::now(),
        ]);
    }

    /**
     * Deja en la tarjeta de Juan la nota «Grabación transcripta: <resumen>» (canal `call`), con los datos clave abajo.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param Lead                 $lead
     * @param LeadLlamada          $llamada
     * @param array<string, mixed> $resumen   Salida de `resumir_transcripcion()`.
     *
     * @return void
     */
    private function dejar_la_nota(LeadLlamadaGrabacion $grabacion, Lead $lead, LeadLlamada $llamada, array $resumen): void
    {
        // La tarjeta donde se registró la llamada; si ya no está, la abierta del lead; si no hay ninguna, no hay dónde dejarla.
        $tarjeta = $llamada->opportunity_id !== null ? PipelineOpportunity::query()->find((int) $llamada->opportunity_id) : null;
        if ($tarjeta === null) {
            $tarjeta = app(TableroDeJuanService::class)->tarjeta_abierta($lead);
        }
        if ($tarjeta === null) {
            return;
        }

        $cuerpo = 'Grabación transcripta: ' . $resumen['resumen'];
        if ($resumen['datos_clave'] !== []) {
            $cuerpo .= "\nDatos clave: " . implode('; ', $resumen['datos_clave']);
        }

        app(PipelineOpportunityService::class)->agregar_nota($tarjeta, $cuerpo, 'call', null, $grabacion->created_by_admin_id !== null ? (int) $grabacion->created_by_admin_id : null);
    }

    // ------------------------------------------------------------------------------------------
    // El compromiso que sale de la llamada
    // ------------------------------------------------------------------------------------------

    /**
     * Decide qué pasa con el próximo contacto que la IA entendió de la llamada.
     *
     * La IA siempre deja su propuesta escrita (`compromiso_propuesto`, para la ficha). Se CREA un compromiso `origen = transcripcion`
     * sólo si no pisa nada ni sobra:
     *  - el motor de compromisos está prendido;
     *  - la llamada NO trajo fecha: si Juan puso una, gana la de Juan;
     *  - la llamada no cerró el caso (no le interesa, ya contrató, pagó la mitad: ahí no hay nada que retomar);
     *  - el lead no es un cliente ni está cerrado (ya no es del embudo);
     *  - el lead no tiene hoy otro compromiso abierto (si lo hay, lo cargó una persona o el lead después de la llamada: lo más reciente
     *    y humano no se pisa con lo que entendió una IA de un audio).
     * El compromiso nace `sin_envio_automatico`: un mensaje a un lead nunca sale solo por lo que una IA entendió de un audio.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param Lead                 $lead
     * @param LeadLlamada          $llamada
     * @param array<string, mixed> $propuesta `vence_at`, `accion`, `cita` y `momento` ya validados.
     *
     * @return array{propuesto: string, compromiso_id: int|null}
     */
    private function derivar_el_compromiso(LeadLlamadaGrabacion $grabacion, Lead $lead, LeadLlamada $llamada, array $propuesta): array
    {
        $servicio = app(LeadCompromisoService::class);

        // La propuesta en palabras para la ficha («Llamarlo el martes 01/12 a las 11:30 — «llamame el martes»»).
        $limpios   = $servicio->limpiar_datos(['vence_at' => $propuesta['vence_at'], 'accion' => $propuesta['accion']]);
        $propuesto = $this->verbo_de_la_accion((string) $propuesta['accion']) . ' ' . $servicio->dia_en_palabras($limpios['vence_at'])
            . ($propuesta['cita'] !== null && $propuesta['cita'] !== '' ? ' — «' . $propuesta['cita'] . '»' : '');
        $propuesto = mb_substr($propuesto, 0, 600);

        if (! $this->corresponde_crear_el_compromiso($servicio, $lead, $llamada)) {
            return ['propuesto' => $propuesto, 'compromiso_id' => null];
        }

        try {
            $compromiso = $servicio->crear($lead, [
                'vence_at'      => $propuesta['vence_at'],
                'accion'        => $propuesta['accion'],
                'resumen'       => $this->resumen_del_compromiso((string) $propuesta['accion'], (string) $propuesta['cita']),
                'cita_del_lead' => $propuesta['cita'],
                'momento'       => $propuesta['momento'],
                'origen'        => LeadCompromiso::ORIGEN_TRANSCRIPCION,
                'origen_id'     => (int) $grabacion->id,
            ], null, ['sin_envio_automatico' => true]);
        } catch (\Throwable $e) {
            // Una fecha que dejó de servir entre medio: la propuesta queda escrita y no se agenda nada.
            Log::channel('daily')->info('TranscripcionDeLlamadaService: el compromiso de la llamada no se pudo crear.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);

            return ['propuesto' => $propuesto, 'compromiso_id' => null];
        }

        return ['propuesto' => $propuesto, 'compromiso_id' => $compromiso !== null ? (int) $compromiso->id : null];
    }

    /**
     * ¿Se puede agendar de verdad el compromiso que entendió la IA? (Ver las condiciones en {@see derivar_el_compromiso()}.)
     *
     * @param LeadCompromisoService $servicio
     * @param Lead                  $lead
     * @param LeadLlamada           $llamada
     *
     * @return bool
     */
    private function corresponde_crear_el_compromiso(LeadCompromisoService $servicio, Lead $lead, LeadLlamada $llamada): bool
    {
        if (! LeadCompromisoService::activo()) {
            return false;
        }

        // Juan puso una fecha en la llamada: gana la suya, aunque ese compromiso ya se haya ejecutado o cancelado.
        if ($llamada->proxima_llamada_at !== null) {
            return false;
        }

        // La llamada cerró el caso.
        if (in_array((string) $llamada->resultado, [LeadLlamada::RESULTADO_NO_LE_INTERESA, LeadLlamada::RESULTADO_YA_CONTRATO, LeadLlamada::RESULTADO_PAGO_LA_MITAD], true)) {
            return false;
        }

        // Un cliente o un lead cerrado ya no es del embudo (mismo criterio que la detección de compromisos por WhatsApp).
        $lead = $lead->fresh();
        if ($lead === null || ! empty($lead->promoted_client_id) || in_array((string) $lead->status, LeadScheduledMessage::STATUSES_DE_LEAD_QUE_FRENAN, true)) {
            return false;
        }

        // Hay un compromiso abierto: nació después de la llamada (si no, «Registrar llamada» lo habría dado por cumplido).
        return $servicio->abierto_de($lead) === null;
    }

    /**
     * «Llamarlo», «Escribirle» o «Esperar que avise», según la acción.
     *
     * @param string $accion
     *
     * @return string
     */
    private function verbo_de_la_accion(string $accion): string
    {
        if ($accion === LeadCompromiso::ACCION_ESCRIBIR) {
            return 'Escribirle';
        }
        if ($accion === LeadCompromiso::ACCION_ESPERAR) {
            return 'Esperar que avise';
        }

        return 'Llamarlo';
    }

    /**
     * El resumen que lleva el compromiso: qué se acordó y lo que se dijo en la llamada.
     *
     * @param string $accion
     * @param string $cita
     *
     * @return string
     */
    private function resumen_del_compromiso(string $accion, string $cita): string
    {
        $que = 'Llamarlo.';
        if ($accion === LeadCompromiso::ACCION_ESCRIBIR) {
            $que = 'Escribirle.';
        } elseif ($accion === LeadCompromiso::ACCION_ESPERAR) {
            $que = 'El lead quedó en avisar; si no avisa, retomarlo.';
        }

        return $que . ($cita !== '' ? ' Se acordó en la llamada: «' . $cita . '».' : '');
    }

    // ------------------------------------------------------------------------------------------
    // Errores
    // ------------------------------------------------------------------------------------------

    /**
     * Deja la grabación en `error` con un texto claro. Sólo si seguía en `transcribiendo` (una que ya terminó no se pisa).
     *
     * @param int    $grabacion_id
     * @param string $texto
     *
     * @return void
     */
    private function dejar_en_error(int $grabacion_id, string $texto): void
    {
        LeadLlamadaGrabacion::query()
            ->whereKey($grabacion_id)
            ->where('status', LeadLlamadaGrabacion::STATUS_TRANSCRIBIENDO)
            ->update([
                'status'      => LeadLlamadaGrabacion::STATUS_ERROR,
                'error_text'  => mb_substr($texto, 0, 500),
                'finished_at' => AppTime::now(),
                'updated_at'  => AppTime::now(),
            ]);
    }
}
