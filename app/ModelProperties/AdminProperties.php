<?php

namespace App\ModelProperties;

/**
 * Perfil básico de admin (me, preferencias; no usado en tablas CRUD principales del primer sprint).
 */
class AdminProperties
{
    /**
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
                'width' => 64,
            ],
            [
                'key' => 'name',
                'text' => 'Nombre',
                'type' => 'text',
                'value' => '',
                'show' => true,
            ],
            [
                'key' => 'email',
                'text' => 'Email',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'exclude_on_update' => true,
            ],
            [
                // Flag que indica si el admin actúa como closer en demos.
                'key'   => 'is_closer',
                'text'  => 'Es closer',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
            ],
            [
                // Teléfono del admin en formato E.164 (+549...) para notificarlo por WhatsApp.
                'key'   => 'phone_number',
                'text'  => 'Teléfono',
                'type'  => 'text',
                'value' => '',
                'show'  => true,
            ],
            [
                // Recibir WhatsApp cuando el agente escala una conversación de lead que no puede resolver.
                'key'   => 'notify_lead_escalation_whatsapp',
                'text'  => 'Notificar escalaciones por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
            ],
            [
                // Recibir WhatsApp cuando se agenda una demo.
                'key'   => 'notify_demo_scheduled_whatsapp',
                'text'  => 'Notificar demos agendadas por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
            ],
            [
                // Recibir WhatsApp cuando falla el envío automático de un mensaje del sistema.
                'key'   => 'notify_send_errors_whatsapp',
                'text'  => 'Notificar errores de envío por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
            ],
        ];
    }
}
