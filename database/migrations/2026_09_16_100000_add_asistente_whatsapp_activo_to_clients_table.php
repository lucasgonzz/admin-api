<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El interruptor por cliente del asistente por WhatsApp.
 *
 * 🔴 Nace en `false` a propósito, y no es prudencia genérica: es lo único que hace seguro el
 * despliegue escalonado. Las cuatro rutas que este canal consume viven en el `empresa-api` del
 * cliente (`api/admin-sync/asistente/*`), y los 40+ clientes corren versiones distintas: durante
 * semanas la mayoría NO las va a tener y va a responder 404. Con la columna en `false` ese
 * cliente sigue cayendo en la rama de soporte de siempre y nadie se entera de nada; recién se
 * prende cliente por cliente, DESPUÉS de actualizarlo y de cargarle la `api_key`.
 *
 * Va indexada porque el comando de los informes de la mañana (`asistente:enviar-informes`)
 * arranca justamente filtrando por esta columna sobre la tabla entera de clientes.
 */
class AddAsistenteWhatsappActivoToClientsTable extends Migration
{
    /**
     * Agrega la columna del interruptor.
     *
     * El guard de `hasColumn` la vuelve idempotente: se puede correr sobre una base que ya la
     * tiene sin que reviente, que es la situación real de un slot al que se le mergea la rama
     * después de haberla probado.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('clients', 'asistente_whatsapp_activo')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            // El dueño de este cliente habla con el asistente de IA de su propio sistema por
            // WhatsApp, en vez de que su mensaje abra un ticket de soporte.
            $table->boolean('asistente_whatsapp_activo')->default(false)->index();
        });
    }

    /**
     * Saca la columna del interruptor.
     *
     * Se saca el índice a mano antes de la columna: MySQL no deja borrar una columna indexada
     * sin antes borrar su índice, y el nombre que le pone Laravel es el de la convención.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('clients', 'asistente_whatsapp_activo')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_asistente_whatsapp_activo_index');
            $table->dropColumn('asistente_whatsapp_activo');
        });
    }
}
