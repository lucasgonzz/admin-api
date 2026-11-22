<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag `es_agente` a los admins (misión admin-control-total, 21/11/2026).
 *
 * Marca a los admins que son agentes (Claude Code, OpenClaw): entran al panel con un token de Sanctum, igual
 * que un humano, pero cada pedido suyo se audita y lo que duele (borrar, plata, terceros, masivas,
 * credenciales) pide confirmación. Los admins humanos quedan en `false` y no cambian en nada.
 */
class AddEsAgenteToAdminsTable extends Migration
{
    /**
     * Agrega la columna, apagada para todos los admins existentes.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('es_agente')->default(false)->after('es_vendedor');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('es_agente');
        });
    }
}
