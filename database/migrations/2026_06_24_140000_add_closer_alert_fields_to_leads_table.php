<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de trazabilidad para el flujo de alerta "Tomar llamada" del closer.
 *
 * Registra los cuatro momentos clave del flujo:
 * 1. Cuándo se disparó la alerta (modal + WhatsApp al closer).
 * 2. Cuándo el closer aceptó la alerta y recibió el link de Meet.
 * 3. Cuándo se avisó al lead que el closer se demoró.
 * 4. Cuándo el sistema decidió reagendar por no-aparición del closer.
 */
class AddCloserAlertFieldsToLeadsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega los cuatro timestamps de trazabilidad.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Timestamp cuando se disparó la alerta inicial al closer (modal + WhatsApp).
            $table->timestamp('closer_alert_sent_at')->nullable()->after('closer_notified_at');

            // Timestamp cuando el closer aceptó la alerta (tocó "Tomar llamada" en el modal).
            $table->timestamp('closer_alert_accepted_at')->nullable()->after('closer_alert_sent_at');

            // Timestamp cuando se envió al lead el aviso de demora del closer.
            $table->timestamp('closer_delay_message_sent_at')->nullable()->after('closer_alert_accepted_at');

            // Timestamp cuando el sistema decidió reagendar por no-aparición del closer.
            $table->timestamp('closer_no_show_rescheduled_at')->nullable()->after('closer_delay_message_sent_at');
        });
    }

    /**
     * Revierte la migración: elimina los cuatro timestamps.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'closer_alert_sent_at',
                'closer_alert_accepted_at',
                'closer_delay_message_sent_at',
                'closer_no_show_rescheduled_at',
            ]);
        });
    }
}
