<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clients.es_ensayo` (misión cruzada prueba-e2e-campana-imperio, 11/11/2026): marca al cliente de una prueba
 * gratuita que es un ENSAYO del equipo y no un negocio real. Es la guarda dura de `pruebas:ensayo`, el comando
 * que simula el paso de los días de la prueba: sin esta marca se niega a correr.
 */
class AddEsEnsayoToClientsTable extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('es_ensayo')->default(false);
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('es_ensayo');
        });
    }
}
