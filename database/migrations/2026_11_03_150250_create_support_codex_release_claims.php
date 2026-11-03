<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportCodexReleaseClaims extends Migration
{
    public function up(): void
    {
        Schema::create('support_codex_release_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agreement_id')->unique();
            $table->unsignedBigInteger('mission_fix_evidence_id')->index('support_release_evidence');
            $table->unsignedBigInteger('version_id')->index();
            $table->string('version', 30);
            $table->string('draft_code', 30)->nullable();
            $table->string('active_key', 16)->nullable()->unique();
            $table->json('develop');
            $table->json('proof')->nullable();
            $table->string('proof_hash', 64)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('blocked_at')->nullable();
            $table->string('blocked_reason', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_codex_release_claims');
    }
}
