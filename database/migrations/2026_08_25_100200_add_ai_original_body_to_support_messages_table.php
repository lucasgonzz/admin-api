<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el texto ORIGINAL del agente cuando el operador lo edita antes de mandarlo.
 *
 * `body` sigue siendo lo que el cliente realmente recibió —es lo que se muestra en la
 * conversación y lo que no hay que tocar—, y acá queda lo que el agente había propuesto.
 * Teniendo el par (propuesto, enviado) se puede medir en qué se equivoca el agente, que es
 * para lo que Lucas lo pidió: "que quede guardada esa edición para luego analizarla".
 *
 * Es el mismo mecanismo que `lead_messages.edited_content` del lado de leads, con los roles
 * invertidos a propósito: allá `content` es el original y `edited_content` el final; acá el
 * final se queda en `body` para no romper todo lo que ya lo lee.
 */
class AddAiOriginalBodyToSupportMessagesTable extends Migration
{
    /**
     * Agrega la columna con el texto propuesto por el agente.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->text('ai_original_body')
                  ->nullable()
                  ->after('ai_auto_send_at')
                  ->comment('Texto que había propuesto el agente, cuando el operador lo editó antes de enviarlo.');
        });
    }

    /**
     * Elimina la columna del texto original del agente.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('ai_original_body');
        });
    }
}
