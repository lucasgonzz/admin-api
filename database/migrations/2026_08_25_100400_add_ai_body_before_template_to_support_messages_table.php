<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el texto tal como salió del agente, antes de que la plantilla de Meta lo envuelva.
 *
 * Necesita columna propia y no puede compartir `ai_original_body`: esa significa una sola cosa
 * —"el operador corrigió lo que el agente proponía"— y el historial que se le manda a Claude la
 * lee justamente así, para etiquetar la línea como corregida. Si el envío por plantilla
 * escribiera ahí, cada respuesta aprobada sin tocar una coma fuera de la ventana de 24hs le
 * diría al agente que lo corrigieron, mostrándole como "corrección" su propio texto envuelto en
 * el saludo de la plantilla. O sea: le enseñaríamos a imitar el error, y de paso el conteo de
 * "cuántas salieron sin corregir" quedaría en cero.
 */
class AddAiBodyBeforeTemplateToSupportMessagesTable extends Migration
{
    /**
     * Agrega el texto previo al envoltorio de la plantilla.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->text('ai_body_before_template')
                  ->nullable()
                  ->after('ai_generated_at')
                  ->comment('Texto tal como salió del agente, antes de envolverlo en la plantilla de Meta.');
        });
    }

    /**
     * Elimina el texto previo al envoltorio.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('ai_body_before_template');
        });
    }
}
