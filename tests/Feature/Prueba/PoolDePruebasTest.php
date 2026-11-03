<?php

namespace Tests\Feature\Prueba;

use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\AdminSetting;
use App\Models\TrialInstance;
use App\Models\UserIdBlock;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
use App\Services\UserIdBlockAllocatorService;
use Illuminate\Support\Facades\Queue;

/**
 * El pool pre-armado (D1 y D3): `pruebas:mantener-pool` y `CrearInstanciaDePoolJob`.
 */
class PoolDePruebasTest extends BaseDePruebaGratuita
{
    public function test_mantener_pool_encola_las_que_faltan_con_nombres_y_bloques_libres(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);

        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);

        $nuevas = TrialInstance::where('estado', TrialInstance::ESTADO_CREANDO)->orderBy('nombre')->get();
        $this->assertSame(['prueba-02'], $nuevas->pluck('nombre')->all(), 'Con mínimo 2 y una libre, se arma UNA.');
        $this->assertSame('4.3.99', $nuevas[0]->version);
        $this->assertSame('1.9.99', $nuevas[0]->ecommerce_version);
        $this->assertSame('tienda-prueba-02', $nuevas[0]->ecommerce_vps_path);

        /* El bloque de user_id quedó reservado y el asignador ya no lo sugiere. */
        $bloque = UserIdBlock::where('block_start', (int) $nuevas[0]->user_id)->first();
        $this->assertNotNull($bloque);
        $this->assertSame(PruebaPoolService::SOURCE_BLOQUE, $bloque->source);
        $this->assertNotSame((int) $nuevas[0]->user_id, (int) app(UserIdBlockAllocatorService::class)->suggest_next_block_start());

        Queue::assertPushed(CrearInstanciaDePoolJob::class, 1);

        /* Una segunda corrida no encola nada más: la que se está creando cuenta. */
        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
        Queue::assertPushed(CrearInstanciaDePoolJob::class, 1);
    }

    public function test_el_job_arma_la_instancia_escribe_los_cuatro_archivos_y_la_deja_libre(): void
    {
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        $this->zona_antes_y_despues(['prueba-01', 'api-prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01']);
        $this->vps->responder('pool-crear.sh', ['ok' => true, 'nombre' => 'prueba-01', 'db' => ['name' => 'prueba_01', 'user' => 'prueba_01', 'pass' => 'Clave-Generada-En-El-Vps-01'], 'hosts' => []]);

        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $instancia->estado, (string) $instancia->error);
        $this->assertSame(100, (int) $instancia->progreso);

        $this->assertSame(['pool-crear.sh', 'pool-configurar.sh'], $this->vps->scripts_corridos());
        $this->assertStringContainsString("pool-crear.sh 'prueba-01' '4.3.99' '1.9.99' '" . $instancia->user_id . "'", $this->vps->comandos_de('pool-crear.sh')[0]);

        /* Los A records del pool. */
        $puts = $this->hostinger->llamadas_de('PUT');
        $this->assertCount(1, $puts);
        $this->assertSame(['api-prueba-01', 'prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01'], array_column($puts[0]['body']['zone'], 'name'));

        /* Los cuatro archivos, con lo que importa adentro. */
        $this->assertSame([
            '/home/api-prueba-01/empresa-api/.env',
            '/home/api-tienda-prueba-01/tienda-api/.env',
            '/home/prueba-01/htdocs/prueba-01.comerciocity.com/config.js',
            '/home/tienda-prueba-01/htdocs/tienda-prueba-01.comerciocity.com/config.js',
        ], array_keys($this->vps->archivos));

        $env_erp = $this->vps->archivos['/home/api-prueba-01/empresa-api/.env'];
        $this->assertStringContainsString("APP_URL=https://api-prueba-01.comerciocity.com\n", $env_erp);
        $this->assertStringContainsString("SANCTUM_STATEFUL_DOMAINS=prueba-01.comerciocity.com\n", $env_erp);
        $this->assertStringContainsString('USER_ID=' . $instancia->user_id . "\n", $env_erp);
        $this->assertStringContainsString("DB_DATABASE=prueba_01\n", $env_erp);
        $this->assertStringContainsString("DB_PASSWORD=Clave-Generada-En-El-Vps-01\n", $env_erp);
        $this->assertStringContainsString("VPS=true\n", $env_erp);
        $this->assertStringContainsString("QUEUE_CONNECTION=redis\n", $env_erp);
        $this->assertStringContainsString("CACHE_PREFIX=prueba-01_\n", $env_erp);
        $this->assertStringContainsString("REDIS_PREFIX=prueba-01_\n", $env_erp);
        $this->assertStringContainsString("FOR_USER=prueba\n", $env_erp);
        $this->assertStringContainsString("ADMIN_SYNC_REQUIRE_API_KEY=true\n", $env_erp);
        $this->assertStringContainsString("MAIL_FROM_NAME='ComercioCity Sistemas'\n", $env_erp, 'Un valor con espacios va entre comillas simples (phpdotenv).');
        /* La APP_KEY lleva `+` y `=`, así que va entre comillas simples (lo que phpdotenv acepta literal). */
        $this->assertMatchesRegularExpression('/^APP_KEY=\'base64:[A-Za-z0-9+\/=]+\'$/m', $env_erp);

        $env_tienda = $this->vps->archivos['/home/api-tienda-prueba-01/tienda-api/.env'];
        $this->assertStringContainsString("APP_URL=https://api-tienda-prueba-01.comerciocity.com\n", $env_tienda);
        $this->assertStringContainsString("SANCTUM_STATEFUL_DOMAINS=tienda-prueba-01.comerciocity.com\n", $env_tienda);
        $this->assertStringContainsString("SESSION_DOMAIN=.comerciocity.com\n", $env_tienda, 'El padre común de tienda-x y api-tienda-x, como lo calcula el pipeline de tiendas.');
        $this->assertStringContainsString("DB_PASSWORD=Clave-Generada-En-El-Vps-01\n", $env_tienda);
        $this->assertStringContainsString("MAIL_PASSWORD=clave-de-mail-de-prueba\n", $env_tienda);
        preg_match('/^APP_KEY=(.+)$/m', $env_erp, $erp_key);
        $this->assertStringContainsString('APP_KEY=' . $erp_key[1] . "\n", $env_tienda, 'La tienda comparte la APP_KEY del ERP.');

        $config_erp = $this->vps->archivos['/home/prueba-01/htdocs/prueba-01.comerciocity.com/config.js'];
        $this->assertStringStartsWith('window.__CC_CONFIG__ = {', $config_erp);
        $this->assertStringContainsString('"VUE_APP_API_URL":"https://api-prueba-01.comerciocity.com"', $config_erp);
        $this->assertStringContainsString('"VUE_APP_PUSHER_KEY":"pusher-key-de-prueba"', $config_erp);

        $config_tienda = $this->vps->archivos['/home/tienda-prueba-01/htdocs/tienda-prueba-01.comerciocity.com/config.js'];
        $this->assertStringContainsString('"VUE_APP_COMMERCE_ID":"' . $instancia->user_id . '"', $config_tienda);
        $this->assertStringContainsString('"VUE_APP_API_URL":"https://api-tienda-prueba-01.comerciocity.com"', $config_tienda);

        /* La contraseña de la base viajó en el base64 de los archivos y NO en claro en ningún comando. */
        $this->assertStringNotContainsString('Clave-Generada-En-El-Vps-01', implode("\n", $this->vps->comandos));
    }

    public function test_si_pool_crear_falla_la_instancia_queda_fallida_y_no_cuenta_como_libre(): void
    {
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        $this->zona_antes_y_despues(['prueba-01', 'api-prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01']);
        $this->vps->fallar('pool-crear.sh', 'clpctl site:add:php: siteUser: This value is not valid');

        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertSame('crear', $instancia->etapa);
        $this->assertStringContainsString('siteUser', (string) $instancia->error);
        $this->assertSame([], $this->vps->archivos);
        $this->assertSame(0, PruebaPoolService::libres_o_creando());
    }

    public function test_mantener_pool_no_encola_con_el_dns_apagado_ni_con_una_fallida_sin_asignar_ni_con_minimo_0(): void
    {
        /* Interruptor apagado (el default en producción): avisa y no fabrica filas ni quema bloques de user_id. */
        config(['services.prueba.dns_write_enabled' => false]);
        $bloques_antes = UserIdBlock::count();
        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
        $this->assertSame(0, TrialInstance::count());
        $this->assertSame($bloques_antes, UserIdBlock::count());
        Queue::assertNothingPushed();

        /* Con el DNS prendido pero una fallida sin asignar: tampoco (se destraba con «Reintentar» o borrándola). */
        config(['services.prueba.dns_write_enabled' => true]);
        $fallida = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        $fallida->update(['estado' => TrialInstance::ESTADO_FALLIDA, 'etapa' => 'crear', 'error' => 'clpctl: algo']);
        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
        $this->assertSame(1, TrialInstance::count());
        Queue::assertNothingPushed();

        /* Mínimo 0, que es el default hasta que Lucas prenda el pool desde admin_settings: no hay nada que armar. */
        $fallida->delete();
        AdminSetting::set(PruebaSettings::KEY_POOL_MINIMO, '0');
        $this->assertSame(0, PruebaSettings::DEFAULT_POOL_MINIMO);
        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
        $this->assertSame(0, TrialInstance::count());
        Queue::assertNothingPushed();
    }

    public function test_si_falla_en_dns_se_libera_el_bloque_de_user_id_y_el_reintento_reserva_otro(): void
    {
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        $user_id   = (int) $instancia->user_id;
        $this->assertNotNull(UserIdBlock::where('block_start', $user_id)->first());

        /* La zona ya tiene `prueba-01` apuntando al VPS y esta instancia no lo escribió: frena en `dns`, antes de tocar el VPS. */
        $this->hostinger->responder('/api/dns/v1/zones/', $this->zona(['prueba-01']), 'GET');
        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertSame('dns', $instancia->etapa);
        $this->assertNull($instancia->user_id, 'El bloque se devolvió.');
        $this->assertNull(UserIdBlock::where('block_start', $user_id)->first());
        $this->assertSame([], $this->vps->scripts_corridos());

        /* El reintento reserva un bloque de nuevo antes de arrancar, y esta vez pasa el DNS (falla recién en `crear`). */
        $this->hostinger->limpiar();
        $this->zona_antes_y_despues(['prueba-01', 'api-prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01']);
        $this->vps->fallar('pool-crear.sh', 'fallo simulado');
        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame('crear', $instancia->etapa);
        $this->assertNotNull($instancia->user_id);
        $this->assertNotNull(UserIdBlock::where('block_start', (int) $instancia->user_id)->where('source', PruebaPoolService::SOURCE_BLOQUE)->first());
        $this->assertSame(['api-prueba-01', 'prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01'], $instancia->dns_labels_escritos());
    }

    public function test_el_proximo_nombre_saltea_los_que_existen_y_reusa_los_borrados(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $borrada = $this->crear_instancia_libre('prueba-02', 900200);
        $borrada->update(['estado' => TrialInstance::ESTADO_BORRADA]);
        $this->crear_instancia_libre('prueba-03', 900300);

        $this->assertSame('prueba-02', app(PruebaPoolService::class)->proximo_nombre_libre());
    }
}
