<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Services\Local\OficinaIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las oficinas de «El local» (misión el-local, 3/11/2026): el reporter local de la PC de Lucas
 * (`herramientas/el-local/reporter.py`) manda cada minuto cómo está cada slot del pool (contrato A,
 * `POST api/claude/local/oficina`, middleware `claude.task.key`).
 *
 * Delgado: valida y guarda con {@see OficinaIngestService}. La forma del error es la del bloque `claude/*`
 * (trait {@see RespuestasParaClaude}), con `errors` por campo como pide el contrato A.
 */
class ClaudeLocalOficinaController extends Controller
{
    use RespuestasParaClaude;

    /** @var OficinaIngestService */
    private $oficina;

    /**
     * @param OficinaIngestService $oficina Valida y guarda el reporte.
     */
    public function __construct(OficinaIngestService $oficina)
    {
        $this->oficina = $oficina;
    }

    /**
     * `POST claude/local/oficina`: upsert de los slots y pulso del reporter.
     *
     * @param Request $request Body del contrato A: `{reporter, sesiones, slots: [...]}`.
     *
     * @return JsonResponse `200 {ok: true, recibido_at, slots}` o `422 {error, errors, errores, ayuda}`.
     */
    public function store_json(Request $request): JsonResponse
    {
        $datos = $request->all();

        $errores = $this->oficina->validar($datos, $this->mensajes());
        if ($errores !== null) {
            /* `errors` es lo que promete el contrato A; `errores` y `ayuda` son la forma de siempre del
               bloque claude/* (validar_o_422 del trait). Viajan los dos para que ningún lector se quede sin
               la suya. */
            return $this->error_422('El reporte de la oficina no es válido. No se guardó nada.', [
                'errors'  => $errores,
                'errores' => $errores,
                'ayuda'   => 'Consultá ' . $this->ayuda_del_schema() . ' para ver los campos y valores válidos.',
            ]);
        }

        $resultado = $this->oficina->guardar($datos);

        return response()->json([
            'ok'          => true,
            'recibido_at' => $resultado['recibido_at']->toIso8601String(),
            'slots'       => (int) $resultado['slots'],
        ], 200);
    }

    /**
     * Los mensajes en español del bloque `claude/*`, más los dos que usa este endpoint y el trait no trae.
     *
     * @return array<string, string>
     */
    private function mensajes()
    {
        return array_merge($this->mensajes_de_validacion(), [
            'present'  => 'El parámetro :attribute tiene que venir (aunque sea una lista vacía).',
            'distinct' => 'El parámetro :attribute está repetido: cada slot va una sola vez.',
        ]);
    }
}
