<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entradas del protocolo de ventas consumidas por Claude al sugerir respuestas.
 */
class CreateProtocolEntriesTable extends Migration
{
    /**
     * Crea la tabla `protocol_entries`.
     */
    public function up()
    {
        Schema::create('protocol_entries', function (Blueprint $table) {
            $table->id();
            $table->string('categoria', 40)->index();
            $table->string('estado_aplicable', 40)->nullable()->index();
            $table->unsignedTinyInteger('followup_numero')->nullable();
            $table->string('titulo', 255);
            $table->text('descripcion');
            $table->text('mensaje_template');
            $table->text('notas_setter')->nullable();
            $table->boolean('activa')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla `protocol_entries`.
     */
    public function down()
    {
        Schema::dropIfExists('protocol_entries');
    }
}
