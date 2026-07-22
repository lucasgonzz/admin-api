<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega admin_tasks.created_via, que registra el origen de la tarea.
 *
 * Valores previstos:
 * - "admin": creada a mano desde el panel (default; también aplica a las filas
 *   existentes antes de esta migración).
 * - "claude": creada por Claude vía el endpoint de ingesta (prompt 04 del grupo).
 * - "template": creada por App\Services\TaskFromTemplatesService.
 * - "lead_alert": alerta de intervención humana generada por
 *   App\Services\LeadAiService cuando el agente escala una conversación.
 *
 * Las filas existentes quedan en "admin" por el default de la columna;
 * no hace falta backfill fino porque todas las tareas previas a este
 * esquema fueron creadas a mano desde el panel.
 */
class AddCreatedViaToAdminTasksTable extends Migration
{
    /**
     * Agrega la columna created_via con default 'admin'.
     */
    public function up()
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->string('created_via', 20)->default('admin')->after('created_by_admin_id');
        });
    }

    /**
     * Elimina la columna created_via.
     */
    public function down()
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
}
