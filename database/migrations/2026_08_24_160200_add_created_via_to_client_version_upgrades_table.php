<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega client_version_upgrades.created_via, que registra el origen del upgrade.
 *
 * Valores previstos:
 * - "claude": creado por Claude vía POST claude/upgrades.
 * - NULL: origen no marcado. Es lo que tienen todas las filas anteriores a esta
 *   migración y las que sigue creando el panel / admin-spa.
 *
 * 🔴 Diferencia deliberada con `admin_tasks.created_via`
 * (2026_07_22_100002_add_created_via_to_admin_tasks_table.php), que es nullable=false
 * con default 'admin': acá la columna es **nullable, sin default y sin backfill**.
 * Las filas históricas de client_version_upgrades ya tienen `created_by_admin_id`
 * cargado y ese es su origen; inventarles un 'admin' sería escribir un dato que nadie
 * midió. NULL significa "origen no marcado, mirá created_by_admin_id como siempre" y
 * deja la semántica de todo lo existente intacta.
 *
 * Sin índice a propósito: baja cardinalidad y no se filtra por ella en ningún camino
 * caliente.
 */
class AddCreatedViaToClientVersionUpgradesTable extends Migration
{
    /**
     * Agrega la columna created_via, nullable y sin default.
     */
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->string('created_via', 20)->nullable()->after('created_by_admin_id');
        });
    }

    /**
     * Elimina la columna created_via.
     */
    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
}
