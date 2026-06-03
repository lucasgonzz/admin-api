<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca comandos de versión que deben ejecutarse manualmente (no vía deployment SSH).
 */
class AddRunManuallyToVersionCommandsTable extends Migration
{
    /**
     * Agrega run_manually a version_commands.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('version_commands', function (Blueprint $table) {
            // Si es true, el deployment automatizado omite el comando y queda pendiente.
            $table->boolean('run_manually')->default(false)->after('is_required');
        });
    }

    /**
     * Elimina run_manually de version_commands.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('version_commands', function (Blueprint $table) {
            $table->dropColumn('run_manually');
        });
    }
}
