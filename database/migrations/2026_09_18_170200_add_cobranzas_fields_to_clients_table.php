<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los dos datos de cobranza que son del cliente y no de un mes (misión modulo-cobranzas,
 * 18/9/2026).
 *
 * - `mensualidad_inicio`: el PRIMER mes que se le cobra. Es lo que separa "este cliente debe
 *   agosto" de "este cliente todavía no existía en agosto": sin este dato, el módulo marcaría en
 *   rojo todos los meses anteriores al alta de cada cliente. Se guarda siempre como el primer día
 *   del mes. Nulo = todavía no arrancó (la planilla lo escribe "TODAVIA NO ARRANCO") y no se le
 *   reclama ningún mes.
 * - `cobranzas_observaciones`: las notas sueltas de la planilla que no son de un período
 *   ("VOLVER LINK", "PORQUE PAGO 80 EN JUNIO") y las que Lucas quiera dejar sobre el cliente. Una
 *   por línea.
 */
class AddCobranzasFieldsToClientsTable extends Migration
{
    /**
     * Agrega las columnas que falten.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('clients', 'mensualidad_inicio')) {
            Schema::table('clients', function (Blueprint $table) {
                // Primer mes que se cobra (primer día del mes). Nulo = todavía no arrancó.
                $table->date('mensualidad_inicio')->nullable();
            });
        }

        if (! Schema::hasColumn('clients', 'cobranzas_observaciones')) {
            Schema::table('clients', function (Blueprint $table) {
                // Notas sueltas de cobranza sobre el cliente, una por línea.
                $table->text('cobranzas_observaciones')->nullable();
            });
        }
    }

    /**
     * Saca las columnas que existan.
     *
     * @return void
     */
    public function down()
    {
        foreach (['mensualidad_inicio', 'cobranzas_observaciones'] as $columna) {
            if (! Schema::hasColumn('clients', $columna)) {
                continue;
            }

            Schema::table('clients', function (Blueprint $table) use ($columna) {
                $table->dropColumn($columna);
            });
        }
    }
}
