<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ancla de actividad del tramo `running` en curso de un deployment.
 *
 * 🔴 Por qué no alcanzaba `deployment_started_at`: esa columna la estampa SOLO el `start`
 * (`DeploymentController::start_json` y `ClaudeUpgradeOpsController::start`). Las otras cinco
 * entradas a `running` —post-cierre, configure-system y retry-commands del panel, más las dos
 * etapas de `claude/*`— escriben el estado y nada más. Un upgrade que estuvo dos días en `paused`
 * y al que recién le apretaron "post-cierre" tiene `deployment_started_at` y su último
 * `deployment_log` con dos días de antigüedad: cualquier vencimiento anclado ahí lo mataría en el
 * primer tick, con el worker todavía sin levantarlo.
 *
 * Son dos hechos distintos y los dos se usan: `deployment_started_at` es cuándo arrancó el
 * deployment ENTERO (lo devuelve `GET claude/upgrades/{id}` como dato informativo);
 * `deployment_running_since` es cuándo arrancó el TRAMO en curso, que es lo único con lo que se
 * puede decidir si está colgado. Pisar el primero con el segundo perdería el dato.
 *
 * 🔴 Disparador mecánico, sin criterio de por medio: **todo `update` que escriba
 * `deployment_status => 'running'` escribe también `deployment_running_since => now()`.** Sin
 * excepción. Una entrada que se olvide del sello es un upgrade que se vence solo a los N minutos
 * aunque esté sano — el modo de falla más grave de este diseño.
 *
 * Nullable, y con BACKFILL para los que ya están en `running`. La primera versión de esta migración
 * los dejaba en NULL con el argumento de que "no se sabe hace cuánto están ahí" — y eso dejaba
 * afuera del vencimiento justo a los upgrades colgados que existen HOY en producción, que son los
 * que motivaron la misión. El problema de "se sale tocando la base a mano" habría seguido intacto
 * para todos los casos ya existentes.
 *
 * Se rellena con `updated_at`, que para una fila en `running` es siempre POSTERIOR a su entrada a
 * ese estado (el `update` que escribió `running` lo tocó, y cualquier escritura de
 * `DeploymentService` posterior también). O sea que solo puede errar hacia NO vencer, nunca hacia
 * vencer de más. No es inventar una medición: es una cota conservadora, y encima el criterio real
 * del comando es `max(ancla, última línea de log)`, así que un deployment que de verdad está vivo
 * se salva solo con sus propios logs.
 *
 * Las filas que NO están en `running` quedan en NULL a propósito: el ancla no significa nada fuera
 * de ese estado y el comando ni las mira.
 *
 * ⚠️ Y la otra mitad de la máquina, escrita para que nadie la lea como un olvido: **el ancla NO se
 * limpia al salir de `running`**, a propósito. Ninguna de las cinco salidas la toca
 * (`DeploymentService` escribiendo `failed`, `paused`, `paused_post_tasks` y `completed`, más el
 * `catch` de `RunDeploymentJob`). Un ancla vieja sobre una fila que ya no está en `running` es
 * inerte —el único lector es el `WHERE` del comando, que exige `running`, y toda entrada a
 * `running` re-sella—, y encima sirve de forense: es la única forma de saber cuándo arrancó el
 * tramo que terminó en `failed`.
 */
class AddDeploymentRunningSinceToClientVersionUpgradesTable extends Migration
{
    /**
     * Agrega deployment_running_since.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            // Momento en que el upgrade entró al tramo `running` en curso.
            $table->timestamp('deployment_running_since')->nullable()->after('deployment_started_at');
        });

        /* Backfill de los que YA están colgados: sin esto quedarían fuera del vencimiento para
         * siempre, que es justo el caso que motivó la misión. `updated_at` es la cota conservadora
         * (ver el docblock de arriba); el `COALESCE` cubre una fila sin `updated_at` cargado. */
        DB::table('client_version_upgrades')
            ->where('deployment_status', 'running')
            ->whereNull('deployment_running_since')
            ->update([
                'deployment_running_since' => DB::raw('COALESCE(`updated_at`, `deployment_started_at`)'),
            ]);
    }

    /**
     * Elimina deployment_running_since.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn('deployment_running_since');
        });
    }
}
