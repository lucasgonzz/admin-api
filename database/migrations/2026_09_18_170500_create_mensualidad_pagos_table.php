<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los pagos recibidos por la mensualidad (misión modulo-cobranzas, 18/9/2026).
 *
 * Una fila por pago, y no un campo `pagado` en el período, porque un mes se puede pagar en dos
 * veces (es el caso real de la planilla: "FALTAN $4.000") y cada transferencia tiene su fecha,
 * su medio y su nota. El estado del mes (`mensualidad_periodos.estado`) se RECALCULA a partir de
 * estos pagos cada vez que se agrega o se borra uno; no se edita a mano por otro camino.
 *
 * `monto` es nullable a propósito: los pagos que vienen de la planilla como "PAGADO" no tienen
 * monto (decisión de Lucas, 18/9/2026: "los meses PAGADO se importan sin monto"), y un pago sin
 * monto NO suma al total cobrado del mes. El único pago importado con monto es el de los meses
 * parciales, donde la planilla dice cuánto falta y de ahí se despeja cuánto entró.
 *
 * Índice normal (no único) en `(client_id, periodo)`: la consulta de siempre es "los pagos de
 * este cliente en estos meses", y puede haber varios por mes.
 */
class CreateMensualidadPagosTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensualidad_pagos', function (Blueprint $table) {
            $table->id();

            // Cliente que pagó.
            $table->unsignedBigInteger('client_id');

            // Mes al que se imputa el pago, como 'YYYY-MM'.
            $table->string('periodo', 7);

            // Cuánto entró. Nulo = pago importado sin monto (no suma).
            $table->decimal('monto', 12, 2)->nullable();

            // Cuándo entró. Nulo cuando la planilla no lo dice.
            $table->date('fecha_pago')->nullable();

            // Por dónde entró: Transferencia, Mercado Pago, Efectivo, Otro.
            $table->string('medio', 40)->nullable();

            // Nota libre del pago.
            $table->text('observacion')->nullable();

            // Quién lo registró. Nulo cuando lo escribe el importador.
            $table->unsignedBigInteger('admin_id')->nullable();

            // Si la fila la escribió el importador de la planilla.
            $table->boolean('importado')->default(false);

            $table->timestamps();

            // La consulta de siempre: pagos de un cliente en un rango de meses.
            $table->index(['client_id', 'periodo'], 'mens_pago_cli_per_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mensualidad_pagos');
    }
}
