<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Client;
use App\Models\RecursosPregunta;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use App\Services\RecursosBorradorService;
use App\Services\RecursosBusquedasService;
use App\Services\RecursosEstadisticasService;
use App\Services\RecursosLinkService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El panel del centro de recursos en el admin (misión centro-de-recursos-clientes, 13/10/2026), bajo
 * `auth:sanctum` como el resto de `admin/*`. Tres solapas y el link del dueño:
 *
 *   Preguntas — GET admin/recursos/preguntas, PUT admin/recursos/preguntas/{id},
 *               POST admin/recursos/preguntas/{id}/borrador (IA).
 *   Videos    — GET admin/recursos/videos (peor puntuados primero), GET admin/recursos/videos/{codigo}/mejoras.
 *   Clientes  — GET admin/recursos/clientes, GET admin/recursos/clientes/{client_id},
 *               POST admin/recursos/clientes/{client_id}/link-dueno.
 *
 * A diferencia de la página, acá SÍ sale el nombre completo del usuario y el cliente: el admin es
 * ComercioCity y necesita saber a quién le responde.
 */
class RecursosPanelController extends Controller
{
    /**
     * Estados por los que se filtran las preguntas.
     */
    const ESTADOS = ['pendientes', 'respondidas', 'ocultas'];

    /**
     * Largo máximo de una respuesta.
     */
    const MAX_RESPUESTA = 5000;

    /**
     * GET admin/recursos/preguntas?estado=pendientes|respondidas|ocultas&limit=200 →
     * `{preguntas: [...], totales: {pendientes, respondidas, ocultas}}`.
     *
     * Sin `estado` vienen todas. El orden es siempre el mismo: las pendientes primero y, entre ellas, la
     * MÁS VIEJA arriba (es la que hace más que espera); después el resto, las más nuevas arriba.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function preguntas_json(Request $request): JsonResponse
    {
        $estado = $request->query('estado');

        if ($estado !== null && ! in_array($estado, self::ESTADOS, true)) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`estado` es pendientes, respondidas u ocultas.'], 422);
        }

        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min(500, $limit));

        $consulta = RecursosPregunta::query()
            ->with(['video:id,codigo,titulo', 'usuario.client:id,name,company_name,slug'])
            ->withCount('me_gustas');

        if ($estado === 'pendientes') {
            $consulta->where('oculta', false)->whereNull('respuesta');
        } elseif ($estado === 'respondidas') {
            $consulta->where('oculta', false)->whereNotNull('respuesta');
        } elseif ($estado === 'ocultas') {
            $consulta->where('oculta', true);
        }

        $preguntas = $consulta
            ->orderByRaw('CASE WHEN oculta = 0 AND respuesta IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN oculta = 0 AND respuesta IS NULL THEN created_at END ASC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $admins = $this->nombres_de_admins($preguntas->pluck('respondido_por_admin_id')->filter()->unique()->all());

        return response()->json([
            'preguntas' => $preguntas->map(function (RecursosPregunta $pregunta) use ($admins) {
                return self::pregunta_para_el_panel($pregunta, $admins);
            })->values()->all(),
            'totales'   => [
                'pendientes'  => RecursosPregunta::where('oculta', false)->whereNull('respuesta')->count(),
                'respondidas' => RecursosPregunta::where('oculta', false)->whereNotNull('respuesta')->count(),
                'ocultas'     => RecursosPregunta::where('oculta', true)->count(),
            ],
        ]);
    }

    /**
     * PUT admin/recursos/preguntas/{id} — `{respuesta?, oculta?}` → la pregunta.
     *
     * Una respuesta vacía (o null) la vuelve a dejar pendiente. Responder o cambiar el texto de la
     * respuesta deja quién y cuándo, y le vuelve a avisar al autor (`respuestas_nuevas` de su `yo`).
     *
     * @param Request $request
     * @param string  $id
     *
     * @return JsonResponse
     */
    public function actualizar_pregunta_json(Request $request, string $id): JsonResponse
    {
        $pregunta = ctype_digit($id) ? RecursosPregunta::find((int) $id) : null;

        if (! $pregunta instanceof RecursosPregunta) {
            return response()->json(['error' => 'pregunta_no_encontrada'], 404);
        }

        if (! $request->exists('respuesta') && ! $request->exists('oculta')) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => 'Mandá `respuesta`, `oculta` o las dos.'], 422);
        }

        if ($request->exists('oculta')) {
            $oculta = $request->input('oculta');

            if (! is_bool($oculta) && ! in_array($oculta, [0, 1, '0', '1'], true)) {
                return response()->json(['error' => 'datos_invalidos', 'detalle' => '`oculta` es true o false.'], 422);
            }

            $pregunta->oculta = (bool) $oculta;
        }

        if ($request->exists('respuesta')) {
            $respuesta = $request->input('respuesta');

            if ($respuesta !== null && ! is_string($respuesta)) {
                return response()->json(['error' => 'datos_invalidos', 'detalle' => '`respuesta` es texto o null.'], 422);
            }

            $respuesta = $respuesta === null ? '' : RecursosPaginaController::texto_limpio($respuesta);

            if (mb_strlen($respuesta, 'UTF-8') > self::MAX_RESPUESTA) {
                return response()->json(['error' => 'datos_invalidos', 'detalle' => '`respuesta` es de hasta ' . self::MAX_RESPUESTA . ' caracteres.'], 422);
            }

            if ($respuesta === '') {
                $pregunta->respuesta               = null;
                $pregunta->respondido_at           = null;
                $pregunta->respondido_por_admin_id = null;
            } elseif ($respuesta !== $pregunta->respuesta) {
                $admin = $request->user();

                $pregunta->respuesta               = $respuesta;
                $pregunta->respondido_at           = now();
                $pregunta->respondido_por_admin_id = $admin instanceof Admin ? (int) $admin->id : null;
            }
        }

        $pregunta->save();

        $pregunta->load(['video:id,codigo,titulo', 'usuario.client:id,name,company_name,slug'])->loadCount('me_gustas');

        return response()->json(self::pregunta_para_el_panel(
            $pregunta,
            $this->nombres_de_admins(array_filter([$pregunta->respondido_por_admin_id]))
        ));
    }

    /**
     * POST admin/recursos/preguntas/{id}/borrador — propone una respuesta con IA y la guarda en
     * `borrador` → `{borrador}`. Sin clave o con error, 422 `{error, motivo}`: el panel deja escribir
     * a mano.
     *
     * @param string                  $id
     * @param RecursosBorradorService $servicio
     *
     * @return JsonResponse
     */
    public function borrador_json(string $id, RecursosBorradorService $servicio): JsonResponse
    {
        $pregunta = ctype_digit($id) ? RecursosPregunta::find((int) $id) : null;

        if (! $pregunta instanceof RecursosPregunta) {
            return response()->json(['error' => 'pregunta_no_encontrada'], 404);
        }

        $resultado = $servicio->proponer($pregunta);

        if (! $resultado['ok']) {
            return response()->json(['error' => $resultado['error'], 'motivo' => $resultado['motivo']], 422);
        }

        return response()->json(['borrador' => $resultado['borrador']]);
    }

    /**
     * GET admin/recursos/videos → `{videos: [...]}`, peor puntuados primero (ver
     * `RecursosEstadisticasService::videos()`).
     *
     * @param RecursosEstadisticasService $estadisticas
     *
     * @return JsonResponse
     */
    public function videos_json(RecursosEstadisticasService $estadisticas): JsonResponse
    {
        return response()->json(['videos' => $estadisticas->videos()]);
    }

    /**
     * GET admin/recursos/videos/{codigo}/mejoras → `{codigo, titulo, mejoras: [...]}`.
     *
     * @param string                      $codigo
     * @param RecursosEstadisticasService $estadisticas
     *
     * @return JsonResponse
     */
    public function mejoras_json(string $codigo, RecursosEstadisticasService $estadisticas): JsonResponse
    {
        $video = RecursosVideo::where('codigo', $codigo)->first();

        if (! $video instanceof RecursosVideo) {
            return response()->json(['error' => 'video_no_encontrado'], 404);
        }

        return response()->json([
            'codigo'  => $video->codigo,
            'titulo'  => $video->titulo,
            'mejoras' => $estadisticas->mejoras($video),
        ]);
    }

    /**
     * GET admin/recursos/busquedas?desde=Y-m-d&hasta=Y-m-d&limit=100 (contrato C-BUSQ-2 de la misión
     * busquedas-centro-recursos, 16/10/2026) → `{desde, hasta, totales, sin_encontrar, sin_clic}`: lo que
     * buscaron los clientes en el buscador de la página, para afinar los sinónimos.
     *
     * `desde` y `hasta` son días INCLUSIVOS en la hora de la app; sin ellos, los últimos 30 días. `limit` es
     * por lista (1 a 200, 100 por defecto). Fechas mal escritas o `desde` posterior a `hasta` → 422. Sin
     * datos contesta igual, con las listas vacías y los totales en cero.
     *
     * @param Request                  $request
     * @param RecursosBusquedasService $busquedas
     *
     * @return JsonResponse
     */
    public function busquedas_json(Request $request, RecursosBusquedasService $busquedas): JsonResponse
    {
        $hoy = Carbon::now()->startOfDay();

        $hasta = $this->dia_de_la_consulta($request->query('hasta'), $hoy->copy());
        $desde = $this->dia_de_la_consulta($request->query('desde'), $hoy->copy()->subDays(29));

        if (! $hasta instanceof Carbon || ! $desde instanceof Carbon) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`desde` y `hasta` son fechas con formato AAAA-MM-DD.'], 422);
        }

        if ($desde->gt($hasta)) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`desde` no puede ser posterior a `hasta`.'], 422);
        }

        $limit = (int) $request->query('limit', 100);
        $limit = max(1, min(200, $limit));

        return response()->json(
            ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')] + $busquedas->resumen($desde, $hasta, $limit)
        );
    }

    /**
     * GET admin/recursos/clientes → `{clientes: [...]}`.
     *
     * @param RecursosEstadisticasService $estadisticas
     *
     * @return JsonResponse
     */
    public function clientes_json(RecursosEstadisticasService $estadisticas): JsonResponse
    {
        return response()->json(['clientes' => $estadisticas->clientes()]);
    }

    /**
     * GET admin/recursos/clientes/{client_id} → `{cliente, resumen, link_dueno, usuarios}`. Un cliente
     * que todavía no entró al centro contesta igual, con todo en cero (lo usa Implementaciones).
     *
     * @param string                      $client_id
     * @param RecursosEstadisticasService $estadisticas
     *
     * @return JsonResponse
     */
    public function cliente_json(string $client_id, RecursosEstadisticasService $estadisticas): JsonResponse
    {
        $client = ctype_digit($client_id) ? Client::find((int) $client_id) : null;

        if (! $client instanceof Client) {
            return response()->json(['error' => 'cliente_no_encontrado'], 404);
        }

        return response()->json($estadisticas->cliente($client));
    }

    /**
     * POST admin/recursos/clientes/{client_id}/link-dueno → `{url, expires_at}`: el link personal
     * vigente del dueño, o uno nuevo.
     *
     * @param Request             $request
     * @param string              $client_id
     * @param RecursosLinkService $links
     *
     * @return JsonResponse
     */
    public function link_dueno_json(Request $request, string $client_id, RecursosLinkService $links): JsonResponse
    {
        $client = ctype_digit($client_id) ? Client::find((int) $client_id) : null;

        if (! $client instanceof Client) {
            return response()->json(['error' => 'cliente_no_encontrado'], 404);
        }

        $admin = $request->user();

        return response()->json($links->link_del_dueno($client, $admin instanceof Admin ? (int) $admin->id : null));
    }

    /**
     * Una pregunta como la ve el panel: con el nombre completo del usuario y el cliente.
     *
     * @param RecursosPregunta      $pregunta
     * @param array<int, string>    $admins   id => nombre de los admins que respondieron.
     *
     * @return array<string, mixed>
     */
    public static function pregunta_para_el_panel(RecursosPregunta $pregunta, array $admins): array
    {
        $usuario = $pregunta->usuario;
        $client  = $usuario instanceof RecursosUsuario ? $usuario->client : null;
        $video   = $pregunta->video;

        if ($pregunta->oculta) {
            $estado = 'oculta';
        } elseif ($pregunta->respuesta !== null) {
            $estado = 'respondida';
        } else {
            $estado = 'pendiente';
        }

        return [
            'id'             => (int) $pregunta->id,
            'estado'         => $estado,
            'codigo'         => $video instanceof RecursosVideo ? $video->codigo : null,
            'video_titulo'   => $video instanceof RecursosVideo ? $video->titulo : null,
            'texto'          => (string) $pregunta->texto,
            'fecha'          => $pregunta->created_at !== null ? $pregunta->created_at->toIso8601String() : null,
            'me_gusta'       => (int) $pregunta->me_gustas_count,
            'usuario'        => $usuario instanceof RecursosUsuario ? [
                'id'       => (int) $usuario->id,
                'nombre'   => $usuario->nombre,
                'email'    => $usuario->email,
                'es_dueno' => (bool) $usuario->es_dueno,
            ] : null,
            'cliente'        => $client instanceof Client ? [
                'id'     => (int) $client->id,
                'nombre' => $client->resolve_display_name(),
                'slug'   => $client->slug,
            ] : null,
            'respuesta'      => $pregunta->respuesta,
            'respondido_at'  => $pregunta->respondido_at !== null ? $pregunta->respondido_at->toIso8601String() : null,
            'respondido_por' => $pregunta->respondido_por_admin_id !== null && isset($admins[(int) $pregunta->respondido_por_admin_id])
                ? $admins[(int) $pregunta->respondido_por_admin_id]
                : null,
            'borrador'       => $pregunta->borrador,
            'oculta'         => (bool) $pregunta->oculta,
        ];
    }

    /**
     * Un día `Y-m-d` de la query string como Carbon a las 00:00, o `$por_defecto` si no vino. null si vino
     * algo que no es una fecha real (incluye "2026-02-30", que PHP corre a marzo si no se lo chequea).
     *
     * @param mixed  $valor
     * @param Carbon $por_defecto
     *
     * @return Carbon|null
     */
    protected function dia_de_la_consulta($valor, Carbon $por_defecto): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return $por_defecto;
        }

        if (! is_string($valor)) {
            return null;
        }

        try {
            $dia = Carbon::createFromFormat('!Y-m-d', $valor);
        } catch (\Exception $e) {
            return null;
        }

        return $dia instanceof Carbon && $dia->format('Y-m-d') === $valor ? $dia : null;
    }

    /**
     * Nombres de los admins por id.
     *
     * @param array<int, int|string> $ids
     *
     * @return array<int, string>
     */
    protected function nombres_de_admins(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $nombres = [];

        foreach (Admin::whereIn('id', array_values($ids))->get(['id', 'name']) as $admin) {
            $nombres[(int) $admin->id] = (string) $admin->name;
        }

        return $nombres;
    }
}
