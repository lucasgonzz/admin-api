<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La casilla de correo del dueño del negocio, en la ficha del cliente.
 *
 * Hasta acá el admin tenía el teléfono del dueño (`clients.phone`) y nada más, porque el único
 * canal hacia el cliente era WhatsApp. El aviso de actualización necesita además una casilla: el
 * detalle de las novedades de una versión no entra en un WhatsApp y encima se pierde en el hilo.
 *
 * 🔴 Nace NULLABLE y vacía a propósito. Los ~45 clientes no tienen ninguna casilla cargada hoy, y
 * el dato bueno vive en el `empresa-api` de cada uno (el `User` dueño de la instancia). La columna
 * se va llenando sola: `ClientContactEmailResolver` le pregunta al cliente por
 * `admin-sync/contacto-dueno` la primera vez que lo necesita y escribe acá lo que trae, para no
 * volver a preguntar. Lo que ese endpoint no pueda contestar —el cliente todavía corre una versión
 * que no lo tiene— se carga a mano en la ficha.
 *
 * Es la FUENTE DE VERDAD del envío: lo que esté escrito acá le gana siempre a lo que conteste el
 * cliente, así que corregirla a mano en el panel alcanza para redirigir el aviso.
 */
class AddEmailToClientsTable extends Migration
{
    /**
     * Agrega la columna de la casilla, al lado del teléfono.
     *
     * El guard de `hasColumn` la vuelve idempotente: se puede correr sobre una base que ya la
     * tiene sin que reviente, que es la situación real de un slot al que se le mergea la rama
     * después de haberla probado.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('clients', 'email')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            // 150 y no 255: es el largo que usan el resto de las columnas de contacto del admin, y
            // una dirección de correo real no se acerca ni de lejos.
            $table->string('email', 150)->nullable()->after('phone');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('clients', 'email')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
}
