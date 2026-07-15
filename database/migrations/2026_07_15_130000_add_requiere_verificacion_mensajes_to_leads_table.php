<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRequiereVerificacionMensajesToLeadsTable extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Toggle por lead: cuando es true, todo mensaje que Claude arme para este lead se
            // retiene para verificación humana antes de enviarse, en cualquier estado. Cuando es
            // false, los mensajes se envían automáticamente al instante. Se auto-enciende (latch)
            // al entrar a la ventana solicita_disponibilidad → closer_activo. Default false.
            // Ver LeadAiService (prompt 407) y el toggle del header de la conversación (prompt 408).
            $table->boolean('requiere_verificacion_mensajes')->default(false)->after('requiere_intervencion_humana');
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('requiere_verificacion_mensajes');
        });
    }
}
