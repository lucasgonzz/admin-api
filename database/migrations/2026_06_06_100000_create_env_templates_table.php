<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de plantilla base de variables .env del sistema.
 * Cada variable puede marcarse como común entre todos los sistemas (contraste al actualizar)
 * o como manual al crear (recordatorio en el proceso de alta de un nuevo sistema).
 */
return new class extends Migration
{
    /**
     * Crea la tabla env_templates con sus campos de control.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('env_templates', function (Blueprint $table) {
            /* Identificador autoincremental. */
            $table->id();

            /* Nombre de la variable .env (ej: MAIL_HOST). Único por sistema. */
            $table->string('key', 120)->unique();

            /* Valor por defecto de la variable en el template base. Nullable para vars sin default. */
            $table->text('value')->nullable();

            /* Grupo funcional para agrupar en la UI: mail, pusher, db, app, misc. */
            $table->string('group', 80)->nullable();

            /* Si es true, el valor de esta variable se contrasta con los clientes al actualizar. */
            $table->boolean('is_common')->default(false);

            /* Si es true, se muestra como recordatorio al dar de alta un sistema nuevo. */
            $table->boolean('is_manual_on_create')->default(false);

            /* Notas internas para el operador (ej: "Configurar manualmente para cada sistema"). */
            $table->text('notes')->nullable();

            /* Orden de aparición dentro del grupo en la pantalla de gestión. */
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla env_templates.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('env_templates');
    }
};
