<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Agrega `is_hotfix` a `versions`: marca si una versión es un parche lateral cargado
 * para un cliente puntual (4 o más componentes en el código, ej. "3.3.1.1"), en vez de
 * una versión troncal (3 componentes, ej. "3.3.1").
 *
 * Antes de esta columna, la única forma de distinguir un hotfix era contar los puntos
 * del código a ojo. Con el nuevo cálculo de rango (`VersionPathService::candidatesBetween`)
 * hace falta poder excluir hotfixes por defecto de una actualización, así que la marca
 * pasa a ser un dato persistido, no algo que se recalcule cada vez.
 *
 * Backfill: se recorre `versions` completa y se marca `is_hotfix = true` donde el
 * código tenga más de 3 componentes separados por punto.
 *
 * 🔴 El backfill de acá abajo es una copia INLINE de la regla, a propósito NO usa
 * `App\Services\VersionNumberComparator::isHotfix()`. Las migraciones son historia
 * congelada: si el día de mañana la regla cambia en el service (por ejemplo, si deja
 * de ser "más de 3 componentes" y pasa a depender de otra cosa), esta migración tiene
 * que seguir reproduciendo el mismo resultado que reprodujo el día que corrió. Atarla
 * a la clase del service la dejaría corriendo una regla distinta a la que corrió en
 * producción.
 */
class AddIsHotfixToVersionsTable extends Migration
{
    /**
     * Agrega la columna `is_hotfix` (boolean, default false) después de `version`, y
     * backfillea las filas existentes según la cantidad de componentes del código.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->boolean('is_hotfix')->default(false)->after('version');
        });

        // Se procesa en chunks de 200 para no cargar toda la tabla en memoria. El
        // update es puntual por fila: no vale la pena armar un WHERE genérico porque
        // "más de 3 componentes" no se puede expresar como comparación SQL directa
        // sobre un string sin parsearlo primero.
        DB::table('versions')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $componentes = explode('.', trim((string) $row->version));
                if (count($componentes) > 3) {
                    DB::table('versions')->where('id', $row->id)->update(['is_hotfix' => true]);
                }
            }
        });
    }

    /**
     * Elimina la columna `is_hotfix`. No hay nada más que revertir: el backfill no
     * tocó ninguna otra columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('is_hotfix');
        });
    }
}
