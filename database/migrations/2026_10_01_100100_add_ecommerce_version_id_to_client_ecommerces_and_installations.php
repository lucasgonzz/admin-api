<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `ecommerce_version_id` a `client_ecommerces` (la versión que la tienda tiene instalada
 * HOY) y a `client_ecommerce_installations` (la versión que despliega ESA corrida). Misión cruzada
 * `versiones-tienda`, 1/10/2026.
 *
 * 🔴 SIN FOREIGN KEY, a propósito y en las dos tablas:
 *   - Es la convención del proyecto para las columnas de trazabilidad (ver
 *     `ecommerce_deployment_logs`): la integridad la cuida Eloquent, y el borrado de una versión
 *     del panel ya se niega si alguna tienda o corrida la referencia.
 *   - `client_ecommerces.status` es un `enum`: cualquier cambio de esquema que pase por doctrine
 *     sobre esta tabla explota con "Unknown database type enum requested" (ver la migración
 *     2026_08_31_100000). Una FK no lo necesita, pero no agregar nada que obligue a recrearla en el
 *     futuro es más barato que acordarse.
 *
 * NULL significa, en las dos: "no se sabe / no hay una versión registrada". Es lo que tienen todas
 * las tiendas instaladas por la vía vieja (compiladas desde `master` en el VPS de builds) y todas las
 * corridas anteriores a esta migración. Sin backfill: inventarle una versión a una tienda compilada
 * desde `master` sería escribir un dato que nadie midió.
 */
class AddEcommerceVersionIdToClientEcommercesAndInstallations extends Migration
{
    /**
     * Agrega la columna (nullable, indexada, sin FK) a las dos tablas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_ecommerces', function (Blueprint $table) {
            // Versión de ecommerce instalada hoy en la tienda (la escribe el pipeline al terminar bien).
            $table->unsignedBigInteger('ecommerce_version_id')->nullable()->after('status')->index();
        });

        Schema::table('client_ecommerce_installations', function (Blueprint $table) {
            // Versión de ecommerce que despliega esta corrida (la pedida o la última publicada).
            $table->unsignedBigInteger('ecommerce_version_id')->nullable()->after('created_via')->index();
        });
    }

    /**
     * Saca la columna de las dos tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_ecommerce_installations', function (Blueprint $table) {
            $table->dropIndex(['ecommerce_version_id']);
            $table->dropColumn('ecommerce_version_id');
        });

        Schema::table('client_ecommerces', function (Blueprint $table) {
            $table->dropIndex(['ecommerce_version_id']);
            $table->dropColumn('ecommerce_version_id');
        });
    }
}
