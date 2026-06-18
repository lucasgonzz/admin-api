<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega phone_number al modelo Admin.
 *
 * Usado primero para notificar al closer por WhatsApp (vía Kapso) cuando
 * un lead confirma demo real. Formato esperado: E.164 (+549...), igual
 * que el resto de los números manejados por WhatsappSendService.
 */
class AddPhoneNumberToAdminsTable extends Migration
{
    /**
     * Agrega la columna phone_number a la tabla admins.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            // Nullable: no todos los admins necesitan recibir WhatsApp.
            $table->string('phone_number')->nullable()->after('is_closer');
        });
    }

    /**
     * Revierte la columna phone_number de admins.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
}
