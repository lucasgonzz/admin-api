<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEcommerce;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los paths de instalación de la tienda cargados A MANO en el modal del cliente
 * (PUT /api/admin/client/{id} → ClientController::sync_ecommerce_urls_from_request()).
 *
 * Contexto: hasta la misión ecommerce-paths-subcarpeta, la carpeta física del hosting donde vive
 * una tienda se derivaba siempre del dominio de la URL pública ({dominio}/public_html). Eso no
 * alcanza cuando la tienda está instalada en una subcarpeta de OTRO dominio (por ejemplo
 * comerciocity.store/public_html/tienda/spa) y se sirve por un subdominio propio.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que los 40 clientes que ya existen no cambien de comportamiento: sin paths cargados, la
 *     ruta se sigue derivando del dominio y se sigue recalculando al cambiar la URL (tests 1 y 4).
 *  2. Que un path cargado a mano sobreviva a los guardados posteriores, incluso cuando cambia el
 *     dominio (tests 2, 3 y 6). La columna guarda el path efectivo, así que "columna con valor"
 *     no significa "cargado a mano": la diferencia la hace la comparación contra la derivación.
 *  3. Que lo que se pega en el campo se normalice antes de guardarse (test 5): ese path termina
 *     siendo el destino de un `rm -rf` en el swap atómico del deploy.
 */
class PathsDeInstalacionDeLaTiendaCargadosAManoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin logueado por Sanctum: la ruta del cliente vive bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de paths de tienda';
        $admin->email    = 'paths-tienda-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente mínimo al que colgarle la tienda.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente de paths de tienda';
        $client->slug            = 'cliente-paths-tienda-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * URL del guardado del cliente (ruta verificada en routes/api.php:223).
     *
     * @param Client $client Cliente.
     *
     * @return string
     */
    private function url(Client $client): string
    {
        return '/api/admin/client/' . $client->id;
    }

    /**
     * Tienda del cliente releída de la base (nunca la instancia que quedó en memoria).
     *
     * @param Client $client Cliente.
     *
     * @return ClientEcommerce
     */
    private function tienda(Client $client): ClientEcommerce
    {
        $ecommerce = ClientEcommerce::where('client_id', $client->id)->first();
        $this->assertNotNull($ecommerce, 'El guardado tendría que haber creado el ClientEcommerce.');

        return $ecommerce;
    }

    /**
     * 1) 🔴 EL TEST DE LOS 40 CLIENTES QUE YA EXISTEN: sin paths cargados, la ruta se deriva del
     *    dominio exactamente como antes de esta misión, y los accessors del modal viajan vacíos
     *    (el campo tiene que verse vacío, no pre-cargado con una ruta que nadie escribió).
     */
    public function test_sin_paths_cargados_la_ruta_se_deriva_del_dominio_como_siempre()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            'ecommerce_spa_path' => '',
            'ecommerce_api_path' => '',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('tienda.cliente.com.ar', $ecommerce->domain);
        $this->assertSame('tienda.cliente.com.ar/public_html', $ecommerce->spa_path);
        $this->assertSame('tienda.cliente.com.ar/public_html/api', $ecommerce->api_path);

        // La columna guarda el path efectivo, pero el accessor dice "esto no lo cargó nadie".
        $this->assertSame('', $respuesta->json('model.ecommerce_spa_path'));
        $this->assertSame('', $respuesta->json('model.ecommerce_api_path'));
    }

    /** 2) Un path cargado a mano se guarda tal cual y vuelve en los accessors del modal. */
    public function test_los_paths_cargados_a_mano_sobreviven_al_guardado()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.comerciocity.store',
            'ecommerce_api_url'  => 'https://api-tienda.comerciocity.store',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda/spa',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/api',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('tienda.comerciocity.store', $ecommerce->domain);
        $this->assertSame('comerciocity.store/public_html/tienda/spa', $ecommerce->spa_path);
        $this->assertSame('comerciocity.store/public_html/tienda/api', $ecommerce->api_path);

        $this->assertSame(
            'comerciocity.store/public_html/tienda/spa',
            $respuesta->json('model.ecommerce_spa_path')
        );
        $this->assertSame(
            'comerciocity.store/public_html/tienda/api',
            $respuesta->json('model.ecommerce_api_path')
        );
    }

    /**
     * 3) Cambiar la URL pública de la tienda NO mueve una instalación cargada a mano: el path
     *    describe una carpeta física, no tiene por qué tener relación con el host público.
     */
    public function test_al_cambiar_el_dominio_los_paths_cargados_a_mano_no_se_recalculan()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.comerciocity.store',
            'ecommerce_api_url'  => 'https://api-tienda.comerciocity.store',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda/spa',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/api',
        ])->assertStatus(200);

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda9.comerciocity.store',
            'ecommerce_api_url'  => 'https://api-tienda9.comerciocity.store',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda/spa',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/api',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('tienda9.comerciocity.store', $ecommerce->domain, 'El dominio sí se re-deriva de la URL.');
        $this->assertSame('comerciocity.store/public_html/tienda/spa', $ecommerce->spa_path);
        $this->assertSame('comerciocity.store/public_html/tienda/api', $ecommerce->api_path);
    }

    /**
     * 4) 🔴 GUARDA DE NO-REGRESIÓN de la intención original del bloque: un path DERIVADO sí se
     *    recalcula al cambiar el dominio. Si este test se pone en rojo, el bug es el orden de las
     *    operaciones en sync_ecommerce_urls_from_request(): manual_spa_path() tiene que compararse
     *    contra la derivación del dominio VIEJO, o sea antes de pisar `domain`.
     */
    public function test_al_cambiar_el_dominio_los_paths_derivados_si_se_recalculan()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            'ecommerce_spa_path' => '',
            'ecommerce_api_path' => '',
        ])->assertStatus(200);

        $this->assertSame('tienda.cliente.com.ar/public_html', $this->tienda($client)->spa_path);

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://otra.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-otra.cliente.com.ar',
            'ecommerce_spa_path' => '',
            'ecommerce_api_path' => '',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('otra.cliente.com.ar', $ecommerce->domain);
        $this->assertSame('otra.cliente.com.ar/public_html', $ecommerce->spa_path);
        $this->assertSame('otra.cliente.com.ar/public_html/api', $ecommerce->api_path);
        $this->assertSame('', $respuesta->json('model.ecommerce_spa_path'));
        $this->assertSame('', $respuesta->json('model.ecommerce_api_path'));
    }

    /**
     * 5) Normalización de lo que realmente se pega en ese campo: la ruta con `domains/` de más,
     *    la ruta absoluta copiada de una sesión SSH, barras dobles, barras invertidas de Windows
     *    y "./" intercalado. Y el caso peligroso: un ".." tira la entrada ENTERA y se vuelve a la
     *    derivación automática, que siempre es una ruta segura.
     */
    public function test_normaliza_entradas_raras_de_los_paths()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $casos = [
            // [ lo que se pega en el campo, lo que tiene que quedar en la columna ]
            [
                '  /domains/comerciocity.store/public_html/tienda/spa/  ',
                'comerciocity.store/public_html/tienda/spa',
            ],
            [
                '/home/u123456/domains/comerciocity.store/public_html/tienda/spa',
                'comerciocity.store/public_html/tienda/spa',
            ],
            [
                'comerciocity.store//public_html///tienda/spa',
                'comerciocity.store/public_html/tienda/spa',
            ],
            [
                'comerciocity.store\\public_html\\tienda\\spa',
                'comerciocity.store/public_html/tienda/spa',
            ],
            [
                './comerciocity.store/./public_html/tienda/spa',
                'comerciocity.store/public_html/tienda/spa',
            ],
            // Un ".." apuntaría fuera de domains/ y el pipeline hace `rm -rf` sobre este path:
            // se descarta la entrada entera y manda la derivación del dominio.
            [
                'comerciocity.store/public_html/../../etc',
                'tienda.cliente.com.ar/public_html',
            ],
        ];

        foreach ($casos as $caso) {
            $respuesta = $this->putJson($this->url($client), [
                'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
                'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
                'ecommerce_spa_path' => $caso[0],
                'ecommerce_api_path' => '',
            ]);

            $respuesta->assertStatus(200);

            $this->assertSame(
                $caso[1],
                $this->tienda($client)->spa_path,
                'No normalizó bien la entrada: ' . $caso[0]
            );
        }
    }

    /**
     * 6) Un guardado que no manda las claves de path (cualquier flujo que no sea el modal nuevo)
     *    no puede pisar un path cargado a mano: clave ausente = este flujo no los administra.
     */
    public function test_un_guardado_que_no_manda_las_claves_de_path_no_pisa_un_path_cargado_a_mano()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.comerciocity.store',
            'ecommerce_api_url'  => 'https://api-tienda.comerciocity.store',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda/spa',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/api',
        ])->assertStatus(200);

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url' => 'https://tienda.comerciocity.store',
            'ecommerce_api_url' => 'https://api-tienda.comerciocity.store',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('comerciocity.store/public_html/tienda/spa', $ecommerce->spa_path);
        $this->assertSame('comerciocity.store/public_html/tienda/api', $ecommerce->api_path);
    }

    /** 7) Vaciar el campo en el modal = volver a la derivación automática. */
    public function test_vaciar_el_campo_del_path_vuelve_a_la_derivacion()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda/spa',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/api',
        ])->assertStatus(200);

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            'ecommerce_spa_path' => '',
            'ecommerce_api_path' => '',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('tienda.cliente.com.ar/public_html', $ecommerce->spa_path);
        $this->assertSame('tienda.cliente.com.ar/public_html/api', $ecommerce->api_path);
        $this->assertSame('', $respuesta->json('model.ecommerce_spa_path'));
        $this->assertSame('', $respuesta->json('model.ecommerce_api_path'));
    }

    /**
     * 8) 🔴 GUARDA DE FORMA: una entrada DEMASIADO CORTA para ser un path de instalación se
     *    descarta entera y se cae a la derivación automática, que siempre es una ruta segura.
     *
     *    LAS TRES ENTRADAS SON DE COPY/PASTE REAL, y las tres eran catastróficas antes de esta
     *    guarda: con `comerciocity.store` guardado como spa_path, get_spa_docroot() daba
     *    `domains/comerciocity.store` y el swap atómico (`mv "$DOCROOT" "$OLD"` + `rm -rf "$OLD"`)
     *    borraba el public_html ENTERO de ese dominio, con todas las otras tiendas que colgaran de
     *    ahí. El warning de ensure_hosting_spa_directory() no lo cubría: el padre (`domains`)
     *    existe siempre, así que el deploy no tenía nada que denunciar.
     */
    public function test_una_entrada_demasiado_corta_cae_a_la_derivacion()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $entradas = [
            // El dominio a secas, olvidándose la cola.
            'comerciocity.store',
            // La ruta absoluta copiada de una sesión SSH: normaliza al caso de arriba.
            '/home/u123456/domains/comerciocity.store',
            // Lo que muestra el File Manager de hPanel parado ADENTRO del dominio: sin el dominio
            // adelante quedaría como `domains/public_html/tienda/spa`, una carpeta inventada al
            // lado de los dominios reales que ningún vhost sirve.
            'public_html/tienda/spa',
        ];

        foreach ($entradas as $entrada) {
            $respuesta = $this->putJson($this->url($client), [
                'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
                'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
                'ecommerce_spa_path' => $entrada,
                'ecommerce_api_path' => $entrada,
            ]);

            $respuesta->assertStatus(200);

            $ecommerce = $this->tienda($client);
            $this->assertSame(
                'tienda.cliente.com.ar/public_html',
                $ecommerce->spa_path,
                'Esta entrada tendría que haberse descartado entera: ' . $entrada
            );
            $this->assertSame('tienda.cliente.com.ar/public_html/api', $ecommerce->api_path);
            $this->assertSame('', $respuesta->json('model.ecommerce_spa_path'));
            $this->assertSame('', $respuesta->json('model.ecommerce_api_path'));
        }
    }

    /**
     * 9) 🔴 EL TEST DE NO-REGRESIÓN DE LOS ~40 CLIENTES: la guarda de forma del test 8 NO puede
     *    rechazar `{dominio}/public_html` ni `{dominio}/public_html/api`, que son exactamente los
     *    paths derivados y legítimos que hoy tienen guardados todos los clientes en producción.
     *    Si este test se pone en rojo, la guarda quedó demasiado estricta y se rompen todas las
     *    tiendas ya instaladas.
     */
    public function test_los_paths_de_los_clientes_existentes_siguen_siendo_aceptados()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        // Se cargan a mano paths con la FORMA de siempre (dos y tres segmentos), pero de un
        // dominio distinto al de la URL, para que cuenten como manuales y se pueda ver que se
        // guardaron tal cual en vez de rechazarse.
        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            'ecommerce_spa_path' => 'cliente.com.ar/public_html',
            'ecommerce_api_path' => 'cliente.com.ar/public_html/api',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('cliente.com.ar/public_html', $ecommerce->spa_path);
        $this->assertSame('cliente.com.ar/public_html/api', $ecommerce->api_path);
        $this->assertSame('cliente.com.ar/public_html', $respuesta->json('model.ecommerce_spa_path'));
        $this->assertSame('cliente.com.ar/public_html/api', $respuesta->json('model.ecommerce_api_path'));
    }

    /**
     * 10) Un cliente nuevo al que se le cargan SOLO los paths, con las dos URLs vacías, no pierde
     *     lo cargado.
     *
     *     EL DEFECTO QUE PROTEGE (encontrado en el chequeo independiente de la misión): el
     *     `return` temprano de sync_ecommerce_urls_from_request() para "las dos URLs vacías" corría
     *     ANTES de los bloques que aplican los paths, así que se descartaban en silencio, sin
     *     ningún error. Y el hint del modal invitaba explícitamente a hacer eso.
     */
    public function test_con_las_dos_urls_vacias_un_path_cargado_igual_se_guarda()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => '',
            'ecommerce_api_url'  => '',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda/spa',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/api',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('comerciocity.store/public_html/tienda/spa', $ecommerce->spa_path);
        $this->assertSame('comerciocity.store/public_html/tienda/api', $ecommerce->api_path);

        // Las URLs vacías se guardan en null, igual que hacía el early return.
        $this->assertNull($ecommerce->spa_url);
        $this->assertNull($ecommerce->api_url);
        $this->assertSame('pending', $ecommerce->status);

        $this->assertSame(
            'comerciocity.store/public_html/tienda/spa',
            $respuesta->json('model.ecommerce_spa_path')
        );
    }

    /**
     * 11) Y el camino de al lado sigue igual que siempre: las dos URLs vacías SIN paths no crean
     *     ninguna tienda. Es la guarda de que la corrección del test 10 no convirtió cualquier
     *     guardado de cliente en un alta de tienda.
     */
    public function test_con_las_dos_urls_vacias_y_sin_paths_no_se_crea_ninguna_tienda()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => '',
            'ecommerce_api_url'  => '',
            'ecommerce_spa_path' => '',
            'ecommerce_api_path' => '',
        ])->assertStatus(200);

        $this->assertNull(
            ClientEcommerce::where('client_id', $client->id)->first(),
            'Un guardado sin URLs ni paths no tiene por qué crear una tienda.'
        );
    }

    /**
     * 12) Espacios invisibles pegados desde una web o un chat (espacio duro U+00A0, BOM U+FEFF).
     *
     *     EL DEFECTO QUE PROTEGE: el `.trim()` de JavaScript del modal los recorta y el `trim()`
     *     de PHP no, aunque los dos docblocks prometen que las implementaciones son equivalentes.
     *     El hint mostraba la ruta limpia, la columna guardaba la ruta con el carácter invisible
     *     pegado, y el deploy terminaba creando en el hosting una carpeta con un carácter
     *     invisible al final que ningún (sub)dominio servía.
     */
    public function test_los_espacios_invisibles_se_recortan_igual_que_en_el_modal()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            // Espacio duro (U+00A0) al final.
            'ecommerce_spa_path' => "comerciocity.store/public_html/tienda/spa\xC2\xA0",
            // BOM (U+FEFF) adelante.
            'ecommerce_api_path' => "\xEF\xBB\xBFcomerciocity.store/public_html/tienda/api",
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('comerciocity.store/public_html/tienda/spa', $ecommerce->spa_path);
        $this->assertSame('comerciocity.store/public_html/tienda/api', $ecommerce->api_path);
    }

    /**
     * 13) Un array en la clave del path no puede terminar guardado como el string "Array".
     *
     *     `(string) []` devuelve literalmente "Array" (con warning), y ese "Array" quedaba en la
     *     columna que después es destino de un `rm -rf`. Un valor que no es escalar se descarta y
     *     se cae a la derivación.
     */
    public function test_un_array_en_la_clave_del_path_cae_a_la_derivacion()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.cliente.com.ar',
            'ecommerce_api_url'  => 'https://api-tienda.cliente.com.ar',
            'ecommerce_spa_path' => ['comerciocity.store', 'public_html'],
            'ecommerce_api_path' => ['clave' => 'valor'],
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('tienda.cliente.com.ar/public_html', $ecommerce->spa_path);
        $this->assertSame('tienda.cliente.com.ar/public_html/api', $ecommerce->api_path);
        $this->assertNotSame('Array', $ecommerce->spa_path);
    }

    /**
     * 14) Un path con caracteres de shell se guarda normalizado (la normalización no es un
     *     validador de nombres de carpeta), pero el script del deploy no lo interpola crudo.
     *
     *     La mitad de shell la cubre tests/Unit/GuardasDelPathDeInstalacionDeLaTiendaTest.php,
     *     que llama a build_spa_atomic_deploy_shell() por reflexión. Acá se deja constancia de
     *     que el valor efectivamente llega hasta la columna, que es de dónde lo saca el script.
     */
    public function test_un_path_con_caracteres_de_shell_llega_a_la_columna_tal_cual()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'ecommerce_spa_url'  => 'https://tienda.comerciocity.store',
            'ecommerce_api_url'  => 'https://api-tienda.comerciocity.store',
            'ecommerce_spa_path' => 'comerciocity.store/public_html/tienda',
            'ecommerce_api_path' => 'comerciocity.store/public_html/tienda/a";id;"b',
        ]);

        $respuesta->assertStatus(200);

        $ecommerce = $this->tienda($client);
        $this->assertSame('comerciocity.store/public_html/tienda/a";id;"b', $ecommerce->api_path);

        // Y el subpath que va a parar al script del deploy es el tramo peligroso.
        $this->assertSame('a";id;"b', $ecommerce->api_subpath_inside_spa_docroot());
    }
}
