<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `ia_app_versions`: las versiones de la app `ia-spa` (`ia.comerciocity.com`), con el mismo control
 * de versiones que gestión y tienda (misión cruzada `app-ia-y-memoria`, 29/10/2026). Molde:
 * `ecommerce_versions`.
 *
 * Cada fila es el puntero al asset `ia-spa-v{version}-dist.zip` del release del tag `v{version}` de
 * `lucasgonzz/ia-spa`. Publicar verifica que el asset exista. `deployed_at` lo estampa la herramienta de
 * la PC (`ia-release.py desplegar`) cuando la versión quedó servida en el VPS.
 *
 * `status` es string y no enum, por el mismo motivo que `ecommerce_versions` (un enum ya rompió
 * `->change()` de doctrine en esta base).
 */
class CreateIaAppVersionsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('ia_app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 30)->unique();
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ia_app_versions');
    }
}
