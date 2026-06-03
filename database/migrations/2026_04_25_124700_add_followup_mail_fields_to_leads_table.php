<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a la tabla `leads` los campos de trazabilidad para el envío
 * del mail de seguimiento comercial posterior a la reunión.
 */
class AddFollowupMailFieldsToLeadsTable extends Migration
{
    /**
     * Ejecuta la migración agregando las columnas de auditoría del follow-up.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Fecha/hora del último envío exitoso del mail de seguimiento.
            $table->timestamp('followup_mail_sent_at')
                  ->nullable()
                  ->after('presentation_mail_sent_at');

            // Mensaje del último error ocurrido al intentar enviar seguimiento.
            $table->text('followup_mail_last_error')
                  ->nullable()
                  ->after('presentation_mail_last_error');
        });
    }

    /**
     * Revierte la migración eliminando las columnas agregadas en up().
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'followup_mail_sent_at',
                'followup_mail_last_error',
            ]);
        });
    }
}
