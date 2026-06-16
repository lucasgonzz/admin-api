<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega flag test_mode a whatsapp_config para omitir envíos reales por Kapso/Meta.
 */
class AddTestModeToWhatsappConfigTable extends Migration
{
    /**
     * Agrega columna test_mode después de is_active.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('whatsapp_config', function (Blueprint $table) {
            // Cuando es true, WhatsappSendService no envía mensajes pero el resto del flujo sigue igual.
            $table->boolean('test_mode')->default(false)->after('is_active');
        });
    }

    /**
     * Elimina la columna test_mode.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('whatsapp_config', function (Blueprint $table) {
            $table->dropColumn('test_mode');
        });
    }
}
