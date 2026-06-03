<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales SSH globales por tipo de hosting (shared_hosting, vps).
 * Tabla de bajo volumen: una fila por tipo.
 */
class CreateClientSshCredentialsTable extends Migration
{
    /**
     * Crea la tabla client_ssh_credentials.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_ssh_credentials', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Tipo de hosting: shared_hosting | vps.
            $table->string('type');

            // Host del servidor SSH.
            $table->string('host');

            // Puerto SSH (por defecto 22).
            $table->unsignedInteger('port')->default(22);

            // Usuario SSH.
            $table->string('username');

            // Contraseña cifrada con encrypt() / cast encrypted en el modelo.
            $table->text('password');

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla client_ssh_credentials.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_ssh_credentials');
    }
}
