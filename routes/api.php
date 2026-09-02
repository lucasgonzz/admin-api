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
use App\Http\Controllers\ClientScheduleController;
use App\Http\Controllers\ComerciocityAfipConfigController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoEventosController;
use App\Http\Controllers\DemoExperienciaController;
use App\Http\Controllers\DemoMediaUrlsController;
use App\Http\Controllers\DemoInstallationController;
use App\Http\Controllers\DemoUpdateController;
use App\Http\Controllers\CommonLaravel\UpdateController as MassUpdateController;
use App\Http\Controllers\AiSystemPromptController;
use App\Http\Controllers\Api\ClientInstallationController;
use App\Http\Controllers\Api\EnvBulkChangeController;
use App\Http\Controllers\Api\EnvTemplateController;
use App\Http\Controllers\WhatsappConfigController;
use App\Http\Controllers\WhatsappWebhookController;
use App\Http\Controllers\RecallWebhookController;
use App\Http\Controllers\FollowupRuleController;
use App\Http\Controllers\FollowupTemplateController;
use App\Http\Controllers\LeadCallController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MetaRawWebhookController;
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
| Webhook CRUDO de Meta (modalidad `kind: meta` de Kapso), SOLO atribución Click-to-WhatsApp.
| Convive con el de arriba: los dos reciben el mismo mensaje, cada uno en su formato. Este no
| procesa mensajes ni crea leads — el bloque `referral` con el ctwa_clid solo viaja en el formato
| crudo, y es lo único que se persiste acá.
|
| 🔴 Autenticación por TOKEN en el path (o cabecera X-CC-Webhook-Token), no por firma: un webhook
| `kind: meta` no manda ninguna cabecera de firma. Ver MetaRawWebhookController.
|
| 🔴 Y limitador PROPIO, no `throttle:api`. El limitador `api` arma UNA sola cubeta por IP para
| todo /api, así que dejar este webhook ahí adentro le parte al medio la capacidad al webhook de
| Kapso —que sí crea leads y mensajes— porque Kapso ahora pega dos veces por cada mensaje entrante
| desde la misma IP. En una ráfaga el 429 le tocaría al que no puede perderlo. Por eso el
| withoutMiddleware(): el grupo `api` del Kernel ya trae `throttle:api` puesto.
*/
Route::post('webhook/meta-raw/{token?}', [MetaRawWebhookController::class, 'receive'])
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:meta-raw-webhook');

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
| Página inmersiva de demo (grupo 300, prompt 03): pública, sin auth:sanctum, identificada por
| el uuid del lead (no enumerable). GET arma el payload completo de la página; POST recibe las
| nueve respuestas del formulario de configuración. Ver App\Http\Controllers\DemoExperienciaController.
*/
Route::prefix('demo-experiencia')->group(function () {
    Route::get('{uuid}', [DemoExperienciaController::class, 'show_json']);
    Route::post('{uuid}/formulario', [DemoExperienciaController::class, 'store_formulario_json']);
    Route::post('{uuid}/ingresar', [DemoExperienciaController::class, 'ingresar_json']);
    // Progreso del lead sobre el video de introducción (misión 46). Público como los otros tres.
    Route::post('{uuid}/intro-progreso', [DemoExperienciaController::class, 'store_intro_progreso_json']);
});

/*
| Canal de ingesta de eventos de una instancia de demo (misión 48): fuera de auth:sanctum, con
| middleware propio. El header X-Demo-Eventos-Key ES el identificador del lead (no se manda
| lead_id en el body: dos fuentes para lo mismo pueden contradecirse). Ver
| App\Http\Middleware\DemoEventosKey y App\Http\Controllers\DemoEventosController.
*/
Route::middleware('demo.eventos.key')
    ->post('demo-eventos', [DemoEventosController::class, 'store_json']);

/*
| Hermano de LECTURA del canal de arriba (misión cruzada demo-panel-recorrido, 17/8/2026): la
| instancia pregunta el mapa actual de URLs de multimedia en vez de quedarse con la foto que le llegó
| en el payload del setup. Misma autenticación, mismo header, mismo 401. Ver
| App\Http\Controllers\DemoMediaUrlsController para el caso concreto que lo hizo falta.
*/
Route::middleware('demo.eventos.key')
    ->get('demo-media-urls', [DemoMediaUrlsController::class, 'index']);

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
| Ingesta y consultas de Claude (protegidas por X-Claude-Task-Key)
|--------------------------------------------------------------------------
| Tres grupos de rutas, todas contra el mismo middleware:
|   - Ingesta: tareas de admin y items de versión que Claude crea desde la conversación.
|   - Análisis: lectura de leads, mensajes y métricas, para que Claude analice el pipeline
|     comercial sin que Lucas tenga que exportar la base entera y pasársela por chat.
|   - Recuperación: envío de plantillas Meta a leads, con simulación obligatoria por defecto.
|
| 🔴 Las rutas de envío tocan leads REALES en producción. Los frenos (dry_run por defecto,
| confirm_count exacto, tope de lote y cooldown) están en ClaudeLeadsOutboundController.
*/
Route::middleware('claude.task.key')
    ->prefix('claude')
    ->group(function () {
        Route::get('admins', 'Api\ClaudeTaskIngestController@admins_json');
        Route::post('task', 'Api\ClaudeTaskIngestController@store_json');
        Route::get('draft-version', 'Api\ClaudeVersionItemsIngestController@draft_version_json');
        Route::post('version-items', 'Api\ClaudeVersionItemsIngestController@store_json');

        /* Análisis de leads: lectura. `schema` describe los filtros de todos los demás. */
        Route::get('schema', 'Api\ClaudeLeadsAnalyticsController@schema_json');
        Route::get('leads', 'Api\ClaudeLeadsAnalyticsController@leads_json');
        Route::get('leads/{id}/messages', 'Api\ClaudeLeadsAnalyticsController@lead_messages_json');
        Route::get('messages', 'Api\ClaudeLeadsAnalyticsController@messages_json');
        Route::get('metrics', 'Api\ClaudeLeadsAnalyticsController@metrics_json');
        Route::get('templates', 'Api\ClaudeLeadsAnalyticsController@templates_json');

        /* Recuperación de leads: envío de plantillas Meta. */
        Route::post('leads/{id}/send-template', 'Api\ClaudeLeadsOutboundController@send_template_json');
        Route::post('send-template-batch', 'Api\ClaudeLeadsOutboundController@send_template_batch_json');

        /* Mensaje de TEXTO LIBRE a un lead. Es la contraparte de send-template para el otro lado de
           la ventana de 24 hs de Meta: adentro de la ventana el texto libre sale y una plantilla
           queda fría; afuera, el texto libre no sale y lo único que llega es una plantilla aprobada.
           🔴 El freno que manda no es un cooldown sino la ventana misma, que resuelve
           WhatsappSessionWindowService (no se recalcula acá), más un mensaje por turno de
           conversación: respondido el lead una vez, el turno es de él. El porqué de haber
           descartado el cooldown de 24 hs de send-template está escrito en
           ClaudeLeadsOutboundController::hay_saliente_en_este_turno(). */
        Route::post('leads/{id}/message', 'Api\ClaudeLeadsOutboundController@send_message_json');

        /* Pipeline de leads: ESCRITURA del estado.
           🔴 Mueven leads REALES. `cerrado_ganado` no se asigna desde acá y un lead ya promovido a
           cliente no se toca: ese tramo es la promoción a Client. Los frenos (dry_run por defecto,
           confirm_count exacto y confirm_token del conjunto) están en ClaudeLeadsPipelineController.
           `leads/status-batch` se declara ANTES que cualquier ruta con {id} de este mismo bloque
           por la misma razón que `upgrades/preview`: que ninguna la capture. */
        Route::post('leads/status-batch', 'Api\ClaudeLeadsPipelineController@update_status_batch_json');
        Route::post('leads/{id}/status', 'Api\ClaudeLeadsPipelineController@update_status_json');

        /* Operación de clientes y actualizaciones: LECTURA.
           `ops-schema` describe todo este sub-bloque (filtros, enumeraciones, la máquina de estados
           del deployment y los frenos de escritura). 🔴 No se toca `schema`, que es el de leads. */
        Route::get('ops-schema', 'Api\ClaudeClientOpsController@ops_schema_json');
        /* Índice auto-descriptivo de TODO lo que Claude puede pedirle al admin, y lectura genérica
           por lista blanca. `catalog` DERIVA las rutas de las registradas y los modelos del config
           (no es una lista escrita a mano) y denuncia en `salud_del_catalogo` cualquier ruta
           claude/* que nadie haya descripto.
           🔴 `query` es SÓLO GET, y eso es la garantía mecánica de que no hay escritura genérica:
           un POST sobre esta ruta devuelve 405 de Laravel. Una escritura por nombre de modelo
           saltearía todos los frenos que están escritos endpoint por endpoint. */
        Route::get('catalog', 'Api\ClaudeCatalogController@index_json');
        Route::get('query', 'Api\ClaudeQueryController@index_json');
        Route::get('clients', 'Api\ClaudeClientOpsController@clients_json');
        Route::get('clients/{id}', 'Api\ClaudeClientOpsController@client_json');
        Route::get('clients/{id}/schedule', 'Api\ClaudeClientOpsController@client_schedule_json');
        /* 🔴 Carga de horarios sin pasar por el modal del admin. REEMPLAZA el conjunto entero: lo
           que no viaja en `dias` se borra. Los frenos (dry_run por defecto y confirm_client_name
           cuando dry_run es false) están en ClaudeClientOpsController; la regla del modelo y la
           transacción, en ClientScheduleReplacementService, que es el mismo que usa la SPA. */
        Route::put('clients/{id}/schedule', 'Api\ClaudeClientOpsController@update_schedule_json');
        /* Reintento del push de horarios al empresa-api del cliente. Idempotente y sin frenos:
           reenvía lo que el admin ya tiene y encola, nunca hace el HTTP adentro del request. */
        Route::post('clients/{id}/schedule/sync', 'Api\ClaudeClientOpsController@sync_schedule_json');
        Route::get('versions', 'Api\ClaudeClientOpsController@versions_json');
        Route::get('upgrades', 'Api\ClaudeClientOpsController@upgrades_json');
        Route::get('upgrades/{id}', 'Api\ClaudeClientOpsController@upgrade_json');
        Route::get('upgrades/{id}/logs', 'Api\ClaudeClientOpsController@upgrade_logs_json');

        /* Operación de clientes y actualizaciones: ESCRITURA.
           🔴 Estas nueve rutas crean actualizaciones sobre clientes REALES y arrancan deployments
           por SSH que pueden dejar un negocio sin sistema. Los frenos de las OCHO que viven en
           ClaudeUpgradeOpsController son confirm_client_name (en todas ellas), dry_run por defecto
           al crear, allow_deploy_to_active_api, el gate de horario del post-cierre —que también
           lleva retry-commands— y el umbral destructivo de expire-stuck.
           🔴 La novena, `upgrades/batch`, es la excepción y por eso se aclara aparte: vive en
           ClaudeUpgradeBatchController y NO usa confirm_client_name, porque en un lote de veinte no
           hay UN nombre que confirmar. Su equivalente es confirm_client_count + confirm_token.
           No leas este encabezado como si la cubriera: ver su comentario propio, abajo.
           `upgrades/preview` se declara ANTES que cualquier ruta con {id}, para que ninguna la
           capture si mañana se agrega un POST claude/upgrades/{id} a secas. */
        Route::post('upgrades/preview', 'Api\ClaudeUpgradeOpsController@preview_json');
        /* Alta EN LOTE, pegada a `preview` y ANTES que cualquier ruta con {id}, por lo mismo que
           aquélla. 🔴 Sólo CREA actualizaciones: no arranca ningún deployment, porque el gate de
           horario y allow_deploy_to_active_api son por cliente. Los frenos (tope de 25, dry_run por
           defecto, confirm_client_count y confirm_token) están en ClaudeUpgradeBatchController. */
        Route::post('upgrades/batch', 'Api\ClaudeUpgradeBatchController@store_batch_json');
        Route::post('upgrades', 'Api\ClaudeUpgradeOpsController@store_json');
        Route::post('upgrades/{id}/deploy/start', 'Api\ClaudeUpgradeOpsController@deploy_start_json');
        Route::post('upgrades/{id}/mark-crons', 'Api\ClaudeUpgradeOpsController@mark_crons_json');
        Route::post('upgrades/{id}/deploy/start-post-closure', 'Api\ClaudeUpgradeOpsController@deploy_start_post_closure_json');
        Route::post('upgrades/{id}/deploy/configure-system', 'Api\ClaudeUpgradeOpsController@deploy_configure_system_json');
        /* Reintento de comandos: espejo del botón del panel MÁS el gate de horario, porque
           `run_commands` corre sobre el sistema en uso del cliente. Destrabe de un deployment
           colgado: no reimplementa el vencimiento, llama al mismo VencerDeploymentsColgados que
           corre el scheduler, y exige el umbral DESTRUCTIVO (45 min), no el de reporte (15). */
        Route::post('upgrades/{id}/deploy/retry-commands', 'Api\ClaudeUpgradeOpsController@deploy_retry_commands_json');
        Route::post('upgrades/{id}/deploy/expire-stuck', 'Api\ClaudeUpgradeOpsController@deploy_expire_stuck_json');

        /* Tiendas (ecommerce) de los clientes: lectura y ACTUALIZACIÓN.
           🔴 Las dos rutas de escritura arrancan pipelines SSH reales contra el hosting de un
           negocio (compilan tienda-spa en el VPS de builds y suben SPA + API por SFTP). Los frenos
           —confirm_client_name en el de a uno, y dry_run por defecto + confirm_client_count +
           confirm_token + tope de 5 en el lote— están en ClaudeEcommerceOpsController.
           🔴 NINGUNA de estas rutas hace la instalación inicial de una tienda: sólo actualización.
           `ecommerce/updates/batch` se declara ANTES que cualquier ruta con {id}, por lo mismo que
           `upgrades/preview`: para que ninguna la capture si mañana se agrega un
           POST claude/ecommerce/updates/{id}. */
        Route::get('ecommerce/stores', 'Api\ClaudeEcommerceOpsController@stores_json');
        Route::get('ecommerce/installations', 'Api\ClaudeEcommerceOpsController@installations_json');
        Route::get('ecommerce/installations/{id}', 'Api\ClaudeEcommerceOpsController@installation_json');
        Route::get('ecommerce/installations/{id}/logs', 'Api\ClaudeEcommerceOpsController@installation_logs_json');
        Route::post('ecommerce/updates/batch', 'Api\ClaudeEcommerceOpsController@update_batch_json');
        Route::post('ecommerce/updates', 'Api\ClaudeEcommerceOpsController@update_json');

        /* Plantillas de CLIENTE (soporte). Idempotentes por `template_name`: reenviar la misma
           plantilla actualiza la fila, nunca crea una segunda, y nunca borra las que no vinieron
           en el payload. No tienen nada que ver con las de lead (`followup_templates`), que las
           levanta el motor de seguimiento automático y se le mandan a un lead. */
        Route::get('client-templates', 'Api\ClaudeClientTemplatesController@index_json');
        Route::post('client-templates', 'Api\ClaudeClientTemplatesController@store_json');

        /* Ficha de contexto por CLIENTE para el agente de soporte. Idempotente por `client_id`:
           reenviar la ficha actualiza la fila, nunca crea una segunda, y nunca borra las fichas
           que no vinieron en el payload.
           🔴 SON DOS CAMPOS Y NO SON EQUIVALENTES: `ficha_operativa` se inyecta en el prompt del
           agente en cada consulta sobre ese cliente; `notas_internas` NO se inyecta nunca y es
           para el operador humano. La separación es del esquema —dos columnas— y el camino que
           llega al prompt hace un SELECT de una sola, así que la nota no puede filtrarse al tono
           de una respuesta que se le manda a ese mismo cliente.
           ⚠️ El GET sí devuelve las dos, y no es contradicción: el consumidor prohibido es el
           prompt, no la sesión que carga las fichas, que necesita leer antes de pisar.
           🔴 Nada calculable se acepta en el payload (tickets abiertos, antigüedad, versión que
           corre, mensajes, escalados): eso lo lee SupportClientContextService de la base al armar
           el prompt. Guardarlo sería garantizar que quede viejo sin que nada lo denuncie. */
        Route::get('client-context', 'Api\ClaudeClientContextController@index_json');
        Route::post('client-context', 'Api\ClaudeClientContextController@store_json');

        /* Multimedia de la demo: el mismo GET/PUT que la pantalla /multimedia-demo del admin,
           pero para la sesion de Claude que produce los clips. Es el ultimo paso del pipeline de
           `/filmar` — sin esto, un clip publicado en R2 queda invisible para el lead porque nadie
           apunto su URL. La validacion y el guardado se delegan en Api\DemoMediaController: no
           hay dos definiciones de "slot valido". */
        Route::get('demo-media', 'Api\ClaudeDemoMediaController@index_json');
        Route::put('demo-media', 'Api\ClaudeDemoMediaController@update_json');

        /* Actualizar la VERSION de una demo. Es el hermano del bloque de upgrades de clientes, pero
           sobre la otra maquina: una demo no es un Client — tiene modelo propio (Demo) y pipeline
           propio (DemoUpdate -> RunDemoUpdateJob -> DemoUpdateService). Existe por el mismo motivo
           que demo-media: cuando un clip queda trabado por un arreglo que ya esta en develop, la
           sesion que filma no tenia forma de bajarlo a la instancia y el clip esperaba a que
           alguien apretara el boton en el panel. El POST devuelve 202 y encola: el pipeline SSH
           NUNCA corre adentro del request. */
        Route::get('demos', 'Api\ClaudeDemoOpsController@demos_json');
        Route::get('demo-updates', 'Api\ClaudeDemoOpsController@demo_updates_json');
        Route::get('demo-updates/{id}', 'Api\ClaudeDemoOpsController@demo_update_json');
        Route::post('demo-updates', 'Api\ClaudeDemoOpsController@store_json');

        /* Correr UN comando de artisan de la lista blanca sobre el servidor de una demo. Existe
           porque el pipeline de actualizacion hace seis etapas fijas y NO corre comandos sueltos,
           mientras que DeploymentService -el de los clientes- si tiene run_seeders y run_commands.
           Esa asimetria trabo el clip 4.4 (necesita demo:sembrar-trazabilidad, que ya esta en el
           servidor desde la 4.0.7) y los clips 1.7/1.8/2.10 (esperaban un queue:restart). La otra
           salida era el demo-setup, que arranca con migrate:fresh y le vacia la base a la
           instancia. Es SINCRONO: estos comandos tardan segundos, no minutos. */
        Route::post('demo-commands', 'Api\ClaudeDemoOpsController@run_command_json');
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
        // Certificados de AFIP: los mismos que el admin usa para facturar sus mensualidades y que
        // se instalan en cada cliente al instalar o actualizar su sistema.
        Route::get('comerciocity-afip-config/certificados', [ComerciocityAfipConfigController::class, 'certificados_json']);
        Route::post('comerciocity-afip-config/certificados', [ComerciocityAfipConfigController::class, 'upload_certificados_json']);

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
        /* Botón "Obtener datos" de la tarjeta Facturación: consulta el padrón de ARCA por CUIT y
           devuelve razón social, domicilio y condición IVA para completar el formulario. No guarda
           nada (el alta la confirma el "Guardar" de la tarjeta, que va por el PUT de arriba).
           El `{cuit}` se restringe a dígitos y separadores para que no se coma otra ruta del grupo. */
        Route::get('client/{clientId}/mensualidad/datos-afip/{cuit}', [ClientMensualidadController::class, 'datos_afip_por_cuit_json'])
            ->where('cuit', '[0-9\-\.]+');
        // Horarios comerciales del cliente: el PUT reemplaza el conjunto entero de días y rangos.
        Route::get('client/{clientId}/horarios', [ClientScheduleController::class, 'show_json']);
        Route::put('client/{clientId}/horarios', [ClientScheduleController::class, 'update_json']);
        /* Botón "Reintentar sincronización" de la pestaña Horarios: encola el push al empresa-api. */
        Route::post('client/{clientId}/horarios/sync', [ClientScheduleController::class, 'sync_json']);
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
        // Tarjetas de estado arriba de la grilla: cuántos leads hay en cada estado y cuántos de ellos
        // necesitan revisión (mismo criterio que el botón de revisión: sin responder o con error).
        // 🔴 Va ANTES de lead/{id}: si va después, {id} se come "status-cards".
        Route::get('lead/status-cards', [LeadController::class, 'status_cards_json']);
        Route::get('lead/{id}', [LeadController::class, 'show_json']);
        Route::post('lead', [LeadController::class, 'store_json']);
        Route::put('lead/{id}', [LeadController::class, 'update_json']);
        Route::delete('lead/{id}', [LeadController::class, 'destroy_json']);
        Route::post('lead/{id}/send-presentation-mail', [LeadController::class, 'send_presentation_mail_json']);
        Route::post('lead/{id}/send-followup-mail', [LeadController::class, 'send_followup_mail_json']);
        Route::post('lead/{id}/run-demo-setup', [LeadController::class, 'run_demo_setup_json']);
        // Edición manual de las respuestas del formulario de la demo desde el modal del lead
        // (misión del 27/8/2026). PUT y no POST: es una actualización idempotente del mismo
        // recurso —las nueve respuestas del lead—, no una acción que dispare un proceso.
        Route::put('lead/{id}/demo-form', [LeadController::class, 'update_demo_form_json']);
        // Disponibilidad de demos/horarios para el panel de verificación (prompt 321).
        Route::get('lead/{id}/panel-availability', [LeadController::class, 'panel_availability_json']);
        // Persistencia de toggles de automatización por lead desde el modal de operaciones (prompt 321).
        Route::patch('lead/{id}/automations', [LeadController::class, 'update_lead_automations_json']);
        /* Marca manual "el lead ya no recibe mensajes": saca sus entregas fallidas del rojo de la
           grilla, que está reservado para lo que se puede reintentar. Es un toggle. */
        Route::put('lead/{id}/toggle-no-recibe-mensajes', [LeadController::class, 'toggle_no_recibe_mensajes_json']);
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
        // Recorrido de la demo del lead (misión 49): plan congelado, hitos y progreso en una sola
        // llamada. Es de sólo lectura y lo poléa el panel cada 10s mientras el lead está adentro.
        Route::get('lead/{id}/demo-roadmap', [LeadController::class, 'demo_roadmap_json']);

        // Reemisión/revocación del token de ingreso a la demo (grupo 233, prompt 05): misma
        // autenticación/permisos que el resto de las acciones del panel del lead (auth:sanctum).
        Route::post('lead/{id}/demo-token/reemitir', [LeadController::class, 'reemitir_demo_token_json']);
        Route::post('lead/{id}/demo-token/revocar', [LeadController::class, 'revocar_demo_token_json']);

        // Override manual por lead de la dinámica de demo (grupo 293, prompt 03): permite pilotear
        // la experiencia nueva con leads elegidos a mano antes de abrirla a todos vía la setting global.
        Route::post('lead/{id}/demo-experiencia', [LeadController::class, 'set_demo_experiencia_json']);

        // Edición manual de la hora de fin de la demo (tarea 62): valida server-side (fin >
        // inicio, mismo día, demo vigente), corre el vencimiento del token y reprograma el check
        // de fin. Es la palanca HUMANA sobre demo_end_time; el canal del agente sigue sin poder
        // escribir ese campo (misión 47).
        Route::post('lead/{id}/demo-end-time', [LeadController::class, 'update_demo_end_time_json']);

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
        /* Reacción con emoji del panel sobre un mensaje del hilo (emoji vacío = quitar la reacción). */
        Route::put('lead-message/{id}/reaction', [LeadController::class, 'react_to_message_json']);

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

        // Web Push: clave pública VAPID + alta/baja/estado de la suscripción del device actual.
        Route::get('push/vapid-public-key', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'vapid_public_key_json']);
        Route::post('push/subscribe', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'store_json']);
        Route::post('push/unsubscribe', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'destroy_json']);
        // POST y no GET a propósito: el endpoint es una URL larga y no tiene por qué viajar en la query string.
        Route::post('push/subscription-status', [\App\Http\Controllers\Api\AdminPushSubscriptionController::class, 'subscription_status_json']);

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

        // Instalaciones desde cero del SISTEMA (ERP) de una demo.
        //
        // 🔴 No hay un `start` aparte como en client-installations: el POST crea la corrida Y
        // dispara el pipeline, cuya etapa run_demo_setup le hace migrate:fresh a la base de la
        // demo. Un endpoint de arranque separado permitiría re-arrancar la misma fila, o sea un
        // segundo migrate:fresh sobre una instancia que puede seguir sembrando.
        Route::get('demo-installation', [DemoInstallationController::class, 'index_json']);
        Route::get('demo-installation/{id}', [DemoInstallationController::class, 'show_json']);
        Route::post('demo-installation', [DemoInstallationController::class, 'store_json']);
        Route::delete('demo-installation/{id}', [DemoInstallationController::class, 'destroy_json']);

        Route::get('update', [UpdateController::class, 'index_json']);
        Route::post('update', [UpdateController::class, 'store_json']);
        // Antes de update/{id}: sin segmento variable, no colisiona (los demás POST
        // sobre update/{id} llevan un segmento fijo después del id).
        Route::post('update/preview', [UpdateController::class, 'preview_json']);
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
        // Va antes de support-ticket/{id}: si no, {id} se come "whatsapp-contacts".
        Route::get('support-ticket/whatsapp-contacts', [\App\Http\Controllers\Api\SupportTicketController::class, 'whatsapp_contacts']);
        // Igual que la de arriba: si quedara después de support-ticket/{id}, el {id} se comería
        // "contact-search" y el buscador del modal de alta contestaría 404.
        Route::get('support-ticket/contact-search', [\App\Http\Controllers\Api\SupportTicketController::class, 'contact_search']);
        Route::get('support-ticket/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'show']);
        Route::get('support-ticket/{id}/whatsapp-window', [\App\Http\Controllers\Api\SupportTicketController::class, 'whatsapp_window']);
        Route::post('support-ticket', [\App\Http\Controllers\Api\SupportTicketController::class, 'store']);
        Route::put('support-ticket/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'update']);
        Route::post('support-ticket/{ticket_id}/message', [\App\Http\Controllers\Api\SupportMessageController::class, 'store']);
        Route::post('support-ticket/{ticket_id}/suggest', [\App\Http\Controllers\Api\SupportAiSuggestionController::class, 'suggest']);
        Route::post('support-message/{id}/retry-remote-sync', [\App\Http\Controllers\Api\SupportMessageController::class, 'retry_remote_sync']);
        Route::post('support-message/{id}/mark-read', [\App\Http\Controllers\Api\SupportMessageController::class, 'mark_read']);
        Route::post('support-message/{id}/approve-ai-draft', [\App\Http\Controllers\Api\SupportMessageController::class, 'approve_ai_draft']);
        Route::post('support-message/{id}/discard-ai-draft', [\App\Http\Controllers\Api\SupportMessageController::class, 'discard_ai_draft']);
        Route::post('support-ticket/{id}/toggle-claude-auto-reply', [\App\Http\Controllers\Api\SupportTicketController::class, 'toggle_claude_auto_reply']);
        Route::post('support-ticket/{id}/toggle-requiere-verificacion', [\App\Http\Controllers\Api\SupportTicketController::class, 'toggle_requiere_verificacion']);
        Route::post('support-ticket/{ticket_id}/typing', [\App\Http\Controllers\Api\SupportMessageController::class, 'typing']);

        // Plantillas de cliente para la bandeja. `client-template` es un prefijo propio y
        // `send-client-template` cuelga de {id} con sufijo, así que ninguna se pisa con
        // support-ticket/{id}. El alta de estas plantillas NO está acá: la hace Claude desde
        // afuera, por el bloque claude/*.
        Route::get('client-template', [\App\Http\Controllers\Api\ClientTemplateController::class, 'index_json']);
        Route::post('support-ticket/{id}/send-client-template', [\App\Http\Controllers\Api\ClientTemplateController::class, 'send_to_ticket_json']);

        Route::get('support-knowledge-base', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'index']);
        Route::post('support-knowledge-base', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'store']);
        Route::put('support-knowledge-base/{id}', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'update']);
        Route::delete('support-knowledge-base/{id}', [\App\Http\Controllers\Api\SupportKnowledgeBaseController::class, 'destroy']);

        Route::get('settings/support-alert-minutes', [\App\Http\Controllers\Api\SupportAlertSettingsController::class, 'show']);
        Route::put('settings/support-alert-minutes', [\App\Http\Controllers\Api\SupportAlertSettingsController::class, 'update']);

        Route::get('settings/support-ai', [\App\Http\Controllers\Api\SupportAiSettingsController::class, 'show']);
        Route::put('settings/support-ai', [\App\Http\Controllers\Api\SupportAiSettingsController::class, 'update']);

        // Firma del PRESTADOR que se estampa en el PDF del contrato de un lead. Las cuatro van
        // dentro de auth:sanctum y no con URL firmada: la vista previa la pide la SPA con su
        // token, y un link firmado sobreviviría fuera de la sesión.
        Route::get('settings/contract-signature', [\App\Http\Controllers\Api\ContractSignatureController::class, 'show']);
        Route::post('settings/contract-signature', [\App\Http\Controllers\Api\ContractSignatureController::class, 'store']);
        Route::get('settings/contract-signature/file', [\App\Http\Controllers\Api\ContractSignatureController::class, 'file']);
        Route::delete('settings/contract-signature', [\App\Http\Controllers\Api\ContractSignatureController::class, 'destroy']);

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

        // Multimedia editable de la demo (grupo 300, prompt 02): un GET pinta toda la pantalla
        // (slots del catálogo sincronizado + URLs cargadas) y un PUT guarda/borra la URL de un slot.
        Route::get('demo-media', [\App\Http\Controllers\Api\DemoMediaController::class, 'index_json']);
        Route::put('demo-media', [\App\Http\Controllers\Api\DemoMediaController::class, 'update_json']);

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
        //
        // Desde el 31/8/2026 los dos endpoints de arranque (`start-install` y `start-update`)
        // aceptan `{ demo_id }` como alternativa a `{ client_id }`: es el mismo pipeline y las
        // mismas rutas, cambia solo el dueño de la tienda (ver la sección "DUEÑO DE LA TIENDA" de
        // EcommerceInstallationService). NO se agregaron rutas paralelas por demo a propósito:
        // dos rutas que terminan en el mismo servicio es la forma conocida de que una se quede
        // sin la guarda que se le agrega a la otra.
        //
        // `ecommerce-installations` acepta además `?owner=cliente|demo` para filtrar el listado
        // por tipo de dueño. Sin el parámetro devuelve todo, como siempre.
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
        // Credenciales que generó el aprovisionamiento del hosting, descifradas y BAJO DEMANDA.
        // 🔴 Endpoint aparte y no un campo del show: ClientApi::$hidden oculta provisioning_secrets
        // porque esa relación viaja en el index y en el show de instalaciones, de upgrades y de
        // clientes. Los secretos salen solo por acá, de a una API por vez y cuando alguien aprieta
        // el botón.
        Route::get('client-apis/{client_api}/hosting-credentials', [ClientInstallationController::class, 'hosting_credentials']);

        // Plantilla base de variables .env: gestión y comparación con clientes.
        Route::get('env-template', [EnvTemplateController::class, 'index']);
        Route::post('env-template', [EnvTemplateController::class, 'store']);
        Route::post('env-template/bulk-update', [EnvTemplateController::class, 'bulk_update']);
        Route::post('env-template/check-diff/{client}', [EnvTemplateController::class, 'check_diff']);
        Route::post('env-template/apply-diff/{client}', [EnvTemplateController::class, 'apply_diff']);
        Route::post('env-template/check-diff-all/{client}', [EnvTemplateController::class, 'check_diff_all']);
        Route::post('env-template/apply-diff-all/{client}', [EnvTemplateController::class, 'apply_diff_all']);

        // Cambio masivo de variables .env sobre varios clientes, en dos tiempos: previsualizar
        // (no escribe, devuelve el diff y un token) y aplicar (exige ese token). Es lo que consume
        // el conector MCP para operar por voz.
        Route::get('env-bulk/clients', [EnvBulkChangeController::class, 'clients']);
        Route::get('env-bulk/history', [EnvBulkChangeController::class, 'history']);
        Route::post('env-bulk/preview', [EnvBulkChangeController::class, 'preview']);
        Route::post('env-bulk/apply', [EnvBulkChangeController::class, 'apply']);

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
