<?php

namespace App\Services\Prueba;

use App\Models\AdminSetting;

/** Catálogo propio de la prueba: nunca importa planes, cierres ni videos históricos de la demo. */
class PruebaVideoCatalogoService
{
    const PIEZAS = [
        '04' => ['titulo' => 'Bienvenida y recorrido del sistema', 'duracion' => 230,
            'descripcion' => 'Un mapa del sistema para elegir qué explorar durante la prueba.'],
        '05' => ['titulo' => 'Importá tu catálogo', 'duracion' => 72,
            'descripcion' => 'Preparar los artículos del negocio y conocer las opciones de importación.'],
        '06' => ['titulo' => 'Revisá y cambiá un precio', 'duracion' => 65,
            'descripcion' => 'Revisar costo, margen y precio de un artículo antes de guardar el cambio.'],
        '07' => ['titulo' => 'Cargá una compra con una foto', 'duracion' => 88,
            'descripcion' => 'Escanear una compra desde Compras y revisar el resultado antes de confirmar.'],
        '08' => ['titulo' => 'Hacé tu primera venta', 'duracion' => 79,
            'descripcion' => 'Preparar y guardar una venta de prueba y revisar lo guardado en Ventas.'],
        '09' => ['titulo' => 'Probá al agente con tu catálogo', 'duracion' => 42,
            'descripcion' => 'Probar en pantalla cómo responde el agente con el catálogo preparado.'],
    ];

    public function videos(): array
    {
        $videos = [];
        foreach (self::PIEZAS as $codigo => $pieza) {
            $url = trim((string) AdminSetting::get(self::key($codigo), ''));
            if (! $this->url_video($url)) {
                continue;
            }

            $clave = 'prueba:' . $codigo;
            $videos[$clave] = [
                'clave' => $clave, 'origen' => 'prueba', 'clip_id' => null, 'codigo' => $codigo,
                'titulo' => $pieza['titulo'], 'url' => $url, 'miniatura_url' => null,
                'duracion' => $pieza['duracion'], 'descripcion' => $pieza['descripcion'],
                'seccion_id' => 'prueba:practicas', 'seccion' => 'Tu prueba',
                'posicion' => count($videos) + 1,
            ];
        }

        foreach ($videos as &$video) {
            $video['cantidad'] = count($videos);
        }
        unset($video);

        return $videos;
    }

    public static function key(string $codigo): string
    {
        return 'prueba_video_guia_' . $codigo . '_url';
    }

    private function url_video(string $url): bool
    {
        $partes = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($partes)
            && strtolower($partes['scheme'] ?? '') === 'https'
            && ! empty($partes['host']) && ! isset($partes['user']) && ! isset($partes['pass'])
            && preg_match('~\.(mp4|webm|m3u8)$~i', $partes['path'] ?? '') === 1;
    }
}
