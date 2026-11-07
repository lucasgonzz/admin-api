<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Snapshot privado del navegador del alta, consumido por StartTrial o purgado a los siete días. */
class AddContextoMetaToTrialInstancesTable extends Migration
{
    public function up()
    {
        Schema::table('trial_instances', function (Blueprint $table) {
            $table->json('contexto_meta')->nullable();
        });
    }

    public function down()
    {
        Schema::table('trial_instances', function (Blueprint $table) {
            $table->dropColumn('contexto_meta');
        });
    }
}
