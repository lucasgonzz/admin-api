<?php

namespace App\Services;

/**
 * Arma el catálogo de `GET claude/catalog`: qué endpoints existen, para qué sirve cada uno y toda la
 * superficie de `GET claude/query`.
 *
 * 🔴 LO QUE SE DERIVA Y LO QUE SE DECLARA, Y POR QUÉ.
 *
 *   - Las RUTAS se derivan de `app('router')->getRoutes()`. Es la única fuente que no puede mentir:
 *     si la ruta está registrada, existe. Una lista de rutas escrita a mano en un config queda vieja
 *     el día que alguien agrega una y no se acuerda de anotarla, y el catálogo pasaría a ser
 *     exactamente lo contrario de lo que promete.
 *     ⚠️ El grupo de `RouteServiceProvider` mete el prefijo `api`, así que `$route->uri()` devuelve
 *     `api/claude/...` y no `claude/...`. El filtro y las claves usan la forma con `api/`.
 *
 *   - Los MODELOS de `/query` se derivan de `config('claude_query')`, que es el mismo archivo que
 *     sirve las consultas. No hay forma de que el catálogo publique una columna que el endpoint no
 *     devuelva, ni al revés.
 *
 *   - Las DESCRIPCIONES, la peligrosidad y los frenos se declaran en `config/claude_catalog.php`.
 *     Eso no se puede derivar: sacarlo del docblock por reflexión sería frágil de verdad, y sacarlo
 *     del nombre de la ruta sería adivinar.
 *
 * 🔴 Y LA COMPARACIÓN ENTRE LO DERIVADO Y LO DECLARADO VIVE ACÁ, EN UN SOLO LUGAR. `cotejar()` la
 * usan el controlador (para publicar `salud_del_catalogo`) y el test (para romper el build). Si el
 * test recorriera las rutas por su cuenta habría DOS definiciones de "las rutas de Claude" y se
 * desincronizarían, que es justo la clase de error que este catálogo viene a matar.
 *
 * Las dos rejas son distintas a propósito:
 *   1. La respuesta NUNCA rompe. Una ruta viva sin descripción se sirve igual, con `para_que: null`,
 *      y aparece listada en `salud_del_catalogo.sin_descripcion`. El catálogo denuncia su propio
 *      desactualizado en vez de dejar de contestar.
 *   2. El test SÍ rompe. Agregar una ruta `claude/*` sin describirla no llega a producción.
 */
class ClaudeCatalogService
{
    /**
     * Prefijo con el que el router registra las rutas de este bloque. Lleva `api/` porque el grupo
     * del RouteServiceProvider lo agrega.
     *
     * @var string
     */
    const PREFIJO = 'api/claude/';

    /**
     * Métodos HTTP que el router agrega solo y que no describen un endpoint distinto.
     *
     * @var array<int, string>
     */
    const METODOS_IMPLICITOS = ['HEAD', 'OPTIONS'];

    /**
     * Rutas `claude/*` REGISTRADAS hoy, derivadas del router.
     *
     * @return array<string, array<string, mixed>> Indexado por "MÉTODO api/claude/uri".
     */
    public function rutas_registradas()
    {
        $rutas = [];

        foreach (app('router')->getRoutes() as $ruta) {
            $uri = (string) $ruta->uri();

            if (strpos($uri, self::PREFIJO) !== 0) {
                continue;
            }

            foreach ($ruta->methods() as $metodo) {
                $metodo = strtoupper((string) $metodo);

                if (in_array($metodo, self::METODOS_IMPLICITOS, true)) {
                    continue;
                }

                $clave = $metodo . ' ' . $uri;

                $rutas[$clave] = [
                    'clave'  => $clave,
                    'metodo' => $metodo,
                    'ruta'   => substr($uri, strlen('api/')),
                    'uri'    => $uri,
                    'accion' => $this->accion_de($ruta),
                ];
            }
        }

        ksort($rutas);

        return $rutas;
    }

    /**
     * Descripciones DECLARADAS, indexadas por la misma clave que las registradas.
     *
     * @return array<string, array<string, mixed>>
     */
    public function declaraciones()
    {
        $declaradas = (array) config('claude_catalog.endpoints', []);

        ksort($declaradas);

        return $declaradas;
    }

    /**
     * Coteja lo derivado contra lo declarado. Es LA reja del catálogo, y la usan el controlador y el
     * test: una sola definición de "las rutas de Claude".
     *
     * @return array<string, mixed>
     */
    public function cotejar()
    {
        $registradas = $this->rutas_registradas();
        $declaradas  = $this->declaraciones();

        $sin_descripcion = [];
        foreach (array_keys($registradas) as $clave) {
            if (! isset($declaradas[$clave])) {
                $sin_descripcion[] = $clave;
            }
        }

        $fantasmas = [];
        foreach (array_keys($declaradas) as $clave) {
            if (! isset($registradas[$clave])) {
                $fantasmas[] = $clave;
            }
        }

        sort($sin_descripcion);
        sort($fantasmas);

        return [
            'rutas_registradas'            => count($registradas),
            'rutas_declaradas'             => count($declaradas),
            'sin_descripcion'              => $sin_descripcion,
            'declaradas_que_ya_no_existen' => $fantasmas,
            'nota'                         => '`sin_descripcion` son rutas claude/* vivas que nadie describió en config/claude_catalog.php: se sirven igual, con para_que en null. `declaradas_que_ya_no_existen` son entradas del config que apuntan a una ruta borrada o renombrada. Las dos tienen que estar vacías: hay un test que lo afirma.',
        ];
    }

    /**
     * Los endpoints con lo derivado y lo declarado ya unidos.
     *
     * 🔴 `parametros` se publica acá porque sin él este catálogo cumplía a medias su propia promesa
     * ("un request y sé todo lo que puedo pedir"). Para `GET claude/query` los parámetros se DERIVAN
     * del config de modelos; para las escrituras no se pueden derivar de ningún lado —las reglas
     * viven adentro de un `validate()`— así que se declaran en `config/claude_catalog.php`. Antes de
     * esto, `to_version_id` de `POST claude/upgrades/batch`, que es obligatorio, no aparecía en NINGUNA
     * parte del catálogo: la única forma de enterarse de los parámetros de un POST que arranca SSH
     * sobre el hosting de un negocio era mandar uno mal y leer el 422.
     *
     * @return array<int, array<string, mixed>>
     */
    public function endpoints()
    {
        $declaradas = $this->declaraciones();
        $salida     = [];

        foreach ($this->rutas_registradas() as $clave => $ruta) {
            $declarada = isset($declaradas[$clave]) ? (array) $declaradas[$clave] : null;

            $salida[] = [
                'metodo'       => $ruta['metodo'],
                'ruta'         => $ruta['ruta'],
                'accion'       => $ruta['accion'],
                'para_que'     => $declarada === null ? null : (isset($declarada['para_que']) ? $declarada['para_que'] : null),
                'escribe'      => $declarada === null ? null : (isset($declarada['escribe']) ? (bool) $declarada['escribe'] : null),
                'peligrosidad' => $declarada === null ? null : (isset($declarada['peligrosidad']) ? $declarada['peligrosidad'] : null),
                'frenos'       => $declarada === null ? [] : (array) (isset($declarada['frenos']) ? $declarada['frenos'] : []),
                /* 🔴 `null` y NO `[]` cuando no está declarado, y la diferencia importa: `[]` se lee
                   como "este endpoint no recibe nada", que sería mentira en las rutas de lectura
                   —todas tienen filtros— y catastrófico si algún día una escritura se quedara sin
                   declarar. `null` dice "no está escrito acá", que es la verdad. Las escrituras no
                   pueden quedar en null: hay un test que lo afirma. */
                'parametros'   => $declarada === null
                    ? null
                    : (isset($declarada['parametros']) ? (array) $declarada['parametros'] : null),
                'aviso'        => $declarada === null
                    ? 'Esta ruta está registrada pero NADIE la describió en config/claude_catalog.php. Aparece también en salud_del_catalogo.sin_descripcion.'
                    : null,
            ];
        }

        return $salida;
    }

    /**
     * La superficie de `GET claude/query`, derivada de `config/claude_query.php`.
     *
     * @return array<string, mixed>
     */
    public function query()
    {
        $modelos = [];

        foreach ((array) config('claude_query.modelos', []) as $clave => $definicion) {
            $filtros = [];
            foreach ((array) $definicion['filtros'] as $nombre => $filtro) {
                $filtros[$nombre] = [
                    'columna' => $filtro['columna'],
                    'tipo'    => $filtro['tipo'],
                    'valores' => isset($filtro['valores']) ? $filtro['valores'] : null,
                ];
            }

            $relaciones = [];
            foreach ((array) (isset($definicion['relaciones']) ? $definicion['relaciones'] : []) as $nombre => $relacion) {
                $relaciones[$nombre] = [
                    'tipo'     => $relacion['tipo'],
                    'tabla'    => $relacion['tabla'],
                    'columnas' => $relacion['columnas'],
                    'limite'   => isset($relacion['limite']) ? (int) $relacion['limite'] : null,
                ];
            }

            $modelos[$clave] = [
                'tabla'           => $definicion['tabla'],
                'descripcion'     => $definicion['descripcion'],
                'columnas'        => $definicion['columnas'],
                'columnas_opt_in' => (array) (isset($definicion['columnas_opt_in']) ? $definicion['columnas_opt_in'] : []),
                'busqueda'        => (array) (isset($definicion['busqueda']) ? $definicion['busqueda'] : []),
                'filtros'         => $filtros,
                'relaciones'      => $relaciones,
                'clave_de_cursor' => $definicion['clave_de_cursor'],
                'orden_default'   => $definicion['orden_default'],
                'limite_default'  => (int) $definicion['limite_default'],
                'limite_max'      => (int) $definicion['limite_max'],
                'nota'            => isset($definicion['nota']) ? $definicion['nota'] : null,
            ];
        }

        ksort($modelos);

        return [
            'endpoint'              => 'GET claude/query',
            'modelos'               => $modelos,
            'modelos_excluidos'     => (array) config('claude_query.modelos_excluidos', []),
            'nota_de_exclusiones'   => config('claude_query.nota_de_exclusiones'),
            'parametros_reservados' => ClaudeQueryService::PARAMETROS_RESERVADOS,
            'nota_de_escritura'     => '🔴 /query es SÓLO lectura. No existe POST claude/query ni ninguna escritura por nombre de modelo: un POST, PUT, PATCH o DELETE sobre esta ruta devuelve 405. Toda escritura va por un endpoint específico con sus frenos.',
            'nota_de_proyeccion'    => 'Las columnas son una lista blanca POSITIVA: se sirve `DB::table($tabla)->select($columnas)` con lo que dice el config, sin select * y sin modelo Eloquent serializado. `fields` sólo achica esa proyección, nunca la agranda, y las columnas opt-in necesitan su include.',
            'nota_de_orden'         => 'No hay order_by: la paginación es por cursor sobre `clave_de_cursor` y `order` sólo elige la dirección. Aceptar otro orden rompería el cursor en silencio.',
        ];
    }

    /**
     * Limitaciones declaradas del bloque.
     *
     * @return array<int, string>
     */
    public function limitaciones()
    {
        return (array) config('claude_catalog.limitaciones_conocidas', []);
    }

    /**
     * Nombre legible de la acción de una ruta.
     *
     * @param \Illuminate\Routing\Route $ruta Ruta registrada.
     *
     * @return string
     */
    private function accion_de($ruta)
    {
        $accion = (string) $ruta->getActionName();

        return str_replace('App\\Http\\Controllers\\', '', $accion);
    }
}
