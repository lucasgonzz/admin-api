<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clients.requires_upgrade` (misión martin-descripcion-y-actualizaciones, 25/11/2026): true si el cliente tiene
 * alguna marca abierta en `client_upgrade_marks`. Es una copia para listar y filtrar rápido en el panel; la
 * mantiene sólo `ClientUpgradeMarkService::sync_client_flag()` (el formulario del cliente no la escribe).
 *
 * Default false: ningún cliente existente queda marcado hasta que corra el backfill.
 */
class AddRequiresUpgradeToClientsTable extends Migration
{
    /**
     * Agrega la columna con su índice.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('requires_upgrade')->default(false);
            $table->index('requires_upgrade', 'clients_requires_upgrade_idx');
        });
    }

    /**
     * Saca el índice y la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_requires_upgrade_idx');
            $table->dropColumn('requires_upgrade');
        });
    }
}
