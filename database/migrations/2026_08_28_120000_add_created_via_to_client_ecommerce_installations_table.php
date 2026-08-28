<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega client_ecommerce_installations.created_via, que registra el origen de la corrida.
 *
 * Valores previstos:
 * - "claude": corrida disparada por Claude vía POST claude/ecommerce/updates (o su lote).
 * - NULL: origen no marcado. Es lo que tienen todas las filas anteriores a esta migración y las
 *   que sigue creando el panel del admin (los tres botones de EcommerceInstallationController).
 *
 * 🔴 SIN ESTA COLUMNA EL COOLDOWN DEL LOTE NO EXISTE. El lote de ecommerce se frena a sí mismo con
 * `COOLDOWN_HORAS_ECOMMERCE`, y para eso tiene que poder distinguir "esta tienda ya la actualizó
 * Claude hace un rato" de "Lucas apretó el botón del panel hace un rato". Sin `created_via` las dos
 * cosas son la misma fila y el cooldown, o frena al panel (que no es asunto de Claude), o no frena
 * nada. La columna no es un adorno de auditoría: es el insumo de un freno.
 *
 * 🔴 Nullable, sin default y sin backfill, por el mismo motivo que
 * `client_version_upgrades.created_via` (2026_08_24_160200): inventarle un origen a las filas
 * históricas sería escribir un dato que nadie midió. NULL significa "origen no marcado" y deja la
 * semántica de todo lo existente intacta — incluido el cooldown, que sólo mira las de Claude.
 *
 * Sin índice a propósito: baja cardinalidad, tabla chica y el filtro siempre va acompañado de
 * `client_ecommerce_id`, que sí tiene índice (`cei_client_ecommerce_idx`).
 */
class AddCreatedViaToClientEcommerceInstallationsTable extends Migration
{
    /**
     * Agrega la columna created_via, nullable y sin default, justo después de `status`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_ecommerce_installations', function (Blueprint $table) {
            $table->string('created_via', 30)->nullable()->after('status');
        });
    }

    /**
     * Elimina la columna created_via.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_ecommerce_installations', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
}
