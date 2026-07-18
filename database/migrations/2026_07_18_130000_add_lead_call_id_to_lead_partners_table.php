<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega `lead_call_id` a `lead_partners` — refactor "múltiples llamadas por lead"
 * (grupo 115, prompt 484).
 *
 * Los socios pasan a poder asociarse a la llamada específica del closer en la que fueron
 * detectados/cargados, en vez de colgar solo del lead. Nullable porque los socios históricos
 * todavía no tienen llamada asignada (los reasigna el backfill del prompt 485) y porque un
 * socio cargado a mano puede no estar atado a una llamada puntual. Sin FK, siguiendo la regla
 * del workspace de no usar `foreign()`/`constrained()` en migraciones nuevas.
 */
class AddLeadCallIdToLeadPartnersTable extends Migration
{
    /**
     * Agrega la columna `lead_call_id` (nullable, indexada) después de `lead_id`, con guard hasColumn.
     */
    public function up()
    {
        if (! Schema::hasColumn('lead_partners', 'lead_call_id')) {
            Schema::table('lead_partners', function (Blueprint $table) {
                $table->unsignedBigInteger('lead_call_id')->nullable()->index()->after('lead_id');
            });
        }
    }

    /**
     * Revierte eliminando la columna `lead_call_id`, con guard hasColumn. Se elimina primero el
     * índice explícitamente por si el runner de MySQL se queja al dropear la columna indexada.
     */
    public function down()
    {
        if (Schema::hasColumn('lead_partners', 'lead_call_id')) {
            Schema::table('lead_partners', function (Blueprint $table) {
                $table->dropIndex(['lead_call_id']);
                $table->dropColumn('lead_call_id');
            });
        }
    }
}
