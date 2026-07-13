<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el teléfono E.164 de la contraparte a cada mensaje de implementación.
 *
 * Es el destino en mensajes outbound y el remitente en mensajes inbound. Necesario
 * para calcular la ventana de 24 h de WhatsApp por persona (el dueño y el responsable
 * de migración son dos ventanas distintas, ya que pueden ser teléfonos diferentes).
 */
class AddPhoneToImplementationMessagesTable extends Migration
{
    /**
     * Agrega la columna `phone` a implementation_messages.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('implementation_messages', function (Blueprint $table) {
            // Teléfono E.164 de la contraparte: destino en outbound, remitente en inbound.
            // Nullable porque los mensajes históricos no lo tienen (se tratan como ventana cerrada).
            $table->string('phone', 30)->nullable()->after('direction');
        });
    }

    /**
     * Elimina la columna `phone` de implementation_messages.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('implementation_messages', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
}
