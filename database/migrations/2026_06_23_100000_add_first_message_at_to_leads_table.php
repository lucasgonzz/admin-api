<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columna desnormalizada para filtrar leads por fecha de inicio de conversación WhatsApp
 * sin subconsultas sobre lead_messages en cada listado del admin-spa.
 */
class AddFirstMessageAtToLeadsTable extends Migration
{
    /**
     * Agrega first_message_at e inicializa valores desde el primer mensaje del hilo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            /** Timestamp del primer mensaje del hilo (entrante o saliente). */
            $table->timestamp('first_message_at')->nullable()->after('last_message_at');
            $table->index('first_message_at');
        });

        /** Backfill: primer mensaje por lead usando sent_at si existe, sino created_at del mensaje. */
        DB::table('leads')->orderBy('id')->chunkById(200, function ($leads) {
            foreach ($leads as $lead) {
                /** Fecha del mensaje más antiguo del lead; null si aún no tiene hilo. */
                $first_message_at = DB::table('lead_messages')
                    ->where('lead_id', $lead->id)
                    ->selectRaw('MIN(COALESCE(sent_at, created_at)) as first_at')
                    ->value('first_at');

                DB::table('leads')->where('id', $lead->id)->update([
                    'first_message_at' => $first_message_at,
                ]);
            }
        });
    }

    /**
     * Revierte la columna first_message_at de leads.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['first_message_at']);
            $table->dropColumn('first_message_at');
        });
    }
}
