<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice compuesto de `lead_messages` para el scope `Lead::scopeRequiereRevision()`.
 *
 * Ese scope alimenta las tarjetas de estado de la grilla de leads, que se recalculan en cada carga
 * de la vista y en cada refresco por webhook. La parte cara es el `NOT EXISTS` de la razón A:
 * "¿existe un mensaje saliente (setter enviado/aprobado, o sistema aprobado) del mismo lead con id
 * mayor que este mensaje del lead?".
 *
 * `(lead_id, sender, status, id)` deja esa búsqueda resuelta por árbol: filtra por lead y emisor,
 * corta por estado y compara el id sin volver a la tabla. El índice existente
 * `lm_lead_evento_id_idx (lead_id, is_status_event, id)` ya cubre el `NOT EXISTS` de la razón B.
 *
 * Aditiva y sin foreign keys.
 */
class AddIndexForLeadRevisionToLeadMessagesTable extends Migration
{
    /**
     * Agrega el índice.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->index(['lead_id', 'sender', 'status', 'id'], 'lm_lead_sender_status_id_idx');
        });
    }

    /**
     * Elimina el índice.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropIndex('lm_lead_sender_status_id_idx');
        });
    }
}
