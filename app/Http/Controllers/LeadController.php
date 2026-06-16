<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Mail\Helpers\LeadPresentationMailHelper;
use App\Mail\Helpers\LeadFollowupMailHelper;
use App\Mail\Helpers\LeadDemoMailHelper;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPersonalizedDemoVideo;
use App\Models\ProtocolEntry;
use App\Events\LeadAiSuggestionFinished;
use App\Events\LeadAiSuggestionGenerating;
use App\Services\LeadAiService;
use App\Services\WhatsappSendService;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadBroadcastService;
use App\Services\LeadConversationAiState;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsAppPasteCleaner;
use App\Services\PromoteLeadService;
use App\Services\PromoteLeadToClientService;
use App\Services\LeadContractPdfService;
use App\Services\RunDemoSetupService;
use App\Services\RunUserSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $leads = $query->paginate(30)->withQueryString();
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

        // Si se reagendó la demo, resetear el flag para que el nuevo horario reciba recordatorio.
        if ($original_demo_date !== $lead->getRawOriginal('demo_date')) {
            $lead->update(['recordatorio_demo_enviado' => false]);
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
        $query = Lead::query()->withAllForList()->orderBy('id', 'desc');

        // Filtro por estado comercial.
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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
     *
     * @param \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection|\Illuminate\Contracts\Pagination\Paginator $models
     *
     * @return void
     */
    protected function prepare_leads_for_list_json($models)
    {
        Lead::prepare_collection_for_list_json($models);
    }

    /**
     * Marca un lead de detalle con alcance completo de mensajes.
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

        // Política funcional: user_id ya no se define en alta/edición de lead.
        // Se asigna recién en la promoción a Client.
        $request->request->remove('user_id');

        // Seteamos campos usando helper declarativo para respetar properties().
        ModelPropertiesHelper::set_from_request($lead, $request, 'lead');
        $this->sync_personalized_demo_videos_from_request($lead, $request);

        // Si se reagendó la demo, resetear el flag para que el nuevo horario reciba recordatorio.
        // Recargar el lead desde DB para leer el demo_date ya persistido por set_from_request.
        $lead->refresh();
        if ($original_demo_date !== $lead->getRawOriginal('demo_date')) {
            $lead->update(['recordatorio_demo_enviado' => false]);
        }

        return response()->json(['model' => $this->fullModel('lead', $id)], 200);
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
     * @param int|string $id Identificador del lead.
     *
     * @return \Illuminate\Http\Response
     */
    public function generate_contract_json($id)
    {
        // Lead con datos de contrato persistidos en la tabla.
        $lead = Lead::findOrFail($id);

        try {
            // Contenido binario del PDF generado con dompdf.
            $pdf_content = LeadContractPdfService::generate($lead);
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
     * Campos requeridos: contact_name, email, doc_number, company_name,
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
        if (empty($lead->company_name))   { $missing[] = 'nombre de empresa'; }
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

        if ($lead->demo_setup_status === 'exitoso') {
            return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
        }

        return response()->json([
            'message' => 'No se pudo crear la demo: ' . $lead->demo_setup_last_error,
            'model' => $this->fullModel('lead', $lead->id),
        ], 422);
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
     * @param Request              $request  Debe incluir `content` (texto del mensaje).
     * @param int|string           $lead_id
     * @param WhatsappSendService  $whatsapp_send_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_direct_message_json(Request $request, $lead_id, WhatsappSendService $whatsapp_send_service)
    {
        $text = trim((string) $request->input('content', ''));
        if ($text === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        $lead = Lead::query()->findOrFail($lead_id);

        $phone = trim((string) ($lead->phone ?? ''));

        $whatsapp_message_id = null;
        if ($phone !== '') {
            try {
                $whatsapp_message_id = $whatsapp_send_service->send_text($phone, $text);
            } catch (\Throwable $e) {
                Log::error('LeadController@send_direct_message_json: error WhatsApp.', [
                    'lead_id' => $lead_id,
                    'error'   => $e->getMessage(),
                ]);

                return response()->json(['message' => 'No se pudo enviar el mensaje por WhatsApp: '.$e->getMessage()], 422);
            }
        }

        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'content'               => $text,
            'status'                => 'enviado',
            'whatsapp_message_id'   => $whatsapp_message_id,
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return response()->json(['model' => $this->fullModel('lead', $lead->id)], 200);
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
            $send_service->send_suggestion($message);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            Log::error('LeadController@approve_message_json: '.$exception->getMessage(), [
                'message_id' => $message_id,
            ]);

            return response()->json(['message' => 'No se pudo enviar el mensaje por WhatsApp.'], 422);
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
            $send_service->send_suggestion($message, $edited_content);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            Log::error('LeadController@approve_message_with_edit_json: '.$exception->getMessage(), [
                'message_id' => $message_id,
            ]);

            return response()->json(['message' => 'No se pudo enviar el mensaje por WhatsApp.'], 422);
        }

        return response()->json(['model' => $this->fullModel('lead', $message->lead_id)], 200);
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
     * Cancela el envío automático programado de una sugerencia y la marca como no enviada.
     *
     * Claude verá la sugerencia en el historial como no enviada al lead.
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

        if ($message->is_followup) {
            return response()->json(['message' => 'Las sugerencias de seguimiento no tienen envío automático.'], 422);
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
            'unread_total' => LeadBroadcastService::count_unread_for_admin($admin_id),
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

        // Mensajes entrantes del lead (los del setter/sistema no aplican al badge).
        $message_ids = LeadMessage::query()
            ->where('lead_id', (int) $lead_id)
            ->where('sender', 'lead')
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
     * Genera el resumen del lead con Claude manualmente desde admin-spa.
     *
     * Llama a la API de Anthropic con el historial de mensajes del lead para
     * producir un resumen orientado al closer. Mismo prompt que el scheduler
     * GenerateDemoSummary pero sin esperar el timing automático.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate_demo_summary_json($id)
    {
        /* Lead con historial completo de mensajes para alimentar el prompt. */
        $lead = Lead::with('messages')->findOrFail($id);

        /* System prompt idéntico al usado en el scheduler automático. */
        $system_prompt = 'Sos un asistente de ventas. Tu tarea es generar un resumen breve del perfil de este lead '
            . 'para que el closer pueda llamarlo inmediatamente después de la demo con todo el contexto necesario. '
            . 'El resumen debe incluir: tipo de negocio, cantidad de empleados, dolores principales que mencionó, '
            . 'qué funcionalidades le interesaron (si las mencionó), objeciones que planteó, preguntas que hizo, '
            . 'y cualquier información relevante para el cierre. '
            . 'Máximo 200 palabras. Sin bullets. Prosa natural.';

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
                'max_tokens' => 800,
                'system'     => $system_prompt,
                'messages'   => [['role' => 'user', 'content' => $user_content]],
            ]);

            $body    = $response->json();
            /* Texto generado por Claude: primer bloque de contenido de la respuesta. */
            $summary = trim($body['content'][0]['text'] ?? '');

            if (empty($summary)) {
                return response()->json(['message' => 'Claude no devolvió resumen.'], 422);
            }

            /* Persistir el resumen generado en el campo demo_summary del lead. */
            $lead->update(['demo_summary' => $summary]);

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
}
