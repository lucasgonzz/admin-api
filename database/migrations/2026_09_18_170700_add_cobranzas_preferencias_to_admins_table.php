<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las preferencias de cada admin en el módulo de Cobranzas (misión modulo-cobranzas, 18/9/2026).
 *
 * Lucas pidió que la selección de meses de la tabla de Mensualidades sea multi-selección y que
 * quede GUARDADA por usuario: si hoy mira agosto y septiembre, mañana la pantalla abre igual. Lo
 * mismo con el orden (sin pago primero / sin factura primero / orden de carga). Es una
 * preferencia de quien mira, no un dato del negocio, así que va en `admins` y no en una tabla
 * propia: un JSON `{meses: ['2026-09'], orden: 'carga'}` que el endpoint valida al escribir.
 *
 * Nulo = nunca eligió nada = mes corriente y orden de carga (lo resuelve el controller, no la
 * base, porque "mes corriente" cambia solo).
 */
class AddCobranzasPreferenciasToAdminsTable extends Migration
{
    /**
     * Agrega la columna si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('admins', 'cobranzas_preferencias')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            // {meses: ['YYYY-MM', ...], orden: 'carga'|'sin_pago'|'sin_factura'}. Nulo = defaults.
            $table->json('cobranzas_preferencias')->nullable();
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('admins', 'cobranzas_preferencias')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('cobranzas_preferencias');
        });
    }
}
