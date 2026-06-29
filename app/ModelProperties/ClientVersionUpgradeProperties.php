<?php

namespace App\ModelProperties;

/**
 * Propiedades de ClientVersionUpgrade (recurso "update" en el SPA).
 *
 * Requiere al menos un `group_title` (sin `key`) para que admin-spa muestre pestañas en el modal:
 * datos básicos del upgrade y operaciones (pasos, seeders, comandos) vía `model_extra_tabs` en Updates.vue.
 */
class ClientVersionUpgradeProperties
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                'group_title' => 'Básico',
            ],
            [
                'key' => 'id',
                'text' => 'N°',
                'type' => 'number',
                'value' => null,
                'only_show' => true,
                'exclude_on_update' => true,
                'use_to_filter_in_search' => true,
                'width' => 64,
            ],
            [
                'key' => 'client_id',
                'text' => 'Cliente',
                'type' => 'select',
                'relation' => 'client',
                'relation_label' => 'company_name',
                'value' => null,
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 200,
            ],
            [
                'key' => 'to_version_id',
                'text' => 'A versión',
                'type' => 'select',
                'relation' => 'version',
                'relation_label' => 'version',
                'value' => null,
                'show' => true,
                'width' => 120,
            ],
            [
                'key' => 'target_client_api_id',
                'text' => 'API destino (deploy / sync)',
                'type' => 'select',
                'relation' => 'target_client_api',
                'relation_label' => 'url',
                'value' => null,
                'show' => true,
                // 'exclude_on_update' => true,
                // 'not_show_on_table' => true,
                'width' => 280,
                'wrap_content' => true,
                /**
                 * Opciones desde las ClientApi del cliente elegido en client_id.
                 * admin-spa: GET /client/{id} y default distinto de active_client_api_id.
                 */
                'from_parent_field' => [
                    'parent_key' => 'client_id',
                    'fetch_resource' => 'client',
                    'collection_key' => 'client_apis',
                    'label_key' => 'url',
                    'default_other_than_active' => true,
                ],
            ],
            [
                'key' => 'from_version_id',
                'text' => 'Desde versión',
                'type' => 'select',
                'relation' => 'version',
                'relation_label' => 'version',
                'value' => null,
                'show' => true,
                'width' => 120,
            ],
            [
                'key' => 'status',
                'text' => 'Estado',
                'type' => 'select',
                'value' => 'pendiente',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 180,
                'options' => [
                    ['value' => 'pendiente', 'text' => 'Pendiente'],
                    ['value' => 'listo_para_actualizar', 'text' => 'Listo para actualizar'],
                    ['value' => 'actualizandose', 'text' => 'Actualizándose'],
                    ['value' => 'terminada', 'text' => 'Terminada'],
                    ['value' => 'fallida', 'text' => 'Fallida'],
                ],
            ],
            [
                'key'   => 'scheduled_date',
                'text'  => 'Fecha programada',
                'type'  => 'date',
                'value' => \Carbon\Carbon::now()->toDateString(),
                'show'  => true,
                'width' => 150,
            ],
            [
                'key' => 'notes',
                'text' => 'Notas',
                'type' => 'textarea',
                'value' => '',
                'show' => false,
                'not_show_on_table' => true,
            ],
            [
                'key' => 'synced_at',
                'text' => 'Sincronizado',
                'type' => 'date',
                'value' => null,
                'only_show' => true,
                'width' => 150,
            ],
            [
                'key' => 'finished_at',
                'text' => 'Finalizado',
                'type' => 'date',
                'value' => null,
                'only_show' => true,
                'width' => 150,
            ],
        ];
    }
}
