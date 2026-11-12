<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Services\ClaudeAdminSqlService;
use App\Services\ClaudeSupportAdminLecturaService;
use Illuminate\Http\Request;

/**
 * Lectura del admin para soporte (misión cruzada `acceso-soporte-al-admin-lectura`, 11/11/2026).
 *
 * Lucas pidió que soporte —Martín, el agente del monitor, y cualquier sesión con la clave de
 * ingesta— pueda consultar la información del admin que necesite, y en particular saber en qué
 * versión está un cliente (del sistema y de su tienda) para decir si tiene o no una funcionalidad.
 *
 * Son tres rutas, las tres de SOLO LECTURA:
 *
 *  - `GET  claude/support-codex/client-versions` — versión del sistema y de la tienda de cada cliente.
 *  - `GET  claude/support-codex/feature-lookup`  — en qué versión salió una funcionalidad y si un
 *    cliente la tiene.
 *  - `POST claude/support-codex/admin-sql`       — SQL de solo lectura sobre la base del admin.
 *
 * 🔴 `admin-sql` ES POST Y NO ESCRIBE. Va por POST porque la consulta viaja en el cuerpo (hasta
 * 12000 caracteres, con comillas y saltos de línea que no tienen por qué pasar por una URL ni
 * quedar en el access log). Las barreras están en `ClaudeAdminSqlValidator` y
 * `ClaudeAdminSqlService`; acá no se decide nada sobre la consulta.
 *
 * El contrato con el monitor (`herramientas/soporte-codex/` del repo de conocimiento) está en
 * `docs/soporte-codex-contrato.md`: los nombres de campo de las respuestas no se cambian.
 */
class ClaudeSupportAdminLecturaController extends Controller
{
    use RespuestasParaClaude;

    /** @var ClaudeSupportAdminLecturaService */
    private $lectura;

    /** @var ClaudeAdminSqlService */
    private $sql;

    /**
     * @param ClaudeSupportAdminLecturaService $lectura Versiones de clientes y búsqueda de funcionalidades.
     * @param ClaudeAdminSqlService            $sql     SQL de solo lectura.
     */
    public function __construct(ClaudeSupportAdminLecturaService $lectura, ClaudeAdminSqlService $sql)
    {
        $this->lectura = $lectura;
        $this->sql     = $sql;
    }

    /**
     * Versión del sistema y de la tienda de cada cliente, paginado por cursor.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function client_versions(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id' => 'nullable|integer|min:1',
            'q'         => 'nullable|string|max:100',
            'limit'     => 'nullable|integer',
            'after_id'  => 'nullable|integer|min:0',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client_id = $this->entero_o_null($request->input('client_id'));

        if ($client_id !== null && ! $this->lectura->existe_el_cliente($client_id)) {
            return $this->error_404('No existe un cliente con id ' . $client_id . '.');
        }

        $pagina = $this->lectura->versiones_de_clientes(
            $client_id,
            $this->texto_o_null($request->input('q')),
            $this->resolver_limite($request->input('limit'), 50, 200),
            $this->entero_o_null($request->input('after_id'))
        );

        return response()->json([
            'data'                     => $pagina['data'],
            'ultima_version_sistema'   => $pagina['ultima_version_sistema'],
            'ultima_version_ecommerce' => $pagina['ultima_version_ecommerce'],
            'has_more'                 => $pagina['has_more'],
            'next_after_id'            => $pagina['next_after_id'],
            'server_time'              => now()->toIso8601String(),
        ], 200);
    }

    /**
     * En qué versión salió una funcionalidad y, si se indica un cliente, si la tiene.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function feature_lookup(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'q'         => 'required|string|min:2|max:100',
            'client_id' => 'nullable|integer|min:1',
            'sistema'   => 'nullable|string|in:empresa,tienda',
            'limit'     => 'nullable|integer',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client_id = $this->entero_o_null($request->input('client_id'));
        $cliente   = null;

        if ($client_id !== null) {
            $cliente = $this->lectura->versiones_de_un_cliente($client_id);

            if ($cliente === null) {
                return $this->error_404('No existe un cliente con id ' . $client_id . '.');
            }
        }

        $q        = trim((string) $request->input('q'));
        $busqueda = $this->lectura->buscar_funcionalidad(
            $q,
            $this->texto_o_null($request->input('sistema')),
            $this->resolver_limite($request->input('limit'), 20, 50),
            $cliente
        );

        return response()->json([
            'q'          => $q,
            'resultados' => $busqueda['resultados'],
            'hay_mas'    => $busqueda['hay_mas'],
            'cliente'    => $cliente,
            'aviso'      => ClaudeSupportAdminLecturaService::AVISO,
        ], 200);
    }

    /**
     * SQL de solo lectura sobre la base del admin.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function admin_sql(Request $request)
    {
        $sql = $request->input('sql');

        if (! is_string($sql)) {
            return $this->error_422('El parámetro sql es obligatorio y tiene que ser texto.', ['codigo' => 'consulta_invalida']);
        }

        $resultado = $this->sql->consultar($sql);

        if (! $resultado['ok']) {
            $extra = ['codigo' => $resultado['codigo']];

            foreach (['sqlstate', 'codigo_mysql'] as $clave) {
                if (array_key_exists($clave, $resultado)) {
                    $extra[$clave] = $resultado[$clave];
                }
            }

            return $this->error_422($resultado['error'], $extra);
        }

        /* JSON_INVALID_UTF8_SUBSTITUTE: una columna binaria o con bytes rotos no puede convertir
           toda la respuesta en un 500 sin cuerpo. */
        return response()->json([
            'ok'        => true,
            'read_only' => true,
            'columns'   => $resultado['columns'],
            'rows'      => $resultado['rows'],
            'row_count' => $resultado['row_count'],
            'truncated' => $resultado['truncated'],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
