<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Services\ClaudeQueryService;
use Illuminate\Http\Request;

/**
 * `GET claude/query` — lectura genérica de las tablas del admin por lista blanca.
 *
 * Existe para que sumar una tabla a lo que Claude puede consultar sean ~10 líneas en
 * `config/claude_query.php` y NINGÚN código. Los endpoints específicos (`claude/clients`,
 * `claude/upgrades`, `claude/leads`) siguen siendo el camino cuando hay algo que calcular —horarios
 * resueltos, salud del deployment, embudo—; esto es para lo que sólo hace falta leer columnas.
 *
 * 🔴 ESTE CONTROLADOR NO TIENE NINGÚN MÉTODO QUE NO SEA `index_json()`, Y LA RUTA SE REGISTRA SÓLO
 * CON `Route::get`. Un `POST`, `PUT`, `PATCH` o `DELETE` sobre `claude/query` devuelve 405 de
 * Laravel, y hay un test que lo afirma para los cuatro verbos. No es una precaución de estilo: una
 * escritura genérica "por nombre de modelo" saltearía todos los frenos que este repo tiene escritos
 * de a uno —`ClaudeUpgradeOpsController` son más de 1300 líneas que casi todas son eso: el
 * `confirm_client_name`, el `dry_run` por defecto, `allow_deploy_to_active_api`, el gate de horario
 * y los dos umbrales del vencimiento— y arrancaría deploys SSH sobre negocios reales. Escribir se
 * escribe con un endpoint propio y sus frenos, o no se escribe.
 *
 * 🔴 Y LO QUE SE DEVUELVE ES UNA LISTA BLANCA POSITIVA, no una lista negra de lo que se esconde.
 * `ClaudeQueryService` arma `DB::table($tabla)->select($columnas)` con las columnas del config: no
 * hay `select *` ni modelo Eloquent serializado, así que `clients.api_key` no viaja porque nadie la
 * escribió en el config, no porque alguien se haya acordado de filtrarla. Encima hay una segunda
 * reja fail-closed (`columnas_prohibidas`) que devuelve 422 sin servir nada si el config declarara
 * igual una columna sensible.
 *
 * Toda la superficie —modelos, columnas, filtros, relaciones y los modelos excluidos con el motivo—
 * se publica en `GET claude/catalog`, derivada del mismo config que sirve las consultas.
 */
class ClaudeQueryController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Servicio que arma y corre la consulta desde el config.
     *
     * @var ClaudeQueryService
     */
    private $consultas;

    /**
     * @param ClaudeQueryService $consultas Servicio de consulta genérica.
     */
    public function __construct(ClaudeQueryService $consultas)
    {
        $this->consultas = $consultas;
    }

    /**
     * GET /api/claude/query
     *
     * Parámetros: `model` (único obligatorio), `include`, `fields`, `after_id`, `limit`, `order`,
     * `q`, `count_only`, más los filtros que declare el modelo pedido. Un filtro no declarado es
     * 422 con la lista de los válidos: ignorarlo en silencio devolvería una lista más larga de la
     * esperada y parecería un problema de datos.
     *
     * ⚠️ NO hay `order_by`. La paginación es por cursor sobre una sola columna (`clave_de_cursor`),
     * y aceptar otro orden rompería el cursor en silencio: la página 2 se saltearía o repetiría
     * filas sin que nada lo denuncie.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'model'      => 'required|string|max:60',
            'after_id'   => 'nullable|integer|min:1',
            'limit'      => 'nullable|integer',
            'order'      => 'nullable|string|in:asc,desc',
            'q'          => 'nullable|string|max:200',
            'count_only' => 'nullable|boolean',
        ]);

        if ($error !== null) {
            return $error;
        }

        $resultado = $this->consultas->consultar($request, (string) $request->input('model'));

        if (! is_array($resultado)) {
            return $resultado;
        }

        return response()->json($resultado);
    }
}
