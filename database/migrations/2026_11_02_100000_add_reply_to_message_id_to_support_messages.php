<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responder citando en la conversación de soporte: el mensaje guarda a cuál responde.
 *
 * Sin clave foránea a propósito: si el mensaje citado desaparece, la cita queda colgada y el
 * panel muestra «Mensaje no disponible» en vez de que el borrado del original falle o arrastre
 * a las respuestas.
 */
class AddReplyToMessageIdToSupportMessages extends Migration
{
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('reply_to_message_id')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropIndex(['reply_to_message_id']);
            $table->dropColumn('reply_to_message_id');
        });
    }
}
