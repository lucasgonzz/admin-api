<?php

use App\Models\Admin;
use App\Models\TaskTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega assigned_admin_id a task_templates para asignar por ID de admin
 * en lugar de depender del nombre en asignado_a.
 */
class AddAssignedAdminIdToTaskTemplatesTable extends Migration
{
    /**
     * Agrega la columna y rellena desde asignado_a cuando coincide un admin por nombre.
     */
    public function up()
    {
        Schema::table('task_templates', function (Blueprint $table) {
            // Admin asignado por ID (mismo criterio que admin_tasks.assigned_admin_id).
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->after('checklist');
        });

        // Migrar plantillas existentes que tenían asignado_a como nombre de admin.
        TaskTemplate::query()
            ->whereNotNull('asignado_a')
            ->where('asignado_a', '!=', '')
            ->each(function (TaskTemplate $template) {
                $admin = Admin::where('name', $template->asignado_a)->first();
                if ($admin) {
                    $template->update(['assigned_admin_id' => $admin->id]);
                }
            });
    }

    /**
     * Elimina assigned_admin_id de task_templates.
     */
    public function down()
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn('assigned_admin_id');
        });
    }
}
