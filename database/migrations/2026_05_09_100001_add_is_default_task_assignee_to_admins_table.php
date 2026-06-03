<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsDefaultTaskAssigneeToAdminsTable extends Migration
{
    /**
     * Agrega flag para que al crear una nueva tarea se preseleccione
     * automáticamente este admin como responsable asignado.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            // Marca si el admin es el asignatario por defecto al crear tareas.
            $table->boolean('is_default_task_assignee')->default(false)->after('is_default_support_owner');
        });
    }

    /**
     * Revierte el flag de asignatario por defecto de tareas.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('is_default_task_assignee');
        });
    }
}
