<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deja constancia de que un mensaje lo escribió el agente, y no una persona.
 *
 * Apenas se aprueba un borrador, `is_ai_suggestion_draft` pasa a false y `sender_admin_id`
 * queda con el operador que lo aprobó: sin este sello, un mensaje que el agente escribió y
 * salió tal cual queda indistinguible de uno tipeado a mano. Y ese es justo el caso más
 * interesante para medir cómo viene el agente — el que no hizo falta corregir.
 *
 * Junto con `ai_original_body` deja responder las tres preguntas del análisis: cuántas
 * sugerencias salieron sin tocar, cuántas se corrigieron, y qué se les cambió.
 */
class AddAiGeneratedAtToSupportMessagesTable extends Migration
{
    /**
     * Agrega el sello de autoría del agente.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->timestamp('ai_generated_at')
                  ->nullable()
                  ->after('ai_original_body')
                  ->comment('Momento en que el agente generó este texto. Null si lo escribió una persona.');
        });
    }

    /**
     * Elimina el sello de autoría del agente.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('ai_generated_at');
        });
    }
}
