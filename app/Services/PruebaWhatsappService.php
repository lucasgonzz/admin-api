<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Helpers\WhatsappNormalizer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Database\Seeders\PipelineLeadSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los WhatsApps de la prueba gratuita que manda el admin (misión cruzada prueba-martin-importacion,
 * 3/11/2026, programa «Prueba gratuita de 7 días»):
 *
 *  1. **La bienvenida de Martín** (`cc_prueba_bienvenida`) cuando la instancia queda lista, con
 *     el primer ingreso y el barrido diario como respaldo. Una sola vez por cliente.
 *  2. **Los empujones por hito faltante** (`cc_prueba_paso_<hito>`): un job diario manda, como mucho,
 *     UNO por día y por negocio; se frena si el negocio escribió en las últimas 24 h (la ventana del
 *     número principal está abierta: ya está hablando con Martín), nunca después de vencida, y solo con
 *     la plantilla aprobada por Meta.
 *  3. **Los avisos a Tomás** (el closer, `admins.is_closer`): al día 5 de la prueba, al recibir
 *     `prueba.vencida` y a pedido desde la pestaña. WhatsApp + push.
 *
 * 🔴 TODO SALE POR EL TICKET DE SOPORTE DEL CLIENTE, por el número principal: se reusa (o se abre) el
 * ticket de WhatsApp del dueño con el mismo criterio que el botón «Nueva conversación» de la bandeja
 * (`SupportWhatsappOpenerService::resolve_or_create_ticket()`), se manda la plantilla y el mensaje queda
 * en el hilo como del equipo (`sender_type: admin`). Así Martín lo ve en su contexto, no se vuelve a
 * presentar, y lo que el negocio conteste cae en ese mismo ticket. Es el molde de los hitos de la
 * implementación (`ImplementationSupportWhatsappService`).
 *
 * 🔴 Y SIEMPRE POR PLANTILLA, nunca texto libre: la primera vez la ventana de 24 h está cerrada (el
 * negocio todavía no nos escribió por este número) y un texto libre lo rechaza Meta. Una plantilla que
 * todavía no está aprobada deja el envío `en_cola` en `prueba_whatsapp_envios`, y el job diario lo
 * destraba cuando `templates:sync-meta` la active.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados ni union types.
 */
class PruebaWhatsappService
{
    /** Prefijo de los eventos de C2 que atiende `al_recibir_evento()`. */
    const PREFIJO_EVENTO = PruebaDelClienteService::PREFIJO_EVENTO;

    /** Motivos del aviso a Tomás. */
    const TOMAS_DIA_5 = 'dia_5';
    const TOMAS_VENCIDA = 'vencida';
    const TOMAS_MANUAL = 'manual';

    /** Zona horaria del tope diario. */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /** @var PruebaDelClienteService */
    protected $pruebas;

    /** @var PruebaPlantillasService */
    protected $plantillas;

    /** @var SupportWhatsappOpenerService */
    protected $opener;

    /** @var WhatsappSendService */
    protected $sender;

    /** @var WhatsappSessionWindowService */
    protected $ventanas;

    /** @var ClientPhoneDirectory */
    protected $directorio;

    /**
     * @param PruebaDelClienteService      $pruebas
     * @param PruebaPlantillasService      $plantillas
     * @param SupportWhatsappOpenerService $opener
     * @param WhatsappSendService          $sender
     * @param WhatsappSessionWindowService $ventanas
     * @param ClientPhoneDirectory         $directorio
     */
    public function __construct(
        PruebaDelClienteService $pruebas,
        PruebaPlantillasService $plantillas,
        SupportWhatsappOpenerService $opener,
        WhatsappSendService $sender,
        WhatsappSessionWindowService $ventanas,
        ClientPhoneDirectory $directorio
    ) {
        $this->pruebas    = $pruebas;
        $this->plantillas = $plantillas;
        $this->opener     = $opener;
        $this->sender     = $sender;
        $this->ventanas   = $ventanas;
        $this->directorio = $directorio;
    }

    /* ------------------------------------------------------------------------------------------
     | C2: lo que dispara un evento de la instancia
     |----------------------------------------------------------------------------------------- */

    /**
     * Un evento `prueba.*` de la instancia (lo llama `DemoEventosController` después de persistirlo).
     * `prueba.ingreso` → la bienvenida (la primera vez); `prueba.vencida` → el aviso a Tomás.
     *
     * @param Lead   $lead   El lead dueño del canal de eventos.
     * @param string $nombre El nombre del evento (`prueba.ingreso`…).
     *
     * @return array<string, mixed> Qué se hizo (para los tests y el log).
     */
    public function al_recibir_evento(Lead $lead, string $nombre): array
    {
        if (strpos($nombre, self::PREFIJO_EVENTO) !== 0) {
            return ['accion' => 'ignorado', 'motivo' => 'no_es_de_la_prueba'];
        }

        $tipo = substr($nombre, strlen(self::PREFIJO_EVENTO));

        if ($tipo === 'ingreso') {
            return array_merge(['accion' => 'bienvenida'], $this->enviar_bienvenida($lead));
        }

        if ($tipo === PruebaDelClienteService::HITO_VENCIDA) {
            $client = $this->pruebas->cliente_del_lead($lead);
            if ($client === null) {
                return ['accion' => 'aviso_tomas', 'estado' => 'sin_cliente'];
            }

            return array_merge(['accion' => 'aviso_tomas'], $this->avisar_a_tomas($client, $lead, self::TOMAS_VENCIDA));
        }

        return ['accion' => 'ignorado', 'motivo' => 'hito_sin_accion'];
    }

    /* ------------------------------------------------------------------------------------------
     | La bienvenida
     |----------------------------------------------------------------------------------------- */

    /**
     * Manda la bienvenida de Martín al negocio del lead, una sola vez. Si la plantilla todavía no está
     * aprobada, queda en cola.
     *
     * @param Lead        $lead
     * @param Client|null $client Si ya se tiene; si no, se cruza por el lead.
     *
     * @return array{estado: string, detalle: string|null}
     */
    public function enviar_bienvenida(Lead $lead, ?Client $client = null): array
    {
        $client = $client !== null ? $client : $this->pruebas->cliente_del_lead($lead);

        if ($client === null) {
            return ['estado' => 'sin_cliente', 'detalle' => 'El lead no tiene un cliente en prueba al que escribirle.'];
        }

        return $this->enviar_bienvenida_a($client, $lead);
    }

    /**
     * La bienvenida a un cliente en prueba, una sola vez.
     *
     * @param Client    $client
     * @param Lead|null $lead
     *
     * @return array{estado: string, detalle: string|null}
     */
    public function enviar_bienvenida_a(Client $client, ?Lead $lead): array
    {
        if ($this->pruebas->datos($client)['vencida']) {
            return ['estado' => 'vencida', 'detalle' => 'La prueba venció.'];
        }

        $reserva = Cache::lock('prueba-bienvenida-' . $client->id, 120);
        if (! $reserva->get()) {
            return ['estado' => 'en_curso', 'detalle' => null];
        }

        try {
            $enviadas = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->whereIn('plantilla', array_merge(PruebaPlantillasService::BIENVENIDAS, [PruebaPlantillasService::ASISTENTE_LISTO]))
                ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
                ->pluck('plantilla');

            $ya = $enviadas->isNotEmpty();
            $plantilla = $ya ? PruebaPlantillasService::ASISTENTE_LISTO : PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE;
            $instancia = $lead !== null ? Prueba\PruebaAsistenteService::instancia_vigente($client) : null;
            if ($instancia !== null) {
                $falta_correo = $instancia->correo_bienvenida_at === null && filter_var((string) $client->email, FILTER_VALIDATE_EMAIL);
                $falta_whatsapp = ! $enviadas->contains(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE)
                    && ! $enviadas->contains(PruebaPlantillasService::ASISTENTE_LISTO)
                    && $this->plantillas->estado_para_enviar($plantilla)['motivo'] === null;

                if ($falta_correo || $falta_whatsapp) {
                    $asistente = app(Prueba\PruebaAsistenteService::class)->habilitar($client, $lead, $instancia);
                    if ($asistente !== null) {
                        if ($falta_correo) {
                            app(Prueba\PruebaCorreoService::class)->enviar($client, $instancia, $asistente);
                        }
                        if ($falta_whatsapp) {
                            return $this->con_video_de_bienvenida($client, $this->enviar_plantilla_por_ticket($client, $lead, $plantilla, null, true, $asistente['numero']));
                        }
                    }
                }
            }

            if ($ya) {
                return ['estado' => 'ya_enviada', 'detalle' => null];
            }

            return $this->con_video_de_bienvenida($client, $this->enviar_plantilla_por_ticket($client, $lead, PruebaPlantillasService::BIENVENIDA, null, true));
        } finally {
            $reserva->release();
        }
    }

    /**
     * Después de una bienvenida que salió, el video 04 por el mismo ticket si la ventana de 24 h ya está abierta
     * (misión prueba-ayuda-unificada-en-soporte, 10/11/2026). Con la ventana cerrada no sale nada: lo manda el
     * primer entrante del lead (`Prueba\PruebaVideoSoporteService`). Nunca cambia el resultado de la bienvenida
     * ni la tumba: un error del video queda en el log.
     *
     * @param Client               $client
     * @param array<string, mixed> $resultado Lo que devolvió `enviar_plantilla_por_ticket()`.
     *
     * @return array<string, mixed> El mismo `$resultado`.
     */
    protected function con_video_de_bienvenida(Client $client, array $resultado): array
    {
        if (($resultado['estado'] ?? null) !== 'enviado' || empty($resultado['support_ticket_id'])) {
            return $resultado;
        }

        try {
            app(Prueba\PruebaVideoSoporteService::class)->entregar_bienvenida($client, (int) $resultado['support_ticket_id']);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no se pudo mandar el video de bienvenida.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);
        }

        return $resultado;
    }

    /** Retoma el alta por los dos canales sin disparar los recordatorios diarios. */
    public function retomar_bienvenidas(?int $cliente_id = null): void
    {
        foreach ($this->pruebas->clientes_en_prueba() as $client) {
            if ($cliente_id !== null && (int) $client->id !== $cliente_id) {
                continue;
            }

            try {
                $instancia = Prueba\PruebaAsistenteService::instancia_vigente($client);
                if ($instancia === null || $instancia->lead_id === null) {
                    continue;
                }

                $lead = Lead::find($instancia->lead_id);
                if ($lead !== null && $lead->demo_setup_status === 'exitoso') {
                    $this->enviar_bienvenida_a($client, $lead);
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo retomar la bienvenida de la prueba.', [
                    'client_id' => (int) $client->id,
                    'tipo' => get_class($e),
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Los empujones (job diario)
     |----------------------------------------------------------------------------------------- */

    /**
     * Recorre los clientes en prueba: destraba las bienvenidas en cola, avisa a Tomás al día 5 y manda
     * como mucho un empujón por negocio. Cada cliente en su propio try: el que falla no frena al resto.
     *
     * @return array<int, array<string, mixed>>
     */
    public function empujar_a_todos(): array
    {
        $resultados = [];

        foreach ($this->pruebas->clientes_en_prueba() as $client) {
            try {
                $lead  = $this->pruebas->lead_del_cliente($client);
                $datos = $this->pruebas->datos($client);

                $tomas = $this->avisar_a_tomas_si_es_el_dia_5($client, $lead, $datos);
                $paso  = $this->empujar($client, $lead, $datos);

                $resultados[] = [
                    'client_id' => (int) $client->id,
                    'nombre'    => $client->resolve_display_name(),
                    'estado'    => $paso['estado'],
                    'detalle'   => $paso['detalle'],
                    'tomas'     => $tomas,
                ];
            } catch (\Throwable $e) {
                Log::channel('daily')->error('PruebaWhatsapp: excepción empujando a un cliente en prueba.', [
                    'client_id' => $client->id,
                    'error'     => $e->getMessage(),
                ]);

                $resultados[] = ['client_id' => (int) $client->id, 'nombre' => $client->resolve_display_name(), 'estado' => 'error', 'detalle' => $e->getMessage(), 'tomas' => null];
            }
        }

        return $resultados;
    }

    /**
     * El empujón de un cliente: la bienvenida pendiente primero; si ya salió, el próximo hito faltante.
     *
     * @param Client                    $client
     * @param Lead|null                 $lead
     * @param array<string, mixed>|null $datos Los días (`PruebaDelClienteService::datos()`), si ya se tienen.
     *
     * @return array{estado: string, detalle: string|null}
     */
    public function empujar(Client $client, ?Lead $lead, ?array $datos = null): array
    {
        $datos = $datos !== null ? $datos : $this->pruebas->datos($client);

        if ($datos['vencida'] === true) {
            return ['estado' => 'vencida', 'detalle' => 'La prueba venció: no se empuja más.'];
        }

        /* La bienvenida primero: sin ella, un empujón sería el primer mensaje que recibe y no sabría quién le habla. */
        $bienvenida = $this->enviar_bienvenida_a($client, $lead);
        if ($bienvenida['estado'] !== 'ya_enviada') {
            return ['estado' => 'bienvenida_' . $bienvenida['estado'], 'detalle' => $bienvenida['detalle']];
        }

        if ($this->ya_se_le_mando_hoy($client)) {
            return ['estado' => 'ya_hoy', 'detalle' => 'Ya salió un mensaje de la prueba hoy.'];
        }

        $telefono = trim((string) $client->telefono_personal());
        if ($telefono !== '' && $this->ventanas->is_open($telefono)) {
            return ['estado' => 'escribio_hace_poco', 'detalle' => 'El negocio escribió en las últimas 24 h: lo atiende Martín.'];
        }

        $hitos = $this->pruebas->hitos($client, $lead, true);

        /* La instancia sabe mejor si venció (C3): aunque el admin no tenga `trial_ends_at`, con `vencida` en
         * true no se empuja más. */
        if ($hitos['vencida'] === true) {
            return ['estado' => 'vencida', 'detalle' => 'La instancia dice que la prueba venció: no se empuja más.'];
        }

        $proximo = PruebaDelClienteService::proximo_hito_faltante($hitos['hitos']);

        if ($proximo === null) {
            return ['estado' => 'sin_hito_faltante', 'detalle' => 'Pasó por todos los hitos.'];
        }

        $plantilla = PruebaPlantillasService::plantilla_del_paso($proximo);
        $estado    = $this->plantillas->estado_para_enviar($plantilla);

        if ($estado['motivo'] !== null) {
            return ['estado' => 'plantilla_no_disponible', 'detalle' => $plantilla . ': ' . $estado['motivo']];
        }

        $envio = $this->enviar_plantilla_por_ticket($client, $lead, $plantilla, $proximo, false);

        return ['estado' => $envio['estado'] === 'enviado' ? 'enviado' : $envio['estado'], 'detalle' => $envio['detalle'] !== null ? $envio['detalle'] : $plantilla];
    }

    /**
     * Si hoy (hora de Argentina) ya salió un mensaje de la prueba al cliente (bienvenida o empujón).
     *
     * @param Client $client
     *
     * @return bool
     */
    public function ya_se_le_mando_hoy(Client $client): bool
    {
        return PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
            ->where('plantilla', '!=', PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS)
            /* Solo WhatsApps: un correo o un mensaje dejado en el sistema no gastan el tope del día. */
            ->where('canal', PruebaWhatsappEnvio::CANAL_WHATSAPP)
            ->where('dia', self::hoy())
            ->exists();
    }

    /**
     * La fecha de hoy en Argentina, `Y-m-d`.
     *
     * @return string
     */
    public static function hoy(): string
    {
        return Carbon::now(self::ZONA)->toDateString();
    }

    /* ------------------------------------------------------------------------------------------
     | El envío por el ticket de soporte
     |----------------------------------------------------------------------------------------- */

    /**
     * Manda una plantilla de la prueba al dueño del cliente POR SU TICKET DE SOPORTE y registra el envío.
     *
     * @param Client      $client
     * @param Lead|null   $lead
     * @param string      $plantilla  Nombre de la plantilla de Meta.
     * @param string|null $hito       El hito que empuja (null para la bienvenida).
     * @param bool        $encolar    true = si la plantilla no está aprobada, queda `en_cola` para reintentar.
     * @param string|null $numero_asistente La segunda variable (`{{2}}`) de las plantillas que la llevan: el número
     *                                      oficial del asistente, el enlace de la encuesta o los días que quedan.
     *
     * @return array{estado: string, detalle: string|null, whatsapp_message_id?: string|null, support_ticket_id?: int|null}
     */
    public function enviar_plantilla_por_ticket(Client $client, ?Lead $lead, string $plantilla, ?string $hito, bool $encolar, ?string $numero_asistente = null): array
    {
        $estado = $this->plantillas->estado_para_enviar($plantilla);

        if ($estado['motivo'] !== null) {
            if ($encolar) {
                /* Una sola fila en cola por plantilla: si ya está, se le refresca el motivo (el evento puede llegar
                 * varias veces, y el job diario vuelve a pasar por acá cada mañana). */
                $en_cola = PruebaWhatsappEnvio::query()
                    ->where('client_id', (int) $client->id)
                    ->where('plantilla', $plantilla)
                    ->where('estado', PruebaWhatsappEnvio::ESTADO_EN_COLA)
                    ->first();

                if ($en_cola !== null) {
                    $en_cola->detalle = $estado['motivo'];
                    $en_cola->save();
                } else {
                    $this->registrar($client, $lead, $plantilla, $hito, PruebaWhatsappEnvio::ESTADO_EN_COLA, null, null, null, $estado['motivo']);
                }
            }

            return ['estado' => 'en_cola', 'detalle' => $plantilla . ': ' . $estado['motivo']];
        }

        $telefono = trim((string) $client->telefono_personal());
        $contacto = $telefono === '' ? null : $this->directorio->resolve_for_client($client, $telefono);

        if ($contacto === null) {
            $this->registrar($client, $lead, $plantilla, $hito, PruebaWhatsappEnvio::ESTADO_FALLIDO, null, null, null, 'El cliente no tiene un teléfono válido en la ficha.');

            return ['estado' => 'sin_contacto', 'detalle' => 'El cliente no tiene un teléfono válido en la ficha.'];
        }

        $admin = $this->remitente();
        if ($admin === null) {
            return ['estado' => 'sin_remitente', 'detalle' => 'No hay ningún admin en la base para figurar como remitente.'];
        }

        $primer_nombre = $this->primer_nombre($client, $lead, (string) $contacto['label']);

        $resuelto = $this->opener->resolve_or_create_ticket($client, (string) $contacto['phone'], $admin, 'Prueba gratuita', 'sistema');
        $ticket   = $resuelto['ticket'];

        /* @var FollowupTemplate $fila */
        $fila   = $estado['fila'];
        $idioma = trim((string) $fila->language_code) !== '' ? (string) $fila->language_code : PruebaPlantillasService::IDIOMA;

        $variables = [$this->opener->sanitize_template_variable($primer_nombre)];
        if ($numero_asistente !== null) {
            $variables[] = $this->opener->sanitize_template_variable($numero_asistente);
        }

        $mensaje = DB::transaction(function () use ($ticket, $client, $contacto, $plantilla, $variables, $idioma, $admin, $primer_nombre, $numero_asistente) {
            $actual = SupportTicket::lockForUpdate()->find($ticket->id);
            if ($actual === null || $actual->source !== 'whatsapp') {
                return null;
            }
            $wamid = $this->sender->send_template(
                (string) $contacto['phone'],
                $plantilla,
                $variables,
                $idioma,
                'Prueba gratuita - ' . $plantilla . ' - cliente #' . $client->id
            );

            return SupportMessage::create([
                'support_ticket_id'      => $ticket->id,
                'sender_type'            => 'admin',
                'sender_admin_id'        => (int) $admin->id,
                'kind'                   => 'text',
                'body'                   => $this->plantillas->cuerpo_renderizado($plantilla, $primer_nombre, $numero_asistente),
                'delivered_at'           => now(),
                'whatsapp_message_id'    => $wamid,
                'remote_delivery_status' => $wamid === null ? 'not_received' : null,
            ]);
        });
        if ($mensaje === null) {
            return ['estado' => 'fallido', 'detalle' => 'La conversación continúa en el sistema.'];
        }
        $wamid = $mensaje->whatsapp_message_id;

        try {
            event(new SupportMessageReceived((int) $mensaje->id));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no se pudo emitir el evento del mensaje.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
        }

        if ($wamid === null) {
            $detalle = (string) $this->sender->last_send_error;
            $this->registrar($client, $lead, $plantilla, $hito, PruebaWhatsappEnvio::ESTADO_FALLIDO, (int) $ticket->id, (int) $mensaje->id, null, $detalle);

            return ['estado' => 'fallido', 'detalle' => $detalle, 'whatsapp_message_id' => null, 'support_ticket_id' => (int) $ticket->id];
        }

        $this->registrar($client, $lead, $plantilla, $hito, PruebaWhatsappEnvio::ESTADO_ENVIADO, (int) $ticket->id, (int) $mensaje->id, $wamid, null);

        /* Si salió la bienvenida completa, también se descarta la bienvenida anterior en cola. */
        PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->whereIn('plantilla', in_array($plantilla, PruebaPlantillasService::BIENVENIDAS, true) ? PruebaPlantillasService::BIENVENIDAS : [$plantilla])
            ->where('estado', PruebaWhatsappEnvio::ESTADO_EN_COLA)
            ->delete();

        Log::channel('daily')->info('PruebaWhatsapp: plantilla de la prueba enviada por el ticket.', [
            'client_id' => $client->id,
            'plantilla' => $plantilla,
            'hito'      => $hito,
            'ticket_id' => $ticket->id,
        ]);

        return ['estado' => 'enviado', 'detalle' => null, 'whatsapp_message_id' => $wamid, 'support_ticket_id' => (int) $ticket->id];
    }

    /**
     * Manda TEXTO LIBRE al dueño por su ticket de soporte (lo usa `AsistenteAvisosService` para el aviso
     * «ya leí tu Excel»). Solo si la ventana del número principal está abierta: si no, no sale nada y
     * quien llama lo deja pendiente.
     *
     * @param Client $client
     * @param string $texto
     * @param int|null $ticket_id Conversación del archivo cuando la instancia informó el origen.
     *
     * @return array{enviado: bool, motivo: string|null, whatsapp_message_id: string|null, support_ticket_id: int|null}
     */
    public function mandar_texto_por_ticket(Client $client, string $texto, ?int $ticket_id = null): array
    {
        $ticket = null;
        if ($ticket_id !== null) {
            $ticket = SupportTicket::whereKey($ticket_id)->where('client_id', $client->id)
                ->whereNull('client_employee_id')->where('source', 'whatsapp')->where('status', SupportTicket::STATUS_ABIERTO)->first();

            if ($ticket === null) {
                return ['enviado' => false, 'motivo' => 'ticket_no_disponible', 'whatsapp_message_id' => null, 'support_ticket_id' => null];
            }
        }

        $telefono = trim((string) ($ticket === null ? $client->telefono_personal() : $ticket->whatsapp_phone));
        $contacto = $telefono === '' ? null : $this->directorio->resolve_for_client($client, $telefono);

        if ($contacto === null || ($ticket !== null && $contacto['client_employee_id'] !== null)
            || ($ticket !== null && $ticket->importacion_soporte_iniciada_at !== null
                && !WhatsappNormalizer::equals_estricto((string) $contacto['raw_phone'], $telefono))) {
            return ['enviado' => false, 'motivo' => 'sin_contacto', 'whatsapp_message_id' => null, 'support_ticket_id' => null];
        }

        if (! $this->ventanas->is_open((string) $contacto['phone'])) {
            return ['enviado' => false, 'motivo' => 'ventana_cerrada', 'whatsapp_message_id' => null, 'support_ticket_id' => null];
        }

        $admin = $this->remitente();
        if ($admin === null) {
            return ['enviado' => false, 'motivo' => 'sin_remitente', 'whatsapp_message_id' => null, 'support_ticket_id' => null];
        }

        if ($ticket === null) {
            $ticket = $this->opener->resolve_or_create_ticket($client, (string) $contacto['phone'], $admin, 'Prueba gratuita', 'sistema')['ticket'];
        }

        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'sender_admin_id'   => (int) $admin->id,
            'kind'              => 'text',
            'body'              => $texto,
            'delivered_at'      => now(),
        ] + ($ticket_id === null ? [] : ['is_trial_import_notice' => true]));

        $wamid = DB::transaction(function () use ($ticket, $contacto, $texto, $client, $mensaje) {
            $actual = SupportTicket::lockForUpdate()->find($ticket->id);
            $wamid = $actual !== null && $actual->source === 'whatsapp'
                ? $this->sender->send_text((string) $contacto['phone'], $texto, 'Soporte - aviso de importación - cliente #' . $client->id, true)
                : null;
            $mensaje->whatsapp_message_id = $wamid;
            $mensaje->remote_delivery_status = $wamid === null ? 'not_received' : null;
            $mensaje->save();
            return $wamid;
        });

        try {
            event(new SupportMessageReceived((int) $mensaje->id));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no se pudo emitir el evento del mensaje.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
        }

        return [
            'enviado'             => $wamid !== null,
            'motivo'              => $wamid !== null ? null : (string) $this->sender->last_send_error,
            'whatsapp_message_id' => $wamid,
            'support_ticket_id'   => (int) $ticket->id,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Tomás
     |----------------------------------------------------------------------------------------- */

    /**
     * Al día 5 de la prueba (una sola vez), avisa a Tomás que está por vencer.
     *
     * @param Client               $client
     * @param Lead|null            $lead
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed>|null null si todavía no es el día 5 (o ya venció).
     */
    public function avisar_a_tomas_si_es_el_dia_5(Client $client, ?Lead $lead, array $datos): ?array
    {
        if ($datos['dia_actual'] === null || $datos['vencida'] === true) {
            return null;
        }

        if ((int) $datos['dia_actual'] < PruebaDelClienteService::DIA_DEL_AVISO_A_TOMAS) {
            return null;
        }

        return $this->avisar_a_tomas($client, $lead, self::TOMAS_DIA_5);
    }

    /**
     * Avisa a Tomás (WhatsApp + push). `dia_5` y `vencida` salen una sola vez por cliente; `manual` siempre.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param string    $motivo Uno de los TOMAS_*.
     *
     * @return array{estado: string, detalle: string|null}
     */
    public function avisar_a_tomas(Client $client, ?Lead $lead, string $motivo): array
    {
        if ($motivo !== self::TOMAS_MANUAL) {
            $ya = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->where('plantilla', PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS)
                ->where('hito', $motivo)
                ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
                ->exists();

            if ($ya) {
                return ['estado' => 'ya_avisado', 'detalle' => null];
            }
        }

        $closer = Admin::query()->where('is_closer', true)->whereNotNull('phone_number')->first();
        if ($closer === null) {
            /* Un solo `fallido` por motivo: el job diario pasa por acá cada mañana y no tiene que dejar una fila por día. */
            $ya_fallido = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->where('plantilla', PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS)
                ->where('hito', $motivo)
                ->where('estado', PruebaWhatsappEnvio::ESTADO_FALLIDO)
                ->exists();

            if (! $ya_fallido) {
                $this->registrar($client, $lead, PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS, $motivo, PruebaWhatsappEnvio::ESTADO_FALLIDO, null, null, null, 'No hay un closer con teléfono cargado.');
            }

            Log::channel('daily')->warning('PruebaWhatsapp: no hay un admin closer con phone_number; el aviso a Tomás no sale.', [
                'client_id' => $client->id,
                'motivo'    => $motivo,
            ]);

            return ['estado' => 'sin_closer', 'detalle' => 'No hay un admin closer con phone_number configurado.'];
        }

        $datos  = $this->pruebas->datos($client);
        $texto  = $this->texto_para_tomas($client, $lead, $motivo, $datos);
        $titulo = 'Prueba gratuita: ' . $client->resolve_display_name();

        $wamid = $this->sender->send_text(trim((string) $closer->phone_number), $texto, 'Aviso a Tomás - prueba gratuita - cliente #' . $client->id, true);

        try {
            AdminPushNotificationService::send_to_admin((int) $closer->id, $titulo, $texto, ['url' => $this->link_al_lead($lead)]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: falló el push a Tomás.', ['client_id' => $client->id, 'error' => $e->getMessage()]);
        }

        $estado = $wamid !== null ? PruebaWhatsappEnvio::ESTADO_ENVIADO : PruebaWhatsappEnvio::ESTADO_FALLIDO;
        $this->registrar($client, $lead, PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS, $motivo, $estado, null, null, $wamid, $wamid !== null ? null : (string) $this->sender->last_send_error);

        return ['estado' => $wamid !== null ? 'avisado' : 'fallido', 'detalle' => $wamid !== null ? null : (string) $this->sender->last_send_error];
    }

    /**
     * El texto del aviso a Tomás.
     *
     * @param Client               $client
     * @param Lead|null            $lead
     * @param string               $motivo
     * @param array<string, mixed> $datos
     *
     * @return string
     */
    protected function texto_para_tomas(Client $client, ?Lead $lead, string $motivo, array $datos): string
    {
        $negocio  = $client->resolve_display_name();
        $contacto = $lead !== null ? trim((string) $lead->contact_name) : '';
        $quien    = $contacto !== '' ? $negocio . ' (' . $contacto . ')' : $negocio;

        if ($motivo === self::TOMAS_VENCIDA) {
            $cuerpo = $quien . ' terminó su prueba gratuita. Es el momento de llamarlo para cerrar.';
        } elseif ($motivo === self::TOMAS_DIA_5) {
            $restan = $datos['dias_restantes'] !== null ? (int) $datos['dias_restantes'] : 2;
            $cuerpo = $quien . ' va por el día ' . (int) $datos['dia_actual'] . ' de su prueba gratuita: le '
                . ($restan === 1 ? 'queda 1 día' : 'quedan ' . $restan . ' días') . '. Conviene llamarlo antes de que venza.';
        } else {
            $cuerpo = 'Pedido desde el admin: llamá a ' . $quien . ', que está en su prueba gratuita'
                . ($datos['dias_restantes'] !== null ? ' (le quedan ' . (int) $datos['dias_restantes'] . ' días)' : '') . '.';
        }

        $link = $this->link_al_lead($lead);

        return $link !== '' ? $cuerpo . "\n" . $link : $cuerpo;
    }

    /**
     * Link al tablero de leads del admin (por slug, como `CloserNotificationService`), vacío sin SPA configurado.
     *
     * @param Lead|null $lead
     *
     * @return string
     */
    protected function link_al_lead(?Lead $lead): string
    {
        $base = rtrim(trim((string) config('services.admin_spa.url')), '/');

        if ($base === '') {
            return '';
        }

        return $base . '/pipelines/tablero?pipeline=' . PipelineLeadSeeder::SLUG . ($lead !== null ? '&lead=' . (int) $lead->id : '');
    }

    /* ------------------------------------------------------------------------------------------
     | Internos
     |----------------------------------------------------------------------------------------- */

    /**
     * Deja el registro del envío.
     *
     * @return PruebaWhatsappEnvio
     */
    protected function registrar(Client $client, ?Lead $lead, string $plantilla, ?string $hito, string $estado, ?int $ticket_id, ?int $mensaje_id, ?string $wamid, ?string $detalle): PruebaWhatsappEnvio
    {
        return PruebaWhatsappEnvio::create([
            'client_id'           => (int) $client->id,
            'lead_id'             => $lead !== null ? (int) $lead->id : null,
            'plantilla'           => $plantilla,
            'hito'                => $hito,
            'estado'              => $estado,
            'support_ticket_id'   => $ticket_id,
            'support_message_id'  => $mensaje_id,
            'whatsapp_message_id' => $wamid,
            'detalle'             => $detalle !== null ? mb_substr($detalle, 0, 1000) : null,
            'dia'                 => self::hoy(),
            'enviado_at'          => $estado === PruebaWhatsappEnvio::ESTADO_ENVIADO ? now() : null,
        ]);
    }

    /**
     * El primer nombre con el que se le habla al dueño: el del lead si lo hay, si no el del contacto de la
     * ficha, si no el nombre del cliente.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param string    $label_del_contacto
     *
     * @return string
     */
    protected function primer_nombre(Client $client, ?Lead $lead, string $label_del_contacto): string
    {
        $candidatos = [
            $lead !== null ? (string) $lead->contact_name : '',
            $label_del_contacto,
            (string) $client->name,
            $client->resolve_display_name(),
        ];

        foreach ($candidatos as $candidato) {
            $primero = Lead::primer_nombre_de($candidato);
            if ($primero !== null && trim($primero) !== '') {
                return trim($primero);
            }
        }

        return 'Hola';
    }

    /**
     * El admin que figura como remitente: el de `CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID`, el destinatario por
     * defecto de tareas, o el primero (misma regla que los endpoints `claude/*`).
     *
     * @return Admin|null
     */
    protected function remitente(): ?Admin
    {
        $configurado = config('services.claude_task_ingest.default_creator_admin_id');
        if (! empty($configurado)) {
            $admin = Admin::find((int) $configurado);
            if ($admin !== null) {
                return $admin;
            }
        }

        $por_defecto = Admin::query()->where('is_default_task_assignee', true)->first();
        if ($por_defecto !== null) {
            return $por_defecto;
        }

        return Admin::query()->orderBy('id')->first();
    }
}
