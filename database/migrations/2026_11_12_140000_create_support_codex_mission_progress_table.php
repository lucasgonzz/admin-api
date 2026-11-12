<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportCodexMissionProgressTable extends Migration
{
    public function up()
    {
        Schema::create('support_codex_mission_progress', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id');
            $table->string('mission_thread_id', 150);
            $table->string('mission_hash', 64);
            $table->string('progress_hash', 64);
            $table->json('payload');
            $table->unsignedBigInteger('support_codex_operation_id')->nullable()->index('scmp_operation');
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['support_ticket_id', 'mission_thread_id', 'mission_hash', 'progress_hash'], 'scmp_unique_progress');
            $table->index(['support_ticket_id', 'mission_hash', 'id'], 'scmp_current');
        });
    }

    public function down()
    {
        Schema::dropIfExists('support_codex_mission_progress');
    }
}
