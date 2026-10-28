<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engancha cada cuota a su venta (misión ventas-y-comisiones, 28/10/2026).
 *
 * Nullable a propósito: las cuotas que ya existen quedan sin venta hasta que corre
 * `ventas:migrar-licencias`, y las rutas viejas de licencias (`client/{id}/licencias*`) siguen
 * creando cuotas sueltas. Sin foreign key, como el resto del repo.
 */
class AddVentaIdToLicenciaCuotasTable extends Migration
{
    /**
     * Agrega la columna con su índice.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('licencia_cuotas', function (Blueprint $table) {
            $table->unsignedBigInteger('venta_id')->nullable()->after('client_id')->index('lc_venta_idx');
        });
    }

    /**
     * Saca la columna y su índice.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('licencia_cuotas', function (Blueprint $table) {
            $table->dropIndex('lc_venta_idx');
            $table->dropColumn('venta_id');
        });
    }
}
