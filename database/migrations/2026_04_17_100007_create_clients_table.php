<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientsTable extends Migration
{
    public function up()
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            // $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('slug', 80)->unique();
            $table->string('api_url', 255);
            $table->string('api_key', 120);
            $table->string('inbound_api_key', 120);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('current_version_id')->references('id')->on('versions')->onDelete('set null');
            $table->index('is_active');
            $table->index('inbound_api_key');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients');
    }
}
