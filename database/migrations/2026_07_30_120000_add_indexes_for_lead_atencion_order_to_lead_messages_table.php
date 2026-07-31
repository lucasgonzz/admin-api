<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices compuestos de `lead_messages` para optimizar el orden por atención de la bandeja.
 *
 * Los índices sirven a dos subconsultas correlacionadas que calcula el listado de leads:
 *   1. `row_warning` — fila amarilla si hay mensajes por verificar (requiere_verificacion + status='sugerido')
 *   2. `failed_send_count` — cuántos mensajes con error sin resolver tiene el lead
 *
 * Ambas se usan en el `ORDER BY` del listado (prompt 02 del grupo 286); sin estos índices,
 * MySQL evalúa las subconsultas sobre TODOS los leads antes de poder paginar.
 * Con los índices, la búsqueda correlacionada se resuelve por árbol sin table scan.
 */
class AddIndexesForLeadAtencionOrderToLeadMessagesTable extends Migration
{
    /**
     * Agrega dos índices compuestos a `lead_messages`.
     *
     * Índice 1 — lm_lead_evento_id_idx:
     * Optimiza el NOT EXISTS de `failed_send_count`, que busca "¿existe un mensaje posterior
     * (id mayor) del mismo lead que NO sea evento de estado?". Las tres columnas en ese orden
     * permiten a MySQL resolver la búsqueda por índice sin recorrer la tabla.
     *
     * Índice 2 — lm_lead_verif_status_idx:
     * Optimiza tanto `row_warning` como `pending_verification_count`, que filtran por
     * requiere_verificacion = true y status = 'sugerido'.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Índice para resolver el NOT EXISTS del failed_send_count sin table scan.
            $table->index(['lead_id', 'is_status_event', 'id'], 'lm_lead_evento_id_idx');

            // Índice para resolver row_warning y pending_verification_count rápidamente.
            $table->index(['lead_id', 'requiere_verificacion', 'status'], 'lm_lead_verif_status_idx');
        });
    }

    /**
     * Elimina los dos índices.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropIndex('lm_lead_evento_id_idx');
            $table->dropIndex('lm_lead_verif_status_idx');
        });
    }
}
