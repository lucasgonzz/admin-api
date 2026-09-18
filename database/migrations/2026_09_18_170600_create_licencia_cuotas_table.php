<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuotas de la licencia (el pago único del contrato) de cada cliente (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * Es la hoja LICENCIAS del Excel: por cliente, N cuotas con mes y monto, cada una verde (pagada),
 * roja (pendiente) o amarilla (pago parcial, el caso de Únicas que pagó $436.000 de USD 600).
 * Salen del contrato —`contract_financiacion` es `[{monto, fecha}]`, una cuota por fila— pero
 * viven en su propia tabla porque el contrato dice lo PACTADO y esto dice lo que PASÓ: una cuota
 * se puede pagar a medias, renegociar o agregar sin tocar el contrato firmado.
 *
 * `moneda` por cuota y no por cliente porque en la planilla conviven las dos: HB tiene tres cuotas
 * en pesos y una en dólares. `monto_pagado` acumula lo que entró; el estado se recalcula desde ahí
 * (`pagada` si cubre el monto o si Lucas la da por completa, `parcial` si entró algo, `pendiente`
 * si nada).
 *
 * `importado` marca las que vinieron de la planilla: el importador solo crea cuotas para un
 * cliente que no tenga ninguna importada, así se puede correr dos veces sin duplicar, y el
 * generador desde el contrato no hace nada si ya hay cuotas (importadas o no).
 */
class CreateLicenciaCuotasTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('licencia_cuotas', function (Blueprint $table) {
            $table->id();

            // Cliente dueño de la cuota. Indexado: siempre se lista por cliente.
            $table->unsignedBigInteger('client_id')->index('lic_cuota_client_idx');

            // Número de cuota (1, 2, 3...). El orden en que se muestran y se cobran.
            $table->unsignedSmallInteger('numero');

            // Mes al que corresponde, como 'YYYY-MM' (la columna del Excel).
            $table->string('periodo', 7);

            // Fecha de vencimiento puntual, si el contrato la tiene. Nulo = solo el mes.
            $table->date('vencimiento')->nullable();

            // Monto de la cuota y en qué moneda (USD o ARS, conviven en la planilla).
            $table->decimal('monto', 12, 2);
            $table->string('moneda', 3)->default('USD');

            // pendiente | parcial | pagada. Se recalcula al registrar pagos.
            $table->string('estado', 20)->default('pendiente');

            // Lo que entró hasta ahora. Nulo = no se sabe (cuotas importadas) o nada.
            $table->decimal('monto_pagado', 12, 2)->nullable();

            // Cuándo se terminó de pagar (o cuándo entró el último pago).
            $table->date('fecha_pago')->nullable();

            // Nota libre ("215.000 pesos fijos", "restan $500.000").
            $table->text('observacion')->nullable();

            // Si la fila la escribió el importador de la planilla.
            $table->boolean('importado')->default(false);

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
        Schema::dropIfExists('licencia_cuotas');
    }
}
