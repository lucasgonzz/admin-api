<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas de Client (admin).
 */
class ClientProperties
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            // [
            //     'key' => 'id',
            //     'text' => 'N°',
            //     'type' => 'number',
            //     'value' => null,
            //     'only_show' => true,
            //     'exclude_on_update' => true,
            //     'use_to_filter_in_search' => true,
            //     'width' => 64,
            // ],
            [
                'key' => 'user_id',
                'text' => 'ID ComercioCity',
                'type' => 'number',
                'value' => null,
                // 'only_show' => true,
                'exclude_on_update' => true,
                'width' => 64,
            ],
            [
                'key' => 'name',
                'text' => 'Nombre',
                'type' => 'text',
                'value' => '',
                'use_to_filter_in_search' => true,
                'width' => 200,
                'show' => true,
            ],
            [
                'key' => 'company_name',
                'text' => 'Empresa',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 200,
            ],
            [
                'key' => 'phone',
                'text' => 'Teléfono',
                'type' => 'text',
                'value' => '',
                'use_to_filter_in_search' => true,
                'width' => 140,
            ],
            [
                'key' => 'client_employees',
                'text' => 'Empleados',
                'type' => 'has_many',
                'width' => 220,
                'wrap_content' => true,
                'full_width' => true,
                'not_persisted_on_model' => true,
                'has_many' => [
                    'model_name' => 'client_employee',
                    'text' => 'Empleado',
                    'supports_temporal_children' => true,
                    'api_store_path' => 'client-employee',
                    'parent_route_key' => 'id',
                    'child_route_key' => 'uuid',
                    'api_create_path' => 'client/{parent}/employees',
                    'api_update_path' => 'client/{parent}/employees/{child}',
                    'api_delete_path' => 'client/{parent}/employees/{child}',
                ],
            ],
            // [
            //     'key' => 'slug',
            //     'text' => 'Slug',
            //     'type' => 'text',
            //     'value' => '',
            //     'show' => true,
            //     'use_to_filter_in_search' => true,
            //     'width' => 120,
            // ],
            [
                'key' => 'client_apis',
                'text' => 'APIs del cliente',
                'type' => 'has_many',
                'width' => 220,
                'wrap_content' => true,
                'full_width' => true,
                'not_persisted_on_model' => true,
                'has_many' => [
                    'model_name' => 'client_api',
                    'text' => 'API',
                    'supports_temporal_children' => true,
                    'api_store_path' => 'client-api',
                    'parent_route_key' => 'uuid',
                    'child_route_key' => 'uuid',
                    'api_create_path' => 'client/{parent}/apis',
                    'api_update_path' => 'client/{parent}/apis/{child}',
                    'api_delete_path' => 'client/{parent}/apis/{child}',
                ],
            ],
            [
                'key' => 'active_client_api_id',
                'text' => 'API activa',
                'type' => 'select',
                'relation' => 'active_client_api',
                'relation_label' => 'url',
                'from_has_many' => [
                    'collection_key' => 'client_apis',
                    'label_key' => 'url',
                ],
                'value' => null,
                'width' => 220,
                'wrap_content' => true,
            ],
            // [
            //     'key' => 'api_key',
            //     'text' => 'API key',
            //     'type' => 'text',
            //     'value' => '',
            //     'show' => false,
            //     'not_show_on_table' => true,
            // ],
            // [
            //     'key' => 'inbound_api_key',
            //     'text' => 'Inbound key',
            //     'type' => 'text',
            //     'value' => '',
            //     'show' => false,
            //     'not_show_on_table' => true,
            // ],
            [
                'key' => 'uuid',
                'text' => 'Id',
                'type' => 'text',
                'value' => '',
                // 'only_show' => true,
                'not_show_on_table' => true,
            ],
            [
                'key' => 'current_version_id',
                'text' => 'Versión actual',
                'type' => 'select',
                'relation' => 'version',
                'relation_label' => 'version',
                'value' => null,
                'use_to_filter_in_search' => true,
                'width' => 140,
            ],
            [
                'key' => 'is_active',
                'text' => 'Activo',
                'type' => 'checkbox',
                'value' => true,
                // 'show' => true,
                'width' => 80,
            ],
        ];
    }
}
