<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `source_group_id` a las cuatro tablas hijas de `versions`
 * (version_notifications, version_seeders, version_commands, version_manual_tasks).
 *
 * Semántica: null = ítem cargado a mano desde admin-spa. Con valor = lo cargó un
 * proceso automático (el loop de Claude Code al cerrar un grupo de prompts), y el
 * valor es el `id` del grupo de claude-comerciocity que lo originó (el mismo id que
 * usa `grupos.json`, ej. "publicacion-novedades-al-admin").
 *
 * Esto le da a cada ítem una clave de idempotencia/origen para que un reintento del
 * loop no duplique filas en la versión borrador. La deduplicación fina la resuelve
 * el prompt 02 de este grupo (endpoint); acá solo se agrega la columna y un índice
 * compuesto para que ese lookup no haga table scan.
 */
class AddSourceGroupIdToVersionItemsTables extends Migration
{
    /**
     * Agrega la columna `source_group_id` (string nullable) y su índice compuesto
     * con `version_id` a las cuatro tablas hijas de versions.
     *
     * @return void
     */
    public function up()
    {
        // version_notifications: la última columna de datos es is_active.
        Schema::table('version_notifications', function (Blueprint $table) {
            $table->string('source_group_id', 120)->nullable()->after('is_active');
            $table->index(['version_id', 'source_group_id'], 'vn_version_source_idx');
        });

        // version_seeders: run_scope quedó como última columna de datos tras la
        // migración 2026_05_29_120000.
        Schema::table('version_seeders', function (Blueprint $table) {
            $table->string('source_group_id', 120)->nullable()->after('run_scope');
            $table->index(['version_id', 'source_group_id'], 'vs_version_source_idx');
        });

        // version_commands: run_scope quedó como última columna de datos (después de
        // run_manually en el orden físico, ver migraciones 2026_05_29_120000 y
        // 2026_06_01_100000, ambas con after('is_required')).
        Schema::table('version_commands', function (Blueprint $table) {
            $table->string('source_group_id', 120)->nullable()->after('run_scope');
            $table->index(['version_id', 'source_group_id'], 'vc_version_source_idx');
        });

        // version_manual_tasks: la última columna de datos es is_required.
        Schema::table('version_manual_tasks', function (Blueprint $table) {
            $table->string('source_group_id', 120)->nullable()->after('is_required');
            $table->index(['version_id', 'source_group_id'], 'vmt_version_source_idx');
        });
    }

    /**
     * Elimina primero los índices compuestos y después las columnas, en las cuatro
     * tablas. Si se dropea la columna sin dropear el índice antes, MySQL falla.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('version_notifications', function (Blueprint $table) {
            $table->dropIndex('vn_version_source_idx');
            $table->dropColumn('source_group_id');
        });

        Schema::table('version_seeders', function (Blueprint $table) {
            $table->dropIndex('vs_version_source_idx');
            $table->dropColumn('source_group_id');
        });

        Schema::table('version_commands', function (Blueprint $table) {
            $table->dropIndex('vc_version_source_idx');
            $table->dropColumn('source_group_id');
        });

        Schema::table('version_manual_tasks', function (Blueprint $table) {
            $table->dropIndex('vmt_version_source_idx');
            $table->dropColumn('source_group_id');
        });
    }
}
