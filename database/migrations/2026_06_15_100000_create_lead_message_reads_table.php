<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de lectura por admin de cada mensaje de lead.
 *
 * Reemplaza el `read_at` global de `lead_messages` por un esquema per-usuario:
 * cada fila indica que un admin puntual ya leyó un mensaje puntual.
 * Un mensaje se considera no leído para un admin mientras no exista su fila aquí.
 */
class CreateLeadMessageReadsTable extends Migration
{
    /**
     * Crea la tabla `lead_message_reads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_message_reads', function (Blueprint $table) {
            $table->id();
            // Mensaje leído (referencia a lead_messages.id).
            $table->unsignedBigInteger('lead_message_id');
            // Admin que leyó el mensaje (referencia a admins.id).
            $table->unsignedBigInteger('admin_id');
            // Momento en que el admin abrió la conversación y marcó el mensaje como leído.
            $table->timestamp('read_at');

            // Un admin no puede tener dos registros de lectura para el mismo mensaje.
            $table->unique(['lead_message_id', 'admin_id'], 'lmr_msg_admin_uq');

            // FKs con borrado en cascada: al eliminar el mensaje o el admin se limpian las lecturas.
            $table->foreign('lead_message_id')->references('id')->on('lead_messages')->onDelete('cascade');
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla `lead_message_reads`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_message_reads');
    }
}
