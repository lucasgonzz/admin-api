<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas del recurso Demo para admin-spa.
 */
class DemoProperties
{
    /**
     * Esquema de campos/columnas para listado y modal CRUD.
     *
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
                'use_to_filter_in_search' => true,
                'width' => 64,
            ],
            [
                'group_title' => 'Demo',
            ],
            [
                'key' => 'erp_spa_url',
                'text' => 'ERP SPA URL',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 220,
                'wrap_content' => true,
                'reprecentar_model'     => true,
            ],
            [
                'key' => 'erp_api_url',
                'text' => 'ERP API URL',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 220,
                'wrap_content' => true,
            ],
            [
                'key' => 'ecommerce_spa_url',
                'text' => 'Ecommerce SPA URL',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 230,
                'wrap_content' => true,
            ],
            [
                'key' => 'ecommerce_api_url',
                'text' => 'Ecommerce API URL',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 230,
                'wrap_content' => true,
            ],
            [
                'key' => 'uuid',
                'text' => 'UUID',
                'type' => 'text',
                'value' => '',
                'only_show' => true,
            ],
        ];
    }
}
