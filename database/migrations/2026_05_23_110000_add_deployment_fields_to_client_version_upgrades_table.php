<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado y marca de inicio del deployment automatizado en un upgrade.
 */
class AddDeploymentFieldsToClientVersionUpgradesTable extends Migration
{
    /**
     * Agrega deployment_status y deployment_started_at.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            // null | running | paused | completed | failed
            $table->string('deployment_status')->nullable()->after('target_client_api_id');

            // Momento en que se inició (o reanudó) el deployment.
            $table->timestamp('deployment_started_at')->nullable()->after('deployment_status');
        });
    }

    /**
     * Revierte las columnas de deployment.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn([
                'deployment_status',
                'deployment_started_at',
            ]);
        });
    }
}
