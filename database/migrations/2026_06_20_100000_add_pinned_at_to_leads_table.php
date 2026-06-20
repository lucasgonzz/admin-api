<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el campo `pinned_at` a la tabla `leads`.
 *
 * Este timestamp indica cuándo fue fijado el lead en el panel de admin-spa.
 * Si es null, el lead no está fijado. El valor se usa para ordenar los leads
 * fijados al inicio de la tabla (el último en ser fijado aparece primero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Timestamp de cuando se fijó el lead; null = no fijado.
            // El orden de los fijados se determina por este campo DESC.
            $table->timestamp('pinned_at')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('pinned_at');
        });
    }
};
