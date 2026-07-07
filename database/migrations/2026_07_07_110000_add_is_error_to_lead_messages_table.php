<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca un LeadMessage como registro de ERROR de sistema (fallo de envío o de generación de IA).
 *
 * Va siempre junto con is_status_event=true (no es actividad real del hilo). MessageBubble lo
 * renderiza como bloque rojo (prompt 300); LeadPendingReviewService lo usa como señal de "error sin
 * resolver" para marcar el lead como pendiente de revisión (prompt 302).
 */
class AddIsErrorToLeadMessagesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->boolean('is_error')->default(false);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('is_error');
        });
    }
}
