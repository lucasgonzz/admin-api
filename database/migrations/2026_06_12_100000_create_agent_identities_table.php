<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda la descripción del agente de ventas (Martín) para inyectarla
 * dinámicamente en el system prompt de Claude en cada llamada.
 */
class CreateAgentIdentitiesTable extends Migration
{
    /**
     * Crea la tabla agent_identities con nombre, descripción y estado activo.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_identities', function (Blueprint $table) {
            $table->id();
            /* Nombre visible del agente (ej: "Martín"). */
            $table->string('name', 100)->default('Martín');
            /* Descripción completa del perfil inyectada en el system prompt de Claude. */
            $table->text('description');
            /* Solo se inyecta la identidad con activa = true. */
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla agent_identities.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_identities');
    }
}
