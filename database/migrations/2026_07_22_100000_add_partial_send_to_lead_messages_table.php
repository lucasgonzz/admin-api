<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contabilidad honesta del envío parcial de una sugerencia partida en varios mensajes (lead #440, 22/7/2026).
 *
 * Antes de este cambio, si una sugerencia de N partes fallaba a mitad de camino (ej. 409 de Kapso
 * por "otro mensaje en vuelo"), LeadSuggestionSendService::send_body() solo devolvía el id de la
 * última parte enviada, y send_suggestion() interpretaba un null como "no se envió nada": marcaba
 * el mensaje `rechazado` aunque el lead hubiera recibido varias partes reales. Estas tres columnas
 * permiten registrar el desenlace intermedio (envío parcial) sin mentir en ningún sentido.
 *
 * - sent_parts_count: cuántas partes salieron efectivamente.
 * - total_parts_count: en cuántas partes se dividió el mensaje original.
 * - partial_send_pending: texto (unido con el separador "\n---\n") de las partes que NO se
 *   enviaron, para que el setter pueda copiarlas y mandarlas a mano.
 *
 * Las tres quedan en null para los mensajes históricos y para los envíos de una sola parte que
 * salen bien (caso mayoritario, sin cambio de comportamiento).
 */
class AddPartialSendToLeadMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('sent_parts_count')->nullable();
            $table->unsignedSmallInteger('total_parts_count')->nullable();
            $table->text('partial_send_pending')->nullable();
        });
    }

    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn(['sent_parts_count', 'total_parts_count', 'partial_send_pending']);
        });
    }
}
