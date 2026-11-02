<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tope de GASTO de los paquetes de IA, en dólares por mes (misión plan-ia-por-gasto, 2/11/2026).
 *
 * El pedido de Lucas: el límite de un plan no es la cantidad de tokens, es la plata que esos tokens
 * costaron, y no es lo mismo un token de DeepSeek que uno de Claude. Medido en Ferre Total: 11,3
 * millones de tokens de caché de Flash cuestan 7 centavos y 217 mil de salida de Sonnet 5 cuestan
 * US$ 2,17, y contra el tope de tokens los dos cuentan igual.
 *
 * Viaja al `empresa-api` de cada cliente como quinta clave del PUT `admin-sync/plan-ia`
 * (`ClientAiPlanSyncService::cuerpo_del_plan`). Del lado de empresa, con un valor > 0 manda el gasto
 * y el tope de tokens deja de cortar; null o 0 = sin tope de gasto, y todo sigue como hoy.
 *
 * 🔴 Nullable y SIN default, a propósito: la columna nace en null en todos los paquetes que ya
 * existen en producción. El valor lo decide Lucas después, y hasta entonces nada cambia para ningún
 * cliente. No se saca ni se toca `tope_tokens_mensual`: los dos conviven hasta que las dos puntas
 * estén desplegadas (se saca en una misión posterior).
 *
 * decimal(10,2): son dólares, y un float acá arrastraría centavos sucios hasta la comparación del
 * otro lado. Mismo tipo que usa empresa para `users.plan_ia_tope_gasto_usd_mensual`.
 *
 * Sin foreign keys. Guard `hasColumn`: corre sobre la base de producción del admin y sobre las bases
 * de testing de los slots, que pueden estar en estados distintos.
 */
class AddTopeGastoUsdMensualToAiPlansTable extends Migration
{
    /**
     * Agrega la columna del tope mensual de gasto.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('ai_plans') || Schema::hasColumn('ai_plans', 'tope_gasto_usd_mensual')) {
            return;
        }

        Schema::table('ai_plans', function (Blueprint $table) {
            // Dólares de IA que puede gastar el negocio por mes calendario. Null = sin tope de gasto.
            $table->decimal('tope_gasto_usd_mensual', 10, 2)
                ->nullable()
                ->after('tope_tokens_mensual');
        });
    }

    /**
     * Revierte quitando la columna.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('ai_plans') || ! Schema::hasColumn('ai_plans', 'tope_gasto_usd_mensual')) {
            return;
        }

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn('tope_gasto_usd_mensual');
        });
    }
}
