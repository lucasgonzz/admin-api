<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de SOLO LECTURA del centro de recursos (misión centro-recursos-soporte-y-tema, 14/10/2026).
 *
 * Cuando el pase viene de un login maestro (soporte entrando como el dueño), la sesión que abre el admin
 * es de solo lectura: se ve el avance del dueño pero no se guarda nada. Este flag es lo que lee el
 * middleware `recursos.escritura` para cortar con 403 las rutas que escriben.
 *
 * Default `false`: las sesiones que ya existen (y todas las que abra un `empresa-api` viejo, que no
 * manda `soporte`) quedan como siempre. Con guarda `hasColumn` para que se pueda volver a correr.
 */
class AddSoloLecturaToRecursosSesionesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('recursos_sesiones') || Schema::hasColumn('recursos_sesiones', 'solo_lectura')) {
            return;
        }

        Schema::table('recursos_sesiones', function (Blueprint $table) {
            $table->boolean('solo_lectura')->default(false)->after('last_used_at');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('recursos_sesiones') || ! Schema::hasColumn('recursos_sesiones', 'solo_lectura')) {
            return;
        }

        Schema::table('recursos_sesiones', function (Blueprint $table) {
            $table->dropColumn('solo_lectura');
        });
    }
}
