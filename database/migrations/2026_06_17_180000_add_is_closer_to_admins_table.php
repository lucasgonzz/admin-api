<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag is_closer al modelo Admin para identificar
 * qué usuarios del equipo actúan como closer en demos.
 */
class AddIsCloserToAdminsTable extends Migration
{
    /**
     * Agrega la columna is_closer a la tabla admins.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            // Marca si el admin es el closer responsable de las llamadas post-demo.
            $table->boolean('is_closer')->default(false)->after('is_default_task_assignee');
        });
    }

    /**
     * Revierte la columna is_closer de admins.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('is_closer');
        });
    }
}
