<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUpdateSeedersAndCommandsTables extends Migration
{
    public function up()
    {
        Schema::create('update_seeders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_version_upgrade_id');
            $table->unsignedBigInteger('version_seeder_id');
            $table->enum('status', ['pendiente', 'exitoso', 'fallido'])->default('pendiente');
            $table->timestamp('executed_at')->nullable();
            $table->text('failure_notes')->nullable();
            $table->timestamps();

            $table->foreign('client_version_upgrade_id')
                  ->references('id')->on('client_version_upgrades')
                  ->onDelete('cascade');

            $table->foreign('version_seeder_id')
                  ->references('id')->on('version_seeders')
                  ->onDelete('cascade');

            $table->index('client_version_upgrade_id');
        });

        Schema::create('update_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_version_upgrade_id');
            $table->unsignedBigInteger('version_command_id');
            $table->enum('status', ['pendiente', 'exitoso', 'fallido'])->default('pendiente');
            $table->timestamp('executed_at')->nullable();
            $table->text('failure_notes')->nullable();
            $table->timestamps();

            $table->foreign('client_version_upgrade_id')
                  ->references('id')->on('client_version_upgrades')
                  ->onDelete('cascade');

            $table->foreign('version_command_id')
                  ->references('id')->on('version_commands')
                  ->onDelete('cascade');

            $table->index('client_version_upgrade_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('update_commands');
        Schema::dropIfExists('update_seeders');
    }
}
