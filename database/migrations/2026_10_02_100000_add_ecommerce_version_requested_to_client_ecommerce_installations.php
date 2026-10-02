<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `client_ecommerce_installations.ecommerce_version_requested`: si la versión de la corrida la
 * PIDIÓ quien la creó (`ecommerce_version_id` / `version` en el pedido) o es la última publicada que
 * el admin resolvió solo (misión cruzada `versiones-tienda`, chequeo independiente del 2/10/2026).
 *
 * POR QUÉ HACE FALTA, si la corrida ya guarda `ecommerce_version_id`. Los endpoints (claude/* y el
 * panel) fijan la versión al crear la corrida también cuando no se pidió ninguna —para que la
 * respuesta diga qué se va a desplegar—, así que mirando sólo el id no se distingue "me pidieron la
 * 1.0.0" de "la 1.0.0 era la última". Y la diferencia gobierna un freno: si a la versión le falta un
 * asset y `DEPLOY_PERMITIR_BUILD_EN_VPS` está prendida, una versión que alguien PIDIÓ falla (no se
 * le despliega master a quien pidió una versión), y una resuelta sola cae a la vía vieja.
 *
 * Default false: las corridas anteriores no tenían cómo pedir versión, así que ninguna la pidió.
 */
class AddEcommerceVersionRequestedToClientEcommerceInstallations extends Migration
{
    /**
     * Agrega la columna, después de `ecommerce_version_id`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_ecommerce_installations', function (Blueprint $table) {
            $table->boolean('ecommerce_version_requested')->default(false)->after('ecommerce_version_id');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_ecommerce_installations', function (Blueprint $table) {
            $table->dropColumn('ecommerce_version_requested');
        });
    }
}
