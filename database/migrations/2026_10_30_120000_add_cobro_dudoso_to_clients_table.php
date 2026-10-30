<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de «cliente de dudoso cobro futuro» (misión cobro-dudoso-y-pagos-ventas, 30/10/2026).
 *
 * Hay clientes a los que se les vendió y cuyas cuotas pendientes probablemente no se cobren, pero
 * tampoco se dan por perdidas. Sin esta marca, esa deuda se mezcla con la plata que de verdad hay
 * que ir a cobrar («Falta cobrar» de Cobranzas → Ventas) y el número queda inflado; borrar las
 * cuotas sería declararlas perdidas. Con la marca encendida, la deuda de ventas del cliente pasa a
 * un balde aparte, «En stand-by».
 *
 * La marca es del CLIENTE y no de la venta: «dudoso cobro» es una opinión sobre quién paga. Afecta
 * solo a las ventas y cuotas de licencia; las mensualidades tienen su propia marca
 * (`no_paga_mensualidad`) y no se tocan. La comisión del vendedor tampoco cambia: se libera por lo
 * que el cliente paga de verdad.
 *
 * Son tres columnas con default (`false` / `null` / `null`): todos los clientes existentes siguen
 * igual y el código que todavía no las conoce no se rompe. Sin foreign keys.
 */
class AddCobroDudosoToClientsTable extends Migration
{
    /**
     * Agrega las tres columnas, cada una con su guarda `hasColumn` para que correrla dos veces (o
     * sobre una base que ya tiene alguna) no falle.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // true = la deuda de ventas de este cliente es de dudoso cobro: va a «En stand-by».
            if (! Schema::hasColumn('clients', 'cobro_dudoso')) {
                $table->boolean('cobro_dudoso')->default(false);
            }

            // Desde cuándo está marcado (la fecha en que se encendió la marca).
            if (! Schema::hasColumn('clients', 'cobro_dudoso_desde')) {
                $table->date('cobro_dudoso_desde')->nullable();
            }

            // Por qué se lo marcó, en palabras (nota libre, hasta 255).
            if (! Schema::hasColumn('clients', 'cobro_dudoso_motivo')) {
                $table->string('cobro_dudoso_motivo', 255)->nullable();
            }
        });
    }

    /**
     * Saca las tres columnas que existan.
     *
     * @return void
     */
    public function down()
    {
        $columnas = array_values(array_filter(
            ['cobro_dudoso', 'cobro_dudoso_desde', 'cobro_dudoso_motivo'],
            function ($columna) {
                return Schema::hasColumn('clients', $columna);
            }
        ));

        if (count($columnas) === 0) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use ($columnas) {
            $table->dropColumn($columnas);
        });
    }
}
