<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope de ejecución para seeders y comandos de versión.
 *
 * per_database: se corre una sola vez por base de datos (seeders maestros, pocos comandos).
 * per_user: se corre una vez por cada tenant/usuario (seeders con user_id, mayoría de comandos).
 */
class AddRunScopeToVersionSeedersAndCommandsTables extends Migration
{
    /**
     * Valores permitidos para run_scope.
     */
    const RUN_SCOPE_PER_DATABASE = 'per_database';

    const RUN_SCOPE_PER_USER = 'per_user';

    /**
     * Agrega run_scope a version_seeders y version_commands con defaults según tipo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('version_seeders', function (Blueprint $table) {
            // Default per_database: la mayoría de los seeders son datos maestros comunes a la BD.
            $table->string('run_scope', 20)
                ->default(self::RUN_SCOPE_PER_DATABASE)
                ->after('is_required');
        });

        Schema::table('version_commands', function (Blueprint $table) {
            // Default per_user: la mayoría de los comandos operan sobre datos de un tenant.
            $table->string('run_scope', 20)
                ->default(self::RUN_SCOPE_PER_USER)
                ->after('is_required');
        });
    }

    /**
     * Elimina run_scope de ambas tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('version_seeders', function (Blueprint $table) {
            $table->dropColumn('run_scope');
        });

        Schema::table('version_commands', function (Blueprint $table) {
            $table->dropColumn('run_scope');
        });
    }
}
