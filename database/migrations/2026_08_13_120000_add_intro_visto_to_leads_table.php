<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `leads` el progreso del lead sobre el video de introducción de la página inmersiva
 * (misión 46, pieza 3 — `contexto/demo_experiencia.md` §3.15, donde el intro se declara señal de
 * temperatura del lead).
 *
 * Vive en una columna y no en `localStorage` por dos motivos: es el gate que habilita el ingreso a
 * la demo, así que tiene que decidirlo el backend; y persiste entre visitas — el lead que mira el
 * intro hoy y tiene el turno mañana no lo vuelve a mirar.
 *
 * Sin foreign keys (regla del repo). Sin índices: no se busca por estas columnas.
 */
return new class extends Migration
{
    /**
     * Agrega las dos columnas del intro.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Porcentaje máximo del intro efectivamente reproducido. Monótono: el endpoint nunca lo
            // baja. Default 0 y no nullable: "no miró nada" y "no sabemos" son lo mismo acá, y un
            // null obligaría a cada lector a decidir qué hacer con él.
            $table->unsignedTinyInteger('intro_visto_pct')->default(0)->after('demo_form_completado_at');

            // Momento en que cruzó el umbral por primera vez. Null = todavía no lo cruzó. Se sella
            // una sola vez, para que quede el dato de cuándo pasó y no el del último reporte.
            $table->timestamp('intro_visto_at')->nullable()->after('intro_visto_pct');
        });
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
