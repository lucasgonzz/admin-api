<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de idempotencia del adelanto de vencimiento por (cliente, período) — hallazgo del chequeo
 * independiente de la misión cobranzas-mejoras, 18/9/2026.
 *
 * Sin esto, "Registrar pago" adelanta `clients.payment_expired_at` un mes CADA VEZ que se lo llama
 * con `cerrar_periodo=true`, sin importar si ese mismo período YA lo había adelantado antes.
 * Escenario real: Lucas registra el pago de septiembre (adelanta el vencimiento), nota que cargó
 * mal el importe, borra el pago y lo vuelve a cargar bien — el segundo registro adelanta OTRA VEZ,
 * dos meses por un solo mes real, sin que nada lo avise.
 *
 * Por eso la marca vive en la fila de `mensualidad_periodos` de ESE período (no en `clients`, ni
 * en una tabla aparte): lo que hay que recordar es "¿esto ya se adelantó por SEPTIEMBRE?", no
 * "¿esto se adelantó alguna vez?" — cada mes tiene su propio candado.
 */
class AddVencimientoAvanzadoEnToMensualidadPeriodosTable extends Migration
{
    /**
     * Agrega la columna si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('mensualidad_periodos', 'vencimiento_avanzado_en')) {
            return;
        }

        Schema::table('mensualidad_periodos', function (Blueprint $table) {
            // Nullable: null = todavía no se adelantó el vencimiento por este período.
            $table->timestamp('vencimiento_avanzado_en')->nullable()->after('importado');
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('mensualidad_periodos', 'vencimiento_avanzado_en')) {
            return;
        }

        Schema::table('mensualidad_periodos', function (Blueprint $table) {
            $table->dropColumn('vencimiento_avanzado_en');
        });
    }
}
