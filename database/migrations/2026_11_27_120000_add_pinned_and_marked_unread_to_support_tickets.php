<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas manuales de la bandeja de soporte (misión soporte-fijar-no-leido): un ticket fijado sube arriba de todo y
 * un ticket marcado como no leído se pinta en amarillo hasta que alguien lo abre. Como la bandeja es una sola para
 * todos los operadores, las marcas viven en el ticket y no por operador.
 */
class AddPinnedAndMarkedUnreadToSupportTickets extends Migration
{
    /**
     * Agrega las dos columnas (nulas = sin marca).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('marked_unread_at')->nullable();
        });
    }

    /**
     * Quita las dos columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['pinned_at', 'marked_unread_at']);
        });
    }
}
