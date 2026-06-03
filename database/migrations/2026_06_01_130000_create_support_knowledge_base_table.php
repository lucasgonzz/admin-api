<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportKnowledgeBaseTable extends Migration
{
    /**
     * Crea la base de conocimiento consumida por Claude en sugerencias de soporte.
     */
    public function up()
    {
        Schema::create('support_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de support_knowledge_base.
     */
    public function down()
    {
        Schema::dropIfExists('support_knowledge_base');
    }
}
