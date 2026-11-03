<?php

namespace App\Services\Local;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\IaImagenPedido;
use App\Models\LocalActividadCliente;
use App\Models\LocalOficinaSlot;
use App\Models\SupportTicket;
use App\Services\ClientScheduleResolver;
use App\Services\SupportCodexHealthService;
use App\Services\SupportCodexService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arma el estado de «El local» que dibuja la SPA (misión el-local, 3/11/2026, contrato B del plan:
 * `GET api/admin/local/escena`).
 *
 * El JSON tiene siete bloques y cada uno sale de un método privado: `negocios()`, `agentes()`, `bandeja()`,
 * `oficina()` y `eventos()`, más `calculado_en` y `resumen`. Los nombres de los campos y los valores de los
 * enums son el contrato con `director.js` de la SPA: no se cambian sin cambiar los dos lados.
 *
 * 🔴 COSTO. La vista se pide cada 60 s y el controlador la guarda 20 s en cache. Tiene que contestar en menos
 * de 500 ms con 60 clientes, así que:
 *   - UNA consulta por fuente, agrupada por `client_id` (nunca una por cliente): ver leer_fuentes();
 *   - toda consulta de mensajes va acotada por una ventana de minutos (constantes VENTANA_*) sobre una columna
 *     indexada — para `support_messages` y `lead_messages` los índices por `created_at` los agregó esta misión;
 *   - nada de `Client::with(...)` ni del modelo entero: los clientes se leen con una lista blanca de columnas
 *     ({@see self::COLUMNAS_CLIENTE}; `Client` tiene `$guarded = []` y ningún `$hidden`), y la única relación
 *     que se carga son los horarios, en dos consultas para todos, porque {@see ClientScheduleResolver} la
 *     necesita y si no la pediría de a un cliente.
 *
 * 🔴 PRIVACIDAD. La escena no lleva ningún texto de un mensaje: solo quién, qué tipo de cosa y cuándo. Lo único
 * con texto libre es el título de la bandeja «Para Lucas» (las primeras 70 letras de una misión, el motivo de
 * una revisión), que es exactamente lo que Lucas tiene que ver para decidir qué abrir.
 *
 * Todas las horas salen de {@see AppTime::now()} (zona America/Argentina/Buenos_Aires) y se devuelven ISO-8601
 * con zona.
 */
class EscenaService
{
    /** Clave del cache de 20 s que pone el controlador. */
    const CACHE_KEY = 'local:escena';

    /** Segundos que vive la escena en cache: dos admins mirando no duplican el cálculo. */
    const CACHE_SEGUNDOS = 20;

    /** Zona de todas las horas que devuelve la escena. */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /** Un negocio está «activo» si hizo algo en los últimos 30 minutos. */
    const VENTANA_ACTIVO_MIN = 30;

    /** Hasta dónde se mira hacia atrás para `ultima_actividad_at` (si no hubo nada, null). */
    const VENTANA_ULTIMA_ACTIVIDAD_MIN = 1440;

    /** Un mensaje del cliente a soporte en los últimos 15 minutos lo deja parado en Soporte. */
    const VENTANA_MENSAJE_SOPORTE_MIN = 15;

    /** Una respuesta del asistente en los últimos 5 minutos lo deja parado en el Asistente. */
    const VENTANA_RESPONDIDO_MIN = 5;

    /**
     * Un mensaje al asistente esperando respuesta cuenta solo si es de la última hora. 🔴 Es una guarda contra
     * filas trabadas (un `enviado` que nunca terminó): sin ella, un cliente quedaría parado en el mostrador
     * para siempre.
     */
    const VENTANA_PENDIENTE_ASISTENTE_MIN = 60;

    /** El pulso del monitor de soporte y el del reporter se dan por muertos a los 5 minutos. */
    const VENTANA_PULSO_MIN = 5;

    /** El agente comercial «trabaja» si mandó un mensaje a un lead en los últimos 5 minutos. */
    const VENTANA_COMERCIAL_MIN = 5;

    /** Cuánto se mira hacia atrás en `lead_messages` (cubre los 5 min del comercial y los 10 de eventos). */
    const VENTANA_LEADS_MIN = 60;

    /** El robot de facturas y el del negocio «trabajan» si hubo un evento suyo en los últimos 10 minutos. */
    const VENTANA_ESCANEO_MIN = 10;

    /** Los eventos son los de los últimos 10 minutos. */
    const VENTANA_EVENTOS_MIN = 10;

    /** Tolerancia para horas que vienen del reloj de otro sistema (un minuto adelantado no es el futuro). */
    const TOLERANCIA_RELOJ_SEG = 60;

    /**
     * Madrugada (hora Argentina, [00:00, 06:00)): un negocio SIN horario cargado se presume cerrado (regla de
     * Lucas del 2/11/2026 para las actualizaciones de madrugada). Fuera de esa franja queda `sin_horario`.
     */
    const MADRUGADA_DESDE = '00:00';
    const MADRUGADA_HASTA = '06:00';

    /** Ventana de los informes de la mañana del asistente (hora Argentina). */
    const INFORMES_DESDE = '08:30';
    const INFORMES_HASTA = '08:45';

    /** Tope de la bandeja «Para Lucas». */
    const TOPE_BANDEJA = 50;

    /** Tope de eventos devueltos (los más nuevos). */
    const TOPE_EVENTOS = 200;

    /** Tope de filas que se leen por fuente de mensajes (protección, no se espera llegar). */
    const TOPE_FILAS_POR_FUENTE = 2000;

    /** Cuando el reporter nunca mandó nada, la oficina se dibuja con 35 slots. */
    const SLOTS_POR_DEFECTO = 35;

    /** Largo de la misión en el título de la bandeja (contrato B). */
    const LETRAS_DE_MISION = 70;

    /** Largo máximo del resto de los títulos de la bandeja. */
    const LETRAS_DE_TITULO = 120;

    /** Las seis estaciones del mostrador, en el orden en que se devuelven (contrato B). */
    const ESTACIONES = ['soporte', 'facturas', 'negocio', 'asistente', 'informes', 'comercial'];

    /**
     * Las estaciones a las que puede caminar un negocio (`negocios[].estacion`). Gana la de la interacción más
     * reciente; este orden solo desempata cuando dos tienen la misma hora.
     */
    const ESTACIONES_DE_NEGOCIO = ['soporte', 'asistente', 'facturas', 'negocio'];

    /**
     * Estados del asistente que dejan al cliente esperando en el mostrador.
     *
     * 🔴 `agrupado` NO está: es un estado FINAL (la foto viajó pegada a otra fila, que es la que sigue su
     * curso). Contarlo dejaría al dueño parado en el mostrador por una fila que ya terminó.
     */
    const ASISTENTE_PENDIENTES = [
        ClientAssistantMessage::ESTADO_ESPERANDO,
        ClientAssistantMessage::ESTADO_RECIBIDO,
        ClientAssistantMessage::ESTADO_ENVIADO,
    ];

    /** Estados del asistente que lo tienen trabajando (pensando la respuesta). */
    const ASISTENTE_TRABAJANDO = [
        ClientAssistantMessage::ESTADO_RECIBIDO,
        ClientAssistantMessage::ESTADO_ENVIADO,
    ];

    /** Estado del asistente que espera otra cosa del dueño (una foto que acompañe al texto). Sin `agrupado`. */
    const ASISTENTE_EN_ESPERA = [
        ClientAssistantMessage::ESTADO_ESPERANDO,
    ];

    /** Columnas de `clients` que lee la escena. 🔴 Lista blanca: nunca el modelo entero. */
    const COLUMNAS_CLIENTE = ['id', 'name', 'company_name', 'slug', 'logo_url'];

    /** @var ClientScheduleResolver */
    private $horarios;

    /** @var Carbon Momento del cálculo. */
    private $ahora;

    /** @var int Momento del cálculo, en segundos. */
    private $ahora_ts;

    /**
     * @param ClientScheduleResolver $horarios Resuelve abierto / cerrado / sin_configurar por cliente.
     */
    public function __construct(ClientScheduleResolver $horarios)
    {
        $this->horarios = $horarios;
    }

    /**
     * La escena completa, con las claves en el orden del contrato B.
     *
     * @return array<string, mixed>
     */
    public function armar()
    {
        $this->ahora    = AppTime::now(self::ZONA);
        $this->ahora_ts = $this->ahora->getTimestamp();

        $clientes = $this->clientes_activos();
        $fuentes  = $this->leer_fuentes(array_keys($clientes));

        $negocios = $this->negocios($clientes, $fuentes);
        $agentes  = $this->agentes($fuentes);
        $bandeja  = $this->bandeja();
        $oficina  = $this->oficina();
        $eventos  = $this->eventos($fuentes);

        $por_estado = ['abierto' => 0, 'cerrado' => 0, 'sin_horario' => 0];
        foreach ($negocios as $negocio) {
            $por_estado[$negocio['estado']]++;
        }

        $slots_libres     = 0;
        $misiones_en_curso = 0;
        foreach ($oficina['slots'] as $slot) {
            if ($slot['estado'] === 'libre') {
                $slots_libres++;
            }
            if ($slot['estado'] === 'trabajando') {
                $misiones_en_curso++;
            }
        }

        return [
            'calculado_en' => $this->ahora->toIso8601String(),
            'resumen'      => [
                'negocios_abiertos'    => $por_estado['abierto'],
                'negocios_cerrados'    => $por_estado['cerrado'],
                'negocios_sin_horario' => $por_estado['sin_horario'],
                'tickets_abiertos'     => (int) SupportTicket::query()->where('status', SupportTicket::STATUS_ABIERTO)->count(),
                'para_lucas'           => $bandeja['total'],
                'misiones_en_curso'    => $misiones_en_curso,
                'slots_libres'         => $slots_libres,
                'slots_total'          => count($oficina['slots']) > 0 ? count($oficina['slots']) : self::SLOTS_POR_DEFECTO,
            ],
            'negocios'     => $negocios,
            'agentes'      => $agentes,
            'bandeja'      => $bandeja['items'],
            'oficina'      => $oficina,
            'eventos'      => $eventos,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Lectura: una consulta por fuente
     |----------------------------------------------------------------------------------------- */

    /**
     * Clientes activos con sus horarios, indexados por id.
     *
     * @return array<int, Client>
     */
    private function clientes_activos()
    {
        $clientes = Client::query()
            ->select(self::COLUMNAS_CLIENTE)
            ->where('is_active', true)
            ->get();

        /* La única relación que se carga, y es liviana: el resolver de horarios hace `loadMissing` por
           cliente, así que sin esta carga conjunta serían dos consultas por negocio. */
        $clientes->load('schedule_days.schedule_ranges');

        $por_id = [];
        foreach ($clientes as $client) {
            $por_id[(int) $client->id] = $client;
        }

        return $por_id;
    }

    /**
     * Lee todas las fuentes de una vez. Cada clave es una consulta (o dos, si una es agregada y la otra trae
     * filas), siempre acotada por ventana de tiempo y por los clientes activos cuando la fuente es por cliente.
     *
     * @param array<int, int> $ids Ids de los clientes activos.
     *
     * @return array<string, mixed>
     */
    private function leer_fuentes(array $ids)
    {
        $desde_24h       = $this->db_hace(self::VENTANA_ULTIMA_ACTIVIDAD_MIN);
        $desde_soporte   = $this->db_hace(self::VENTANA_MENSAJE_SOPORTE_MIN);
        $desde_asistente = $this->db_hace(self::VENTANA_PENDIENTE_ASISTENTE_MIN);
        $desde_leads     = $this->db_hace(self::VENTANA_LEADS_MIN);
        $desde_eventos   = $this->db_hace(self::VENTANA_EVENTOS_MIN);
        $hasta_reloj     = $this->ahora->copy()->addSeconds(self::TOLERANCIA_RELOJ_SEG)->setTimezone($this->tz_db())->format('Y-m-d H:i:s');

        $fuentes = [
            'ultima'            => [],
            'soporte_ultima'    => null,
            'soporte_mensajes'  => [],
            'soporte_leases'    => [],
            'soporte_escalados' => [],
            'asistente'         => [],
            'imagenes'          => [],
            'leads'             => [],
            'actividad'         => [],
        ];

        if (count($ids) > 0) {
            /* 1. Última actividad de cada cliente en las últimas 24 h, fuente por fuente (agregadas). */
            $this->sumar_ultima($fuentes['ultima'], DB::table('client_assistant_messages')
                ->whereIn('client_id', $ids)
                ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
                ->where('created_at', '>=', $desde_24h)
                ->groupBy('client_id')
                ->select('client_id')
                ->selectRaw('MAX(created_at) AS ultima')
                ->get());

            $mensajes_de_clientes = DB::table('support_messages as m')
                ->join('support_tickets as t', 't.id', '=', 'm.support_ticket_id')
                ->whereIn('t.client_id', $ids)
                ->where('m.sender_type', 'user')
                ->where('m.created_at', '>=', $desde_24h)
                ->groupBy('t.client_id')
                ->select('t.client_id')
                ->selectRaw('MAX(m.created_at) AS ultima')
                ->get();
            $this->sumar_ultima($fuentes['ultima'], $mensajes_de_clientes);
            foreach ($mensajes_de_clientes as $fila) {
                $fuentes['soporte_ultima'] = $this->max_ts($fuentes['soporte_ultima'], $this->ts($fila->ultima));
            }

            /* client_serper_usages.occurred_at se guarda en UTC (ver SerperUsageController::store). */
            $this->sumar_ultima($fuentes['ultima'], DB::table('client_serper_usages')
                ->whereIn('client_id', $ids)
                ->where('occurred_at', '>=', $this->ahora->copy()->subMinutes(self::VENTANA_ULTIMA_ACTIVIDAD_MIN)->utc()->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<=', $this->ahora->copy()->addSeconds(self::TOLERANCIA_RELOJ_SEG)->utc()->format('Y-m-d H:i:s'))
                ->groupBy('client_id')
                ->select('client_id')
                ->selectRaw('MAX(occurred_at) AS ultima')
                ->get(), 'UTC');

            /* Lecturas de novedades: se mira `created_at` (lo pone el admin al recibirla, en su hora) y no
               `read_at`, que llega del sistema del cliente con la zona que éste haya mandado. */
            $this->sumar_ultima($fuentes['ultima'], DB::table('client_notification_reads')
                ->whereIn('client_id', $ids)
                ->where('created_at', '>=', $desde_24h)
                ->groupBy('client_id')
                ->select('client_id')
                ->selectRaw('MAX(created_at) AS ultima')
                ->get());

            /* La actividad que avisa el sistema del cliente (Fase 2). Un `logout` es irse, no actividad. */
            $this->sumar_ultima($fuentes['ultima'], DB::table('local_actividad_clientes')
                ->whereIn('client_id', $ids)
                ->where('tipo', '<>', LocalActividadCliente::TIPO_LOGOUT)
                ->where('ocurrido_at', '>=', $desde_24h)
                ->where('ocurrido_at', '<=', $hasta_reloj)
                ->groupBy('client_id')
                ->select('client_id')
                ->selectRaw('MAX(ocurrido_at) AS ultima')
                ->get());

            /* 2. Asistente: las filas de la última hora (estación, agente, informes y eventos salen de acá). */
            foreach (DB::table('client_assistant_messages')
                ->whereIn('client_id', $ids)
                ->where('created_at', '>=', $desde_asistente)
                ->select('id', 'client_id', 'direccion', 'estado', 'created_at')
                ->orderByDesc('id')
                ->limit(self::TOPE_FILAS_POR_FUENTE)
                ->get() as $fila) {
                $fuentes['asistente'][] = [
                    'id'        => (int) $fila->id,
                    'client_id' => (int) $fila->client_id,
                    'direccion' => (string) $fila->direccion,
                    'estado'    => (string) $fila->estado,
                    'ts'        => $this->ts($fila->created_at),
                ];
            }
        }

        /* 3. Soporte: mensajes de los últimos 15 minutos (del cliente, o respuestas de la IA ya enviadas). */
        foreach (DB::table('support_messages as m')
            ->join('support_tickets as t', 't.id', '=', 'm.support_ticket_id')
            ->where('m.created_at', '>=', $desde_soporte)
            ->where(function ($q) {
                $q->where('m.sender_type', 'user')
                    ->orWhere(function ($q2) {
                        $q2->where('m.sender_type', 'admin')->whereNotNull('m.ai_generated_at');
                    });
            })
            ->where(function ($q) {
                // Un borrador de la IA sin aprobar no le llegó a nadie: no es una respuesta.
                $q->where('m.is_ai_suggestion_draft', false)->orWhereNull('m.is_ai_suggestion_draft');
            })
            ->select('m.id', 'm.support_ticket_id', 'm.sender_type', 'm.created_at', 't.client_id', 't.status')
            ->orderByDesc('m.id')
            ->limit(self::TOPE_FILAS_POR_FUENTE)
            ->get() as $fila) {
            $fuentes['soporte_mensajes'][] = [
                'id'          => (int) $fila->id,
                'ticket_id'   => (int) $fila->support_ticket_id,
                'client_id'   => $fila->client_id === null ? null : (int) $fila->client_id,
                'sender_type' => (string) $fila->sender_type,
                'abierto'     => (string) $fila->status === SupportTicket::STATUS_ABIERTO,
                'ts'          => $this->ts($fila->created_at),
            ];
        }

        /* Reservas vigentes de Martín (el caso tiene `lease_expires_at` en el futuro). La reserva dura
           SupportCodexService::LEASE_MINUTES (y se renueva por ese mismo plazo), así que su inicio es el
           vencimiento menos ese plazo. */
        foreach (DB::table('support_codex_cases as c')
            ->join('support_tickets as t', 't.id', '=', 'c.support_ticket_id')
            ->where('c.lease_expires_at', '>', $this->db_ahora())
            ->select('t.id as ticket_id', 't.client_id', 't.status', 'c.lease_expires_at')
            ->get() as $fila) {
            $vence = $this->ts($fila->lease_expires_at);

            $fuentes['soporte_leases'][] = [
                'ticket_id' => (int) $fila->ticket_id,
                'client_id' => $fila->client_id === null ? null : (int) $fila->client_id,
                'abierto'   => (string) $fila->status === SupportTicket::STATUS_ABIERTO,
                'inicio'    => $vence === null ? null : $vence - SupportCodexService::LEASE_MINUTES * 60,
            ];
        }

        /* Tickets delegados a Lucas en la ventana de eventos. */
        foreach (DB::table('support_tickets')
            ->whereNotNull('escalated_at')
            ->where('escalated_at', '>=', $desde_eventos)
            ->select('id', 'client_id', 'escalated_at')
            ->get() as $fila) {
            $fuentes['soporte_escalados'][] = [
                'ticket_id' => (int) $fila->id,
                'client_id' => $fila->client_id === null ? null : (int) $fila->client_id,
                'ts'        => $this->ts($fila->escalated_at),
            ];
        }

        /* 4. Pedidos de imagen del agente: los reservados ahora (la PC de Lucas generándolos) y los nuevos. */
        foreach (DB::table('ia_imagen_pedidos')
            ->where(function ($q) use ($desde_eventos) {
                $q->where('estado', IaImagenPedido::ESTADO_RESERVADO)
                    ->orWhere('created_at', '>=', $desde_eventos);
            })
            ->select('id', 'client_id', 'estado', 'created_at')
            ->orderByDesc('id')
            ->limit(self::TOPE_FILAS_POR_FUENTE)
            ->get() as $fila) {
            $fuentes['imagenes'][] = [
                'id'        => (int) $fila->id,
                'client_id' => (int) $fila->client_id,
                'estado'    => (string) $fila->estado,
                'ts'        => $this->ts($fila->created_at),
            ];
        }

        /* 5. Leads: los mensajes de la última hora, sin los eventos de estado del hilo. */
        foreach (DB::table('lead_messages')
            ->where('created_at', '>=', $desde_leads)
            ->where(function ($q) {
                $q->where('is_status_event', false)->orWhereNull('is_status_event');
            })
            ->select('id', 'lead_id', 'sender', 'status', 'sent_at', 'created_at')
            ->orderByDesc('id')
            ->limit(self::TOPE_FILAS_POR_FUENTE)
            ->get() as $fila) {
            $creado = $this->ts($fila->created_at);
            $enviado = $this->ts($fila->sent_at);

            $fuentes['leads'][] = [
                'id'      => (int) $fila->id,
                'lead_id' => (int) $fila->lead_id,
                'sender'  => (string) $fila->sender,
                'status'  => (string) $fila->status,
                'ts'      => $creado,
                // Una sugerencia aprobada más tarde sale cuando se envía, no cuando se escribió.
                'enviado' => $enviado !== null ? $enviado : $creado,
            ];
        }

        /* 6. La actividad que avisan los sistemas de los clientes, de los últimos 10 minutos. */
        foreach (DB::table('local_actividad_clientes')
            ->where('ocurrido_at', '>=', $this->db_hace(max(self::VENTANA_ESCANEO_MIN, self::VENTANA_EVENTOS_MIN)))
            ->where('ocurrido_at', '<=', $hasta_reloj)
            ->select('id', 'client_id', 'tipo', 'ocurrido_at')
            ->orderByDesc('ocurrido_at')
            ->limit(self::TOPE_FILAS_POR_FUENTE)
            ->get() as $fila) {
            $fuentes['actividad'][] = [
                'id'        => (int) $fila->id,
                'client_id' => (int) $fila->client_id,
                'tipo'      => (string) $fila->tipo,
                'ts'        => $this->ts($fila->ocurrido_at),
            ];
        }

        return $fuentes;
    }

    /* ------------------------------------------------------------------------------------------
     | Bloques del contrato B
     |----------------------------------------------------------------------------------------- */

    /**
     * `negocios`: los clientes activos ordenados por nombre, con su estado de horario, si están activos y en
     * qué estación del mostrador están.
     *
     * `estado` sale de {@see ClientScheduleResolver::estado_en()}: `abierto` / `cerrado`, y `sin_configurar` se
     * publica como `sin_horario`, salvo de madrugada (00:00 a 06:00, hora Argentina), cuando un negocio sin
     * horario se presume `cerrado`.
     *
     * @param array<int, Client>   $clientes Clientes activos por id.
     * @param array<string, mixed> $fuentes  Lo leído en leer_fuentes().
     *
     * @return array<int, array<string, mixed>>
     */
    private function negocios(array $clientes, array $fuentes)
    {
        $estaciones = $this->estaciones_por_cliente($fuentes);
        $activo_desde = $this->ahora_ts - self::VENTANA_ACTIVO_MIN * 60;

        $negocios = [];
        $hora      = $this->ahora->copy()->setTimezone(self::ZONA)->format('H:i');
        $madrugada = $hora >= self::MADRUGADA_DESDE && $hora < self::MADRUGADA_HASTA;

        foreach ($clientes as $id => $client) {
            $horario = $this->horarios->estado_en($client, $this->ahora, self::ZONA);
            $estado  = $horario;
            if ($horario === ClientScheduleResolver::ESTADO_SIN_CONFIGURAR) {
                $estado = $madrugada ? ClientScheduleResolver::ESTADO_CERRADO : 'sin_horario';
            }

            $ultima   = isset($fuentes['ultima'][$id]) ? $fuentes['ultima'][$id] : null;
            $estacion = isset($estaciones[$id]) ? $estaciones[$id] : null;

            $negocios[] = [
                'id'                  => (int) $id,
                'nombre'              => $this->nombre_de($client->company_name, $client->name, $id),
                'slug'                => $this->texto_o_null($client->slug),
                'logo_url'            => $this->texto_o_null($client->logo_url),
                'estado'              => $estado,
                'activo_reciente'     => $ultima !== null && $ultima >= $activo_desde,
                'ultima_actividad_at' => $this->iso($ultima),
                'estacion'            => $estacion === null ? null : $estacion['estacion'],
                'estacion_desde_at'   => $estacion === null ? null : $this->iso($estacion['desde']),
                'estacion_detalle'    => $estacion === null ? null : $estacion['detalle'],
            ];
        }

        usort($negocios, function ($a, $b) {
            $por_nombre = strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre']));

            return $por_nombre !== 0 ? $por_nombre : $a['id'] - $b['id'];
        });

        return $negocios;
    }

    /**
     * Una estación por cliente: 🔴 la del agente al que le habló ÚLTIMO (pedido de Lucas: «si después le pide
     * algo al agente personal, camina hasta ese; si vuelve a escribir a soporte, vuelve»). No hay prioridad
     * fija: cada estación propone la hora de la última interacción del cliente dentro de su ventana y gana la
     * más nueva. `desde` es esa hora.
     *
     * - `soporte`: el último mensaje del cliente en un ticket ABIERTO (últimos 15 minutos), o el inicio de una
     *   reserva vigente de Martín sobre un ticket abierto (vencimiento − LEASE_MINUTES).
     * - `asistente`: la última fila del hilo con el asistente que sigue activa (esperando / recibido / enviado,
     *   de la última hora) o que se respondió en los últimos 5 minutos.
     * - `facturas`: un `escaneo` avisado por el sistema del cliente en los últimos 10 minutos.
     * - `negocio`: un `agente_negocio` avisado por el sistema del cliente en los últimos 10 minutos.
     *
     * A igual hora decide el orden de {@see self::ESTACIONES_DE_NEGOCIO}, solo para que el resultado no
     * dependa del orden de lectura.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<int, array{estacion: string, desde: int, detalle: string|null}>
     */
    private function estaciones_por_cliente(array $fuentes)
    {
        $candidatas = [];

        foreach ($fuentes['soporte_mensajes'] as $fila) {
            if ($fila['sender_type'] !== 'user' || ! $fila['abierto'] || $fila['client_id'] === null || $fila['ts'] === null) {
                continue;
            }
            $this->proponer_estacion($candidatas, $fila['client_id'], 'soporte', $fila['ts'], 'ticket #' . $fila['ticket_id']);
        }

        foreach ($fuentes['soporte_leases'] as $fila) {
            if (! $fila['abierto'] || $fila['client_id'] === null || $fila['inicio'] === null) {
                continue;
            }
            $inicio = min($fila['inicio'], $this->ahora_ts);
            $this->proponer_estacion($candidatas, $fila['client_id'], 'soporte', $inicio, 'ticket #' . $fila['ticket_id']);
        }

        $respondido_desde = $this->ahora_ts - self::VENTANA_RESPONDIDO_MIN * 60;
        foreach ($fuentes['asistente'] as $fila) {
            if ($fila['ts'] === null) {
                continue;
            }
            if (in_array($fila['estado'], self::ASISTENTE_PENDIENTES, true)) {
                $this->proponer_estacion($candidatas, $fila['client_id'], 'asistente', $fila['ts'], 'esperando al asistente');
            } elseif ($fila['estado'] === ClientAssistantMessage::ESTADO_RESPONDIDO && $fila['ts'] >= $respondido_desde) {
                $this->proponer_estacion($candidatas, $fila['client_id'], 'asistente', $fila['ts'], 'el asistente respondió');
            }
        }

        $actividad_desde = $this->ahora_ts - self::VENTANA_ESCANEO_MIN * 60;
        foreach ($fuentes['actividad'] as $fila) {
            if ($fila['ts'] === null || $fila['ts'] < $actividad_desde) {
                continue;
            }
            if ($fila['tipo'] === LocalActividadCliente::TIPO_ESCANEO) {
                $this->proponer_estacion($candidatas, $fila['client_id'], 'facturas', $fila['ts'], 'escaneando una factura');
            } elseif ($fila['tipo'] === LocalActividadCliente::TIPO_AGENTE_NEGOCIO) {
                $this->proponer_estacion($candidatas, $fila['client_id'], 'negocio', $fila['ts'], 'atendiendo a sus clientes');
            }
        }

        $estaciones = [];
        foreach ($candidatas as $id => $por_estacion) {
            $ganadora = null;
            foreach (self::ESTACIONES_DE_NEGOCIO as $estacion) {
                if (! isset($por_estacion[$estacion])) {
                    continue;
                }
                // Estricto: a igual hora se queda la que vino antes en ESTACIONES_DE_NEGOCIO.
                if ($ganadora === null || $por_estacion[$estacion]['ts'] > $ganadora['desde']) {
                    $ganadora = [
                        'estacion' => $estacion,
                        'desde'    => $por_estacion[$estacion]['ts'],
                        'detalle'  => $por_estacion[$estacion]['detalle'],
                    ];
                }
            }
            if ($ganadora !== null) {
                $estaciones[$id] = $ganadora;
            }
        }

        return $estaciones;
    }

    /**
     * Anota que el cliente interactuó con una estación a cierta hora, quedándose con la más nueva de esa
     * estación (y su detalle).
     *
     * @param array<int, array<string, array{ts: int, detalle: string|null}>> $candidatas
     * @param int                                                          $client_id
     * @param string                                                       $estacion
     * @param int                                                          $ts
     * @param string|null                                                  $detalle
     *
     * @return void
     */
    private function proponer_estacion(array &$candidatas, $client_id, $estacion, $ts, $detalle)
    {
        $id = (int) $client_id;

        if (! isset($candidatas[$id][$estacion]) || $ts > $candidatas[$id][$estacion]['ts']) {
            $candidatas[$id][$estacion] = ['ts' => (int) $ts, 'detalle' => $detalle];
        }
    }

    /**
     * `agentes`: los seis robots del mostrador, siempre los seis y en el orden de {@see self::ESTACIONES}.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<int, array<string, mixed>>
     */
    private function agentes(array $fuentes)
    {
        return [
            $this->agente_soporte($fuentes),
            $this->agente_por_actividad('facturas', LocalActividadCliente::TIPO_ESCANEO, 'escaneando una factura', $fuentes),
            $this->agente_por_actividad('negocio', LocalActividadCliente::TIPO_AGENTE_NEGOCIO, 'atendiendo el WhatsApp de un negocio', $fuentes),
            $this->agente_asistente($fuentes),
            $this->agente_informes($fuentes),
            $this->agente_comercial($fuentes),
        ];
    }

    /**
     * Soporte (Martín, el agente de tickets por WhatsApp).
     *
     * - `apagado`: el monitor local de soporte no da pulso hace más de 5 minutos (o nunca dio).
     * - `trabajando`: hay alguna reserva vigente de un ticket.
     * - `libre`: el resto.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<string, mixed>
     */
    private function agente_soporte(array $fuentes)
    {
        $salud = $this->json_de_setting(SupportCodexHealthService::KEY);
        $visto = isset($salud['last_seen_at']) ? $this->ts_de_pulso($salud['last_seen_at']) : null;

        $atendiendo = [];
        foreach ($fuentes['soporte_leases'] as $fila) {
            if ($fila['client_id'] !== null) {
                $atendiendo[] = $fila['client_id'];
            }
        }
        $atendiendo = $this->ids_unicos($atendiendo);
        $reservas   = count($fuentes['soporte_leases']);
        $pendientes = (int) SupportTicket::query()->sinResponder()->count();

        if ($visto === null || $visto < $this->ahora_ts - self::VENTANA_PULSO_MIN * 60) {
            $estado  = 'apagado';
            $detalle = 'el monitor de soporte no da señales';
        } elseif ($reservas > 0) {
            $estado  = 'trabajando';
            $detalle = 'Martín atendiendo ' . $reservas . ($reservas === 1 ? ' ticket' : ' tickets');
        } else {
            $estado  = 'libre';
            $detalle = $pendientes > 0
                ? $pendientes . ($pendientes === 1 ? ' ticket esperando respuesta' : ' tickets esperando respuesta')
                : null;
        }

        $ultimo = $fuentes['soporte_ultima'];
        foreach ($fuentes['soporte_mensajes'] as $fila) {
            $ultimo = $this->max_ts($ultimo, $fila['ts']);
        }
        foreach ($fuentes['soporte_escalados'] as $fila) {
            $ultimo = $this->max_ts($ultimo, $fila['ts']);
        }

        return $this->agente('soporte', $estado, $atendiendo, $pendientes, $ultimo, $detalle);
    }

    /**
     * Facturas y WhatsApp del negocio: hoy no dejan rastro en el admin, así que su única señal es la
     * actividad que mandará el sistema del cliente en la Fase 2 (`escaneo` / `agente_negocio`). Sin eso en
     * los últimos 10 minutos, el robot está pero con la antena apagada (`sin_senal`).
     *
     * @param string               $id       Estación.
     * @param string               $tipo     Tipo de `local_actividad_clientes` que lo pone a trabajar.
     * @param string               $haciendo Texto del detalle cuando trabaja.
     * @param array<string, mixed> $fuentes
     *
     * @return array<string, mixed>
     */
    private function agente_por_actividad($id, $tipo, $haciendo, array $fuentes)
    {
        $desde      = $this->ahora_ts - self::VENTANA_ESCANEO_MIN * 60;
        $atendiendo = [];
        $ultimo     = null;

        foreach ($fuentes['actividad'] as $fila) {
            if ($fila['tipo'] !== $tipo || $fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            $atendiendo[] = $fila['client_id'];
            $ultimo       = $this->max_ts($ultimo, $fila['ts']);
        }

        if (count($atendiendo) === 0) {
            return $this->agente($id, 'sin_senal', [], 0, null, 'todavía sin señal del sistema de los clientes');
        }

        return $this->agente($id, 'trabajando', $this->ids_unicos($atendiendo), 0, $ultimo, $haciendo);
    }

    /**
     * El asistente (agente personal del dueño).
     *
     * - `trabajando`: hay mensajes en `recibido` o `enviado` (pensando una respuesta), o la PC de Lucas está
     *   generando una imagen que pidió un dueño (`ia_imagen_pedidos` en `reservado`).
     * - `pendientes`: mensajes en `esperando` (esperan una foto del dueño). `agrupado` no cuenta: es final.
     * - `atendiendo`: los clientes con algún mensaje esperando respuesta.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<string, mixed>
     */
    private function agente_asistente(array $fuentes)
    {
        $trabajando = false;
        $pendientes = 0;
        $atendiendo = [];
        $ultimo     = null;

        foreach ($fuentes['asistente'] as $fila) {
            $ultimo = $this->max_ts($ultimo, $fila['ts']);
            if (in_array($fila['estado'], self::ASISTENTE_TRABAJANDO, true)) {
                $trabajando = true;
            }
            if (in_array($fila['estado'], self::ASISTENTE_EN_ESPERA, true)) {
                $pendientes++;
            }
            if (in_array($fila['estado'], self::ASISTENTE_PENDIENTES, true)) {
                $atendiendo[] = $fila['client_id'];
            }
        }

        $generando = false;
        foreach ($fuentes['imagenes'] as $fila) {
            if ($fila['estado'] === IaImagenPedido::ESTADO_RESERVADO) {
                $generando    = true;
                $atendiendo[] = $fila['client_id'];
            }
        }

        if ($generando) {
            $detalle = 'generando una imagen';
        } elseif ($trabajando) {
            $detalle = 'pensando una respuesta';
        } else {
            $detalle = null;
        }

        return $this->agente(
            'asistente',
            $trabajando || $generando ? 'trabajando' : 'libre',
            $this->ids_unicos($atendiendo),
            $pendientes,
            $ultimo,
            $detalle
        );
    }

    /**
     * Informes de la mañana: trabaja entre las 08:30 y las 08:45 (hora Argentina) si en esa ventana el
     * asistente ya mandó algún mensaje (`direccion = out`). Fuera de esa ventana, libre.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<string, mixed>
     */
    private function agente_informes(array $fuentes)
    {
        $local = $this->ahora->copy()->setTimezone(self::ZONA);
        $hora  = $local->format('H:i');

        if ($hora < self::INFORMES_DESDE || $hora >= self::INFORMES_HASTA) {
            return $this->agente('informes', 'libre', [], 0, null, null);
        }

        $desde      = $local->copy()->setTimeFromTimeString(self::INFORMES_DESDE . ':00')->getTimestamp();
        $atendiendo = [];
        $ultimo     = null;

        foreach ($fuentes['asistente'] as $fila) {
            if ($fila['direccion'] !== ClientAssistantMessage::DIRECCION_SALIENTE || $fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            $atendiendo[] = $fila['client_id'];
            $ultimo       = $this->max_ts($ultimo, $fila['ts']);
        }

        if (count($atendiendo) === 0) {
            return $this->agente('informes', 'libre', [], 0, null, null);
        }

        return $this->agente('informes', 'trabajando', $this->ids_unicos($atendiendo), 0, $ultimo, 'mandando los informes del día');
    }

    /**
     * Comercial (el agente que le contesta a los leads).
     *
     * - `trabajando`: le mandó un mensaje a un lead en los últimos 5 minutos (`sender = sistema`,
     *   `status = enviado`).
     * - `pendientes`: mensajes sugeridos esperando aprobación (`status = sugerido`).
     * - `atendiendo`: siempre vacío, los leads no son negocios del local.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<string, mixed>
     */
    private function agente_comercial(array $fuentes)
    {
        $desde      = $this->ahora_ts - self::VENTANA_COMERCIAL_MIN * 60;
        $trabajando = false;
        $ultimo     = null;

        foreach ($fuentes['leads'] as $fila) {
            $ultimo = $this->max_ts($ultimo, $fila['ts']);
            if ($fila['sender'] === 'sistema' && $fila['status'] === 'enviado') {
                $ultimo = $this->max_ts($ultimo, $fila['enviado']);
                if ($fila['enviado'] !== null && $fila['enviado'] >= $desde) {
                    $trabajando = true;
                }
            }
        }

        $pendientes = (int) DB::table('lead_messages')->where('status', 'sugerido')->count();

        if ($trabajando) {
            $detalle = 'respondiendo a leads';
        } elseif ($pendientes > 0) {
            $detalle = $pendientes . ($pendientes === 1 ? ' mensaje sugerido para revisar' : ' mensajes sugeridos para revisar');
        } else {
            $detalle = null;
        }

        return $this->agente('comercial', $trabajando ? 'trabajando' : 'libre', [], $pendientes, $ultimo, $detalle);
    }

    /**
     * `bandeja` «Para Lucas»: lo que Soporte y los demás le dejaron a Lucas en el mostrador, los más nuevos
     * primero y con tope de {@see self::TOPE_BANDEJA}.
     *
     * Fuentes (cada una con su propio tope, así ninguna puede tapar a las demás en la consulta):
     *   - `support_codex_cases.state = mission_pending` → `mision_pendiente`. 🔴 Sin las que Lucas ya inició
     *     (`mission_started_at` cargado): «cuando Lucas la inicia, la misión pasa a las oficinas» (pedido de
     *     Lucas, §0 del plan). El caso sigue en `mission_pending` después de iniciarla, así que sin este filtro
     *     la carpeta quedaría en la bandeja para siempre.
     *   - `state = manual_review` sobre un ticket abierto → `revision`.
     *   - ticket abierto con `escalated_at` y sin caso en esos dos estados → `caso_delegado`.
     *   - `support_codex_operations.status = awaiting_approval` → `aprobacion`.
     *   - `lead_messages.status = sugerido` de un lead con `requiere_verificacion_mensajes` → `aprobacion`.
     *   - `admin_tasks` sin hacer creadas por Claude → `tarea`.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function bandeja()
    {
        $items = [];

        $casos = DB::table('support_codex_cases as c')
            ->join('support_tickets as t', 't.id', '=', 'c.support_ticket_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 't.client_id')
            ->where(function ($q) {
                $q->where(function ($q1) {
                    $q1->where('c.state', 'mission_pending')->whereNull('c.mission_started_at');
                })->orWhere(function ($q2) {
                    $q2->where('c.state', 'manual_review')->where('t.status', SupportTicket::STATUS_ABIERTO);
                });
            })
            ->select(
                'c.state',
                'c.review_reason',
                'c.updated_at',
                't.id as ticket_id',
                't.client_id',
                'cl.name as client_name',
                'cl.company_name as client_company'
            )
            // Solo el principio de la misión: el texto entero puede ser largo y acá se usan 70 letras.
            ->selectRaw('LEFT(c.mission, 400) AS mission_inicio')
            ->orderByDesc('c.updated_at')
            ->limit(self::TOPE_BANDEJA)
            ->get();

        foreach ($casos as $caso) {
            $cliente = $this->nombre_de($caso->client_company, $caso->client_name, $caso->client_id);

            if ($caso->state === 'mission_pending') {
                $items[] = $this->item_de_bandeja(
                    'soporte-mision-' . (int) $caso->ticket_id,
                    'mision_pendiente',
                    'Misión · ' . $cliente . ': ' . $this->recortar($caso->mission_inicio, self::LETRAS_DE_MISION),
                    $caso->client_id,
                    $caso->updated_at,
                    $this->ruta_de_ticket($caso->ticket_id)
                );
                continue;
            }

            $motivo = $this->recortar($caso->review_reason, self::LETRAS_DE_TITULO);
            $items[] = $this->item_de_bandeja(
                'soporte-revision-' . (int) $caso->ticket_id,
                'revision',
                'Revisión · ' . $cliente . ': ' . ($motivo !== '' ? $motivo : 'ticket #' . (int) $caso->ticket_id),
                $caso->client_id,
                $caso->updated_at,
                $this->ruta_de_ticket($caso->ticket_id)
            );
        }

        $delegados = DB::table('support_tickets as t')
            ->leftJoin('clients as cl', 'cl.id', '=', 't.client_id')
            ->whereNotNull('t.escalated_at')
            ->where('t.status', SupportTicket::STATUS_ABIERTO)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('support_codex_cases as c')
                    ->whereColumn('c.support_ticket_id', 't.id')
                    ->whereIn('c.state', ['mission_pending', 'manual_review']);
            })
            ->select('t.id', 't.client_id', 't.name', 't.escalation_reason', 't.escalated_at', 'cl.name as client_name', 'cl.company_name as client_company')
            ->orderByDesc('t.escalated_at')
            ->limit(self::TOPE_BANDEJA)
            ->get();

        foreach ($delegados as $ticket) {
            $cliente = $this->nombre_de($ticket->client_company, $ticket->client_name, $ticket->client_id);
            $motivo  = $this->recortar($ticket->escalation_reason !== null && trim((string) $ticket->escalation_reason) !== ''
                ? $ticket->escalation_reason
                : $ticket->name, self::LETRAS_DE_TITULO);

            $items[] = $this->item_de_bandeja(
                'soporte-caso-' . (int) $ticket->id,
                'caso_delegado',
                'Ticket #' . (int) $ticket->id . ' · ' . $cliente . ($motivo !== '' ? ': ' . $motivo : ''),
                $ticket->client_id,
                $ticket->escalated_at,
                $this->ruta_de_ticket($ticket->id)
            );
        }

        $operaciones = DB::table('support_codex_operations as o')
            ->join('support_tickets as t', 't.id', '=', 'o.support_ticket_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 't.client_id')
            ->where('o.status', 'awaiting_approval')
            ->select('o.id', 'o.created_at', 't.id as ticket_id', 't.client_id', 'cl.name as client_name', 'cl.company_name as client_company')
            ->orderByDesc('o.created_at')
            ->limit(self::TOPE_BANDEJA)
            ->get();

        foreach ($operaciones as $operacion) {
            $cliente = $this->nombre_de($operacion->client_company, $operacion->client_name, $operacion->client_id);

            $items[] = $this->item_de_bandeja(
                'soporte-aprobacion-' . (int) $operacion->id,
                'aprobacion',
                'Aprobar respuesta · ' . $cliente . ': ticket #' . (int) $operacion->ticket_id,
                $operacion->client_id,
                $operacion->created_at,
                $this->ruta_de_ticket($operacion->ticket_id)
            );
        }

        $sugeridos = DB::table('lead_messages as lm')
            ->join('leads as l', 'l.id', '=', 'lm.lead_id')
            ->where('lm.status', 'sugerido')
            ->where('l.requiere_verificacion_mensajes', true)
            ->select('lm.id', 'lm.lead_id', 'lm.created_at', 'l.contact_name', 'l.company_name')
            ->orderByDesc('lm.created_at')
            ->limit(self::TOPE_BANDEJA)
            ->get();

        foreach ($sugeridos as $mensaje) {
            $nombre = $this->texto_o_null($mensaje->contact_name);
            if ($nombre === null) {
                $nombre = $this->texto_o_null($mensaje->company_name);
            }

            $items[] = $this->item_de_bandeja(
                'lead-mensaje-' . (int) $mensaje->id,
                'aprobacion',
                'Mensaje a lead ' . ($nombre !== null ? $this->recortar($nombre, self::LETRAS_DE_TITULO) : '#' . (int) $mensaje->lead_id),
                null,
                $mensaje->created_at,
                '/leads/' . (int) $mensaje->lead_id . '/conversacion'
            );
        }

        $tareas = DB::table('admin_tasks')
            ->where('is_done', false)
            ->where('created_via', 'claude')
            ->select('id', 'title', 'created_at')
            ->orderByDesc('created_at')
            ->limit(self::TOPE_BANDEJA)
            ->get();

        foreach ($tareas as $tarea) {
            $titulo = $this->recortar($tarea->title, self::LETRAS_DE_TITULO);

            $items[] = $this->item_de_bandeja(
                'tarea-' . (int) $tarea->id,
                'tarea',
                $titulo !== '' ? $titulo : 'Tarea #' . (int) $tarea->id,
                null,
                $tarea->created_at,
                '/tareas'
            );
        }

        usort($items, function ($a, $b) {
            $por_fecha = strcmp((string) $b['_orden'], (string) $a['_orden']);

            return $por_fecha !== 0 ? $por_fecha : strcmp($a['id'], $b['id']);
        });

        $total = count($items);
        $items = array_slice($items, 0, self::TOPE_BANDEJA);

        foreach ($items as $i => $item) {
            unset($items[$i]['_orden']);
        }

        return ['items' => array_values($items), 'total' => $total];
    }

    /**
     * `oficina`: el pulso del reporter local, el planificador y las mesas (una por slot).
     *
     * - `reporter.ok`: hubo pulso en los últimos 5 minutos. Sin pulso no se manda nada: es un panel.
     * - `planificador.estado`: `trabajando` si algún slot está en fase `tomado` o `plan`.
     * - `slots`: las filas de `local_oficina_slots` ordenadas por slot, con los campos del contrato A más
     *   `reportado_at`.
     *
     * @return array<string, mixed>
     */
    private function oficina()
    {
        $pulso = $this->json_de_setting(OficinaIngestService::PULSO_KEY);
        $visto = isset($pulso['last_seen_at']) ? $this->ts_de_pulso($pulso['last_seen_at']) : null;

        $slots        = [];
        $planificando = false;

        foreach (LocalOficinaSlot::query()->orderBy('slot')->get() as $fila) {
            if (in_array($fila->fase, ['tomado', 'plan'], true)) {
                $planificando = true;
            }

            $slots[] = [
                'slot'                => (int) $fila->slot,
                'proyecto'            => $fila->proyecto,
                'estado'              => (string) $fila->estado,
                'rama'                => $fila->rama,
                'mision'              => $fila->mision,
                'fase'                => $fila->fase,
                'claim_iniciado_at'   => $this->iso($fila->claim_iniciado_at),
                'ultima_actividad_at' => $this->iso($fila->ultima_actividad_at),
                'sucios'              => (int) $fila->sucios,
                'agentes'             => is_array($fila->agentes) ? array_values($fila->agentes) : [],
                'reportado_at'        => $this->iso($fila->reportado_at),
            ];
        }

        return [
            'reporter'     => [
                'ok'           => $visto !== null && $visto >= $this->ahora_ts - self::VENTANA_PULSO_MIN * 60,
                'last_seen_at' => $this->iso($visto),
                'version'      => isset($pulso['version']) && $pulso['version'] !== '' ? (string) $pulso['version'] : null,
            ],
            'planificador' => ['estado' => $planificando ? 'trabajando' : 'libre'],
            'slots'        => $slots,
        ];
    }

    /**
     * `eventos`: lo que pasó en los últimos 10 minutos, del más viejo al más nuevo, con un `id` estable para
     * que la SPA no repita un globo que ya mostró.
     *
     * @param array<string, mixed> $fuentes
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventos(array $fuentes)
    {
        $desde   = $this->ahora_ts - self::VENTANA_EVENTOS_MIN * 60;
        $eventos = [];

        foreach ($fuentes['soporte_mensajes'] as $fila) {
            if ($fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            $eventos[] = $this->evento(
                'sm-' . $fila['id'],
                $fila['sender_type'] === 'user' ? 'ticket_mensaje' : 'ticket_respuesta',
                'soporte',
                $fila['client_id'],
                $fila['ts'],
                'ticket #' . $fila['ticket_id']
            );
        }

        foreach ($fuentes['soporte_escalados'] as $fila) {
            if ($fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            $eventos[] = $this->evento('esc-' . $fila['ticket_id'], 'delegado', 'soporte', $fila['client_id'], $fila['ts'], 'ticket #' . $fila['ticket_id']);
        }

        foreach ($fuentes['asistente'] as $fila) {
            if ($fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            if ($fila['direccion'] === ClientAssistantMessage::DIRECCION_ENTRANTE) {
                $eventos[] = $this->evento('cam-' . $fila['id'], 'asistente_mensaje', 'asistente', $fila['client_id'], $fila['ts'], null);
            } elseif ($fila['estado'] === ClientAssistantMessage::ESTADO_RESPONDIDO) {
                $eventos[] = $this->evento('cam-' . $fila['id'], 'asistente_respuesta', 'asistente', $fila['client_id'], $fila['ts'], null);
            }
        }

        foreach ($fuentes['imagenes'] as $fila) {
            if ($fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            $eventos[] = $this->evento('img-' . $fila['id'], 'imagen_pedido', 'asistente', $fila['client_id'], $fila['ts'], null);
        }

        foreach ($fuentes['leads'] as $fila) {
            if ($fila['sender'] === 'lead' && $fila['ts'] !== null && $fila['ts'] >= $desde) {
                $eventos[] = $this->evento('lm-' . $fila['id'], 'lead_mensaje', 'comercial', null, $fila['ts'], 'lead #' . $fila['lead_id']);
            } elseif ($fila['sender'] === 'sistema' && $fila['status'] === 'enviado' && $fila['enviado'] !== null && $fila['enviado'] >= $desde) {
                $eventos[] = $this->evento('lm-' . $fila['id'], 'lead_respuesta', 'comercial', null, $fila['enviado'], 'lead #' . $fila['lead_id']);
            }
        }

        $agente_por_tipo = [
            LocalActividadCliente::TIPO_ESCANEO        => 'facturas',
            LocalActividadCliente::TIPO_AGENTE_NEGOCIO => 'negocio',
            'asistente'                                => 'asistente',
        ];
        foreach ($fuentes['actividad'] as $fila) {
            if ($fila['ts'] === null || $fila['ts'] < $desde) {
                continue;
            }
            $eventos[] = $this->evento(
                'act-' . $fila['id'],
                'actividad',
                isset($agente_por_tipo[$fila['tipo']]) ? $agente_por_tipo[$fila['tipo']] : null,
                $fila['client_id'],
                $fila['ts'],
                $fila['tipo']
            );
        }

        usort($eventos, function ($a, $b) {
            if ($a['_ts'] !== $b['_ts']) {
                return $a['_ts'] < $b['_ts'] ? -1 : 1;
            }

            return strcmp($a['id'], $b['id']);
        });

        // Si hubiera más que el tope, quedan los más nuevos (y siguen en orden ascendente).
        if (count($eventos) > self::TOPE_EVENTOS) {
            $eventos = array_slice($eventos, -self::TOPE_EVENTOS);
        }

        foreach ($eventos as $i => $evento) {
            unset($eventos[$i]['_ts']);
        }

        return array_values($eventos);
    }

    /* ------------------------------------------------------------------------------------------
     | Armado de cada pieza
     |----------------------------------------------------------------------------------------- */

    /**
     * Un agente con la forma del contrato B.
     *
     * @param string          $id
     * @param string          $estado     trabajando | libre | apagado | sin_senal
     * @param array<int, int> $atendiendo Ids de clientes.
     * @param int             $pendientes
     * @param int|null        $ultimo     Último evento, en segundos.
     * @param string|null     $detalle
     *
     * @return array<string, mixed>
     */
    private function agente($id, $estado, array $atendiendo, $pendientes, $ultimo, $detalle)
    {
        return [
            'id'               => $id,
            'estado'           => $estado,
            'atendiendo'       => array_values($atendiendo),
            'pendientes'       => (int) $pendientes,
            'ultimo_evento_at' => $this->iso($ultimo),
            'detalle'          => $detalle,
        ];
    }

    /**
     * Un ítem de la bandeja con la forma del contrato B (más `_orden`, que se quita antes de devolver).
     *
     * @param string      $id
     * @param string      $tipo      caso_delegado | revision | mision_pendiente | aprobacion | tarea
     * @param string      $titulo
     * @param mixed       $client_id
     * @param mixed       $creado    Fecha cruda de la base (hora del admin).
     * @param string|null $ruta
     *
     * @return array<string, mixed>
     */
    private function item_de_bandeja($id, $tipo, $titulo, $client_id, $creado, $ruta)
    {
        $ts = $this->ts($creado);

        return [
            'id'        => $id,
            'tipo'      => $tipo,
            'titulo'    => $titulo,
            'client_id' => $client_id === null ? null : (int) $client_id,
            'creado_at' => $this->iso($ts),
            'ruta'      => $ruta,
            '_orden'    => $ts === null ? '' : sprintf('%012d', $ts),
        ];
    }

    /**
     * Un evento con la forma del contrato B (más `_ts`, que se quita antes de devolver).
     *
     * @param string      $id
     * @param string      $tipo
     * @param string|null $agente
     * @param int|null    $client_id
     * @param int         $ts
     * @param string|null $detalle
     *
     * @return array<string, mixed>
     */
    private function evento($id, $tipo, $agente, $client_id, $ts, $detalle)
    {
        return [
            'id'          => $id,
            'tipo'        => $tipo,
            'agente'      => $agente,
            'client_id'   => $client_id === null ? null : (int) $client_id,
            'ocurrido_at' => $this->iso($ts),
            'detalle'     => $detalle,
            '_ts'         => (int) $ts,
        ];
    }

    /**
     * Ruta de la SPA que abre un ticket.
     *
     * 🔴 Va con el id numérico y no con el uuid: la ruta `/soporte/ticket/:ticket_id` de la SPA busca el
     * ticket con `GET support-ticket/{id}`, que filtra por `id` (SupportTicketController::show). Con el uuid
     * el link abriría la bandeja sin el ticket.
     *
     * @param mixed $ticket_id
     *
     * @return string
     */
    private function ruta_de_ticket($ticket_id)
    {
        return '/soporte/ticket/' . (int) $ticket_id;
    }

    /* ------------------------------------------------------------------------------------------
     | Utilidades
     |----------------------------------------------------------------------------------------- */

    /**
     * Suma a `$ultima` (client_id => segundos) las filas agregadas `{client_id, ultima}` de una fuente,
     * quedándose con la más nueva.
     *
     * @param array<int, int>                $ultima
     * @param \Illuminate\Support\Collection $filas
     * @param string|null                    $zona   Zona en la que la fuente guarda la hora (null = la del admin).
     *
     * @return void
     */
    private function sumar_ultima(array &$ultima, $filas, $zona = null)
    {
        foreach ($filas as $fila) {
            $id = (int) $fila->client_id;
            $ts = $this->ts($fila->ultima, $zona);
            if ($ts === null) {
                continue;
            }
            $ultima[$id] = isset($ultima[$id]) ? max($ultima[$id], $ts) : $ts;
        }
    }

    /**
     * Lee un `admin_settings` que guarda JSON. Null o basura → [].
     *
     * @param string $clave
     *
     * @return array<string, mixed>
     */
    private function json_de_setting($clave)
    {
        $crudo = AdminSetting::get($clave);
        if ($crudo === null || $crudo === '') {
            return [];
        }

        $datos = json_decode((string) $crudo, true);

        return is_array($datos) ? $datos : [];
    }

    /**
     * La hora de un pulso: el del monitor de soporte la guarda en segundos (epoch) y el del reporter en ISO.
     *
     * @param mixed $valor
     *
     * @return int|null
     */
    private function ts_de_pulso($valor)
    {
        if (is_int($valor) || (is_string($valor) && ctype_digit($valor))) {
            return (int) $valor;
        }

        return $this->ts($valor);
    }

    /**
     * Segundos de una fecha de la base, o null.
     *
     * @param mixed       $valor Carbon o string 'Y-m-d H:i:s' (o ISO).
     * @param string|null $zona  Zona en que se guardó (null = la del admin).
     *
     * @return int|null
     */
    private function ts($valor, $zona = null)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->getTimestamp();
        }

        try {
            return Carbon::parse((string) $valor, $zona === null ? $this->tz_db() : $zona)->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ISO-8601 con zona Argentina, o null.
     *
     * @param mixed $valor Segundos, Carbon o null.
     *
     * @return string|null
     */
    private function iso($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor)->setTimezone(self::ZONA)->toIso8601String();
        }

        return Carbon::createFromTimestamp((int) $valor, self::ZONA)->toIso8601String();
    }

    /**
     * El mayor de dos segundos posiblemente nulos.
     *
     * @param int|null $a
     * @param int|null $b
     *
     * @return int|null
     */
    private function max_ts($a, $b)
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return max($a, $b);
    }

    /**
     * Ids enteros, sin repetidos y ordenados.
     *
     * @param array<int, mixed> $ids
     *
     * @return array<int, int>
     */
    private function ids_unicos(array $ids)
    {
        $unicos = array_values(array_unique(array_map('intval', $ids)));
        sort($unicos);

        return $unicos;
    }

    /**
     * Momento actual menos N minutos, en el formato y la zona de las columnas datetime de la base.
     *
     * @param int $minutos
     *
     * @return string
     */
    private function db_hace($minutos)
    {
        return $this->ahora->copy()->subMinutes($minutos)->setTimezone($this->tz_db())->format('Y-m-d H:i:s');
    }

    /**
     * Momento actual en el formato y la zona de las columnas datetime de la base.
     *
     * @return string
     */
    private function db_ahora()
    {
        return $this->ahora->copy()->setTimezone($this->tz_db())->format('Y-m-d H:i:s');
    }

    /**
     * Zona en que el admin guarda sus fechas (la de la app).
     *
     * @return string
     */
    private function tz_db()
    {
        $zona = trim((string) config('app.timezone'));

        return $zona === '' ? self::ZONA : $zona;
    }

    /**
     * Nombre visible de un negocio: el comercial (`company_name`) y si no el de la ficha (`name`), igual que
     * el resto del admin.
     *
     * @param mixed $company_name
     * @param mixed $name
     * @param mixed $id
     *
     * @return string
     */
    private function nombre_de($company_name, $name, $id)
    {
        $nombre = $this->texto_o_null($company_name);
        if ($nombre === null) {
            $nombre = $this->texto_o_null($name);
        }

        return $nombre !== null ? $nombre : 'Cliente #' . (int) $id;
    }

    /**
     * Texto en una sola línea, recortado a N letras (con «…» si se cortó). Vacío si no había nada.
     *
     * @param mixed $texto
     * @param int   $letras
     *
     * @return string
     */
    private function recortar($texto, $letras)
    {
        if ($texto === null || is_array($texto)) {
            return '';
        }

        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));
        if (mb_strlen($texto) <= $letras) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $letras)) . '…';
    }

    /**
     * Texto recortado o null si quedó vacío.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
