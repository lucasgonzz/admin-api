<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientScheduleJob;
use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Services\ClientScheduleResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * API JSON (Sanctum) de los horarios comerciales de un cliente, para la pestaña "Horarios" del
 * modal del cliente en admin-spa.
 *
 * Molde: ClientMensualidadController (GET/PUT client/{clientId}/mensualidad).
 *
 * Dos ideas mandan todo este controlador:
 *
 *  1. 🔴 El PUT es un REEMPLAZO ATÓMICO del conjunto entero: se borran todos los días del cliente
 *     y se recrean desde el payload, dentro de una transacción. La UI manda siempre el conjunto
 *     completo en un solo guardado, así que un diff por uuid sería mucho más código para cero
 *     beneficio. Se pierden los id/uuid de días y rangos y es aceptable a propósito: hoy nada en
 *     el sistema referencia a un día ni a un rango. Si mañana algo lo hace, esta decisión hay que
 *     releerla ANTES de tocar nada.
 *  2. 🔴 Toda validación que falla devuelve 422 SIN escribir ni una fila. Se valida el payload
 *     entero antes de abrir la transacción: nada de borrar primero y descubrir el error después.
 *
 * La enumeración de días (`day_keys`) y la resolución de la semana viajan desde el back para que
 * la SPA no reimplemente ni la lista ni la regla de precedencia de "Todos los días".
 */
class ClientScheduleController extends Controller
{
    /** Días que se resuelven y viajan en la respuesta, para que la UI muestre "cómo queda la semana". */
    const DIAS_RESUELTOS = 7;

    /** Formato aceptado para una hora: 'H:i' de 00:00 a 23:59. */
    const REGEX_HORA = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * Horarios cargados del cliente + la enumeración de días + la semana ya resuelta.
     *
     * @param  int|string             $clientId Id numérico o uuid del cliente.
     * @param  ClientScheduleResolver $resolver Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($clientId, ClientScheduleResolver $resolver)
    {
        $client = $this->find_client_by_route_id($clientId);

        return response()->json($this->armar_payload($client, $resolver));
    }

    /**
     * Reemplaza el conjunto entero de horarios del cliente y devuelve el mismo payload que
     * show_json(), ya releído de la base.
     *
     * @param  Request                $request
     * @param  int|string             $clientId Id numérico o uuid del cliente.
     * @param  ClientScheduleResolver $resolver Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, ClientScheduleResolver $resolver)
    {
        $client = $this->find_client_by_route_id($clientId);

        // 🔴 Primero se valida TODO el payload. Si algo está mal, se sale con 422 antes de tocar
        // una sola fila: el guardado no puede dejar al cliente con la mitad vieja y la mitad nueva.
        $dias = $this->validar_dias($request);

        DB::transaction(function () use ($client, $dias) {
            // Reemplazo atómico: se borran todos los días del cliente (la FK con onDelete cascade
            // se lleva sus rangos) y se recrea el conjunto desde el payload.
            ClientScheduleDay::where('client_id', $client->id)->delete();

            foreach ($dias as $dia) {
                $fila            = new ClientScheduleDay();
                $fila->client_id = $client->id;
                $fila->day_key   = $dia['dia'];
                $fila->save();

                // sort_order se asigna por hora de apertura ascendente (los rangos ya vienen
                // ordenados de la validación): es orden de presentación, no dato del usuario.
                $orden = 0;
                foreach ($dia['rangos'] as $rango) {
                    $fila_rango                         = new ClientScheduleRange();
                    $fila_rango->client_schedule_day_id = $fila->id;
                    $fila_rango->start_time             = $rango['desde'];
                    $fila_rango->end_time               = $rango['hasta'];
                    $fila_rango->sort_order             = $orden;
                    $fila_rango->save();
                    $orden++;
                }
            }
        });

        /* Los horarios ya quedaron guardados: recién ahora se avisa al empresa-api del cliente, y se
         * hace ENCOLANDO.
         *
         * 🔴 `->onConnection('database')` explícito, no decorativo: con QUEUE_CONNECTION=sync un
         * dispatch pelado correría el push HTTP adentro de este request, y con timeout 15 s y dos
         * reintentos le sumaría hasta ~45 segundos de espera al modal del admin por un efecto
         * secundario que a quien está guardando no le importa en este momento.
         *
         * Va DESPUÉS de la transacción a propósito: el job lee el cliente de la base cuando corre,
         * así que despacharlo adentro sería empujar un estado que todavía puede hacer rollback. */
        SyncClientScheduleJob::dispatch($client->id)->onConnection('database');

        return response()->json($this->armar_payload($client, $resolver));
    }

    /**
     * Reintento a mano de la sincronización de horarios al empresa-api del cliente, para el botón
     * "Reintentar sincronización" de la pestaña.
     *
     * Es idempotente: reenvía el estado actual de los horarios, no acumula nada del lado del
     * cliente. Por eso no lleva ni `dry_run` ni `confirm_client_name`, a diferencia del resto de la
     * escritura de los endpoints de operación: lo único que hace es empujarle a la propia API del
     * cliente un dato que el admin ya tiene.
     *
     * Devuelve 202 y el estado PERSISTIDO al momento de encolar (que todavía es el del intento
     * anterior): el push corre en el worker, así que el estado nuevo se ve consultando de nuevo.
     *
     * @param  int|string $clientId Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync_json($clientId)
    {
        $client = $this->find_client_by_route_id($clientId);

        // 🔴 Misma conexión explícita que en update_json(): nunca HTTP adentro del request.
        SyncClientScheduleJob::dispatch($client->id)->onConnection('database');

        return response()->json([
            'encolado'       => true,
            'conexion'       => 'database',
            'client_id'      => (int) $client->id,
            'sincronizacion' => $this->estado_de_sincronizacion($client),
            'nota'           => 'El push corre en el worker `queue:work database` que el scheduler '
                . 'dispara cada minuto. El estado que viaja acá todavía es el del intento anterior: '
                . 'volvé a pedir GET admin/client/{clientId}/horarios para ver el resultado.',
        ], 202);
    }

    /**
     * Valida el body del PUT y lo devuelve normalizado.
     *
     * Las seis validaciones del plan, todas 422 y todas antes de escribir:
     *
     *  1. `dias` es array (vacío es válido: borra todo).
     *  2. `dia` pertenece a ClientScheduleDay::DAY_KEYS y no se repite.
     *  3. `rangos` es array (vacío es válido: ese día el negocio está cerrado).
     *  4. `desde` y `hasta` en formato 'H:i'.
     *  5. `hasta` estrictamente mayor que `desde` (un rango no cruza la medianoche).
     *  6. Dos rangos del mismo día no se solapan.
     *
     * @param  Request $request Request del PUT.
     * @return array<int, array> Días normalizados, con los rangos ordenados por hora de apertura.
     *
     * @throws ValidationException
     */
    private function validar_dias(Request $request)
    {
        // Estructura y formato. `present` en vez de `required` porque un array vacío es un valor
        // válido acá (borrar todo / día cerrado) y `required` lo rechazaría.
        $request->validate([
            'dias'                 => ['present', 'array'],
            'dias.*.dia'           => ['required', 'string', 'in:' . implode(',', ClientScheduleDay::DAY_KEYS)],
            'dias.*.rangos'        => ['present', 'array'],
            'dias.*.rangos.*.desde' => ['required', 'string', 'regex:' . self::REGEX_HORA],
            'dias.*.rangos.*.hasta' => ['required', 'string', 'regex:' . self::REGEX_HORA],
        ]);

        $dias_crudos = $request->input('dias', []);
        $normalizados = [];
        $vistos       = [];

        foreach ($dias_crudos as $indice => $dia_crudo) {
            $day_key = (string) $dia_crudo['dia'];

            // Un día no puede venir dos veces: el unique (client_id, day_key) lo rechazaría con un
            // error de base, y un error de base no es una respuesta que la UI pueda mostrar.
            if (in_array($day_key, $vistos, true)) {
                throw ValidationException::withMessages([
                    'dias.' . $indice . '.dia' => 'El día "' . ClientScheduleDay::label_for($day_key) . '" está cargado más de una vez.',
                ]);
            }

            $vistos[] = $day_key;

            $normalizados[] = [
                'dia'    => $day_key,
                'rangos' => $this->validar_rangos_de_un_dia($dia_crudo['rangos'], $day_key, $indice),
            ];
        }

        return $normalizados;
    }

    /**
     * Valida y ordena los rangos de un día.
     *
     * @param  array  $rangos_crudos Rangos tal como vinieron en el body.
     * @param  string $day_key       Clave del día, para el mensaje de error.
     * @param  int    $indice        Índice del día en el array, para el campo del error.
     * @return array<int, array<string, string>> Rangos normalizados y ordenados por 'desde'.
     *
     * @throws ValidationException
     */
    private function validar_rangos_de_un_dia($rangos_crudos, $day_key, $indice)
    {
        $rangos = [];

        foreach ($rangos_crudos as $rango_crudo) {
            $rangos[] = [
                'desde' => (string) $rango_crudo['desde'],
                'hasta' => (string) $rango_crudo['hasta'],
            ];
        }

        // Con el formato 'H:i' garantizado por el regex, comparar como strings es comparar horas.
        usort($rangos, function ($a, $b) {
            return strcmp($a['desde'], $b['desde']);
        });

        $anterior = null;

        foreach ($rangos as $rango) {
            // 🔴 Un rango no cruza la medianoche: se exige hasta > desde estricto. Un negocio que
            // cierra a medianoche o después se carga con 23:59 (limitación declarada del modelo).
            if (strcmp($rango['hasta'], $rango['desde']) <= 0) {
                throw ValidationException::withMessages([
                    'dias.' . $indice . '.rangos' => 'En "' . ClientScheduleDay::label_for($day_key) . '", la hora de cierre (' . $rango['hasta'] . ') tiene que ser posterior a la de apertura (' . $rango['desde'] . ').',
                ]);
            }

            // Ya ordenados por apertura: si este empieza antes de que termine el anterior, se pisan.
            // Dos rangos que se tocan (13:00–16:00 después de 09:00–13:00) NO se solapan.
            if ($anterior !== null && strcmp($rango['desde'], $anterior['hasta']) < 0) {
                throw ValidationException::withMessages([
                    'dias.' . $indice . '.rangos' => 'En "' . ClientScheduleDay::label_for($day_key) . '", los rangos ' . $anterior['desde'] . '–' . $anterior['hasta'] . ' y ' . $rango['desde'] . '–' . $rango['hasta'] . ' se solapan.',
                ]);
            }

            $anterior = $rango;
        }

        return $rangos;
    }

    /**
     * Payload común de show_json() y de la respuesta del PUT.
     *
     * `day_keys` viaja desde el back para que la SPA no hardcodee la enumeración de días, y
     * `resueltos_proximos_7_dias` para que no reimplemente la regla de precedencia de
     * "Todos los días": las dos cosas se deciden en un solo lugar, que es acá.
     *
     * @param  Client                 $client   Cliente dueño de los horarios.
     * @param  ClientScheduleResolver $resolver Resolvedor de la regla de precedencia.
     * @return array
     */
    private function armar_payload(Client $client, ClientScheduleResolver $resolver)
    {
        $timezone = $this->timezone();

        // load() y no loadMissing(): después del PUT la relación en memoria está vieja.
        $client->load('schedule_days.schedule_ranges');

        return [
            'timezone'                  => $timezone,
            'day_keys'                  => ClientScheduleDay::day_keys_payload(),
            'dias'                      => $this->dias_cargados($client),
            'resueltos_proximos_7_dias' => $resolver->resolve_dias($client, Carbon::now($timezone), self::DIAS_RESUELTOS, $timezone),
            'sincronizacion'            => $this->estado_de_sincronizacion($client),
        ];
    }

    /**
     * Estado del último push de los horarios al empresa-api del cliente, para que la pestaña pueda
     * mostrar "Sincronizado el …" o el motivo del fallo sin volver a pegarle a la API del cliente.
     *
     * Las tres columnas en null significan "nunca se intentó", que NO es lo mismo que un fallo.
     *
     * @param  Client $client Cliente dueño de los horarios.
     * @return array
     */
    private function estado_de_sincronizacion(Client $client)
    {
        return [
            'estado'      => $client->schedule_sync_status === null ? null : (string) $client->schedule_sync_status,
            'mensaje'     => $client->schedule_sync_message === null ? null : (string) $client->schedule_sync_message,
            'sincronizado_at' => $client->schedule_synced_at === null
                ? null
                : (string) $client->schedule_synced_at,
        ];
    }

    /**
     * Los días cargados del cliente, tal cual están en la base (sin resolver precedencia), en el
     * orden de presentación de DAY_KEYS.
     *
     * @param  Client $client Cliente con `schedule_days.schedule_ranges` ya cargados.
     * @return array<int, array>
     */
    private function dias_cargados(Client $client)
    {
        $dias = [];

        foreach (ClientScheduleDay::DAY_KEYS as $day_key) {
            foreach ($client->schedule_days as $fila) {
                if ((string) $fila->day_key !== $day_key) {
                    continue;
                }

                $rangos = [];

                foreach ($fila->schedule_ranges as $rango) {
                    $rangos[] = [
                        'desde' => $this->formatear_hora($rango->start_time),
                        'hasta' => $this->formatear_hora($rango->end_time),
                    ];
                }

                $dias[] = [
                    'dia'       => $day_key,
                    'dia_label' => ClientScheduleDay::label_for($day_key),
                    // Cero rangos NO es un error ni un faltante: es "ese día el negocio está cerrado".
                    'rangos'    => $rangos,
                ];
            }
        }

        return $dias;
    }

    /**
     * Normaliza una hora de la columna `time` ('09:00:00') al 'H:i' que viaja por API.
     *
     * @param  mixed $valor Valor crudo de la columna.
     * @return string
     */
    private function formatear_hora($valor)
    {
        if ($valor instanceof Carbon) {
            return $valor->format('H:i');
        }

        $partes = explode(':', trim((string) $valor));
        $hora   = isset($partes[0]) ? (int) $partes[0] : 0;
        $minuto = isset($partes[1]) ? (int) $partes[1] : 0;

        return sprintf('%02d:%02d', $hora, $minuto);
    }

    /**
     * Timezone del comercio. Todos los clientes son argentinos y hoy no hay columna por cliente:
     * se usa el de la app, pero SIEMPRE viaja explícito en la respuesta, porque una hora sin zona
     * declarada es discutible.
     *
     * @return string
     */
    private function timezone()
    {
        $timezone = trim((string) config('app.timezone'));

        return $timezone === '' ? 'UTC' : $timezone;
    }

    /**
     * Busca el Client por id numérico o uuid (mismo criterio que DeploymentController y
     * ClientEmployeeController).
     *
     * @param  int|string $route_id Id numérico o uuid.
     * @return Client
     */
    private function find_client_by_route_id($route_id)
    {
        if (is_numeric($route_id)) {
            return Client::findOrFail((int) $route_id);
        }

        return Client::where('uuid', (string) $route_id)->firstOrFail();
    }
}
