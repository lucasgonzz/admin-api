<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag notify_verificacion_agendamiento_whatsapp al modelo Admin.
 *
 * Separado de notify_verificacion_whatsapp (que sigue siendo solo para el motivo "error",
 * ej. fallback de disponibilidad): este nuevo flag es específico para cuando un mensaje
 * requiere verificación porque el lead está en el tramo de coordinación de agenda
 * (solicita_disponibilidad..demo_pendiente_de_terminar) — decisión de negocio, no error.
 * El aviso "de ruido" para este motivo es push (siempre); este flag es el WhatsApp opcional
 * adicional que cada admin activa si lo quiere.
 */
class AddNotifyVerificacionAgendamientoWhatsappToAdminsTable extends Migration
{
    /**
     * Agrega la columna boolean después de notify_verificacion_whatsapp.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('notify_verificacion_agendamiento_whatsapp')
                  ->default(false)
                  ->after('notify_verificacion_whatsapp')
                  ->comment('Recibir WhatsApp (además del push) cuando un mensaje requiere verificación por estar el lead coordinando agenda, no por error.');
        });
    }

    /**
     * Elimina la columna de notificación de verificación de agendamiento.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('notify_verificacion_agendamiento_whatsapp');
        });
    }
}
