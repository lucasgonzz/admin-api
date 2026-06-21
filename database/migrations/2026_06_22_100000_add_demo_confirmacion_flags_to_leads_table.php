<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega flags de confirmación conversacional de demo a la tabla leads.
 *
 * - demo_ingreso_confirmado: true cuando el lead confirmó por WhatsApp que pudo entrar a la demo
 * - demo_fin_check_enviado: true cuando ya se envió el mensaje preguntando si terminó la demo
 *
 * Estos dos flags permiten que el estado `demo_realizada` se setee automáticamente
 * sólo cuando el lead confirma por WhatsApp que terminó la demo.
 */
class AddDemoConfirmacionFlagsToLeadsTable extends Migration
{
    /**
     * Agrega los dos flags de confirmación de demo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // true cuando el lead confirmó por WhatsApp que pudo entrar a la demo
            $table->boolean('demo_ingreso_confirmado')->default(false)->after('demo_check_ingreso_enviado');
            // true cuando ya se envió el mensaje preguntando si terminó la demo
            $table->boolean('demo_fin_check_enviado')->default(false)->after('demo_ingreso_confirmado');
        });
    }

    /**
     * Elimina los dos flags de confirmación de demo.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['demo_ingreso_confirmado', 'demo_fin_check_enviado']);
        });
    }
}
