<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Mail\Helpers\LeadPresentationMailHelper;
use App\Mail\Helpers\LeadFollowupMailHelper;
use App\Mail\Helpers\LeadDemoMailHelper;
use App\Models\Client;
use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use App\Models\LeadPartner;
use App\Models\LeadPersonalizedDemoVideo;
use App\Models\LeadPipelineStatus;
use App\Models\ProtocolEntry;
use App\Events\LeadAiSuggestionFinished;
use App\Events\LeadAiSuggestionGenerating;
use App\Services\LeadAiService;
use App\Services\LeadConversationErrorLogger;
use App\Services\LeadRecoveryReasonService;
use App\Services\WhatsappSendService;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadBroadcastService;
use App\Services\LeadConversationAiState;
use App\Services\LeadStatusCardsService;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\LeadWhatsAppPasteCleaner;
use App\Services\SeparadorDeMensajesManuales;
use App\Services\PromoteLeadService;
use App\Services\PromoteLeadToClientService;
use App\Services\LeadContractPdfService;
use App\Services\BatchLeadAiRecoveryService;
use App\Services\DemoHitosService;
use App\Services\DemoIngresoTokenService;
use App\Services\DemoPlanResolver;
use App\Services\LeadDemoFormMapper;
use App\Services\RunDemoSetupService;
use App\Services\RunUserSetupService;
use App\Services\CloserGoogleCalendarEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Panel de Leads (prospectos).
 *
 * Centraliza el alta del prospecto con todos los datos técnicos necesarios
 * para disparar la demo en el empresa-api elegido y para enviarle el mail
 * "tarjeta de presentación".
 *
 * Acciones estándar (CRUD) + dos acciones específicas:
 * - send_presentation_mail: envía el mail ComercioCity al prospecto.
 * - run_demo_setup: llama al empresa-api target para correr DemoSetupHelper::run.
 */
class LeadController extends Controller
{
    /** Valores válidos para el status del pipeline comercial + IA. */
    const STATUSES = [
        'nuevo'             => 'Nuevo',
        'contactado'        => 'Contactado',
        'calificado'        => 'Calificado',
        'demo_agendada'     => 'Demo agendada',
        'demo_realizada'    => 'Demo realizada',
        'mail2_enviado'     => 'Mail2 enviado',
        'cerrado_ganado'    => 'Cerrado ganado',
        'cerrado_perdido'   => 'Cerrado perdido',
        'en_pausa'          => 'En pausa',
    ];

    /** Tipos de negocio soportados por DemoSetupHelper, reflejados en el select del form. */
    const BUSINESS_TYPES = [
        'ferreteria'    => 'Ferretería - otro',
        // 'distribuidora' => 'Distribuidora',
        'ropa'          => 'Tienda de ropa',
        // 'forrajeria'    => 'Forrajería',
    ];

    /**
     * Eventos crudos que `demo_roadmap_json()` lee para contar el detalle del recorrido de un clip
     * (misión del 1/9/2026).
     *
     * 🔴 Los tres son de UX y NINGUNO mueve un hito, ni antes ni después de esta misión: caen en la
     * rama de "evento de negocio" de {@see \App\Services\DemoHitosService::aplicar()}, que busca
     * hitos con `evento_esperado` igual al nombre, y ningún hito del catálogo declara ninguno de
     * los tres. Se guardaban en crudo desde la misión 48 y no los leía nadie — el pedido de Lucas
     * ("*en el admin no estoy pudiendo ver esa información del recorrido de la demo*") era
     * exactamente eso: el dato estaba, faltaba mostrarlo.
     *
     * Leerlos acá NO los convierte en algo que mueva el estado del hito. `estado` sigue saliendo
     * de las dos marcas del hito y sigue sin poder retroceder; esto agrega detalle al costado.
     *
     * @var array<int, string>
     */
    const EVENTOS_DETALLE_RECORRIDO = [
        // Cuánto del video vio el lead: `datos.porcentaje`, entero de 1 a 99. Lo emite
        // `TarjetaClip.vue` de `empresa-spa` cada vez que el video cruza un décimo nuevo y al
        // pausar. El 100 no se emite: ése es `clip.terminado`, que sí mueve el hito a `parcial`.
        'clip.progreso',
        // El lead apretó "Probar" debajo del video y el tour arrancó: `datos.pasos`.
        'tour.iniciado',
        // El tour terminó, llegue o no hasta el final: `datos.completo` (bool), `datos.pasos`,
        // `datos.mostrados`, `datos.salteados`.
        'tour.completado',
    ];

    /**
     * Listado de leads con filtro básico por estado y client target.
     */
    public function index(Request $request)
    {
        $query = Lead::withAll()->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('target_client_id')) {
            $query->where('target_client_id', $request->input('target_client_id'));
        }

        $leads = $query->paginate(500)->withQueryString();
        $clients = Client::orderBy('name')->get();
        $statuses = self::STATUSES;

        return view('leads.index', compact('leads', 'clients', 'statuses'));
    }

    /**
     * Formulario de alta del lead. Trae los clients activos para el selector
     * de "empresa-api destino" y los diccionarios de estados / tipos de negocio.
     */
    public function create()
    {
        $clients = Client::where('is_active', true)->orderBy('name')->get();
        $statuses = self::STATUSES;
        $business_types = self::BUSINESS_TYPES;

        return view('leads.create', compact('clients', 'statuses', 'business_types'));
    }

    /**
     * Persistencia del lead nuevo. Guarda el admin que lo crea y defaultea
     * status en "nuevo" si no se indica otro.
     */
    public function store(Request $request)
    {
        $data = $this->extract_data($request);
        $data['created_by_admin_id'] = Auth::id();

        $lead = Lead::create($data);

        return redirect()->route('leads.show', $lead->id)->with('success', 'Lead creado.');
    }

    /**
     * Vista de detalle con botones para enviar el mail de presentación y
     * para disparar la demo remota en el empresa-api elegido.
     */
    public function show($id)
    {
        $lead = Lead::withAll()->findOrFail($id);
        $statuses = self::STATUSES;
        $business_types = self::BUSINESS_TYPES;

        return view('leads.show', compact('lead', 'statuses', 'business_types'));
    }

    /**
     * Formulario de edición: mismos datos que el de alta pero precargando el lead.
     */
    public function edit($id)
    {
        $lead = Lead::findOrFail($id);
        $clients = Client::where('is_active', true)->orderBy('name')->get();
        $statuses = self::STATUSES;
        $business_types = self::BUSINESS_TYPES;

        return view('leads.edit', compact('lead', 'clients', 'statuses', 'business_types'));
    }

    /**
     * Actualización del lead con los mismos campos que el store.
     *
     * Si la fecha de demo cambia, resetea `recordatorio_demo_enviado` para que el nuevo
     * horario también reciba su recordatorio automático pre-demo.
     */
    public function update(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);

        // Capturar demo_date original (raw string) antes de persistir para detectar cambio.
        $original_demo_date = $lead->getRawOriginal('demo_date');

        $data = $this->extract_data($request);
        $lead->update($data);

        // Si se reagendó la demo, resetear flags para que el nuevo horario reciba automatizaciones.
        if ($original_demo_date !== $lead->getRawOriginal('demo_date')) {
            $lead->update([
                'recordatorio_demo_enviado'   => false,
                'recordatorio_manana_enviado' => false,
                // Reprogramación del check de fin (grupo 307, prompt 01): si quedó seteada de una
                // conversación viva en el horario viejo, es un timestamp del pasado que nunca más
                // cae dentro de la ventana de ±2 minutos -- el check de fin quedaría trabado para
                // siempre. Al reagendar, vuelve a null para que el nuevo horario calcule su propio
                // objetivo (demo_datetime + duración) como cualquier demo sin reprogramar.
                'demo_fin_check_reprogramado_para' => null,
            ]);
        }

        return redirect()->route('leads.show', $lead->id)->with('success', 'Lead actualizado.');
    }

    /**
     * Borrado simple.
     */
    public function destroy($id)
    {
        $lead = Lead::findOrFail($id);
        $lead->delete();

        return redirect()->route('leads.index')->with('success', 'Lead eliminado.');
    }

    /**
     * Envía el mail "tarjeta de presentación" al email del lead.
     *
     * El disparo es manual desde la vista show. Se registra el momento de
     * éxito en `presentation_mail_sent_at` y, si falla, el mensaje queda en
     * `presentation_mail_last_error` para inspección.
     */
    public function send_presentation_mail($id)
    {
        $lead = Lead::findOrFail($id);

        // Precondición dura: sin email no hay forma de mandar el correo.
        if (empty($lead->email)) {
            return redirect()->route('leads.show', $lead->id)
                             ->with('error', 'El lead no tiene email cargado.');
        }

        try {
            Mail::to($lead->email)->send(LeadPresentationMailHelper::build($lead));
            $lead->update([
                'presentation_mail_sent_at' => now(),
                'presentation_mail_last_error' => null,
            ]);

            return redirect()->route('leads.show', $lead->id)
                             ->with('success', 'Mail de presentación enviado a ' . $lead->email);
        } catch (\Throwable $e) {
            Log::error('LeadController@send_presentation_mail error: ' . $e->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            $lead->update([
                'presentation_mail_last_error' => $e->getMessage(),
            ]);

            return redirect()->route('leads.show', $lead->id)
                             ->with('error', 'No se pudo enviar el mail: ' . $e->getMessage());
        }
    }

    /**
     * Envía el mail de seguimiento post-reunión al email del lead.
     *
     * Se utiliza para reforzar el cierre comercial compartiendo propuesta,
     * acceso al sistema y testimonio de cliente. Registra timestamp de éxito
     * y último error para trazabilidad operativa desde el panel.
     */
    public function send_followup_mail($id)
    {
        // Lead objetivo del envío de seguimiento.
        $lead = Lead::findOrFail($id);

        // Precondición dura: sin email cargado no se puede enviar el correo.
        if (empty($lead->email)) {
            return redirect()->route('leads.show', $lead->id)
                             ->with('error', 'El lead no tiene email cargado.');
        }

        try {
            Mail::to($lead->email)->send(LeadFollowupMailHelper::build($lead));
            $lead->update([
                'followup_mail_sent_at' => now(),
                'followup_mail_last_error' => null,
            ]);

            return redirect()->route('leads.show', $lead->id)
                             ->with('success', 'Mail de seguimiento enviado a ' . $lead->email);
        } catch (\Throwable $e) {
            Log::error('LeadController@send_followup_mail error: ' . $e->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            $lead->update([
                'followup_mail_last_error' => $e->getMessage(),
            ]);

            return redirect()->route('leads.show', $lead->id)
                             ->with('error', 'No se pudo enviar el mail de seguimiento: ' . $e->getMessage());
        }
    }

    /**
     * Dispara la ejecución remota de DemoSetupHelper en el empresa-api target.
     *
     * La lógica de integración vive en RunDemoSetupService para mantener el
     * controlador delgado y alineado con el resto de admin-api.
     */
    public function run_demo_setup($id, RunDemoSetupService $service)
    {
        $lead = Lead::findOrFail($id);

        $lead = $service->run($lead);

        if ($lead->demo_setup_status === 'exitoso') {
            return redirect()->route('leads.show', $lead->id)
                             ->with('success', 'Demo creada correctamente en el sistema destino.');
        }

        return redirect()->route('leads.show', $lead->id)
                         ->with('error', 'No se pudo crear la demo: ' . $lead->demo_setup_last_error);
    }

    /**
     * Muestra el formulario de promoción: solo pide la URL del nuevo empresa-api
     * de producción que el técnico ya instaló.
     * Solo se puede acceder si el Lead no fue promovido todavía.
     */
    public function promote($id)
    {
        $lead = Lead::findOrFail($id);

        // Si ya está en estado cerrado ganado (cliente en pipeline), no se vuelve a mostrar el formulario de promoción
        if ($lead->status === 'cerrado_ganado') {
            return redirect()->route('leads.show', $lead->id)
                             ->with('error', 'El lead ya fue promovido a cliente.');
        }

        return view('leads.promote', compact('lead'));
    }

    /**
     * Procesa la promoción del Lead: guarda API URL de producción y marca status "cliente".
     * El Client se crea al ejecutar user setup.
     *
     * @param PromoteLeadService $service
     */
    public function store_promote($id, Request $request, PromoteLeadService $service)
    {
        $lead = Lead::findOrFail($id);

        // api_url es el único input que necesitamos: la URL del nuevo sistema instalado
        $api_url = trim($request->input('api_url', ''));
        if (empty($api_url)) {
            return redirect()->route('leads.promote', $lead->id)
                             ->with('error', 'La URL del sistema es obligatoria.');
        }

        try {
            $lead = $service->promote($lead, $api_url);
        } catch (\Throwable $e) {
            Log::error('LeadController@store_promote error: ' . $e->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            return redirect()->route('leads.promote', $lead->id)
                             ->with('error', 'Error al promover: ' . $e->getMessage());
        }

        return redirect()->route('leads.show', $lead->id)
                         ->with('success', 'Lead promovido a cliente correctamente. Ahora podés crear el sistema real.');
    }

    /**
     * Dispara el user-setup en el empresa-api de producción del Lead promovido.
     *
     * @param RunUserSetupService $service
     */
    public function run_user_setup($id, RunUserSetupService $service)
    {
        $lead = Lead::findOrFail($id);

        $lead = $service->run($lead);

        if ($lead->user_setup_status === 'exitoso') {
            return redirect()->route('leads.show', $lead->id)
                             ->with('success', 'Sistema real creado correctamente.');
        }

        return redirect()->route('leads.show', $lead->id)
                         ->with('error', 'No se pudo crear el sistema: ' . $lead->user_setup_last_error);
    }

    /**
     * Renderiza en navegador el HTML real del "Mail 1 - DEMO" para revisión visual.
     *
     * Esta acción no envía correo; solo devuelve el Mailable armado con los
     * datos actuales del lead para validar diseño, textos y links.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Mail\Mailable
     */
    public function preview_demo_mail($id)
    {
        // Lead usado como fuente de datos para construir el mail preview.
        $lead = Lead::withAll()->findOrFail($id);

        // Devolver el mailable directamente permite previsualizar el blade en el browser.
        return LeadDemoMailHelper::build($lead);
    }

    // --- API JSON (admin-spa) ---

    /**
     * Listado JSON de leads para admin-spa con paginado opcional.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        // Tamaño de página configurable por la grilla del SPA.
        $per = (int) $request->input('per_page', 50);
        if ($per < 1) {
            $per = 20;
        }
        if ($per > 200) {
            $per = 200;
        }

        // Query base liviana: relaciones del lead + solo mensajes de notificación.
        $query = Lead::query()->withAllForList();

        // Orden de la bandeja (atención, fijados, desempate): delegado a Lead::scopeApplyBandejaOrder()
        // (prompt 286/02) para compartir el mismo criterio con SearchController — antes estaba
        // duplicado en los dos y ya había divergido. Default sigue siendo 'last_message'.
        $sort_by = (string) $request->input('sort_by', 'last_message');
        $query->applyBandejaOrder($sort_by);

        // Filtro por estado comercial. Acepta un status único o una lista separada por comas
        // (ej. "demo_agendada,demo_en_curso") para traer leads en varios estados del pipeline a la vez.
        if ($request->filled('status')) {
            $status_param = (string) $request->input('status');
            if (strpos($status_param, ',') !== false) {
                $statuses = array_values(array_filter(array_map('trim', explode(',', $status_param))));
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $status_param);
            }
        }

        // Filtro por sistema destino.
        if ($request->filled('target_client_id')) {
            $query->where('target_client_id', (int) $request->input('target_client_id'));
        }

        // Contrato estándar: si viene page => paginado, caso contrario colección completa.
        if ($request->has('page')) {
            $models = $query->paginate($per);
        } else {
            $models = $query->get();
        }

        $this->prepare_leads_for_list_json($models);

        return response()->json(['models' => $models], 200);
    }

    /**
     * Normaliza leads de listado: mensajes de notificación bajo `messages` y metadata de alcance.
     * Agrega is_notified_by_me a cada lead usando una sola query contra lead_admin_notifications.
     *
     * @param \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection|\Illuminate\Contracts\Pagination\Paginator $models
     *
     * @return void
     */
    protected function prepare_leads_for_list_json($models)
    {
        Lead::prepare_collection_for_list_json($models);

        /* Obtiene todos los lead_id del listado. */
        $items    = method_exists($models, 'items') ? $models->items() : $models->all();
        $lead_ids = array_map(function ($lead) {
            return $lead->id;
        }, $items);

        if (empty($lead_ids)) {
            return;
        }

        /* Una sola query para saber qué leads tienen suscripto al admin autenticado. */
        $notified = \Illuminate\Support\Facades\DB::table('lead_admin_notifications')
            ->where('admin_id', Auth::id())
            ->whereIn('lead_id', $lead_ids)
            ->pluck('lead_id')
            ->flip()
            ->toArray();

        /* Asigna is_notified_by_me a cada lead del listado. */
        foreach ($items as $lead) {
            $lead->is_notified_by_me = isset($notified[$lead->id]);
        }
    }

    /**
     * Agrega al lead el link completo de ingreso a la demo, para el bloque "Link de ingreso a la
     * demo" del modal (`lead_demo_ingreso_link` en LeadProperties). El accesor ya normaliza el
     * esquema con DemoUrlNormalizer, así que lo que sale de acá es navegable tal cual.
     *
     * 🔴 Este es el ÚNICO lugar del controlador donde se decide que el link viaja. Está separado
     * a propósito de prepare_lead_for_detail_json(): los endpoints que cambian el token también
     * lo necesitan y NO pueden usar aquel método (ver full_lead_with_demo_link()).
     *
     * 🔴 A propósito NO se agrega a un `$appends` del modelo. El accesor lee `$this->demo`, así
     * que en `$appends` correría en todos los lugares donde el modelo se serializa entero — el
     * listado de leads, el `broadcastWith()` de LeadSuggestionCreated (que emite sobre un
     * `Channel` público de Pusher) y los endpoints públicos de DemoExperienciaController —,
     * sumando una query por lead donde la relación no esté cargada y, peor, mandando el link CON
     * el token en claro a payloads que hoy no lo llevan.
     *
     * 🔴 Y es `append()` de instancia, no una asignación (`$lead->demo_ingreso_url = ...`). La
     * asignación mete una clave que no es columna adentro de `$attributes` y deja el modelo
     * *dirty*: el día que alguien agregue un `save()` después del llamado, el UPDATE incluiría
     * `demo_ingreso_url` y saldría `Unknown column`. `append()` produce el mismo JSON sin
     * ensuciar los atributos.
     *
     * @param \App\Models\Lead|null $lead
     *
     * @return \App\Models\Lead|null El mismo lead recibido.
     */
    protected function append_demo_ingreso_url(?Lead $lead)
    {
        if (! $lead) {
            return null;
        }

        $lead->append('demo_ingreso_url');

        return $lead;
    }

    /**
     * Lead completo en formato fullModel CON el link de ingreso ya appendeado. Es lo que tienen
     * que devolver los endpoints que cambian el token de ingreso (run-demo-setup,
     * demo-token/reemitir, demo-token/revocar).
     *
     * Por qué existe, medido el 26/8/2026: cada corrida del setup y cada reemisión emiten un
     * token nuevo y la instancia BORRA el anterior. El link no es una columna sino un accesor,
     * así que en un `fullModel()` pelado la clave `demo_ingreso_url` no viaja — y el SPA fusiona
     * la respuesta con `Object.assign()`, que deja intactas las claves ausentes. Resultado: el
     * modal mostraba el token nuevo en un campo y el link VIEJO (ya muerto) en el otro, que es
     * justo el que se copia y se le manda al lead.
     *
     * 🔴 Acá NO se llama a prepare_lead_for_detail_json(), por más que parezca el mismo trabajo y
     * dé ganas de unificarlos. Ese método además hace `mark_messages_scope('full')`, y el front
     * lee esa marca: la mutación `update_lead_en_conversacion` del store REEMPLAZA el hilo de
     * mensajes cuando el scope viene en 'full' y lo FUSIONA cuando no. Las acciones de token se
     * disparan desde el panel lateral de WhatsApp y commitean esa misma mutación, así que mandar
     * el scope completo desde acá cambiaría el hilo de fusionar a reemplazar —perdiendo lo que el
     * panel tenga en vuelo— a cambio de nada para este arreglo.
     *
     * @param int|string $id Identificador del lead.
     *
     * @return \App\Models\Lead|null
     */
    protected function full_lead_with_demo_link($id)
    {
        return $this->append_demo_ingreso_url($this->fullModel('lead', $id));
    }

    /**
     * Marca un lead de detalle con alcance completo de mensajes e incluye
     * si el admin autenticado está suscrito a notificaciones WhatsApp del lead.
     *
     * @param \App\Models\Lead|null $lead
     *
     * @return \App\Models\Lead|null
     */
    protected function prepare_lead_for_detail_json(?Lead $lead)
    {
        if (! $lead) {
            return null;
        }

        $lead->mark_messages_scope('full');

        /* Indica si el admin autenticado tiene activa la suscripción WhatsApp para este lead. */
        $lead->is_notified_by_me = \Illuminate\Support\Facades\DB::table('lead_admin_notifications')
            ->where('lead_id', $lead->id)
            ->where('admin_id', Auth::id())
            ->exists();

        /* Link completo de ingreso a la demo. La regla vive en append_demo_ingreso_url(): acá se
           llama y nada más, para que el detalle y los endpoints de acción no se desincronicen. */
        $this->append_demo_ingreso_url($lead);

        /* Link público de la página de experiencia (`/experiencia/{uuid}` de admin-spa). Va
           appendeado ACÁ y no adentro de append_demo_ingreso_url(): ese helper lo comparten los
           endpoints que rotan el token, y este link no depende del token —sale del uuid, que no
           cambia nunca—, así que no tiene por qué viajar en esas respuestas. Y tampoco va en un
           `$appends` del modelo, por el mismo motivo que el otro: correría en el listado y en el
           broadcast, donde nadie lo consume, sin que nada lo pida. */
        $lead->append('demo_experiencia_url');

        /* Estado de la tarjeta "Respuestas del formulario de la demo" (misión del 27/8/2026). Va
           acá por el mismo criterio que la línea de arriba —el detalle es el único lugar donde el
           modal lo consume— y en `append()` y no en una asignación: `demo_form_panel` no es una
           columna, así que asignarlo lo metería en `$attributes` y el próximo `save()` sobre este
           lead saldría con un `Unknown column` en el UPDATE. */
        $lead->append('demo_form_panel');

        return $lead;
    }

    /**
     * Devuelve un lead puntual en formato fullModel para alinear relaciones del recurso.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        // Modelo completo alineado al estándar del proyecto.
        $model = $this->fullModel('lead', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        $this->prepare_lead_for_detail_json($model);

        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea un lead desde admin-spa.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Data saneada desde request con el mismo mapping del flujo Blade.
        $data = $this->extract_data($request);
        // Admin autenticado que crea el lead desde SPA.
        $data['created_by_admin_id'] = Auth::id();

        // Persistencia principal del lead.
        $lead = Lead::create($data);
        $this->sync_personalized_demo_videos_from_request($lead, $request);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 201);
    }

    /**
     * Actualiza un lead desde admin-spa.
     *
     * Si la fecha de demo cambia, resetea `recordatorio_demo_enviado` para que el nuevo
     * horario también reciba su recordatorio automático pre-demo.
     *
     * Y si cambian los checkboxes `use_deposits` / `use_price_lists` —las dos únicas respuestas
     * del formulario de la demo que este endpoint genérico puede escribir—, deja la edición
     * marcada como manual: ver {@see self::marcar_edicion_manual_del_formulario()}.
     *
     * @param Request $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        // Registro objetivo de edición.
        $lead = Lead::findOrFail($id);

        // Capturar demo_date original (raw string) antes de persistir para detectar cambio.
        $original_demo_date = $lead->getRawOriginal('demo_date');

        /* Foto de las dos respuestas del formulario que este endpoint puede escribir, tomada
           ANTES de aplicar el request. Se comparan las COLUMNAS y no las respuestas efectivas:
           `Lead::booted()` fuerza `use_deposits = true` al crear el lead mientras que el default
           del catálogo es `usa_depositos => false`, así que un lead sin formulario tiene la
           columna y la respuesta efectiva en desacuerdo desde que nace — comparar contra la
           efectiva marcaría edición manual en cada guardado del modal, sin que nadie toque nada.
           Ver `marcar_edicion_manual_del_formulario()`. */
        $formulario_previo = [
            'use_deposits'    => (bool) $lead->use_deposits,
            'use_price_lists' => (bool) $lead->use_price_lists,
        ];

        // Política funcional: user_id ya no se define en alta/edición de lead.
        // Se asigna recién en la promoción a Client.
        $request->request->remove('user_id');

        // Seteamos campos usando helper declarativo para respetar properties().
        ModelPropertiesHelper::set_from_request($lead, $request, 'lead');
        $this->sync_personalized_demo_videos_from_request($lead, $request);

        // Si se reagendó la demo, resetear flags para que el nuevo horario reciba automatizaciones.
        // Recargar el lead desde DB para leer el demo_date ya persistido por set_from_request.
        $lead->refresh();
        if ($original_demo_date !== $lead->getRawOriginal('demo_date')) {
            $lead->update([
                'recordatorio_demo_enviado'   => false,
                'recordatorio_manana_enviado' => false,
                // Reprogramación del check de fin (grupo 307, prompt 01): si quedó seteada de una
                // conversación viva en el horario viejo, es un timestamp del pasado que nunca más
                // cae dentro de la ventana de ±2 minutos -- el check de fin quedaría trabado para
                // siempre. Al reagendar, vuelve a null para que el nuevo horario calcule su propio
                // objetivo (demo_datetime + duración) como cualquier demo sin reprogramar.
                'demo_fin_check_reprogramado_para' => null,
            ]);
        }

        $this->marcar_edicion_manual_del_formulario($lead, $formulario_previo);

        return response()->json(['model' => $this->fullModel('lead', $id)], 200);
    }

    /**
     * Deja constancia de que el "Guardar" general del modal tocó una respuesta del formulario de
     * la demo, para que el demo setup la respete (27/8/2026).
     *
     * 🔴 POR QUÉ EXISTE
     * -----------------
     * `use_deposits` y `use_price_lists` son a la vez dos checkboxes del grupo Demo del meta
     * (`LeadProperties`) y dos de las nueve respuestas del formulario de la demo (`usa_depositos`
     * y `tipo_precios`, ver `LeadDemoFormMapper`). Sin esta marca, tocar el checkbox escribía la
     * columna y no pasaba nada más: `LeadDemoFormMapper::respuestas_efectivas()` decide con
     * `demo_form_completado_at` / `demo_form_editado_admin_at`, así que seguía devolviendo los
     * defaults del catálogo, el demo setup armaba la instancia ignorando el cambio y la tarjeta de
     * al lado mostraba el valor viejo. Dos controles sobre el mismo dato y sólo uno contaba.
     *
     * Se intentó cerrarlo sacando los dos checkboxes del meta; se revirtió el mismo día porque la
     * tarjeta no los reemplaza (el porqué completo está en `LeadProperties`, arriba de
     * `use_deposits`). Ésta es la otra forma de cerrarlo: que el guardado general también cuente.
     *
     * 🔴 POR QUÉ MARCA Y NADA MÁS, SIN EL MERGE QUE HACE `update_demo_form_json()`
     * ----------------------------------------------------------------------------
     * La pregunta es legítima y hay que contestarla, porque marcar tiene un efecto lateral:
     * `respuestas_efectivas()` deja de devolver los defaults del catálogo y pasa a devolver
     * `from_lead()`, o sea las nueve columnas crudas. Si las columnas no fueran las del catálogo,
     * tildar un checkbox cambiaría de rebote respuestas que nadie tocó.
     *
     * No pasa, y no por casualidad: la migración `2026_07_31_160000_add_demo_form_fields_to_leads_table`
     * creó las seis columnas nuevas con el default DOCUMENTADO EN EL CATÁLOGO y no con `false`
     * (verificado contra el esquema el 27/8/2026: `descuentos_por_metodo_pago`,
     * `usa_cuentas_corrientes_proveedores`, `registra_compras` y `usa_ecommerce` en 1;
     * `costos_en_dolares` y `usa_presupuestos` en 0). Súmese `omitir_cuentas_corrientes` en 0
     * —o sea `usa_cuentas_corrientes_clientes` en `true`, el default del catálogo— y
     * `use_price_lists` en 0 (`tipo_precios => unico`, ídem).
     *
     * La ÚNICA respuesta en la que un lead sin formulario tiene la columna en desacuerdo con el
     * catálogo es `usa_depositos`: `Lead::booted()` fuerza `use_deposits = true` al crear el lead
     * y el catálogo dice `false`. Y ésa es justamente la que el checkbox de al lado le está
     * mostrando a Lucas TILDADA. Hacer el merge sobre `respuestas_efectivas()` —como sí hace
     * `update_demo_form_json()`, que no tiene ningún checkbox al lado— destildaría ese checkbox
     * solo al guardar, y encima sobre una columna que `RunUserSetupService::build_payload()` manda
     * al setup del cliente real. Marcar y nada más deja el modal coherente consigo mismo: después
     * de guardar, la tarjeta dice lo mismo que el checkbox que Lucas estaba mirando.
     *
     * ⚠️ Esa igualdad columnas/catálogo se sostiene a mano, no por construcción: si algún día se
     * cambia un default en `demo_catalogo.md` §2 sin una migración que acompañe (o al revés),
     * este método pasa a poder mover respuestas que nadie tocó y hay que revisarlo.
     *
     * 🔴 POR QUÉ SÓLO PARA LA DINÁMICA NUEVA
     * ---------------------------------------
     * Para un lead de la dinámica ACTUAL la marca no la mira nadie: los tres consumidores de
     * `respuestas_efectivas()` se cortan antes con su propia guardia de dinámica
     * (`RunDemoSetupService::respuestas_para_payload()` devuelve `[]`,
     * `DemoPlanResolver::congelar_en_memoria()` y `DemoHitosService::generar()` devuelven en la
     * primera línea), y la tarjeta del modal muestra sólo el cartel. Escribirla igual no sería
     * inocuo: el interruptor por lead (`set_demo_experiencia_json`) permite pasar un lead de
     * `actual` a `nueva`, y ese lead llegaría a la dinámica nueva con las nueve respuestas dadas
     * por buenas cuando en realidad nunca hubo formulario — que es justo lo que la distinción
     * entre "contestó que no" y "no contestó" existe para evitar.
     *
     * NO re-congela el roadmap ni escribe en el hilo del lead, a diferencia de
     * `update_demo_form_json()`: éste es el guardado genérico del modal y no puede convertirse en
     * una acción de demo. El plan lo congela quien corresponde —el formulario del lead, o
     * `RunDemoSetupService::congelar_plan_si_falta()`, que resuelve con `respuestas_efectivas()` y
     * por lo tanto ya respeta esta edición—.
     *
     * @param Lead                $lead              Lead ya persistido por `set_from_request()`.
     * @param array<string, bool> $formulario_previo Valor de las dos columnas antes del request.
     *
     * @return void
     */
    protected function marcar_edicion_manual_del_formulario(Lead $lead, array $formulario_previo)
    {
        if (! $lead->usa_experiencia_demo_nueva()) {
            return;
        }

        /* Comparación de columna contra columna, que es lo único que distingue "Lucas tocó el
           checkbox" de "Lucas guardó el modal con el checkbox como estaba". El SPA manda el
           borrador entero en cada guardado, así que las dos claves llegan SIEMPRE. */
        $use_deposits    = (bool) $lead->use_deposits;
        $use_price_lists = (bool) $lead->use_price_lists;

        if ($use_deposits === $formulario_previo['use_deposits']
            && $use_price_lists === $formulario_previo['use_price_lists']
        ) {
            return;
        }

        $lead->demo_form_editado_admin_at = now();
        $lead->save();

        Log::info('Se editaron respuestas del formulario de la demo desde el "Guardar" general del modal del lead.', [
            'lead_id'         => $lead->id,
            'use_deposits'    => $use_deposits,
            'use_price_lists' => $use_price_lists,
        ]);
    }

    /**
     * Elimina un lead desde admin-spa.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        // Lead objetivo de eliminación.
        $lead = Lead::findOrFail($id);
        $lead->delete();

        return response()->json(null, 204);
    }

    /**
     * Genera y descarga el PDF del contrato ComercioCity para el lead.
     *
     * Lee los campos `contract_*` del lead y delega en {@see LeadContractPdfService}.
     *
     * El interruptor `incluir_firma` es una opción de ESTA generación, no un dato del contrato:
     * por eso no entra en `build_contract_payload()` del PUT del lead ni se persiste en la tabla
     * (guardarlo pediría una migración y no es lo que se pidió). El default `true` mantiene el
     * contrato de API compatible hacia atrás: una SPA vieja que mande `{}` recibe el PDF con la
     * firma, que es el comportamiento deseado.
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string               $id Identificador del lead.
     *
     * @return \Illuminate\Http\Response
     */
    public function generate_contract_json(Request $request, $id)
    {
        // Lead con datos de contrato persistidos en la tabla.
        $lead = Lead::findOrFail($id);

        // Si estampa la firma del PRESTADOR en esta generación.
        $incluir_firma = $request->boolean('incluir_firma', true);

        try {
            // Contenido binario del PDF generado con dompdf.
            $pdf_content = LeadContractPdfService::generate($lead, $incluir_firma);
        } catch (\Throwable $error) {
            Log::error('LeadController@generate_contract_json error: ' . $error->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            return response()->json([
                'message' => 'No se pudo generar el contrato: ' . $error->getMessage(),
            ], 422);
        }

        return response($pdf_content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="contrato_' . $lead->id . '.pdf"',
        ]);
    }

    /**
     * Envía el mail de presentación desde admin-spa.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_presentation_mail_json($id)
    {
        // Lead sobre el cual se ejecuta la acción.
        $lead = Lead::findOrFail($id);

        // Validación mínima: el email es obligatorio para enviar correo.
        if (empty($lead->email)) {
            return response()->json(['message' => 'El lead no tiene email cargado.'], 422);
        }

        try {
            Mail::to($lead->email)->send(LeadPresentationMailHelper::build($lead));
            $lead->update([
                'presentation_mail_sent_at' => now(),
                'presentation_mail_last_error' => null,
            ]);
        } catch (\Throwable $error) {
            Log::error('LeadController@send_presentation_mail_json error: ' . $error->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            $lead->update([
                'presentation_mail_last_error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar el mail: ' . $error->getMessage(),
                'model' => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Envía el mail de seguimiento desde admin-spa.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_followup_mail_json($id)
    {
        // Lead sobre el cual se ejecuta la acción.
        $lead = Lead::findOrFail($id);

        // Validación mínima: el email es obligatorio para enviar correo.
        if (empty($lead->email)) {
            return response()->json(['message' => 'El lead no tiene email cargado.'], 422);
        }

        try {
            // Envío del mailable de propuesta (Mail 2) al destinatario del lead.
            Mail::to($lead->email)->send(LeadFollowupMailHelper::build($lead));

            /**
             * En Laravel con transportes tipo SwiftMailer, puede haber fallas de
             * destinatario sin excepción. Si el método existe, validamos el array
             * de failures para evitar marcar "éxito" cuando el envío fue rechazado.
             */
            if (method_exists(Mail::getFacadeRoot(), 'failures')) {
                // Lista de direcciones rechazadas por el transporte.
                $mailer_failures = Mail::failures();
                if (!empty($mailer_failures)) {
                    throw new \RuntimeException('Destinatario rechazado por el servidor SMTP: ' . implode(', ', $mailer_failures));
                }
            }

            // Registro de éxito real: fecha de envío y limpieza de error previo.
            $lead->update([
                'followup_mail_sent_at' => now(),
                'followup_mail_last_error' => null,
            ]);
        } catch (\Throwable $error) {
            Log::error('LeadController@send_followup_mail_json error: ' . $error->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            $lead->update([
                'followup_mail_last_error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar el mail de seguimiento: ' . $error->getMessage(),
                'model' => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Envía el "Mail 1 - DEMO" al prospecto desde admin-spa.
     *
     * Valida que el lead tenga todos los datos necesarios para la demo antes
     * de disparar el correo. Registra timestamp de éxito y último error para
     * trazabilidad desde el panel.
     *
     * Campos requeridos: contact_name, email, doc_number,
     * demo_id, demo_date, demo_start_time, demo_end_time.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_demo_mail_json($id)
    {
        // Lead objetivo del envío del mail de demo.
        $lead = Lead::withAll()->findOrFail($id);

        // Validación de campos obligatorios para la demo antes de enviar el correo.
        $missing = [];
        if (empty($lead->contact_name))   { $missing[] = 'nombre'; }
        if (empty($lead->email))          { $missing[] = 'email'; }
        if (empty($lead->doc_number))     { $missing[] = 'documento'; }
        if (empty($lead->demo_id))        { $missing[] = 'demo asignada'; }
        if (empty($lead->demo_date))      { $missing[] = 'fecha demo'; }
        if (empty($lead->demo_start_time)) { $missing[] = 'hora inicio'; }
        if (empty($lead->demo_end_time))  { $missing[] = 'hora fin'; }

        if (!empty($missing)) {
            return response()->json([
                'message' => 'Faltan los siguientes campos: ' . implode(', ', $missing) . '.',
            ], 422);
        }

        try {
            Mail::to($lead->email)->send(LeadDemoMailHelper::build($lead));
            $lead->update([
                'demo_mail_sent_at'   => now(),
                'demo_mail_last_error' => null,
            ]);
        } catch (\Throwable $error) {
            Log::error('LeadController@send_demo_mail_json error: ' . $error->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            $lead->update([
                'demo_mail_last_error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar el mail de demo: ' . $error->getMessage(),
                'model'   => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Ejecuta demo-setup remoto desde admin-spa.
     *
     * @param int|string $id
     * @param RunDemoSetupService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function run_demo_setup_json($id, RunDemoSetupService $service)
    {
        // Lead objetivo para proceso demo en sistema destino.
        $lead = Lead::findOrFail($id);
        // Ejecución encapsulada en servicio para mantener controlador liviano.
        $lead = $service->run($lead);

        /* Con el link appendeado, y también en la rama 422: el setup emite el token nuevo ANTES de
           llamar a la instancia, así que un setup fallido igual dejó el link viejo muerto. */
        if ($lead->demo_setup_status === 'exitoso') {
            return response()->json(['model' => $this->full_lead_with_demo_link($lead->id)], 200);
        }

        return response()->json([
            'message' => 'No se pudo crear la demo: ' . $lead->demo_setup_last_error,
            'model' => $this->full_lead_with_demo_link($lead->id),
        ], 422);
    }

    /**
     * PUT /api/admin/lead/{id}/demo-form — guarda a mano las respuestas del formulario de
     * configuración de la demo, desde la tarjeta del modal del lead (misión del 27/8/2026).
     *
     * Pedido de Lucas: *"yo quiero también desde ahí poder modificar las respuestas de ese
     * formulario, ya sea que el lead le haya contestado o que estén por defecto (...) para que
     * cuando ejecute correr demo setup de forma manual, utilice esos datos"*.
     *
     * Es el espejo administrativo de `DemoExperienciaController::store_formulario_json()`, y lo es
     * a propósito hasta en las reglas de validación: son dos puertas al MISMO estado (las columnas
     * del lead más el plan congelado), y si aceptaran cosas distintas el panel podría dejar el lead
     * en un estado que la página pública nunca produce. Las tres diferencias, todas deliberadas:
     *
     *  1. Marca `demo_form_editado_admin_at` y NO `demo_form_completado_at`. Aquella fecha
     *     significa "contestó el lead" y además mueve el disparo automático del setup
     *     (`RunDemoSetupService::evaluar_disparo()` cambia de rama según ella).
     *  2. El merge va sobre `respuestas_efectivas()` y no sobre `from_lead()`. Para un lead sin
     *     formulario la tarjeta le mostró a Lucas los DEFAULTS del catálogo; al guardar tienen que
     *     persistirse esos defaults más lo que él haya cambiado. Con `from_lead()` se guardarían
     *     las columnas crudas —casi todas apagadas—, que no es lo que vio en pantalla: cambiaría
     *     una sola respuesta y se llevaría puestas otras cuatro sin tocarlas.
     *  3. Re-congela el roadmap en vez de sólo dejar constancia de la divergencia, pero SÓLO si el
     *     plan YA estaba congelado y el setup sigue en `pendiente`. Nunca lo congela por primera
     *     vez: eso le corresponde al formulario del lead, o al demo setup si el lead no contesta
     *     nunca (ver el bloque grande arriba de la transacción, que explica por qué).
     *
     * No pisa nada del lead: si después de esta edición el lead completa el formulario en la
     * página, sus respuestas ganan, igual que hoy. La tarjeta muestra las dos fechas para que se
     * vea qué pasó.
     *
     * @param int|string $id      Identificador del lead.
     * @param Request    $request Body: cualquier subconjunto de las nueve respuestas.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_demo_form_json($id, Request $request)
    {
        /* Lead al que se le editan las respuestas. */
        $lead = Lead::findOrFail($id);

        /* Un lead de la dinámica ACTUAL no tiene página inmersiva ni formulario: no hay respuestas
           que editar, y dejarlo escribir las columnas armaría un estado que ningún otro camino
           produce. Es la misma guardia con la que ya se defienden solos DemoPlanResolver y
           DemoHitosService, acá con un mensaje que el modal pueda mostrar. */
        if (! $lead->usa_experiencia_demo_nueva()) {
            return response()->json([
                'message' => 'Este lead usa la dinámica de demo actual, que no tiene formulario de configuración.',
            ], 422);
        }

        /* Mismas reglas que el endpoint público, incluido el `sometimes` de las nueve: la tarjeta
           manda sólo lo que cambió, y una clave ausente significa "dejala como está", no "apagala". */
        $validated = $request->validate([
            'tipo_precios'                       => 'sometimes|string|in:unico,listas',
            'usa_depositos'                      => 'sometimes|boolean',
            'usa_cuentas_corrientes_clientes'    => 'sometimes|boolean',
            'costos_en_dolares'                  => 'sometimes|boolean',
            'descuentos_por_metodo_pago'         => 'sometimes|boolean',
            'usa_cuentas_corrientes_proveedores' => 'sometimes|boolean',
            'usa_presupuestos'                   => 'sometimes|boolean',
            'registra_compras'                   => 'sometimes|boolean',
            'usa_ecommerce'                      => 'sometimes|boolean',
        ]);

        /* Las respuestas de ANTES de tocar nada, y se sacan acá porque después de `to_lead()` ya
           no hay forma de reconstruirlas: se necesitan para el merge Y para contar en el hilo qué
           cambió. Es además la misma foto que la tarjeta le mostró a Lucas.

           El estado del roadmap (`demo_setup_status`, `demo_plan_congelado_at`) NO se toma de acá:
           se relee bajo lock dentro de la transacción, ver el bloque de más abajo. */
        $respuestas_previas = LeadDemoFormMapper::respuestas_efectivas($lead);

        $respuestas = array_merge($respuestas_previas, $validated);

        LeadDemoFormMapper::to_lead($lead, $respuestas);

        $lead->demo_form_editado_admin_at = now();

        /* 🔴 LA REGLA DEL ROADMAP, Y POR QUÉ NO ES "CONGELAR SIEMPRE" (27/8/2026)
           ----------------------------------------------------------------------------------
           El panel re-congela el plan SÓLO si YA estaba congelado y el setup sigue en
           `pendiente`. Nunca lo congela por primera vez. Son dos mitades con motivos distintos:

           1. **Re-congela si ya estaba congelado y el setup sigue pendiente.** Con el setup ya
              corrido el lead puede tener hitos marcados y clips vistos: rehacer el plan los
              dejaría apuntando a clips que salieron del recorrido, que es justo lo que la regla
              de "nunca retroceder" de la misión 48 prohíbe. La tarjeta avisa en ese caso que el
              recorrido quedó armado con las respuestas viejas.

           2. **NO lo congela por primera vez.** Y esto es lo que alguien va a querer "completar"
              porque parece un agujero, así que va escrito: `DemoPlanResolver::congelar_en_memoria()`
              congela UNA sola vez y después no re-resuelve nunca (con `demo_plan_congelado_at`
              seteada se va sin hacer nada). Si el panel congelara el plan de un lead que todavía
              no completó el formulario, el lead que entra después y contesta distinto pisaría las
              columnas —el endpoint público hace merge y el lead siempre gana— pero se quedaría
              con el roadmap del admin para siempre. Caso medido: el admin contesta "No" a
              compras, el lead contesta "Sí", y el payload sale contradiciéndose
              (`respuestas_formulario.registra_compras = true` con un `demo_plan` armado con
              `false`), mientras el lead nunca ve en su recorrido lo que pidió.

              No congelar no pierde nada: si el lead contesta, lo congela su propio formulario
              (camino normal); si no contesta nunca, lo congela
              `RunDemoSetupService::congelar_plan_si_falta()` al armar la instancia — y ése
              resuelve con `respuestas_efectivas()`, o sea que YA respeta esta edición manual.
              Así "gana el lead" vale también para el roadmap. */
        \Illuminate\Support\Facades\DB::transaction(function () use ($lead) {
            /* El estado del roadmap se relee ACÁ, bajo `lockForUpdate()` y adentro de la
               transacción, y no se toma de la foto de arriba. El comando `leads:run-demo-setup`
               corre cada minuto y reclama leads en `pendiente`: sin lock, el tick puede reclamar
               el lead y armar el payload con el plan viejo mientras esta transacción lo borra y
               congela otro — y la instancia queda con un roadmap que ya no existe. El lock
               serializa las dos y la que llega segunda decide con el estado real. Es el mismo
               patrón, y por el mismo motivo, que ya documenta
               `DemoPlanResolver::congelar_en_memoria()` para `demo_plan_congelado_at`. */
            $fila = \Illuminate\Support\Facades\DB::table('leads')
                ->where('id', $lead->id)
                ->lockForUpdate()
                ->first(['demo_setup_status', 'demo_plan_congelado_at']);

            $setup_estado = ($fila !== null && $fila->demo_setup_status !== null)
                ? $fila->demo_setup_status
                : 'pendiente';

            $plan_estaba_congelado = ($fila !== null && $fila->demo_plan_congelado_at !== null);

            if ($setup_estado !== 'pendiente' || ! $plan_estaba_congelado) {
                /* Los dos caminos que sólo guardan las respuestas: el setup ya corrió (el plan no
                   se toca) o el plan todavía no está congelado (no lo congela el panel, punto 2
                   de arriba). */
                $lead->save();

                return;
            }

            /* 🔴 Se verifica que el plan nuevo SIRVA antes de destruir el que el lead ya tiene.
               `congelar_en_memoria()` devuelve `false` en dos casos distintos —el plan ya estaba
               congelado, o `resolver()` dio `null`— y hasta hoy el segundo dejaba al lead sin
               roadmap: el plan y los hitos ya estaban borrados y persistidos, la regeneración se
               salteaba, y la transacción commiteaba igual sin una sola excepción.

               Y no alcanza con preguntar por `null`, que era el chequeo anterior: `resolver()`
               devuelve `null` SÓLO cuando `DemoCatalogoService::get()` da `[]` (archivo sin
               sincronizar o JSON inválido). Un catálogo que es JSON VÁLIDO pero que no produce
               nada utilizable —`orden_secciones: []`, o las secciones renombradas por un typo, que
               dejan de cruzar con `clips[].seccion`— devuelve un plan perfectamente formado con
               `secciones: []`. Ese plan pasaba el `!== null`, se llevaba puesto el roadmap de 12
               hitos del lead y lo dejaba con uno solo (el de ingreso), con HTTP 200 y sin una línea
               de error. Ni el catálogo sin sincronizar ni el catálogo mal editado pueden costarle
               el plan a un lead que ya lo tenía; y guardar las respuestas —lo único que Lucas
               pidió— tampoco puede fallar por eso. Así que se deja el plan viejo intacto, se
               loguea y se sigue guardando.

               Cuesta una resolución de más (la definitiva la hace `congelar_en_memoria()`), y se
               paga a propósito: preguntarle al mismo resolver es lo único que no se desincroniza
               el día que `resolver()` aprenda a devolver `null` por otro motivo. */
            $plan_nuevo = DemoPlanResolver::resolver($lead);

            if (! $this->plan_de_demo_utilizable($plan_nuevo)) {
                Log::error('No se re-congeló el plan de demo desde el panel: el catálogo no produce un plan utilizable para este lead. El plan anterior queda intacto.', [
                    'lead_id'           => $lead->id,
                    'catalogo_resolvio' => $plan_nuevo !== null,
                    'secciones_nuevas'  => is_array($plan_nuevo) && isset($plan_nuevo['secciones'])
                        ? count($plan_nuevo['secciones'])
                        : 0,
                ]);

                $lead->save();

                return;
            }

            /* 🔴 El borrado del plan se PERSISTE antes de re-congelar, y no alcanza con
               limpiarlo en memoria: `DemoPlanResolver::congelar_en_memoria()` decide con un
               segundo chequeo contra la fila bloqueada (`lockForUpdate()` sobre
               `demo_plan_congelado_at`), no con el atributo del modelo. Sin este `save()`
               previo, ese chequeo seguiría viendo la fecha vieja y la re-congelación se iría
               sin hacer nada, en silencio. */
            $lead->demo_plan              = null;
            $lead->demo_plan_congelado_at = null;
            $lead->save();

            /* Y los hitos se borran porque `DemoHitosService::generar()` es idempotente: con
               hitos existentes no crea ninguno y el roadmap quedaría con los del plan viejo.
               Sólo se llega acá con el setup en `pendiente`, o sea antes de que el lead haya
               podido entrar a la demo y marcar nada. */
            LeadDemoHito::where('lead_id', $lead->id)->delete();

            /* Mismo orden que el endpoint público: congelar en memoria, un solo `save()` con las
               respuestas y el plan juntos, y los hitos adentro de la misma transacción. */
            if (! DemoPlanResolver::congelar_en_memoria($lead)) {
                /* Inalcanzable por construcción: el catálogo se acaba de verificar y las dos
                   guardias de "ya estaba congelado" ven el `null` que se persistió recién en esta
                   misma transacción. Si aun así devolviera `false`, la excepción tira la
                   transacción entera atrás y el lead se queda con el plan y los hitos que tenía.
                   Que el guardado falle y se vea es preferible a borrarle el roadmap en silencio:
                   de acá no puede salir un lead sin plan. */
                throw new \RuntimeException(
                    'No se pudo re-congelar el plan de demo del lead ' . $lead->id . ' después de borrarlo.'
                );
            }

            $lead->save();

            DemoHitosService::generar($lead);
        });

        /* Trazabilidad en el hilo del lead, con el mismo patrón de evento de sistema que usa el
           endpoint público: `sender` sistema, `is_status_event` para que no cuente como actividad
           real del hilo, y sin `sent_by_admin_id` — el hilo es la conversación con el lead y este
           mensaje no se le manda a nadie. Quién lo hizo queda igual en el timestamp de la columna. */
        LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'content'         => $this->describir_edicion_de_formulario($respuestas_previas, $respuestas),
            'status'          => 'enviado',
            'is_followup'     => false,
            'is_status_event' => true,
        ]);

        /* 🔴 Al modelo de la respuesta se le appendea `demo_form_panel` además del link de ingreso.
           No es decorativo: el SPA fusiona la respuesta con `Object.assign()`, que deja intactas
           las claves ausentes, así que sin este append la tarjeta se quedaría mostrando el origen y
           las fechas de antes de guardar —"todavía no completó el formulario"— sobre respuestas que
           acaban de persistirse. Es el mismo modo de falla que documenta
           `full_lead_with_demo_link()` para el link de ingreso, y va acá y no adentro de ese helper
           porque los endpoints que rotan el token no tienen por qué recalcular este bloque. */
        $model = $this->full_lead_with_demo_link($lead->id);
        if ($model) {
            $model->append('demo_form_panel');
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Si un plan recién resuelto sirve para reemplazar al que el lead ya tiene congelado.
     *
     * 🔴 EL CRITERIO, Y DE DÓNDE SALE
     * -------------------------------
     * Un plan es UTILIZABLE si tiene al menos un clip de NÚCLEO entre sus secciones. No es una
     * definición inventada acá: es exactamente lo que `DemoHitosService::generar()` recorre para
     * armar el roadmap — itera `plan['secciones'][]['clips'][]` y crea un hito por cada clip con
     * `tipo === 'nucleo'`, salteando los de biblioteca. Un plan que no tenga ninguno produce un
     * roadmap de un solo hito, el de "Entrar a la demo", que `generar()` crea siempre y para todos
     * los leads por igual. O sea: un roadmap vacío disfrazado de roadmap.
     *
     * Preguntar por `resolver() !== null` NO alcanza, y ése era el agujero. `resolver()` devuelve
     * `null` sólo si `DemoCatalogoService::get()` da `[]` — contenido vacío o JSON inválido. Un
     * catálogo que es JSON válido pero que no produce nada (`orden_secciones: []`, o las secciones
     * renombradas por un typo, que dejan de cruzar con `clips[].seccion`) devuelve un plan legítimo
     * con `secciones: []`: pasaba el chequeo de `null`, reemplazaba el plan del lead y le dejaba
     * un solo hito donde tenía doce, con HTTP 200 y sin un log de error.
     *
     * Se recorren los clips en vez de mirar `totales.clips_nucleo` a propósito: `totales` es un
     * resumen que `resolver()` calcula aparte, y este chequeo tiene que decidir con lo mismo que
     * `generar()` va a leer, no con un contador paralelo que puede desincronizarse.
     *
     * @param array<string, mixed>|null $plan Plan tal como lo devuelve `DemoPlanResolver::resolver()`.
     *
     * @return bool
     */
    protected function plan_de_demo_utilizable($plan)
    {
        if (! is_array($plan) || empty($plan)) {
            return false;
        }

        $secciones = isset($plan['secciones']) && is_array($plan['secciones']) ? $plan['secciones'] : [];

        foreach ($secciones as $seccion) {
            $clips = isset($seccion['clips']) && is_array($seccion['clips']) ? $seccion['clips'] : [];

            foreach ($clips as $clip) {
                if (isset($clip['tipo']) && $clip['tipo'] === 'nucleo') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Arma el texto del evento de sistema que queda en el hilo del lead cuando se editan las
     * respuestas del formulario desde el panel.
     *
     * Lista sólo lo que cambió, con las CLAVES del formulario y no con el texto de cada pregunta.
     * Es a propósito: el texto de las nueve preguntas vive en un solo lugar (el módulo del SPA que
     * comparten la página inmersiva y la tarjeta del modal), y copiarlo acá crearía una segunda
     * versión que coincidiría por casualidad y no por construcción. Este mensaje es traza interna,
     * no algo que el lead lea.
     *
     * @param array<string, mixed> $previas Respuestas efectivas antes de la edición.
     * @param array<string, mixed> $nuevas  Respuestas efectivas después de la edición.
     *
     * @return string
     */
    protected function describir_edicion_de_formulario(array $previas, array $nuevas)
    {
        $cambios = [];

        foreach ($nuevas as $clave => $valor) {
            $anterior = array_key_exists($clave, $previas) ? $previas[$clave] : null;

            if ($anterior === $valor) {
                continue;
            }

            $cambios[] = $clave . ': de ' . $this->valor_de_respuesta_legible($anterior)
                . ' a ' . $this->valor_de_respuesta_legible($valor);
        }

        if (empty($cambios)) {
            /* Guardar sin cambiar nada NO es un no-op y por eso también deja mensaje: fija las
               respuestas que estaban a la vista (los defaults del catálogo, para un lead que no
               completó el formulario) como elección explícita, y a partir de ahí el demo setup las
               usa en vez de resolverlas de nuevo. */
            return 'Se confirmaron desde el panel las respuestas del formulario de la demo, sin cambios.';
        }

        return 'Respuestas del formulario de la demo editadas desde el panel — ' . implode('; ', $cambios) . '.';
    }

    /**
     * Traduce a texto un valor de respuesta del formulario para el mensaje del hilo.
     *
     * @param mixed $valor
     *
     * @return string
     */
    protected function valor_de_respuesta_legible($valor)
    {
        if (is_bool($valor)) {
            return $valor ? 'sí' : 'no';
        }

        return (string) $valor;
    }

    /**
     * GET /api/admin/lead/{id}/demo-roadmap — el recorrido de la demo de este lead (misión 49).
     *
     * (El prefijo `admin` lo pone el grupo de rutas del panel, donde viven también
     * `run-demo-setup` y `demo-token/reemitir`. La spec de la misión escribía el path sin él.)
     *
     * Devuelve el plan congelado, sus hitos y el progreso en UNA sola llamada. Es a propósito:
     * el panel poléa este endpoint cada 10 segundos mientras el lead está adentro de la demo, y
     * una respuesta por partes obligaría a una query por hito en cada tick.
     *
     * Sólo LEE. No hay forma de mover un hito desde el panel: el estado de un hito es el registro
     * de lo que el lead efectivamente hizo, y si se pudiera editar a mano dejaría de serlo.
     *
     * `tiene_plan: false` NO es un error: es el estado normal de casi todos los leads, porque el
     * plan recién se congela cuando completan el formulario de la página inmersiva.
     *
     * Desde el 1/9/2026 cada hito de tutorial con clip lleva además cinco campos de detalle
     * (`visto`, `porcentaje_visto`, `tour_iniciado`, `probado`, `porcentaje_tour`), que salen de
     * los eventos crudos de {@see self::EVENTOS_DETALLE_RECORRIDO}. Son campos AGREGADOS: ninguno
     * de los que ya estaban se renombró ni se sacó, porque el panel viejo puede seguir cacheado en
     * el navegador de Lucas después de un `/deploy-admin` y tiene que seguir dibujando igual.
     *
     * @param int|string $id Identificador del lead.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function demo_roadmap_json($id)
    {
        /* Lead del que se pide el recorrido. Se piden SOLO las tres columnas que se usan, y no el
         * `select *` de siempre: `leads` tiene ~140 columnas, doce de ellas TEXT/JSON (notas,
         * resúmenes de llamada, errores de setup), y este endpoint se poléa cada diez segundos por
         * cada lead abierto. En el resto del controller el `select *` no cuesta nada porque se
         * llama una vez por acción; acá se llama 540 veces por lead y por sesión. */
        $lead = Lead::select('id', 'demo_plan', 'demo_plan_congelado_at')->findOrFail($id);

        $plan = $lead->demo_plan;
        $tiene_plan = is_array($plan) && ! empty($plan);

        $hitos = LeadDemoHito::where('lead_id', $lead->id)
            ->orderBy('orden')
            ->get();

        $completos = 0;
        $parciales = 0;
        $lista     = [];

        /* 🔴 UNA sola consulta para TODOS los hitos, y va acá afuera del `foreach` a propósito.
         * Este endpoint se poléa cada diez segundos por cada lead abierto (540 veces por lead y
         * por sesión, es el mismo motivo por el que arriba se piden tres columnas en vez de un
         * `select *`): resolver el detalle adentro del bucle serían ~20 queries cada diez
         * segundos por lead en vez de una. */
        $detalle_por_clip = $this->detalle_de_recorrido_por_clip($lead->id, $hitos);

        foreach ($hitos as $hito) {
            if ($hito->estado === LeadDemoHito::ESTADO_COMPLETO) {
                $completos++;
            } elseif ($hito->estado === LeadDemoHito::ESTADO_PARCIAL) {
                $parciales++;
            }

            /* Se arma la fila a mano en vez de devolver el modelo entero: así el payload es un
             * contrato explícito y una columna nueva de `lead_demo_hitos` no se filtra sola. */
            $fila = [
                'orden'             => (int) $hito->orden,
                'tipo'              => $hito->tipo,
                'seccion'           => $hito->seccion,
                'clip_id'           => $hito->clip_id,
                'titulo'            => $hito->titulo,
                'estado'            => $hito->estado,
                'evento_esperado'   => $hito->evento_esperado,
                'tutorial_visto_at' => $hito->tutorial_visto_at ? $hito->tutorial_visto_at->format('Y-m-d H:i:s') : null,
                'accion_hecha_at'   => $hito->accion_hecha_at ? $hito->accion_hecha_at->format('Y-m-d H:i:s') : null,
            ];

            /* Los cinco campos de detalle van SÓLO en los hitos de tutorial que tienen clip.
             *
             * El hito de ingreso no tiene ni video ni tour, así que dibujarle un "Visto 0%" no
             * sería un cero: sería afirmar que hay algo que ver y que el lead no lo vio. Que los
             * campos directamente no estén es lo que le permite al panel distinguir los dos casos
             * con la misma regla que usa contra una API vieja — si no vienen, no dibuja nada. */
            if ($this->hito_lleva_detalle_de_recorrido($hito)) {
                $lista[] = array_merge($fila, $this->detalle_de_recorrido_del_hito($hito, $detalle_por_clip));

                continue;
            }

            $lista[] = $fila;
        }

        return response()->json([
            'tiene_plan'   => $tiene_plan,
            'congelado_at' => $lead->demo_plan_congelado_at
                ? $lead->demo_plan_congelado_at->format('Y-m-d H:i:s')
                : null,

            // Las nueve respuestas con las que se resolvió el plan, tal como quedaron congeladas
            // (no las columnas actuales del lead: si reenvió el formulario, pueden diferir).
            'respuestas' => $tiene_plan && isset($plan['respuestas']) ? $plan['respuestas'] : [],

            // Errores del catálogo que el resolver encontró al armar este plan. El panel los
            // muestra: son un typo del repo que llegó a producción por el sync, y morir en un log
            // es exactamente lo que no puede pasar.
            'condiciones_invalidas' => $tiene_plan && isset($plan['condiciones_invalidas'])
                ? $plan['condiciones_invalidas']
                : [],

            /* El denominador del progreso es el TOTAL de hitos de este lead, no una constante:
             * cada lead tiene el recorrido que le tocó según sus respuestas, así que 3/12 y 3/20
             * son progresos distintos y compararlos contra un total fijo no querría decir nada. */
            'progreso' => [
                'completos' => $completos,
                'parciales' => $parciales,
                'total'     => count($lista),
            ],

            'hitos' => $lista,
        ], 200);
    }

    /**
     * ¿A este hito le corresponden los cinco campos de detalle del recorrido?
     *
     * Sólo a los de tutorial que tienen clip: son los únicos que tienen un video para ver y un
     * tour para probar. El hito de ingreso (`tipo: 'ingreso'`) queda afuera, y también un hito de
     * tutorial sin `clip_id` — que no debería existir, pero el clip del plan congelado puede venir
     * sin `id` y `DemoHitosService::generar()` lo escribe como null en vez de romper.
     *
     * @param LeadDemoHito $hito
     *
     * @return bool
     */
    protected function hito_lleva_detalle_de_recorrido(LeadDemoHito $hito)
    {
        return $hito->tipo === LeadDemoHito::TIPO_TUTORIAL
            && $hito->clip_id !== null
            && (string) $hito->clip_id !== '';
    }

    /**
     * Los cinco campos de detalle de un hito de tutorial, ya resueltos contra los eventos crudos.
     *
     * 🔴 Los dos porcentajes NUNCA son `null` y nunca salen de una división sin divisor: el panel
     * los compara con `> 0` y un `null` ahí se leería como "no hay dato" cuando lo que hay es un
     * cero legítimo.
     *
     * 🔴 Y los dos caen al comportamiento de antes de esta misión cuando NO hay eventos: una
     * `empresa` vieja no emite `clip.progreso` ni `tour.completado`, y `empresa` sale a los ~40
     * clientes por release mientras el admin lo despliega Lucas con `/deploy-admin` — o sea que
     * durante un tiempo el admin nuevo va a estar leyendo instancias viejas. En ese caso
     * `porcentaje_visto` es 100 si el hito tiene `tutorial_visto_at` (que la instancia vieja SÍ
     * manda, vía `clip.terminado`) y 0 si no, que es exactamente lo único que se sabía hasta hoy.
     *
     * @param LeadDemoHito                        $hito
     * @param array<string, array<string, mixed>> $detalle_por_clip Salida de
     *                                                             {@see self::detalle_de_recorrido_por_clip()}.
     *
     * @return array<string, mixed> Las cinco claves del contrato, siempre las cinco.
     */
    protected function detalle_de_recorrido_del_hito(LeadDemoHito $hito, array $detalle_por_clip)
    {
        $clip = (string) $hito->clip_id;

        /* `visto` sale del hito y no de los eventos: la marca del hito es la que ya escribió
         * `DemoHitosService` con el `clip.terminado`, y es la que tiene que seguir mandando. Un
         * `clip.progreso` no puede declarar visto un video, y por eso el emisor no manda el 100. */
        $visto = $hito->tutorial_visto_at !== null;

        $del_clip = isset($detalle_por_clip[$clip]) ? $detalle_por_clip[$clip] : null;

        if ($del_clip === null) {
            return [
                'visto'            => $visto,
                'porcentaje_visto' => $visto ? 100 : 0,
                'tour_iniciado'    => false,
                'probado'          => false,
                'porcentaje_tour'  => 0,
            ];
        }

        return [
            'visto' => $visto,

            /* El hito visto manda sobre los eventos: si `clip.terminado` llegó, el lead vio el
             * video entero, aunque el último `clip.progreso` que entró diga 70 (los eventos no
             * llegan ordenados: el emisor reintenta y la red reordena). */
            'porcentaje_visto' => $visto ? 100 : $del_clip['porcentaje_visto'],

            'tour_iniciado' => $del_clip['tour_iniciado'],
            'probado'       => $del_clip['probado'],

            // Misma jerarquía: un tour completo es 100, aunque el conteo de pasos diera otra cosa.
            'porcentaje_tour' => $del_clip['probado'] ? 100 : $del_clip['porcentaje_tour'],
        ];
    }

    /**
     * Agrupa por clip los eventos de UX del recorrido de un lead: cuánto vio de cada video y
     * cuánto hizo del tour de cada clip.
     *
     * 🔴 UNA sola consulta, con `whereIn` sobre los tres nombres y el índice de `lead_id` que ya
     * existe. Ver el comentario del llamador: acá el costo se multiplica por 540 por lead.
     *
     * El agrupado se hace en PHP y no en SQL porque los tres valores viven adentro del json de
     * `datos`, que no se agrega de forma portable — y porque el máximo por clip sobre un puñado de
     * filas es gratis comparado con la ida a la base.
     *
     * 🔴 `datos` es json LIBRE que entró desde el navegador de un lead: `porcentaje`, `mostrados`
     * y `pasos` pueden faltar, venir `null`, venir string, venir negativos o venir absurdos. Cada
     * valor se lee como si fuera hostil y NUNCA se divide sin haber comprobado antes que el
     * divisor es un entero positivo.
     *
     * @param int                                                          $lead_id
     * @param \Illuminate\Support\Collection<int, LeadDemoHito>|array      $hitos   Hitos ya leídos.
     *
     * @return array<string, array<string, mixed>> Detalle indexado por `clip_id`.
     */
    protected function detalle_de_recorrido_por_clip($lead_id, $hitos)
    {
        $hay_clips = false;

        foreach ($hitos as $hito) {
            if ($this->hito_lleva_detalle_de_recorrido($hito)) {
                $hay_clips = true;

                break;
            }
        }

        /* Un lead sin plan —el estado normal de casi todos, y el que más se abre desde el panel—
         * no tiene ningún hito de tutorial, así que ni se pregunta: el endpoint sigue haciendo las
         * mismas dos queries que hacía antes de esta misión. */
        if (! $hay_clips) {
            return [];
        }

        /* Tres columnas y no `select *`: `datos` ya es la columna cara de esta tabla (json de
         * hasta 4 KB por fila, y un lead que miró toda la demo puede tener un par de cientos de
         * filas de `clip.progreso`), y `uuid`, `ocurrido_at` y los timestamps no se usan acá. */
        $eventos = DemoEventoRecibido::select('nombre', 'clip_id', 'datos')
            ->where('lead_id', $lead_id)
            ->whereIn('nombre', self::EVENTOS_DETALLE_RECORRIDO)
            ->whereNotNull('clip_id')
            ->get();

        $detalle = [];

        foreach ($eventos as $evento) {
            $clip = (string) $evento->clip_id;

            if ($clip === '') {
                continue;
            }

            if (! isset($detalle[$clip])) {
                $detalle[$clip] = [
                    'porcentaje_visto' => 0,
                    'tour_iniciado'    => false,
                    'probado'          => false,
                    'porcentaje_tour'  => 0,
                ];
            }

            /* El cast `array` del modelo es `json_decode($valor, true)`: un json escalar guardado
             * en la columna (`5`, `"x"`, `true`) se decodifica como escalar y NO como array. Se
             * pregunta antes de indexar, o un `datos` de esa forma sería un warning por fila. */
            $datos = is_array($evento->datos) ? $evento->datos : [];

            if ($evento->nombre === 'tour.iniciado') {
                $detalle[$clip]['tour_iniciado'] = true;

                continue;
            }

            if ($evento->nombre === 'clip.progreso') {
                $porcentaje = $this->porcentaje_saneado(
                    array_key_exists('porcentaje', $datos) ? $datos['porcentaje'] : null
                );

                /* El MÁXIMO, no el último que entró. Por dos motivos, y los dos son reales: los
                 * eventos no llegan ordenados (el emisor reintenta y la red reordena), y el lead
                 * puede retroceder el video. Lo que se quiere mostrar es hasta dónde llegó. */
                if ($porcentaje !== null && $porcentaje > $detalle[$clip]['porcentaje_visto']) {
                    $detalle[$clip]['porcentaje_visto'] = $porcentaje;
                }

                continue;
            }

            /* Queda `tour.completado`, que llega SIEMPRE que el tour termina, haya llegado al final
             * o no: `datos.completo` es el que dice cuál de las dos. Ojo con la comparación
             * estricta — el motor manda un booleano de verdad, y aceptar `"false"` o `0` como
             * verdadero marcaría probado un tour que el lead abandonó. */
            if (array_key_exists('completo', $datos) && $datos['completo'] === true) {
                $detalle[$clip]['probado'] = true;
            }

            $avance = $this->porcentaje_de_avance_de_tour($datos);

            if ($avance !== null && $avance > $detalle[$clip]['porcentaje_tour']) {
                $detalle[$clip]['porcentaje_tour'] = $avance;
            }
        }

        return $detalle;
    }

    /**
     * Lee un porcentaje que vino del navegador y lo devuelve como entero de 0 a 100, o `null` si
     * no se puede leer como número.
     *
     * `null` y `0` NO son lo mismo acá y por eso se distinguen: `null` significa "este evento no
     * trae un porcentaje utilizable" y entonces no participa del máximo; `0` es un porcentaje
     * legítimo. Si se devolviera 0 para lo ilegible daría igual —el máximo lo ignora— pero el que
     * lea este método después no tendría cómo saber cuál de las dos cosas pasó.
     *
     * @param mixed $valor Lo que vino en el json, sin ninguna garantía de tipo.
     *
     * @return int|null Entero de 0 a 100, o null si no es un número legible.
     */
    protected function porcentaje_saneado($valor)
    {
        // `is_numeric` cubre de una el int, el float y el string numérico, y rechaza el array, el
        // bool, el null y el "80%" con el signo pegado.
        if (! is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;

        // JSON no sabe expresar NAN ni INF, así que esto no debería poder pasar — pero un
        // `(int) NAN` en PHP 7.4 da un entero basura sin avisar, y el precio de preguntar es cero.
        if (! is_finite($numero)) {
            return null;
        }

        $entero = (int) round($numero);

        if ($entero < 0) {
            return 0;
        }

        if ($entero > 100) {
            return 100;
        }

        return $entero;
    }

    /**
     * Cuánto del tour recorrió el lead, en porcentaje, a partir del `datos` de un
     * `tour.completado` (`{ completo, pasos, mostrados, salteados }`).
     *
     * 🔴 Nunca divide por cero: si `pasos` no se lee como un entero POSITIVO, este evento no
     * aporta nada y devuelve `null`. Es el único divisor de todo el endpoint y viene de un json
     * que escribió el navegador de un lead.
     *
     * @param array<string, mixed> $datos Carga del evento, ya garantizada como array.
     *
     * @return int|null Entero de 0 a 100, o null si el evento no trae un avance legible.
     */
    protected function porcentaje_de_avance_de_tour(array $datos)
    {
        $pasos     = array_key_exists('pasos', $datos) ? $datos['pasos'] : null;
        $mostrados = array_key_exists('mostrados', $datos) ? $datos['mostrados'] : null;

        if (! is_numeric($pasos) || ! is_numeric($mostrados)) {
            return null;
        }

        $pasos_float = (float) $pasos;

        if (! is_finite($pasos_float)) {
            return null;
        }

        $pasos_enteros = (int) $pasos_float;

        // Acá está la guarda de la división: 0, negativo o "0.4 pasos" no dividen nada.
        if ($pasos_enteros <= 0) {
            return null;
        }

        $mostrados_float = (float) $mostrados;

        if (! is_finite($mostrados_float)) {
            return null;
        }

        // El clampeo lo hace el mismo saneador que el otro porcentaje: un `mostrados` mayor que
        // `pasos` (o negativo) es exactamente el tipo de absurdo que puede llegar de un json libre.
        return $this->porcentaje_saneado($mostrados_float / $pasos_enteros * 100);
    }

    /**
     * Reemite el token de ingreso directo a la demo del lead (grupo 233, prompt 05): genera un
     * link nuevo (por ejemplo si se reagendó el turno o el lead lo perdió) sin volver a correr
     * el setup completo, que tarda y vacía la base. El token anterior queda invalidado
     * automáticamente (DemoIngresoTokenService::reemitir ya lo resuelve).
     *
     * Si el aviso a la instancia falla (por ejemplo, instancia apagada), el servicio revierte el
     * Lead al token anterior y lanza una excepción: acá se responde 422 sin haber dejado un
     * token huérfano que la instancia no conoce.
     *
     * @param int|string              $id      Identificador del lead.
     * @param DemoIngresoTokenService $service Servicio inyectado con la lógica de reemisión.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reemitir_demo_token_json($id, DemoIngresoTokenService $service)
    {
        /* Lead objetivo al que se le reemite el token de ingreso. */
        $lead = Lead::findOrFail($id);

        try {
            $lead = $service->reemitir($lead);
        } catch (\Throwable $e) {
            /* También acá va el link: el servicio revierte al token anterior, y el panel tiene que
               ver el link que efectivamente quedó vigente, no el que tenía dibujado. */
            return response()->json([
                'message' => 'No se pudo reemitir el token: ' . $e->getMessage(),
                'model' => $this->full_lead_with_demo_link($lead->id),
            ], 422);
        }

        /* Deja constancia en admin_notifications de quién reemitió el token y cuándo. */
        $this->registrar_evento_token_demo($lead, 'Token de ingreso a la demo reemitido');

        return response()->json(['model' => $this->full_lead_with_demo_link($lead->id)], 200);
    }

    /**
     * Revoca el token de ingreso directo a la demo del lead (grupo 233, prompt 05): por ejemplo
     * si el link se compartió donde no debía. La sesión que ya estaba abierta con ese token se
     * cae en el siguiente request (middleware de vigencia, prompt 03 de este grupo).
     *
     * @param int|string              $id      Identificador del lead.
     * @param DemoIngresoTokenService $service Servicio inyectado con la lógica de revocación.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function revocar_demo_token_json($id, DemoIngresoTokenService $service)
    {
        /* Lead objetivo al que se le revoca el token de ingreso. */
        $lead = Lead::findOrFail($id);

        try {
            $lead = $service->revocar($lead);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo revocar el token: ' . $e->getMessage(),
                'model' => $this->full_lead_with_demo_link($lead->id),
            ], 422);
        }

        /* Deja constancia en admin_notifications de quién revocó el token y cuándo. */
        $this->registrar_evento_token_demo($lead, 'Token de ingreso a la demo revocado');

        return response()->json(['model' => $this->full_lead_with_demo_link($lead->id)], 200);
    }

    /**
     * Override manual por lead de la dinámica de demo (grupo 293, prompt 03): permite cambiarle a
     * un lead puntual la experiencia con la que va a ver la demo, sin depender de la setting global
     * (`LeadDemoSettings::get_experiencia_default()`), que solo aplica al crear leads nuevos. Es lo
     * que habilita pilotear la experiencia nueva con dos o tres leads elegidos a mano antes de
     * abrirla a todos.
     *
     * @param int|string $id      Identificador del lead a modificar.
     * @param Request    $request Debe traer `demo_experiencia` (uno de {@see Lead::EXPERIENCIAS}).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function set_demo_experiencia_json($id, Request $request)
    {
        /* Validación acotada: solo se acepta uno de los dos valores conocidos. La fuente de verdad
           de los valores válidos es Lead::EXPERIENCIAS; se arma el "in:" a partir de esa constante
           para no duplicar el literal. */
        $request->validate([
            'demo_experiencia' => 'required|string|in:' . implode(',', Lead::EXPERIENCIAS),
        ]);

        /* Lead objetivo al que se le cambia la dinámica de demo. */
        $lead = Lead::findOrFail($id);

        /* Valor nuevo pedido por el admin. */
        $demo_experiencia_nueva = (string) $request->input('demo_experiencia');

        /* Si el valor no cambia (doble click, o el admin reenvía el mismo valor), no se escribe ni
           se registra evento: evita llenar el hilo de eventos vacíos. */
        if ($lead->demo_experiencia === $demo_experiencia_nueva) {
            return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
        }

        $lead->demo_experiencia = $demo_experiencia_nueva;
        $lead->save();

        /* Deja constancia en el hilo del lead de quién cambió la dinámica y a qué valor, reusando
           el mismo helper que ya usan reemitir/revocar de token (no se renombra: tiene call sites
           de producción existentes y es genérico en lo que hace). */
        $texto_evento = $demo_experiencia_nueva === Lead::EXPERIENCIA_NUEVA
            ? 'Dinámica de demo cambiada a experiencia nueva'
            : 'Dinámica de demo cambiada a experiencia actual';
        $this->registrar_evento_token_demo($lead, $texto_evento);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Estados del pipeline en los que tiene sentido mover a mano la hora de fin de la demo
     * (tarea 62): desde que quedó agendada hasta que el fin quedó pendiente de confirmar. Fuera
     * de estos estados no hay "demo vigente" cuyo fin editar, y el endpoint responde 422.
     *
     * @var array<int, string>
     */
    const ESTADOS_CON_DEMO_EDITABLE = [
        'demo_agendada',
        'ingresando_demo',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
    ];

    /**
     * Edita a mano la hora de fin de la demo del lead desde el panel (tarea 62).
     *
     * Es la palanca HUMANA sobre `demo_end_time` — la contracara de la misión 47, que le prohibió
     * al modelo escribir ese campo. Este endpoint es de la UI del admin (auth:sanctum), no del
     * canal del agente: la restricción de la 47 no se afloja acá, se complementa.
     *
     * Validación server-side, en este orden:
     * 1. Formato HH:MM estricto (00:00–23:59). Como solo se acepta una hora del día y la fecha
     *    sigue siendo `demo_date`, el fin queda por construcción en el MISMO día calendario que el
     *    inicio, con techo 23:59 — el mismo criterio y clamp de la misión 47: la demo nunca cruza
     *    de día. Un valor con fecha ("2026-08-26 15:00") o fuera de rango ("25:00") es 422.
     * 2. Lead con demo vigente: demo_id + demo_date + demo_start_time cargados y status dentro de
     *    ESTADOS_CON_DEMO_EDITABLE.
     * 3. Fin estrictamente posterior al inicio (un fin "menor" sería cruzar de día: 422).
     *
     * Efectos, además de persistir el campo:
     * - El TOKEN de ingreso acompaña (si hay uno emitido y no revocado): vencimiento nuevo =
     *   demo_date + fin + gracia. Si se extiende, vía extender_vencimiento(); si se ACORTA, vía
     *   acortar_vencimiento() con un piso de `now + gracia` — la instancia valida la vigencia en
     *   cada request (middleware DemoSessionVigente de empresa-api), así que un vencimiento en el
     *   pasado le cortaría la sesión al lead que está adentro en su próximo click. Con el piso, el
     *   link deja de servir para entrar casi de inmediato pero el que ya está adentro tiene la
     *   gracia para cerrar: no se lo patea a mitad de sesión. Si el aviso a la instancia falla, se
     *   revierte TODO (fin y reprogramación) y se responde 422: nada de estados a medias.
     * - El CHECK DE FIN se reprograma con el mecanismo ya existente del grupo 307
     *   (`demo_fin_check_reprogramado_para`), que CheckDemoFin, CheckDemoFinSeguimiento y
     *   CheckDemoFinTimeout ya usan como reemplazo del objetivo `inicio + duración`. Sin esto, a
     *   una demo de 10 a 11 extendida hasta las 15 se le preguntaría "¿terminaste?" a las 11:00 —
     *   o nunca, si el lead entra después de las 11. No se toca para un lead con ventana extendida
     *   de la dinámica nueva: la misión 47 lo dejó fuera de esos relojes a propósito.
     * - Trazabilidad (patrón del log de la 47 para el demo_end_time del modelo): un Log::info con
     *   quién/de-qué-valor/a-qué-valor, más el evento visible en el hilo del lead.
     *
     * @param int|string              $id            Identificador del lead.
     * @param Request                 $request       Debe traer `demo_end_time` (HH:MM).
     * @param DemoIngresoTokenService $token_service Servicio que ajusta el vencimiento del token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_demo_end_time_json($id, Request $request, DemoIngresoTokenService $token_service)
    {
        /* Lead objetivo de la edición manual del fin. */
        $lead = Lead::findOrFail($id);

        /* 1. Formato estricto HH:MM del mismo día. */
        $fin_nuevo = trim((string) $request->input('demo_end_time', ''));
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $fin_nuevo, $fin_match)) {
            return response()->json([
                'message' => 'La hora de fin tiene que ser una hora del día en formato HH:MM (00:00 a 23:59): la demo no cruza de día.',
            ], 422);
        }

        /* 2. Demo vigente: campos cargados y estado del ciclo. */
        if (is_null($lead->demo_date) || empty($lead->demo_start_time) || is_null($lead->demo_id)
            || ! in_array((string) $lead->status, self::ESTADOS_CON_DEMO_EDITABLE, true)) {
            return response()->json([
                'message' => 'El lead no tiene una demo agendada o en curso: no hay fin que editar.',
            ], 422);
        }

        /* 3. Fin posterior al inicio. El inicio se parsea con la misma regex tolerante que usa el
         * resto del repo (demo_start_time es texto libre histórico). Si el inicio es ilegible no
         * hay contra qué validar y se rechaza: mejor 422 que aceptar un fin incomparable. */
        if (! preg_match('/(\d{1,2}):(\d{2})/', (string) $lead->demo_start_time, $inicio_match)) {
            return response()->json([
                'message' => 'La hora de inicio de la demo no tiene un formato legible: corregila antes de editar el fin.',
            ], 422);
        }
        $inicio_minutos = (int) $inicio_match[1] * 60 + (int) $inicio_match[2];
        $fin_minutos    = (int) $fin_match[1] * 60 + (int) $fin_match[2];
        if ($fin_minutos <= $inicio_minutos) {
            return response()->json([
                'message' => 'La hora de fin tiene que ser posterior al inicio (' . $lead->demo_start_time . ') dentro del mismo día.',
            ], 422);
        }

        /* Sin cambios reales (doble click o reenvío del mismo valor): no se escribe, no se avisa
         * a la instancia y no se ensucia el hilo con eventos vacíos (patrón de demo-experiencia). */
        $fin_anterior = (string) $lead->demo_end_time;
        if ($fin_anterior === $fin_nuevo) {
            return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
        }

        /* Reloj y momentos derivados del fin nuevo. AppTime y no Carbon::now() directo: los
         * relojes del ciclo corren sobre el reloj virtual de debug y este endpoint los reprograma. */
        $now          = \App\Helpers\AppTime::now();
        $fin_datetime = \Carbon\Carbon::parse(
            $lead->demo_date->format('Y-m-d') . ' ' . $fin_nuevo,
            'America/Argentina/Buenos_Aires'
        );

        /* Backup para revertir si el aviso del token a la instancia falla. */
        $reprogramado_anterior = $lead->demo_fin_check_reprogramado_para !== null
            ? $lead->demo_fin_check_reprogramado_para->copy()
            : null;

        /* Nuevo objetivo del check de fin (mecanismo del grupo 307):
         * - Lead con ventana extendida de la dinámica nueva: null intacto — la misión 47 lo sacó
         *   de esos relojes a propósito, no hay check que reprogramar.
         * - Fin nuevo en el futuro: el check (y su seguimiento y su timeout) corren a esa hora.
         * - Fin nuevo ya pasado y demo en curso: `now`, para que el check dispare en la próxima
         *   corrida (la ventana del comando es ±2 minutos) en vez de quedar trabado para siempre
         *   apuntando al pasado — el riesgo que documenta update_json() al limpiar este campo.
         * - Fin nuevo ya pasado en cualquier otro estado: null (cálculo por defecto de siempre). */
        if ($lead->demo_flexible && $lead->usa_experiencia_demo_nueva()) {
            $reprogramado_nuevo = $reprogramado_anterior;
        } elseif ($fin_datetime->gt($now)) {
            $reprogramado_nuevo = $fin_datetime->copy();
        } elseif ((string) $lead->status === 'demo_en_curso') {
            $reprogramado_nuevo = $now->copy();
        } else {
            $reprogramado_nuevo = null;
        }

        $lead->update([
            'demo_end_time'                    => $fin_nuevo,
            'demo_fin_check_reprogramado_para' => $reprogramado_nuevo,
        ]);

        /* El token acompaña al fin editado. Solo si hay uno emitido y no revocado: un lead sin
         * token todavía no tiene nada que ajustar (cuando se emita, calcular_expiracion() ya lee
         * demo_end_time), y uno revocado se revocó a propósito — extenderlo acá lo reviviría. */
        $gracia         = \App\Services\LeadDemoSettings::get_gracia_minutos_post();
        $expira_nueva   = $fin_datetime->copy()->addMinutes($gracia);
        $token_ajustado = false;
        if (! empty($lead->demo_ingreso_token) && is_null($lead->demo_ingreso_token_revocado_at)) {
            try {
                if ($lead->demo_ingreso_token_expira_at === null
                    || $lead->demo_ingreso_token_expira_at->lt($expira_nueva)) {
                    $token_ajustado = $token_service->extender_vencimiento($lead, $expira_nueva);
                } else {
                    /* Piso del acorte: nunca un vencimiento en el pasado (ver docblock). */
                    $piso            = $now->copy()->addMinutes($gracia);
                    $expira_objetivo = $expira_nueva->lt($piso) ? $piso : $expira_nueva;
                    $token_ajustado  = $token_service->acortar_vencimiento($lead, $expira_objetivo);
                }
            } catch (\Throwable $e) {
                /* El servicio ya revirtió el vencimiento del token; acá se revierte el resto para
                 * no dejar un fin editado cuyo acceso no acompaña. */
                $lead->update([
                    'demo_end_time'                    => $fin_anterior !== '' ? $fin_anterior : null,
                    'demo_fin_check_reprogramado_para' => $reprogramado_anterior,
                ]);

                return response()->json([
                    'message' => 'No se pudo ajustar el token de ingreso: ' . $e->getMessage(),
                    'model'   => $this->fullModel('lead', $lead->id),
                ], 422);
            }
        }

        /* Trazabilidad (tarea 62, pieza 6): quién, de qué valor, a qué valor — el mismo patrón
         * lado a lado del log de la 47 cuando el modelo mandaba demo_end_time. */
        $admin = Auth::user();
        Log::info('LeadController: demo_end_time editado a mano desde el panel del lead.', [
            'lead_id'                      => $lead->id,
            'admin_id'                     => $admin ? $admin->id : null,
            'admin_name'                   => $admin ? $admin->name : null,
            'demo_end_time_anterior'       => $fin_anterior !== '' ? $fin_anterior : null,
            'demo_end_time_nuevo'          => $fin_nuevo,
            'token_ajustado'               => $token_ajustado,
            'check_fin_reprogramado_para'  => $reprogramado_nuevo !== null ? $reprogramado_nuevo->format('Y-m-d H:i:s') : null,
        ]);

        /* Y el evento visible en el hilo del lead, reusando el helper genérico del panel. */
        $this->registrar_evento_token_demo(
            $lead,
            'Fin de la demo cambiado de ' . ($fin_anterior !== '' ? $fin_anterior : 'sin hora') . ' a ' . $fin_nuevo
        );

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Registra en `admin_notifications` (columna JSON de un LeadMessage) el evento de
     * reemisión/revocación manual del token de ingreso a la demo, siguiendo el mismo patrón que
     * ya usa LeadAiService para "Demo agendada" y "Mail de demo enviado": un elemento
     * ['evento' => ..., 'admins' => [...]] dentro del array admin_notifications.
     *
     * A diferencia de LeadAiService (que acumula el evento sobre el LeadMessage que ya está
     * procesando en ese flujo y lo flushea al final), acá se crea un LeadMessage dedicado con
     * is_status_event=true (mismo recurso que usa LeadFollowupService para el pase automático a
     * "En Pausa"): esta es una acción administrativa manual desde el panel sin un mensaje "en
     * curso" al que atarla, y reusar el último LeadMessage del hilo podría pisar contenido de
     * otro flujo (una sugerencia pendiente de Claude, por ejemplo). Con is_status_event=true no
     * cuenta como actividad real del hilo (no actualiza last_message_at, no genera badge de "sin
     * leer": scopeForListNotifications lo excluye explícitamente).
     *
     * 'admins' => [] porque este evento no dispara ninguna notificación por WhatsApp a otros
     * admins (a diferencia de "Demo agendada", que sí pagea a los admins suscriptos vía
     * DemoScheduledWhatsappService); igual que "Mail de demo enviado", que también deja
     * 'admins' => [] cuando no hay a quién avisar por ese canal.
     *
     * @param Lead   $lead   Lead sobre el que se registra el evento.
     * @param string $evento Etiqueta legible del evento (reemitido/revocado).
     *
     * @return void
     */
    protected function registrar_evento_token_demo(Lead $lead, string $evento)
    {
        /* Admin autenticado que ejecutó la acción manual desde el panel. La ruta exige
           auth:sanctum, pero se resuelve con guarda por si se llamara fuera de ese contexto. */
        $admin = Auth::user();

        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $evento . ($admin ? ' por ' . $admin->name . '.' : '.'),
            'status'              => 'enviado',
            'is_followup'         => false,
            /* Evento administrativo, no actividad real del hilo de WhatsApp. */
            'is_status_event'     => true,
            'sent_by_admin_id'    => $admin ? $admin->id : null,
            'admin_notifications' => [
                ['evento' => $evento, 'admins' => []],
            ],
        ]);
    }

    /**
     * Datos de disponibilidad para el panel de verificación del lead (prompt 321): catálogo de
     * demos del pool (id + label) y los slots libres por demo/fecha, para poblar el selector de
     * entorno y el calendario de horarios al editar o forzar el agendamiento manualmente.
     *
     * Reutiliza LeadAiService::build_availability_json() (el mismo cálculo que usa Claude para
     * ofrecer horarios), pasando exclude_lead_id = $lead->id para que la demo ya asignada al
     * propio lead no aparezca bloqueada contra sí misma (FIX prompt 279).
     *
     * @param int|string $lead_id Lead sobre el que se calcula la disponibilidad.
     * @param LeadAiService $ai_service Servicio con la lógica de cálculo de slots (inyectado).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function panel_availability_json($lead_id, LeadAiService $ai_service)
    {
        // Lead objetivo: solo se usa su id, para excluirlo del bloqueo contra su propia demo.
        $lead = Lead::findOrFail($lead_id);

        // Snapshot de calendario de Google (no se usa acá, solo interesa la disponibilidad calculada).
        $calendar_snapshot = null;
        // Disponibilidad de la ventana fija de días corridos (self::DIAS_DISPONIBILIDAD),
        // sin fecha específica solicitada por el lead.
        $availability = $ai_service->build_availability_json(LeadAiService::DIAS_DISPONIBILIDAD, $calendar_snapshot, null, (int) $lead->id, $lead->usa_experiencia_demo_nueva());

        // Catálogo de demos del pool con label legible: se usa erp_spa_url, misma convención
        // que ya usa admin-spa para mostrar una demo (ver Leads.vue::demo_client_label).
        $demos = Demo::orderBy('id')->get();
        $demos_json = [];
        foreach ($demos as $demo) {
            $demos_json[] = [
                'demo_id' => (int) $demo->id,
                'label'   => $demo->erp_spa_url,
            ];
        }

        // El servicio arma las claves de fecha como "nombre_dia Y-m-d" (ej. "domingo 2026-06-28"),
        // formato que usan otros consumidores de build_availability_json y que no se debe tocar ahí.
        // Este endpoint remapea las claves a Y-m-d puro para cumplir el contrato del panel, sin
        // asumir una posición fija del substring dentro de la clave original.
        $slots_por_demo = [];
        foreach (($availability['demos'] ?? []) as $demo_id => $fechas) {
            $slots_por_demo[$demo_id] = [];
            foreach ($fechas as $fecha_legible => $horarios) {
                preg_match('/\d{4}-\d{2}-\d{2}/', $fecha_legible, $match_fecha);
                $fecha_key = $match_fecha[0] ?? $fecha_legible;
                $slots_por_demo[$demo_id][$fecha_key] = $horarios;
            }
        }

        return response()->json([
            'demos' => $demos_json,
            // Slots por demo_id => { 'Y-m-d' => ['HH:MM', ...] }, según el contrato del panel.
            'slots' => $slots_por_demo,
        ], 200);
    }

    /**
     * Persiste los toggles de automatización por lead (prompt 318) desde el modal de operaciones
     * del panel de verificación: el maestro que agrupa todo (automatizaciones_demo_activas) y los
     * cuatro específicos (recordatorio de demo, checks de ingreso/fin, resumen para el closer).
     *
     * Cada flag se castea explícitamente a boolean porque puede llegar como string/0/1 según el
     * cliente HTTP; si no viene en el request, se conserva el valor actual del lead.
     *
     * @param Request $request
     * @param int|string $lead_id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_lead_automations_json(Request $request, $lead_id)
    {
        // Lead objetivo cuyos flags de automatización se actualizan.
        $lead = Lead::findOrFail($lead_id);

        $lead->update([
            'automatizaciones_demo_activas' => (bool) $request->input('automatizaciones_demo_activas', $lead->automatizaciones_demo_activas),
            'auto_recordatorio_demo'        => (bool) $request->input('auto_recordatorio_demo', $lead->auto_recordatorio_demo),
            'auto_check_ingreso_demo'       => (bool) $request->input('auto_check_ingreso_demo', $lead->auto_check_ingreso_demo),
            'auto_check_fin_demo'           => (bool) $request->input('auto_check_fin_demo', $lead->auto_check_fin_demo),
            'auto_resumen_closer'           => (bool) $request->input('auto_resumen_closer', $lead->auto_resumen_closer),
        ]);

        // Se devuelve el lead completo (fullModel) para que el store del frontend actualice
        // los flags y cualquier otra relación que dependa de ellos en un solo golpe.
        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Promueve un lead a cliente de producción desde admin-spa.
     *
     * @param int|string $id
     * @param Request $request
     * @param PromoteLeadService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_promote_json($id, Request $request, PromoteLeadService $service)
    {
        // Lead base del proceso de promoción.
        $lead = Lead::findOrFail($id);
        // URL obligatoria del nuevo sistema de producción.
        $api_url = trim($request->input('api_url', ''));
        if (empty($api_url)) {
            return response()->json(['message' => 'La URL del sistema es obligatoria.'], 422);
        }

        try {
            $lead = $service->promote($lead, $api_url);
        } catch (\Throwable $error) {
            Log::error('LeadController@store_promote_json error: ' . $error->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            return response()->json([
                'message' => 'Error al promover: ' . $error->getMessage(),
                'model' => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Promueve el Lead a Client de producción en admin-api y genera las tareas automáticas.
     *
     * Crea el perfil de Client con los datos del lead (nombre, empresa, configuración comercial)
     * y dispara el proceso 'lead_a_cliente' que crea las AdminTasks predefinidas para el equipo.
     * A diferencia de run-user-setup, NO ejecuta el setup remoto del empresa-api del cliente.
     *
     * @param  int|string             $id
     * @param  Request                $request
     * @param  PromoteLeadToClientService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function promote_to_client_json($id, Request $request, PromoteLeadToClientService $service)
    {
        // Lead a promover.
        $lead = Lead::findOrFail($id);

        // Verificar que el lead no esté ya vinculado a un Client para evitar duplicados.
        if ($lead->promoted_client_id) {
            return response()->json([
                'message' => 'El lead ya tiene un Client de producción vinculado. Para reinstalar el sistema usá "Correr user setup".',
                'model'   => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        try {
            // Ejecutar el servicio de promoción: crea Client + genera tareas automáticas.
            // Si el operador envió un subdominio sugerido desde la UI, se usa directamente.
            $suggested_subdomain = trim((string) $request->input('suggested_subdomain', ''));
            $lead = $service->run($lead, $request->user(), $suggested_subdomain);
        } catch (\Throwable $error) {
            Log::error('LeadController@promote_to_client_json error: ' . $error->getMessage(), [
                'lead_id' => $lead->id,
            ]);

            return response()->json([
                'message' => 'Error al promover a cliente: ' . $error->getMessage(),
                'model'   => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Ejecuta user-setup del sistema real desde admin-spa.
     *
     * @param int|string $id
     * @param RunUserSetupService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function run_user_setup_json($id, RunUserSetupService $service)
    {
        // Lead promovido objetivo para el setup de producción.
        $lead = Lead::findOrFail($id);
        // Ejecución encapsulada en servicio de provisioning.
        $lead = $service->run($lead);

        if ($lead->user_setup_status === 'exitoso') {
            return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
        }

        return response()->json([
            'message' => 'No se pudo crear el sistema: ' . $lead->user_setup_last_error,
            'model' => $this->fullModel('lead', $lead->id),
        ], 422);
    }

    /**
     * Persiste uno o varios mensajes pegados desde WhatsApp (lead y/o setter) y genera sugerencia vía Claude.
     *
     * Acepta un bloque con varias líneas de export de WhatsApp Web; clasifica cada mensaje por remitente
     * usando el teléfono y nombre de contacto del lead, y los crea en orden antes de llamar a Claude.
     *
     * @param Request $request Debe incluir `content` (texto pegado del chat).
     * @param int|string $lead_id
     * @param LeadAiService $ai_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_message_json(Request $request, $lead_id, LeadAiService $ai_service)
    {
        $raw = trim((string) $request->input('content', ''));
        if ($raw === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        $lead = Lead::query()->with('messages')->findOrFail($lead_id);

        $parsed_messages = LeadWhatsAppPasteCleaner::parse_export_paste(
            $raw,
            (string) $lead->phone,
            (string) $lead->contact_name
        );

        if (empty($parsed_messages)) {
            return response()->json(['message' => 'El mensaje no puede estar vacío (tras quitar el formato de WhatsApp).'], 422);
        }

        $created_count = 0;

        foreach ($parsed_messages as $parsed_item) {
            $sender = isset($parsed_item['sender']) ? (string) $parsed_item['sender'] : 'lead';
            $content = isset($parsed_item['content']) ? trim((string) $parsed_item['content']) : '';

            if ($content === '') {
                continue;
            }

            if (! in_array($sender, ['lead', 'setter'], true)) {
                $sender = 'lead';
            }

            LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => $sender,
                'content'               => $content,
                'status'                => 'enviado',
                'is_followup'           => false,
                'requiere_verificacion' => false,
            ]);

            $created_count++;
        }

        if ($created_count === 0) {
            return response()->json(['message' => 'El mensaje no puede estar vacío (tras quitar el formato de WhatsApp).'], 422);
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id);

        try {
            $fresh = Lead::query()->with('messages')->where('id', $lead->id)->first();
            if (! $fresh) {
                return response()->json(['message' => 'Lead no encontrado.'], 404);
            }
            $ai_service->generate_suggestion($fresh, false);
        } catch (\Throwable $e) {
            Log::error('LeadController@store_message_json AI error: '.$e->getMessage(), ['lead_id' => $lead->id]);

            return response()->json([
                'message' => 'No se pudo generar la sugerencia: '.$e->getMessage(),
                'model'   => $this->fullModel('lead', $lead->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Envía un mensaje de texto directamente al lead por WhatsApp desde el panel de admin.
     *
     * El mensaje se crea como `setter` y se envía sin pasar por Claude.
     *
     * Si el operador escribió el separador completo -renglón en blanco, línea con tres guiones,
     * renglón en blanco-, el texto sale partido en varios WhatsApp por el mismo camino que las
     * sugerencias de Claude (`LeadSuggestionSendService::enviar_partes()`): con sus pausas de
     * 1200ms, sus reintentos y su corte al primer fallo. En la base queda UN SOLO LeadMessage con
     * el texto completo, separadores incluidos, más los contadores de partes: el hilo de leads ya
     * sabe leer esas tres columnas y mostrar el envío parcial, así que no hace falta una fila por
     * parte (que sí obligaría a cambiar el SPA).
     *
     * @param Request                   $request               Debe incluir `content` (texto del mensaje).
     * @param int|string                $lead_id
     * @param WhatsappSendService       $whatsapp_send_service Envío de una sola parte, el de siempre.
     * @param LeadSuggestionSendService $send_service          Envío partido, compartido con las sugerencias.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_direct_message_json(Request $request, $lead_id, WhatsappSendService $whatsapp_send_service, LeadSuggestionSendService $send_service)
    {
        $text = trim((string) $request->input('content', ''));
        if ($text === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        $lead = Lead::query()->findOrFail($lead_id);

        $phone = trim((string) ($lead->phone ?? ''));

        /* El criterio de partido es el mismo que en soporte y vive en una sola clase a propósito:
           lo que escribe una persona se parte solo si pidió partirlo con el separador completo.
           Un "---" suelto, un subrayado de markdown o unos guiones adentro de un párrafo no
           parten nada, porque cambiarle el mensaje a alguien sin que lo haya pedido es peor que
           no partirlo. */
        $partes = (new SeparadorDeMensajesManuales())->partir($text);

        /* Los tres contadores quedan en null cuando el mensaje salió entero. La burbuja de leads
           decide si muestra "Enviado parcialmente" mirando sent_parts_count/total_parts_count, así
           que llenarlos en un mensaje que nunca se partió le pondría un distintivo de más. */
        $whatsapp_message_id  = null;
        $sent_parts_count     = null;
        $total_parts_count    = null;
        $partial_send_pending = null;

        if ($phone !== '') {
            try {
                if (count($partes) > 1) {
                    /* Se reusa el envío partido de las sugerencias en vez de escribir uno nuevo:
                       las pausas de 1200ms, los tres intentos con backoff y el corte apenas una
                       parte no sale son la solución al incidente del lead #440 (22/7/2026), y una
                       copia de eso se desincroniza sola. */
                    $resultado = $send_service->enviar_partes(
                        $phone,
                        $partes,
                        'Mensaje directo del panel - Lead #' . $lead->id
                            . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : ''),
                        SeparadorDeMensajesManuales::SEPARADOR
                    );

                    $whatsapp_message_id = $resultado['last_message_id'];
                    $sent_parts_count    = $resultado['sent_parts'];
                    $total_parts_count   = $resultado['total_parts'];

                    /* Lo pendiente se guarda solo cuando algo salió y algo no: si no salió ninguna
                       parte, el texto entero sigue en `content` y duplicarlo no le sirve a nadie. */
                    if ($resultado['sent_parts'] > 0 && $resultado['sent_parts'] < $resultado['total_parts']) {
                        $partial_send_pending = $resultado['pending_text'];
                    }

                    if ($resultado['sent_parts'] === 0) {
                        /* Nada llegó al lead. Se deja asentado en el hilo igual que cuando el envío
                           tira excepción, para que el operador vea por qué no salió. El mensaje se
                           crea igual unas líneas más abajo, como ya pasaba con el envío de una sola
                           parte que WhatsApp rechaza: acá se agrega el partido, no se rediseña el
                           manejo de fallos. */
                        (new LeadConversationErrorLogger())->log(
                            (int) $lead->id,
                            'No se pudo enviar el mensaje por WhatsApp',
                            (string) ($resultado['error'] ?? '')
                        );
                    }
                } else {
                    $whatsapp_message_id = $whatsapp_send_service->send_text($phone, $text);
                }
            } catch (\Throwable $e) {
                Log::error('LeadController@send_direct_message_json: error WhatsApp.', [
                    'lead_id' => $lead_id,
                    'error'   => $e->getMessage(),
                ]);

                // Registrar el fallo de envío en la conversación del lead.
                (new LeadConversationErrorLogger())->log(
                    (int) $lead->id,
                    'No se pudo enviar el mensaje por WhatsApp',
                    $e->getMessage()
                );

                return response()->json(['message' => 'No se pudo enviar el mensaje por WhatsApp: '.$e->getMessage()], 422);
            }
        }

        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            // El texto completo, separadores incluidos: es lo que el operador escribió y lo que
            // tiene que poder releer y volver a copiar desde el hilo.
            'content'               => $text,
            'status'                => 'enviado',
            'whatsapp_message_id'   => $whatsapp_message_id,
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
            // Admin autor del mensaje directo (prompt 403).
            'sent_by_admin_id'      => (int) $request->user()->id,
            'sent_parts_count'      => $sent_parts_count,
            'total_parts_count'     => $total_parts_count,
            'partial_send_pending'  => $partial_send_pending,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Envía un mensaje de audio (nota de voz) directamente al lead por WhatsApp desde el panel de admin.
     *
     * El audio llega grabado desde el navegador como archivo multipart (campo `audio`, fallback `file`).
     * Se persiste el binario en disco `public`, se crea el LeadMessage como `setter` con kind `audio` y
     * su LeadMessageAttachment, y se envía con el servicio de audio ya existente (mismo que usa soporte).
     * Igual que el envío de texto, el mensaje queda guardado aunque WhatsApp esté en test_mode o el lead
     * no tenga teléfono (en esos casos el envío devuelve null sin ser error).
     *
     * @param Request             $request               Debe incluir el archivo de audio en `audio` (o `file`).
     * @param int|string          $lead_id
     * @param WhatsappSendService $whatsapp_send_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_direct_audio_json(Request $request, $lead_id, WhatsappSendService $whatsapp_send_service)
    {
        // El frontend graba con MediaRecorder y sube el blob en el campo `audio`; aceptamos `file` como fallback.
        $uploaded_file = $request->file('audio') ?: $request->file('file');
        if ($uploaded_file === null || ! $uploaded_file->isValid()) {
            return response()->json(['message' => 'No se recibió un archivo de audio válido.'], 422);
        }

        // Mime real del archivo subido. WhatsApp acepta audio/*; iOS Safari puede sniffearse como video/mp4
        // aunque el contenido sea audio; Chrome a veces reporta video/webm para blobs WebM de voz.
        $mime = strtolower((string) $uploaded_file->getMimeType());

        // iOS Safari graba en fragmented MP4 que PHP finfo no siempre reconoce (devuelve application/octet-stream).
        // Como safety net, usar el mime reportado por el cliente si corresponde a audio o video/mp4.
        if ($mime === 'application/octet-stream') {
            $client_mime     = strtolower((string) $uploaded_file->getClientMimeType());
            $is_client_audio = strpos($client_mime, 'audio/') === 0;
            $is_client_mp4   = $client_mime === 'video/mp4' || $client_mime === 'video/quicktime';
            $is_client_webm  = strpos($client_mime, 'webm') !== false;
            if ($is_client_audio || $is_client_mp4 || $is_client_webm) {
                $mime = $client_mime;
            }
        }

        $is_audio_mime = strpos($mime, 'audio/') === 0;
        $is_webm = strpos($mime, 'webm') !== false;
        $is_mp4_video = $mime === 'video/mp4' || $mime === 'video/quicktime';
        if (! $is_audio_mime && ! $is_webm && ! $is_mp4_video) {
            return response()->json(['message' => 'El archivo no es un audio soportado (mime: '.$mime.').'], 422);
        }

        // Límite de 16 MB: tope de audio de la Cloud API de WhatsApp.
        $max_bytes = 16 * 1024 * 1024;
        if ($uploaded_file->getSize() > $max_bytes) {
            return response()->json(['message' => 'El audio supera el tamaño máximo permitido (16 MB).'], 422);
        }

        // Lead destino del audio.
        $lead = Lead::query()->findOrFail($lead_id);

        // Extensión coherente con el mime real, para que el servicio de envío detecte el formato correcto.
        $extension = $this->resolve_outbound_audio_extension($mime, $uploaded_file->getClientOriginalExtension());

        // Guardamos el binario en disco public bajo el mismo directorio que los audios entrantes del lead.
        $directory = 'lead_messages/' . $lead->id;
        $stored_name = 'out_' . now()->format('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $extension;
        $stored_path = $directory . '/' . $stored_name;
        Storage::disk('public')->put($stored_path, file_get_contents($uploaded_file->getRealPath()));

        // Mensaje saliente: mismo contrato que el audio entrante (kind audio + content placeholder).
        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'kind'                  => 'audio',
            'content'               => '[Audio enviado]',
            'status'                => 'enviado',
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
            // Admin autor del audio directo (prompt 403).
            'sent_by_admin_id'      => (int) $request->user()->id,
        ]);

        // Adjunto persistido: habilita la reproducción en la conversación vía public_url firmado.
        $attachment = LeadMessageAttachment::create([
            'lead_message_id' => $message->id,
            'disk'            => 'public',
            'path'            => $stored_path,
            'mime'            => $mime !== '' ? $mime : null,
            'size'            => Storage::disk('public')->size($stored_path),
        ]);

        // Envío real por WhatsApp reutilizando el servicio de audio existente (sube media + envía type audio).
        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone !== '') {
            try {
                $whatsapp_message_id = $whatsapp_send_service->send_audio_attachment($phone, $attachment);
                if ($whatsapp_message_id !== null && $whatsapp_message_id !== '') {
                    $message->update(['whatsapp_message_id' => $whatsapp_message_id]);
                }

                // Si Meta rechazó el upload silenciosamente (null), no dejar el mensaje en la UI como enviado.
                if ($whatsapp_message_id === null) {
                    $whatsapp_config = \App\Models\WhatsappConfig::getActive();
                    $is_test_mode = $whatsapp_config && $whatsapp_config->test_mode;
                    if (! $is_test_mode) {
                        Log::warning('LeadController@send_direct_audio_json: audio guardado pero WhatsApp send retornó null (revisar logs de WhatsappSendService).', [
                            'lead_id'    => $lead_id,
                            'attachment' => $attachment->id,
                        ]);
                        $message->delete();
                        $attachment->delete();
                        Storage::disk('public')->delete($stored_path);

                        return response()->json(['message' => 'El audio se grabó correctamente pero no se pudo enviar a WhatsApp. Revisá los logs para más detalles.'], 422);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('LeadController@send_direct_audio_json: error WhatsApp.', [
                    'lead_id' => $lead_id,
                    'error'   => $e->getMessage(),
                ]);

                // El mensaje y su adjunto ya quedaron persistidos; informamos el fallo de envío sin borrarlos.
                return response()->json(['message' => 'No se pudo enviar el audio por WhatsApp: '.$e->getMessage()], 422);
            }
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Envía una imagen directamente al lead por WhatsApp desde el panel de admin (prompt 465).
     *
     * Calcado de `send_direct_audio_json`: la imagen llega como archivo multipart (campo `image`,
     * fallback `file`), se valida mime y tamaño, se persiste el binario en disco `public`, se crea
     * el LeadMessage como `setter` con kind `image` (con `caption` opcional como contenido) y su
     * LeadMessageAttachment, y se envía reutilizando `WhatsappSendService::send_image_attachment`
     * (ya usado por el módulo de soporte). El mensaje queda guardado aunque WhatsApp esté en
     * test_mode o el lead no tenga teléfono (en esos casos el envío devuelve null sin ser error).
     *
     * @param Request             $request               Debe incluir el archivo en `image` (o `file`)
     *                                                    y opcionalmente `caption` (texto libre).
     * @param int|string          $lead_id
     * @param WhatsappSendService $whatsapp_send_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_direct_image_json(Request $request, $lead_id, WhatsappSendService $whatsapp_send_service)
    {
        // El frontend sube la imagen en el campo `image`; aceptamos `file` como fallback (mismo patrón que audio).
        $uploaded_file = $request->file('image') ?: $request->file('file');
        if ($uploaded_file === null || ! $uploaded_file->isValid()) {
            return response()->json(['message' => 'No se recibió un archivo de imagen válido.'], 422);
        }

        // Mime real del archivo subido.
        $mime = strtolower((string) $uploaded_file->getMimeType());

        // Algunos navegadores/plataformas no permiten a finfo detectar el mime real (devuelve
        // application/octet-stream). Como safety net, usamos el mime reportado por el cliente
        // si corresponde efectivamente a una imagen.
        if ($mime === 'application/octet-stream') {
            $client_mime = strtolower((string) $uploaded_file->getClientMimeType());
            if (strpos($client_mime, 'image/') === 0) {
                $mime = $client_mime;
            }
        }

        // Solo se acepta contenido cuyo mime sea efectivamente una imagen.
        if (strpos($mime, 'image/') !== 0) {
            return response()->json(['message' => 'El archivo no es una imagen soportada (mime: '.$mime.').'], 422);
        }

        // Límite de 5 MB: tope de imagen de la Cloud API de WhatsApp.
        $max_bytes = 5 * 1024 * 1024;
        if ($uploaded_file->getSize() > $max_bytes) {
            return response()->json(['message' => 'La imagen supera el tamaño máximo permitido (5 MB).'], 422);
        }

        // Caption opcional: si viene vacío, se guarda como null (no como string vacío).
        $caption = trim((string) $request->input('caption', ''));
        if ($caption === '') {
            $caption = null;
        }

        // Lead destino de la imagen.
        $lead = Lead::query()->findOrFail($lead_id);

        // Extensión derivada del mime real, coherente con lo que reconoce el servicio de envío.
        switch ($mime) {
            case 'image/png':
                $ext = 'png';
                break;
            case 'image/webp':
                $ext = 'webp';
                break;
            case 'image/gif':
                $ext = 'gif';
                break;
            case 'image/jpeg':
            default:
                $ext = 'jpg';
                break;
        }

        // Guardamos el binario en disco public bajo el mismo directorio que los mensajes del lead.
        $directory = 'lead_messages/' . $lead->id;
        $stored_name = 'out_img_' . now()->format('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $stored_path = $directory . '/' . $stored_name;
        Storage::disk('public')->put($stored_path, file_get_contents($uploaded_file->getRealPath()));

        // Mensaje saliente: kind image + content con el caption (o placeholder si no hay caption).
        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'kind'                  => 'image',
            'content'               => ($caption !== null && $caption !== '') ? $caption : '[Imagen enviada]',
            'status'                => 'enviado',
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
            // Admin autor de la imagen directa (mismo patrón que audio/texto).
            'sent_by_admin_id'      => (int) $request->user()->id,
        ]);

        // Adjunto persistido: habilita la visualización en la conversación vía public_url firmado.
        $attachment = LeadMessageAttachment::create([
            'lead_message_id'   => $message->id,
            'disk'              => 'public',
            'path'              => $stored_path,
            'mime'              => $mime,
            'size'              => Storage::disk('public')->size($stored_path),
            'original_filename' => $uploaded_file->getClientOriginalName(),
        ]);

        // Envío real por WhatsApp reutilizando el servicio de imagen existente (sube media + envía type image).
        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone !== '') {
            try {
                $whatsapp_message_id = $whatsapp_send_service->send_image_attachment($phone, $attachment, $caption);
                if ($whatsapp_message_id !== null && $whatsapp_message_id !== '') {
                    $message->update(['whatsapp_message_id' => $whatsapp_message_id]);
                }

                // Si Meta rechazó el upload silenciosamente (null), no dejar el mensaje en la UI como enviado.
                if ($whatsapp_message_id === null) {
                    $whatsapp_config = \App\Models\WhatsappConfig::getActive();
                    $is_test_mode = $whatsapp_config && $whatsapp_config->test_mode;
                    if (! $is_test_mode) {
                        Log::warning('LeadController@send_direct_image_json: imagen guardada pero WhatsApp send retornó null (revisar logs de WhatsappSendService).', [
                            'lead_id'    => $lead_id,
                            'attachment' => $attachment->id,
                        ]);
                        $message->delete();
                        $attachment->delete();
                        Storage::disk('public')->delete($stored_path);

                        return response()->json(['message' => 'La imagen se guardó correctamente pero no se pudo enviar a WhatsApp. Revisá los logs para más detalles.'], 422);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('LeadController@send_direct_image_json: error WhatsApp.', [
                    'lead_id' => $lead_id,
                    'error'   => $e->getMessage(),
                ]);

                // El mensaje y su adjunto ya quedaron persistidos; informamos el fallo de envío sin borrarlos.
                return response()->json(['message' => 'No se pudo enviar la imagen por WhatsApp: '.$e->getMessage()], 422);
            }
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Envía un documento (PDF/Excel/Word/etc.) directamente al lead por WhatsApp desde el panel de admin (prompt 466).
     *
     * Calcado de `send_direct_image_json`: el documento llega como archivo multipart (campo `document`,
     * fallback `file`), se valida que NO sea una imagen ni un audio (esos van por sus endpoints propios),
     * se valida el tamaño (tope 100 MB de la Cloud API de WhatsApp), se persiste el binario en disco
     * `public`, se crea el LeadMessage como `setter` con kind `document` (con `caption` opcional como
     * contenido) y su LeadMessageAttachment (guardando el nombre original del archivo), y se envía
     * reutilizando `WhatsappSendService::send_document_attachment` (ahora público, prompt 466). El
     * mensaje queda guardado aunque WhatsApp esté en test_mode o el lead no tenga teléfono (en esos
     * casos el envío devuelve null sin ser error).
     *
     * @param Request             $request               Debe incluir el archivo en `document` (o `file`)
     *                                                    y opcionalmente `caption` (texto libre).
     * @param int|string          $lead_id
     * @param WhatsappSendService $whatsapp_send_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_direct_document_json(Request $request, $lead_id, WhatsappSendService $whatsapp_send_service)
    {
        // El frontend sube el documento en el campo `document`; aceptamos `file` como fallback (mismo patrón que imagen/audio).
        $uploaded_file = $request->file('document') ?: $request->file('file');
        if ($uploaded_file === null || ! $uploaded_file->isValid()) {
            return response()->json(['message' => 'No se recibió un archivo de documento válido.'], 422);
        }

        // Mime real del archivo subido.
        $mime = strtolower((string) $uploaded_file->getMimeType());

        // Si finfo no pudo detectar el mime real (devuelve application/octet-stream), usamos el mime
        // reportado por el cliente como safety net, siempre que no venga vacío.
        if ($mime === 'application/octet-stream') {
            $client_mime = strtolower((string) $uploaded_file->getClientMimeType());
            if ($client_mime !== '') {
                $mime = $client_mime;
            }
        }

        // Este endpoint es solo para documentos: las imágenes y audios tienen sus propios endpoints
        // (send-direct-image / send-direct-audio), que aplican su propia validación y envío.
        if (strpos($mime, 'image/') === 0 || strpos($mime, 'audio/') === 0) {
            return response()->json(['message' => 'Este archivo debe enviarse como imagen o audio, no como documento (mime: '.$mime.').'], 422);
        }

        // Límite de 100 MB: tope de documento de la Cloud API de WhatsApp. El límite práctico real lo
        // impone upload_max_filesize/post_max_size de PHP; si el archivo es más grande, la request
        // muere antes de llegar acá con un 413 (esperable, no se maneja en este método).
        $max_bytes = 100 * 1024 * 1024;
        if ($uploaded_file->getSize() > $max_bytes) {
            return response()->json(['message' => 'El documento supera el tamaño máximo permitido (100 MB).'], 422);
        }

        // Caption opcional: si viene vacío, se guarda como null (no como string vacío).
        $caption = trim((string) $request->input('caption', ''));
        if ($caption === '') {
            $caption = null;
        }

        // Lead destino del documento.
        $lead = Lead::query()->findOrFail($lead_id);

        // Nombre original del archivo: es el nombre con el que el lead lo recibe por WhatsApp y con
        // el que el admin lo descarga después desde la conversación.
        $original_name = trim((string) $uploaded_file->getClientOriginalName());
        if ($original_name === '') {
            $original_name = 'documento';
        }

        // Extensión para el nombre de archivo en disco (no se expone al lead, solo uso interno).
        $ext = strtolower((string) $uploaded_file->getClientOriginalExtension());
        if ($ext === '') {
            $ext = 'bin';
        }

        // Guardamos el binario en disco public bajo el mismo directorio que los mensajes del lead.
        $directory = 'lead_messages/' . $lead->id;
        $stored_name = 'out_doc_' . now()->format('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $stored_path = $directory . '/' . $stored_name;
        Storage::disk('public')->put($stored_path, file_get_contents($uploaded_file->getRealPath()));

        // Mensaje saliente: kind document + content con el caption (o placeholder si no hay caption).
        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'kind'                  => 'document',
            'content'               => ($caption !== null && $caption !== '') ? $caption : '[Documento enviado]',
            'status'                => 'enviado',
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
            // Admin autor del documento directo (mismo patrón que imagen/audio/texto).
            'sent_by_admin_id'      => (int) $request->user()->id,
        ]);

        // Adjunto persistido: habilita la visualización/descarga en la conversación vía public_url
        // firmado, conservando el nombre original con el que el lead lo recibió.
        $attachment = LeadMessageAttachment::create([
            'lead_message_id'   => $message->id,
            'disk'              => 'public',
            'path'              => $stored_path,
            'mime'              => $mime,
            'size'              => Storage::disk('public')->size($stored_path),
            'original_filename' => $original_name,
        ]);

        // Envío real por WhatsApp reutilizando el servicio de documento (ahora público, prompt 466):
        // sube media + envía type document con el nombre original del archivo.
        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone !== '') {
            try {
                $whatsapp_message_id = $whatsapp_send_service->send_document_attachment($phone, $attachment, $original_name, $mime);
                if ($whatsapp_message_id !== null && $whatsapp_message_id !== '') {
                    $message->update(['whatsapp_message_id' => $whatsapp_message_id]);
                }

                // Si Meta rechazó el upload silenciosamente (null), no dejar el mensaje en la UI como enviado.
                if ($whatsapp_message_id === null) {
                    $whatsapp_config = \App\Models\WhatsappConfig::getActive();
                    $is_test_mode = $whatsapp_config && $whatsapp_config->test_mode;
                    if (! $is_test_mode) {
                        Log::warning('LeadController@send_direct_document_json: documento guardado pero WhatsApp send retornó null (revisar logs de WhatsappSendService).', [
                            'lead_id'    => $lead_id,
                            'attachment' => $attachment->id,
                        ]);
                        $message->delete();
                        $attachment->delete();
                        Storage::disk('public')->delete($stored_path);

                        return response()->json(['message' => 'El documento se guardó correctamente pero no se pudo enviar a WhatsApp. Revisá los logs para más detalles.'], 422);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('LeadController@send_direct_document_json: error WhatsApp.', [
                    'lead_id' => $lead_id,
                    'error'   => $e->getMessage(),
                ]);

                // El mensaje y su adjunto ya quedaron persistidos; informamos el fallo de envío sin borrarlos.
                return response()->json(['message' => 'No se pudo enviar el documento por WhatsApp: '.$e->getMessage()], 422);
            }
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Deriva la extensión del audio saliente a partir del mime real del archivo subido.
     *
     * Mantiene coherencia con los formatos que reconoce WhatsappSendService al reenviar el audio.
     *
     * @param string $mime               Mime real reportado por el archivo subido.
     * @param string $original_extension Extensión original del archivo (fallback).
     *
     * @return string
     */
    private function resolve_outbound_audio_extension(string $mime, string $original_extension): string
    {
        $mime = strtolower($mime);

        if (strpos($mime, 'ogg') !== false) {
            return 'ogg';
        }
        if (strpos($mime, 'mpeg') !== false) {
            return 'mp3';
        }
        if (strpos($mime, 'aac') !== false) {
            return 'aac';
        }
        if (strpos($mime, 'amr') !== false) {
            return 'amr';
        }
        if (strpos($mime, 'mp4') !== false || strpos($mime, 'quicktime') !== false) {
            return 'm4a';
        }
        if (strpos($mime, 'webm') !== false) {
            return 'webm';
        }

        // Fallback: usamos la extensión original si vino, o ogg (nota de voz por defecto).
        $original_extension = strtolower(trim($original_extension));

        return $original_extension !== '' ? $original_extension : 'ogg';
    }

    /**
     * Simula un mensaje entrante del lead sin pasar por WhatsApp (herramienta de testing del setter).
     *
     * Replica el mismo flujo que dispara el webhook real de Kapso al recibir un mensaje del lead:
     * crea el LeadMessage como `lead`, emite el broadcast de conversación y programa la sugerencia
     * de Claude con el debounce configurado. Útil para probar el pipeline de IA y de seguimiento
     * aunque WhatsApp esté en `test_mode` o el lead no responda realmente.
     *
     * @param Request    $request Debe incluir `content` (texto simulado del lead).
     * @param int|string $id      Identificador del lead.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function simulate_inbound_json(Request $request, $id)
    {
        // Lead objetivo de la simulación.
        $lead = Lead::findOrFail($id);

        // Texto simulado del lead; sin contenido no hay nada que simular.
        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        // Mismo orden que WhatsappWebhookController::handle_lead_message: onboarding antes de persistir inbound.
        $onboarding_service = new LeadWhatsappOnboardingService();
        $display_name = $onboarding_service->resolve_display_name(
            ['from' => $lead->phone, 'contact_name' => $lead->contact_name],
            [],
            $lead
        );
        $run_onboarding = $onboarding_service->should_run_onboarding($lead);

        // Mensaje entrante del lead, equivalente al que persiste el webhook (kind text, status enviado).
        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'kind'                  => 'text',
            'content'               => $content,
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
            'sent_at'               => now(),
        ]);

        // Notificar a la conversación abierta y a los listados (mismo broadcast que el webhook).
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        // Bienvenida inmediata + job de presentación (igual que webhook real).
        if ($run_onboarding) {
            $onboarding_service->send_welcome_and_schedule_presentation($lead, $display_name);
        } elseif (
            (string) $lead->status === 'nuevo'
            && ! $onboarding_service->has_auto_message_been_sent($lead)
        ) {
            // Recuperación: inbound previo sin auto (p. ej. simulación anterior sin onboarding).
            $onboarding_service->send_welcome_and_schedule_presentation($lead, $display_name);
        }

        // Disparar el mismo flujo de sugerencia IA con debounce que usa el webhook real.
        // (No genera sugerencia en el primer inbound del lead, igual que en producción.)
        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Pide sugerencia a Claude de inmediato cuando hay mensajes del lead sin responder.
     *
     * Cancela el debounce automático pendiente; el envío automático de la sugerencia generada
     * sigue respetando la demora configurada en LeadAiSuggestionAutoSendScheduler.
     *
     * @param int|string              $lead_id
     * @param LeadAiService           $ai_service
     * @param LeadAiSuggestionScheduler $scheduler
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function request_ai_suggestion_json($lead_id, LeadAiService $ai_service, LeadAiSuggestionScheduler $scheduler)
    {
        $lead = Lead::query()->with('messages')->findOrFail($lead_id);

        if (LeadConversationAiState::count_lead_inbound_messages((int) $lead->id) <= 1) {
            return response()->json([
                'message' => 'La sugerencia IA aplica desde el segundo mensaje del lead.',
            ], 422);
        }

        if (! LeadConversationAiState::has_unanswered_lead_messages($lead)) {
            return response()->json([
                'message' => 'No hay mensajes del lead sin responder.',
            ], 422);
        }

        if (LeadConversationAiState::has_pending_non_followup_suggestion($lead)) {
            return response()->json([
                'message' => 'Ya hay una sugerencia pendiente de revisión.',
            ], 422);
        }

        $scheduler->cancel_scheduled_suggestion((int) $lead->id);

        event(new LeadAiSuggestionGenerating((int) $lead->id));

        try {
            $fresh = Lead::query()->with('messages')->where('id', $lead->id)->first();
            if (! $fresh) {
                return response()->json(['message' => 'Lead no encontrado.'], 404);
            }
            $ai_service->generate_suggestion($fresh, false);
        } catch (\Throwable $e) {
            Log::error('LeadController@request_ai_suggestion_json AI error: '.$e->getMessage(), ['lead_id' => $lead->id]);

            try {
                $lead_identifier = "Lead #{$lead->id}"
                    . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '');
                $notify_service = new \App\Services\SystemErrorWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $notify_service->notify_send_error(
                    "Generación manual de sugerencia IA ({$lead_identifier})",
                    $e->getMessage()
                );
            } catch (\Throwable $notify_exception) {
                Log::error('LeadController: error al notificar admins de fallo de sugerencia.', [
                    'lead_id'   => $lead->id,
                    'exception' => $notify_exception->getMessage(),
                ]);
            }

            // Registrar el error también en la conversación del lead (además del log y del aviso a admins de arriba).
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo generar la sugerencia de Claude',
                $e->getMessage()
            );

            return response()->json([
                'message' => 'No se pudo generar la sugerencia: '.$e->getMessage(),
                'model'   => $this->fullModel('lead', $lead->id),
            ], 422);
        } finally {
            event(new LeadAiSuggestionFinished((int) $lead->id));
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Genera sugerencia IA a partir del historial completo aunque el último mensaje sea del setter.
     *
     * Permite retomar el flujo con Claude cuando el operador tomó control manual de la conversación
     * (claude_auto_reply desactivado) y escribió al lead sin esperar respuesta entrante nueva.
     *
     * @param int|string                $lead_id
     * @param LeadAiService             $ai_service
     * @param LeadAiSuggestionScheduler $scheduler
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resume_with_claude_json($lead_id, LeadAiService $ai_service, LeadAiSuggestionScheduler $scheduler)
    {
        // Lead con mensajes para evaluar sugerencias pendientes y generar la nueva propuesta.
        $lead = Lead::query()->with('messages')->findOrFail($lead_id);

        // Solo bloquear si ya hay una sugerencia pendiente sin enviar (misma regla que el botón ⚡).
        if (LeadConversationAiState::has_pending_non_followup_suggestion($lead)) {
            return response()->json([
                'message' => 'Ya hay una sugerencia pendiente de revisión.',
            ], 422);
        }

        // Cancelar cualquier debounce pendiente antes de generar en caliente.
        $scheduler->cancel_scheduled_suggestion((int) $lead->id);

        event(new LeadAiSuggestionGenerating((int) $lead->id));

        try {
            $fresh = Lead::query()->with('messages')->where('id', $lead->id)->first();
            if (! $fresh) {
                return response()->json(['message' => 'Lead no encontrado.'], 404);
            }
            $ai_service->generate_suggestion($fresh, false);
        } catch (\Throwable $e) {
            Log::error('LeadController@resume_with_claude_json AI error: '.$e->getMessage(), ['lead_id' => $lead->id]);

            try {
                $lead_identifier = "Lead #{$lead->id}"
                    . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '');
                $notify_service = new \App\Services\SystemErrorWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $notify_service->notify_send_error(
                    "Generación manual de sugerencia IA ({$lead_identifier})",
                    $e->getMessage()
                );
            } catch (\Throwable $notify_exception) {
                Log::error('LeadController: error al notificar admins de fallo de sugerencia.', [
                    'lead_id'   => $lead->id,
                    'exception' => $notify_exception->getMessage(),
                ]);
            }

            // Registrar el error también en la conversación del lead (además del log y del aviso a admins de arriba).
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo generar la sugerencia de Claude',
                $e->getMessage()
            );

            return response()->json([
                'message' => 'No se pudo generar la sugerencia: '.$e->getMessage(),
                'model'   => $this->fullModel('lead', $lead->id),
            ], 422);
        } finally {
            event(new LeadAiSuggestionFinished((int) $lead->id));
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Cancela el job diferido que pediría sugerencia IA a Claude tras el debounce automático.
     *
     * No genera sugerencia ni modifica mensajes; el setter puede responder manualmente o pedir IA después.
     *
     * @param int|string                $lead_id
     * @param LeadAiSuggestionScheduler $scheduler
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel_scheduled_ai_suggestion_json($lead_id, LeadAiSuggestionScheduler $scheduler)
    {
        $lead = Lead::query()->with('messages')->findOrFail($lead_id);

        if (LeadConversationAiState::count_lead_inbound_messages((int) $lead->id) <= 1) {
            return response()->json([
                'message' => 'La sugerencia IA automática aplica desde el segundo mensaje del lead.',
            ], 422);
        }

        if (! LeadConversationAiState::has_unanswered_lead_messages($lead)) {
            return response()->json([
                'message' => 'No hay mensajes del lead sin responder.',
            ], 422);
        }

        if (LeadConversationAiState::has_pending_non_followup_suggestion($lead)) {
            return response()->json([
                'message' => 'Ya hay una sugerencia pendiente de revisión.',
            ], 422);
        }

        $scheduler->cancel_scheduled_suggestion((int) $lead->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Activa o desactiva la respuesta automática de Claude para un lead concreto.
     *
     * Al desactivar, cancela el debounce pendiente para que no se genere sugerencia en cola.
     *
     * @param int|string                $lead_id
     * @param LeadAiSuggestionScheduler $scheduler
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_claude_auto_reply_json($lead_id, LeadAiSuggestionScheduler $scheduler)
    {
        $lead = Lead::query()->with('messages')->findOrFail($lead_id);

        // Invierte el flag por lead (default true en BD para leads existentes).
        $lead->claude_auto_reply = ! (bool) $lead->claude_auto_reply;
        $lead->save();

        // Si se desactiva, invalidar jobs diferidos que aún no llamaron a Claude.
        if (! $lead->claude_auto_reply) {
            $scheduler->cancel_scheduled_suggestion((int) $lead->id);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Activa o desactiva el flag de intervención humana requerida para el lead.
     * Cuando se desactiva (admin resolvió el problema), NO re-activa claude_auto_reply
     * automáticamente — el admin lo hace manualmente si lo desea.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_requiere_intervencion_humana_json($id)
    {
        $lead = Lead::query()->with('messages')->findOrFail($id);

        $lead->requiere_intervencion_humana = ! (bool) $lead->requiere_intervencion_humana;
        $lead->save();

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Activa o desactiva la verificación de mensajes del lead. Cuando está en true, todo mensaje que
     * arme Claude para este lead se retiene para verificación humana antes de enviarse, en cualquier
     * estado. Cuando está en false, los mensajes se envían al instante. Se auto-enciende al entrar al
     * tramo de agenda (ver Lead::booted, prompt 406); acá se lo prende/apaga a mano desde el header.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_requiere_verificacion_mensajes_json($id)
    {
        $lead = Lead::query()->with('messages')->findOrFail($id);

        $lead->requiere_verificacion_mensajes = ! (bool) $lead->requiere_verificacion_mensajes;
        $lead->save();

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Marca un mensaje sugerido como aprobado (listo para enviar por el setter).
     *
     * @param int|string $message_id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve_message_json($message_id, LeadSuggestionSendService $send_service)
    {
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);
        if ($message->status !== 'sugerido') {
            return response()->json(['message' => 'Solo se pueden enviar mensajes en estado sugerido.'], 422);
        }

        try {
            // Admin autenticado que aprobó la sugerencia desde el panel (prompt 403).
            $send_service->send_suggestion($message, null, null, false, (int) Auth::id());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            $this->registrar_aprobacion_fallada((int) $message->lead_id, 'LeadController@approve_message_json', $message_id, $exception);

            return response()->json(['message' => 'No se pudo completar la aprobación. Revisá la conversación antes de reintentar.'], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Aprueba un mensaje sugerido guardando el texto editado por el setter antes de enviar.
     *
     * @param int|string $message_id
     * @param Request $request Debe incluir `edited_content` (texto final enviado).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve_message_with_edit_json($message_id, Request $request, LeadSuggestionSendService $send_service)
    {
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);
        if ($message->status !== 'sugerido') {
            return response()->json(['message' => 'Solo se pueden enviar mensajes sugeridos de la IA.'], 422);
        }

        $edited_content = trim((string) $request->input('edited_content', ''));
        if ($edited_content === '') {
            return response()->json(['message' => 'El texto editado no puede estar vacío.'], 422);
        }

        try {
            // Admin autenticado que aprobó (con texto editado) la sugerencia desde el panel (prompt 403).
            $send_service->send_suggestion($message, $edited_content, null, false, (int) Auth::id());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            $this->registrar_aprobacion_fallada((int) $message->lead_id, 'LeadController@approve_message_with_edit_json', $message_id, $exception);

            return response()->json(['message' => 'No se pudo completar la aprobación. Revisá la conversación antes de reintentar.'], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Aprueba un mensaje sugerido aplicando las acciones EDITADAS por el admin (en vez de las
     * originales de Claude) y, opcionalmente, un texto final editado. Espeja
     * approve_message_with_edit_json() pero además acepta `final_actions` (ver contrato en el
     * prompt 320): el admin puede activar/desactivar/editar cada acción (estado sugerido, agendar
     * demo, forzar un slot que figure ocupado, suprimir el Mail 1, guardar nombre/email, cancelar
     * demo, marcar intervención humana) antes de que se apliquen de verdad. LeadAiService guarda
     * el diff entre lo sugerido por Claude y lo que quedó vigente en `actions_override_log` del
     * mensaje, para poder revisar después dónde el agente se equivocó.
     *
     * @param int|string $message_id
     * @param Request $request Puede incluir `edited_content` (texto final, opcional) y
     *                          `final_actions` (objeto con las acciones efectivas, opcional).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve_message_with_actions_json($message_id, Request $request, LeadSuggestionSendService $send_service)
    {
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);
        if ($message->status !== 'sugerido') {
            return response()->json(['message' => 'Solo se pueden enviar mensajes sugeridos de la IA.'], 422);
        }

        /* Texto final editado por el admin: opcional, se pisa el sugerido por Claude si viene no vacío. */
        $edited_content_raw = trim((string) $request->input('edited_content', ''));
        $edited_content      = $edited_content_raw !== '' ? $edited_content_raw : null;

        /* Paquete de acciones editado por el admin (ver contrato `final_actions` del prompt 320).
         * Si no viene, se aplican las acciones originales de Claude (mismo comportamiento que
         * approve_message_json / approve_message_with_edit_json). */
        $final_actions_raw = $request->input('final_actions');
        $final_actions      = is_array($final_actions_raw) ? $final_actions_raw : null;

        try {
            // Admin autenticado que aprobó (con acciones editadas) la sugerencia desde el panel (prompt 403).
            $send_service->send_suggestion($message, $edited_content, $final_actions, false, (int) Auth::id());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            $this->registrar_aprobacion_fallada((int) $message->lead_id, 'LeadController@approve_message_with_actions_json', $message_id, $exception);

            return response()->json(['message' => 'No se pudo completar la aprobación. Revisá la conversación antes de reintentar.'], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Deja constancia de una excepción INESPERADA de un endpoint de aprobación de sugerencias.
     *
     * 🔴 Este bloque NO puede decir "No se pudo enviar la sugerencia por WhatsApp", que es lo que
     * decía hasta el 2/9/2026. Los fallos de envío REALES —ventana de 24hs cerrada, Kapso caído,
     * lead sin teléfono, envío parcial— los registra LeadSuggestionSendService con su propio bloque
     * y con el motivo verdadero ANTES de volver acá. Todo lo que llega a este catch es otra cosa:
     * una excepción que subió, con el mensaje posiblemente YA ENTREGADO al lead. Afirmar que no se
     * envió es tomar una señal parcial ("saltó una excepción") como prueba de un hecho más fuerte
     * ("el lead no recibió nada"), y el setter termina mandando el mensaje dos veces.
     *
     * El detalle técnico va al Log y NO al hilo: el hilo lo lee un setter, y $exception->getMessage()
     * ahí era literalmente "Return value of ... must be an instance of App\Models\LeadMessage".
     *
     * Los tres endpoints de aprobación comparten este método en vez de tener una copia cada uno: tres
     * bloques idénticos es exactamente el caldo de cultivo que este repo ya conoce (por eso
     * LeadSuggestionSendService::enviar_partes() es pública y compartida), y el copy de este aviso ya
     * se corrigió una vez en tres lugares a la vez.
     *
     * @param int        $lead_id
     * @param string     $endpoint   Nombre del endpoint para el log (ej. "LeadController@approve_message_json").
     * @param int|string $message_id Id del mensaje tal como llegó por la ruta.
     * @param \Throwable $exception
     *
     * @return void
     */
    private function registrar_aprobacion_fallada(int $lead_id, string $endpoint, $message_id, \Throwable $exception): void
    {
        Log::error($endpoint.': '.$exception->getMessage(), [
            'message_id' => $message_id,
            'lead_id'    => $lead_id,
            'excepcion'  => get_class($exception),
            'archivo'    => $exception->getFile().':'.$exception->getLine(),
        ]);

        (new LeadConversationErrorLogger())->log(
            $lead_id,
            'Hubo un problema al aprobar la sugerencia',
            'El sistema falló mientras procesaba la aprobación. Puede que el mensaje haya salido igual: revisá la conversación antes de mandarlo de nuevo. El detalle técnico quedó guardado en el registro del sistema.'
        );
    }

    /**
     * Marca un mensaje sugerido como rechazado y recalcula flags del lead.
     *
     * @param int|string $message_id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject_message_json($message_id)
    {
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);
        if ($message->status !== 'sugerido') {
            return response()->json(['message' => 'Solo se pueden rechazar mensajes en estado sugerido.'], 422);
        }

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        $message->update(['status' => 'rechazado']);

        $lead = $message->lead;
        if ($lead) {
            $lead->sync_suggestion_flags();
        }

        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Cancela el envío automático programado de una sugerencia y la marca como rechazada.
     *
     * Aplica a sugerencias normales y a seguimientos del tramo de agenda
     * (LeadFollowupService::create_pending_followup_for_verification) que tienen
     * auto-envío programado. Claude verá la sugerencia en el historial como rechazada.
     *
     * @param int|string $message_id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel_auto_send_message_json($message_id)
    {
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);

        if ((string) $message->sender !== 'sistema') {
            return response()->json(['message' => 'Solo aplica a sugerencias del sistema.'], 422);
        }

        if ((string) $message->status !== 'sugerido') {
            return response()->json(['message' => 'Solo se puede cancelar el envío de sugerencias pendientes.'], 422);
        }

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        $message->update(['status' => 'rechazado']);

        $lead = $message->lead;
        if ($lead) {
            $lead->sync_suggestion_flags();
        }

        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Alterna la marca "este lead ya no recibe mensajes" (número bloqueado o dado de baja).
     *
     * 🔴 Para qué sirve: el rojo de la grilla existe para lo que se puede REINTENTAR — un error de
     * envío del sistema o un rechazo de Meta. Un número que bloqueó o que ya no existe no se
     * reintenta, así que pintarlo de rojo es ruido permanente sobre una fila que nunca se va a
     * poder resolver. Con la marca puesta, sus entregas fallidas dejan de pintar la fila.
     *
     * La pone una persona, no el sistema, y el motivo está en la migración 2026_09_02_150000: hoy
     * no tenemos el código de error de Meta (nunca se capturó) y el patrón de fallos no alcanza
     * para deducirlo — 33 de 54 leads con una entrega fallida después recibieron mensajes bien.
     *
     * Es un toggle a propósito: un número puede volver a andar (el lead desbloquea, recupera la
     * línea), y en ese caso la marca se levanta desde el mismo lugar.
     *
     * @param Request    $request Body opcional: motivo (texto libre, 200 chars).
     * @param int|string $id      Id del lead.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_no_recibe_mensajes_json(Request $request, $id)
    {
        /* Lead objetivo de la marca. */
        $lead = Lead::findOrFail($id);

        /* Alterna: si ya estaba marcado, la marca se levanta (el número volvió a andar). */
        if ($lead->no_recibe_mensajes_at !== null) {
            $lead->update([
                'no_recibe_mensajes_at'     => null,
                'no_recibe_mensajes_motivo' => null,
            ]);
        } else {
            /* Admin que la puso: queda en el motivo para que se sepa quién lo decidió. */
            $admin = Auth::user();
            $motivo = trim((string) $request->input('motivo', ''));
            if ($motivo === '') {
                $motivo = 'Marcado a mano' . ($admin ? ' por ' . $admin->name : '') . '.';
            }

            $lead->update([
                'no_recibe_mensajes_at'     => now(),
                'no_recibe_mensajes_motivo' => mb_substr($motivo, 0, 200),
            ]);

            /* Deja el rastro en la conversación, como cualquier acción administrativa del panel:
               is_status_event para que no toque last_message_at ni el badge de sin leer. */
            LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => 'sistema',
                'content'               => 'Marcado como "el lead ya no recibe mensajes"'
                    . ($admin ? ' por ' . $admin->name : '') . '. '
                    . 'Sus entregas fallidas dejan de pintar la fila de rojo.',
                'status'                => 'enviado',
                'is_followup'           => false,
                'is_status_event'       => true,
                'requiere_verificacion' => false,
                'sent_by_admin_id'      => $admin ? $admin->id : null,
            ]);
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Alterna si el mensaje se incluye o excluye del historial enviado a Claude.
     *
     * Los mensajes marcados con deleted_from_context=true siguen siendo visibles
     * en la UI del operador pero no se envían a Claude como parte del contexto,
     * lo que permite ignorar mensajes que no aportan valor a la conversación con la IA.
     *
     * @param int|string $message_id Id de lead_messages.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_deleted_from_context_json($message_id)
    {
        /* Busca el mensaje incluyendo el lead para poder retornar el modelo completo. */
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);

        /* Invierte el estado actual del campo. */
        $message->update(['deleted_from_context' => !$message->deleted_from_context]);

        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Reacciona con un emoji, desde el panel, a un mensaje del hilo del lead.
     *
     * El emoji vacío es el "quitar la reacción": así lo pide la Cloud API de Meta y así viaja
     * desde el SPA, sin un endpoint aparte. Reemplazar una reacción por otra tampoco tiene camino
     * especial: Meta pisa la anterior sobre el mismo message_id y acá el update pisa las columnas.
     *
     * Nada se persiste si el envío no salió: una reacción que no llegó al lead no es información
     * que nadie necesite recuperar, y pintarla en la burbuja diría que el lead la vio cuando no la
     * vio. Se devuelve 422 con el motivo y listo — no se registra un bloque rojo en el hilo, que
     * sería peor que el aviso que ya ve quien apretó.
     *
     * 🔴 Poner y quitar se ramifican ANTES de las guardas, no después. Las guardas de abajo miran
     * el estado del mensaje OBJETIVO (que nunca salió, que quedó fallido, que tiene un wamid de
     * prueba) y eso solo tiene sentido para poner una reacción nueva. Cuando también frenaban el
     * quitado, una reacción puesta con el modo de prueba encendido —que nunca llegó al teléfono
     * del lead— quedaba pintada para siempre apenas se apagaba el modo de prueba, sin ninguna
     * forma de sacarla. El quitado se valida contra la reacción GUARDADA, en
     * {@see quitar_reaccion_del_panel()}, no contra el mensaje.
     *
     * @param \Illuminate\Http\Request $request                Trae 'emoji' ('' = quitar).
     * @param int|string               $message_id             Id de lead_messages.
     * @param WhatsappSendService      $whatsapp_send_service  Envío saliente vía Kapso.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function react_to_message_json(Request $request, $message_id, WhatsappSendService $whatsapp_send_service)
    {
        /* El campo tiene que venir, y si viene con algo tiene que ser un string. Sin esto, un PUT
           sin 'emoji' se leía como un quitado —borraba la reacción y devolvía 200— y un array
           reventaba en 500 al castearlo.

           El `nullable` no es un descuido: el middleware global ConvertEmptyStringsToNull
           (Http/Kernel.php, línea 23) convierte el "" que manda el SPA en null antes de que este
           método lo vea, así que acá "cadena vacía" y "null" son literalmente el mismo valor y no
           hay forma —ni sentido— de separarlos. Los dos son el quitado; lo que se rechaza es el
           campo AUSENTE y cualquier tipo que no sea string. */
        $request->validate([
            'emoji' => ['present', 'nullable', 'string'],
        ]);

        /* Busca el mensaje incluyendo el lead para poder retornar el modelo completo. */
        $message = LeadMessage::query()->with('lead')->findOrFail($message_id);

        /* La intención se resuelve antes que nada: sin emoji es un quitado, y el quitado tiene su
           propio camino con sus propias reglas. */
        $emoji_crudo = trim((string) $request->input('emoji'));
        if ($emoji_crudo === '') {
            return $this->quitar_reaccion_del_panel($message, $whatsapp_send_service);
        }

        $emoji_canonico = $this->resolve_reaction_emoji($emoji_crudo);
        if ($emoji_canonico === null) {
            return response()->json(['message' => 'Ese emoji no está en la paleta de reacciones rápidas.'], 422);
        }

        /* Los eventos internos del hilo nunca salieron por WhatsApp: no hay a qué reaccionarle. */
        if ($message->is_status_event || $message->is_error) {
            return response()->json(['message' => 'Los eventos internos del hilo no se envían por WhatsApp, así que no se les puede reaccionar.'], 422);
        }

        /* Cubre sugerencias sin enviar, rechazadas, historial importado y envíos que fallaron. */
        $wamid = trim((string) ($message->whatsapp_message_id ?? ''));
        if ($wamid === '') {
            return response()->json(['message' => 'Este mensaje nunca salió por WhatsApp, así que el lead no puede ver una reacción sobre él.'], 422);
        }

        if ((string) $message->whatsapp_delivery_status === 'fallido') {
            return response()->json(['message' => 'Ese mensaje no se pudo entregar al lead: reaccionarle no tendría a qué engancharse.'], 422);
        }

        /* 🔴 Meta NO entrega una reacción cuyo mensaje objetivo tenga más de 30 días, pero responde
           200 al POST igual: el rechazo lo avisa después, por webhook. Sin esta guarda el operador
           reacciona, la pill se pinta, y el lead no ve nada. Un hilo con historia está lleno de
           mensajes de más de 30 días y se llega con un clic normal.

           WhatsappWebhookController::handle_failed_admin_reaction_status() escucha ese rechazo y
           despinta la pill, pero es la red de seguridad, no el primer freno: cortar acá evita el
           viaje de ida y vuelta y la pill que aparece y desaparece sola en la cara del operador. */
        $fecha_del_objetivo = $message->sent_at !== null ? $message->sent_at : $message->created_at;
        if ($fecha_del_objetivo !== null && $fecha_del_objetivo->lt(now()->subDays(30))) {
            return response()->json(['message' => 'WhatsApp no permite reaccionar a mensajes de más de 30 días: el lead no vería la reacción.'], 422);
        }

        /* wamid simulado de una prueba local, con el modo de prueba ya apagado: ese id no existe en
           WhatsApp y Meta devolvería un 400. Se corta acá, antes de la red. */
        if (strncmp($wamid, 'test-', 5) === 0) {
            $whatsapp_config = \App\Models\WhatsappConfig::getActive();
            $en_modo_prueba = $whatsapp_config && $whatsapp_config->is_active && $whatsapp_config->test_mode;
            if (! $en_modo_prueba) {
                return response()->json(['message' => 'Ese mensaje se envió con el modo de prueba activo: su id no existe en WhatsApp.'], 422);
            }
        }

        $lead = $message->lead;
        if ($lead === null) {
            return response()->json(['message' => 'El mensaje no tiene un lead asociado.'], 422);
        }

        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone === '') {
            return response()->json(['message' => 'El lead no tiene teléfono cargado.'], 422);
        }

        try {
            /* El último true es $skip_failure_notification: el aviso por WhatsApp a los admins está
               throttleado globalmente a 1 cada 10 minutos, y un operador toqueteando la paleta en un
               hilo frío se comería ese cupo, dejando un fallo de envío REAL de esos 10 minutos
               reducido a una línea de log. Quien apretó ya ve el error en el acto. */
            $reaction_wamid = $whatsapp_send_service->send_reaction(
                $phone,
                $wamid,
                $emoji_canonico,
                'Reacción del panel - Lead #' . $lead->id . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : ''),
                true
            );
        } catch (\Throwable $e) {
            Log::error('LeadController@react_to_message_json: error WhatsApp.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->mensaje_de_reaccion_fallida($e->getMessage(), $whatsapp_send_service->last_send_status_code, false),
            ], 422);
        }

        /* Si no salió, no se guarda nada: ver el docblock del método. El detalle crudo va al log; a
           la pantalla va el mensaje en español que arma mensaje_de_reaccion_fallida(). */
        if ($reaction_wamid === null) {
            $detalle = trim((string) $whatsapp_send_service->last_send_error);
            Log::error('LeadController@react_to_message_json: la reacción no salió.', [
                'lead_id'     => $lead->id,
                'message_id'  => $message->id,
                'status_code' => $whatsapp_send_service->last_send_status_code,
                'detalle'     => $detalle,
            ]);

            return response()->json([
                'message' => $this->mensaje_de_reaccion_fallida($detalle, $whatsapp_send_service->last_send_status_code, false),
            ], 422);
        }

        $message->update([
            'admin_reaction_emoji'               => $emoji_canonico,
            'admin_reaction_at'                  => now(),
            'admin_reaction_whatsapp_message_id' => $reaction_wamid,
            'admin_reaction_by_admin_id'         => (int) $request->user()->id,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Quita la reacción que el panel había dejado sobre un mensaje del hilo.
     *
     * 🔴 Acá NO valen las guardas del camino de poner. Lo que se está deshaciendo es una fila
     * nuestra, no una propiedad del mensaje objetivo: que el mensaje haya quedado fallido después,
     * o que su wamid sea de una prueba local, no puede impedir sacar de la burbuja algo que el
     * operador ya no quiere ver. La única pregunta que importa es si esa reacción llegó alguna vez
     * al teléfono del lead:
     *
     * - No hay reacción guardada → 200 sin hacer nada. El endpoint es idempotente.
     * - La reacción es simulada (wamid 'test-…') o no tiene wamid → nunca existió del lado del
     *   lead: limpieza local pura, sin tocar la red.
     * - La reacción es real → se le pide a Meta que la quite (emoji vacío). Si Meta lo rechaza NO
     *   se limpia nada: la reacción sigue puesta en el teléfono del lead y el panel tiene que
     *   seguir mostrando lo que el lead realmente ve.
     *
     * @param LeadMessage         $message                Mensaje del hilo con la reacción a quitar.
     * @param WhatsappSendService $whatsapp_send_service  Envío saliente vía Kapso.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function quitar_reaccion_del_panel(LeadMessage $message, WhatsappSendService $whatsapp_send_service)
    {
        /* Nada que quitar. Se devuelve el modelo igual para que el SPA tenga qué commitear. */
        $emoji_guardado = trim((string) ($message->admin_reaction_emoji ?? ''));
        if ($emoji_guardado === '') {
            return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
        }

        $reaccion_wamid = trim((string) ($message->admin_reaction_whatsapp_message_id ?? ''));
        $es_simulada = ($reaccion_wamid === '' || strncmp($reaccion_wamid, 'test-', 5) === 0);

        $lead = $message->lead;
        $phone = $lead !== null ? trim((string) ($lead->phone ?? '')) : '';
        $wamid_objetivo = trim((string) ($message->whatsapp_message_id ?? ''));

        if (! $es_simulada) {
            /* La reacción es real pero no tenemos con qué pedirle el quitado a Meta. No se limpia
               nada: el lead la sigue viendo en su teléfono. */
            if ($phone === '' || $wamid_objetivo === '') {
                return response()->json(['message' => 'No se pudo quitar la reacción: falta el teléfono del lead o el id de WhatsApp del mensaje, y la reacción sigue puesta en el teléfono del lead.'], 422);
            }

            try {
                /* $skip_failure_notification en true por el mismo motivo que al poner: no gastar el
                   cupo global de avisos a admins en algo que el operador ya ve en pantalla. */
                $quitado_wamid = $whatsapp_send_service->send_reaction(
                    $phone,
                    $wamid_objetivo,
                    '',
                    'Quitar reacción del panel - Lead #' . $lead->id . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : ''),
                    true
                );
            } catch (\Throwable $e) {
                Log::error('LeadController@react_to_message_json: error WhatsApp al quitar la reacción.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                    'error'      => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => $this->mensaje_de_reaccion_fallida($e->getMessage(), $whatsapp_send_service->last_send_status_code, true),
                ], 422);
            }

            /* Meta lo rechazó: las columnas quedan como están, a propósito. */
            if ($quitado_wamid === null) {
                $detalle = trim((string) $whatsapp_send_service->last_send_error);
                Log::error('LeadController@react_to_message_json: el quitado de la reacción no salió.', [
                    'lead_id'     => $lead->id,
                    'message_id'  => $message->id,
                    'status_code' => $whatsapp_send_service->last_send_status_code,
                    'detalle'     => $detalle,
                ]);

                return response()->json([
                    'message' => $this->mensaje_de_reaccion_fallida($detalle, $whatsapp_send_service->last_send_status_code, true),
                ], 422);
            }
        }

        /* Se limpia también el wamid: acá no hay idempotencia de webhook que proteger (a diferencia
           del camino entrante, que sí lo conserva) y dejarlo sería basura sin lector. */
        $message->update([
            'admin_reaction_emoji'               => null,
            'admin_reaction_at'                  => null,
            'admin_reaction_whatsapp_message_id' => null,
            'admin_reaction_by_admin_id'         => null,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
    }

    /**
     * Traduce un fallo de reacción al mensaje que ve el operador, en español.
     *
     * El detalle crudo que sale de Guzzle es inglés técnico y truncado ("HTTP request returned
     * status code 400:\n{"error":{"message":"Message failed to send because more than 24 hours…"),
     * y todo lo que ve el usuario va en español. El crudo se loguea; a la pantalla va esto.
     *
     * `last_send_status_code` sirve para no diagnosticar la ventana de 24hs sobre un fallo que
     * claramente es otra cosa: Meta la rechaza con un 400, así que un 401 o un 500 no son ese caso
     * aunque el texto del error mencione las 24 horas. (Ese campo ya lo leía
     * WhatsappSendService::last_send_was_transient() para decidir reintentos; esta es la segunda
     * lectura, la primera que termina en la pantalla del operador.)
     *
     * @param string   $detalle     Detalle crudo (last_send_error o el mensaje de la excepción).
     * @param int|null $status_code last_send_status_code del servicio, si lo llegó a capturar.
     * @param bool     $quitando    true si lo que falló era el quitado de una reacción ya puesta.
     *
     * @return string
     */
    private function mensaje_de_reaccion_fallida(string $detalle, $status_code, bool $quitando): string
    {
        $detalle_normalizado = strtolower($detalle);
        $status = $status_code === null ? null : (int) $status_code;
        $status_compatible = ($status === null || $status === 400);

        $ventana_cerrada = $status_compatible && (
            strpos($detalle_normalizado, '131047') !== false
            || strpos($detalle_normalizado, '24 hours') !== false
            || strpos($detalle_normalizado, '24 horas') !== false
        );

        if ($ventana_cerrada) {
            if ($quitando) {
                return 'El lead no escribe hace más de 24 horas, así que WhatsApp no deja quitarle la reacción: sigue puesta en su teléfono hasta que vuelva a escribir.';
            }

            return 'El lead no responde hace más de 24 horas y WhatsApp no deja mandarle nada nuevo hasta que vuelva a escribir.';
        }

        if ($quitando) {
            return 'No se pudo quitar la reacción del WhatsApp del lead: sigue puesta en su teléfono. Probá de nuevo en un rato.';
        }

        return 'No se pudo enviar la reacción por WhatsApp. El detalle quedó en el log; probá de nuevo en un rato.';
    }

    /**
     * Valida un emoji recibido contra la paleta del panel y devuelve su forma canónica.
     *
     * La comparación se hace sacando los selectores de variación (U+FE0F) de los dos lados. No es
     * paranoia: el ❤️ viaja del navegador con o sin U+FE0F según cómo se haya escrito el literal
     * en el .vue, y un in_array() estricto lo rechazaría la mitad de las veces. Lo que sale a Meta
     * es siempre la forma canónica del backend, nunca lo que mandó el cliente.
     *
     * @param string $emoji Emoji tal como llegó en el request.
     *
     * @return string|null Forma canónica de la constante, o null si no está en la paleta.
     */
    private function resolve_reaction_emoji(string $emoji): ?string
    {
        $recibido_normalizado = str_replace("\u{FE0F}", '', $emoji);
        if ($recibido_normalizado === '') {
            return null;
        }

        foreach (LeadMessage::REACCIONES_DEL_PANEL as $permitido) {
            if (str_replace("\u{FE0F}", '', $permitido) === $recibido_normalizado) {
                return $permitido;
            }
        }

        return null;
    }

    /**
     * Sirve un adjunto de lead vía URL firmada (nueva pestaña sin depender de public/storage).
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string               $id      ID de lead_message_attachments.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\Response
     */
    public function serve_message_attachment_file_json(Request $request, $id)
    {
        /* La ruta usa middleware signed; validación defensiva adicional. */
        if (! $request->hasValidSignature()) {
            abort(403, 'Enlace de adjunto inválido o expirado.');
        }

        $attachment = LeadMessageAttachment::query()->findOrFail($id);
        $disk = trim((string) ($attachment->disk ?? 'public'));
        if ($disk === '') {
            $disk = 'public';
        }

        $path = trim((string) ($attachment->path ?? ''));
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404, 'Adjunto no encontrado.');
        }

        $download_name = $attachment->display_filename ?: basename($path);
        $mime = trim((string) ($attachment->mime ?? ''));

        $headers = [];
        if ($mime !== '') {
            $headers['Content-Type'] = $mime;
        }
        // Misma ventana que la firma de la URL (LeadMessageAttachment::getPublicUrlAttribute):
        // el navegador no vuelve a pedir el archivo mientras la URL firmada siga siendo válida.
        $headers['Cache-Control'] = 'private, max-age=172800';

        // Parámetro opcional (viaja dentro de la firma) que fuerza descarga en vez de inline:
        // usado por el download_url de LeadMessageAttachment para documentos/archivos que
        // deben bajarse con su nombre real en lugar de abrirse en el navegador.
        $disposition = strtolower(trim((string) $request->query('disposition', '')));
        if ($disposition === 'attachment') {
            // Fuerza descarga con Content-Disposition: attachment y el nombre real del archivo.
            return Storage::disk($disk)->download($path, $download_name, $headers);
        }

        // Por defecto, inline (imágenes y videos se muestran/reproducen en la misma página).
        return Storage::disk($disk)->response($path, $download_name, $headers);
    }

    /**
     * Marca como vista la alerta de seguimiento automático (pestaña Conversación WhatsApp).
     *
     * @param int|string $lead_id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_followup_suggestion_seen_json($lead_id)
    {
        $lead = Lead::query()->findOrFail($lead_id);
        if ($lead->tiene_seguimiento_sin_ver) {
            $lead->tiene_seguimiento_sin_ver = false;
            $lead->save();
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Totales de mensajes del lead sin leer para el admin autenticado (badge del menú Leads en admin-spa).
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unread_badges_json(Request $request)
    {
        // Admin autenticado: el total de no leídos es per-usuario.
        $admin_id = (int) $request->user()->id;

        return response()->json([
            'unread_total'     => LeadBroadcastService::count_unread_for_admin($admin_id),
            'unread_by_status' => LeadBroadcastService::count_unread_by_status_for_admin($admin_id),
        ], 200);
    }

    /**
     * Tarjetas de estado de la grilla de leads: cuántos leads hay en cada estado clave y cuántos de
     * ellos necesitan revisión (mismo criterio que el botón de revisión).
     *
     * 🔴 Endpoint aparte de `unread_badges_json()` a propósito: aquel es per-admin, cuenta MENSAJES
     * y se dispara con debounce en cada `LeadConversationUpdated` del sistema entero. Estos conteos
     * son globales, cuentan LEADS y cambian mucho menos seguido.
     *
     * Los conteos no leen ningún filtro del request: son globales, igual que los badges de la barra
     * de estados.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status_cards_json(Request $request)
    {
        return response()->json([
            'cards' => LeadStatusCardsService::cards_for_statuses(LeadPipelineStatus::SLUGS_TARJETAS_ESTADO),
        ], 200);
    }

    /**
     * Marca como leídos los mensajes entrantes del lead (sender = lead) al abrir la conversación.
     *
     * La lectura es per-usuario: se inserta un registro en lead_message_reads para
     * el admin autenticado, sin afectar el estado de lectura de los demás admins.
     *
     * @param Request    $request
     * @param int|string $lead_id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_whatsapp_messages_read_json(Request $request, $lead_id)
    {
        // Admin autenticado que abre la conversación.
        $admin_id = (int) $request->user()->id;

        // FIX (2/7/2026): antes solo se marcaban leídos los mensajes sender=lead, por lo que el
        // badge gris "actividad no vista" (unseen_count, cuenta cualquier emisor) nunca bajaba a 0
        // salvo que coincidiera con mensajes del lead también sin leer. Abrir la conversación debe
        // marcar leído TODO el hilo, no solo lo del lead.
        $message_ids = LeadMessage::query()
            ->where('lead_id', (int) $lead_id)
            ->pluck('id');

        // Una fila de lectura por mensaje para este admin (idempotente vía firstOrCreate).
        foreach ($message_ids as $message_id) {
            \App\Models\LeadMessageRead::firstOrCreate([
                'lead_message_id' => $message_id,
                'admin_id'        => $admin_id,
            ], [
                'read_at' => now(),
            ]);
        }

        // Abrir la conversación también limpia cualquier marca manual de "no leído" (estilo WhatsApp).
        \App\Models\LeadManualUnreadMark::where('lead_id', (int) $lead_id)
            ->where('admin_id', $admin_id)
            ->delete();

        // Abrir la conversación también quita la marca de "pendiente de revisión" (global, botón de
        // revisión — prompt 295): al entrar, el operador ya toma la acción, así que la fila deja de
        // estar roja para todos.
        Lead::query()
            ->where('id', (int) $lead_id)
            ->whereNotNull('pendiente_revision_at')
            ->update(['pendiente_revision_at' => null]);

        LeadBroadcastService::emit_conversation_updated((int) $lead_id);

        return response()->json(['model' => $this->fullModel('lead', $lead_id)], 200);
    }

    /**
     * Persiste la colección de videos personalizados enviada desde admin-spa.
     *
     * Reglas:
     * - Si no viene la clave `personalized_demo_videos`, no se modifica nada.
     * - Si viene array vacío, se eliminan todos los videos del lead.
     * - Filas totalmente vacías (título, descripción, comentarios y URL en blanco) se ignoran.
     * - Se respeta el orden del array (`sort_order`).
     *
     * @param Lead    $lead    Lead dueño de los registros hijos.
     * @param Request $request Payload JSON del PUT/POST.
     *
     * @return void
     */
    protected function sync_personalized_demo_videos_from_request(Lead $lead, Request $request): void
    {
        if (! $request->has('personalized_demo_videos')) {
            return;
        }

        $raw_rows = $request->input('personalized_demo_videos');
        if (! is_array($raw_rows)) {
            return;
        }

        $kept_ids = [];
        $order_index = 0;

        foreach ($raw_rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $video_url = trim((string) ($row['video_url'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $comments = trim((string) ($row['comments'] ?? ''));
            if ($title === '' && $video_url === '' && $description === '' && $comments === '') {
                continue;
            }
            $row_id = isset($row['id']) ? (int) $row['id'] : 0;

            if ($row_id > 0) {
                $existing = LeadPersonalizedDemoVideo::query()
                    ->where('lead_id', $lead->id)
                    ->where('id', $row_id)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'title'       => $title !== '' ? $title : null,
                        'description' => $description !== '' ? $description : null,
                        'comments'    => $comments !== '' ? $comments : null,
                        'video_url'   => $video_url !== '' ? $video_url : null,
                        'sort_order'  => $order_index,
                    ]);
                    $kept_ids[] = $existing->id;
                    $order_index++;

                    continue;
                }
            }

            $created = $lead->personalized_demo_videos()->create([
                'title'       => $title !== '' ? $title : null,
                'description' => $description !== '' ? $description : null,
                'comments'    => $comments !== '' ? $comments : null,
                'video_url'   => $video_url !== '' ? $video_url : null,
                'sort_order'  => $order_index,
            ]);
            $kept_ids[] = $created->id;
            $order_index++;
        }

        if (! empty($kept_ids)) {
            $lead->personalized_demo_videos()->whereNotIn('id', $kept_ids)->delete();
        } else {
            $lead->personalized_demo_videos()->delete();
        }
    }

    /**
     * Envía el recordatorio pre-demo manualmente desde admin-spa.
     *
     * Genera el mensaje de recordatorio sin verificar timing (útil para testing
     * sin esperar el scheduler SendDemoReminders). Crea un LeadMessage con
     * status 'sugerido' y actualiza el flag recordatorio_demo_enviado.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_demo_reminder_json($id)
    {
        /* Lead objetivo con mensajes para construir el contexto del recordatorio. */
        $lead = Lead::with('messages')->findOrFail($id);

        /* Construir la fecha y hora de la demo para incluir en el mensaje. */
        $demo_date_str = $lead->demo_date
            ? $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d')
            : now('America/Argentina/Buenos_Aires')->format('Y-m-d');
        $demo_time_str = $lead->demo_start_time ?? '00:00';

        try {
            /* Parsear la fecha completa combinando fecha y hora de la demo. */
            $demo_datetime = \Carbon\Carbon::parse("{$demo_date_str} {$demo_time_str}");
        } catch (\Exception $e) {
            $demo_datetime = now('America/Argentina/Buenos_Aires');
        }

        /* Nombre del contacto con fallback para evitar saludo vacío. */
        $contact_name = $lead->contact_name ?? 'Cliente';
        /* Hora formateada para incluir en el razonamiento del mensaje. */
        $demo_hour = $demo_datetime->format('H:i');

        /* Texto del recordatorio: mismo contenido que el scheduler automático. */
        $content = "Hola {$contact_name}! En unos minutos ya tenés disponible el acceso a la demo de ComercioCity.\n\n"
            . "Un consejo antes de entrar: empezá por el video introductorio que te mandamos al mail, "
            . "son 3 minutos y te van a ayudar a entender qué mirar cuando entrás al sistema.\n\n"
            . "Cualquier duda que surja mientras recorrés la plataforma, escribime por acá. 👋";

        /* Crear mensaje sugerido para que el setter lo revise antes de enviar. */
        \App\Models\LeadMessage::create([
            'lead_id'      => $lead->id,
            'sender'       => 'sistema',
            'content'      => $content,
            'status'       => 'sugerido',
            'is_followup'  => false,
            'ai_reasoning' => "Recordatorio manual pre-demo. Demo programada para las {$demo_hour}.",
        ]);

        /* Marcar el lead como que ya tiene el recordatorio y una sugerencia pendiente. */
        $lead->update([
            'recordatorio_demo_enviado'  => true,
            'tiene_sugerencia_pendiente' => true,
        ]);

        \App\Events\LeadSuggestionCreated::dispatch($lead->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Genera el check de ingreso a la demo manualmente desde admin-spa.
     *
     * Crea el mensaje de check de ingreso sin verificar timing (para testing
     * sin esperar el scheduler DemoIngressCheck). Actualiza el flag
     * demo_check_ingreso_enviado para evitar duplicados.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function check_demo_ingress_json($id)
    {
        /* Lead objetivo con mensajes para el check de ingreso. */
        $lead = Lead::with('messages')->findOrFail($id);

        /* Nombre del contacto con fallback para saludo personalizado. */
        $contact_name = $lead->contact_name ?? 'Cliente';

        /* Crear mensaje sugerido de check de ingreso para el setter. */
        \App\Models\LeadMessage::create([
            'lead_id'      => $lead->id,
            'sender'       => 'sistema',
            'content'      => "Hola {$contact_name}! ¿Pudiste ingresar a la demo sin problemas? 👋",
            'status'       => 'sugerido',
            'is_followup'  => false,
            'ai_reasoning' => 'Check manual de ingreso a la demo.',
        ]);

        /* Marcar el lead como que ya recibió el check de ingreso y tiene sugerencia pendiente. */
        $lead->update([
            'demo_check_ingreso_enviado' => true,
            'tiene_sugerencia_pendiente' => true,
        ]);

        \App\Events\LeadSuggestionCreated::dispatch($lead->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Genera manualmente el check de fin de demo para el lead indicado.
     *
     * Crea un mensaje sugerido preguntando si el lead pudo terminar la demo
     * y marca el flag demo_fin_check_enviado en true. Espeja la lógica del
     * check_demo_ingress_json para que el operador pueda enviarlo sin esperar
     * al scheduler automático.
     *
     * @param int|string $id Identificador del lead.
     * @return \Illuminate\Http\JsonResponse
     */
    public function check_demo_fin_json($id)
    {
        /* Lead objetivo. */
        $lead = Lead::findOrFail($id);

        /* Nombre del contacto con fallback para el saludo personalizado. */
        $contact_name = $lead->contact_name ?? 'cliente';

        /* Crear mensaje sugerido de check de fin de demo para el setter. */
        \App\Models\LeadMessage::create([
            'lead_id'      => $lead->id,
            'sender'       => 'sistema',
            'content'      => "¡Hola {$contact_name}! ¿Pudiste recorrer la demo completa? 😊",
            'status'       => 'sugerido',
            'is_followup'  => false,
            'ai_reasoning' => 'Check manual de fin de demo.',
        ]);

        /* Marcar el flag de check de fin y activar sugerencia pendiente para el setter. */
        $lead->update([
            'demo_fin_check_enviado'     => true,
            'tiene_sugerencia_pendiente' => true,
        ]);

        \App\Events\LeadSuggestionCreated::dispatch($lead->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Fuerza la (re)creación del evento de calendario del closer para este lead y, con eso,
     * la obtención de su link de Meet -- son la misma operación en Google Calendar. Usa
     * recreate_event_for_lead() para no dejar eventos duplicados si el lead ya tenía uno:
     * borra el anterior (si existía) y crea uno limpio. Requiere que el lead ya tenga
     * demo_date y demo_start_time cargados (si no, no hay con qué calcular el horario
     * del evento del closer).
     *
     * @param int|string                      $id
     * @param CloserGoogleCalendarEventService $calendar_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function force_calendar_event_json($id, CloserGoogleCalendarEventService $calendar_service)
    {
        /* Lead objetivo al que se le va a forzar el evento/Meet. */
        $lead = Lead::findOrFail($id);

        /* Sin fecha/hora de demo cargadas no hay con qué calcular el horario del evento del closer. */
        if (empty($lead->demo_date) || empty($lead->demo_start_time)) {
            return response()->json([
                'message' => 'El lead todavía no tiene fecha y hora de demo cargadas.',
            ], 422);
        }

        /* Guardar el evento y fecha anteriores antes de recrear, para poder invalidar/eliminar el viejo. */
        $old_google_event_id = $lead->google_event_id;
        $old_demo_date       = $lead->demo_date ? $lead->demo_date->format('Y-m-d') : null;

        /* recreate_event_for_lead() elimina el evento anterior (si existía) y crea uno nuevo limpio,
         * evitando así eventos duplicados en el calendario del closer. */
        $calendar_service->recreate_event_for_lead($lead, $old_google_event_id, $old_demo_date);

        // recreate_event_for_lead() persiste el nuevo google_event_id/meet_url internamente
        // (vía create_event_for_lead() -> $lead->update()); recargar para devolver el modelo fresco.
        $lead->refresh();

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Fuerza el seguimiento que corresponde al lead ahora mismo (testing manual),
     * ignorando horas de espera y sugerencia pendiente. Ver LeadFollowupService::force_followup_now.
     *
     * @param int|string $id
     * @param \App\Services\LeadFollowupService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function force_followup_json($id, \App\Services\LeadFollowupService $service)
    {
        $lead = Lead::findOrFail($id);
        $outcome = $service->force_followup_now($lead);

        return response()->json([
            'model'   => $this->fullModel('lead', $lead->id),
            'outcome' => $outcome,
        ], 200);
    }

    /**
     * Genera el resumen estructurado del lead con Claude manualmente desde admin-spa.
     *
     * Llama a la API de Anthropic con el historial de mensajes del lead para producir:
     * - demo_summary: resumen textual en prosa orientado al closer (máx. 200 palabras)
     * - demo_summary_structured: JSON con empresa, situacion_actual, funcionalidades, puntos_dolor,
     *   precio_sugerido y temperatura (uso interno del equipo)
     *
     * Si el parse JSON falla, se guarda solo demo_summary con el texto crudo (fallback seguro).
     *
     * @param int|string $id ID del lead.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate_demo_summary_json($id)
    {
        /* Lead con historial completo de mensajes para alimentar el prompt. */
        $lead = Lead::with('messages')->findOrFail($id);

        /*
         * System prompt (prompt 117): solicita JSON estructurado con 7 claves.
         * Mismo texto que GenerateDemoSummary::SYSTEM_PROMPT para mantener coherencia.
         */
        $system_prompt = 'Sos un asistente de ventas. Analizá la conversación del lead y devolvé ÚNICAMENTE un JSON válido '
            . '(sin backticks, sin texto adicional, sin explicaciones) con exactamente estas 7 claves: '
            . '- resumen_textual: resumen en prosa natural (máximo 200 palabras) orientado al closer, con tipo de negocio, empleados, dolores, funcionalidades de interés, objeciones y datos clave para el cierre. '
            . '- empresa: una o dos frases sobre a qué se dedica el negocio y cuántos empleados tiene. '
            . '- situacion_actual: qué sistema o herramienta usa actualmente para gestionar su negocio (si no lo mencionó, escribir "No especificó"). '
            . '- funcionalidades: funcionalidades de ComercioCity que le interesaron o preguntó durante la conversación. '
            . '- puntos_dolor: principales dolores o problemas con su situación actual. '
            . '- precio_sugerido: objeto interno (nunca mostrar al lead) con las subclaves precio_base (número 500, 1000 o 1500), incluye_ecommerce (booleano), total (número final en USD), bono (número en USD o null si no aplica) y razonamiento (una o dos frases explicando qué señales detectaste). '
            . 'Para calcular precio_sugerido, detectá estas señales en la conversación. SEÑALES ALTAS: (1) más de una sucursal — menciona dos o más locales, depósitos o puntos de venta; (2) mayorista/distribuidor — se presenta como mayorista, distribuidor o vende a revendedores. '
            . 'SEÑALES MEDIAS: (1) cuenta corriente — menciona fiado, deudas de clientes, cuentas a cobrar/pagar o trabaja con crédito; (2) quiere ecommerce — pide tienda online, integración con Mercado Libre o Tienda Nube, o ventas por internet. '
            . 'Precio base según señales: sin señales altas ni dos medias juntas → precio_base 500; una señal alta O dos señales medias → precio_base 1000; dos señales altas O una alta más una media → precio_base 1500. '
            . 'Si quiere ecommerce, sumá al precio base: base 500 +200=total 700 bono 200; base 1000 +300=total 1300 bono 300; base 1500 +500=total 2000 bono 500. '
            . 'Si NO quiere ecommerce: base 500 bono 100; base 1000 bono null; base 1500 bono null. '
            . '- temperatura: objeto interno con nivel ("alta", "media" o "baja") y razonamiento (una o dos frases basadas en lo que dijo el lead). '
            . 'Nivel alta: menciona urgencia, problema activo que le duele hoy, pregunta por implementación o plazos, dice que lo necesita ya. '
            . 'Nivel media: interés genuino sin apuro, hace preguntas de funcionalidades, no menciona urgencia concreta. '
            . 'Nivel baja: solo averigua, "viendo opciones", no cuenta un dolor concreto, respuestas cortas sin profundidad. '
            . 'Devolvé SOLO el JSON, nada más.';

        /* Construir el historial formateado para Claude a partir de los mensajes del lead. */
        $messages_text = $lead->messages->map(function ($msg) {
            $sender  = $msg->sender === 'lead' ? 'LEAD' : 'MARTÍN';
            $content = trim((string) ($msg->content ?? ''));
            if ($content === '') {
                return null;
            }

            return "[{$sender}]: {$content}";
        })->filter()->implode("\n");

        /* Sin mensajes no hay historial para resumir. */
        if (empty($messages_text)) {
            return response()->json(['message' => 'El lead no tiene mensajes para resumir.'], 422);
        }

        /* Prompt de usuario con la conversación completa. */
        $user_content = "Conversación completa con el lead:\n\n{$messages_text}\n\nGenerá el resumen para el closer.";

        try {
            /* Llamada a la API de Anthropic con los parámetros estándar del proyecto. */
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-20250514'),
                /* max_tokens aumentado a 1000 para dar espacio al JSON estructurado. */
                'max_tokens' => 1000,
                'system'     => $system_prompt,
                'messages'   => [['role' => 'user', 'content' => $user_content]],
            ]);

            /* Concatenar todos los bloques de texto de la respuesta. */
            $body = $response->json();
            $raw  = '';
            if (isset($body['content']) && is_array($body['content'])) {
                foreach ($body['content'] as $block) {
                    if (is_array($block) && isset($block['text'])) {
                        $raw .= (string) $block['text'];
                    }
                }
            }
            $raw = trim($raw);

            /* Limpiar posibles backticks de markdown que Claude podría agregar. */
            $raw = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/i', '$1', $raw);

            /*
             * Intentar parsear el JSON estructurado devuelto por Claude.
             * Extrae entre el primer { y el último } para tolerar texto residual.
             */
            $parsed      = null;
            $start_pos   = strpos($raw, '{');
            $end_pos     = strrpos($raw, '}');
            if ($start_pos !== false && $end_pos !== false && $end_pos > $start_pos) {
                $json   = substr($raw, $start_pos, $end_pos - $start_pos + 1);
                $parsed = json_decode($json, true);
            }

            /*
             * Normalizar la clave del resumen textual: Claude a veces usa 'resumen_narrativo'
             * o devuelve 'resumen_textual' vacío pero el resto del JSON es correcto.
             * Se acepta cualquiera de las dos claves; si ambas están vacías pero el JSON
             * tiene las tarjetas estructuradas (empresa, puntos_dolor, etc.) igual se guarda.
             */
            $summary_text = trim((string) ($parsed['resumen_textual'] ?? $parsed['resumen_narrativo'] ?? ''));
            $has_structured = is_array($parsed) && (
                ! empty($parsed['empresa'])
                || ! empty($parsed['puntos_dolor'])
                || ! empty($parsed['funcionalidades'])
                || ! empty($parsed['situacion_actual'])
            );

            if ($has_structured || (is_array($parsed) && $summary_text !== '')) {
                /* Parse exitoso: guardar resumen textual + resumen estructurado. */
                $summary    = $summary_text;

                /*
                 * Claude puede devolver funcionalidades y puntos_dolor como array JSON
                 * (ej: ["facturación", "stock"]) en lugar de string. Se normaliza a string.
                 * precio_sugerido y temperatura son objetos y no pasan por esta función.
                 */
                $normalize_to_string = function ($value): string {
                    if (is_array($value)) {
                        return implode(', ', $value);
                    }
                    return trim((string) ($value ?? ''));
                };

                $structured = [
                    'empresa'          => $normalize_to_string($parsed['empresa']          ?? ''),
                    'situacion_actual' => $normalize_to_string($parsed['situacion_actual'] ?? ''),
                    'funcionalidades'  => $normalize_to_string($parsed['funcionalidades']  ?? ''),
                    'puntos_dolor'     => $normalize_to_string($parsed['puntos_dolor']     ?? ''),
                    'precio_sugerido'  => is_array($parsed['precio_sugerido'] ?? null) ? $parsed['precio_sugerido'] : null,
                    'temperatura'      => is_array($parsed['temperatura']     ?? null) ? $parsed['temperatura']     : null,
                ];

                $lead->update([
                    'demo_summary'            => $summary,
                    /* El cast 'array' en Lead::$casts serializa automáticamente a JSON. */
                    'demo_summary_structured' => $structured,
                ]);
            } else {
                /*
                 * Fallback: Claude no devolvió JSON válido.
                 * Se guarda el texto crudo en demo_summary para no perder el contenido.
                 * demo_summary_structured queda sin actualizar.
                 */
                Log::warning('LeadController@generate_demo_summary_json: Claude no devolvió JSON válido, usando texto crudo.', [
                    'lead_id' => $lead->id,
                    'raw'     => substr($raw, 0, 300),
                ]);

                if (empty($raw)) {
                    return response()->json(['message' => 'Claude no devolvió resumen.'], 422);
                }

                $lead->update(['demo_summary' => $raw]);
            }

            return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);

        } catch (\Throwable $e) {
            Log::error('LeadController@generate_demo_summary_json: error al llamar Claude.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Error al generar resumen: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Marca que el closer realizó la llamada post-demo al lead.
     *
     * Actualiza el timestamp closer_called_at con el momento actual,
     * completando la etapa final del pipeline de cierre comercial.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_closer_called_json($id)
    {
        /* Lead objetivo de la marca de llamada del closer. */
        $lead = Lead::findOrFail($id);
        /* Registrar el momento exacto de la llamada del closer. */
        $lead->update(['closer_called_at' => now()]);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Activa o desactiva la suscripción del admin autenticado a notificaciones WhatsApp
     * de mensajes del lead indicado.
     *
     * Al activar: inserta fila en lead_admin_notifications (idempotente con insertOrIgnore).
     * Al desactivar: elimina la fila del admin autenticado para ese lead.
     *
     * Respuesta: { notificar_mensajes: bool } — si el admin autenticado está suscrito ahora.
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string               $id Lead ID.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_notify_messages_json(Request $request, $id)
    {
        $lead    = Lead::findOrFail($id);
        $enabled = (bool) $request->input('enabled');

        /* Admin autenticado que realiza la acción de suscripción/desuscripción. */
        $admin = Auth::user();

        if ($enabled) {
            /* Inserta la fila en la tabla pivot; si ya existe, la ignora (idempotente). */
            \Illuminate\Support\Facades\DB::table('lead_admin_notifications')
                ->insertOrIgnore([
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
        } else {
            /* Elimina solo la suscripción del admin autenticado para no afectar a otros admins. */
            \Illuminate\Support\Facades\DB::table('lead_admin_notifications')
                ->where('lead_id', $lead->id)
                ->where('admin_id', $admin->id)
                ->delete();
        }

        return response()->json([
            'notificar_mensajes' => $enabled,
        ]);
    }

    /**
     * Fija o desfija un lead en la tabla de leads.
     *
     * Si el lead no está fijado, setea pinned_at = now().
     * Si ya está fijado, lo limpia (null).
     * El campo pinned_at se usa para ordenar los leads fijados al inicio
     * y para determinar el orden entre fijados (el más reciente primero).
     * El pin es global: todos los admins ven el mismo estado de fijado.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_pinned_json($id)
    {
        /* Lead objetivo del toggle de pin. */
        $lead = Lead::findOrFail($id);

        /* Invertir estado: si ya tiene pinned_at lo limpia; si no, lo setea a ahora. */
        if ($lead->pinned_at) {
            $lead->update(['pinned_at' => null]);
        } else {
            $lead->update(['pinned_at' => now()]);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Marca o desmarca manualmente un lead como "no leído" para el admin autenticado (estilo WhatsApp).
     *
     * Es un flag visual independiente de unread_count/unseen_count (mensajes reales sin leer): se
     * muestra en la grilla como un punto rojo sin número (ver LeadProperties, clave
     * 'manually_unread_key'), y se limpia automáticamente la próxima vez que ese admin abra la
     * conversación (ver mark_whatsapp_messages_read_json). Es per-admin: cada admin tiene su propio
     * estado, igual que el resto del sistema de lectura — no es global como el pin de chat.
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_manual_unread_json(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);
        $admin_id = (int) $request->user()->id;

        $existing = \App\Models\LeadManualUnreadMark::where('lead_id', $lead->id)
            ->where('admin_id', $admin_id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            \App\Models\LeadManualUnreadMark::create([
                'lead_id'   => $lead->id,
                'admin_id'  => $admin_id,
                'marked_at' => now(),
            ]);
        }

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Normaliza el input del formulario (contact + setup técnico) en un array
     * listo para create/update. Las checkboxes se resuelven con boolean().
     *
     * @param Request $request
     *
     * @return array<string, mixed>
     */
    protected function extract_data(Request $request)
    {
        return [
            // Datos de contacto
            'contact_name'        => $request->input('contact_name'),
            'company_name'        => $request->input('company_name'),
            'email'               => $request->input('email'),
            'phone'               => $request->input('phone'),
            'doc_number'          => $request->input('doc_number'),
            'meeting_scheduled_at'=> $request->filled('meeting_scheduled_at')
                ? $request->input('meeting_scheduled_at')
                : null,
            'notes'               => $request->input('notes'),

            // Estado del pipeline y client destino
            'status'              => $request->input('status', 'nuevo'),
            'target_client_id'    => $request->filled('target_client_id')
                ? (int) $request->input('target_client_id')
                : null,
            'demo_id'             => $request->filled('demo_id')
                ? (int) $request->input('demo_id')
                : null,
            // Demo: fecha (HTML date) + horas en texto (mutators en Lead normalizan).
            'demo_date'         => $request->input('demo_date'),
            'demo_start_time'   => $request->input('demo_start_time'),
            'demo_end_time'     => $request->input('demo_end_time'),
            'api_url'             => $request->filled('api_url')
                ? rtrim(trim((string) $request->input('api_url')), '/')
                : null,

            // Campos visibles del User de demo
            'user_name'           => $request->input('user_name'),
            'total_a_pagar'       => $request->input('total_a_pagar'),

            // Tipo de negocio + sucursales
            'business_type'       => $request->input('business_type'),
            'use_deposits'        => $request->boolean('use_deposits'),
            'address_1'           => $request->input('address_1'),
            'address_2'           => $request->input('address_2'),
            'address_3'           => $request->input('address_3'),

            // Listas de precios
            'use_price_lists'     => $request->boolean('use_price_lists'),
            'price_type_1'        => $request->input('price_type_1'),
            'price_type_2'        => $request->input('price_type_2'),
            'price_type_3'        => $request->input('price_type_3'),

            // Flags booleanos de setup
            'iva_included'                 => $request->boolean('iva_included'),
            'ventas_con_fecha_de_entrega'  => $request->boolean('ventas_con_fecha_de_entrega'),
            'cajas'                        => $request->boolean('cajas'),
            'usar_codigos_de_barra'        => $request->boolean('usar_codigos_de_barra'),
            'codigos_de_barra_por_defecto' => $request->boolean('codigos_de_barra_por_defecto'),
            'consultora_de_precios'        => $request->boolean('consultora_de_precios'),
            'imagenes'                     => $request->boolean('imagenes'),
            'produccion'                   => $request->boolean('produccion'),
            'ask_amount_in_vender'         => $request->boolean('ask_amount_in_vender'),
            'redondear_centenas_en_vender' => $request->boolean('redondear_centenas_en_vender'),
            'omitir_cuentas_corrientes'    => $request->boolean('omitir_cuentas_corrientes'),
        ];
    }

    /**
     * Envía manualmente una plantilla Meta al lead desde el panel de admin.
     *
     * Recibe el nombre de la plantilla, el código de idioma y el array de variables
     * ya resueltas. Llama a WhatsappSendService::send_template() y registra el
     * LeadMessage con el body renderizado.
     *
     * @param Request             $request               Debe incluir: template_name, language_code, variables (array), content (texto renderizado)
     * @param int|string          $lead_id
     * @param WhatsappSendService $whatsapp_send_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_template_json(Request $request, $lead_id, WhatsappSendService $whatsapp_send_service)
    {
        /* Parámetros obligatorios recibidos del frontend. */
        $template_name  = trim((string) $request->input('template_name', ''));
        $language_code  = trim((string) $request->input('language_code', 'es_AR'));
        $variables      = $request->input('variables', []);
        /* Texto ya renderizado (variables reemplazadas) que se guarda como contenido del mensaje. */
        $content        = trim((string) $request->input('content', ''));

        /* Validaciones mínimas antes de llamar a WhatsApp. */
        if ($template_name === '') {
            return response()->json(['message' => 'El nombre de la plantilla es obligatorio.'], 422);
        }
        if ($content === '') {
            return response()->json(['message' => 'El contenido renderizado no puede estar vacío.'], 422);
        }

        $lead  = Lead::query()->findOrFail($lead_id);
        $phone = trim((string) ($lead->phone ?? ''));

        /* Intentar el envío por WhatsApp solo si el lead tiene teléfono. */
        $whatsapp_message_id = null;
        if ($phone !== '') {
            try {
                $whatsapp_message_id = $whatsapp_send_service->send_template(
                    $phone,
                    $template_name,
                    $variables,
                    $language_code
                );
            } catch (\Throwable $e) {
                Log::error('LeadController@send_template_json: error WhatsApp.', [
                    'lead_id'       => $lead_id,
                    'template_name' => $template_name,
                    'error'         => $e->getMessage(),
                ]);

                /* Registrar el fallo de envío en la conversación del lead. */
                (new LeadConversationErrorLogger())->log(
                    (int) $lead->id,
                    'No se pudo enviar la plantilla por WhatsApp',
                    $e->getMessage()
                );

                return response()->json(['message' => 'No se pudo enviar la plantilla por WhatsApp: ' . $e->getMessage()], 422);
            }
        }

        /* Registrar el mensaje en la conversación del lead. */
        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'content'               => $content,
            'status'                => 'enviado',
            'whatsapp_message_id'   => $whatsapp_message_id,
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
            // Admin que envió la plantilla a mano (prompt 403).
            'sent_by_admin_id'      => (int) $request->user()->id,
        ]);

        /* Notificar a todos los clientes conectados que la conversación cambió. */
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
    }

    /**
     * Sugiere con IA el motivo de la demora ({{2}} de cc_recuperacion_motivo),
     * leyendo la conversación real del lead.
     *
     * Nunca falla: si Anthropic no responde, el servicio devuelve un motivo genérico.
     *
     * @param  int|string                 $lead_id
     * @param  LeadRecoveryReasonService  $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function suggest_recovery_reason_json($lead_id, LeadRecoveryReasonService $service)
    {
        $lead = Lead::query()->findOrFail($lead_id);

        return response()->json(['motivo' => $service->suggest($lead)], 200);
    }

    /**
     * Panel del closer: leads repartidos en las tres columnas operativas.
     *
     * 🔴 El divisor entre "Listos para la llamada" y "En seguimiento" es `lead_calls.started_at`,
     * NO la existencia de una LeadCall. Desde el grupo 307 el propio agente agenda la llamada con
     * el closer cuando el lead confirma que quiere avanzar: esa fila nace con `scheduled_at`
     * cargado y `started_at` en null (ver `LeadCallService::schedule_closer_call()`). Dividir por
     * "tiene llamada" mandaría a seguimiento a gente que todavía no habló con nadie.
     *
     * Las tres reglas son mutuamente excluyentes: las columnas 1 y 2 tienen conjuntos de estado
     * disjuntos y las dos exigen que no haya ninguna llamada iniciada; la 3 exige lo contrario.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function closer_panel_json()
    {
        /* Cierres y pausa: salen del panel del closer, no hay nada que hacer con ellos. */
        $estados_cerrados = ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'];

        /* Ciclo de demo sin terminar: agendó y todavía no confirmó que la recorrió. */
        $estados_demo_sin_terminar = [
            'demo_agendada',
            'ingresando_demo',
            'demo_en_curso',
            'demo_pendiente_de_ingreso',
            'demo_pendiente_de_terminar',
        ];

        /*
         * Columna 1 "Demos agendadas": el lead agendó la demo y todavía no la terminó. Incluye
         * los subestados del ciclo (entrando, en curso, y las dos ramas de fallo) porque el closer
         * pidió el panorama completo de lo que puede llegarle en los próximos días, no solo lo que
         * tiene fecha futura.
         */
        $agendadas = Lead::withAll()
            ->whereIn('status', $estados_demo_sin_terminar)
            ->whereDoesntHave('calls', function ($q) {
                $q->whereNotNull('started_at');
            })
            ->orderBy('demo_date')
            ->orderBy('demo_start_time')
            ->get();

        /*
         * Columna 2 "Listos para la llamada": terminaron la demo y todavía no tuvieron la llamada
         * con el closer. Dos poblaciones distintas, que el frontend separa por badge:
         *   - `closer_activo`  → confirmaron que quieren avanzar (el agente ya se los preguntó).
         *   - `demo_realizada` → terminaron la demo pero nadie les preguntó todavía, o no
         *     contestaron. Es también donde cae el vencimiento de `demo_pendiente_de_terminar`.
         * Se cargan las llamadas porque acá ya puede haber una AGENDADA por el agente (grupo 307)
         * y el closer necesita ver el horario acordado y el link de Meet.
         */
        $para_llamar = Lead::withAll()
            ->with(['calls' => function ($q) {
                $q->with('partners')->orderBy('id');
            }])
            ->whereIn('status', ['demo_realizada', 'closer_activo'])
            ->whereDoesntHave('calls', function ($q) {
                $q->whereNotNull('started_at');
            })
            ->orderBy('demo_date', 'desc')
            ->orderBy('demo_start_time', 'desc')
            ->get();

        /*
         * Columna 3 "En seguimiento": ya tuvieron al menos una llamada de verdad con el closer y
         * todavía no cerraron. Se filtra por estado cerrado en vez de exigir `closer_activo` para
         * no perder un lead que quedó con un estado raro después de la llamada: el criterio del
         * pedido es "tuvo la llamada y todavía no está cerrado ganado".
         */
        $seguimiento = Lead::withAll()
            ->with(['calls' => function ($q) {
                $q->with('partners')->orderBy('id');
            }])
            ->whereHas('calls', function ($q) {
                $q->whereNotNull('started_at');
            })
            ->whereNotIn('status', $estados_cerrados)
            ->orderBy('closer_called_at', 'desc')
            ->get();

        return response()->json([
            'agendadas'   => $agendadas,
            'para_llamar' => $para_llamar,
            'seguimiento' => $seguimiento,
            'settings'    => [
                'alert_delay_minutes'   => (int) AdminSetting::get('closer_alert_delay_minutes', 5),
                'alert_abandon_minutes' => (int) AdminSetting::get('closer_alert_abandon_minutes', 20),
                /* Cuenta de Google con la que se crean los eventos: el panel abre todo link de
                 * Meet con `authuser=` esta cuenta. Sin esto el closer entra con la sesión que
                 * tenga abierta el navegador, que casi nunca es la del calendario conectado, y
                 * Google Meet lo trata como invitado y le pide que el anfitrión lo admita. */
                'closer_google_account' => $this->closer_google_account(),
            ],
        ], 200);
    }

    /**
     * Mail de la cuenta de Google conectada del closer, o null si no hay ninguna conexión activa.
     *
     * Se resuelve con la misma regla que `CloserGoogleCalendarEventService::get_closer_connection()`
     * (primer admin con `is_closer`, su conexión activa) para que el panel muestre exactamente la
     * cuenta con la que se están creando los eventos y no otra.
     *
     * @return string|null
     */
    private function closer_google_account()
    {
        $closer = Admin::where('is_closer', true)->first();
        if (! $closer) {
            return null;
        }

        $connection = AdminCalendarConnection::where('admin_id', $closer->id)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return null;
        }

        $email = trim((string) $connection->google_account_email);

        return $email !== '' ? $email : null;
    }

    /**
     * Confirma un socio sugerido: deja de estar pendiente de alta.
     *
     * @param int|string $id ID del LeadPartner.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirm_partner_json($id)
    {
        $partner = LeadPartner::findOrFail($id);
        $partner->pending_confirmation = false;
        $partner->save();

        return response()->json(['model' => $partner], 200);
    }

    /**
     * Rechaza o elimina un socio sugerido del lead.
     *
     * @param int|string $id ID del LeadPartner.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_partner_json($id)
    {
        $partner = LeadPartner::findOrFail($id);
        $partner->delete();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Crea un socio manualmente para un lead (ya confirmado).
     *
     * @param int|string $lead_id
     * @param Request    $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_partner_json($lead_id, Request $request)
    {
        Lead::findOrFail($lead_id);

        $partner = LeadPartner::create([
            'lead_id'               => (int) $lead_id,
            'name'                  => trim((string) $request->input('name', '')) ?: null,
            'phone'                 => trim((string) $request->input('phone', '')) ?: null,
            'notes'                 => trim((string) $request->input('notes', '')) ?: null,
            'source'                => 'manual',
            'pending_confirmation'  => false,
        ]);

        return response()->json(['model' => $partner], 201);
    }

    /**
     * Devuelve los minutos configurables para alertas "Tomar llamada" del closer.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function closer_alert_settings_json()
    {
        return response()->json([
            'alert_delay_minutes'   => (int) AdminSetting::get('closer_alert_delay_minutes', 5),
            'alert_abandon_minutes' => (int) AdminSetting::get('closer_alert_abandon_minutes', 20),
        ], 200);
    }

    /**
     * Persiste los minutos de alerta y abandono del panel del closer.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_closer_alert_settings_json(Request $request)
    {
        AdminSetting::set('closer_alert_delay_minutes', (string) (int) $request->input('alert_delay_minutes', 5));
        AdminSetting::set('closer_alert_abandon_minutes', (string) (int) $request->input('alert_abandon_minutes', 20));

        return response()->json(['ok' => true], 200);
    }

    /**
     * Genera (o regenera) un mensaje de seguimiento sugerido para el closer basado en call_summary.
     *
     * Llama a CloserFollowupService con el lead actualizado y devuelve el lead completo
     * con la conversación refrescada para que el frontend pueda mostrar el nuevo mensaje.
     *
     * @param int $id ID del lead al que se le generará el seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse Lead completo con mensajes y adjuntos.
     */
    public function generate_closer_followup_json($id)
    {
        /* Buscar el lead con todas sus relaciones para que el servicio tenga el contexto completo. */
        $lead = Lead::withAll()->findOrFail($id);

        /* Resumen a usar: el de la llamada completada más reciente del lead. */
        $latest_call = $lead->calls()->whereNotNull('call_summary')->orderByDesc('id')->first();
        if (!$latest_call) {
            return response()->json(['message' => 'El lead todavía no tiene ninguna llamada con resumen.'], 422);
        }

        /* Generar la sugerencia de seguimiento con Claude a partir del resumen de esa llamada. */
        app(\App\Services\CloserFollowupService::class)->generate_followup_from_summary($lead, $latest_call->call_summary);

        /* Refrescar el lead para incluir el nuevo mensaje en la respuesta. */
        $lead->refresh();
        $lead->load('messages.attachments');

        return response()->json($lead);
    }

    /**
     * El closer acepta la alerta "Tomar llamada":
     * registra el timestamp de aceptación y envía el link de Meet al lead por WhatsApp.
     *
     * @param int $id ID del lead cuya alerta fue aceptada.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function closer_accept_alert_json($id)
    {
        // Buscar el lead o devolver 404 si no existe.
        $lead = Lead::findOrFail($id);

        // Delegar al servicio de alertas del closer.
        app(\App\Services\CloserAlertService::class)->accept_alert($lead);

        return response()->json([
            'ok'       => true,
            'meet_url' => $lead->meet_url,
        ], 200);
    }

    /**
     * Endpoint retirado (grupo 117): el bot ahora se manda por llamada (LeadCall) vía
     * LeadCallController (calls/join, calls/new, calls/{id}/send-bot).
     *
     * @param int|string $id ID del lead.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_recall_bot_json($id)
    {
        /* Endpoint retirado (grupo 117): el bot ahora se manda por llamada (LeadCall) vía
         * LeadCallController (calls/join, calls/new, calls/{id}/send-bot). Este método
         * escribía recall_bot_id en el lead, que el webhook nuevo ya no rutea. Se deja como
         * no-op 200 para no romper llamadas viejas del frontend. */
        return response()->json(['ok' => true, 'retired' => true], 200);
    }

    /**
     * Encola GenerateLeadAiSuggestionJob para todos los leads sin respuesta elegibles.
     *
     * Punto de entrada del recovery manual batch: útil cuando errores del servidor
     * mataron jobs silenciosamente y hay leads esperando sugerencia de Claude.
     *
     * @param BatchLeadAiRecoveryService $service Servicio de recovery inyectado por Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function batch_recover_unanswered_json(BatchLeadAiRecoveryService $service)
    {
        /* Ejecutar el recovery de respuestas sin contestar y obtener los contadores. */
        $result = $service->dispatch_unanswered_leads();

        /* Además, reintentar seguimientos automáticos por plantilla que se ejecutaron pero nunca
           llegaron a enviarse (ver LeadFollowupService::send_followup_via_template(), prompt 245, y
           BatchLeadAiRecoveryService::retry_failed_followups(), prompt 246). */
        $followups_result = $service->retry_failed_followups();

        Log::channel('daily')->info('batch_recover_unanswered: recovery iniciado.', array_merge($result, [
            'followups_retried' => $followups_result['retried'],
            'followups_skipped' => $followups_result['skipped_followups'],
        ]));

        return response()->json([
            'message'           => 'Recovery iniciado',
            'dispatched'        => $result['dispatched'],
            'skipped'           => $result['skipped'],
            'followups_retried' => $followups_result['retried'],
            'followups_skipped' => $followups_result['skipped_followups'],
        ]);
    }
}

