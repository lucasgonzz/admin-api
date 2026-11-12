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

    /**
     * Marca (en `support_messages.erp_auto_reply`) de la copia de la bienvenida que se deja visible en Soporte del
     * sistema del negocio cuando el WhatsApp no pudo salir. Ver {@see self::asegurar_bienvenida_en_el_sistema()}.
     */
    const MARCA_BIENVENIDA_SISTEMA = 'prueba_bienvenida_sistema';

    /** Dentro del alcance de las novedades: cuántas veces sale, como mucho, el WhatsApp de un mismo paso. */
    const TOPE_POR_PASO = 1;

    /** `ingreso` (si nunca entró) se recuerda hasta dos veces: sin entrar no hay nada más que proponerle. */
    const TOPE_DE_INGRESO = 2;

    /** El día que vence sí sale paso, salvo que falten menos de estas horas para el vencimiento. */
    const HORAS_SIN_PASO_ANTES_DE_VENCER = 3;

    const MOTIVO_ULTIMO_DIA      = 'Faltan menos de 3 horas para que venza la prueba: no sale paso, sale la encuesta al vencer.';
    const MOTIVO_TOPE_DE_INGRESO = 'Todavía no entró y ya se le recordó dos veces: no se le mandan más pasos.';

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
            /* Una cuenta de ensayo no le avisa al closer: eso solo lo hace `pruebas:ensayo --con-closer`. */
            if ((bool) $client->es_ensayo) {
                return ['accion' => 'aviso_tomas', 'estado' => 'ensayo'];
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
            $resultado = $this->bienvenida_por_whatsapp($client, $lead);
            $this->asegurar_bienvenida_en_el_sistema($client, $lead, $resultado);

            return $resultado;
        } finally {
            $reserva->release();
        }
    }

    /**
     * La bienvenida por WhatsApp (plantilla por el ticket de soporte), con el candado ya tomado.
     *
     * @param Client    $client
     * @param Lead|null $lead
     *
     * @return array{estado: string, detalle: string|null}
     */
    protected function bienvenida_por_whatsapp(Client $client, ?Lead $lead): array
    {
            $enviadas = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->whereIn('plantilla', array_merge(PruebaPlantillasService::BIENVENIDAS, [PruebaPlantillasService::ASISTENTE_LISTO]))
                ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
                ->pluck('plantilla');

            $ya = $enviadas->isNotEmpty();
            $plantilla = $ya ? PruebaPlantillasService::ASISTENTE_LISTO : $this->plantillas->bienvenida_vigente(true, $client);
            $instancia = $lead !== null ? Prueba\PruebaAsistenteService::instancia_vigente($client) : null;
            if ($instancia !== null) {
                $falta_correo = $instancia->correo_bienvenida_at === null && filter_var((string) $client->email, FILTER_VALIDATE_EMAIL);
                $falta_whatsapp = $enviadas->intersect(PruebaPlantillasService::BIENVENIDAS_CON_ASISTENTE)->isEmpty()
                    && ! $enviadas->contains(PruebaPlantillasService::ASISTENTE_LISTO)
                    && $this->plantillas->estado_para_enviar($plantilla)['motivo'] === null;

                if ($falta_correo || $falta_whatsapp) {
                    $asistente = app(Prueba\PruebaAsistenteService::class)->habilitar($client, $lead, $instancia);
                    if ($asistente !== null) {
                        if ($falta_correo) {
                            app(Prueba\PruebaCorreoService::class)->enviar($client, $instancia, $asistente);
                        }
                        if ($falta_whatsapp) {
                            return $this->con_video_de_bienvenida($client, $this->enviar_plantilla_por_ticket($client, $lead, $plantilla, null, true, $this->variables_de_la_bienvenida($plantilla, $client, (string) $asistente['numero'])));
                        }
                    }
                }
            }

            if ($ya) {
                return ['estado' => 'ya_enviada', 'detalle' => null];
            }

            $sin_asistente = $this->plantillas->bienvenida_vigente(false, $client);

            return $this->con_video_de_bienvenida($client, $this->enviar_plantilla_por_ticket($client, $lead, $sin_asistente, null, true, $this->variables_de_la_bienvenida($sin_asistente, $client, null)));
    }

    /**
     * Las variables que lleva cada bienvenida además del nombre: la v1 con asistente, su número; la v2, la dirección
     * del sistema; la v2 con asistente, la dirección y el número.
     *
     * @param string      $plantilla
     * @param Client      $client
     * @param string|null $numero_asistente
     *
     * @return string|array<int, string>|null
     */
    protected function variables_de_la_bienvenida(string $plantilla, Client $client, ?string $numero_asistente)
    {
        $url = (string) Prueba\PruebaCorreoService::url_del_sistema($client);

        if ($plantilla === PruebaPlantillasService::BIENVENIDA_V2) {
            return $url;
        }
        if (in_array($plantilla, [PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2, PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V3], true)) {
            return [$url, (string) $numero_asistente];
        }

        return $numero_asistente;
    }

    /**
     * El negocio tiene que ver la bienvenida en Soporte de SU SISTEMA aunque el WhatsApp no salga (misión
     * prueba-e2e-campana-imperio, 11/11/2026). Lo que el sistema muestra es el ticket «Prueba gratuita» de
     * WhatsApp, y de él solo los mensajes que salieron (con wamid) o que están marcados como sincronizados
     * (`InboundAgentesController::filtrar_mensajes_visibles()`). Entonces:
     *
     *  - Si la plantilla salió, no hay nada que hacer: el mensaje ya se ve. Y si antes se había dejado una copia
     *    para el sistema, se retira: la bienvenida no se duplica.
     *  - Si quedó en cola (Meta todavía no aprobó la plantilla) o Meta la rechazó, se abre igual el ticket y se
     *    deja UNA copia para el sistema: el texto de la bienvenida v2 y el video 04, marcada como sincronizada.
     *    No sale nada por WhatsApp ni se registra en `prueba_whatsapp_envios`: la bienvenida por WhatsApp sigue
     *    pendiente y sale cuando se pueda.
     *
     * Nunca cambia el resultado de la bienvenida ni la tumba.
     *
     * @param Client               $client
     * @param Lead|null            $lead
     * @param array<string, mixed> $resultado Lo que devolvió `bienvenida_por_whatsapp()`.
     *
     * @return void
     */
    protected function asegurar_bienvenida_en_el_sistema(Client $client, ?Lead $lead, array $resultado): void
    {
        try {
            $estado = isset($resultado['estado']) ? (string) $resultado['estado'] : '';

            /* Cliente de ensayo (12/11/2026): la bienvenida ya se dejó en la conversación del sistema por
               `enviar_plantilla_por_ticket()`; esta copia abriría un ticket de WhatsApp, que el ensayo no usa. */
            if (Prueba\PruebaEnsayoSinWhatsappService::es_ensayo($client)) {
                return;
            }

            if ($estado === 'enviado') {
                $copia = $this->bienvenida_del_sistema($client);
                if ($copia !== null) {
                    $copia->delete();
                }

                return;
            }

            if (! in_array($estado, ['en_cola', 'fallido'], true) || ! Prueba\PruebaSettings::novedades_para($client)
                || $this->bienvenida_del_sistema($client) !== null) {
                return;
            }

            /* Si alguna bienvenida ya salió por WhatsApp (lo que falló fue otra cosa), ya la ve. */
            $ya_salio = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->whereIn('plantilla', PruebaPlantillasService::BIENVENIDAS)
                ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
                ->exists();
            if ($ya_salio) {
                return;
            }

            $telefono = trim((string) $client->telefono_personal());
            $contacto = $telefono === '' ? null : $this->directorio->resolve_for_client($client, $telefono);
            $admin    = $this->remitente();
            if ($contacto === null || $contacto['client_employee_id'] !== null || $admin === null) {
                return;
            }

            $ticket = $this->opener->resolve_or_create_ticket($client, (string) $contacto['phone'], $admin, 'Prueba gratuita', 'sistema')['ticket'];
            if ($ticket === null || $ticket->source !== 'whatsapp') {
                return;
            }

            $url   = Prueba\PruebaCorreoService::url_del_sistema($client);
            $texto = PruebaPlantillasService::cuerpo_de(PruebaPlantillasService::BIENVENIDA_V2);
            if ($url === null) {
                $texto = str_replace(PruebaPlantillasService::COMO_ENTRAR . ' ', '', $texto);
            }
            $texto = strtr($texto, [
                '{{1}}' => $this->primer_nombre($client, $lead, (string) $contacto['label']),
                '{{2}}' => (string) $url,
            ]);

            $catalogo = app(Prueba\PruebaVideoCatalogoService::class)->videos();
            $clave    = 'prueba:' . Prueba\PruebaVideoSoporteService::CODIGO_BIENVENIDA;
            if (isset($catalogo[$clave])) {
                $texto .= "\n\n" . app(Prueba\PruebaVideoSoporteService::class)->texto_del_video($catalogo[$clave]);
            }

            $mensaje = SupportMessage::create([
                'support_ticket_id'      => (int) $ticket->id,
                'sender_type'            => 'admin',
                'sender_admin_id'        => (int) $admin->id,
                'kind'                   => 'text',
                'body'                   => $texto,
                'delivered_at'           => now(),
                /* Sin wamid: no salió por WhatsApp. Lo que lo hace visible en el sistema es esta marca. */
                'synced_to_client_at'    => now(),
                'is_trial_import_notice' => true,
                'erp_auto_reply'         => [self::MARCA_BIENVENIDA_SISTEMA => true],
            ]);

            try {
                event(new SupportMessageReceived((int) $mensaje->id));
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('PruebaWhatsapp: no se pudo emitir el evento del mensaje.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no se pudo dejar la bienvenida visible en el sistema del negocio.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * La copia de la bienvenida dejada para el sistema del negocio, si existe.
     *
     * @param Client $client
     *
     * @return SupportMessage|null
     */
    protected function bienvenida_del_sistema(Client $client): ?SupportMessage
    {
        return SupportMessage::query()
            ->whereHas('ticket', function ($ticket) use ($client) {
                $ticket->where('client_id', (int) $client->id);
            })
            ->where('sender_type', 'admin')
            ->where('erp_auto_reply->' . self::MARCA_BIENVENIDA_SISTEMA, true)
            ->orderByDesc('id')
            ->first();
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
            /* Este barrido NO saltea a la cuenta de ensayo: un alta que nace marcada (`prueba_ensayo_correos`) depende
               de él para completar su bienvenida (el WhatsApp del asistente y el correo salen cuando su sistema lo
               habilita, y el envío que falló se reintenta acá). La bienvenida sale una sola vez y no mira el reloj. */
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
            /* Al cliente de ensayo lo maneja solo `pruebas:ensayo` (con su reloj): ni empujón ni aviso al closer. */
            if ((bool) $client->es_ensayo) {
                continue;
            }

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

        /* Desde el día 5, el mensaje del día es el aviso de que la prueba está por terminar (una sola vez). */
        $por_terminar = $this->avisar_al_negocio_que_esta_por_terminar($client, $lead, $datos);
        if ($por_terminar !== null) {
            return $por_terminar;
        }

        if ($this->ya_se_le_mando_hoy($client)) {
            return ['estado' => 'ya_hoy', 'detalle' => 'Ya salió un mensaje de la prueba hoy.'];
        }

        $alcance = Prueba\PruebaSettings::novedades_para($client);

        /* Dentro del alcance nuevo, el día que vence sí sale paso mientras la prueba siga en curso (la encuesta sale
           recién al vencer), salvo que falten menos de tres horas para el vencimiento. */
        if ($alcance && self::vence_muy_pronto($datos)) {
            return ['estado' => 'ultimo_dia', 'detalle' => self::MOTIVO_ULTIMO_DIA];
        }

        if ($this->escribio_hace_poco($client, $alcance)) {
            return ['estado' => 'escribio_hace_poco', 'detalle' => 'El negocio escribió en las últimas 24 h: lo atiende Martín.'];
        }

        $hitos = $this->pruebas->hitos($client, $lead, true);

        /* La instancia sabe mejor si venció (C3): aunque el admin no tenga `trial_ends_at`, con `vencida` en
         * true no se empuja más. */
        if ($hitos['vencida'] === true) {
            return ['estado' => 'vencida', 'detalle' => 'La instancia dice que la prueba venció: no se empuja más.'];
        }

        $eleccion = $this->elegir_paso($client, $hitos);

        if ($eleccion['paso'] === null) {
            /* Si ningún paso tiene la plantilla aprobada, el primero que tocaba le llega igual por correo (una vez). */
            if ($eleccion['sin_plantilla'] !== null) {
                $this->correo_del_paso($client, $lead, $eleccion['sin_plantilla']);
            }

            return ['estado' => $eleccion['estado'], 'detalle' => $eleccion['detalle']];
        }

        $envio = $this->enviar_plantilla_por_ticket($client, $lead, $eleccion['plantilla'], $eleccion['paso'], false);

        if ($envio['estado'] === 'enviado') {
            $this->correo_del_paso($client, $lead, $eleccion['paso']);
        }

        return ['estado' => $envio['estado'] === 'enviado' ? 'enviado' : $envio['estado'], 'detalle' => $envio['detalle'] !== null ? $envio['detalle'] : $eleccion['plantilla']];
    }

    /**
     * Qué paso le toca hoy a este cliente y con qué plantilla. No manda nada: lo usan el empujón y la lectura para
     * el informe diario ({@see self::que_sale_hoy()}), así las dos dicen lo mismo.
     *
     * Fuera del alcance de las novedades es la regla de siempre: el primer hito que falta, en el orden de siempre, y
     * si su plantilla no está aprobada ese día no sale nada.
     *
     * Dentro del alcance (revisión del 11/11/2026), en el orden del recorrido nuevo:
     *  1. Tope de UN envío por paso ({@see self::TOPE_POR_PASO}): un paso ya mandado y sin cumplir no se repite; al día
     *     siguiente sale el siguiente que falte. La excepción es ENTRAR, que se recuerda hasta dos veces
     *     ({@see self::TOPE_DE_INGRESO}); si ya van dos y no entró, no se le manda ningún paso más. Cuando ya se le
     *     nombró todo lo que falta, no sale paso: la lista no se reinicia.
     *  2. Si la plantilla del paso que toca no está aprobada, se pasa al siguiente que la tenga (nunca queda mudo).
     *
     * @param Client               $client
     * @param array<string, mixed> $hitos  Lo que devuelve `PruebaDelClienteService::hitos()` para este cliente.
     *
     * @return array{paso: string|null, plantilla: string|null, estado: string, detalle: string|null, sin_plantilla: string|null}
     *   `sin_plantilla` = dentro del alcance, el primer paso pendiente que se salteó por no tener plantilla aprobada,
     *   cuando al final no quedó ninguno para mandar.
     */
    public function elegir_paso(Client $client, array $hitos): array
    {
        $orden  = $this->pruebas->orden_del_recorrido($client);
        $omitir = isset($hitos['sin_soporte']) ? $hitos['sin_soporte'] : [];

        if (! Prueba\PruebaSettings::novedades_para($client)) {
            $proximo = PruebaDelClienteService::proximo_hito_faltante($hitos['hitos'], $omitir, $orden);
            if ($proximo === null) {
                return ['paso' => null, 'plantilla' => null, 'estado' => 'sin_hito_faltante', 'detalle' => 'Pasó por todos los hitos.', 'sin_plantilla' => null];
            }

            $plantilla = PruebaPlantillasService::plantilla_del_paso($proximo);
            $estado    = $this->plantillas->estado_para_enviar($plantilla);
            if ($estado['motivo'] !== null) {
                return ['paso' => null, 'plantilla' => $plantilla, 'estado' => 'plantilla_no_disponible', 'detalle' => $plantilla . ': ' . $estado['motivo'], 'sin_plantilla' => $proximo];
            }

            return ['paso' => $proximo, 'plantilla' => $plantilla, 'estado' => 'enviar', 'detalle' => null, 'sin_plantilla' => null];
        }

        /* Cuántas veces salió ya el WhatsApp de cada paso (cualquiera de sus plantillas). */
        $veces = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            /* `sistema` = el mismo paso dejado en el sistema de un cliente de ensayo (12/11/2026). */
            ->whereIn('canal', [PruebaWhatsappEnvio::CANAL_WHATSAPP, PruebaWhatsappEnvio::CANAL_SISTEMA])
            ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
            ->where('plantilla', 'like', PruebaPlantillasService::PASO_PREFIJO . '%')
            ->whereNotNull('hito')
            ->pluck('hito')
            ->countBy()
            ->all();

        $sin_plantilla = null;
        $plantilla_que_falta = null;

        foreach ($orden as $hito) {
            if (in_array($hito, $omitir, true) || (isset($hitos['hitos'][$hito]) && $hitos['hitos'][$hito] !== null)) {
                continue;
            }

            if ((isset($veces[$hito]) ? (int) $veces[$hito] : 0) >= ($hito === 'ingreso' ? self::TOPE_DE_INGRESO : self::TOPE_POR_PASO)) {
                if ($hito === 'ingreso') {
                    return ['paso' => null, 'plantilla' => null, 'estado' => 'tope_de_ingreso', 'detalle' => self::MOTIVO_TOPE_DE_INGRESO, 'sin_plantilla' => null];
                }

                continue;
            }

            $plantilla = $this->plantillas->plantilla_del_paso_vigente($hito, $client);
            if ($this->plantillas->estado_para_enviar($plantilla)['motivo'] !== null) {
                if ($sin_plantilla === null) {
                    $sin_plantilla       = $hito;
                    $plantilla_que_falta = $plantilla;
                }

                continue;
            }

            return ['paso' => $hito, 'plantilla' => $plantilla, 'estado' => 'enviar', 'detalle' => null, 'sin_plantilla' => null];
        }

        if ($sin_plantilla !== null) {
            return ['paso' => null, 'plantilla' => $plantilla_que_falta, 'estado' => 'plantilla_no_disponible',
                'detalle' => 'Ningún paso pendiente tiene su plantilla aprobada (falta ' . $plantilla_que_falta . ').', 'sin_plantilla' => $sin_plantilla];
        }

        return ['paso' => null, 'plantilla' => null, 'estado' => 'sin_hito_faltante', 'detalle' => 'No queda ningún paso sin mandar: ya cumplió o ya se le nombró todo.', 'sin_plantilla' => null];
    }

    /**
     * Si la prueba vence dentro de las próximas 24 horas: es su último día (el día N de N).
     *
     * @param array<string, mixed> $datos `PruebaDelClienteService::datos()`.
     *
     * @return bool
     */
    public static function vence_hoy(array $datos): bool
    {
        return $datos['vencida'] !== true && $datos['dia_actual'] !== null && (int) $datos['dia_actual'] >= (int) $datos['dias'];
    }

    /**
     * Si faltan menos de {@see self::HORAS_SIN_PASO_ANTES_DE_VENCER} horas para que venza: a esa altura ya no sale
     * paso.
     *
     * @param array<string, mixed> $datos `PruebaDelClienteService::datos()`.
     *
     * @return bool
     */
    public static function vence_muy_pronto(array $datos): bool
    {
        if ($datos['vencida'] === true || empty($datos['vence_at'])) {
            return false;
        }

        return Carbon::now()->diffInSeconds(Carbon::parse((string) $datos['vence_at']), false) < self::HORAS_SIN_PASO_ANTES_DE_VENCER * 3600;
    }

    /**
     * El paso que le tocaría hoy a este negocio por WhatsApp si hoy no saliera otra cosa (ni aviso ni tope diario):
     * con las reglas del empujón, sin mandar nada. Lo usa el correo del aviso de que la prueba termina, que lo
     * lleva debajo («Mientras tanto, lo próximo para probar…»). null si hoy no le toca ninguno.
     *
     * @param Client               $client
     * @param Lead|null            $lead
     * @param array<string, mixed> $datos
     * @param array<string, mixed>|null $hitos Si ya se tienen (los del alcance del cliente).
     *
     * @return string|null
     */
    public function paso_que_tocaba(Client $client, ?Lead $lead, array $datos, ?array $hitos = null): ?string
    {
        $alcance = Prueba\PruebaSettings::novedades_para($client);

        if (($alcance && self::vence_muy_pronto($datos)) || $this->escribio_hace_poco($client, $alcance)) {
            return null;
        }

        $hitos = $hitos !== null ? $hitos : $this->pruebas->hitos($client, $lead, true);
        if (isset($hitos['vencida']) && $hitos['vencida'] === true) {
            return null;
        }

        return $this->elegir_paso($client, $hitos)['paso'];
    }

    /**
     * Si el negocio escribió hace menos de 24 horas. Siempre mira la ventana de WhatsApp del dueño (la regla de
     * siempre); con `$cualquier_canal` (dentro del alcance de las novedades) mira además cualquier mensaje suyo a
     * Soporte, por WhatsApp o por el chat de su sistema: lo está guiando Martín.
     *
     * @param Client $client
     * @param bool   $cualquier_canal
     *
     * @return bool
     */
    public function escribio_hace_poco(Client $client, bool $cualquier_canal): bool
    {
        $telefono = trim((string) $client->telefono_personal());
        if ($telefono !== '' && $this->ventanas->is_open($telefono)) {
            return true;
        }

        if (! $cualquier_canal) {
            return false;
        }

        $entrantes = DB::table('support_messages')
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->where('support_tickets.client_id', (int) $client->id)
            ->where('support_messages.sender_type', 'user')
            ->where('support_messages.created_at', '>=', Carbon::now()->subHours(24));
        SupportMessage::apply_mensaje_real_conditions($entrantes, 'support_messages');

        return $entrantes->exists();
    }

    /**
     * Qué le sale HOY a este negocio, con las mismas reglas y en el mismo orden que el empujón diario y sin mandar
     * nada (misión prueba-e2e-campana-imperio, 11/11/2026): es lo que lee el informe diario.
     *
     * @param Client               $client
     * @param array<string, mixed> $datos `PruebaDelClienteService::datos()`.
     * @param array<string, mixed> $hitos `PruebaDelClienteService::hitos()` de este cliente (ya con su alcance).
     *
     * @return array{paso: string|null, paso_en_el_correo: string|null, motivo_sin_paso: string|null, aviso_dia_5: bool, vence_hoy: bool, encuesta_pendiente: bool, plantilla: string|null, video_codigo: string|null}
     *   `paso` = el paso que sale hoy por WhatsApp. `paso_en_el_correo` = el día del aviso de que termina, el paso que
     *   va debajo del aviso en el correo (no cuenta como enviado). `vence_hoy` = vence dentro de 24 h (igual sale
     *   paso, salvo que falten menos de 3 h).
     */
    public function que_sale_hoy(Client $client, array $datos, array $hitos): array
    {
        $alcance = Prueba\PruebaSettings::novedades_para($client);
        $vencida = $datos['vencida'] === true || (isset($hitos['vencida']) && $hitos['vencida'] === true)
            || (string) $client->estado === Prueba\PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $en_prueba = Prueba\PruebaCicloService::esta_en_prueba($client);

        $hoy = [
            'paso' => null, 'paso_en_el_correo' => null, 'motivo_sin_paso' => null, 'aviso_dia_5' => false,
            'vence_hoy' => $en_prueba && ! $vencida && self::vence_hoy($datos),
            'encuesta_pendiente' => false, 'plantilla' => null, 'video_codigo' => null,
        ];

        /* La encuesta: sale al vencer, así que está pendiente el día que vence y mientras no haya salido. */
        if ($alcance && Prueba\PruebaSettings::encuesta_activa()) {
            $encuesta = \App\Models\PruebaEncuesta::where('client_id', (int) $client->id)->first();
            $hoy['encuesta_pendiente'] = $hoy['vence_hoy'] || ($en_prueba && $vencida && ($encuesta === null || $encuesta->enviada_at === null));
        }

        if (! $en_prueba) {
            $hoy['motivo_sin_paso'] = 'Ya no está en prueba.';

            return $hoy;
        }
        if ($vencida) {
            $hoy['motivo_sin_paso'] = 'La prueba venció: no se empuja más.';

            return $hoy;
        }

        $con_bienvenida = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->whereIn('plantilla', array_merge(PruebaPlantillasService::BIENVENIDAS, [PruebaPlantillasService::ASISTENTE_LISTO]))
            ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
            ->exists();
        if (! $con_bienvenida) {
            $hoy['plantilla']       = $this->plantillas->bienvenida_vigente(false, $client);
            $hoy['motivo_sin_paso'] = 'Primero sale la bienvenida.';

            return $hoy;
        }

        if ($this->corresponde_el_aviso_de_que_termina($client, $datos)) {
            $hoy['aviso_dia_5']     = true;
            $hoy['plantilla']       = PruebaPlantillasService::DIA_5;
            $hoy['motivo_sin_paso'] = 'Hoy sale el aviso de que la prueba termina (el WhatsApp del día es ese).';

            /* El correo de hoy lleva, debajo del aviso, el paso que le tocaba (no cuenta para el tope de envíos). */
            $correos = app(Prueba\PruebaCorreoService::class);
            if (filter_var(trim((string) $client->email), FILTER_VALIDATE_EMAIL) && ! $correos->ya_salio($client, Prueba\PruebaCorreoService::CORREO_DIA_5)) {
                $hoy['paso_en_el_correo'] = $this->paso_que_tocaba($client, null, $datos, $hitos);
                if ($hoy['paso_en_el_correo'] !== null && isset(Prueba\PruebaCorreoService::VIDEO_DEL_PASO[$hoy['paso_en_el_correo']])) {
                    $hoy['video_codigo'] = Prueba\PruebaCorreoService::VIDEO_DEL_PASO[$hoy['paso_en_el_correo']];
                }
            }

            return $hoy;
        }

        if ($this->ya_se_le_mando_hoy($client)) {
            $hoy['motivo_sin_paso'] = 'Ya salió un mensaje de la prueba hoy.';

            return $hoy;
        }

        if ($alcance && self::vence_muy_pronto($datos)) {
            $hoy['motivo_sin_paso'] = self::MOTIVO_ULTIMO_DIA;

            return $hoy;
        }

        if ($this->escribio_hace_poco($client, $alcance)) {
            $hoy['motivo_sin_paso'] = 'El negocio escribió en las últimas 24 h: lo atiende Martín.';

            return $hoy;
        }

        $eleccion         = $this->elegir_paso($client, $hitos);
        $hoy['paso']      = $eleccion['paso'];
        $hoy['plantilla'] = $eleccion['plantilla'];
        if ($eleccion['paso'] === null) {
            $hoy['motivo_sin_paso'] = $eleccion['detalle'];
        } elseif ($alcance && isset(Prueba\PruebaCorreoService::VIDEO_DEL_PASO[$eleccion['paso']])) {
            $hoy['video_codigo'] = Prueba\PruebaCorreoService::VIDEO_DEL_PASO[$eleccion['paso']];
        }

        return $hoy;
    }

    /**
     * El correo que acompaña al empujón de un paso, con el video que corresponde (misión
     * prueba-e2e-campana-imperio, 11/11/2026). Uno por paso y por cliente; nunca cambia el resultado del empujón.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param string    $hito
     *
     * @return void
     */
    protected function correo_del_paso(Client $client, ?Lead $lead, string $hito): void
    {
        if (! Prueba\PruebaSettings::novedades_para($client)) {
            return;
        }

        try {
            app(Prueba\PruebaCorreoService::class)->enviar_paso($client, $lead, $hito);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no salió el correo del paso.', [
                'client_id' => (int) $client->id,
                'hito'      => $hito,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Desde el día 5 de la prueba, le avisa AL NEGOCIO que está por terminar (misión prueba-e2e-campana-imperio,
     * 11/11/2026): un correo y el WhatsApp `cc_prueba_dia_5`, cada uno una sola vez. El WhatsApp ocupa el lugar
     * del empujón de ese día (sigue siendo uno por día) y sale aunque el negocio haya escrito hace poco: no es un
     * empujón, es un aviso de fecha. El correo no depende del WhatsApp. El aviso a Tomás no cambia.
     *
     * @param Client               $client
     * @param Lead|null            $lead
     * @param array<string, mixed> $datos
     *
     * @return array{estado: string, detalle: string|null}|null null = no salió ningún WhatsApp acá: sigue el empujón normal.
     */
    public function avisar_al_negocio_que_esta_por_terminar(Client $client, ?Lead $lead, array $datos): ?array
    {
        if (! Prueba\PruebaSettings::novedades_para($client) || $datos['dia_actual'] === null || $datos['vencida'] === true
            || (int) $datos['dia_actual'] < PruebaDelClienteService::DIA_DEL_AVISO_A_TOMAS) {
            return null;
        }

        /* Los días que realmente faltan (`dias_restantes`): el mismo número que se le informa al closer. */
        $restan = $datos['dias_restantes'] !== null ? (int) $datos['dias_restantes'] : (int) $datos['dias'] - (int) $datos['dia_actual'];
        $falta  = $restan <= 1 ? '1 día' : $restan . ' días';

        try {
            /* El correo lleva el aviso y, debajo, el paso que le tocaba hoy con su video. Ese paso NO cuenta como
               enviado: mañana sale por WhatsApp como siempre. Solo se calcula si el correo todavía no salió. */
            $correos = app(Prueba\PruebaCorreoService::class);
            if (! $correos->ya_salio($client, Prueba\PruebaCorreoService::CORREO_DIA_5)) {
                $correos->enviar_por_terminar($client, $lead, $falta, $this->paso_que_tocaba($client, $lead, $datos));
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no salió el correo de que la prueba está por terminar.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);
        }

        if (! $this->corresponde_el_aviso_de_que_termina($client, $datos)) {
            return null;
        }

        $envio = $this->enviar_plantilla_por_ticket($client, $lead, PruebaPlantillasService::DIA_5, self::TOMAS_DIA_5, false, $falta);

        return ['estado' => (string) $envio['estado'], 'detalle' => $envio['detalle'] !== null ? $envio['detalle'] : PruebaPlantillasService::DIA_5];
    }

    /**
     * Si HOY corresponde mandar el WhatsApp de que la prueba termina: dentro del alcance, del día 5 en adelante, sin
     * vencer, una sola vez, sin otro WhatsApp de la prueba hoy y con la plantilla aprobada. Sale aunque el negocio
     * haya escrito hace poco.
     *
     * @param Client               $client
     * @param array<string, mixed> $datos
     *
     * @return bool
     */
    public function corresponde_el_aviso_de_que_termina(Client $client, array $datos): bool
    {
        if (! Prueba\PruebaSettings::novedades_para($client) || $datos['dia_actual'] === null || $datos['vencida'] === true
            || (int) $datos['dia_actual'] < PruebaDelClienteService::DIA_DEL_AVISO_A_TOMAS) {
            return false;
        }

        $resuelto = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->where('plantilla', PruebaPlantillasService::DIA_5)
            ->whereIn('estado', [PruebaWhatsappEnvio::ESTADO_ENVIADO, PruebaWhatsappEnvio::ESTADO_FALLIDO])
            ->exists();

        return ! $resuelto && ! $this->ya_se_le_mando_hoy($client)
            && $this->plantillas->estado_para_enviar(PruebaPlantillasService::DIA_5)['motivo'] === null;
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
            /* Solo WhatsApps: un correo no gasta el tope del día. `sistema` lo registra únicamente el cliente de
               ensayo (12/11/2026), cuyo mensaje del día va a la conversación del sistema en lugar de WhatsApp. */
            ->whereIn('canal', [PruebaWhatsappEnvio::CANAL_WHATSAPP, PruebaWhatsappEnvio::CANAL_SISTEMA])
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
     * @param string|array<int, string>|null $numero_asistente La segunda variable (`{{2}}`) de las plantillas que la
     *                                      llevan: el número oficial del asistente, el enlace de la encuesta, los días
     *                                      que quedan o la dirección del sistema. Una lista = `{{2}}` y `{{3}}`.
     *
     * @return array{estado: string, detalle: string|null, whatsapp_message_id?: string|null, support_ticket_id?: int|null}
     */
    public function enviar_plantilla_por_ticket(Client $client, ?Lead $lead, string $plantilla, ?string $hito, bool $encolar, $numero_asistente = null): array
    {
        /* Cliente de ensayo (12/11/2026): cero WhatsApp. El mismo texto va a la conversación del sistema. */
        if (Prueba\PruebaEnsayoSinWhatsappService::es_ensayo($client)) {
            return $this->plantilla_al_sistema_del_ensayo($client, $lead, $plantilla, $hito, $numero_asistente);
        }

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
        foreach (is_array($numero_asistente) ? array_values($numero_asistente) : [$numero_asistente] as $extra) {
            if ($extra !== null) {
                $variables[] = $this->opener->sanitize_template_variable((string) $extra);
            }
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
        /* Cliente de ensayo (12/11/2026): cero WhatsApp, sea cual sea el ticket del origen. El texto va a la
           conversación del sistema del dueño. */
        if (Prueba\PruebaEnsayoSinWhatsappService::es_ensayo($client)) {
            return app(Prueba\PruebaEnsayoSinWhatsappService::class)->dejar($client, $texto);
        }

        /* El aviso vuelve por el MISMO canal por el que llegó el archivo (misión prueba-e2e-campana-imperio,
         * 11/11/2026): si el ticket es la conversación de Soporte del sistema del dueño de una prueba, el mensaje
         * se sincroniza a su sistema y no sale por WhatsApp. */
        if ($ticket_id !== null) {
            $del_sistema = SupportTicket::whereKey($ticket_id)->where('client_id', $client->id)
                ->whereNull('client_employee_id')->where('source', 'erp')->where('status', SupportTicket::STATUS_ABIERTO)->first();

            if ($del_sistema !== null) {
                return $this->mandar_texto_al_sistema($client, $del_sistema, $texto);
            }
        }

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

    /**
     * Deja un aviso del equipo en la conversación de Soporte DEL SISTEMA del dueño de un negocio en prueba y lo
     * sincroniza a su sistema (`admin-sync/support/messages`, el mismo camino que las respuestas de Martín). El
     * mensaje lleva `is_trial_import_notice`: queda en el hilo sin tapar el entrante que espera respuesta.
     *
     * Si el sistema del negocio no lo confirma, el mensaje se borra (no llenar el hilo con copias en cada
     * reintento) y se informa como no enviado.
     *
     * @param Client        $client
     * @param SupportTicket $ticket Ticket abierto del canal sistema, sin empleado.
     * @param string        $texto
     *
     * @return array{enviado: bool, motivo: string|null, whatsapp_message_id: string|null, support_ticket_id: int|null}
     */
    protected function mandar_texto_al_sistema(Client $client, SupportTicket $ticket, string $texto): array
    {
        $no = ['enviado' => false, 'motivo' => 'ticket_no_disponible', 'whatsapp_message_id' => null, 'support_ticket_id' => null];

        if (! $this->pruebas->es_cliente_en_prueba($client) || (int) $client->user_id < 1
            || (int) $ticket->client_user_id !== (int) $client->user_id) {
            return $no;
        }

        $admin   = $this->remitente();
        $mensaje = SupportMessage::create([
            'support_ticket_id'      => (int) $ticket->id,
            'sender_type'            => 'admin',
            'sender_admin_id'        => $admin !== null ? (int) $admin->id : null,
            'kind'                   => 'text',
            'body'                   => $texto,
            'delivered_at'           => now(),
            'is_trial_import_notice' => true,
        ]);

        $sincronizado = false;
        try {
            $sincronizado = app(SupportClientSyncService::class)->sync_message_to_client($mensaje);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no se pudo sincronizar el aviso al sistema del negocio.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
        }

        if (! $sincronizado) {
            $mensaje->delete();

            return ['enviado' => false, 'motivo' => 'El sistema del negocio no confirmó el mensaje.', 'whatsapp_message_id' => null, 'support_ticket_id' => (int) $ticket->id];
        }

        try {
            event(new SupportMessageReceived((int) $mensaje->id));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaWhatsapp: no se pudo emitir el evento del mensaje.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
        }

        return ['enviado' => true, 'motivo' => null, 'whatsapp_message_id' => null, 'support_ticket_id' => (int) $ticket->id];
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
     * La plantilla de un cliente de ENSAYO (12/11/2026): no se llama a Kapso ni a Meta. El cuerpo de la plantilla ya
     * resuelto con sus variables se deja en la conversación del sistema del dueño y el envío se registra con
     * `canal = sistema`, para que el resumen de la prueba y el reloj de ensayo sigan mostrando qué salió cada día.
     * No depende de que Meta haya aprobado la plantilla: no sale por Meta.
     *
     * @param Client      $client
     * @param Lead|null   $lead
     * @param string      $plantilla
     * @param string|null $hito
     * @param string|array<int, string>|null $numero_asistente Las variables `{{2}}`/`{{3}}`, como en WhatsApp.
     *
     * @return array{estado: string, detalle: string|null, whatsapp_message_id: null, support_ticket_id: int|null}
     */
    protected function plantilla_al_sistema_del_ensayo(Client $client, ?Lead $lead, string $plantilla, ?string $hito, $numero_asistente): array
    {
        $texto = $this->plantillas->cuerpo_renderizado($plantilla, $this->primer_nombre($client, $lead, ''), $numero_asistente);
        if ($hito !== null && strpos($plantilla, PruebaPlantillasService::PASO_PREFIJO) === 0) {
            $texto = self::paso_con_su_video($texto, $hito);
        }
        $envio = app(Prueba\PruebaEnsayoSinWhatsappService::class)->dejar($client, $texto);

        $this->registrar($client, $lead, $plantilla, $hito,
            $envio['enviado'] ? PruebaWhatsappEnvio::ESTADO_ENVIADO : PruebaWhatsappEnvio::ESTADO_FALLIDO,
            $envio['support_ticket_id'], $envio['support_message_id'], null, $envio['motivo'], PruebaWhatsappEnvio::CANAL_SISTEMA);

        if ($envio['enviado']) {
            /* Igual que en WhatsApp: si salió la bienvenida, se descarta la bienvenida anterior en cola. */
            PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->whereIn('plantilla', in_array($plantilla, PruebaPlantillasService::BIENVENIDAS, true) ? PruebaPlantillasService::BIENVENIDAS : [$plantilla])
                ->where('estado', PruebaWhatsappEnvio::ESTADO_EN_COLA)
                ->delete();
        }

        return ['estado' => $envio['enviado'] ? 'enviado' : 'fallido', 'detalle' => $envio['motivo'],
            'whatsapp_message_id' => null, 'support_ticket_id' => $envio['support_ticket_id']];
    }

    /**
     * El texto de un paso para la conversación del sistema (12/11/2026). La plantilla de WhatsApp es solo texto y
     * algunas prometen «si querés te paso un video»: por el sistema la promesa se saca y, si el paso tiene video, va
     * el MISMO que lleva el correo del paso (`PruebaCorreoService::video_del_paso()`, catálogo aprobado). La URL va
     * suelta al final de su línea, como la mandan Martín y el respaldo: el chat de Soporte del sistema la muestra
     * como reproductor (`Conversation.vue`, URL de `videos.comerciocity.store/landing-prueba/…mp4`).
     *
     * @param string $texto La plantilla ya resuelta.
     * @param string $hito
     *
     * @return string
     */
    public static function paso_con_su_video(string $texto, string $hito): string
    {
        $texto = PruebaPlantillasService::sin_promesa_de_video($texto);
        $video = Prueba\PruebaCorreoService::video_del_paso($hito);

        if ($video === null) {
            return $texto;
        }

        return $texto . "\n\n" . 'Te dejo un video de cómo se hace, «' . $video['titulo'] . '»: ' . $video['url'];
    }

    /**
     * Deja el registro del envío.
     *
     * @param string $canal Por dónde salió (`PruebaWhatsappEnvio::CANAL_*`); `sistema` solo para el cliente de ensayo.
     *
     * @return PruebaWhatsappEnvio
     */
    protected function registrar(Client $client, ?Lead $lead, string $plantilla, ?string $hito, string $estado, ?int $ticket_id, ?int $mensaje_id, ?string $wamid, ?string $detalle, string $canal = PruebaWhatsappEnvio::CANAL_WHATSAPP): PruebaWhatsappEnvio
    {
        return PruebaWhatsappEnvio::create([
            'client_id'           => (int) $client->id,
            'canal'               => $canal,
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
