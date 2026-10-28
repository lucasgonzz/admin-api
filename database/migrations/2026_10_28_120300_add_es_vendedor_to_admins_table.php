<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag `es_vendedor` a los admins (misión ventas-y-comisiones, 28/10/2026).
 *
 * Marca a quien se le liquida comisión por las ventas. Si hay exactamente un vendedor, las ventas
 * nuevas se le asignan solas (`VentaService::vendedor_por_defecto()`). Es distinto de `is_closer`
 * (el que hace la llamada post-demo), aunque hoy sean la misma persona: el comando
 * `ventas:migrar-licencias` copia `is_closer` a `es_vendedor` si no hay ningún vendedor marcado.
 */
class AddEsVendedorToAdminsTable extends Migration
{
    /**
     * Agrega la columna, apagada para todos.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('es_vendedor')->default(false)->after('es_setter');
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
            $table->dropColumn('es_vendedor');
        });
    }
}
