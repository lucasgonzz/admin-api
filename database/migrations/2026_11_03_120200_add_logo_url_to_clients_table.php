<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suma a `clients` el logo del negocio y el rastro de su sincronización (misión el-local, 3/11/2026).
 *
 * «El local» dibuja a cada negocio con su logo como cabeza. Lo llena una vez por día
 * `local:sincronizar-logos` leyendo `admin-sync/branding` del sistema del cliente (`image_url` de empresa,
 * si no `logo_url` de la tienda, si no `setup_data.logo_url`).
 *
 * - `logo_url`: la URL del logo, o null si el cliente no tiene ninguno.
 * - `logo_synced_at`: última sincronización EXITOSA (el sistema del cliente contestó).
 * - `logo_sync_status`: cómo terminó el último intento (ok, sin_logo, error_red, http_404…).
 *
 * Las tres nullable y con guarda `hasColumn`: la migración se puede correr dos veces sin romper.
 */
class AddLogoUrlToClientsTable extends Migration
{
    /**
     * Agrega las columnas.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('clients', 'logo_url')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('logo_url', 500)->nullable();
            });
        }

        if (! Schema::hasColumn('clients', 'logo_synced_at')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dateTime('logo_synced_at')->nullable();
            });
        }

        if (! Schema::hasColumn('clients', 'logo_sync_status')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('logo_sync_status', 40)->nullable();
            });
        }
    }

    /**
     * Quita las columnas.
     *
     * @return void
     */
    public function down()
    {
        foreach (['logo_sync_status', 'logo_synced_at', 'logo_url'] as $columna) {
            if (Schema::hasColumn('clients', $columna)) {
                Schema::table('clients', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
