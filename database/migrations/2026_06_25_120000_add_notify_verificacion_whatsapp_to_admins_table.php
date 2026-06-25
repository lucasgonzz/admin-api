<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag notify_verificacion_whatsapp al modelo Admin.
 *
 * notify_verificacion_whatsapp:
 *   Cuando una sugerencia del agente queda marcada como requiere_verificacion = true,
 *   se envía un WhatsApp a todos los admins con este flag activo informando que hay
 *   una sugerencia pendiente de aprobación manual para un lead.
 */
class AddNotifyVerificacionWhatsappToAdminsTable extends Migration
{
    /**
     * Agrega la columna boolean después de notify_send_errors_whatsapp.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            /* Flag para recibir WhatsApp cuando una sugerencia queda pendiente de verificación manual. */
            $table->boolean('notify_verificacion_whatsapp')
                  ->default(false)
                  ->after('notify_send_errors_whatsapp')
                  ->comment('Recibir WhatsApp cuando una sugerencia del agente queda pendiente de verificación manual.');
        });
    }

    /**
     * Elimina la columna de notificación de verificaciones pendientes.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('notify_verificacion_whatsapp');
        });
    }
}
