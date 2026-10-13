<?php

namespace App\Services;

use App\Models\RecursosVideo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026): lo que lee la
 * página (`GET recursos/catalogo` y `GET recursos/buscar-indice`, cacheados) y la carga que hace
 * Claude (`POST claude/recursos/catalogo`, que invalida esas cachés).
 *
 * La caché no vence sola: el catálogo cambia solamente cuando se carga, y la carga la borra. Así el
 * catálogo público (364 videos, cada visita) no consulta la base en cada pedido, y nunca sirve un
 * catálogo viejo después de una carga.
 */
class RecursosCatalogoService
{
    const CACHE_CATALOGO = 'recursos:catalogo:v1';

    const CACHE_INDICE = 'recursos:buscar-indice:v1';

    /**
     * Topes de la carga. No son reglas de negocio: frenan un payload roto antes de escribir.
     */
    const MAX_VIDEOS = 2000;

    /**
     * Campos de texto de cada video y su largo máximo (en caracteres).
     *
     * @var array<string, int>
     */
    const LARGOS = [
        'codigo'         => 20,
        'titulo'         => 255,
        'carpeta_nombre' => 255,
        'seccion'        => 255,
        'url'            => 500,
        'miniatura_url'  => 500,
        'version'        => 16,
    ];

    /**
     * `{carpetas, videos}` del catálogo público: videos activos ordenados por `orden`, sin
     * transcripción, y las carpetas que tienen al menos uno.
     *
     * @return array{carpetas: array<int, array{nro: int, nombre: string}>, videos: array<int, array<string, mixed>>}
     */
    public function catalogo(): array
    {
        return Cache::rememberForever(self::CACHE_CATALOGO, function () {
            $videos = RecursosVideo::where('activo', true)
                ->orderBy('orden')
                ->orderBy('id')
                ->get();

            $activos  = [];
            foreach ($videos as $video) {
                $activos[$video->codigo] = true;
            }

            $carpetas = [];
            foreach ($videos as $video) {
                if (! isset($carpetas[(int) $video->carpeta])) {
                    $carpetas[(int) $video->carpeta] = ['nro' => (int) $video->carpeta, 'nombre' => (string) $video->carpeta_nombre];
                }
            }
            ksort($carpetas);

            return [
                'carpetas' => array_values($carpetas),
                'videos'   => $videos->map(function (RecursosVideo $video) use ($activos) {
                    return $video->para_el_catalogo($activos);
                })->values()->all(),
            ];
        });
    }

    /**
     * `{codigo: transcripcion}` de todos los videos activos, para el buscador de la página.
     *
     * @return array<string, string>
     */
    public function indice(): array
    {
        return Cache::rememberForever(self::CACHE_INDICE, function () {
            $indice = [];

            foreach (RecursosVideo::where('activo', true)->orderBy('orden')->get(['codigo', 'transcripcion']) as $video) {
                $indice[$video->codigo] = (string) $video->transcripcion;
            }

            return $indice;
        });
    }

    /**
     * Borra las cachés del catálogo. La llama la carga; también sirve a mano.
     *
     * @return void
     */
    public function olvidar(): void
    {
        Cache::forget(self::CACHE_CATALOGO);
        Cache::forget(self::CACHE_INDICE);
    }

    /**
     * Valida un payload de carga. Devuelve los errores por campo (vacío = sirve) y los videos
     * normalizados, listos para escribir.
     *
     * Se valida TODO antes de escribir nada: un catálogo con un video roto no se carga a medias.
     *
     * @param mixed $carpetas
     * @param mixed $videos
     *
     * @return array{errores: array<string, string>, videos: array<int, array<string, mixed>>}
     */
    public function validar($carpetas, $videos): array
    {
        $errores = [];
        $nombres = [];

        if ($carpetas !== null && ! is_array($carpetas)) {
            $errores['carpetas'] = 'Tiene que ser una lista de {nro, nombre}.';
        } elseif (is_array($carpetas)) {
            foreach (array_values($carpetas) as $i => $carpeta) {
                if (! is_array($carpeta) || ! isset($carpeta['nro']) || ! self::entero($carpeta['nro']) || (int) $carpeta['nro'] < 1
                    || ! isset($carpeta['nombre']) || ! is_string($carpeta['nombre']) || trim($carpeta['nombre']) === '') {
                    $errores['carpetas.' . $i] = 'Cada carpeta es {nro: entero ≥ 1, nombre: texto}.';
                    continue;
                }

                $nombres[(int) $carpeta['nro']] = mb_substr(trim($carpeta['nombre']), 0, self::LARGOS['carpeta_nombre'], 'UTF-8');
            }
        }

        if (! is_array($videos) || count($videos) === 0) {
            $errores['videos'] = 'Tiene que ser una lista con al menos un video.';

            return ['errores' => $errores, 'videos' => []];
        }

        if (count($videos) > self::MAX_VIDEOS) {
            $errores['videos'] = 'Son más de ' . self::MAX_VIDEOS . ' videos: el payload no parece un catálogo.';

            return ['errores' => $errores, 'videos' => []];
        }

        $limpios = [];
        $vistos  = [];

        foreach (array_values($videos) as $i => $video) {
            $donde = 'videos.' . $i;

            if (! is_array($video)) {
                $errores[$donde] = 'Cada video es un objeto.';
                continue;
            }

            $limpio = [];

            // Textos obligatorios.
            foreach (['codigo', 'titulo', 'url'] as $campo) {
                $valor = isset($video[$campo]) && is_string($video[$campo]) ? trim($video[$campo]) : '';

                if ($valor === '') {
                    $errores[$donde . '.' . $campo] = 'Obligatorio.';
                } elseif (mb_strlen($valor, 'UTF-8') > self::LARGOS[$campo]) {
                    $errores[$donde . '.' . $campo] = 'Hasta ' . self::LARGOS[$campo] . ' caracteres.';
                }

                $limpio[$campo] = $valor;
            }

            if ($limpio['codigo'] !== '') {
                if (isset($vistos[$limpio['codigo']])) {
                    $errores[$donde . '.codigo'] = 'El código ' . $limpio['codigo'] . ' viene repetido (también en videos.' . $vistos[$limpio['codigo']] . ').';
                }

                $vistos[$limpio['codigo']] = $i;
            }

            foreach (['url', 'miniatura_url'] as $campo) {
                if (isset($video[$campo]) && is_string($video[$campo]) && trim($video[$campo]) !== ''
                    && ! preg_match('#^https?://[^\s"\'<>]+$#i', trim($video[$campo]))) {
                    $errores[$donde . '.' . $campo] = 'Tiene que ser una URL http o https.';
                }
            }

            // Carpeta.
            if (! isset($video['carpeta']) || ! self::entero($video['carpeta']) || (int) $video['carpeta'] < 1 || (int) $video['carpeta'] > 65535) {
                $errores[$donde . '.carpeta'] = 'Entero entre 1 y 65535.';
                $limpio['carpeta'] = 0;
            } else {
                $limpio['carpeta'] = (int) $video['carpeta'];
            }

            $carpeta_nombre = isset($video['carpeta_nombre']) && is_string($video['carpeta_nombre']) ? trim($video['carpeta_nombre']) : '';

            if ($carpeta_nombre === '' && isset($nombres[$limpio['carpeta']])) {
                $carpeta_nombre = $nombres[$limpio['carpeta']];
            }

            if ($carpeta_nombre === '') {
                $errores[$donde . '.carpeta_nombre'] = 'Falta el nombre de la carpeta (en el video o en `carpetas`).';
            }

            $limpio['carpeta_nombre'] = mb_substr($carpeta_nombre, 0, self::LARGOS['carpeta_nombre'], 'UTF-8');

            // Orden y duración.
            if (! isset($video['orden']) || ! self::entero($video['orden']) || (int) $video['orden'] < 0) {
                $errores[$donde . '.orden'] = 'Entero ≥ 0.';
                $limpio['orden'] = 0;
            } else {
                $limpio['orden'] = (int) $video['orden'];
            }

            if (! isset($video['duracion']) || ! is_numeric($video['duracion']) || (float) $video['duracion'] <= 0 || (float) $video['duracion'] > 999999) {
                $errores[$donde . '.duracion'] = 'Segundos, mayor que cero.';
                $limpio['duracion'] = 0;
            } else {
                $limpio['duracion'] = round((float) $video['duracion'], 1);
            }

            // Textos opcionales.
            foreach (['seccion', 'miniatura_url', 'version'] as $campo) {
                $valor = isset($video[$campo]) && is_string($video[$campo]) ? trim($video[$campo]) : '';

                if (mb_strlen($valor, 'UTF-8') > self::LARGOS[$campo]) {
                    $errores[$donde . '.' . $campo] = 'Hasta ' . self::LARGOS[$campo] . ' caracteres.';
                }

                $limpio[$campo] = $valor === '' ? null : $valor;
            }

            foreach (['para_que', 'cuando_no', 'transcripcion'] as $campo) {
                if (isset($video[$campo]) && ! is_string($video[$campo])) {
                    $errores[$donde . '.' . $campo] = 'Tiene que ser texto.';
                }

                $valor = isset($video[$campo]) && is_string($video[$campo]) ? trim($video[$campo]) : '';
                $limpio[$campo] = $valor === '' ? null : $valor;
            }

            // Relacionados: lista de códigos.
            $relacionados = [];

            if (isset($video['relacionados'])) {
                if (! is_array($video['relacionados'])) {
                    $errores[$donde . '.relacionados'] = 'Tiene que ser una lista de códigos.';
                } else {
                    foreach ($video['relacionados'] as $codigo) {
                        if (is_string($codigo) && trim($codigo) !== '' && mb_strlen(trim($codigo), 'UTF-8') <= self::LARGOS['codigo']) {
                            $relacionados[] = trim($codigo);
                        }
                    }
                }
            }

            $limpio['relacionados'] = array_values(array_unique(array_filter($relacionados, function ($codigo) use ($limpio) {
                return $codigo !== $limpio['codigo'];
            })));

            $limpios[] = $limpio;
        }

        return ['errores' => $errores, 'videos' => $limpios];
    }

    /**
     * Escribe un catálogo YA validado: upsert por `codigo`, y con `$desactivar_faltantes` los activos
     * que no vinieron quedan `activo = false` (nunca se borran: tienen progreso). Borra las cachés.
     *
     * @param array<int, array<string, mixed>> $videos Los normalizados de `validar()`.
     * @param bool                              $desactivar_faltantes
     *
     * @return array{creados: int, actualizados: int, sin_cambios: int, desactivados: int}
     */
    public function cargar(array $videos, bool $desactivar_faltantes): array
    {
        $resultado = DB::transaction(function () use ($videos, $desactivar_faltantes) {
            $creados      = 0;
            $actualizados = 0;
            $sin_cambios  = 0;
            $codigos      = [];

            $existentes = RecursosVideo::whereIn('codigo', array_column($videos, 'codigo'))
                ->get()
                ->keyBy('codigo');

            foreach ($videos as $datos) {
                $codigos[] = $datos['codigo'];

                $video = $existentes->get($datos['codigo']);
                $nuevo = ! $video instanceof RecursosVideo;

                if ($nuevo) {
                    $video = new RecursosVideo();
                }

                $video->fill($datos);
                $video->activo = true;

                if ($nuevo) {
                    $video->save();
                    $creados++;
                } elseif ($video->isDirty()) {
                    $video->save();
                    $actualizados++;
                } else {
                    $sin_cambios++;
                }
            }

            $desactivados = 0;

            if ($desactivar_faltantes) {
                $desactivados = RecursosVideo::where('activo', true)
                    ->whereNotIn('codigo', $codigos)
                    ->update(['activo' => false, 'updated_at' => now()]);
            }

            return [
                'creados'      => $creados,
                'actualizados' => $actualizados,
                'sin_cambios'  => $sin_cambios,
                'desactivados' => (int) $desactivados,
            ];
        });

        $this->olvidar();

        return $resultado;
    }

    /**
     * Si un valor es un entero (int, o texto/float que represente uno exacto).
     *
     * @param mixed $valor
     *
     * @return bool
     */
    private static function entero($valor): bool
    {
        if (is_int($valor)) {
            return true;
        }

        if (is_float($valor)) {
            return floor($valor) === $valor;
        }

        return is_string($valor) && preg_match('/^-?\d+$/', trim($valor)) === 1;
    }
}
