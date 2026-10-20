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
        foreach (DemoCatalogoService::slots() as $clip) {
            $id = (string) ($clip['id'] ?? '');
            if (! isset($urls[$id]) || ! $this->url_video($urls[$id])) {
                continue;
            }
            $videos['demo:' . $id] = [
                'clave' => 'demo:' . $id, 'origen' => 'demo', 'clip_id' => $id, 'codigo' => null,
                'titulo' => (string) ($clip['titulo'] ?? $id), 'url' => $urls[$id],
                'miniatura_url' => null, 'duracion' => 0, 'descripcion' => (string) ($clip['grupo'] ?? ''),
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
                ];
            }
        }
        foreach (app(RecursosCatalogoService::class)->catalogo()['videos'] as $video) {
            if (! $this->url_video((string) $video['url'])) {
                continue;
            }
            $clave = 'recurso:' . $video['codigo'];
            $videos[$clave] = [
                'clave' => $clave, 'origen' => 'recurso', 'clip_id' => null, 'codigo' => $video['codigo'],
                'titulo' => $video['titulo'], 'url' => $video['url'], 'miniatura_url' => $video['miniatura_url'],
                'duracion' => (int) ceil($video['duracion']), 'descripcion' => (string) $video['para_que'],
            ];
        }
        return $videos;
    }

    public function clips_del_plan(Lead $lead): array
    {
        $clips = [];
        foreach ((array) (($lead->demo_plan ?? [])['secciones'] ?? []) as $seccion) {
            foreach ((array) ($seccion['clips'] ?? []) as $clip) {
                if (($clip['tipo'] ?? 'nucleo') === 'nucleo') {
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
        $palabras = array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($pregunta))), function ($p) {
            return strlen($p) > 2 && ! in_array($p, ['que', 'como', 'para', 'con', 'una', 'por', 'del', 'los', 'las', 'quiero', 'puedo', 'tenes', 'video', 'ver']);
        }));
        $indice = app(RecursosCatalogoService::class)->indice();
        $encontrados = [];
        foreach ($this->videos($lead) as $video) {
            $titulo = strtolower(Str::ascii($video['titulo'] . ' ' . $video['descripcion']));
            $transcripcion = $video['origen'] === 'recurso' ? (string) ($indice[$video['codigo']] ?? '') : '';
            $texto = strtolower(Str::ascii($transcripcion));
            $puntos = 0;
            foreach ($palabras as $palabra) {
                $puntos += strpos($titulo, $palabra) !== false ? 5 : 0;
                $puntos += strpos($texto, $palabra) !== false ? 1 : 0;
            }
            if ($puntos > 0) {
                $video['puntaje'] = $puntos;
                $video['transcripcion'] = mb_substr($transcripcion, 0, 6500);
                $encontrados[] = $video;
            }
        }
        usort($encontrados, function ($a, $b) { return $b['puntaje'] <=> $a['puntaje']; });
        return array_slice($encontrados, 0, 8);
    }

    private function url_video(string $url): bool
    {
        return preg_match('~^https://~i', $url) === 1 && preg_match('~\.(mp4|webm|m3u8)(\?|$)~i', $url) === 1;
    }
}
