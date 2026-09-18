<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\EcommerceInstallationService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Misión `pusher-app-produccion-vs-desarrollo` (18/9/2026) — hasta esta misión,
 * `EcommerceInstallationService::build_spa_env_file_content()` no escribía NINGUNA variable de
 * Pusher en el `.env` que compila tienda-spa, y `tienda-spa/src/main.js` tenía la key hardcodeada
 * (y encima la de una app que ya no existe en la cuenta). Ahora las dos claves salen de la MISMA
 * config que ya usa `DeploymentService` para empresa-spa (`services.deploy.spa_pusher_key` /
 * `spa_pusher_cluster`): una sola cuenta de Pusher para toda la flota, no una por proyecto.
 *
 * Se prueba por reflexión, sobre una instancia sin constructor con `$client` seteado a mano: el
 * constructor real pide una `EcommerceOperationRun` persistida que este cálculo no usa (mismo
 * criterio que `ConfigJsDelSpaEnElDeploymentTest`/`DemoUpdateSpaBuildEnvTest`).
 */
class SpaDeTiendaCompilaConLaAppDePusherCentralTest extends TestCase
{
    /**
     * Instancia de EcommerceInstallationService con `$client` seteado (no persistido: alcanza con
     * el `user_id` que `owner_commerce_id()` necesita) y `$demo` en null (dueño real, no demo).
     *
     * @return EcommerceInstallationService
     */
    private function servicio_con_owner(): EcommerceInstallationService
    {
        $reflection = new ReflectionClass(EcommerceInstallationService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $client          = new Client();
        $client->user_id = 4200;

        $propiedad_client = $reflection->getProperty('client');
        $propiedad_client->setAccessible(true);
        $propiedad_client->setValue($service, $client);

        return $service;
    }

    /**
     * @param  EcommerceInstallationService  $service
     * @return string
     */
    private function compilar(EcommerceInstallationService $service): string
    {
        $reflection = new ReflectionClass(EcommerceInstallationService::class);
        $metodo     = $reflection->getMethod('build_spa_env_file_content');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(
            $service,
            'https://api-x.comerciocity.com/public',
            'https://x.com.ar',
            'Comercio de ejemplo',
            'Descripción de ejemplo'
        );
    }

    /** Con la config de producción cargada, el `.env` de tienda-spa lleva las dos claves. */
    public function test_lleva_vue_app_pusher_key_y_cluster_de_la_config_central(): void
    {
        config(['services.deploy.spa_pusher_key' => 'key-de-produccion-0x9']);
        config(['services.deploy.spa_pusher_cluster' => 'sa1']);

        $contenido = $this->compilar($this->servicio_con_owner());

        $this->assertStringContainsString('VUE_APP_PUSHER_KEY=key-de-produccion-0x9', $contenido);
        $this->assertStringContainsString('VUE_APP_PUSHER_CLUSTER=sa1', $contenido);
    }

    /**
     * 🔴 Sea cual sea la config, la app vieja (1561202/7fc3a66c…) no puede aparecer en ningún
     * build: hasta esta misión era literalmente imposible que no apareciera, porque
     * `tienda-spa/src/main.js` la tenía hardcodeada y este `.env` no escribía nada que la
     * pudiera pisar.
     */
    public function test_la_app_vieja_no_aparece_en_el_env_compilado(): void
    {
        config(['services.deploy.spa_pusher_key' => 'key-de-produccion-0x9']);
        config(['services.deploy.spa_pusher_cluster' => 'sa1']);

        $contenido = $this->compilar($this->servicio_con_owner());

        $this->assertStringNotContainsString('7fc3a66c', $contenido, 'La app vieja no puede aparecer en ningún build.');
        $this->assertStringNotContainsString('1561202', $contenido, 'La app vieja no puede aparecer en ningún build.');
    }
}
