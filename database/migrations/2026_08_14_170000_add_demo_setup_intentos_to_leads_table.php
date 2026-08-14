<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `leads` el contador de intentos automáticos del demo setup (misión 60, pieza 4).
 *
 * 🔴 Sin este contador el reintento automático desde `fallido` sería un bucle: `leads:run-demo-setup`
 * corre cada minuto, así que un fallo permanente —una instancia caída, una URL mal cargada— dispararía
 * un `migrate:fresh` por minuto hasta que venciera el turno del lead. El contador es lo único que
 * convierte "reintentar" en "reintentar UNA vez".
 *
 * No es lo mismo que `demo_setup_last_run_at`: esa columna dice cuándo fue el último arranque, no
 * cuántos hubo, y el botón manual del panel también la escribe.
 *
 * Default 0 y no nullable: los leads que ya existen nunca reintentaron automáticamente, que es
 * exactamente lo que 0 significa. Un null obligaría a cada lector a decidir qué hacer con él, y el
 * lector de esto es una condición de guarda.
 *
 * Sin foreign keys (regla del repo). Sin índice: la consulta que la usa ya filtra por
 * `demo_setup_status` y por fecha, y esta columna sólo desempata.
 */
return new class extends Migration
{
    /**
     * Agrega la columna del contador.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('demo_setup_intentos')->default(0)->after('demo_setup_last_error');
        });
    }

    /**
     * Revierte la columna agregada (vacío, como el resto de migraciones del repo).
     *
     * @return void
     */
    public function down()
    {
    }
};
