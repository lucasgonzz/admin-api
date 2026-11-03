<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos columnas más en `trial_instances` (chequeo independiente de la misión prueba-alta-instancia, 3/11/2026):
 *
 *  - `seguimiento_uuid`: el uuid PROPIO de la prueba que devuelve `POST landing/prueba/alta` y que la landing
 *    usa en `GET landing/prueba/{uuid}/estado`. Antes se devolvía el uuid del lead, que ya viaja en los links
 *    públicos de la demo (`demo/{uuid}`): con uno aparte, quien tiene un link de demo no puede seguir la
 *    prueba, ni al revés. Random por alta (se sortea al tomar la instancia).
 *  - `dns_labels`: los labels de A records que ESTA instancia escribió en la zona (del nombre del pool y del
 *    slug). `PruebaDnsService::crear_registros` solo trata como propio un label que ya existe si está acá
 *    (reintento de la etapa `dns`); si no, frena como con una IP ajena. Y `borrar_registros` borra solo estos.
 *
 * Sin FK, con defaults (nulos): regla de las migraciones del proyecto.
 */
class AddSeguimientoUuidAndDnsLabelsToTrialInstancesTable extends Migration
{
    public function up(): void
    {
        Schema::table('trial_instances', function (Blueprint $table) {
            $table->string('seguimiento_uuid', 36)->nullable()->after('alias_slug')->index('trial_inst_seg_uuid_idx');
            $table->text('dns_labels')->nullable()->after('respaldo_ruta');
        });
    }

    public function down(): void
    {
        Schema::table('trial_instances', function (Blueprint $table) {
            $table->dropIndex('trial_inst_seg_uuid_idx');
            $table->dropColumn(['seguimiento_uuid', 'dns_labels']);
        });
    }
}
