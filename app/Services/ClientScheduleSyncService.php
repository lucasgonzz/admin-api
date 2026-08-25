<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientScheduleDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Empuja los horarios comerciales de un cliente al empresa-api de ese cliente
 * (`PUT {base_url}/api/admin-sync/business-hours`), para que el agente de WhatsApp de empresa pueda
 * contestar "¿a qué hora abren?" y recordar el horario de cierre cuando corresponda.
 *
 * 🔴 CUATRO REGLAS QUE GOBIERNAN ESTE SERVICIO:
 *
 *  1. **La semana viaja YA RESUELTA, calculada acá con `ClientScheduleResolver`.** Los siete días,
 *     con la regla de "Todos los días" y el override del día puntual ya aplicados. `empresa-api`
 *     NO reimplementa la resolución: solo lee. Si las dos puntas resolvieran el mismo invariante
 *     por su cuenta, el día que se agregue un caso (feriados, medio día) quedarían dos criterios y
 *     uno se olvidaría. `dias_crudos` viaja solo como comodidad de lectura ("los sábados hasta las
 *     13") y NO es fuente de verdad.
 *
 *  2. **"No hay dato" NUNCA viaja como "cerrado".** Es el tercer estado del resolvedor cruzando el
 *     cable, y viaja en DOS niveles: un cliente sin ningún día cargado va con `configurado: false`
 *     y `semana: []`; y adentro de la semana, un día sin configurar va con **`abierto: null`** (no
 *     `false`) y `estado: 'sin_configurar'`. El agente de WhatsApp no puede afirmar que el negocio
 *     está cerrado en ninguno de los dos casos: no sabe. Confundirlos le diría a un comprador que
 *     el comercio está cerrado un martes a las 10.
 *
 *  3. **Nunca se propaga una excepción.** Cuando esto corre, el guardado de horarios en el admin ya
 *     terminó y fue exitoso: un empresa-api caído no puede convertirse en un error del admin. Los
 *     tres cortes previos y todos los desenlaces del HTTP escriben su motivo en las columnas
 *     `schedule_sync_*` del cliente y devuelven, punto.
 *
 *  4. **Compatibilidad hacia atrás.** Hoy el `empresa-api` de los clientes NO tiene esta ruta: el
 *     404 es el caso ESPERADO y se degrada a `manual_required`, exactamente como
 *     `DeploymentService::step_update_default_version()` con `admin-sync/update-default-version`.
 *     El día que salga la mitad de empresa, el mismo push empieza a devolver 200 sin tocar una
 *     línea de acá. Nunca se saca ni se renombra una clave ya enviada: los campos nuevos se agregan
 *     opcionales.
 */
class ClientScheduleSyncService
{
    /** El empresa-api del cliente confirmó la recepción (2xx). */
    const ESTADO_SUCCESS = 'success';

    /** No se pudo empujar por configuración o por versión vieja del cliente: lo resuelve una persona. */
    const ESTADO_MANUAL_REQUIRED = 'manual_required';

    /** El cliente está inactivo: no se llama a nadie y no es un fallo. */
    const ESTADO_SKIPPED = 'skipped';

    /** El empresa-api contestó algo inesperado o no se lo pudo contactar. */
    const ESTADO_FAILED = 'failed';

    /** Cantidad de días de la semana que viajan resueltos. Siempre siete: es una semana. */
    const DIAS_DE_LA_SEMANA = 7;

    /** Caracteres del cuerpo de la respuesta del cliente que se guardan en el mensaje. */
    const CHARS_DE_CUERPO = 300;

    /**
     * @var ClientEmpresaApiUrlResolver Resuelve la URL base del empresa-api del cliente.
     */
    protected $api_url_resolver;

    /**
     * @var ClientScheduleResolver Aplica la regla de precedencia de los horarios.
     */
    protected $schedule_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver  Inyectable para tests.
     * @param ClientScheduleResolver|null      $schedule_resolver Inyectable para tests.
     */
    public function __construct(
        ?ClientEmpresaApiUrlResolver $api_url_resolver = null,
        ?ClientScheduleResolver $schedule_resolver = null
    ) {
        $this->api_url_resolver  = $api_url_resolver === null ? new ClientEmpresaApiUrlResolver() : $api_url_resolver;
        $this->schedule_resolver = $schedule_resolver === null ? new ClientScheduleResolver() : $schedule_resolver;
    }

    /**
     * Empuja los horarios del cliente a su empresa-api y persiste el desenlace.
     *
     * 🔴 No lanza NUNCA: todos los caminos terminan escribiendo `schedule_sync_status` y devolviendo
     * el resultado.
     *
     * @param Client      $client   Cliente cuyos horarios se sincronizan.
     * @param string|null $timezone Timezone del comercio; por defecto config('app.timezone').
     *
     * @return array ['status' => string, 'message' => string|null, 'synced_at' => string|null]
     */
    public function sync(Client $client, $timezone = null)
    {
        // Corte 1: cliente inactivo. Mismo criterio que PublishVersionService::syncExisting().
        // No es un fallo: es que no corresponde llamar a nadie.
        if (! $client->is_active) {
            return $this->registrar(
                $client,
                self::ESTADO_SKIPPED,
                'El cliente está inactivo: no se sincronizan los horarios a su empresa-api.'
            );
        }

        $url = $this->api_url_resolver->admin_sync_url(
            $client,
            ClientEmpresaApiUrlResolver::BUSINESS_HOURS_PATH
        );

        // Corte 2: sin URL resoluble. Es un problema de configuración del admin, no del cliente.
        if ($url === '') {
            return $this->registrar(
                $client,
                self::ESTADO_MANUAL_REQUIRED,
                'No hay URL válida del empresa-api de este cliente. Configurá una ClientApi con URL '
                . 'http/https y marcala como API activa, o cargá api_url en el cliente.'
            );
        }

        // Corte 3: sin api_key. Mismo motivo: configuración faltante del lado del admin.
        if (empty($client->api_key)) {
            return $this->registrar(
                $client,
                self::ESTADO_MANUAL_REQUIRED,
                'El cliente no tiene api_key configurada (tiene que coincidir con ADMIN_API_INBOUND_KEY '
                . 'del empresa-api del cliente).'
            );
        }

        $payload = $this->build_payload($client, $timezone);

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->put($url, $payload);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            /* En Laravel 8, ->retry() convierte cualquier respuesta no-2xx en excepción antes de que
             * put() devuelva algo (PendingRequest::send() llama a $response->throw() dentro del
             * retry() cuando $tries > 1). Sin recuperar acá la respuesta real, la rama del 404 de más
             * abajo sería código muerto — es el mismo pozo que ya documentó
             * DeploymentService::step_update_default_version(). */
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response !== null && $response->successful()) {
            return $this->registrar($client, self::ESTADO_SUCCESS, null, true);
        }

        if ($response !== null && $response->status() === 404) {
            /* 🔴 El 404 es el caso ESPERADO hasta que salga la mitad de empresa-api: la instancia del
             * cliente todavía no tiene la ruta. Se degrada limpio y NO se toca schedule_synced_at. */
            return $this->registrar(
                $client,
                self::ESTADO_MANUAL_REQUIRED,
                'El empresa-api de este cliente respondió 404 en ' . $url . '. La versión instalada '
                . 'todavía no tiene el endpoint admin-sync/business-hours, o la URL de la ClientApi '
                . 'está mal cargada (revisar si falta el /public de hosting compartido). Los horarios '
                . 'van a viajar solos cuando el cliente se actualice.'
            );
        }

        if ($response !== null) {
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $response->status() . ': '
                . substr((string) $response->body(), 0, self::CHARS_DE_CUERPO);

            if ($response->status() === 401 || $response->status() === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            return $this->registrar($client, self::ESTADO_FAILED, $mensaje);
        }

        Log::warning('ClientScheduleSyncService: no se pudo contactar al empresa-api del cliente.', [
            'client_id' => $client->id,
            'url'       => $url,
            'error'     => $transport_error,
        ]);

        return $this->registrar(
            $client,
            self::ESTADO_FAILED,
            'No se pudo contactar al empresa-api del cliente en ' . $url . ': ' . $transport_error
        );
    }

    /**
     * Arma el payload del contrato con empresa-api.
     *
     * Forma:
     *
     *   [
     *     'timezone'       => 'America/Argentina/Buenos_Aires',
     *     'actualizado_en' => '2026-08-24T18:30:00-03:00',
     *     'configurado'    => true,
     *     'semana'         => [ ['dia_semana' => 0, 'dia' => 'domingo', 'abierto' => false,
     *                            'estado' => 'cerrado', 'origen' => 'dia_propio', 'rangos' => []],
     *                           ['dia_semana' => 1, 'dia' => 'lunes', 'abierto' => null,
     *                            'estado' => 'sin_configurar', 'origen' => 'sin_configurar', 'rangos' => []], … ],
     *     'dias_crudos'    => [ ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]] ],
     *   ]
     *
     * `dia_semana` es el índice de Carbon::dayOfWeek (0 = domingo), que es la convención que ya usa
     * la casa (CloserAgendaService::NOMBRES_DIA). No se inventa otra.
     *
     * `abierto` es a nivel DÍA ("ese día el comercio abre en algún momento"), no a nivel instante, y
     * 🔴 tiene TRES valores: `true` (abre), `false` (cerrado) y **`null` = `sin_configurar`, o sea
     * "no se sabe"**. No es un booleano: si lo fuera, "no hay dato" y "cerrado" viajarían iguales, y
     * `abierto` es el campo más obvio de consumir del otro lado — el que se lee solo, sin mirar
     * `estado`. `estado` y `origen` siguen viajando al lado, con el detalle completo.
     *
     * @param Client      $client   Cliente dueño de los horarios.
     * @param string|null $timezone Timezone; por defecto config('app.timezone').
     *
     * @return array
     */
    public function build_payload(Client $client, $timezone = null)
    {
        $tz = $this->resolver_timezone($timezone);

        $client->loadMissing('schedule_days.schedule_ranges');

        $dias_crudos = $this->dias_crudos($client);
        $configurado = count($dias_crudos) > 0;

        return [
            'timezone'       => $tz,
            'actualizado_en' => Carbon::now($tz)->toIso8601String(),
            // 🔴 false = "no hay dato". NO es "cerrado".
            'configurado'    => $configurado,
            'semana'         => $configurado ? $this->semana_resuelta($client, $tz) : [],
            'dias_crudos'    => $dias_crudos,
        ];
    }

    /**
     * Los siete días de la semana ya resueltos por ClientScheduleResolver, de domingo (0) a
     * sábado (6).
     *
     * Se arranca desde el domingo de la semana en curso justamente para que el recorrido dé los
     * `dayOfWeek` 0..6 en orden, sin que el consumidor tenga que ordenar nada. Las fechas concretas
     * no viajan: el contrato es semanal, no de un rango de fechas.
     *
     * @param Client $client Cliente dueño de los horarios.
     * @param string $tz     Timezone del comercio.
     *
     * @return array<int, array>
     */
    private function semana_resuelta(Client $client, $tz)
    {
        $domingo = Carbon::now($tz)->startOfWeek(Carbon::SUNDAY);

        $semana = [];

        foreach ($this->schedule_resolver->resolve_dias($client, $domingo, self::DIAS_DE_LA_SEMANA, $tz) as $resuelto) {
            $semana[] = [
                'dia_semana' => (int) array_search($resuelto['dia'], ClientScheduleDay::DAY_KEYS_BY_DOW, true),
                'dia'        => $resuelto['dia'],
                'dia_label'  => $resuelto['dia_label'],
                /*
                 * 🔴 Tres valores, no dos: true = abre en algún momento, false = cerrado,
                 * null = SIN CONFIGURAR ("no se sabe"). Con un booleano, "no hay dato" y "cerrado"
                 * viajan iguales, que es justo el error que este contrato no quiere — y `abierto`
                 * es el campo más obvio de consumir del otro lado, así que tiene que ser honesto
                 * por sí solo, sin obligar a leer `estado`.
                 */
                'abierto'    => $this->abierto_a_nivel_dia($resuelto['estado']),
                'estado'     => $resuelto['estado'],
                'origen'     => $resuelto['origen'],
                'rangos'     => $resuelto['rangos'],
                'cierre'     => $resuelto['cierre_del_dia'],
            ];
        }

        return $semana;
    }

    /**
     * Traduce el estado del resolvedor al campo `abierto` del contrato, que tiene TRES valores.
     *
     * 🔴 `null` no es "falso por defecto": es "no se sabe". Es el tercer estado del resolvedor
     * cruzando el cable, igual que `configurado: false` a nivel payload. Colapsarlo a `false` le
     * diría a un comprador que el comercio está cerrado un martes a las 10.
     *
     * @param string $estado Estado devuelto por ClientScheduleResolver::resolve_for_date().
     *
     * @return bool|null true = abre en algún momento, false = cerrado, null = sin configurar.
     */
    private function abierto_a_nivel_dia($estado)
    {
        if ($estado === ClientScheduleResolver::ESTADO_SIN_CONFIGURAR) {
            return null;
        }

        return $estado === ClientScheduleResolver::ESTADO_CON_HORARIO;
    }

    /**
     * Los días tal como están cargados en el admin, sin resolver precedencia, en el orden de
     * presentación de DAY_KEYS.
     *
     * ⚠️ Viaja solo como comodidad de lectura para el agente ("los sábados hasta las 13"). NO es
     * fuente de verdad: la fuente de verdad es `semana`.
     *
     * @param Client $client Cliente con `schedule_days.schedule_ranges` cargados.
     *
     * @return array<int, array>
     */
    private function dias_crudos(Client $client)
    {
        $por_key = [];

        foreach ($client->schedule_days as $dia) {
            $rangos = [];

            foreach ($dia->schedule_ranges as $rango) {
                $rangos[] = [
                    'desde' => $this->hora_hhmm($rango->start_time),
                    'hasta' => $this->hora_hhmm($rango->end_time),
                ];
            }

            usort($rangos, function ($a, $b) {
                return strcmp($a['desde'], $b['desde']);
            });

            $por_key[(string) $dia->day_key] = [
                'dia'       => (string) $dia->day_key,
                'dia_label' => ClientScheduleDay::label_for($dia->day_key),
                // Cero rangos NO es un faltante: es "ese día el comercio está cerrado".
                'rangos'    => $rangos,
            ];
        }

        $dias = [];

        foreach (ClientScheduleDay::DAY_KEYS as $day_key) {
            if (isset($por_key[$day_key])) {
                $dias[] = $por_key[$day_key];
            }
        }

        return $dias;
    }

    /**
     * Persiste el desenlace en el cliente y lo devuelve.
     *
     * `schedule_synced_at` SOLO se toca cuando hubo éxito: un fallo posterior no puede borrar la
     * información de cuándo fue la última vez que el cliente quedó al día.
     *
     * @param Client      $client  Cliente a marcar.
     * @param string      $status  Uno de los cuatro estados.
     * @param string|null $mensaje Motivo, cuando no es success.
     * @param bool        $exitoso Si además hay que estampar schedule_synced_at.
     *
     * @return array ['status' => string, 'message' => string|null, 'synced_at' => string|null]
     */
    private function registrar(Client $client, $status, $mensaje = null, $exitoso = false)
    {
        $cambios = [
            'schedule_sync_status'  => $status,
            'schedule_sync_message' => $mensaje,
        ];

        if ($exitoso) {
            $cambios['schedule_synced_at'] = Carbon::now();
        }

        $client->update($cambios);

        return [
            'status'    => $status,
            'message'   => $mensaje,
            'synced_at' => $client->schedule_synced_at === null ? null : (string) $client->schedule_synced_at,
        ];
    }

    /**
     * Normaliza una hora de la columna `time` ('09:00:00') al 'HH:MM' que viaja por el contrato.
     *
     * @param mixed $valor Valor crudo de la columna.
     *
     * @return string
     */
    private function hora_hhmm($valor)
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
     * Timezone efectivo. Viaja explícito en el payload porque una hora sin zona declarada es
     * discutible, y del otro lado la va a leer un agente que le contesta a un comprador.
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
