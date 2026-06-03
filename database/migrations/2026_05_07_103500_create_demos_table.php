<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `demos` para administrar entornos demo reutilizables.
 *
 * Cada registro representa un set de URLs para ERP y ecommerce.
 */
class CreateDemosTable extends Migration
{
    public function up()
    {
        Schema::create('demos', function (Blueprint $table) {
            // Identidad del registro de demo.
            $table->id();
            // UUID para trazabilidad externa consistente con otros recursos.
            $table->uuid('uuid')->unique();

            // URL del frontend del ERP.
            $table->string('erp_spa_url', 255);
            // URL del backend API del ERP.
            $table->string('erp_api_url', 255);
            // URL del frontend SPA del ecommerce.
            $table->string('ecommerce_spa_url', 255);
            // URL del backend API del ecommerce.
            $table->string('ecommerce_api_url', 255);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('demos');
    }
}
