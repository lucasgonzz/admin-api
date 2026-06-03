<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renombra valores del pipeline comercial en `leads.status` al esquema nuevo
 * y agrega flags para sugerencias de IA y seguimiento automático.
 *
 * Sin nuevas FKs: solo columnas booleanas indexadas.
 */
class UpdateLeadsStatusPipelineAndAiFlags extends Migration
{
    /**
     * Aplica el mapeo de estados legacy → nuevos y agrega columnas de IA.
     */
    public function up()
    {
        // Mapeo de valores históricos de `status` a los nuevos del protocolo comercial + IA.
        $map = [
            'reunion_agendada'   => 'demo_agendada',
            'demo_enviada'       => 'demo_realizada',
            'cliente'            => 'cerrado_ganado',
            'descartado'         => 'cerrado_perdido',
            'propuesta_enviada'  => 'mail2_enviado',
            'perdido'            => 'cerrado_perdido',
        ];

        foreach ($map as $from => $to) {
            DB::table('leads')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('leads', function (Blueprint $table) {
            // Hay sugerencia de Claude pendiente de aprobación por el setter.
            $table->boolean('tiene_sugerencia_pendiente')->default(false)->after('status')->index();
            // El scheduler marcó que el lead requiere atención de seguimiento.
            $table->boolean('requiere_seguimiento')->default(false)->after('tiene_sugerencia_pendiente')->index();
        });
    }

    /**
     * Revierte columnas y vuelve estados nuevos → legacy (best-effort).
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['tiene_sugerencia_pendiente', 'requiere_seguimiento']);
        });

        $reverse = [
            'demo_agendada'      => 'reunion_agendada',
            'demo_realizada'     => 'demo_enviada',
            'cerrado_ganado'     => 'cliente',
            'cerrado_perdido'    => 'descartado',
            'mail2_enviado'      => 'propuesta_enviada',
        ];

        foreach ($reverse as $from => $to) {
            DB::table('leads')->where('status', $from)->update(['status' => $to]);
        }
    }
}
