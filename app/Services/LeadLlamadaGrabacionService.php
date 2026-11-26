<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Jobs\TranscribirGrabacionDeLlamadaJob;
use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadLlamada;
use App\Models\LeadLlamadaGrabacion;
use App\Services\Pipelines\PipelineRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * La subida por partes de la grabación de una llamada de Juan y su estado (misión leads-referidos-compromisos, etapa E3).
 *
 * El archivo del celular puede pesar decenas de megas y el límite de subida del servidor (nginx y PHP) no está medido, así que el
 * panel lo sube en partes de 4 MB en tres pasos: {@see iniciar()} (qué archivo viene), {@see recibir_parte()} (una por vez, idempotente
 * por índice) y {@see completar()} (une las partes, calcula el sha256 y encola la transcripción). La transcripción en sí es de
 * {@see TranscripcionDeLlamadaService}.
 *
 * 🔴 Privacidad: el nombre del archivo y el teléfono del lead son datos personales y NO van a los logs. Este servicio no loguea nada;
 * el archivo se guarda en el disco privado `local` con un nombre propio (`audio.<ext>`), nunca con el nombre que trajo el celular.
 *
 * El 422 de validación sale como {@see PipelineRuleException} (`errors.<campo>`, igual que «Registrar llamada»); las respuestas que
 * llevan datos extra (`faltantes`, el 409 con la grabación que ya estaba) se devuelven armadas como `['http' => int, 'cuerpo' => array]`.
 */
class LeadLlamadaGrabacionService
{
    /** Tamaño de cada parte de la subida: 4 MB (el panel parte el archivo igual). */
    const PARTE_BYTES = 4194304;

    /** Extensiones de audio que acepta la subida. */
    const EXTENSIONES = ['m4a', 'mp3', 'mp4', 'aac', 'ogg', 'opus', 'oga', 'wav', 'webm', 'flac', 'amr', '3gp'];

    /** Minutos que puede estar «transcribiendo» antes de darla por colgada (el worker se cayó) y permitir «Reintentar». */
    const MINUTOS_PARA_DAR_POR_COLGADA = 30;

    /** Largo máximo del nombre original que se guarda (la columna es `string(190)`). */
    const MAX_NOMBRE = 190;

    // ------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------

    /**
     * La llamada de un lead, o 404 si no existe o es de OTRO lead (una grabación nunca se cuelga de la llamada de otro).
     *
     * @param Lead       $lead
     * @param int|string $llamada_id
     *
     * @return LeadLlamada
     *
     * @throws PipelineRuleException 404.
     */
    public function llamada_del_lead(Lead $lead, $llamada_id): LeadLlamada
    {
        $llamada = LeadLlamada::query()->where('lead_id', (int) $lead->id)->whereKey((int) $llamada_id)->first();
        if ($llamada === null) {
            throw PipelineRuleException::no_existe('La llamada');
        }

        return $llamada;
    }

    /**
     * La grabación de una llamada, o 404 si todavía no tiene.
     *
     * @param LeadLlamada $llamada
     *
     * @return LeadLlamadaGrabacion
     *
     * @throws PipelineRuleException 404.
     */
    public function de_la_llamada(LeadLlamada $llamada): LeadLlamadaGrabacion
    {
        $grabacion = LeadLlamadaGrabacion::query()->where('lead_llamada_id', (int) $llamada->id)->orderByDesc('id')->first();
        if ($grabacion === null) {
            throw PipelineRuleException::no_existe('La grabación');
        }

        return $grabacion;
    }

    /**
     * La grabación como la ve la pantalla.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param bool                 $con_transcripcion false en el listado de llamadas: una transcripción de 45.000 caracteres por cada llamada
     *                                                haría pesadísimo el listado; ahí viaja `tiene_transcripcion` y el texto se pide con
     *                                                `GET …/grabacion` al desplegarlo.
     *
     * @return array<string, mixed>
     */
    public function a_json(LeadLlamadaGrabacion $grabacion, bool $con_transcripcion = true): array
    {
        $json = [
            'id'                   => (int) $grabacion->id,
            'lead_id'              => (int) $grabacion->lead_id,
            'lead_llamada_id'      => (int) $grabacion->lead_llamada_id,
            'status'               => (string) $grabacion->status,
            'nombre'               => (string) $grabacion->nombre_original,
            'extension'            => (string) $grabacion->extension,
            'bytes'                => (int) $grabacion->bytes_declarados,
            'bytes_recibidos'      => (int) $grabacion->bytes_recibidos,
            'error_text'           => $grabacion->error_text,
            'duracion_segundos'    => $grabacion->duracion_segundos === null ? null : (int) $grabacion->duracion_segundos,
            'tramos'               => $grabacion->tramos === null ? null : (int) $grabacion->tramos,
            'modelo'               => $grabacion->modelo,
            'resumen'              => $grabacion->resumen,
            'compromiso_propuesto' => $grabacion->compromiso_propuesto,
            'compromiso_id'        => $grabacion->compromiso_id === null ? null : (int) $grabacion->compromiso_id,
            'tiene_transcripcion'  => (string) $grabacion->transcripcion !== '',
            'puede_reintentar'     => $this->puede_reintentar($grabacion),
            'started_at'           => $grabacion->started_at ? $grabacion->started_at->format('Y-m-d H:i:s') : null,
            'finished_at'          => $grabacion->finished_at ? $grabacion->finished_at->format('Y-m-d H:i:s') : null,
            'created_at'           => $grabacion->created_at ? $grabacion->created_at->format('Y-m-d H:i:s') : null,
        ];

        if ($con_transcripcion) {
            $json['transcripcion'] = $grabacion->transcripcion;
        }

        return $json;
    }

    /**
     * ¿Se puede pedir «Reintentar»? Desde `error`, o desde `transcribiendo` si lleva más de 30 minutos (el worker se cayó). Desde
     * `pendiente` no: ya está en la cola y esperar es lo correcto.
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return bool
     */
    public function puede_reintentar(LeadLlamadaGrabacion $grabacion): bool
    {
        if ((string) $grabacion->status === LeadLlamadaGrabacion::STATUS_ERROR) {
            return true;
        }

        if ((string) $grabacion->status !== LeadLlamadaGrabacion::STATUS_TRANSCRIBIENDO) {
            return false;
        }

        // Una grabación «transcribiendo» sin fecha de inicio no se puede medir: se la da por colgada.
        return $grabacion->started_at === null
            || $grabacion->started_at->lt(AppTime::now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADA));
    }

    // ------------------------------------------------------------------------------------------
    // Paso 1: iniciar
    // ------------------------------------------------------------------------------------------

    /**
     * Arranca la subida: valida lo que declara el celular (extensión de audio, tamaño hasta el tope) y crea la grabación `subiendo`.
     *
     * Una llamada tiene UNA grabación. Si ya tiene una que se quedó a medio subir (`subiendo`) o que falló (`error`), la nueva la
     * reemplaza (se borran sus archivos); si ya está en cola, transcribiéndose o lista, no se pisa.
     *
     * @param Lead                 $lead
     * @param LeadLlamada          $llamada
     * @param Admin|null           $actor   Quién sube.
     * @param array<string, mixed> $datos   `nombre`, `bytes` y, opcional, `mime`.
     *
     * @return array{conflicto: bool, grabacion: LeadLlamadaGrabacion} `conflicto` = la llamada ya tenía una grabación que no se puede
     *         pisar; `grabacion` es la que ya estaba (conflicto) o la nueva.
     *
     * @throws PipelineRuleException 422 con `errors.nombre` y/o `errors.bytes`.
     */
    public function iniciar(Lead $lead, LeadLlamada $llamada, $actor, array $datos): array
    {
        $limpios = $this->validar_el_inicio($datos);

        return DB::transaction(function () use ($lead, $llamada, $actor, $limpios) {
            // El candado de la llamada: dos pedidos a la vez no dejan dos grabaciones.
            LeadLlamada::query()->whereKey((int) $llamada->id)->lockForUpdate()->first();

            $existente = LeadLlamadaGrabacion::query()->where('lead_llamada_id', (int) $llamada->id)->orderByDesc('id')->first();
            if ($existente !== null) {
                if (! in_array((string) $existente->status, [LeadLlamadaGrabacion::STATUS_SUBIENDO, LeadLlamadaGrabacion::STATUS_ERROR], true)) {
                    return ['conflicto' => true, 'grabacion' => $existente];
                }

                $this->descartar($existente);
            }

            $grabacion = LeadLlamadaGrabacion::create([
                'lead_llamada_id'     => (int) $llamada->id,
                'lead_id'             => (int) $lead->id,
                'nombre_original'     => $limpios['nombre'],
                'extension'           => $limpios['extension'],
                'mime'                => $limpios['mime'],
                'bytes_declarados'    => $limpios['bytes'],
                'bytes_recibidos'     => 0,
                'partes_recibidas'    => json_encode((object) []),
                'status'              => LeadLlamadaGrabacion::STATUS_SUBIENDO,
                'created_by_admin_id' => $actor !== null ? (int) $actor->id : null,
            ]);

            return ['conflicto' => false, 'grabacion' => $grabacion];
        });
    }

    /**
     * Valida y normaliza lo que declara el celular al arrancar la subida. No toca la base.
     *
     * @param array<string, mixed> $datos
     *
     * @return array{nombre: string, extension: string, mime: string|null, bytes: int}
     *
     * @throws PipelineRuleException 422.
     */
    private function validar_el_inicio(array $datos): array
    {
        // Errores por campo.
        $errores = [];

        $nombre = isset($datos['nombre']) && is_scalar($datos['nombre']) ? trim((string) $datos['nombre']) : '';
        // La extensión es lo que viene después del ÚLTIMO punto: `llamada.m4a.exe` es un .exe, no un m4a.
        $extension = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
        if ($nombre === '') {
            $errores['nombre'][] = 'Falta el nombre del archivo.';
        } elseif (! in_array($extension, self::EXTENSIONES, true)) {
            $errores['nombre'][] = 'El archivo tiene que ser un audio (' . implode(', ', self::EXTENSIONES) . ').';
        }

        // Tamaño declarado: un entero positivo hasta el tope de la configuración.
        $maximo = $this->maximo_de_bytes();
        $bytes  = null;
        if (isset($datos['bytes']) && (is_int($datos['bytes']) || (is_string($datos['bytes']) && ctype_digit($datos['bytes'])))) {
            $bytes = (int) $datos['bytes'];
        }
        if ($bytes === null || $bytes < 1) {
            $errores['bytes'][] = 'El archivo está vacío.';
        } elseif ($bytes > $maximo) {
            $errores['bytes'][] = 'El archivo pesa más de ' . (int) round($maximo / 1048576) . ' MB.';
        }

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        $mime = isset($datos['mime']) && is_string($datos['mime']) && trim($datos['mime']) !== '' ? mb_substr(trim($datos['mime']), 0, 80) : null;

        return ['nombre' => mb_substr($nombre, 0, self::MAX_NOMBRE), 'extension' => $extension, 'mime' => $mime, 'bytes' => (int) $bytes];
    }

    /**
     * Tope de tamaño del archivo, en bytes (200 MB por defecto).
     *
     * @return int
     */
    public function maximo_de_bytes(): int
    {
        return max(1, (int) config('services.openai_call_transcription.max_bytes', 200 * 1048576));
    }

    // ------------------------------------------------------------------------------------------
    // Paso 2: partes
    // ------------------------------------------------------------------------------------------

    /**
     * Guarda una parte. Repetir una parte (un reintento del celular por una mala señal) la pisa: no duplica nada ni suma dos veces.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param mixed                $indice    Número de parte (0 es la primera).
     * @param UploadedFile|null    $parte     El pedazo del archivo (hasta 4 MB).
     *
     * @return LeadLlamadaGrabacion Con los bytes recibidos al día.
     *
     * @throws PipelineRuleException 422 si la grabación ya no admite partes, el índice no existe para ese tamaño o la parte es inválida.
     */
    public function recibir_parte(LeadLlamadaGrabacion $grabacion, $indice, $parte): LeadLlamadaGrabacion
    {
        // Cantidad de partes que componen el archivo declarado.
        $total = $this->cantidad_de_partes($grabacion);

        // Índice entero entre 0 y total-1.
        $numero = null;
        if ((is_int($indice) && $indice >= 0) || (is_string($indice) && ctype_digit($indice))) {
            $numero = (int) $indice;
        }
        if ($numero === null || $numero >= $total) {
            throw PipelineRuleException::validacion(['indice' => ['La parte ' . (is_scalar($indice) ? $indice : '?') . ' no existe: el archivo tiene ' . $total . ' partes (de la 0 a la ' . ($total - 1) . ').']]);
        }

        if (! ($parte instanceof UploadedFile) || ! $parte->isValid()) {
            throw PipelineRuleException::validacion(['parte' => ['No llegó el pedazo del archivo.']]);
        }
        if ((int) $parte->getSize() > self::PARTE_BYTES || (int) $parte->getSize() < 1) {
            throw PipelineRuleException::validacion(['parte' => ['Cada parte tiene que pesar entre 1 byte y 4 MB.']]);
        }

        return DB::transaction(function () use ($grabacion, $numero, $parte) {
            // Se relee con candado: dos partes que llegan juntas no se pisan el registro de lo recibido.
            $actual = LeadLlamadaGrabacion::query()->whereKey((int) $grabacion->id)->lockForUpdate()->first();
            if ($actual === null) {
                throw PipelineRuleException::no_existe('La grabación');
            }
            if ((string) $actual->status !== LeadLlamadaGrabacion::STATUS_SUBIENDO) {
                throw new PipelineRuleException('Esta grabación ya no admite partes (está «' . $actual->status . '»).', [], 422);
            }

            Storage::disk('local')->putFileAs($actual->carpeta(), $parte, 'parte_' . $numero);

            $recibidas          = $actual->partes();
            $recibidas[$numero] = (int) $parte->getSize();
            ksort($recibidas);

            $actual->partes_recibidas = json_encode((object) $recibidas);
            $actual->bytes_recibidos  = array_sum($recibidas);
            $actual->save();

            return $actual;
        });
    }

    /**
     * Cuántas partes de {@see PARTE_BYTES} componen el archivo declarado.
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return int
     */
    private function cantidad_de_partes(LeadLlamadaGrabacion $grabacion): int
    {
        return max(1, (int) ceil(((int) $grabacion->bytes_declarados) / self::PARTE_BYTES));
    }

    // ------------------------------------------------------------------------------------------
    // Paso 3: completar
    // ------------------------------------------------------------------------------------------

    /**
     * Cierra la subida: comprueba que estén todas las partes y que sumen lo declarado, las une, calcula el sha256 y encola la
     * transcripción.
     *
     *  - Faltan partes → 422 con `faltantes` (los índices). Sumas distintas → 422 con `errors.bytes`. No cambia nada: Juan vuelve a
     *    mandar lo que falte y completa de nuevo.
     *  - El `sha256` que mandó el celular no coincide con el del archivo unido → 422 (se corrompió en el camino).
     *  - El mismo archivo ya está en OTRA llamada del MISMO lead → 409 con esa grabación; esta se descarta y no se transcribe dos veces.
     *  - Una grabación que ya no está `subiendo` (un doble toque en «completar») responde 200 con su estado, sin encolar de nuevo.
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param string|null          $sha256_declarado Huella que calculó el celular (opcional).
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function completar(LeadLlamadaGrabacion $grabacion, $sha256_declarado = null): array
    {
        // Resultado de la transacción: la respuesta y, si hay que transcribir, el id a encolar DESPUÉS de confirmarla.
        $resultado = DB::transaction(function () use ($grabacion, $sha256_declarado) {
            $actual = LeadLlamadaGrabacion::query()->whereKey((int) $grabacion->id)->lockForUpdate()->first();
            if ($actual === null) {
                throw PipelineRuleException::no_existe('La grabación');
            }

            // Un doble toque: ya se completó, no se hace dos veces.
            if ((string) $actual->status !== LeadLlamadaGrabacion::STATUS_SUBIENDO) {
                return ['http' => 200, 'cuerpo' => ['grabacion' => $this->a_json($actual)], 'encolar' => null];
            }

            // Partes que faltan.
            $recibidas = $actual->partes();
            $faltantes = [];
            for ($indice = 0, $total = $this->cantidad_de_partes($actual); $indice < $total; $indice++) {
                if (! isset($recibidas[$indice])) {
                    $faltantes[] = $indice;
                }
            }
            if ($faltantes !== []) {
                return ['http' => 422, 'cuerpo' => [
                    'message'   => 'Faltan ' . count($faltantes) . ' partes del archivo: volvé a subirlas y completá de nuevo.',
                    'faltantes' => $faltantes,
                    'grabacion' => $this->a_json($actual),
                ], 'encolar' => null];
            }
            if (array_sum($recibidas) !== (int) $actual->bytes_declarados) {
                return ['http' => 422, 'cuerpo' => [
                    'message'   => 'Las partes no suman el tamaño del archivo (' . array_sum($recibidas) . ' de ' . (int) $actual->bytes_declarados . ' bytes). Volvé a subirlo.',
                    'errors'    => ['bytes' => ['Las partes recibidas no suman el tamaño del archivo.']],
                    'faltantes' => [],
                    'grabacion' => $this->a_json($actual),
                ], 'encolar' => null];
            }

            // Se unen las partes en un archivo propio (`audio.<ext>`): no se usa el nombre del celular, que trae datos personales.
            $ruta_relativa = $actual->carpeta() . '/audio.' . $actual->extension;
            $this->unir_las_partes($actual, $ruta_relativa);
            $ruta_absoluta = Storage::disk('local')->path($ruta_relativa);
            $sha256        = (string) hash_file('sha256', $ruta_absoluta);

            // El celular mandó su huella y no coincide: el archivo se corrompió en la subida.
            $declarado = is_string($sha256_declarado) ? strtolower(trim($sha256_declarado)) : '';
            if ($declarado !== '' && $declarado !== $sha256) {
                Storage::disk('local')->delete($ruta_relativa);

                return ['http' => 422, 'cuerpo' => [
                    'message'   => 'El archivo se dañó en la subida. Volvé a subirlo.',
                    'errors'    => ['sha256' => ['El archivo se dañó en la subida.']],
                    'faltantes' => [],
                    'grabacion' => $this->a_json($actual),
                ], 'encolar' => null];
            }

            // El mismo audio ya está en otra llamada de este lead.
            $repetida = LeadLlamadaGrabacion::query()
                ->where('lead_id', (int) $actual->lead_id)
                ->where('sha256', $sha256)
                ->where('id', '!=', (int) $actual->id)
                ->orderBy('id')
                ->first();
            if ($repetida !== null) {
                $this->descartar($actual);

                return ['http' => 409, 'cuerpo' => [
                    'message'   => 'Esa grabación ya estaba cargada en otra llamada de este lead.',
                    'code'      => 'grabacion_repetida',
                    'grabacion' => $this->a_json($repetida),
                ], 'encolar' => null];
            }

            // Las partes ya no hacen falta: queda sólo el archivo unido.
            foreach (array_keys($recibidas) as $indice) {
                Storage::disk('local')->delete($actual->carpeta() . '/parte_' . $indice);
            }

            $actual->sha256          = $sha256;
            $actual->ruta            = $ruta_relativa;
            $actual->bytes_recibidos = (int) filesize($ruta_absoluta);
            $actual->status          = LeadLlamadaGrabacion::STATUS_PENDIENTE;
            $actual->error_text      = null;
            $actual->save();

            return ['http' => 200, 'cuerpo' => ['grabacion' => $this->a_json($actual)], 'encolar' => (int) $actual->id];
        });

        // Se encola fuera de la transacción: el worker no puede ver la grabación antes de que esté confirmada.
        if ($resultado['encolar'] !== null) {
            TranscribirGrabacionDeLlamadaJob::dispatch($resultado['encolar']);
        }

        return ['http' => $resultado['http'], 'cuerpo' => $resultado['cuerpo']];
    }

    /**
     * Une las partes `parte_0 … parte_N` en un solo archivo, copiando de a flujos (nunca se carga el archivo entero en memoria).
     *
     * @param LeadLlamadaGrabacion $grabacion
     * @param string               $ruta_relativa Destino dentro del disco `local`.
     *
     * @return void
     *
     * @throws \RuntimeException Si no se puede leer una parte o escribir el destino.
     */
    private function unir_las_partes(LeadLlamadaGrabacion $grabacion, string $ruta_relativa): void
    {
        $disco   = Storage::disk('local');
        $destino = $disco->path($ruta_relativa);

        $salida = @fopen($destino, 'wb');
        if ($salida === false) {
            throw new \RuntimeException('No se pudo escribir la grabación unida.');
        }

        try {
            for ($indice = 0, $total = $this->cantidad_de_partes($grabacion); $indice < $total; $indice++) {
                $entrada = @fopen($disco->path($grabacion->carpeta() . '/parte_' . $indice), 'rb');
                if ($entrada === false) {
                    throw new \RuntimeException('No se pudo leer la parte ' . $indice . '.');
                }
                try {
                    stream_copy_to_stream($entrada, $salida);
                } finally {
                    fclose($entrada);
                }
            }
        } finally {
            fclose($salida);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Reintentar
    // ------------------------------------------------------------------------------------------

    /**
     * Vuelve a poner en cola una grabación que falló (o que quedó colgada en `transcribiendo` hace más de 30 minutos).
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return LeadLlamadaGrabacion En `pendiente`.
     *
     * @throws PipelineRuleException 422 si no se puede reintentar desde su estado actual.
     */
    public function reintentar(LeadLlamadaGrabacion $grabacion): LeadLlamadaGrabacion
    {
        $actualizada = DB::transaction(function () use ($grabacion) {
            $actual = LeadLlamadaGrabacion::query()->whereKey((int) $grabacion->id)->lockForUpdate()->first();
            if ($actual === null) {
                throw PipelineRuleException::no_existe('La grabación');
            }

            if (! $this->puede_reintentar($actual)) {
                throw new PipelineRuleException($this->motivo_de_no_reintentar($actual), [], 422);
            }

            // Sin el archivo unido no hay nada que transcribir (por ejemplo, si se borró a mano): el reintento no tiene sentido.
            if ((string) $actual->ruta === '' || ! Storage::disk('local')->exists((string) $actual->ruta)) {
                throw new PipelineRuleException('El archivo de la grabación ya no está en el servidor: hay que subirlo de nuevo.', [], 422);
            }

            $actual->status      = LeadLlamadaGrabacion::STATUS_PENDIENTE;
            $actual->error_text  = null;
            $actual->finished_at = null;
            $actual->save();

            return $actual;
        });

        TranscribirGrabacionDeLlamadaJob::dispatch((int) $actualizada->id);

        return $actualizada;
    }

    /**
     * Por qué no se puede reintentar, en una oración para el toast.
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return string
     */
    private function motivo_de_no_reintentar(LeadLlamadaGrabacion $grabacion): string
    {
        switch ((string) $grabacion->status) {
            case LeadLlamadaGrabacion::STATUS_LISTA:
                return 'La grabación ya está transcripta.';
            case LeadLlamadaGrabacion::STATUS_PENDIENTE:
                return 'La grabación ya está en la cola: esperá unos minutos.';
            case LeadLlamadaGrabacion::STATUS_TRANSCRIBIENDO:
                return 'La grabación se está transcribiendo ahora: esperá unos minutos.';
            default:
                return 'La grabación todavía se está subiendo.';
        }
    }

    // ------------------------------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------------------------------

    /**
     * Borra una grabación y todos sus archivos (partes y archivo unido).
     *
     * @param LeadLlamadaGrabacion $grabacion
     *
     * @return void
     */
    public function descartar(LeadLlamadaGrabacion $grabacion): void
    {
        Storage::disk('local')->deleteDirectory($grabacion->carpeta());
        $grabacion->delete();
    }
}
