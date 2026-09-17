<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Consumo de IA de un cliente, abierto por persona y por día.
 *
 * Es el otro corte del mismo hecho que guarda `ClientAiTokenUsage`: aquélla contesta "en qué se
 * gastó", ésta contesta "quién lo gastó". Ver la migración
 * `create_client_ai_token_usage_people_table` para por qué `auth_user_id` es NOT NULL con el
 * centinela 0 y no nullable.
 *
 * 🔴 **Acá no hay costo y no puede haberlo.** El precio depende del modelo y este corte no lo
 * trae, así que todo lo que sale de este modelo son tokens y llamadas. Inventar un costo repartido
 * proporcionalmente sería justo el total que miente que el resto de esta parte se cuidó de evitar.
 *
 * @property int         $client_id                   Cliente dueño del consumo.
 * @property string      $fecha                       Día del consumo (en la zona del comercio).
 * @property int         $auth_user_id                Usuario de la base del cliente; 0 = automático.
 * @property string|null $nombre                      Nombre resuelto por el cliente.
 * @property int         $llamadas                    Llamadas de esa persona ese día.
 * @property int         $input_tokens                Tokens de prompt que no salieron de la caché.
 * @property int         $output_tokens               Tokens generados.
 * @property int         $cache_creation_input_tokens Tokens escritos en la caché de prompt.
 * @property int         $cache_read_input_tokens     Tokens leídos de la caché de prompt.
 */
class ClientAiTokenUsagePerson extends Model
{
    /**
     * Nombre de la tabla. Explícito porque la pluralización automática de Laravel sobre
     * `ClientAiTokenUsagePerson` da `client_ai_token_usage_people` por casualidad y no por diseño:
     * dejarlo librado a eso es una sorpresa esperando a que alguien renombre la clase.
     *
     * @var string
     */
    protected $table = 'client_ai_token_usage_people';

    /**
     * 🔴 Centinela de "procesos automáticos". El `empresa-api` manda `auth_user_id: null` para el
     * gasto que no disparó ninguna persona (el scheduler de embeddings, los informes por comando);
     * acá se guarda como 0 porque un NULL no colisiona consigo mismo en un índice único y esa fila
     * —que es la que más se repite— se apilaría en cada corrida.
     */
    const AUTOMATICO = 0;

    /**
     * Etiqueta con la que se muestra el centinela. Vive acá y no en el controlador para que las dos
     * puntas que lean esta tabla digan lo mismo.
     */
    const ETIQUETA_AUTOMATICO = 'Procesos automáticos';

    /**
     * Los cuatro contadores que se suman. Mismo orden y mismos nombres que en `ClientAiTokenUsage`.
     *
     * @var array<int, string>
     */
    const CONTADORES = [
        'input_tokens',
        'output_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
    ];

    /**
     * Todas las filas las escribe la recolección, nunca un request de usuario.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Los contadores viajan como enteros: se suman y se comparan.
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
     * El consumo de un cliente en un rango, plegado por persona (sin abrir por día) y ordenado de
     * mayor a menor.
     *
     * Se pliega en PHP y no en la base porque hay que elegir un `nombre` entre los de los días del
     * rango, y "el último que informó el cliente" no es una función de agregación: un `MAX()` sobre
     * el texto devolvería el alfabéticamente mayor, que no tiene nada que ver.
     *
     * El volumen lo permite de sobra: son los empleados de un comercio por los días del rango.
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

        /** @var array<int, array<string, mixed>> Personas en construcción, por auth_user_id. */
        $personas = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->auth_user_id;

            if (! isset($personas[$clave])) {
                $personas[$clave] = [
                    'auth_user_id'  => $clave === self::AUTOMATICO ? null : $clave,
                    'es_automatico' => $clave === self::AUTOMATICO,
                    'nombre'        => null,
                    'llamadas'      => 0,
                    'tokens'        => 0,
                ];

                foreach (self::CONTADORES as $columna) {
                    $personas[$clave][$columna] = 0;
                }
            }

            $nombre = trim((string) $fila->nombre);
            if ($nombre !== '') {
                $personas[$clave]['nombre'] = $nombre;
            }

            $personas[$clave]['llamadas'] += (int) $fila->llamadas;

            foreach (self::CONTADORES as $columna) {
                $cantidad                      = (int) $fila->{$columna};
                $personas[$clave][$columna]   += $cantidad;
                $personas[$clave]['tokens']   += $cantidad;
            }
        }

        foreach ($personas as $clave => $persona) {
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

        // De mayor a menor consumo: es el orden en el que se mira "quién gastó".
        usort($personas, function ($a, $b) {
            if ((int) $a['tokens'] === (int) $b['tokens']) {
                return (int) $b['llamadas'] - (int) $a['llamadas'];
            }

            return (int) $b['tokens'] - (int) $a['tokens'];
        });

        return $personas;
    }
}
