<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportCodexUpgradeAgreements extends Migration
{
    public function up(): void
    {
        Schema::create('support_codex_upgrade_agreements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id')->index();
            $table->unsignedBigInteger('mission_fix_evidence_id')->index();
            $table->unsignedBigInteger('support_codex_operation_id')->unique('support_upgrade_proposal_operation');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->unsignedSmallInteger('minimum_minutes');
            $table->string('proposal_body_hash', 64);
            $table->string('confirmation_phrase', 140);
            $table->unsignedBigInteger('confirmation_message_id')->nullable()->unique('support_upgrade_confirmation');
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_codex_upgrade_agreements');
    }
}
