<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Poliformiza el dueño de una tienda: `client_ecommerces` pasa a poder colgar de un `Client`
 * (como siempre) o de una `Demo` (nuevo), con exactamente uno de los dos cargado.
 *
 * POR QUÉ. Hasta el 31/8/2026, para instalar o actualizar el ecommerce de una demo había que
 * crear un cliente falso llamado "demo", cargarle el link de la tienda de esa demo y recién ahí
 * usar los pipelines de cliente. El pipeline de ecommerce (~2900 líneas en
 * EcommerceInstallationService) lee del `Client` en apenas seis lugares — nombre, user_id, api_key
 * y la API de empresa activa —, así que el camino barato no es duplicar el pipeline: es que la
 * fila sepa quién es su dueño y que el servicio resuelva esos cuatro datos según el caso.
 *
 * 🔴 `client_id` pasa a NULLABLE, y eso NO afloja nada para los clientes: la regla "un dueño y
 * solo uno" se hace cumplir en `ClientEcommerce` (hook `saving`), donde vale para todos los
 * caminos de escritura y no solo para los que se acuerden de validar. La base no puede expresar
 * "exactamente uno de estos dos" con una FK.
 *
 * La FK de `client_id` se dropea y se vuelve a crear igual: MySQL admite cambiar la nulabilidad
 * con la FK puesta, pero el índice y la constraint quedan más claros recreándolos explícitamente,
 * y así el `down()` es simétrico. El `MODIFY` va por SQL crudo a propósito y no por `->change()`
 * de doctrine/dbal: la tabla tiene una columna `status` de tipo `enum`, que doctrine no sabe
 * mapear y hace explotar cualquier `change()` sobre CUALQUIER columna de esta tabla con
 * "Unknown database type enum requested".
 */
class AddDemoIdToClientEcommercesTable extends Migration
{
    /**
     * Hace `client_id` nullable y agrega `demo_id`.
     *
     * @return void
     */
    public function up()
    {
        // La FK vieja se saca antes del MODIFY para que el cambio de nulabilidad no dependa de
        // cómo la trate la versión de MySQL de turno.
        Schema::table('client_ecommerces', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        // Cambio de nulabilidad por SQL crudo (ver el docblock de la clase: `enum` + doctrine).
        DB::statement('ALTER TABLE `client_ecommerces` MODIFY `client_id` BIGINT UNSIGNED NULL');

        Schema::table('client_ecommerces', function (Blueprint $table) {
            // Misma FK que antes: se recrea idéntica, ahora sobre una columna nullable.
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

            // Demo dueña de la tienda. Nullable como `client_id`: la fila tiene uno u otro.
            $table->unsignedBigInteger('demo_id')->nullable()->after('client_id')->index();
            $table->foreign('demo_id')->references('id')->on('demos')->onDelete('cascade');
        });
    }

    /**
     * Vuelve atrás: saca `demo_id` y devuelve `client_id` a NOT NULL.
     *
     * ⚠️ Si al momento de revertir hubiera tiendas de demo (filas con `client_id` NULL), el
     * MODIFY falla — y está bien que falle: convertirlas en NOT NULL implicaría inventarles un
     * cliente o borrarlas, y ninguna de las dos cosas la puede decidir una migración.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_ecommerces', function (Blueprint $table) {
            $table->dropForeign(['demo_id']);
            $table->dropIndex(['demo_id']);
            $table->dropColumn('demo_id');
        });

        Schema::table('client_ecommerces', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        DB::statement('ALTER TABLE `client_ecommerces` MODIFY `client_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('client_ecommerces', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }
}
