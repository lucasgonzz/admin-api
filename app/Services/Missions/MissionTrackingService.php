<?php

namespace App\Services\Missions;

use App\Models\MissionAnswer;
use App\Models\MissionQuestion;
use App\Models\MissionTracking;
use App\Models\MissionTrackingEvent;
use App\Services\Local\EscenaService;
use App\Services\Local\PcSnapshotService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Seguimiento de misiones en el Admin (misión dotin-misiones-admin, 26/11/2026): recibe el estado que publica la
 * Gamer (`PUT claude/missions/{slug}`), sus eventos, y arma el bloque `seguimiento` que leen El local y el conector MCP.
 *
 * Reglas que sostiene:
 *  - Un PUT con `seq` menor o igual al guardado no se aplica (200 `aplicado: false`): las publicaciones llegan fuera de orden.
 *  - `fase_desde` sólo cambia cuando cambia la fase y `estado_desde` sólo cuando cambia el estado: un latido con lo mismo
 *    no «reinicia» el cronómetro.
 *  - La frescura (`vigencia`) se mide contra la hora DEL ADMIN (`reportado_at`), nunca contra el reloj de la PC, y una misión
 *    «activa» sin actividad real reciente figura «activa sin señal».
 *  - `terminada` exige evidencia verificable (merges o archivos con sha256).
 *
 * Los métodos que atienden a la Gamer devuelven `['status' => int, 'body' => array]` para que el controlador sólo los
 * convierta en JSON (los errores llevan `message` y un `codigo` estable).
 */
class MissionTrackingService
{
    /** Estados del seguimiento. */
    const ESTADOS = ['en_cola', 'activa', 'pausada_tecnica', 'esperando_decision', 'terminada', 'detenida'];

    /** Estados finales: no cambian sin una publicación nueva, así que nunca figuran como desactualizados. */
    const ESTADOS_FINALES = ['terminada', 'detenida'];

    /** Tipos de evento que puede publicar la Gamer. */
    const TIPOS_DE_EVENTO = ['arranque_confirmado', 'corte_tecnico', 'reanudacion', 'pregunta', 'respuesta_aplicada', 'detenida', 'terminada', 'fase'];

    /** Fase fina -> etapa (copia de `PHASE_STAGE` del reporter de El local). */
    const FASE_ETAPA = [
        'contexto' => 'plan', 'plan' => 'plan',
        'tests-rojo' => 'construccion', 'construccion' => 'construccion',
        'revision-1' => 'revision', 'revision-2' => 'revision', 'interfaz' => 'revision',
        'merge-base' => 'tests', 'suite-final' => 'tests',
        'revision-merge' => 'merge', 'merge-local' => 'merge',
        'informe' => 'cierre', 'entregada' => 'cierre',
    ];

    /** Estado del seguimiento -> estado que entiende El local (el mapa del plan, §2.0). */
    const ESTADO_DE_EL_LOCAL = [
        'en_cola' => 'trabajando', 'activa' => 'trabajando', 'pausada_tecnica' => 'trabajando',
        'esperando_decision' => 'esperando_lucas', 'detenida' => 'trabada', 'terminada' => 'terminada',
    ];

    /** Zona horaria de todas las fechas que salen y se guardan. */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * La clave de misión que usan El local y el seguimiento.
     *
     * @param string $slug Slug de la misión.
     *
     * @return string `cc:<slug>`.
     */
    public function clave_de($slug)
    {
        return 'cc:' . $slug;
    }

    /**
     * El estado que entiende El local para un estado del seguimiento.
     *
     * @param string $estado Estado del seguimiento.
     *
     * @return string `trabajando`, `esperando_lucas`, `trabada` o `terminada`.
     */
    public function estado_de_el_local($estado)
    {
        return isset(self::ESTADO_DE_EL_LOCAL[$estado]) ? self::ESTADO_DE_EL_LOCAL[$estado] : 'trabajando';
    }

    /**
     * La hora actual del Admin (respeta el reloj congelado de los tests).
     *
     * @return Carbon
     */
    public function ahora()
    {
        return Carbon::now(self::ZONA);
    }

    /**
     * Borra la caché de la escena de El local: lo publicado tiene que verse ya, no a los 20 segundos.
     *
     * @return void
     */
    public function olvidar_escena()
    {
        Cache::forget(EscenaService::CACHE_KEY);
    }

    /**
     * Una fecha que llegó en ISO-8601 (con su zona) pasada a la zona del Admin, que es en la que se guardan las fechas.
     *
     * @param string|null $valor Fecha recibida.
     *
     * @return Carbon|null
     */
    private function a_hora_del_admin($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::parse($valor)->setTimezone(self::ZONA);
    }

    /**
     * Una fecha como ISO-8601 de Argentina (null si no hay).
     *
     * @param mixed $fecha Atributo datetime.
     *
     * @return string|null
     */
    private function iso($fecha)
    {
        return $fecha === null ? null : Carbon::parse($fecha)->setTimezone(self::ZONA)->toIso8601String();
    }

    /**
     * Minutos enteros entre una fecha y ahora (nunca negativos); null si no hay fecha.
     *
     * @param mixed  $fecha Atributo datetime.
     * @param Carbon $ahora Momento de referencia.
     *
     * @return int|null
     */
    private function minutos_desde($fecha, Carbon $ahora)
    {
        if ($fecha === null) {
            return null;
        }

        return (int) floor(max(0, $ahora->getTimestamp() - Carbon::parse($fecha)->getTimestamp()) / 60);
    }

    /**
     * Un texto recortado a su largo (los textos largos no son un error: se recortan).
     *
     * @param mixed $texto Texto recibido.
     * @param int   $largo Largo máximo.
     *
     * @return string|null
     */
    private function recortar($texto, $largo)
    {
        if ($texto === null) {
            return null;
        }

        return mb_substr((string) $texto, 0, $largo);
    }

    /**
     * Una respuesta de error con el formato de la Gamer.
     *
     * @param int    $status  Estado HTTP.
     * @param string $codigo  Código estable.
     * @param string $mensaje Mensaje en castellano.
     *
     * @return array{status: int, body: array<string, string>}
     */
    private function error($status, $codigo, $mensaje)
    {
        return ['status' => $status, 'body' => ['message' => $mensaje, 'codigo' => $codigo]];
    }

    /**
     * La respuesta 422 de una validación fallida (con el mapa de errores).
     *
     * @param \Illuminate\Contracts\Validation\Validator $validador Validador que falló.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error_de_validacion($validador)
    {
        return ['status' => 422, 'body' => ['message' => 'Los datos enviados no son válidos.', 'codigo' => 'datos_invalidos', 'errors' => $validador->errors()->toArray()]];
    }

    /**
     * Si la evidencia alcanza para dar una misión por terminada: al menos un merge `repo@rama=sha` o un archivo con su sha256.
     *
     * @param mixed $evidencia Evidencia recibida.
     *
     * @return bool
     */
    private function evidencia_valida($evidencia)
    {
        if (! is_array($evidencia)) {
            return false;
        }

        if (isset($evidencia['merges']) && is_array($evidencia['merges']) && count($evidencia['merges']) > 0) {
            $todos = true;
            foreach ($evidencia['merges'] as $merge) {
                if (! is_string($merge) || ! preg_match('/^[a-z-]+@[a-z0-9._\/-]+=[0-9a-f]{7,40}$/', $merge)) {
                    $todos = false;
                }
            }
            if ($todos) {
                return true;
            }
        }

        if (isset($evidencia['archivos']) && is_array($evidencia['archivos']) && count($evidencia['archivos']) > 0) {
            foreach ($evidencia['archivos'] as $archivo) {
                if (! is_array($archivo) || empty($archivo['ruta']) || ! is_string($archivo['ruta']) || mb_strlen($archivo['ruta']) > 200
                    || empty($archivo['sha256']) || ! is_string($archivo['sha256']) || ! preg_match('/^[0-9a-f]{64}$/', $archivo['sha256'])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * `PUT claude/missions/{slug}`: upsert del estado completo de una misión.
     *
     * @param string               $slug    Slug de la misión (la ruta ya lo validó).
     * @param array<string, mixed> $entrada Cuerpo del pedido.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function publicar($slug, array $entrada)
    {
        $validador = Validator::make($entrada, [
            'seq'                      => 'required|integer|min:1',
            'host'                     => 'required|string|max:60',
            'titulo'                   => 'nullable|string',
            'origen'                   => 'nullable|in:' . implode(',', PcSnapshotService::ORIGENES),
            'pedido_id'                => ['nullable', 'string', 'regex:/^[0-9]{8}(-[0-9]{4})?-[a-z0-9-]{1,40}$/'],
            'hilo'                     => ['nullable', 'string', 'regex:/^[CG][A-Z0-9]{8,12}(:[0-9]{10}\.[0-9]{6})?$/'],
            'repos'                    => 'nullable|array|max:10',
            'repos.*'                  => 'string|max:40',
            'estado'                   => 'required|in:' . implode(',', self::ESTADOS),
            'fase'                     => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'fase_desde'               => 'nullable|date',
            'estado_desde'             => 'nullable|date',
            'ultimo_avance'            => 'nullable|string',
            'pendiente'                => 'nullable|string',
            'motivo'                   => 'nullable|string',
            'proximo_paso'             => 'nullable|string',
            'ejecucion_n'              => 'nullable|integer|min:0',
            'intentos_tecnicos'        => 'nullable|integer|min:0',
            'arranque_confirmado_at'   => 'nullable|date',
            'ultima_actividad_real_at' => 'nullable|date',
            'pregunta_abierta'         => 'nullable|array',
            'pregunta_abierta.question_uid' => 'required_with:pregunta_abierta|string|max:36',
            'pregunta_abierta.revision'     => 'nullable|integer|min:1',
            'evidencia'                => 'nullable|array',
        ]);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $datos = $validador->validated();

        if ($datos['estado'] === 'terminada' && ! $this->evidencia_valida(isset($datos['evidencia']) ? $datos['evidencia'] : null)) {
            return $this->error(422, 'terminada_sin_evidencia', 'Una misión terminada necesita evidencia: al menos un merge (repo@rama=sha) o un archivo con su sha256.');
        }

        $clave = $this->clave_de($slug);

        // Hasta dos vueltas: si dos PUT crean la misma fila a la vez, el segundo reintenta y la encuentra.
        for ($vuelta = 0; $vuelta < 2; $vuelta++) {
            try {
                $resultado = DB::transaction(function () use ($clave, $slug, $datos) {
                    return $this->aplicar_publicacion($clave, $slug, $datos);
                });
            } catch (QueryException $e) {
                if ($vuelta === 1) {
                    throw $e;
                }
                continue;
            }

            if ($resultado['body']['aplicado']) {
                $this->olvidar_escena();
            }

            return $resultado;
        }

        return $this->error(500, 'error_interno', 'No se pudo guardar el estado.');
    }

    /**
     * Aplica una publicación ya validada dentro de la transacción (con la fila bloqueada).
     *
     * @param string               $clave `cc:<slug>`.
     * @param string               $slug  Slug.
     * @param array<string, mixed> $datos Datos validados.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function aplicar_publicacion($clave, $slug, array $datos)
    {
        $ahora = $this->ahora();
        $fila  = MissionTracking::where('mission_key', $clave)->lockForUpdate()->first();

        if ($fila !== null && (int) $datos['seq'] <= (int) $fila->seq) {
            return ['status' => 200, 'body' => ['mission_key' => $clave, 'aplicado' => false, 'motivo' => 'seq_viejo', 'seq_actual' => (int) $fila->seq]];
        }

        $creada = $fila === null;
        if ($creada) {
            $fila = new MissionTracking();
            $fila->mission_key = $clave;
            $fila->slug        = $slug;
        }

        // El cronómetro de la fase y el del estado sólo arrancan de nuevo cuando cambia la fase o el estado.
        $fase = isset($datos['fase']) ? $datos['fase'] : null;
        if ($creada || $fila->fase !== $fase) {
            $desde = $this->a_hora_del_admin(isset($datos['fase_desde']) ? $datos['fase_desde'] : null);
            $fila->fase_desde = $fase === null ? null : ($desde !== null ? $desde : $ahora->copy());
        }
        $fila->fase  = $fase;
        $fila->etapa = $fase !== null && isset(self::FASE_ETAPA[$fase]) ? self::FASE_ETAPA[$fase] : null;

        if ($creada || $fila->estado !== $datos['estado']) {
            $desde = $this->a_hora_del_admin(isset($datos['estado_desde']) ? $datos['estado_desde'] : null);
            $fila->estado_desde = $desde !== null ? $desde : $ahora->copy();
        }
        $estado_anterior = $fila->estado;
        $fila->estado    = $datos['estado'];

        $fila->host      = $datos['host'];
        $fila->titulo    = $this->recortar(isset($datos['titulo']) ? $datos['titulo'] : null, 120);
        $fila->origen    = isset($datos['origen']) && $datos['origen'] !== null ? $datos['origen'] : 'desconocido';
        $fila->pedido_id = isset($datos['pedido_id']) ? $datos['pedido_id'] : null;
        $fila->hilo      = isset($datos['hilo']) ? $datos['hilo'] : null;
        $fila->repos     = isset($datos['repos']) ? array_values($datos['repos']) : [];

        $fila->ultimo_avance = $this->recortar(isset($datos['ultimo_avance']) ? $datos['ultimo_avance'] : null, 500);
        $fila->pendiente     = $this->recortar(isset($datos['pendiente']) ? $datos['pendiente'] : null, 500);
        $fila->motivo        = $this->recortar(isset($datos['motivo']) ? $datos['motivo'] : null, 300);
        $fila->proximo_paso  = $this->recortar(isset($datos['proximo_paso']) ? $datos['proximo_paso'] : null, 300);

        $fila->ejecucion_n              = isset($datos['ejecucion_n']) ? (int) $datos['ejecucion_n'] : 0;
        $fila->intentos_tecnicos        = isset($datos['intentos_tecnicos']) ? (int) $datos['intentos_tecnicos'] : 0;
        $fila->arranque_confirmado_at   = $this->a_hora_del_admin(isset($datos['arranque_confirmado_at']) ? $datos['arranque_confirmado_at'] : null);
        $fila->ultima_actividad_real_at = $this->a_hora_del_admin(isset($datos['ultima_actividad_real_at']) ? $datos['ultima_actividad_real_at'] : null);

        // La hora del Admin del último PUT aplicado: contra ella se mide si la PC sigue reportando.
        $fila->reportado_at = $ahora->copy();
        $fila->seq          = (int) $datos['seq'];

        // Terminada: la evidencia ya se validó y queda la hora en que se dio por terminada (la primera vez).
        $fila->evidencia = isset($datos['evidencia']) ? $datos['evidencia'] : null;
        if ($datos['estado'] === 'terminada') {
            if ($estado_anterior !== 'terminada' || $fila->terminada_at === null) {
                $fila->terminada_at = $ahora->copy();
            }
        } else {
            $fila->terminada_at = null;
        }

        // Reconciliación de la pregunta abierta: lo que la Gamer ya no considera abierto se cierra.
        $abierta_uid = isset($datos['pregunta_abierta']['question_uid']) ? $datos['pregunta_abierta']['question_uid'] : null;
        if ($abierta_uid !== null) {
            $existente = MissionQuestion::where('question_uid', $abierta_uid)->first();
            if ($existente !== null && in_array($existente->estado, ['aplicada', 'cerrada', 'reemplazada'], true)) {
                // Una publicación atrasada que todavía nombra una pregunta ya resuelta no la reabre.
                $abierta_uid = null;
            }
        }
        $this->cerrar_preguntas_que_ya_no_estan_abiertas($clave, $abierta_uid, $ahora);
        $fila->pregunta_abierta_uid = $abierta_uid;

        $fila->save();

        return ['status' => 200, 'body' => [
            'mission_key'  => $clave,
            'aplicado'     => true,
            'creada'       => $creada,
            'seq'          => (int) $fila->seq,
            'estado'       => $fila->estado,
            'estado_desde' => $this->iso($fila->estado_desde),
            'fase'         => $fila->fase,
            'fase_desde'   => $this->iso($fila->fase_desde),
        ]];
    }

    /**
     * Cierra las preguntas de la misión que están abiertas o respondidas y NO son la que la Gamer dice que sigue abierta,
     * y rechaza las respuestas que quedaban pendientes sobre ellas.
     *
     * @param string      $clave       `cc:<slug>`.
     * @param string|null $abierta_uid Uid de la pregunta que sigue abierta (null = ninguna).
     * @param Carbon      $ahora       Momento.
     *
     * @return void
     */
    private function cerrar_preguntas_que_ya_no_estan_abiertas($clave, $abierta_uid, Carbon $ahora)
    {
        $consulta = MissionQuestion::where('mission_key', $clave)->whereIn('estado', ['abierta', 'respondida']);
        if ($abierta_uid !== null) {
            $consulta->where('question_uid', '!=', $abierta_uid);
        }

        foreach ($consulta->get() as $pregunta) {
            $pregunta->estado        = 'cerrada';
            $pregunta->closed_at     = $ahora->copy();
            $pregunta->cierre_motivo = 'cerrada_por_ejecutor';
            $pregunta->save();

            MissionAnswer::where('question_id', $pregunta->id)->whereIn('estado', ['registrada', 'reclamada'])->update([
                'estado' => 'rechazada', 'rechazo_motivo' => 'cerrada', 'updated_at' => $ahora->copy(),
            ]);
        }
    }

    /**
     * `POST claude/missions/{slug}/events`: un hito del historial, idempotente por `event_uid`.
     *
     * @param string               $slug    Slug de la misión.
     * @param array<string, mixed> $entrada Cuerpo del pedido.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function registrar_evento($slug, array $entrada)
    {
        $clave = $this->clave_de($slug);
        if (! MissionTracking::where('mission_key', $clave)->exists()) {
            return $this->error(404, 'mision_inexistente', 'No hay una misión con ese slug en el seguimiento.');
        }

        $validador = Validator::make($entrada, [
            'event_uid'   => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'tipo'        => 'required|in:' . implode(',', self::TIPOS_DE_EVENTO),
            'estado'      => 'nullable|string|max:30',
            'fase'        => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'texto'       => 'nullable|string',
            'ocurrido_at' => 'required|date',
        ]);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $datos = $validador->validated();

        $existente = MissionTrackingEvent::where('event_uid', $datos['event_uid'])->first();
        if ($existente !== null) {
            return ['status' => 200, 'body' => ['duplicado' => true, 'event' => $this->evento_serializado($existente)]];
        }

        try {
            $evento = $this->crear_evento($clave, $datos['tipo'], [
                'event_uid'   => $datos['event_uid'],
                'estado'      => isset($datos['estado']) ? $datos['estado'] : null,
                'fase'        => isset($datos['fase']) ? $datos['fase'] : null,
                'texto'       => isset($datos['texto']) ? $datos['texto'] : null,
                'ocurrido_at' => $this->a_hora_del_admin($datos['ocurrido_at']),
            ]);
        } catch (QueryException $e) {
            // Otro pedido idéntico ganó la carrera por el `event_uid` único: es un duplicado.
            $existente = MissionTrackingEvent::where('event_uid', $datos['event_uid'])->first();
            if ($existente === null) {
                throw $e;
            }

            return ['status' => 200, 'body' => ['duplicado' => true, 'event' => $this->evento_serializado($existente)]];
        }

        return ['status' => 201, 'body' => ['event' => $this->evento_serializado($evento)]];
    }

    /**
     * Crea un evento del historial de una misión. Lo usan la Gamer (por `POST events`) y el propio Admin (preguntas y
     * respuestas), sin guardar nunca el texto de una respuesta.
     *
     * @param string               $clave `cc:<slug>`.
     * @param string               $tipo  Tipo de evento.
     * @param array<string, mixed> $datos `event_uid?`, `estado?`, `fase?`, `texto?`, `ocurrido_at?`.
     *
     * @return MissionTrackingEvent
     */
    public function crear_evento($clave, $tipo, array $datos = [])
    {
        return MissionTrackingEvent::create([
            'mission_key' => $clave,
            'event_uid'   => isset($datos['event_uid']) ? $datos['event_uid'] : 'adm-' . bin2hex(random_bytes(12)),
            'tipo'        => $tipo,
            'estado'      => isset($datos['estado']) ? $datos['estado'] : null,
            'fase'        => isset($datos['fase']) ? $datos['fase'] : null,
            'texto'       => $this->recortar(isset($datos['texto']) ? $datos['texto'] : null, 300),
            'ocurrido_at' => isset($datos['ocurrido_at']) && $datos['ocurrido_at'] !== null ? $datos['ocurrido_at'] : $this->ahora(),
        ]);
    }

    /**
     * Un evento como sale por la API.
     *
     * @param MissionTrackingEvent $evento Evento.
     *
     * @return array<string, mixed>
     */
    private function evento_serializado(MissionTrackingEvent $evento)
    {
        return [
            'tipo'        => $evento->tipo,
            'estado'      => $evento->estado,
            'fase'        => $evento->fase,
            'texto'       => $evento->texto,
            'ocurrido_at' => $this->iso($evento->ocurrido_at),
        ];
    }

    /**
     * El bloque `seguimiento` de una misión: estado, fase, frescura, pregunta abierta y evidencia (§2.2.4 del plan).
     *
     * @param MissionTracking $fila         Fila de seguimiento.
     * @param Carbon          $ahora        Momento contra el que se mide la frescura.
     * @param bool            $con_eventos  Si suma los últimos eventos (sólo el detalle de una misión).
     *
     * @return array<string, mixed>
     */
    public function serializar(MissionTracking $fila, Carbon $ahora, $con_eventos = false)
    {
        $pregunta = null;
        if ($fila->pregunta_abierta_uid !== null) {
            $modelo = MissionQuestion::where('question_uid', $fila->pregunta_abierta_uid)->first();
            if ($modelo !== null) {
                $respuesta = MissionAnswer::where('question_id', $modelo->id)->whereIn('estado', ['registrada', 'reclamada', 'aplicada'])->orderBy('id', 'desc')->first();
                $pregunta  = $this->pregunta_serializada($modelo, $respuesta);
            }
        }

        return $this->armar_bloque($fila, $ahora, $con_eventos, $pregunta);
    }

    /**
     * El bloque `seguimiento` de varias misiones a la vez (la escena trae hasta 40): tres consultas en total.
     *
     * @param array<int, string> $claves Claves `cc:<slug>`.
     * @param Carbon|null        $ahora  Momento (por defecto, ahora).
     *
     * @return array<string, array<string, mixed>> Bloque por clave (las que no tienen fila no están).
     */
    public function por_claves(array $claves, Carbon $ahora = null)
    {
        $salida = [];
        if (count($claves) === 0) {
            return $salida;
        }
        $ahora = $ahora === null ? $this->ahora() : $ahora;

        $filas = MissionTracking::whereIn('mission_key', $claves)->get();
        if ($filas->count() === 0) {
            return $salida;
        }

        $uids      = $filas->pluck('pregunta_abierta_uid')->filter()->values()->all();
        $preguntas = count($uids) > 0 ? MissionQuestion::whereIn('question_uid', $uids)->get()->keyBy('question_uid') : collect();
        $respuestas = collect();
        if ($preguntas->count() > 0) {
            $respuestas = MissionAnswer::whereIn('question_id', $preguntas->pluck('id')->all())
                ->whereIn('estado', ['registrada', 'reclamada', 'aplicada'])->orderBy('id', 'asc')->get()->keyBy('question_id');
        }

        foreach ($filas as $fila) {
            $pregunta = null;
            if ($fila->pregunta_abierta_uid !== null && $preguntas->has($fila->pregunta_abierta_uid)) {
                $modelo   = $preguntas->get($fila->pregunta_abierta_uid);
                $pregunta = $this->pregunta_serializada($modelo, $respuestas->get($modelo->id));
            }
            $salida[$fila->mission_key] = $this->armar_bloque($fila, $ahora, false, $pregunta);
        }

        return $salida;
    }

    /**
     * Arma el bloque con la frescura calculada.
     *
     * @param MissionTracking          $fila        Fila.
     * @param Carbon                   $ahora       Momento.
     * @param bool                     $con_eventos Si suma los eventos.
     * @param array<string, mixed>|null $pregunta   Pregunta ya serializada.
     *
     * @return array<string, mixed>
     */
    private function armar_bloque(MissionTracking $fila, Carbon $ahora, $con_eventos, $pregunta)
    {
        $al_dia   = (int) config('missions.reporte_al_dia_min', 15);
        $sin_senal = (int) config('missions.reporte_sin_senal_min', 60);
        $actividad_max = (int) config('missions.actividad_real_max_min', 20);

        $minutos_sin_reporte   = $this->minutos_desde($fila->reportado_at, $ahora);
        $minutos_sin_actividad = $this->minutos_desde($fila->ultima_actividad_real_at, $ahora);

        // Los estados finales no cambian sin una publicación nueva: no «envejecen».
        if (in_array($fila->estado, self::ESTADOS_FINALES, true)) {
            $vigencia = 'al_dia';
        } elseif ($minutos_sin_reporte === null || $minutos_sin_reporte >= $sin_senal) {
            $vigencia = 'sin_senal';
        } elseif ($minutos_sin_reporte >= $al_dia) {
            $vigencia = 'desactualizada';
        } else {
            $vigencia = 'al_dia';
        }

        // Nunca «activa» sólo porque el proceso existe: hace falta actividad real reciente.
        $estado_visible = $fila->estado;
        if ($fila->estado === 'activa' && ($minutos_sin_actividad === null || $minutos_sin_actividad >= $actividad_max)) {
            $estado_visible = 'activa_sin_senal';
        }

        $bloque = [
            'fuente'                   => 'admin',
            'estado'                   => $fila->estado,
            'estado_visible'           => $estado_visible,
            'estado_desde'             => $this->iso($fila->estado_desde),
            'minutos_en_estado'        => $this->minutos_desde($fila->estado_desde, $ahora),
            'fase'                     => $fila->fase,
            'etapa'                    => $fila->etapa,
            'fase_desde'               => $this->iso($fila->fase_desde),
            'minutos_en_fase'          => $this->minutos_desde($fila->fase_desde, $ahora),
            'ultimo_avance'            => $fila->ultimo_avance,
            'pendiente'                => $fila->pendiente,
            'motivo'                   => $fila->motivo,
            'proximo_paso'             => $fila->proximo_paso,
            'ejecucion_n'              => (int) $fila->ejecucion_n,
            'intentos_tecnicos'        => (int) $fila->intentos_tecnicos,
            'arranque_confirmado_at'   => $this->iso($fila->arranque_confirmado_at),
            'ultima_actividad_real_at' => $this->iso($fila->ultima_actividad_real_at),
            'reportado_at'             => $this->iso($fila->reportado_at),
            'vigencia'                 => $vigencia,
            'minutos_sin_reporte'      => $minutos_sin_reporte,
            'minutos_sin_actividad'    => $minutos_sin_actividad,
            'pregunta'                 => $pregunta,
            'evidencia'                => $fila->evidencia,
            'terminada_at'             => $this->iso($fila->terminada_at),
        ];

        if ($con_eventos) {
            $bloque['eventos'] = [];
            $limite = (int) config('missions.eventos_en_detalle', 10);
            foreach (MissionTrackingEvent::where('mission_key', $fila->mission_key)->orderBy('ocurrido_at', 'desc')->orderBy('id', 'desc')->limit($limite)->get() as $evento) {
                $bloque['eventos'][] = $this->evento_serializado($evento);
            }
        }

        return $bloque;
    }

    /**
     * Una pregunta como sale en el bloque `seguimiento`.
     *
     * @param MissionQuestion    $pregunta  Pregunta.
     * @param MissionAnswer|null $respuesta Respuesta vigente (si hay).
     *
     * @return array<string, mixed>
     */
    private function pregunta_serializada(MissionQuestion $pregunta, $respuesta)
    {
        return [
            'question_uid'    => $pregunta->question_uid,
            'revision'        => (int) $pregunta->revision,
            'tipo'            => $pregunta->tipo,
            'estado'          => $pregunta->estado,
            'pregunta'        => $pregunta->pregunta,
            'opciones'        => $this->opciones_serializadas($pregunta),
            'recomendada'     => $pregunta->recomendada,
            'created_at'      => $this->iso($pregunta->created_at),
            'puede_responder' => $pregunta->tipo === 'ordinaria' && $pregunta->estado === 'abierta',
            'respuesta'       => $respuesta === null ? null : [
                'estado'     => $respuesta->estado,
                'opcion'     => $respuesta->opcion,
                'created_at' => $this->iso($respuesta->created_at),
                'canal'      => $respuesta->canal,
            ],
        ];
    }

    /**
     * Las opciones de una pregunta con sus tres claves, siempre en el mismo orden.
     *
     * @param MissionQuestion $pregunta Pregunta.
     *
     * @return array<int, array<string, string|null>>
     */
    private function opciones_serializadas(MissionQuestion $pregunta)
    {
        $salida = [];
        foreach (is_array($pregunta->opciones) ? $pregunta->opciones : [] as $opcion) {
            $salida[] = [
                'clave'   => isset($opcion['clave']) ? $opcion['clave'] : null,
                'texto'   => isset($opcion['texto']) ? $opcion['texto'] : null,
                'impacto' => isset($opcion['impacto']) ? $opcion['impacto'] : null,
            ];
        }

        return $salida;
    }

    /**
     * Una misión que sólo existe en el seguimiento (la PC todavía no la reportó a El local) con la forma de las misiones
     * resumidas del conector: estado de El local por el mapa del plan, `fuente = admin` y el bloque `seguimiento`.
     *
     * @param MissionTracking $fila        Fila.
     * @param Carbon          $ahora       Momento.
     * @param bool            $con_eventos Si el bloque trae los últimos eventos.
     *
     * @return array<string, mixed>
     */
    public function como_mision(MissionTracking $fila, Carbon $ahora, $con_eventos = false)
    {
        $ultima = $fila->ultima_actividad_real_at !== null ? $fila->ultima_actividad_real_at : $fila->reportado_at;

        return [
            'id'                  => $fila->mission_key,
            'titulo'              => $fila->titulo,
            'origen'              => $fila->origen,
            'estado'              => $this->estado_de_el_local($fila->estado),
            'etapa'               => $fila->etapa,
            'fase'                => $fila->fase,
            'minutos_en_etapa'    => $this->minutos_desde($fila->fase_desde, $ahora),
            'umbral_min'          => null,
            'demorada'            => false,
            'pedido_id'           => $fila->pedido_id,
            'slug'                => $fila->slug,
            'ticket'              => null,
            'repos'               => is_array($fila->repos) ? array_values($fila->repos) : [],
            'ultima_actividad_at' => $this->iso($ultima),
            'fuente'              => 'admin',
            'seguimiento'         => $this->serializar($fila, $ahora, $con_eventos),
        ];
    }
}
