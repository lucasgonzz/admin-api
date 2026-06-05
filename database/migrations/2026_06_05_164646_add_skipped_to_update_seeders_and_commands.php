<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `skipped` a update_seeders y update_commands.
 * Permite marcar un ítem para que sea omitido durante el deployment,
 * incluso antes de que se habilite la etapa post-cierre del negocio.
 */
class AddSkippedToUpdateSeedersAndCommands extends Migration
{
    /**
     * Agrega columna `skipped` (boolean, default false) en ambas tablas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('update_seeders', function (Blueprint $table) {
            // Indica si el operador decidió saltear este seeder en el deployment.
            $table->boolean('skipped')->default(false)->after('failure_notes');
        });

        Schema::table('update_commands', function (Blueprint $table) {
            // Indica si el operador decidió saltear este comando en el deployment.
            $table->boolean('skipped')->default(false)->after('failure_notes');
        });
    }

    /**
     * Revierte la migración eliminando la columna `skipped` de ambas tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('update_seeders', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });

        Schema::table('update_commands', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });
    }
}
