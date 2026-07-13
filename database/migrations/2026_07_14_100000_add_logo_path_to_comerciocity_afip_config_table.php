<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `logo_path` a `comerciocity_afip_config` (prompt 360).
 *
 * Permite configurar un logo personalizado para la Factura C de mensualidad
 * (`MensualidadFacturaPdf`) desde admin-spa, en vez del `public/afip/logo.jpg`
 * hardcodeado. Cuando es `null`, el PDF sigue usando ese logo default (sin
 * regresión para los negocios que no carguen uno propio).
 */
class AddLogoPathToComerciocityAfipConfigTable extends Migration
{
    /**
     * Agrega la columna `logo_path` a `comerciocity_afip_config`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('comerciocity_afip_config', function (Blueprint $table) {
            // Ruta relativa a `public/` del logo personalizado (ej. `/afip/logo_custom.png`).
            // `null` = usar el logo default `logo.jpg`.
            $table->string('logo_path', 120)->nullable()->after('afip_produccion');
        });
    }

    /**
     * Revierte el agregado de la columna `logo_path`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('comerciocity_afip_config', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
}
