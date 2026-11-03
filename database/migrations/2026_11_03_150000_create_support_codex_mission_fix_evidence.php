<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportCodexMissionFixEvidence extends Migration
{
    public function up(): void
    {
        Schema::create('support_codex_mission_fix_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id');
            $table->string('mission_thread_id', 150);
            $table->string('mission_hash', 64);
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['support_ticket_id', 'mission_thread_id', 'mission_hash'], 'support_codex_fix_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_codex_mission_fix_evidence');
    }
}
