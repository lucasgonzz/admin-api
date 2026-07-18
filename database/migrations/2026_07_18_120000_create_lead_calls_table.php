<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tabla `lead_calls` — refactor "múltiples llamadas por lead" (grupo 115, prompt 484).
 *
 * Hoy un lead tiene una sola llamada del closer, con los datos sueltos en columnas de `leads`
 * (meet_url, google_event_id, recall_bot_id, call_summary). Esta tabla nueva permite N llamadas
 * por lead, cada una con su propio Meet, evento de calendario, bot de Recall.ai, transcripción
 * completa y resumen estructurado. Las columnas viejas de `leads` NO se tocan (compatibilidad
 * hacia atrás); el backfill que las copia acá vive en un prompt aparte (485).
 *
 * Sin foreign keys: regla del workspace, no usar `foreign()`/`constrained()` en migraciones
 * nuevas. La relación lógica (lead_calls.lead_id -> leads.id) se implementa en Eloquent.
 */
class CreateLeadCallsTable extends Migration
{
    /**
     * Ejecuta la migración (crea la tabla `lead_calls`).
     */
    public function up()
    {
        Schema::create('lead_calls', function (Blueprint $table) {
            // Clave primaria de la llamada.
            $table->id();

            // Lead dueño de la llamada. Sin FK (regla del workspace); indexado para joins/filtros.
            $table->unsignedBigInteger('lead_id')->index();

            // Link de Google Meet de ESTA llamada (propio, no compartido con otras llamadas del lead).
            $table->string('meet_url')->nullable();

            // Id del evento en el Google Calendar del closer correspondiente a ESTA llamada.
            $table->string('google_event_id')->nullable();

            // Id del bot de Recall.ai de ESTA llamada. Indexado: el webhook de Recall busca la
            // llamada por acá (hoy busca el lead directo por leads.recall_bot_id).
            $table->string('recall_bot_id')->nullable()->index();

            // Transcripción completa formateada de la llamada. No existía antes del refactor
            // (antes solo se guardaba el resumen); a partir de acá sí se persiste completa.
            $table->longText('transcript')->nullable();

            // JSON del resumen estructurado extraído por Claude (mismo shape que leads.call_summary
            // hoy). Se castea a array en el modelo LeadCall.
            $table->text('call_summary')->nullable();

            // Ciclo de vida de la llamada: 'pendiente' (creada, sin transcripción todavía) o
            // 'completada' (llegó la transcripción de Recall). String plano, sin enum de BD/PHP.
            $table->string('estado', 20)->default('pendiente');

            // Fecha/hora para la que se agendó la llamada, si aplica.
            $table->timestamp('scheduled_at')->nullable();

            // Fecha/hora en que se inició/tuvo efectivamente la llamada.
            $table->timestamp('started_at')->nullable();

            // Timestamps estándar de creación/actualización.
            $table->timestamps();
        });
    }

    /**
     * Revierte la migración (elimina la tabla `lead_calls`).
     */
    public function down()
    {
        Schema::dropIfExists('lead_calls');
    }
}
