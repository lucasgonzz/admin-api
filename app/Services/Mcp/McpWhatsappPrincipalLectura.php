<?php

namespace App\Services\Mcp;

use App\Helpers\WhatsappNormalizer;
use App\Services\BuscadorDeLeadPorTelefono;
use App\Services\ClaudeLeadQueryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lectura del número PRINCIPAL de WhatsApp (leads y soporte) para el conector de WhatsApp de Dotin/ChatGPT
 * (misión dotin-whatsapp-admin, 26/11/2026).
 *
 * 🔴 Sólo SELECT. No marca nada como leído (ni en leads ni en soporte), no manda recibos a Meta, no hace HTTP y no
 * instancia ningún servicio de envío. Los canales son dos, ambos de tablas que ya existen:
 *   - `lead`: mensajes de `lead_messages` del lead con ese teléfono (sin los borradores de la IA ni los eventos
 *     internos); "no leído" = mensaje del lead sin fila en `lead_message_reads` para el admin del token.
 *   - `soporte`: mensajes de tickets de soporte por WhatsApp (`source = 'whatsapp'`) de ese teléfono, sin los
 *     borradores de la IA; "no leído" = mensaje del cliente con `read_at` vacío.
 *
 * Los dos servicios de consulta que reutiliza ({@see ClaudeLeadQueryService::messages_query()} y
 * {@see BuscadorDeLeadPorTelefono::buscar_exacto()}) son de sólo lectura.
 */
class McpWhatsappPrincipalLectura
{
    /** Tope de filas que se traen por canal al armar la lista de chats. */
    const TOPE_FILAS_POR_CANAL = 400;

    /** Tope de caracteres del último mensaje que se muestra en la lista de chats. */
    const MAX_ULTIMO_MENSAJE = 200;

    /** Estados de un mensaje de lead que nunca salieron (borradores de la IA o rechazados). */
    const ESTADOS_NO_ENVIADOS = ['sugerido', 'rechazado'];

    /**
     * Lista de chats del número principal (lead + soporte), uno por teléfono, del más reciente al más viejo.
     *
     * @param bool        $solo_no_leidos Sólo los que tienen mensajes sin leer.
     * @param string|null $desde          Sólo chats con actividad desde esta fecha (ISO 8601).
     * @param int         $limite         Máximo de chats.
     * @param int         $admin_id       Admin del token (los "leídos" de los leads son por usuario).
     *
     * @return array<int, array<string, mixed>>
     */
    public function chats(bool $solo_no_leidos, ?string $desde, int $limite, int $admin_id): array
    {
        $desde_sql = $desde === null ? null : Carbon::parse($desde)->toDateTimeString();

        // Un solo mapa por teléfono normalizado, alimentado por los dos canales.
        $por_telefono = [];

        $consulta_de_leads = ClaudeLeadQueryService::messages_query(['from' => $desde_sql]);
        $this->solo_enviados($consulta_de_leads);
        $filas = $consulta_de_leads
            ->join('leads', 'leads.id', '=', 'lead_messages.lead_id')
            ->leftJoin('lead_message_reads as lmr', function ($union) use ($admin_id) {
                $union->on('lmr.lead_message_id', '=', 'lead_messages.id')->where('lmr.admin_id', '=', $admin_id);
            })
            ->whereNotNull('leads.phone')
            ->select(
                'lead_messages.id', 'lead_messages.sender', 'lead_messages.content', 'leads.phone', 'leads.contact_name',
                'lmr.id as leido_id', DB::raw(ClaudeLeadQueryService::message_time_sql() . ' as momento')
            )
            ->orderByDesc('momento')->orderByDesc('lead_messages.id')
            ->limit(self::TOPE_FILAS_POR_CANAL)
            ->get();

        foreach ($filas as $fila) {
            $es_entrante = $fila->sender === 'lead';
            $this->sumar_a_chat($por_telefono, (string) $fila->phone, (string) $fila->contact_name, 'lead', $fila->content,
                $es_entrante, $fila->momento, $es_entrante && $fila->leido_id === null);
        }

        $consulta_de_soporte = DB::table('support_messages')
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->where('support_tickets.source', 'whatsapp')
            ->whereNotNull('support_tickets.whatsapp_phone')
            ->where('support_messages.is_ai_suggestion_draft', false);
        if ($desde_sql !== null) {
            $consulta_de_soporte->where('support_messages.created_at', '>=', $desde_sql);
        }
        $filas = $consulta_de_soporte
            ->select(
                'support_messages.id', 'support_messages.sender_type', 'support_messages.body', 'support_messages.read_at',
                'support_messages.created_at as momento', 'support_tickets.whatsapp_phone as phone', 'support_tickets.client_user_name'
            )
            ->orderByDesc('support_messages.created_at')->orderByDesc('support_messages.id')
            ->limit(self::TOPE_FILAS_POR_CANAL)
            ->get();

        foreach ($filas as $fila) {
            $es_entrante = $fila->sender_type === 'user';
            $this->sumar_a_chat($por_telefono, (string) $fila->phone, (string) $fila->client_user_name, 'soporte', $fila->body,
                $es_entrante, $fila->momento, $es_entrante && $fila->read_at === null);
        }

        $chats = array_values($por_telefono);
        if ($solo_no_leidos) {
            $chats = array_values(array_filter($chats, function ($chat) {
                return $chat['no_leidos'] > 0;
            }));
        }
        usort($chats, function ($a, $b) {
            return strcmp((string) $b['last_message_at'], (string) $a['last_message_at']);
        });

        return array_slice($chats, 0, $limite);
    }

    /**
     * Mensajes del teléfono en el número principal (lead + soporte), en orden cronológico.
     *
     * @param string      $telefono Teléfono en E.164.
     * @param string|null $desde    Fecha ISO 8601: sólo mensajes desde ahí (sin ella, los más nuevos).
     * @param int         $limite   Máximo de mensajes.
     * @param int         $admin_id Admin del token.
     *
     * @return array{mensajes: array<int, array<string, mixed>>, hay_mas: bool}
     */
    public function mensajes(string $telefono, ?string $desde, int $limite, int $admin_id): array
    {
        $desde_sql = $desde === null ? null : Carbon::parse($desde)->toDateTimeString();
        $juntos    = [];

        // Canal lead: el lead que tiene EXACTAMENTE ese teléfono.
        $lead = BuscadorDeLeadPorTelefono::buscar_exacto($telefono);
        if ($lead !== null) {
            $consulta = ClaudeLeadQueryService::messages_query(['lead_ids' => [$lead->id], 'from' => $desde_sql]);
            $this->solo_enviados($consulta);
            $filas = $consulta
                ->leftJoin('lead_message_reads as lmr', function ($union) use ($admin_id) {
                    $union->on('lmr.lead_message_id', '=', 'lead_messages.id')->where('lmr.admin_id', '=', $admin_id);
                })
                ->select(
                    'lead_messages.id', 'lead_messages.sender', 'lead_messages.content', 'lead_messages.status',
                    DB::raw(ClaudeLeadQueryService::message_time_sql() . ' as momento')
                )
                ->orderByDesc('momento')->orderByDesc('lead_messages.id')
                ->limit($limite + 1)
                ->get();

            foreach ($filas as $fila) {
                $juntos[] = [
                    'id'         => 'lead:' . $fila->id,
                    'canal'      => 'lead',
                    'direccion'  => $fila->sender === 'lead' ? 'in' : 'out',
                    'tipo'       => 'text',
                    'texto'      => $fila->content,
                    'estado'     => $fila->status,
                    'enviado_at' => $this->iso($fila->momento),
                    '_momento'   => (string) $fila->momento,
                ];
            }
        }

        // Canal soporte: tickets de WhatsApp de ese teléfono.
        $tickets = $this->tickets_de($telefono);
        if (count($tickets) > 0) {
            $consulta = DB::table('support_messages')
                ->whereIn('support_ticket_id', $tickets)
                ->where('is_ai_suggestion_draft', false);
            if ($desde_sql !== null) {
                $consulta->where('created_at', '>=', $desde_sql);
            }
            $filas = $consulta->orderByDesc('created_at')->orderByDesc('id')->limit($limite + 1)->get();

            foreach ($filas as $fila) {
                $juntos[] = [
                    'id'         => 'soporte:' . $fila->id,
                    'canal'      => 'soporte',
                    'direccion'  => $fila->sender_type === 'user' ? 'in' : 'out',
                    'tipo'       => 'text',
                    'texto'      => $fila->body,
                    'estado'     => $fila->read_at === null ? 'recibido' : 'leido',
                    'enviado_at' => $this->iso($fila->created_at),
                    '_momento'   => (string) $fila->created_at,
                ];
            }
        }

        // Orden cronológico; sin `desde` se queda con los más nuevos, con `desde` con los que siguen a esa fecha.
        usort($juntos, function ($a, $b) {
            return strcmp($a['_momento'], $b['_momento']);
        });
        $hay_mas = count($juntos) > $limite;
        $juntos  = $desde === null ? array_slice($juntos, -$limite) : array_slice($juntos, 0, $limite);

        foreach ($juntos as $indice => $mensaje) {
            unset($juntos[$indice]['_momento']);
        }

        return ['mensajes' => array_values($juntos), 'hay_mas' => $hay_mas];
    }

    /**
     * Ids de los tickets de soporte por WhatsApp cuyo teléfono es el pedido (candidatos por los últimos 8 dígitos
     * en la base y comparación completa en PHP).
     *
     * @param string $telefono Teléfono en E.164.
     *
     * @return array<int, int>
     */
    private function tickets_de(string $telefono): array
    {
        $digitos = (string) preg_replace('/\D+/', '', $telefono);
        if (strlen($digitos) < 8) {
            return [];
        }

        $candidatos = DB::table('support_tickets')
            ->where('source', 'whatsapp')
            ->whereNotNull('whatsapp_phone')
            ->where('whatsapp_phone', 'like', '%' . substr($digitos, -8) . '%')
            ->get(['id', 'whatsapp_phone']);

        $ids = [];
        foreach ($candidatos as $ticket) {
            if (WhatsappNormalizer::phones_match((string) $ticket->whatsapp_phone, $telefono)) {
                $ids[] = (int) $ticket->id;
            }
        }

        return $ids;
    }

    /**
     * Deja afuera los mensajes de lead que nunca salieron (borradores de la IA y rechazados).
     *
     * @param \Illuminate\Database\Query\Builder $consulta Consulta sobre `lead_messages`.
     *
     * @return void
     */
    private function solo_enviados($consulta): void
    {
        $consulta->where(function ($q) {
            $q->whereNull('lead_messages.status')->orWhereNotIn('lead_messages.status', self::ESTADOS_NO_ENVIADOS);
        });
    }

    /**
     * Suma un mensaje al chat de su teléfono (alta del chat si todavía no está). Las filas llegan de la más nueva
     * a la más vieja: la primera que se ve de un canal es el último mensaje de ese canal.
     *
     * @param array<string, array<string, mixed>> $por_telefono Mapa en construcción (se modifica).
     * @param string                              $telefono_crudo Teléfono tal como está guardado.
     * @param string                              $nombre         Nombre del contacto (puede venir vacío).
     * @param string                              $canal          `lead` o `soporte`.
     * @param string|null                         $texto          Cuerpo del mensaje.
     * @param bool                                $entrante       Si lo escribió el contacto.
     * @param string                              $momento        Fecha del mensaje.
     * @param bool                                $sin_leer       Si cuenta como no leído.
     *
     * @return void
     */
    private function sumar_a_chat(array &$por_telefono, $telefono_crudo, $nombre, $canal, $texto, $entrante, $momento, $sin_leer): void
    {
        $telefono = WhatsappNormalizer::normalize($telefono_crudo);
        if ($telefono === '') {
            return;
        }

        $momento_iso = (string) $this->iso($momento);
        if (! isset($por_telefono[$telefono])) {
            $por_telefono[$telefono] = [
                'telefono'                 => $telefono,
                'nombre'                   => $nombre !== '' ? $nombre : null,
                'no_leidos'                => 0,
                'ultimo_mensaje'           => null,
                'ultimo_mensaje_direccion' => null,
                'last_message_at'          => null,
                'canal'                    => $canal,
            ];
        }

        $chat = &$por_telefono[$telefono];
        if ($sin_leer) {
            $chat['no_leidos']++;
        }
        if ($chat['last_message_at'] === null || strcmp($momento_iso, (string) $chat['last_message_at']) > 0) {
            $resumen = (string) $texto;
            $chat['ultimo_mensaje']           = mb_strlen($resumen) > self::MAX_ULTIMO_MENSAJE ? mb_substr($resumen, 0, self::MAX_ULTIMO_MENSAJE - 1) . '…' : $resumen;
            $chat['ultimo_mensaje_direccion'] = $entrante ? 'in' : 'out';
            $chat['last_message_at']          = $momento_iso;
            $chat['canal']                    = $canal;
        }
        if ($chat['nombre'] === null && $nombre !== '') {
            $chat['nombre'] = $nombre;
        }
    }

    /**
     * Una fecha de la base como ISO 8601 (null si no hay).
     *
     * @param mixed $valor Fecha.
     *
     * @return string|null
     */
    private function iso($valor): ?string
    {
        return $valor === null || $valor === '' ? null : Carbon::parse($valor)->toIso8601String();
    }
}
