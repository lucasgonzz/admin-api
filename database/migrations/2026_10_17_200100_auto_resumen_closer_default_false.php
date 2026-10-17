<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El resumen automático para el closer se apaga para todos (misión flujo-lead-landing-closer,
 * decisión D7 de Lucas, 17/10/2026).
 *
 * `leads.auto_resumen_closer` nació con default `true` (2026_07_08_100001, prompt 318): cada lead
 * en `demo_agendada` con la demo de hoy generaba solo su resumen con `leads:generate-demo-summary`,
 * a la hora de fin menos los minutos configurados. Lucas pidió que el resumen deje de generarse
 * solo y pase a ser a pedido: queda el botón «Generar» de la solapa Resumen y de Operaciones, y el
 * toggle por lead para quien lo quiera prendido.
 *
 * Dos cosas, y las dos hacen falta:
 *   1. El DEFAULT de la columna pasa a `false`, para que los leads que nazcan de acá en adelante
 *      no lo tengan prendido.
 *   2. Las filas EXISTENTES se ponen en `0`. Cambiar solo el default no toca ningún lead ya
 *      creado, y hay leads con demo agendada para los próximos días que seguirían generando el
 *      resumen solos.
 *
 * `down()` restaura el default `true` pero NO los datos: no hay forma de saber qué leads lo tenían
 * en `true` por default y cuáles porque alguien lo prendió a mano.
 *
 * `->change()` necesita doctrine/dbal, que el repo ya tiene (lo usan otras migraciones de este
 * mismo directorio, por ejemplo 2026_05_27_140000_set_nullable_api_url_to_clients_table).
 */
class AutoResumenCloserDefaultFalse extends Migration
{
    /**
     * Cambia el default y apaga el flag en las filas existentes.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'auto_resumen_closer')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('auto_resumen_closer')->default(false)->change();
        });

        DB::table('leads')->where('auto_resumen_closer', true)->update(['auto_resumen_closer' => false]);
    }

    /**
     * Restaura el default anterior (solo el default, no los datos).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'auto_resumen_closer')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('auto_resumen_closer')->default(true)->change();
        });
    }
}
