<?php

namespace App\ModelProperties;

/**
 * Perfil básico de admin (me, preferencias; no usado en tablas CRUD principales del primer sprint).
 *
 * Propiedades del modelo Admin expuestas en el endpoint de meta. Cada propiedad incluye:
 * - key: nombre de la columna en base de datos
 * - text: etiqueta legible del campo
 * - type: tipo de input (boolean, text, number)
 * - value: valor por defecto
 * - show: si se muestra en la interfaz
 * - description: texto explicativo en segunda persona (para qué sirve)
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
                'description' => null,
            ],
            [
                'key' => 'name',
                'text' => 'Nombre',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'description' => null,
            ],
            [
                'key' => 'email',
                'text' => 'Email',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'exclude_on_update' => true,
                'description' => null,
            ],
            [
                // Flag que indica si el admin actúa como closer en demos.
                'key'   => 'is_closer',
                'text'  => 'Es closer',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Los closers pueden conectar su Google Calendar para bloquear disponibilidad de demos.',
            ],
            [
                // Flag que identifica al admin como setter: las tareas que se generan a partir
                // de conversaciones de leads se asignan automáticamente a todos los admins con
                // este flag activo.
                'key'   => 'es_setter',
                'text'  => 'Es setter',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Los setters reciben automáticamente todas las tareas que nacen de conversaciones de leads: cuando el agente detecta que una conversación necesita que intervenga una persona, la tarea se le asigna a todos los admins marcados como setter, y a cada uno le aparece el aviso en pantalla. Si no hay ningún setter marcado, esas tareas quedan sin asignar y las puede tomar cualquiera.',
            ],
            [
                // Flag para asignación automática al crear nuevas tareas internas manualmente.
                'key'   => 'is_default_task_assignee',
                'text'  => 'Asignado por defecto en tareas nuevas',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Cuando se crea una tarea a mano desde el panel, los admins con esta opción activa vienen preseleccionados como responsables. Es sólo una preselección: se puede cambiar antes de guardar. No tiene nada que ver con las tareas que genera el agente a partir de conversaciones de leads (para eso está "Es setter").',
            ],
            [
                // Teléfono del admin en formato E.164 (+549...) para notificarlo por WhatsApp.
                'key'   => 'phone_number',
                'text'  => 'Teléfono',
                'type'  => 'text',
                'value' => '',
                'show'  => true,
                'description' => 'En formato E.164 (ej: +5491112345678). Se usa para notificar al closer por WhatsApp cuando una demo se confirma.',
            ],
            [
                // Recibir WhatsApp cuando el agente escala una conversación de lead que no puede resolver.
                'key'   => 'notify_lead_escalation_whatsapp',
                'text'  => 'Notificar escalaciones por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Recibís un WhatsApp cuando el agente no puede resolver algo solo y necesita que un humano intervenga en la conversación (una pregunta fuera de lo habitual, un pedido inusual, una objeción que requiere criterio). También te llega este mismo aviso cuando un lead confirma que terminó la demo y ya está listo para la llamada del closer — comparte este flag con la escalación general.',
            ],
            [
                // Recibir WhatsApp cuando se agenda una demo.
                'key'   => 'notify_demo_scheduled_whatsapp',
                'text'  => 'Notificar demos agendadas por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Recibís un WhatsApp en cada paso del ciclo de una demo agendada: cuando se agenda o reagenda, cuando se manda el aviso de check-in, cuando el lead confirma que pudo ingresar (o avisa que no pudo), y cuando confirma que la terminó.',
            ],
            [
                // Recibir WhatsApp cuando falla el envío automático de un mensaje del sistema.
                'key'   => 'notify_send_errors_whatsapp',
                'text'  => 'Notificar errores de envío por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Recibís un WhatsApp cuando falla el envío automático de un mensaje del sistema (ej. un error de conexión). Para no inundarte si hay una ráfaga de fallos seguidos, como máximo se manda uno cada 10 minutos.',
            ],
            [
                // Recibir WhatsApp cuando una sugerencia del agente queda pendiente de verificación manual
                // por un ERROR (ej. fallback de disponibilidad). No se usa para el motivo "agendamiento".
                'key'   => 'notify_verificacion_whatsapp',
                'text'  => 'Notificar verificación pendiente por error, por WhatsApp',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Recibís un WhatsApp cuando una sugerencia del agente queda pendiente de aprobación manual por un ERROR interno — por ejemplo, no se pudo consultar la disponibilidad del calendario y el agente no puede armar una respuesta confiable sin ayuda. No se activa por estar coordinando agenda (para eso está el checkbox de abajo) — este es solo para cuando algo salió mal.',
            ],
            [
                // Recibir WhatsApp (además del push, que siempre se manda) cuando un mensaje requiere verificación
                // porque el lead está coordinando agenda (motivo de negocio, no error).
                'key'   => 'notify_verificacion_agendamiento_whatsapp',
                'text'  => 'Notificar por WhatsApp cuando un lead está coordinando agenda',
                'type'  => 'boolean',
                'value' => false,
                'show'  => true,
                'description' => 'Desde que un lead entra a coordinar la agenda de la demo hasta que llega a closer activo, todo mensaje del agente requiere tu aprobación antes de salir — no porque haya un error, es el proceso normal para ese tramo. Este aviso ya te llega siempre como notificación push y sonido en el navegador (si lo tenés abierto); activá este checkbox si además querés un WhatsApp.',
            ],
        ];
    }
}
