<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTestModeToWhatsappConfigTable extends Migration
{
    /**
     * Agrega el flag `test_mode`: cuando está activo, los mensajes salientes NO se envían
     * por WhatsApp pero el resto del flujo (sugerencias de Claude, guardado de mensajes,
     * simulación de leads) sigue funcionando normalmente.
     */
    public function up()
    {
        Schema::table('whatsapp_config', function (Blueprint $table) {
            // Modo de prueba: true corta el envío real por WhatsApp sin afectar el resto del pipeline.
            $table->boolean('test_mode')->default(false)->after('is_active');
        });
    }

    /**
     * Revierte el agregado del flag `test_mode`.
     */
    public function down()
    {
        Schema::table('whatsapp_config', function (Blueprint $table) {
            $table->dropColumn('test_mode');
        });
    }
}
