<?php

namespace App\Services;

use App\Events\LeadSuggestionCreated;
use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use App\Jobs\ConfirmarReservaLandingJob;
use App\Models\Demo;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La demo autogestionada desde la landing (misión demo-autogestionada-landing, 22/10/2026): los
 * turnos que ve el visitante y la reserva que hace él mismo, sin pasar por WhatsApp.
 *
 * 🔴 POR QUÉ no hay acá ningún cálculo de turnos propio. Una demo «de verdad» agendada es la que deja
 * la rama «demo directa» de `LeadAiService::apply_parsed_response()`. Si este servicio tuviera su
 * propia idea de qué es un turno libre, el formulario de la web y el agente de WhatsApp podrían darle
 * la misma instancia a dos leads, o la web ofrecer algo que el agente después no reconoce. Por eso:
 *
 *   - Los turnos que se OFRECEN son la grilla del panel (`LeadAiService::build_availability_json`,
 *     la misma llamada que `LeadController::panel_availability_json`), unión de las instancias, y
 *     solo los slots que admiten la ventana de dos horas (`$ventanas_extendidas`). Encima va un filtro
 *     de PRESENTACIÓN (`config/landing_agenda.php`), que esconde y no invalida (D1).
 *   - Al CONFIRMAR se aplican las dos definiciones que ya existen, dentro del MISMO lock del agente
 *     (`demo_asignacion_directa`): el slot tiene que estar en la grilla para esa instancia Y la
 *     instancia tiene que estar libre según `DemoDirectaService` para `ventana_para($inicio)`, más
 *     `validar_inicio_de_demo_pedida()`. La primera instancia por id que pasa las dos (D2).
 *   - Lo que se ESCRIBE es `DemoDirectaService::campos_de_reserva()`, la misma lista que usa el
 *     agente, y después los mismos pasos posteriores (D3; el detalle, en `reservar()`).
 *
 * Los efectos lentos (carta, WhatsApp al lead, aviso a los admins, envío a Meta) NO van acá: van en
 * `ConfirmarReservaLandingJob`, que corre después de responder (D9).
 */
class LandingAgendaService
{
    /** Nombres de día como los dice el turno («jueves 23/10 de 15:00 a 17:00»). Carbon: 0 = domingo. */
    const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /** Abreviatura de la etiqueta del chip («jue 23/10»). */
    const DIAS_CORTOS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    /** Estados de un lead que ya es cliente (además de `promoted_client_id`): 409 `ya_es_cliente`. */
    const ESTADOS_DE_CLIENTE = ['cerrado_ganado'];

    /**
     * Estados de un lead que ya hizo la demo: 409 `ya_hizo_la_demo`. `mail2_enviado` está en desuso pero
     * es post-demo (mismo criterio que `MetaConversionesService::ESTADOS_QUALIFIED_LEAD`).
     */
    const ESTADOS_POST_DEMO = ['demo_realizada', 'closer_activo', 'mail2_enviado'];

    /** Códigos del 409 (contrato con la landing: no se cambian). */
    const CODIGO_TURNO_TOMADO   = 'turno_tomado';
    const CODIGO_YA_TIENE_DEMO  = 'ya_tiene_demo';
    const CODIGO_YA_ES_CLIENTE  = 'ya_es_cliente';
    const CODIGO_YA_HIZO_LA_DEMO = 'ya_hizo_la_demo';

    /**
     * Los turnos para el visitante: el cuerpo del 200 de `GET landing/agenda/turnos`.
     *
     * @param int|null $exclude_lead_id Lead a excluir de la ocupación (el del teléfono, en un 409).
     *
     * @return array{zona: string, duracion_minutos: int, dias: array<int, array<string, mixed>>}
     */
    public function turnos(?int $exclude_lead_id = null): array
    {
        return [
            'zona'             => DemoDirectaService::TZ,
            'duracion_minutos' => LeadDemoSettings::get_duracion_minutos(),
            'dias'             => $this->dias_para_mostrar($this->grilla($exclude_lead_id)),
        ];
    }

    /**
     * La grilla del panel, por instancia: `[demo_id => [Y-m-d => [HH:MM inicio => HH:MM hasta]]]`.
     *
     * Solo entran los slots que tienen «hasta» en `$ventanas_extendidas`: los que admiten la reserva de
     * dos horas que se le promete al lead. Un slot de la grilla sin ventana (porque otra demo lo pisa
     * antes de las dos horas) no se ofrece ni se acepta.
     *
     * @param int|null $exclude_lead_id
     *
     * @return array<int, array<string, array<string, string>>>
     */
    public function grilla(?int $exclude_lead_id = null): array
    {
        $snapshot = null;
        $config   = null;
        $ventanas = null;

        $disponibilidad = app(LeadAiService::class)->build_availability_json(
            (int) config('landing_agenda.presentacion.dias', 7),
            $snapshot,
            null,
            $exclude_lead_id,
            true,
            null,
            $config,
            $ventanas
        );

        $grilla = [];
        foreach (($disponibilidad['demos'] ?? []) as $demo_id => $fechas) {
            foreach ((array) $fechas as $etiqueta => $horarios) {
                /* La clave es "nombre_dia Y-m-d" (formato del prompt del agente, no se toca allá). */
                if (! preg_match('/\d{4}-\d{2}-\d{2}/', (string) $etiqueta, $m)) {
                    continue;
                }
                foreach ((array) $horarios as $slot) {
                    $slot = (string) $slot;
                    if (isset($ventanas[$demo_id][$etiqueta][$slot])) {
                        $grilla[(int) $demo_id][$m[0]][$slot] = (string) $ventanas[$demo_id][$etiqueta][$slot];
                    }
                }
            }
        }

        return $grilla;
    }

    /**
     * Une las instancias y aplica el filtro de presentación. Días sin horarios no viajan; todo en orden
     * cronológico.
     *
     * @param array<int, array<string, array<string, string>>> $grilla
     *
     * @return array<int, array<string, mixed>>
     */
    private function dias_para_mostrar(array $grilla): array
    {
        $union = [];
        foreach ($grilla as $fechas) {
            foreach ($fechas as $fecha => $slots) {
                foreach ($slots as $hora => $hasta) {
                    /* El «hasta» no depende de la instancia (inicio + tope de horas, cortado a las
                     * 23:59): la primera que lo tenga alcanza. */
                    if (! isset($union[$fecha][$hora])) {
                        $union[$fecha][$hora] = $hasta;
                    }
                }
            }
        }

        $desde  = $this->minutos_de((string) config('landing_agenda.presentacion.desde', '09:00'));
        $hasta  = $this->minutos_de((string) config('landing_agenda.presentacion.hasta', '21:00'));
        $paso   = max(1, (int) config('landing_agenda.presentacion.paso_minutos', 60));
        $minimo = AppTime::now(DemoDirectaService::TZ)->addMinutes((int) config('landing_agenda.presentacion.minimo_minutos_desde_ahora', 60));

        ksort($union);
        $dias = [];
        foreach ($union as $fecha => $slots) {
            ksort($slots);
            $horarios = [];
            foreach ($slots as $hora => $hasta_slot) {
                $minutos = $this->minutos_de((string) $hora);
                if ($minutos === null || $minutos % $paso !== 0
                    || ($desde !== null && $minutos < $desde) || ($hasta !== null && $minutos > $hasta)) {
                    continue;
                }
                $inicio = Carbon::createFromFormat('Y-m-d H:i', $fecha . ' ' . $hora, DemoDirectaService::TZ)->second(0);
                if ($inicio->lt($minimo)) {
                    continue;
                }
                $horarios[] = ['hora' => (string) $hora, 'hasta' => (string) $hasta_slot];
            }
            if (empty($horarios)) {
                continue;
            }

            $dia    = Carbon::createFromFormat('Y-m-d', $fecha, DemoDirectaService::TZ)->startOfDay();
            $dias[] = [
                'fecha'    => $fecha,
                'etiqueta' => self::DIAS_CORTOS[$dia->dayOfWeek] . ' ' . $dia->format('d/m'),
                'nombre'   => self::DIAS[$dia->dayOfWeek],
                'horarios' => $horarios,
            ];
        }

        return $dias;
    }

    /**
     * Reserva la demo desde la landing.
     *
     * Devuelve qué contestar (`http` + `cuerpo`) y, si la reserva quedó hecha, el trabajo de después
     * (`trabajo`), que el controller despacha para después de la respuesta.
     *
     * Orden, y por qué:
     *
     *   1. Lock global `demo_asignacion_directa` (el MISMO del agente, 8 s / espera 5): sin él, el agente
     *      aprobando una demo directa y esta reserva podrían elegir la misma instancia. Sin lock → 503
     *      `reintentar` (no se escribió nada).
     *   2. Bajo el lock: el lead del teléfono EXACTO (`BuscadorDeLeadPorTelefono::buscar_exacto`, D4) y sus 409 (D5); la
     *      instancia (D2); y la escritura: lead nuevo si no había, el UPDATE de `campos_de_reserva()`
     *      (D3) con los datos que faltaban, el evento del hilo, el contacto de atribución (D7) y
     *      `registrar_reserva()` — en ese orden, para que la reserva tome este contacto como el último.
     *      Igual que el agente (`LeadAiService`, rama demo directa: UPDATE por clave adentro del lock,
     *      `registrar_reserva()` y recién ahí `release()`).
     *   3. Fuera del lock: `LeadPipelineStatus::ensure_exists('demo_agendada')`, los eventos de Meta con
     *      el contexto web (D8, ANTES del `save()`; ver abajo), el contador del A/B de bienvenida y el
     *      `$lead->save()` final.
     *
     * 🔴 El `$lead->save()` final es a propósito y replica el del agente. En `apply_parsed_response()` el
     * UPDATE por clave escribe la reserva y además deja los mismos valores en memoria; el único `save()`
     * del método, mucho más abajo, encuentra `status` sucio y dispara lo que el UPDATE esquivó: el latch
     * de `requiere_verificacion_mensajes` al entrar a la ventana de verificación (`Lead::booted`, con
     * las mismas condiciones del interruptor de Cuenta), el embudo de Pipelines y los observers de
     * conversiones y de reserva. Acá pasa exactamente lo mismo, así el lead queda igual que uno agendado
     * por el agente. Los dos observers no duplican nada: `registrar_reserva()` es idempotente (único por
     * lead) y los eventos de Meta ya están registrados, con su contexto, ANTES del `save()` —si fuera al
     * revés, el observer los crearía sin contexto y saldrían como `chat`—.
     *
     * @param array<string, mixed> $datos        Validados por el controller (nombre, whatsapp, email,
     *                                           rubro, fecha, hora, atribucion).
     * @param array<string, string>|null $contexto_web IP, user agent, fbp, fbc y URL de la página.
     *
     * @return array{http: int, cuerpo: array<string, mixed>, trabajo: ConfirmarReservaLandingJob|null}
     */
    public function reservar(array $datos, ?array $contexto_web): array
    {
        $telefono = WhatsappNormalizer::normalize((string) $datos['whatsapp']);
        $inicio   = $this->inicio_pedido((string) $datos['fecha'], (string) $datos['hora']);

        $lock    = Cache::lock('demo_asignacion_directa', 8);
        $lock_ok = false;
        try {
            $lock_ok = (bool) $lock->block(5);
        } catch (LockTimeoutException $e) {
            $lock_ok = false;
        }

        if (! $lock_ok) {
            Log::warning('LandingAgenda: no se consiguió el lock de asignación de demo.', ['fecha' => $datos['fecha'], 'hora' => $datos['hora']]);

            return $this->respuesta(503, [
                'codigo'  => 'reintentar',
                'mensaje' => 'Estamos confirmando otra reserva en este mismo instante. Probá de nuevo en unos segundos.',
            ]);
        }

        $liberado = false;
        try {
            /* Tope GLOBAL de reservas efectivas (segunda vuelta, 🟠3). Va bajo el lock, así dos reservas
             * simultáneas no pasan las dos con el contador en el límite. */
            if ($this->tope_global_alcanzado()) {
                return $this->respuesta(429, [
                    'mensaje' => 'Ahora no podemos tomar más reservas desde la web. Escribinos por WhatsApp y te la agendamos.',
                ]);
            }

            /* SOLO el número exacto (ver BuscadorDeLeadPorTelefono): el formulario lo tipea cualquiera, y
             * una coincidencia por sufijo le escribiría el turno a la ficha de otra persona. Sin exacto,
             * nace un lead nuevo. */
            $lead = BuscadorDeLeadPorTelefono::buscar_exacto($telefono);

            if ($lead !== null) {
                $bloqueo = $this->bloqueo_del_lead($lead);
                if ($bloqueo !== null) {
                    return $this->respuesta(409, $bloqueo);
                }
            }

            $eleccion = $inicio !== null ? $this->elegir_instancia($lead, $inicio) : null;
            if ($eleccion === null) {
                $lock->release();
                $liberado = true;

                return $this->respuesta(409, [
                    'codigo'  => self::CODIGO_TURNO_TOMADO,
                    'mensaje' => 'Ese horario se acaba de ocupar. Elegí otro de los que siguen libres.',
                    'turnos'  => $this->turnos($lead !== null ? (int) $lead->id : null)['dias'],
                ]);
            }

            $reserva = $this->escribir_reserva($lead, $telefono, $datos, $eleccion['demo'], $eleccion['ventana']);
            $this->contar_reserva_global();

            $lock->release();
            $liberado = true;
        } finally {
            if (! $liberado) {
                $lock->release();
            }
        }

        /** @var Lead $lead */
        $lead    = $reserva['lead'];
        $evento  = $reserva['evento'];
        /** @var ConfirmarReservaLandingJob $trabajo Ya despachado para después de responder. */
        $trabajo = $reserva['trabajo'];

        /* 🔴 Desde acá, cada paso en su try/catch y con rastro en el hilo (🟠4): la reserva está escrita y
         * el trabajo de después ya despachado; ninguno de estos puede convertirla en un 500. */
        try {
            LeadPipelineStatus::ensure_exists('demo_agendada');
        } catch (\Throwable $e) {
            $this->anotar_error((int) $lead->id, 'No se pudo asegurar el estado demo_agendada del pipeline', $e);
        }

        /* D8: los eventos que nacen en esta reserva, con el contexto del navegador. Sin despachar: el
         * envío a Meta va en el trabajo de después de responder. */
        $filas = [];
        try {
            $filas = app(MetaConversionesService::class)->registrar_alcanzados($lead, null, false, $contexto_web);
            $trabajo->filas_meta = array_map(function (LeadEventoMeta $fila) {
                return (int) $fila->id;
            }, $filas);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LandingAgenda: no se pudieron registrar los eventos de Meta de la reserva.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        /* Igual que el agente al asignar una demo directa: el A/B de la bienvenida cuenta el agendado. */
        try {
            if ($lead->welcome_variant_id) {
                $variante = \App\Models\MessageVariant::find($lead->welcome_variant_id);
                if ($variante) {
                    $variante->increment_scheduled();
                }
            }
        } catch (\Throwable $e) {
            Log::error('LandingAgenda: no se pudo contar el agendado en el A/B de la bienvenida.', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        /* El save() del agente (ver el docblock): latch de verificación, embudo y observers. */
        try {
            $lead->save();
        } catch (\Throwable $e) {
            $this->anotar_error((int) $lead->id, 'La reserva desde la web quedó escrita, pero falló el guardado final del lead (latch de verificación y observers)', $e);
        }

        LeadSuggestionCreated::dispatch((int) $lead->id);
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, $evento !== null ? (int) $evento->id : null);

        Log::info('LandingAgenda: demo reservada desde la web.', [
            'lead_id'    => $lead->id,
            'lead_nuevo' => $reserva['lead_nuevo'],
            'demo_id'    => $lead->demo_id,
            'demo_date'  => $reserva['fecha'],
            'demo_start' => $reserva['desde'],
            'demo_end'   => $reserva['hasta'],
        ]);

        $meta_eventos = [];
        foreach ($filas as $fila) {
            /* El píxel solo dispara los eventos de la web: un lead de anuncio (con `ctwa_clid`) reporta por
             * la WABA, y un `Lead` del píxel sin su par de la CAPI en ese dataset sería un evento de más. */
            if ($fila->canal !== LeadEventoMeta::CANAL_WEB) {
                continue;
            }
            $meta_eventos[] = ['nombre' => (string) $fila->evento, 'event_id' => (string) $fila->event_id];
        }

        $whatsapp_confirmacion = false;
        try {
            $whatsapp_confirmacion = FollowupTemplate::estado_para_enviar((string) config('landing_agenda.plantilla_confirmacion'))['motivo'] === null;
        } catch (\Throwable $e) {
            Log::error('LandingAgenda: no se pudo leer el estado de la plantilla de confirmación.', ['error' => $e->getMessage()]);
        }

        return [
            'http'    => 201,
            'cuerpo'  => [
                'experiencia_url' => $lead->demo_experiencia_url,
                'turno'           => [
                    'fecha' => $reserva['fecha'],
                    'desde' => $reserva['desde'],
                    'hasta' => $reserva['hasta'],
                    'texto' => $reserva['texto'],
                ],
                'correo'          => self::correo_enmascarado((string) $lead->email),
                'meta_eventos'    => $meta_eventos,
                /* ¿Va a salir el WhatsApp de confirmación? Solo si la plantilla está activa y aprobada AHORA
                 * (el mismo gate que mira el trabajo de después antes de mandarla), para que la landing no le
                 * prometa al visitante un WhatsApp que no le va a llegar. */
                'whatsapp_confirmacion' => $whatsapp_confirmacion,
            ],
            'trabajo' => $trabajo,
        ];
    }

    /** Claves del contador global de reservas (RateLimiter: ventana deslizante desde la primera). */
    const CLAVE_TOPE_HORA = 'landing-agenda-reservas-global-hora';
    const CLAVE_TOPE_DIA  = 'landing-agenda-reservas-global-dia';

    /**
     * ¿Ya se tomaron, desde la web, todas las reservas que se admiten por hora o por día?
     *
     * 🔴 POR QUÉ un tope global además del throttle por IP (revisión independiente, 🟠3). Sin captcha
     * (D13), `abierto_hace_ms` y el honeypot se falsifican con un `curl`, y el throttle por IP no frena a
     * quien tenga varias. Con tres instancias, unas cien reservas tapan todo lo que la landing ofrece en
     * siete días: el agente de WhatsApp se quedaría sin instancias para los leads reales (comparten la
     * ocupación), y cada reserva le manda un WhatsApp a un número cualquiera y una carta a un correo
     * cualquiera, que bajan la calidad del número institucional ante Meta. Cuenta reservas EFECTIVAS
     * (no intentos: un 409 o un 422 no gastan cupo), y al pasarse deja un warning en el log para que se
     * vea en el primer día de campaña.
     *
     * @return bool
     */
    private function tope_global_alcanzado(): bool
    {
        $por_hora = max(1, (int) config('landing_agenda.reservas_globales_por_hora', 20));
        $por_dia  = max(1, (int) config('landing_agenda.reservas_globales_por_dia', 60));

        $hora = RateLimiter::tooManyAttempts(self::CLAVE_TOPE_HORA, $por_hora);
        $dia  = RateLimiter::tooManyAttempts(self::CLAVE_TOPE_DIA, $por_dia);
        if (! $hora && ! $dia) {
            return false;
        }

        Log::channel('daily')->warning('LandingAgenda: se alcanzó el tope global de reservas desde la web; se rechaza con 429.', [
            'tope'                  => $hora ? 'hora' : 'dia',
            'reservas_ultima_hora'  => RateLimiter::attempts(self::CLAVE_TOPE_HORA),
            'reservas_ultimo_dia'   => RateLimiter::attempts(self::CLAVE_TOPE_DIA),
            'limite_por_hora'       => $por_hora,
            'limite_por_dia'        => $por_dia,
        ]);

        return true;
    }

    /**
     * Suma una reserva efectiva a los dos contadores globales.
     *
     * @return void
     */
    private function contar_reserva_global(): void
    {
        try {
            RateLimiter::hit(self::CLAVE_TOPE_HORA, 3600);
            RateLimiter::hit(self::CLAVE_TOPE_DIA, 86400);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LandingAgenda: no se pudo sumar la reserva al tope global.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * El 409 que le corresponde a un lead existente, o null si se puede reservar sobre él (D5).
     *
     * 🔴 `ya_tiene_demo` NO dice cuál es el turno: el formulario no autentica a nadie, y con el teléfono
     * de otra persona cualquiera podría averiguar cuándo tiene su demo.
     *
     * @param Lead $lead
     *
     * @return array<string, string>|null
     */
    private function bloqueo_del_lead(Lead $lead): ?array
    {
        $status = (string) $lead->status;

        if (($lead->promoted_client_id !== null && (string) $lead->promoted_client_id !== '')
            || in_array($status, self::ESTADOS_DE_CLIENTE, true)) {
            return [
                'codigo'  => self::CODIGO_YA_ES_CLIENTE,
                'mensaje' => 'Ese WhatsApp ya es de un cliente de ComercioCity. Escribinos por WhatsApp y te ayudamos.',
            ];
        }

        /* Solo si la demo sigue VIGENTE, con el mismo cálculo que usa el agente
         * (`LeadAiService::fin_real_del_turno_asignado`). Un no-show de hace días sigue en
         * `demo_pendiente_de_ingreso` (el turno lo suelta solo una persona), y para el agente esa demo
         * está vencida y se le ofrece de nuevo: la web no puede decirle que "ya tiene una". Vencida, se
         * reserva sobre ese mismo lead (`campos_de_reserva` resetea los relojes y el trabajo de después
         * mueve el token, como en un reagendado). */
        $fin_real = LeadAiService::fin_real_del_turno_asignado($lead);
        if (in_array($status, DemoDirectaService::ESTADOS_QUE_OCUPAN_LA_INSTANCIA, true)
            && $lead->demo_id !== null && $fin_real !== null && ! AppTime::now(DemoDirectaService::TZ)->gt($fin_real)) {
            return [
                'codigo'  => self::CODIGO_YA_TIENE_DEMO,
                'mensaje' => 'Ese WhatsApp ya tiene una demo reservada. Si querés cambiarla, escribinos por WhatsApp.',
            ];
        }

        if (in_array($status, self::ESTADOS_POST_DEMO, true)) {
            return [
                'codigo'  => self::CODIGO_YA_HIZO_LA_DEMO,
                'mensaje' => 'Ese WhatsApp ya hizo la demo. Escribinos por WhatsApp y seguimos desde ahí.',
            ];
        }

        return null;
    }

    /**
     * La primera instancia (por id) que pasa las DOS definiciones de turno libre (D2), o null.
     *
     * @param Lead|null $lead   El lead existente, si lo hay (se excluye de su propia ocupación).
     * @param Carbon    $inicio
     *
     * @return array{demo: Demo, ventana: array{inicio: Carbon, fin: Carbon, cabe: bool}}|null
     */
    private function elegir_instancia(?Lead $lead, Carbon $inicio): ?array
    {
        $ahora = AppTime::now(DemoDirectaService::TZ);

        if (app(LeadAiService::class)->validar_inicio_de_demo_pedida($inicio, $ahora) !== null) {
            return null;
        }

        $directa = app(DemoDirectaService::class);
        $ventana = $directa->ventana_para($inicio);
        if (! $ventana['cabe']) {
            return null;
        }

        $grilla = $this->grilla($lead !== null ? (int) $lead->id : null);
        $fecha  = $ventana['inicio']->format('Y-m-d');
        $hora   = $ventana['inicio']->format('H:i');

        /* `DemoDirectaService` excluye al lead por id. Un visitante sin ficha todavía no tiene id: se le
         * pasa uno provisorio con id 0 (ninguna fila real lo tiene), que no excluye a nadie. Con un Lead
         * sin id la consulta quedaría `id != NULL`, que en SQL no devuelve ninguna fila y daría todo libre. */
        $quien = $lead;
        if ($quien === null) {
            $quien     = new Lead();
            $quien->id = 0;
        }

        foreach (Demo::query()->orderBy('id')->get() as $demo) {
            if (! isset($grilla[(int) $demo->id][$fecha][$hora])) {
                continue;
            }
            if (! $directa->demo_libre_para_ventana($quien, (int) $demo->id, $ventana['inicio'], $ventana['fin'])) {
                continue;
            }

            return ['demo' => $demo, 'ventana' => $ventana];
        }

        return null;
    }

    /**
     * Escribe la reserva (bajo el lock). Ver `reservar()`.
     *
     * @param Lead|null            $lead
     * @param string               $telefono E.164 normalizado.
     * @param array<string, mixed> $datos
     * @param Demo                 $demo
     * @param array{inicio: Carbon, fin: Carbon, cabe: bool} $ventana
     *
     * @return array{lead: Lead, evento: LeadMessage|null, trabajo: ConfirmarReservaLandingJob, lead_nuevo: bool, fecha: string, desde: string, hasta: string, texto: string}
     */
    private function escribir_reserva(?Lead $lead, string $telefono, array $datos, Demo $demo, array $ventana): array
    {
        $ahora     = AppTime::now();
        $nombre    = trim((string) $datos['nombre']);
        $email     = trim((string) $datos['email']);
        $rubro     = (string) $datos['rubro'];
        $rubro_txt = DemoIndustriaCatalogo::OPCIONES[$rubro];

        $lead_nuevo = $lead === null;
        if ($lead_nuevo) {
            /* Mismo alta que el webhook (`WhatsappWebhookController::handle_lead_message`): teléfono en
             * E.164, `nuevo` y el documento aleatorio de la demo. El estado de la demo lo pone el UPDATE de
             * abajo, igual que para un lead existente. */
            $lead = Lead::create([
                'phone'               => $telefono,
                'contact_name'        => $nombre,
                'email'               => $email,
                'business_type'       => $rubro_txt,
                'demo_industria'      => $rubro,
                'origen'              => LeadOrigenService::ORIGEN_LANDING,
                'origen_calculado_at' => $ahora,
                'status'              => 'nuevo',
            ]);
            try {
                LeadDocNumberGenerator::assign_to_lead_if_empty($lead);
            } catch (\Throwable $e) {
                Log::error('LandingAgenda: no se pudo asignar el documento de la demo al lead nuevo.', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        /* Un formulario público no pisa datos de un lead existente (D5): nombre, correo y rubro libre solo
         * si estaban vacíos; el origen solo si no tenía. `demo_industria` sí: es la configuración de ESTA
         * demo, la que la experiencia muestra marcada en «Contanos cómo trabajás». */
        $complemento = [
            'demo_industria'                  => $rubro,
            'demo_reservada_desde_landing_at' => $ahora,
        ];
        $correo_distinto = null;
        if (! $lead_nuevo) {
            if (trim((string) $lead->contact_name) === '') {
                $complemento['contact_name'] = $nombre;
            }
            if (trim((string) $lead->email) === '') {
                $complemento['email'] = $email;
            } elseif (mb_strtolower(trim((string) $lead->email)) !== mb_strtolower($email)) {
                $correo_distinto = $email;
            }
            if (trim((string) $lead->business_type) === '') {
                $complemento['business_type'] = $rubro_txt;
            }
            if ($lead->origen === null || (string) $lead->origen === '') {
                $complemento['origen']              = LeadOrigenService::ORIGEN_LANDING;
                $complemento['origen_calculado_at'] = $ahora;
            }
        }

        /* D3: la MISMA lista de campos que escribe el agente, por el mismo UPDATE directo por clave. */
        $campos = array_merge(
            app(DemoDirectaService::class)->campos_de_reserva($demo, $ventana['inicio'], $ventana['fin']),
            $complemento
        );
        Lead::query()->whereKey($lead->id)->update($campos);
        foreach ($campos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $fecha = $ventana['inicio']->format('Y-m-d');
        $desde = $ventana['inicio']->format('H:i');
        $hasta = $ventana['fin']->format('H:i');
        $texto = self::texto_del_turno($ventana['inicio'], $ventana['fin']);

        /* 🔴 LA RESERVA YA ESTÁ ESCRITA. Desde acá nada puede dejarla sin carta, sin WhatsApp y sin aviso a
         * los admins (segunda vuelta, 🟠4): el trabajo de después se arma y se despacha AHORA, antes de
         * cualquier otro paso que pueda tirar, y lo que falta (el evento, los eventos de Meta) se le
         * completa por referencia más abajo. Lo que tire después queda en el hilo como error y la
         * respuesta es 201 igual. */
        $trabajo = new ConfirmarReservaLandingJob((int) $lead->id, null, [], $texto);
        dispatch($trabajo)->afterResponse();

        $contenido = 'Reservó su demo desde la web: ' . $texto . ' · rubro ' . $rubro_txt . '.';
        if ($correo_distinto !== null) {
            $contenido .= ' En el formulario escribió otro correo (' . $correo_distinto . '); la carta va al que ya teníamos.';
        }

        /* D6/D7: el evento visible en el hilo, que además es el `message_id` del contacto de atribución. */
        $evento = null;
        try {
            $evento = LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => 'sistema',
                'content'               => $contenido,
                'status'                => 'enviado',
                'is_followup'           => false,
                'is_status_event'       => true,
                'requiere_verificacion' => false,
                'sent_at'               => $ahora,
                'admin_notifications'   => [
                    ['evento' => 'Demo reservada desde la web', 'admins' => []],
                ],
            ]);
            $trabajo->evento_id = (int) $evento->id;
        } catch (\Throwable $e) {
            $this->anotar_error((int) $lead->id, 'No se pudo dejar en el hilo la reserva desde la web (' . $texto . ')', $e);
        }

        /* La atribución es accesoria: no puede tumbar una reserva. Si falla, el `ReservaAtribucionObserver`
         * del save() final vuelve a intentar `registrar_reserva()`. */
        try {
            $atribucion = app(LeadAtribucionService::class);
            if ($evento !== null) {
                $atribucion->registrar_contacto_de_reserva($evento, isset($datos['atribucion']) && is_array($datos['atribucion']) ? $datos['atribucion'] : []);
            }
            /* Igual que el agente: el UPDATE no dispara observers, así que la reserva se acredita acá. */
            $atribucion->registrar_reserva($lead);
        } catch (\Throwable $e) {
            $this->anotar_error((int) $lead->id, 'No se pudo registrar la atribución de la reserva desde la web', $e);
        }

        return [
            'lead'       => $lead,
            'evento'     => $evento,
            'trabajo'    => $trabajo,
            'lead_nuevo' => $lead_nuevo,
            'fecha'      => $fecha,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'texto'      => $texto,
        ];
    }

    /**
     * Deja en el hilo del lead (bloque rojo de error, `LeadConversationErrorLogger`) y en el log un paso de
     * la reserva que falló DESPUÉS de escribirla. La reserva sigue en pie y la respuesta es 201.
     *
     * @param int        $lead_id
     * @param string     $contexto
     * @param \Throwable $e
     *
     * @return void
     */
    private function anotar_error(int $lead_id, string $contexto, \Throwable $e): void
    {
        Log::error('LandingAgenda: ' . $contexto . '.', ['lead_id' => $lead_id, 'error' => $e->getMessage()]);
        (new LeadConversationErrorLogger())->log($lead_id, $contexto, $e->getMessage());
    }

    /**
     * «jueves 23/10 de 15:00 a 17:00»: el turno como lo leen el visitante, la plantilla y el hilo.
     *
     * @param Carbon $inicio
     * @param Carbon $fin
     *
     * @return string
     */
    public static function texto_del_turno(Carbon $inicio, Carbon $fin): string
    {
        $inicio = $inicio->copy()->setTimezone(DemoDirectaService::TZ);
        $fin    = $fin->copy()->setTimezone(DemoDirectaService::TZ);

        return self::DIAS[$inicio->dayOfWeek] . ' ' . $inicio->format('d/m') . ' de ' . $inicio->format('H:i') . ' a ' . $fin->format('H:i');
    }

    /**
     * El correo con la casilla tapada («lu***@gmail.com»): el visitante ve a qué casilla fue la carta sin
     * que el formulario revele el correo completo de un lead existente.
     *
     * @param string $email
     *
     * @return string
     */
    public static function correo_enmascarado(string $email): string
    {
        $email = trim($email);
        $arroba = strrpos($email, '@');
        if ($email === '' || $arroba === false || $arroba === 0) {
            return '';
        }

        return mb_substr(substr($email, 0, $arroba), 0, 2) . '***' . substr($email, $arroba);
    }

    /**
     * El instante pedido, en la zona de la demo, o null si no es una hora real (un `25:00` pasa el regex
     * del contrato pero no existe: se contesta como turno tomado, con los turnos frescos).
     *
     * @param string $fecha Y-m-d
     * @param string $hora  HH:MM
     *
     * @return Carbon|null
     */
    private function inicio_pedido(string $fecha, string $hora): ?Carbon
    {
        $minutos = $this->minutos_de($hora);
        if ($minutos === null) {
            return null;
        }

        try {
            $inicio = Carbon::createFromFormat('Y-m-d H:i', $fecha . ' ' . $hora, DemoDirectaService::TZ);
        } catch (\Throwable $e) {
            return null;
        }

        return $inicio !== false ? $inicio->second(0) : null;
    }

    /**
     * «HH:MM» → minutos desde la medianoche, o null si no es una hora válida.
     *
     * @param string $hora
     *
     * @return int|null
     */
    private function minutos_de(string $hora): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $hora, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * @param int                  $http
     * @param array<string, mixed> $cuerpo
     *
     * @return array{http: int, cuerpo: array<string, mixed>, trabajo: null}
     */
    private function respuesta(int $http, array $cuerpo): array
    {
        return ['http' => $http, 'cuerpo' => $cuerpo, 'trabajo' => null];
    }
}
