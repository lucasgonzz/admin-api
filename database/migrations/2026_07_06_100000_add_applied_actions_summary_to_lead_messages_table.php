<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega applied_actions_summary a lead_messages: registro persistido (en español, legible)
 * de las acciones que efectivamente se aplicaron al enviar/aprobar este mensaje (agendar demo,
 * guardar email, guardar nombre, cambio de estado, etc.), para poder mostrarlas en la burbuja
 * después de aprobar aunque `pending_actions` ya se haya limpiado a null (ver
 * LeadMessage::build_actions_summary y LeadAiService::apply_parsed_response, prompt 277).
 * Null cuando el mensaje no ejecutó ninguna acción estructurada.
 */
class AddAppliedActionsSummaryToLeadMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->json('applied_actions_summary')->nullable()->after('pending_actions');
        });
    }

    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('applied_actions_summary');
        });
    }
}
