<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadScheduledMessage;
use Carbon\Carbon;

/**
 * Seguimientos automáticos del embudo de WhatsApp (misión embudo-whatsapp-precios, etapa E3, PLAN §2.3).
 *
 * Cada estado del embudo tiene sus pasos de seguimiento, con un desfase en horas desde el ANCLA. Se programan sobre
 * `LeadScheduledMessage` (sin motor nuevo): son filas con `paso_embudo` y `estado_lead_al_programar`, sin autor, que despacha
 * el mismo comando de siempre (`leads:send-scheduled-messages`), con tres frenos propios en
 * {@see LeadScheduledMessageService} (flujo apagado, cambio de estado y conversación pendiente).
 *
 *   | Estado        | Paso               | Desfase | Modo                                         |
 *   | nuevo         | nuevo_3h           | 3 h     | texto libre                                  |
 *   | nuevo         | nuevo_22h          | 22 h    | texto libre (las dos formas de arrancar)     |
 *   | contactado    | conversando_22h    | 22 h    | texto libre                                  |
 *   | contactado    | conversando_dia_3  | 72 h    | plantilla (sólo si hay nombre configurado)   |
 *   | link_enviado  | link_2h            | 2 h     | texto libre                                  |
 *   | link_enviado  | link_22h           | 22 h    | texto libre                                  |
 *   | link_enviado  | link_dia_3         | 72 h    | plantilla (sólo si hay nombre configurado)   |
 *
 * El ancla es el momento en que ocurrió lo que arma el paso (salió `llegada_2`, salió el link) o, cuando lo que dispara es un
 * mensaje del lead, ese mensaje: cada inbound de un lead en `contactado` o `link_enviado` vuelve a anclar los pasos.
 *
 * Reglas de hora de cada paso:
 *  - un paso de TEXTO LIBRE tiene que caer antes de que cierre la ventana de 24 h de Meta (contada desde el último inbound);
 *    si no, no se programa;
 *  - todo paso cae dentro de la franja de seguimientos ({@see FranjaHorariaDeSeguimientos}): fuera de ella se corre al próximo
 *    inicio de franja y, si eso saca a un texto libre de la ventana, se adelanta a la última hora de franja que quede dentro.
 *
 * Sólo se programa para un lead que está «en el flujo» de entrada ({@see LeadFlujoEntradaService::en_el_flujo()}): con el
 * interruptor apagado no se programa nada (y el despacho cancela lo que ya estuviera programado).
 */
class LeadEmbudoSeguimientosService
{
    /** Modo de un paso: texto libre dentro de la ventana de 24 h. */
    private const TEXTO = 'texto';

    /** Modo de un paso: plantilla de Meta (puede salir con la ventana cerrada). */
    private const PLANTILLA = 'plantilla';

    /** Prefijo de la clave de `admin_settings` que pisa el texto de un paso. */
    private const PREFIJO_TEXTO = 'lead_embudo_paso_';

    /** Prefijo de la clave de `admin_settings` con el nombre de la plantilla de un paso. */
    private const PREFIJO_PLANTILLA = 'lead_embudo_plantilla_';

    /** Minutos que se pospone un paso retenido por una conversación pendiente. */
    public const MINUTOS_DE_ESPERA = 60;

    /** Nombre que se usa en la plantilla cuando no se sabe cómo se llama el lead. */
    private const NOMBRE_GENERICO = 'qué tal';

    /**
     * Los pasos de cada estado, en orden. `horas` es el desfase desde el ancla; `modo` es texto libre o plantilla.
     *
     * @var array<string, array<int, array{paso: string, horas: int, modo: string}>>
     */
    public const PASOS = [
        'nuevo' => [
            ['paso' => 'nuevo_3h', 'horas' => 3, 'modo' => self::TEXTO],
            ['paso' => 'nuevo_22h', 'horas' => 22, 'modo' => self::TEXTO],
        ],
        'contactado' => [
            ['paso' => 'conversando_22h', 'horas' => 22, 'modo' => self::TEXTO],
            ['paso' => 'conversando_dia_3', 'horas' => 72, 'modo' => self::PLANTILLA],
        ],
        'link_enviado' => [
            ['paso' => 'link_2h', 'horas' => 2, 'modo' => self::TEXTO],
            ['paso' => 'link_22h', 'horas' => 22, 'modo' => self::TEXTO],
            ['paso' => 'link_dia_3', 'horas' => 72, 'modo' => self::PLANTILLA],
        ],
    ];

    /**
     * Textos por defecto de los pasos de texto libre (borradores: se pisan desde `admin_settings`, clave
     * `lead_embudo_paso_<paso>`). `{link_prueba}` se reemplaza por el link de la prueba. `nuevo_22h` no está acá: es
     * `respuesta_1` (las dos formas de arrancar) tal cual.
     *
     * @var array<string, string>
     */
    private const TEXTOS = [
        'nuevo_3h'        => '¿Pudiste ver mi mensaje? Contame tu rubro y más o menos cuántos productos manejás y te digo cómo arrancar.',
        'conversando_22h' => '¿Pudiste pensarlo? ¿Arrancás por tu cuenta con la prueba gratis o preferís que te lo dejemos cargado?',
        'link_2h'         => '¿Pudiste crear tu prueba? Te dejo el link de nuevo: {link_prueba}',
        'link_22h'        => '¿Pudiste crear tu prueba? Te dejo el link de nuevo: {link_prueba}',
    ];

    // ------------------------------------------------------------------------------------------
    // Programar
    // ------------------------------------------------------------------------------------------

    /**
     * Deja programados los pasos del estado ACTUAL del lead y cancela los pendientes de cualquier otro estado.
     *
     * Idempotente: un paso que ya está pendiente se mueve a su hora nueva en lugar de duplicarse (así cada inbound re-ancla
     * sin dejar filas canceladas de más). No hace nada si el lead no está en el flujo, ya es cliente, no tiene teléfono o su
     * estado no tiene pasos.
     *
     * @param Lead        $lead      Lead con su estado al día.
     * @param Carbon|null $ancla     Momento del que se cuentan los desfases; null = su último mensaje (o ahora, si no escribió).
     * @param bool        $reiniciar false = si el paso ya tiene una fila (en cualquier estado de la fila) no se vuelve a
     *                               programar: lo usan los enganches de `nuevo`, que no se repiten.
     *
     * @return void
     */
    public static function programar_para_estado(Lead $lead, ?Carbon $ancla = null, bool $reiniciar = true): void
    {
        if (! LeadFlujoEntradaService::en_el_flujo($lead) || $lead->promoted_client_id !== null || trim((string) $lead->phone) === '') {
            return;
        }

        $estado = (string) $lead->status;

        /* Lo programado para otro estado ya no corresponde: el lead cambió de estado. */
        self::cancelar_pendientes_de_otros_estados($lead, $estado);

        if (! isset(self::PASOS[$estado])) {
            return;
        }

        $ahora = AppTime::now();
        $ancla = $ancla !== null ? $ancla->copy() : self::ultimo_mensaje_del_lead($lead, $ahora);
        $expira = self::cuando_cierra_la_ventana($lead);

        foreach (self::PASOS[$estado] as $definicion) {
            $paso = $definicion['paso'];
            $es_texto = $definicion['modo'] === self::TEXTO;

            /* Un paso de plantilla sólo existe si tiene nombre configurado. */
            $plantilla = $es_texto ? '' : self::nombre_de_la_plantilla($paso);
            if (! $es_texto && $plantilla === '') {
                continue;
            }

            $existente = LeadScheduledMessage::query()
                ->where('lead_id', (int) $lead->id)
                ->where('paso_embudo', $paso)
                ->where('status', LeadScheduledMessage::STATUS_PENDIENTE)
                ->first();

            if ($existente === null && ! $reiniciar
                && LeadScheduledMessage::query()->where('lead_id', (int) $lead->id)->where('paso_embudo', $paso)->exists()) {
                continue;
            }

            $cuando = self::momento_del_paso($ancla->copy()->addHours($definicion['horas']), $es_texto, $expira, $ahora);

            if ($cuando === null) {
                /* No cabe (fuera de la ventana de 24 h): no se programa y, si había uno pendiente, se saca. */
                if ($existente !== null) {
                    self::cancelar($existente, LeadScheduledMessage::CANCELED_REANCLADO);
                }
                continue;
            }

            $contenido = $es_texto ? self::texto_del_paso($paso) : self::cuerpo_de_la_plantilla($plantilla, $lead);

            if ($existente !== null) {
                $existente->scheduled_send_at = $cuando;
                $existente->content           = $contenido;
                $existente->save();
                continue;
            }

            LeadScheduledMessage::create([
                'lead_id'                  => (int) $lead->id,
                'scheduled_send_at'        => $cuando,
                'mode'                     => $es_texto ? LeadScheduledMessage::MODE_TEXTO_LIBRE : LeadScheduledMessage::MODE_PLANTILLA,
                'content'                  => $contenido,
                'template_name'            => $es_texto ? null : $plantilla,
                'template_language'        => $es_texto ? null : self::idioma_de_la_plantilla($plantilla),
                'template_variables'       => $es_texto ? null : [self::nombre_del_lead($lead)],
                'cancel_if_lead_replies'   => false,
                'baseline_lead_message_id' => self::id_del_ultimo_mensaje_del_lead($lead),
                'status'                   => LeadScheduledMessage::STATUS_PENDIENTE,
                'created_by_admin_id'      => null,
                'paso_embudo'              => $paso,
                'estado_lead_al_programar' => $estado,
            ]);
        }
    }

    /**
     * Lo que pasa cuando el lead escribe: si ya tiene pasos del embudo y está en `contactado` o `link_enviado`, los vuelve a
     * anclar en ese mensaje (y, si venía de otro estado, cancela los de antes). Lo llama el webhook en cada inbound.
     *
     * @param Lead $lead Lead con su estado al día (después de pasar a `contactado`, si le tocaba).
     *
     * @return void
     */
    public static function al_llegar_un_mensaje(Lead $lead): void
    {
        if (! in_array((string) $lead->status, ['contactado', 'link_enviado'], true) || ! self::tiene_pasos($lead)) {
            return;
        }

        self::programar_para_estado($lead, null);
    }

    /**
     * Lo que pasa cuando sale un mensaje nuestro por la conversación (la IA o el setter): si contiene `llegada_2` y el lead
     * sigue en `nuevo`, ese es el momento en que se programan los pasos de `nuevo`. El camino literal de la llegada llama a
     * {@see programar_para_estado()} por su cuenta (`LeadWhatsappOnboardingService::enviar_puntos_del_flujo`).
     *
     * @param Lead   $lead
     * @param string $texto Texto que salió.
     *
     * @return void
     */
    public static function al_salir_un_mensaje(Lead $lead, string $texto): void
    {
        if ((string) $lead->status !== 'nuevo' || ! LeadFlujoEntradaService::cumple_el_ancla('prueba_y_rubro', $texto)) {
            return;
        }

        self::programar_para_estado($lead, AppTime::now(), false);
    }

    /**
     * ¿El lead tiene o tuvo algún paso del embudo?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function tiene_pasos(Lead $lead): bool
    {
        return LeadScheduledMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('paso_embudo')->exists();
    }

    /**
     * ¿El lead está en un estado con pasos y tiene alguna fila de paso? Es lo que el cron viejo de seguimientos mira para no
     * mandar una segunda cadencia encima de la nueva.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function lo_lleva_el_embudo(Lead $lead): bool
    {
        return isset(self::PASOS[(string) $lead->status]) && self::tiene_pasos($lead);
    }

    // ------------------------------------------------------------------------------------------
    // Despacho
    // ------------------------------------------------------------------------------------------

    /**
     * La hora a la que se pospone un paso retenido por una conversación pendiente: dentro de 60 minutos, respetando la franja y,
     * si es de texto libre, la ventana de 24 h.
     *
     * @param LeadScheduledMessage $paso
     * @param Lead                 $lead
     *
     * @return Carbon|null Null si ya no entra (el despacho lo cancela).
     */
    public static function momento_pospuesto(LeadScheduledMessage $paso, Lead $lead): ?Carbon
    {
        $es_texto = (string) $paso->mode === LeadScheduledMessage::MODE_TEXTO_LIBRE;
        $ahora    = AppTime::now();
        $cuando   = $ahora->copy()->addMinutes(self::MINUTOS_DE_ESPERA);
        $expira   = $es_texto ? self::cuando_cierra_la_ventana($lead) : null;

        if ($es_texto && ($expira === null || $cuando->gte($expira))) {
            return null;
        }

        if (! FranjaHorariaDeSeguimientos::esta_dentro($cuando)) {
            $cuando = FranjaHorariaDeSeguimientos::momento_para_reprogramar($cuando);
            if ($es_texto && $cuando->gte($expira)) {
                return null;
            }
        }

        return $cuando;
    }

    /**
     * ¿Hay una conversación a medio contestar? Una pregunta del lead sin respuesta, una sugerencia de Martín sin enviar o una
     * generación de la IA agendada o en curso. En ese caso el paso espera: lo que Martín está por decir va primero.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function hay_conversacion_pendiente(Lead $lead): bool
    {
        return LeadFlujoEntradaService::hay_pregunta_sin_contestar($lead)
            || LeadFlujoEntradaService::hay_sugerencia_sin_enviar($lead)
            || (new LeadAiSuggestionScheduler())->hay_generacion_agendada((int) $lead->id);
    }

    // ------------------------------------------------------------------------------------------
    // Textos
    // ------------------------------------------------------------------------------------------

    /**
     * El texto de un paso de texto libre: el que se pisó en `admin_settings` (`lead_embudo_paso_<paso>`) o el de por defecto,
     * con el link de la prueba puesto. `nuevo_22h` es `respuesta_1`, las dos formas de arrancar, tal cual.
     *
     * @param string $paso Clave del paso.
     *
     * @return string
     */
    public static function texto_del_paso(string $paso): string
    {
        $guardado = trim((string) AdminSetting::get(self::PREFIJO_TEXTO . $paso, ''));

        if ($guardado !== '') {
            $texto = $guardado;
        } elseif ($paso === 'nuevo_22h') {
            $texto = LeadFlujoEntradaService::texto_del_punto('dos_formas');
        } else {
            $texto = isset(self::TEXTOS[$paso]) ? self::TEXTOS[$paso] : '';
        }

        return str_replace('{link_prueba}', LeadOfertaDePruebaService::link(), $texto);
    }

    /**
     * El nombre de la plantilla configurada para un paso de día 3 (`lead_embudo_plantilla_<paso>`); '' = sin plantilla.
     *
     * @param string $paso
     *
     * @return string
     */
    private static function nombre_de_la_plantilla(string $paso): string
    {
        return trim((string) AdminSetting::get(self::PREFIJO_PLANTILLA . $paso, ''));
    }

    /**
     * El cuerpo de la plantilla ya con el nombre del lead: es lo que queda escrito en el hilo cuando sale.
     *
     * @param string $plantilla Nombre de la plantilla de Meta.
     * @param Lead   $lead
     *
     * @return string
     */
    private static function cuerpo_de_la_plantilla(string $plantilla, Lead $lead): string
    {
        $fila = FollowupTemplate::query()->where('template_name', $plantilla)->first();

        if ($fila === null || trim((string) $fila->body_template) === '') {
            return 'Plantilla ' . $plantilla;
        }

        return str_replace('{{1}}', self::nombre_del_lead($lead), (string) $fila->body_template);
    }

    /**
     * El idioma de la plantilla registrada (es_AR si no está registrada).
     *
     * @param string $plantilla
     *
     * @return string
     */
    private static function idioma_de_la_plantilla(string $plantilla): string
    {
        $fila = FollowupTemplate::query()->where('template_name', $plantilla)->first();

        return $fila !== null && trim((string) $fila->language_code) !== '' ? (string) $fila->language_code : 'es_AR';
    }

    /**
     * Cómo se le habla al lead en la variable `{{1}}` de una plantilla: su nombre de pila, o una fórmula neutra.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private static function nombre_del_lead(Lead $lead): string
    {
        $nombre = trim((string) $lead->contact_name);
        if ($nombre === '') {
            return self::NOMBRE_GENERICO;
        }

        $partes = preg_split('/\s+/u', $nombre);

        return $partes !== false && isset($partes[0]) && $partes[0] !== '' ? $partes[0] : self::NOMBRE_GENERICO;
    }

    // ------------------------------------------------------------------------------------------
    // Horas
    // ------------------------------------------------------------------------------------------

    /**
     * La hora real de un paso: el instante pedido, corrido por la franja y comprobado contra la ventana de 24 h.
     *
     * @param Carbon      $pedido   Ancla + desfase.
     * @param bool        $es_texto true = texto libre (tiene que caer antes de que cierre la ventana).
     * @param Carbon|null $expira   Cierre de la ventana de 24 h; null = cerrada.
     * @param Carbon      $ahora
     *
     * @return Carbon|null Null si no hay hora válida.
     */
    private static function momento_del_paso(Carbon $pedido, bool $es_texto, ?Carbon $expira, Carbon $ahora): ?Carbon
    {
        if ($es_texto && ($expira === null || $pedido->gte($expira))) {
            return null;
        }

        $cuando = $pedido->copy();

        if (! FranjaHorariaDeSeguimientos::esta_dentro($cuando)) {
            $cuando = FranjaHorariaDeSeguimientos::momento_para_reprogramar($cuando);

            /* Correrlo lo sacó de la ventana: se adelanta a la última hora de franja que quede adentro. */
            if ($es_texto && $cuando->gte($expira)) {
                $cuando = self::ultima_hora_de_franja_antes_de($expira);
            }
        }

        if ($cuando === null || $cuando->lte($ahora)) {
            return null;
        }

        return $cuando->copy()->setTimezone(config('app.timezone'));
    }

    /**
     * El comienzo de la última hora de franja que queda antes de `$limite` (por ejemplo las 20:00 de la víspera si el límite
     * es de madrugada).
     *
     * @param Carbon $limite Instante exclusivo.
     *
     * @return Carbon
     */
    private static function ultima_hora_de_franja_antes_de(Carbon $limite): Carbon
    {
        $hora = $limite->copy()->setTimezone(FranjaHorariaDeSeguimientos::ZONA_HORARIA)->subSecond()->startOfHour();

        if (FranjaHorariaDeSeguimientos::esta_dentro($hora)) {
            return $hora;
        }

        $ultima = FranjaHorariaDeSeguimientos::HORA_DE_FIN - 1;

        return (int) $hora->hour < FranjaHorariaDeSeguimientos::HORA_DE_INICIO
            ? $hora->copy()->subDay()->setTime($ultima, 0, 0)
            : $hora->copy()->setTime($ultima, 0, 0);
    }

    /**
     * Cuándo cierra la ventana de 24 h de Meta del lead (con el mismo servicio que usa el despacho).
     *
     * @param Lead $lead
     *
     * @return Carbon|null Null si la ventana está cerrada.
     */
    private static function cuando_cierra_la_ventana(Lead $lead): ?Carbon
    {
        $estado = app(WhatsappSessionWindowService::class)->window_state(trim((string) $lead->phone));

        if (empty($estado['open']) || empty($estado['expires_at'])) {
            return null;
        }

        try {
            return Carbon::parse($estado['expires_at'])->setTimezone(config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * El instante del último mensaje del lead (el ancla por defecto); `$ahora` si nunca escribió.
     *
     * @param Lead   $lead
     * @param Carbon $ahora
     *
     * @return Carbon
     */
    private static function ultimo_mensaje_del_lead(Lead $lead, Carbon $ahora): Carbon
    {
        $ultimo = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'lead')
            ->where('is_status_event', false)
            ->orderByDesc('id')
            ->first();

        return $ultimo !== null && $ultimo->created_at !== null ? Carbon::instance($ultimo->created_at) : $ahora->copy();
    }

    /**
     * El id del último mensaje del lead (para el baseline de la fila programada), o null si nunca escribió.
     *
     * @param Lead $lead
     *
     * @return int|null
     */
    private static function id_del_ultimo_mensaje_del_lead(Lead $lead): ?int
    {
        $id = LeadMessage::query()->where('lead_id', (int) $lead->id)->where('sender', 'lead')->max('id');

        return $id !== null ? (int) $id : null;
    }

    // ------------------------------------------------------------------------------------------
    // Cancelar
    // ------------------------------------------------------------------------------------------

    /**
     * Cancela los pasos pendientes del lead que se programaron para un estado distinto del dado.
     *
     * @param Lead   $lead
     * @param string $estado_actual
     *
     * @return void
     */
    private static function cancelar_pendientes_de_otros_estados(Lead $lead, string $estado_actual): void
    {
        $pendientes = LeadScheduledMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->whereNotNull('paso_embudo')
            ->where('status', LeadScheduledMessage::STATUS_PENDIENTE)
            ->where(function ($query) use ($estado_actual) {
                $query->whereNull('estado_lead_al_programar')->orWhere('estado_lead_al_programar', '!=', $estado_actual);
            })
            ->get();

        foreach ($pendientes as $paso) {
            self::cancelar($paso, LeadScheduledMessage::CANCELED_CAMBIO_DE_ESTADO);
        }
    }

    /**
     * Deja un paso pendiente cancelado con su motivo y avisa a las conversaciones abiertas.
     *
     * @param LeadScheduledMessage $paso
     * @param string               $motivo Uno de los `CANCELED_*` del modelo.
     *
     * @return void
     */
    private static function cancelar(LeadScheduledMessage $paso, string $motivo): void
    {
        $paso->status          = LeadScheduledMessage::STATUS_CANCELADO;
        $paso->canceled_reason = $motivo;
        $paso->save();

        LeadBroadcastService::emit_conversation_updated((int) $paso->lead_id, null);
    }
}
