<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissionStartedToSupportCodexCases extends Migration
{
    public function up()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->string('mission_thread_id', 150)->nullable();
            $table->dateTime('mission_started_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn(['mission_thread_id', 'mission_started_at']);
        });
    }
}
