<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Agrega la columna `demo_experiencia` a `leads` (grupo 293, prompt 01 — interruptor de dinámica de demo).
 *
 * Guarda, por lead, qué dinámica de demo le toca: la actual (Mail 1 con credenciales y videos por
 * link) o la nueva (página inmersiva, formulario, panel lateral y tour). El valor se estampa una
 * sola vez al crear el lead (ver el hook `creating` en el modelo `Lead`) y nunca se relee de una
 * setting global en runtime: si se leyera en vivo, prender la dinámica nueva le cambiaría el guion
 * a leads que ya tienen demo agendada con la dinámica anterior (ya recibieron el Mail 1).
 *
 * Sin foreign keys (regla del repo). Sin condicionar nombres/columnas por env().
 */
return new class extends Migration
{
    /**
     * Agrega la columna `demo_experiencia` (nullable, sin default de BD) y backfillea los leads
     * existentes a `'actual'`, que es la dinámica que ya venían usando.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Sin ->default() a nivel de BD a propósito: el valor lo pone el modelo (hook creating),
            // un default de MySQL competiría con el hook y ocultaría el caso "insert sin Eloquent".
            $table->string('demo_experiencia', 20)->nullable()->after('demo_ingreso_token_revocado_at');
        });

        // Backfill: todos los leads que ya existen siguen con la dinámica de producción vigente.
        DB::table('leads')->whereNull('demo_experiencia')->update(['demo_experiencia' => 'actual']);
    }

    /**
     * Revierte las columnas agregadas (vacío, como el resto de migraciones del repo).
     *
     * @return void
     */
    public function down()
    {
    }
};
