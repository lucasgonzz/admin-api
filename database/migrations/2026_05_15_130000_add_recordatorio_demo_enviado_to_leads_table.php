<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega flag para evitar generar el recordatorio pre-demo más de una vez por demo agendada.
 *
 * Se resetea a false cada vez que se cambia la fecha de la demo, de modo que
 * el nuevo horario también reciba su recordatorio automático.
 */
class AddRecordatorioDemoEnviadoToLeadsTable extends Migration
{
    /**
     * Agrega la columna booleana `recordatorio_demo_enviado` en la tabla `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Flag que indica si ya se generó el mensaje de recordatorio para la demo agendada.
            $table->boolean('recordatorio_demo_enviado')->default(false)->after('tiene_seguimiento_sin_ver');
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
            $table->dropColumn('recordatorio_demo_enviado');
        });
    }
}
