<?php

namespace App\Services;

use App\Models\Client;
use App\Models\RecursosEncuesta;
use App\Models\RecursosPregunta;
use App\Models\RecursosProgreso;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use App\Models\RecursosVoto;
use Illuminate\Support\Facades\DB;

/**
 * Los números del panel del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026):
 * cómo le va a cada video (solapa "Videos") y cuánto avanzó cada cliente (solapa "Clientes" y la
 * pantalla de Implementaciones).
 *
 * 🔴 EL AVANCE se calcula IGUAL que en la página (función `avance()` del prototipo aprobado): sobre
 * los videos ACTIVOS, cada terminado cuenta 1 y cada uno sin terminar cuenta su porcentaje; el total
 * se divide por la cantidad de videos. Si el panel lo contara distinto, Lucas vería un número y el
 * cliente otro.
 */
class RecursosEstadisticasService
{
    /**
     * Por video: vistas, terminados, votos, encuestas y "qué mejorar", ordenado del PEOR puntuado al
     * mejor.
     *
     * Puntaje = (👎 + útil "no" + se entendió "no" + 0,5 × "más o menos") / respuestas, donde
     * "respuestas" son todas las señales que se contaron: votos (👍 y 👎), respuestas a "¿te fue útil?"
     * y respuestas a "¿se entendió?". Va de 0 (todo bien) a 1 (todo mal). Empate: primero el que tiene
     * más respuestas (más evidencia). Un video sin ninguna respuesta tiene puntaje null y va al final,
     * en el orden del catálogo.
     *
     * Incluye los inactivos que tengan alguna señal (su historia sigue sirviendo), con `activo: false`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function videos(): array
    {
        $vistas = RecursosProgreso::groupBy('recursos_video_id')
            ->select('recursos_video_id', DB::raw('COUNT(*) AS vistas'), DB::raw('SUM(CASE WHEN terminado_at IS NULL THEN 0 ELSE 1 END) AS terminados'))
            ->get()->keyBy('recursos_video_id');

        $votos = RecursosVoto::groupBy('recursos_video_id')
            ->select('recursos_video_id', DB::raw('SUM(CASE WHEN valor > 0 THEN 1 ELSE 0 END) AS positivos'), DB::raw('SUM(CASE WHEN valor < 0 THEN 1 ELSE 0 END) AS negativos'))
            ->get()->keyBy('recursos_video_id');

        $encuestas = RecursosEncuesta::groupBy('recursos_video_id')
            ->select(
                'recursos_video_id',
                DB::raw('COUNT(*) AS total'),
                DB::raw("SUM(CASE WHEN util = 'si' THEN 1 ELSE 0 END) AS util_si"),
                DB::raw("SUM(CASE WHEN util = 'no' THEN 1 ELSE 0 END) AS util_no"),
                DB::raw("SUM(CASE WHEN entendio = 'si' THEN 1 ELSE 0 END) AS entendio_si"),
                DB::raw("SUM(CASE WHEN entendio = 'mas_o_menos' THEN 1 ELSE 0 END) AS entendio_mas_o_menos"),
                DB::raw("SUM(CASE WHEN entendio = 'no' THEN 1 ELSE 0 END) AS entendio_no"),
                DB::raw("SUM(CASE WHEN mejora IS NULL OR mejora = '' THEN 0 ELSE 1 END) AS mejoras")
            )
            ->get()->keyBy('recursos_video_id');

        $preguntas = RecursosPregunta::groupBy('recursos_video_id')
            ->select('recursos_video_id', DB::raw('COUNT(*) AS total'), DB::raw('SUM(CASE WHEN respuesta IS NULL AND oculta = 0 THEN 1 ELSE 0 END) AS pendientes'))
            ->get()->keyBy('recursos_video_id');

        $filas = [];

        foreach (RecursosVideo::orderBy('orden')->orderBy('id')->get() as $video) {
            $v = $vistas->get($video->id);
            $o = $votos->get($video->id);
            $e = $encuestas->get($video->id);
            $p = $preguntas->get($video->id);

            $positivos = $o ? (int) $o->positivos : 0;
            $negativos = $o ? (int) $o->negativos : 0;

            $enc = [
                'total'                => $e ? (int) $e->total : 0,
                'util_si'              => $e ? (int) $e->util_si : 0,
                'util_no'              => $e ? (int) $e->util_no : 0,
                'entendio_si'          => $e ? (int) $e->entendio_si : 0,
                'entendio_mas_o_menos' => $e ? (int) $e->entendio_mas_o_menos : 0,
                'entendio_no'          => $e ? (int) $e->entendio_no : 0,
            ];

            $respuestas = $positivos + $negativos
                + $enc['util_si'] + $enc['util_no']
                + $enc['entendio_si'] + $enc['entendio_mas_o_menos'] + $enc['entendio_no'];

            $malas = $negativos + $enc['util_no'] + $enc['entendio_no'] + 0.5 * $enc['entendio_mas_o_menos'];

            $hay_algo = $v !== null || $respuestas > 0 || $p !== null || ($e !== null && (int) $e->mejoras > 0);

            if (! $video->activo && ! $hay_algo) {
                continue;
            }

            $filas[] = [
                'codigo'         => $video->codigo,
                'titulo'         => $video->titulo,
                'carpeta'        => (int) $video->carpeta,
                'carpeta_nombre' => $video->carpeta_nombre,
                'orden'          => (int) $video->orden,
                'activo'         => (bool) $video->activo,
                'vistas'         => $v ? (int) $v->vistas : 0,
                'terminados'     => $v ? (int) $v->terminados : 0,
                'votos'          => ['positivos' => $positivos, 'negativos' => $negativos],
                'encuestas'      => $enc,
                'mejoras'        => $e ? (int) $e->mejoras : 0,
                'preguntas'      => ['total' => $p ? (int) $p->total : 0, 'pendientes' => $p ? (int) $p->pendientes : 0],
                'respuestas'     => $respuestas,
                'puntaje'        => $respuestas > 0 ? round($malas / $respuestas, 4) : null,
            ];
        }

        usort($filas, function ($a, $b) {
            if ($a['puntaje'] === null || $b['puntaje'] === null) {
                if ($a['puntaje'] === null && $b['puntaje'] === null) {
                    return $a['orden'] <=> $b['orden'];
                }

                return $a['puntaje'] === null ? 1 : -1;
            }

            if ($a['puntaje'] !== $b['puntaje']) {
                return $b['puntaje'] <=> $a['puntaje'];
            }

            if ($a['respuestas'] !== $b['respuestas']) {
                return $b['respuestas'] <=> $a['respuestas'];
            }

            return $a['orden'] <=> $b['orden'];
        });

        return $filas;
    }

    /**
     * Los "qué mejorarías" de un video, los más nuevos primero, con quién lo dijo.
     *
     * @param RecursosVideo $video
     *
     * @return array<int, array<string, mixed>>
     */
    public function mejoras(RecursosVideo $video): array
    {
        return RecursosEncuesta::where('recursos_video_id', $video->id)
            ->whereNotNull('mejora')
            ->where('mejora', '!=', '')
            ->with('usuario.client')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (RecursosEncuesta $encuesta) {
                $usuario = $encuesta->usuario;

                return [
                    'texto'    => (string) $encuesta->mejora,
                    'util'     => $encuesta->util,
                    'entendio' => $encuesta->entendio,
                    'fecha'    => $encuesta->updated_at !== null ? $encuesta->updated_at->toIso8601String() : null,
                    'usuario'  => $usuario instanceof RecursosUsuario ? $usuario->nombre : null,
                    'cliente'  => $usuario instanceof RecursosUsuario && $usuario->client instanceof Client
                        ? $usuario->client->resolve_display_name()
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * TODOS los clientes activos con lo suyo en el centro: usuarios, avance promedio, videos
     * terminados y última actividad. Un cliente que nunca entró sale igual, en cero.
     *
     * 🔴 Todos y no solo los que ya entraron: desde esta lista se saca el link del dueño, y el caso que
     * más importa es justamente el del cliente que todavía no entró (hasta que su sistema tenga el
     * botón del centro, el link es la única puerta).
     *
     * Quedan afuera los dados de baja (`is_active` en false), con el mismo criterio que los procesos que
     * recorren la flota (`RecolectarTokensCommand`, `SincronizarAiPlanesCommand`).
     *
     * Orden: primero los que tienen actividad, la más reciente arriba; después el resto por nombre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function clientes(): array
    {
        $por_usuario = $this->avance_por_usuario();

        $usuarios_por_cliente = [];
        foreach (RecursosUsuario::get(['id', 'client_id', 'ultimo_ingreso_at']) as $usuario) {
            $usuarios_por_cliente[(int) $usuario->client_id][] = $usuario;
        }

        $lista = [];

        foreach (Client::where('is_active', true)->get(['id', 'name', 'company_name', 'slug']) as $client) {
            $fila = [
                'client_id'         => (int) $client->id,
                'nombre'            => $client->resolve_display_name(),
                'slug'              => $client->slug,
                'usuarios'          => 0,
                'avance'            => 0.0,
                'videos_terminados' => 0,
                'ultima_actividad'  => null,
            ];

            $usuarios = isset($usuarios_por_cliente[(int) $client->id]) ? $usuarios_por_cliente[(int) $client->id] : [];
            $suma     = 0.0;

            foreach ($usuarios as $usuario) {
                $suyo = isset($por_usuario[$usuario->id]) ? $por_usuario[$usuario->id] : ['avance' => 0.0, 'terminados' => 0, 'ultima' => null];

                $fila['usuarios']++;
                $suma                      += $suyo['avance'];
                $fila['videos_terminados'] += $suyo['terminados'];
                $fila['ultima_actividad']   = self::mas_reciente(
                    $fila['ultima_actividad'],
                    self::mas_reciente($suyo['ultima'], $usuario->ultimo_ingreso_at !== null ? $usuario->ultimo_ingreso_at->toDateTimeString() : null)
                );
            }

            $fila['avance']           = $fila['usuarios'] > 0 ? round($suma / $fila['usuarios'], 1) : 0.0;
            $fila['ultima_actividad'] = self::iso($fila['ultima_actividad']);

            $lista[] = $fila;
        }

        usort($lista, function ($a, $b) {
            $a_activo = $a['ultima_actividad'] !== null;
            $b_activo = $b['ultima_actividad'] !== null;

            if ($a_activo !== $b_activo) {
                return $a_activo ? -1 : 1;
            }

            if ($a_activo) {
                $por_fecha = strcmp((string) $b['ultima_actividad'], (string) $a['ultima_actividad']);

                if ($por_fecha !== 0) {
                    return $por_fecha;
                }
            }

            $por_nombre = strcmp(mb_strtolower((string) $a['nombre'], 'UTF-8'), mb_strtolower((string) $b['nombre'], 'UTF-8'));

            return $por_nombre !== 0 ? $por_nombre : $a['client_id'] <=> $b['client_id'];
        });

        return $lista;
    }

    /**
     * Un cliente: el resumen (lo que muestra Implementaciones), si tiene link del dueño vigente, y
     * cada usuario con su avance total y por carpeta.
     *
     * @param Client $client
     *
     * @return array<string, mixed>
     */
    public function cliente(Client $client): array
    {
        $usuarios = RecursosUsuario::where('client_id', $client->id)
            ->orderByDesc('es_dueno')
            ->orderBy('nombre')
            ->get();

        $ids         = $usuarios->pluck('id')->all();
        $por_usuario = $this->avance_por_usuario($ids);

        $videos = RecursosVideo::where('activo', true)->orderBy('orden')->get(['id', 'carpeta', 'carpeta_nombre']);

        $carpetas = [];
        foreach ($videos as $video) {
            $nro = (int) $video->carpeta;

            if (! isset($carpetas[$nro])) {
                $carpetas[$nro] = ['nro' => $nro, 'nombre' => (string) $video->carpeta_nombre, 'total' => 0];
            }

            $carpetas[$nro]['total']++;
        }
        ksort($carpetas);

        $video_carpeta = [];
        foreach ($videos as $video) {
            $video_carpeta[(int) $video->id] = (int) $video->carpeta;
        }

        // Avance por usuario y carpeta: suma de (terminado ? 1 : porcentaje/100) de cada video activo.
        $por_carpeta = [];
        $terminados_carpeta = [];

        if ($ids !== []) {
            foreach (RecursosProgreso::whereIn('recursos_usuario_id', $ids)->get(['recursos_usuario_id', 'recursos_video_id', 'porcentaje', 'terminado_at']) as $fila) {
                $video_id = (int) $fila->recursos_video_id;

                if (! isset($video_carpeta[$video_id])) {
                    continue;
                }

                $u   = (int) $fila->recursos_usuario_id;
                $nro = $video_carpeta[$video_id];

                $por_carpeta[$u][$nro] = (isset($por_carpeta[$u][$nro]) ? $por_carpeta[$u][$nro] : 0)
                    + ($fila->terminado_at !== null ? 1 : min(1, (float) $fila->porcentaje / 100));

                if ($fila->terminado_at !== null) {
                    $terminados_carpeta[$u][$nro] = (isset($terminados_carpeta[$u][$nro]) ? $terminados_carpeta[$u][$nro] : 0) + 1;
                }
            }
        }

        $lista   = [];
        $resumen = ['usuarios' => 0, 'avance' => 0.0, 'videos_terminados' => 0, 'ultima_actividad' => null];

        foreach ($usuarios as $usuario) {
            $suyo = isset($por_usuario[$usuario->id]) ? $por_usuario[$usuario->id] : ['avance' => 0.0, 'terminados' => 0, 'ultima' => null];

            $ultima = self::mas_reciente($suyo['ultima'], $usuario->ultimo_ingreso_at !== null ? $usuario->ultimo_ingreso_at->toDateTimeString() : null);

            $sus_carpetas = [];
            foreach ($carpetas as $nro => $carpeta) {
                $suma = isset($por_carpeta[$usuario->id][$nro]) ? $por_carpeta[$usuario->id][$nro] : 0;

                $sus_carpetas[] = [
                    'nro'        => $nro,
                    'nombre'     => $carpeta['nombre'],
                    'total'      => $carpeta['total'],
                    'terminados' => isset($terminados_carpeta[$usuario->id][$nro]) ? $terminados_carpeta[$usuario->id][$nro] : 0,
                    'avance'     => $carpeta['total'] > 0 ? round($suma * 100 / $carpeta['total'], 1) : 0.0,
                ];
            }

            $lista[] = [
                'id'                => (int) $usuario->id,
                'nombre'            => $usuario->nombre,
                'email'             => $usuario->email,
                'es_dueno'          => (bool) $usuario->es_dueno,
                'empresa_user_id'   => $usuario->empresa_user_id,
                'ultimo_ingreso_at' => $usuario->ultimo_ingreso_at !== null ? $usuario->ultimo_ingreso_at->toIso8601String() : null,
                'ultima_actividad'  => self::iso($ultima),
                'avance'            => round($suyo['avance'], 1),
                'terminados'        => $suyo['terminados'],
                'carpetas'          => $sus_carpetas,
            ];

            $resumen['usuarios']++;
            $resumen['avance']            += $suyo['avance'];
            $resumen['videos_terminados'] += $suyo['terminados'];
            $resumen['ultima_actividad']   = self::mas_reciente($resumen['ultima_actividad'], $ultima);
        }

        $resumen['avance']           = $resumen['usuarios'] > 0 ? round($resumen['avance'] / $resumen['usuarios'], 1) : 0.0;
        $resumen['ultima_actividad'] = self::iso($resumen['ultima_actividad']);
        $resumen['videos_activos']   = $videos->count();

        $vigente = (new RecursosLinkService())->link_vigente_del_dueno($client);

        return [
            'cliente'    => [
                'id'     => (int) $client->id,
                'nombre' => $client->resolve_display_name(),
                'slug'   => $client->slug,
            ],
            'resumen'    => $resumen,
            'link_dueno' => $vigente === null ? null : ['expires_at' => $vigente['expires_at']],
            'usuarios'   => $lista,
        ];
    }

    /**
     * Avance (0–100), terminados y última actividad de cada usuario, sobre los videos activos.
     *
     * @param array<int, int>|null $ids Solo estos usuarios (null = todos).
     *
     * @return array<int, array{avance: float, terminados: int, ultima: string|null}>
     */
    protected function avance_por_usuario(?array $ids = null): array
    {
        $total = RecursosVideo::where('activo', true)->count();

        $consulta = RecursosProgreso::query()
            ->join('recursos_videos', 'recursos_videos.id', '=', 'recursos_progresos.recursos_video_id')
            ->where('recursos_videos.activo', true)
            ->groupBy('recursos_progresos.recursos_usuario_id')
            ->select(
                'recursos_progresos.recursos_usuario_id',
                DB::raw('SUM(CASE WHEN recursos_progresos.terminado_at IS NULL THEN LEAST(recursos_progresos.porcentaje, 100) / 100 ELSE 1 END) AS suma'),
                DB::raw('SUM(CASE WHEN recursos_progresos.terminado_at IS NULL THEN 0 ELSE 1 END) AS terminados'),
                DB::raw('MAX(recursos_progresos.updated_at) AS ultima')
            );

        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }

            $consulta->whereIn('recursos_progresos.recursos_usuario_id', $ids);
        }

        $resultado = [];

        foreach ($consulta->get() as $fila) {
            $resultado[(int) $fila->recursos_usuario_id] = [
                'avance'     => $total > 0 ? (float) $fila->suma * 100 / $total : 0.0,
                'terminados' => (int) $fila->terminados,
                'ultima'     => $fila->ultima !== null ? (string) $fila->ultima : null,
            ];
        }

        return $resultado;
    }

    /**
     * La más reciente de dos fechas 'Y-m-d H:i:s' (o null).
     *
     * @param string|null $a
     * @param string|null $b
     *
     * @return string|null
     */
    private static function mas_reciente(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    /**
     * Una fecha de MySQL como ISO 8601 (o null).
     *
     * @param string|null $fecha
     *
     * @return string|null
     */
    private static function iso(?string $fecha): ?string
    {
        return $fecha === null ? null : \Carbon\Carbon::parse($fecha)->toIso8601String();
    }
}
