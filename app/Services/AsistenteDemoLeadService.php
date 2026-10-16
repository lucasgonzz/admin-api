<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientAssistantMessage;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\WhatsappConfig;
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
 *   2. **Quién puede usarlo** (`situacion_del_lead()`): un lead con una instancia asignada,
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
     * `exitoso` hasta que la instancia está armada. Nunca pasa de los minutos que la instancia se
     * reserva antes del turno para su setup (`LeadDemoSettings::get_setup_minutos_antes()`): con un
     * margen mayor, el asistente de un lead abriría sobre la instancia que otro todavía ocupa.
     */
    public const MINUTOS_ANTES_DEL_TURNO = 15;

    /** Clave: texto para el lead cuya demo todavía se está armando. */
    public const KEY_TEXTO_PREPARANDO = 'asistente_whatsapp_texto_lead_preparando';

    /** Clave: texto para el lead que no tiene una demo activa. */
    public const KEY_TEXTO_SIN_TURNO = 'asistente_whatsapp_texto_lead_sin_turno';

    private const DEFAULT_TEXTO_PREPARANDO = 'Tu demo se está armando todavía. Apenas puedas entrar al sistema, escribime de nuevo '
        . 'por acá y lo probamos juntos.';

    /** Marca de los textos fijos que se reemplaza por el número del equipo (el de leads, configurable). */
    private const MARCA_NUMERO_DEL_EQUIPO = '{equipo}';

    private const DEFAULT_TEXTO_SIN_TURNO = 'Este número es el asistente personal de ComercioCity, y se puede probar durante tu demo. '
        . 'Ahora no tenés una demo activa. Para seguir hablando con el equipo escribinos al {equipo}';

    /** Lo que recibe el lead cuando la instancia de su demo no tiene el asistente (404 del endpoint). */
    private const DEFAULT_TEXTO_SIN_ENDPOINT = 'Por ahora el asistente no está disponible en tu demo. Escribinos al '
        . '{equipo} y lo resolvemos enseguida.';

    /** Lo que recibe el lead cuando el asistente de su demo falla (el de los dueños lo manda al chat del sistema). */
    private const DEFAULT_TEXTO_DE_DISCULPA = 'Uf, no pude contestarte ahora. Probá de nuevo en un ratito; si sigue igual, '
        . 'escribinos al {equipo}.';

    /** Situaciones en las que puede estar un lead frente al asistente. */
    public const SITUACION_ACTIVO    = 'activo';
    public const SITUACION_PREPARANDO = 'preparando';
    public const SITUACION_SIN_TURNO = 'sin_turno';
    public const SITUACION_SIN_CLAVE = 'sin_clave';

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
     * Si el asistente se le puede ofrecer A ESTE LEAD: el canal disponible y un teléfono al que se le
     * pueda contestar (argentino: el envío normaliza el destino como argentino).
     *
     * Lo usan el mail y el contexto del agente, para no prometerle el asistente a un lead de otro país
     * al que el webhook no le va a abrir el hilo, ni sobre una instancia de demo sin clave.
     *
     * @param Lead      $lead
     * @param Demo|null $demo Instancia asignada (o prevista) al lead, si se la conoce.
     *
     * @return bool
     */
    public static function disponible_para(Lead $lead, ?Demo $demo = null): bool
    {
        if (! self::disponible() || ! self::telefono_argentino((string) $lead->phone)) {
            return false;
        }

        /* Una instancia sin clave no puede atender al asistente (el job no le pega sin credencial): no se le
         * promete al lead lo que esa demo no puede dar. */
        return $demo === null || trim((string) $demo->api_key) !== '';
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

        if (! self::telefono_argentino($telefono)) {
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

            /* La instancia no tiene clave cargada (`demos.api_key`): el job no le habla a una demo sin
             * credencial, así que se le dice al lead que el asistente no está disponible en vez de mandarlo a
             * un error que además avisa a los admins en cada mensaje. */
            $demo_del_lead = Demo::find((int) $lead->demo_id);
            if ($demo_del_lead === null || trim((string) $demo_del_lead->api_key) === '') {
                return ['situacion' => self::SITUACION_SIN_CLAVE, 'lead' => $lead];
            }

            return ['situacion' => self::SITUACION_ACTIVO, 'lead' => $lead];
        }

        return ['situacion' => self::SITUACION_SIN_TURNO, 'lead' => $con_demo_vencida];
    }

    /**
     * Ventana [desde, hasta] en la que el asistente atiende a este lead.
     *
     * Empieza `MINUTOS_ANTES_DEL_TURNO` antes del inicio y termina cuando vence el turno, que es LA MISMA
     * definición de "vigente" que usa el link de ingreso y el mail (`DemoTurnoTexto::vence_el_turno()`):
     * un lead que todavía puede operar en su demo —dentro de la gracia— no se queda sin asistente. Un
     * turno sin fecha o con una hora ilegible NO tiene ventana: el lado seguro es no abrirle el asistente
     * a nadie por un dato roto.
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

        $inicio = DemoTurnoTexto::leer_hora((string) $lead->demo_start_time);
        $fin    = DemoTurnoTexto::leer_hora((string) $lead->demo_end_time);
        if ($inicio === null || $fin === null) {
            return null;
        }

        $dia = Carbon::parse($lead->demo_date->format('Y-m-d'), DemoDirectaService::TZ)->startOfDay();

        return [
            'desde' => $dia->copy()->addMinutes($inicio['minutos'] - min(self::MINUTOS_ANTES_DEL_TURNO, LeadDemoSettings::get_setup_minutos_antes())),
            'hasta' => DemoTurnoTexto::vence_el_turno($lead),
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
        if ($situacion === self::SITUACION_SIN_CLAVE) {
            return self::texto_sin_endpoint();
        }

        if ($situacion === self::SITUACION_PREPARANDO) {
            $texto = trim((string) AdminSetting::get(self::KEY_TEXTO_PREPARANDO, ''));

            return self::con_el_numero_del_equipo($texto !== '' ? $texto : self::DEFAULT_TEXTO_PREPARANDO);
        }

        $texto = trim((string) AdminSetting::get(self::KEY_TEXTO_SIN_TURNO, ''));

        return self::con_el_numero_del_equipo($texto !== '' ? $texto : self::DEFAULT_TEXTO_SIN_TURNO);
    }

    /**
     * Lo que recibe el lead cuando la instancia de su demo no tiene el endpoint del asistente (404).
     *
     * @return string
     */
    public static function texto_sin_endpoint(): string
    {
        return self::con_el_numero_del_equipo(self::DEFAULT_TEXTO_SIN_ENDPOINT);
    }

    /**
     * Lo que recibe el lead cuando el asistente de su demo falla del lado del admin o de la instancia.
     *
     * Es propio y no el de los dueños (`AsistenteWhatsappService::TEXTO_DE_DISCULPA`), que manda al chat
     * del sistema: un lead no tiene un sistema propio donde seguir.
     *
     * @return string
     */
    public static function texto_de_disculpa(): string
    {
        return self::con_el_numero_del_equipo(self::DEFAULT_TEXTO_DE_DISCULPA);
    }

    /**
     * El número del equipo (el de leads, configurable en la cuenta) tal como se lee: "+54 9 3444544199".
     *
     * Sale de `LeadDemoSettings::get_whatsapp_numero_leads()` y no de una cadena escrita acá: si Lucas
     * cambia ese número, estos textos lo siguen solos. Sin un número válido configurado devuelve
     * 'WhatsApp de siempre' ("escribinos al WhatsApp de siempre"), que no manda a ningún lado pero tampoco miente.
     *
     * @return string
     */
    public static function numero_del_equipo_legible(): string
    {
        $digitos = LeadDemoSettings::get_whatsapp_numero_leads();
        if ($digitos === '') {
            return 'WhatsApp de siempre';
        }

        /* Un celular argentino cargado sin el 9 (54 + 10 dígitos, como viene por defecto) se escribe igual con el 9:
         * así se lo dicta y así lo muestra el resto del sistema. Compacto y sin guiones a propósito: el largo del
         * prefijo de área varía (11, 351, 3444...) y un agrupado fijo lo escribiría mal para la mitad del país. */
        if (strlen($digitos) === 12 && strpos($digitos, '54') === 0 && $digitos[2] !== '9') {
            $digitos = '549' . substr($digitos, 2);
        }

        if (strlen($digitos) === 13 && strpos($digitos, '549') === 0) {
            return '+54 9 ' . substr($digitos, 3);
        }

        return '+' . $digitos;
    }

    /**
     * Reemplaza la marca `{equipo}` de un texto fijo por el número del equipo.
     *
     * @param string $texto
     *
     * @return string
     */
    private static function con_el_numero_del_equipo(string $texto): string
    {
        return str_replace(self::MARCA_NUMERO_DEL_EQUIPO, self::numero_del_equipo_legible(), $texto);
    }

    /**
     * Si un teléfono es argentino (empieza con 54, ignorando ceros y signos).
     *
     * @param string $telefono
     *
     * @return bool
     */
    public static function telefono_argentino(string $telefono): bool
    {
        $digitos = ltrim((string) preg_replace('/\D+/', '', $telefono), '0');

        return strpos($digitos, '54') === 0;
    }
}
