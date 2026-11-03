<?php

namespace Tests\Feature\Prueba;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\ClientSshCredential;
use App\Models\EnvTemplate;
use App\Models\TrialInstance;
use App\Models\Version;
use App\Services\HostingerApiClient;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVpsService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\HostingerApiClientFake;
use Tests\Fakes\PruebaVpsServiceFake;
use Tests\TestCase;

/**
 * Base de los tests de la prueba gratuita (misión `prueba-alta-instancia`, 3/11/2026): el VPS, la API de
 * Hostinger y el HTTP hacia el ERP falsificados; una versión publicada de empresa y de tienda; la
 * credencial del VPS; las plantillas de `.env` mínimas; y las perillas de la prueba con valores fijos.
 *
 * 🔴 Ningún test sale a la red: `PruebaVpsServiceFake` no abre SSH, `HostingerApiClientFake` no llama a
 * Hostinger y `Http::fake()` atiende Meta y los hosts `*.comerciocity.com` del ERP.
 */
abstract class BaseDePruebaGratuita extends TestCase
{
    use DatabaseTransactions;

    const AHORA = '2026-11-03 15:00:00';

    const IP_VPS = '76.13.171.147';

    const TELEFONO = '3444622139';

    const TELEFONO_E164 = '+5493444622139';

    /** @var PruebaVpsServiceFake */
    protected $vps;

    /** @var HostingerApiClientFake */
    protected $hostinger;

    /** @var Admin */
    protected $admin;

    /** @var array<int, array<string, mixed>> Pedidos HTTP que salieron hacia los ERP (`*.comerciocity.com`). */
    protected $pedidos_al_erp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        Mail::fake();

        $this->vps = new PruebaVpsServiceFake();
        $this->app->instance(PruebaVpsService::class, $this->vps);

        $this->hostinger = new HostingerApiClientFake();
        $this->app->instance(HostingerApiClient::class, $this->hostinger);

        /* Los WhatsApp salientes (aviso de sin capacidad) no salen a Kapso. */
        $this->app->instance(WhatsappSendService::class, new class extends WhatsappSendService {
            public $enviados = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->enviados[] = ['to' => $to, 'template' => $template_name];

                return 'wamid.prueba';
            }

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->enviados[] = ['to' => $to, 'text' => $body];

                return 'wamid.prueba';
            }
        });

        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'traza'], 200),
            '*.comerciocity.com/*' => function ($request) {
                $this->pedidos_al_erp[] = ['url' => $request->url(), 'metodo' => $request->method(), 'body' => $request->data(), 'headers' => $request->headers()];

                return $this->respuesta_del_erp($request);
            },
        ]);

        config([
            'services.hostinger.api_token'        => 'token-de-prueba',
            'services.hostinger.account_username' => 'u767360347',
            'services.hostinger.domain'           => 'comerciocity.com',
            'services.hostinger.vps_ip'           => self::IP_VPS,
            'services.prueba.dns_write_enabled'   => true,
            'services.prueba.whatsapp_soporte'    => '+5493410000000',
            'services.admin_api.public_url'       => 'https://api-admin.comerciocity.com/public',
            'services.deploy.spa_pusher_key'      => 'pusher-key-de-prueba',
            'services.deploy.spa_pusher_cluster'  => 'sa1',
            'services.meta_capi.token'            => '',
            'services.meta_capi.dataset_id'       => '',
            'services.meta_capi.web_token'        => 'token-web-de-prueba',
            'services.meta_capi.web_dataset_id'   => '999000111222',
            'services.meta_capi.graph_version'    => 'v24.0',
            'services.meta_capi.test_event_code'  => '',
            'landing_agenda.hosts_de_origen'      => ['comerciocity.com', 'www.comerciocity.com'],
            'landing_agenda.abierto_minimo_ms'    => 2500,
        ]);

        AdminSetting::set(PruebaSettings::KEY_POOL_MINIMO, '2');
        AdminSetting::set(PruebaSettings::KEY_MAX_ACTIVAS, '25');
        AdminSetting::set(PruebaSettings::KEY_DIAS_DEFAULT, '7');
        AdminSetting::set(PruebaSettings::KEY_VIDEO_URL, 'https://videos.test/bienvenida.mp4');
        AdminSetting::set(PruebaSettings::KEY_DIAS_GRACIA_BORRADO, '30');

        $this->crear_credencial_vps();
        $this->crear_versiones_publicadas();
        $this->crear_plantillas_de_env();

        $this->admin = Admin::create(['name' => 'Operador de prueba', 'email' => 'prueba-' . uniqid() . '@test.local', 'password' => 'fixture-no-utilizable']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Lo que contesta un ERP falso: demo setup OK, C3 OK con `vence_at` devuelto. Los tests lo pisan con `$this->erp`. */
    protected $erp = [];

    protected function respuesta_del_erp($request)
    {
        $url = $request->url();

        foreach ($this->erp as $aguja => $respuesta) {
            if (strpos($url, $aguja) !== false) {
                return is_callable($respuesta) ? $respuesta($request) : $respuesta;
            }
        }

        if (strpos($url, 'admin-sync/demo-setup') !== false) {
            return Http::response(['ok' => true], 200);
        }
        if (strpos($url, 'admin-sync/prueba/vencimiento') !== false) {
            return Http::response(['vence_at' => isset($request->data()['vence_at']) ? $request->data()['vence_at'] : null], 200);
        }
        if (strpos($url, 'admin-sync/prueba/estado') !== false) {
            return Http::response(['vence_at' => null, 'vencida' => false, 'semilla_presente' => true, 'hitos' => []], 200);
        }

        return Http::response(['error' => 'ruta no prevista en el test'], 404);
    }

    /** Pedidos al ERP cuya URL contiene la aguja. */
    protected function pedidos_al_erp_con(string $aguja): array
    {
        return array_values(array_filter($this->pedidos_al_erp, function ($pedido) use ($aguja) {
            return strpos($pedido['url'], $aguja) !== false;
        }));
    }

    protected function crear_credencial_vps(): ClientSshCredential
    {
        $credential = ClientSshCredential::where('type', 'vps')->first();
        if ($credential !== null) {
            return $credential;
        }

        $credential           = new ClientSshCredential();
        $credential->type     = 'vps';
        $credential->host     = '127.0.0.1';
        $credential->port     = 22;
        $credential->username = 'root';
        $credential->password = 'test';
        $credential->save();

        return $credential;
    }

    protected function crear_versiones_publicadas(): void
    {
        Version::create(['version' => '4.3.99', 'title' => 'Prueba', 'status' => 'published', 'published_at' => now()]);
        \App\Models\EcommerceVersion::create(['version' => '1.9.99', 'title' => 'Tienda de prueba', 'status' => 'published', 'published_at' => now()]);
    }

    protected function crear_plantillas_de_env(): void
    {
        $filas = [
            ['scope' => 'empresa', 'key' => 'APP_ENV', 'value' => 'production', 'group' => 'app', 'sort_order' => 1],
            ['scope' => 'empresa', 'key' => 'APP_DEBUG', 'value' => 'false', 'group' => 'app', 'sort_order' => 2],
            ['scope' => 'empresa', 'key' => 'QUEUE_CONNECTION', 'value' => 'database', 'group' => 'misc', 'sort_order' => 1],
            ['scope' => 'empresa', 'key' => 'MAIL_PASSWORD', 'value' => 'clave-de-mail-de-prueba', 'group' => 'mail', 'sort_order' => 1],
            ['scope' => 'empresa', 'key' => 'MAIL_FROM_NAME', 'value' => 'ComercioCity Sistemas', 'group' => 'mail', 'sort_order' => 2],
            ['scope' => 'tienda', 'key' => 'APP_ENV', 'value' => 'production', 'group' => 'app', 'sort_order' => 1],
            ['scope' => 'tienda', 'key' => 'QUEUE_CONNECTION', 'value' => 'sync', 'group' => 'misc', 'sort_order' => 1],
        ];
        foreach ($filas as $fila) {
            EnvTemplate::updateOrCreate(['key' => $fila['key'], 'scope' => $fila['scope']], array_merge($fila, ['is_common' => true, 'is_manual_on_create' => false]));
        }
    }

    /** Una instancia `libre` del pool, lista para que un alta la tome. */
    protected function crear_instancia_libre(string $nombre = 'prueba-01', int $user_id = 900100): TrialInstance
    {
        return TrialInstance::create([
            'nombre'             => $nombre,
            'estado'             => TrialInstance::ESTADO_LIBRE,
            'user_id'            => $user_id,
            'erp_vps_path'       => $nombre,
            'ecommerce_vps_path' => 'tienda-' . $nombre,
            'version'            => '4.3.99',
            'ecommerce_version'  => '1.9.99',
            'etapa'              => 'libre',
            'progreso'           => 100,
        ]);
    }

    /** El cuerpo del alta desde la landing, con lo que M5 manda (ver ESTADO.md del programa). */
    protected function cuerpo(array $cambios = []): array
    {
        return array_merge([
            'nombre'           => 'Lucas Prueba',
            'telefono'         => self::TELEFONO,
            'email'            => 'lucasgonzalez5500@gmail.com',
            'empresa'          => 'Prueba Test',
            'password'         => 'clave-de-prueba-123',
            'rubro'            => 'ferreteria',
            'utm_source'       => 'facebook',
            'utm_campaign'     => 'prueba-gratis',
            'fbp'              => 'fb.1.1761130000000.1234567890',
            'event_source_url' => 'https://comerciocity.com/prueba',
            'sitio_web'        => '',
            'abierto_hace_ms'  => 8000,
        ], $cambios);
    }

    protected function postear_alta(array $cambios = [])
    {
        return $this->postJson('/api/landing/prueba/alta', $this->cuerpo($cambios), ['User-Agent' => 'Mozilla/5.0 (prueba)']);
    }

    /** La zona DNS del fake: `[['name', 'type', 'content']]`. */
    protected function zona(array $labels_al_vps, array $otros = []): array
    {
        $zona = [['name' => 'otrocliente', 'type' => 'A', 'content' => '1.2.3.4']];
        foreach ($labels_al_vps as $label) {
            $zona[] = ['name' => $label, 'type' => 'A', 'content' => self::IP_VPS];
        }
        foreach ($otros as $registro) {
            $zona[] = $registro;
        }

        return $zona;
    }

    /** Deja al fake de Hostinger contestando una zona vacía de este slug ANTES y completa DESPUÉS de escribir. */
    protected function zona_antes_y_despues(array $labels): void
    {
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [$this->zona([]), $this->zona($labels)], 'GET');
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'PUT');
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'DELETE');
        $this->hostinger->responder('/api/dns/v1/snapshots/', [['id' => 'snap-1', 'reason' => 'Zone records update request']], 'GET');
    }
}
