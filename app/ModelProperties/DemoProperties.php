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
                'key' => 'nombre',
                'text' => 'Nombre del comercio',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 180,
                'placeholder' => 'ej: Tienda Demo 3',
                'description' => 'Nombre a mostrar del comercio de esta demo. Es el equivalente de «Razón social» en un cliente: va como APP_NAME del .env de la tienda, nombre de la PWA y título del sitio. Si lo dejás vacío se usa el subdominio del ERP (demo3), que funciona pero queda feo en la pestaña del navegador.',
            ],
            [
                'key' => 'user_id',
                'text' => 'ID de comercio (USER_ID)',
                'type' => 'number',
                'value' => null,
                'show' => true,
                'width' => 150,
                'description' => 'EL MISMO NÚMERO que tiene USER_ID en el .env del ERP de esta demo: es el id con el que se crea el usuario dueño de los datos. La tienda lo usa para pedirle su configuración a la API (/api/commerce/{id}). Si no coincide, la tienda queda en blanco sin ningún error.',
            ],
            [
                'key' => 'api_key',
                'text' => 'API key del ERP',
                'type' => 'text',
                'value' => '',
                'show' => false,
                'width' => 180,
                'description' => 'Clave server-to-server con la que el admin le pide a la empresa-api de esta demo el branding (logo, color y descripción) al compilar la tienda. Es el equivalente de la API key de un cliente. Si falta, la instalación no se frena: avisa por log y usa el color por defecto.',
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
                /* Dejó de ser "(informativo)" el 31/8/2026: desde que existe el pipeline de
                 * instalación/actualización del ecommerce de una demo, este desplegable SÍ tiene
                 * efecto — marcarlo como VPS hace que el pipeline se niegue a arrancar. */
                'text' => 'Hosting ecommerce',
                'type' => 'select',
                'value' => 'shared_hosting',
                'show' => true,
                'width' => 170,
                'options' => [
                    ['value' => 'shared_hosting', 'text' => 'Shared hosting'],
                    ['value' => 'vps', 'text' => 'VPS'],
                ],
                'description' => 'Dónde vive el ecommerce de esta demo. Hoy el pipeline solo sabe desplegar en hosting compartido: si la marcás como VPS, la instalación y la actualización del ecommerce se niegan a arrancar con un mensaje explícito, en vez de subir el código al servidor equivocado.',
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
