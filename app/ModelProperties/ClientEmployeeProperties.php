<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas de ClientEmployee (hijo has_many de Client).
 */
class ClientEmployeeProperties
{
    /**
     * Esquema de campos para meta, tabla y formulario anidado.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                'key' => 'name',
                'text' => 'Nombre',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 180,
            ],
            [
                'key' => 'phone',
                'text' => 'Teléfono',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 140,
            ],
            [
                'key' => 'notes',
                'text' => 'Notas',
                'type' => 'textarea',
                'value' => '',
                'show' => true,
                'full_width' => true,
                'wrap_content' => true,
                'width' => 220,
            ],
        ];
    }
}
