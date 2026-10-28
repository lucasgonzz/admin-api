<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `comision_pagos` (misión ventas-y-comisiones, 28/10/2026): cada pago que se le
 * hace a un vendedor a cuenta de la comisión de una venta.
 *
 * `vendedor_admin_id` se guarda en el pago y no se deduce de la venta: si mañana la venta cambia de
 * vendedor, lo que ya se le pagó al anterior sigue siendo de él. La moneda es la de la venta en el
 * momento del pago. Sin foreign keys y con índices de nombre corto.
 */
class CreateComisionPagosTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('comision_pagos', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('venta_id')->index('cp_venta_idx');
            $table->unsignedBigInteger('vendedor_admin_id')->index('cp_vendedor_idx');

            $table->decimal('monto', 14, 2);
            $table->string('moneda', 3)->default('USD');
            $table->date('fecha')->nullable();
            $table->string('nota', 255)->nullable();

            // Admin que registró el pago desde Cobranzas → Comisiones.
            $table->unsignedBigInteger('registrado_por_admin_id')->nullable();

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
        Schema::dropIfExists('comision_pagos');
    }
}
