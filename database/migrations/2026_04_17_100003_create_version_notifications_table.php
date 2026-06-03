<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVersionNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('version_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('version_id');
            $table->string('title', 200);
            $table->text('body');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('cascade');
            $table->index(['version_id', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('version_notifications');
    }
}
