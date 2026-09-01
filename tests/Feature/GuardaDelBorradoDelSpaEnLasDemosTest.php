<?php

namespace Tests\Feature;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoUpdate;
use App\Models\Version;
use App\Services\DemoPathResolver;
use App\Services\DemoUpdateService;
use App\Services\GuardaDeBorradoDeSpa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La guarda del borrado recursivo, en el camino de las DEMOS.
 *
 * El comando de despliegue del SPA hace `cd "$SPA_DIR"` y después `find . -mindepth 1 -delete`.
 * Vive en cuatro armadores: los dos de clientes (InstallationService y DeploymentService), que
 * llevan la guarda desde el 31/8/2026, y los dos de demos (DemoInstallationService y
 * DemoUpdateService), que hasta ahora no la tenían.
 *
 * 🔴 DemoPathResolver ya traía dos guardas propias y buenas —`assert_slug()` y
 * `assert_no_es_el_sitio_de_la_api()`—, pero las dos validan el INSUMO adentro del resolver. Esta es
 * de otra naturaleza: valida el string que efectivamente se va a vaciar, en el armador del comando,
 * que es el último lugar donde todavía se puede frenar. Sirve el día en que el resolver devuelva
 * mal, que es justamente para lo que existe.
 *
 * Lo que fijan estos tests es que ese día el comando NO se arma.
 */
class GuardaDelBorradoDelSpaEnLasDemosTest extends TestCase
{
    use DatabaseTransactions;

    // ─────────────────────────────────────────────────────────────────────────
    // La guarda sola, sin pipeline de por medio
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El caso del incidente: directorio vacío.
     *
     * @return void
     */
    public function test_frena_con_el_directorio_vacio()
    {
        $mensaje = $this->mensaje_de_error(function () {
            GuardaDeBorradoDeSpa::assert('', 'demo3', 'la demo 1', 'Revisá la demo.');
        });

        $this->assertStringContainsString('FRENADO ANTES DE BORRAR', $mensaje);
        $this->assertStringContainsString('find . -mindepth 1 -delete', $mensaje);
    }

    /**
     * Las raíces donde conviven todos los sitios de un servidor.
     *
     * @return void
     */
    public function test_frena_con_las_raices_compartidas()
    {
        $raices = [
            'domains',
            'domains/comerciocity.com',
            'domains/comerciocity.com/public_html',
            'domains/comerciocity.com/public_html/',
            '/home',
        ];

        foreach ($raices as $raiz) {
            $mensaje = $this->mensaje_de_error(function () use ($raiz) {
                GuardaDeBorradoDeSpa::assert($raiz, 'demo3', 'la demo 1', 'Revisá la demo.');
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La guarda dejó pasar la raíz compartida "' . $raiz . '".'
            );
        }
    }

    /**
     * Sin identificador no hay forma de decir que el directorio es el correcto.
     *
     * @return void
     */
    public function test_frena_si_no_hay_identificador()
    {
        $mensaje = $this->mensaje_de_error(function () {
            GuardaDeBorradoDeSpa::assert(
                'domains/comerciocity.com/public_html/demo3/spa',
                '',
                'la demo 1',
                'Revisá la demo.'
            );
        });

        $this->assertStringContainsString('FRENADO ANTES DE BORRAR', $mensaje);
        $this->assertStringContainsString('identificador', $mensaje);
    }

    /**
     * El identificador tiene que aparecer adentro de la ruta: si el resolver devolvió el directorio
     * de OTRO, se frena.
     *
     * @return void
     */
    public function test_frena_si_el_directorio_es_el_de_otro()
    {
        $mensaje = $this->mensaje_de_error(function () {
            GuardaDeBorradoDeSpa::assert(
                'domains/comerciocity.com/public_html/ferretotal/spa',
                'demo3',
                'la demo 1',
                'Revisá la demo.'
            );
        });

        $this->assertStringContainsString('FRENADO ANTES DE BORRAR', $mensaje);
        $this->assertStringContainsString('demo3', $mensaje);
    }

    /**
     * 🔴 EL AGUJERO QUE MÁS IMPORTA: el directorio del VECINO.
     *
     * El chequeo del identificador era un `strpos` (substring) y por eso, con identificador "demo",
     * la ruta `.../public_html/demo2/spa` pasaba: contiene "demo". O sea que la guarda que existe
     * para que el pipeline de una demo no vacíe el SPA de otra no atajaba justamente ese caso.
     *
     * Y los pares <slug> / <slug>2 no son una hipótesis: son la convención del sistema. Cada cliente
     * tiene dos instancias y las demos van demo, demo2, demo3. En producción están galvan y galvan2,
     * ferretotal y ferretotal2, arfren y arfren2, trama y trama2.
     *
     * Ahora el identificador tiene que ser un segmento entero de la ruta.
     *
     * @return void
     */
    public function test_frena_con_el_directorio_del_vecino_cuyo_slug_empieza_igual()
    {
        $casos = [
            ['domains/comerciocity.com/public_html/demo2/spa', 'demo'],
            ['/home/demo2/htdocs/demo2.comerciocity.com', 'demo'],
            ['domains/comerciocity.com/public_html/galvan2/spa', 'galvan'],
            ['domains/comerciocity.com/public_html/ferretotal2/spa', 'ferretotal'],
        ];

        foreach ($casos as $caso) {
            $mensaje = $this->mensaje_de_error(function () use ($caso) {
                GuardaDeBorradoDeSpa::assert($caso[0], $caso[1], 'la demo 1', 'Revisá la demo.');
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La guarda dejó pasar "' . $caso[0] . '" con el identificador "' . $caso[1]
                    . '": es el SPA del vecino y lo habría vaciado.'
            );
        }
    }

    /**
     * 🔴 La regla nueva, y la que la guarda vieja dejaba pasar.
     *
     * En el VPS el SPA vive en /home/<slug>/htdocs/<dominio>. Si el dominio saliera vacío, la ruta
     * queda en /home/demo3/htdocs — que CONTIENE "demo3", así que el chequeo del identificador la
     * dejaría pasar, y vaciar eso se lleva todos los sitios de ese usuario.
     *
     * @return void
     */
    public function test_frena_con_el_home_del_usuario_y_su_htdocs_aunque_contengan_el_identificador()
    {
        foreach (['/home/demo3', '/home/demo3/htdocs', '/home/demo3/htdocs/'] as $dir) {
            $mensaje = $this->mensaje_de_error(function () use ($dir) {
                GuardaDeBorradoDeSpa::assert($dir, 'demo3', 'la demo 1', 'Revisá la demo.');
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La guarda dejó pasar "' . $dir . '", que contiene el identificador pero no es el SPA.'
            );
        }
    }

    /**
     * El camino sano no se toca: las dos rutas reales de una demo pasan sin decir nada.
     *
     * @return void
     */
    public function test_las_rutas_reales_de_una_demo_pasan()
    {
        GuardaDeBorradoDeSpa::assert(
            'domains/comerciocity.com/public_html/demo3/spa',
            'demo3',
            'la demo 1',
            'Revisá la demo.'
        );

        GuardaDeBorradoDeSpa::assert(
            '/home/demo3/htdocs/demo3.comerciocity.com',
            'demo3',
            'la demo 1',
            'Revisá la demo.'
        );

        /* Sin excepción: llegar acá es la prueba. */
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El resolver de demos, que es quien la llama
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El identificador de una demo sale del slug en compartido y del vps_slug en VPS.
     *
     * @return void
     */
    public function test_el_resolver_acepta_las_rutas_que_el_mismo_calcula()
    {
        $resolver = new DemoPathResolver();

        $shared = new Demo(['erp_spa_url' => 'demo3.comerciocity.com']);
        $resolver->assert_directorio_de_spa_borrable($shared, $resolver->spa_path($shared));

        $vps = new Demo([
            'erp_spa_url'      => 'demo3.comerciocity.com',
            'erp_hosting_type' => 'vps',
        ]);
        $resolver->assert_directorio_de_spa_borrable($vps, $resolver->spa_path($vps));

        $this->assertTrue(true);
    }

    /**
     * 🔴 Y frena cuando le pasan el directorio de otro, que es el caso para el que existe.
     *
     * @return void
     */
    public function test_el_resolver_frena_si_le_pasan_el_directorio_de_otra_demo()
    {
        $resolver = new DemoPathResolver();
        $demo     = new Demo(['erp_spa_url' => 'demo3.comerciocity.com']);

        $mensaje = $this->mensaje_de_error(function () use ($resolver, $demo) {
            $resolver->assert_directorio_de_spa_borrable(
                $demo,
                'domains/comerciocity.com/public_html/demo2/spa'
            );
        });

        $this->assertStringContainsString('FRENADO ANTES DE BORRAR', $mensaje);
        $this->assertStringContainsString('ERP SPA URL', $mensaje);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El armador del comando de actualización
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 EL TEST QUE JUSTIFICA LA MISIÓN. Con un directorio equivocado, el comando NO se arma.
     *
     * Se invoca el armador directamente, que es lo único que se puede hacer sin un servidor del
     * otro lado. Que tire antes de devolver el string es la prueba: el string es lo único que
     * después se ejecuta, así que si no se arma, no hay forma de que un `find -delete` salga por SSH.
     *
     * @return void
     */
    public function test_el_armador_de_la_actualizacion_no_arma_el_comando_con_un_directorio_de_otro()
    {
        $service = new DemoUpdateService($this->crear_demo_update('demo3'));

        $mensaje = $this->mensaje_de_error(function () use ($service) {
            $this->invocar(
                $service,
                'build_spa_hosting_deploy_shell',
                ['domains/comerciocity.com/public_html/otra-demo/spa']
            );
        });

        $this->assertStringContainsString('FRENADO ANTES DE BORRAR', $mensaje);
    }

    /**
     * El mismo armador, con la ruta correcta, sigue devolviendo el comando de siempre — y ahora con
     * el `SPA_DIR` escapado en POSIX.
     *
     * 🔴 El esperado lleva comillas SIMPLES literales, no `escapeshellarg()`. Ese era el bug: esa
     * función escapa según el sistema donde corre PHP, y en el WAMP de esta máquina emite comillas
     * DOBLES, adentro de las cuales el `sh` remoto expande `$` y backticks. Escribir el esperado con
     * `escapeshellarg()` haría que el test compare el código contra sí mismo y pase igual.
     *
     * @return void
     */
    public function test_con_la_ruta_correcta_el_comando_sale_y_el_spa_dir_va_en_comillas_simples()
    {
        $update  = $this->crear_demo_update('demo3');
        $service = new DemoUpdateService($update);
        $dir     = 'domains/comerciocity.com/public_html/demo3/spa';

        $shell = (string) $this->invocar($service, 'build_spa_hosting_deploy_shell', [$dir]);

        $this->assertStringContainsString('find . -mindepth 1 -delete', $shell);
        $this->assertStringContainsString("SPA_DIR='" . $dir . "'", $shell);
        $this->assertStringNotContainsString('"' . $dir . '"', $shell);
    }

    /**
     * 🔴 LOS SEGMENTOS `.` Y `..`, que atravesaban la guarda entera.
     *
     * Y no hacía falta ningún bug de código para llegar: `parse_url('https://..', PHP_URL_HOST)`
     * devuelve el string `'..'`, no vacío ni false. Una demo en VPS con la «ERP SPA URL» cargada
     * como `https://..` —campo de texto libre que DemoController no valida— resolvía a
     * `/home/<slug>/htdocs/..`, que tiene al slug como segmento, no es ninguna raíz y no es
     * literalmente el home ni el htdocs. Pasaba. Y `cd '/home/demo3/htdocs/..'` deja al shell
     * parado en `/home/demo3`, así que el `find` vaciaba el home del usuario con todos sus sitios.
     *
     * @return void
     */
    public function test_frena_con_segmentos_que_mueven_el_directorio_real()
    {
        $casos = [
            '/home/demo3/htdocs/..',
            '/home/demo3/htdocs/.',
            '/home/demo3/htdocs/../..',
            '/home/demo3//htdocs',
            'domains/comerciocity.com/public_html/demo3/../demo2/spa',
        ];

        foreach ($casos as $dir) {
            $mensaje = $this->mensaje_de_error(function () use ($dir) {
                GuardaDeBorradoDeSpa::assert($dir, 'demo3', 'la demo 1', 'Revisá la demo.');
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La guarda dejó pasar "' . $dir . '": el directorio que el servidor termina vaciando '
                    . 'no es el que dice la ruta.'
            );
        }
    }

    /**
     * 🔴 El SPA vive ADENTRO del directorio del identificador, nunca es el directorio mismo.
     *
     * Sin esta regla pasaban la carpeta madre (que contiene `api/` Y `spa/`) y el propio `api/`.
     * Vaciar cualquiera de los dos se lleva puesto el sistema.
     *
     * @return void
     */
    public function test_frena_con_la_carpeta_madre_y_con_el_directorio_de_la_api()
    {
        $casos = [
            'domains/comerciocity.com/public_html/demo3',
            'domains/comerciocity.com/public_html/demo3/api',
            '/home/demo3/htdocs/demo3.comerciocity.com/api',
        ];

        foreach ($casos as $dir) {
            $mensaje = $this->mensaje_de_error(function () use ($dir) {
                GuardaDeBorradoDeSpa::assert($dir, 'demo3', 'la demo 1', 'Revisá la demo.');
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La guarda dejó pasar "' . $dir . '", que no es el directorio del SPA.'
            );
        }
    }

    /**
     * 🔴 La raíz de la cuenta compartida se DERIVA de las constantes de los dos resolvers.
     *
     * Si alguien cambia el dominio de la cuenta en cualquiera de las dos y la guarda tuviera la
     * ruta como literal, la lista quedaría vieja en silencio y la raíz pasaría a ser borrable. Este
     * test lee las constantes en vez de repetir el string, así que se mueve con ellas.
     *
     * @return void
     */
    public function test_la_raiz_de_la_cuenta_compartida_sale_de_las_constantes_de_los_resolvers()
    {
        $prefijos = [
            \App\Services\ClientApiPathResolver::PREFIJO_SHARED,
            DemoPathResolver::SHARED_HOSTING_PREFIX,
        ];

        foreach ($prefijos as $prefijo) {
            $mensaje = $this->mensaje_de_error(function () use ($prefijo) {
                GuardaDeBorradoDeSpa::assert(rtrim($prefijo, '/'), 'demo3', 'la demo 1', 'Revisá.');
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La raíz "' . $prefijo . '" dejó de estar prohibida: la lista de la guarda y la '
                    . 'constante del resolver se desincronizaron.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El armador del comando de INSTALACIÓN de una demo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 EL OTRO ARMADOR, que es la mitad del trabajo de esta misión.
     *
     * `DemoInstallationService::build_spa_hosting_deploy_shell()` tiene el mismo `find -delete` que
     * el de actualización. Sin este test, alguien podía borrar la llamada a la guarda de ese método
     * y la suite seguía verde: el agujero se reabría sin que nada lo denunciara.
     *
     * Se comprueba por reflexión sobre el fuente y no instanciando el servicio, porque su
     * constructor pide una DemoInstallation con relaciones cargadas y credenciales SSH, y lo que
     * importa fijar acá es exactamente una cosa: que la guarda se llame antes del `find`.
     *
     * @return void
     */
    public function test_el_armador_de_la_instalacion_de_demos_llama_a_la_guarda_antes_del_borrado()
    {
        $metodo = $this->cuerpo_del_armador('DemoInstallationService');

        $posicion_guarda = strpos($metodo, 'assert_directorio_de_spa_borrable');
        $posicion_find   = strpos($metodo, 'find . -mindepth 1 -delete');

        $this->assertNotFalse(
            $posicion_guarda,
            'DemoInstallationService dejó de llamar a la guarda: su `find . -mindepth 1 -delete` '
                . 'volvió a quedar sin la última línea antes del borrado.'
        );
        $this->assertNotFalse($posicion_find, 'Cambió el comando de despliegue: revisá este test.');
        $this->assertLessThan(
            $posicion_find,
            $posicion_guarda,
            'La guarda quedó DESPUÉS del find dentro del método: tiene que correr antes de armar el '
                . 'comando, no después.'
        );
    }

    /**
     * Y el mismo candado para el armador de la actualización, del lado del fuente.
     *
     * El test de arriba lo prueba ejecutándolo, que es más fuerte; este agrega la garantía de que la
     * llamada esté ANTES del `find` en el archivo, que es lo único que el otro no puede ver.
     *
     * @return void
     */
    public function test_el_armador_de_la_actualizacion_de_demos_tambien_llama_a_la_guarda_antes()
    {
        $metodo = $this->cuerpo_del_armador('DemoUpdateService');

        $posicion_guarda = strpos($metodo, 'assert_directorio_de_spa_borrable');
        $posicion_find   = strpos($metodo, 'find . -mindepth 1 -delete');

        $this->assertNotFalse($posicion_guarda, 'DemoUpdateService dejó de llamar a la guarda.');
        $this->assertNotFalse($posicion_find, 'Cambió el comando de despliegue: revisá este test.');
        $this->assertLessThan($posicion_find, $posicion_guarda);
    }

    /**
     * El cuerpo del método que arma el comando de despliegue del SPA, sin el resto del archivo.
     *
     * ⚠️ Acotarlo al método no es cosmético: los dos servicios NOMBRAN el `find . -mindepth 1
     * -delete` en comentarios que están mucho antes, explicando por qué el resolver valida lo que
     * valida. Buscando en el archivo entero, la primera coincidencia es uno de esos comentarios y la
     * comparación de posiciones no significa nada.
     *
     * @param  string  $clase
     * @return string
     */
    private function cuerpo_del_armador(string $clase)
    {
        $fuente = (string) file_get_contents(app_path('Services/' . $clase . '.php'));

        $desde = strpos($fuente, 'private function build_spa_hosting_deploy_shell');
        $this->assertNotFalse($desde, 'Se renombró build_spa_hosting_deploy_shell en ' . $clase . '.');

        $hasta = strpos($fuente, 'echo SPA_DEPLOY_OK', $desde);
        $this->assertNotFalse($hasta, 'Cambió el final del comando en ' . $clase . '.');

        return substr($fuente, $desde, $hasta - $desde);
    }

    /**
     * Ningún armador de comando de demos puede volver a `escapeshellarg()`.
     *
     * ⚠️ Este candado existe porque el test que compara el `SPA_DIR` con comillas simples solo
     * detecta la regresión corriendo en Windows: en Linux `escapeshellarg()` también emite comillas
     * simples, así que el código con el bug pintaría verde. Esto es independiente de la plataforma.
     *
     * @return void
     */
    public function test_el_armador_del_borrado_no_usa_escapeshellarg()
    {
        $metodo = $this->cuerpo_del_armador('DemoUpdateService');

        $this->assertStringNotContainsString(
            'escapeshellarg(',
            $metodo,
            'El armador del comando de borrado volvió a escapeshellarg(), que en Windows emite '
                . 'comillas dobles y deja que el sh remoto expanda $ y backticks.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Andamiaje
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea una demo con su DemoUpdate, que es lo que el servicio recibe por constructor.
     *
     * @param  string  $slug
     * @return DemoUpdate
     */
    private function crear_demo_update(string $slug)
    {
        /* El constructor de DemoUpdateService resuelve la credencial SSH con firstOrFail(), así que
           tiene que existir antes de instanciarlo. Nada de este test la usa: no se abre una sola
           sesión. */
        $credencial = ClientSshCredential::where('type', 'shared_hosting')->first();
        if ($credencial === null) {
            $credencial           = new ClientSshCredential();
            $credencial->type     = 'shared_hosting';
            $credencial->host     = '198.51.100.10';
            $credencial->port     = 65002;
            $credencial->username = 'usuario-de-prueba';
            $credencial->password = 'secreto';
            $credencial->save();
        }

        /* Los cuatro campos de ecommerce van aunque el test no los use: la tabla los tiene NOT NULL
           sin default, así que omitirlos rompe el INSERT y no lo que se quiere probar. */
        $demo = Demo::create([
            'erp_spa_url'            => $slug . '.comerciocity.com',
            'erp_api_url'            => 'https://api-' . $slug . '.comerciocity.com',
            'erp_hosting_type'       => 'shared_hosting',
            'ecommerce_spa_url'      => 'https://tienda-' . $slug . '.comerciocity.store',
            'ecommerce_api_url'      => 'https://api-tienda-' . $slug . '.comerciocity.store',
            'ecommerce_hosting_type' => 'shared_hosting',
        ]);

        /* El número se aleatoriza porque `versions.version` es unique y el test corre sobre la base
           del slot, que ya tiene versiones cargadas. */
        $version = Version::create([
            'version' => '97.' . random_int(100, 999) . '.' . random_int(100, 999),
            'title'   => 'Version de prueba de la guarda del borrado',
        ]);

        return DemoUpdate::create([
            'demo_id'    => $demo->id,
            'version_id' => $version->id,
            'status'     => 'pendiente',
        ]);
    }

    /**
     * Invoca un método privado del servicio.
     *
     * Es la única forma de fijar el comando sin un servidor del otro lado, y es el mismo criterio
     * con el que ya se prueban los armadores de comando de los otros tres pipelines.
     *
     * @param  object            $objeto
     * @param  string            $metodo
     * @param  array<int, mixed> $argumentos
     * @return mixed
     */
    private function invocar($objeto, string $metodo, array $argumentos = [])
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * Corre un closure y devuelve el mensaje de la excepción, o '' si no lanzó ninguna.
     *
     * @param  \Closure  $accion
     * @return string
     */
    private function mensaje_de_error(\Closure $accion)
    {
        try {
            $accion();
        } catch (\Throwable $excepcion) {
            return $excepcion->getMessage();
        }

        return '';
    }
}
