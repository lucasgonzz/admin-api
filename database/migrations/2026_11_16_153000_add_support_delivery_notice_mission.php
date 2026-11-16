<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSupportDeliveryNoticeMission extends Migration
{
    public function up()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_notice_mission_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn('delivery_notice_mission_id');
        });
    }
}
