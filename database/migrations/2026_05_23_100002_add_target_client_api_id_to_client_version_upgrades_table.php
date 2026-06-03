<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API destino usada durante un upgrade de versión (deployment).
 */
class AddTargetClientApiIdToClientVersionUpgradesTable extends Migration
{
    /**
     * Agrega target_client_api_id a client_version_upgrades.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            // Endpoint de API contra el que se ejecuta el upgrade.
            $table->unsignedBigInteger('target_client_api_id')->nullable()->after('client_id');
        });
    }

    /**
     * Revierte la columna target_client_api_id.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn('target_client_api_id');
        });
    }
}
