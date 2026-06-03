<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega trazabilidad del "Mail 1 - DEMO" a la tabla leads.
 *
 * Columnas:
 * - demo_mail_sent_at: timestamp del último envío exitoso del mail de demo.
 * - demo_mail_last_error: último mensaje de error al intentar enviar el mail.
 */
class AddDemoMailFieldsToLeadsTable extends Migration
{
    /**
     * Agrega las columnas de trazabilidad del mail de demo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Fecha y hora del último envío exitoso del mail de demo.
            $table->timestamp('demo_mail_sent_at')->nullable()->after('followup_mail_sent_at');

            // Último error registrado al intentar enviar el mail de demo.
            $table->text('demo_mail_last_error')->nullable()->after('demo_mail_sent_at');
        });
    }

    /**
     * Elimina las columnas agregadas por esta migración.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['demo_mail_sent_at', 'demo_mail_last_error']);
        });
    }
}
