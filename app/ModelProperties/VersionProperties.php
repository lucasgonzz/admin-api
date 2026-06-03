<?php

namespace App\ModelProperties;

/**
 * Definición declarativa de columnas/formulario de Version para el SPA (meta + asignación masiva guiada).
 *
 * Incluir al menos un separador con `group_title` (sin `key`) habilita pestañas en el modal
 * del admin-spa: una para datos de la versión y otra para relaciones (seeders, comandos, etc.)
 * vía `model_extra_tabs` en la vista Versions.
 */
class VersionProperties
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
                'show' => true,
                'exclude_on_update' => true,
                'use_to_filter_in_search' => true,
                'width' => 72,
            ],
            [
                'key' => 'version',
                'text' => 'Código',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'reprecentar_model' => true,
                'width' => 120,
            ],
            [
                'key' => 'title',
                'text' => 'Título',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 200,
                'wrap_content' => true,
            ],
            [
                'key' => 'status',
                'text' => 'Estado',
                'type' => 'select',
                'value' => 'draft',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 120,
                'options' => [
                    ['value' => 'draft', 'text' => 'Borrador'],
                    ['value' => 'published', 'text' => 'Publicada'],
                    ['value' => 'archived', 'text' => 'Archivada'],
                ],
            ],
            [
                'key' => 'published_at',
                'text' => 'Publicada el',
                'type' => 'date',
                'value' => null,
                'show' => true,
                'width' => 150,
            ],
            [
                'key' => 'description',
                'text' => 'Descripción',
                'type' => 'textarea',
                'value' => '',
                'show' => true,
                'not_show_on_table' => true,
            ],
        ];
    }
}
