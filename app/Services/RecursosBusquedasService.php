<?php

namespace App\Services;

use App\Models\RecursosBusqueda;
use App\Models\RecursosVideo;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que se busca en el buscador del centro de recursos (misión busquedas-centro-recursos,
 * 16/10/2026): guardar cada búsqueda sin datos personales, resumirlas para el panel y purgar las viejas.
 *
 * 🔴 PRIVACIDAD, el corazón de esta clase: la consulta la escribe un cliente en un cuadro de texto libre,
 * y a veces pega ahí lo que no debe (un mail, un teléfono, un CUIT, una tarjeta, un link). `normalizar()`
 * devuelve null —y la búsqueda NO se guarda— cuando la consulta tiene pinta de dato personal. Los
 * nombres propios no se pueden detectar: se acepta, con retención corta (`purgar()`) y panel solo para
 * el admin. La consulta tampoco va jamás a un log.
 */
class RecursosBusquedasService
{
    /**
     * Cuántas filas borra `purgar()` por vez (no trabar la tabla con un DELETE enorme).
     */
    const TANDA_DE_PURGA = 1000;

    /**
     * La consulta como se guarda, o null si hay que descartarla.
     *
     * Se normaliza (minúsculas, sin acentos ni emojis, espacios colapsados) y se mira si tiene pinta de
     * dato personal SOBRE LA CONSULTA YA NORMALIZADA y ANTES de recortarla, así un número largo no se
     * escapa por quedar pasado de los 120 caracteres. Descarta si:
     *   - tiene `@` (un mail), también el de ancho completo, que `Str::ascii` borra en vez de pasar a `@`;
     *   - tiene `http` o `www.` (un link), o un dominio (`mi-sitio.com.ar`);
     *   - tiene una clave: una palabra de 20 o más caracteres con letras y números (`clave_api_4ec39…`);
     *   - tiene una corrida de 5 o más dígitos, o 7 o más dígitos en total (teléfonos, CUIT, DNI,
     *     tarjetas, aunque estén escritos con espacios, puntos o guiones);
     *   - le quedan menos de 2 caracteres alfanuméricos (vacía, solo signos o solo emojis).
     * Un código como "t5.15" no se descarta (3 dígitos).
     *
     * @param mixed $texto
     *
     * @return string|null
     */
    public function normalizar($texto): ?string
    {
        if (! is_string($texto)) {
            return null;
        }

        // `Str::ascii` pasa a ASCII: saca los acentos ("más" → "mas", "ñ" → "n") y descarta lo que no
        // tiene equivalente (los emojis). Se baja a minúsculas antes y después porque algunas
        // transliteraciones devuelven mayúsculas.
        $bajo = mb_strtolower($texto, 'UTF-8');

        // 🔴 `Str::ascii` BORRA el `＠` y el `﹫` (arroba de ancho completo y chica) en vez de pasarlos a `@`:
        // "juan＠gmail.com" quedaba como "juangmail.com", un mail guardado. Con NFKC se vuelven `@`, `www`, etc.;
        // si no hay extensión `intl`, igual se cortan las dos arrobas a mano.
        if (class_exists('Normalizer')) {
            $compatible = \Normalizer::normalize($bajo, \Normalizer::FORM_KC);
            if (is_string($compatible)) {
                $bajo = $compatible;
            }
        }

        if (preg_match('/[@\x{FF20}\x{FE6B}]/u', $bajo) === 1) {
            return null;
        }

        $consulta = mb_strtolower(Str::ascii($bajo), 'UTF-8');
        // Controles y saltos de línea cuentan como espacio; después se colapsan.
        $consulta = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/', ' ', $consulta));

        if (strpos($consulta, '@') !== false) {
            return null;
        }

        if (strpos($consulta, 'http') !== false || strpos($consulta, 'www.') !== false) {
            return null;
        }

        // Un dominio ("mi-sitio.com.ar/xxx") o una clave larga con letras y números ("clave_api_4ec39hq…").
        if (preg_match('/[a-z0-9-]+\.(com|net|org|ar|info|edu|gob|io|me|app|shop|store)(?![a-z0-9])/', $consulta) === 1) {
            return null;
        }

        if (preg_match_all('/[a-z0-9_\-]{20,}/', $consulta, $palabras) > 0) {
            foreach ($palabras[0] as $palabra) {
                if (preg_match('/\d/', $palabra) === 1 && preg_match('/[a-z]/', $palabra) === 1) {
                    return null;
                }
            }
        }

        if (preg_match('/\d{5,}/', $consulta) === 1 || preg_match_all('/\d/', $consulta) >= 7) {
            return null;
        }

        if (preg_match_all('/[a-z0-9]/', $consulta) < 2) {
            return null;
        }

        $maximo = (int) config('recursos.busquedas.max_consulta', 120);

        return rtrim(mb_substr($consulta, 0, $maximo, 'UTF-8'));
    }

    /**
     * Registra una búsqueda (upsert por `evento`).
     *
     * Si el evento no existe se crea con todo el payload (si trae `abrio`, queda con eso). Si ya existe,
     * NO se pisa nada (ni la consulta ni los resultados): solo se completa el resultado abierto cuando
     * la fila todavía no lo tenía, así que el primer clic gana.
     *
     * @param array<string, mixed> $datos      Ya validados: evento, consulta, resultados, primero, completo, abrio, posicion.
     * @param int|null             $usuario_id null = invitado.
     *
     * @return array{registrado: bool, motivo?: string}
     */
    public function registrar(array $datos, ?int $usuario_id): array
    {
        $consulta = $this->normalizar($datos['consulta']);

        if ($consulta === null) {
            return ['registrado' => false, 'motivo' => 'descartada'];
        }

        $evento = (string) $datos['evento'];
        $abrio  = $datos['abrio'] ?? null;

        $existente = RecursosBusqueda::where('evento', $evento)->exists();

        if (! $existente) {
            try {
                RecursosBusqueda::create([
                    'recursos_usuario_id' => $usuario_id,
                    'evento'              => $evento,
                    'consulta'            => $consulta,
                    'resultados'          => (int) $datos['resultados'],
                    'primero_codigo'      => $datos['primero'] ?? null,
                    'completo'            => (bool) $datos['completo'],
                    'abierto_codigo'      => $abrio,
                    'abierto_posicion'    => $abrio === null ? null : ($datos['posicion'] ?? null),
                ]);

                return ['registrado' => true];
            } catch (QueryException $e) {
                // Dos pedidos con el mismo evento a la vez: el único frenó al segundo INSERT. Si lo que
                // falló fue otra cosa, la fila no está y el error se relanza (no se traga).
                if (! RecursosBusqueda::where('evento', $evento)->exists()) {
                    throw $e;
                }
            }
        }

        if ($abrio !== null) {
            // Por el query builder y con `whereNull`: la condición y la escritura son UNA sola sentencia,
            // así que dos clics a la vez no se pisan (gana el primero).
            RecursosBusqueda::where('evento', $evento)
                ->whereNull('abierto_codigo')
                ->update([
                    'abierto_codigo'   => $abrio,
                    'abierto_posicion' => $datos['posicion'] ?? null,
                    'updated_at'       => now(),
                ]);
        }

        return ['registrado' => true];
    }

    /**
     * El resumen del panel (contrato C-BUSQ-2) del período `desde`–`hasta`, ambos días inclusivos.
     *
     * @param Carbon $desde
     * @param Carbon $hasta
     * @param int    $limit Tope de filas de cada lista.
     *
     * @return array{totales: array<string, int>, sin_encontrar: array<int, array<string, mixed>>, sin_clic: array<int, array<string, mixed>>}
     */
    public function resumen(Carbon $desde, Carbon $hasta, int $limit): array
    {
        $inicio = $desde->copy()->startOfDay();
        $fin    = $hasta->copy()->endOfDay();

        $periodo = function () use ($inicio, $fin) {
            return DB::table('recursos_busquedas')->whereBetween('created_at', [$inicio, $fin]);
        };

        $t = $periodo()->selectRaw(
            'COUNT(*) AS busquedas, '
            . 'SUM(CASE WHEN resultados = 0 THEN 1 ELSE 0 END) AS sin_resultados, '
            . 'SUM(CASE WHEN resultados > 0 AND completo = 0 THEN 1 ELSE 0 END) AS incompletas, '
            . 'SUM(CASE WHEN resultados > 0 AND abierto_codigo IS NULL THEN 1 ELSE 0 END) AS sin_clic, '
            . 'SUM(CASE WHEN abierto_codigo IS NOT NULL THEN 1 ELSE 0 END) AS con_clic'
        )->first();

        $totales = [
            'busquedas'      => (int) $t->busquedas,
            'sin_resultados' => (int) $t->sin_resultados,
            'incompletas'    => (int) $t->incompletas,
            'sin_clic'       => (int) $t->sin_clic,
            'con_clic'       => (int) $t->con_clic,
        ];

        $columnas = 'consulta, COUNT(*) AS veces, '
            . 'SUM(CASE WHEN resultados = 0 THEN 1 ELSE 0 END) AS sin_resultados, '
            . 'MAX(resultados) AS resultados_max, MAX(created_at) AS ultima, MAX(id) AS ultimo_id';

        $sin_encontrar = $periodo()
            ->where(function ($q) {
                $q->where('resultados', 0)->orWhere('completo', 0);
            })
            ->selectRaw($columnas)
            ->groupBy('consulta')
            ->orderByDesc('veces')->orderByDesc('ultima')->orderBy('consulta')
            ->limit($limit)
            ->get();

        $sin_clic = $periodo()
            ->selectRaw($columnas)
            ->groupBy('consulta')
            ->havingRaw('MAX(resultados) > 0 AND SUM(CASE WHEN abierto_codigo IS NULL THEN 0 ELSE 1 END) = 0')
            ->orderByDesc('veces')->orderByDesc('ultima')->orderBy('consulta')
            ->limit($limit)
            ->get();

        // Lo que devolvió la fila más reciente de cada consulta (el id crece con el tiempo), con UNA
        // consulta para las dos listas; y los títulos, con otra. Nada de N+1.
        $ids = $sin_encontrar->pluck('ultimo_id')->merge($sin_clic->pluck('ultimo_id'))->unique()->values()->all();

        $filas = $ids === []
            ? collect()
            : DB::table('recursos_busquedas')->whereIn('id', $ids)->get(['id', 'resultados', 'primero_codigo'])->keyBy('id');

        $codigos = $filas->pluck('primero_codigo')->filter()->unique()->values()->all();
        $titulos = $codigos === [] ? [] : RecursosVideo::whereIn('codigo', $codigos)->pluck('titulo', 'codigo')->all();

        $armar = function ($grupo) use ($filas, $titulos) {
            $fila   = $filas->get($grupo->ultimo_id);
            $codigo = ($fila !== null && (int) $fila->resultados > 0 && $fila->primero_codigo !== null && $fila->primero_codigo !== '')
                ? (string) $fila->primero_codigo
                : null;

            return [
                'consulta'       => (string) $grupo->consulta,
                'veces'          => (int) $grupo->veces,
                'resultados_max' => (int) $grupo->resultados_max,
                'sin_resultados' => (int) $grupo->sin_resultados,
                'primero_codigo' => $codigo,
                'primero_titulo' => $codigo !== null && isset($titulos[$codigo]) ? (string) $titulos[$codigo] : null,
                'ultima'         => (string) $grupo->ultima,
            ];
        };

        return [
            'totales'       => $totales,
            'sin_encontrar' => $sin_encontrar->map($armar)->values()->all(),
            'sin_clic'      => $sin_clic->map($armar)->values()->all(),
        ];
    }

    /**
     * Borra las búsquedas de más de `$dias` días (por `created_at`), en tandas.
     *
     * @param int $dias
     *
     * @return int Cuántas borró.
     */
    public function purgar(int $dias): int
    {
        $limite = now()->subDays($dias);
        $total  = 0;

        do {
            $borradas = DB::table('recursos_busquedas')->where('created_at', '<', $limite)->limit(self::TANDA_DE_PURGA)->delete();
            $total   += $borradas;
        } while ($borradas >= self::TANDA_DE_PURGA);

        return $total;
    }
}
