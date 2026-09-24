<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tercer tope de los paquetes de IA: cuántas búsquedas por código de barras CON búsqueda web puede
 * hacer por día el asistente de empresa de un negocio (misión asistente-fotos-barras-y-compras,
 * decisión de Lucas del 24/9/2026: 30 por día por negocio, configurable desde el admin).
 *
 * Cada búsqueda web se paga aparte del consumo de tokens, y un dueño que saca fotos de góndola en
 * ráfaga puede disparar decenas en minutos; por eso tiene tope propio y no se cuenta dentro de las
 * interacciones diarias.
 *
 * Viaja al `empresa-api` de cada cliente como la cuarta clave del PUT `admin-sync/plan-ia`
 * (`ClientAiPlanSyncService::cuerpo_del_plan`). Del lado de empresa, null o 0 = su defecto (30),
 * NO "sin tope": a diferencia de los otros dos topes, una búsqueda web nunca queda libre.
 *
 * Default 30 en la columna para que los paquetes que ya existen en producción (los tres del
 * seeder, que `firstOrCreate` no vuelve a tocar) queden con el valor decidido sin pasar por el ABM.
 * Nullable porque el ABM permite dejarlo vacío (= el defecto de empresa).
 *
 * Sin foreign keys. Guard `hasColumn`: corre sobre la base de producción del admin y sobre las bases
 * de testing de los slots, que pueden estar en estados distintos.
 */
class AddTopeBusquedasWebDiariasToAiPlansTable extends Migration
{
    /**
     * Agrega la columna del tope diario de búsquedas web.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('ai_plans') || Schema::hasColumn('ai_plans', 'tope_busquedas_web_diarias')) {
            return;
        }

        Schema::table('ai_plans', function (Blueprint $table) {
            // Búsquedas por código de barras con búsqueda web por día por negocio (decisión de Lucas
            // 24/9/2026). Null = el defecto del lado de empresa (30).
            $table->unsignedInteger('tope_busquedas_web_diarias')
                ->nullable()
                ->default(30)
                ->after('tope_interacciones_diarias');
        });
    }

    /**
     * Revierte quitando la columna.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('ai_plans') || ! Schema::hasColumn('ai_plans', 'tope_busquedas_web_diarias')) {
            return;
        }

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn('tope_busquedas_web_diarias');
        });
    }
}
