<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientNotificationReadsTable extends Migration
{
    public function up()
    {
        Schema::create('client_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('version_notification_id');
            $table->unsignedBigInteger('client_user_id');
            $table->string('client_user_name', 150)->nullable();
            $table->string('client_user_email', 150)->nullable();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('version_notification_id')->references('id')->on('version_notifications')->onDelete('cascade');

            $table->unique(
                ['client_id', 'version_notification_id', 'client_user_id'],
                'cnr_client_notif_user_uq'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('client_notification_reads');
    }
}
