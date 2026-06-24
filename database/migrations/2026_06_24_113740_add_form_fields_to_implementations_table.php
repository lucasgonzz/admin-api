<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega form_token y form_submitted_at a la tabla implementations.
 *
 * form_token: UUID v4 que identifica al cliente para acceder al formulario sin exponer el id.
 * form_submitted_at: timestamp de envío definitivo del formulario de configuración.
 */
class AddFormFieldsToImplementationsTable extends Migration
{
    /**
     * Agrega las columnas al esquema.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('implementations', function (Blueprint $table) {
            // Token único (UUID v4) para acceso público al formulario sin autenticación.
            $table->string('form_token', 64)->nullable()->unique()->after('notes');

            // Fecha y hora en que el cliente envió definitivamente el formulario.
            $table->timestamp('form_submitted_at')->nullable()->after('form_token');
        });
    }

    /**
     * Revierte los cambios eliminando las columnas agregadas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('implementations', function (Blueprint $table) {
            // Eliminar primero el índice único antes de dropear la columna.
            $table->dropUnique(['form_token']);
            $table->dropColumn(['form_token', 'form_submitted_at']);
        });
    }
}
