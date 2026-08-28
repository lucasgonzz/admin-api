<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Services\ClaudeCatalogService;
use Illuminate\Http\Request;

/**
 * `GET claude/catalog` — el índice auto-descriptivo de TODO lo que Claude puede pedirle al admin.
 *
 * Existe porque hasta hoy había que leer `routes/api.php` y ocho controladores para saber qué se
 * puede pedir, cuál de esas rutas escribe y qué freno tiene cada una. Los dos schemas que ya
 * existían (`claude/schema` para leads y `claude/ops-schema` para clientes) describen su propio
 * sub-bloque y nada más; esto es el índice de todos.
 *
 * 🔴 LAS RUTAS SE DERIVAN, NO SE ESCRIBEN. Salen de `app('router')->getRoutes()` filtrando por el
 * prefijo, que es la única fuente que no puede mentir. Lo mismo con los modelos de `/query`, que
 * salen del mismo `config/claude_query.php` que sirve las consultas. Lo único declarado es lo que no
 * se puede derivar: para qué sirve cada endpoint, su peligrosidad y sus frenos.
 *
 * 🔴 Y EL CATÁLOGO DENUNCIA SU PROPIO DESACTUALIZADO. `salud_del_catalogo` publica las rutas vivas
 * que nadie describió (`sin_descripcion`) y las entradas del config que apuntan a una ruta que ya no
 * existe (`declaradas_que_ya_no_existen`). Eso NUNCA rompe el request: una ruta indescripta se sirve
 * igual con `para_que: null`. Lo que sí rompe es el test, que afirma que las dos listas están
 * vacías. Dos rejas distintas a propósito: una avisa en caliente, la otra no deja que llegue.
 *
 * ⚠️ El cotejo derivado-contra-declarado vive entero en `ClaudeCatalogService::cotejar()`, y lo
 * llaman este controlador y el test. Si el test recorriera las rutas por su cuenta serían dos
 * definiciones de "las rutas de Claude" y se desincronizarían.
 */
class ClaudeCatalogController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Servicio que deriva las rutas y arma el catálogo.
     *
     * @var ClaudeCatalogService
     */
    private $catalogo;

    /**
     * @param ClaudeCatalogService $catalogo Servicio del catálogo.
     */
    public function __construct(ClaudeCatalogService $catalogo)
    {
        $this->catalogo = $catalogo;
    }

    /**
     * GET /api/claude/catalog
     *
     * Sin parámetros salvo `seccion` (endpoints | query | limitaciones), que sirve para pedir una
     * sola parte cuando el catálogo entero es más de lo que hace falta.
     *
     * `salud_del_catalogo` viaja SIEMPRE, se pida la sección que se pida: si la única parte que
     * denuncia el desactualizado se pudiera filtrar, dejaría de ser una reja.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'seccion' => 'nullable|string|in:endpoints,query,limitaciones',
        ]);

        if ($error !== null) {
            return $error;
        }

        $seccion = $this->texto_o_null($request->input('seccion'));

        $respuesta = [
            'generado_en' => now()->toIso8601String(),
            'auth'        => (array) config('claude_catalog.auth', []),
            'rate_limit'  => (array) config('claude_catalog.rate_limit', []),
        ];

        if ($seccion === null || $seccion === 'endpoints') {
            $respuesta['endpoints'] = $this->catalogo->endpoints();
        }

        if ($seccion === null || $seccion === 'query') {
            $respuesta['query'] = $this->catalogo->query();
        }

        if ($seccion === null || $seccion === 'limitaciones') {
            $respuesta['limitaciones_conocidas'] = $this->catalogo->limitaciones();
        }

        $respuesta['salud_del_catalogo'] = $this->catalogo->cotejar();

        return response()->json($respuesta);
    }
}
