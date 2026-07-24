<?php

use App\Http\Controllers\AdminTaskController;
use App\Http\Controllers\AdminTaskNotificationController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminSearchProxyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ColumnPreferenceController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\ClientApiController;
use App\Http\Controllers\ClientEmployeeController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientMensualidadController;
use App\Http\Controllers\ComerciocityAfipConfigController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoUpdateController;
use App\Http\Controllers\CommonLaravel\UpdateController as MassUpdateController;
use App\Http\Controllers\AiSystemPromptController;
use App\Http\Controllers\Api\ClientInstallationController;
use App\Http\Controllers\Api\EnvTemplateController;
use App\Http\Controllers\WhatsappConfigController;
use App\Http\Controllers\WhatsappWebhookController;
use App\Http\Controllers\RecallWebhookController;
use App\Http\Controllers\FollowupRuleController;
use App\Http\Controllers\FollowupTemplateController;
use App\Http\Controllers\LeadCallController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProtocolEntryController;
use App\Http\Controllers\SharedDatabaseGroupController;
use App\Http\Controllers\UpdateCommandController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UpdateSeederController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminCalendarConnectionController;
use App\Http\Controllers\Api\ImplementationFormController;
use App\Http\Controllers\Api\DebugVirtualTimeController;
use Illuminate\Support\Facades\Route;

/*
| Webhook Kapso / WhatsApp (público, verificación por firma HMAC)
*/
Route::post('webhook/whatsapp', [WhatsappWebhookController::class, 'receive'])
    ->middleware('throttle:api');

/*
| Webhook Recall.ai (público, verificación por firma HMAC opcional)
*/
Route::post('webhook/recall', [RecallWebhookController::class, 'receive'])
    ->middleware('throttle:api');

/*
| Formulario público de configuración de implementación (acceso por token, sin auth).
| El cliente accede con un link único que contiene form_token; no requiere Sanctum.
*/
Route::prefix('form')->group(function () {
    Route::get('implementation/{token}',         [ImplementationFormController::class, 'show']);
    Route::patch('implementation/{token}',       [ImplementationFormController::class, 'save']);
    Route::post('implementation/{token}/submit', [ImplementationFormController::class, 'submit']);
});

/*
| Vista en vivo del PDF de una Factura C de mensualidad (prompt 362): pública
| (fuera de `auth:sanctum`) porque una navegación directa del navegador
| (window.open) no puede mandar el header Authorization. Se gatea con un
| token de un solo uso, de vida corta (2 min), emitido por la ruta autenticada
| `client/{clientId}/factura/{invoiceId}/pdf-access-token` (ver grupo admin).
*/
Route::get('client/{clientId}/factura/{invoiceId}/pdf-view/{token}', [ClientMensualidadController::class, 'factura_pdf_view']);

/*
| Callback desde empresa-api cliente (inbound)
*/
Route::middleware('admin.inbound.key')
    ->prefix('inbound')
    ->group(function () {
        Route::post('notification-reads', 'Api\InboundReadController@store');
        Route::post('support/messages', 'Api\InboundSupportMessageController@store');
        Route::post('support/messages/read', 'Api\InboundSupportMessageController@mark_read');
        Route::post('support/typing', 'Api\InboundSupportMessageController@typing');
    });

/*
|--------------------------------------------------------------------------
| Ingesta de tareas creadas por Claude (protegida por X-Claude-Task-Key)
|--------------------------------------------------------------------------
*/
Route::middleware('claude.task.key')
    ->prefix('claude')
    ->group(function () {
        Route::get('admins', 'Api\ClaudeTaskIngestController@admins_json');
        Route::post('task', 'Api\ClaudeTaskIngestController@store_json');
    });

/*
| Admin SPA: token Sanctum (prefijo admin)
*/
Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    /* Callback de Google OAuth: público porque Google no envía header de Sanctum.
     * La seguridad se garantiza por la firma HMAC del parámetro state. */
    Route::get('calendar/google/callback', [AdminCalendarConnectionController::class, 'callback']);

    /* Adjuntos de lead: URL firmada (abre en nueva pestaña sin symlink /storage en el hosting). */
    Route::get('lead-message-attachment/{id}/file', [LeadController::class, 'serve_message_attachment_file_json'])
        ->name('lead.message.attachment.file')
        ->middleware('signed');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'update_profile']);

        Route::get('meta/{model}', [MetaController::class, 'show']);
        Route::get('column-preferences/{model}', [ColumnPreferenceController::class, 'show']);
        Route::put('column-preferences/{model}', [ColumnPreferenceController::class, 'update']);

        Route::post('search/{model}/null/1', [AdminSearchProxyController::class, 'search']);
        Route::post('search-from-modal/{model}', [SearchController::class, 'searchFromModal']);
        Route::post('mass-update/{model}', [MassUpdateController::class, 'update']);

        Route::get('version', [VersionController::class, 'index_json']);
        Route::get('version/{id}', [VersionController::class, 'show_json']);
        Route::post('version', [VersionController::class, 'store_json']);
        Route::put('version/{id}', [VersionController::class, 'update_json']);
        Route::delete('version/{id}', [VersionController::class, 'destroy_json']);

        Route::get('client', [ClientController::class, 'index_json']);
        Route::post('client/suggest-subdomain', [ClientController::class, 'suggest_subdomain_json']);
        Route::get('client/{id}', [ClientController::class, 'show_json']);
        Route::post('client', [ClientController::class, 'store_json']);
        Route::put('client/{id}', [ClientController::class, 'update_json']);
        Route::delete('client/{id}', [ClientController::class, 'destroy_json']);

        // Configuración fiscal (AFIP) propia de ComercioCity: fila única, GET/PUT.
        Route::get('comerciocity-afip-config', [ComerciocityAfipConfigController::class, 'show_json']);
        Route::put('comerciocity-afip-config', [ComerciocityAfipConfigController::class, 'update_json']);
        Route::post('comerciocity-afip-config/logo', [ComerciocityAfipConfigController::class, 'upload_logo_json']);

        Route::get('shared-database-groups', [SharedDatabaseGroupController::class, 'index_json']);
        Route::post('shared-database-groups', [SharedDatabaseGroupController::class, 'store_json']);
        Route::delete('shared-database-groups/{id}', [SharedDatabaseGroupController::class, 'destroy_json']);
        Route::post('clients/{id}/shared-database-group', [SharedDatabaseGroupController::class, 'assign_client_json']);
        Route::delete('clients/{id}/shared-database-group', [SharedDatabaseGroupController::class, 'remove_client_json']);

        Route::post('client-api', [ClientApiController::class, 'store_json']);
        Route::put('client-api/{id}', [ClientApiController::class, 'update_json']);
        Route::delete('client-api/{id}', [ClientApiController::class, 'destroy_json']);

        Route::post('client-employee', [ClientEmployeeController::class, 'store_json']);
        Route::put('client-employee/{id}', [ClientEmployeeController::class, 'update_json']);
        Route::delete('client-employee/{id}', [ClientEmployeeController::class, 'destroy_json']);

        // Empleados del cliente (rutas anidadas por uuid, usadas desde admin-spa has_many).
        Route::post('client/{clientId}/employees', [ClientEmployeeController::class, 'store_for_client_json']);
        Route::post('client/{clientId}/employees/sync-from-empresa', [ClientEmployeeController::class, 'sync_from_empresa_json']);
        Route::put('client/{clientId}/employees/{employeeId}', [ClientEmployeeController::class, 'update_for_client_json']);
        Route::delete('client/{clientId}/employees/{employeeId}', [ClientEmployeeController::class, 'destroy_for_client_json']);

        // Mensualidad del cliente (inputs manuales + total calculado de forma autónoma, prompt 329).
        Route::get('client/{clientId}/mensualidad', [ClientMensualidadController::class, 'show_json']);
        Route::put('client/{clientId}/mensualidad', [ClientMensualidadController::class, 'update_json']);
        // Emisión de Factura C (WSFE) por la mensualidad del cliente (prompt 331).
        Route::post('client/{clientId}/emitir-factura', [ClientMensualidadController::class, 'emitir_factura_json']);
        // Historial de Facturas C emitidas/rechazadas para este cliente, sin los SOAP crudos (prompt 364).
        Route::get('client/{clientId}/facturas', [ClientMensualidadController::class, 'facturas_json']);
        // Sincronización OPCIONAL con la empresa-api del cliente: traer conteos vivos / empujar fecha de pago (prompt 335).
        Route::post('client/{clientId}/mensualidad/traer-del-cliente', [ClientMensualidadController::class, 'traer_del_cliente_json']);
        Route::post('client/{clientId}/mensualidad/actualizar-en-cliente', [ClientMensualidadController::class, 'actualizar_en_cliente_json']);
        // PDF de una Factura C ya emitida y autorizada (prompt 332).
        Route::get('client/{clientId}/factura/{invoiceId}/pdf', [ClientMensualidadController::class, 'factura_pdf']);
        // Token de un solo uso para la vista en vivo del PDF sin auth:sanctum (prompt 362).
        Route::post('client/{clientId}/factura/{invoiceId}/pdf-access-token', [ClientMensualidadController::class, 'factura_pdf_access_token_json']);

        Route::get('lead', [LeadController::class, 'index_json']);
        Route::get('lead/unread-badges', [LeadController::class, 'unread_badges_json']);
        // Ruta de recovery batch: debe ir antes de las rutas con {id} para evitar colisión.
        Route::post('lead/batch-recover-unanswered', [LeadController::class, 'batch_recover_unanswered_json']);
        Route::post('lead/mark-pending-review', [LeadController::class, 'mark_pending_review_json']);
        Route::get('lead/{id}', [LeadController::class, 'show_json']);
        Route::post('lead', [LeadController::class, 'store_json']);
        Route::put('lead/{id}', [LeadController::class, 'update_json']);
        Route::delete('lead/{id}', [LeadController::class, 'destroy_json']);
        Route::post('lead/{id}/send-presentation-mail', [LeadController::class, 'send_presentation_mail_json']);
        Route::post('lead/{id}/send-followup-mail', [LeadController::class, 'send_followup_mail_json']);
        Route::post('lead/{id}/run-demo-setup', [LeadController::class, 'run_demo_setup_json']);
        // Disponibilidad de demos/horarios para el panel de verificación (prompt 321).
        Route::get('lead/{id}/panel-availability', [LeadController::class, 'panel_availability_json']);
        // Persistencia de toggles de automatización por lead desde el modal de operaciones (prompt 321).
        Route::patch('lead/{id}/automations', [LeadController::class, 'update_lead_automations_json']);
        Route::post('lead/{id}/promote', [LeadController::class, 'store_promote_json']);
        Route::post('lead/{id}/promote-to-client', [LeadController::class, 'promote_to_client_json']);
        Route::post('lead/{id}/run-user-setup', [LeadController::class, 'run_user_setup_json']);
        Route::post('lead/{id}/send-demo-mail', [LeadController::class, 'send_demo_mail_json']);
        Route::post('lead/{id}/generate-contract', [LeadController::class, 'generate_contract_json']);
        Route::post('lead/{id}/messages', [LeadController::class, 'store_message_json']);
        Route::post('lead/{id}/send-direct-message', [LeadController::class, 'send_direct_message_json']);
        Route::post('lead/{lead_id}/send-template', [LeadController::class, 'send_template_json']);
        Route::post('lead/{lead_id}/suggest-recovery-reason', [LeadController::class, 'suggest_recovery_reason_json']);
        Route::post('lead/{id}/send-direct-audio', [LeadController::class, 'send_direct_audio_json']);
        Route::post('lead/{id}/send-direct-image', [LeadController::class, 'send_direct_image_json']);
        Route::post('lead/{id}/send-direct-document', [LeadController::class, 'send_direct_document_json']);
        Route::post('lead/{id}/simulate-inbound', [LeadController::class, 'simulate_inbound_json']);
        Route::post('lead/{id}/request-ai-suggestion', [LeadController::class, 'request_ai_suggestion_json']);
        Route::post('lead/{id}/resume-with-claude', [LeadController::class, 'resume_with_claude_json']);
        Route::post('lead/{id}/cancel-scheduled-ai-suggestion', [LeadController::class, 'cancel_scheduled_ai_suggestion_json']);
        Route::post('lead/{id}/toggle-claude-auto-reply', [LeadController::class, 'toggle_claude_auto_reply_json']);
        Route::post('lead/{id}/toggle-requiere-intervencion-humana', [LeadController::class, 'toggle_requiere_intervencion_humana_json']);
        Route::post('lead/{id}/toggle-requiere-verificacion-mensajes', [LeadController::class, 'toggle_requiere_verificacion_mensajes_json']);
        Route::post('lead/{id}/mark-followup-suggestion-seen', [LeadController::class, 'mark_followup_suggestion_seen_json']);
        Route::post('lead/{id}/mark-whatsapp-messages-read', [LeadController::class, 'mark_whatsapp_messages_read_json']);
        Route::post('lead/{id}/send-demo-reminder', [LeadController::class, 'send_demo_reminder_json']);
        Route::post('lead/{id}/check-demo-ingress', [LeadController::class, 'check_demo_ingress_json']);
        Route::post('lead/{id}/check-demo-fin', [LeadController::class, 'check_demo_fin_json']);
        Route::post('lead/{id}/force-calendar-event', [LeadController::class, 'force_calendar_event_json']);
        Route::post('lead/{id}/force-followup', [LeadController::class, 'force_followup_json']);
        Route::post('lead/{id}/generate-demo-summary', [LeadController::class, 'generate_demo_summary_json']);
        Route::post('lead/{id}/mark-closer-called', [LeadController::class, 'mark_closer_called_json']);
        Route::post('lead/{id}/toggle-notify-messages', [LeadController::class, 'toggle_notify_messages_json']);
        Route::post('lead/{id}/toggle-pinned', [LeadController::class, 'toggle_pinned_json']);
        Route::post('lead/{id}/toggle-manual-unread', [LeadController::class, 'toggle_manual_unread_json']);

        // Panel del closer: leads filtrados por rol y sección operativa.
        Route::get('closer/panel', [LeadController::class, 'closer_panel_json']);
        Route::post('lead-partner/{id}/confirm', [LeadController::class, 'confirm_partner_json']);
        Route::delete('lead-partner/{id}', [LeadController::class, 'destroy_partner_json']);
        Route::post('lead/{id}/partners', [LeadController::class, 'store_partner_json']);

        Route::get('settings/closer-alert', [LeadController::class, 'closer_alert_settings_json']);
        Route::put('settings/closer-alert', [LeadController::class, 'update_closer_alert_settings_json']);

        // El closer acepta la alerta "Tomar llamada": registra aceptación + envía Meet al lead.
        Route::post('lead/{id}/closer-accept-alert', [LeadController::class, 'closer_accept_alert_json']);
        Route::post('lead/{id}/generate-closer-followup', [LeadController::class, 'generate_closer_followup_json']);
        Route::post('lead/{id}/send-recall-bot', [LeadController::class, 'send_recall_bot_json']);

        // Ciclo de llamadas del closer con el lead (unirse/nueva reunión/mandar bot manual): LeadCallController (prompt 491).
        Route::post('lead/{id}/calls/join', [LeadCallController::class, 'join_json']);
        Route::post('lead/{id}/calls/new', [LeadCallController::class, 'create_new_json']);
        Route::post('lead/{id}/calls/{call_id}/send-bot', [LeadCallController::class, 'send_bot_json']);

        Route::get('message-variant', [\App\Http\Controllers\Api\MessageVariantController::class, 'index_json']);
        Route::post('message-variant', [\App\Http\Controllers\Api\MessageVariantController::class, 'store_json']);
        Route::put('message-variant/{id}', [\App\Http\Controllers\Api\MessageVariantController::class, 'update_json']);
        Route::delete('message-variant/{id}', [\App\Http\Controllers\Api\MessageVariantController::class, 'destroy_json']);

        Route::put('lead-message/{id}/approve', [LeadController::class, 'approve_message_json']);
        Route::put('lead-message/{id}/approve-with-edit', [LeadController::class, 'approve_message_with_edit_json']);
        /* Aprobación con acciones editadas por el admin (final_actions) + log de override (prompt 320). */
        Route::put('lead-message/{id}/approve-with-actions', [LeadController::class, 'approve_message_with_actions_json']);
        Route::put('lead-message/{id}/reject', [LeadController::class, 'reject_message_json']);
        Route::put('lead-message/{id}/cancel-auto-send', [LeadController::class, 'cancel_auto_send_message_json']);
        /* Alterna si el mensaje se incluye o se excluye del historial enviado a Claude. */
        Route::put('lead-message/{id}/toggle-deleted-from-context', [LeadController::class, 'toggle_deleted_from_context_json']);

        Route::get('followup-rule', [FollowupRuleController::class, 'index_json']);
        Route::put('followup-rule/{id}', [FollowupRuleController::class, 'update_json']);

        Route::get('followup-template', [FollowupTemplateController::class, 'index_json']);
        Route::put('followup-template/{id}', [FollowupTemplateController::class, 'update_json']);

        Route::get('ai-system-prompt', [AiSystemPromptController::class, 'index']);
        Route::put('ai-system-prompt', [AiSystemPromptController::class, 'update']);

        Route::get('whatsapp-config', [WhatsappConfigController::class, 'show']);
        Route::put('whatsapp-config', [WhatsappConfigController::class, 'update']);

        Route::get('protocol-entry', [ProtocolEntryController::class, 'index_json']);
        Route::post('protocol-entry', [ProtocolEntryController::class, 'store_json']);
        Route::patch('protocol-entry/{id}/toggle-activa', [ProtocolEntryController::class, 'toggle_activa']);
        Route::get('protocol-entry/{id}', [ProtocolEntryController::class, 'show_json']);
        Route::put('protocol-entry/{id}', [ProtocolEntryController::class, 'update_json']);
        Route::delete('protocol-entry/{id}', [ProtocolEntryController::class, 'destroy_json']);

        // Web Push: clave pública VAPID + alta/baja de la suscripción del device actual.
        Route::get('push/vapid-public-key', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'vapid_public_key_json']);
        Route::post('push/subscribe', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'store_json']);
        Route::post('push/unsubscribe', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'destroy_json']);

        // CRUD de usuarios admin (equipo interno de ComercioCity).
        Route::get('admin-user', [AdminUserController::class, 'index_json']);
        Route::get('admin-user/{id}', [AdminUserController::class, 'show_json']);
        Route::post('admin-user', [AdminUserController::class, 'store_json']);
        Route::put('admin-user/{id}', [AdminUserController::class, 'update_json']);
        Route::delete('admin-user/{id}', [AdminUserController::class, 'destroy_json']);

        // Google Calendar OAuth: conexión del closer (autenticado por Sanctum).
        // {admin_id} identifica el admin objetivo que se está gestionando desde el modal,
        // no necesariamente el admin autenticado en la sesión.
        Route::get('calendar/google/{admin_id}/connect', [AdminCalendarConnectionController::class, 'connect']);
        Route::get('calendar/google/{admin_id}/status', [AdminCalendarConnectionController::class, 'status']);
        Route::get('calendar/google/{admin_id}/list-calendars', [AdminCalendarConnectionController::class, 'list_calendars']);
        Route::put('calendar/google/{admin_id}/select-calendar', [AdminCalendarConnectionController::class, 'select_calendar']);
        Route::get('calendar/google/{admin_id}/events', [AdminCalendarConnectionController::class, 'get_events']);
        Route::post('calendar/google/{admin_id}/sync', [AdminCalendarConnectionController::class, 'sync_calendar']);
        Route::delete('calendar/google/{admin_id}', [AdminCalendarConnectionController::class, 'disconnect']);

        Route::get('demo', [DemoController::class, 'index_json']);
        Route::get('demo/{id}', [DemoController::class, 'show_json']);
        Route::post('demo', [DemoController::class, 'store_json']);
        Route::put('demo/{id}', [DemoController::class, 'update_json']);
        Route::delete('demo/{id}', [DemoController::class, 'destroy_json']);

        // Demo Updates: pipeline de actualización SPA + API de una demo.
        Route::get('demo-update', [DemoUpdateController::class, 'index_json']);
        Route::get('demo-update/{id}', [DemoUpdateController::class, 'show_json']);
        Route::post('demo-update', [DemoUpdateController::class, 'store_json']);
        Route::delete('demo-update/{id}', [DemoUpdateController::class, 'destroy_json']);

        Route::get('update', [UpdateController::class, 'index_json']);
        Route::post('update', [UpdateController::class, 'store_json']);
        Route::get('update/{id}', [UpdateController::class, 'show_json']);
        Route::put('update/{id}', [UpdateController::class, 'update_json']);
        Route::delete('update/{id}', [UpdateController::class, 'destroy_json']);

        Route::get('update/{id}/extra-data', [UpdateController::class, 'extra_data_json']);
        Route::post('update/{id}/advance-status', [UpdateController::class, 'advance_status_json']);
        Route::post('update/{id}/mark-step', [UpdateController::class, 'mark_step_json']);
        Route::post('update/{id}/sync', [UpdateController::class, 'sync_to_client_json']);
        Route::post('update/{id}/seeders/{seeder}/mark', [UpdateSeederController::class, 'mark_json']);
        Route::post('update/{id}/seeders/{seeder}/toggle-skip', [UpdateSeederController::class, 'toggle_skip_json']);
        Route::post('update/{id}/commands/{command}/mark', [UpdateCommandController::class, 'mark_json']);
        Route::post('update/{id}/commands/{command}/toggle-skip', [UpdateCommandController::class, 'toggle_skip_json']);

        // Deployment
        Route::post('update/{id}/deploy/start', [DeploymentController::class, 'start_json']);
        Route::post('update/{id}/deploy/start-post-closure', [DeploymentController::class, 'start_post_closure_json']);
        Route::post('update/{id}/deploy/retry-commands', [DeploymentController::class, 'retry_commands_json']);
        Route::post('update/{id}/deploy/configure-system', [DeploymentController::class, 'configure_system_json']);
        Route::post('update/{id}/deploy/confirm-crons', [DeploymentController::class, 'confirm_crons_json']);
        Route::get('update/{id}/deploy/logs', [DeploymentController::class, 'logs_json']);

        // Client APIs
        Route::post('client/{clientId}/apis', [DeploymentController::class, 'store_client_api_json']);
        Route::put('client/{clientId}/apis/{apiId}', [DeploymentController::class, 'update_client_api_json']);
        Route::delete('client/{clientId}/apis/{apiId}', [DeploymentController::class, 'destroy_client_api_json']);
        Route::post('client/{clientId}/apis/{apiId}/set-active', [DeploymentController::class, 'set_active_api_json']);

        // Lista de admins para selectores de asignación (tareas, etc.).
        Route::get('admin', [AdminController::class, 'index']);

        // Plantillas de tareas automáticas (ABM).
        Route::get('task-template', [TaskTemplateController::class, 'index_json']);
        Route::post('task-template', [TaskTemplateController::class, 'store_json']);
        Route::put('task-template/{id}', [TaskTemplateController::class, 'update_json']);
        Route::delete('task-template/{id}', [TaskTemplateController::class, 'destroy_json']);
        Route::patch('task-template/{id}/toggle-active', [TaskTemplateController::class, 'toggle_active_json']);
        Route::patch('task-template/{id}/move-up', [TaskTemplateController::class, 'move_up_json']);
        Route::patch('task-template/{id}/move-down', [TaskTemplateController::class, 'move_down_json']);

        // Tareas internas del panel.
        Route::get('task', [AdminTaskController::class, 'index_json']);
        Route::post('task', [AdminTaskController::class, 'store_json']);
        Route::put('task/reorder', [AdminTaskController::class, 'reorder_json']);
        Route::put('task/{id}', [AdminTaskController::class, 'update_json']);
        Route::delete('task/{id}', [AdminTaskController::class, 'destroy_json']);

        // Avisos in-app de asignación de tareas (admin_task_notifications).
        Route::get('task-notification/pending', [AdminTaskNotificationController::class, 'pending_json']);
        Route::post('task-notification/seen-all', [AdminTaskNotificationController::class, 'mark_all_seen_json']);
        Route::post('task-notification/{id}/seen', [AdminTaskNotificationController::class, 'mark_seen_json']);

        // Soporte tipo bandeja estilo Front.
        Route::get('support-ticket', [\App\Http\Controllers\Api\SupportTicketController::class, 'index']);
        Route::get('support-ticket/unread-badges', [\App\Http\Controllers\Api\SupportTicketController::class, 'unread_badges']);
        Route::get('support-ticket/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'show']);
        Route::post('support-ticket', [\App\Http\Controllers\Api\SupportTicketController::class, 'store']);
        Route::put('support-ticket/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'update']);
        Route::post('support-ticket/{ticket_id}/message', [\App\Http\Controllers\Api\SupportMessageController::class, 'store']);
        Route::post('support-ticket/{ticket_id}/suggest', [\App\Http\Controllers\Api\SupportAiSuggestionController::class, 'suggest']);
        Route::post('support-message/{id}/retry-remote-sync', [\App\Http\Controllers\Api\SupportMessageController::class, 'retry_remote_sync']);
        Route::post('support-message/{id}/mark-read', [\App\Http\Controllers\Api\SupportMessageController::class, 'mark_read']);
        Route::post('support-ticket/{ticket_id}/typing', [\App\Http\Controllers\Api\SupportMessageController::class, 'typing']);

        Route::get('support-knowledge-base', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'index']);
        Route::post('support-knowledge-base', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'store']);
        Route::put('support-knowledge-base/{id}', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'update']);
        Route::delete('support-knowledge-base/{id}', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'destroy']);

        Route::get('settings/support-alert-minutes', [\App\Http\Controllers\Api\SupportAlertSettingsController::class, 'show']);
        Route::put('settings/support-alert-minutes', [\App\Http\Controllers\Api\SupportAlertSettingsController::class, 'update']);

        Route::get('settings/support-ai', [\App\Http\Controllers\Api\SupportAiSettingsController::class, 'show']);
        Route::put('settings/support-ai', [\App\Http\Controllers\Api\SupportAiSettingsController::class, 'update']);

        Route::get('settings/lead-whatsapp-onboarding', [\App\Http\Controllers\Api\LeadWhatsappOnboardingSettingsController::class, 'show']);
        Route::put('settings/lead-whatsapp-onboarding', [\App\Http\Controllers\Api\LeadWhatsappOnboardingSettingsController::class, 'update']);

        // Identidad del agente Martín: nombre y descripción inyectados en el system prompt de Claude.
        Route::get('settings/agent-identity', [\App\Http\Controllers\Api\AgentIdentityController::class, 'show']);
        Route::put('settings/agent-identity', [\App\Http\Controllers\Api\AgentIdentityController::class, 'update']);

        // Sincroniza identidad y system prompt del agente desde GitHub a la BD.
        Route::post('settings/agent-prompts/sync', [\App\Http\Controllers\Api\AgentPromptSyncController::class, 'sync']);
        Route::get('settings/agent-prompts/files', [\App\Http\Controllers\Api\AgentPromptSyncController::class, 'files']);

        // Configuración de demos: duración, márgenes de setup/gracia y tiempos de automatizaciones.
        Route::get('settings/lead-demo', [\App\Http\Controllers\Api\LeadDemoSettingsController::class, 'show']);
        Route::put('settings/lead-demo', [\App\Http\Controllers\Api\LeadDemoSettingsController::class, 'update']);

        // Implementaciones: listado, detalle y avance manual de etapa.
        Route::get('implementation', [\App\Http\Controllers\Api\ImplementationController::class, 'index']);
        // Conteo de implementaciones listas para avanzar (badge del Nav); debe ir antes del wildcard {implementation}.
        Route::get('implementation/ready-to-advance-count', [\App\Http\Controllers\Api\ImplementationController::class, 'ready_to_advance_count']);
        Route::get('implementation/{implementation}', [\App\Http\Controllers\Api\ImplementationController::class, 'show']);
        Route::get('implementation/{implementation}/stage4-data', [\App\Http\Controllers\Api\ImplementationController::class, 'get_stage4_data']);
        // Descarga un archivo de la Etapa 4 vía proxy (evita exponer URLs firmadas de Kapso al browser).
        Route::get('implementation/{implementation}/stage4-file-download', [\App\Http\Controllers\Api\ImplementationController::class, 'stage4_file_download']);
        // Descarga un adjunto de un mensaje de la conversación (mismo proxy Kapso).
        Route::get('implementation/{implementation}/message-file-download/{message}', [\App\Http\Controllers\Api\ImplementationController::class, 'message_file_download']);
        Route::post('implementation/{implementation}/advance-stage', [\App\Http\Controllers\Api\ImplementationController::class, 'advance_stage']);
        Route::post('implementation/{implementation}/simulate-inbound', [\App\Http\Controllers\Api\ImplementationController::class, 'simulate_inbound']);
        Route::post('implementation/{implementation}/send-message', [\App\Http\Controllers\Api\ImplementationController::class, 'send_message']);
        // Cambio de modo de automatización ('manual' | 'auto') — prompt 342.
        Route::put('implementation/{implementation}/automation-mode', [\App\Http\Controllers\Api\ImplementationController::class, 'update_automation_mode']);
        // Edición manual de las respuestas del formulario de la Etapa 1 desde el panel de admin — prompt 178/01.
        Route::patch('implementation/{implementation}/form-responses', [\App\Http\Controllers\Api\ImplementationController::class, 'update_form_responses']);
        // Acciones manuales del flujo de implementación (preview + envío) y ventana de 24 h.
        Route::get('implementation/{implementation}/actions', [\App\Http\Controllers\Api\ImplementationController::class, 'actions_state']);
        Route::get('implementation/{implementation}/actions/{action}/preview', [\App\Http\Controllers\Api\ImplementationController::class, 'action_preview']);
        Route::post('implementation/{implementation}/actions/{action}', [\App\Http\Controllers\Api\ImplementationController::class, 'action_execute']);
        Route::delete('implementation/{implementation}', [\App\Http\Controllers\Api\ImplementationController::class, 'destroy']);

        Route::post('client/{client}/implementation/start', [\App\Http\Controllers\Api\ImplementationController::class, 'start']);

        // Ecommerce implementations: listado, detalle, avance manual de etapa y baja.
        Route::get('ecommerce-implementation', [\App\Http\Controllers\Api\EcommerceImplementationController::class, 'index']);
        // Conteo de implementaciones de ecommerce listas para avanzar; antes del wildcard.
        Route::get('ecommerce-implementation/ready-to-advance-count', [\App\Http\Controllers\Api\EcommerceImplementationController::class, 'ready_to_advance_count']);
        Route::get('ecommerce-implementation/{ecommerce_implementation}', [\App\Http\Controllers\Api\EcommerceImplementationController::class, 'show']);
        Route::post('client/{client}/ecommerce-implementation/start', [\App\Http\Controllers\Api\EcommerceImplementationController::class, 'start']);
        Route::post('ecommerce-implementation/{ecommerce_implementation}/advance-stage', [\App\Http\Controllers\Api\EcommerceImplementationController::class, 'advance_stage']);
        Route::delete('ecommerce-implementation/{ecommerce_implementation}', [\App\Http\Controllers\Api\EcommerceImplementationController::class, 'destroy']);

        // Instalación/actualización del ecommerce (tienda-spa + tienda-api): job en cola +
        // endpoints de estado/logs para el polling del panel (prompts 583/584/585).
        Route::get('ecommerce-installations', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'index_json']);
        Route::get('client-ecommerce/{client_ecommerce}/installations', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'show_json']);
        Route::post('client-ecommerce/{client_ecommerce}/installations/start-install', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'start_install_json']);
        Route::post('ecommerce-installations/start-update', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'start_update_json']);
        Route::post('ecommerce-installations/start-install', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'start_install_for_client_json']);
        Route::get('ecommerce-installations/{installation}/logs', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'logs_json']);
        Route::delete('ecommerce-installations/{installation}', [\App\Http\Controllers\Api\EcommerceInstallationController::class, 'destroy_json']);

        // Configuración de implementaciones: admin asignado por defecto.
        Route::get('settings/implementation-assigned-admin', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'show']);
        Route::put('settings/implementation-assigned-admin', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update']);

        // Configuración de implementaciones: tiempo de espera para confirmar lista de empleados (Etapa 1).
        Route::get('settings/implementation-employees-wait', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_employees_wait']);
        Route::put('settings/implementation-employees-wait', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_employees_wait']);

        // Configuración de implementaciones: tiempo de espera para procesar archivos (Etapa 4).
        Route::get('settings/implementation-file-wait', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_file_wait']);
        Route::put('settings/implementation-file-wait', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_file_wait']);

        // Configuración de implementaciones: delay post-envío del formulario antes del contacto WhatsApp.
        Route::get('settings/implementation-form-contact-delay', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_form_contact_delay']);
        Route::put('settings/implementation-form-contact-delay', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_form_contact_delay']);

        Route::get('settings/implementation-google-cuota-default', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_google_cuota_default']);
        Route::put('settings/implementation-google-cuota-default', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_google_cuota_default']);

        // Configuración de implementaciones: API key de Google Custom Search para clientes reales.
        Route::get('settings/implementation-google-api-key-default', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_google_api_key_default']);
        Route::put('settings/implementation-google-api-key-default', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_google_api_key_default']);

        // Configuración de implementaciones: API key de Google Custom Search para demos.
        Route::get('settings/implementation-google-api-key-demo', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_google_api_key_demo']);
        Route::put('settings/implementation-google-api-key-demo', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_google_api_key_demo']);

        // Configuración de implementaciones: cuota de Google Custom Search por defecto para demos.
        Route::get('settings/implementation-google-cuota-demo', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_google_cuota_demo']);
        Route::put('settings/implementation-google-cuota-demo', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_google_cuota_demo']);

        // Configuración de implementaciones: URL base del formulario público de configuración.
        Route::get('settings/implementation-form-url', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_form_url']);
        Route::put('settings/implementation-form-url', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_form_url']);

        Route::get('task-template', [TaskTemplateController::class, 'index_json']);

        // Instalaciones iniciales de sistema para clientes.
        Route::get('installations', [ClientInstallationController::class, 'index_all']);
        // Creación global: cliente, API destino y versión se reciben explícitos en el body
        // (a diferencia de clients/{client}/installations, que fuerza la API activa y la última versión).
        Route::post('installations', [ClientInstallationController::class, 'store_global']);
        Route::get('clients/{client}/installations', [ClientInstallationController::class, 'index']);
        Route::post('clients/{client}/installations', [ClientInstallationController::class, 'store']);
        Route::get('client-installations/{installation}', [ClientInstallationController::class, 'show']);
        Route::delete('client-installations/{installation}', [ClientInstallationController::class, 'destroy']);
        Route::put('client-installations/{installation}/env-values', [ClientInstallationController::class, 'update_env_values']);
        Route::post('client-installations/{installation}/start', [ClientInstallationController::class, 'start']);

        // Plantilla base de variables .env: gestión y comparación con clientes.
        Route::get('env-template', [EnvTemplateController::class, 'index']);
        Route::post('env-template', [EnvTemplateController::class, 'store']);
        Route::post('env-template/bulk-update', [EnvTemplateController::class, 'bulk_update']);
        Route::post('env-template/check-diff/{client}', [EnvTemplateController::class, 'check_diff']);
        Route::post('env-template/apply-diff/{client}', [EnvTemplateController::class, 'apply_diff']);
        Route::post('env-template/check-diff-all/{client}', [EnvTemplateController::class, 'check_diff_all']);
        Route::post('env-template/apply-diff-all/{client}', [EnvTemplateController::class, 'apply_diff_all']);

        // Reportes diarios del agente analizador: listado, descarga y generación manual.
        Route::get('agent-report', [\App\Http\Controllers\Api\AgentReportController::class, 'index_json']);
        Route::post('agent-report/generate', [\App\Http\Controllers\Api\AgentReportController::class, 'generate_json']);
        Route::get('agent-report/{id}/download', [\App\Http\Controllers\Api\AgentReportController::class, 'download'])
            ->name('agent.report.download');

        // Propuestas del agente: listado, creación manual y aprobación/rechazo.
        Route::get('agent-proposal', [\App\Http\Controllers\Api\AgentProposalController::class, 'index_json']);
        Route::post('agent-proposal', [\App\Http\Controllers\Api\AgentProposalController::class, 'store_json']);
        Route::post('agent-proposal/{id}/approve', [\App\Http\Controllers\Api\AgentProposalController::class, 'approve_json']);
        Route::post('agent-proposal/{id}/reject', [\App\Http\Controllers\Api\AgentProposalController::class, 'reject_json']);

        // Configuración del agente: presupuesto Meta, hora del reporte y retención de archivos.
        Route::get('settings/agent', [\App\Http\Controllers\Api\AgentSettingsController::class, 'show']);
        Route::put('settings/agent', [\App\Http\Controllers\Api\AgentSettingsController::class, 'update']);

        // Chequeo y ejecución de seeders pendientes en producción.
        Route::get('pending-seeders', [\App\Http\Controllers\Api\PendingSeedersController::class, 'index']);
        Route::post('pending-seeders/run', [\App\Http\Controllers\Api\PendingSeedersController::class, 'run']);

    });
});

// Debug: control del tiempo virtual (solo accesible en local — el controller aborta 404 en producción)
Route::get('/debug/virtual-time', [DebugVirtualTimeController::class, 'show']);
Route::post('/debug/virtual-time', [DebugVirtualTimeController::class, 'set']);
Route::delete('/debug/virtual-time', [DebugVirtualTimeController::class, 'clear']);
