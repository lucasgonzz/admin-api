<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\CloserGoogleCalendarEventService;
use App\Services\DemoCicloAdminNotificationService;
use App\Services\GoogleCalendarOAuthService;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Helpers\AppTime;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pasa a demo_pendiente_de_ingreso a los leads que no entraron de verdad a la demo dentro del
 * tiempo límite.
 *
 * Se ejecuta cada minuto. Busca leads en `demo_agendada` (ya no hay un estado intermedio
 * `ingresando_demo`: se sacó del catálogo porque el ingreso real ahora se detecta solo, vía el
 * evento `demo.ingreso` que aplica DemoEventosController::avanzar_pipeline_por_ingreso_real() —
 * ver esa clase, misión demo-v2-estados-automaticos) cuyo `demo_start_time +
 * demo_ingreso_timeout_minutos` ya pasó y que todavía no tienen `demo_ingreso_confirmado`.
 *
 * Si el lead entró de verdad, ya está en `demo_en_curso` (lo puso el evento) y esta query ni lo
 * mira: el filtro por `status = 'demo_agendada'` alcanza solo.
 *
 * Son DOS vencimientos distintos y conviene no confundirlos:
 *
 *   - `handle()`: el timeout de la dinámica ACTUAL (`demo_ingreso_timeout_minutos`). No le manda
 *     ningún mensaje al lead —Claude ya está gestionando la conversación, si hace falta—, cambia el
 *     estado para que el equipo lo vea, libera el hold del closer y avisa a admins.
 *   - `vencer_demos_directas_sin_ingreso()`: el no-show de la demo DIRECTA
 *     (`demo_directa_no_show_minutos`). Desde el 13/10/2026 el lead CONSERVA su turno y además se
 *     le pregunta "¿pudiste entrar?" con la plantilla aprobada `cc_check_ingreso_demo`. El detalle
 *     y el por qué están en el docblock de ese método.
 */
class CheckDemoIngresoTimeout extends Command
{
    /**
     * Plantilla Meta aprobada: "¡Hola {{1}}! ¿Cómo vas? ¿Pudiste entrar a la demo?".
     *
     * Es LA MISMA que manda `CheckDemoIngresoPostVideo` (misión demo-agendado-directo, 10/9/2026),
     * a propósito: el mensaje al lead es el mismo, cambia nada más el disparador (terminar el video
     * allá, el no-show acá). No se crea ninguna plantilla nueva.
     */
    private const TEMPLATE_CHECK_INGRESO = 'cc_check_ingreso_demo';

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-ingreso-timeout';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a demo_pendiente_de_ingreso los leads que no entraron de verdad a la demo en el tiempo límite';

    /**
     * Servicio de envío saliente vía Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $whatsapp_send_service;

    /**
     * Mismo patrón que `CheckDemoIngresoPostVideo`: el servicio se inyecta para que un test pueda
     * espiar los envíos con `$this->app->instance()`, y si nadie lo inyecta se arma uno real.
     *
     * @param WhatsappSendService|null $whatsapp_send_service Inyección opcional (tests).
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        parent::__construct();
        $this->whatsapp_send_service = $whatsapp_send_service !== null ? $whatsapp_send_service : new WhatsappSendService();
    }

    /**
     * Procesa los leads con timeout de ingreso superado.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Minutos de espera antes de considerar el ingreso fallido, desde demo_start_time. */
        $timeout_minutos = LeadDemoSettings::get_ingreso_timeout_minutos();

        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /* Buscar leads en demo_agendada que no confirmaron el ingreso y aún no fueron notificados. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_ingreso_demo', true)
            // Mismo gate que usaba el extinto CheckDemoIngress (misión demo-v2-estados-automaticos,
            // 4/9/2026) para no competir con una sugerencia del agente en vuelo. Tiene más sentido
            // ahora que nunca hay un mensaje de por medio que "reinicie el reloj".
            ->where('tiene_sugerencia_pendiente', false)
            ->where('demo_ingreso_confirmado', false)
            ->where('demo_no_ingreso_notificado', false)
            /* Ventana extendida afuera (misión 47): a un lead al que le ofrecimos "de 20:00 a
             * 23:59, entrá cuando puedas" este comando no puede mandarlo a demo_pendiente_de_ingreso
             * mientras su ventana sigue abierta.
             *
             * Las dos condiciones, por el mismo motivo de siempre: `demo_flexible` es una columna
             * preexistente que Lucas también marca a mano en leads de la dinámica actual, y esos
             * tienen que seguir pasando por acá. */
            ->where(function ($query) {
                $query->where('demo_flexible', false)
                    ->orWhere(function ($otra_dinamica) {
                        $otra_dinamica->where('demo_experiencia', '!=', Lead::EXPERIENCIA_NUEVA)
                            ->orWhereNull('demo_experiencia');
                    });
            })
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        /* Contador de leads procesados para el log final. */
        $processed = 0;

        foreach ($candidates as $lead) {
            /* Construir datetime de inicio de demo en timezone Argentina. */
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                (string) $lead->demo_start_time
            );

            if ($demo_datetime === null) {
                continue;
            }

            /* El timeout vence cuando demo_start_time + timeout_minutos ya pasó. Ya no se corre la
             * referencia por el último mensaje del lead (así funcionaba con el check de ingreso
             * que se mandaba por WhatsApp): esa lógica existía para no interrumpir una conversación
             * activa sobre un mensaje que ya no se manda, y deja de tener sentido (misión
             * demo-v2-estados-automaticos, 4/9/2026). */
            if ($demo_datetime->copy()->addMinutes($timeout_minutos)->gt($now)) {
                continue;
            }

            /*
             * Pasar el lead a demo_pendiente_de_ingreso y marcar el flag anti-duplicado -- con un
             * UPDATE condicionado en la base, no un update() sobre el objeto en memoria. El loop
             * hace llamadas de red por cada lead (Google Calendar + WhatsApp) y puede tardar
             * segundos entre candidatos: para cuando le toca el turno a este lead, el evento real
             * `demo.ingreso` (DemoEventosController::avanzar_pipeline_por_ingreso_real(), que corre
             * en un request HTTP totalmente aparte) puede haberlo adelantado a demo_en_curso
             * mientras tanto. Un update() sobre `$lead` -- que sigue teniendo el status viejo en
             * memoria -- pisaría ese avance real de vuelta a demo_pendiente_de_ingreso.
             *
             * Mismo patrón que ya usa este repo para la misma carrera (RunDemoSetupService::
             * mark_failed()): la condición viaja ADENTRO del UPDATE, y la cantidad de filas
             * afectadas decide si corresponde seguir con los efectos secundarios.
             */
            $filas_afectadas = Lead::query()
                ->whereKey($lead->id)
                ->where('status', 'demo_agendada')
                ->update([
                    'status'                     => 'demo_pendiente_de_ingreso',
                    'demo_no_ingreso_notificado' => true,
                ]);

            if ($filas_afectadas === 0) {
                /* El lead ya cambió de estado por otro camino (típicamente el ingreso real)
                 * mientras este comando procesaba la cola. No es una falla, es la carrera resuelta
                 * bien: no hay que liberar el hold del closer ni notificar "no ingresó" sobre un
                 * lead que sí entró. */
                Log::info('CheckDemoIngresoTimeout: lead cambió de estado mientras se procesaba (carrera con el ingreso real), se saltea.', [
                    'lead_id' => $lead->id,
                ]);

                continue;
            }

            /*
             * Liberar la reserva preventiva del closer (grupo 306, prompt 05, correctivo del
             * 3/8/2026): este es el timeout de ingreso REAL — el lead se hizo fantasma y nadie lo
             * marcó a mano. demo_date no se toca en este comando (el lead vuelve a
             * demo_pendiente_de_ingreso con la misma demo cargada), así que $lead->fresh() todavía
             * tiene la fecha para invalidar la caché correcta; no hace falta pasarla explícita.
             * Best-effort: una falla de Google no puede frenar el procesamiento del resto de la
             * cola de timeouts.
             */
            try {
                $oauth_service_hold = app(GoogleCalendarOAuthService::class);
                (new CloserGoogleCalendarEventService(
                    $oauth_service_hold,
                    new CloserGoogleCalendarBusyService($oauth_service_hold)
                ))->release_hold_for_lead($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngresoTimeout: error al liberar la reserva preventiva del closer.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            /* Notificar a admins suscritos vía WhatsApp que el lead no ingresó por timeout. */
            try {
                $ciclo_service = new DemoCicloAdminNotificationService($this->whatsapp_send_service);
                $ciclo_service->notify_no_ingreso($lead->fresh(), 'no entró a la demo dentro del tiempo límite');
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngresoTimeout: error al notificar no_ingreso a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            /* Notificar a admin-spa vía socket. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoIngresoTimeout: lead pasó a demo_pendiente_de_ingreso por timeout', [
                'lead_id'        => $lead->id,
                'contact_name'   => $lead->contact_name,
                'demo_datetime'  => $demo_datetime->toDateTimeString(),
                'timeout_minutos' => $timeout_minutos,
            ]);

            $processed++;
        }

        $processed += $this->vencer_demos_directas_sin_ingreso($now);

        $this->info("Timeouts de ingreso procesados: {$processed}");

        return 0;
    }

    /**
     * Demo DIRECTA (misión demo-agendado-directo, 10/9/2026): el lead que dijo "sí" y no entró en
     * N minutos desde el inicio (setting `demo_directa_no_show_minutos`, default 60) pasa a
     * `demo_pendiente_de_ingreso` y se le pregunta si pudo entrar — pero CONSERVA su turno.
     *
     * 🔴 Cambio del 13/10/2026 (misión demo-dos-horas-y-experiencia): este método ya NO limpia el
     * turno. Hasta hoy nuleaba `demo_id`, `demo_date`, `demo_start_time`, `demo_end_time`,
     * `demo_flexible` y volvía `demo_setup_status` a `pendiente`, con el argumento de que una
     * ventana extendida de SEIS horas no podía quedar tomada por un "dale" que nunca entró (con
     * tres instancias, tres no-shows a la tarde dejaban al cuarto lead sin lugar).
     *
     * Con la ventana en DOS horas ese argumento se cae, y lo que queda es el costo: al lead se le
     * dijo *"la tenés reservada dos horas, hasta las HH:MM"* y a los 60 minutos le vaciábamos el
     * turno, así que el botón de su página se apagaba y el link le daba vencido justo cuando se
     * sentaba. Ahora el turno queda intacto: la instancia sigue reservada hasta el fin REAL de la
     * ventana —`max(fin + gracia, inicio + bloqueo_real)`, 130 minutos con los valores de hoy, el
     * mismo cálculo que hace `DemoDirectaService::fines_solapados()`— y el lead puede entrar en el
     * minuto 90 por su propio botón. No hace falta tocar ningún gate para eso:
     * `DemoExperienciaController::evaluar_ingreso()` no mira el `status`, y
     * `DemoEventosController::ESTADOS_DESDE_LOS_QUE_AVANZA_POR_INGRESO_REAL` incluye
     * `demo_pendiente_de_ingreso`, así que el evento real lo lleva a `demo_en_curso` igual.
     *
     * 💡 Y arregla de rebote un lead varado que nadie había visto: `CheckDemoPendienteIngresoTimeout`
     * —el que revierte a `calificado` a las N horas— filtra por `whereNotNull('demo_date')`. Al
     * nulear `demo_date`, este comando dejaba a esos leads FUERA de esa query para siempre: se
     * quedaban en `demo_pendiente_de_ingreso` sin revert y sin que la instancia 4 de seguimiento los
     * retomara. Conservar el turno los devuelve a esa cola.
     *
     * Lo que sigue igual: pasa a `demo_pendiente_de_ingreso`, marca `demo_no_ingreso_notificado`
     * (una vez por lead) y avisa a admins. Lo que se agrega: el "¿pudiste entrar?" por la plantilla
     * aprobada `cc_check_ingreso_demo`, la misma de `CheckDemoIngresoPostVideo`, con su mismo flag
     * anti-duplicado (`demo_check_ingreso_enviado`) y su misma ventana de silencio — si el lead no
     * vio el video, ese comando nunca le pregunta, y el no-show es el otro momento en que
     * corresponde preguntar.
     *
     * Y el que SÍ sigue soltando el turno es `marcar_no_ingreso` (el botón del panel): esa es una
     * decisión humana —"este no entra más"— y no un reloj.
     *
     * Sólo la demo directa (ventana extendida de la dinámica nueva): los flexibles de la dinámica
     * actual son el checkbox manual de Lucas y no pasan por acá.
     *
     * @param Carbon $now
     *
     * @return int Leads vencidos.
     */
    private function vencer_demos_directas_sin_ingreso(Carbon $now): int
    {
        $no_show_minutos = LeadDemoSettings::get_demo_directa_no_show_minutos();

        $candidatas = Lead::query()
            ->where('status', 'demo_agendada')
            ->where('demo_experiencia', Lead::EXPERIENCIA_NUEVA)
            ->where('demo_flexible', true)
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_ingreso_demo', true)
            ->where('tiene_sugerencia_pendiente', false)
            ->where('demo_ingreso_confirmado', false)
            ->where('demo_no_ingreso_notificado', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        $vencidas = 0;

        foreach ($candidatas as $lead) {
            $inicio = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                (string) $lead->demo_start_time
            );
            if ($inicio === null || $inicio->copy()->addMinutes($no_show_minutos)->gt($now)) {
                continue;
            }

            /* Mismo UPDATE condicionado que el timeout de arriba, por la misma carrera con el
             * evento real de ingreso. El turno NO se toca (ver el docblock): la instancia sigue
             * reservada y el lead puede entrar más tarde con el mismo link. */
            $filas_afectadas = Lead::query()
                ->whereKey($lead->id)
                ->where('status', 'demo_agendada')
                ->update([
                    'status'                     => 'demo_pendiente_de_ingreso',
                    'demo_no_ingreso_notificado' => true,
                ]);

            if ($filas_afectadas === 0) {
                Log::info('CheckDemoIngresoTimeout: demo directa cambió de estado mientras se procesaba (carrera con el ingreso real), se saltea.', [
                    'lead_id' => $lead->id,
                ]);

                continue;
            }

            /* Hasta cuándo sigue reservada de verdad. Se calcula con el lead de la base (que
             * conserva su turno) y es lo que se le informa a los admins: el aviso viejo decía "la
             * instancia quedó libre", que desde hoy sería falso. */
            $reservada_hasta = $this->fin_real_de_la_ventana($lead->fresh());

            $motivo = 'no entró a la demo directa en ' . $no_show_minutos . ' minutos; la instancia'
                . ' sigue reservada' . ($reservada_hasta !== null ? ' hasta las ' . $reservada_hasta->format('H:i') : '')
                . ' y puede entrar con el mismo link';

            try {
                $ciclo_service = new DemoCicloAdminNotificationService($this->whatsapp_send_service);
                $ciclo_service->notify_no_ingreso($lead->fresh(), $motivo);
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngresoTimeout: error al notificar no_ingreso (demo directa) a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            $this->preguntar_si_pudo_entrar($lead->fresh(), $now);

            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoIngresoTimeout: demo directa vencida por no-show, el turno se conserva', [
                'lead_id'         => $lead->id,
                'contact_name'    => $lead->contact_name,
                'demo_id'         => $lead->demo_id,
                'inicio'          => $inicio->toDateTimeString(),
                'no_show_minutos' => $no_show_minutos,
                'reservada_hasta' => $reservada_hasta !== null ? $reservada_hasta->toDateTimeString() : null,
            ]);

            $vencidas++;
        }

        return $vencidas;
    }

    /**
     * Fin REAL de la reserva de la instancia de un lead: `max(fin nominal + gracia, inicio +
     * bloqueo_real)`.
     *
     * Es el mismo criterio —a propósito, el mismo que hay que cambiar si alguna vez cambia— que
     * usan `DemoDirectaService::fines_solapados()` (para decidir si la instancia está libre para
     * otro lead), `DemoIngresoTokenService::calcular_expiracion()` (hasta cuándo vale el link) y
     * `DemoExperienciaController::build_turno()` (hasta cuándo el botón deja entrar). Con los
     * valores del 13/10/2026 —ventana de dos horas (fin nominal = inicio + 120), gracia 10, bloqueo
     * real 120— da inicio + 130 minutos.
     *
     * @param Lead|null $lead Lead con su turno cargado.
     *
     * @return Carbon|null Null si el lead perdió el turno o la hora no parsea.
     */
    private function fin_real_de_la_ventana(?Lead $lead): ?Carbon
    {
        if ($lead === null || $lead->demo_date === null) {
            return null;
        }

        $fecha  = $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d');
        $inicio = $this->parse_demo_datetime($fecha, (string) $lead->demo_start_time);
        if ($inicio === null) {
            return null;
        }

        $fin_nominal = $this->parse_demo_datetime($fecha, (string) $lead->demo_end_time);
        if ($fin_nominal === null) {
            $fin_nominal = $inicio->copy()->addMinutes(LeadDemoSettings::get_duracion_minutos());
        }

        $por_gracia  = $fin_nominal->copy()->addMinutes(LeadDemoSettings::get_gracia_minutos_post());
        $por_bloqueo = $inicio->copy()->addMinutes(LeadDemoSettings::get_bloqueo_real_minutos());

        return $por_bloqueo->gt($por_gracia) ? $por_bloqueo : $por_gracia;
    }

    /**
     * El "¿pudiste entrar?" del no-show, por la plantilla aprobada `cc_check_ingreso_demo`
     * (13/10/2026).
     *
     * Es el mismo mensaje y el mismo mecanismo que `CheckDemoIngresoPostVideo`: misma plantilla,
     * mismo texto, mismo flag anti-duplicado (`demo_check_ingreso_enviado`, que es además el que
     * lee `WhatsappWebhookController::handle_demo_confirmation_if_needed()` para interpretar el "sí"
     * o el "no" que conteste el lead) y misma ventana de silencio
     * (`check_ingreso_silencio_minutos`). Los dos comandos comparten el flag justamente para no
     * preguntarle dos veces: el que llegue primero manda, el otro ve el flag en true y no insiste.
     *
     * 🔴 Dos guardas que cortan el mensaje pero NO el vencimiento, que corre igual:
     *
     *   - `demo_setup_status !== 'exitoso'`: si la demo no se armó, el botón nunca se habilitó.
     *     Preguntarle si pudo entrar sería preguntarle por algo que no pudo hacer, y encima
     *     echándole la culpa. Misma guarda que el comando post-video, por el mismo motivo.
     *   - Conversación en los últimos N minutos, en cualquier dirección: si estamos hablando con
     *     el lead, una plantilla automática pisa la conversación. A diferencia del post-video acá
     *     no se reintenta en el próximo tick (el vencimiento es de una sola vez), así que ese lead
     *     queda sin el check — es el lado barato: alguien ya está hablando con él.
     *
     * Los eventos técnicos del hilo (`is_status_event`) no cuentan como conversación, igual que en
     * el post-video: "terminó el video" o un bloque de error no son "hablar con el lead".
     *
     * @param Lead|null $lead Lead ya pasado a demo_pendiente_de_ingreso.
     * @param Carbon    $now  Reloj del comando.
     *
     * @return bool true si el mensaje salió.
     */
    private function preguntar_si_pudo_entrar(?Lead $lead, Carbon $now): bool
    {
        if ($lead === null || (bool) $lead->demo_check_ingreso_enviado) {
            return false;
        }

        if ((string) $lead->demo_setup_status !== 'exitoso') {
            Log::info('CheckDemoIngresoTimeout: no-show sin check de ingreso, la demo no se armó.', [
                'lead_id'           => $lead->id,
                'demo_setup_status' => (string) $lead->demo_setup_status,
            ]);

            return false;
        }

        $silencio_limite = $now->copy()->subMinutes(LeadDemoSettings::get_check_ingreso_silencio_minutos());

        $hubo_conversacion = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where(function ($query) {
                $query->whereNull('is_status_event')->orWhere('is_status_event', false);
            })
            ->where('created_at', '>', $silencio_limite)
            ->exists();

        if ($hubo_conversacion) {
            Log::info('CheckDemoIngresoTimeout: no-show sin check de ingreso, hay conversación reciente.', [
                'lead_id' => $lead->id,
            ]);

            return false;
        }

        /* Primer nombre, no el completo (decisión de Lucas, 8/9/2026). */
        $contact_name = trim((string) $lead->contact_first_name);
        if ($contact_name === '') {
            $contact_name = 'cliente';
        }
        $content = "¡Hola {$contact_name}! ¿Cómo vas? ¿Pudiste entrar a la demo?";

        $whatsapp_message_id = null;
        $phone = trim((string) $lead->phone);
        if ($phone !== '') {
            $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                $phone,
                self::TEMPLATE_CHECK_INGRESO,
                [$contact_name],
                'es_AR',
                "Check de ingreso por no-show - Lead #{$lead->id} ({$lead->contact_name})"
            );
        } else {
            Log::warning('CheckDemoIngresoTimeout: lead sin teléfono, no se manda el check de ingreso', ['lead_id' => $lead->id]);
        }

        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'status'              => 'enviado',
            'is_followup'         => false,
            'content'             => $content,
            'whatsapp_message_id' => $whatsapp_message_id,
        ]);

        $lead->update([
            'demo_check_ingreso_enviado'    => true,
            'demo_check_ingreso_enviado_at' => $now->copy(),
        ]);

        try {
            $ciclo_service = new DemoCicloAdminNotificationService($this->whatsapp_send_service);
            $ciclo_service->notify_check_ingreso_enviado($lead->fresh());
        } catch (\Throwable $e) {
            Log::error('CheckDemoIngresoTimeout: error al notificar check_ingreso_enviado a admins.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        Log::info('CheckDemoIngresoTimeout: check de ingreso enviado por no-show', [
            'lead_id'      => $lead->id,
            'contact_name' => $lead->contact_name,
        ]);

        return true;
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en texto libre.
     *
     * @return Carbon|null
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        try {
            return Carbon::parse("{$date} {$time}");
        } catch (\Exception $e) {
            return null;
        }
    }
}
