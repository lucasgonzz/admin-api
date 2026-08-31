<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `demos` los tres datos que el pipeline de ecommerce le pedía hasta ahora al `Client`
 * dueño de la tienda.
 *
 * Cuando el dueño de un `client_ecommerce` es una demo y no un cliente, el pipeline
 * (EcommerceInstallationService) necesita contestar exactamente cuatro preguntas. Tres salen de
 * estas columnas y la cuarta (la URL de empresa-api para el branding) ya se puede deducir de
 * `erp_api_url`:
 *
 *   - `nombre`   → APP_NAME del .env de tienda-api, nombre de la PWA y VUE_APP_SITE_NAME.
 *                  Es el equivalente de `clients.company_name ?? clients.name`. Nullable: si
 *                  queda vacío, `Demo::display_name()` cae al slug de `erp_spa_url` (demo3), que
 *                  es un nombre feo pero nunca vacío.
 *   - `user_id`  → el commerce id: VUE_APP_COMMERCE_ID del build de tienda-spa y el `{id}` de
 *                  GET {tienda-api}/api/commerce/{id}. Es el equivalente de `clients.user_id`.
 *                  🔴 Es EL MISMO NÚMERO que va en `USER_ID` del `.env` del ERP de esa demo: es
 *                  el id con el que `DemoSetupHelper` de empresa-api crea el `User`
 *                  (`'id' => config('app.USER_ID')`). Si no coincide, la tienda le pide su
 *                  configuración a un comercio que no existe y queda en blanco.
 *   - `api_key`  → la clave server-to-server que viaja en el header `X-Admin-Api-Key` al pedirle
 *                  el branding (logo/color/descripción) a empresa-api. Equivalente de
 *                  `clients.api_key`. Nullable: sin ella el pipeline degrada igual que con un
 *                  cliente sin api_key, o sea avisa por log y prueba con la tienda-api.
 *
 * Las tres son nullable a propósito: las demos que ya existen (2838 filas al 31/8/2026) no
 * cambian de comportamiento en ningún flujo actual, y el catálogo se completa a mano cuando a esa
 * demo se le quiera instalar o actualizar el ecommerce.
 */
class AddOwnerFieldsToDemosTable extends Migration
{
    /**
     * Agrega las tres columnas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('demos', function (Blueprint $table) {
            /* Nombre a mostrar del comercio de la demo (APP_NAME / nombre de la PWA). 190 y no
             * 255 porque es un campo de texto corto y así entra sin problema en un índice utf8mb4
             * si algún día se quiere buscar por él. */
            $table->string('nombre', 190)->nullable()->after('uuid');

            /* Commerce id de la demo: el mismo `USER_ID` del .env de su ERP. */
            $table->unsignedBigInteger('user_id')->nullable()->after('nombre');

            /* Clave server-to-server para pedirle el branding a la empresa-api de la demo. */
            $table->string('api_key', 255)->nullable()->after('user_id');
        });
    }

    /**
     * Saca las tres columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->dropColumn(['nombre', 'user_id', 'api_key']);
        });
    }
}
