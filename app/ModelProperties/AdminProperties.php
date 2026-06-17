<?php

namespace App\ModelProperties;

/**
 * Perfil básico de admin (me, preferencias; no usado en tablas CRUD principales del primer sprint).
 */
class AdminProperties
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                'key' => 'id',
                'text' => 'N°',
                'type' => 'number',
                'value' => null,
                'show' => true,
                'exclude_on_update' => true,
                'width' => 64,
            ],
            [
                'key' => 'name',
                'text' => 'Nombre',
                'type' => 'text',
                'value' => '',
                'show' => true,
            ],
            [
                'key' => 'email',
                'text' => 'Email',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'exclude_on_update' => true,
            ],
            [
                // Flag que indica si el admin actúa como closer en demos.
                'key'   => 'is_closer',
                'text'  => 'Es closer',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
            ],
        ];
    }
}
