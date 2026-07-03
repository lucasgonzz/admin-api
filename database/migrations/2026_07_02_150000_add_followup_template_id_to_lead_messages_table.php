<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `followup_template_id` a `lead_messages`: registra qué FollowupTemplate se intentó enviar
 * en un seguimiento automático, haya salido bien o mal.
 *
 * Se usa para dos cosas (prompt 245, 2/7/2026):
 *  1. Excluir del conteo de max_followups los seguimientos que fallaron al enviarse (whatsapp_message_id
 *     null) — ver LeadFollowupService::process_lead()/force_followup_now().
 *  2. Permitir que un reintento manual (BatchLeadAiRecoveryService::retry_failed_followups(), prompt 246)
 *     reenvíe exactamente la misma plantilla sin tener que volver a resolverla por el estado actual del
 *     lead, que pudo haber cambiado entre el intento fallido y el reintento.
 *
 * Sin FK declarativa, mismo criterio que el resto de `lead_messages` (relación por columna en Eloquent).
 */
class AddFollowupTemplateIdToLeadMessagesTable extends Migration
{
    /**
     * Agrega la columna `followup_template_id` a `lead_messages`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Plantilla (followup_templates.id) que este mensaje intentó enviar. Null en mensajes que
            // no son seguimientos por plantilla (ej: notify_closer_for_followup, sugerencias de Claude).
            $table->unsignedBigInteger('followup_template_id')->nullable()->index();
        });
    }

    /**
     * Elimina la columna `followup_template_id`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('followup_template_id');
        });
    }
}
