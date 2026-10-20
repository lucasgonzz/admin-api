<?php

namespace App\Services;

/** Slugs del contrato de demo. El rubro comercial libre del lead conserva su propio campo. */
class DemoIndustriaCatalogo
{
    const DEFAULT = 'ferreteria';

    const OPCIONES = [
        'ferreteria' => 'Ferretería y herrajes',
        'indumentaria' => 'Indumentaria y accesorios',
        'autopartes' => 'Autopartes y repuestos',
        'jugueteria' => 'Juguetería',
        'construccion' => 'Materiales de construcción',
        'bebidas' => 'Bebidas y vinoteca',
        'mascotas' => 'Mascotas',
        'limpieza' => 'Limpieza y descartables',
        'belleza' => 'Belleza y productos capilares',
        'almacen' => 'Almacén, kiosco y dietética',
        'pesca' => 'Pesca y aire libre',
        'hogar' => 'Hogar y electrodomésticos',
        'tecnologia' => 'Tecnología e impresión 3D',
        'distribuidora' => 'Distribuidora y comercio general',
    ];

    public static function normalizar($valor): string
    {
        return isset(self::OPCIONES[(string) $valor]) ? (string) $valor : self::DEFAULT;
    }

    public static function regla(): string
    {
        return 'sometimes|string|in:' . implode(',', array_keys(self::OPCIONES));
    }
}
