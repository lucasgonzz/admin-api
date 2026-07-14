<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `automation_mode` a `implementations` (prompt 342).
 *
 * Pivot de producto (13/7/2026): la implementación deja de ser un agente 100% automático
 * y pasa a ser orquestada por Martín desde el panel, asistida por IA. Esta columna gatea
 * los 6 puntos de disparo automático existentes sin borrar ese código: en 'manual' no se
 * ejecuta ninguno (mensajes/avances los dispara el panel); en 'auto' el flujo se comporta
 * exactamente igual que antes de este prompt.
 *
 * Default 'manual': las implementaciones que ya existen en producción quedan en modo
 * manual, que es el comportamiento deseado a partir de este pivot.
 */
class AddAutomationModeToImplementationsTable extends Migration
{
    /**
     * Agrega la columna `automation_mode` a `implementations`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('implementations', function (Blueprint $table) {
            // 'manual' = Martín orquesta cada envío desde el panel. 'auto' = flujo automático original.
            $table->string('automation_mode', 20)->default('manual')->after('status');
        });
    }

    /**
     * Revierte el agregado de la columna `automation_mode`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('implementations', function (Blueprint $table) {
            $table->dropColumn('automation_mode');
        });
    }
}
