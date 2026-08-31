<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas de DemoInstallation para admin-spa.
 *
 * Mismo contrato que DemoUpdateProperties y ClientVersionUpgradeProperties: columnas de la grilla,
 * campos del modal y filtros de búsqueda.
 */
class DemoInstallationProperties
{
    /**
     * Retorna la definición completa de campos del recurso demo-installation.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                // Identificador numérico del registro; solo lectura.
                'key'                     => 'id',
                'text'                    => 'N°',
                'type'                    => 'number',
                'value'                   => null,
                'only_show'               => true,
                'exclude_on_update'       => true,
                'use_to_filter_in_search' => true,
                'width'                   => 64,
            ],
            [
                // Demo que se instala; se muestra la URL del SPA como label, igual que en
                // DemoUpdateProperties, para que las dos grillas del módulo se lean igual.
                'key'                     => 'demo_id',
                'text'                    => 'Demo',
                'type'                    => 'select',
                'relation'                => 'demo',
                'relation_label'          => 'erp_spa_url',
                'value'                   => null,
                'show'                    => true,
                'use_to_filter_in_search' => true,
                'width'                   => 250,
                'wrap_content'            => true,
            ],
            [
                // Versión que se instala: es el tag que se compila y se empaqueta en el VPS.
                'key'            => 'version_id',
                'text'           => 'Versión a instalar',
                'type'           => 'select',
                'relation'       => 'version',
                'relation_label' => 'version',
                'value'          => null,
                'show'           => true,
                'width'          => 130,
            ],
            [
                // Estado del pipeline.
                //
                // ⚠️ Los valores son los de client_installations (pendiente | instalando |
                // completada | fallida), NO los de demo_updates (… | ejecutandose | completado |
                // fallido). Están escritos así a propósito y tienen que coincidir letra por letra
                // con las constantes de DemoInstallation: el panel compara strings.
                'key'                     => 'status',
                'text'                    => 'Estado',
                'type'                    => 'select',
                'value'                   => 'pendiente',
                'show'                    => true,
                'only_show'               => true,
                'use_to_filter_in_search' => true,
                'width'                   => 150,
                'options'                 => [
                    ['value' => 'pendiente',  'text' => 'Pendiente'],
                    ['value' => 'instalando', 'text' => 'Instalando'],
                    ['value' => 'completada', 'text' => 'Completada'],
                    ['value' => 'fallida',    'text' => 'Fallida'],
                ],
            ],
            [
                // Motivo del fallo; solo lectura y fuera de la grilla (es un texto largo).
                'key'       => 'failure_reason',
                'text'      => 'Motivo del fallo',
                'type'      => 'text',
                'value'     => null,
                'only_show' => true,
                'show'      => false,
                'width'     => 300,
            ],
            [
                // Timestamp de inicio del pipeline; solo lectura.
                'key'       => 'started_at',
                'text'      => 'Iniciada',
                'type'      => 'date',
                'value'     => null,
                'only_show' => true,
                'width'     => 150,
            ],
            [
                // Timestamp de finalización (haya salido bien o mal); solo lectura.
                'key'       => 'finished_at',
                'text'      => 'Finalizada',
                'type'      => 'date',
                'value'     => null,
                'only_show' => true,
                'width'     => 150,
            ],
            /*
             * NO agregar acá ninguna propiedad para el LOG ni para `env_manual_values`.
             *
             * El log se renderiza exclusivamente en la pestaña Operaciones del modal (admin-spa),
             * que lo lee de la relación `logs` del modelo completo que devuelve
             * GET /demo-installation/{id} y lo muestra parseado, con timestamps y colores por
             * nivel. Declararlo acá lo hace renderizar TAMBIÉN como un textarea de texto plano
             * arriba del modal: decenas de miles de caracteres de salida cruda de webpack, con la
             * consola buena enterrada varias pantallas más abajo. Es exactamente lo que pasó con
             * DemoUpdate el 13/7/2026, y por eso DemoUpdateProperties tampoco lo declara — ni
             * ClientVersionUpgradeProperties.
             *
             * `env_manual_values` queda afuera por el mismo tipo de razón más una propia: es un
             * JSON con las credenciales de la base de la demo, y el meta lo pintaría como un
             * campo de texto con la contraseña en claro en la grilla.
             */
        ];
    }
}
