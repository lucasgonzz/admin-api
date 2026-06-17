<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla admin_calendar_connections para almacenar la conexión OAuth
 * de Google Calendar de cada admin closer. Se mantiene separada de admins
 * porque las credenciales OAuth tienen ciclo de vida propio (conexión/desconexión/expiración).
 */
class CreateAdminCalendarConnectionsTable extends Migration
{
    /**
     * Crea la tabla con los campos necesarios para la integración OAuth de Google Calendar.
     */
    public function up()
    {
        Schema::create('admin_calendar_connections', function (Blueprint $table) {
            $table->id();

            // Referencia al admin propietario de esta conexión (único por admin).
            $table->unsignedBigInteger('admin_id')->unique();

            // Refresh token cifrado con Crypt::encryptString(); nunca se expone en JSON.
            $table->text('google_refresh_token_encrypted');

            // ID del calendario DEDICADO elegido por el closer (no el primario/personal).
            $table->string('google_calendar_id');

            // Cuenta de Google conectada (informativo, para mostrar en la UI).
            $table->string('google_account_email')->nullable();

            // Fecha de la primera conexión OAuth exitosa.
            $table->timestamp('connected_at')->nullable();

            // Última vez que se consultó Google Calendar con éxito (diagnóstico).
            $table->timestamp('last_synced_at')->nullable();

            // Permite desactivar la conexión sin borrar el registro histórico.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla de conexiones de Google Calendar.
     */
    public function down()
    {
        Schema::dropIfExists('admin_calendar_connections');
    }
}
