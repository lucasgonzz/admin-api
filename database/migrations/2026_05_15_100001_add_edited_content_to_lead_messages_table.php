<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto final enviado por el setter cuando modifica una sugerencia de IA antes de aprobar.
 *
 * Sin FK declarativa: relación por `lead_id` en Eloquent.
 */
class AddEditedContentToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna `edited_content` a `lead_messages`.
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->text('edited_content')->nullable()->after('content');
        });
    }

    /**
     * Elimina columna `edited_content` de `lead_messages`.
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('edited_content');
        });
    }
}
