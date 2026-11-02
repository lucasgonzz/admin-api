<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro de los cambios de contacto de un cliente (misión telefono-personal-del-dueno, 2/11/2026).
 *
 * Una fila por CAMPO cambiado por `PATCH claude/clients/{id}/contacto`. Cambiar un teléfono redirige los envíos
 * y el reconocimiento del dueño ante el agente personal; si algo se rompe después, esta tabla dice qué cambió,
 * de qué valor a cuál, cuándo y por qué (`motivo`).
 *
 * ⚠️ Solo registra lo que pasa por esa ruta. Lo que se edita a mano en la ficha del panel NO deja rastro acá.
 *
 * Sin llave foránea a `clients` a propósito, igual que el resto de las tablas de rastro del admin: el rastro
 * tiene que sobrevivir a un cliente borrado.
 */
class CreateClientContactChangesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_contact_changes')) {
            return;
        }

        Schema::create('client_contact_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id')->index();
            // `phone`, `asistente_phone` o `asistente_whatsapp_activo`.
            $table->string('campo', 40);
            $table->string('valor_anterior', 191)->nullable();
            $table->string('valor_nuevo', 191)->nullable();
            // Quién lo hizo: hoy solo `claude` (rutas claude/*). Los cambios hechos desde la ficha del panel NO dejan rastro.
            $table->string('origen', 20)->default('claude');
            $table->string('motivo', 191)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_contact_changes');
    }
}
