<?php

namespace App\ModelProperties;

/**
 * Columnas y formulario de `EcommerceVersion` para el SPA (`GET meta/ecommerce_version`): la tabla
 * y el modal del módulo "Versiones de ecommerce" (misión cruzada `versiones-tienda`, 1/10/2026).
 *
 * Mismo formato que `VersionProperties`, recortado a lo que una versión de tienda tiene: código,
 * título, estado, fecha de publicación y descripción. Sin `is_hotfix` ni relaciones.
 */
class EcommerceVersionProperties
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                'key'                     => 'id',
                'text'                    => 'N°',
                'type'                    => 'number',
                'value'                   => null,
                'show'                    => true,
                'exclude_on_update'       => true,
                'use_to_filter_in_search' => true,
                'width'                   => 72,
            ],
            [
                'key'                     => 'version',
                'text'                    => 'Código',
                'type'                    => 'text',
                'value'                   => '',
                'show'                    => true,
                'use_to_filter_in_search' => true,
                'reprecentar_model'       => true,
                'width'                   => 120,
            ],
            [
                'key'                     => 'title',
                'text'                    => 'Título',
                'type'                    => 'text',
                'value'                   => '',
                'show'                    => true,
                'use_to_filter_in_search' => true,
                'width'                   => 220,
                'wrap_content'            => true,
            ],
            [
                'key'                     => 'status',
                'text'                    => 'Estado',
                'type'                    => 'select',
                'value'                   => 'published',
                'show'                    => true,
                'use_to_filter_in_search' => true,
                'width'                   => 120,
                'options'                 => [
                    ['value' => 'draft', 'text' => 'Borrador'],
                    ['value' => 'published', 'text' => 'Publicada'],
                    ['value' => 'archived', 'text' => 'Archivada'],
                ],
            ],
            [
                'key'               => 'published_at',
                'text'              => 'Publicada el',
                'type'              => 'date',
                'value'             => null,
                'show'              => true,
                'exclude_on_update' => true,
                'width'             => 150,
            ],
            [
                'key'               => 'description',
                'text'              => 'Descripción',
                'type'              => 'textarea',
                'value'             => '',
                'show'              => true,
                'not_show_on_table' => true,
            ],
        ];
    }
}
