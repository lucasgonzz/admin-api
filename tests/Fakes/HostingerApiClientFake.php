<?php

namespace Tests\Fakes;

use App\Services\HostingerApiClient;

/**
 * Reemplazo de HostingerApiClient para los tests: registra las llamadas y devuelve respuestas
 * preparadas, sin salir a la red.
 *
 * 🔴 Sobreescribe UN SOLO método: request(), que es transporte puro. Todo lo demás del cliente es
 * código real y se ejecuta de verdad en cada test: el armado de las rutas con el usuario y el
 * dominio de config, el payload exacto de cada POST, el 'overwrite' => false del PUT de DNS y la
 * clasificación de errores.
 *
 * Es el mismo criterio de EnvSshServiceFake: se falsea el transporte y se conserva el formateo,
 * porque el formateo es lógica del admin. Un fake que sobreescribiera create_subdomain() entero
 * daría todos los tests en verde mientras el directory real se arma mal — que es exactamente el
 * bug que estos tests tienen que poder detectar.
 *
 * PHP 7.4.
 */
class HostingerApiClientFake extends HostingerApiClient
{
    /**
     * Todas las llamadas que pasaron por request(), en orden.
     *
     * Cada entrada: ['metodo' => 'POST', 'ruta' => '/api/...', 'body' => [...]].
     *
     * @var array<int, array<string, mixed>>
     */
    public $llamadas = [];

    /**
     * Respuesta que se devuelve cuando ninguna regla matchea.
     *
     * @var array<int|string, mixed>
     */
    public $respuesta_por_defecto = [];

    /**
     * Reglas de respuesta, en orden de declaración. Gana la primera que matchea.
     *
     * Cada entrada: ['ruta' => string, 'metodo' => string|'', 'respuesta' => array].
     *
     * @var array<int, array<string, mixed>>
     */
    private $respuestas = [];

    /**
     * Reglas de falla, en orden de declaración. Se evalúan ANTES que las respuestas.
     *
     * Cada entrada: ['ruta' => string, 'metodo' => string|'', 'codigo' => int, 'cuerpo' => string].
     *
     * @var array<int, array<string, mixed>>
     */
    private $fallas = [];

    /**
     * Respuestas que cambian entre una llamada y la siguiente sobre la misma ruta.
     *
     * 🔴 Existe por la guarda G8 del plan (§5): la verificación posterior del PUT de DNS hace un GET
     * de la zona ANTES y otro DESPUÉS, y toda la guarda consiste justamente en que esas dos
     * respuestas pueden ser distintas. Con una regla fija de responder() no hay forma de escribir el
     * caso que importa —el PUT se llevó puesto un registro— y G8 quedaría sin test.
     *
     * Cada entrada: ['ruta' => string, 'metodo' => string|'', 'respuestas' => array<int, array>].
     *
     * @var array<int, array<string, mixed>>
     */
    private $secuencias = [];

    /**
     * Prepara la respuesta de toda llamada cuya ruta contenga $ruta_parcial.
     *
     * @param  string  $ruta_parcial  Fragmento de la ruta (ej: '/databases').
     * @param  array<int|string, mixed>  $respuesta
     * @param  string  $metodo  Verbo al que aplica; vacío = cualquiera. Hace falta porque el GET y
     *                          el POST de un recurso comparten la ruta.
     * @return void
     */
    public function responder(string $ruta_parcial, array $respuesta, string $metodo = ''): void
    {
        $this->respuestas[] = [
            'ruta'      => $ruta_parcial,
            'metodo'    => strtoupper($metodo),
            'respuesta' => $respuesta,
        ];
    }

    /**
     * Hace fallar toda llamada cuya ruta contenga $ruta_parcial, con el código y el cuerpo dados.
     *
     * El cuerpo es el que después clasifica clasificar_error(): es lo que permite probar la
     * idempotencia ("ya existe") y, sobre todo, el caso desconocido, que tiene que hacer fallar al
     * llamador en vez de dar por bueno que el recurso estaba.
     *
     * @param  string  $ruta_parcial
     * @param  int     $codigo  Status HTTP.
     * @param  string  $cuerpo  Cuerpo crudo de la respuesta de error.
     * @param  string  $metodo  Verbo al que aplica; vacío = cualquiera.
     * @return void
     */
    public function fallar_con(string $ruta_parcial, int $codigo, string $cuerpo, string $metodo = ''): void
    {
        $this->fallas[] = [
            'ruta'   => $ruta_parcial,
            'metodo' => strtoupper($metodo),
            'codigo' => $codigo,
            'cuerpo' => $cuerpo,
        ];
    }

    /**
     * Prepara respuestas que se van consumiendo, una por llamada, sobre la misma ruta.
     *
     * La última se repite si hay más llamadas que respuestas. Se evalúa despues de las fallas y
     * ANTES de responder(), para que una secuencia pueda ganarle a una regla fija.
     *
     * @param  string  $ruta_parcial
     * @param  array<int, array<int|string, mixed>>  $respuestas  En orden de consumo.
     * @param  string  $metodo
     * @return void
     */
    public function responder_secuencia(string $ruta_parcial, array $respuestas, string $metodo = ''): void
    {
        $this->secuencias[] = [
            'ruta'       => $ruta_parcial,
            'metodo'     => strtoupper($metodo),
            'respuestas' => array_values($respuestas),
        ];
    }

    /**
     * Borra todas las reglas y el registro de llamadas.
     *
     * @return void
     */
    public function limpiar(): void
    {
        $this->llamadas    = [];
        $this->respuestas  = [];
        $this->fallas      = [];
        $this->secuencias  = [];
    }

    /**
     * Llamadas registradas de un verbo determinado.
     *
     * @param  string  $metodo
     * @return array<int, array<string, mixed>>
     */
    public function llamadas_de(string $metodo): array
    {
        $metodo      = strtoupper($metodo);
        $encontradas = [];

        foreach ($this->llamadas as $llamada) {
            if ($llamada['metodo'] === $metodo) {
                $encontradas[] = $llamada;
            }
        }

        return $encontradas;
    }

    /**
     * Llamadas que modifican algo del otro lado.
     *
     * Es el aserto de "no se escribió ni una sola cosa", que varios tests del plan necesitan
     * (§7, tests 3 y 5a): un preflight que falla no puede haber dejado nada creado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function escrituras(): array
    {
        $escrituras = [];

        foreach ($this->llamadas as $llamada) {
            if (in_array($llamada['metodo'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $escrituras[] = $llamada;
            }
        }

        return $escrituras;
    }

    /**
     * Expone la redacción del payload para poder afirmar, desde un test, que la contraseña no llega
     * al log. El método real es protected porque nadie fuera del cliente tiene por qué llamarlo.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function redactar_payload_publico(array $body): array
    {
        return $this->redactar_payload($body);
    }

    /**
     * Registra la llamada y devuelve lo preparado, sin tocar la red.
     *
     * No replica el chequeo de token del padre: quien tiene que fallar temprano por falta de token
     * es probar_token(), que lo mira con token_configurado() antes de llegar hasta acá, y ese
     * camino sí corre de verdad en los tests.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    protected function request(string $method, string $path, array $body = []): array
    {
        $method = strtoupper($method);

        $this->llamadas[] = [
            'metodo' => $method,
            'ruta'   => $path,
            'body'   => $body,
        ];

        foreach ($this->fallas as $falla) {
            if ($this->matchea($falla, $method, $path)) {
                /* Mismo formato de mensaje y mismo código que arma el request() real. */
                throw new \RuntimeException(
                    'La API de Hostinger respondió ' . $falla['codigo'] . ': '
                        . substr((string) $falla['cuerpo'], 0, 300),
                    (int) $falla['codigo']
                );
            }
        }

        foreach ($this->secuencias as $indice => $secuencia) {
            if (! $this->matchea($secuencia, $method, $path)) {
                continue;
            }

            /* Se consume una; la última queda pegada para las llamadas que sobren. */
            if (count($secuencia['respuestas']) > 1) {
                $respuesta = array_shift($this->secuencias[$indice]['respuestas']);

                return $respuesta;
            }

            return $secuencia['respuestas'][0];
        }

        foreach ($this->respuestas as $regla) {
            if ($this->matchea($regla, $method, $path)) {
                return $regla['respuesta'];
            }
        }

        return $this->respuesta_por_defecto;
    }

    /**
     * ¿La regla aplica a esta llamada?
     *
     * @param  array<string, mixed>  $regla
     * @param  string  $metodo
     * @param  string  $path
     * @return bool
     */
    private function matchea(array $regla, string $metodo, string $path): bool
    {
        if ($regla['metodo'] !== '' && $regla['metodo'] !== $metodo) {
            return false;
        }

        return strpos($path, (string) $regla['ruta']) !== false;
    }
}
