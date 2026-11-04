<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLocalMissionRequestToSupportCodexCases extends Migration
{
    public function up(): void
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->uuid('local_mission_request_id')->nullable()->unique();
            $table->uuid('local_mission_claim_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropUnique(['local_mission_request_id']);
            $table->dropColumn(['local_mission_request_id', 'local_mission_claim_token']);
        });
    }
}
