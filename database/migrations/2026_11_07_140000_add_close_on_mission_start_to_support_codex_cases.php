<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCloseOnMissionStartToSupportCodexCases extends Migration
{
    public function up(): void
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->boolean('close_on_mission_start')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn('close_on_mission_start');
        });
    }
}
