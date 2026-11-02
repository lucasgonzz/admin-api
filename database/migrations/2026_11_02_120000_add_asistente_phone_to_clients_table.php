<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El teléfono PERSONAL del dueño, separado del teléfono del negocio (misión telefono-personal-del-dueno,
 * 2/11/2026).
 *
 * `clients.phone` cumplía dos roles a la vez: el WhatsApp del NEGOCIO (el que se conecta a Kapso para que el
 * agente atienda a los clientes del cliente) y el número del DUEÑO como persona (el desde el que le escribe a su
 * agente personal y al que el admin le escribe). Casi siempre coinciden, pero no siempre: el Mayorista de pesca
 * (cliente 64) tiene un número de negocio y el dueño escribe desde otro.
 *
 * 🔴 Nullable y sin default a propósito: con la columna en NULL todo se comporta EXACTAMENTE como antes
 * (`Client::telefono_personal()` cae a `phone`). Es lo que hace seguro desplegar sin tocar a ningún cliente.
 */
class AddAsistentePhoneToClientsTable extends Migration
{
    /**
     * Agrega la columna del teléfono personal del dueño.
     *
     * El guard de `hasColumn` la vuelve idempotente: se puede correr sobre una base que ya la tiene.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('clients', 'asistente_phone')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            // Teléfono personal del dueño (el de su agente personal). NULL = usa `phone`.
            $table->string('asistente_phone', 40)->nullable();
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('clients', 'asistente_phone')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('asistente_phone');
        });
    }
}
