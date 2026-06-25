<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega delay_seconds a message_variants para A/B testing del tiempo entre auto y welcome.
 */
class AddDelaySecondsToMessageVariantsTable extends Migration
{
    /**
     * Agrega columna nullable: null = usar welcome_delay_seconds global en admin_settings.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('message_variants', function (Blueprint $table) {
            /* Delay en segundos entre el mensaje auto y el welcome; null = valor global. */
            $table->unsignedInteger('delay_seconds')->nullable()->after('body');
        });
    }

    /**
     * Elimina la columna delay_seconds de message_variants.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('message_variants', function (Blueprint $table) {
            $table->dropColumn('delay_seconds');
        });
    }
}
