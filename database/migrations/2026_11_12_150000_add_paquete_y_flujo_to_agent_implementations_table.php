<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPaqueteYFlujoToAgentImplementationsTable extends Migration
{
    public function up()
    {
        Schema::table('agent_implementations', function (Blueprint $table) {
            $table->string('paquete', 10)->default('full');
            // El historial conserva su circuito; las altas nuevas usan la entrega personal obligatoria.
            $table->unsignedTinyInteger('flujo_version')->default(1);
        });
    }

    public function down()
    {
        Schema::table('agent_implementations', function (Blueprint $table) {
            $table->dropColumn(['paquete', 'flujo_version']);
        });
    }
}
