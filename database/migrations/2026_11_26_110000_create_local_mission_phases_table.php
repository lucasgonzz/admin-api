<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `local_mission_phases`: el historial de fases de las misiones de «El local» (misión el-local-linux,
 * 25/11/2026, ampliación §2.E del plan).
 *
 * Una fila por misión, fase y vuelta (construcción → revisión → construcción da `construccion#1` y
 * `construccion#2`). La escribe `PcSnapshotService::save()` con lo que manda el reporter en
 * `misiones[].fase_historial` y la lee la escena para calcular cuánto lleva cada misión en su etapa. A
 * diferencia del snapshot (un espejo que se reescribe cada minuto en `admin_settings`), esto crece sin techo y
 * se consulta por misión y por fecha: por eso es una tabla.
 *
 * 🔴 Sólo forma del trabajo (clave de misión, fase, etapa, horarios); ningún texto del pedido ni de los chats.
 * Sin claves foráneas ni unique, con índices de nombre corto y columnas con default.
 */
class CreateLocalMissionPhasesTable extends Migration
{
    /**
     * Crea la tabla (idempotente: si ya existe no hace nada).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('local_mission_phases')) {
            return;
        }

        Schema::create('local_mission_phases', function (Blueprint $table) {
            $table->id();

            // Id de la misión del reporter: `cc:<slug>`, `oc:<tarjeta>`, `codex:<hilo>` o `wt:<rama>`.
            $table->string('mission_key', 80);

            // Origen y título (recortado a 120) de la misión la última vez que se la vio.
            $table->string('origen', 20)->nullable();
            $table->string('titulo', 120)->nullable();

            // Fase fina de la skill (plan, construccion, revision-1, suite-final…) y su etapa de las seis.
            $table->string('fase', 40);
            $table->string('etapa', 20)->nullable();

            // N.º de aparición de esa fase en el historial de la misión (1 la primera vez).
            $table->unsignedTinyInteger('vuelta')->default(1);

            // Cuándo empezó y terminó la fase (hasta null = es la actual), en la hora del admin.
            $table->dateTime('desde');
            $table->dateTime('hasta')->nullable();

            // Duración en segundos (null mientras la fase sigue abierta).
            $table->unsignedInteger('segundos')->nullable();

            // true si el reporter la dedujo de los archivos de la misión y no de un registro real.
            $table->boolean('inferida')->default(false);

            // Cuándo llegó al admin el último reporte de esta fila.
            $table->dateTime('reportado_at');

            $table->timestamps();

            // Consulta por misión y por rango de fechas (nombres cortos).
            $table->index('mission_key', 'lmp_mission_idx');
            $table->index('desde', 'lmp_desde_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('local_mission_phases');
    }
}
