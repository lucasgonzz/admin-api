<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVersionCommandsTable extends Migration
{
    public function up()
    {
        Schema::create('version_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('version_id');
            $table->string('command', 255);
            $table->text('description')->nullable();
            $table->integer('execution_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('cascade');
            $table->index('version_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('version_commands');
    }
}
