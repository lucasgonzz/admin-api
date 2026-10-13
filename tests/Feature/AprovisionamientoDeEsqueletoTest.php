<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\DeploymentLog;
use App\Services\HostingProvisioningService;
use App\Services\HostingerApiClient;
use App\Services\InstallationService;
use App\Services\RemoteCommandRunner;
use App\Services\SharedHostingProvisioning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\HostingerApiClientFake;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Fakes\RemoteCommandRunnerFake;
use Tests\TestCase;

/**
 * El esqueleto del subdominio secundario sobre el hosting compartido: la segunda corrida de
 * aprovisionamiento de un mismo cliente, que tiene que reusar lo que ya creó la real.
 *
 * Caso de producción (13/10/2026, cliente 73 Los Peña, implementación 8): la instalación real
 * terminó `completada` y el esqueleto terminó `fallida` a los 18 s, en provision_db, con
 * `La API de Hostinger respondió 422: {"message": "[Hosting:9999] Database name must be unique"}`.
 * Antes, provision_sites había recibido `422 "Subdomain is already hosted"` en los cuatro
 * subdominios y los había tratado como "error que no sé clasificar".
 *
 * La causa no era una sola cosa sino una clase de error: un lector de respuestas de un proveedor
 * externo que asume una forma sin verificarla. GET /databases responde `{"data": [...], "meta":
 * {...}}`, envuelto y paginado de a 25, y el código lo leía como lista pelada: la idempotencia de
 * la base no funcionó nunca en producción. Y la suite de aprovisionamiento no lo vio porque su
 * fake replicaba la misma suposición (`responder('/databases', [['name' => ...]], 'GET')`).
 *
 * 🔴 Por eso TODOS los fixtures de GET /databases de este archivo tienen la forma REAL de la API
 * ({data, meta}), construidos con respuesta_de_bases(). La lista pelada aparece una sola vez, en el
 * test que fija que sigue tolerada.
 *
 * 🔴 Ningún test de este archivo sale a la red ni toca HOSTINGER_API_TOKEN: HostingerApiClientFake
 * sobreescribe request() y nada más, así que el armado de rutas, la paginación y la clasificación
 * de errores corren de verdad.
 */
class AprovisionamientoDeEsqueletoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Fecha de aprovisionamiento que dejó la real en los tests de reuso. Fija a propósito: con
     * now() el test dependería de que no cruce la medianoche.
     *
     * @var string
     */
    const FECHA_DE_LA_REAL = '2026-10-12 10:00:00';

    /**
     * Host de mentira al que apunta el transporte REAL en los tests de la sección 9. Nada sale ahí:
     * Http::fake() atiende cada pedido, y el que no atienda lo frena HttpFactorySinSalida.
     *
     * @var string
     */
    const BASE_URL_DE_PRUEBA = 'https://hostinger.invalido.test';

    /**
     * Reemplazo en memoria del cliente HTTP de Hostinger, bindeado para toda la prueba.
     *
     * @var HostingerApiClientFake
     */
    private $hostinger;

    /**
     * Líneas que el proveedor de la instalación REAL mandó al panel: ['step', 'linea', 'level'].
     *
     * @var array<int, array<string, string>>
     */
    private $lineas = [];

    /**
     * Líneas que el proveedor del ESQUELETO mandó al panel. Van aparte para poder afirmar qué dijo
     * el esqueleto sin que lo que dijo la real se mezcle.
     *
     * @var array<int, array<string, string>>
     */
    private $lineas_del_esqueleto = [];

    /**
     * Reemplazo en memoria del runner de comandos remotos.
     *
     * @var RemoteCommandRunnerFake|null
     */
    private $runner = null;

    /**
     * Guion de lo que contesta el Hostinger "de mentira" de la sección 9 (el que atiende Http::fake()
     * por debajo del transporte REAL). Se lee en CADA pedido, así que un test lo cambia entre dos
     * fases (la real y el esqueleto) sin volver a armar el fake.
     *
     * Claves: `slug`, `lecturas_de_bases` (JSON crudo por cada GET /databases, la última se repite),
     * `post_subdominio` y `post_base` (cada uno [status, cuerpo crudo]).
     *
     * @var array<string, mixed>
     */
    private $escenario_http = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostinger = new HostingerApiClientFake();
        $this->app->instance(HostingerApiClient::class, $this->hostinger);

        /*
         * bind() con closure y no instance(): HostingProvisioningService::runner() resuelve el
         * runner con makeWith(['credential' => ...]) y el container ignora un instance() cuando la
         * resolución lleva parámetros. Sin esto, provision_sites() abriría un SSH real contra
         * 127.0.0.1 en cada test.
         */
        $this->app->bind(RemoteCommandRunner::class, function ($app, $parametros) {
            if ($this->runner === null) {
                $this->runner = new RemoteCommandRunnerFake($parametros['credential']);
            }

            return $this->runner;
        });

        /* La config se fija en el test y no se hereda del .env de la máquina. */
        config([
            'services.hostinger.api_token'        => 'token-de-prueba',
            'services.hostinger.account_username' => 'u767360347',
            'services.hostinger.domain'           => 'comerciocity.com',
            'services.hostinger.database_prefix'  => 'u767360347_',
            'services.hostinger.subdomain_directory_template'        => '{path}',
            'services.hostinger.subdomain_is_using_public_directory' => false,
            /* 0 = una sola lectura de la zona y sin sleep, para no colgar la suite. */
            'services.hostinger.zone_wait_seconds'                   => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 y 2 — EL PAR DE PUNTA A PUNTA: LA REAL CREA, EL ESQUELETO REUSA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Par nuevo de punta a punta: el caso exacto de Los Peña.
     *
     * La real crea los 4 subdominios y la base y guarda la contraseña en las dos ClientApi. Después
     * corre el esqueleto sobre el mismo cliente: Hostinger le contesta `422 "Subdomain is already
     * hosted"` en cada subdominio, y la base ya figura en la lista de la cuenta — EN LA PÁGINA 2 de
     * 3, que es donde cae una base de un cliente reciente entre las 74 de la cuenta.
     *
     * El esqueleto tiene que terminar sin crear nada, sin llamar siquiera al POST de bases, y los
     * secretos de las dos filas tienen que seguir siendo, byte por byte, los que dejó la real.
     */
    public function test_el_esqueleto_de_un_par_nuevo_reusa_la_base_y_los_subdominios_de_la_real(): void
    {
        $datos = $this->correr_la_real();
        $slug  = $datos['slug'];

        /* La real, antes de que el esqueleto toque nada. */
        $this->assertCount(4, $this->posts_a('/subdomains'));
        $this->assertCount(1, $this->posts_a('/databases'));
        $this->assertCount(5, $datos['proveedor']->result()->creados());

        $secretos_de_la_real = $datos['secretos_de_la_real'];
        $this->assertSame((string) $datos['installation']->id, $secretos_de_la_real['provisioned_by_installation_id']);
        $this->assertSame($secretos_de_la_real, $this->secretos_de($datos['api2']));

        $this->correr_el_esqueleto($datos);

        $esqueleto = $datos['proveedor_esqueleto'];

        /*
         * Los 4 POST de subdominio se intentaron (4 de la real + 4 del esqueleto: la idempotencia se
         * verifica contra el proveedor y no contra una columna), pero ninguno se tomó como error.
         */
        $this->assertCount(8, $this->posts_a('/subdomains'));

        $ya_existian = $esqueleto->result()->ya_existian();
        $subdominios = [];

        foreach ($ya_existian as $recurso) {
            if ($recurso['tipo'] === 'subdominio') {
                $subdominios[] = $recurso['nombre'];
            }
        }

        $this->assertSame($esqueleto->nombres_de_subdominios(), $subdominios);
        $this->assertCount(5, $ya_existian);
        $this->assertSame([], $esqueleto->result()->creados(), 'El esqueleto no tiene que crear NADA.');

        /*
         * 🔴 Antes de este arreglo los cuatro subdominios solo se salvaban por el desvío de último
         * recurso (el nombre figuraba en la zona DNS) y el panel decía "que no sé clasificar".
         * "already hosted" es un ya-existe reconocible y tiene que entrar por la puerta de adelante.
         */
        $this->assertCount(0, $this->lineas_que_contienen('que no sé clasificar', $this->lineas_del_esqueleto));
        $this->assertCount(4, $this->lineas_que_contienen('ya existía, se sigue', $this->lineas_del_esqueleto));

        /* La base: NINGÚN POST, ni siquiera el intento (el chequeo previo ya la vio). */
        $this->assertCount(1, $this->posts_a('/databases'), 'Solo el POST de la real.');

        /* Y la vio donde estaba: en la página 2, así que la lista se leyó paginada. */
        $this->assertContains(
            '/api/hosting/v1/accounts/u767360347/databases?page=2',
            array_column($this->hostinger->llamadas, 'ruta')
        );

        /* Los secretos de las DOS filas siguen siendo los de la real, hasta el id de quién los dejó. */
        $this->assertSame($secretos_de_la_real, $this->secretos_de($datos['api1']));
        $this->assertSame($secretos_de_la_real, $this->secretos_de($datos['api2']));
    }

    /**
     * El .env del esqueleto sale con la MISMA DB_PASSWORD que el de la real.
     *
     * Es la consecuencia que importa del caso anterior: write_env del esqueleto lee los secretos de
     * SU fila (la ClientApi 2). Si el esqueleto hubiera generado y guardado una contraseña propia, el
     * .env del subdominio secundario llevaría una clave que MySQL no conoce y el cliente quedaría con
     * un frente que no conecta a la base. Y como la API de Hostinger no deja resetear la contraseña,
     * no tendría arreglo desde el admin.
     */
    public function test_el_env_del_esqueleto_lleva_la_misma_password_que_el_de_la_real(): void
    {
        $datos = $this->correr_la_real();
        $this->correr_el_esqueleto($datos);

        $nombre_de_la_base   = 'u767360347_' . $datos['slug'];
        $password_de_la_real = $this->posts_a('/databases')[0]['body']['password'];

        $env_de_la_real = $this->invocar(
            new InstallationService($datos['installation']->fresh()),
            'merge_env_del_aprovisionamiento',
            [[]]
        );
        $env_del_esqueleto = $this->invocar(
            new InstallationService($datos['esqueleto']->fresh()),
            'merge_env_del_aprovisionamiento',
            [[]]
        );

        $this->assertSame(
            [
                'DB_DATABASE' => $nombre_de_la_base,
                'DB_USERNAME' => $nombre_de_la_base,
                'DB_PASSWORD' => $password_de_la_real,
            ],
            $env_del_esqueleto
        );
        $this->assertSame($env_de_la_real, $env_del_esqueleto);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 a 6 — EL POST DE LA BASE FALLA: CUÁNDO SE REUSA Y CUÁNDO NO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El 422 literal de producción, con la base que recién aparece al releer la lista.
     *
     * Es la carrera que justifica el try/catch: el chequeo previo no ve la base (otra corrida del
     * mismo cliente, o Hostinger todavía no la refleja en la lista), el POST choca con
     * `[Hosting:9999] Database name must be unique`, y la relectura —que ahora sí la trae— es la que
     * autoriza a reusarla. Se reusa con un warning, con UN solo POST, y SIN tocar los secretos.
     */
    public function test_el_422_literal_de_produccion_se_reusa_si_la_base_figura_al_releer_la_lista(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $nombre = 'u767360347_' . $datos['slug'];

        $secretos = $this->dejar_secretos_de_la_real($datos);

        $this->hostinger->responder_secuencia('/databases', [
            $this->respuesta_de_bases([]),
            $this->respuesta_de_bases([$nombre]),
        ], 'GET');
        $this->hostinger->fallar_con('/databases', 422, '{"message": "[Hosting:9999] Database name must be unique"}', 'POST');

        $datos['proveedor_esqueleto']->provision_db();

        $this->assertCount(1, $this->posts_a('/databases'), 'Exactamente un intento de creación.');
        $this->assertCount(2, $this->gets_a('/databases'), 'La lectura previa y la verificación, nada más.');

        $this->assertSame(
            [['tipo' => 'base', 'nombre' => $nombre]],
            $datos['proveedor_esqueleto']->result()->ya_existian()
        );
        $this->assertSame([], $datos['proveedor_esqueleto']->result()->creados());

        $avisos = $this->lineas_que_contienen('ya figura en la cuenta', $this->lineas_del_esqueleto);
        $this->assertCount(1, $avisos);
        $this->assertSame('warning', $avisos[0]['level']);
        $this->assertStringContainsString('Database name must be unique', $avisos[0]['linea']);

        $this->afirmar_secretos_intactos($datos, $secretos);
    }

    /**
     * Un 422 con un texto que NO se reconoce también se verifica antes de rendirse.
     *
     * El mensaje de Hostinger puede cambiar de redacción: lo que no se puede permitir es que ese
     * cambio vuelva a tirar abajo al esqueleto. Un error DESCONOCIDO no se da por "ya existe", pero
     * tampoco se relanza sin mirar: se pregunta a la cuenta, y si la base está, se reusa.
     */
    public function test_un_422_con_texto_desconocido_tambien_se_verifica_y_se_reusa_si_la_base_figura(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $nombre = 'u767360347_' . $datos['slug'];

        $secretos = $this->dejar_secretos_de_la_real($datos);

        $this->hostinger->responder_secuencia('/databases', [
            $this->respuesta_de_bases([]),
            $this->respuesta_de_bases([$nombre]),
        ], 'GET');
        $this->hostinger->fallar_con('/databases', 422, '{"message":"Algo que Hostinger redactó distinto."}', 'POST');

        $datos['proveedor_esqueleto']->provision_db();

        $this->assertCount(1, $this->posts_a('/databases'));
        $this->assertCount(2, $this->gets_a('/databases'));
        $this->assertSame(
            [['tipo' => 'base', 'nombre' => $nombre]],
            $datos['proveedor_esqueleto']->result()->ya_existian()
        );
        $this->afirmar_secretos_intactos($datos, $secretos);
    }

    /**
     * 🔴 No se da por buena una base sin verificarla: el mismo 422, pero la base NO está en la lista.
     *
     * Es el test que sostiene "nunca se adivina". Un "Database name must be unique" sin la base a la
     * vista puede ser un nombre tomado por OTRA cuenta, un resto a medio borrar, o una respuesta
     * equivocada: seguir de largo dejaría al pipeline creyendo que la base está y escribiendo un
     * .env que no conecta. La etapa tiene que fallar con el error ORIGINAL de la creación, y no
     * dejar ningún secreto escrito.
     */
    public function test_el_mismo_422_sin_la_base_en_la_lista_falla_con_el_error_original_y_no_guarda_nada(): void
    {
        $datos = $this->preparar_par_de_instalaciones();

        /* La cuenta tiene bases, pero no la de este cliente. */
        $this->hostinger->responder('/databases', $this->respuesta_de_bases(['u767360347_otro_cliente']), 'GET');
        $this->hostinger->fallar_con('/databases', 422, '{"message": "[Hosting:9999] Database name must be unique"}', 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor_esqueleto']->provision_db();
        });

        $this->assertStringContainsString('Database name must be unique', $mensaje);
        $this->assertStringContainsString('respondió 422', $mensaje);

        $this->assertCount(1, $this->posts_a('/databases'));
        $this->assertCount(2, $this->gets_a('/databases'), 'La verificación SÍ se hizo.');
        $this->assertSame([], $datos['proveedor_esqueleto']->result()->ya_existian());
        $this->assertSame([], $datos['proveedor_esqueleto']->result()->creados());
        $this->assertNull($this->secretos_de($datos['api1']));
        $this->assertNull($this->secretos_de($datos['api2']));
        $this->assertNull(ClientApi::find($datos['api2']->id)->hosting_provisioned_at);
    }

    /**
     * Lo mismo con un 422 de texto desconocido: sin la base a la vista, tampoco se da por buena.
     */
    public function test_un_422_desconocido_sin_la_base_en_la_lista_falla_con_el_error_original(): void
    {
        $datos = $this->preparar_par_de_instalaciones();

        $this->hostinger->responder('/databases', $this->respuesta_de_bases([]), 'GET');
        $this->hostinger->fallar_con('/databases', 422, '{"message":"Algo que Hostinger redactó distinto."}', 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor_esqueleto']->provision_db();
        });

        $this->assertStringContainsString('Algo que Hostinger redactó distinto', $mensaje);
        $this->assertNull($this->secretos_de($datos['api2']));
    }

    /**
     * Si la verificación misma falla, se relanza el error ORIGINAL y no el de la verificación.
     *
     * El operador tiene que leer lo que le pasó a la creación de la base (el 422), no un 502 de una
     * lectura posterior que no explica nada. Se usa un fake que hace fallar SOLO la segunda lectura
     * de bases: la primera (el chequeo previo) tiene que pasar para llegar al POST.
     */
    public function test_si_la_verificacion_falla_se_relanza_el_error_original_de_la_creacion(): void
    {
        $fake = new class extends HostingerApiClientFake {
            /**
             * Cuántas veces se leyó GET /databases.
             *
             * @var int
             */
            public $lecturas_de_bases = 0;

            protected function request(string $method, string $path, array $body = []): array
            {
                if (strtoupper($method) === 'GET' && strpos($path, '/databases') !== false) {
                    $this->lecturas_de_bases++;

                    if ($this->lecturas_de_bases === 2) {
                        $this->llamadas[] = ['metodo' => 'GET', 'ruta' => $path, 'body' => $body];

                        throw new \RuntimeException('La API de Hostinger respondió 502: Bad Gateway', 502);
                    }
                }

                return parent::request($method, $path, $body);
            }
        };

        $this->hostinger = $fake;
        $this->app->instance(HostingerApiClient::class, $fake);

        $datos = $this->preparar_par_de_instalaciones();

        $this->hostinger->responder('/databases', $this->respuesta_de_bases([]), 'GET');
        $this->hostinger->fallar_con('/databases', 422, '{"message": "[Hosting:9999] Database name must be unique"}', 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor_esqueleto']->provision_db();
        });

        $this->assertSame(2, $fake->lecturas_de_bases, 'La verificación se intentó.');
        $this->assertStringContainsString('Database name must be unique', $mensaje);
        $this->assertStringNotContainsString('502', $mensaje);
        $this->assertNull($this->secretos_de($datos['api2']));
    }

    /**
     * Un error que seguro NO es "ya existe" se relanza sin reinterpretarlo ni consultar la lista.
     *
     * Token vencido, permisos, una ruta que no existe o una caída del proveedor no tienen nada que
     * ver con que la base exista, y una segunda lectura contra una API que acaba de fallar solo
     * agregaría ruido (o, peor, un falso "ya existe" si la lista tuviera una base homónima).
     * La única lectura de /databases es la del chequeo previo.
     *
     * @dataProvider errores_que_no_se_reinterpretan
     *
     * @param  int  $codigo
     */
    public function test_otro_error_del_post_no_se_reinterpreta_ni_consulta_la_lista(int $codigo): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $nombre = 'u767360347_' . $datos['slug'];

        /*
         * La base NO está en la primera lectura y SÍ en la segunda. Si el código volviera a leer la
         * lista tras el error, la reusaría como si nada; el test lo detecta por el conteo de lecturas
         * y porque la etapa no debería terminar bien.
         */
        $this->hostinger->responder_secuencia('/databases', [
            $this->respuesta_de_bases([]),
            $this->respuesta_de_bases([$nombre]),
        ], 'GET');
        $this->hostinger->fallar_con('/databases', $codigo, '{"message":"Falla ajena a la existencia de la base."}', 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor_esqueleto']->provision_db();
        });

        $this->assertStringContainsString('respondió ' . $codigo, $mensaje);
        $this->assertCount(1, $this->posts_a('/databases'));
        $this->assertCount(1, $this->gets_a('/databases'), 'Solo la lectura del chequeo previo.');
        $this->assertSame([], $datos['proveedor_esqueleto']->result()->ya_existian());
        $this->assertNull($this->secretos_de($datos['api2']));
    }

    /**
     * @return array<string, array<int, int>>
     */
    public function errores_que_no_se_reinterpretan(): array
    {
        return [
            '401 token rechazado'     => [401],
            '403 sin permiso'         => [403],
            '404 ruta inexistente'    => [404],
            '500 caída del proveedor' => [500],
        ];
    }

    /**
     * La base existe (la lista la trae desde el chequeo previo) y ninguna fila tiene su contraseña.
     *
     * Es el caso de un resto de otra prueba, o de una base cuyo secreto nunca se guardó: la API de
     * Hostinger no deja leer ni resetear la contraseña, así que no hay reintento posible y el
     * mensaje tiene que decir qué hacer. No se llega al POST y no se escribe nada.
     */
    public function test_la_base_existente_sin_secretos_falla_con_el_mensaje_del_operador(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $nombre = 'u767360347_' . $datos['slug'];

        $this->hostinger->responder('/databases', $this->respuesta_de_bases([$nombre]), 'GET');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor_esqueleto']->provision_db();
        });

        $this->assertSame(sprintf(SharedHostingProvisioning::MENSAJE_BASE_SIN_SECRETO, $nombre), $mensaje);
        $this->assertStringContainsString('no permite leerla ni resetearla', $mensaje);
        $this->assertCount(0, $this->posts_a('/databases'));
        $this->assertNull($this->secretos_de($datos['api1']));
        $this->assertNull($this->secretos_de($datos['api2']));
    }

    /**
     * Lo mismo, pero llegando por el 422: la base aparece al verificar y no hay contraseña.
     *
     * El mensaje que sale es el del operador (MENSAJE_BASE_SIN_SECRETO) y no el 422 crudo: ya se
     * sabe de quién es el problema, y es de la contraseña que falta. Los secretos de la fila pueden
     * existir sin la contraseña (acá, solo el uid de un cron): lo que cuenta es db_password.
     */
    public function test_el_422_con_la_base_a_la_vista_pero_sin_password_guardada_falla_con_el_mensaje_del_operador(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $nombre = 'u767360347_' . $datos['slug'];

        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $api->provisioning_secrets = ['cron_uid' => 'un-cron'];
            $api->save();
        }

        $this->hostinger->responder_secuencia('/databases', [
            $this->respuesta_de_bases([]),
            $this->respuesta_de_bases([$nombre]),
        ], 'GET');
        $this->hostinger->fallar_con('/databases', 422, '{"message": "[Hosting:9999] Database name must be unique"}', 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor_esqueleto']->provision_db();
        });

        $this->assertSame(sprintf(SharedHostingProvisioning::MENSAJE_BASE_SIN_SECRETO, $nombre), $mensaje);
        $this->assertCount(1, $this->posts_a('/databases'));
        $this->assertSame(['cron_uid' => 'un-cron'], $this->secretos_de($datos['api2']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7 — HostingerApiClient::list_databases()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Desenvuelve `data` y recorre las 3 páginas de la cuenta real (25 + 25 + 24 = 74 filas).
     *
     * La primera llamada va SIN query string —es la ruta que ya fija HostingerApiClientTest— y las
     * siguientes con `?page=N`. Se mira también el orden de las filas: es la lista de la API tal
     * cual, página tras página.
     */
    public function test_list_databases_desenvuelve_y_recorre_las_tres_paginas_de_la_cuenta(): void
    {
        $paginas = $this->tres_paginas_de_bases();
        $this->hostinger->responder_secuencia('/databases', $paginas, 'GET');

        $filas = $this->hostinger->list_databases();

        $this->assertCount(74, $filas);
        $this->assertSame($this->nombres_de_relleno(1, 74), array_column($filas, 'name'));

        $ruta = '/api/hosting/v1/accounts/u767360347/databases';

        $this->assertSame(
            [$ruta, $ruta . '?page=2', $ruta . '?page=3'],
            array_column($this->hostinger->llamadas, 'ruta'),
            'La página 1 sin query string; después ?page=2 y ?page=3, y no una cuarta.'
        );
        $this->assertSame(['GET', 'GET', 'GET'], array_column($this->hostinger->llamadas, 'metodo'));
    }

    /**
     * La lista pelada (los fakes viejos y cualquier respuesta sin envoltorio) sigue tolerada: se usa
     * tal cual y NO se pagina, porque sin `meta` no hay de dónde saber que falta algo.
     */
    public function test_list_databases_tolera_la_lista_pelada_sin_paginar(): void
    {
        $this->hostinger->responder('/databases', [['name' => 'u767360347_a'], ['name' => 'u767360347_b']], 'GET');

        $filas = $this->hostinger->list_databases();

        $this->assertSame(['u767360347_a', 'u767360347_b'], array_column($filas, 'name'));
        $this->assertCount(1, $this->hostinger->llamadas);
        $this->assertStringNotContainsString('?', $this->hostinger->llamadas[0]['ruta']);
    }

    /**
     * Una respuesta vacía da una lista vacía, venga pelada o envuelta, con una sola llamada.
     */
    public function test_list_databases_con_una_respuesta_vacia_devuelve_una_lista_vacia(): void
    {
        $this->hostinger->responder('/databases', [], 'GET');
        $this->assertSame([], $this->hostinger->list_databases());
        $this->assertCount(1, $this->hostinger->llamadas);

        $this->hostinger->limpiar();
        $this->hostinger->responder('/databases', $this->respuesta_de_bases([]), 'GET');
        $this->assertSame([], $this->hostinger->list_databases());
        $this->assertCount(1, $this->hostinger->llamadas);
    }

    /**
     * Sin `meta` la respuesta envuelta es una sola página, aunque traiga filas.
     *
     * Asumir lo contrario dejaría a una API que dejara de paginar en un bucle de peticiones.
     */
    public function test_list_databases_sin_meta_es_una_sola_pagina(): void
    {
        $this->hostinger->responder('/databases', ['data' => [['name' => 'u767360347_a']]], 'GET');

        $this->assertSame([['name' => 'u767360347_a']], $this->hostinger->list_databases());
        $this->assertCount(1, $this->hostinger->llamadas);
    }

    /**
     * Una página vacía corta la lectura aunque `meta` diga que faltan: si la API se contradice,
     * seguir pidiendo no la arregla (y es lo que evita un bucle).
     */
    public function test_list_databases_corta_en_una_pagina_vacia_aunque_meta_prometa_mas(): void
    {
        $this->hostinger->responder('/databases', $this->respuesta_de_bases([], 1, 74), 'GET');

        $this->assertSame([], $this->hostinger->list_databases());
        $this->assertCount(1, $this->hostinger->llamadas);
    }

    /**
     * 🔴 Pasarse del tope de páginas LANZA: una lista parcial daría un falso "la base no existe".
     *
     * Es el mismo bug que se vino a cerrar, por la otra puerta: devolver lo que se juntó hasta el
     * tope haría que base_ya_existe() dijera false para una base que existe en la página 21, y el
     * esqueleto volvería a intentar crearla. Acá la API promete un millón de filas y devuelve
     * siempre lo mismo; la lectura tiene que parar en el tope y decirlo.
     */
    public function test_list_databases_lanza_si_meta_promete_mas_paginas_que_el_tope(): void
    {
        $this->hostinger->responder(
            '/databases',
            $this->respuesta_de_bases($this->nombres_de_relleno(1, 25), 1, 1000000),
            'GET'
        );

        $excepcion = $this->excepcion_de(function () {
            $this->hostinger->list_databases();
        });

        $this->assertInstanceOf(\RuntimeException::class, $excepcion);
        $this->assertSame(0, $excepcion->getCode());
        $this->assertStringContainsString('tope de ' . HostingerApiClient::MAX_PAGINAS_DE_LISTA . ' páginas', $excepcion->getMessage());
        $this->assertStringContainsString('lista parcial', $excepcion->getMessage());
        $this->assertCount(HostingerApiClient::MAX_PAGINAS_DE_LISTA, $this->hostinger->llamadas);
    }

    /**
     * El borde del tope: una lista que ocupa EXACTAMENTE el máximo de páginas se lee completa.
     *
     * Fija que el tope se aplica cuando todavía faltan páginas y no cuando se llega a la última:
     * con un `>=` mal puesto, una cuenta de 500 bases (20 páginas de 25) tiraría una excepción
     * habiendo leído todo. Con una base más (501) sí hace falta la 21 y sí tiene que lanzar.
     */
    public function test_list_databases_lee_completa_una_lista_de_exactamente_el_tope_de_paginas(): void
    {
        $maximo = HostingerApiClient::MAX_PAGINAS_DE_LISTA;

        $this->hostinger->responder(
            '/databases',
            $this->respuesta_de_bases($this->nombres_de_relleno(1, 25), 1, $maximo * 25),
            'GET'
        );

        $this->assertCount($maximo * 25, $this->hostinger->list_databases());
        $this->assertCount($maximo, $this->hostinger->llamadas);

        $this->hostinger->limpiar();
        $this->hostinger->responder(
            '/databases',
            $this->respuesta_de_bases($this->nombres_de_relleno(1, 25), 1, $maximo * 25 + 1),
            'GET'
        );

        $excepcion = $this->excepcion_de(function () {
            $this->hostinger->list_databases();
        });

        $this->assertInstanceOf(\RuntimeException::class, $excepcion);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8 — CLASIFICACIÓN DE ERRORES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Los dos 422 reales de Hostinger (13/10/2026) son "ya existe", con cualquier capitalización.
     */
    public function test_los_dos_422_reales_de_hostinger_se_clasifican_como_ya_existe(): void
    {
        $cliente = new HostingerApiClient();

        $mensajes = [
            'La API de Hostinger respondió 422: {"message": "[Hosting:9999] Subdomain is already hosted"}',
            'La API de Hostinger respondió 422: {"message": "[Hosting:9999] Database name must be unique"}',
            'La API de Hostinger respondió 422: {"message": "[HOSTING:9999] DATABASE NAME MUST BE UNIQUE"}',
        ];

        foreach ($mensajes as $mensaje) {
            $excepcion = new \RuntimeException($mensaje, 422);

            $this->assertSame(
                HostingerApiClient::CLASIFICACION_YA_EXISTE,
                $cliente->clasificar_error($excepcion),
                $mensaje
            );
            $this->assertTrue($cliente->es_error_de_ya_existe($excepcion), $mensaje);
        }
    }

    /**
     * Un 422 de otro texto sigue DESCONOCIDO, y los errores ajenos a la existencia siguen aparte.
     *
     * Lo que se agregó a la lista son DOS frases medidas, no una categoría nueva: un 422 cualquiera
     * no pasa a "ya existe", y 401/500/transporte siguen siendo OTRO_ERROR.
     */
    public function test_un_422_de_otro_texto_sigue_desconocido_y_los_otros_errores_siguen_aparte(): void
    {
        $cliente = new HostingerApiClient();

        $desconocido = new \RuntimeException(
            'La API de Hostinger respondió 422: {"message": "[Hosting:9999] The website domain is invalid"}',
            422
        );
        $this->assertSame(HostingerApiClient::CLASIFICACION_DESCONOCIDA, $cliente->clasificar_error($desconocido));

        $otros = [
            401 => 'La API de Hostinger respondió 401: {"message":"Unauthenticated."}',
            500 => 'La API de Hostinger respondió 500: {"message":"Server error."}',
            0   => 'Error de conexión con la API de Hostinger: cURL error 28: timeout',
        ];

        foreach ($otros as $codigo => $mensaje) {
            $this->assertSame(
                HostingerApiClient::CLASIFICACION_OTRO_ERROR,
                $cliente->clasificar_error(new \RuntimeException($mensaje, $codigo)),
                'El código ' . $codigo . ' tiene que seguir siendo otro_error.'
            );
        }
    }

    /**
     * 🔴 Fija que NO se ensanchó de más: la misma lista la leen los provisioners del VPS.
     *
     * VpsDatabaseProvisioner y VpsSiteProvisioner clasifican errores de `clpctl` con esta lista. Un
     * "Site already exists." sigue siendo "ya existe"; uno que no dice nada de eso no lo es, y
     * tampoco lo es un mensaje de validación que mencione `must be unique` sin ser el de nombre de
     * base de Hostinger: por eso la frase agregada es `name must be unique` y no `must be unique`.
     */
    public function test_los_errores_de_clpctl_del_vps_no_se_clasifican_de_mas(): void
    {
        $cliente = new HostingerApiClient();

        $ya_existe = new \RuntimeException(
            'El comando remoto falló (exit 1): clpctl site:add:php --domainName=api-lacava.comerciocity.com → Site already exists.',
            1
        );
        $this->assertSame(HostingerApiClient::CLASIFICACION_YA_EXISTE, $cliente->clasificar_error($ya_existe));

        $inventado = new \RuntimeException(
            'El comando remoto falló (exit 1): clpctl db:add --databaseName=lacava → Something went sideways.',
            1
        );
        $this->assertSame(HostingerApiClient::CLASIFICACION_DESCONOCIDA, $cliente->clasificar_error($inventado));
        $this->assertFalse($cliente->es_error_de_ya_existe($inventado));

        $de_validacion = new \RuntimeException(
            'El comando remoto falló (exit 1): clpctl site:add:php --siteUser=lacava → Validation error: the site user must be unique.',
            1
        );
        $this->assertSame(HostingerApiClient::CLASIFICACION_DESCONOCIDA, $cliente->clasificar_error($de_validacion));
        $this->assertFalse($cliente->es_error_de_ya_existe($de_validacion));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9 — EL TRANSPORTE REAL, CON Http::fake
    // ─────────────────────────────────────────────────────────────────────────
    //
    // 🔴 Por qué esta sección existe. Todo lo de arriba corre sobre HostingerApiClientFake, que
    // sobreescribe request() y devuelve arrays YA decodificados: el json_decode del envoltorio
    // {data, meta} y la URL `?page=N` que arma el transporte real quedaban sin probar, y son
    // justamente las dos cosas que estaban mal en producción. Acá se usa el HostingerApiClient de
    // verdad y se falsea una capa más abajo: el HTTP. Cada respuesta es el JSON CRUDO que mandaría
    // Hostinger, y los pedidos se verifican con lo que Http::fake() registró.

    /**
     * list_databases() contra el transporte real: decodifica el JSON crudo y pide las tres URLs.
     *
     * 74 filas con las claves REALES de una fila de la API (medidas el 13/10/2026), en tres
     * páginas. La primera URL termina en `/databases` SIN query string y las otras en `?page=2` y
     * `?page=3`: exactamente tres GET, sin una cuarta página de más.
     */
    public function test_con_el_transporte_real_list_databases_decodifica_el_json_y_pide_las_tres_urls(): void
    {
        $this->usar_el_transporte_real();
        $this->escenario_http['lecturas_de_bases'] = $this->tres_paginas_crudas_de_bases();

        $filas = (new HostingerApiClient())->list_databases();

        $this->assertCount(74, $filas);
        $this->assertSame($this->nombres_de_relleno(1, 74), array_column($filas, 'name'));
        $this->assertSame(
            ['name', 'user', 'domain', 'permissions', 'created_at', 'updated_at', 'disk_usage_mb', 'max_size_mb', 'host', 'port'],
            array_keys($filas[0]),
            'La fila llega entera, no solo el nombre.'
        );

        $url = self::BASE_URL_DE_PRUEBA . '/api/hosting/v1/accounts/u767360347/databases';

        Http::assertSentCount(3);
        $this->assertSame(
            [['GET', $url], ['GET', $url . '?page=2'], ['GET', $url . '?page=3']],
            array_map(function ($pedido) {
                return [$pedido['metodo'], $pedido['url']];
            }, $this->pedidos_http())
        );
        $this->assertStringNotContainsString('?', $this->pedidos_http()[0]['url']);
    }

    /**
     * El par completo contra el transporte real, con la base en la página 3 de 3.
     *
     * La real crea los 4 subdominios y la base (lectura vacía, POST 200). Después, el esqueleto: los
     * cuatro POST de subdominio dan 422 "already hosted", la base figura en la ÚLTIMA página, y el
     * POST de la base —si se intentara— daría el 422 "must be unique". El esqueleto tiene que
     * terminar sin ningún POST a /databases, con los 4 subdominios como ya existentes y con los
     * secretos de las dos filas idénticos a los que dejó la real.
     */
    public function test_el_esqueleto_contra_el_transporte_real_reusa_la_base_de_la_pagina_tres(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $slug   = $datos['slug'];
        $nombre = 'u767360347_' . $slug;

        $this->usar_el_transporte_real($slug);

        /* La real. */
        $this->escenario_http['post_base'] = [200, '{"name":"' . $nombre . '"}'];

        $real = $datos['proveedor'];
        $real->provision_check();
        $real->provision_sites();
        $real->provision_dns();
        $real->provision_db();

        $this->assertCount(4, $this->pedidos_http_a('POST', '/subdomains'));
        $this->assertCount(1, $this->pedidos_http_a('POST', '/databases'));

        $secretos_de_la_real = $this->secretos_de($datos['api1']);
        $this->assertSame($nombre, $secretos_de_la_real['db_name']);
        $this->assertSame($secretos_de_la_real, $this->secretos_de($datos['api2']));

        /* El esqueleto, con el registro de pedidos EN BLANCO: lo que se afirma abajo es solo de él. */
        $this->empezar_a_registrar_pedidos_http();
        $this->escenario_http['post_subdominio']   = [422, '{"message":"[Hosting:9999] Subdomain is already hosted"}'];
        $this->escenario_http['post_base']         = [422, '{"message": "[Hosting:9999] Database name must be unique"}'];
        $this->escenario_http['lecturas_de_bases'] = $this->tres_paginas_crudas_de_bases($nombre, 3);

        $esqueleto = $datos['proveedor_esqueleto'];
        $esqueleto->provision_check();
        $esqueleto->provision_sites();
        $esqueleto->provision_dns();
        $esqueleto->provision_db();

        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST' && strpos($request->url(), '/databases') !== false;
        });
        $this->assertCount(4, $this->pedidos_http_a('POST', '/subdomains'), 'Los 4 se intentaron.');
        $this->assertCount(3, $this->pedidos_http_a('GET', '/databases'), 'Leyó las tres páginas, hasta la última.');

        $ya_existian = $esqueleto->result()->ya_existian();
        $this->assertCount(5, $ya_existian);
        $this->assertSame('base', $ya_existian[4]['tipo']);
        $this->assertSame($nombre, $ya_existian[4]['nombre']);
        $this->assertSame([], $esqueleto->result()->creados());
        $this->assertCount(0, $this->lineas_que_contienen('que no sé clasificar', $this->lineas_del_esqueleto));

        $this->assertSame($secretos_de_la_real, $this->secretos_de($datos['api1']));
        $this->assertSame($secretos_de_la_real, $this->secretos_de($datos['api2']));
    }

    /**
     * El 422 literal de producción por el transporte real: lectura 1 sin la base, el POST da 422
     * "Database name must be unique", la lectura 2 sí la trae → se reusa, con UN solo POST y sin
     * tocar los secretos.
     *
     * Es el caso 3 de arriba, pero con el 422 pasando por el status y el cuerpo de una respuesta
     * HTTP de verdad: de ahí sale el código que lee clasificar_error().
     */
    public function test_el_422_literal_por_el_transporte_real_se_reusa_al_releer_la_lista(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $slug   = $datos['slug'];
        $nombre = 'u767360347_' . $slug;

        $secretos = $this->dejar_secretos_de_la_real($datos);

        $this->usar_el_transporte_real($slug);
        $this->escenario_http['lecturas_de_bases'] = [
            $this->pagina_cruda_de_bases([], 1, 0),
            $this->pagina_cruda_de_bases([$nombre], 1, 1),
        ];
        $this->escenario_http['post_base'] = [422, '{"message": "[Hosting:9999] Database name must be unique"}'];

        $datos['proveedor_esqueleto']->provision_db();

        $posts = $this->pedidos_http_a('POST', '/databases');
        $this->assertCount(1, $posts, 'Exactamente un intento de creación.');
        $this->assertCount(2, $this->pedidos_http_a('GET', '/databases'));

        $this->assertSame(
            [['tipo' => 'base', 'nombre' => $nombre]],
            $datos['proveedor_esqueleto']->result()->ya_existian()
        );
        $this->assertSame([], $datos['proveedor_esqueleto']->result()->creados());
        $this->assertCount(1, $this->lineas_que_contienen('ya figura en la cuenta', $this->lineas_del_esqueleto));

        $this->afirmar_secretos_sin_tocar($datos, $secretos, $posts[0]['body']['password']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10 — EL NOMBRE DE LA BASE SE COMPARA ENTERO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nombre exacto, no prefijo: la base del cliente `<slug>2` no es la del cliente `<slug>`.
     *
     * La cuenta de Hostinger tiene las bases de ~70 clientes y los slugs se parecen (`lacava` y
     * `lacava2` son dos clientes). Si la comparación fuera por prefijo —o por "contiene"—, crear la
     * base de `<slug>` encontraría la de `<slug>2`, se daría por existente, y el cliente quedaría sin
     * base propia y usando (o fallando por) la de otro. La lista trae SOLO bases vecinas por nombre:
     * `<slug>2` (la base la contiene) y un prefijo del slug (el slug la contiene). La de `<slug>` hay
     * que CREARLA, con su POST y sus secretos.
     */
    public function test_la_base_se_busca_por_nombre_exacto_y_no_por_prefijo(): void
    {
        $datos  = $this->preparar_par_de_instalaciones();
        $slug   = $datos['slug'];
        $nombre = 'u767360347_' . $slug;

        $this->hostinger->responder(
            '/databases',
            $this->respuesta_de_bases([$nombre . '2', 'u767360347_' . substr($slug, 0, 6)]),
            'GET'
        );
        $this->hostinger->responder('/databases', ['name' => $nombre], 'POST');

        $datos['proveedor']->provision_db();

        $posts = $this->posts_a('/databases');
        $this->assertCount(1, $posts, 'La base de este cliente no existía: hay que crearla.');
        $this->assertSame($nombre, $posts[0]['body']['name']);

        $this->assertSame([['tipo' => 'base', 'nombre' => $nombre]], $datos['proveedor']->result()->creados());
        $this->assertSame([], $datos['proveedor']->result()->ya_existian());

        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $secretos = $this->secretos_de($api);
            $this->assertSame($nombre, $secretos['db_name']);
            $this->assertSame($posts[0]['body']['password'], $secretos['db_password']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11 — CLASIFICACIÓN AFINADA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * La frase de la base es `database name must be unique`, no `name must be unique`.
     *
     * Otros mensajes de validación que dicen "name must be unique" no son "ya existe": el nombre de
     * un subdominio o el de un usuario de sitio de clpctl no son la base. La lista de patrones la
     * leen también VpsDatabaseProvisioner y VpsSiteProvisioner sobre errores de `clpctl`: darlos por
     * "ya existía" haría que esas etapas siguieran de largo con un recurso que no existe. El mensaje
     * medido en producción, en cambio, sí lo es.
     */
    public function test_solo_el_mensaje_de_la_base_se_clasifica_como_ya_existe(): void
    {
        $cliente = new HostingerApiClient();

        $no_son_ya_existe = [
            'Hostinger: nombre de subdominio' => new \RuntimeException(
                'La API de Hostinger respondió 422: {"message": "[Hosting:9999] Subdomain name must be unique"}',
                422
            ),
            'clpctl: usuario del sitio, con 422' => new \RuntimeException(
                'El comando remoto falló (exit 1): clpctl site:add:php --siteUser=lacava → Validation error: the site user name must be unique.',
                422
            ),
            'clpctl: usuario del sitio, con exit 1' => new \RuntimeException(
                'El comando remoto falló (exit 1): clpctl site:add:php --siteUser=lacava → Validation error: the site user name must be unique.',
                1
            ),
        ];

        foreach ($no_son_ya_existe as $descripcion => $excepcion) {
            $this->assertSame(
                HostingerApiClient::CLASIFICACION_DESCONOCIDA,
                $cliente->clasificar_error($excepcion),
                $descripcion
            );
            $this->assertFalse($cliente->es_error_de_ya_existe($excepcion), $descripcion);
        }

        $de_la_base = new \RuntimeException(
            'La API de Hostinger respondió 422: {"message": "[Hosting:9999] Database name must be unique"}',
            422
        );
        $this->assertSame(HostingerApiClient::CLASIFICACION_YA_EXISTE, $cliente->clasificar_error($de_la_base));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12 — LAS ETAPAS REALES DEL PIPELINE DEL ESQUELETO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El esqueleto recorre SUS etapas de aprovisionamiento, las del InstallationService, y llega al
     * write_env con las credenciales de la real.
     *
     * Es lo más cerca que se llega de "el esqueleto termina completada" sin fakear el SSH/SFTP de
     * InstallationService::run(), que ninguna suite del repo falsea. Se arma el servicio de la fila
     * esqueleto y se invoca por reflexión cada step_provision_* de su lista de etapas, en ese
     * orden, con la real ya provisionada y los 422 "already hosted" que dio Hostinger. Después, el
     * merge del .env que hace write_env. Lo que queda sin probar es lo que corre por SSH
     * (prepare_dirs, upload_public, finalize_skeleton) y que no depende de este arreglo.
     */
    public function test_las_etapas_de_aprovisionamiento_del_esqueleto_terminan_y_dejan_el_env_de_la_real(): void
    {
        $datos = $this->correr_la_real();
        $this->registrar_las_respuestas_del_esqueleto($datos);

        $nombre              = 'u767360347_' . $datos['slug'];
        $password_de_la_real = $this->posts_a('/databases')[0]['body']['password'];
        $servicio            = new InstallationService($datos['esqueleto']->fresh());

        /* Las etapas del esqueleto: aprovisiona, pero no crea cron ni pide certificado. */
        $etapas = $this->etapas_de($servicio);

        $this->assertSame(
            ['provision_check', 'provision_sites', 'provision_dns', 'provision_db',
             'prepare_dirs', 'upload_public', 'write_env', 'finalize_skeleton'],
            $etapas
        );
        $this->assertNotContains('provision_cron', $etapas);
        $this->assertNotContains('provision_ssl', $etapas);

        /* Cada etapa de aprovisionamiento, en el orden de la lista, y ninguna puede lanzar. */
        $corridas = [];

        foreach ($etapas as $etapa) {
            if (strpos($etapa, 'provision_') !== 0) {
                continue;
            }

            $excepcion = $this->excepcion_de(function () use ($servicio, $etapa) {
                $this->invocar($servicio, 'step_' . $etapa, []);
            });

            $this->assertNull(
                $excepcion,
                'La etapa ' . $etapa . ' del esqueleto lanzó: ' . ($excepcion === null ? '' : $excepcion->getMessage())
            );
            $corridas[] = $etapa;
        }

        $this->assertSame(['provision_check', 'provision_sites', 'provision_dns', 'provision_db'], $corridas);

        /* Ni un POST más a /databases que el de la real; los subdominios, todos como ya existentes. */
        $this->assertCount(1, $this->posts_a('/databases'));

        $resultado = $this->invocar($servicio, 'provisioner', [])->result();
        $this->assertSame([], $resultado->creados());
        $this->assertCount(5, $resultado->ya_existian());

        /* La línea que la etapa deja en el panel de la instalación del esqueleto. */
        $lineas_del_panel = DeploymentLog::where('client_installation_id', $datos['esqueleto']->id)
            ->where('step', 'provision_db')
            ->pluck('line')
            ->all();
        $this->assertContains($resultado->resumen(), $lineas_del_panel);
        $this->assertStringContainsString('0 recurso(s) creado(s)', $resultado->resumen());

        /* Y el .env que escribiría write_env: las credenciales de la real, a la letra. */
        $env = $this->invocar($servicio, 'merge_env_del_aprovisionamiento', [[]]);

        $this->assertSame(
            ['DB_DATABASE' => $nombre, 'DB_USERNAME' => $nombre, 'DB_PASSWORD' => $password_de_la_real],
            $env
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Corre la instalación REAL del par hasta dejar la base creada: preflight, subdominios, DNS y
     * base, con GET /databases vacío (y envuelto, como la API real) y el POST de la base respondiendo.
     *
     * Devuelve los datos del par más los secretos tal como los dejó la real.
     *
     * @return array<string, mixed>
     */
    private function correr_la_real(): array
    {
        $datos = $this->preparar_par_de_instalaciones();
        $slug  = $datos['slug'];

        $this->responder_zona_completa($slug);
        $this->hostinger->responder('/databases', $this->respuesta_de_bases([]), 'GET');
        $this->hostinger->responder('/databases', ['name' => 'u767360347_' . $slug], 'POST');

        $real = $datos['proveedor'];
        $real->provision_check();
        $real->provision_sites();
        $real->provision_dns();
        $real->provision_db();

        $datos['secretos_de_la_real'] = $this->secretos_de($datos['api1']);

        return $datos;
    }

    /**
     * Corre el ESQUELETO del par DESPUÉS de que la real terminó, con las respuestas que Hostinger dio
     * en producción: 422 "already hosted" en cada subdominio, 422 "must be unique" si se intentara
     * crear la base otra vez, y la base ya presente en la página 2 de 3 de la lista.
     *
     * Las reglas de falla se registran acá y no antes: la real tiene que haber terminado sin ellas.
     *
     * @param  array<string, mixed>  $datos
     * @return void
     */
    private function correr_el_esqueleto(array $datos): void
    {
        $this->registrar_las_respuestas_del_esqueleto($datos);

        $esqueleto = $datos['proveedor_esqueleto'];
        $esqueleto->provision_check();
        $esqueleto->provision_sites();
        $esqueleto->provision_dns();
        $esqueleto->provision_db();
    }

    /**
     * Registra en el fake lo que Hostinger contestó en producción al esqueleto: 422 "already hosted"
     * en cada subdominio, 422 "must be unique" si se intentara crear la base otra vez, y la base ya
     * presente en la página 2 de 3 de la lista.
     *
     * Aparte de correr_el_esqueleto() para el test que maneja las etapas por el InstallationService
     * en vez de llamar al proveedor directo.
     *
     * @param  array<string, mixed>  $datos
     * @return void
     */
    private function registrar_las_respuestas_del_esqueleto(array $datos): void
    {
        $nombre = 'u767360347_' . $datos['slug'];

        $this->hostinger->fallar_con('/subdomains', 422, '{"message": "[Hosting:9999] Subdomain is already hosted"}', 'POST');
        $this->hostinger->fallar_con('/databases', 422, '{"message": "[Hosting:9999] Database name must be unique"}', 'POST');
        $this->hostinger->responder_secuencia('/databases', $this->tres_paginas_de_bases($nombre), 'GET');
    }

    /**
     * Respuesta de GET /databases con la forma REAL de la API: envuelta en `data` y con `meta`.
     *
     * @param  array<int, string>  $nombres  Bases de ESTA página.
     * @param  int                 $pagina   Número de página que dice `meta.current_page`.
     * @param  int|null            $total    `meta.total`; por defecto, la cantidad de nombres.
     * @return array<string, mixed>
     */
    private function respuesta_de_bases(array $nombres, int $pagina = 1, ?int $total = null): array
    {
        $filas = [];

        foreach ($nombres as $nombre) {
            $filas[] = ['name' => $nombre];
        }

        return [
            'data' => $filas,
            'meta' => [
                'current_page' => $pagina,
                'per_page'     => 25,
                'total'        => $total === null ? count($nombres) : $total,
            ],
        ];
    }

    /**
     * Nombres de relleno para armar las bases de OTROS clientes de la cuenta.
     *
     * @param  int  $desde     Primer número.
     * @param  int  $cantidad
     * @return array<int, string>
     */
    private function nombres_de_relleno(int $desde, int $cantidad): array
    {
        $nombres = [];

        for ($numero = $desde; $numero < $desde + $cantidad; $numero++) {
            $nombres[] = 'u767360347_relleno' . sprintf('%02d', $numero);
        }

        return $nombres;
    }

    /**
     * Las tres páginas de la cuenta real (25 + 25 + 24 = 74 bases), de relleno salvo, si se pide, una
     * base puesta como la última de la página 2.
     *
     * @param  string|null  $en_la_pagina_2  Nombre de la base de este cliente.
     * @return array<int, array<string, mixed>>
     */
    private function tres_paginas_de_bases(?string $en_la_pagina_2 = null): array
    {
        $pagina_1 = $this->nombres_de_relleno(1, 25);
        $pagina_2 = $this->nombres_de_relleno(26, 25);
        $pagina_3 = $this->nombres_de_relleno(51, 24);

        if ($en_la_pagina_2 !== null) {
            $pagina_2[24] = $en_la_pagina_2;
        }

        return [
            $this->respuesta_de_bases($pagina_1, 1, 74),
            $this->respuesta_de_bases($pagina_2, 2, 74),
            $this->respuesta_de_bases($pagina_3, 3, 74),
        ];
    }

    /**
     * Deja en las dos ClientApi lo que habría dejado la instalación real al crear la base.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, string>  Los secretos guardados.
     */
    private function dejar_secretos_de_la_real(array $datos): array
    {
        $nombre   = 'u767360347_' . $datos['slug'];
        $secretos = [
            'db_name'                        => $nombre,
            'db_user'                        => $nombre,
            'db_password'                    => 'Clave-Real-De-La-Base-24',
            'provisioned_by_installation_id' => (string) $datos['installation']->id,
        ];

        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $api->provisioning_secrets   = $secretos;
            $api->hosting_provisioned_at = self::FECHA_DE_LA_REAL;
            $api->save();
        }

        return $secretos;
    }

    /**
     * Afirma que el reuso de la base no tocó NADA de lo que dejó la real: ni los secretos de ninguna
     * de las dos filas (incluido quién los dejó) ni la fecha de aprovisionamiento.
     *
     * Si el esqueleto hubiera llamado a persistir_secretos() con la contraseña de su intento,
     * `provisioned_by_installation_id` y `db_password` serían los suyos.
     *
     * @param  array<string, mixed>   $datos
     * @param  array<string, string>  $secretos  Los que dejó dejar_secretos_de_la_real().
     * @return void
     */
    private function afirmar_secretos_intactos(array $datos, array $secretos): void
    {
        $this->afirmar_secretos_sin_tocar($datos, $secretos, $this->posts_a('/databases')[0]['body']['password']);
    }

    /**
     * Lo mismo que afirmar_secretos_intactos(), con la contraseña del intento de creación dada: la
     * de los tests del transporte real sale del pedido que registró Http::fake(), no del fake propio.
     *
     * @param  array<string, mixed>   $datos
     * @param  array<string, string>  $secretos
     * @param  string                 $password_del_intento  La que se generó y se mandó en el POST.
     * @return void
     */
    private function afirmar_secretos_sin_tocar(array $datos, array $secretos, string $password_del_intento): void
    {
        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $this->assertSame($secretos, $this->secretos_de($api));
            $this->assertNotSame($password_del_intento, $this->secretos_de($api)['db_password']);
            $this->assertSame(
                self::FECHA_DE_LA_REAL,
                ClientApi::find($api->id)->hosting_provisioned_at->format('Y-m-d H:i:s')
            );
        }
    }

    /**
     * Secretos guardados en una ClientApi, releída de la base.
     *
     * @param  ClientApi  $api
     * @return array<string, string>|null
     */
    private function secretos_de(ClientApi $api)
    {
        return ClientApi::find($api->id)->provisioning_secrets;
    }

    /**
     * Cliente con sus dos ClientApi estándar, la instalación REAL y la del ESQUELETO (kind
     * `esqueleto`, sobre la segunda ClientApi), cada una con su proveedor de hosting compartido.
     *
     * @return array<string, mixed>
     */
    private function preparar_par_de_instalaciones(): array
    {
        $datos = $this->preparar_cliente_aprovisionable();

        $esqueleto = ClientInstallation::create([
            'client_id'              => $datos['client']->id,
            'client_api_id'          => $datos['api2']->id,
            'kind'                   => ClientInstallation::KIND_ESQUELETO,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
        ]);

        $lineas = &$this->lineas_del_esqueleto;

        $datos['esqueleto']           = $esqueleto;
        $datos['proveedor_esqueleto'] = HostingProvisioningService::para(
            $esqueleto,
            $datos['api2'],
            function ($step, $linea, $level) use (&$lineas) {
                $lineas[] = ['step' => $step, 'linea' => $linea, 'level' => $level];
            }
        );

        return $datos;
    }

    /**
     * Cliente con sus dos ClientApi estándar, una instalación real con aprovisionamiento tildado y
     * el proveedor de hosting compartido ya instanciado.
     *
     * @return array<string, mixed>
     */
    private function preparar_cliente_aprovisionable(): array
    {
        $slug = 'prov' . strtolower(Str::random(8));

        $this->crear_credencial_ssh();

        $client                  = new Client();
        $client->name            = 'Cliente ' . $slug;
        $client->slug            = $slug;
        $client->api_url         = 'https://api-' . $slug . '.comerciocity.com';
        $client->api_key         = Str::random(20);
        $client->inbound_api_key = Str::random(20);
        $client->save();

        $api1 = $this->crear_client_api($client->id, $slug);
        $api2 = $this->crear_client_api($client->id, $slug . '2');

        $installation = ClientInstallation::create([
            'client_id'              => $client->id,
            'client_api_id'          => $api1->id,
            'kind'                   => ClientInstallation::KIND_COMPLETA,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
        ]);

        $lineas    = &$this->lineas;
        $proveedor = HostingProvisioningService::para(
            $installation,
            $api1,
            function ($step, $linea, $level) use (&$lineas) {
                $lineas[] = ['step' => $step, 'linea' => $linea, 'level' => $level];
            }
        );

        return [
            'slug'         => $slug,
            'client'       => $client,
            'api1'         => $api1,
            'api2'         => $api2,
            'installation' => $installation,
            'proveedor'    => $proveedor,
        ];
    }

    /**
     * @param  int     $client_id
     * @param  string  $label
     * @return ClientApi
     */
    private function crear_client_api(int $client_id, string $label): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client_id;
        $api->url          = 'https://api-' . $label . '.comerciocity.com';
        $api->spa_url      = 'https://' . $label . '.comerciocity.com';
        $api->path         = $label . '/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * Credencial de hosting compartido: el preflight la exige y la base de testing no la tiene.
     *
     * @return void
     */
    private function crear_credencial_ssh(): void
    {
        if (ClientSshCredential::where('type', 'shared_hosting')->first() !== null) {
            return;
        }

        $credential           = new ClientSshCredential();
        $credential->type     = 'shared_hosting';
        $credential->host     = '127.0.0.1';
        $credential->port     = 22;
        $credential->username = 'test';
        $credential->password = 'test';
        $credential->save();
    }

    /**
     * Deja la zona DNS respondiendo con los 4 A records puestos, que es lo que Hostinger hace sola
     * al crear cada subdominio.
     *
     * @param  string  $slug
     * @return void
     */
    private function responder_zona_completa(string $slug): void
    {
        $this->hostinger->responder('/api/dns/v1/zones/', [
            ['name' => 'api-' . $slug, 'type' => 'A'],
            ['name' => $slug, 'type' => 'A'],
            ['name' => 'api-' . $slug . '2', 'type' => 'A'],
            ['name' => $slug . '2', 'type' => 'A'],
        ], 'GET');
    }

    /**
     * POSTs registrados contra una ruta.
     *
     * @param  string  $ruta_parcial
     * @return array<int, array<string, mixed>>
     */
    private function posts_a(string $ruta_parcial): array
    {
        return $this->llamadas_a('POST', $ruta_parcial);
    }

    /**
     * GETs registrados contra una ruta (cuenta también los `?page=N`).
     *
     * @param  string  $ruta_parcial
     * @return array<int, array<string, mixed>>
     */
    private function gets_a(string $ruta_parcial): array
    {
        return $this->llamadas_a('GET', $ruta_parcial);
    }

    /**
     * @param  string  $metodo
     * @param  string  $ruta_parcial
     * @return array<int, array<string, mixed>>
     */
    private function llamadas_a(string $metodo, string $ruta_parcial): array
    {
        $encontradas = [];

        foreach ($this->hostinger->llamadas_de($metodo) as $llamada) {
            if (strpos($llamada['ruta'], $ruta_parcial) !== false) {
                $encontradas[] = $llamada;
            }
        }

        return $encontradas;
    }

    /**
     * Líneas del panel que contienen un fragmento.
     *
     * @param  string                             $fragmento
     * @param  array<int, array<string, string>>  $lineas
     * @return array<int, array<string, string>>
     */
    private function lineas_que_contienen(string $fragmento, array $lineas): array
    {
        $encontradas = [];

        foreach ($lineas as $linea) {
            if (strpos($linea['linea'], $fragmento) !== false) {
                $encontradas[] = $linea;
            }
        }

        return $encontradas;
    }

    /**
     * Corre algo que TIENE que fallar y devuelve la excepción, o null si no falló.
     *
     * 🔴 Existe porque $this->fail() de PHPUnit tira una AssertionFailedError que hereda de
     * \RuntimeException: puesto adentro de un `try { ... } catch (\RuntimeException)`, el propio
     * catch se lo come y el test pasa igual aunque el código no haya fallado nunca. Con esto, un
     * método que no explota devuelve null y la aserción que sigue se pone en rojo sola.
     *
     * @param  \Closure  $accion
     * @return \Throwable|null
     */
    private function excepcion_de(\Closure $accion)
    {
        try {
            $accion();
        } catch (\Throwable $excepcion) {
            return $excepcion;
        }

        return null;
    }

    /**
     * Mensaje del error que TIENE que lanzar una acción, o '' si no lanzó nada.
     *
     * @param  \Closure  $accion
     * @return string
     */
    private function mensaje_de_error(\Closure $accion): string
    {
        $excepcion = $this->excepcion_de($accion);

        return $excepcion === null ? '' : $excepcion->getMessage();
    }

    /**
     * Pone a HostingerApiClient a hablar por el transporte REAL, con un Hostinger de mentira debajo.
     *
     * El cliente bindeado es el de verdad (no el fake que sobreescribe request()), y debajo de él
     * Http::fake() contesta con el JSON crudo que dicta $this->escenario_http. Por defecto: la lista
     * de bases vacía (una sola página), los POST de subdominio y de base en 200, la zona con los 4 A
     * records y la lista de cronjobs vacía.
     *
     * @param  string  $slug  Slug del cliente: define qué 4 nombres trae la zona DNS.
     * @return void
     */
    private function usar_el_transporte_real(string $slug = ''): void
    {
        config([
            'services.hostinger.base_url'   => self::BASE_URL_DE_PRUEBA,
            'services.hostinger.verify_ssl' => true,
            'services.hostinger.ca_bundle'  => null,
            'services.hostinger.timeout'    => 5,
        ]);

        $this->app->instance(HostingerApiClient::class, new HostingerApiClient());

        $this->escenario_http = [
            'slug'              => $slug,
            'lecturas_de_bases' => [$this->pagina_cruda_de_bases([], 1, 0)],
            'post_subdominio'   => [200, '{}'],
            'post_base'         => [200, '{}'],
        ];

        $this->empezar_a_registrar_pedidos_http();
    }

    /**
     * Cambia el cliente HTTP por una fábrica LIMPIA (sin pedidos registrados) y le enchufa el guion.
     *
     * 🔴 HttpFactorySinSalida y no `new Factory()`: Http::fake() ACUMULA stubs y gana el primero que
     * contesta, así que volver a falsear sobre la misma fábrica no reemplaza nada ni borra lo
     * registrado; y una fábrica pelada dejaría al test sin el freno a internet que instala TestCase.
     *
     * @return void
     */
    private function empezar_a_registrar_pedidos_http(): void
    {
        Http::swap(new HttpFactorySinSalida());

        Http::fake(function ($request) {
            return $this->responder_como_hostinger($request->method(), $request->url());
        });
    }

    /**
     * Qué contesta el Hostinger de mentira a un pedido, según $this->escenario_http.
     *
     * Devuelve null para una ruta que el guion no conoce: HttpFactorySinSalida lo frena y el test
     * falla diciendo cuál fue, en vez de recibir una respuesta inventada.
     *
     * @param  string  $metodo
     * @param  string  $url
     * @return mixed  Una respuesta de Http::response(), o null.
     */
    private function responder_como_hostinger(string $metodo, string $url)
    {
        if (strpos($url, '/cron-jobs') !== false) {
            return Http::response('[]', 200);
        }

        if (strpos($url, '/api/dns/v1/zones/') !== false) {
            $slug = $this->escenario_http['slug'];

            return Http::response(json_encode([
                ['name' => 'api-' . $slug, 'type' => 'A'],
                ['name' => $slug, 'type' => 'A'],
                ['name' => 'api-' . $slug . '2', 'type' => 'A'],
                ['name' => $slug . '2', 'type' => 'A'],
            ]), 200);
        }

        if (strpos($url, '/subdomains') !== false && $metodo === 'POST') {
            return Http::response($this->escenario_http['post_subdominio'][1], $this->escenario_http['post_subdominio'][0]);
        }

        if (strpos($url, '/databases') !== false && $metodo === 'POST') {
            return Http::response($this->escenario_http['post_base'][1], $this->escenario_http['post_base'][0]);
        }

        if (strpos($url, '/databases') !== false && $metodo === 'GET') {
            /* Se consume una lectura por pedido; la última queda pegada para las que sobren. */
            if (count($this->escenario_http['lecturas_de_bases']) > 1) {
                $cuerpo = array_shift($this->escenario_http['lecturas_de_bases']);
            } else {
                $cuerpo = $this->escenario_http['lecturas_de_bases'][0];
            }

            return Http::response($cuerpo, 200);
        }

        return null;
    }

    /**
     * Una fila de GET /databases con las claves REALES que devuelve la API (medidas el 13/10/2026).
     *
     * @param  string  $nombre
     * @return array<string, mixed>
     */
    private function fila_real_de_base(string $nombre): array
    {
        return [
            'name'          => $nombre,
            'user'          => $nombre,
            'domain'        => 'comerciocity.com',
            'permissions'   => 'ALL',
            'created_at'    => '2026-10-01T12:00:00.000000Z',
            'updated_at'    => '2026-10-01T12:00:00.000000Z',
            'disk_usage_mb' => 1,
            'max_size_mb'   => 1024,
            'host'          => 'localhost',
            'port'          => 3306,
        ];
    }

    /**
     * Una página de GET /databases como la manda Hostinger: el JSON CRUDO, con `data` y `meta`.
     *
     * @param  array<int, string>  $nombres  Bases de ESTA página.
     * @param  int                 $pagina
     * @param  int                 $total    `meta.total`.
     * @return string
     */
    private function pagina_cruda_de_bases(array $nombres, int $pagina, int $total): string
    {
        $filas = [];

        foreach ($nombres as $nombre) {
            $filas[] = $this->fila_real_de_base($nombre);
        }

        return json_encode([
            'data' => $filas,
            'meta' => ['current_page' => $pagina, 'per_page' => 25, 'total' => $total],
        ]);
    }

    /**
     * Las tres páginas de la cuenta real (25 + 25 + 24 = 74) en JSON crudo; si se pasa un nombre, va
     * como la última fila de la página pedida.
     *
     * @param  string|null  $nombre
     * @param  int          $en_la_pagina  1, 2 o 3.
     * @return array<int, string>
     */
    private function tres_paginas_crudas_de_bases(?string $nombre = null, int $en_la_pagina = 2): array
    {
        $paginas = [
            $this->nombres_de_relleno(1, 25),
            $this->nombres_de_relleno(26, 25),
            $this->nombres_de_relleno(51, 24),
        ];

        if ($nombre !== null) {
            $paginas[$en_la_pagina - 1][count($paginas[$en_la_pagina - 1]) - 1] = $nombre;
        }

        return [
            $this->pagina_cruda_de_bases($paginas[0], 1, 74),
            $this->pagina_cruda_de_bases($paginas[1], 2, 74),
            $this->pagina_cruda_de_bases($paginas[2], 3, 74),
        ];
    }

    /**
     * Los pedidos que Http::fake() registró desde el último empezar_a_registrar_pedidos_http().
     *
     * @return array<int, array<string, mixed>>  Cada uno: ['metodo', 'url', 'body'].
     */
    private function pedidos_http(): array
    {
        $pedidos = [];

        foreach (Http::recorded() as $par) {
            $pedidos[] = [
                'metodo' => $par[0]->method(),
                'url'    => $par[0]->url(),
                'body'   => $par[0]->data(),
            ];
        }

        return $pedidos;
    }

    /**
     * Pedidos HTTP registrados de un verbo contra una ruta (cuenta también los `?page=N`).
     *
     * @param  string  $metodo
     * @param  string  $ruta_parcial
     * @return array<int, array<string, mixed>>
     */
    private function pedidos_http_a(string $metodo, string $ruta_parcial): array
    {
        $encontrados = [];

        foreach ($this->pedidos_http() as $pedido) {
            if ($pedido['metodo'] === $metodo && strpos($pedido['url'], $ruta_parcial) !== false) {
                $encontrados[] = $pedido;
            }
        }

        return $encontrados;
    }

    /**
     * Lista de etapas que va a correr un InstallationService, leída por reflexión.
     *
     * @param  InstallationService  $servicio
     * @return array<int, string>
     */
    private function etapas_de(InstallationService $servicio): array
    {
        $propiedad = new \ReflectionProperty($servicio, 'steps');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($servicio);
    }

    /**
     * Invoca un método privado por reflexión.
     *
     * @param  object  $objeto
     * @param  string  $metodo
     * @param  array<int, mixed>  $argumentos
     * @return mixed
     */
    private function invocar($objeto, string $metodo, array $argumentos)
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }
}
