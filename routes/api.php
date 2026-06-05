<?php

use App\Http\Controllers\AdminTaskController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminSearchProxyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ColumnPreferenceController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\ClientApiController;
use App\Http\Controllers\ClientEmployeeController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\CommonLaravel\UpdateController as MassUpdateController;
use App\Http\Controllers\Admin\ProtocolCacheController;
use App\Http\Controllers\AiSystemPromptController;
use App\Http\Controllers\WhatsappConfigController;
use App\Http\Controllers\WhatsappWebhookController;
use App\Http\Controllers\FollowupRuleController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProtocolEntryController;
use App\Http\Controllers\UpdateCommandController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UpdateSeederController;
use App\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

/*
| Webhook Kapso / WhatsApp (público, verificación por firma HMAC)
*/
Route::post('webhook/whatsapp', [WhatsappWebhookController::class, 'receive'])
    ->middleware('throttle:api');

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
| Admin SPA: token Sanctum (prefijo admin)
*/
Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

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
        Route::get('client/{id}', [ClientController::class, 'show_json']);
        Route::post('client', [ClientController::class, 'store_json']);
        Route::put('client/{id}', [ClientController::class, 'update_json']);
        Route::delete('client/{id}', [ClientController::class, 'destroy_json']);

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

        Route::get('lead', [LeadController::class, 'index_json']);
        Route::get('lead/unread-badges', [LeadController::class, 'unread_badges_json']);
        Route::get('lead/{id}', [LeadController::class, 'show_json']);
        Route::post('lead', [LeadController::class, 'store_json']);
        Route::put('lead/{id}', [LeadController::class, 'update_json']);
        Route::delete('lead/{id}', [LeadController::class, 'destroy_json']);
        Route::post('lead/{id}/send-presentation-mail', [LeadController::class, 'send_presentation_mail_json']);
        Route::post('lead/{id}/send-followup-mail', [LeadController::class, 'send_followup_mail_json']);
        Route::post('lead/{id}/run-demo-setup', [LeadController::class, 'run_demo_setup_json']);
        Route::post('lead/{id}/promote', [LeadController::class, 'store_promote_json']);
        Route::post('lead/{id}/promote-to-client', [LeadController::class, 'promote_to_client_json']);
        Route::post('lead/{id}/run-user-setup', [LeadController::class, 'run_user_setup_json']);
        Route::post('lead/{id}/send-demo-mail', [LeadController::class, 'send_demo_mail_json']);
        Route::post('lead/{id}/generate-contract', [LeadController::class, 'generate_contract_json']);
        Route::post('lead/{id}/messages', [LeadController::class, 'store_message_json']);
        Route::post('lead/{id}/mark-followup-suggestion-seen', [LeadController::class, 'mark_followup_suggestion_seen_json']);
        Route::post('lead/{id}/mark-whatsapp-messages-read', [LeadController::class, 'mark_whatsapp_messages_read_json']);
        Route::put('lead-message/{id}/approve', [LeadController::class, 'approve_message_json']);
        Route::put('lead-message/{id}/approve-with-edit', [LeadController::class, 'approve_message_with_edit_json']);
        Route::put('lead-message/{id}/reject', [LeadController::class, 'reject_message_json']);

        Route::get('followup-rule', [FollowupRuleController::class, 'index_json']);
        Route::put('followup-rule/{id}', [FollowupRuleController::class, 'update_json']);

        Route::get('ai-system-prompt', [AiSystemPromptController::class, 'index']);
        Route::put('ai-system-prompt', [AiSystemPromptController::class, 'update']);

        Route::post('protocol/refresh-cache', [ProtocolCacheController::class, 'refresh']);

        Route::get('whatsapp-config', [WhatsappConfigController::class, 'show']);
        Route::put('whatsapp-config', [WhatsappConfigController::class, 'update']);

        Route::get('protocol-entry', [ProtocolEntryController::class, 'index_json']);
        Route::post('protocol-entry', [ProtocolEntryController::class, 'store_json']);
        Route::patch('protocol-entry/{id}/toggle-activa', [ProtocolEntryController::class, 'toggle_activa']);
        Route::get('protocol-entry/{id}', [ProtocolEntryController::class, 'show_json']);
        Route::put('protocol-entry/{id}', [ProtocolEntryController::class, 'update_json']);
        Route::delete('protocol-entry/{id}', [ProtocolEntryController::class, 'destroy_json']);

        Route::get('demo', [DemoController::class, 'index_json']);
        Route::get('demo/{id}', [DemoController::class, 'show_json']);
        Route::post('demo', [DemoController::class, 'store_json']);
        Route::put('demo/{id}', [DemoController::class, 'update_json']);
        Route::delete('demo/{id}', [DemoController::class, 'destroy_json']);

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
        Route::post('update/{id}/commands/{command}/mark', [UpdateCommandController::class, 'mark_json']);

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
        Route::delete('implementation/{implementation}', [\App\Http\Controllers\Api\ImplementationController::class, 'destroy']);

        Route::post('client/{client}/implementation/start', [\App\Http\Controllers\Api\ImplementationController::class, 'start']);

        // Configuración de implementaciones: admin asignado por defecto.
        Route::get('settings/implementation-assigned-admin', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'show']);
        Route::put('settings/implementation-assigned-admin', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update']);

        // Configuración de implementaciones: tiempo de espera para procesar archivos (Etapa 4).
        Route::get('settings/implementation-file-wait', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'get_file_wait']);
        Route::put('settings/implementation-file-wait', [\App\Http\Controllers\Api\ImplementationSettingsController::class, 'update_file_wait']);

        Route::get('task-template', [TaskTemplateController::class, 'index_json']);

    });
});
