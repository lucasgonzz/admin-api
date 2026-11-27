<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\DemoChatConversacion;
use App\Models\DemoChatMensaje;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\PendingApprovalNotification;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Push «hay un mensaje esperando tu aprobación» para CUALQUIER mensaje que espere el ok de una persona
 * (misión push-mensajes-por-aprobar, pedido de Lucas del 26/11/2026: «que la app me envíe una push notification
 * cuando haya cualquier tipo de mensaje esperando mi verificación»).
 *
 * 🔴 POR QUÉ UN CONCILIADOR SOBRE EL ESTADO Y NO UN OBSERVER. Antes de esta misión cada camino que dejaba un mensaje
 * por aprobar decidía por su cuenta si avisaba, y los que nacieron después (compromisos, retención posterior, closer,
 * soporte con Claude, chat de la demo) no avisaban. Un observer de modelo tampoco alcanza: la retención posterior
 * (`LeadAiSuggestionAutoSendScheduler`) y el toggle «verificar mensajes» de soporte cambian el estado con un `->update()`
 * por query builder, que no dispara eventos de Eloquent. Acá se define UNA vez qué es «un mensaje esperando
 * verificación» ({@see self::pending_query()}) y el comando `pending-approvals:notify` (cada minuto) busca esos mensajes
 * en las tres tablas, los registra en `pending_approval_notifications` y manda un aviso por mensaje.
 *
 * Los dos servicios que ya avisaban en el momento ({@see LeadVerificacionWhatsappService},
 * {@see LeadVerificacionAgendamientoNotificationService}) siguen avisando al instante, pero ANTES reclaman el mismo
 * registro con {@see self::claim_direct()}, así el conciliador no repite y el Caso A (segunda llamada sobre el mismo
 * mensaje) tampoco.
 *
 * Reglas de no-spam: un aviso por mensaje (`source_key` único + reserva con `lockForUpdate`) y, dentro de una misma
 * conversación, un aviso nuevo se AGRUPA (sin push) si hace menos de 10 minutos que se avisó otro de esa conversación
 * cuyo mensaje sigue esperando o ya no existe.
 *
 * Ningún método público tira hacia el llamador: todo `\Throwable` se loguea y se traga, para que un fallo del aviso
 * nunca rompa el flujo que lo invoca.
 */
class PendingApprovalPushService
{
    /** Tipo de origen: mensaje de lead (`lead_messages`). */
    const TYPE_LEAD = 'lead_message';

    /** Tipo de origen: mensaje de soporte (`support_messages`). */
    const TYPE_SUPPORT = 'support_message';

    /** Tipo de origen: mensaje del chat de la demo (`demo_chat_mensajes`). */
    const TYPE_DEMO = 'demo_chat_message';

    /** Tabla del registro de avisos. */
    const TABLE = 'pending_approval_notifications';

    /** Segundos que un mensaje debe tener de vida antes de tomarlo (deja terminar el request que lo creó). */
    const SETTLE_SECONDS = 20;

    /** Sólo se descubren mensajes nacidos en los últimos N minutos (al desplegar no se avisa el histórico). */
    const DISCOVERY_WINDOW_MINUTES = 30;

    /** Ventana de agrupación por conversación, en minutos. */
    const GROUP_WINDOW_MINUTES = 10;

    /** Intentos fallidos de envío antes de rendirse (`failed`). */
    const MAX_ATTEMPTS = 3;

    /** Minutos que dura la reserva de una fila mientras un proceso la atiende. */
    const LEASE_MINUTES = 2;

    /** Largo máximo del cuerpo del push. */
    const PREVIEW_LENGTH = 140;

    /** Máximo de filas que procesa cada vuelta del conciliador. */
    const MAX_ROWS_PER_RUN = 100;

    /** Máximo de mensajes nuevos que se registran por tipo y por vuelta. */
    const DISCOVERY_BATCH = 200;

    /** Texto del cuerpo cuando el mensaje no tiene texto. */
    const EMPTY_BODY = 'Hay un mensaje esperando tu aprobación.';

    /** @var array<int, string> Valores de `system_message_kind` que esperan a una persona aunque no pidan verificación. */
    const MANUAL_KINDS = ['closer_followup_suggestion'];

    /** @var bool|null Memoria de {@see self::table_ready()} por instancia (null = todavía no se consultó). */
    private $table_ready_memo = null;

    // ------------------------------------------------------------------------------------------
    // Criterio único de «esperando verificación»
    // ------------------------------------------------------------------------------------------

    /**
     * Clase de modelo de cada tipo de origen.
     *
     * @param string $type Uno de los `TYPE_*`.
     *
     * @return string Nombre completo de la clase Eloquent.
     *
     * @throws \InvalidArgumentException Si el tipo no existe (sólo por un error de programación).
     */
    private static function model_class(string $type): string
    {
        if ($type === self::TYPE_LEAD) {
            return LeadMessage::class;
        }
        if ($type === self::TYPE_SUPPORT) {
            return SupportMessage::class;
        }
        if ($type === self::TYPE_DEMO) {
            return DemoChatMensaje::class;
        }

        throw new \InvalidArgumentException('Tipo de mensaje por aprobar desconocido: ' . $type);
    }

    /**
     * EL criterio único de «mensaje esperando verificación» (parte que se expresa en SQL), por tipo:
     *
     *  - Lead: `sender = sistema`, `status = sugerido`, no es evento de estado ni error, y pide verificación o es de un
     *    `system_message_kind` manual (seguimiento del closer).
     *  - Soporte: borrador de la IA (`is_ai_suggestion_draft`) sin cuenta regresiva (`ai_auto_send_at` nulo) y sin una
     *    operación de Codex/Martín ligada (ésa la avisa `SupportAttentionNotificationService`).
     *  - Chat de la demo: respuesta del asistente en `borrador`.
     *
     * Lo que depende del ticket o de la conversación (ensayo, Codex, lead existente) lo completa
     * {@see self::context_for()}.
     *
     * @param string $type Uno de los `TYPE_*`.
     *
     * @return \Illuminate\Database\Eloquent\Builder Consulta de los mensajes pendientes de ese tipo.
     *
     * @throws \InvalidArgumentException Si el tipo no existe.
     */
    public static function pending_query(string $type)
    {
        if ($type === self::TYPE_LEAD) {
            return LeadMessage::query()
                ->where('sender', 'sistema')
                ->where('status', 'sugerido')
                ->where(function ($query) {
                    $query->where('is_status_event', false)->orWhereNull('is_status_event');
                })
                ->where(function ($query) {
                    $query->where('is_error', false)->orWhereNull('is_error');
                })
                ->where(function ($query) {
                    $query->where('requiere_verificacion', true)
                        ->orWhereIn('system_message_kind', self::MANUAL_KINDS);
                });
        }

        if ($type === self::TYPE_SUPPORT) {
            return SupportMessage::query()
                ->where('is_ai_suggestion_draft', true)
                ->whereNull('ai_auto_send_at')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('support_codex_operations')
                        ->whereColumn('support_codex_operations.message_id', 'support_messages.id');
                });
        }

        if ($type === self::TYPE_DEMO) {
            return DemoChatMensaje::query()
                ->where('estado', 'borrador')
                ->where('rol', 'asistente');
        }

        throw new \InvalidArgumentException('Tipo de mensaje por aprobar desconocido: ' . $type);
    }

    /**
     * Completa el criterio con lo que depende de la conversación y devuelve su contexto, o null si el mensaje NO es uno
     * que espere a una persona (ticket inexistente, de ensayo o manejado por Codex; lead inexistente).
     *
     * @param string                                   $type    Uno de los `TYPE_*`.
     * @param LeadMessage|SupportMessage|DemoChatMensaje $message Mensaje que ya cumple {@see self::pending_query()}.
     *
     * @return array<string, mixed>|null `['group_key' => string, 'lead' => Lead|null, 'ticket' => SupportTicket|null]`.
     */
    private function context_for(string $type, $message): ?array
    {
        if ($type === self::TYPE_LEAD) {
            $lead = Lead::find($message->lead_id);
            if ($lead === null) {
                return null;
            }

            return ['group_key' => 'lead:' . $lead->id, 'lead' => $lead, 'ticket' => null];
        }

        if ($type === self::TYPE_SUPPORT) {
            $ticket = SupportTicket::find($message->support_ticket_id);
            if ($ticket === null || $ticket->es_de_ensayo() || SupportCodexSettings::manages($ticket)) {
                return null;
            }

            return ['group_key' => 'ticket:' . $ticket->id, 'lead' => null, 'ticket' => $ticket];
        }

        /* Chat de la demo: la conversación es del lead (o del lead de prueba) y sin lead no hay a quién avisar. */
        $conversation = DemoChatConversacion::find($message->conversacion_id);
        if ($conversation === null) {
            return null;
        }
        $lead_id = $conversation->lead_id !== null ? $conversation->lead_id : $conversation->prueba_lead_id;
        $lead    = $lead_id !== null ? Lead::find($lead_id) : null;
        if ($lead === null) {
            return null;
        }

        return ['group_key' => 'demo_chat:' . $lead->id, 'lead' => $lead, 'ticket' => null];
    }

    // ------------------------------------------------------------------------------------------
    // Seams (los tests los sustituyen)
    // ------------------------------------------------------------------------------------------

    /**
     * Envío efectivo del push a un admin. Aislado para que los tests lo sustituyan: el envío real necesita las claves
     * VAPID y salir a la red del push service de Apple o Google.
     *
     * @param int                  $admin_id        Admin destinatario.
     * @param string               $title           Título.
     * @param string               $body            Cuerpo.
     * @param array<string, mixed> $data            Datos extra (van en el primer nivel del payload).
     * @param array<int, string>   $accepted_hashes Hashes de endpoints que ya aceptaron este aviso (no se repiten).
     *
     * @return array<string, mixed> `accepted_endpoint_hashes`, `failed_endpoint_hashes` y `complete`.
     */
    protected function send_push(int $admin_id, string $title, string $body, array $data, array $accepted_hashes): array
    {
        return AdminPushNotificationService::send_to_admin_with_result($admin_id, $title, $body, $data, $accepted_hashes);
    }

    /**
     * Indica si la tabla del registro existe (código desplegado antes que la migración => false). Memorizado por
     * instancia. Aislado para poder simular la ausencia de la tabla sin tocar el esquema.
     *
     * @return bool
     */
    protected function table_ready(): bool
    {
        if ($this->table_ready_memo === null) {
            $this->table_ready_memo = Schema::hasTable(self::TABLE);
        }

        return $this->table_ready_memo;
    }

    // ------------------------------------------------------------------------------------------
    // Conciliador (comando por minuto)
    // ------------------------------------------------------------------------------------------

    /**
     * Una vuelta del conciliador: descubre mensajes nuevos y procesa las filas pendientes. Es lo que corre el comando
     * `pending-approvals:notify`.
     *
     * @return array<string, int> Contadores: `discovered` y la cantidad de filas que terminaron en cada estado.
     */
    public function run(): array
    {
        $counters = [
            'discovered' => 0, 'sent' => 0, 'grouped' => 0, 'resolved' => 0,
            'no_recipients' => 0, 'failed' => 0, 'pending' => 0,
        ];

        try {
            /* Código desplegado antes de la migración: no se toca nada. */
            if (! $this->table_ready()) {
                $this->log('warning', 'PendingApprovalPushService: falta la tabla ' . self::TABLE . ', no se avisa nada.');

                return $counters;
            }

            $counters['discovered'] = $this->discover();

            $now = $this->now();

            /* Filas a atender: pendientes, con intentos disponibles y sin una reserva vigente de otro proceso. */
            $ids = PendingApprovalNotification::query()
                ->where('status', 'pending')
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($query) use ($now) {
                    $query->whereNull('processing_until')->orWhere('processing_until', '<=', $now->format('Y-m-d H:i:s'));
                })
                ->orderBy('id')
                ->limit(self::MAX_ROWS_PER_RUN)
                ->pluck('id');

            foreach ($ids as $id) {
                /* Cada fila en su propio try/catch (dentro de process): una falla no corta la vuelta. */
                $result = $this->process((int) $id);
                if (isset($counters[$result])) {
                    $counters[$result]++;
                }
            }
        } catch (\Throwable $e) {
            $this->log('error', 'PendingApprovalPushService: falló la vuelta del conciliador.', ['error' => $e->getMessage()]);
        }

        return $counters;
    }

    /**
     * Registra (estado `pending`, canal `poller`) los mensajes que esperan aprobación, nacieron en los últimos 30 minutos,
     * ya pasaron el asentamiento de 20 segundos y todavía no tienen fila. Usa `created_at` y no `updated_at` para que
     * editar un borrador viejo no dispare un aviso.
     *
     * @return int Cantidad de filas nuevas.
     */
    public function discover(): int
    {
        $created = 0;

        try {
            if (! $this->table_ready()) {
                return 0;
            }

            $now   = $this->now();
            $since = $now->copy()->subMinutes(self::DISCOVERY_WINDOW_MINUTES)->format('Y-m-d H:i:s');
            $until = $now->copy()->subSeconds(self::SETTLE_SECONDS)->format('Y-m-d H:i:s');

            foreach ([self::TYPE_LEAD, self::TYPE_SUPPORT, self::TYPE_DEMO] as $type) {
                try {
                    $class = self::model_class($type);
                    $table = (new $class())->getTable();

                    $candidates = self::pending_query($type)
                        ->where($table . '.created_at', '>=', $since)
                        ->where($table . '.created_at', '<=', $until)
                        ->whereNotExists(function ($query) use ($type, $table) {
                            $query->selectRaw('1')
                                ->from(self::TABLE)
                                ->where(self::TABLE . '.source_type', $type)
                                ->whereColumn(self::TABLE . '.source_id', $table . '.id');
                        })
                        ->orderBy($table . '.id')
                        ->limit(self::DISCOVERY_BATCH)
                        ->get();

                    foreach ($candidates as $message) {
                        try {
                            $context = $this->context_for($type, $message);
                            if ($context === null) {
                                continue;
                            }

                            $created += DB::table(self::TABLE)->insertOrIgnore([
                                $this->new_row_values($type, (int) $message->id, (string) $context['group_key'], 'poller'),
                            ]);
                        } catch (\Throwable $e) {
                            $this->log('error', 'PendingApprovalPushService: no se pudo registrar un mensaje.', [
                                'type' => $type, 'message_id' => $message->id, 'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->log('error', 'PendingApprovalPushService: falló el descubrimiento de un tipo.', [
                        'type' => $type, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->log('error', 'PendingApprovalPushService: falló el descubrimiento.', ['error' => $e->getMessage()]);
        }

        return $created;
    }

    /**
     * Atiende una fila `pending`: la reserva, recarga el mensaje y decide si queda resuelta, se agrupa, no tiene
     * destinatarios o se avisa por push.
     *
     * @param int $id Id de la fila de `pending_approval_notifications`.
     *
     * @return string Estado en que quedó la fila (`sent`, `grouped`, `resolved`, `no_recipients`, `failed` o `pending`);
     *                `pending` también si no se pudo reservar o se difirió.
     */
    public function process(int $id): string
    {
        /* @var PendingApprovalNotification|null $row Fila reservada por este proceso. */
        $row = null;

        try {
            $now = $this->now();

            /* Reserva atómica: dos procesos no atienden la misma fila a la vez. */
            $row = DB::transaction(function () use ($id, $now) {
                $locked = PendingApprovalNotification::query()->whereKey($id)->lockForUpdate()->first();

                if ($locked === null
                    || $locked->status !== 'pending'
                    || (int) $locked->attempts >= self::MAX_ATTEMPTS
                    || ($locked->processing_until !== null && $locked->processing_until->gt($now))) {
                    return null;
                }

                $locked->processing_until = $now->copy()->addMinutes(self::LEASE_MINUTES);
                $locked->save();

                return $locked;
            });

            if ($row === null) {
                return 'pending';
            }

            $type = (string) $row->source_type;

            /* Recarga el mensaje: si ya no existe o ya no espera a nadie, no hay nada que avisar. */
            $message = self::pending_query($type)->whereKey((int) $row->source_id)->first();
            $context = $message !== null ? $this->context_for($type, $message) : null;
            if ($message === null || $context === null) {
                return $this->finish($row, 'resolved');
            }

            /* Un mensaje de lead que se está enviando en este instante todavía no se sabe si queda pendiente. */
            if ($type === self::TYPE_LEAD && app(LeadSuggestionEnvioEnCurso::class)->esta_en_curso((int) $message->id)) {
                $row->processing_until = null;
                $row->save();

                return 'pending';
            }

            /* Agrupación por conversación: ya se avisó hace poco algo de esta conversación que sigue esperando. */
            $anchor = $this->find_group_anchor($row);
            if ($anchor !== null) {
                $row->grouped_into_id = (int) $anchor->id;

                return $this->finish($row, 'grouped');
            }

            $recipients = $this->recipients_for($type, $context);
            $already    = array_map('intval', (array) ($row->admin_ids ?: []));
            if (empty($recipients) && empty($already)) {
                $this->log('warning', 'PendingApprovalPushService: mensaje por aprobar sin destinatarios con device.', [
                    'source_key' => $row->source_key,
                ]);

                return $this->finish($row, 'no_recipients');
            }

            $content = $this->build_content($type, $message, $context);

            return $this->deliver($row, $recipients, $already, $content);
        } catch (\Throwable $e) {
            $this->log('error', 'PendingApprovalPushService: falló el procesamiento de una fila.', [
                'row_id' => $id, 'error' => $e->getMessage(),
            ]);

            return $this->register_failure($row);
        }
    }

    /**
     * Manda el push a cada destinatario que todavía no lo tiene y deja la fila en `sent`, o cuenta un intento fallido.
     *
     * @param PendingApprovalNotification $row        Fila reservada.
     * @param array<int, int>             $recipients Admins con device.
     * @param array<int, int>             $already    Admins que ya recibieron el aviso en un intento anterior.
     * @param array<string, mixed>        $content    `title`, `body`, `data`.
     *
     * @return string Estado en que quedó la fila.
     */
    private function deliver(PendingApprovalNotification $row, array $recipients, array $already, array $content): string
    {
        /* Hashes de devices que ya aceptaron el aviso (de intentos anteriores): no se les repite. */
        $accepted = (array) ($row->accepted_endpoint_hashes ?: []);

        /* Admins con el aviso completo (los de intentos anteriores más los de este). */
        $completed = $already;

        /* Se vuelve true si algún destinatario no quedó completo. */
        $incomplete = false;

        foreach ($recipients as $admin_id) {
            if (in_array($admin_id, $already, true)) {
                continue;
            }

            try {
                $result   = $this->send_push($admin_id, $content['title'], $content['body'], $content['data'], $accepted);
                $accepted = array_values(array_unique(array_merge($accepted, (array) ($result['accepted_endpoint_hashes'] ?? []))));

                if (! empty($result['complete'])) {
                    $completed[] = $admin_id;
                } else {
                    $incomplete = true;
                }
            } catch (\Throwable $e) {
                $incomplete = true;
                $this->log('error', 'PendingApprovalPushService: error al enviar el push.', [
                    'source_key' => $row->source_key, 'admin_id' => $admin_id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $row->admin_ids                = array_values(array_unique($completed));
        $row->accepted_endpoint_hashes = $accepted;

        if ($incomplete) {
            return $this->register_failure($row);
        }

        $row->notified_at = $this->now();

        return $this->finish($row, 'sent');
    }

    /**
     * Cuenta un intento fallido: libera la reserva y la fila sigue `pending`; al tercero queda `failed`.
     *
     * @param PendingApprovalNotification|null $row Fila reservada (null si todavía no se había reservado).
     *
     * @return string `pending` o `failed`.
     */
    private function register_failure(?PendingApprovalNotification $row): string
    {
        if ($row === null) {
            return 'pending';
        }

        try {
            $row->attempts         = (int) $row->attempts + 1;
            $row->processing_until = null;

            if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
                $row->status = 'failed';
                $this->log('error', 'PendingApprovalPushService: el aviso agotó los intentos.', ['source_key' => $row->source_key]);
            }

            $row->save();

            return (string) $row->status;
        } catch (\Throwable $e) {
            $this->log('error', 'PendingApprovalPushService: no se pudo contar el intento fallido.', ['error' => $e->getMessage()]);

            return 'pending';
        }
    }

    /**
     * Cierra una fila con el estado dado y suelta la reserva.
     *
     * @param PendingApprovalNotification $row    Fila reservada.
     * @param string                      $status Estado final.
     *
     * @return string El mismo estado.
     */
    private function finish(PendingApprovalNotification $row, string $status): string
    {
        $row->status           = $status;
        $row->processing_until = null;
        $row->save();

        return $status;
    }

    // ------------------------------------------------------------------------------------------
    // Camino directo (servicios de verificación que ya avisaban en el momento)
    // ------------------------------------------------------------------------------------------

    /**
     * Reclama el aviso único de un mensaje de lead ANTES de que un servicio de verificación mande su push.
     *
     * Devuelve el contenido a mandar (`notification_id`, `title`, `body`, `data`), o null si NO hay que mandar (ya se
     * avisó, otro proceso lo está atendiendo o quedó agrupado en un aviso reciente de la misma conversación). Si la tabla
     * no existe o algo tira, falla ABIERTO: devuelve el contenido con `notification_id = null` para que el servicio
     * mande su push como antes de esta misión.
     *
     * @param LeadMessage $message Mensaje sugerido que espera aprobación.
     *
     * @return array<string, mixed>|null
     */
    public function claim_direct(LeadMessage $message): ?array
    {
        $lead    = null;
        $content = null;

        try {
            $lead    = Lead::find($message->lead_id);
            $context = ['group_key' => 'lead:' . $message->lead_id, 'lead' => $lead, 'ticket' => null];
            $content = $this->build_content(self::TYPE_LEAD, $message, $context);

            if (! $this->table_ready()) {
                return array_merge(['notification_id' => null], $content);
            }

            $now = $this->now();

            /* Reclamo atómico: crea la fila si no existe y la reserva si sigue pendiente. */
            $claimed = DB::transaction(function () use ($message, $context, $now) {
                DB::table(self::TABLE)->insertOrIgnore([
                    $this->new_row_values(self::TYPE_LEAD, (int) $message->id, (string) $context['group_key'], 'direct'),
                ]);

                $row = PendingApprovalNotification::query()
                    ->where('source_key', self::TYPE_LEAD . ':' . $message->id)
                    ->lockForUpdate()
                    ->first();

                if ($row === null
                    || $row->status !== 'pending'
                    || ($row->processing_until !== null && $row->processing_until->gt($now))) {
                    return null;
                }

                /* Hace poco se avisó algo de esta conversación que sigue esperando: éste se agrupa y no suena. */
                $anchor = $this->find_group_anchor($row);
                if ($anchor !== null) {
                    $row->status           = 'grouped';
                    $row->grouped_into_id  = (int) $anchor->id;
                    $row->processing_until = null;
                    $row->save();

                    return null;
                }

                $row->channel          = 'direct';
                $row->processing_until = $now->copy()->addMinutes(self::LEASE_MINUTES);
                $row->save();

                return $row;
            });

            if ($claimed === null) {
                return null;
            }

            return array_merge(['notification_id' => (int) $claimed->id], $content);
        } catch (\Throwable $e) {
            $this->log('error', 'PendingApprovalPushService: falló el reclamo directo, se avisa como antes.', [
                'message_id' => $message->id, 'error' => $e->getMessage(),
            ]);

            if ($content === null) {
                $content = [
                    'title' => 'Para aprobar',
                    'body'  => self::EMPTY_BODY,
                    'data'  => ['url' => '/leads?lead_id=' . $message->lead_id],
                ];
            }

            return array_merge(['notification_id' => null], $content);
        }
    }

    /**
     * Confirma que el servicio directo mandó el aviso: la fila queda `sent` (o `no_recipients` si no le llegó a nadie).
     * Con `null` (código sin tabla) no hace nada.
     *
     * @param int|null         $notification_id Id devuelto por {@see self::claim_direct()}.
     * @param array<int, int>  $admin_ids       Admins a los que el servicio les mandó el push.
     *
     * @return void
     */
    public function confirm_direct(?int $notification_id, array $admin_ids): void
    {
        if ($notification_id === null) {
            return;
        }

        try {
            $row = PendingApprovalNotification::find($notification_id);
            if ($row === null) {
                return;
            }

            $row->admin_ids        = array_values(array_unique(array_map('intval', $admin_ids)));
            $row->status           = empty($admin_ids) ? 'no_recipients' : 'sent';
            $row->notified_at      = empty($admin_ids) ? null : $this->now();
            $row->processing_until = null;
            $row->save();
        } catch (\Throwable $e) {
            $this->log('error', 'PendingApprovalPushService: no se pudo confirmar el aviso directo.', [
                'notification_id' => $notification_id, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Agrupación, destinatarios y contenido
    // ------------------------------------------------------------------------------------------

    /**
     * Busca el aviso reciente de la misma conversación al que se puede agrupar esta fila: una fila `sent` de los últimos
     * 10 minutos cuyo mensaje sigue esperando o ya no existe (lo reemplazó el sistema; en soporte un ancla borrada NO sirve porque también
     * la borra una persona). Si ese mensaje ya lo resolvió
     * alguien (aprobado, enviado, rechazado, publicado) NO sirve: hay algo nuevo que Lucas no vio.
     *
     * @param PendingApprovalNotification $row Fila a decidir.
     *
     * @return PendingApprovalNotification|null La fila ancla, o null si corresponde avisar.
     */
    private function find_group_anchor(PendingApprovalNotification $row): ?PendingApprovalNotification
    {
        if ((string) $row->group_key === '') {
            return null;
        }

        $since = $this->now()->copy()->subMinutes(self::GROUP_WINDOW_MINUTES)->format('Y-m-d H:i:s');

        $candidates = PendingApprovalNotification::query()
            ->where('group_key', $row->group_key)
            ->where('status', 'sent')
            ->where('id', '!=', $row->id)
            ->where('notified_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $class = self::model_class((string) $candidate->source_type);

            /* El mensaje del aviso anterior ya no existe. En leads y chat de la demo sólo lo borra el sistema al reemplazar
             * la sugerencia (descartar marca 'rechazado'/'descartado', no borra), así que el aviso nuevo se agrupa. En
             * soporte también lo borra una persona (descartar el borrador o contestar a mano): un ancla borrada no sirve,
             * el aviso nuevo es algo que Lucas no vio y avisa. */
            if (! $class::query()->whereKey((int) $candidate->source_id)->exists()) {
                if ((string) $candidate->source_type === self::TYPE_SUPPORT) {
                    continue;
                }

                return $candidate;
            }

            /* Sigue esperando: el aviso nuevo se agrupa. Si ya lo resolvieron, no. */
            if (self::pending_query((string) $candidate->source_type)->whereKey((int) $candidate->source_id)->exists()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Admins que reciben el aviso y que tienen al menos un device de push registrado.
     *
     *  - Lead y chat de la demo: setters y campanita del lead (con la red de último recurso a todos los admins).
     *  - Soporte: dueño por defecto de soporte, quienes tienen el aviso de escalado y el asignado al ticket.
     *
     * @param string               $type    Uno de los `TYPE_*`.
     * @param array<string, mixed> $context Resultado de {@see self::context_for()}.
     *
     * @return array<int, int> Ids de admin, sin repetidos.
     */
    private function recipients_for(string $type, array $context): array
    {
        if ($type === self::TYPE_SUPPORT) {
            $admin_ids = app(SupportAttentionNotificationService::class)->destinatarios($context['ticket']);
        } else {
            $admin_ids = LeadNotificationAudienceResolver::for_verificacion($context['lead']);
        }

        $admin_ids  = array_values(array_unique(array_map('intval', $admin_ids)));
        $con_device = LeadNotificationAudienceResolver::ids_con_device_de_push($admin_ids);

        return array_values(array_filter($admin_ids, function ($admin_id) use ($con_device) {
            return in_array($admin_id, $con_device, true);
        }));
    }

    /**
     * Arma título, cuerpo y datos del push (contrato con admin-spa: todo en el primer nivel del payload).
     *
     * @param string                                     $type    Uno de los `TYPE_*`.
     * @param LeadMessage|SupportMessage|DemoChatMensaje $message Mensaje que espera aprobación.
     * @param array<string, mixed>                       $context Contexto de la conversación (`lead`, `ticket`).
     *
     * @return array<string, mixed> `title`, `body`, `data`.
     */
    private function build_content(string $type, $message, array $context): array
    {
        if ($type === self::TYPE_SUPPORT) {
            $ticket = $context['ticket'];

            return [
                'title' => 'Para aprobar · Soporte · ' . $ticket->resolve_contact_display_name(),
                'body'  => $this->preview((string) $message->body),
                'data'  => [
                    'url'        => '/soporte/ticket/' . $ticket->id,
                    'tag'        => 'aprobacion-ticket-' . $ticket->id,
                    'tipo'       => 'aprobacion_pendiente',
                    'origen'     => self::TYPE_SUPPORT,
                    'message_id' => (int) $message->id,
                    'ticket_id'  => (int) $ticket->id,
                ],
            ];
        }

        $lead    = $context['lead'];
        $lead_id = $lead !== null ? (int) $lead->id : (int) $message->lead_id;
        $name    = $this->lead_display_name($lead, $lead_id);

        if ($type === self::TYPE_DEMO) {
            return [
                'title' => 'Para aprobar · Chat de la demo · ' . $name,
                'body'  => $this->preview((string) $message->contenido),
                'data'  => [
                    'url'        => '/leads?lead_id=' . $lead_id,
                    'tag'        => 'aprobacion-demo-chat-' . $lead_id,
                    'tipo'       => 'aprobacion_pendiente',
                    'origen'     => self::TYPE_DEMO,
                    'message_id' => (int) $message->id,
                    'lead_id'    => $lead_id,
                ],
            ];
        }

        return [
            'title' => 'Para aprobar · ' . $name,
            'body'  => $this->preview((string) $message->content),
            'data'  => [
                'url'        => '/leads/' . $lead_id . '/conversacion?message_id=' . $message->id,
                'tag'        => 'aprobacion-lead-' . $lead_id,
                'tipo'       => 'aprobacion_pendiente',
                'origen'     => self::TYPE_LEAD,
                'message_id' => (int) $message->id,
                'lead_id'    => $lead_id,
            ],
        ];
    }

    /**
     * Nombre legible del lead: contacto, si no empresa, si no «Lead #id».
     *
     * @param Lead|null $lead    Lead (puede no existir).
     * @param int       $lead_id Id del lead, para el nombre de último recurso.
     *
     * @return string
     */
    private function lead_display_name(?Lead $lead, int $lead_id): string
    {
        if ($lead !== null && ! empty($lead->contact_name)) {
            return (string) $lead->contact_name;
        }
        if ($lead !== null && ! empty($lead->company_name)) {
            return (string) $lead->company_name;
        }

        return 'Lead #' . $lead_id;
    }

    /**
     * Cuerpo del push: texto sin espacios de más (saltos de línea y espacios repetidos colapsados a uno), recortado a
     * 140 caracteres con «…». Vacío: texto fijo.
     *
     * @param string $text Texto del mensaje.
     *
     * @return string
     */
    private function preview(string $text): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', trim($text)));
        if ($clean === '') {
            return self::EMPTY_BODY;
        }

        return mb_strimwidth($clean, 0, self::PREVIEW_LENGTH, '…');
    }

    // ------------------------------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------------------------------

    /**
     * Valores de una fila nueva del registro (estado `pending`, sin intentos).
     *
     * @param string $type      Uno de los `TYPE_*`.
     * @param int    $source_id Id del mensaje.
     * @param string $group_key Clave de la conversación.
     * @param string $channel   `poller` o `direct`.
     *
     * @return array<string, mixed>
     */
    private function new_row_values(string $type, int $source_id, string $group_key, string $channel): array
    {
        $stamp = $this->now()->format('Y-m-d H:i:s');

        return [
            'source_key'  => $type . ':' . $source_id,
            'source_type' => $type,
            'source_id'   => $source_id,
            'group_key'   => $group_key,
            'status'      => 'pending',
            'channel'     => $channel,
            'attempts'    => 0,
            'created_at'  => $stamp,
            'updated_at'  => $stamp,
        ];
    }

    /**
     * Momento actual de la aplicación (respeta `Carbon::setTestNow`).
     *
     * @return \Carbon\Carbon
     */
    private function now(): \Carbon\Carbon
    {
        return AppTime::now();
    }

    /**
     * Escribe en el log diario sin que un fallo del log rompa nada.
     *
     * @param string               $level   `warning` o `error`.
     * @param string               $message Mensaje.
     * @param array<string, mixed> $context Datos extra.
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('daily')->{$level}($message, $context);
        } catch (\Throwable $e) {
            /* Ni el log puede romper el flujo que invocó el aviso. */
        }
    }
}
