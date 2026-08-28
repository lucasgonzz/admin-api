<?php

namespace App\Http\Controllers\Api\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Los helpers genéricos que comparten TODOS los controladores del bloque `claude/*`: paginación
 * por cursor, normalización de parámetros que pueden venir como lista o como CSV, validación que
 * siempre contesta JSON, y las dos respuestas de error (422 y 404) con la forma única del bloque.
 *
 * 🔴 POR QUÉ EXISTE ESTE TRAIT. Estos quince métodos nacieron privados adentro de
 * `ClaudeClientOpsController` y ya estaban copiados —con divergencias— en
 * `ClaudeUpgradeOpsController` y en `ClaudeLeadsAnalyticsController`. Con los cuatro controladores
 * nuevos de esta misión iban a ser SEIS copias de `validar_o_422`, y eso es exactamente la clase
 * de error que este repo tiene documentada: dos (o seis) definiciones de la misma cosa que se
 * desincronizan sin que nada lo denuncie. El día que haya que arreglar el 302 que devuelve una
 * request sin `Accept: application/json`, o cambiar la forma del cuerpo del 422, se arregla en un
 * solo lugar o no se arregla.
 *
 * 🔴 Y ES UN CONTRATO, NO SOLO CÓDIGO REPETIDO. Todo `claude/*` promete la misma forma de error
 * (`{"error": "..."}` con 422 o 404) y la misma manera de paginar (`after_id` + `has_more` +
 * `next_after_id`, sin `count(*)`). Un controlador que se copia los helpers puede cambiar esa
 * forma sin darse cuenta; uno que usa el trait, no.
 *
 * ⚠️ `ClaudeLeadsAnalyticsController` TODAVÍA tiene su propia copia, con un tercer texto
 * (`GET claude/schema`). Quedó afuera a propósito: no es de esta misión y migrarla sin tests de por
 * medio sería tocar el bloque de leads para arreglar el de clientes. Está anotado para que el que
 * pase por ahí sepa que hay una cuarta copia y no la descubra de nuevo.
 *
 * ⚠️ Lo que este trait NO se lleva, a propósito: todo lo de horarios de `ClaudeClientOpsController`
 * (`resolvedor()`, `normalizar_timezone()`, `resolver_timezone_pedido()`, `hora_hhmm()`,
 * `dias_cargados_de()`). Eso no es genérico: es la lógica de un endpoint concreto y meterla acá
 * la haría parecer compartida cuando no lo es.
 *
 * Los métodos son `protected` (nacieron `private`) porque un trait sirve a la clase que lo usa, no
 * a nadie de afuera: 🔴 ninguno de estos es ni tiene que volverse parte de la API pública de un
 * controlador.
 */
trait RespuestasParaClaude
{
    /**
     * Endpoint que se le sugiere al que recibió un 422, para que averigüe los valores válidos.
     *
     * 🔴 Es un punto de extensión y no una constante justamente porque los controladores viejos
     * ya publican otro texto: `ClaudeClientOpsController` viene diciendo `GET claude/ops-schema`
     * desde el 24/8/2026 y sobrescribe este método para seguir diciendo exactamente eso. Cambiarle
     * el texto a un endpoint que ya está en uso sería un cambio de contrato disfrazado de refactor.
     * Para todo lo nuevo, la respuesta correcta es el catálogo.
     *
     * @return string
     */
    protected function ayuda_del_schema()
    {
        return 'GET claude/catalog';
    }

    /**
     * Aplica el cursor por id respetando la dirección del orden.
     *
     * @param \Illuminate\Database\Query\Builder $query    Query en construcción.
     * @param string                             $columna  Columna del cursor (calificada).
     * @param int|null                           $after_id Id del cursor.
     * @param string                             $direction asc | desc.
     *
     * @return void
     */
    protected function aplicar_cursor($query, $columna, $after_id, $direction)
    {
        if ($after_id === null) {
            return;
        }

        if ($direction === 'desc') {
            $query->where($columna, '<', $after_id);

            return;
        }

        $query->where($columna, '>', $after_id);
    }

    /**
     * Trae una página pidiendo una fila de más, para saber si hay siguiente sin contar el total.
     *
     * @param \Illuminate\Database\Query\Builder $query Query ya ordenada.
     * @param int                                $limit Tamaño de página.
     *
     * @return array<string, mixed>
     */
    protected function traer_pagina($query, $limit)
    {
        $rows     = $query->limit($limit + 1)->get();
        $has_more = $rows->count() > $limit;

        if ($has_more) {
            $rows = $rows->slice(0, $limit)->values();
        }

        return ['rows' => $rows, 'has_more' => $has_more];
    }

    /**
     * Normaliza y valida el parámetro `include` contra la lista blanca del endpoint.
     * Acepta `include[]=a&include[]=b` o `include=a,b`.
     *
     * @param Request            $request    Request entrante.
     * @param array<int, string> $permitidos Includes válidos.
     *
     * @return array<int, string>|\Illuminate\Http\JsonResponse
     */
    protected function resolver_includes(Request $request, array $permitidos)
    {
        $includes = $this->normalizar_lista($request->input('include'));

        foreach ($includes as $include) {
            if (! in_array($include, $permitidos, true)) {
                return $this->error_422('El include "' . $include . '" no existe en este endpoint.', [
                    'includes_validos' => $permitidos,
                ]);
            }
        }

        return $includes;
    }

    /**
     * Normaliza un parámetro que puede llegar como array (`client_ids[]=1&client_ids[]=2`) o como
     * string separado por comas (`client_ids=1,2`).
     *
     * @param mixed $valor Valor crudo.
     *
     * @return array<int, string>
     */
    protected function normalizar_lista($valor)
    {
        if ($valor === null) {
            return [];
        }

        if (! is_array($valor)) {
            $valor = explode(',', (string) $valor);
        }

        $lista = [];
        foreach ($valor as $item) {
            if (is_array($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item === '' || in_array($item, $lista, true)) {
                continue;
            }

            $lista[] = $item;
        }

        return $lista;
    }

    /**
     * Igual que normalizar_lista() pero devolviendo enteros positivos.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return array<int, int>
     */
    protected function normalizar_lista_enteros($valor)
    {
        $enteros = [];

        foreach ($this->normalizar_lista($valor) as $item) {
            if (! is_numeric($item)) {
                continue;
            }

            $entero = (int) $item;
            if ($entero > 0 && ! in_array($entero, $enteros, true)) {
                $enteros[] = $entero;
            }
        }

        return $enteros;
    }

    /**
     * Tamaño de página: default si no vino, y recorte al tope duro en vez de error.
     *
     * @param mixed $raw     Valor crudo del parámetro.
     * @param int   $default Valor por defecto.
     * @param int   $max     Tope duro.
     *
     * @return int
     */
    protected function resolver_limite($raw, $default, $max)
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return $default;
        }

        $limite = (int) $raw;

        if ($limite < 1) {
            return 1;
        }

        return $limite > $max ? $max : $limite;
    }

    /**
     * Corre `$request->validate()` garantizando una respuesta JSON 422 aunque la request no traiga
     * `Accept: application/json`. Sin esto, una GET pelada de un script recibiría un redirect 302
     * en vez del error, que es imposible de diagnosticar del otro lado.
     *
     * @param Request               $request Request entrante.
     * @param array<string, string> $reglas  Reglas de validación.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si validó bien.
     */
    protected function validar_o_422(Request $request, array $reglas)
    {
        try {
            $request->validate($reglas, $this->mensajes_de_validacion());
        } catch (ValidationException $e) {
            return response()->json([
                'error'   => 'parámetros inválidos',
                'errores' => $e->errors(),
                'ayuda'   => 'Consultá ' . $this->ayuda_del_schema() . ' para ver los filtros y valores válidos.',
            ], 422);
        }

        return null;
    }

    /**
     * Mensajes de validación en español. El proyecto solo tiene traducciones en inglés
     * (resources/lang/en), así que se pasan inline.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required'    => 'El parámetro :attribute es obligatorio.',
            'date'        => 'El parámetro :attribute tiene que ser una fecha válida.',
            'date_format' => 'El parámetro :attribute tiene que venir en formato :format.',
            'integer'     => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'     => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'      => 'El parámetro :attribute tiene que ser texto.',
            'in'          => 'El parámetro :attribute tiene un valor que no está permitido. Mirá '
                . $this->ayuda_del_schema() . ' para ver los válidos.',
            'min'         => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'         => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Respuesta 422 con mensaje legible en español.
     *
     * @param string              $mensaje Mensaje del error.
     * @param array<string, mixed> $extra   Datos adicionales.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function error_422($mensaje, array $extra = [])
    {
        return response()->json(array_merge(['error' => $mensaje], $extra), 422);
    }

    /**
     * Respuesta 404 con mensaje legible en español.
     *
     * @param string $mensaje Mensaje del error.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function error_404($mensaje)
    {
        return response()->json(['error' => $mensaje], 404);
    }

    /**
     * Lee un parámetro booleano distinguiendo "ausente" (null) de "false" explícito. Sin esto,
     * `$request->boolean()` devolvería false para un filtro que nunca se pidió y el filtro se
     * aplicaría igual.
     *
     * @param Request $request Request entrante.
     * @param string  $clave   Nombre del parámetro.
     *
     * @return bool|null
     */
    protected function booleano_o_null(Request $request, $clave)
    {
        if (! $request->has($clave)) {
            return null;
        }

        $valor = $request->input($clave);
        if ($valor === null || $valor === '') {
            return null;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Entero, o null si el parámetro vino vacío o ausente.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return int|null
     */
    protected function entero_o_null($valor)
    {
        if ($valor === null || $valor === '' || is_array($valor) || ! is_numeric($valor)) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Texto de un parámetro, cayendo al default cuando llega vacío o ausente.
     *
     * 🔴 Existe porque `$request->input('order', 'asc')` NO alcanza: el middleware global
     * `ConvertEmptyStringsToNull` convierte `?order=` en null, y como la clave EXISTE con valor
     * null, `input()` devuelve null en vez del default. Ese null terminaba en `orderBy('')`, que
     * en Laravel 8 tira InvalidArgumentException: un `?order=` de más devolvía 500 en vez del 422
     * legible que este controlador se propone devolver siempre.
     *
     * @param Request $request Request entrante.
     * @param string  $clave   Nombre del parámetro.
     * @param string  $default Valor por defecto.
     *
     * @return string
     */
    protected function texto_con_default(Request $request, $clave, $default)
    {
        $valor = $this->texto_o_null($request->input($clave));

        return $valor !== null ? $valor : $default;
    }

    /**
     * Texto recortado, o null si quedó vacío.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return string|null
     */
    protected function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Parsea una fecha-hora sin lanzar.
     *
     * @param string|null $valor Valor crudo.
     *
     * @return Carbon|null
     */
    protected function parsear_o_null($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor, config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
