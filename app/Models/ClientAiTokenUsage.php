<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Consumo de IA de un cliente, ya agregado por día, acción y modelo.
 *
 * Es el espejo local de `ai_token_usages` del `empresa-api` de ese cliente. Ver la migración
 * `create_client_ai_token_usages_table` para por qué el unique `(client_id, fecha, proceso,
 * modelo)` es la pieza central y no un detalle.
 *
 * 🔴 Acá vive también el cálculo del costo, y vive acá y no en un service porque lo necesitan los
 * dos controladores (el del cliente y el del resumen global) y es una cuenta pura sobre las
 * columnas de esta fila: cualquier otro lugar le agregaría una indirección a cambio de nada. Lo
 * que NO se hace nunca es guardar el resultado — ver el docblock de `config/ia_precios.php`.
 *
 * @property int         $client_id                   Cliente dueño del consumo.
 * @property string      $fecha                       Día del consumo (en la zona del comercio).
 * @property string      $proceso                     La acción que gastó (chat_mensaje, embeddings_articulos…).
 * @property string      $proveedor                   anthropic | openai.
 * @property string      $modelo                      Modelo exacto informado por el cliente.
 * @property int         $llamadas                    Llamadas agregadas en esta fila.
 * @property int         $input_tokens                Tokens de prompt que no salieron de la caché.
 * @property int         $output_tokens               Tokens generados.
 * @property int         $cache_creation_input_tokens Tokens escritos en la caché de prompt.
 * @property int         $cache_read_input_tokens     Tokens leídos de la caché de prompt.
 */
class ClientAiTokenUsage extends Model
{
    /**
     * Divisor de la tabla de precios: los valores de `config('ia_precios')` son por MILLÓN de
     * tokens, no por token. Está acá como constante porque la cuenta se escribe en un solo lugar
     * y quien lea el número quiere saber de dónde sale.
     */
    const TOKENS_POR_UNIDAD_DE_PRECIO = 1000000;

    /**
     * Las cuatro puntas que se facturan por separado, mapeadas de la columna de la tabla a la
     * clave del precio. Recorrer este mapa (en vez de escribir las cuatro multiplicaciones a mano)
     * es lo que hace que agregar una quinta punta el día que un proveedor la invente sea una línea.
     *
     * @var array<string, string>
     */
    const PUNTAS = [
        'input_tokens'                => 'input',
        'output_tokens'               => 'output',
        'cache_creation_input_tokens' => 'cache_write',
        'cache_read_input_tokens'     => 'cache_read',
    ];

    /**
     * Todas las filas las escribe la recolección, nunca un request de usuario: no hay entrada de
     * afuera de la que protegerse con una lista blanca.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Los contadores viajan como enteros: se suman y se multiplican por un precio, y un string
     * numérico en una suma de PHP es una sorpresa esperando su turno.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'                   => 'integer',
        'llamadas'                    => 'integer',
        'input_tokens'                => 'integer',
        'output_tokens'               => 'integer',
        'cache_creation_input_tokens' => 'integer',
        'cache_read_input_tokens'     => 'integer',
    ];

    /**
     * Cliente dueño del consumo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Costo en dólares de un puñado de contadores, para un modelo dado.
     *
     * 🔴 Devuelve **null** cuando el modelo no está en `config('ia_precios')`, y eso es una
     * respuesta, no un fallo: significa "no sé cuánto salió esto". El llamador lo muestra como
     * "sin precio cargado" y lo deja fuera del total. Nunca se asume un precio por defecto: un
     * total que miente es peor que un renglón sin plata.
     *
     * @param string|null                $modelo    Modelo tal como lo informó el cliente.
     * @param array<string, int|float>   $tokens    Contadores, con las claves de self::PUNTAS.
     * @param array<string, array>|null  $precios   Tabla de precios; por defecto config('ia_precios').
     *
     * @return float|null Dólares, o null si el modelo no tiene precio cargado.
     */
    public static function costo_usd($modelo, array $tokens, $precios = null)
    {
        if ($precios === null) {
            $precios = config('ia_precios', []);
        }

        $clave = trim((string) $modelo);

        if ($clave === '' || ! is_array($precios) || ! isset($precios[$clave]) || ! is_array($precios[$clave])) {
            return null;
        }

        $tarifa = $precios[$clave];
        $costo  = 0.0;

        foreach (self::PUNTAS as $columna => $punta) {
            $cantidad = (float) (isset($tokens[$columna]) ? $tokens[$columna] : 0);
            $precio   = (float) (isset($tarifa[$punta]) ? $tarifa[$punta] : 0);

            $costo += ($cantidad * $precio) / self::TOKENS_POR_UNIDAD_DE_PRECIO;
        }

        return $costo;
    }

    /**
     * Si un modelo tiene precio cargado.
     *
     * Existe como método propio porque el front necesita distinguir "costó cero" de "no sé cuánto
     * costó", y `costo_usd() === null` obliga a repetir esa lectura en cada llamador.
     *
     * @param string|null               $modelo  Modelo tal como lo informó el cliente.
     * @param array<string, array>|null $precios Tabla de precios; por defecto config('ia_precios').
     *
     * @return bool
     */
    public static function tiene_precio($modelo, $precios = null)
    {
        if ($precios === null) {
            $precios = config('ia_precios', []);
        }

        $clave = trim((string) $modelo);

        return $clave !== '' && is_array($precios) && isset($precios[$clave]) && is_array($precios[$clave]);
    }

    /**
     * Agrupa el consumo de un rango de días por las dimensiones pedidas, con el costo ya calculado.
     *
     * 🔴 **`modelo` entra SIEMPRE en el GROUP BY de la consulta, pida lo que pida el llamador**, y
     * recién después se pliega. Es la única forma de costear bien: el precio depende del modelo, y
     * un grupo que sumó Haiku y Sonnet en la misma fila ya perdió la información que hace falta
     * para multiplicar. Agrupar por día y costear después "con un precio promedio" es justamente el
     * total que miente que `config/ia_precios.php` no quiere.
     *
     * La consulta agrega en la base y no en PHP: el resumen global son cuarenta y cinco clientes por
     * treinta días, y traerse eso fila por fila para sumarlo acá es plata de memoria por nada.
     *
     * Cada grupo devuelto trae, además de los contadores:
     *   - `tokens`             → la suma de las cuatro puntas, que es el número grande de la tarjeta.
     *   - `costo_usd`          → dólares de los modelos con precio cargado, o **null** cuando
     *                            NINGÚN modelo del grupo tiene precio. 🔴 `null` y 0 no son lo
     *                            mismo y por eso no se colapsan: 0 es "no costó nada" y null es "no
     *                            sé cuánto costó". Un renglón que dice 0 cuando en realidad nadie
     *                            cargó el precio es un total que miente.
     *   - `modelos_sin_precio` → los modelos de ese grupo que NO tienen precio. Si la lista no está
     *                            vacía y `costo_usd` no es null, ese costo es un PISO, no el total,
     *                            y el front lo dice.
     *
     * @param array<int, string> $dimensiones Columnas por las que agrupar (ej. ['fecha'], ['proceso']).
     * @param string             $desde       Primer día del rango, AAAA-MM-DD.
     * @param string             $hasta       Último día del rango, AAAA-MM-DD (inclusive).
     * @param int|null           $client_id   Un solo cliente, o null para todos.
     *
     * @return array<int, array<string, mixed>> Grupos, en el orden que devolvió la base.
     */
    public static function resumir(array $dimensiones, $desde, $hasta, $client_id = null)
    {
        /* `modelo` va sí o sí, y sin duplicarlo si el llamador ya lo pidió (por_modelo lo pide). */
        $del_grupo = $dimensiones;
        if (! in_array('modelo', $del_grupo, true)) {
            $del_grupo[] = 'modelo';
        }

        $seleccion = $del_grupo;
        $seleccion[] = DB::raw('SUM(llamadas) as llamadas');

        foreach (array_keys(self::PUNTAS) as $columna) {
            $seleccion[] = DB::raw('SUM(' . $columna . ') as ' . $columna);
        }

        $query = static::query()
            ->whereBetween('fecha', [(string) $desde, (string) $hasta]);

        if ($client_id !== null) {
            $query->where('client_id', (int) $client_id);
        }

        $filas = $query->groupBy($del_grupo)
            ->orderBy($del_grupo[0])
            ->get($seleccion);

        return self::plegar($filas, $dimensiones);
    }

    /**
     * Pliega las filas de `resumir()` (que vienen abiertas por modelo) en los grupos pedidos.
     *
     * @param \Illuminate\Support\Collection $filas       Filas agregadas por la base.
     * @param array<int, string>             $dimensiones Columnas del grupo final.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function plegar($filas, array $dimensiones)
    {
        /** Tabla de precios leída UNA vez: adentro del bucle serían N lecturas de config. */
        $precios = config('ia_precios', []);

        /** @var array<string, array<string, mixed>> Grupos en construcción, por clave compuesta. */
        $grupos = [];

        foreach ($filas as $fila) {
            $partes = [];
            foreach ($dimensiones as $dimension) {
                $partes[] = (string) $fila->{$dimension};
            }
            $clave = implode('|', $partes);

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [];

                foreach ($dimensiones as $dimension) {
                    $grupos[$clave][$dimension] = $fila->{$dimension};
                }

                $grupos[$clave]['llamadas']           = 0;
                $grupos[$clave]['tokens']             = 0;
                $grupos[$clave]['costo_usd']          = 0.0;
                $grupos[$clave]['modelos_sin_precio'] = [];
                // Cuántos modelos del grupo SÍ pudieron costearse. Si termina en cero, el costo
                // del grupo no es 0: es null ("no sé").
                $grupos[$clave]['__con_precio']       = 0;

                foreach (array_keys(self::PUNTAS) as $columna) {
                    $grupos[$clave][$columna] = 0;
                }
            }

            $tokens_de_la_fila = [];

            foreach (array_keys(self::PUNTAS) as $columna) {
                $cantidad                    = (int) $fila->{$columna};
                $tokens_de_la_fila[$columna] = $cantidad;
                $grupos[$clave][$columna]   += $cantidad;
                $grupos[$clave]['tokens']   += $cantidad;
            }

            $grupos[$clave]['llamadas'] += (int) $fila->llamadas;

            $costo = self::costo_usd($fila->modelo, $tokens_de_la_fila, $precios);

            if ($costo === null) {
                /* Sin precio cargado: no suma nada al costo y se nombra el modelo, para que el
                 * front pueda decir "este total no incluye X" en vez de mostrar un número corto
                 * como si fuera completo. */
                if (! in_array((string) $fila->modelo, $grupos[$clave]['modelos_sin_precio'], true)) {
                    $grupos[$clave]['modelos_sin_precio'][] = (string) $fila->modelo;
                }

                continue;
            }

            $grupos[$clave]['costo_usd'] += $costo;
            $grupos[$clave]['__con_precio']++;
        }

        /* 🔴 Acá se decide el null. Un grupo donde ningún modelo tenía precio NO vale 0 dólares:
         * vale "no sé". El auxiliar se saca para que no viaje en la respuesta. */
        foreach ($grupos as $clave => $grupo) {
            if ($grupo['__con_precio'] === 0) {
                $grupos[$clave]['costo_usd'] = null;
            }

            unset($grupos[$clave]['__con_precio']);
        }

        return array_values($grupos);
    }

    /**
     * Suma una lista de grupos de `resumir()` en un único total.
     *
     * Se suma sobre los grupos ya costeados y no se vuelve a consultar la base: el costo de cada
     * grupo ya se calculó con el modelo abierto, así que sumarlos da exactamente el mismo número
     * que costear fila por fila.
     *
     * Misma regla del null que en `plegar()`: si ningún grupo pudo costearse, el total es `null`,
     * no cero.
     *
     * @param array<int, array<string, mixed>> $grupos Salida de `resumir()`.
     *
     * @return array<string, mixed>
     */
    public static function totalizar(array $grupos)
    {
        $total = [
            'llamadas'           => 0,
            'tokens'             => 0,
            'costo_usd'          => 0.0,
            'modelos_sin_precio' => [],
        ];

        foreach (array_keys(self::PUNTAS) as $columna) {
            $total[$columna] = 0;
        }

        /** Cuántos grupos trajeron un costo real (no null). */
        $costeados = 0;

        foreach ($grupos as $grupo) {
            $total['llamadas'] += (int) $grupo['llamadas'];
            $total['tokens']   += (int) $grupo['tokens'];

            if ($grupo['costo_usd'] !== null) {
                $total['costo_usd'] += (float) $grupo['costo_usd'];
                $costeados++;
            }

            foreach (array_keys(self::PUNTAS) as $columna) {
                $total[$columna] += (int) $grupo[$columna];
            }

            foreach ($grupo['modelos_sin_precio'] as $modelo) {
                if (! in_array($modelo, $total['modelos_sin_precio'], true)) {
                    $total['modelos_sin_precio'][] = $modelo;
                }
            }
        }

        // Ni un solo grupo con precio: el total no es cero, es "no sé". Con la lista vacía (o sea,
        // sin consumo en el rango) el total SÍ es cero: no gastó nada, y eso sí se sabe.
        if ($costeados === 0 && $total['modelos_sin_precio'] !== []) {
            $total['costo_usd'] = null;
        }

        return $total;
    }
}
