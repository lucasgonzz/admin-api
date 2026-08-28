<?php

namespace Tests\Feature;

use App\Services\ClaudeCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El catálogo auto-descriptivo del bloque `claude/*` (`GET claude/catalog`).
 *
 * Este test es la segunda de las dos rejas del catálogo, y la que importa. La primera vive en la
 * propia respuesta (`salud_del_catalogo`) y avisa en caliente sin romper nada; ésta rompe el build.
 * Lo que se protege acá, en orden de importancia:
 *
 *  1. 🔴 Que agregar una ruta `claude/*` sin describirla NO llegue a producción. Un índice que
 *     miente es peor que no tener índice: el que lo lee deja de leer el código y se queda con lo que
 *     el índice dice.
 *  2. 🔴 Que el cotejo derivado-contra-declarado sea UNO SOLO. Este test llama al mismo
 *     `ClaudeCatalogService::cotejar()` que llama el controlador. Si recorriera las rutas por su
 *     cuenta habría dos definiciones de "las rutas de Claude" y se desincronizarían, que es
 *     exactamente la clase de error que este catálogo viene a matar.
 *  3. Que la respuesta NO se rompa cuando el catálogo está desactualizado: una ruta indescripta se
 *     sirve igual, con `para_que: null`, y aparece denunciada. Un catálogo que devuelve 500 el día
 *     que alguien agrega una ruta se desactiva en el primer apuro.
 *  4. Que toda ruta que escribe declare al menos un freno. Una escritura sin ningún freno declarado
 *     es o un error del config o un endpoint que hay que revisar; en los dos casos hay que mirarlo.
 */
class CatalogoDeEndpointsDeClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-catalogo';

    /** Peligrosidades que el config puede declarar. */
    const PELIGROSIDADES = ['lectura', 'baja', 'media', 'alta'];

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Cuerpo de la respuesta como texto, con los acentos SIN escapar.
     *
     * Mismo motivo que en ActualizacionDelEcommercePorClaudeTest: `getContent()` escapa los no-ASCII
     * y arruina cualquier comparación de texto con acentos.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a leer.
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta
     |----------------------------------------------------------------------------------------- */

    /**
     * El middleware es fail-closed: sin el header no contesta.
     *
     * @return void
     */
    public function test_sin_clave_el_catalogo_devuelve_401(): void
    {
        $this->getJson('/api/claude/catalog')->assertStatus(401);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Las dos rejas
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Toda ruta `claude/*` registrada tiene su entrada en config/claude_catalog.php.
     *
     * El día que alguien agregue una ruta sin describirla, este test le dice cuál. Se pregunta por
     * el MISMO método que usa el controlador para publicar `salud_del_catalogo`.
     *
     * @return void
     */
    public function test_toda_ruta_claude_registrada_esta_declarada_en_el_catalogo(): void
    {
        $cotejo = app(ClaudeCatalogService::class)->cotejar();

        $this->assertSame(
            [],
            $cotejo['sin_descripcion'],
            'Hay rutas claude/* vivas sin describir en config/claude_catalog.php: ' . implode(', ', $cotejo['sin_descripcion'])
        );

        $this->assertGreaterThan(0, $cotejo['rutas_registradas'], 'No se derivó ninguna ruta claude/*: el filtro del prefijo quedó mal.');

        /* Y la respuesta publica lo mismo que devolvió el servicio: una sola definición. */
        $respuesta = $this->getJson('/api/claude/catalog', $this->headers());
        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('salud_del_catalogo.sin_descripcion'));
        $this->assertSame($cotejo['rutas_registradas'], $respuesta->json('salud_del_catalogo.rutas_registradas'));
        $this->assertCount($cotejo['rutas_registradas'], $respuesta->json('endpoints'));
    }

    /**
     * 🔴 Ninguna entrada del config apunta a una ruta borrada o renombrada.
     *
     * Es la mitad que se olvida: describir una ruta que ya no existe no rompe nada en runtime, pero
     * deja el índice mintiendo hacia el otro lado.
     *
     * @return void
     */
    public function test_ninguna_entrada_del_catalogo_apunta_a_una_ruta_que_ya_no_existe(): void
    {
        $cotejo = app(ClaudeCatalogService::class)->cotejar();

        $this->assertSame(
            [],
            $cotejo['declaradas_que_ya_no_existen'],
            'Hay entradas en config/claude_catalog.php que apuntan a rutas inexistentes: ' . implode(', ', $cotejo['declaradas_que_ya_no_existen'])
        );
    }

    /**
     * 🔴 Una ruta nueva sin entrada se DENUNCIA y no rompe el request.
     *
     * Se registra una ruta de mentira en caliente y se verifica que la respuesta: (a) sigue
     * devolviendo 200, (b) la lista en `sin_descripcion`, y (c) la sirve igual con `para_que` en
     * null y un aviso. Ese es todo el diseño de la reja 1: denunciar sin dejar de contestar.
     *
     * @return void
     */
    public function test_una_ruta_nueva_sin_entrada_aparece_en_sin_descripcion(): void
    {
        Route::get('api/claude/inventada-por-el-test', function () {
            return response()->json([]);
        });

        $respuesta = $this->getJson('/api/claude/catalog', $this->headers());

        $respuesta->assertStatus(200);
        $this->assertContains('GET api/claude/inventada-por-el-test', $respuesta->json('salud_del_catalogo.sin_descripcion'));

        $indescripta = null;
        foreach ((array) $respuesta->json('endpoints') as $endpoint) {
            if ($endpoint['ruta'] === 'claude/inventada-por-el-test') {
                $indescripta = $endpoint;
            }
        }

        $this->assertNotNull($indescripta, 'La ruta indescripta ni siquiera aparece en la lista de endpoints.');
        $this->assertNull($indescripta['para_que']);
        $this->assertNotNull($indescripta['aviso']);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Lo derivado
     |----------------------------------------------------------------------------------------- */

    /**
     * Los modelos de `/query` salen del mismo config que sirve las consultas, no de una copia.
     *
     * @return void
     */
    public function test_el_catalogo_lista_los_modelos_de_query_derivados_del_config(): void
    {
        $respuesta = $this->getJson('/api/claude/catalog', $this->headers());
        $respuesta->assertStatus(200);

        $publicados = array_keys((array) $respuesta->json('query.modelos'));
        $del_config = array_keys((array) config('claude_query.modelos'));

        sort($publicados);
        sort($del_config);

        $this->assertSame($del_config, $publicados);

        /* Y publica la superficie de cada uno: columnas, filtros y relaciones. */
        $cliente = (array) $respuesta->json('query.modelos.client');
        $this->assertSame('clients', $cliente['tabla']);
        $this->assertContains('name', $cliente['columnas']);
        $this->assertNotContains('api_key', $cliente['columnas']);
        $this->assertArrayHasKey('is_active', $cliente['filtros']);
        $this->assertArrayHasKey('version_actual', $cliente['relaciones']);
        $this->assertSame(['phone'], $cliente['columnas_opt_in']['contacto']);

        /* 🔴 Y publica que no se puede escribir por ahí. */
        $this->assertStringContainsString('405', (string) $respuesta->json('query.nota_de_escritura'));
    }

    /**
     * Los modelos excluidos se publican CON el motivo.
     *
     * Un "no está" con motivo escrito vale mil veces más que un 422 pelado: el que consulta sabe si
     * tiene que buscar otro endpoint o si directamente no se puede.
     *
     * @return void
     */
    public function test_el_catalogo_publica_los_modelos_excluidos_con_su_motivo(): void
    {
        $respuesta = $this->getJson('/api/claude/catalog', $this->headers());
        $respuesta->assertStatus(200);

        $excluidos = (array) $respuesta->json('query.modelos_excluidos');

        $this->assertArrayHasKey('ClientSshCredential', $excluidos);
        $this->assertSame('secreto', $excluidos['ClientSshCredential']['motivo']);
        $this->assertStringContainsString('password', $excluidos['ClientSshCredential']['columna']);

        $this->assertArrayHasKey('DeploymentLog', $excluidos);
        $this->assertSame('volumen', $excluidos['DeploymentLog']['motivo']);

        /* Todo excluido por secreto nombra la columna concreta que lo justifica: un "por las dudas"
           no le sirve a nadie para decidir si revisar de nuevo. */
        foreach ($excluidos as $modelo => $detalle) {
            if (isset($detalle['motivo']) && $detalle['motivo'] === 'secreto') {
                $this->assertArrayHasKey('columna', $detalle, 'El modelo excluido "' . $modelo . '" dice "secreto" pero no nombra la columna.');
                $this->assertNotSame('', trim((string) $detalle['columna']));
            }
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Lo declarado
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Toda ruta de escritura declara al menos un freno, y toda entrada dice para qué sirve.
     *
     * @return void
     */
    public function test_toda_ruta_de_escritura_declara_al_menos_un_freno(): void
    {
        $declaradas = (array) config('claude_catalog.endpoints');

        $this->assertNotEmpty($declaradas);

        $escrituras = 0;

        foreach ($declaradas as $clave => $endpoint) {
            $this->assertArrayHasKey('para_que', $endpoint, 'La entrada "' . $clave . '" no dice para qué sirve.');
            $this->assertNotSame('', trim((string) $endpoint['para_que']), 'La entrada "' . $clave . '" tiene el para_que vacío.');

            $this->assertContains(
                $endpoint['peligrosidad'],
                self::PELIGROSIDADES,
                'La entrada "' . $clave . '" declara una peligrosidad desconocida.'
            );

            if (empty($endpoint['escribe'])) {
                $this->assertSame('lectura', $endpoint['peligrosidad'], 'La entrada "' . $clave . '" no escribe pero no está marcada como lectura.');
                continue;
            }

            $escrituras++;

            $this->assertNotEmpty(
                $endpoint['frenos'],
                'La ruta de escritura "' . $clave . '" no declara ni un freno. O falta escribirlo, o el endpoint hay que revisarlo.'
            );
        }

        $this->assertGreaterThan(0, $escrituras, 'No se encontró ninguna ruta de escritura declarada: el config quedó mal.');
    }

    /**
     * 🔴 Toda ruta de escritura declara sus parámetros, y cada uno dice si es obligatorio.
     *
     * La reja del freno (`>= 1 freno`) no cubría esto: un endpoint podía declarar cinco frenos y
     * cero parámetros, que es exactamente como estaba. Para `POST claude/upgrades/batch`,
     * `to_version_id` —obligatorio— no aparecía en ninguna parte del catálogo, así que la única
     * forma de enterarse de los parámetros de un POST que arranca SSH sobre el hosting de un negocio
     * era mandar uno mal y leer el 422.
     *
     * Se verifica también que salga POR LA RESPUESTA y no sólo del config: el que consulta lee el
     * JSON, no el archivo.
     *
     * @return void
     */
    public function test_toda_ruta_de_escritura_declara_sus_parametros(): void
    {
        $declaradas = (array) config('claude_catalog.endpoints');

        $this->assertNotEmpty($declaradas);

        $escrituras = 0;

        foreach ($declaradas as $clave => $endpoint) {
            if (empty($endpoint['escribe'])) {
                continue;
            }

            $escrituras++;

            $this->assertArrayHasKey(
                'parametros',
                $endpoint,
                'La ruta de escritura "' . $clave . '" no declara `parametros`. Sin eso, la única forma de saber qué '
                    . 'recibe es mandar una llamada mal y leer el 422.'
            );

            $this->assertNotEmpty(
                $endpoint['parametros'],
                'La ruta de escritura "' . $clave . '" declara `parametros` vacío. Toda escritura recibe algo: como '
                    . 'mínimo el {id} de la ruta.'
            );

            foreach ((array) $endpoint['parametros'] as $indice => $parametro) {
                $donde = 'El parámetro #' . $indice . ' de "' . $clave . '"';

                $this->assertArrayHasKey('nombre', $parametro, $donde . ' no tiene nombre.');
                $this->assertNotSame('', trim((string) $parametro['nombre']), $donde . ' tiene el nombre vacío.');

                $this->assertArrayHasKey('obligatorio', $parametro, $donde . ' no dice si es obligatorio.');
                $this->assertIsBool($parametro['obligatorio'], $donde . ' declara `obligatorio` con algo que no es booleano.');

                $this->assertArrayHasKey('que_es', $parametro, $donde . ' no dice qué es.');
                $this->assertNotSame('', trim((string) $parametro['que_es']), $donde . ' tiene el `que_es` vacío.');
            }
        }

        $this->assertGreaterThan(0, $escrituras, 'No se encontró ninguna ruta de escritura declarada: el config quedó mal.');

        /* Y que efectivamente salga por la respuesta, que es lo que se lee del otro lado. */
        $respuesta = $this->getJson('/api/claude/catalog?seccion=endpoints', $this->headers());
        $respuesta->assertStatus(200);

        $vistos = 0;
        foreach ((array) $respuesta->json('endpoints') as $endpoint) {
            if (empty($endpoint['escribe'])) {
                continue;
            }

            $vistos++;
            $this->assertNotEmpty(
                $endpoint['parametros'],
                'La respuesta del catálogo no publica los parámetros de "' . $endpoint['metodo'] . ' ' . $endpoint['ruta'] . '".'
            );
        }

        $this->assertSame($escrituras, $vistos, 'La cantidad de escrituras publicadas no coincide con la declarada.');

        /* El caso concreto que originó esto: el único obligatorio del lote de upgrades. */
        $cuerpo = $this->cuerpo($respuesta);
        $this->assertStringContainsString('to_version_id', $cuerpo);
    }

    /**
     * Las limitaciones que hoy sólo viven en docblocks se publican en la respuesta.
     *
     * Las tres que no se pueden perder: que el panel despacha el pipeline de ecommerce INLINE, que
     * nadie destraba una corrida de tienda colgada, y que el gate de horario usa el timezone global.
     *
     * @return void
     */
    public function test_el_catalogo_declara_las_limitaciones_conocidas(): void
    {
        $respuesta = $this->getJson('/api/claude/catalog', $this->headers());
        $respuesta->assertStatus(200);

        $limitaciones = implode(' | ', (array) $respuesta->json('limitaciones_conocidas'));

        $this->assertStringContainsString('INLINE', $limitaciones);
        $this->assertStringContainsString('95, 154 y 212', $limitaciones);
        $this->assertStringContainsString('instalando', $limitaciones);
        $this->assertStringContainsString('timezone', $this->cuerpo($respuesta));
    }

    /**
     * `seccion` devuelve sólo esa parte, pero la salud del catálogo viaja siempre.
     *
     * Si la única parte que denuncia el desactualizado se pudiera filtrar, dejaría de ser una reja.
     *
     * @return void
     */
    public function test_una_seccion_devuelve_solo_esa_parte_pero_siempre_la_salud(): void
    {
        $respuesta = $this->getJson('/api/claude/catalog?seccion=limitaciones', $this->headers());

        $respuesta->assertStatus(200);
        $this->assertArrayNotHasKey('endpoints', $respuesta->json());
        $this->assertArrayNotHasKey('query', $respuesta->json());
        $this->assertNotEmpty($respuesta->json('limitaciones_conocidas'));
        $this->assertSame([], $respuesta->json('salud_del_catalogo.sin_descripcion'));

        $this->getJson('/api/claude/catalog?seccion=inventada', $this->headers())->assertStatus(422);
    }
}
