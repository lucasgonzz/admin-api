<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega los campos de Recall.ai a la tabla leads:
 *
 * - call_summary: JSON estructurado extraído por Claude de la transcripción de la llamada del closer.
 * - recall_bot_id: ID del bot de Recall.ai enviado a la reunión del lead; null si no fue enviado aún.
 */
class AddRecallFieldsToLeadsTable extends Migration
{
    /**
     * Agrega call_summary y recall_bot_id a la tabla leads.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            /* Resumen estructurado de la llamada del closer, generado por Claude. */
            $table->text('call_summary')->nullable()->after('meet_url');

            /* ID del bot de Recall.ai enviado a la reunión del lead. */
            $table->string('recall_bot_id')->nullable()->after('call_summary');
        });
    }

    /**
     * Elimina los campos recall de la tabla leads.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['call_summary', 'recall_bot_id']);
        });
    }
}
