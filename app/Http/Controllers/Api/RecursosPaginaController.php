<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RecursosSesion;
use App\Models\RecursosEncuesta;
use App\Models\RecursosPregunta;
use App\Models\RecursosPreguntaMeGusta;
use App\Models\RecursosProgreso;
use App\Models\RecursosSesion as RecursosSesionModelo;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use App\Models\RecursosVoto;
use App\Services\RecursosCatalogoService;
use App\Services\RecursosProgresoService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que consume la página del centro de recursos (`recursos.comerciocity.com`), contrato C2 de la
 * misión centro-de-recursos-clientes (13/10/2026). La sesión (entrar y salir) está en
 * `RecursosSesionController`.
 *
 * Públicas: el catálogo, un video con sus preguntas respondidas y el índice del buscador (decisión de
 * Lucas: sin sesión el video SE VE). Con sesión (`recursos.sesion`): `yo`, el avance, los votos, las
 * preguntas, los me gusta y la encuesta. El usuario del centro llega en
 * `$request->attributes->get('recursos_usuario')`, NUNCA en `$request->user()` (ver `RecursosSesion`).
 *
 * Errores con la misma forma que el resto del contrato: `{error: '<código>'}` y, en los de datos, un
 * `detalle` legible. 404 `video_no_encontrado` / `pregunta_no_encontrada`; 422 `datos_invalidos`,
 * `tramos_invalidos`, `encuesta_vacia`.
 */
class RecursosPaginaController extends Controller
{
    /**
     * Largo máximo de una pregunta, en caracteres (contrato C2).
     */
    const MAX_PREGUNTA = 2000;

    /**
     * Largo máximo del "qué mejorarías" de la encuesta.
     */
    const MAX_MEJORA = 2000;

    /**
     * GET recursos/catalogo — carpetas y videos activos, sin transcripción. Cacheado.
     *
     * @param RecursosCatalogoService $catalogo
     *
     * @return JsonResponse
     */
    public function catalogo_json(RecursosCatalogoService $catalogo): JsonResponse
    {
        return response()->json($catalogo->catalogo());
    }

    /**
     * GET recursos/buscar-indice — `{codigo: transcripcion}` de todos los activos. Cacheado.
     *
     * @param RecursosCatalogoService $catalogo
     *
     * @return JsonResponse
     */
    public function buscar_indice_json(RecursosCatalogoService $catalogo): JsonResponse
    {
        $indice = $catalogo->indice();

        // `(object)` para que un índice vacío salga `{}` y no `[]`: la página lo lee como mapa.
        return response()->json((object) $indice);
    }

    /**
     * GET recursos/videos/{codigo} — `{video, preguntas}`: el video del catálogo con su transcripción,
     * y las preguntas visibles para quien pide (respondidas de todos; con sesión, además las propias sin
     * responder). Con sesión, marca como vistas las respuestas a sus preguntas de este video.
     *
     * @param Request $request
     * @param string  $codigo
     *
     * @return JsonResponse
     */
    public function video_json(Request $request, string $codigo): JsonResponse
    {
        $video = $this->video_activo($codigo);

        if (! $video instanceof RecursosVideo) {
            return response()->json(['error' => 'video_no_encontrado'], 404);
        }

        $usuario    = $this->usuario($request);
        $usuario_id = $usuario instanceof RecursosUsuario ? (int) $usuario->id : null;

        $preguntas = RecursosPregunta::where('recursos_video_id', $video->id)
            ->visiblesPara($usuario_id)
            ->with('usuario:id,nombre')
            ->withCount('me_gustas')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $mios = $this->me_gusta_de($usuario_id, $preguntas->pluck('id')->all());

        if ($usuario_id !== null) {
            // El autor ya vio la respuesta: deja de figurar en `respuestas_nuevas` de `yo`.
            RecursosPregunta::where('recursos_video_id', $video->id)
                ->where('recursos_usuario_id', $usuario_id)
                ->whereNotNull('respuesta')
                ->where(function ($q) {
                    $q->whereNull('vista_por_autor_at')->orWhereColumn('vista_por_autor_at', '<', 'respondido_at');
                })
                ->update(['vista_por_autor_at' => now()]);
        }

        $datos                  = $video->para_el_catalogo($this->codigos_activos());
        $datos['transcripcion'] = $video->transcripcion;

        return response()->json([
            'video'     => $datos,
            'preguntas' => $preguntas->map(function (RecursosPregunta $pregunta) use ($video, $usuario_id, $mios) {
                return self::pregunta_para_la_pagina($pregunta, $video->codigo, $usuario_id, isset($mios[$pregunta->id]));
            })->values()->all(),
        ]);
    }

    /**
     * GET recursos/yo — el usuario y todo lo suyo: avance por video, votos, encuestas respondidas, me
     * gusta dados y respuestas nuevas a sus preguntas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function yo_json(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

        $progreso = [];
        foreach (RecursosProgreso::where('recursos_usuario_id', $usuario->id)->with('video:id,codigo')->get() as $fila) {
            if ($fila->video instanceof RecursosVideo) {
                $progreso[$fila->video->codigo] = [
                    'porcentaje' => (float) $fila->porcentaje,
                    'posicion'   => (float) $fila->ultima_posicion,
                    'terminado'  => $fila->terminado_at !== null,
                ];
            }
        }

        $votos = [];
        foreach (RecursosVoto::where('recursos_usuario_id', $usuario->id)->with('video:id,codigo')->get() as $voto) {
            if ($voto->video instanceof RecursosVideo) {
                $votos[$voto->video->codigo] = (int) $voto->valor;
            }
        }

        $encuestas = RecursosEncuesta::query()
            ->join('recursos_videos', 'recursos_videos.id', '=', 'recursos_encuestas.recursos_video_id')
            ->where('recursos_encuestas.recursos_usuario_id', $usuario->id)
            ->orderBy('recursos_videos.orden')
            ->pluck('recursos_videos.codigo')
            ->all();

        $me_gusta = RecursosPreguntaMeGusta::where('recursos_usuario_id', $usuario->id)
            ->orderBy('recursos_pregunta_id')
            ->pluck('recursos_pregunta_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        $nuevas = RecursosPregunta::query()
            ->join('recursos_videos', 'recursos_videos.id', '=', 'recursos_preguntas.recursos_video_id')
            ->where('recursos_preguntas.recursos_usuario_id', $usuario->id)
            ->where('recursos_preguntas.oculta', false)
            ->whereNotNull('recursos_preguntas.respuesta')
            ->where(function ($q) {
                $q->whereNull('recursos_preguntas.vista_por_autor_at')
                    ->orWhereColumn('recursos_preguntas.vista_por_autor_at', '<', 'recursos_preguntas.respondido_at');
            })
            ->orderBy('recursos_preguntas.respondido_at')
            ->get(['recursos_videos.codigo', 'recursos_preguntas.id'])
            ->map(function ($fila) {
                return ['codigo' => $fila->codigo, 'pregunta_id' => (int) $fila->id];
            })
            ->values()
            ->all();

        $yo = $usuario->para_si_mismo();

        // Solo cuando la sesión es de solo lectura (login maestro): ausente = false (contrato C2).
        $sesion = $request->attributes->get(RecursosSesion::ATRIBUTO_SESION);

        if ($sesion instanceof RecursosSesionModelo && $sesion->solo_lectura) {
            $yo['solo_lectura'] = true;
        }

        return response()->json([
            'usuario'           => $yo,
            'progreso'          => (object) $progreso,
            'votos'             => (object) $votos,
            'encuestas'         => $encuestas,
            'me_gusta'          => $me_gusta,
            'respuestas_nuevas' => $nuevas,
        ]);
    }

    /**
     * POST recursos/progreso — `{codigo, posicion, tramos: [[desde, hasta], …]}` → `{porcentaje, terminado}`.
     *
     * @param Request                 $request
     * @param RecursosProgresoService $servicio
     *
     * @return JsonResponse
     */
    public function progreso_json(Request $request, RecursosProgresoService $servicio): JsonResponse
    {
        $video = $this->video_activo($request->input('codigo'));

        if (! $video instanceof RecursosVideo) {
            return response()->json(['error' => 'video_no_encontrado'], 404);
        }

        $posicion = $request->input('posicion', 0);

        if (! is_numeric($posicion) || (float) $posicion < 0) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`posicion` tiene que ser un número de segundos ≥ 0.'], 422);
        }

        $tramos = $request->input('tramos', []);
        $error  = $servicio->error_de_los_tramos($video, $tramos);

        if ($error !== null) {
            return response()->json(['error' => 'tramos_invalidos', 'detalle' => $error], 422);
        }

        return response()->json($servicio->registrar($this->usuario($request), $video, (float) $posicion, (array) $tramos));
    }

    /**
     * POST recursos/votos — `{codigo, valor: 1 | -1 | 0}` (0 saca el voto) → `{codigo, valor}`.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function votos_json(Request $request): JsonResponse
    {
        $video = $this->video_activo($request->input('codigo'));

        if (! $video instanceof RecursosVideo) {
            return response()->json(['error' => 'video_no_encontrado'], 404);
        }

        $valor = $request->input('valor');

        if (! is_scalar($valor) || is_bool($valor) || ! in_array((string) $valor, ['1', '-1', '0'], true)) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`valor` tiene que ser 1, -1 o 0.'], 422);
        }

        $valor   = (int) $valor;
        $usuario = $this->usuario($request);

        if ($valor === 0) {
            RecursosVoto::where('recursos_usuario_id', $usuario->id)->where('recursos_video_id', $video->id)->delete();
        } else {
            try {
                RecursosVoto::updateOrCreate(
                    ['recursos_usuario_id' => $usuario->id, 'recursos_video_id' => $video->id],
                    ['valor' => $valor]
                );
            } catch (QueryException $e) {
                // Dos clics casi juntos: el único frenó al segundo INSERT. Se actualiza la que quedó.
                RecursosVoto::where('recursos_usuario_id', $usuario->id)->where('recursos_video_id', $video->id)->update(['valor' => $valor]);
            }
        }

        return response()->json(['codigo' => $video->codigo, 'valor' => $valor]);
    }

    /**
     * POST recursos/preguntas — `{codigo, texto}` (1 a 2000 caracteres) → la pregunta creada (201).
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function preguntas_json(Request $request): JsonResponse
    {
        $video = $this->video_activo($request->input('codigo'));

        if (! $video instanceof RecursosVideo) {
            return response()->json(['error' => 'video_no_encontrado'], 404);
        }

        $texto = $request->input('texto');
        $texto = is_string($texto) ? self::texto_limpio($texto) : '';

        if ($texto === '' || mb_strlen($texto, 'UTF-8') > self::MAX_PREGUNTA) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`texto` tiene que tener entre 1 y ' . self::MAX_PREGUNTA . ' caracteres.'], 422);
        }

        $usuario = $this->usuario($request);

        $pregunta = RecursosPregunta::create([
            'recursos_video_id'   => $video->id,
            'recursos_usuario_id' => $usuario->id,
            'texto'               => $texto,
        ]);

        $pregunta->setRelation('usuario', $usuario);
        $pregunta->me_gustas_count = 0;

        return response()->json(self::pregunta_para_la_pagina($pregunta, $video->codigo, (int) $usuario->id, false), 201);
    }

    /**
     * POST recursos/preguntas/{id}/me-gusta — alterna el me gusta → `{me_gusta: bool, total}`. Solo
     * sobre preguntas que ese usuario puede ver.
     *
     * @param Request $request
     * @param string  $id
     *
     * @return JsonResponse
     */
    public function me_gusta_json(Request $request, string $id): JsonResponse
    {
        $usuario = $this->usuario($request);

        $pregunta = ctype_digit($id)
            ? RecursosPregunta::where('id', (int) $id)->visiblesPara((int) $usuario->id)->first()
            : null;

        if (! $pregunta instanceof RecursosPregunta || ! $this->video_activo_por_id((int) $pregunta->recursos_video_id)) {
            return response()->json(['error' => 'pregunta_no_encontrada'], 404);
        }

        $borrados = RecursosPreguntaMeGusta::where('recursos_pregunta_id', $pregunta->id)
            ->where('recursos_usuario_id', $usuario->id)
            ->delete();

        $me_gusta = false;

        if ($borrados === 0) {
            try {
                RecursosPreguntaMeGusta::create([
                    'recursos_pregunta_id' => $pregunta->id,
                    'recursos_usuario_id'  => $usuario->id,
                ]);
            } catch (QueryException $e) {
                // Otro pedido lo dio en el medio: queda dado.
            }

            $me_gusta = true;
        }

        return response()->json([
            'me_gusta' => $me_gusta,
            'total'    => RecursosPreguntaMeGusta::where('recursos_pregunta_id', $pregunta->id)->count(),
        ]);
    }

    /**
     * POST recursos/encuestas — `{codigo, util, entendio, mejora}` (al menos uno). Volver a mandarla la
     * actualiza con lo que llegó. → `{codigo, util, entendio, mejora}`.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function encuestas_json(Request $request): JsonResponse
    {
        $video = $this->video_activo($request->input('codigo'));

        if (! $video instanceof RecursosVideo) {
            return response()->json(['error' => 'video_no_encontrado'], 404);
        }

        $util     = $request->input('util');
        $entendio = $request->input('entendio');
        $mejora   = $request->input('mejora');

        if ($util !== null && ! in_array($util, RecursosEncuesta::UTIL, true)) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`util` es "si", "no" o null.'], 422);
        }

        if ($entendio !== null && ! in_array($entendio, RecursosEncuesta::ENTENDIO, true)) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`entendio` es "si", "mas_o_menos", "no" o null.'], 422);
        }

        if ($mejora !== null && ! is_string($mejora)) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`mejora` es texto o null.'], 422);
        }

        $mejora = $mejora === null ? '' : self::texto_limpio($mejora);

        if (mb_strlen($mejora, 'UTF-8') > self::MAX_MEJORA) {
            return response()->json(['error' => 'datos_invalidos', 'detalle' => '`mejora` es de hasta ' . self::MAX_MEJORA . ' caracteres.'], 422);
        }

        $mejora = $mejora === '' ? null : $mejora;

        if ($util === null && $entendio === null && $mejora === null) {
            return response()->json(['error' => 'encuesta_vacia', 'detalle' => 'Respondé al menos una de las tres.'], 422);
        }

        $usuario = $this->usuario($request);
        $valores = ['util' => $util, 'entendio' => $entendio, 'mejora' => $mejora];

        try {
            RecursosEncuesta::updateOrCreate(
                ['recursos_usuario_id' => $usuario->id, 'recursos_video_id' => $video->id],
                $valores
            );
        } catch (QueryException $e) {
            RecursosEncuesta::where('recursos_usuario_id', $usuario->id)->where('recursos_video_id', $video->id)->update($valores);
        }

        return response()->json(['codigo' => $video->codigo] + $valores);
    }

    /**
     * Una pregunta como la ve la página (contrato C2, §3.3 del plan).
     *
     * 🔴 Del autor sale SOLO el nombre de pila: ni el apellido ni el negocio ni el mail, aunque sea
     * la propia (la página no los necesita y así no hay un camino por el que se cuelen los de otro).
     *
     * @param RecursosPregunta $pregunta     Con `usuario` y `me_gustas_count` cargados.
     * @param string           $codigo
     * @param int|null         $usuario_id   Quién pide (null = invitado).
     * @param bool             $me_gusta_mio
     *
     * @return array<string, mixed>
     */
    public static function pregunta_para_la_pagina(RecursosPregunta $pregunta, string $codigo, ?int $usuario_id, bool $me_gusta_mio): array
    {
        $autor = $pregunta->usuario instanceof RecursosUsuario ? $pregunta->usuario->nombre : null;

        return [
            'id'           => (int) $pregunta->id,
            'codigo'       => $codigo,
            'autor'        => RecursosUsuario::nombre_de_pila($autor),
            'es_mia'       => $usuario_id !== null && (int) $pregunta->recursos_usuario_id === $usuario_id,
            'texto'        => (string) $pregunta->texto,
            'fecha'        => $pregunta->created_at !== null ? $pregunta->created_at->toIso8601String() : null,
            'me_gusta'     => (int) $pregunta->me_gustas_count,
            'me_gusta_mio' => $me_gusta_mio,
            'respuesta'    => $pregunta->respuesta === null ? null : [
                'texto' => (string) $pregunta->respuesta,
                'fecha' => $pregunta->respondido_at !== null ? $pregunta->respondido_at->toIso8601String() : null,
            ],
        ];
    }

    /**
     * Texto de un usuario, sin caracteres de control (salvo saltos de línea y tabs) ni los de
     * dirección del texto (que dan vuelta lo que se ve en el panel), y recortado.
     *
     * Se sacan esos y no "toda la categoría C" de Unicode a propósito: ahí adentro está el unidor de
     * ancho cero (U+200D) que arma los emojis compuestos, y un cliente escribe con emojis.
     *
     * @param string $texto
     *
     * @return string
     */
    public static function texto_limpio(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $texto);

        return trim($texto);
    }

    /**
     * El usuario del centro del pedido (lo deja el middleware de sesión), o null.
     *
     * @param Request $request
     *
     * @return RecursosUsuario|null
     */
    protected function usuario(Request $request): ?RecursosUsuario
    {
        $usuario = $request->attributes->get(RecursosSesion::ATRIBUTO_USUARIO);

        return $usuario instanceof RecursosUsuario ? $usuario : null;
    }

    /**
     * El video activo de un código, o null.
     *
     * @param mixed $codigo
     *
     * @return RecursosVideo|null
     */
    protected function video_activo($codigo): ?RecursosVideo
    {
        if (! is_string($codigo) || trim($codigo) === '' || strlen($codigo) > 20) {
            return null;
        }

        return RecursosVideo::where('codigo', trim($codigo))->where('activo', true)->first();
    }

    /**
     * Si el video de ese id está activo.
     *
     * @param int $id
     *
     * @return bool
     */
    protected function video_activo_por_id(int $id): bool
    {
        return RecursosVideo::where('id', $id)->where('activo', true)->exists();
    }

    /**
     * Los códigos activos como mapa (para filtrar relacionados).
     *
     * @return array<string, bool>
     */
    protected function codigos_activos(): array
    {
        $activos = [];

        foreach (RecursosVideo::where('activo', true)->pluck('codigo') as $codigo) {
            $activos[$codigo] = true;
        }

        return $activos;
    }

    /**
     * De estas preguntas, a cuáles les dio me gusta el usuario (mapa id => true).
     *
     * @param int|null        $usuario_id
     * @param array<int, int> $ids
     *
     * @return array<int, bool>
     */
    protected function me_gusta_de(?int $usuario_id, array $ids): array
    {
        if ($usuario_id === null || $ids === []) {
            return [];
        }

        $mios = [];

        foreach (RecursosPreguntaMeGusta::where('recursos_usuario_id', $usuario_id)->whereIn('recursos_pregunta_id', $ids)->pluck('recursos_pregunta_id') as $id) {
            $mios[(int) $id] = true;
        }

        return $mios;
    }
}
