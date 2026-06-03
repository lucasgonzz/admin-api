<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almacena el texto base del system prompt de Claude para sugerencias de leads.
 */
class CreateAiSystemPromptsTable extends Migration
{
    /**
     * Crea la tabla `ai_system_prompts`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ai_system_prompts', function (Blueprint $table) {
            $table->id();
            $table->longText('contenido');
            $table->string('descripcion')->default('System prompt principal');
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla `ai_system_prompts`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_system_prompts');
    }
}
