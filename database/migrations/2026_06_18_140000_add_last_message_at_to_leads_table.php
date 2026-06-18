<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columna desnormalizada para ordenar leads por actividad reciente en WhatsApp
 * sin subconsultas por mensaje en cada listado del admin-spa.
 */
class AddLastMessageAtToLeadsTable extends Migration
{
    /**
     * Agrega last_message_at e inicializa valores desde lead_messages existentes.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Timestamp del último mensaje del hilo (entrante o saliente).
            $table->timestamp('last_message_at')->nullable()->after('updated_at');
            $table->index('last_message_at');
        });

        // Backfill: último created_at de mensajes; si no hay hilo, usar created_at del lead.
        DB::table('leads')->orderBy('id')->chunkById(200, function ($leads) {
            foreach ($leads as $lead) {
                /** Fecha del mensaje más reciente del lead, si existe historial. */
                $last_message_at = DB::table('lead_messages')
                    ->where('lead_id', $lead->id)
                    ->max('created_at');

                DB::table('leads')->where('id', $lead->id)->update([
                    'last_message_at' => $last_message_at ?? $lead->created_at,
                ]);
            }
        });
    }

    /**
     * Revierte la columna last_message_at de leads.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['last_message_at']);
            $table->dropColumn('last_message_at');
        });
    }
}
