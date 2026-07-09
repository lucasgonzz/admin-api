<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de configuración fiscal (AFIP) propia de ComercioCity.
 *
 * Es una config global de una sola fila: los datos que ComercioCity necesita
 * para emitir sus propias facturas (hoy Factura C como Monotributista) desde
 * admin, análogos a los que cada cliente carga en `afip_information` de su
 * empresa-api, pero acá centralizados para la empresa dueña del producto.
 */
class CreateComerciocityAfipConfigTable extends Migration
{
    /**
     * Crea la tabla `comerciocity_afip_config`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('comerciocity_afip_config', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Condición frente al IVA: hoy "Monotributista" (Factura C); a futuro "Responsable inscripto".
            $table->string('condicion_iva', 60)->default('Monotributista');
            // CUIT de ComercioCity.
            $table->string('cuit')->nullable();
            // Razón social a facturar.
            $table->string('razon_social')->nullable();
            // Domicilio comercial declarado ante AFIP.
            $table->string('domicilio_comercial')->nullable();
            // Ingresos brutos (dato de AFIP, se guarda como texto igual que en empresa-api).
            $table->string('ingresos_brutos')->nullable();
            // Punto de venta habilitado para la facturación electrónica.
            $table->integer('punto_venta')->nullable();
            // Fecha de inicio de actividades ante AFIP.
            $table->date('inicio_actividades')->nullable();
            // Flag de ambiente AFIP: false = homologación (testing), true = producción.
            $table->boolean('afip_produccion')->default(false);
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte la creación de la tabla `comerciocity_afip_config`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('comerciocity_afip_config');
    }
}
