<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `local_oficina_slots`: la foto de las oficinas de «El local» (misión el-local, 3/11/2026).
 *
 * Una fila por slot del pool de la PC de Lucas, que el reporter local (`herramientas/el-local/reporter.py`)
 * reescribe cada minuto con `POST api/claude/local/oficina` (contrato A del plan). Es un ESPEJO, no un
 * historial: se hace upsert por `slot` y la vista lee las filas tal cual.
 *
 * 🔴 Lo que se guarda es solo la forma del trabajo (slot, proyecto, estado, rama, slug de la misión, fase,
 * roles y horarios). Ningún texto de plan, nombre de chat ni dato de ticket: el reporter no los manda y
 * esta tabla no tiene dónde ponerlos.
 *
 * Sin claves foráneas, como el resto del repo, y con índice de nombre corto.
 */
class CreateLocalOficinaSlotsTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('local_oficina_slots')) {
            return;
        }

        Schema::create('local_oficina_slots', function (Blueprint $table) {
            $table->id();

            // Número del slot en el pool (s1..s35 hoy; el contrato admite hasta 60 filas).
            $table->unsignedSmallInteger('slot');

            // admin | empresa | tienda (o lo que diga pool.json), null si el reporter no lo sabe.
            $table->string('proyecto', 20)->nullable();

            // libre | trabajando | colgado | huerfano.
            $table->string('estado', 20)->default('libre');

            // Rama actual de la api del slot, slug de la misión y fase (tomado … cierre).
            $table->string('rama', 120)->nullable();
            $table->string('mision', 120)->nullable();
            $table->string('fase', 20)->nullable();

            // Horarios que manda el reporter, guardados en la hora del admin (America/Argentina/Buenos_Aires).
            $table->dateTime('claim_iniciado_at')->nullable();
            $table->dateTime('ultima_actividad_at')->nullable();

            // Archivos modificados sin commitear (`git status --porcelain -uno`).
            $table->unsignedSmallInteger('sucios')->default(0);

            // Agentes activos ahora en el slot: [{rol, runtime, estado, desde_at, nombre}].
            $table->json('agentes')->nullable();

            // Cuándo llegó al admin el último reporte de esta fila.
            $table->dateTime('reportado_at')->nullable();

            $table->timestamps();

            $table->unique('slot', 'los_slot_uq');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('local_oficina_slots');
    }
}
