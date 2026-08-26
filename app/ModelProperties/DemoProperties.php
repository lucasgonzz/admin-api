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
                'key' => 'erp_hosting_type',
                'text' => 'Hosting ERP',
                'type' => 'select',
                'value' => 'shared_hosting',
                'show' => true,
                'width' => 150,
                'options' => [
                    ['value' => 'shared_hosting', 'text' => 'Shared hosting'],
                    ['value' => 'vps', 'text' => 'VPS'],
                ],
                'description' => 'Dónde vive el ERP de esta demo. Decide el servidor SSH al que se sube la actualización, cómo se arman los paths remotos y si la URL de la API lleva /public (solo en shared hosting).',
            ],
            [
                'key' => 'erp_vps_path',
                'text' => 'VPS Path ERP',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'width' => 160,
                'placeholder' => 'ej: demo3',
                'description' => 'Solo para Hosting ERP = VPS, y OPCIONAL: si lo dejás vacío se deduce del subdominio de la ERP SPA URL (demo3.comerciocity.com → demo3). Cargalo solo si el sitio en el VPS se llama distinto. API SSH: /home/api-{vps_path}/empresa-api. SPA SSH: /home/{vps_path}/htdocs/{dominio_spa}.',
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
                'key' => 'ecommerce_hosting_type',
                'text' => 'Hosting ecommerce',
                'type' => 'select',
                'value' => 'shared_hosting',
                'show' => true,
                'width' => 170,
                'options' => [
                    ['value' => 'shared_hosting', 'text' => 'Shared hosting'],
                    ['value' => 'vps', 'text' => 'VPS'],
                ],
                'description' => 'Dónde vive el ecommerce de esta demo. Hoy es un dato del catálogo: todavía no existe actualización de ecommerce de demo que lo consuma.',
            ],
            [
                'key' => 'ecommerce_vps_path',
                'text' => 'VPS Path ecommerce',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'width' => 170,
                'placeholder' => 'ej: demo3-tienda',
                'description' => 'Solo para Hosting ecommerce = VPS, y opcional. Mismo criterio que el del ERP.',
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
