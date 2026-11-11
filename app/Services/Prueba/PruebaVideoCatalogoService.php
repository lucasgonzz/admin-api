<?php

namespace App\Services\Prueba;

use App\Models\AdminSetting;

/** Catálogo propio de la prueba: nunca importa planes, cierres ni videos históricos de la demo. */
class PruebaVideoCatalogoService
{
    /** Tanda aprobada del 11/11/2026 (pizarra animada), verificada por hash completo. Configurar no autoriza otro material. */
    const URLS_APROBADAS = [
        '04' => 'https://videos.comerciocity.store/landing-prueba/20261111/04-bienvenida-y-recorrido-claude-v2-d77600fcb3c9f595.mp4',
        '05' => 'https://videos.comerciocity.store/landing-prueba/20261111/05-importa-tu-catalogo-claude-v1-7e315f5c9f377c3c.mp4',
        '06' => 'https://videos.comerciocity.store/landing-prueba/20261111/06-revisa-y-cambia-un-precio-claude-v1-53fdff8d1ebbabba.mp4',
        '07' => 'https://videos.comerciocity.store/landing-prueba/20261111/07-carga-una-compra-con-una-foto-claude-v1-35e86755dfa2c7e2.mp4',
        '08' => 'https://videos.comerciocity.store/landing-prueba/20261111/08-hace-tu-primera-venta-claude-v1-0348d9fe2f116f4a.mp4',
        '09' => 'https://videos.comerciocity.store/landing-prueba/20261111/09-proba-al-agente-con-tu-catalogo-claude-v1-81faa2a7b4e798a7.mp4',
    ];

    const PIEZAS = [
        '04' => ['titulo' => 'Bienvenida y recorrido del sistema', 'duracion' => 228,
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
