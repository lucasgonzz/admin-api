<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna admin_notifications a lead_messages.
 *
 * Almacena como JSON los eventos de notificación a admins que se dispararon
 * al procesar este mensaje (escalación, demo agendada, ingreso confirmado, etc.)
 * y los nombres de los admins efectivamente notificados en cada evento.
 * Queda null si el mensaje no generó ninguna notificación a admins.
 */
class AddAdminNotificationsToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna nullable admin_notifications al final de la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            /* JSON con los eventos de notificación a admins disparados por este mensaje. */
            $table->text('admin_notifications')->nullable();
        });
    }

    /**
     * Revierte la columna admin_notifications.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('admin_notifications');
        });
    }
}
