<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas de DemoUpdate para admin-spa.
 *
 * Define columnas de tabla, campos de formulario y filtros de búsqueda
 * siguiendo el mismo contrato que ClientVersionUpgradeProperties.
 */
class DemoUpdateProperties
{
    /**
     * Retorna la definición completa de campos del recurso demo-update.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                // Identificador numérico del registro; solo lectura.
                'key'                    => 'id',
                'text'                   => 'N°',
                'type'                   => 'number',
                'value'                  => null,
                'only_show'              => true,
                'exclude_on_update'      => true,
                'use_to_filter_in_search' => true,
                'width'                  => 64,
            ],
            [
                // Demo objetivo del pipeline; se muestra la URL del SPA como label.
                'key'                    => 'demo_id',
                'text'                   => 'Demo',
                'type'                   => 'select',
                'relation'               => 'demo',
                'relation_label'         => 'erp_spa_url',
                'value'                  => null,
                'show'                   => true,
                'use_to_filter_in_search' => true,
                'width'                  => 250,
                'wrap_content'           => true,
            ],
            [
                // Versión destino a la que se llevará la demo en el pipeline.
                'key'            => 'version_id',
                'text'           => 'Versión destino',
                'type'           => 'select',
                'relation'       => 'version',
                'relation_label' => 'version',
                'value'          => null,
                'show'           => true,
                'width'          => 120,
            ],
            [
                // Estado actual del pipeline de actualización.
                'key'                    => 'status',
                'text'                   => 'Estado',
                'type'                   => 'select',
                'value'                  => 'pendiente',
                'show'                   => true,
                'only_show'              => true,
                'use_to_filter_in_search' => true,
                'width'                  => 150,
                'options'                => [
                    ['value' => 'pendiente',    'text' => 'Pendiente'],
                    ['value' => 'ejecutandose', 'text' => 'Ejecutándose'],
                    ['value' => 'completado',   'text' => 'Completado'],
                    ['value' => 'fallido',      'text' => 'Fallido'],
                ],
            ],
            [
                // Timestamp de inicio de ejecución del job; solo lectura.
                'key'       => 'started_at',
                'text'      => 'Iniciado',
                'type'      => 'date',
                'value'     => null,
                'only_show' => true,
                'width'     => 150,
            ],
            [
                // Timestamp de finalización (exitoso o fallido); solo lectura.
                'key'       => 'finished_at',
                'text'      => 'Finalizado',
                'type'      => 'date',
                'value'     => null,
                'only_show' => true,
                'width'     => 150,
            ],
            [
                // Log acumulado del pipeline; no se muestra en tabla, solo en detalle.
                'key'              => 'log',
                'text'             => 'Log',
                'type'             => 'textarea',
                'value'            => null,
                'only_show'        => true,
                'not_show_on_table' => true,
            ],
        ];
    }
}
