<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega dos flags de notificación WhatsApp al modelo Admin.
 *
 * notify_lead_escalation_whatsapp:
 *   Cuando el agente detecta que no puede resolver una conversación de lead
 *   (el lead quiere rever el servicio, hace preguntas fuera del protocolo,
 *   se enoja, pide hablar con un humano) y el sistema escala, se envía un
 *   WhatsApp a todos los admins con este flag activo.
 *
 * notify_demo_scheduled_whatsapp:
 *   Cuando un lead confirma y agenda una demo, se envía un WhatsApp a todos
 *   los admins con este flag activo informando el nombre, teléfono y horario.
 */
class AddNotificationFlagsToAdminsTable extends Migration
{
    /**
     * Agrega las dos columnas boolean después de phone_number.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            /* Flag para recibir WhatsApp cuando el agente escala una conversación de lead. */
            $table->boolean('notify_lead_escalation_whatsapp')
                  ->default(false)
                  ->after('phone_number');

            /* Flag para recibir WhatsApp cuando se agenda una demo. */
            $table->boolean('notify_demo_scheduled_whatsapp')
                  ->default(false)
                  ->after('notify_lead_escalation_whatsapp');
        });
    }

    /**
     * Elimina las dos columnas de notificación.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn([
                'notify_lead_escalation_whatsapp',
                'notify_demo_scheduled_whatsapp',
            ]);
        });
    }
}
