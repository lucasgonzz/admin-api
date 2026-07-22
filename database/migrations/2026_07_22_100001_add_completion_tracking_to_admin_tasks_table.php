<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Agrega trazabilidad de quién y cuándo completó una tarea interna.
 *
 * done_at: momento en que la tarea se marcó como realizada.
 * done_by_admin_id: admin que la marcó como realizada.
 *
 * Para las tareas que ya estaban marcadas como is_done = true antes de esta
 * migración, se aproxima done_at con su updated_at (mejor dato disponible),
 * y done_by_admin_id queda en null porque esa información no existe
 * históricamente.
 */
class AddCompletionTrackingToAdminTasksTable extends Migration
{
    /**
     * Agrega las columnas y hace el backfill de las tareas ya completadas.
     */
    public function up()
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            // Momento en que la tarea fue marcada como realizada.
            $table->timestamp('done_at')->nullable()->after('is_done');
            // Admin que marcó la tarea como realizada.
            $table->unsignedBigInteger('done_by_admin_id')->nullable()->index()->after('done_at');
        });

        // Backfill: solo tareas ya marcadas como realizadas. No se toca is_done = false.
        DB::table('admin_tasks')
            ->where('is_done', true)
            ->update([
                // Mejor aproximación disponible: la fecha de la última actualización de la fila.
                'done_at' => DB::raw('updated_at'),
            ]);
    }

    /**
     * Elimina las dos columnas agregadas.
     */
    public function down()
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->dropColumn(['done_at', 'done_by_admin_id']);
        });
    }
}
