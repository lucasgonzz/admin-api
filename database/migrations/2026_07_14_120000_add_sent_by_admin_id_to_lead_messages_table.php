<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `sent_by_admin_id` a `lead_messages` (prompt 403): admin que puso este mensaje saliente
 * en la vía — autor cuando `sender='setter'` (envío directo desde el panel: texto, audio o
 * plantilla), aprobador cuando `sender='sistema'` y se aprobó desde el panel (approve_message_json,
 * approve_message_with_edit_json, approve_message_with_actions_json). Queda `null` cuando la IA lo
 * auto-envió (AutoSendLeadAiSuggestionJob, respaldo sin revisión humana) o cuando es historial
 * importado por pegado de WhatsApp (store_message_json).
 *
 * Sin FK declarativa, mismo criterio que el resto de `lead_messages` (relación por columna en
 * Eloquent, ver LeadMessage::sent_by_admin()).
 */
class AddSentByAdminIdToLeadMessagesTable extends Migration
{
    /**
     * Agrega la columna `sent_by_admin_id` (nullable, indexada) después de `status`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Admin (admins.id) autor/aprobador del envío saliente. Null si lo auto-envió la IA o
            // si es historial importado por pegado (ver docblock de la clase).
            $table->unsignedBigInteger('sent_by_admin_id')->nullable()->after('status')->index();
        });
    }

    /**
     * Elimina la columna `sent_by_admin_id`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('sent_by_admin_id');
        });
    }
}
