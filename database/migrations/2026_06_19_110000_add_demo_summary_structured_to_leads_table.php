<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna demo_summary_structured a la tabla leads.
 *
 * Almacena el resumen estructurado en JSON con 4 claves (empresa, situacion_actual,
 * funcionalidades, puntos_dolor) generado por Claude junto al resumen textual.
 */
return new class extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna después de demo_summary.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            /* Columna nullable para no romper leads existentes sin resumen estructurado. */
            $table->text('demo_summary_structured')->nullable()->after('demo_summary');
        });
    }

    /**
     * Revierte la migración: elimina la columna.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('demo_summary_structured');
        });
    }
};
