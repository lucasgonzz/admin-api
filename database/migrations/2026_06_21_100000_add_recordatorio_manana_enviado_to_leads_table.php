<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega flag para evitar enviar el recordatorio de mañana más de una vez por demo agendada.
 *
 * Se resetea a false cada vez que cambia la fecha de la demo, de modo que
 * el nuevo día también reciba su recordatorio automático por WhatsApp.
 */
class AddRecordatorioMananaEnviadoToLeadsTable extends Migration
{
    /**
     * Agrega la columna booleana `recordatorio_manana_enviado` en la tabla `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Flag que indica si ya se envió el recordatorio de mañana para la demo agendada.
            $table->boolean('recordatorio_manana_enviado')->default(false)->after('recordatorio_demo_enviado');
        });
    }

    /**
     * Elimina la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('recordatorio_manana_enviado');
        });
    }
}
