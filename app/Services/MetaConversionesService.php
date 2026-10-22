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
 *     `Purchase`. Un lead de la web al que le llega el `ctwa_clid` de un anuncio pasa a `whatsapp`
 *     solo si todavía no se registró nada por la web: 🔴 el canal es pegajoso, un lead no se
 *     reporta en dos canales (ver {@see canal_del_lead()}).
 *   - Y desde el 22/10/2026 (misión purchase-capi-al-promover), la COMPRA de cualquier lead que se
 *     vuelve cliente, tenga atribución o no: un promovido sin canal manda solo su `Purchase` por la
 *     `web` (ver {@see canal_de_la_compra()} y {@see registrar_compra()}). Los ya promovidos los
 *     informa `leads:meta-capi-compras`, dentro de la ventana de 7 días que acepta Meta.
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

    /**
     * Nombre del evento que manda `leads:meta-capi-compras --probar` en lugar de `Purchase`: un evento
     * con `test_event_code` cuenta para la medición de Meta (ver {@see enviar_prueba()}).
     */
    const EVENTO_DE_PRUEBA = 'CompraDePrueba';

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
     * El canal que le tocaría al lead por sus datos de HOY, sin mirar lo que ya se registró:
     *
     *   - con `ctwa_clid` → `whatsapp` (sea cual sea el origen: el click id es la atribución más
     *     fuerte que hay, y es lo que la primera tanda ya hacía);
     *   - sin `ctwa_clid` y con `origen = landing` → `web`;
     *   - cualquier otro → null: no hay con qué atribuirle nada.
     *
     * No toca la base: lo usa el observer de cada `save()` como filtro barato. El canal que manda es
     * el de {@see canal_del_lead()}, que es pegajoso.
     *
     * @param Lead $lead
     *
     * @return string|null
     */
    public static function canal_natural(Lead $lead): ?string
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
     * Por qué canal salen las conversiones de este lead, o null si no tiene ninguno.
     *
     * 🔴 **El canal es pegajoso** (corrección del chequeo de la segunda tanda, 13/10/2026): un lead
     * no se reporta en dos canales. El caso: entra con la frase de la web, queda `landing`, salen
     * `Lead` y `Schedule` por la web, y horas después llega el referral de su anuncio y se le carga
     * el `ctwa_clid`. Con el canal natural se registrarían `LeadSubmitted` y `QualifiedLead` por
     * WhatsApp para etapas que ya se reportaron. Por eso, si el lead ya tiene filas registradas, su
     * canal es el de esas filas (el de la primera, si por algo tuviera de los dos), aunque hoy le
     * tocaría otro.
     *
     * Lo pegajoso no le inventa canal a un lead que se quedó sin ninguno (por ejemplo, un referral
     * sin `ctwa_clid` que lo pasó de `landing` a `anuncio_whatsapp`): si el canal natural es null,
     * devuelve null, y sus filas pendientes no salen ({@see enviar()} las deja `omitido`).
     *
     * Una consulta a la base, solo para los leads que tienen canal natural.
     *
     * @param Lead $lead
     *
     * @return string|null
     */
    public static function canal_del_lead(Lead $lead): ?string
    {
        $natural = self::canal_natural($lead);
        if ($natural === null || ! $lead->id) {
            return $natural;
        }

        $registrado = (string) LeadEventoMeta::query()
            ->where('lead_id', (int) $lead->id)
            ->orderBy('id')
            ->value('canal');

        return in_array($registrado, LeadEventoMeta::CANALES, true) ? $registrado : $natural;
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

        /* Los eventos son acumulativos y el `Purchase` es el último: si ya está registrado en el
           canal, una etapa anterior que falte no se reporta después con la fecha de hoy. Pasa con
           un promovido sin atribución (solo su compra, misión purchase-capi-al-promover) que más
           tarde quedara con canal. */
        $ya_compro = LeadEventoMeta::query()
            ->where('lead_id', (int) $lead->id)
            ->where('canal', $canal)
            ->where('evento', LeadEventoMeta::EVENTO_PURCHASE)
            ->exists();
        if ($ya_compro) {
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
     * Por qué canal sale el `Purchase` del lead (misión purchase-capi-al-promover, 22/10/2026).
     *
     * El de su embudo si tiene ({@see canal_del_lead()}: WhatsApp con `ctwa_clid`, web si vino de la
     * landing). Y si no tiene, pero está promovido, la `web`: el dataset del píxel con sus datos de
     * contacto en SHA-256. Hasta el 22/10 la compra de un lead sin atribución (recomendado, orgánico,
     * `desconocido`: la mayoría) no salía nunca, y Meta no se enteraba de quién termina pagando.
     *
     * @param Lead $lead
     *
     * @return string|null null si no está promovido y no tiene canal.
     */
    public static function canal_de_la_compra(Lead $lead): ?string
    {
        $canal = self::canal_del_lead($lead);
        if ($canal !== null) {
            return $canal;
        }

        return self::esta_promovido($lead) ? LeadEventoMeta::CANAL_WEB : null;
    }

    /**
     * Registra el `Purchase` del lead promovido, si todavía no lo tiene en su canal de compra, y lo
     * encola.
     *
     * 🔴 **Solo el `Purchase`.** A un lead sin atribución no se le reportan `Lead` ni `Schedule`: esas
     * etapas pasaron hace semanas y saldrían con la fecha de hoy. Para los leads con canal, el
     * `Purchase` ya lo registra {@see registrar_alcanzados()} junto con el resto, y el observer no los
     * manda por acá; sí lo usa el retroactivo, que trae su propia fecha, para los promovidos de antes
     * de la medición de cualquier canal.
     *
     * Sin la configuración del canal, la fila queda `sin_configurar` (el conciliador la manda cuando
     * aparece, dentro de la vigencia) y no se encola nada.
     *
     * @param Lead        $lead
     * @param Carbon|null $momento   Cuándo se promovió (default: ahora).
     * @param string|null $estado    null = pendiente (o sin_configurar); `omitido` para dejarla anotada sin mandar.
     * @param string|null $motivo    El porqué de un `omitido`.
     * @param bool        $despachar false para registrar sin encolar.
     *
     * @return LeadEventoMeta|null La fila creada, o null si no correspondía o ya estaba.
     */
    public function registrar_compra(Lead $lead, ?Carbon $momento = null, ?string $estado = null, ?string $motivo = null, bool $despachar = true): ?LeadEventoMeta
    {
        $canal = self::canal_de_la_compra($lead);
        if ($canal === null || ! $lead->id) {
            return null;
        }

        $config = self::config_completa($canal);
        if ($estado === null) {
            $estado = $config ? LeadEventoMeta::ESTADO_PENDIENTE : LeadEventoMeta::ESTADO_SIN_CONFIGURAR;
        }

        $ya_esta = LeadEventoMeta::query()
            ->where('lead_id', (int) $lead->id)
            ->where('canal', $canal)
            ->where('evento', LeadEventoMeta::EVENTO_PURCHASE)
            ->exists();
        if ($ya_esta) {
            return null;
        }

        $fila = $this->crear_fila($lead, $canal, LeadEventoMeta::EVENTO_PURCHASE, $estado, $momento !== null ? $momento : AppTime::now(), $motivo);
        if ($fila === null) {
            return null;
        }

        if ($estado === LeadEventoMeta::ESTADO_SIN_CONFIGURAR) {
            Log::channel('daily')->warning(
                'Conversiones Meta: falta ' . self::config_que_falta($canal) . '. El Purchase queda sin_configurar y no sale ninguna llamada.',
                ['lead_id' => (int) $lead->id, 'canal' => $canal]
            );
        } elseif ($estado === LeadEventoMeta::ESTADO_PENDIENTE && $despachar) {
            $this->despachar($fila);
        }

        return $fila;
    }

    /**
     * Manda UN evento de prueba con el body de la compra del lead y el `test_event_code` de la pestaña
     * «Probar eventos» de Events Manager, sin registrar nada en `lead_eventos_meta`
     * (`leads:meta-capi-compras --probar`): mismo canal, mismos datos de coincidencia, misma moneda y
     * valor que viajarían en su `Purchase`, con un `event_id` propio (`…-purchase-prueba`).
     *
     * 🔴 **Viaja como {@see EVENTO_DE_PRUEBA}, no como `Purchase`.** La doc de Meta lo dice textual: los
     * eventos con `test_event_code` «are not dropped… are used for targeting and ads measurement». Un
     * `Purchase` de prueba de un cliente real sería una compra con la fecha de hoy, y uno de un lead
     * técnico, un comprador que no existe. Con un evento propio se ven el payload, la aceptación y los
     * datos de coincidencia sin ensuciar las compras que optimiza la campaña.
     *
     * 🔴 El código va solo en esta llamada: cargar `META_CAPI_TEST_EVENT_CODE` en el `.env` de
     * producción convertiría en prueba a TODOS los eventos reales mientras esté puesto.
     *
     * @param Lead   $lead
     * @param string $codigo El test_event_code (TEST…).
     *
     * @return array<string, mixed> `status` (HTTP o 0 si no salió), `respuesta` (resumen sin token),
     *                              `payload` (lo que viajó, con los datos ya hasheados) y `error`.
     */
    public function enviar_prueba(Lead $lead, string $codigo): array
    {
        if (trim($codigo) === '') {
            return ['status' => 0, 'respuesta' => null, 'payload' => null, 'error' => 'El código de prueba está vacío: sin él el evento contaría como real.'];
        }

        $canal = self::canal_de_la_compra($lead);
        if ($canal === null) {
            return ['status' => 0, 'respuesta' => null, 'payload' => null, 'error' => 'El lead no está promovido ni tiene canal: no hay compra que probar.'];
        }
        if (! self::config_completa($canal)) {
            return ['status' => 0, 'respuesta' => null, 'payload' => null, 'error' => 'Falta ' . self::config_que_falta($canal) . '.'];
        }

        $muestra = new LeadEventoMeta([
            'lead_id'    => (int) $lead->id,
            'canal'      => $canal,
            'evento'     => LeadEventoMeta::EVENTO_PURCHASE,
            'event_id'   => self::event_id_para((int) $lead->id, LeadEventoMeta::EVENTO_PURCHASE, $canal) . '-prueba',
            'event_time' => AppTime::now(),
        ]);
        $payload                          = $this->armar_payload($muestra, $lead);
        $payload['data'][0]['event_name'] = self::EVENTO_DE_PRUEBA;
        $payload['test_event_code']       = trim($codigo);

        try {
            $respuesta = Http::withToken(self::token_del_canal($canal))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.meta_capi.timeout', 15))
                ->post($this->url_de_eventos($canal), $payload);
        } catch (\Throwable $exception) {
            return ['status' => 0, 'respuesta' => null, 'payload' => $payload, 'error' => 'No se pudo conectar con Meta: ' . $this->sin_token($exception->getMessage())];
        }

        $json = $respuesta->json();

        return [
            'status'    => $respuesta->status(),
            'respuesta' => $this->resumen_de_respuesta(is_array($json) ? $json : []),
            'payload'   => $payload,
            'error'     => $respuesta->successful() ? null : $this->sin_token(
                is_array($json) && isset($json['error']['message']) && is_string($json['error']['message']) ? $json['error']['message'] : 'sin mensaje'
            ),
        ];
    }

    /**
     * ¿El lead ya es cliente?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private static function esta_promovido(Lead $lead): bool
    {
        return $lead->promoted_client_id !== null && (string) $lead->promoted_client_id !== '';
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
            $fila = $this->crear_fila($lead, $canal, $evento, $estado, $momento, $motivo);
            if ($fila !== null) {
                $creados[] = $fila;
            }
        }

        return $creados;
    }

    /**
     * Crea UNA fila. El único (`lead_id`, `canal`, `evento`) es el que deduplica: si otro proceso la
     * registró entre la lectura y el alta, devuelve null sin error.
     *
     * @param Lead        $lead
     * @param string      $canal
     * @param string      $evento
     * @param string      $estado
     * @param Carbon      $momento
     * @param string|null $motivo
     *
     * @return LeadEventoMeta|null
     */
    private function crear_fila(Lead $lead, string $canal, string $evento, string $estado, Carbon $momento, ?string $motivo): ?LeadEventoMeta
    {
        try {
            return LeadEventoMeta::create([
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

                return null;
            }

            /* Cualquier otra cosa (un valor que no entra con `strict`, la base caída) es un
               error de verdad y no un duplicado: se loguea como tal y se sigue con el resto. */
            Log::channel('daily')->error('Conversiones Meta: la base rechazó el registro del evento.', [
                'lead_id' => (int) $lead->id,
                'canal'   => $canal,
                'evento'  => $evento,
                'error'   => $this->sin_token($exception->getMessage()),
            ]);

            return null;
        }
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
        if ($lead === null) {
            $this->marcar_error(
                $evento,
                $canal === LeadEventoMeta::CANAL_WHATSAPP
                    ? 'El lead ya no existe o no tiene ctwa_clid: Meta no tiene con qué atribuir el evento.'
                    : 'El lead ya no existe: no hay a quién atribuirle el evento.',
                null
            );

            return LeadEventoMeta::ESTADO_ERROR;
        }

        /* 🔴 Un lead no se reporta en dos canales: una fila de un canal que ya no es el del lead (o
           de un lead que se quedó sin canal) no sale. Queda `omitido`, que no reclama nadie más.
           El `Purchase` mira el canal de la compra: un promovido sin atribución sale por la web. */
        $canal_del_lead = (string) $evento->evento === LeadEventoMeta::EVENTO_PURCHASE
            ? self::canal_de_la_compra($lead)
            : self::canal_del_lead($lead);
        if ($canal_del_lead !== $canal) {
            $this->marcar_omitido(
                $evento,
                LeadEventoMeta::MOTIVO_CANAL_CAMBIADO . ' ('
                    . ($canal_del_lead !== null ? 'ahora es ' . $canal_del_lead : 'ya no tiene canal')
                    . '): este evento de ' . $canal . ' no sale, para no reportarlo en dos canales.'
            );

            return LeadEventoMeta::ESTADO_OMITIDO;
        }

        if ($canal === LeadEventoMeta::CANAL_WHATSAPP && trim((string) $lead->ctwa_clid) === '') {
            $this->marcar_error($evento, 'El lead ya no existe o no tiene ctwa_clid: Meta no tiene con qué atribuir el evento.', null);

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
            $dato['user_data']     = $this->user_data_web($lead, $momento);
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
    private function user_data_web(Lead $lead, ?Carbon $momento = null): array
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

        $fbc = self::fbc_del_lead($lead, $momento);
        if ($fbc !== null) {
            $datos['fbc'] = $fbc;
        }

        return $datos;
    }

    /**
     * El `fbc` del lead, armado con el `fbclid` del anuncio que trajo a la landing (misión
     * purchase-capi-al-promover, 22/10/2026).
     *
     * La landing guarda los parámetros del clic en la referencia `[CC:…]` que viaja en el primer
     * mensaje (misión atribucion-whatsapp-anuncios, 21/10), con el `fbclid` incluido. Meta pide el
     * `fbc` como `fb.1.{milisegundos del clic}.{fbclid}`, **sin hash**, y documenta armarlo así cuando
     * no hay cookie `_fbc`. Es el dato de coincidencia más fuerte que tenemos de la web: une el evento
     * con el clic exacto, no con la persona.
     *
     * El momento es el de la referencia (cuando la landing la emitió, con la visita). Si el lead tiene
     * varios contactos con `fbclid`, va el del más reciente: el último clic antes de la compra.
     * Un `fbclid` con caracteres raros no se manda (Meta lo rechazaría). Con `$hasta`, solo clics
     * anteriores al evento (un reintento no le pega a la compra un clic posterior).
     *
     * 🔴 Nunca tira: si la consulta falla (por ejemplo, sin las tablas de atribución), devuelve null y
     * el evento sale sin `fbc`, como antes del 22/10. Un `fbc` no puede tumbar los eventos de la web.
     *
     * @param Lead        $lead
     * @param Carbon|null $hasta El `event_time` del evento.
     *
     * @return string|null
     */
    public static function fbc_del_lead(Lead $lead, ?Carbon $hasta = null): ?string
    {
        if (! $lead->id) {
            return null;
        }

        try {
            $consulta = DB::table('lead_atribucion_contactos as c')
                ->join('landing_whatsapp_referencias as r', 'r.id', '=', 'c.referencia_id')
                ->where('c.lead_id', (int) $lead->id)
                ->where('c.evidencia', 'referencia_landing')
                ->orderByDesc('c.message_id');
            if ($hasta !== null) {
                $consulta->where('r.created_at', '<=', $hasta);
            }
            $contactos = $consulta->get(['c.datos', 'r.created_at']);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('Conversiones Meta: no se pudo leer el fbclid de la landing; el evento sale sin fbc.', [
                'lead_id' => (int) $lead->id,
                'error'   => $exception->getMessage(),
            ]);

            return null;
        }

        foreach ($contactos as $contacto) {
            $datos  = json_decode((string) $contacto->datos, true);
            $fbclid = is_array($datos) && isset($datos['fbclid']) && is_string($datos['fbclid']) ? trim($datos['fbclid']) : '';

            if ($fbclid === '' || ! preg_match('/^[A-Za-z0-9_\-]{1,500}$/', $fbclid) || $contacto->created_at === null) {
                continue;
            }

            return 'fb.1.' . (Carbon::parse($contacto->created_at)->getTimestamp() * 1000) . '.' . $fbclid;
        }

        return null;
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
     * Deja `omitido` una fila que este proceso reclamó y que no tiene que salir. Condicional sobre
     * `enviando`: solo la toca el que la reclamó.
     *
     * @param LeadEventoMeta $evento
     * @param string         $motivo
     *
     * @return void
     */
    private function marcar_omitido(LeadEventoMeta $evento, string $motivo): void
    {
        LeadEventoMeta::query()
            ->whereKey((int) $evento->id)
            ->where('estado', LeadEventoMeta::ESTADO_ENVIANDO)
            ->update([
                'estado'       => LeadEventoMeta::ESTADO_OMITIDO,
                'ultimo_error' => $motivo,
            ]);

        Log::channel('daily')->info('Conversiones Meta: el evento no sale porque el lead cambió de canal.', [
            'lead_evento_meta_id' => (int) $evento->id,
            'lead_id'             => (int) $evento->lead_id,
            'canal'               => self::canal_de_la_fila($evento),
            'evento'              => $evento->evento,
        ]);
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
