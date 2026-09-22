<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Consumo de IA de un cliente, abierto por persona, proveedor y modelo, por día.
 *
 * Es el tercer corte del mismo hecho: `ClientAiTokenUsage` contesta "en qué se gastó",
 * `ClientAiTokenUsagePerson` contesta "quién lo gastó", y éste contesta "quién, con qué modelo y
 * cuánta plata". Ver la migración `create_client_ai_token_usage_person_models_table` para por qué
 * la clave única tiene cinco dimensiones y ninguna es nullable.
 *
 * 🔴 **Acá SÍ hay costo, y es el único corte por persona que puede tenerlo.** El precio depende del
 * modelo y este corte lo trae, así que cada fila se costea con `ClientAiTokenUsage::costo_usd()`
 * —la misma tabla de precios y la misma aritmética que el resto del admin, sin una segunda copia—
 * y la persona suma lo de sus modelos. Con una regla que no se negocia: **un modelo sin precio
 * deja a la persona sin costo total (null), nunca en un número corto.** Cero significa "no costó
 * nada"; null, "no sé cuánto costó". Un total que suma tres modelos de cuatro y lo muestra como
 * si fuera completo es exactamente el total que miente que `config/ia_precios.php` no quiere.
 *
 * @property int         $client_id                   Cliente dueño del consumo.
 * @property string      $fecha                       Día del consumo (en la zona del comercio).
 * @property int         $auth_user_id                Usuario de la base del cliente; 0 = automático.
 * @property string      $proveedor                   anthropic | deepseek | openai.
 * @property string      $modelo                      Modelo exacto informado por el cliente.
 * @property string|null $nombre                      Nombre resuelto por el cliente.
 * @property int         $llamadas                    Llamadas de esa persona con ese modelo ese día.
 * @property int         $input_tokens                Tokens de prompt que no salieron de la caché.
 * @property int         $output_tokens               Tokens generados.
 * @property int         $cache_creation_input_tokens Tokens escritos en la caché de prompt.
 * @property int         $cache_read_input_tokens     Tokens leídos de la caché de prompt.
 */
class ClientAiTokenUsagePersonModel extends Model
{
    /**
     * Nombre de la tabla. Explícito por el mismo motivo que en `ClientAiTokenUsagePerson`: la
     * pluralización automática sobre `...PersonModel` daría `..._person_models` por casualidad y
     * no por diseño.
     *
     * @var string
     */
    protected $table = 'client_ai_token_usage_person_models';

    /**
     * 🔴 Centinela de "procesos automáticos": EL MISMO que en `ClientAiTokenUsagePerson`, tomado de
     * ahí y no redeclarado, porque el controlador cruza los dos cortes por esta clave y dos
     * centinelas distintos serían dos filas de "nadie" que no se encuentran.
     */
    const AUTOMATICO = ClientAiTokenUsagePerson::AUTOMATICO;

    /**
     * Etiqueta del centinela, también compartida: las dos puntas que lean un corte por persona
     * tienen que decir lo mismo.
     */
    const ETIQUETA_AUTOMATICO = ClientAiTokenUsagePerson::ETIQUETA_AUTOMATICO;

    /**
     * Los cuatro contadores que se suman. Mismo orden y mismos nombres que en las dos tablas
     * hermanas, y por eso se toman de una de ellas en vez de escribirse de nuevo.
     *
     * @var array<int, string>
     */
    const CONTADORES = ClientAiTokenUsagePerson::CONTADORES;

    /**
     * Todas las filas las escribe la recolección, nunca un request de usuario.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Los contadores viajan como enteros: se suman y se multiplican por un precio.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'                   => 'integer',
        'auth_user_id'                => 'integer',
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
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * El consumo de un cliente en un rango, plegado por persona y, adentro de cada persona, por
     * proveedor y modelo, con el costo ya calculado.
     *
     * Cada persona devuelta trae:
     *   - `auth_user_id`          → id en la base del cliente, o null para los procesos automáticos.
     *   - `es_automatico`         → true para la fila del centinela.
     *   - `nombre`                → el del día MÁS NUEVO del rango (ver abajo).
     *   - `llamadas`, `tokens`    → sumados sobre todos sus modelos, más los cuatro contadores.
     *   - `costo_usd`             → suma de sus modelos, o **null si alguno no tiene precio**.
     *   - `tiene_precio_completo` → true solo cuando TODOS sus modelos tienen precio cargado.
     *   - `modelos`               → uno por (proveedor, modelo): llamadas, tokens, los cuatro
     *                               contadores, `costo_usd` (null si no hay precio) y `tiene_precio`.
     *
     * Se pliega en PHP y no en la base por el mismo motivo que en `ClientAiTokenUsagePerson`: el
     * `nombre` a elegir es el del último día que informó el cliente, y eso no es una función de
     * agregación. El volumen lo permite de sobra: son los empleados de un comercio, por los
     * modelos que usaron, por los días del rango.
     *
     * Orden: personas por costo descendente, y adentro de cada una sus modelos por costo
     * descendente. Lo que no tiene precio va al final de cada lista, ordenado por tokens: es lo que
     * se mira cuando la pregunta es "quién gastó más plata", y un renglón sin plata no puede
     * colarse arriba de uno que sí la tiene.
     *
     * @param int    $client_id Cliente.
     * @param string $desde     Primer día del rango, AAAA-MM-DD.
     * @param string $hasta     Último día del rango, AAAA-MM-DD (inclusive).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resumir($client_id, $desde, $hasta)
    {
        /* Ascendente por fecha a propósito: al plegar, el nombre de cada vuelta pisa al anterior,
         * así que gana el del día MÁS NUEVO. Es el que el cliente resolvió más recientemente. */
        $filas = static::query()
            ->where('client_id', (int) $client_id)
            ->whereBetween('fecha', [(string) $desde, (string) $hasta])
            ->orderBy('fecha')
            ->get();

        /** Tabla de precios leída UNA vez: adentro del bucle serían N lecturas de config. */
        $precios = config('ia_precios', []);

        /** @var array<int, array<string, mixed>> Personas en construcción, por auth_user_id. */
        $personas = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->auth_user_id;

            if (! isset($personas[$clave])) {
                $personas[$clave] = [
                    'auth_user_id'          => $clave === self::AUTOMATICO ? null : $clave,
                    'es_automatico'         => $clave === self::AUTOMATICO,
                    'nombre'                => null,
                    'llamadas'              => 0,
                    'tokens'                => 0,
                    'costo_usd'             => 0.0,
                    'tiene_precio_completo' => true,
                    // Indexados por "proveedor|modelo" mientras se pliega; al final, array_values.
                    'modelos'               => [],
                ];

                foreach (self::CONTADORES as $columna) {
                    $personas[$clave][$columna] = 0;
                }
            }

            $nombre = trim((string) $fila->nombre);
            if ($nombre !== '') {
                $personas[$clave]['nombre'] = $nombre;
            }

            /* El modelo se identifica por las DOS dimensiones, igual que en la clave única: el
             * mismo modelo con distinto proveedor es otro renglón, no el mismo. */
            $clave_modelo = (string) $fila->proveedor . '|' . (string) $fila->modelo;

            if (! isset($personas[$clave]['modelos'][$clave_modelo])) {
                $personas[$clave]['modelos'][$clave_modelo] = [
                    'proveedor'    => (string) $fila->proveedor,
                    'modelo'       => (string) $fila->modelo,
                    'llamadas'     => 0,
                    'tokens'       => 0,
                    // Se calculan al cerrar el plegado, cuando los contadores ya están completos.
                    'costo_usd'    => null,
                    'tiene_precio' => false,
                ];

                foreach (self::CONTADORES as $columna) {
                    $personas[$clave]['modelos'][$clave_modelo][$columna] = 0;
                }
            }

            $personas[$clave]['llamadas']                            += (int) $fila->llamadas;
            $personas[$clave]['modelos'][$clave_modelo]['llamadas']  += (int) $fila->llamadas;

            foreach (self::CONTADORES as $columna) {
                $cantidad = (int) $fila->{$columna};

                $personas[$clave][$columna]                            += $cantidad;
                $personas[$clave]['tokens']                            += $cantidad;
                $personas[$clave]['modelos'][$clave_modelo][$columna]  += $cantidad;
                $personas[$clave]['modelos'][$clave_modelo]['tokens']  += $cantidad;
            }
        }

        foreach ($personas as $clave => $persona) {
            $modelos = [];

            foreach ($persona['modelos'] as $modelo) {
                $contadores = [];
                foreach (self::CONTADORES as $columna) {
                    $contadores[$columna] = (int) $modelo[$columna];
                }

                /* Misma tabla y misma cuenta que el resto del admin. `null` es una respuesta, no un
                 * fallo: "no sé cuánto salió esto". */
                $costo = ClientAiTokenUsage::costo_usd($modelo['modelo'], $contadores, $precios);

                $modelo['costo_usd']    = $costo;
                $modelo['tiene_precio'] = $costo !== null;

                if ($costo === null) {
                    /* 🔴 Acá se decide el null de la persona. Un solo modelo sin precio alcanza:
                     * el total de la persona pasa a "no sé", no a "la suma de los que sí sé". */
                    $personas[$clave]['tiene_precio_completo'] = false;
                } else {
                    $personas[$clave]['costo_usd'] += $costo;
                }

                $modelos[] = $modelo;
            }

            if (! $personas[$clave]['tiene_precio_completo']) {
                $personas[$clave]['costo_usd'] = null;
            }

            usort($modelos, [self::class, 'comparar_por_costo']);
            $personas[$clave]['modelos'] = $modelos;

            // El centinela se nombra al leer, siempre igual, incluso si el cliente mandó un nombre.
            if ($persona['es_automatico']) {
                $personas[$clave]['nombre'] = self::ETIQUETA_AUTOMATICO;
                continue;
            }

            // Un usuario que el cliente ya borró llega sin nombre: se lo nombra por su id en vez de
            // dejar el renglón en blanco, que se leería como un error de la pantalla.
            if ($personas[$clave]['nombre'] === null) {
                $personas[$clave]['nombre'] = 'Usuario #' . $clave;
            }
        }

        $personas = array_values($personas);

        usort($personas, [self::class, 'comparar_por_costo']);

        return $personas;
    }

    /**
     * Orden "quién gastó más plata": con precio antes que sin precio; entre los que tienen precio,
     * de mayor a menor costo; a igual costo (o sin precio), de mayor a menor en tokens; y a igual
     * tokens, en llamadas.
     *
     * Sirve tanto para las personas como para los modelos de cada persona: las dos listas tienen
     * `costo_usd` (null = sin precio), `tokens` y `llamadas`.
     *
     * Es público solo porque `usort` lo recibe como callable estático; no es parte de la API del
     * modelo.
     *
     * @param array<string, mixed> $a Una fila.
     * @param array<string, mixed> $b Otra fila.
     *
     * @return int
     */
    public static function comparar_por_costo(array $a, array $b)
    {
        $a_tiene = $a['costo_usd'] !== null;
        $b_tiene = $b['costo_usd'] !== null;

        if ($a_tiene !== $b_tiene) {
            return $a_tiene ? -1 : 1;
        }

        if ($a_tiene && (float) $a['costo_usd'] !== (float) $b['costo_usd']) {
            return (float) $b['costo_usd'] > (float) $a['costo_usd'] ? 1 : -1;
        }

        if ((int) $a['tokens'] !== (int) $b['tokens']) {
            return (int) $b['tokens'] - (int) $a['tokens'];
        }

        return (int) $b['llamadas'] - (int) $a['llamadas'];
    }
}
