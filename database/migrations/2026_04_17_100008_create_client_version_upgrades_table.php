<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientVersionUpgradesTable extends Migration
{
    public function up()
    {
        Schema::create('client_version_upgrades', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('from_version_id')->nullable();
            $table->unsignedBigInteger('to_version_id');
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('from_version_id')->references('id')->on('versions')->onDelete('set null');
            $table->foreign('to_version_id')->references('id')->on('versions')->onDelete('cascade');
            $table->foreign('created_by_admin_id')->references('id')->on('admins')->onDelete('set null');

            $table->index(['client_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('client_version_upgrades');
    }
}
