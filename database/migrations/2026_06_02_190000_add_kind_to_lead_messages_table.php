<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de mensaje WhatsApp (text, audio, image, …) alineado a support_messages.kind.
 *
 * Sin FK: relación por lead_id en Eloquent.
 */
class AddKindToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna kind con default text para mensajes históricos.
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->string('kind', 20)->default('text')->index();
        });
    }

    /**
     * Quita la columna kind.
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
}
