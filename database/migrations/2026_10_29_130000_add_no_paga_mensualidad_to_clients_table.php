<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de «este cliente no paga mensualidad» (misión no-paga-mensualidad, 29/10/2026).
 *
 * Hay clientes cargados en el admin que no usan el sistema y a los que no se les cobra. Sin esta
 * marca, el módulo Cobranzas los cuenta como deuda (rojo, «Falta cobrar») y el total a cobrar de
 * cada mes queda inflado con plata que nunca va a entrar. Con la marca encendida el módulo los deja
 * fuera de lo que hay que cobrar.
 *
 * Es una columna con default `false`: todos los clientes existentes siguen pagando, y el código que
 * todavía no la conoce no se rompe.
 */
class AddNoPagaMensualidadToClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('clients', 'no_paga_mensualidad')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            // true = el cliente no usa el sistema / no se le cobra la mensualidad.
            $table->boolean('no_paga_mensualidad')->default(false);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('clients', 'no_paga_mensualidad')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('no_paga_mensualidad');
        });
    }
}
