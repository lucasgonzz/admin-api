<?php

namespace App\Services\Missions;

use App\Models\Admin;
use App\Models\MissionAnswer;
use App\Models\MissionQuestion;
use App\Models\MissionTracking;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Preguntas de decisión de las misiones y sus respuestas (misión dotin-misiones-admin, 26/11/2026).
 *
 * Tres actores, tres puertas:
 *  - La Gamer PUBLICA preguntas ({@see publicar_pregunta()}) y CONSUME respuestas ({@see pendientes()}, {@see reclamar()},
 *    {@see ver_respuesta()}, {@see aplicar()}, {@see rechazar()}), todo con `X-Claude-Task-Key`.
 *  - Lucas RESPONDE desde el Admin y Dotin desde el conector MCP, los dos por {@see registrar_respuesta()}: ÚNICO punto
 *    donde nace una respuesta. La clave de la Gamer no tiene ninguna ruta que registre respuestas.
 *
 * Máquina de estados de la respuesta: `registrada -> reclamada -> aplicada`, o `registrada|reclamada -> rechazada`; una
 * sola respuesta vigente (`registrada|reclamada|aplicada`) por pregunta. Las preguntas `sensible` NUNCA se responden por
 * acá: necesitan el OK personal de Lucas por Slack o Telegram.
 *
 * Todos los métodos devuelven `['status' => int, 'body' => array]`; los errores llevan `message` y un `codigo` estable.
 */
class MissionQuestionService
{
    /** Estados desde los que una pregunta ya no admite respuestas. */
    const ESTADOS_CERRADOS = ['aplicada', 'cerrada', 'reemplazada'];

    /** Respuestas que cuentan como «vigentes» de una pregunta. */
    const ESTADOS_VIGENTES = ['registrada', 'reclamada', 'aplicada'];

    /** Motivos con los que la Gamer rechaza una respuesta. */
    const MOTIVOS_DE_RECHAZO = ['ajena', 'obsoleta', 'cerrada', 'mision_inexistente', 'sensible', 'otro'];

    /** @var MissionTrackingService */
    private $seguimiento;

    /**
     * @param MissionTrackingService $seguimiento Estado de las misiones (eventos, reloj, caché de la escena).
     */
    public function __construct(MissionTrackingService $seguimiento)
    {
        $this->seguimiento = $seguimiento;
    }

    /**
     * Una respuesta de error con el formato de la API de misiones.
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
     * Una fecha como ISO-8601 de Argentina (null si no hay).
     *
     * @param mixed $fecha Atributo datetime.
     *
     * @return string|null
     */
    private function iso($fecha)
    {
        return $fecha === null ? null : Carbon::parse($fecha)->setTimezone(MissionTrackingService::ZONA)->toIso8601String();
    }

    /**
     * Una pregunta como sale por la API.
     *
     * @param MissionQuestion $pregunta Pregunta.
     *
     * @return array<string, mixed>
     */
    public function pregunta_serializada(MissionQuestion $pregunta)
    {
        $opciones = [];
        foreach (is_array($pregunta->opciones) ? $pregunta->opciones : [] as $opcion) {
            $opciones[] = [
                'clave'   => isset($opcion['clave']) ? $opcion['clave'] : null,
                'texto'   => isset($opcion['texto']) ? $opcion['texto'] : null,
                'impacto' => isset($opcion['impacto']) ? $opcion['impacto'] : null,
            ];
        }

        return [
            'question_uid' => $pregunta->question_uid,
            'revision'     => (int) $pregunta->revision,
            'tipo'         => $pregunta->tipo,
            'estado'       => $pregunta->estado,
            'pregunta'     => $pregunta->pregunta,
            'opciones'     => $opciones,
            'recomendada'  => $pregunta->recomendada,
        ];
    }

    /**
     * Una respuesta como sale por la API. El texto y la opción sólo viajan si se piden: el aviso `pending` y el `claim`
     * nunca los llevan (el aviso no es la respuesta).
     *
     * @param MissionAnswer $respuesta    Respuesta.
     * @param bool          $con_contenido Si suma `opcion` y `texto`.
     *
     * @return array<string, mixed>
     */
    public function respuesta_serializada(MissionAnswer $respuesta, $con_contenido = true)
    {
        $salida = [
            'id'           => (int) $respuesta->id,
            'answer_uid'   => $respuesta->answer_uid,
            'mission_key'  => $respuesta->mission_key,
            'question_uid' => $respuesta->question_uid,
            'revision'     => (int) $respuesta->revision,
            'estado'       => $respuesta->estado,
            'canal'        => $respuesta->canal,
            'created_at'   => $this->iso($respuesta->created_at),
        ];
        if ($con_contenido) {
            $salida['opcion'] = $respuesta->opcion;
            $salida['texto']  = $respuesta->texto;
        }

        return $salida;
    }

    /* ------------------------------------------------------------------------------------------
     | La Gamer publica preguntas
     |----------------------------------------------------------------------------------------- */

    /**
     * `POST claude/missions/{slug}/questions`: publica una pregunta o su revisión, idempotente por `question_uid`.
     *
     * - uid nuevo: se crea (201) y las otras abiertas o respondidas de la misión pasan a `reemplazada`;
     * - mismo uid y misma revisión: 200 `duplicada`;
     * - mismo uid y revisión mayor: se actualiza, vuelve a `abierta` y la respuesta de la revisión vieja se rechaza;
     * - revisión menor: 409 `revision_vieja`; uid ya resuelto: 409 `pregunta_cerrada`; uid de otra misión: 409 `pregunta_ajena`.
     *
     * @param string               $slug    Slug de la misión (tiene que existir en el seguimiento).
     * @param array<string, mixed> $entrada Cuerpo del pedido.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function publicar_pregunta($slug, array $entrada)
    {
        $clave = $this->seguimiento->clave_de($slug);
        if (! MissionTracking::where('mission_key', $clave)->exists()) {
            return $this->error(404, 'mision_inexistente', 'No hay una misión con ese slug en el seguimiento.');
        }

        $validador = Validator::make($entrada, [
            'question_uid'      => 'required|uuid',
            'revision'          => 'required|integer|min:1',
            'tipo'              => 'required|in:ordinaria,sensible',
            'pregunta'          => 'required|string|max:500',
            'opciones'          => 'required|array|min:2|max:6',
            'opciones.*.clave'  => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,20}$/', 'distinct'],
            'opciones.*.texto'  => 'required|string|max:200',
            'opciones.*.impacto' => 'nullable|string|max:300',
            'recomendada'       => 'nullable|string|max:20',
        ]);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $datos = $validador->validated();

        $opciones = [];
        $claves   = [];
        foreach ($datos['opciones'] as $opcion) {
            $claves[]   = $opcion['clave'];
            $opciones[] = ['clave' => $opcion['clave'], 'texto' => $opcion['texto'], 'impacto' => isset($opcion['impacto']) ? $opcion['impacto'] : null];
        }
        $recomendada = isset($datos['recomendada']) ? $datos['recomendada'] : null;
        if ($recomendada !== null && ! in_array($recomendada, $claves, true)) {
            return $this->error(422, 'recomendada_invalida', 'La opción recomendada tiene que ser una de las opciones de la pregunta.');
        }

        try {
            $resultado = DB::transaction(function () use ($clave, $datos, $opciones, $recomendada) {
                return $this->aplicar_pregunta($clave, $datos, $opciones, $recomendada);
            });
        } catch (QueryException $e) {
            // Dos publicaciones iguales a la vez: la segunda ya encuentra la fila de la primera.
            $resultado = DB::transaction(function () use ($clave, $datos, $opciones, $recomendada) {
                return $this->aplicar_pregunta($clave, $datos, $opciones, $recomendada);
            });
        }

        $this->seguimiento->olvidar_escena();

        return $resultado;
    }

    /**
     * Aplica la publicación de una pregunta dentro de la transacción.
     *
     * @param string                          $clave       `cc:<slug>`.
     * @param array<string, mixed>            $datos       Datos validados.
     * @param array<int, array<string, mixed>> $opciones    Opciones normalizadas.
     * @param string|null                     $recomendada Clave recomendada.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function aplicar_pregunta($clave, array $datos, array $opciones, $recomendada)
    {
        $ahora    = $this->seguimiento->ahora();
        $revision = (int) $datos['revision'];
        $mision   = MissionTracking::where('mission_key', $clave)->lockForUpdate()->first();
        $actual   = MissionQuestion::where('question_uid', $datos['question_uid'])->lockForUpdate()->first();

        if ($actual !== null) {
            if ($actual->mission_key !== $clave) {
                return $this->error(409, 'pregunta_ajena', 'Esa pregunta pertenece a otra misión.');
            }
            if (in_array($actual->estado, self::ESTADOS_CERRADOS, true)) {
                return $this->error(409, 'pregunta_cerrada', 'Esa pregunta ya está resuelta, cerrada o reemplazada.');
            }
            if ($revision < (int) $actual->revision) {
                return $this->error(409, 'revision_vieja', 'Ya hay una revisión más nueva de esa pregunta.');
            }
            if ($revision === (int) $actual->revision) {
                return ['status' => 200, 'body' => ['question' => $this->pregunta_serializada($actual), 'duplicada' => true]];
            }

            // Revisión mayor: la pregunta cambió, así que lo respondido sobre la anterior ya no vale.
            $actual->revision    = $revision;
            $actual->tipo        = $datos['tipo'];
            $actual->pregunta    = $datos['pregunta'];
            $actual->opciones    = $opciones;
            $actual->recomendada = $recomendada;
            $actual->estado      = 'abierta';
            $actual->answered_at = null;
            $actual->save();

            MissionAnswer::where('question_id', $actual->id)->whereIn('estado', ['registrada', 'reclamada'])->update([
                'estado' => 'rechazada', 'rechazo_motivo' => 'revision_reemplazada', 'updated_at' => $ahora->copy(),
            ]);

            $mision->pregunta_abierta_uid = $actual->question_uid;
            $mision->save();
            $this->seguimiento->crear_evento($clave, 'pregunta', ['estado' => $mision->estado, 'texto' => 'Pregunta corregida (revisión ' . $revision . '): ' . $datos['pregunta']]);

            return ['status' => 200, 'body' => ['question' => $this->pregunta_serializada($actual), 'duplicada' => false]];
        }

        // Uid nuevo: ocupa el lugar de la abierta anterior.
        foreach (MissionQuestion::where('mission_key', $clave)->whereIn('estado', ['abierta', 'respondida'])->get() as $anterior) {
            $anterior->estado        = 'reemplazada';
            $anterior->closed_at     = $ahora->copy();
            $anterior->cierre_motivo = 'reemplazada';
            $anterior->save();

            MissionAnswer::where('question_id', $anterior->id)->whereIn('estado', ['registrada', 'reclamada'])->update([
                'estado' => 'rechazada', 'rechazo_motivo' => 'reemplazada', 'updated_at' => $ahora->copy(),
            ]);
        }

        $nueva = MissionQuestion::create([
            'mission_key'  => $clave,
            'question_uid' => $datos['question_uid'],
            'revision'     => $revision,
            'tipo'         => $datos['tipo'],
            'pregunta'     => $datos['pregunta'],
            'opciones'     => $opciones,
            'recomendada'  => $recomendada,
            'estado'       => 'abierta',
        ]);

        $mision->pregunta_abierta_uid = $nueva->question_uid;
        $mision->save();
        $this->seguimiento->crear_evento($clave, 'pregunta', ['estado' => $mision->estado, 'texto' => $datos['pregunta']]);

        return ['status' => 201, 'body' => ['question' => $this->pregunta_serializada($nueva), 'duplicada' => false]];
    }

    /* ------------------------------------------------------------------------------------------
     | Lucas (o Dotin) registra la respuesta
     |----------------------------------------------------------------------------------------- */

    /**
     * Registra la respuesta de Lucas a una pregunta ORDINARIA. Único punto donde nace una respuesta (Admin y MCP).
     *
     * Se evalúa en este orden: misión inexistente (404), pregunta inexistente (404), pregunta de otra misión (409),
     * `answer_uid` repetido (200 si es lo mismo, 409 `answer_uid_reusado` si no), pregunta sensible (403
     * `requiere_ok_personal`), pregunta cerrada (409), ya respondida (409), revisión vieja (409), respuesta vacía (422) y
     * opción inválida (422).
     *
     * @param string               $slug  Slug de la misión.
     * @param string               $uid   `question_uid`.
     * @param array<string, mixed> $datos `revision`, `opcion`, `texto`, `answer_uid`.
     * @param Admin                $autor Admin que responde (el humano o el dueño del token MCP).
     * @param string               $canal `admin` o `mcp`.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function registrar_respuesta($slug, $uid, array $datos, Admin $autor, $canal)
    {
        $validador = Validator::make($datos, [
            'revision'   => 'required|integer|min:1',
            'opcion'     => 'nullable|string|max:20',
            'texto'      => 'nullable|string|max:1000',
            'answer_uid' => 'required|uuid',
        ]);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $datos = $validador->validated();

        $resultado = DB::transaction(function () use ($slug, $uid, $datos, $autor, $canal) {
            return $this->guardar_respuesta($slug, $uid, $datos, $autor, $canal);
        });

        if ($resultado['status'] === 201) {
            $this->seguimiento->olvidar_escena();
        }

        return $resultado;
    }

    /**
     * La parte de {@see registrar_respuesta()} que corre dentro de la transacción, con la pregunta bloqueada.
     *
     * @param string               $slug  Slug de la misión.
     * @param string               $uid   `question_uid`.
     * @param array<string, mixed> $datos Datos validados.
     * @param Admin                $autor Admin que responde.
     * @param string               $canal `admin` o `mcp`.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function guardar_respuesta($slug, $uid, array $datos, Admin $autor, $canal)
    {
        $clave = $this->seguimiento->clave_de($slug);
        if (! MissionTracking::where('mission_key', $clave)->exists()) {
            return $this->error(404, 'mision_inexistente', 'No hay una misión con ese slug en el seguimiento.');
        }

        $pregunta = MissionQuestion::where('question_uid', $uid)->lockForUpdate()->first();
        if ($pregunta === null) {
            return $this->error(404, 'pregunta_inexistente', 'No existe esa pregunta.');
        }
        if ($pregunta->mission_key !== $clave) {
            return $this->error(409, 'pregunta_ajena', 'Esa pregunta pertenece a otra misión.');
        }

        $opcion = isset($datos['opcion']) && $datos['opcion'] !== '' ? $datos['opcion'] : null;
        $texto  = isset($datos['texto']) && $datos['texto'] !== '' ? $datos['texto'] : null;

        // Un reintento con el mismo `answer_uid` es la misma respuesta; el mismo uid con otro contenido es un error.
        $repetida = MissionAnswer::where('answer_uid', $datos['answer_uid'])->first();
        if ($repetida !== null) {
            $igual = $repetida->question_uid === $uid && (int) $repetida->revision === (int) $datos['revision']
                && $repetida->opcion === $opcion && $repetida->texto === $texto;
            if ($igual) {
                return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($repetida), 'duplicada' => true]];
            }

            return $this->error(409, 'answer_uid_reusado', 'Ese answer_uid ya se usó con otro contenido.');
        }

        if ($pregunta->tipo === 'sensible') {
            return $this->error(403, 'requiere_ok_personal', 'Esta decisión necesita el OK personal de Lucas por Slack o Telegram; no se registra por el Admin ni por Dotin.');
        }
        if (in_array($pregunta->estado, self::ESTADOS_CERRADOS, true)) {
            return $this->error(409, 'pregunta_cerrada', 'Esa pregunta ya está resuelta, cerrada o reemplazada.');
        }
        if ($pregunta->estado === 'respondida') {
            return $this->error(409, 'ya_respondida', 'Esa pregunta ya tiene una respuesta registrada.');
        }
        if ((int) $datos['revision'] !== (int) $pregunta->revision) {
            return $this->error(409, 'revision_vieja', 'La pregunta cambió: respondé la revisión vigente.');
        }
        if ($opcion === null && $texto === null) {
            return $this->error(422, 'respuesta_vacia', 'Elegí una opción o escribí una respuesta.');
        }
        if ($opcion !== null) {
            $claves = [];
            foreach (is_array($pregunta->opciones) ? $pregunta->opciones : [] as $una) {
                $claves[] = isset($una['clave']) ? $una['clave'] : null;
            }
            if (! in_array($opcion, $claves, true)) {
                return $this->error(422, 'opcion_invalida', 'Esa opción no es una de las opciones de la pregunta.');
            }
        }

        $ahora = $this->seguimiento->ahora();
        $respuesta = MissionAnswer::create([
            'question_id'  => $pregunta->id,
            'mission_key'  => $clave,
            'question_uid' => $uid,
            'revision'     => (int) $pregunta->revision,
            'answer_uid'   => $datos['answer_uid'],
            'opcion'       => $opcion,
            'texto'        => $texto,
            'admin_id'     => $autor->id,
            'canal'        => $canal,
            'estado'       => 'registrada',
        ]);

        $pregunta->estado      = 'respondida';
        $pregunta->answered_at = $ahora->copy();
        $pregunta->save();

        // El evento deja constancia de que se respondió, nunca de qué se respondió.
        $this->seguimiento->crear_evento($clave, 'respuesta_registrada', ['texto' => 'Respuesta registrada por ' . $canal . ' a la pregunta (revisión ' . $pregunta->revision . ').']);

        return ['status' => 201, 'body' => ['answer' => $this->respuesta_serializada($respuesta), 'duplicada' => false]];
    }

    /* ------------------------------------------------------------------------------------------
     | La Gamer consume respuestas
     |----------------------------------------------------------------------------------------- */

    /**
     * `GET claude/missions/answers/pending`: las respuestas que la Gamer todavía tiene que tomar, SIN su contenido.
     *
     * @param string|null $host   Sólo las misiones de esa PC (null = todas).
     * @param int|null    $limite Cantidad máxima (por defecto `missions.pending_limit`, tope 100).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function pendientes($host, $limite)
    {
        $maximo = (int) config('missions.pending_limit', 20);
        $limite = $limite === null || (int) $limite < 1 ? $maximo : min((int) $limite, 100);

        $consulta = MissionAnswer::whereIn('estado', ['registrada', 'reclamada']);
        if ($host !== null && $host !== '') {
            $consulta->whereIn('mission_key', MissionTracking::where('host', $host)->pluck('mission_key')->all());
        }

        $respuestas = [];
        foreach ($consulta->orderBy('id', 'asc')->limit($limite)->get() as $respuesta) {
            $item = $this->respuesta_serializada($respuesta, false);
            $item['slug'] = substr($respuesta->mission_key, 3);
            unset($item['canal']);
            $respuestas[] = $item;
        }

        return ['status' => 200, 'body' => ['answers' => $respuestas]];
    }

    /**
     * `POST claude/missions/answers/{id}/claim`: la Gamer reclama una respuesta con su `claim_token`.
     *
     * @param int                  $id      Id de la respuesta.
     * @param array<string, mixed> $entrada Cuerpo (`claim_token`).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function reclamar($id, array $entrada)
    {
        $validador = Validator::make($entrada, ['claim_token' => 'required|uuid']);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $token = $validador->validated()['claim_token'];

        return DB::transaction(function () use ($id, $token) {
            $respuesta = MissionAnswer::where('id', $id)->lockForUpdate()->first();
            if ($respuesta === null) {
                return $this->error(404, 'respuesta_inexistente', 'No existe esa respuesta.');
            }
            if ($respuesta->estado === 'aplicada') {
                return $this->error(409, 'ya_aplicada', 'Esa respuesta ya se aplicó.');
            }
            if ($respuesta->estado === 'rechazada') {
                return $this->error(409, 'rechazada', 'Esa respuesta fue rechazada.');
            }
            if ($respuesta->estado === 'reclamada') {
                if ($respuesta->claim_token !== $token) {
                    return $this->error(409, 'reclamada_por_otro', 'Esa respuesta ya la reclamó otra ejecución.');
                }

                return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($respuesta, false)]];
            }

            $respuesta->estado      = 'reclamada';
            $respuesta->claim_token = $token;
            $respuesta->claimed_at  = $this->seguimiento->ahora();
            $respuesta->save();

            return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($respuesta, false)]];
        });
    }

    /**
     * `GET claude/missions/{slug}/questions/{uid}/answer`: la pregunta y su respuesta vigente, con opción y texto.
     *
     * @param string $slug Slug de la misión.
     * @param string $uid  `question_uid`.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function ver_respuesta($slug, $uid)
    {
        $pregunta = MissionQuestion::where('question_uid', $uid)->first();
        if ($pregunta === null) {
            return $this->error(404, 'pregunta_inexistente', 'No existe esa pregunta.');
        }
        if ($pregunta->mission_key !== $this->seguimiento->clave_de($slug)) {
            return $this->error(409, 'pregunta_ajena', 'Esa pregunta pertenece a otra misión.');
        }

        $respuesta = MissionAnswer::where('question_id', $pregunta->id)->whereIn('estado', self::ESTADOS_VIGENTES)->orderBy('id', 'desc')->first();
        if ($respuesta === null) {
            return $this->error(404, 'sin_respuesta', 'Esa pregunta todavía no tiene una respuesta vigente.');
        }

        $texto_de_opcion = null;
        foreach (is_array($pregunta->opciones) ? $pregunta->opciones : [] as $opcion) {
            if (isset($opcion['clave']) && $opcion['clave'] === $respuesta->opcion) {
                $texto_de_opcion = isset($opcion['texto']) ? $opcion['texto'] : null;
            }
        }
        $admin = Admin::find($respuesta->admin_id);

        $salida = $this->respuesta_serializada($respuesta, true);
        $salida['opcion_texto'] = $texto_de_opcion;
        $salida['admin']        = ['id' => (int) $respuesta->admin_id, 'nombre' => $admin === null ? null : $admin->name];
        $salida['claimed_at']   = $this->iso($respuesta->claimed_at);
        $salida['applied_at']   = $this->iso($respuesta->applied_at);

        return ['status' => 200, 'body' => ['question' => $this->pregunta_serializada($pregunta), 'answer' => $salida]];
    }

    /**
     * `POST claude/missions/answers/{id}/applied`: la Gamer confirma que aplicó la respuesta. Idempotente: la segunda
     * vez es 200 `ya_aplicada` y NO mueve `applied_at`.
     *
     * @param int                  $id      Id de la respuesta.
     * @param array<string, mixed> $entrada Cuerpo (`claim_token`, `ejecucion_n`).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function aplicar($id, array $entrada)
    {
        $validador = Validator::make($entrada, ['claim_token' => 'required|uuid', 'ejecucion_n' => 'required|integer|min:0']);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $datos = $validador->validated();

        $resultado = DB::transaction(function () use ($id, $datos) {
            $respuesta = MissionAnswer::where('id', $id)->lockForUpdate()->first();
            if ($respuesta === null) {
                return $this->error(404, 'respuesta_inexistente', 'No existe esa respuesta.');
            }
            if ($respuesta->estado === 'rechazada') {
                return $this->error(409, 'rechazada', 'Esa respuesta fue rechazada.');
            }
            if ($respuesta->estado === 'registrada') {
                return $this->error(409, 'sin_reclamar', 'Esa respuesta todavía no se reclamó.');
            }
            if ($respuesta->claim_token !== $datos['claim_token']) {
                return $this->error(409, 'token_distinto', 'El token no es el que reclamó esa respuesta.');
            }
            if ($respuesta->estado === 'aplicada') {
                return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($respuesta, false), 'ya_aplicada' => true]];
            }

            $ahora = $this->seguimiento->ahora();
            $respuesta->estado      = 'aplicada';
            $respuesta->applied_at  = $ahora->copy();
            $respuesta->ejecucion_n = (int) $datos['ejecucion_n'];
            $respuesta->save();

            $pregunta = MissionQuestion::where('id', $respuesta->question_id)->lockForUpdate()->first();
            if ($pregunta !== null) {
                $pregunta->estado     = 'aplicada';
                $pregunta->applied_at = $ahora->copy();
                $pregunta->save();
            }
            MissionTracking::where('mission_key', $respuesta->mission_key)->where('pregunta_abierta_uid', $respuesta->question_uid)->update(['pregunta_abierta_uid' => null]);

            $this->seguimiento->crear_evento($respuesta->mission_key, 'respuesta_aplicada', ['texto' => 'La misión aplicó la respuesta en la ejecución ' . (int) $datos['ejecucion_n'] . '.']);

            return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($respuesta, false), 'ya_aplicada' => false]];
        });

        if ($resultado['status'] === 200) {
            $this->seguimiento->olvidar_escena();
        }

        return $resultado;
    }

    /**
     * `POST claude/missions/answers/{id}/reject`: la Gamer descarta una respuesta (ajena, obsoleta, cerrada, sensible...).
     * La pregunta pasa a `cerrada` con el mismo motivo; con `otro` no se cierra: vuelve a `abierta` para poder responderla de nuevo.
     *
     * @param int                  $id      Id de la respuesta.
     * @param array<string, mixed> $entrada Cuerpo (`motivo`, `detalle`).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function rechazar($id, array $entrada)
    {
        $validador = Validator::make($entrada, ['motivo' => 'required|in:' . implode(',', self::MOTIVOS_DE_RECHAZO), 'detalle' => 'nullable|string']);
        if ($validador->fails()) {
            return $this->error_de_validacion($validador);
        }
        $datos = $validador->validated();

        $resultado = DB::transaction(function () use ($id, $datos) {
            $respuesta = MissionAnswer::where('id', $id)->lockForUpdate()->first();
            if ($respuesta === null) {
                return $this->error(404, 'respuesta_inexistente', 'No existe esa respuesta.');
            }
            if ($respuesta->estado === 'aplicada') {
                return $this->error(409, 'ya_aplicada', 'Esa respuesta ya se aplicó.');
            }
            if ($respuesta->estado === 'rechazada') {
                return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($respuesta, false)]];
            }

            $respuesta->estado          = 'rechazada';
            $respuesta->rechazo_motivo  = $datos['motivo'];
            $respuesta->rechazo_detalle = isset($datos['detalle']) ? mb_substr($datos['detalle'], 0, 300) : null;
            $respuesta->save();

            $pregunta = MissionQuestion::where('id', $respuesta->question_id)->lockForUpdate()->first();
            if ($pregunta !== null && $pregunta->estado === 'respondida') {
                if ($datos['motivo'] === 'otro') {
                    $pregunta->estado      = 'abierta';
                    $pregunta->answered_at = null;
                } else {
                    $pregunta->estado        = 'cerrada';
                    $pregunta->closed_at     = $this->seguimiento->ahora();
                    $pregunta->cierre_motivo = $datos['motivo'];
                    MissionTracking::where('mission_key', $respuesta->mission_key)->where('pregunta_abierta_uid', $respuesta->question_uid)->update(['pregunta_abierta_uid' => null]);
                }
                $pregunta->save();
            }

            return ['status' => 200, 'body' => ['answer' => $this->respuesta_serializada($respuesta, false)]];
        });

        if ($resultado['status'] === 200) {
            $this->seguimiento->olvidar_escena();
        }

        return $resultado;
    }
}
