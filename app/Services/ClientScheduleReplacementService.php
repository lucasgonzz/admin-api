<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validación y reemplazo atómico del conjunto de horarios comerciales de un cliente.
 *
 * 🔴 POR QUÉ EXISTE ESTE SERVICIO. Este cuerpo nació adentro de `ClientScheduleController` como
 * dos métodos privados (`validar_dias()` y el bloque `DB::transaction()` de `update_json()`).
 * Cuando apareció el segundo consumidor —`PUT claude/clients/{id}/schedule`, para que Claude pueda
 * cargar horarios sin sesión de admin— la alternativa era espejarlo, y espejarlo sembraba la clase
 * de error que este repo ya tiene documentada: *el mismo invariante decidido con dos criterios
 * distintos*. Las seis reglas de validación de abajo NO son validación de formulario, son la
 * definición del modelo de horarios; el día que cambie una (que un rango pueda cruzar la
 * medianoche, por ejemplo) tiene que cambiar en un solo lugar o no cambia.
 *
 * Es la misma postura que se tomó con `ClientVersionUpgradeCreationService`: se extrae y lo llaman
 * los dos, no se espeja.
 *
 * 🔴 LO QUE ESTE SERVICIO NO SABE: los frenos de `claude/*` (`dry_run`, `confirm_client_name`) NO
 * viven acá. Son del controlador de Claude y solo de él: metidos acá estarían también en el camino
 * de la SPA, que no los quiere y no los pidió.
 *
 * La separación entre `validar()` y `reemplazar()` no es estética: es lo que hace posible el
 * `dry_run` del endpoint de Claude —validar el payload entero y contestar sin escribir una fila— y
 * es lo mismo que ya garantizaba, en el camino de la SPA, que un 422 no dejara al cliente con la
 * mitad vieja y la mitad nueva.
 */
class ClientScheduleReplacementService
{
    /**
     * Formato aceptado para una hora: 'H:i' de 00:00 a 23:59.
     *
     * 🔴 Única definición. `ClientScheduleController` la tenía como const propia y ahora la lee de
     * acá: dos regex de hora que se separan es un cliente que guarda desde la SPA lo que la API de
     * Claude rechaza.
     *
     * @var string
     */
    const REGEX_HORA = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * Valida el payload entero y lo devuelve normalizado, SIN tocar la base.
     *
     * Las seis validaciones, todas 422 y todas antes de escribir:
     *
     *  1. `dias` es array (vacío es válido: borra todo).
     *  2. `dia` pertenece a ClientScheduleDay::DAY_KEYS y no se repite.
     *  3. `rangos` es array (vacío es válido: ese día el negocio está cerrado).
     *  4. `desde` y `hasta` en formato 'H:i'.
     *  5. `hasta` estrictamente mayor que `desde` (un rango no cruza la medianoche).
     *  6. Dos rangos del mismo día no se solapan.
     *
     * @param array<string, mixed> $entrada Cuerpo crudo del request (`$request->all()`).
     *
     * @return array<int, array> Días normalizados, con los rangos ordenados por hora de apertura.
     *
     * @throws ValidationException
     */
    public function validar(array $entrada)
    {
        /* Se valida sobre el cuerpo COMPLETO y no sobre `$entrada['dias']` a propósito: así
           `present` distingue "no mandaron la clave" de "la mandaron vacía", que es exactamente la
           distinción que hacía `$request->validate()` cuando esto vivía en el controlador. */
        Validator::make($entrada, [
            'dias'                  => ['present', 'array'],
            'dias.*.dia'            => ['required', 'string', 'in:' . implode(',', ClientScheduleDay::DAY_KEYS)],
            'dias.*.rangos'         => ['present', 'array'],
            'dias.*.rangos.*.desde' => ['required', 'string', 'regex:' . self::REGEX_HORA],
            'dias.*.rangos.*.hasta' => ['required', 'string', 'regex:' . self::REGEX_HORA],
        ])->validate();

        $dias_crudos = isset($entrada['dias']) && is_array($entrada['dias']) ? $entrada['dias'] : [];

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
     * Reemplaza el conjunto entero de horarios del cliente, dentro de una transacción.
     *
     * 🔴 Es un REEMPLAZO ATÓMICO, no un diff: se borran todos los días del cliente y se recrean
     * desde el payload. Los dos consumidores mandan siempre el conjunto completo en un solo
     * guardado, así que un diff por uuid sería mucho más código para cero beneficio. Se pierden los
     * id/uuid de días y rangos y es aceptable a propósito: hoy nada en el sistema referencia a un
     * día ni a un rango. Si mañana algo lo hace, esta decisión hay que releerla ANTES de tocar nada.
     *
     * ⚠️ Recibe los días YA validados por `validar()`. No revalida: llamarlo con datos crudos es un
     * error de programación, no un caso de uso.
     *
     * @param Client            $client Cliente dueño de los horarios.
     * @param array<int, array> $dias   Días normalizados que devolvió `validar()`.
     *
     * @return void
     */
    public function reemplazar(Client $client, array $dias)
    {
        DB::transaction(function () use ($client, $dias) {
            // La FK con onDelete cascade se lleva los rangos de cada día borrado.
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
    }

    /**
     * Valida y ordena los rangos de un día.
     *
     * @param array  $rangos_crudos Rangos tal como vinieron en el body.
     * @param string $day_key       Clave del día, para el mensaje de error.
     * @param int    $indice        Índice del día en el array, para el campo del error.
     *
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
}
