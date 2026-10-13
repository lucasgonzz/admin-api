<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Jobs\EnviarEventoMetaJob;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LicenciaCuota;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conversiones de los leads a Meta por la Conversions API (misión origen-del-lead-y-conversiones-meta,
 * 13/10/2026), por dos canales que comparten tabla, estados, reclamo atómico y conciliador:
 *
 *   - `whatsapp`: los leads de anuncio Click-to-WhatsApp (con `ctwa_clid`), por la CAPI for Business
 *     Messaging, al conjunto de datos de la WABA. Eventos `LeadSubmitted`, `QualifiedLead`, `Purchase`.
 *   - `web` (segunda tanda, 13/10/2026): los leads que vinieron de la web (`origen = landing` y sin
 *     `ctwa_clid`), al conjunto de datos del PÍXEL de la web. Eventos estándar `Lead`, `Schedule`,
 *     `Purchase`. Un lead de la web al que le llega el `ctwa_clid` de un anuncio pasa a `whatsapp`:
 *     el click id atribuye mejor que los datos de contacto.
 *
 * Contrato con Meta, canal `whatsapp` (verificado en su documentación):
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
 * Canal `web`: mismo endpoint sobre el dataset del píxel, sin `messaging_channel` ni `ctwa_clid`:
 *
 *     {"data":[{"event_name":"Schedule","event_time":1760000000,"event_id":"cc-lead-123-web-schedule",
 *               "action_source":"chat",
 *               "user_data":{"ph":["<sha256 549…>","<sha256 54… sin el 9>"],"em":["<sha256>"],
 *                            "country":["<sha256 ar>"],"external_id":["<sha256 cc-lead-123>"],
 *                            "fn":["<sha256>"],"ln":["<sha256>"]}}],
 *      "test_event_code":"TEST123"}
 *
 * `action_source: chat` porque la conversión pasó en una conversación de WhatsApp y no en el sitio:
 * con `website` Meta exige `client_user_agent` y `event_source_url`, que no tenemos. Todo `user_data`
 * viaja en SHA-256, normalizado como pide Meta (ver {@see user_data_web()}).
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
 * evento queda `sin_configurar`, con un warning en el log y CERO llamadas HTTP. El canal `web` usa
 * SUS PROPIAS `META_CAPI_WEB_TOKEN` y `META_CAPI_WEB_DATASET_ID`, sin caer a las de WhatsApp: con un
 * fallback, cargar solo las de WhatsApp mandaría los eventos de la web al dataset de la WABA sin que
 * nadie lo decida, y un token sin permiso sobre el píxel quemaría los tres intentos de cada evento.
 * Si es un solo dataset, se cargan las cuatro con los valores repetidos.
 */
class MetaConversionesService
{
    /** Host de la Graph API de Meta. */
    const URL_GRAPH = 'https://graph.facebook.com';

    /** `action_source` de la CAPI for Business Messaging. */
    const ACTION_SOURCE = 'business_messaging';

    /** `messaging_channel` de los leads de Click-to-WhatsApp. */
    const MESSAGING_CHANNEL = 'whatsapp';

    /**
     * `action_source` del canal `web`: la conversión pasó en una conversación (de WhatsApp), no en el
     * sitio. Con `website` Meta exige `client_user_agent` y `event_source_url`, que no tenemos.
     */
    const ACTION_SOURCE_WEB = 'chat';

    /** País de los leads (ISO 3166-1 alfa-2, en minúsculas como lo pide Meta antes del hash). */
    const PAIS = 'ar';

    /** Moneda del `Purchase` cuando el contrato no dice otra (las licencias se pactan en dólares). */
    const MONEDA_POR_DEFECTO = 'USD';

    /**
     * Cómo se escribe la moneda a mano en el contrato → la de `LicenciaCuota::MONEDAS`. Las claves
     * van normalizadas: minúsculas, sin tildes, sin espacios ni puntos.
     */
    const ALIAS_DE_MONEDA = [
        '$'       => 'ARS',
        'ars'     => 'ARS',
        'peso'    => 'ARS',
        'pesos'   => 'ARS',
        'u$s'     => 'USD',
        'us$'     => 'USD',
        'usd'     => 'USD',
        'dolar'   => 'USD',
        'dolares' => 'USD',
    ];

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
        'mail2_enviado',
        'cerrado_ganado',
    ];

    /**
     * Estados desde los que el lead cuenta como `QualifiedLead`: la demo agendada y todo lo que viene
     * después. Meta no tiene un evento de «demo agendada» (informe del 5/10/2026, §10.7).
     *
     * `mail2_enviado` está en desuso pero es post-demo: entra a los dos conjuntos, igual que en
     * `ClaudeLeadMetricsService`.
     */
    const ESTADOS_QUALIFIED_LEAD = [
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'mail2_enviado',
        'cerrado_ganado',
    ];

    /**
     * ¿Está todo lo necesario para mandar por este canal? Token y dataset; el resto tiene default.
     *
     * @param string $canal `whatsapp` (default, el de la primera tanda) o `web`.
     *
     * @return bool
     */
    public static function config_completa(string $canal = LeadEventoMeta::CANAL_WHATSAPP): bool
    {
        return self::token_del_canal($canal) !== '' && self::dataset_del_canal($canal) !== '';
    }

    /**
     * Por qué canal salen las conversiones de este lead, o null si no tiene ninguno.
     *
     *   - con `ctwa_clid` → `whatsapp` (sea cual sea el origen: el click id es la atribución más
     *     fuerte que hay, y es lo que la primera tanda ya hacía);
     *   - sin `ctwa_clid` y con `origen = landing` → `web`;
     *   - cualquier otro → null: no hay con qué atribuirle nada.
     *
     * No toca la base: lo usa el observer de cada `save()` como filtro barato.
     *
     * @param Lead $lead
     *
     * @return string|null
     */
    public static function canal_del_lead(Lead $lead): ?string
    {
        if (trim((string) $lead->ctwa_clid) !== '') {
            return LeadEventoMeta::CANAL_WHATSAPP;
        }

        if ((string) $lead->origen === LeadOrigenService::ORIGEN_LANDING) {
            return LeadEventoMeta::CANAL_WEB;
        }

        return null;
    }

    /**
     * `event_id` que viaja a Meta para este lead, este evento y este canal. Es estable: el mismo
     * lead, evento y canal dan siempre el mismo, así Meta deduplica un reenvío.
     *
     * Los de WhatsApp conservan el formato de la primera tanda (`cc-lead-12-leadsubmitted`); los de
     * la web llevan el canal en el medio (`cc-lead-12-web-schedule`), así un `Purchase` de cada canal
     * no comparte id.
     *
     * @param int    $lead_id
     * @param string $evento
     * @param string $canal
     *
     * @return string
     */
    public static function event_id_para(int $lead_id, string $evento, string $canal = LeadEventoMeta::CANAL_WHATSAPP): string
    {
        $prefijo = 'cc-lead-' . $lead_id . '-';
        if ($canal === LeadEventoMeta::CANAL_WEB) {
            $prefijo .= LeadEventoMeta::CANAL_WEB . '-';
        }

        return $prefijo . strtolower($evento);
    }

    /**
     * Eventos que el lead ya alcanzó, en orden, con los nombres del canal.
     *
     * Las etapas son las mismas en los dos canales; cambia el nombre que viaja: `LeadSubmitted` y
     * `QualifiedLead` por WhatsApp, `Lead` y `Schedule` por la web. `Purchase` en los dos.
     *
     * @param Lead   $lead
     * @param string $canal
     *
     * @return array<int, string>
     */
    public function eventos_alcanzados(Lead $lead, string $canal = LeadEventoMeta::CANAL_WHATSAPP): array
    {
        $nombres   = $canal === LeadEventoMeta::CANAL_WEB ? LeadEventoMeta::EVENTOS_WEB : LeadEventoMeta::EVENTOS;
        $status    = (string) $lead->status;
        $promovido = $lead->promoted_client_id !== null && (string) $lead->promoted_client_id !== '';
        $eventos   = [];

        if ($promovido || in_array($status, self::ESTADOS_LEAD_SUBMITTED, true)) {
            $eventos[] = $nombres[0];
        }
        if ($promovido || in_array($status, self::ESTADOS_QUALIFIED_LEAD, true)) {
            $eventos[] = $nombres[1];
        }
        if ($promovido) {
            $eventos[] = $nombres[2];
        }

        return $eventos;
    }

    /**
     * Registra los eventos que el lead alcanzó y todavía no tiene, y encola su envío.
     *
     * Solo para leads con canal ({@see canal_del_lead()}): con `ctwa_clid` por WhatsApp, o de la web
     * por el dataset del píxel. Cualquier otro no registra nada. Con la configuración del canal
     * incompleta, los eventos quedan `sin_configurar` (con un warning en el log) y no se encola nada.
     *
     * @param Lead        $lead
     * @param Carbon|null $momento   Cuándo alcanzó la etapa (default: ahora).
     * @param bool        $despachar false para registrar sin encolar.
     *
     * @return array<int, LeadEventoMeta> Las filas que se crearon en esta llamada.
     */
    public function registrar_alcanzados(Lead $lead, ?Carbon $momento = null, bool $despachar = true): array
    {
        $canal = self::canal_del_lead($lead);
        if ($canal === null) {
            return [];
        }

        $config  = self::config_completa($canal);
        $creados = $this->crear_filas_faltantes(
            $lead,
            $canal,
            $config ? LeadEventoMeta::ESTADO_PENDIENTE : LeadEventoMeta::ESTADO_SIN_CONFIGURAR,
            $momento !== null ? $momento : AppTime::now(),
            null
        );

        if (empty($creados)) {
            return [];
        }

        if (! $config) {
            Log::channel('daily')->warning(
                'Conversiones Meta: falta ' . self::config_que_falta($canal) . '. Los eventos quedan sin_configurar y no sale ninguna llamada.',
                [
                    'lead_id' => (int) $lead->id,
                    'canal'   => $canal,
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
     * anterior a la medición, o cuando lo deja como `landing` (canal `web`, segunda tanda). Las
     * etapas que ese lead alcanzó antes de que existiera la medición NO
     * se le mandan a Meta: sería reportar un clic de hace semanas con la fecha de hoy (Purchase
     * incluido). Dejarlas registradas es lo que hace que ni el observer ni el conciliador las vuelvan
     * a registrar como `pendiente` después; y un `omitido` no lo reclama nunca el envío. Las etapas
     * que el lead alcance DESPUÉS sí salen, como las de cualquier lead.
     *
     * @param Lead $lead Con el `ctwa_clid` o el `origen` ya cargados.
     *
     * @return array<int, LeadEventoMeta> Las filas que se crearon en esta llamada.
     */
    public function registrar_omitidos(Lead $lead): array
    {
        $canal = self::canal_del_lead($lead);
        if ($canal === null) {
            return [];
        }

        return $this->crear_filas_faltantes(
            $lead,
            $canal,
            LeadEventoMeta::ESTADO_OMITIDO,
            AppTime::now(),
            LeadEventoMeta::MOTIVO_OMITIDO
        );
    }

    /**
     * Crea, en orden, las filas de los eventos que el lead alcanzó y no tiene registrados todavía en
     * este canal. El canal lo decide el que llama ({@see canal_del_lead()}).
     *
     * @param Lead        $lead
     * @param string      $canal   `whatsapp` o `web`.
     * @param string      $estado  Estado con el que nacen las filas.
     * @param Carbon      $momento `event_time` de las filas.
     * @param string|null $motivo  Texto para `ultimo_error` (el porqué de un `omitido`).
     *
     * @return array<int, LeadEventoMeta>
     */
    private function crear_filas_faltantes(Lead $lead, string $canal, string $estado, Carbon $momento, ?string $motivo): array
    {
        if (! $lead->id) {
            return [];
        }

        $alcanzados = $this->eventos_alcanzados($lead, $canal);
        if (empty($alcanzados)) {
            return [];
        }

        $existentes = LeadEventoMeta::query()
            ->where('lead_id', (int) $lead->id)
            ->where('canal', $canal)
            ->pluck('evento')
            ->all();
        $faltantes  = array_values(array_diff($alcanzados, $existentes));
        $creados    = [];

        foreach ($faltantes as $evento) {
            try {
                $creados[] = LeadEventoMeta::create([
                    'lead_id'      => (int) $lead->id,
                    'canal'        => $canal,
                    'evento'       => $evento,
                    'estado'       => $estado,
                    'event_id'     => self::event_id_para((int) $lead->id, $evento, $canal),
                    'event_time'   => $momento,
                    'intentos'     => 0,
                    'ultimo_error' => $motivo,
                ]);
            } catch (QueryException $exception) {
                if (self::es_error_de_duplicado($exception)) {
                    /* El único (lead_id, canal, evento): otro proceso lo registró entre la lectura y
                       el alta. Es exactamente lo que el único está para frenar; no hay nada que hacer. */
                    Log::channel('daily')->info('Conversiones Meta: el evento ya estaba registrado.', [
                        'lead_id' => (int) $lead->id,
                        'canal'   => $canal,
                        'evento'  => $evento,
                    ]);
                    continue;
                }

                /* Cualquier otra cosa (un valor que no entra con `strict`, la base caída) es un
                   error de verdad y no un duplicado: se loguea como tal y se sigue con el resto. */
                Log::channel('daily')->error('Conversiones Meta: la base rechazó el registro del evento.', [
                    'lead_id' => (int) $lead->id,
                    'canal'   => $canal,
                    'evento'  => $evento,
                    'error'   => $this->sin_token($exception->getMessage()),
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
        $canal = self::canal_de_la_fila($evento);

        if (! self::config_completa($canal)) {
            LeadEventoMeta::query()
                ->whereKey((int) $evento->id)
                ->whereIn('estado', [LeadEventoMeta::ESTADO_PENDIENTE, LeadEventoMeta::ESTADO_SIN_CONFIGURAR])
                ->update(['estado' => LeadEventoMeta::ESTADO_SIN_CONFIGURAR]);

            Log::channel('daily')->warning(
                'Conversiones Meta: falta ' . self::config_que_falta($canal) . '. El evento no se manda.',
                ['lead_evento_meta_id' => (int) $evento->id, 'lead_id' => (int) $evento->lead_id, 'canal' => $canal, 'evento' => $evento->evento]
            );

            return (string) LeadEventoMeta::query()->whereKey((int) $evento->id)->value('estado');
        }

        if (! $this->reclamar($evento)) {
            return (string) LeadEventoMeta::query()->whereKey((int) $evento->id)->value('estado');
        }

        $lead = Lead::query()->find((int) $evento->lead_id);
        if ($canal === LeadEventoMeta::CANAL_WHATSAPP && ($lead === null || trim((string) $lead->ctwa_clid) === '')) {
            $this->marcar_error($evento, 'El lead ya no existe o no tiene ctwa_clid: Meta no tiene con qué atribuir el evento.', null);

            return LeadEventoMeta::ESTADO_ERROR;
        }
        if ($lead === null) {
            $this->marcar_error($evento, 'El lead ya no existe: no hay a quién atribuirle el evento.', null);

            return LeadEventoMeta::ESTADO_ERROR;
        }

        $token   = self::token_del_canal($canal);
        $url     = $this->url_de_eventos($canal);
        $payload = $this->armar_payload($evento, $lead);

        if ($canal === LeadEventoMeta::CANAL_WEB
            && empty($payload['data'][0]['user_data']['ph'])
            && empty($payload['data'][0]['user_data']['em'])) {
            /* Sin teléfono ni mail, Meta no tiene con qué cruzar al lead: el país y el id propio solos
               no identifican a nadie. No se gasta la llamada. */
            $this->marcar_error($evento, 'El lead no tiene un teléfono ni un mail que Meta pueda cruzar: el evento no sale.', null);

            return LeadEventoMeta::ESTADO_ERROR;
        }

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
            'event_name' => (string) $evento->evento,
            'event_time' => (int) $momento->getTimestamp(),
            'event_id'   => (string) $evento->event_id,
        ];

        if (self::canal_de_la_fila($evento) === LeadEventoMeta::CANAL_WEB) {
            $dato['action_source'] = self::ACTION_SOURCE_WEB;
            $dato['user_data']     = $this->user_data_web($lead);
        } else {
            $dato['action_source']     = self::ACTION_SOURCE;
            $dato['messaging_channel'] = self::MESSAGING_CHANNEL;
            $dato['user_data']         = [
                'whatsapp_business_account_id' => (string) config('services.meta_capi.waba_id'),
                'ctwa_clid'                    => trim((string) $lead->ctwa_clid),
            ];
        }

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
     * El `user_data` de un evento del canal `web`: los datos de contacto del lead en SHA-256,
     * normalizados como pide Meta antes del hash. Solo las claves que se pueden llenar.
     *
     *   - `ph`: el teléfono con solo dígitos y código de país, sin `+`. Un celular argentino viaja en
     *     las DOS variantes (`549…` y `54…` sin el 9): Meta guarda los números de los dos modos. Es la
     *     misma normalización que ya usan las audiencias ({@see AudienciasMetaService::normalizar_telefono()}).
     *   - `em`: el mail sin espacios y en minúsculas, si lo tenemos.
     *   - `country`: `ar`. Salvo que el teléfono sea de otro país: ahí no se manda, porque un país
     *     equivocado le resta al cruce en vez de sumarle.
     *   - `external_id`: `cc-lead-{id}`, el id propio del lead. Estable, así Meta une entre sí los
     *     eventos del mismo lead.
     *   - `fn` / `ln`: solo si el nombre del contacto se puede partir en nombre y apellido (dos
     *     palabras o más): la primera y la última. Con una sola palabra no va ninguno.
     *
     * @param Lead $lead
     *
     * @return array<string, array<int, string>>
     */
    private function user_data_web(Lead $lead): array
    {
        $datos    = [];
        $telefono = AudienciasMetaService::normalizar_telefono($lead->phone);

        if ($telefono !== null) {
            $datos['ph'] = [hash('sha256', $telefono['con_9'])];
            if ($telefono['sin_9'] !== null) {
                $datos['ph'][] = hash('sha256', $telefono['sin_9']);
            }
        }

        $email = AudienciasMetaService::hash_email($lead->email);
        if ($email !== null) {
            $datos['em'] = [$email];
        }

        /* Un teléfono internacional normaliza sin la variante del 9: ese lead no es argentino. */
        if ($telefono === null || $telefono['sin_9'] !== null) {
            $datos['country'] = [hash('sha256', self::PAIS)];
        }

        $datos['external_id'] = [hash('sha256', 'cc-lead-' . (int) $lead->id)];

        $nombre_y_apellido = self::nombre_y_apellido($lead->contact_name);
        if ($nombre_y_apellido !== null) {
            $datos['fn'] = [hash('sha256', $nombre_y_apellido[0])];
            $datos['ln'] = [hash('sha256', $nombre_y_apellido[1])];
        }

        return $datos;
    }

    /**
     * Nombre y apellido del contacto, normalizados como pide Meta para `fn`/`ln`: minúsculas, sin
     * signos, sin números y sin espacios. Las letras con tilde quedan: Meta las acepta en UTF-8, y es
     * como las escribe la persona en su perfil.
     *
     * «Guillermo González» → [guillermo, gonzález]; «Juan Carlos Pérez» → [juan, pérez];
     * «Guille 🔥» → null (una sola palabra).
     *
     * @param string|null $contact_name
     *
     * @return array<int, string>|null [nombre, apellido], o null si no se puede partir.
     */
    public static function nombre_y_apellido($contact_name): ?array
    {
        $palabras = preg_split('/\s+/u', trim((string) $contact_name), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($palabras)) {
            return null;
        }

        $limpias = [];
        foreach ($palabras as $palabra) {
            $limpia = mb_strtolower((string) preg_replace('/[^\p{L}]+/u', '', $palabra), 'UTF-8');
            if ($limpia !== '') {
                $limpias[] = $limpia;
            }
        }

        if (count($limpias) < 2) {
            return null;
        }

        return [$limpias[0], $limpias[count($limpias) - 1]];
    }

    /**
     * La red de seguridad que corre cada diez minutos (`leads:meta-capi-conciliar`).
     *
     *   1. Registra lo que ningún observer vio: la demo directa escribe `status='demo_agendada'` con
     *      un UPDATE directo que esquiva `Lead::saved`. Mira los leads con `ctwa_clid` y los de la
     *      web (`origen = landing`) tocados dentro de la vigencia (un UPDATE de Eloquent mueve
     *      `updated_at` igual).
     *   2. Libera los `enviando` colgados (el proceso murió sin soltar la fila): pasan a `error`.
     *   3. Vuelve a encolar los `pendiente` huérfanos: los que llevan más de quince minutos sin que
     *      nadie los tome. Pasa si el worker muere después de reservar el job (Laravel lo manda a
     *      `failed_jobs` sin pasar por `handle()`) o si el `dispatch` tiró después del alta.
     *   4. Reintenta los `error` con menos de tres intentos, pero solo si el último intento tiene más
     *      de quince minutos: con la cola atrasada o Meta caído, reencolar en cada corrida quemaría
     *      los tres intentos en ráfaga.
     *   5. Si la configuración apareció, manda los `sin_configurar`.
     *
     * Todo dentro de la vigencia (`event_time`). Un `omitido` no se toca nunca. Los pasos 3 a 5 miran
     * solo las filas de los canales que tienen la configuración completa.
     *
     * Encola, no manda: el envío lo hace el worker, con el mismo reclamo atómico que el primer
     * intento, así que un evento encolado dos veces sale una sola. Al reencolar, la fila vuelve a
     * `pendiente` con el `updated_at` de ahora: eso es lo que evita que la corrida siguiente la
     * vuelva a encolar mientras espera al worker.
     *
     * @return array<string, int>
     */
    public function conciliar(): array
    {
        $resumen = [
            'registrados'               => 0,
            'colgados_liberados'        => 0,
            'pendientes_reencolados'    => 0,
            'reintentos'                => 0,
            'sin_configurar_reenviados' => 0,
        ];

        $ahora           = AppTime::now();
        $desde           = $ahora->copy()->subDays(LeadEventoMeta::DIAS_DE_VIGENCIA);
        $quieto_antes_de = $ahora->copy()->subMinutes(LeadEventoMeta::MINUTOS_ANTES_DE_REENCOLAR);

        Lead::query()
            ->where(function ($query) {
                $query->where(function ($con_clid) {
                    $con_clid->whereNotNull('ctwa_clid')->where('ctwa_clid', '!=', '');
                })->orWhere('origen', LeadOrigenService::ORIGEN_LANDING);
            })
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

        $canales = array_values(array_filter(LeadEventoMeta::CANALES, function ($canal) {
            return self::config_completa($canal);
        }));
        if (empty($canales)) {
            return $resumen;
        }

        $huerfanos = LeadEventoMeta::query()
            ->whereIn('canal', $canales)
            ->where('estado', LeadEventoMeta::ESTADO_PENDIENTE)
            ->where('updated_at', '<=', $quieto_antes_de)
            ->where('event_time', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($huerfanos as $fila) {
            $resumen['pendientes_reencolados'] += $this->reencolar($fila) ? 1 : 0;
        }

        $errores = LeadEventoMeta::query()
            ->whereIn('canal', $canales)
            ->where('estado', LeadEventoMeta::ESTADO_ERROR)
            ->where('intentos', '<', LeadEventoMeta::MAX_INTENTOS)
            ->where('updated_at', '<=', $quieto_antes_de)
            ->where('event_time', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($errores as $fila) {
            $resumen['reintentos'] += $this->reencolar($fila) ? 1 : 0;
        }

        $sin_configurar = LeadEventoMeta::query()
            ->whereIn('canal', $canales)
            ->where('estado', LeadEventoMeta::ESTADO_SIN_CONFIGURAR)
            ->where('event_time', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($sin_configurar as $fila) {
            $resumen['sin_configurar_reenviados'] += $this->reencolar($fila) ? 1 : 0;
        }

        return $resumen;
    }

    /**
     * Devuelve una fila a `pendiente` (con el `updated_at` de ahora) y la encola. Condicional sobre
     * el estado que tenía: si un worker la reclamó en el medio, no se toca ni se encola.
     *
     * @param LeadEventoMeta $fila
     *
     * @return bool true si quedó encolada.
     */
    private function reencolar(LeadEventoMeta $fila): bool
    {
        $tomadas = LeadEventoMeta::query()
            ->whereKey((int) $fila->id)
            ->where('estado', (string) $fila->estado)
            ->update(['estado' => LeadEventoMeta::ESTADO_PENDIENTE]);

        if ($tomadas === 0) {
            return false;
        }

        $this->despachar($fila);

        return true;
    }

    /**
     * ¿La excepción es una violación de unicidad? Mismo criterio que
     * `MetaRawWebhookController::es_error_de_duplicado()` (que es privado): SQLSTATE 23000 cubre toda
     * la familia de violaciones de integridad (un NOT NULL roto también es 23000), así que hace falta
     * además el código del driver: 1062 es el «Duplicate entry» de MySQL y 19 el de SQLite.
     *
     * @param QueryException $exception
     *
     * @return bool
     */
    private static function es_error_de_duplicado(QueryException $exception): bool
    {
        $error_info    = $exception->errorInfo;
        $codigo_driver = is_array($error_info) && isset($error_info[1]) ? (int) $error_info[1] : 0;

        return (string) $exception->getCode() === '23000' && in_array($codigo_driver, [1062, 19], true);
    }

    /**
     * Saca los tokens (el de WhatsApp y el de la web) de cualquier texto antes de escribirlo en una
     * fila o en el log. El más largo primero, por si uno contuviera al otro.
     *
     * @param string $texto
     *
     * @return string
     */
    public function sin_token(string $texto): string
    {
        $tokens = array_values(array_unique(array_filter([
            trim((string) config('services.meta_capi.token')),
            trim((string) config('services.meta_capi.web_token')),
        ], function ($token) {
            return $token !== '';
        })));

        usort($tokens, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($tokens as $token) {
            $texto = str_replace($token, '[token]', $texto);
        }

        return $texto;
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
     * URL del endpoint de eventos del dataset del canal.
     *
     * @param string $canal
     *
     * @return string
     */
    private function url_de_eventos(string $canal): string
    {
        return self::URL_GRAPH . '/'
            . trim((string) config('services.meta_capi.graph_version', 'v24.0')) . '/'
            . self::dataset_del_canal($canal) . '/events';
    }

    /**
     * Token del canal: `META_CAPI_TOKEN` para WhatsApp, `META_CAPI_WEB_TOKEN` para la web. 🔴 Sin
     * fallback entre canales (ver el docblock de la clase). Privado a propósito: el token no sale de
     * esta clase.
     *
     * @param string $canal
     *
     * @return string
     */
    private static function token_del_canal(string $canal): string
    {
        $clave = $canal === LeadEventoMeta::CANAL_WEB ? 'services.meta_capi.web_token' : 'services.meta_capi.token';

        return trim((string) config($clave));
    }

    /**
     * Dataset del canal: `META_CAPI_DATASET_ID` (el de la WABA) para WhatsApp,
     * `META_CAPI_WEB_DATASET_ID` (el del píxel) para la web. 🔴 Sin fallback entre canales.
     *
     * @param string $canal
     *
     * @return string
     */
    private static function dataset_del_canal(string $canal): string
    {
        $clave = $canal === LeadEventoMeta::CANAL_WEB ? 'services.meta_capi.web_dataset_id' : 'services.meta_capi.dataset_id';

        return trim((string) config($clave));
    }

    /**
     * Las variables que faltan para el canal, para el warning del log y del comando.
     *
     * @param string $canal
     *
     * @return string
     */
    public static function config_que_falta(string $canal): string
    {
        if ($canal === LeadEventoMeta::CANAL_WEB) {
            return 'META_CAPI_WEB_TOKEN o META_CAPI_WEB_DATASET_ID';
        }

        return 'META_CAPI_TOKEN o META_CAPI_DATASET_ID';
    }

    /**
     * Canal de una fila. Una fila sin canal (no debería existir: la columna tiene default) es de
     * WhatsApp, que es lo que eran todas antes de la segunda tanda.
     *
     * @param LeadEventoMeta $evento
     *
     * @return string
     */
    private static function canal_de_la_fila(LeadEventoMeta $evento): string
    {
        return (string) $evento->canal === LeadEventoMeta::CANAL_WEB
            ? LeadEventoMeta::CANAL_WEB
            : LeadEventoMeta::CANAL_WHATSAPP;
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
            'canal'               => self::canal_de_la_fila($evento),
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
     * Moneda del `Purchase`, contra la lista blanca del repo (`LicenciaCuota::MONEDAS`: USD y ARS).
     *
     * Mismo criterio que `ClientContratoService::normalizar_moneda()` (que es privado, por eso se
     * replica acá), más los alias que se tipean a mano en el contrato: `$`, `ars`, `peso`, `pesos` son
     * ARS; `u$s`, `us$`, `usd`, `dolar`, `dólar`, `dolares`, `dólares` son USD. 🔴 Antes pasaba
     * cualquier `[A-Z]{3}` tal cual (`DOL`, `PES` → 400 de Meta) y `$` o `pesos` caían a USD con el
     * valor en pesos: un Purchase de USD 2.500.000.
     *
     * Vacío → USD, salvo que el propio precio diga la moneda (`$ 2.500.000`, `USD 2500`). Cualquier
     * otra cosa → USD con un warning en el log, para que alguien corrija el contrato.
     *
     * @param Lead $lead
     *
     * @return string USD o ARS.
     */
    private function moneda_del_contrato(Lead $lead): string
    {
        $crudo = trim((string) $lead->contract_currency);

        if ($crudo === '') {
            /* Lo que queda del precio sin números ni separadores: `$ 2.500.000` deja `$`. */
            $del_precio = $this->moneda_reconocida((string) preg_replace('/[0-9.,\s]+/u', '', (string) $lead->contract_precio_licencia));

            return $del_precio !== null ? $del_precio : self::MONEDA_POR_DEFECTO;
        }

        $moneda = $this->moneda_reconocida($crudo);
        if ($moneda !== null) {
            return $moneda;
        }

        Log::channel('daily')->warning('Conversiones Meta: la moneda del contrato no se reconoce; el Purchase sale en USD.', [
            'lead_id' => (int) $lead->id,
            'moneda'  => $crudo,
        ]);

        return self::MONEDA_POR_DEFECTO;
    }

    /**
     * USD o ARS si el texto es una de las dos (o uno de sus alias), null si no.
     *
     * @param string $texto
     *
     * @return string|null
     */
    private function moneda_reconocida(string $texto): ?string
    {
        $clave = (string) preg_replace('/[\s.]+/u', '', mb_strtolower(Str::ascii(trim($texto)), 'UTF-8'));
        if ($clave === '') {
            return null;
        }

        if (isset(self::ALIAS_DE_MONEDA[$clave])) {
            return self::ALIAS_DE_MONEDA[$clave];
        }

        $mayusculas = strtoupper($clave);

        return in_array($mayusculas, LicenciaCuota::MONEDAS, true) ? $mayusculas : null;
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
