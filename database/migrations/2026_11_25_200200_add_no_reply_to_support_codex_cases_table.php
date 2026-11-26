<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carril «no necesita respuesta» de Martín (misión martin-descripcion-y-actualizaciones, 25/11/2026, contrato C1).
 *
 * `no_reply_message_id`: el último entrante que Martín dio por atendido sin responder (un acuse, un «ok»).
 * `no_reply_reason`: por qué (lo ve Lucas en el contexto del ticket). Ambas nullable: los casos existentes no
 * cambian. Sin FK.
 */
class AddNoReplyToSupportCodexCasesTable extends Migration
{
    /**
     * Agrega las dos columnas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->unsignedBigInteger('no_reply_message_id')->nullable();
            $table->string('no_reply_reason', 300)->nullable();
        });
    }

    /**
     * Saca las columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn(['no_reply_message_id', 'no_reply_reason']);
        });
    }
}
