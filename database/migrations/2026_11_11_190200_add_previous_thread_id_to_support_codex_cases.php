<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotación del hilo de Codex de un ticket (misión cruzada `ficha-del-cliente`, 11/11/2026): cuando la conversación
 * de Martín pasa los ~380.000 tokens, el monitor abre una nueva y la vincula con `claim` + `rotate_thread`. El caso no
 * tiene bitácora de cambios, así que el rastro del hilo anterior queda acá:
 *  - `previous_thread_id`: la conversación que se reemplazó (la última; una rotación nueva la pisa).
 *  - `thread_rotated_at`: cuándo.
 * Solo agrega columnas nullable: un admin viejo las ignora.
 */
class AddPreviousThreadIdToSupportCodexCases extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->string('previous_thread_id', 150)->nullable();
            $table->timestamp('thread_rotated_at')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn(['previous_thread_id', 'thread_rotated_at']);
        });
    }
}
