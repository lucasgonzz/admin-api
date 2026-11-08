<?php

namespace App\Services;

use Illuminate\Support\Str;

class SupportVideoResource
{
    public function buscar(string $consulta): array
    {
        preg_match_all('/[a-z0-9]+(?:\.[0-9]+)?/', $this->normalizar(mb_substr($consulta, 0, 300)), $coincidencias);
        $palabras = array_diff(array_unique($coincidencias[0]), ['de', 'la', 'el', 'en', 'y', 'del', 'una', 'un', 'para', 'con', 'los', 'las']);
        if ($palabras === []) {
            return [];
        }

        $videos = app(RecursosCatalogoService::class)->catalogo()['videos'];
        $resultados = [];
        foreach ($videos as $video) {
            if (empty($video['url']) || ! preg_match('/^T\d+\.\d+$/D', $video['codigo'])) {
                continue;
            }
            $titulo = $this->normalizar($video['titulo']);
            $uso = $this->normalizar((string) $video['para_que']);
            $puntaje = in_array(strtolower($video['codigo']), $palabras, true) ? 100 : 0;
            foreach ($palabras as $palabra) {
                $puntaje += strpos($titulo, $palabra) !== false ? 4 : (strpos($uso, $palabra) !== false ? 1 : 0);
            }
            if ($puntaje > 0) {
                $resultados[] = ['puntaje' => $puntaje, 'video' => array_intersect_key($video, array_flip(['codigo', 'titulo', 'para_que', 'cuando_no']))];
            }
        }

        usort($resultados, function (array $a, array $b) {
            return $b['puntaje'] <=> $a['puntaje'] ?: strcmp($a['video']['codigo'], $b['video']['codigo']);
        });

        return array_column(array_slice($resultados, 0, 6), 'video');
    }

    public static function enlace(string $codigo): string
    {
        return 'https://recursos.comerciocity.com/?v=' . rawurlencode($codigo);
    }

    private function normalizar(string $texto): string
    {
        return strtolower(Str::ascii($texto));
    }
}
