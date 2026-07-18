<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el nombre de archivo original (el que mandó el lead) a lead_message_attachments.
 *
 * Sin este dato solo teníamos el nombre generado en disco (wa_ab12cd34.xlsx), por lo que
 * la descarga forzada (prompt 464) no podía ofrecer el nombre real del archivo.
 * Sin FK declarativa, siguiendo el patrón de la migración original de la tabla.
 */
class AddOriginalFilenameToLeadMessageAttachmentsTable extends Migration
{
    /**
     * Agrega la columna original_filename (nullable) después de mime, con guard hasColumn.
     */
    public function up()
    {
        if (! Schema::hasColumn('lead_message_attachments', 'original_filename')) {
            Schema::table('lead_message_attachments', function (Blueprint $table) {
                $table->string('original_filename', 255)->nullable()->after('mime');
            });
        }
    }

    /**
     * Revierte agregando el drop de la columna, con guard hasColumn.
     */
    public function down()
    {
        if (Schema::hasColumn('lead_message_attachments', 'original_filename')) {
            Schema::table('lead_message_attachments', function (Blueprint $table) {
                $table->dropColumn('original_filename');
            });
        }
    }
}
