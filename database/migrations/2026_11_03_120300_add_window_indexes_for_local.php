<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices por `created_at` en `support_messages` y `lead_messages`, y por `sent_at` en `lead_messages`
 * (misión el-local, 3/11/2026).
 *
 * La escena de «El local» (`GET api/admin/local/escena`) pregunta cada 20 s «qué mensajes de soporte y de
 * leads hubo en los últimos minutos». Ninguna de las dos tablas tenía un índice que sirva para eso: sin
 * estos, cada cálculo recorre las tablas enteras, que son las dos más grandes de la mensajería y crecen
 * todos los días. Con el índice, la consulta lee solo las filas de la ventana. `lead_messages.sent_at` hace
 * falta porque una sugerencia vieja aprobada tarde se ENVÍA ahora: el agente comercial mira `created_at` o
 * `sent_at` dentro de la ventana, cada uno con su índice.
 *
 * 🔴 Guarda propia con `SHOW INDEX` (Laravel 8 no trae `Schema::hasIndex`): la migración se puede correr
 * dos veces, y no choca si alguien ya creó el índice a mano. En MySQL 8 `ADD INDEX` es en línea: no
 * bloquea escrituras mientras se arma.
 */
class AddWindowIndexesForLocal extends Migration
{
    /**
     * Índices que agrega: [tabla, columna, nombre corto].
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private $indices = [
        ['support_messages', 'created_at', 'sm_created_at_idx'],
        ['lead_messages', 'created_at', 'lm_created_at_idx'],
        ['lead_messages', 'sent_at', 'lm_sent_at_idx'],
    ];

    /**
     * Crea los índices que falten.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->indices as $definicion) {
            list($tabla, $columna, $indice) = $definicion;

            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
                continue;
            }

            if ($this->existe($tabla, $indice)) {
                continue;
            }

            Schema::table($tabla, function ($table) use ($columna, $indice) {
                $table->index($columna, $indice);
            });
        }
    }

    /**
     * Quita los índices si están.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->indices as $definicion) {
            list($tabla, , $indice) = $definicion;

            if (! Schema::hasTable($tabla) || ! $this->existe($tabla, $indice)) {
                continue;
            }

            Schema::table($tabla, function ($table) use ($indice) {
                $table->dropIndex($indice);
            });
        }
    }

    /**
     * ¿Existe ya un índice con ese nombre en la tabla?
     *
     * @param string $tabla  Tabla a mirar.
     * @param string $indice Nombre del índice.
     *
     * @return bool
     */
    private function existe($tabla, $indice)
    {
        return count(DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$indice])) > 0;
    }
}
