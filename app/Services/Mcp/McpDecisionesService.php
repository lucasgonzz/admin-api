<?php

namespace App\Services\Mcp;

use App\Models\Admin;
use App\Services\Missions\MissionQuestionService;

/**
 * La única herramienta del conector de DECISIONES de misiones (misión dotin-misiones-admin, 26/11/2026):
 * `answer_mission_question`, con la que Dotin registra la respuesta de Lucas a una pregunta ORDINARIA de una misión.
 *
 * 🔴 Reglas que no se negocian:
 *  - Hace exactamente lo mismo que el botón del Admin ({@see MissionQuestionService::registrar_respuesta()}), con canal
 *    `mcp` y el admin dueño del token como autor. No hay otro camino para registrar una respuesta por esta vía.
 *  - Una pregunta SENSIBLE (producción, clientes o terceros, gastos, borrados, releases) no se registra: resultado de
 *    error `requiere_ok_personal`. Lucas la responde en persona por Slack o Telegram.
 *  - Es idempotente: `idempotency_key` es el `answer_uid`; repetirla con lo mismo devuelve la misma respuesta
 *    (`duplicada`), y con otro contenido es `answer_uid_reusado`.
 *  - Registrar la respuesta no la aplica: la Gamer la toma sola (≤ 10 min) y reanuda la misión.
 *
 * Los errores son resultados con `isError = true` y `structuredContent {ok: false, codigo, mensaje}`.
 */
class McpDecisionesService
{
    /** @var MissionQuestionService */
    private $preguntas;

    /**
     * @param MissionQuestionService $preguntas Registra la respuesta (mismo servicio que usa el Admin).
     */
    public function __construct(MissionQuestionService $preguntas)
    {
        $this->preguntas = $preguntas;
    }

    /**
     * Los nombres de las herramientas que este servicio sabe ejecutar.
     *
     * @return array<int, string>
     */
    public function nombres()
    {
        return ['answer_mission_question'];
    }

    /**
     * El catálogo: una herramienta que escribe (no es de sólo lectura), no destructiva, idempotente y de mundo cerrado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogo()
    {
        return [[
            'name'        => 'answer_mission_question',
            'title'       => 'Responder una decisión de misión',
            'description' => 'Registra la respuesta de Lucas a una pregunta ORDINARIA de una misión (la que ves en get_mission o get_request_status, en '
                . 'seguimiento.pregunta con puede_responder = true). Pasá la opción elegida (option) o un texto (text) y una idempotency_key (uuid) nueva por intento. '
                . 'No sirve para preguntas sensibles (producción, clientes o terceros, gastos, borrados): esas las responde Lucas en persona por Slack o Telegram. '
                . 'La misión se retoma sola cuando la PC la levante (hasta 10 minutos): no hace falta avisar a OpenClaw.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'mission_id'      => ['type' => 'string', 'description' => 'Id de la misión (cc:<slug>) o su slug.'],
                    'question_id'     => ['type' => 'string', 'description' => 'question_uid de la pregunta (uuid).'],
                    'revision'        => ['type' => 'integer', 'minimum' => 1, 'description' => 'Revisión de la pregunta que se está respondiendo.'],
                    'option'          => ['type' => 'string', 'description' => 'Clave de la opción elegida (por ejemplo A).'],
                    'text'            => ['type' => 'string', 'maxLength' => 1000, 'description' => 'Respuesta en texto libre (opcional si hay option).'],
                    'idempotency_key' => ['type' => 'string', 'description' => 'uuid nuevo por intento; repetirlo devuelve la misma respuesta.'],
                ],
                'required'   => ['mission_id', 'question_id', 'revision', 'idempotency_key'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ]];
    }

    /**
     * Ejecuta la herramienta.
     *
     * @param string               $nombre     Nombre (ya verificado con {@see nombres()}).
     * @param array<string, mixed> $argumentos Argumentos del cliente.
     * @param Admin                $admin      Admin dueño del token: queda como autor de la respuesta.
     *
     * @return array<string, mixed> `{content, structuredContent, isError}`.
     */
    public function ejecutar($nombre, array $argumentos, Admin $admin)
    {
        if ($nombre !== 'answer_mission_question') {
            return $this->error('herramienta_desconocida', 'Herramienta desconocida: ' . $nombre);
        }

        // Argumentos obligatorios: sin ellos no se llega al servicio.
        foreach (['mission_id', 'question_id', 'idempotency_key'] as $clave) {
            if (! isset($argumentos[$clave]) || ! is_string($argumentos[$clave]) || trim($argumentos[$clave]) === '') {
                return $this->error('argumentos_invalidos', 'Falta el argumento obligatorio "' . $clave . '".');
            }
        }
        if (! isset($argumentos['revision']) || ! is_int($argumentos['revision']) || $argumentos['revision'] < 1) {
            return $this->error('argumentos_invalidos', 'El argumento "revision" es obligatorio: un entero desde 1.');
        }
        foreach (['option', 'text'] as $clave) {
            if (isset($argumentos[$clave]) && ! is_string($argumentos[$clave])) {
                return $this->error('argumentos_invalidos', 'El argumento "' . $clave . '" tiene que ser un texto.');
            }
        }

        $slug = trim($argumentos['mission_id']);
        if (strpos($slug, 'cc:') === 0) {
            $slug = substr($slug, 3);
        }

        $resultado = $this->preguntas->registrar_respuesta($slug, trim($argumentos['question_id']), [
            'revision'   => $argumentos['revision'],
            'opcion'     => isset($argumentos['option']) ? $argumentos['option'] : null,
            'texto'      => isset($argumentos['text']) ? $argumentos['text'] : null,
            'answer_uid' => trim($argumentos['idempotency_key']),
        ], $admin, 'mcp');

        if ($resultado['status'] >= 400) {
            $codigo = isset($resultado['body']['codigo']) ? $resultado['body']['codigo'] : 'error';
            $mensaje = isset($resultado['body']['message']) ? $resultado['body']['message'] : 'No se pudo registrar la respuesta.';
            if ($codigo === 'datos_invalidos' && isset($resultado['body']['errors'])) {
                $mensaje .= ' ' . json_encode($resultado['body']['errors'], JSON_UNESCAPED_UNICODE);
            }

            return $this->error($codigo, $mensaje);
        }

        $respuesta = $resultado['body']['answer'];
        $datos = [
            'ok'       => true,
            'answer'   => [
                'id'           => $respuesta['id'],
                'answer_uid'   => $respuesta['answer_uid'],
                'mission_key'  => $respuesta['mission_key'],
                'question_uid' => $respuesta['question_uid'],
                'revision'     => $respuesta['revision'],
                'opcion'       => $respuesta['opcion'],
                'estado'       => $respuesta['estado'],
            ],
            'duplicada' => ! empty($resultado['body']['duplicada']),
            'aviso'     => 'La misión se retoma sola cuando la Gamer la levante (≤ 10 min). No hace falta avisar a OpenClaw.',
        ];

        return [
            'content'           => [['type' => 'text', 'text' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $datos,
            'isError'           => false,
        ];
    }

    /**
     * Un resultado de error de herramienta.
     *
     * @param string $codigo  Código estable.
     * @param string $mensaje Mensaje en castellano.
     *
     * @return array<string, mixed>
     */
    private function error($codigo, $mensaje)
    {
        $datos = ['ok' => false, 'codigo' => $codigo, 'mensaje' => $mensaje];

        return [
            'content'           => [['type' => 'text', 'text' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $datos,
            'isError'           => true,
        ];
    }
}
