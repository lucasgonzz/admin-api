<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El estado de cada mes de mensualidad de cada cliente (misión modulo-cobranzas, 18/9/2026).
 *
 * Es la celda del Excel de Lucas: una por (cliente, mes). Y como en el Excel, la fila existe
 * solo cuando hay algo que decir de ese mes: un mes sin fila es un mes que el módulo calcula
 * solo (facturado si hay Factura C autorizada, pendiente si le toca, futuro si todavía no llegó,
 * no aplica si es anterior al alta). Por eso `estado` solo admite lo que alguien AFIRMÓ:
 *
 *   pendiente  — reabierto a mano, o importado de la planilla como "sin marca".
 *   parcial    — se pagó una parte; el saldo sale de `monto_esperado` menos los pagos.
 *   pagado     — el mes está cobrado (con pagos registrados o marcado a mano).
 *   sin_cargo  — ese mes no se cobra (bonificado, todavía no arrancaba, etc.).
 *
 * `monto_esperado` se copia acá porque `clients.total_mensualidad` es el de HOY: cuando el precio
 * cambia, lo que se esperaba de un mes viejo no tiene por qué cambiar con él. Nulo = usar el total
 * actual del cliente, que es el caso de los meses importados como pagados (Lucas: "sin monto").
 *
 * `importado` marca las filas que vinieron de la planilla, para que el importador sea idempotente
 * y para que borrar el último pago de un mes importado no lo reabra (ese mes lo cerró la planilla,
 * no un pago).
 *
 * El único `(client_id, periodo)` es el mecanismo de deduplicación del importador y del
 * `firstOrNew` del servicio, no una prolijidad: dos filas para el mismo mes serían dos verdades.
 * Es un compuesto de dos columnas con una string de 7 caracteres, que es lo que la convención del
 * repo permite. Nombre explícito para no depender del que autogenera Laravel.
 */
class CreateMensualidadPeriodosTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensualidad_periodos', function (Blueprint $table) {
            $table->id();

            // Cliente dueño del mes.
            $table->unsignedBigInteger('client_id');

            // Mes, como 'YYYY-MM'. String y no date para comparar y agrupar sin conversiones.
            $table->string('periodo', 7);

            // Lo que alguien afirmó de este mes: pendiente | parcial | pagado | sin_cargo.
            $table->string('estado', 20)->default('pendiente');

            // Cuánto se esperaba cobrar ese mes. Nulo = el total actual del cliente.
            $table->decimal('monto_esperado', 12, 2)->nullable();

            // Nota del mes ("FALTAN $4.000", "pagó por adelantado").
            $table->text('observacion')->nullable();

            // Si la fila la escribió el importador de la planilla.
            $table->boolean('importado')->default(false);

            $table->timestamps();

            // Un solo estado por (cliente, mes). Ver el docblock.
            $table->unique(['client_id', 'periodo'], 'mens_per_cli_per_uq');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mensualidad_periodos');
    }
}
