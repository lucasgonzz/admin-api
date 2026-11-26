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
     * Transcribe una grabación `pendiente`. Si ya no está pendiente (la tomó otro worker, se borró) o ya va por otro intento (alguien
     * tocó «Reintentar» y este job es el viejo), no hace nada.
     *
     * Con `$solo_resumen` no transcribe: vuelve a resumir una grabación `lista` que se había quedado sin resumen (la IA no contestó).
     *
     * @param int      $grabacion_id
     * @param int|null $intento      Intento con el que se encoló el job (null = sin control, para quien lo llama a mano).
     * @param bool     $solo_resumen
     *
     * @return void
     */
    public function transcribir(int $grabacion_id, $intento = null, bool $solo_resumen = false): void
    {
        // Temporales de tramos que dejó un job que el worker mató por tiempo (datos personales): se barren los de más de dos horas.
        try {
            app(CortadorDeAudioEnTramos::class)->barrer_los_viejos();
        } catch (\Throwable $e) {
            // Limpiar no puede impedir una transcripción.
        }

        if ($solo_resumen) {
            $this->volver_a_resumir($grabacion_id, $intento);

            return;
        }

        $grabacion = $this->tomar($grabacion_id, $intento);
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
            $this->dejar_en_error($grabacion, $e->getMessage());
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('TranscripcionDeLlamadaService: falló una transcripción.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
            $this->dejar_en_error($grabacion, 'La transcripción falló por un error del servidor. Tocá «Reintentar»; si se repite, avisá al equipo técnico.');
        }
    }

    /**
     * Vuelve a resumir una grabación `lista` con transcripción y sin resumen. Nunca lanza.
     *
     * @param int      $grabacion_id
     * @param int|null $intento
     *
     * @return void
     */
    private function volver_a_resumir(int $grabacion_id, $intento): void
    {
        $grabacion = LeadLlamadaGrabacion::query()->whereKey($grabacion_id)->first();
        if ($grabacion === null || (string) $grabacion->status !== LeadLlamadaGrabacion::STATUS_LISTA
            || trim((string) $grabacion->transcripcion) === '' || trim((string) $grabacion->resumen) !== ''
            || ($intento !== null && (int) $grabacion->intento !== (int) $intento)) {
            return;
        }

        try {
            $this->resumir_y_cerrar($grabacion, (string) $grabacion->transcripcion);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('TranscripcionDeLlamadaService: falló volver a resumir.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Tomar la grabación
    // ------------------------------------------------------------------------------------------

    /**
     * Pasa la grabación de `pendiente` a `transcribiendo` (con candado, así dos workers no la toman a la vez).
     *
     * @param int      $grabacion_id
     * @param int|null $intento      Intento del job: si la grabación ya va por otro, este job es viejo.
     *
     * @return LeadLlamadaGrabacion|null Null si no existe, no estaba pendiente o el job es de un intento anterior.
     */
    private function tomar(int $grabacion_id, $intento = null)
    {
        return DB::transaction(function () use ($grabacion_id, $intento) {
            $grabacion = LeadLlamadaGrabacion::query()->whereKey($grabacion_id)->lockForUpdate()->first();
            if ($grabacion === null || (string) $grabacion->status !== LeadLlamadaGrabacion::STATUS_PENDIENTE
                || ($intento !== null && (int) $grabacion->intento !== (int) $intento)) {
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
     * Nunca se guarda una transcripción CORTADA: ver {@see transcribir_un_tramo()}.
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
        // Modelo que transcribe: con ffmpeg el de la configuración (tramos cortos); sin ffmpeg el que no tiene tope de salida.
        $modelo = $this->modelo();
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
            $modelo = $this->modelo_sin_ffmpeg();
        }

        // Archivos temporales que hay que borrar al terminar (los tramos y las mitades que arme la guardia de corte).
        $temporales = $cortador !== null ? $tramos : [];

        try {
            $textos = [];
            $total  = count($tramos);
            foreach ($tramos as $posicion => $ruta_del_tramo) {
                // Sin ffmpeg se manda el original: su nombre de envío conserva la extensión real (OpenAI detecta el formato por ahí).
                $nombre   = $cortador !== null ? basename($ruta_del_tramo) : 'audio.' . $grabacion->extension;
                $textos[] = $this->texto_del_tramo($clave, $modelo, $cortador, $ruta_del_tramo, $nombre, $posicion + 1, $total, $temporales);
            }
        } finally {
            if ($cortador !== null) {
                $cortador->limpiar($temporales);
            }
        }

        // Se unen en orden con un salto de párrafo; un tramo sin voz (silencio) vuelve vacío y no suma nada.
        $texto = implode("\n\n", array_values(array_filter($textos, function ($parte) {
            return $parte !== '';
        })));
        if ($texto === '') {
            throw new \DomainException('OpenAI no devolvió texto: el audio parece no tener voz. Revisá el archivo.');
        }

        // Se guarda YA, antes de resumir: si el resumen se corta, lo pagado no se pierde. Sólo si este job sigue siendo el vigente.
        $this->actualizar_si_es_mi_intento($grabacion, [
            'transcripcion'     => $texto,
            'tramos'            => count($tramos),
            'modelo'            => $modelo,
            'duracion_segundos' => $duracion,
        ]);

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
     * El texto de UN tramo, sin recortes. Si OpenAI lo devolvió cortado por su tope de salida (guardia de corte) y hay ffmpeg, el tramo
     * se parte en dos mitades y cada una se transcribe por separado; si una mitad también sale cortada, o no hay ffmpeg, es un error:
     * NUNCA se sigue con un texto al que le falta el final.
     *
     * @param string                          $clave
     * @param string                          $modelo
     * @param CortadorDeAudioEnTramos|null    $cortador   Null sin ffmpeg.
     * @param string                          $ruta       Archivo del tramo.
     * @param string                          $nombre     Nombre con el que viaja.
     * @param int                             $numero     Número del tramo (desde 1).
     * @param int                             $total      Cuántos tramos hay.
     * @param array<int, string>              $temporales Salida: se suman las mitades que se creen, para borrarlas al final.
     *
     * @return string
     *
     * @throws \DomainException
     */
    private function texto_del_tramo(string $clave, string $modelo, $cortador, string $ruta, string $nombre, int $numero, int $total, array &$temporales): string
    {
        $resultado = $this->transcribir_un_tramo($clave, $modelo, $ruta, $nombre, $numero, $total);
        if (! $resultado['cortado']) {
            return $resultado['texto'];
        }

        // Cortado por el tope de salida: con ffmpeg se prueba con las dos mitades; sin él no hay nada más que hacer.
        $mitades = [];
        if ($cortador !== null) {
            try {
                $mitades = $cortador->partir_en_dos($ruta);
            } catch (\RuntimeException $e) {
                $mitades = [];
            }
            foreach ($mitades as $mitad) {
                $temporales[] = $mitad;
            }
        }
        if (count($mitades) !== 2) {
            throw new \DomainException($this->texto_de_corte($numero, $total));
        }

        $partes = [];
        foreach ($mitades as $mitad) {
            $parte = $this->transcribir_un_tramo($clave, $modelo, $mitad, basename($mitad), $numero, $total);
            if ($parte['cortado']) {
                throw new \DomainException($this->texto_de_corte($numero, $total));
            }
            $partes[] = $parte['texto'];
        }

        return implode("\n\n", array_values(array_filter($partes, function ($parte) {
            return $parte !== '';
        })));
    }

    /**
     * El aviso de una transcripción que salió cortada.
     *
     * @param int $numero
     * @param int $total
     *
     * @return string
     */
    private function texto_de_corte(int $numero, int $total): string
    {
        return ($total > 1 ? 'En el tramo ' . $numero . ' de ' . $total . ', ' : 'En este audio, ') . 'la transcripción salió cortada porque OpenAI llegó a su tope de salida: no se guardó '
            . 'para no dejar una llamada con el final perdido. Se arregla con tramos más cortos (OPENAI_CALL_TRANSCRIPTION_TRAMO_CORTO_SEGUNDOS, hace falta ffmpeg) '
            . 'o con otro modelo (OPENAI_CALL_TRANSCRIPTION_MODEL / OPENAI_CALL_TRANSCRIPTION_MODEL_SIN_FFMPEG). Después tocá «Reintentar».';
    }

    /**
     * Transcribe UN archivo con OpenAI, con un reintento si el pedido falla.
     *
     * @param string $clave   Clave de OpenAI.
     * @param string $modelo  Modelo de transcripción.
     * @param string $ruta    Archivo del tramo.
     * @param string $nombre  Nombre con el que viaja (la extensión le dice el formato a OpenAI).
     * @param int    $numero  Número del tramo (desde 1).
     * @param int    $total   Cuántos tramos hay.
     *
     * @return array{texto: string, cortado: bool} `texto` vacío si no había voz; `cortado` si la salida llegó al tope de tokens.
     *
     * @throws \DomainException Si el segundo intento también falla.
     */
    private function transcribir_un_tramo(string $clave, string $modelo, string $ruta, string $nombre, int $numero, int $total): array
    {
        // Último código HTTP visto (0 = ni llegó respuesta): sólo para el mensaje.
        $codigo = 0;

        for ($intento = 1; $intento <= 2; $intento++) {
            $resultado = $this->pedir_la_transcripcion($clave, $modelo, $ruta, $nombre);
            if ($resultado['texto'] !== null) {
                return ['texto' => $resultado['texto'], 'cortado' => $resultado['cortado']];
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
     * @param string $modelo
     * @param string $ruta
     * @param string $nombre
     *
     * @return array{texto: string|null, http: int, cortado: bool} `texto` es null si el pedido falló; `http` es el código (0 si no
     *         llegó respuesta); `cortado` es true si `usage.output_tokens` llegó al tope de la guardia (`max_output_tokens`).
     */
    private function pedir_la_transcripcion(string $clave, string $modelo, string $ruta, string $nombre): array
    {
        $recurso = @fopen($ruta, 'rb');
        if ($recurso === false) {
            return ['texto' => null, 'http' => 0, 'cortado' => false];
        }

        try {
            $respuesta = Http::withToken($clave)
                ->timeout(self::TIMEOUT_POR_PEDIDO_SEGUNDOS)
                ->withOptions(['connect_timeout' => 10])
                ->attach('file', $recurso, $nombre)
                ->post(self::URL_DE_OPENAI, [
                    'model'           => $modelo,
                    'language'        => 'es',
                    'response_format' => 'json',
                ]);

            if (! $respuesta->successful()) {
                return ['texto' => null, 'http' => (int) $respuesta->status(), 'cortado' => false];
            }

            $texto = $respuesta->json('text');
            if (! is_string($texto)) {
                return ['texto' => null, 'http' => (int) $respuesta->status(), 'cortado' => false];
            }

            // Guardia de corte: los modelos gpt-4o devuelven `usage.output_tokens`; con un tope de 2.000, una salida cerca del tope es
            // una transcripción recortada en silencio. whisper-1 no trae `usage` de tokens: no se puede medir y no tiene ese tope.
            $salida = $respuesta->json('usage.output_tokens');
            $tope   = (int) config('services.openai_call_transcription.max_output_tokens', 1800);

            return ['texto' => trim($texto), 'http' => (int) $respuesta->status(), 'cortado' => is_numeric($salida) && $tope > 0 && (int) $salida >= $tope];
        } catch (\Throwable $e) {
            // Sin conexión, timeout o respuesta rota: el llamador lo reintenta una vez. Nada de esto se loguea (puede traer rutas).
            return ['texto' => null, 'http' => 0, 'cortado' => false];
        } finally {
            if (is_resource($recurso)) {
                fclose($recurso);
            }
        }
    }

    /**
     * Modelo de transcripción de la configuración (con ffmpeg, tramos cortos).
     *
     * @return string
     */
    private function modelo(): string
    {
        return (string) config('services.openai_call_transcription.model', 'gpt-4o-transcribe');
    }

    /**
     * Modelo para el archivo entero cuando el servidor no tiene ffmpeg (por defecto `whisper-1`, sin tope de salida).
     *
     * @return string
     */
    private function modelo_sin_ffmpeg(): string
    {
        return (string) config('services.openai_call_transcription.model_sin_ffmpeg', 'whisper-1');
    }

    /**
     * Actualiza columnas de la grabación SÓLO si sigue en el intento de este job: una grabación reintentada mientras un job viejo seguía
     * vivo no recibe sus resultados.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param array<string, mixed> $columnas
     *
     * @return bool true si se actualizó.
     */
    private function actualizar_si_es_mi_intento(LeadLlamadaGrabacion $grabacion, array $columnas): bool
    {
        return LeadLlamadaGrabacion::query()
            ->whereKey((int) $grabacion->id)
            ->where('intento', (int) $grabacion->intento)
            ->update(array_merge($columnas, ['updated_at' => AppTime::now()])) > 0;
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

                $this->cerrar($grabacion, $resumen['ok'] ? $resumen['resumen'] : null, $propuesto, $compromiso_id);
            });
        } catch (\Throwable $e) {
            // Lo que depende del resumen se deshizo; la transcripción (lo valioso) queda y la grabación se cierra sin resumen.
            Log::channel('daily')->warning('TranscripcionDeLlamadaService: no se pudo guardar el resumen.', ['grabacion_id' => $grabacion->id, 'error' => get_class($e)]);
            $this->cerrar($grabacion, null, null, null);
        }
    }

    /**
     * Marca la grabación `lista` con lo que salió del resumen (sólo si este job sigue siendo el vigente).
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param string|null          $resumen
     * @param string|null          $propuesto
     * @param int|null             $compromiso_id
     *
     * @return void
     */
    private function cerrar(LeadLlamadaGrabacion $grabacion, $resumen, $propuesto, $compromiso_id): void
    {
        $this->actualizar_si_es_mi_intento($grabacion, [
            'status'               => LeadLlamadaGrabacion::STATUS_LISTA,
            'error_text'           => null,
            'resumen'              => $resumen,
            'compromiso_propuesto' => $propuesto,
            'compromiso_id'        => $compromiso_id,
            'finished_at'          => AppTime::now(),
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
        if (! empty($propuesta['pasada'])) {
            // La fecha ya pasó cuando se transcribió (la llamada fue días antes): no se puede validar como compromiso futuro; se arma el
            // día en palabras directo (con la hora por defecto de la acción si sólo vino el día).
            $momento = \App\Services\Pipelines\PipelineFieldsService::parsear_fecha_de_la_spa((string) $propuesta['vence_at']);
            if (strlen((string) $propuesta['vence_at']) === 10) {
                $momento = $momento->setTime($propuesta['accion'] === LeadCompromiso::ACCION_ESPERAR ? 18 : 10, 0, 0);
            }
        } else {
            $momento = $servicio->limpiar_datos(['vence_at' => $propuesta['vence_at'], 'accion' => $propuesta['accion']])['vence_at'];
        }
        $propuesto = $this->verbo_de_la_accion((string) $propuesta['accion']) . ' ' . $servicio->dia_en_palabras($momento)
            . ($propuesta['cita'] !== null && $propuesta['cita'] !== '' ? ' — «' . $propuesta['cita'] . '»' : '');
        if (! empty($propuesta['pasada'])) {
            // El compromiso se calcula contra el día de la llamada: si ese día ya pasó, agendarlo sería inventar un contacto.
            return ['propuesto' => mb_substr($propuesto, 0, 570) . ' (la fecha ya pasó: no se agendó)', 'compromiso_id' => null];
        }
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
     * Deja la grabación en `error` con un texto claro. Sólo si seguía en `transcribiendo` y en el intento de este job (una que ya terminó,
     * o que se reintentó, no se pisa).
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param string               $texto
     *
     * @return void
     */
    private function dejar_en_error(LeadLlamadaGrabacion $grabacion, string $texto): void
    {
        LeadLlamadaGrabacion::query()
            ->whereKey((int) $grabacion->id)
            ->where('intento', (int) $grabacion->intento)
            ->where('status', LeadLlamadaGrabacion::STATUS_TRANSCRIBIENDO)
            ->update([
                'status'      => LeadLlamadaGrabacion::STATUS_ERROR,
                'error_text'  => mb_substr($texto, 0, 900),
                'finished_at' => AppTime::now(),
                'updated_at'  => AppTime::now(),
            ]);
    }
}
