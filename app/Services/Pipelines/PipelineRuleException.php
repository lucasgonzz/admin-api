<?php

namespace App\Services\Pipelines;

/**
 * El "no" de una regla del CRM de pipelines (misión pipelines-crm, 27/9/2026), ya convertido en la
 * respuesta que la SPA espera.
 *
 * Dos formas, las dos con el mensaje en español porque el wrapper `@/utils/axios` de la SPA lo
 * muestra tal cual en un toast:
 *  - regla de negocio: `{message}` (422). Ej.: "Ya está en esa etapa."
 *  - validación: `{message, errors}` (422), con `errors` en el formato estándar de Laravel
 *    (`{"fields.canal": ["..."]}`), que es lo que el `MoveModal` pinta debajo de cada campo.
 *  - no existe: `{message}` con 404.
 *
 * 🔴 POR QUÉ NO SE USA `ValidationException` DE LARAVEL: en Laravel 8 su mensaje está escrito a
 * fuego en inglés ("The given data was invalid.") y el locale del admin es `en`, así que el toast
 * de la SPA mostraría eso. Esta excepción arma la misma forma de respuesta con un mensaje legible.
 *
 * Se renderiza sola (`render()`, que el Handler de Laravel llama antes que nada), así los
 * controladores no repiten un try/catch por endpoint. Y no se loguea (`report()` vacío): es una
 * respuesta esperada, no una falla.
 */
class PipelineRuleException extends \RuntimeException
{
    /**
     * Errores por campo, formato Laravel: `['clave' => ['mensaje', ...]]`. Vacío en una regla de
     * negocio.
     *
     * @var array<string, array<int, string>>
     */
    private $errores;

    /**
     * Status HTTP de la respuesta (422 o 404).
     *
     * @var int
     */
    private $status;

    /**
     * @param string                              $message Mensaje en español, listo para un toast.
     * @param array<string, array<int, string>>   $errores Errores por campo (vacío = regla de negocio).
     * @param int                                 $status  422 o 404.
     */
    public function __construct($message, array $errores = [], $status = 422)
    {
        parent::__construct((string) $message);

        $this->errores = $errores;
        $this->status  = (int) $status;
    }

    /**
     * Error de validación con errores por campo. El mensaje general es el primer error (más "y N
     * más" si hay otros), para que el toast diga algo útil y no un genérico.
     *
     * @param array<string, array<int, string>|string> $errores
     *
     * @return self
     */
    public static function validacion(array $errores)
    {
        $normalizados = [];
        foreach ($errores as $clave => $mensajes) {
            $normalizados[(string) $clave] = array_values((array) $mensajes);
        }

        $primero  = null;
        $cantidad = 0;
        foreach ($normalizados as $mensajes) {
            foreach ($mensajes as $mensaje) {
                if ($primero === null) {
                    $primero = $mensaje;
                }
                $cantidad++;
            }
        }

        $mensaje = $primero !== null ? $primero : 'Revisá los datos cargados.';
        if ($cantidad > 1) {
            $mensaje .= ' (y ' . ($cantidad - 1) . ' ' . ($cantidad - 1 === 1 ? 'error más' : 'errores más') . ')';
        }

        return new self($mensaje, $normalizados, 422);
    }

    /**
     * Un registro que no existe (404), con mensaje legible. Reemplaza al "No query results for
     * model [...]" en inglés que devolvería un `findOrFail()`.
     *
     * @param string $que      Ej.: "La oportunidad", "El pipeline".
     * @param bool   $femenino Para el "la / lo haya borrado".
     *
     * @return self
     */
    public static function no_existe($que, $femenino = true)
    {
        return new self($que . ' no existe (puede que otro admin ' . ($femenino ? 'la' : 'lo') . ' haya borrado).', [], 404);
    }

    /**
     * Errores por campo.
     *
     * @return array<string, array<int, string>>
     */
    public function errors()
    {
        return $this->errores;
    }

    /**
     * Status HTTP.
     *
     * @return int
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * No se loguea: es una respuesta esperada, no una falla del sistema. (El Handler de Laravel 8
     * no reporta la excepción si este método existe y no devuelve `false`.)
     *
     * @return void
     */
    public function report()
    {
    }

    /**
     * La respuesta JSON que ve la SPA.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function render($request)
    {
        $cuerpo = ['message' => $this->getMessage()];

        if ($this->errores !== []) {
            $cuerpo['errors'] = $this->errores;
        }

        return response()->json($cuerpo, $this->status);
    }
}
