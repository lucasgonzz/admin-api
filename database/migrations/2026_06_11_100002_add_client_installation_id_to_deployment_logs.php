<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna client_installation_id a deployment_logs.
 *
 * Permite que los logs de una instalación inicial queden asociados a su
 * ClientInstallation, de la misma forma que los de un upgrade se asocian
 * a client_version_upgrade_id.
 */
class AddClientInstallationIdToDeploymentLogs extends Migration
{
    /**
     * Agrega la columna nullable client_installation_id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('deployment_logs', function (Blueprint $table) {
            // ID de la instalación inicial asociada (null si el log pertenece a un upgrade).
            $table->unsignedBigInteger('client_installation_id')->nullable()->after('client_version_upgrade_id');
        });
    }

    /**
     * Elimina la columna client_installation_id.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->dropColumn('client_installation_id');
        });
    }
}
