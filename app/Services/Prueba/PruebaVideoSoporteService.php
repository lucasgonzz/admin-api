<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Los videos de la prueba gratuita en el ticket de Soporte (misión cruzada prueba-ayuda-unificada-en-soporte,
 * 10/11/2026). Lucas decidió que el recorrido de videos va en el ticket de Soporte: el admin pone el primero (el
 * 04, bienvenida y recorrido) sin correr Codex, y Martín manda los siguientes según los hitos.
 *
 * Dos cosas:
 *
 *  1. **`prueba.videos` en el contexto de Martín (C1).** Se registra como extensión de
 *     `PruebaContextoSoporteService` (sin tocar ese archivo) y suma la lista del catálogo aprobado
 *     (`PruebaVideoCatalogoService::videos()`) con `{codigo, titulo, url, hito, entregado_at}`. El hito es fijo
 *     ({@see self::HITOS}) y `entregado_at` sale del registro de envíos (`prueba_whatsapp_envios`, plantilla
 *     `video_<codigo>`). Clave opcional: un Martín viejo la ignora y sigue con `recursos`.
 *
 *  2. **El video 04 sin Codex (C2).** {@see self::entregar_bienvenida()} lo manda por el ticket «Prueba gratuita»
 *     con `PruebaWhatsappService::mandar_texto_por_ticket()`, una sola vez por cliente.
 *
 * 🔴 Por qué no hay un «mensaje solo para el sistema» para la ventana cerrada: se investigó y el ticket no lo
 * tiene. `support_messages` no tiene ninguna marca por mensaje que lo deje fuera de WhatsApp; lo único parecido es
 * `support_tickets.sistema_exclusivo_at`, que es del TICKET entero (lo pone el traspaso del lead desde su sistema)
 * y no de un mensaje. Un mensaje de admin sin wamid en un ticket de WhatsApp tampoco lo ve el sistema del cliente
 * (`InboundAgentesController::filtrar_mensajes_visibles()` pide wamid o `synced_to_client_at`). Así que vale la
 * regla del plan: con la ventana cerrada no sale nada, y el video sale apenas el lead escriba, ANTES de que
 * Martín tome el ticket (el webhook lo intenta al recibir el entrante y `SupportCodexService::claim()` lo vuelve
 * a intentar antes de armar el contexto). No se inventa una columna nueva.
 *
 * 🔴 El mensaje sale con `is_trial_import_notice` (lo pone `mandar_texto_por_ticket()` cuando recibe el ticket):
 * es la marca que ya existe para «aviso del equipo que queda en el hilo pero no tapa el entrante que espera
 * respuesta» (`SupportCodexService::last_real_message()`). Sin ella, el video mandado después de la pregunta del
 * lead dejaría el ticket como respondido y Martín no la contestaría nunca.
 *
 * Idempotente: el candado por cliente serializa el webhook, la toma de Martín y la bienvenida, y un envío
 * `enviado` (o `fallido`) de `video_04` corta todo reintento. Nunca se manda texto libre con la ventana cerrada:
 * esa guarda la hace `mandar_texto_por_ticket()`.
 */
class PruebaVideoSoporteService
{
    /** El hito de la prueba que acompaña cada video (fijo, del plan de la misión). */
    const HITOS = [
        '04' => 'ingreso',
        '05' => 'importo',
        '06' => 'articulo_creado',
        '07' => 'compra',
        '08' => 'venta',
        '09' => 'agente',
    ];

    /** El video que pone el admin sin Codex. */
    const CODIGO_BIENVENIDA = '04';

    /** Estado del envío reservado antes del HTTP (ver entregar_bienvenida()). Nunca se reintenta. */
    const ESTADO_ENVIANDO = 'enviando';

    /** Prefijo de `prueba_whatsapp_envios.plantilla` para los videos entregados por el ticket. */
    const PLANTILLA_PREFIJO = 'video_';

    /** @var bool Si la extensión ya quedó registrada en este proceso. */
    protected static $registrada = false;

    /**
     * Registra la extensión `videos` del bloque `prueba` (una vez por proceso; `$forzar` para los tests).
     *
     * @param bool $forzar
     *
     * @return void
     */
    public static function registrar(bool $forzar = false): void
    {
        if (self::$registrada && ! $forzar) {
            return;
        }

        PruebaContextoSoporteService::extender(function (array $base, Client $client, ?Lead $lead, ?TrialInstance $instancia) {
            return app(PruebaVideoSoporteService::class)->extension($client);
        });

        self::$registrada = true;
    }

    /**
     * `plantilla` del registro de envíos para un video.
     *
     * @param string $codigo
     *
     * @return string
     */
    public static function plantilla(string $codigo): string
    {
        return self::PLANTILLA_PREFIJO . $codigo;
    }

    /**
     * Lo que se le suma al bloque `prueba`. Nunca lanza: ante un error no suma la clave (Martín sigue con `recursos`).
     *
     * @param Client $client
     *
     * @return array<string, mixed>
     */
    public function extension(Client $client): array
    {
        try {
            return ['videos' => $this->videos_del_cliente($client)];
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaVideoSoporteService: no se pudieron armar los videos del contexto.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Los videos aprobados con su hito y cuándo se le entregaron a este cliente por el ticket (o null).
     *
     * @param Client $client
     *
     * @return array<int, array{codigo: string, titulo: string, url: string, hito: string|null, entregado_at: string|null}>
     */
    public function videos_del_cliente(Client $client): array
    {
        $entregas = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->where('plantilla', 'like', self::PLANTILLA_PREFIJO . '%')
            ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
            ->orderBy('id')
            ->get();

        $catalogo  = app(PruebaVideoCatalogoService::class)->videos();
        $salientes = $this->salientes_con_videos($client, $catalogo);

        $videos = [];
        foreach ($catalogo as $video) {
            $codigo  = (string) $video['codigo'];
            $url     = (string) $video['url'];
            $entrega = $entregas->first(function (PruebaWhatsappEnvio $envio) use ($codigo) {
                return $envio->plantilla === self::plantilla($codigo);
            });

            /* Lo primero que haya: el registro de envíos (video 04 del admin, respaldo) o un mensaje del equipo que
             * salió con la URL exacta (Martín la manda por su cuenta y nadie más lo registra). */
            $momentos = [];
            if ($entrega !== null && $entrega->enviado_at !== null) {
                $momentos[] = $entrega->enviado_at;
            }
            $mensaje = $salientes->first(function ($fila) use ($url) {
                return strpos((string) $fila->body, $url) !== false;
            });
            if ($mensaje !== null && $mensaje->created_at !== null) {
                $momentos[] = \Carbon\Carbon::parse($mensaje->created_at);
            }
            usort($momentos, function ($a, $b) {
                return $a->getTimestamp() - $b->getTimestamp();
            });

            $videos[] = [
                'codigo'       => $codigo,
                'titulo'       => (string) $video['titulo'],
                'url'          => $url,
                'hito'         => self::hito_del_video($codigo, $client),
                'entregado_at' => $momentos === [] ? null : $momentos[0]->toIso8601String(),
            ];
        }

        return $videos;
    }

    /**
     * El hito que acompaña a un video PARA ESTE CLIENTE. Dentro del alcance de las novedades (misión
     * prueba-e2e-campana-imperio, 11/11/2026) el 06 («Revisá y cambiá un precio») es el de actualizar un artículo,
     * no el de crearlo; fuera del alcance vale el mapa de siempre ({@see self::HITOS}).
     *
     * @param string $codigo
     * @param Client $client
     *
     * @return string|null
     */
    public static function hito_del_video(string $codigo, Client $client): ?string
    {
        if (PruebaSettings::novedades_para($client)) {
            $hito = array_search($codigo, PruebaCorreoService::VIDEO_DEL_PASO, true);
            if ($hito !== false) {
                return (string) $hito;
            }
        }

        return isset(self::HITOS[$codigo]) ? self::HITOS[$codigo] : null;
    }

    /**
     * Los mensajes del equipo que SALIERON (con wamid de WhatsApp o sincronizados al sistema) en los tickets del
     * cliente y que contienen la URL de algún video del catálogo, del más viejo al más nuevo. Sin borradores.
     *
     * @param Client                            $client
     * @param array<string, array<string, mixed>> $catalogo
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function salientes_con_videos(Client $client, array $catalogo)
    {
        if ($catalogo === []) {
            return collect();
        }

        $query = \Illuminate\Support\Facades\DB::table('support_messages')
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->where('support_tickets.client_id', (int) $client->id)
            ->where('support_messages.sender_type', 'admin')
            ->where(function ($salio) {
                $salio->whereNotNull('support_messages.whatsapp_message_id')->orWhereNotNull('support_messages.synced_to_client_at');
            })
            ->where(function ($con_url) use ($catalogo) {
                foreach ($catalogo as $video) {
                    $con_url->orWhere('support_messages.body', 'like', '%' . addcslashes((string) $video['url'], '%_\\') . '%');
                }
            });
        \App\Models\SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');

        return $query->orderBy('support_messages.id')->get(['support_messages.body', 'support_messages.created_at']);
    }

    /**
     * El texto del video de bienvenida: una línea con el título, la URL y una invitación a preguntar.
     *
     * @param array<string, mixed> $video Fila de `PruebaVideoCatalogoService::videos()`.
     *
     * @return string
     */
    public function texto_del_video(array $video): string
    {
        return 'Te dejo el primer video de tu prueba, «' . $video['titulo'] . '»: ' . $video['url']
            . "\nCualquier duda de cómo se usa algo, preguntanos por acá.";
    }

    /**
     * Manda el video 04 por el ticket «Prueba gratuita» del dueño, una sola vez. Lo llaman la bienvenida (si la
     * ventana ya está abierta), el webhook al recibir un entrante y `claim()` antes de entregarle el ticket a Martín.
     *
     * @param Client   $client
     * @param int|null $ticket_id El ticket de WhatsApp del dueño; null = el abierto del dueño, si hay.
     *
     * @return array{estado: string, detalle: string|null}
     *   `estado`: `enviado`, `ya_entregado`, `en_curso` (otro proceso lo está mandando), `ventana_cerrada`,
     *   `sin_video`, `sin_ticket`, `no_es_prueba` o `fallido`.
     */
    public function entregar_bienvenida(Client $client, ?int $ticket_id = null): array
    {
        if (! app(PruebaDelClienteService::class)->es_cliente_en_prueba($client)
            || (string) $client->estado !== PruebaCicloService::CLIENTE_PRUEBA) {
            return ['estado' => 'no_es_prueba', 'detalle' => null];
        }

        if ($this->ya_resuelto($client)) {
            return ['estado' => 'ya_entregado', 'detalle' => null];
        }

        $catalogo = app(PruebaVideoCatalogoService::class)->videos();
        $clave    = 'prueba:' . self::CODIGO_BIENVENIDA;
        if (! isset($catalogo[$clave])) {
            return ['estado' => 'sin_video', 'detalle' => 'El video 04 no está configurado con la URL aprobada.'];
        }

        /* Cliente de ensayo (12/11/2026): el video va a la conversación del sistema, sin WhatsApp ni ventana de 24 h. */
        $ensayo = PruebaEnsayoSinWhatsappService::es_ensayo($client);
        $ticket = $ensayo ? app(PruebaEnsayoSinWhatsappService::class)->conversacion_del_sistema($client) : $this->ticket_del_dueno($client, $ticket_id);
        if ($ticket === null) {
            return ['estado' => 'sin_ticket', 'detalle' => 'No hay un ticket de WhatsApp abierto del dueño.'];
        }

        $reserva = Cache::lock('prueba-video-04-' . $client->id, 60);
        if (! $reserva->get()) {
            return ['estado' => 'en_curso', 'detalle' => null];
        }

        try {
            /* Adentro del candado otra vez: el otro proceso pudo terminar entre la primera mirada y la reserva. */
            if ($this->ya_resuelto($client)) {
                return ['estado' => 'ya_entregado', 'detalle' => null];
            }

            if (! $ensayo && ! app(\App\Services\WhatsappSessionWindowService::class)->is_open((string) $ticket->whatsapp_phone)) {
                return ['estado' => 'ventana_cerrada', 'detalle' => null];
            }

            /* 🔴 El registro va ANTES del HTTP, en `enviando`, y fuera de cualquier transacción de quien llama (la toma
             * de Martín lo llama antes de abrir la suya): si algo falla después de que el WhatsApp salió, la fila
             * sigue ahí y corta todo reintento. Un `enviando` que quedó colgado (el proceso murió en el medio) no se
             * reintenta nunca: puede haber salido, y Martín ve `entregado_at` en null y decide él. */
            $registro = PruebaWhatsappEnvio::create([
                'client_id'         => (int) $client->id,
                'lead_id'           => optional(app(PruebaDelClienteService::class)->lead_del_cliente($client))->id,
                'plantilla'         => self::plantilla(self::CODIGO_BIENVENIDA),
                'hito'              => self::HITOS[self::CODIGO_BIENVENIDA],
                'estado'            => self::ESTADO_ENVIANDO,
                'canal'             => $ensayo ? PruebaWhatsappEnvio::CANAL_SISTEMA : PruebaWhatsappEnvio::CANAL_WHATSAPP,
                'support_ticket_id' => (int) $ticket->id,
                'dia'               => PruebaWhatsappService::hoy(),
            ]);

            $envio = app(PruebaWhatsappService::class)->mandar_texto_por_ticket($client, $this->texto_del_video($catalogo[$clave]), (int) $ticket->id);

            if ($envio['support_ticket_id'] === null) {
                /* No se creó ningún mensaje ni salió nada (la ventana se cerró en el medio, sin contacto, ticket que
                 * ya no sirve): se borra la reserva y se reintenta en el próximo entrante. */
                $registro->delete();

                return ['estado' => $envio['motivo'] === 'ventana_cerrada' ? 'ventana_cerrada' : 'sin_ticket', 'detalle' => $envio['motivo']];
            }

            $mensaje_id = \App\Models\SupportMessage::where('support_ticket_id', (int) $envio['support_ticket_id'])
                ->where('sender_type', 'admin')->where('is_trial_import_notice', true)->orderByDesc('id')->value('id');

            /* Un fallido también corta los reintentos: el mensaje ya quedó en el hilo (sin entregar) y Martín ve
             * `entregado_at` en null y lo manda él. Reintentar en cada entrante llenaría el hilo de copias. */
            $registro->estado              = $envio['enviado'] ? PruebaWhatsappEnvio::ESTADO_ENVIADO : PruebaWhatsappEnvio::ESTADO_FALLIDO;
            $registro->support_message_id  = $mensaje_id !== null ? (int) $mensaje_id : null;
            $registro->whatsapp_message_id = $envio['whatsapp_message_id'];
            $registro->detalle             = $envio['enviado'] ? null : mb_substr((string) $envio['motivo'], 0, 1000);
            $registro->enviado_at          = $envio['enviado'] ? now() : null;
            $registro->save();

            return ['estado' => $envio['enviado'] ? 'enviado' : 'fallido', 'detalle' => $envio['motivo']];
        } finally {
            $reserva->release();
        }
    }

    /**
     * Si el video 04 ya salió, se intentó y falló, o quedó `enviando` (puede haber salido) para este cliente.
     *
     * @param Client $client
     *
     * @return bool
     */
    protected function ya_resuelto(Client $client): bool
    {
        return PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->where('plantilla', self::plantilla(self::CODIGO_BIENVENIDA))
            ->whereIn('estado', [PruebaWhatsappEnvio::ESTADO_ENVIADO, PruebaWhatsappEnvio::ESTADO_FALLIDO, self::ESTADO_ENVIANDO])
            ->exists();
    }

    /**
     * El ticket de WhatsApp abierto del DUEÑO (sin empleado) donde va el video.
     *
     * @param Client   $client
     * @param int|null $ticket_id
     *
     * @return SupportTicket|null
     */
    protected function ticket_del_dueno(Client $client, ?int $ticket_id): ?SupportTicket
    {
        $query = SupportTicket::where('client_id', (int) $client->id)
            ->where('source', 'whatsapp')
            ->whereNull('client_employee_id')
            ->where('status', SupportTicket::STATUS_ABIERTO);

        if ($ticket_id !== null) {
            $query->whereKey($ticket_id);
        }

        return $query->orderByDesc('id')->first();
    }
}
