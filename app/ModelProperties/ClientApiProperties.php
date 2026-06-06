<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas de ClientApi (hijo has_many de Client).
 */
class ClientApiProperties
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
                'key' => 'url',
                'text' => 'URL API',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 220,
                'wrap_content' => true,
            ],
            [
                'key' => 'path',
                'text' => 'Path servidor (SSH)',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 160,
            ],
            [
                'key' => 'spa_url',
                'text' => 'URL SPA',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'width' => 200,
                'wrap_content' => true,
            ],
            [
                'key' => 'hosting_type',
                'text' => 'Hosting',
                'type' => 'select',
                'value' => 'shared_hosting',
                'show' => true,
                'width' => 140,
                'options' => [
                    ['value' => 'shared_hosting', 'text' => 'Shared hosting'],
                    ['value' => 'vps', 'text' => 'VPS'],
                ],
            ],
        ];
    }
}
