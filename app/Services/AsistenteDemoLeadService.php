<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientAssistantMessage;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\WhatsappConfig;
use App\Helpers\WhatsappNormalizer;
use Carbon\Carbon;

/**
 * El asistente personal para el lead mientras dura su demo (misión mail-demo-turno-y-asistente,
 * 16/10/2026).
 *
 * El número del asistente atiende solo al DUEÑO de un cliente (`WhatsappWebhookController::
 * handle_mensaje_del_asistente`). Lucas pidió que el lead lo pueda probar durante su demo: que le
 * escriba desde el mismo WhatsApp con el que habla con nosotros y le haga preguntas y pedidos
 * sobre los datos de su instancia de demostración. Esta clase concentra las cuatro decisiones que
 * eso necesita, para que ni el webhook ni el job ni el mail tengan cada uno su propia copia:
 *
 *   1. **Cuándo está disponible el canal** (`config_disponible()`): el interruptor propio y que el
 *      número del asistente esté LISTO (`WhatsappConfig::canalListo`). Mientras el número no exista
 *      de verdad —hoy no existe—, el mail no muestra el bloque, el agente de leads no lo menciona y
 *      el webhook ni se entera: todo queda inerte, sin prometerle al lead algo que no funciona.
 *   2. **Quién puede usarlo** (`lead_con_turno_por_telefono()`): un lead con una instancia asignada,
 *      en un estado que la ocupa, con el setup terminado y DENTRO de su turno, cuyo teléfono es
 *      EXACTAMENTE el que escribe. Comparación estricta (`equals_estricto`), no los últimos ocho
 *      dígitos: acá un falso positivo le abre a una persona el asistente de la demo de otra.
 *   3. **A qué sistema se le habla** (`cliente_virtual()`): la instancia de demo del lead. La demo no
 *      tiene fila en `clients` y no se le crea una (entraría en las tandas de actualización, en los
 *      informes de las 08:30 y en los conteos de la flota). El job de siempre pide un `Client`, así
 *      que se le arma uno SIN GUARDAR, en memoria, con la clave y las URLs de la demo.
 *   4. **Qué se le dice cuando todavía no o ya no** (`texto_*`).
 *
 * 🔴 Nada de esto toca a los dueños de cliente: el webhook solo llega acá cuando `find_dueno_por_
 * telefono_exacto()` ya devolvió null, y toda fila con `demo_id` null recorre el camino de siempre.
 */
class AsistenteDemoLeadService
{
    /** Clave del interruptor propio de este canal. Ausente = prendido. */
    public const KEY_ACTIVO = 'asistente_demo_leads_activo';

    /**
     * Minutos ANTES del inicio del turno desde los que el asistente ya contesta.
     *
     * El turno arranca diez minutos después del "sí" (`DemoDirectaService::MINUTOS_HASTA_EL_INICIO`)
     * y el lead recibe el mail en ese momento: si lo prueba apenas lo lee, todavía no empezó. El
     * margen cubre ese rato; el dato real que decide es `demo_setup_status`, que no sale de
     * `exitoso` hasta que la instancia está armada.
     */
    public const MINUTOS_ANTES_DEL_TURNO = 15;

    /** Clave: texto para el lead cuya demo todavía se está armando. */
    public const KEY_TEXTO_PREPARANDO = 'asistente_whatsapp_texto_lead_preparando';

    /** Clave: texto para el lead que no tiene una demo activa. */
    public const KEY_TEXTO_SIN_TURNO = 'asistente_whatsapp_texto_lead_sin_turno';

    private const DEFAULT_TEXTO_PREPARANDO = 'Tu demo se está armando todavía. Apenas puedas entrar al sistema, escribime de nuevo '
        . 'por acá y lo probamos juntos.';

    private const DEFAULT_TEXTO_SIN_TURNO = 'Este número es el asistente personal de ComercioCity, y se puede probar durante tu demo. '
        . 'Ahora no tenés una demo activa. Para seguir hablando con el equipo escribinos al +54 9 3444 54-4199';

    /** Lo que recibe el lead cuando la instancia de su demo no tiene el asistente (404 del endpoint). */
    public const TEXTO_SIN_ENDPOINT = 'Por ahora el asistente no está disponible en tu demo. Escribinos al '
        . '+54 9 3444 54-4199 y lo resolvemos enseguida.';

    /** Situaciones en las que puede estar un lead frente al asistente. */
    public const SITUACION_ACTIVO    = 'activo';
    public const SITUACION_PREPARANDO = 'preparando';
    public const SITUACION_SIN_TURNO = 'sin_turno';

    /**
     * Si el interruptor propio de este canal está prendido.
     *
     * Ausente o vacío = prendido: es una función nueva que Lucas pidió, y la marcha atrás explícita
     * es escribir `0`/`false`/`off`/`no` en `admin_settings`. Se listan los valores que APAGAN y
     * todo lo demás deja el canal prendido, al revés de `tickets_habilitados()`: ahí el lado seguro
     * era apagado; acá lo seguro ya lo da el número sin armar (ver `config_disponible()`).
     *
     * @return bool
     */
    public static function interruptor_prendido(): bool
    {
        $raw = AdminSetting::get(self::KEY_ACTIVO, null);
        if ($raw === null) {
            return true;
        }

        return ! in_array(strtolower(trim((string) $raw)), ['0', 'false', 'off', 'no', 'apagado'], true);
    }

    /**
     * La fila del número del asistente, SOLO si el canal está en condiciones de atender a un lead.
     *
     * Es el único punto que decide "¿le prometemos el asistente al lead?": lo leen el mail, el
     * contexto del agente de leads y el webhook. Las tres cosas tienen que coincidir siempre.
     *
     * @return WhatsappConfig|null Null = no disponible (interruptor apagado o número sin terminar).
     */
    public static function config_disponible(): ?WhatsappConfig
    {
        if (! self::interruptor_prendido()) {
            return null;
        }

        return WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE);
    }

    /**
     * Si el asistente se le puede ofrecer a un lead ahora mismo.
     *
     * @return bool
     */
    public static function disponible(): bool
    {
        return self::config_disponible() !== null;
    }

    /**
     * El número del asistente tal como se lee ("+1 201-534-4158"), o '' si no está disponible.
     *
     * @return string
     */
    public static function numero_visible(): string
    {
        $config = self::config_disponible();

        return $config !== null ? trim((string) $config->display_phone) : '';
    }

    /**
     * Enlace `wa.me` que abre el chat con el asistente con un toque, con un primer mensaje ya
     * escrito para que el lead no tenga que pensar cómo arrancar. '' si no está disponible.
     *
     * @return string
     */
    public static function url_whatsapp(): string
    {
        $config = self::config_disponible();
        if ($config === null) {
            return '';
        }

        $digitos = preg_replace('/\D+/', '', (string) $config->display_phone);
        if ($digitos === null || $digitos === '') {
            return '';
        }

        return 'https://wa.me/' . $digitos . '?text=' . rawurlencode('Hola, estoy en mi demo de ComercioCity');
    }

    /**
     * La situación de un lead frente al asistente, y el lead mismo si puede usarlo ahora.
     *
     * @param string      $telefono Teléfono del remitente TAL CUAL llegó en el payload.
     * @param Carbon|null $ahora    Instante de referencia (reloj de la app si no viene).
     *
     * @return array{situacion: string, lead: Lead|null} `lead` viene cargado salvo en `sin_turno`
     *                                                   cuando el teléfono no es de ningún lead con demo.
     */
    public function situacion_del_lead(string $telefono, ?Carbon $ahora = null): array
    {
        $ahora = $ahora !== null ? $ahora->copy()->setTimezone(DemoDirectaService::TZ) : AppTime::now(DemoDirectaService::TZ);

        if (! self::argentino($telefono)) {
            /* `WhatsappSendService::send_text()` normaliza el destino como argentino: a un número de
             * otro país no se le puede contestar, y rutearlo sería abrir un hilo mudo. */
            return ['situacion' => self::SITUACION_SIN_TURNO, 'lead' => null];
        }

        $candidatos = Lead::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereNotNull('demo_id')
            ->whereNotNull('demo_date')
            ->where('demo_date', '>=', $ahora->copy()->subDay()->format('Y-m-d'))
            ->whereIn('status', DemoDirectaService::ESTADOS_QUE_OCUPAN_LA_INSTANCIA)
            ->orderByDesc('id')
            ->get();

        $con_demo_vencida = null;
        foreach ($candidatos as $lead) {
            if (! WhatsappNormalizer::equals_estricto((string) $lead->phone, $telefono)) {
                continue;
            }

            $ventana = $this->ventana_del_turno($lead);
            if ($ventana === null) {
                continue;
            }

            if ($ahora->lt($ventana['desde']) || $ahora->gt($ventana['hasta'])) {
                $con_demo_vencida = $con_demo_vencida ?: $lead;
                continue;
            }

            if ((string) $lead->demo_setup_status !== 'exitoso') {
                return ['situacion' => self::SITUACION_PREPARANDO, 'lead' => $lead];
            }

            return ['situacion' => self::SITUACION_ACTIVO, 'lead' => $lead];
        }

        return ['situacion' => self::SITUACION_SIN_TURNO, 'lead' => $con_demo_vencida];
    }

    /**
     * El lead con turno vigente y la instancia lista cuyo teléfono es este, o null.
     *
     * @param string      $telefono Teléfono del remitente TAL CUAL llegó en el payload.
     * @param Carbon|null $ahora    Instante de referencia.
     *
     * @return Lead|null
     */
    public function lead_con_turno_por_telefono(string $telefono, ?Carbon $ahora = null): ?Lead
    {
        $resultado = $this->situacion_del_lead($telefono, $ahora);

        return $resultado['situacion'] === self::SITUACION_ACTIVO ? $resultado['lead'] : null;
    }

    /**
     * Ventana [desde, hasta] en la que el asistente atiende a este lead.
     *
     * Sale del turno cargado en el lead (`demo_date` + `demo_start_time`/`demo_end_time`), en la zona
     * de la demo. Un turno sin fecha, sin hora de fin o con una hora ilegible NO tiene ventana: el
     * lado seguro es no abrirle el asistente a nadie por un dato roto.
     *
     * @param Lead $lead
     *
     * @return array{desde: Carbon, hasta: Carbon}|null
     */
    public function ventana_del_turno(Lead $lead): ?array
    {
        if ($lead->demo_date === null) {
            return null;
        }

        $inicio = self::hora_a_minutos((string) $lead->demo_start_time);
        $fin    = self::hora_a_minutos((string) $lead->demo_end_time);
        if ($inicio === null || $fin === null || $fin <= $inicio) {
            return null;
        }

        $dia = Carbon::parse($lead->demo_date->format('Y-m-d'), DemoDirectaService::TZ)->startOfDay();

        return [
            'desde' => $dia->copy()->addMinutes($inicio - self::MINUTOS_ANTES_DEL_TURNO),
            'hasta' => $dia->copy()->addMinutes($fin),
        ];
    }

    /**
     * Un `Client` EN MEMORIA que representa la instancia de demo, para el job del asistente.
     *
     * 🔴 Nunca se guarda. Tiene `is_active` en false a propósito: es la condición que
     * `AsistenteAvisosService::cliente_recibe_avisos()` mira antes de prometerle al `empresa-api`
     * "te aviso por acá", y a una demo no se le va a avisar nada (el barrido recorre clientes reales).
     * `asistente_whatsapp_activo` va en true porque es lo que el job exige para tramitar el mensaje.
     *
     * Las URLs salen de `demos.erp_api_url` / `erp_spa_url` y la clave de `demos.api_key`, que son
     * las mismas que ya usa `DemoIngresoTokenService` para hablarle a esa instancia. El `ClientApi`
     * se arma en memoria y se cuelga de la relación ya cargada, así que el resolver de URLs no
     * consulta la base.
     *
     * @param Demo $demo Instancia de demo del lead.
     *
     * @return Client
     */
    public function cliente_virtual(Demo $demo): Client
    {
        $api = new ClientApi();
        $api->forceFill([
            'url'          => (string) $demo->erp_api_url,
            'spa_url'      => (string) $demo->erp_spa_url,
            'hosting_type' => (new DemoPathResolver())->hosting_type($demo),
        ]);

        $client = new Client();
        $client->forceFill([
            'id'                         => 0,
            'name'                       => 'Demo ' . $demo->display_name(),
            'is_active'                  => false,
            'asistente_whatsapp_activo'  => true,
            'api_key'                    => trim((string) $demo->api_key),
        ]);
        $client->setRelation('client_apis', collect([$api]));
        $client->setRelation('active_client_api', $api);

        return $client;
    }

    /**
     * El destino de una fila del hilo: el cliente virtual de su demo si es de un lead, y null si es
     * de una demo que ya no existe. Para una fila de dueño no se usa: el job busca el `Client`.
     *
     * @param ClientAssistantMessage $fila
     *
     * @return Client|null
     */
    public function destino_de_la_fila(ClientAssistantMessage $fila): ?Client
    {
        if (! $fila->es_de_demo()) {
            return null;
        }

        $demo = Demo::find((int) $fila->demo_id);

        return $demo !== null ? $this->cliente_virtual($demo) : null;
    }

    /**
     * Texto para un lead que escribe al asistente cuando no tiene una demo activa.
     *
     * @param string $situacion Una de las SITUACION_*.
     *
     * @return string
     */
    public static function texto_para_lead(string $situacion): string
    {
        if ($situacion === self::SITUACION_PREPARANDO) {
            $texto = trim((string) AdminSetting::get(self::KEY_TEXTO_PREPARANDO, ''));

            return $texto !== '' ? $texto : self::DEFAULT_TEXTO_PREPARANDO;
        }

        $texto = trim((string) AdminSetting::get(self::KEY_TEXTO_SIN_TURNO, ''));

        return $texto !== '' ? $texto : self::DEFAULT_TEXTO_SIN_TURNO;
    }

    /**
     * Si un teléfono es argentino (empieza con 54, ignorando ceros y signos).
     *
     * @param string $telefono
     *
     * @return bool
     */
    private static function argentino(string $telefono): bool
    {
        $digitos = ltrim((string) preg_replace('/\D+/', '', $telefono), '0');

        return strpos($digitos, '54') === 0;
    }

    /**
     * Minutos desde la medianoche de un texto "HH:MM" (o "HH:MM:SS"), o null si no se lee.
     *
     * @param string $hora
     *
     * @return int|null
     */
    private static function hora_a_minutos(string $hora): ?int
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})/', $hora, $m)) {
            return null;
        }

        $horas   = (int) $m[1];
        $minutos = (int) $m[2];
        if ($horas > 23 || $minutos > 59) {
            return null;
        }

        return $horas * 60 + $minutos;
    }
}
