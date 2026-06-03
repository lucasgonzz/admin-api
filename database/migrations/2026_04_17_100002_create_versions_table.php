<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVersionsTable extends Migration
{
    public function up()
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('version', 30)->unique();
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('versions');
    }
}
