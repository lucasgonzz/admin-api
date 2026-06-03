<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrador de sugerencia IA en el hilo de conversación antes del envío automático por WhatsApp.
 */
class AddAiDraftFieldsToSupportMessages extends Migration
{
    /**
     * Agrega flag de borrador y timestamp de auto-envío al mensaje sugerido.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->boolean('is_ai_suggestion_draft')->default(false)->after('body')->index();
            $table->timestamp('ai_auto_send_at')->nullable()->after('is_ai_suggestion_draft');
        });
    }

    /**
     * Revierte columnas de borrador IA en mensajes de soporte.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn([
                'is_ai_suggestion_draft',
                'ai_auto_send_at',
            ]);
        });
    }
}
