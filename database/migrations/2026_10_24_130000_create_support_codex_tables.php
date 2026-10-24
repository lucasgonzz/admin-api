<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportCodexTables extends Migration
{
    public function up()
    {
        Schema::create('support_codex_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id')->unique();
            $table->string('state', 40)->default('ready')->index();
            $table->string('thread_id', 150)->nullable();
            $table->string('worker_id', 150)->nullable();
            $table->string('lease_hash', 64)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->unsignedBigInteger('handled_message_id')->default(0);
            $table->unsignedBigInteger('expected_message_id')->nullable();
            $table->unsignedBigInteger('last_reply_id')->nullable();
            $table->dateTime('confirmation_due_at')->nullable()->index();
            $table->boolean('follow_up_sent')->default(false);
            $table->longText('mission')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('support_codex_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 120)->unique();
            $table->unsignedBigInteger('support_ticket_id')->index();
            $table->unsignedBigInteger('expected_message_id');
            $table->string('action', 40);
            $table->string('request_hash', 64);
            $table->string('status', 30)->default('sending');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('support_codex_operations');
        Schema::dropIfExists('support_codex_cases');
    }
}
