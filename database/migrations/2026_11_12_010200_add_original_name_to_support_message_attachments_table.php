<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `support_message_attachments.original_name` (misión cruzada prueba-e2e-campana-imperio, 11/11/2026): el
 * nombre con el que el negocio subió el archivo por Soporte de su sistema («lista de precios.xlsx»). Hasta
 * ahora solo se guardaba el nombre de disco. Opcional: los adjuntos anteriores y los de WhatsApp quedan en null.
 */
class AddOriginalNameToSupportMessageAttachmentsTable extends Migration
{
    public function up()
    {
        Schema::table('support_message_attachments', function (Blueprint $table) {
            $table->string('original_name', 191)->nullable()->after('path');
        });
    }

    public function down()
    {
        Schema::table('support_message_attachments', function (Blueprint $table) {
            $table->dropColumn('original_name');
        });
    }
}
