<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `ecommerce_versions`: el registro de las versiones publicadas del ecommerce
 * (`tienda-spa` + `tienda-api`), el equivalente de `versions` para empresa (misión cruzada
 * `versiones-tienda`, 1/10/2026).
 *
 * POR QUÉ UNA TABLA APARTE Y NO UNA FILA MÁS EN `versions`. `versions` es la de empresa y arrastra
 * todo lo de empresa: seeders, comandos, tareas manuales, notificaciones y el rango semántico que
 * arma `VersionPathService` para los upgrades. Una versión de tienda no tiene nada de eso —tienda
 * no tiene migraciones propias (el esquema es de empresa)— y mezclarlas haría que cada consulta de
 * empresa tuviera que filtrar las de tienda, que es la forma conocida de que una se olvide.
 *
 * Cada fila es el puntero a DOS artefactos de GitHub Actions, que el pipeline baja en vez de
 * compilar en el VPS de builds:
 *   - `lucasgonzz/tienda-spa`, tag `v{version}`, asset `tienda-spa-v{version}-dist.zip`;
 *   - `lucasgonzz/tienda-api`, tag `v{version}`, asset `tienda-api-v{version}.zip`.
 *
 * 🔴 `version` es UNIQUE y es la ÚNICA fuente que el admin usa para armar el tag y el nombre de los
 * assets: escrita distinto que en el commit `[release:X.Y.Z]`, el deploy no encuentra nada. Por eso
 * el alta por API verifica que los dos assets existan antes de publicar.
 *
 * `status` es string y no enum a propósito (draft | published | archived): un enum en esta base ya
 * rompió `->change()` de doctrine en `client_ecommerces`, y el conjunto de valores se valida en el
 * controlador igual que en `versions`.
 */
class CreateEcommerceVersionsTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ecommerce_versions', function (Blueprint $table) {
            $table->id();

            // Código semántico de la versión ("1.0.0"). Mismo largo y mismo regex que `versions`.
            $table->string('version', 30)->unique();

            // Título corto para el panel y para el informe de la versión.
            $table->string('title', 200)->nullable();

            // Qué trae la versión, en texto libre.
            $table->text('description')->nullable();

            // draft | published | archived. Solo una `published` se despliega.
            $table->string('status', 20)->default('draft')->index();

            // Cuándo pasó a `published` por primera vez.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ecommerce_versions');
    }
}
