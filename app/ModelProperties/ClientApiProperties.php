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
                'text' => 'Ruta relativa API (SSH)',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 200,
                'placeholder' => 'ej: colman/api',
                'description' => 'Ruta de la API en el hosting compartido, solo el segmento después de public_html/ (no la URL pública). Ejemplo completo SSH: domains/comerciocity.com/public_html/{subdominio}/api. El deploy del SPA usa la misma ruta cambiando /api por /spa. La URL pública del frontend va en «URL SPA».',
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
            [
                'key' => 'vps_path',
                'text' => 'VPS Path',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'width' => 160,
                'placeholder' => 'ej: arfren2',
                'description' => 'Solo para hosting_type=vps. Identificador del cliente en el VPS. API SSH: /home/api-{vps_path}/empresa-api. SPA SSH: /home/{vps_path}/htdocs/{dominio_spa}.',
            ],
        ];
    }
}
