<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía `admin_settings.value` para plantillas largas (mensajes WhatsApp de leads, etc.).
 */
class ChangeAdminSettingsValueToText extends Migration
{
    /**
     * Cambia value de VARCHAR(255) a TEXT.
     */
    public function up()
    {
        Schema::table('admin_settings', function (Blueprint $table) {
            $table->text('value')->change();
        });
    }

    /**
     * Restaura VARCHAR(255) (puede truncar datos si ya hay textos largos).
     */
    public function down()
    {
        Schema::table('admin_settings', function (Blueprint $table) {
            $table->string('value')->change();
        });
    }
}
