<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportTicketUpdated;
use App\Http\Controllers\CommonLaravel\BaseController;
use App\Helpers\WhatsappNormalizer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\ClientTemplate;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Services\ClientPhoneDirectory;
use App\Services\SupportAiSuggestionDraftService;
use App\Services\SupportClientSyncService;
use App\Services\SupportTemplateSendService;
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
     *
     * Y con `source=whatsapp` + `client_template_id` el primer mensaje sale por una plantilla
     * real del catálogo de client_templates, elegida por el operador, en vez de texto libre
     * auto-envuelto en la plantilla oculta de apertura.
     */
    public function store(Request $request, SupportClientSyncService $sync_service)
    {
        if ($request->input('source') === 'whatsapp') {
            if ($request->filled('client_template_id')) {
                return $this->store_whatsapp_con_plantilla($request);
            }

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
     * Abre una conversación de soporte por WhatsApp mandando una plantilla real elegida por el
     * operador, en vez de texto libre auto-envuelto en la plantilla oculta de apertura.
     *
     * Es el mismo camino que store_whatsapp() en la resolución de contacto y el reuso de ticket
     * -los dos pasan por SupportWhatsappOpenerService::resolve_or_create_ticket()-, pero el envío
     * lo hace SupportTemplateSendService::enviar(), el mismo servicio que ya usa
     * ClientTemplateController::send_to_ticket_json() para conversaciones existentes. No se
     * bloquea por ventana abierta: una plantilla se puede mandar siempre.
     *
     * @param Request $request Alta con client_id, whatsapp_phone, client_template_id y variables.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function store_whatsapp_con_plantilla(Request $request)
    {
        $validated = $request->validate([
            'client_id'          => 'required|integer|exists:clients,id',
            'whatsapp_phone'     => 'required|string',
            'client_template_id' => 'required|integer|exists:client_templates,id',
            'variables'          => 'nullable|array',
            'variables.*'        => 'nullable|string|max:600',
            'name'               => 'nullable|string|max:255',
        ]);

        // Elegir una cosa o la otra: si además viene un body con contenido, no se ignora en
        // silencio -el operador pensaría que mandó las dos cosas y solo salió una-.
        if (trim((string) $request->input('body', '')) !== '') {
            return response()->json([
                'error' => 'Elegí una cosa o la otra: o mandás texto libre o mandás una plantilla, no las dos en el mismo alta.',
            ], 422);
        }

        $client = Client::findOrFail((int) $validated['client_id']);

        // Mismo motivo y mismo texto que store_whatsapp(): el webhook exige is_active en sus
        // tres formas de reconocer un número.
        if (! $client->is_active) {
            return response()->json([
                'error' => 'Ese cliente está dado de baja: el webhook no lo va a reconocer y su respuesta caería en el pipeline de leads.',
            ], 422);
        }

        $admin = Admin::find((int) Auth::id());
        if ($admin === null) {
            return response()->json(['error' => 'admin no encontrado'], 401);
        }

        $template = ClientTemplate::findOrFail((int) $validated['client_template_id']);

        if ($template->activa === false) {
            return response()->json(['error' => 'Esa plantilla está desactivada.'], 422);
        }

        $opener = app(SupportWhatsappOpenerService::class);

        try {
            $resuelto = $opener->resolve_or_create_ticket(
                $client,
                (string) $validated['whatsapp_phone'],
                $admin,
                (string) $request->input('name', '')
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $ticket = $resuelto['ticket'];

        $window = app(WhatsappSessionWindowService::class)->window_state($resuelto['phone']);

        // Valores en el orden en que los cargó el operador. Se re-indexa porque un objeto JSON
        // con claves salteadas ("0", "2") desordenaría los {{n}} sin avisar -mismo criterio que
        // send_to_ticket_json()-.
        $variables = array_values((array) $request->input('variables', []));

        $resultado = app(SupportTemplateSendService::class)->enviar($ticket, $template, $variables, $admin);

        return response()->json([
            'model'    => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
            'message'  => $resultado['message'],
            'reused'   => $resuelto['reused'],
            'whatsapp' => [
                'delivery'      => $resultado['delivery'],
                'message_id'    => $resultado['message']->whatsapp_message_id,
                'error'         => $resultado['error'],
                'used_template' => true,
                'window_open'   => (bool) $window['open'],
                'window'        => $window,
                'template_name' => $template->template_name,
            ],
        ], $resuelto['reused'] ? 200 : 201);
    }

    /**
     * Prende o apaga el agente de IA para un ticket puntual.
     *
     * Apagarlo también se lleva el borrador que esté esperando: con el agente apagado, un
     * borrador con fecha de autoenvío igual saldría, y apagar el agente y ver que le contesta
     * al cliente lo mismo es lo peor que puede hacer este botón. No hace falta cancelar el job
     * encolado: SendSupportAiSuggestion relee el flag antes de mandar.
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

        // Apagarlo también se lleva lo que ya estaba en curso: un borrador con fecha de
        // autoenvío sigue saliendo aunque el agente quede apagado, y apagar el agente y ver
        // que igual le contesta al cliente es lo peor que puede hacer este botón.
        if (! $ticket->claude_auto_reply) {
            (new SupportAiSuggestionDraftService())->clear_ticket_pending_state($ticket);
        }

        // Vía ::dispatch() y no event(new ...): el dispatch del evento pasa por BroadcastGuard,
        // así una falla de Pusher no voltea un ticket que ya quedó guardado.
        SupportTicketUpdated::dispatch((int) $ticket->id);

        return response()->json([
            'model' => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
        ], 200);
    }

    /**
     * Prende o apaga la verificación humana obligatoria para un ticket puntual.
     *
     * Con esto prendido —que es como nace un ticket si la config global de Cuenta lo pide—
     * ninguna sugerencia del agente sale sola: queda como borrador esperando que una persona
     * la mande, con o sin ajustes.
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

        // Al prenderla, hay que apagar el reloj del borrador que ya estaba en curso. El job de
        // autoenvío lo frena bien, pero la pantalla seguiría mostrando el contador corriendo
        // hasta cero sin que pase nada, y el operador no sabe si el mensaje salió o no.
        if ($ticket->requiere_verificacion_mensajes) {
            SupportMessage::where('support_ticket_id', $ticket->id)
                ->where('is_ai_suggestion_draft', true)
                ->update(['ai_auto_send_at' => null]);

            $ticket->ai_suggestion_send_at = null;
            $ticket->save();
        }

        // Vía ::dispatch() y no event(new ...): el dispatch del evento pasa por BroadcastGuard,
        // así una falla de Pusher no voltea un ticket que ya quedó guardado.
        SupportTicketUpdated::dispatch((int) $ticket->id);

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
     * Tope de clientes distintos que devuelve el buscador de contactos.
     *
     * El buscador está para encontrar a alguien, no para paginar la cartera: con más de veinte
     * empresas en pantalla el operador afina el texto, que es más rápido que scrollear.
     */
    const CONTACT_SEARCH_MAX_CLIENTS = 20;

    /**
     * Tope de contactos que devuelve el buscador, sumando todos los clientes.
     *
     * Cada contacto cuesta una resolución de ventana de 24hs, así que el tope no es estético:
     * es lo que evita que una búsqueda de dos letras barra la base entera.
     */
    const CONTACT_SEARCH_MAX_CONTACTS = 60;

    /**
     * Busca contactos de WhatsApp para abrir un ticket: por dueño, empresa, empleado o teléfono.
     *
     * Hasta ahora el modal de alta pedía `GET /client`, se traía la cartera entera con todos sus
     * accessors y filtraba en el navegador: no sabía buscar por empleado ni por teléfono, y el
     * costo crecía con cada cliente nuevo.
     *
     * La fuente de los teléfonos es SIEMPRE ClientPhoneDirectory, que replica las tres formas en
     * que el webhook reconoce un número entrante. Si acá apareciera un teléfono que el directorio
     * no conoce, el webhook no lo iba a reconocer y la respuesta del cliente caería en el pipeline
     * de leads en vez de en el ticket: esa es la falla silenciosa que esta pantalla viene a evitar.
     *
     * @param Request $request Con `q`, el texto tipeado en el buscador.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function contact_search(Request $request)
    {
        $q = trim((string) $request->input('q', ''));

        // Menos de dos caracteres no es un error: es el estado inicial del input y el de la
        // primera tecla. Devolver la lista vacía con 200 evita que cada tecla dispare una
        // barrida de la base y que la pantalla tenga que distinguir "poco texto" de "falló".
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => [], 'truncated' => false], 200);
        }

        $q_lower = mb_strtolower($q);
        $q_digits = (string) preg_replace('/\D+/', '', $q);
        $like = '%' . $this->escapar_like($q_lower) . '%';

        // Por qué entró cada cliente en los resultados, y con qué matcheó exactamente. Se guarda
        // el motivo -y no solo el id- porque de él depende cuántos contactos de ese cliente se
        // muestran: buscar la empresa trae a todos, buscar un empleado trae solo a ese.
        $motivos = [];
        $empleados_que_matchearon = [];
        $telefonos_que_matchearon = [];

        // 1) Por la ficha del cliente: nombre del dueño o razón social.
        $por_ficha = Client::query()
            ->select('id', 'name', 'company_name')
            ->where(function ($sub_query) use ($like) {
                $sub_query->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(company_name) LIKE ?', [$like]);
            })
            ->get();

        foreach ($por_ficha as $fila) {
            $nombre = mb_strtolower(trim((string) ($fila->name ?? '')));
            $empresa = mb_strtolower(trim((string) ($fila->company_name ?? '')));

            // La base compara sin acentos y PHP no, así que puede matchear allá y no acá. Cuando
            // pasa se cae en `cliente`, que da exactamente el mismo conjunto de contactos que
            // `empresa`: lo único que cambia es la etiqueta con la que se explica el resultado.
            $motivo = ($empresa !== '' && mb_strpos($empresa, $q_lower) !== false
                && ($nombre === '' || mb_strpos($nombre, $q_lower) === false))
                ? 'empresa'
                : 'cliente';

            $motivos[(int) $fila->id][$motivo] = true;
        }

        // 2) Por el nombre de un empleado.
        $por_empleado = ClientEmployee::query()
            ->select('id', 'client_id')
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->get();

        foreach ($por_empleado as $fila) {
            $client_id = (int) $fila->client_id;
            $motivos[$client_id]['empleado'] = true;
            $empleados_que_matchearon[$client_id][(int) $fila->id] = true;
        }

        // 3) Por teléfono. Se exige un mínimo de cuatro dígitos porque con menos el LIKE le pega
        // a media base y devolver cien contactos es lo mismo que no devolver ninguno.
        if (strlen($q_digits) >= 4) {
            $like_digits = '%' . $q_digits . '%';

            foreach (Client::query()
                ->select('id', 'phone')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->whereRaw($this->solo_digitos_sql('phone') . ' LIKE ?', [$like_digits])
                ->get() as $fila) {
                $client_id = (int) $fila->id;
                $motivos[$client_id]['telefono'] = true;
                $telefonos_que_matchearon[$client_id][WhatsappNormalizer::normalize((string) $fila->phone)] = true;
            }

            foreach (ClientEmployee::query()
                ->select('id', 'client_id', 'phone')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->whereRaw($this->solo_digitos_sql('phone') . ' LIKE ?', [$like_digits])
                ->get() as $fila) {
                $client_id = (int) $fila->client_id;
                $motivos[$client_id]['telefono'] = true;
                $telefonos_que_matchearon[$client_id][WhatsappNormalizer::normalize((string) $fila->phone)] = true;
            }

            // El lead promovido es la tercera forma en que el webhook reconoce un número, así que
            // un teléfono que solo vive ahí tiene que ser buscable igual que los otros dos.
            foreach (Lead::query()
                ->select('id', 'promoted_client_id', 'phone')
                ->whereNotNull('promoted_client_id')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->whereRaw($this->solo_digitos_sql('phone') . ' LIKE ?', [$like_digits])
                ->get() as $fila) {
                $client_id = (int) $fila->promoted_client_id;
                $motivos[$client_id]['telefono'] = true;
                $telefonos_que_matchearon[$client_id][WhatsappNormalizer::normalize((string) $fila->phone)] = true;
            }
        }

        if (count($motivos) === 0) {
            return response()->json(['results' => [], 'truncated' => false], 200);
        }

        // Solo clientes activos. Al webhook un cliente dado de baja no lo reconoce en ninguna de
        // sus tres formas, así que abrirle una conversación garantiza que su respuesta caiga en
        // el pipeline de leads. El with() es para que phones_for_client() no dispare una consulta
        // de empleados por cliente.
        $clients = Client::query()
            ->whereIn('id', array_keys($motivos))
            ->where('is_active', true)
            ->with('client_employees')
            ->get()
            ->all();

        usort($clients, function ($a, $b) use ($q_lower) {
            $a_empieza = $this->empieza_con_la_busqueda($a, $q_lower) ? 0 : 1;
            $b_empieza = $this->empieza_con_la_busqueda($b, $q_lower) ? 0 : 1;

            if ($a_empieza !== $b_empieza) {
                return $a_empieza - $b_empieza;
            }

            return strcmp($this->clave_de_orden_del_cliente($a), $this->clave_de_orden_del_cliente($b));
        });

        $phone_directory = app(ClientPhoneDirectory::class);
        $window_service = app(WhatsappSessionWindowService::class);

        $results = [];
        $clientes_devueltos = 0;
        $truncated = false;

        foreach ($clients as $client) {
            if ($clientes_devueltos >= self::CONTACT_SEARCH_MAX_CLIENTS) {
                $truncated = true;
                break;
            }

            $client_id = (int) $client->id;
            $motivo = $this->motivo_ganador($motivos[$client_id]);
            $filas_del_cliente = [];

            foreach ($this->contactos_con_el_dueno_primero($phone_directory->phones_for_client($client)) as $contacto) {
                if (! $this->contacto_entra_en_el_resultado(
                    $contacto,
                    $motivo,
                    isset($empleados_que_matchearon[$client_id]) ? $empleados_que_matchearon[$client_id] : [],
                    isset($telefonos_que_matchearon[$client_id]) ? $telefonos_que_matchearon[$client_id] : []
                )) {
                    continue;
                }

                $filas_del_cliente[] = $this->fila_de_contacto($client, $contacto, $motivo, $window_service);
            }

            if (count($filas_del_cliente) === 0) {
                continue;
            }

            // El corte es por cliente entero y no a mitad de sus contactos: media empresa listada
            // se lee como si al operador le faltaran teléfonos cargados, no como un resultado
            // recortado.
            if (count($results) + count($filas_del_cliente) > self::CONTACT_SEARCH_MAX_CONTACTS) {
                $truncated = true;
                break;
            }

            $results = array_merge($results, $filas_del_cliente);
            $clientes_devueltos++;
        }

        return response()->json([
            'results'   => $results,
            'truncated' => $truncated,
        ], 200);
    }

    /**
     * Estado de la ventana de 24hs de Meta para el hilo que está abierto en pantalla.
     *
     * `whatsapp-contacts` ya devolvía la ventana, pero es POR CLIENTE y lo consume el modal de
     * alta: pedírselo desde la conversación obligaría a mandar el client_id y a quedarse con el
     * teléfono del ticket del lado del navegador. Este devuelve la del hilo abierto y nada más.
     *
     * La respuesta viaja con `expires_at` porque la ventana se vence sola con el tiempo: el SPA
     * la cierra comparando contra su propio reloj, sin volver a preguntar cada minuto. Se vuelve
     * a consultar solo cuando entra un mensaje del cliente, que es lo único que la puede reabrir.
     *
     * @param int|string $id Id del ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function whatsapp_window($id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $source = trim((string) ($ticket->source ?? ''));
        $phone = trim((string) ($ticket->whatsapp_phone ?? ''));

        // Un ticket que no es de WhatsApp no tiene ventana, y eso no es un error: la pantalla
        // simplemente no muestra el aviso. Con un 422 el SPA tendría que distinguir un ticket del
        // ERP de una caída de la API, y ante la duda terminaría escondiendo el aviso siempre.
        if ($source !== 'whatsapp' || $phone === '') {
            return response()->json([
                'source' => $source !== '' ? $source : null,
                'phone'  => null,
                'window' => null,
            ], 200);
        }

        return response()->json([
            'source' => $source,
            'phone'  => $phone,
            'window' => app(WhatsappSessionWindowService::class)->window_state($phone),
        ], 200);
    }

    /**
     * Reordena los contactos de un cliente para dejar al dueño arriba.
     *
     * ClientPhoneDirectory devuelve los EMPLEADOS primero, y con razón: el webhook los prioriza
     * al reconocer un número entrante, así el ticket queda atado al empleado y el hilo no se
     * parte. Pero para esta pantalla Lucas pidió lo contrario -"si escribe el nombre de la
     * empresa, me dé primero en las opciones el dueño de la empresa y después los empleados"-,
     * así que el orden se da vuelta acá y no en el directorio: cambiarlo allá le movería la
     * prioridad al webhook, que es lo único que no se puede tocar.
     *
     * @param array<int, array<string, mixed>> $contactos Filas de phones_for_client().
     *
     * @return array<int, array<string, mixed>> Las mismas filas, dueño primero.
     */
    private function contactos_con_el_dueno_primero(array $contactos): array
    {
        $duenos = [];
        $empleados = [];

        foreach ($contactos as $contacto) {
            if ($this->es_dueno((string) $contacto['origin'])) {
                $duenos[] = $contacto;
                continue;
            }

            $empleados[] = $contacto;
        }

        return array_merge($duenos, $empleados);
    }

    /**
     * Indica si un origen del directorio corresponde al dueño del negocio.
     *
     * `client` es el teléfono de la ficha y `lead` el del lead que se promovió a ese cliente:
     * los dos son la misma persona, el dueño. `employee` es el resto.
     *
     * @param string $origin Origen devuelto por ClientPhoneDirectory.
     *
     * @return bool
     */
    private function es_dueno(string $origin): bool
    {
        return $origin === 'client' || $origin === 'lead';
    }

    /**
     * Elige, entre los motivos por los que entró un cliente, el que decide qué contactos se ven.
     *
     * Gana el más amplio: quien escribe "distribuidora" y de paso le pega al nombre de un
     * empleado está buscando la empresa, y espera ver todos sus contactos, no uno solo.
     *
     * @param array<string, bool> $motivos_del_cliente Motivos acumulados para ese cliente.
     *
     * @return string cliente|empresa|empleado|telefono
     */
    private function motivo_ganador(array $motivos_del_cliente): string
    {
        foreach (['cliente', 'empresa', 'empleado'] as $candidato) {
            if (isset($motivos_del_cliente[$candidato])) {
                return $candidato;
            }
        }

        return 'telefono';
    }

    /**
     * Decide si un contacto puntual entra en la respuesta, según por qué entró su cliente.
     *
     * @param array<string, mixed> $contacto   Fila de phones_for_client().
     * @param string               $motivo     Motivo ganador del cliente.
     * @param array<int, bool>     $empleados  Ids de empleado que matchearon por nombre.
     * @param array<string, bool>  $telefonos  Teléfonos normalizados que matchearon.
     *
     * @return bool
     */
    private function contacto_entra_en_el_resultado(array $contacto, string $motivo, array $empleados, array $telefonos): bool
    {
        // Buscó por la ficha del cliente: se muestran todos sus contactos.
        if ($motivo === 'cliente' || $motivo === 'empresa') {
            return true;
        }

        $employee_id = $contacto['client_employee_id'] !== null ? (int) $contacto['client_employee_id'] : null;
        if ($employee_id !== null && isset($empleados[$employee_id])) {
            return true;
        }

        return isset($telefonos[(string) $contacto['phone']]);
    }

    /**
     * Arma la fila de respuesta de un contacto, con el estado de su ventana de 24hs.
     *
     * @param Client                      $client         Cliente dueño del contacto.
     * @param array<string, mixed>        $contacto       Fila de phones_for_client().
     * @param string                      $motivo         Por qué entró el cliente en los resultados.
     * @param WhatsappSessionWindowService $window_service Resolutor de la ventana (cachea por request).
     *
     * @return array<string, mixed>
     */
    private function fila_de_contacto(Client $client, array $contacto, string $motivo, WhatsappSessionWindowService $window_service): array
    {
        $client_name = trim((string) ($client->name ?? ''));
        $company_name = trim((string) ($client->company_name ?? ''));
        $origin = (string) $contacto['origin'];

        // La fila del dueño se etiqueta con el nombre de la persona y no con resolve_display_name(),
        // que devuelve la razón social: la pantalla ya usa la razón social como título del grupo, y
        // repetirla adentro deja al operador sin saber a quién le está por escribir.
        $label = (string) $contacto['label'];
        if ($origin === 'client' && $client_name !== '') {
            $label = $client_name;
        }

        return [
            'client_id'          => (int) $client->id,
            'client_name'        => $client_name !== '' ? $client_name : null,
            'company_name'       => $company_name !== '' ? $company_name : null,
            'label'              => $label,
            'phone'              => (string) $contacto['phone'],
            'raw_phone'          => (string) $contacto['raw_phone'],
            'client_employee_id' => $contacto['client_employee_id'] !== null ? (int) $contacto['client_employee_id'] : null,
            'origin'             => $origin,
            'is_owner'           => $this->es_dueno($origin),
            'match'              => $motivo,
            'window'             => $window_service->window_state((string) $contacto['phone']),
        ];
    }

    /**
     * Indica si el cliente EMPIEZA con lo que se buscó, para subirlo arriba de la lista.
     *
     * Quien tipea "dist" espera ver "Distribuidora del Sur" antes que "Panadería Distinguida":
     * el que arranca con el texto casi siempre es el que se estaba buscando.
     *
     * @param Client $client  Cliente a evaluar.
     * @param string $q_lower Búsqueda en minúsculas.
     *
     * @return bool
     */
    private function empieza_con_la_busqueda(Client $client, string $q_lower): bool
    {
        $nombre = mb_strtolower(trim((string) ($client->name ?? '')));
        $empresa = mb_strtolower(trim((string) ($client->company_name ?? '')));

        return ($nombre !== '' && mb_strpos($nombre, $q_lower) === 0)
            || ($empresa !== '' && mb_strpos($empresa, $q_lower) === 0);
    }

    /**
     * Clave alfabética de un cliente para el desempate del orden.
     *
     * Se ordena por lo mismo que la pantalla muestra como título del grupo -la razón social, y si
     * no hay, el nombre-, para que la lista se lea ordenada y no por una clave invisible. Sin
     * Collator: la extensión intl no está garantizada en el hosting y strcmp alcanza para esto.
     *
     * @param Client $client Cliente a ordenar.
     *
     * @return string
     */
    private function clave_de_orden_del_cliente(Client $client): string
    {
        $empresa = trim((string) ($client->company_name ?? ''));
        if ($empresa !== '') {
            return mb_strtolower($empresa);
        }

        return mb_strtolower(trim((string) ($client->name ?? '')));
    }

    /**
     * Expresión SQL que deja una columna de teléfono en puros dígitos, para comparar por LIKE.
     *
     * Los teléfonos se guardan como los tipeó cada uno -"341 555-1111", "(341) 555 1111",
     * "+5493415551111"-, así que un LIKE sobre la columna cruda solo encuentra a los que ya
     * estaban en E.164. Se usa REPLACE anidado y no REGEXP_REPLACE porque este último recién
     * existe en MySQL 8 y el hosting no siempre está ahí.
     *
     * @param string $columna Nombre de la columna, sin comillas.
     *
     * @return string
     */
    private function solo_digitos_sql(string $columna): string
    {
        $expresion = '`' . $columna . '`';

        foreach ([' ', '-', '(', ')', '+', '.'] as $caracter) {
            $expresion = "REPLACE(" . $expresion . ", '" . $caracter . "', '')";
        }

        return $expresion;
    }

    /**
     * Neutraliza los comodines de LIKE en el texto que tipeó el operador.
     *
     * Sin esto, buscar "%" devuelve la base entera y buscar "_" cualquier cosa de un carácter:
     * el buscador terminaría contestando con más filas cuanto menos específica es la búsqueda.
     *
     * @param string $texto Texto tipeado.
     *
     * @return string
     */
    private function escapar_like(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
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
        // Vía ::dispatch() y no event(new ...): el dispatch del evento pasa por BroadcastGuard,
        // así una falla de Pusher no voltea un ticket que ya quedó guardado.
        SupportTicketUpdated::dispatch((int) $ticket->id);

        return response()->json([
            'model' => $this->ticketQueryForInbox()->where('id', $ticket->id)->first(),
        ], 200);
    }
}

