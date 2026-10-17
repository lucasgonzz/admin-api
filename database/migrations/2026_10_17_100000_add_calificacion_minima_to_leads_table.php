<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificación mínima del lead (misión flujo-lead-landing-closer, 17/10/2026, decisión de Lucas):
 * para estar `calificado` alcanza con que tenga un negocio, diga cuántas personas trabajan con él
 * (mínimo una) y conteste si hoy usa algún sistema. Nada más.
 *
 * Dos columnas nuevas, las dos que no existían:
 *  - `cantidad_empleados`: la cuenta que sostiene la guarda de `calificado` (el que trabaja solo
 *    recibe la demo igual pero queda en `contactado`, D1 del plan).
 *  - `sistema_actual`: texto corto ("Excel", "cuaderno", "ninguno", el nombre de un sistema). Es un
 *    dato para Thomás y para el agente, no un filtro.
 *
 * El RUBRO no se agrega: ya existe `business_type` (varchar 80), que es la columna que el panel, los
 * endpoints `claude/leads` y los seeders usan como rubro del lead. La acción `guardar_calificacion`
 * escribe `rubro` ahí (ver LeadAiService::aplicar_guardar_calificacion()). Una columna `rubro` aparte
 * dejaría el mismo dato en dos lugares.
 *
 * Sin claves foráneas, con guardas `hasColumn` y `down()` tolerante, como todas las de esta tabla.
 */
class AddCalificacionMinimaToLeadsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'cantidad_empleados')) {
                $table->unsignedSmallInteger('cantidad_empleados')->nullable()->after('business_type');
            }
            if (! Schema::hasColumn('leads', 'sistema_actual')) {
                $table->string('sistema_actual', 120)->nullable()->after('cantidad_empleados');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            foreach (['sistema_actual', 'cantidad_empleados'] as $columna) {
                if (Schema::hasColumn('leads', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
