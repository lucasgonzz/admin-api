<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite desactivar por lead la respuesta automática de Claude sin afectar al resto.
 *
 * Sin FK: se actualiza desde admin-spa vía {@see \App\Http\Controllers\LeadController::toggle_claude_auto_reply_json}.
 */
class AddClaudeAutoReplyToLeadsTable extends Migration
{
    /**
     * Agrega columna booleana con default true (comportamiento actual para leads existentes).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('claude_auto_reply')->default(true)->after('tiene_sugerencia_pendiente');
        });
    }

    /**
     * Elimina la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('claude_auto_reply');
        });
    }
}
