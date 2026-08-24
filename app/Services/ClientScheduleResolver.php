<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientScheduleDay;
use Carbon\Carbon;

/**
 * Resuelve el horario comercial de un cliente para una fecha o un instante.
 *
 * Es la pieza que le permite a Claude decidir cuándo puede arrancar las tareas post-cierre de una
 * actualización. Vive SOLO en el back: la SPA no reimplementa la resolución, la pide por API.
 *
 * 🔴 Los estados son TRES, no dos:
 *
 *  - `con_horario` / `abierto` → hay rangos que rigen (y, para un instante, el momento cae adentro).
 *  - `cerrado`                 → hay configuración que aplica y dice que no está abierto.
 *  - `sin_configurar`          → no hay fila del día puntual NI fila 'todos'.
 *
 * Confundir `sin_configurar` con `cerrado` haría que se arranque el post-cierre sobre un negocio
 * abierto y con gente adentro del sistema. Ningún método colapsa los tres estados a un booleano.
 *
 * Regla de precedencia, literal como la dictó Lucas: la fila 'todos' actúa en nombre de todos los
 * días, SALVO los días que tengan su propia fila, que la pisan. La fila del día puntual gana
 * siempre, incluso si está vacía: esa es la forma de decir "el martes cerramos".
 */
class ClientScheduleResolver
{
    /** Hay rangos cargados para esa fecha (estado a nivel día, no a nivel instante). */
    const ESTADO_CON_HORARIO = 'con_horario';

    /** El instante cae adentro de algún rango vigente. */
    const ESTADO_ABIERTO = 'abierto';

    /** Hay configuración que aplica y dice que el negocio no está abierto. */
    const ESTADO_CERRADO = 'cerrado';

    /** No hay fila del día puntual ni fila 'todos'. NO es lo mismo que cerrado. */
    const ESTADO_SIN_CONFIGURAR = 'sin_configurar';

    /** El día tiene su propia fila y esa fila es la que rige. */
    const ORIGEN_DIA_PROPIO = 'dia_propio';

    /** El día no tiene fila propia y hereda de la fila 'todos'. */
    const ORIGEN_TODOS_LOS_DIAS = 'todos_los_dias';

    /** Ni fila propia ni fila 'todos'. */
    const ORIGEN_SIN_CONFIGURAR = 'sin_configurar';

    /** Apareció un día sin configurar en la ventana: se corta y no se adivina. */
    const MOTIVO_SIN_CONFIGURAR = 'sin_configurar';

    /** Se agotó la ventana sin encontrar ningún día con rangos. */
    const MOTIVO_SIN_HORARIOS_EN_LA_VENTANA = 'sin_horarios_en_la_ventana';

    /**
     * Resuelve qué horario rige para una fecha concreta.
     *
     * Estructura devuelta:
     *
     *   [
     *     'fecha'          => '2026-08-25',
     *     'dia'            => 'martes',
     *     'dia_label'      => 'Martes',
     *     'origen'         => 'dia_propio' | 'todos_los_dias' | 'sin_configurar',
     *     'estado'         => 'con_horario' | 'cerrado' | 'sin_configurar',
     *     'rangos'         => [ ['desde' => '08:00', 'hasta' => '13:00'] ],
     *     'cierre_del_dia' => '13:00' | null,
     *     'timezone'       => 'America/Argentina/Buenos_Aires',
     *   ]
     *
     * @param Client      $client   Cliente dueño de los horarios.
     * @param Carbon      $fecha    Fecha a resolver (se lee en el timezone pedido).
     * @param string|null $timezone Timezone; por defecto config('app.timezone').
     *
     * @return array
     */
    public function resolve_for_date(Client $client, Carbon $fecha, $timezone = null)
    {
        $tz          = $this->resolver_timezone($timezone);
        $fecha_local = $fecha->copy()->setTimezone($tz);
        $day_key     = ClientScheduleDay::DAY_KEYS_BY_DOW[$fecha_local->dayOfWeek];

        $this->cargar_horarios($client);

        // La fila del día puntual gana siempre; recién si no existe se mira la fila 'todos'.
        $origen = self::ORIGEN_DIA_PROPIO;
        $dia    = $this->buscar_dia($client, $day_key);

        if ($dia === null) {
            $dia    = $this->buscar_dia($client, 'todos');
            $origen = $dia === null ? self::ORIGEN_SIN_CONFIGURAR : self::ORIGEN_TODOS_LOS_DIAS;
        }

        $base = [
            'fecha'     => $fecha_local->toDateString(),
            'dia'       => $day_key,
            'dia_label' => ClientScheduleDay::label_for($day_key),
            'timezone'  => $tz,
        ];

        if ($dia === null) {
            // 🔴 No hay NINGUNA configuración que aplique. No se asume que esté cerrado.
            return array_merge($base, [
                'origen'         => self::ORIGEN_SIN_CONFIGURAR,
                'estado'         => self::ESTADO_SIN_CONFIGURAR,
                'rangos'         => [],
                'cierre_del_dia' => null,
            ]);
        }

        $rangos = $this->rangos_de($dia);

        return array_merge($base, [
            'origen'         => $origen,
            'estado'         => count($rangos) === 0 ? self::ESTADO_CERRADO : self::ESTADO_CON_HORARIO,
            'rangos'         => $rangos,
            'cierre_del_dia' => $this->cierre_de($rangos),
        ]);
    }

    /**
     * Estado del negocio en un instante puntual.
     *
     * Un rango se considera abierto en [desde, hasta): a la hora exacta del cierre el negocio ya
     * está cerrado, que es justo lo que necesita el gate del post-cierre.
     *
     * @param Client      $client   Cliente dueño de los horarios.
     * @param Carbon      $momento  Instante a evaluar.
     * @param string|null $timezone Timezone; por defecto config('app.timezone').
     *
     * @return string 'abierto' | 'cerrado' | 'sin_configurar'
     */
    public function estado_en(Client $client, Carbon $momento, $timezone = null)
    {
        $tz            = $this->resolver_timezone($timezone);
        $momento_local = $momento->copy()->setTimezone($tz);
        $resuelto      = $this->resolve_for_date($client, $momento_local, $tz);

        if ($resuelto['estado'] === self::ESTADO_SIN_CONFIGURAR) {
            return self::ESTADO_SIN_CONFIGURAR;
        }

        $hora = $momento_local->format('H:i');

        foreach ($resuelto['rangos'] as $rango) {
            if ($hora >= $rango['desde'] && $hora < $rango['hasta']) {
                return self::ESTADO_ABIERTO;
            }
        }

        return self::ESTADO_CERRADO;
    }

    /**
     * Resuelve una ventana de días consecutivos.
     *
     * Los horarios se cargan UNA sola vez y se resuelve en memoria: siete días no pueden costar
     * siete consultas.
     *
     * @param Client      $client        Cliente dueño de los horarios.
     * @param Carbon      $desde         Primer día de la ventana.
     * @param int         $cantidad_dias Cantidad de días a resolver.
     * @param string|null $timezone      Timezone; por defecto config('app.timezone').
     *
     * @return array<int, array> Una entrada de resolve_for_date() por día.
     */
    public function resolve_dias(Client $client, Carbon $desde, $cantidad_dias = 7, $timezone = null)
    {
        $tz            = $this->resolver_timezone($timezone);
        $cantidad_dias = (int) $cantidad_dias;

        if ($cantidad_dias < 1) {
            $cantidad_dias = 1;
        }

        $this->cargar_horarios($client);

        $inicio = $desde->copy()->setTimezone($tz)->startOfDay();
        $dias   = [];

        for ($i = 0; $i < $cantidad_dias; $i++) {
            $dias[] = $this->resolve_for_date($client, $inicio->copy()->addDays($i), $tz);
        }

        return $dias;
    }

    /**
     * Instante del PRÓXIMO CIERRE DEL DÍA, o null si no se puede determinar.
     *
     * 🔴 Cierre del día = el `hasta` MAYOR de todos los rangos de ese día, NO el fin del rango
     * vigente. Un negocio 8:00–13:00 / 16:00–21:00 cierra a las 21:00: a las 13:00 reabre, y
     * arrancar el post-cierre ahí lo correría con el cliente trabajando a las 16.
     *
     * @param Client      $client        Cliente dueño de los horarios.
     * @param Carbon      $desde         Instante desde el que se busca.
     * @param int         $dias_ventana  Cantidad de días hacia adelante que se recorren.
     * @param string|null $timezone      Timezone; por defecto config('app.timezone').
     *
     * @return Carbon|null
     */
    public function proximo_cierre(Client $client, Carbon $desde, $dias_ventana = 7, $timezone = null)
    {
        $detalle = $this->proximo_cierre_detallado($client, $desde, $dias_ventana, $timezone);

        return $detalle['instante'];
    }

    /**
     * Igual que proximo_cierre(), pero devolviendo también POR QUÉ no hay instante.
     *
     * Un null sin motivo es una respuesta que el consumidor no puede interpretar: no es lo mismo
     * "hay un día sin configurar en la ventana y me corto antes de adivinar" que "el cliente está
     * cerrado toda la ventana".
     *
     * @param Client      $client       Cliente dueño de los horarios.
     * @param Carbon      $desde        Instante desde el que se busca.
     * @param int         $dias_ventana Cantidad de días hacia adelante que se recorren.
     * @param string|null $timezone     Timezone; por defecto config('app.timezone').
     *
     * @return array ['instante' => Carbon|null, 'motivo' => string|null]
     */
    public function proximo_cierre_detallado(Client $client, Carbon $desde, $dias_ventana = 7, $timezone = null)
    {
        $tz           = $this->resolver_timezone($timezone);
        $dias_ventana = (int) $dias_ventana;

        if ($dias_ventana < 1) {
            $dias_ventana = 1;
        }

        $this->cargar_horarios($client);

        $momento = $desde->copy()->setTimezone($tz);
        $inicio  = $momento->copy()->startOfDay();

        for ($i = 0; $i < $dias_ventana; $i++) {
            $fecha    = $inicio->copy()->addDays($i);
            $resuelto = $this->resolve_for_date($client, $fecha, $tz);

            // 🔴 Un día sin configurar CORTA la búsqueda: no se saltea ni se adivina.
            if ($resuelto['estado'] === self::ESTADO_SIN_CONFIGURAR) {
                return ['instante' => null, 'motivo' => self::MOTIVO_SIN_CONFIGURAR];
            }

            // Un día cerrado (fila propia, cero rangos) no corta: es información, se saltea.
            if ($resuelto['cierre_del_dia'] === null) {
                continue;
            }

            $instante = $fecha->copy()->setTimeFromTimeString($resuelto['cierre_del_dia'] . ':00');

            if ($instante->greaterThan($momento)) {
                return ['instante' => $instante, 'motivo' => null];
            }
        }

        return ['instante' => null, 'motivo' => self::MOTIVO_SIN_HORARIOS_EN_LA_VENTANA];
    }

    /**
     * Carga los días y sus rangos una sola vez por instancia de cliente.
     *
     * @param Client $client Cliente dueño de los horarios.
     *
     * @return void
     */
    private function cargar_horarios(Client $client)
    {
        $client->loadMissing('schedule_days.schedule_ranges');
    }

    /**
     * Busca la fila de un día entre las ya cargadas en memoria (sin pegarle a la base).
     *
     * @param Client $client  Cliente dueño de los horarios.
     * @param string $day_key Clave de día a buscar.
     *
     * @return ClientScheduleDay|null
     */
    private function buscar_dia(Client $client, $day_key)
    {
        foreach ($client->schedule_days as $dia) {
            if ((string) $dia->day_key === (string) $day_key) {
                return $dia;
            }
        }

        return null;
    }

    /**
     * Rangos de un día, normalizados a 'H:i' y ordenados por hora de apertura.
     *
     * @param ClientScheduleDay $dia Día cargado con sus rangos.
     *
     * @return array<int, array<string, string>>
     */
    private function rangos_de(ClientScheduleDay $dia)
    {
        $rangos = [];

        foreach ($dia->schedule_ranges as $rango) {
            $desde = $this->formatear_hora($rango->start_time);
            $hasta = $this->formatear_hora($rango->end_time);

            if ($desde === null || $hasta === null) {
                continue;
            }

            $rangos[] = ['desde' => $desde, 'hasta' => $hasta];
        }

        usort($rangos, function ($a, $b) {
            return strcmp($a['desde'], $b['desde']);
        });

        return $rangos;
    }

    /**
     * Cierre del día: el 'hasta' mayor de todos los rangos.
     *
     * @param array<int, array<string, string>> $rangos Rangos ya normalizados.
     *
     * @return string|null 'HH:MM' o null si el día no tiene rangos.
     */
    private function cierre_de(array $rangos)
    {
        $cierre = null;

        foreach ($rangos as $rango) {
            if ($cierre === null || strcmp($rango['hasta'], $cierre) > 0) {
                $cierre = $rango['hasta'];
            }
        }

        return $cierre;
    }

    /**
     * Normaliza una hora de la columna `time` ('09:00:00') al formato 'H:i' ('09:00').
     *
     * @param mixed $valor Valor crudo de la columna.
     *
     * @return string|null
     */
    private function formatear_hora($valor)
    {
        if ($valor instanceof Carbon) {
            return $valor->format('H:i');
        }

        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        $partes = explode(':', $valor);
        $hora   = isset($partes[0]) ? (int) $partes[0] : 0;
        $minuto = isset($partes[1]) ? (int) $partes[1] : 0;

        return sprintf('%02d:%02d', $hora, $minuto);
    }

    /**
     * Timezone efectivo. Toda respuesta que involucre horarios devuelve el que usó, explícito:
     * una hora sin zona declarada es discutible, y acá una hora mal interpretada arranca un
     * deployment sobre un negocio abierto.
     *
     * @param string|null $timezone Timezone pedido.
     *
     * @return string
     */
    private function resolver_timezone($timezone)
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            $timezone = trim((string) config('app.timezone'));
        }

        return $timezone === '' ? 'UTC' : $timezone;
    }
}
