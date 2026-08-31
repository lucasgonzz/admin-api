<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a client_apis la marca de cuándo se aprovisionó el hosting de esa API.
 */
class AddHostingProvisionedAtToClientApisTable extends Migration
{
    /**
     * Agrega la columna hosting_provisioned_at.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            // null = esta API nunca se aprovisionó, que es el estado de todas las filas de hoy: por
            // eso tampoco necesita backfill.
            //
            // 🔴 Es para la interfaz y para el log ("esta API ya se aprovisionó el X"), NO para
            // decidir si hay que aprovisionar. La idempotencia se verifica siempre contra el
            // proveedor de verdad: alguien puede haber borrado el subdominio a mano desde hPanel y
            // esta columna no se enteraría nunca. Saltear el paso mirando esta fecha sería dar por
            // hecho un subdominio que ya no existe.
            $table->timestamp('hosting_provisioned_at')->nullable()->after('provisioning_secrets');
        });
    }

    /**
     * Saca la columna hosting_provisioned_at.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->dropColumn('hosting_provisioned_at');
        });
    }
}
