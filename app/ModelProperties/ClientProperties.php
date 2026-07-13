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
            /**
             * Separador de grupo: confina TODO el formulario básico (ID, nombre,
             * empleados, APIs, versión, etc.) a su propia pestaña "Básico".
             *
             * Sin al menos un `group_title`, `ModelModal` (common-vue/model/Index.vue)
             * evalúa `has_group_props = false`, y entonces `should_show_group_form`
             * devuelve true SIEMPRE por la rama `!has_group_props && has_extra_tabs`
             * — es decir, el formulario se renderiza encima de cada pestaña extra
             * (Instalaciones / Ecommerce / Mensualidad) en vez de tener la suya.
             * Todas las props que siguen, al no declarar `group_title` propio, caen
             * en este grupo por el fallback `first_group_title`.
             */
            [
                'group_title' => 'Básico',
            ],
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
                'width' => 64,
            ],
            /**
             * FK del grupo de BD compartida.
             * No se edita como select genérico: la UI está en
             * admin-spa SharedDatabaseGroup.vue (extra-props del cliente).
             * Sin `relation` evita el GET /meta/shared_database_group (404).
             */
            [
                'key' => 'shared_database_group_id',
                'text' => 'Grupo BD compartida',
                'type' => 'number',
                'value' => null,
                'show' => false,
                'not_show_on_table' => true,
                'width' => 180,
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
                'show'  => true,
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
                    'sync_from_empresa' => [
                        'button_text' => 'Sincronizar desde empresa',
                        'api_path' => 'client/{parent}/employees/sync-from-empresa',
                    ],
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
                    // Id numérico: evita colisiones si varios clients comparten uuid (p. ej. datos de prueba).
                    'parent_route_key' => 'id',
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
            [
                'key' => 'implementation',
                'text' => 'Implementación',
                'type' => 'custom',
                'custom_component' => 'client_implementation',
                'not_persisted_on_model' => true,
                'not_show_on_table' => true,
                'full_width' => true,
                'value' => null,
            ],
        ];
    }
}
