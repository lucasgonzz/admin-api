<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llamadas telefónicas de Juan (el vendedor) con los leads (misión embudo-juan-pipeline, etapa E1 y E1b).
 *
 * Cada fila es una llamada registrada con «Registrar llamada» del tablero, a mano o desde un audio de Juan que carga
 * el agente de OpenClaw. No es `lead_calls` (esas son las videollamadas Meet del closer, con su transcripción).
 *
 * Sin claves foráneas, con defaults y con índices cortos (regla de migraciones del workspace). La idempotencia de los
 * audios es por `(lead_id, origen_clave)` y se resuelve en el servicio, dentro del candado del lead: no hay índice único
 * compuesto.
 */
class CreateLeadLlamadasTable extends Migration
{
    /**
     * Crea la tabla `lead_llamadas`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_llamadas', function (Blueprint $table) {
            $table->bigIncrements('id');
            /* Lead al que se llamó. */
            $table->unsignedBigInteger('lead_id')->index('lead_llam_lead_idx');
            /* Tarjeta del tablero en el momento de la llamada (puede borrarse después: sin clave foránea). */
            $table->unsignedBigInteger('opportunity_id')->nullable();
            /* Admin que hizo la llamada (Juan). */
            $table->unsignedBigInteger('admin_id')->nullable();
            /* ¿Atendió? Sin atender no hay resultado. */
            $table->boolean('atendio')->default(false);
            /* Uno de LeadLlamada::RESULTADOS. */
            $table->string('resultado', 30)->nullable();
            /* Motivo de pérdida (sólo con «no le interesa»). */
            $table->string('motivo', 255)->nullable();
            /* Lo que Juan le cotizó (texto libre, ej. «Gestión $628.400, mitad $314.200»). */
            $table->string('cotizado', 255)->nullable();
            $table->text('notas')->nullable();
            /* Próxima llamada pedida (sin atender o «volver a llamar»). */
            $table->dateTime('proxima_llamada_at')->nullable();
            /* E1b: `manual` (el modal) o `audio_juan` (el agente leyó un audio de Juan). */
            $table->string('origen', 20)->default('manual');
            /* E1b: quién cargó la llamada (el agente); `admin_id` es quien la hizo. */
            $table->unsignedBigInteger('cargada_por_admin_id')->nullable();
            /* E1b: clave de idempotencia del audio (ej. `wa:<sha256>`). */
            $table->string('origen_clave', 120)->nullable()->index('lead_llam_origen_idx');
            /* E1b: transcripción completa del audio. */
            $table->text('transcripcion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_llamadas');
    }
}
