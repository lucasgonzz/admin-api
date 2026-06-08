<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asigna uuid únicos a clients que comparten el mismo valor (datos de prueba / seeders).
 *
 * Mantiene el uuid original en el registro de menor id por cada valor duplicado.
 */
class DeduplicateClientUuids extends Migration
{
    /**
     * Regenera uuid en duplicados (excepto el de menor id por cada uuid repetido).
     *
     * @return void
     */
    public function up()
    {
        /** Uuids que aparecen en más de un client. */
        $duplicate_uuids = DB::table('clients')
            ->select('uuid')
            ->whereNotNull('uuid')
            ->where('uuid', '!=', '')
            ->groupBy('uuid')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('uuid');

        foreach ($duplicate_uuids as $uuid) {
            /** Ids ordenados: el primero conserva el uuid; el resto reciben uno nuevo. */
            $client_ids = DB::table('clients')
                ->where('uuid', $uuid)
                ->orderBy('id')
                ->pluck('id');

            $keep_first = true;
            foreach ($client_ids as $client_id) {
                if ($keep_first) {
                    $keep_first = false;
                    continue;
                }

                DB::table('clients')
                    ->where('id', $client_id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }
        }
    }

    /**
     * No revierte: los uuid generados no son determinísticos.
     *
     * @return void
     */
    public function down()
    {
    }
}
