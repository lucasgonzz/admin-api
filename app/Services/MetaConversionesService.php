<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Jobs\EnviarEventoMetaJob;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conversiones de los leads de anuncio a Meta, por la Conversions API for Business Messaging
 * (misión origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * Contrato con Meta (verificado en su documentación):
 *
 *     POST https://graph.facebook.com/{graph_version}/{dataset_id}/events
 *     Authorization: Bearer {token}
 *     {"data":[{"event_name":"LeadSubmitted","event_time":1760000000,"event_id":"cc-lead-123-leadsubmitted",
 *               "action_source":"business_messaging","messaging_channel":"whatsapp",
 *               "user_data":{"whatsapp_business_account_id":"...","ctwa_clid":"..."}}],
 *      "test_event_code":"TEST123"}
 *
 * `Purchase` lleva además `custom_data: {currency, value}`. Respuesta buena: 200
 * `{events_received, messages, fbtrace_id}`; mala: 4xx `{error: {message, type, code, error_subcode,
 * fbtrace_id}}`.
 *
 * 🔴 **Los eventos son acumulativos.** La demo directa lleva a un lead de `nuevo` a `demo_agendada`
 * en un solo paso (la frase del botón ES un sí): si `LeadSubmitted` saliera solo con `calificado`
 * literal, los leads de anuncio que agendan de una nunca lo mandarían. Cada evento sale al alcanzar
 * su etapa O UNA POSTERIOR, en orden y una sola vez (lo garantiza el único de `lead_eventos_meta`).
 *
 * 🔴 **El token no se loguea nunca ni viaja en la URL.** Va en el header `Authorization: Bearer`:
 * Guzzle copia la URI completa en el mensaje de sus excepciones, y un `?access_token=` terminaría en
 * el log con la primera caída de red (misma regla que `meta_scrape_token`). Y por las dudas, todo
 * mensaje que se escribe en la fila o en el log pasa por {@see sin_token()}.
 *
 * 🔴 **Sin configuración no sale nada.** `META_CAPI_TOKEN` o `META_CAPI_DATASET_ID` vacíos: el
 * evento queda `sin_configurar`, con un warning en el log y CERO llamadas HTTP.
 */
class MetaConversionesService
{
    /** Host de la Graph API de Meta. */
    const URL_GRAPH = 'https://graph.facebook.com';

    /** `action_source` de la CAPI for Business Messaging. */
    const ACTION_SOURCE = 'business_messaging';

    /** `messaging_channel` de los leads de Click-to-WhatsApp. */
    const MESSAGING_CHANNEL = 'whatsapp';

    /** Moneda del `Purchase` cuando el contrato no dice otra (las licencias se pactan en dólares). */
    const MONEDA_POR_DEFECTO = 'USD';

    /**
     * Estados desde los que el lead cuenta como `LeadSubmitted`: calificado y todo lo que viene
     * después en el embudo (cerrado_ganado incluido). Un lead promovido también cuenta.
     */
    const ESTADOS_LEAD_SUBMITTED = [
        'calificado',
        'solicita_disponibilidad',
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'cerrado_ganado',
    ];

    /**
     * Estados desde los que el lead cuenta como `QualifiedLead`: la demo agendada y todo lo que viene
     * después. Meta no tiene un evento de «demo agendada» (informe del 5/10/2026, §10.7).
     */
    const ESTADOS_QUALIFIED_LEAD = [
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'cerrado_ganado',
    ];

    /**
     * ¿Está todo lo necesario para mandar? Token y dataset; el resto tiene default.
     *
     * @return bool
     */
    public static function config_completa(): bool
    {
        return trim((string) config('services.meta_capi.token')) !== ''
            && trim((string) config('services.meta_capi.dataset_id')) !== '';
    }

    /**
     * `event_id` que viaja a Meta para este lead y este evento. Es estable: el mismo lead y el mismo
     * evento dan siempre el mismo, así Meta deduplica un reenvío.
     *
     * @param int    $lead_id
     * @param string $evento
     *
     * @return string
     */
    public static function event_id_para(int $lead_id, string $evento): string
    {
        return 'cc-lead-' . $lead_id . '-' . strtolower($evento);
    }

    /**
     * Eventos que el lead ya alcanzó, en orden.
     *
     * @param Lead $lead
     *
     * @return array<int, string>
     */
    public function eventos_alcanzados(Lead $lead): array
    {
        $status    = (string) $lead->status;
        $promovido = $lead->promoted_client_id !== null && (string) $lead->promoted_client_id !== '';
        $eventos   = [];

        if ($promovido || in_array($status, self::ESTADOS_LEAD_SUBMITTED, true)) {
            $eventos[] = LeadEventoMeta::EVENTO_LEAD_SUBMITTED;
        }
        if ($promovido || in_array($status, self::ESTADOS_QUALIFIED_LEAD, true)) {
            $eventos[] = LeadEventoMeta::EVENTO_QUALIFIED_LEAD;
        }
        if ($promovido) {
            $eventos[] = LeadEventoMeta::EVENTO_PURCHASE;
        }

        return $eventos;
    }

    /**
     * Registra los eventos que el lead alcanzó y todavía no tiene, y encola su envío.
     *
     * Solo para leads con `ctwa_clid`: sin el click id, Meta no tiene cómo atribuir nada y el evento
     * no se registra. Con la configuración incompleta, los eventos quedan `sin_configurar` (con un
     * warning en el log) y no se encola nada.
     *
     * @param Lead        $lead
     * @param Carbon|null $momento   Cuándo alcanzó la etapa (default: ahora).
     * @param bool        $despachar false para registrar sin encolar.
     *
     * @return array<int, LeadEventoMeta> Las filas que se crearon en esta llamada.
     */
    public function registrar_alcanzados(Lead $lead, ?Carbon $momento = null, bool $despachar = true): array
    {
        $config  = self::config_completa();
        $creados = $this->crear_filas_faltantes(
            $lead,
            $config ? LeadEventoMeta::ESTADO_PENDIENTE : LeadEventoMeta::ESTADO_SIN_CONFIGURAR,
            $momento !== null ? $momento : AppTime::now(),
            null
        );

        if (empty($creados)) {
            return [];
        }

        if (! $config) {
            Log::channel('daily')->warning(
                'Conversiones Meta: falta META_CAPI_TOKEN o META_CAPI_DATASET_ID. Los eventos quedan sin_configurar y no sale ninguna llamada.',
                [
                    'lead_id' => (int) $lead->id,
                    'eventos' => array_map(function (LeadEventoMeta $fila) {
                        return $fila->evento;
                    }, $creados),
                ]
            );

            return $creados;
        }

        if ($despachar) {
            foreach ($creados as $fila) {
                $this->despachar($fila);
            }
        }

        return $creados;
    }

    /**
     * Registra como `omitido` cada evento que el lead YA alcanzó y todavía no tiene, sin encolar nada.
     *
     * 🔴 Es lo que usa `leads:calcular-origen --aplicar` cuando le carga el `ctwa_clid` a un lead
     * anterior a la medición. Las etapas que ese lead alcanzó antes de que existiera la medición NO
     * se le mandan a Meta: sería reportar un clic de hace semanas con la fecha de hoy (Purchase
     * incluido). Dejarlas registradas es lo que hace que ni el observer ni el conciliador las vuelvan
     * a registrar como `pendiente` después; y un `omitido` no lo reclama nunca el envío. Las etapas
     * que el lead alcance DESPUÉS sí salen, como las de cualquier lead.
     *
     * @param Lead $lead Con el `ctwa_clid` ya cargado.
     *
     * @return array<int, LeadEventoMeta> Las filas que se crearon en esta llamada.
     */
    public function registrar_omitidos(Lead $lead): array
    {
        return $this->crear_filas_faltantes(
            $lead,
            LeadEventoMeta::ESTADO_OMITIDO,
            AppTime::now(),
            LeadEventoMeta::MOTIVO_OMITIDO
        );
    }

    /**
     * Crea, en orden, las filas de los eventos que el lead alcanzó y no tiene registrados todavía.
     * Solo para leads con `ctwa_clid`: sin el click id, Meta no tiene cómo atribuir nada.
     *
     * @param Lead        $lead
     * @param string      $estado  Estado con el que nacen las filas.
     * @param Carbon      $momento `event_time` de las filas.
     * @param string|null $motivo  Texto para `ultimo_error` (el porqué de un `omitido`).
     *
     * @return array<int, LeadEventoMeta>
     */
    private function crear_filas_faltantes(Lead $lead, string $estado, Carbon $momento, ?string $motivo): array
    {
        if (trim((string) $lead->ctwa_clid) === '' || ! $lead->id) {
            return [];
        }

        $alcanzados = $this->eventos_alcanzados($lead);
        if (empty($alcanzados)) {
            return [];
        }

        $existentes = LeadEventoMeta::query()->where('lead_id', (int) $lead->id)->pluck('evento')->all();
        $faltantes  = array_values(array_diff($alcanzados, $existentes));
        $creados    = [];

        foreach ($faltantes as $evento) {
            try {
                $creados[] = LeadEventoMeta::create([
                    'lead_id'      => (int) $lead->id,
                    'evento'       => $evento,
                    'estado'       => $estado,
                    'event_id'     => self::event_id_para((int) $lead->id, $evento),
                    'event_time'   => $momento,
                    'intentos'     => 0,
                    'ultimo_error' => $motivo,
                ]);
            } catch (QueryException $exception) {
                /* El único (lead_id, evento): otro proceso lo registró entre la lectura y el alta.
                   Es exactamente lo que el único está para frenar; no hay nada que hacer. */
                Log::channel('daily')->info('Conversiones Meta: el evento ya estaba registrado.', [
                    'lead_id' => (int) $lead->id,
                    'evento'  => $evento,
                ]);
            }
        }

        return $creados;
    }

    /**
     * Manda UN evento a Meta y deja el resultado en la fila.
     *
     * 🔴 Reclama la fila con un UPDATE condicional antes de salir a la red: si dos workers agarran el
     * mismo evento (el scheduler no serializa la cola), el que llega segundo afecta cero filas y no
     * manda nada. Mismo patrón que `client_upgrade_notices`.
     *
     * @param LeadEventoMeta $evento
     *
     * @return string El estado en que quedó la fila.
     */
    public function enviar(LeadEventoMeta $evento): string
    {
        if (! self::config_completa()) {
            LeadEventoMeta::query()
                ->whereKey((int) $evento->id)
                ->whereIn('estado', [LeadEventoMeta::ESTADO_PENDIENTE, LeadEventoMeta::ESTADO_SIN_CONFIGURAR])
                ->update(['estado' => LeadEventoMeta::ESTADO_SIN_CONFIGURAR]);

            Log::channel('daily')->warning(
                'Conversiones Meta: falta META_CAPI_TOKEN o META_CAPI_DATASET_ID. El evento no se manda.',
                ['lead_evento_meta_id' => (int) $evento->id, 'lead_id' => (int) $evento->lead_id, 'evento' => $evento->evento]
            );

            return (string) LeadEventoMeta::query()->whereKey((int) $evento->id)->value('estado');
        }

        if (! $this->reclamar($evento)) {
            return (string) LeadEventoMeta::query()->whereKey((int) $evento->id)->value('estado');
        }

        $lead = Lead::query()->find((int) $evento->lead_id);
        if ($lead === null || trim((string) $lead->ctwa_clid) === '') {
            $this->marcar_error($evento, 'El lead ya no existe o no tiene ctwa_clid: Meta no tiene con qué atribuir el evento.', null);

            return LeadEventoMeta::ESTADO_ERROR;
        }

        $token   = trim((string) config('services.meta_capi.token'));
        $url     = $this->url_de_eventos();
        $payload = $this->armar_payload($evento, $lead);

        try {
            $respuesta = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.meta_capi.timeout', 15))
                ->post($url, $payload);
        } catch (\Throwable $exception) {
            $this->marcar_error($evento, 'No se pudo conectar con Meta: ' . $this->sin_token($exception->getMessage()), null);

            return LeadEventoMeta::ESTADO_ERROR;
        }

        $json    = $respuesta->json();
        $resumen = $this->resumen_de_respuesta(is_array($json) ? $json : []);

        if ($respuesta->successful()) {
            LeadEventoMeta::query()->whereKey((int) $evento->id)->update([
                'estado'       => LeadEventoMeta::ESTADO_ENVIADO,
                'enviado_at'   => AppTime::now(),
                'ultimo_error' => null,
                'respuesta'    => json_encode($resumen),
            ]);

            return LeadEventoMeta::ESTADO_ENVIADO;
        }

        $mensaje = is_array($json) && isset($json['error']['message']) && is_string($json['error']['message'])
            ? $json['error']['message']
            : 'sin mensaje';

        $this->marcar_error(
            $evento,
            'Meta rechazó el evento (HTTP ' . $respuesta->status() . '): ' . $this->sin_token($mensaje),
            $resumen
        );

        return LeadEventoMeta::ESTADO_ERROR;
    }

    /**
     * El body que viaja a Meta para este evento. Público para que se pueda mirar sin mandarlo.
     *
     * @param LeadEventoMeta $evento
     * @param Lead           $lead
     *
     * @return array<string, mixed>
     */
    public function armar_payload(LeadEventoMeta $evento, Lead $lead): array
    {
        $momento = $evento->event_time !== null ? $evento->event_time : AppTime::now();

        $dato = [
            'event_name'        => (string) $evento->evento,
            'event_time'        => (int) $momento->getTimestamp(),
            'event_id'          => (string) $evento->event_id,
            'action_source'     => self::ACTION_SOURCE,
            'messaging_channel' => self::MESSAGING_CHANNEL,
            'user_data'         => [
                'whatsapp_business_account_id' => (string) config('services.meta_capi.waba_id'),
                'ctwa_clid'                    => trim((string) $lead->ctwa_clid),
            ],
        ];

        if ((string) $evento->evento === LeadEventoMeta::EVENTO_PURCHASE) {
            $dato['custom_data'] = [
                'currency' => $this->moneda_del_contrato($lead),
                'value'    => $this->valor_del_contrato($lead),
            ];
        }

        $payload = ['data' => [$dato]];

        $codigo_de_prueba = trim((string) config('services.meta_capi.test_event_code'));
        if ($codigo_de_prueba !== '') {
            $payload['test_event_code'] = $codigo_de_prueba;
        }

        return $payload;
    }

    /**
     * La red de seguridad que corre cada diez minutos (`leads:meta-capi-conciliar`).
     *
     *   1. Registra lo que ningún observer vio: la demo directa escribe `status='demo_agendada'` con
     *      un UPDATE directo que esquiva `Lead::saved`. Mira los leads con `ctwa_clid` tocados en los
     *      últimos siete días (un UPDATE de Eloquent mueve `updated_at` igual).
     *   2. Libera los `enviando` colgados (el proceso murió sin soltar la fila): pasan a `error`.
     *   3. Reintenta los `error` con menos de tres intentos y de los últimos siete días.
     *   4. Si la configuración apareció, manda los `sin_configurar` de los últimos siete días.
     *
     * Encola, no manda: el envío lo hace el worker, con el mismo reclamo que el primer intento.
     *
     * @return array<string, int>
     */
    public function conciliar(): array
    {
        $resumen = [
            'registrados'               => 0,
            'colgados_liberados'        => 0,
            'reintentos'                => 0,
            'sin_configurar_reenviados' => 0,
        ];

        $ahora = AppTime::now();
        $desde = $ahora->copy()->subDays(LeadEventoMeta::DIAS_DE_VIGENCIA);

        Lead::query()
            ->whereNotNull('ctwa_clid')
            ->where('ctwa_clid', '!=', '')
            ->where('updated_at', '>=', $desde)
            ->chunkById(200, function ($leads) use ($ahora, &$resumen) {
                foreach ($leads as $lead) {
                    try {
                        /* El momento del evento es el último cambio del lead, no la corrida: el
                           conciliador llega hasta diez minutos tarde. */
                        $momento = $lead->updated_at !== null && $lead->updated_at->lte($ahora)
                            ? Carbon::instance($lead->updated_at)
                            : $ahora->copy();
                        $resumen['registrados'] += count($this->registrar_alcanzados($lead, $momento));
                    } catch (\Throwable $exception) {
                        Log::channel('daily')->error('Conversiones Meta: el conciliador no pudo registrar los eventos de un lead.', [
                            'lead_id' => (int) $lead->id,
                            'error'   => $this->sin_token($exception->getMessage()),
                        ]);
                    }
                }
            });

        $resumen['colgados_liberados'] = LeadEventoMeta::query()
            ->where('estado', LeadEventoMeta::ESTADO_ENVIANDO)
            ->where('updated_at', '<=', $ahora->copy()->subMinutes(LeadEventoMeta::MINUTOS_PARA_DAR_POR_COLGADO))
            ->update([
                'estado'       => LeadEventoMeta::ESTADO_ERROR,
                'ultimo_error' => 'El envío quedó colgado: el proceso murió sin terminar. Se reintenta.',
            ]);

        if (! self::config_completa()) {
            return $resumen;
        }

        $errores = LeadEventoMeta::query()
            ->where('estado', LeadEventoMeta::ESTADO_ERROR)
            ->where('intentos', '<', LeadEventoMeta::MAX_INTENTOS)
            ->where('event_time', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($errores as $fila) {
            $this->despachar($fila);
            $resumen['reintentos']++;
        }

        $sin_configurar = LeadEventoMeta::query()
            ->where('estado', LeadEventoMeta::ESTADO_SIN_CONFIGURAR)
            ->where('event_time', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($sin_configurar as $fila) {
            $this->despachar($fila);
            $resumen['sin_configurar_reenviados']++;
        }

        return $resumen;
    }

    /**
     * Saca el token de cualquier texto antes de escribirlo en una fila o en el log.
     *
     * @param string $texto
     *
     * @return string
     */
    public function sin_token(string $texto): string
    {
        $token = trim((string) config('services.meta_capi.token'));
        if ($token === '') {
            return $texto;
        }

        return str_replace($token, '[token]', $texto);
    }

    /**
     * Deja la fila en `error` si todavía no se resolvió. Lo usa el job como último recurso.
     *
     * @param int    $lead_evento_meta_id
     * @param string $motivo
     *
     * @return void
     */
    public function anotar_caida(int $lead_evento_meta_id, string $motivo): void
    {
        LeadEventoMeta::query()
            ->whereKey($lead_evento_meta_id)
            ->whereIn('estado', [LeadEventoMeta::ESTADO_PENDIENTE, LeadEventoMeta::ESTADO_ENVIANDO])
            ->update([
                'estado'       => LeadEventoMeta::ESTADO_ERROR,
                'ultimo_error' => $this->sin_token($motivo),
            ]);
    }

    /**
     * URL del endpoint de eventos del dataset configurado.
     *
     * @return string
     */
    private function url_de_eventos(): string
    {
        return self::URL_GRAPH . '/'
            . trim((string) config('services.meta_capi.graph_version', 'v24.0')) . '/'
            . trim((string) config('services.meta_capi.dataset_id')) . '/events';
    }

    /**
     * El reclamo: pasa la fila a `enviando` y suma un intento, solo si nadie la tiene y no agotó
     * los intentos. Un `enviando` colgado (más de diez minutos) también se puede reclamar.
     *
     * @param LeadEventoMeta $evento
     *
     * @return bool true si este proceso se quedó con la fila.
     */
    private function reclamar(LeadEventoMeta $evento): bool
    {
        $colgado_desde = AppTime::now()->subMinutes(LeadEventoMeta::MINUTOS_PARA_DAR_POR_COLGADO);

        $reclamadas = LeadEventoMeta::query()
            ->whereKey((int) $evento->id)
            ->where('intentos', '<', LeadEventoMeta::MAX_INTENTOS)
            ->where(function ($query) use ($colgado_desde) {
                $query->whereIn('estado', [
                    LeadEventoMeta::ESTADO_PENDIENTE,
                    LeadEventoMeta::ESTADO_SIN_CONFIGURAR,
                    LeadEventoMeta::ESTADO_ERROR,
                ])->orWhere(function ($colgado) use ($colgado_desde) {
                    $colgado->where('estado', LeadEventoMeta::ESTADO_ENVIANDO)
                        ->where('updated_at', '<=', $colgado_desde);
                });
            })
            ->update([
                'estado'   => LeadEventoMeta::ESTADO_ENVIANDO,
                'intentos' => DB::raw('intentos + 1'),
            ]);

        if ($reclamadas === 0) {
            Log::channel('daily')->info('Conversiones Meta: el evento ya lo tiene otro proceso, ya salió o agotó los intentos.', [
                'lead_evento_meta_id' => (int) $evento->id,
            ]);

            return false;
        }

        $evento->refresh();

        return true;
    }

    /**
     * Deja la fila en `error` con el motivo (sin token) y, si la hay, la respuesta recortada.
     *
     * @param LeadEventoMeta            $evento
     * @param string                    $motivo
     * @param array<string, mixed>|null $resumen
     *
     * @return void
     */
    private function marcar_error(LeadEventoMeta $evento, string $motivo, ?array $resumen): void
    {
        $motivo = $this->sin_token($motivo);

        LeadEventoMeta::query()->whereKey((int) $evento->id)->update([
            'estado'       => LeadEventoMeta::ESTADO_ERROR,
            'ultimo_error' => $motivo,
            'respuesta'    => $resumen !== null ? json_encode($resumen) : null,
        ]);

        Log::channel('daily')->warning('Conversiones Meta: el evento no salió.', [
            'lead_evento_meta_id' => (int) $evento->id,
            'lead_id'             => (int) $evento->lead_id,
            'evento'              => $evento->evento,
            'motivo'              => $motivo,
        ]);
    }

    /**
     * Encola el envío de una fila por la conexión `database` (la fija el propio job).
     *
     * @param LeadEventoMeta $evento
     *
     * @return void
     */
    private function despachar(LeadEventoMeta $evento): void
    {
        EnviarEventoMetaJob::dispatch((int) $evento->id)
            ->onConnection(EnviarEventoMetaJob::CONEXION_DE_COLA);
    }

    /**
     * Lo que se guarda de la respuesta de Meta: los contadores, los avisos y el id de rastreo, más
     * el código del error si lo hubo. Nada más (y nada que lleve el token).
     *
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function resumen_de_respuesta(array $json): array
    {
        $resumen = [];
        foreach (['events_received', 'messages', 'fbtrace_id'] as $clave) {
            if (array_key_exists($clave, $json)) {
                $resumen[$clave] = $json[$clave];
            }
        }

        if (isset($json['error']) && is_array($json['error'])) {
            $error = [];
            foreach (['type', 'code', 'error_subcode', 'fbtrace_id'] as $clave) {
                if (array_key_exists($clave, $json['error'])) {
                    $error[$clave] = $json['error'][$clave];
                }
            }
            $resumen['error'] = $error;
        }

        return $resumen;
    }

    /**
     * Moneda del contrato en ISO 4217 (tres letras). Cualquier otra cosa, o nada, es USD.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function moneda_del_contrato(Lead $lead): string
    {
        $moneda = strtoupper(trim((string) $lead->contract_currency));

        return preg_match('/^[A-Z]{3}$/', $moneda) ? $moneda : self::MONEDA_POR_DEFECTO;
    }

    /**
     * Precio de la licencia del contrato, leído como se escribe en Argentina (`1.500` = mil
     * quinientos). 0 si no se puede leer. Entero cuando no tiene decimales.
     *
     * @param Lead $lead
     *
     * @return int|float
     */
    private function valor_del_contrato(Lead $lead)
    {
        try {
            $valor = ClientContratoService::monto_desde_texto($lead->contract_precio_licencia);
        } catch (\Throwable $exception) {
            $valor = 0.0;
        }

        if ($valor <= 0) {
            return 0;
        }

        return floor($valor) == $valor ? (int) $valor : round($valor, 2);
    }
}
