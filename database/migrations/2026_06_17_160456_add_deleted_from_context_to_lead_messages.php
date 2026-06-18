<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna deleted_from_context a lead_messages.
 *
 * Permite marcar mensajes como excluidos del historial enviado a Claude
 * sin borrarlos físicamente (WhatsApp no admite borrado real).
 * Los mensajes marcados siguen siendo visibles en la UI pero aparecen
 * visualmente como eliminados y no se incluyen en el contexto de la IA.
 */
class AddDeletedFromContextToLeadMessages extends Migration
{
    /**
     * Aplica la migración.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            /* Indica que el mensaje debe excluirse del contexto enviado a Claude. */
            $table->boolean('deleted_from_context')->default(false)->after('ai_auto_send_at');
        });
    }

    /**
     * Revierte la migración.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('deleted_from_context');
        });
    }
}
