<?php

namespace App\ModelProperties;

/**
 * Propiedades declarativas del recurso ProtocolEntry para admin-spa/meta.
 */
class ProtocolEntryProperties
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            [
                'group_title' => 'Protocolo',
            ],
            [
                'key' => 'categoria',
                'text' => 'Categoría',
                'type' => 'select',
                'value' => 'etapa_principal',
                'show' => true,
                'options' => [
                    ['value' => 'etapa_principal', 'text' => 'Etapa principal'],
                    ['value' => 'seguimiento', 'text' => 'Seguimiento'],
                    ['value' => 'situacion_frecuente', 'text' => 'Situación frecuente'],
                ],
            ],
            [
                'key' => 'estado_aplicable',
                'text' => 'Estado del lead',
                'type' => 'select',
                'value' => null,
                'show' => true,
                'options' => [
                    ['value' => '', 'text' => 'Todos'],
                    ['value' => 'nuevo', 'text' => 'Nuevo'],
                    ['value' => 'contactado', 'text' => 'Contactado'],
                    ['value' => 'calificado', 'text' => 'Calificado'],
                    ['value' => 'demo_agendada', 'text' => 'Demo agendada'],
                    ['value' => 'demo_realizada', 'text' => 'Demo realizada'],
                    ['value' => 'mail2_enviado', 'text' => 'Mail2 enviado'],
                ],
            ],
            [
                'key' => 'followup_numero',
                'text' => 'N° seguimiento',
                'type' => 'number',
                'value' => null,
            ],
            [
                'key' => 'titulo',
                'text' => 'Título',
                'type' => 'text',
                'value' => '',
                'show' => true,
            ],
            [
                'key' => 'descripcion',
                'text' => 'Descripción',
                'type' => 'textarea',
                'value' => '',
            ],
            [
                'key' => 'mensaje_template',
                'text' => 'Mensaje template',
                'type' => 'textarea',
                'value' => '',
            ],
            [
                'key' => 'notas_setter',
                'text' => 'Notas setter',
                'type' => 'textarea',
                'value' => '',
            ],
            [
                'key' => 'activa',
                'text' => 'Activa',
                'type' => 'checkbox',
                'value' => true,
                'show' => true,
            ],
        ];
    }
}
