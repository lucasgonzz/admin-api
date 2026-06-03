<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de tiempo máximo por estado del lead para seguimientos automáticos vía IA.
 */
class CreateFollowupRulesTable extends Migration
{
    /**
     * Crea la tabla `followup_rules`.
     */
    public function up()
    {
        Schema::create('followup_rules', function (Blueprint $table) {
            $table->id();
            $table->string('estado', 40)->unique()->index();
            $table->unsignedInteger('horas_espera');
            $table->unsignedInteger('max_followups');
            $table->boolean('activa')->default(true)->index();
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla `followup_rules`.
     */
    public function down()
    {
        Schema::dropIfExists('followup_rules');
    }
}
