<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportCodexScheduledUpgrades extends Migration
{
    public function up(): void
    {
        Schema::create('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agreement_id')->unique();
            $table->unsignedBigInteger('support_ticket_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('client_api_id');
            $table->unsignedBigInteger('from_version_id');
            $table->unsignedBigInteger('version_id');
            $table->string('version', 30);
            $table->json('artifacts');
            $table->unsignedBigInteger('upgrade_id')->nullable()->unique();
            $table->string('status', 30);
            $table->string('reason', 100)->nullable();
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->dateTime('live_touched_at')->nullable();
            $table->dateTime('notification_requested_at')->nullable();
            $table->text('verification_evidence')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at_utc'], 'support_scheduled_upgrade_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_codex_scheduled_upgrades');
    }
}
