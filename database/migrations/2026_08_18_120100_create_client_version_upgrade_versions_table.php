<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Crea `client_version_upgrade_versions`: pivot entre `client_version_upgrades` y
 * `versions` que persiste, para cada actualización, exactamente qué versiones quedaron
 * confirmadas (troncales + los hotfixes que el operador tildó a mano en el paso de
 * confirmación).
 *
 * Antes de esta tabla, el rango de una actualización se recalculaba cada vez que hacía
 * falta (tracking Blade, notificaciones, tareas manuales) filtrando `versions` por
 * `id` entre `from_version_id` y `to_version_id` — sin filtrar por `status` ni ordenar
 * por número de versión. Esta pivot es la nueva fuente de verdad: se escribe una sola
 * vez al crear la actualización (`UpdateController::store`/`store_json`) y de ahí en
 * más se lee tal cual, sin volver a derivar nada.
 *
 * Backfill: reproduce EXACTAMENTE la regla vieja (rango por `id`, sin filtro de
 * `status`, `from_version_id` null => solo la versión destino) para cada
 * `client_version_upgrade` ya existente.
 *
 * 🔴 Este backfill NO corrige el bug en los upgrades históricos — lo CONGELA a
 * propósito. El detalle y el payload de un upgrade viejo siguen mostrando exactamente
 * lo mismo que mostraban antes de esta migración (mismas versiones, mismo criterio),
 * pero a partir de ahora ese resultado queda fijo en la pivot en vez de recalcularse:
 * si el `status` de una versión cambia más adelante, un upgrade histórico ya no lo
 * refleja. Sin este backfill, todo upgrade viejo mostraría cero notificaciones y cero
 * tareas manuales (la pivot vacía), que sería una regresión visible en datos
 * históricos — peor que congelar el resultado tal como estaba.
 */
class CreateClientVersionUpgradeVersionsTable extends Migration
{
    /**
     * Crea la tabla pivot y backfillea las actualizaciones existentes con la regla
     * vieja de rango por `id`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_version_upgrade_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('client_version_upgrade_id');
            $table->unsignedBigInteger('version_id');
            $table->index('version_id');
            // Nombre de índice explícito: el autogenerado por Laravel (concatenando los
            // dos nombres de columna) supera el límite de 64 caracteres de MySQL para
            // identificadores (error 1059).
            $table->primary(['client_version_upgrade_id', 'version_id'], 'cvu_versions_primary');
        });

        // Se procesa en chunks de 100. insertOrIgnore hace el backfill idempotente
        // si esta migración se corre más de una vez (ej. reintento de deploy).
        DB::table('client_version_upgrades')->orderBy('id')->chunk(100, function ($upgrades) {
            foreach ($upgrades as $u) {
                if ($u->from_version_id === null) {
                    // Regla vieja: sin origen, el rango es solo la versión destino.
                    $ids = [$u->to_version_id];
                } else {
                    // Regla vieja: rango por id de tabla, sin filtrar por status.
                    $ids = DB::table('versions')
                        ->where('id', '>', $u->from_version_id)
                        ->where('id', '<=', $u->to_version_id)
                        ->orderBy('id')
                        ->pluck('id')
                        ->all();
                }

                foreach ($ids as $vid) {
                    DB::table('client_version_upgrade_versions')->insertOrIgnore([
                        'client_version_upgrade_id' => $u->id,
                        'version_id'                => $vid,
                    ]);
                }
            }
        });
    }

    /**
     * Elimina la tabla pivot completa.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_version_upgrade_versions');
    }
}
