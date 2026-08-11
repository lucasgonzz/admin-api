<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agranda `admin_push_subscriptions.endpoint` de varchar(191) a TEXT y mueve el índice
 * único a una columna nueva `endpoint_hash` (SHA-256 hex del endpoint).
 *
 * POR QUÉ UNA COLUMNA HASH Y NO UN VARCHAR MÁS LARGO (leer antes de "simplificar" esto):
 *
 * El 191 original no era arbitrario: es el largo máximo indexable con utf8mb4 bajo el
 * límite histórico de 767 bytes por índice (4 bytes × 191 = 764). El razonamiento del
 * índice estaba bien; lo que estaba mal era el supuesto de que un endpoint entra ahí.
 * No entra: los endpoints de Apple (`https://web.push.apple.com/…`), que son los que
 * genera una PWA en iPhone, pasan holgadamente los 191 caracteres, y los de FCM quedan
 * al borde. Con `'strict' => true` en config/database.php, MySQL no trunca: aborta con
 * SQLSTATE[22001] Data too long. Resultado medido el 11/8/2026: POST /push/subscribe
 * devolvía 500 y NINGUNA suscripción llegaba a guardarse nunca.
 *
 * Por eso no alcanza con `string('endpoint', 500)->unique()`: 500 × 4 = 2000 bytes,
 * arriba del límite del índice, y el ALTER falla o queda dependiendo de que el server
 * tenga innodb_large_prefix + DYNAMIC. Sería el mismo error corrido unos caracteres más
 * a la derecha. El endpoint completo va a TEXT (sin límite práctico) y la unicidad la
 * garantiza el hash, que son 64 caracteres ASCII y entra en cualquier índice.
 */
class AlterAdminPushSubscriptionsEndpointToText extends Migration
{
    /**
     * Aplica el cambio: columna hash, endpoint a TEXT, backfill y único sobre el hash.
     *
     * @return void
     */
    public function up()
    {
        /* 1) Columna del hash, nullable todavía: hay que backfillear antes de indexarla. */
        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->char('endpoint_hash', 64)->nullable()->after('admin_id');
        });

        /* 2) Se cae el único sobre endpoint ANTES del cambio de tipo: MySQL no permite
         *    un índice sobre una columna TEXT sin longitud de prefijo. */
        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint']);
        });

        /* 3) El endpoint pasa a TEXT y ya puede guardar la URL completa del push service. */
        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->text('endpoint')->change();
        });

        /* 4) Backfill del hash para las filas existentes. Si la tabla está vacía —que es lo
         *    más probable, justamente porque el bug impedía guardar— esto es un no-op. */
        DB::table('admin_push_subscriptions')
            ->select('id', 'endpoint')
            ->orderBy('id')
            ->chunk(200, function ($filas) {
                foreach ($filas as $fila) {
                    DB::table('admin_push_subscriptions')
                        ->where('id', $fila->id)
                        ->update(['endpoint_hash' => hash('sha256', (string) $fila->endpoint)]);
                }
            });

        /* 5) Recién con todas las filas hasheadas se puede exigir NOT NULL + único.
         *    Los hashes no colisionan porque los endpoints venían de un índice único.
         *
         *    El NOT NULL va por SQL directo y no por ->change(): doctrine/dbal 3 no tiene
         *    registrado el tipo CHAR y un ->change() sobre esta columna revienta con
         *    "Unknown column type 'char' requested" (medido acá el 11/8/2026, la migración
         *    abortó a mitad de camino). El repo ya usa DB::statement para otros ALTER. */
        DB::statement('ALTER TABLE admin_push_subscriptions MODIFY endpoint_hash CHAR(64) NOT NULL');

        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->unique('endpoint_hash');
        });
    }

    /**
     * Revierte al esquema anterior.
     *
     * ATENCIÓN 1: el esquema viejo no puede representar un endpoint de más de 191 caracteres
     * —esa es exactamente la limitación que esta migración vino a sacar—, así que el revert
     * borra esas filas antes de achicar la columna. Sin eso, el ALTER falla en modo estricto
     * y la migración queda irreversible. En producción eso significa que un rollback vacía la
     * tabla, porque TODOS los endpoints de Apple superan los 191. Los devices borrados se
     * vuelven a registrar solos la próxima vez que el admin abre la app (re-registro
     * automático del arranque), así que se pierde el registro, no el acceso.
     *
     * ATENCIÓN 2: el único viejo era sobre `varchar(191)` con colación `utf8mb4_unicode_ci`,
     * que es case-insensitive, accent-insensitive y PAD SPACE — o sea MÁS restrictiva que la
     * distinción por SHA-256 del esquema nuevo. Dos endpoints que difieran solo en mayúsculas
     * o en espacios finales conviven bajo el esquema nuevo y colisionan al volver al viejo, y
     * el DDL de MySQL no es transaccional: el ALTER que falla deja la tabla ya achicada, sin
     * ningún índice único, y la migración marcada como aplicada. Por eso el revert deduplica
     * antes de recrear el índice, en vez de confiar en que no pase.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint_hash']);
        });

        /* Filas que no entran en varchar(191): no tienen representación en el esquema viejo. */
        DB::table('admin_push_subscriptions')
            ->select('id', 'endpoint')
            ->orderBy('id')
            ->chunk(200, function ($filas) {
                foreach ($filas as $fila) {
                    if (mb_strlen((string) $fila->endpoint) > 191) {
                        DB::table('admin_push_subscriptions')->where('id', $fila->id)->delete();
                    }
                }
            });

        /* Filas que el esquema nuevo distingue y el viejo no (ver ATENCIÓN 2): se conserva la
         * más vieja de cada grupo. La comparación usa la colación de la columna, que es la
         * misma que va a usar el índice único al recrearse. */
        DB::statement(
            'DELETE t1 FROM admin_push_subscriptions t1 ' .
            'INNER JOIN admin_push_subscriptions t2 ON t1.endpoint = t2.endpoint AND t1.id > t2.id'
        );

        /* La columna hash se va antes del cambio de tipo, para que la introspección que
         * hace doctrine/dbal en el ->change() no tenga que mirar un CHAR (ver up()). */
        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('endpoint_hash');
        });

        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint', 191)->change();
        });

        Schema::table('admin_push_subscriptions', function (Blueprint $table) {
            $table->unique('endpoint');
        });
    }
}
