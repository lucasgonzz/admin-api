<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tienda online (ecommerce) asociada a un cliente. Guarda el dominio, las URLs
 * de despliegue y la configuración recolectada para la tienda.
 */
class CreateClientEcommercesTable extends Migration
{
    /**
     * Crea la tabla client_ecommerces.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_ecommerces', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Cliente dueño de la tienda.
            $table->unsignedBigInteger('client_id')->index();
            // Dominio final de la tienda (ej: minegocio.com.ar).
            $table->string('domain')->nullable();
            // URL de la API de la tienda (tienda-api desplegada).
            $table->string('api_url')->nullable();
            // URL del SPA de la tienda (tienda-spa desplegada).
            $table->string('spa_url')->nullable();
            // Path de instalación del API en el servidor.
            $table->string('api_path')->nullable();
            // Path de instalación del SPA en el servidor.
            $table->string('spa_path')->nullable();
            // Estado del despliegue de la tienda.
            $table->enum('status', ['pending', 'installing', 'active'])->default('pending')->index();
            // Configuración recolectada por WhatsApp (colores, redes, preferencias, etc.).
            $table->json('ecommerce_setup_data')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla client_ecommerces.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_ecommerces');
    }
}
