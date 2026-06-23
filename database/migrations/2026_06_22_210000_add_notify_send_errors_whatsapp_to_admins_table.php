<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag notify_send_errors_whatsapp al modelo Admin.
 *
 * notify_send_errors_whatsapp:
 *   Cuando un envío automático del sistema falla (seguimiento de lead,
 *   recordatorio de demo), se envía un WhatsApp a todos los admins con
 *   este flag activo informando el contexto y el error ocurrido.
 */
class AddNotifySendErrorsWhatsappToAdminsTable extends Migration
{
    /**
     * Agrega la columna boolean después de notify_demo_scheduled_whatsapp.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            /* Flag para recibir WhatsApp cuando falla el envío automático de un mensaje del sistema. */
            $table->boolean('notify_send_errors_whatsapp')
                  ->default(false)
                  ->after('notify_demo_scheduled_whatsapp')
                  ->comment('Recibir WhatsApp cuando falla el envío automático de un mensaje del sistema.');
        });
    }

    /**
     * Elimina la columna de notificación de errores.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('notify_send_errors_whatsapp');
        });
    }
}
