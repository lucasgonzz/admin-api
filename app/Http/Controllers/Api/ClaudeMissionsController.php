<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Missions\MissionQuestionService;
use App\Services\Missions\MissionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las rutas que usa la Gamer (la PC de Lucas) para el seguimiento de misiones (misión dotin-misiones-admin, 26/11/2026).
 *
 * Todas cuelgan del grupo `claude.task.key` (clave `X-Claude-Task-Key`, fail-closed) bajo `claude/missions/*`:
 * publicar el estado y los eventos de una misión, publicar preguntas y consumir las respuestas que Lucas dio en el Admin
 * (`pending`, `claim`, leer, `applied`, `reject`). La lógica vive en {@see MissionTrackingService} y en
 * {@see MissionQuestionService}; acá sólo se traduce `{status, body}` a JSON.
 *
 * 🔴 Esta clave NO registra respuestas: sólo las consume. Quien responde es Lucas (Admin) o Dotin (MCP `decisiones`).
 */
class ClaudeMissionsController extends Controller
{
    /** @var MissionTrackingService */
    private $seguimiento;

    /** @var MissionQuestionService */
    private $preguntas;

    /**
     * @param MissionTrackingService $seguimiento Estado y eventos de las misiones.
     * @param MissionQuestionService $preguntas   Preguntas y respuestas.
     */
    public function __construct(MissionTrackingService $seguimiento, MissionQuestionService $preguntas)
    {
        $this->seguimiento = $seguimiento;
        $this->preguntas   = $preguntas;
    }

    /**
     * Convierte el resultado de un servicio en la respuesta JSON.
     *
     * @param array{status: int, body: array<string, mixed>} $resultado Resultado del servicio.
     *
     * @return JsonResponse
     */
    private function responder(array $resultado)
    {
        return response()->json($resultado['body'], $resultado['status'], [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * `PUT claude/missions/{slug}`: publica el estado completo de una misión (upsert por `seq`).
     *
     * @param Request $request Cuerpo con el estado.
     * @param string  $slug    Slug de la misión.
     *
     * @return JsonResponse
     */
    public function publicar_estado(Request $request, $slug)
    {
        return $this->responder($this->seguimiento->publicar($slug, $request->all()));
    }

    /**
     * `POST claude/missions/{slug}/events`: un hito del historial (idempotente por `event_uid`).
     *
     * @param Request $request Cuerpo del evento.
     * @param string  $slug    Slug de la misión.
     *
     * @return JsonResponse
     */
    public function publicar_evento(Request $request, $slug)
    {
        return $this->responder($this->seguimiento->registrar_evento($slug, $request->all()));
    }

    /**
     * `POST claude/missions/{slug}/questions`: publica una pregunta de decisión o su revisión.
     *
     * @param Request $request Cuerpo de la pregunta.
     * @param string  $slug    Slug de la misión.
     *
     * @return JsonResponse
     */
    public function publicar_pregunta(Request $request, $slug)
    {
        return $this->responder($this->preguntas->publicar_pregunta($slug, $request->all()));
    }

    /**
     * `GET claude/missions/answers/pending`: las respuestas por tomar (sin su contenido).
     *
     * @param Request $request `host?` y `limit?`.
     *
     * @return JsonResponse
     */
    public function respuestas_pendientes(Request $request)
    {
        $host   = $request->query('host');
        $limite = $request->query('limit');

        return $this->responder($this->preguntas->pendientes(is_string($host) ? $host : null, is_numeric($limite) ? (int) $limite : null));
    }

    /**
     * `POST claude/missions/answers/{id}/claim`: la Gamer reclama una respuesta.
     *
     * @param Request $request Cuerpo con `claim_token`.
     * @param int     $id      Id de la respuesta.
     *
     * @return JsonResponse
     */
    public function reclamar_respuesta(Request $request, $id)
    {
        return $this->responder($this->preguntas->reclamar((int) $id, $request->all()));
    }

    /**
     * `GET claude/missions/{slug}/questions/{uid}/answer`: la respuesta vigente de una pregunta, con su contenido.
     *
     * @param string $slug Slug de la misión.
     * @param string $uid  `question_uid`.
     *
     * @return JsonResponse
     */
    public function ver_respuesta($slug, $uid)
    {
        return $this->responder($this->preguntas->ver_respuesta($slug, $uid));
    }

    /**
     * `POST claude/missions/answers/{id}/applied`: la Gamer confirma que aplicó la respuesta.
     *
     * @param Request $request Cuerpo con `claim_token` y `ejecucion_n`.
     * @param int     $id      Id de la respuesta.
     *
     * @return JsonResponse
     */
    public function aplicar_respuesta(Request $request, $id)
    {
        return $this->responder($this->preguntas->aplicar((int) $id, $request->all()));
    }

    /**
     * `POST claude/missions/answers/{id}/reject`: la Gamer descarta una respuesta.
     *
     * @param Request $request Cuerpo con `motivo` y `detalle?`.
     * @param int     $id      Id de la respuesta.
     *
     * @return JsonResponse
     */
    public function rechazar_respuesta(Request $request, $id)
    {
        return $this->responder($this->preguntas->rechazar((int) $id, $request->all()));
    }
}
