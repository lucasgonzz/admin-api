<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSupportOvernightNoticeOperation extends Migration
{
    public function up()
    {
        Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->unsignedBigInteger('overnight_notice_operation_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->dropColumn('overnight_notice_operation_id');
        });
    }
}
