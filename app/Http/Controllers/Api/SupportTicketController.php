<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportTicketUpdated;
use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Services\ClientPhoneDirectory;
use App\Services\SupportClientSyncService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportTicketController extends BaseController
{
    /**
     * Lista tickets para la bandeja admin con filtros básicos.
     */
    public function index(Request $request)
    {
        // Admin autenticado que consulta la bandeja.
        $admin_id = (int) Auth::id();
        // Filtro por destinatario/asignación: nuevo `assigned_filter` o legacy `filter`.
        $assigned_filter = $this->resolve_assigned_filter_param($request, $admin_id);

        // Relaciones de bandeja: preview del último mensaje sin cargar toda la conversación (evita payload enorme).
        $query = $this->ticketQueryForInbox()
            ->orderBy('updated_at', 'desc');

        $this->apply_assigned_filter_to_ticket_query($query, $assigned_filter, $admin_id);

        // Responde lista completa, totales legacy (nav global) y filas para botones por operador.
        $models = $query->get();
        return response()->json(
            [
                'models' => $models,
                'unread_totals' => $this->unread_totals_for_admin_inbox($admin_id),
                'inbox_nav' => $this->inbox_nav_rows(),
            ],
            200
        );
    }

    /**
     * Agregado de no leídos (mensajes user sin read_at) para badges "Míos" y "Otros" sin listar tickets.
     */
    public function unread_badges()
    {
        $admin_id = (int) Auth::id();
        return response()->json(
            [
                'unread_totals' => $this->unread_totals_for_admin_inbox($admin_id),
                'inbox_nav' => $this->inbox_nav_rows(),
            ],
            200
        );
    }

    /**
     * Interpreta el filtro de bandeja: prioriza `assigned_filter`; si falta, usa `filter` legacy (mine/others/all).
     *
     * Valores admitidos en `assigned_filter`: all, mine, unassigned, others (legacy), o id numérico de admin.
     *
     * @param Request $request Request HTTP entrante
     * @param int $admin_id Id del operador autenticado
     * @return string Valor normalizado de filtro
     */
    protected function resolve_assigned_filter_param(Request $request, int $admin_id): string
    {
        $raw = $request->input('assigned_filter');
        if ($raw !== null && $raw !== '') {
            return (string) $raw;
        }
        $legacy = $request->input('filter', 'mine');
        if ($legacy === 'mine') {
            return 'mine';
        }
        if ($legacy === 'others') {
            return 'others';
        }
        if ($legacy === 'all') {
            return 'all';
        }

        return 'mine';
    }

    /**
     * Eager load típico del listado de bandeja: cliente, asignado, último mensaje y contador de no leídos.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function ticketQueryForInbox()
    {
        return SupportTicket::query()
            ->with([
                'client',
                'client_employee',
                'assigned_admin',
                'lastMessage.sender_admin',
            ])
            ->withUnreadMessagesCount();
    }

    /**
     * Restringe el listado de tickets según el menú lateral (todos, míos, sin asignar, u operador concreto).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query base de SupportTicket
     * @param string $assigned_filter Valor normalizado (all, mine, unassigned, others, o id numérico)
     * @param int $admin_id Operador autenticado (para mine y others)
     * @return void
     */
    protected function apply_assigned_filter_to_ticket_query($query, string $assigned_filter, int $admin_id): void
    {
        if ($assigned_filter === 'mine') {
            $query->where('assigned_admin_id', $admin_id);

            return;
        }
        if ($assigned_filter === 'others') {
            $query->where(function ($sub_query) use ($admin_id) {
                $sub_query->where('assigned_admin_id', '<>', $admin_id)
                    ->orWhereNull('assigned_admin_id');
            });

            return;
        }
        if ($assigned_filter === 'unassigned') {
            $query->whereNull('assigned_admin_id');

            return;
        }
        if ($assigned_filter === 'all') {
            return;
        }
        if (ctype_digit($assigned_filter)) {
            $query->where('assigned_admin_id', (int) $assigned_filter);

            return;
        }

        // Valor desconocido: no listar toda la bandeja por error; alineado con "mine".
        $query->where('assigned_admin_id', $admin_id);
    }

    /**
     * Filas para la bandeja lateral: un botón por cada admin del sistema (aunque no tenga tickets ni no leídos).
     * Opcionalmente la fila "Sin asignar" si hay tickets sin operador o mensajes pendientes en esa cola.
     *
     * @return array<int, array{assigned_admin_id: int|null, display_name: string, unread_count: int}>
     */
    protected function inbox_nav_rows(): array
    {
        // Mapa aid => cantidad de mensajes user sin leer (aid null agrupa en clave especial).
        $count_map = $this->unread_counts_grouped_by_assigned_admin();

        // Todos los operadores: cada uno tiene botón aunque unread_count sea 0 y sin tickets asignados.
        $admins = Admin::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = [];
        foreach ($admins as $admin) {
            $aid = (int) $admin->id;
            $rows[] = [
                'assigned_admin_id' => $aid,
                'display_name' => (string) $admin->name,
                'unread_count' => (int) ($count_map[$aid] ?? 0),
            ];
        }

        $has_unassigned_tickets = SupportTicket::query()->whereNull('assigned_admin_id')->exists();
        $unassigned_unread = (int) ($count_map['unassigned'] ?? 0);
        if ($has_unassigned_tickets || $unassigned_unread > 0) {
            $rows[] = [
                'assigned_admin_id' => null,
                'display_name' => 'Sin asignar',
                'unread_count' => $unassigned_unread,
            ];
        }

        return $rows;
    }

    /**
     * Cuenta mensajes de usuario sin leer agrupados por admin asignado al ticket (null = sin asignar).
     *
     * @return array<int|string, int> Claves: id numérico o la cadena 'unassigned'
     */
    protected function unread_counts_grouped_by_assigned_admin(): array
    {
        $aggregates = SupportMessage::query()
            ->where('support_messages.sender_type', 'user')
            ->whereNull('support_messages.read_at')
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->selectRaw('support_tickets.assigned_admin_id as aid, COUNT(*) as cnt')
            ->groupBy('support_tickets.assigned_admin_id')
            ->get();

        $count_map = [];
        foreach ($aggregates as $row) {
            if ($row->aid === null) {
                $count_map['unassigned'] = (int) $row->cnt;
            } else {
                $count_map[(int) $row->aid] = (int) $row->cnt;
            }
        }

        return $count_map;
    }

    /**
     * Suma de mensajes del usuario (empresa) aún no leídos, agrupado como en el menú de filtros.
     *
     * @param int $admin_id Operador autenticado
     * @return array{mine: int, others: int}
     */
    protected function unread_totals_for_admin_inbox(int $admin_id): array
    {
        // "Míos": tickets asignados a este operador
        $mine = SupportMessage::query()
            ->where('sender_type', 'user')
            ->whereNull('read_at')
            ->whereHas('ticket', function ($query) use ($admin_id) {
                $query->where('assigned_admin_id', $admin_id);
            })
            ->count();

        // "Otros": no asignados a este operador o sin asignar (mismo criterio que index)
        $others = SupportMessage::query()
            ->where('sender_type', 'user')
            ->whereNull('read_at')
            ->whereHas('ticket', function ($query) use ($admin_id) {
                $query->where(function ($sub_query) use ($admin_id) {
                    $sub_query
                        ->where('assigned_admin_id', '<>', $admin_id)
                        ->orWhereNull('assigned_admin_id');
                });
            })
            ->count();

        return [
            'mine' => $mine,
            'others' => $others,
        ];
    }

    /**
     * Muestra un ticket puntual del módulo soporte.
     */
    public function show($id)
    {
        // Recupera ticket con relaciones para abrir conversación.
        $model = SupportTicket::query()
            ->where('id', $id)
            ->withAll()
            ->withUnreadMessagesCount()
            ->firstOrFail();
        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea ticket nuevo desde admin-spa para un usuario de cliente.
     *
     * Con `source=whatsapp` abre la conversación por WhatsApp en vez de replicarla al ERP
     * del cliente. Sin ese parámetro el comportamiento es exactamente el de siempre, para no
     * romper el modal que ya está en producción.
     */
    public function store(Request $request, SupportClientSyncService $sync_service)
    {
        if ($request->input('source') === 'whatsapp') {
            return $this->store_whatsapp($request);
        }

        // Crea ticket con data enviada por operador de soporte.
        $ticket = SupportTicket::create([
            'client_id' => (int) $request->input('client_id'),
            'client_user_id' => (int) $request->input('client_user_id'),
            'client_user_name' => $request->input('client_user_name'),
            'client_user_email' => $request->input('client_user_email'),
            'assigned_admin_id' => (int) $request->input('assigned_admin_id', Auth::id()),
            'name' => $request->input('name'),
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Replica creación de ticket en empresa-api para habilitar conversación inmediata.
        $sync_service->create_ticket_in_client($ticket);

        return response()->json([
            'model' => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
        ], 201);
    }

    /**
     * Abre una conversación de soporte por WhatsApp desde la bandeja.
     *
     * @param Request $request Alta con client_id, whatsapp_phone y body.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function store_whatsapp(Request $request)
    {
        $validated = $request->validate([
            'client_id'      => 'required|integer|exists:clients,id',
            'whatsapp_phone' => 'required|string',
            'body'           => 'required|string|max:4096',
            'name'           => 'nullable|string|max:255',
        ]);

        // min:1 dejaba pasar un espacio en blanco: el servicio lo trimea, WhatsApp rechaza el
        // cuerpo vacío y quedaba una conversación abierta con un mensaje vacío sin entregar.
        $body = trim((string) $validated['body']);
        if ($body === '') {
            return response()->json(['error' => 'El mensaje no puede estar vacío.'], 422);
        }

        $client = Client::findOrFail((int) $validated['client_id']);

        // El webhook exige is_active en sus tres formas de reconocer un número. Abrirle una
        // conversación a un cliente dado de baja garantiza que su respuesta caiga en el
        // pipeline de leads, que es justo la falla silenciosa que este trabajo viene a evitar.
        if (! $client->is_active) {
            return response()->json([
                'error' => 'Ese cliente está dado de baja: el webhook no lo va a reconocer y su respuesta caería en el pipeline de leads.',
            ], 422);
        }

        $admin = Admin::find((int) Auth::id());
        if ($admin === null) {
            return response()->json(['error' => 'admin no encontrado'], 401);
        }

        $opener = app(SupportWhatsappOpenerService::class);

        try {
            $result = $opener->open(
                $client,
                (string) $validated['whatsapp_phone'],
                $body,
                $admin,
                (string) $request->input('name', '')
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'model'    => $this->ticketQueryForInbox()->where('id', $result['ticket']->id)->first(),
            'message'  => $result['message'],
            'reused'   => $result['reused'],
            'whatsapp' => $result['whatsapp'],
        ], $result['reused'] ? 200 : 201);
    }

    /**
     * Prende o apaga el agente de IA para un ticket puntual.
     *
     * Apagarlo solo afecta a las sugerencias FUTURAS: un borrador que ya esté esperando sigue
     * ahí, para que el operador lo mande o lo descarte a mano. Es el mismo criterio que
     * LeadController::toggle_claude_auto_reply_json(). No hace falta cancelar el job encolado:
     * SendSupportAiSuggestion vuelve a mirar el flag cuando corre.
     *
     * @param int|string $id Id del ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_claude_auto_reply($id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $ticket->claude_auto_reply = ! (bool) $ticket->claude_auto_reply;
        $ticket->save();

        event(new SupportTicketUpdated((int) $ticket->id));

        return response()->json([
            'model' => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
        ], 200);
    }

    /**
     * Prende o apaga la verificación humana obligatoria para un ticket puntual.
     *
     * Con esto prendido —que es como nace todo ticket— ninguna sugerencia del agente sale sola:
     * queda como borrador esperando que una persona la mande, con o sin ajustes.
     *
     * @param int|string $id Id del ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_requiere_verificacion($id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $ticket->requiere_verificacion_mensajes = ! (bool) $ticket->requiere_verificacion_mensajes;
        $ticket->save();

        event(new SupportTicketUpdated((int) $ticket->id));

        return response()->json([
            'model' => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
        ], 200);
    }

    /**
     * Contactos de WhatsApp de un cliente y estado de la ventana de 24hs de cada uno.
     *
     * Lo consume el modal de alta para avisarle al operador, ANTES de mandar, si su texto
     * va a salir tal cual o metido dentro de la plantilla aprobada.
     *
     * @param Request $request Con client_id obligatorio.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function whatsapp_contacts(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
        ]);

        $client = Client::findOrFail((int) $validated['client_id']);

        $phone_directory = app(ClientPhoneDirectory::class);
        $window_service = app(WhatsappSessionWindowService::class);

        $contacts = [];
        foreach ($phone_directory->phones_for_client($client) as $contact) {
            $contact['window'] = $window_service->window_state((string) $contact['phone']);
            $contacts[] = $contact;
        }

        return response()->json([
            'contacts'      => $contacts,
            'template_name' => app(SupportWhatsappOpenerService::class)->resolve_template_name(),
        ], 200);
    }

    /**
     * Reasigna/cierra/reabre ticket y sincroniza a empresa-api.
     */
    public function update(Request $request, $id, SupportClientSyncService $sync_service)
    {
        // Busca ticket editable desde admin.
        $ticket = SupportTicket::findOrFail($id);

        // Reasigna operador cuando el frontend lo solicita.
        if ($request->has('assigned_admin_id')) {
            $ticket->assigned_admin_id = $request->input('assigned_admin_id');
        }
        // Actualiza nombre del caso.
        if ($request->has('name')) {
            $ticket->name = $request->input('name');
        }
        // Cambia estado operativo del ticket.
        if ($request->has('status')) {
            $ticket->status = $request->input('status');
        }
        // Gestiona fecha de cierre según estado final.
        if ($ticket->status === 'closed') {
            $ticket->closed_at = now();
            /* Resuelto: ya no requiere supervisión; limpia marca de escalado en bandeja. */
            $ticket->escalated_at = null;
            $ticket->escalation_reason = null;
        }
        if ($ticket->status === 'open') {
            $ticket->closed_at = null;
        }

        $ticket->save();

        // Sincroniza cambios de ticket al empresa-api de ese cliente. Un ticket de WhatsApp
        // no tiene contraparte allá: replicarlo es un POST que siempre falla.
        if ($ticket->source !== 'whatsapp') {
            $sync_service->sync_ticket_to_client($ticket);
        }

        // Notifica a todos los operadores (support.admins) para alinear bandejas tras reasignación u otros cambios.
        event(new SupportTicketUpdated((int) $ticket->id));

        return response()->json([
            'model' => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
        ], 200);
    }
}

