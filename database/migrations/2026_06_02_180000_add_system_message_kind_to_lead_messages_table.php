<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de mensaje automático del sistema (idempotencia aunque el texto sea editable desde Cuenta).
 *
 * Sin FK: relación por lead_id en Eloquent.
 */
class AddSystemMessageKindToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna nullable para distinguir bienvenida automática vs presentación.
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->string('system_message_kind', 40)->nullable()->index();
        });
    }

    /**
     * Quita la columna de tipo de mensaje automático.
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('system_message_kind');
        });
    }
}
