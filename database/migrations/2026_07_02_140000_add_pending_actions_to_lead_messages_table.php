<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega pending_actions a lead_messages: cuando un mensaje requiere verificación por el
 * motivo "agendamiento" (ver LeadAiService::requires_agendamiento_verification_gate), acá se
 * guarda el $parsed original de Claude (guardar_nombre, guardar_email, agendar_demo,
 * cancelar_demo, etc.) SIN aplicarlo — se aplica recién cuando el admin aprueba el mensaje
 * (ver LeadAiService::apply_pending_actions, llamado desde LeadSuggestionSendService::send_suggestion).
 * Null en el resto de los casos.
 */
class AddPendingActionsToLeadMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->json('pending_actions')->nullable()->after('calendar_snapshot');
        });
    }

    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('pending_actions');
        });
    }
}
