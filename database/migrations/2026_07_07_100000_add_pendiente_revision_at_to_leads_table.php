<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca "pendiente de revisión" para un lead (global, no per-admin).
 *
 * null = el lead no está pendiente. Con fecha = marcado para revisar por el botón de revisión
 * (LeadPendingReviewService, prompt 302): su último mensaje quedó sin responder, o hubo un error
 * de envío/generación sin resolver. La grilla de leads pinta esas filas en rojo (prompt 296). Se
 * limpia al abrir la conversación (mark_whatsapp_messages_read_json, prompt 302). Análoga a pinned_at.
 */
class AddPendienteRevisionAtToLeadsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('pendiente_revision_at')->nullable()->after('pinned_at');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('pendiente_revision_at');
        });
    }
}
