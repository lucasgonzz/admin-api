<?php

namespace App\Services;

use App\Models\DemoMedia;
use App\Models\Lead;
use Illuminate\Support\Str;

/** Resuelve siempre las URLs actuales. El plan fija el orden, nunca congela el contenido. */
class DemoChatCatalogoService
{
    public function videos(Lead $lead): array
    {
        $urls = DemoMedia::url_por_slot();
        $videos = [];
        $fuente = DemoCatalogoService::get();
        $ids_vigentes = array_fill_keys(array_column(array_merge((array) ($fuente['clips'] ?? []), (array) ($fuente['videos_sueltos'] ?? [])), 'id'), true);
        foreach (DemoCatalogoService::slots() as $clip) {
            $id = (string) ($clip['id'] ?? '');
            if (! isset($urls[$id]) || ! $this->url_video($urls[$id])) {
                continue;
            }
            $videos['demo:' . $id] = [
                'clave' => 'demo:' . $id, 'origen' => 'demo', 'clip_id' => $id, 'codigo' => null,
                'titulo' => (string) ($clip['titulo'] ?? $id), 'url' => $urls[$id],
                'miniatura_url' => null, 'duracion' => 0, 'descripcion' => (string) ($clip['grupo'] ?? ''),
                'seccion_id' => 'demo:' . ($clip['grupo'] ?? 'Tu recorrido'),
                'seccion' => $this->nombre_seccion((string) ($clip['grupo'] ?? 'Tu recorrido')),
                // El scroll conserva adjuntos históricos, pero no integra el selector de videos.
                'en_catalogo' => isset($ids_vigentes[$id]),
            ];
        }
        // Un plan antiguo sigue siendo útil aunque el clip haya salido del catálogo de hoy.
        foreach ($this->clips_del_plan($lead) as $clip) {
            $id = (string) ($clip['id'] ?? '');
            if (! isset($videos['demo:' . $id]) && isset($urls[$id]) && $this->url_video($urls[$id])) {
                $videos['demo:' . $id] = [
                    'clave' => 'demo:' . $id, 'origen' => 'demo', 'clip_id' => $id, 'codigo' => null,
                    'titulo' => (string) ($clip['titulo'] ?? $id), 'url' => $urls[$id],
                    'miniatura_url' => null, 'duracion' => 0, 'descripcion' => '',
                    'seccion_id' => 'demo:' . ($clip['seccion'] ?? 'Tu recorrido'),
                    'seccion' => $this->nombre_seccion((string) ($clip['seccion'] ?? 'Tu recorrido')),
                    'en_catalogo' => false,
                ];
            }
        }
        $recursos = app(RecursosCatalogoService::class)->catalogo();
        $carpetas = array_column($recursos['carpetas'], 'nombre', 'nro');
        foreach ($recursos['videos'] as $video) {
            if (! $this->url_video((string) $video['url'])) {
                continue;
            }
            $clave = 'recurso:' . $video['codigo'];
            $videos[$clave] = [
                'clave' => $clave, 'origen' => 'recurso', 'clip_id' => null, 'codigo' => $video['codigo'],
                'titulo' => $video['titulo'], 'url' => $video['url'], 'miniatura_url' => $video['miniatura_url'],
                'duracion' => (int) ceil($video['duracion']), 'descripcion' => (string) $video['para_que'],
                'seccion_id' => 'recurso:' . $video['carpeta'],
                'seccion' => (string) ($carpetas[$video['carpeta']] ?? 'Centro de recursos'),
                'en_catalogo' => true,
            ];
        }

        // Se cuenta después de filtrar: el código global y los videos sin publicar no
        // determinan la posición que el lead puede recorrer en esta sección.
        $vigentes = array_filter($videos, function ($video) { return $video['en_catalogo']; });
        $cantidades = array_count_values(array_column($vigentes, 'seccion_id'));
        $posiciones = [];
        foreach ($videos as &$video) {
            if (! $video['en_catalogo']) { unset($video['en_catalogo']); continue; }
            unset($video['en_catalogo']);
            $seccion = $video['seccion_id'];
            $posiciones[$seccion] = ($posiciones[$seccion] ?? 0) + 1;
            $video['posicion'] = $posiciones[$seccion];
            $video['cantidad'] = $cantidades[$seccion];
        }
        unset($video);
        return $videos;
    }

    public function clips_del_plan(Lead $lead): array
    {
        $clips = [];
        foreach ((array) (($lead->demo_plan ?? [])['secciones'] ?? []) as $seccion) {
            foreach ((array) ($seccion['clips'] ?? []) as $clip) {
                if (($clip['tipo'] ?? 'nucleo') === 'nucleo') {
                    $clip['seccion'] = $clip['seccion'] ?? $seccion['id'] ?? $seccion['nombre'] ?? 'Tu recorrido';
                    $clips[] = $clip;
                }
            }
        }
        return $clips;
    }

    public function siguiente(Lead $lead, array $vistos, array $ofrecidos = []): ?array
    {
        $videos = $this->videos($lead);
        foreach ($this->clips_del_plan($lead) as $clip) {
            $clave = 'demo:' . $clip['id'];
            if (! isset($vistos[$clave]) && ! isset($ofrecidos[$clave]) && isset($videos[$clave])) {
                return $videos[$clave];
            }
        }
        return null;
    }

    /** Recuperación determinista: títulos y propósito pesan más que una mención en transcripción. */
    public function buscar(Lead $lead, string $pregunta): array
    {
        $palabras = array_diff($this->palabras($pregunta), ['que', 'como', 'para', 'con', 'una', 'por', 'del', 'los', 'las', 'quiero', 'puedo', 'tenes', 'video', 'ver', 'mis', 'tus', 'sus']);
        $indice = app(RecursosCatalogoService::class)->indice();
        $encontrados = [];
        foreach ($this->videos($lead) as $video) {
            $titulo = $this->palabras($video['titulo']);
            $proposito = $this->palabras($video['descripcion']);
            $transcripcion = $video['origen'] === 'recurso' ? (string) ($indice[$video['codigo']] ?? '') : '';
            // El título nombra la tarea mostrada. Una mención incidental en una transcripción
            // larga no debe desplazarla, sin importar si viene de la demo o de recursos.
            $puntos = 10 * count(array_intersect($palabras, $titulo))
                + 3 * count(array_intersect($palabras, $proposito))
                + min(2, count(array_intersect($palabras, $this->palabras($transcripcion))));
            if ($puntos > 0) {
                $video['puntaje'] = $puntos;
                $video['transcripcion'] = mb_substr($transcripcion, 0, 6500);
                $encontrados[] = $video;
            }
        }
        usort($encontrados, function ($a, $b) { return $b['puntaje'] <=> $a['puntaje']; });
        return array_slice($encontrados, 0, 8);
    }

    private function palabras(string $texto): array
    {
        $palabras = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($texto)));
        $palabras = array_filter($palabras, function ($p) { return strlen($p) > 2; });
        return array_values(array_unique(array_map(function ($p) {
            // cliente/clientes comparten señal; no buscar substrings como «IA» dentro de otra palabra.
            return strlen($p) > 4 && substr($p, -1) === 's' ? substr($p, 0, -1) : $p;
        }, $palabras)));
    }

    private function url_video(string $url): bool
    {
        $partes = parse_url($url);
        return is_array($partes) && strtolower($partes['scheme'] ?? '') === 'https'
            && ! empty($partes['host']) && ! isset($partes['user']) && ! isset($partes['pass'])
            && preg_match('~\.(mp4|webm|m3u8)$~i', $partes['path'] ?? '') === 1;
    }

    private function nombre_seccion(string $seccion): string
    {
        return preg_replace('/^S\d+\s*[-–·]\s*/u', '', $seccion);
    }
}
