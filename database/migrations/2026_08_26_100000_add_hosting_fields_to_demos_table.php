<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `demos` el tipo de hosting (shared_hosting | vps) y el identificador en el VPS,
 * por sistema: uno para el ERP y otro para el ecommerce.
 *
 * Hasta ahora TODA demo se asumía en hosting compartido: DemoUpdateService armaba los paths
 * como domains/comerciocity.com/public_html/{slug}/api y ClientEmpresaApiUrlResolver le
 * agregaba /public a la URL de la API sin preguntar. Con la migración de clientes al VPS
 * (informe 20260826-plan-migracion-shared-a-vps.md) las demos también se van a mover, y el
 * admin tiene que saber por qué camino ir — igual que ya lo sabe para un cliente vía
 * client_apis.hosting_type.
 *
 * Ejemplos de uso del vps_path (misma convención que client_apis):
 *   API SSH:  /home/api-{vps_path}/empresa-api
 *   SPA SSH:  /home/{vps_path}/htdocs/{dominio_spa}
 *
 * A diferencia de client_apis, acá el vps_path es OPCIONAL: si queda vacío, DemoPathResolver
 * lo deduce del slug de erp_spa_url (demo3.comerciocity.com -> demo3). Decisión de Lucas,
 * 26/8/2026: el caso normal es que el sitio del VPS se llame igual que el subdominio.
 *
 * El default 'shared_hosting' es lo que hace que las demos que ya existen sigan comportándose
 * exactamente igual que antes, sin backfill de datos: al 26/8/2026 ninguna demo está en el VPS.
 */
class AddHostingFieldsToDemosTable extends Migration
{
    /**
     * Agrega las cuatro columnas, cada par pegado a las URLs de su sistema.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('demos', function (Blueprint $table) {
            /* Dónde vive el ERP de la demo: 'shared_hosting' (default histórico) o 'vps'. */
            $table->string('erp_hosting_type')->default('shared_hosting')->after('erp_api_url');

            /* Identificador del ERP de la demo en el VPS (ej: demo3). Vacío = se deduce del slug. */
            $table->string('erp_vps_path')->nullable()->after('erp_hosting_type');

            /* Dónde vive el ecommerce de la demo. Hoy se guarda como dato del catálogo: todavía
             * no existe pipeline de actualización de ecommerce para demos que lo consuma. */
            $table->string('ecommerce_hosting_type')->default('shared_hosting')->after('ecommerce_api_url');

            /* Identificador del ecommerce de la demo en el VPS. Mismo criterio que el del ERP. */
            $table->string('ecommerce_vps_path')->nullable()->after('ecommerce_hosting_type');
        });
    }

    /**
     * Elimina las cuatro columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->dropColumn([
                'erp_hosting_type',
                'erp_vps_path',
                'ecommerce_hosting_type',
                'ecommerce_vps_path',
            ]);
        });
    }
}
