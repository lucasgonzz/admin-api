<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices que le faltaban a `deployment_logs` desde que se creó.
 *
 * La tabla nació el 23/5/2026 con un solo índice —la PK— y nunca se le agregó uno por las dos
 * columnas por las que se la filtra SIEMPRE. Que es un olvido y no una decisión lo confirma la
 * tabla gemela del ecommerce (`ecommerce_deployment_logs`), creada dos meses después, que sí
 * declara el suyo.
 *
 * Hasta ahora se pagaba caro pero en silencio: `resumen_de_logs()` de `claude/*` hace un
 * `MAX(created_at)` filtrando por `client_version_upgrade_id` en cada `GET claude/upgrades/{id}`,
 * o sea un full scan cada vez que Claude poléa. La misión 61 lo vuelve peor porque
 * `deployments:vencer-colgados` corre la misma consulta cada cinco minutos, para siempre, sobre una
 * tabla que crece sin techo (`log_remote_output` escribe una línea por bloque de salida remota y
 * solo `start_json` borra, y solo las de ese upgrade).
 *
 * Van los dos: `client_installation_id` tiene exactamente el mismo problema del lado de las
 * instalaciones iniciales, y arreglar uno solo sería arreglar la instancia y no la familia.
 */
class AddIndexToDeploymentLogsTable extends Migration
{
    /**
     * Agrega los índices por las dos columnas de filtrado.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->index('client_version_upgrade_id', 'deployment_logs_upgrade_idx');
            $table->index('client_installation_id', 'deployment_logs_installation_idx');
        });
    }

    /**
     * Elimina los índices.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->dropIndex('deployment_logs_upgrade_idx');
            $table->dropIndex('deployment_logs_installation_idx');
        });
    }
}
