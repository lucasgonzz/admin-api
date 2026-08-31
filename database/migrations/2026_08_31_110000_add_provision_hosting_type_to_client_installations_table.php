<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a client_installations el tipo de hosting a aprovisionar antes de instalar.
 *
 * Nace del aprovisionamiento del hosting del cliente: el mismo botón que hoy instala el sistema
 * puede, además, crear antes los 4 subdominios, la base de datos y el cron.
 */
class AddProvisionHostingTypeToClientInstallationsTable extends Migration
{
    /**
     * Agrega la columna provision_hosting_type.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_installations', function (Blueprint $table) {
            // null = NO aprovisionar (comportamiento de siempre).
            // 'shared_hosting' | 'vps' = correr el bloque de aprovisionamiento contra ese hosting.
            //
            // 🔴 El default es null, y es lo que hace que TODA fila ya existente siga corriendo el
            // pipeline de siempre, byte por byte, sin una sola sentencia de backfill: el código lee
            // esta columna y, si está vacía, ni arma los pasos nuevos. Mismo criterio que el `kind`
            // del 24/8 pero al revés —allá el default era un valor, acá es la ausencia—, porque acá
            // la ausencia SÍ tiene un significado propio y correcto: "no aprovisiones nada".
            //
            // 🔴 Una columna nullable y NO un booleano + un tipo. Dos columnas admiten el estado
            // imposible provision=true con tipo=null, y cada lector tendría que decidir qué hacer
            // con él. Con una sola no hay combinación inválida que inventar.
            //
            // Sin enum de base (ni de PHP: el proyecto corre 7.4). Los valores válidos viven en
            // ClientInstallation::PROVISION_* y en la validación del controlador.
            //
            // Sin índice: nunca se filtra por esta columna. Sin FK: convención del proyecto.
            $table->string('provision_hosting_type', 20)->nullable()->default(null)->after('group_uuid');
        });
    }

    /**
     * Saca la columna provision_hosting_type.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_installations', function (Blueprint $table) {
            $table->dropColumn('provision_hosting_type');
        });
    }
}
