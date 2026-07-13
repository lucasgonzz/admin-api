<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que client_version_upgrade_id en deployment_logs sea opcional (NULL).
 *
 * Los logs de una instalación inicial (ver InstallationService::log()) se
 * asocian a client_installation_id y dejan client_version_upgrade_id en null
 * a propósito, para que el frontend distinga logs de instalación de logs de
 * upgrade. La columna seguía siendo NOT NULL desde su creación original
 * (create_deployment_logs_table), lo que rompía la creación de instalaciones
 * con un error de integridad (1048).
 */
class MakeClientVersionUpgradeIdNullableInDeploymentLogs extends Migration
{
    /**
     * Aplica nullable en client_version_upgrade_id de deployment_logs.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('deployment_logs')) {
            return;
        }

        Schema::table('deployment_logs', function (Blueprint $table) {
            if (Schema::hasColumn('deployment_logs', 'client_version_upgrade_id')) {
                $table->unsignedBigInteger('client_version_upgrade_id')->nullable()->change();
            }
        });
    }

    /**
     * Revierte client_version_upgrade_id a NOT NULL (solo si no hay filas con NULL).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('deployment_logs')) {
            return;
        }

        Schema::table('deployment_logs', function (Blueprint $table) {
            if (Schema::hasColumn('deployment_logs', 'client_version_upgrade_id')) {
                $table->unsignedBigInteger('client_version_upgrade_id')->nullable(false)->change();
            }
        });
    }
}
