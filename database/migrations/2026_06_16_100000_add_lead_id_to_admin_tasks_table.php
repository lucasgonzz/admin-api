<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLeadIdToAdminTasksTable extends Migration
{
    /**
     * Agrega lead_id a admin_tasks para vincular tareas automáticas de alerta a su lead de origen.
     * Nullable: la mayoría de tareas no tienen lead asociado (creadas manualmente por admins).
     */
    public function up()
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            // FK nullable: solo las tareas de alerta automática la tienen.
            $table->unsignedBigInteger('lead_id')->nullable()->after('assigned_admin_id')->index();
        });
    }

    public function down()
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->dropColumn('lead_id');
        });
    }
}
