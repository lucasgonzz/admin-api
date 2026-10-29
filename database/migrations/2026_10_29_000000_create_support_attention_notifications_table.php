<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportAttentionNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('support_attention_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id')->unique();
            $table->string('fingerprint', 64)->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->text('dispatched_admin_ids')->nullable();
            $table->text('accepted_endpoint_hashes')->nullable();
            $table->dateTime('processing_until')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('enqueued_at')->nullable();
            $table->timestamps();
            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('support_attention_notifications');
    }
}
