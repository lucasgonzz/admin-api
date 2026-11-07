<?php

namespace App\Services\Prueba;

use App\Models\AdminSetting;

/** Catálogo propio de la prueba: nunca importa planes, cierres ni videos históricos de la demo. */
class PruebaVideoCatalogoService
{
    /** Tanda aprobada del 7/11/2026, verificada por hash completo. Configurar no autoriza otro material. */
    const URLS_APROBADAS = [
        '04' => 'https://videos.comerciocity.store/landing-prueba/20261107/04-bienvenida-y-recorrido-v8-904bf6fb63ed71ec.mp4',
        '05' => 'https://videos.comerciocity.store/landing-prueba/20261107/05-importa-tu-catalogo-v4-5c3ff8667f590302.mp4',
        '06' => 'https://videos.comerciocity.store/landing-prueba/20261107/06-revisa-y-cambia-un-precio-v4-0f33edc6012184fc.mp4',
        '07' => 'https://videos.comerciocity.store/landing-prueba/20261107/07-carga-una-compra-con-una-foto-v4-b7ab810b35ab2a1d.mp4',
        '08' => 'https://videos.comerciocity.store/landing-prueba/20261107/08-hace-tu-primera-venta-v4-6bf28a33ad57bee8.mp4',
        '09' => 'https://videos.comerciocity.store/landing-prueba/20261107/09-proba-al-agente-con-tu-catalogo-v4-afa99edf54562d4f.mp4',
    ];

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
            if ($url !== self::URLS_APROBADAS[$codigo]) {
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

}
