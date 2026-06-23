<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega las columnas de trazabilidad del ciclo de vida automatizado de la demo.
 *
 * Estas columnas son consumidas por los prompts 095-098 del flujo de estados:
 *   - timestamps de ingreso y fin confirmados (para operaciones visibles),
 *   - flags de un solo disparo (anti-duplicado) para seguimientos y notificaciones.
 *
 * Nota: demo_ingreso_confirmado (boolean, sin _at) ya existe; aquí se agrega el _at.
 */
return new class extends Migration
{
    /**
     * Agrega las 6 columnas nuevas a la tabla `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Hora exacta en que Claude confirmó el ingreso del lead a la demo.
            $table->dateTime('demo_ingreso_confirmado_at')->nullable()->after('demo_ingreso_confirmado');

            // Flag: Claude infirió que la demo terminó (el lead confirmó el fin).
            $table->boolean('demo_terminada_confirmada')->default(false)->after('demo_ingreso_confirmado_at');

            // Hora exacta en que se confirmó el fin de la demo.
            $table->dateTime('demo_terminada_confirmada_at')->nullable()->after('demo_terminada_confirmada');

            // Flag de un solo disparo: ya se mandó el seguimiento de fin (evita duplicar).
            $table->boolean('demo_fin_seguimiento_enviado')->default(false)->after('demo_terminada_confirmada_at');

            // Flag de un solo disparo: ya se notificó a admins que el lead no confirmó el fin (timeout).
            $table->boolean('demo_pendiente_terminar_notificado')->default(false)->after('demo_fin_seguimiento_enviado');

            // Flag de un solo disparo: ya se notificó a admins que el lead no confirmó el ingreso.
            $table->boolean('demo_no_ingreso_notificado')->default(false)->after('demo_pendiente_terminar_notificado');
        });
    }

    /**
     * Revierte las columnas agregadas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'demo_ingreso_confirmado_at',
                'demo_terminada_confirmada',
                'demo_terminada_confirmada_at',
                'demo_fin_seguimiento_enviado',
                'demo_pendiente_terminar_notificado',
                'demo_no_ingreso_notificado',
            ]);
        });
    }
};
