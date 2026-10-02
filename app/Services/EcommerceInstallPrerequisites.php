<?php

namespace App\Services;

use App\Models\ClientEcommerce;
use App\Models\EnvTemplate;

/**
 * Lo que una INSTALACIÓN desde cero de una tienda necesita y una actualización no: de dónde sale el
 * `.env` de `tienda-api` (misión cruzada `versiones-tienda`, 2/10/2026).
 *
 * Existe para que haya UNA sola definición de esas condiciones. Hasta esta misión vivían adentro de
 * `EcommerceInstallationController::assert_deploy_prerequisites()` (el panel), y desde que Lucas pidió
 * poder instalar una tienda por API (`POST claude/ecommerce/installs`, 2/10/2026) las necesitan los
 * dos caminos. Dos copias de la misma lista de guardas es la forma conocida de que una se quede sin
 * la guarda que se le agrega a la otra. Los mensajes son EXACTAMENTE los que ya devolvía el panel.
 *
 * Las dos condiciones, en este orden:
 *  1. Hay plantilla de `.env` de tienda (`env_templates` con `scope = 'tienda'`): sin filas, el `.env`
 *     sale con lo mínimo y `tienda-api` queda instalada pero sin bootear.
 *  2. Existe de dónde copiar `DB_*` y `APP_KEY` (la misma base física que la empresa del dueño): la
 *     API de empresa activa del cliente, o la «ERP SPA URL» de la demo. Es lo que lee
 *     `EcommerceInstallationService::step_write_env()` vía `owner_empresa_env_vars()`; sin esto la
 *     instalación muere recién en `write_env`, con el SPA y la API ya subidos.
 */
class EcommerceInstallPrerequisites
{
    /**
     * El primer motivo por el que esta tienda no se puede instalar desde cero, o null si se puede.
     *
     * @param  ClientEcommerce  $client_ecommerce
     * @return string|null
     */
    public function problem_for_install(ClientEcommerce $client_ecommerce): ?string
    {
        // Plantilla base del .env de tienda ('scope' = 'tienda').
        if (! EnvTemplate::where('scope', 'tienda')->exists()) {
            return 'No hay una plantilla de .env de tienda cargada. Cargala o corré el seeder de plantillas de '
                . 'tienda en admin-api antes de arrancar la instalación.';
        }

        // De dónde salen DB_DATABASE, DB_USERNAME, DB_PASSWORD y APP_KEY para el .env de tienda-api.
        if ($client_ecommerce->is_demo()) {
            // Demo: se lee el .env del ERP de la demo, en la ruta que resuelve DemoPathResolver::api_path()
            // a partir del subdominio de la «ERP SPA URL».
            $demo = $client_ecommerce->demo;
            if ($demo === null || trim((string) $demo->erp_spa_url) === '') {
                return 'La tienda toma la base de datos y la clave de la aplicación del .env del ERP de la demo, '
                    . 'así que la demo necesita su «ERP SPA URL» cargada en el módulo de Demos.';
            }

            return null;
        }

        // Cliente: la API de empresa activa de su perfil.
        if ($client_ecommerce->client === null || $client_ecommerce->client->active_client_api === null) {
            return 'La tienda toma la base de datos y la clave de la aplicación del .env de la API de empresa del '
                . 'cliente, así que el cliente necesita una API activa seleccionada en su perfil.';
        }

        return null;
    }
}
