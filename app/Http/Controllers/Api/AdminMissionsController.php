<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Missions\MissionQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La puerta humana del seguimiento de misiones (misión dotin-misiones-admin, 26/11/2026): Lucas responde desde el
 * Admin una decisión ORDINARIA que una misión le dejó planteada.
 *
 * Va en el grupo `['auth:sanctum', 'admin.solo_leads']` (el perfil «sólo leads» queda afuera por su lista blanca).
 * Además de estar logueado hace falta ser un admin humano (no `es_agente`) y, si `missions.answer_admin_emails` tiene
 * valor, figurar en esa lista. La lógica (y la negativa a registrar una pregunta sensible) vive en
 * {@see MissionQuestionService::registrar_respuesta()}, la misma que usa el conector MCP de decisiones.
 */
class AdminMissionsController extends Controller
{
    /** @var MissionQuestionService */
    private $preguntas;

    /**
     * @param MissionQuestionService $preguntas Preguntas y respuestas de las misiones.
     */
    public function __construct(MissionQuestionService $preguntas)
    {
        $this->preguntas = $preguntas;
    }

    /**
     * `POST admin/local/missions/{slug}/questions/{uid}/answer`: registra la respuesta de Lucas (canal `admin`).
     *
     * @param Request $request Cuerpo: `revision`, `opcion?`, `texto?`, `answer_uid` (lo genera el SPA una vez por intento).
     * @param string  $slug    Slug de la misión.
     * @param string  $uid     `question_uid`.
     *
     * @return JsonResponse
     */
    public function responder(Request $request, $slug, $uid)
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof Admin, 403);
        abort_if(! empty($usuario->es_agente) || ! empty($usuario->solo_leads), 403);

        // Con lista de emails, sólo los de la lista (vacía = cualquier admin humano).
        $permitidos = [];
        foreach (explode(',', (string) config('missions.answer_admin_emails', '')) as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $permitidos[] = $email;
            }
        }
        if (count($permitidos) > 0 && ! in_array(strtolower((string) $usuario->email), $permitidos, true)) {
            return response()->json(['message' => 'Tu usuario no puede responder decisiones de misiones.', 'codigo' => 'no_autorizado'], 403);
        }

        $resultado = $this->preguntas->registrar_respuesta($slug, $uid, $request->all(), $usuario, 'admin');

        return response()->json($resultado['body'], $resultado['status'], [], JSON_UNESCAPED_UNICODE);
    }
}
