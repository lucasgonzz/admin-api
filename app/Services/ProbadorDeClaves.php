<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Prueba una clave de API contra su proveedor SIN gastar tokens.
 *
 * Sirve para la plantilla .env del admin: antes de aplicarle una clave nueva a toda la flota (env-bulk)
 * y desde el botón "Probar" de la pantalla, se confirma que el proveedor la acepta. Hoy sólo se sabe
 * probar ANTHROPIC_API_KEY (un GET a /v1/models, que no consume tokens); cualquier otra clave devuelve
 * `no_soportada` y se agrega acá cuando haga falta.
 *
 * 🔴 La clave NUNCA aparece en el mensaje devuelto, en un log ni en una excepción que salga de acá:
 * viaja sólo en el header de la request. No se loguea ninguna cabecera ni el mensaje de las
 * excepciones de red (pueden traer la URL o fragmentos del pedido).
 *
 * Estados posibles:
 *  - valida          el proveedor la aceptó (HTTP 200).
 *  - rechazada       el proveedor la rechazó (HTTP 401/403): aplicarla rompería la IA de los clientes.
 *  - no_concluyente  no se pudo saber (timeout, red caída, 429, 5xx, otra respuesta): no prueba nada.
 *  - no_soportada    no hay prueba disponible para esa variable.
 *  - sin_valor       no hay valor para probar (la variable no existe o está vacía).
 */
class ProbadorDeClaves
{
    /**
     * Estados que devuelve probar() (y que reusan los controladores).
     */
    const VALIDA         = 'valida';
    const RECHAZADA      = 'rechazada';
    const NO_CONCLUYENTE = 'no_concluyente';
    const NO_SOPORTADA   = 'no_soportada';
    const SIN_VALOR      = 'sin_valor';

    /**
     * Endpoint de Anthropic que lista los modelos: valida la clave y no consume tokens.
     *
     * @var string
     */
    const ANTHROPIC_URL_MODELOS = 'https://api.anthropic.com/v1/models';

    /**
     * Segundos de espera máxima por la respuesta del proveedor.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 10;

    /**
     * Indica si hay una prueba disponible para esa variable.
     *
     * @param  string  $key  Nombre de la variable (ej: ANTHROPIC_API_KEY).
     * @return bool
     */
    public function es_soportada(string $key): bool
    {
        return strtoupper($key) === 'ANTHROPIC_API_KEY';
    }

    /**
     * Prueba el valor de una variable contra su proveedor.
     *
     * @param  string       $key    Nombre de la variable.
     * @param  string|null  $valor  Valor en claro a probar.
     * @return array{estado: string, mensaje: string, http: int|null}
     */
    public function probar(string $key, $valor): array
    {
        if (! $this->es_soportada($key)) {
            return $this->resultado(self::NO_SOPORTADA, 'No hay prueba disponible para ' . $key . '.', null);
        }

        if ($valor === null || trim((string) $valor) === '') {
            return $this->resultado(self::SIN_VALOR, $key . ' no tiene un valor cargado para probar.', null);
        }

        return $this->probar_anthropic((string) $valor);
    }

    /**
     * Prueba una clave de Anthropic con GET /v1/models.
     *
     * @param  string  $clave  Clave en claro (sólo viaja en el header).
     * @return array{estado: string, mensaje: string, http: int|null}
     */
    private function probar_anthropic(string $clave): array
    {
        $http = Http::withHeaders([
            'x-api-key'         => $clave,
            'anthropic-version' => '2023-06-01',
        ])->timeout(self::TIMEOUT_SEGUNDOS);

        /* Configuración TLS según entorno, igual que CallSummaryService (WAMP en Windows requiere cacert explícito). */
        $verificar_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle     = config('services.anthropic.ca_bundle');

        if (! $verificar_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        try {
            $respuesta = $http->get(self::ANTHROPIC_URL_MODELOS);
        } catch (\Throwable $e) {
            /* No se usa el mensaje de la excepción: no hace falta y no se arriesga a filtrar nada. */
            return $this->resultado(
                self::NO_CONCLUYENTE,
                'No se pudo conectar con Anthropic para probar la clave. Reintentá en un momento.',
                null
            );
        }

        $codigo = $respuesta->status();

        if ($codigo === 200) {
            return $this->resultado(self::VALIDA, 'Anthropic aceptó la clave.', $codigo);
        }

        if ($codigo === 401 || $codigo === 403) {
            return $this->resultado(self::RECHAZADA, 'Anthropic rechazó la clave (HTTP ' . $codigo . '): no es válida o fue revocada.', $codigo);
        }

        return $this->resultado(
            self::NO_CONCLUYENTE,
            'Anthropic respondió HTTP ' . $codigo . ' y no se pudo confirmar si la clave sirve.',
            $codigo
        );
    }

    /**
     * Arma el resultado con la forma fija que consumen los controladores y la SPA.
     *
     * @param  string    $estado
     * @param  string    $mensaje
     * @param  int|null  $http
     * @return array{estado: string, mensaje: string, http: int|null}
     */
    private function resultado(string $estado, string $mensaje, $http): array
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'http' => $http];
    }
}
