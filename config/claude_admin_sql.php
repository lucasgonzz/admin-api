<?php

/*
|--------------------------------------------------------------------------
| SQL de solo lectura sobre la base del admin: POST claude/support-codex/admin-sql
|--------------------------------------------------------------------------
| Misión cruzada `acceso-soporte-al-admin-lectura` (11/11/2026). Lucas pidió que soporte (Martín y
| cualquier sesión con la clave de ingesta) pueda consultar TODA la información del admin. «Toda»
| es todo dato operativo **menos credenciales y secretos**, que no salen nunca.
|
| 🔴 ESTE ARCHIVO ES LA REJA, NO DOCUMENTACIÓN. Lo leen `App\Services\ClaudeAdminSqlValidator`
| (rechaza la consulta antes de que toque MySQL) y `App\Services\ClaudeAdminSqlService` (redacta el
| resultado). Y hay tres tests de build que lo cotejan contra el esquema REAL
| (`Tests\Feature\AdminSqlDeSoloLecturaParaSoporteTest`):
|   a) toda tabla con una columna `value`, `payload` o terminada en `_values` tiene que estar en
|      `tablas_vedadas` o en `tablas_revisadas`, con su motivo;
|   b) en toda tabla NO vedada, toda columna cuyo nombre matchee `patron_sensible` o esté en
|      `columnas_reservadas` sale redactada;
|   c) 🔴 FAIL-CLOSED POR COLUMNA DE TEXTO: en toda tabla NO vedada, toda columna de tipo `json`,
|      `text` (cualquier tamaño) o `blob` tiene que matchear `patron_sensible`, o estar en
|      `columnas_reservadas`, o figurar en `columnas_de_texto_revisadas` con el motivo por el que es
|      legible. Una migración que agregue una columna de texto sin decidir acá ROMPE EL BUILD.
|
| POR QUÉ (c). La primera versión de esta reja era solo por nombre sensible, y era fail-open:
| `SELECT request, response FROM mensualidad_invoices` devolvía el SOAP crudo de ARCA con
| `<Auth><Token>…</Token><Sign>…</Sign>`. Ni la tabla estaba vedada, ni `request` suena a secreto.
| Un texto libre o un JSON pueden traer cualquier cosa; por eso ahora cada uno se decide a mano.
|
| ⚠️ Lo que sigue siendo por NOMBRE: una columna `varchar` con un secreto y un nombre inocente no la
| ve ningún test. Se la nombra de modo que matchee el patrón (`*_token`, `*_secret`, `*_cifrada`, …)
| o se la agrega a `columnas_reservadas`.
*/

return [

    /*
     | Largo máximo de la consulta, filas devueltas, largo de cada texto y tiempo de ejecución.
     | Son el contrato con el monitor (`docs/soporte-codex-contrato.md`): no se cambian sin avisar.
     */
    'max_caracteres'      => 12000,
    'max_filas'           => 200,
    'max_caracteres_valor' => 4000,
    'max_segundos'        => 8,

    /*
     | Nombre sensible. Se usa DOS veces, con la misma expresión:
     |   1. sobre el texto de la consulta (ya sin literales): si aparece, la consulta se rechaza.
     |      Nombrar `api_key` es la única forma de filtrar, ordenar o renombrar esa columna;
     |   2. sobre el nombre de cada columna del resultado (y cada clave de un JSON adentro de un
     |      valor): si matchea, el valor sale como `[dato reservado]`. Es lo que cubre `SELECT *`.
     |
     | Sin delimitadores ni flags: el que la usa la envuelve en `~...~i`.
     */
    'patron_sensible' => 'password|passwd|secret|token|api.?key|private.?key|session.?id|authorization|credential|clave.fiscal|encrypted|cifrad|p256dh|env_manual_values',

    /* Lo que se devuelve en lugar de un valor reservado. Igual que el diagnóstico del ERP. */
    'mascara' => '[dato reservado]',

    /*
     | Tablas que no se consultan: si el nombre aparece como palabra en la consulta (también en
     | SHOW COLUMNS / DESCRIBE), se rechaza con `tabla_vedada`. Cada una con su motivo, para que el
     | que revise la lista sepa por qué está y no la saque "porque no parece grave".
     */
    'tablas_vedadas' => [
        'admin_settings'                        => 'Clave/valor de configuración del admin: en `value` viven claves de proveedores (IA, WhatsApp, correo) mezcladas con ajustes inocentes, sin forma de separarlas por nombre de columna.',
        'env_templates'                         => 'Plantilla del .env de los clientes: `value` trae contraseñas y claves por defecto.',
        'env_change_batches'                    => 'Lotes de cambios de .env de producción: token de confirmación y valores de entorno.',
        'env_change_items'                      => 'Ítems de cambios de .env: el valor nuevo (cifrado) y la clave de entorno que se toca.',
        'client_ssh_credentials'                => 'Credenciales SSH del hosting de cada cliente.',
        'admin_push_subscriptions'              => 'Suscripciones Web Push de los administradores: `p256dh` y `auth` son material criptográfico del navegador.',
        'admin_calendar_connections'            => 'Conexión con Google Calendar: refresh token de la cuenta.',
        'personal_access_tokens'                => 'Tokens de Sanctum de los administradores.',
        'password_resets'                       => 'Tokens de recuperación de contraseña.',
        'sessions'                              => 'Sesiones del panel (si la tabla existe): el payload es la sesión entera.',
        'jobs'                                  => 'Cola: el payload serializa modelos y argumentos completos, incluidas credenciales en tránsito.',
        'failed_jobs'                           => 'Cola fallida: mismo payload que `jobs`, más la traza.',
        'job_batches'                           => 'Lotes de la cola: opciones serializadas.',
        'recall_configs'                        => 'Clave de la API de Recall y secreto del webhook.',
        'whatsapp_config'                       => 'Clave de Kapso, token del webhook de Meta y secreto del webhook.',
        'mensualidad_invoice_pdf_access_tokens' => 'Tokens de acceso público a los PDF de las facturas.',
        'recursos_links'                        => 'Links de acceso al centro de recursos: el token (cifrado y en hash) es la llave de entrada del cliente.',
        'recursos_sesiones'                     => 'Sesiones del centro de recursos: hash del token de sesión.',
        'support_codex_task_grants'             => 'Permisos de un solo uso de las tareas de Martín: hash del token.',
        'trial_instances'                       => 'Instancias de la prueba gratuita: contraseña (cifrada) del usuario de la prueba.',
        'implementation_messages'               => 'Conversación de la implementación: incluye el mensaje de ENTREGA DEL ACCESO, con el usuario y la contraseña del sistema del cliente. Aunque el resultado salga tapado, filtrando por texto (`body LIKE …`) se puede inferir el valor letra por letra: la redacción protege lo que se devuelve, no lo que se puede preguntar. La conversación se lee por `claude/implementations`.',
        'ecommerce_implementation_messages'     => 'Conversación de la implementación de la tienda: mismo caso que `implementation_messages` (entrega de accesos).',
    ],

    /*
     | Tablas con una columna `value`, `payload` o `*_values` que se REVISARON y se dejan consultar.
     | El test de build exige que toda tabla así esté arriba o acá. El motivo dice qué se miró.
     */
    'tablas_revisadas' => [
        'client_installations'               => 'La columna es `env_manual_values` (valores del .env cargados a mano en la instalación): matchea `patron_sensible`, así que no se puede nombrar y con `SELECT *` sale redactada. El resto de la fila (estado, versión, fechas, motivo de falla) es operativo.',
        'demo_installations'                 => 'Mismo caso que `client_installations`: `env_manual_values` queda cubierta por `patron_sensible`.',
        'support_codex_mission_fix_evidence' => '`payload` es la evidencia de una corrección integrada (commits, ramas, repos y pruebas) que ya viaja completa en el contexto del ticket (`mission_fix_evidence`); no guarda credenciales. Sus claves pasan igual por la redacción recursiva.',
    ],

    /*
     | NOMBRES de columna que nunca se sirven, en ninguna tabla. Se comportan igual que
     | `patron_sensible` en las tres capas: (1) si el nombre aparece como PALABRA en la consulta, se
     | rechaza con `campo_sensible`; (2) una columna del resultado con ese nombre sale con la máscara
     | (cubre `SELECT *`); (3) una clave de un JSON con ese nombre, a cualquier profundidad, también.
     |
     | Son globales a propósito: el resultado de una consulta no dice de qué tabla vino cada columna
     | (un alias o un JOIN la esconden), así que la única regla que se puede hacer cumplir es por
     | nombre. El costo: `datos` o `line` quedan reservadas en TODAS las tablas.
     */
    'columnas_reservadas' => [
        'request'                  => '`mensualidad_invoices.request`: el SOAP crudo que se le manda a ARCA, con `<Auth><Token>` y `<Sign>` del ticket de acceso.',
        'response'                 => '`mensualidad_invoices.response`: la respuesta cruda de ARCA. Cuerpo de un tercero, sin formato que controlemos.',
        'raw'                      => '`whatsapp_ad_referrals.raw`: el payload crudo del webhook de Meta.',
        'setup_data'               => '`clients.setup_data`: datos de configuración del sistema del cliente, de formato libre (incluye accesos).',
        'ecommerce_setup_data'     => '`client_ecommerces.ecommerce_setup_data`: configuración de la tienda recolectada por WhatsApp (medios de pago, accesos).',
        'approval_payload'         => '`support_codex_operations.approval_payload`: la operación completa pendiente de aprobación, con adjuntos en base64 y cuerpos sin enmascarar.',
        'env_manual_values'        => '`client_installations` / `demo_installations`: valores del .env cargados a mano. Ya matchea el patrón; está acá para que la lista sea completa.',
        'line'                     => '`deployment_logs`, `demo_installation_logs`, `ecommerce_deployment_logs`: la salida cruda de los pipelines por SSH (comandos, salida de artisan y de composer), que puede imprimir valores de entorno. Los logs se leen por sus endpoints de `claude/*`, que los recortan.',
        'log'                      => '`demo_updates.log`: la salida cruda de la actualización de una demo, misma clase que `line`.',
        'referencia_base64'        => '`ia_imagen_pedidos.referencia_base64`: la imagen que subió un usuario del cliente, en base64. No es texto y es contenido del cliente.',
        'datos'                    => '`demo_eventos_recibidos`, `landing_whatsapp_referencias`, `lead_atribucion_contactos`, `lead_atribucion_reservas`: payloads de afuera (landing, Meta, la demo) guardados tal cual: identificadores de clic, IP, user agent y lo que el origen mande.',
        'contexto_web'             => '`lead_eventos_meta.contexto_web`: IP, user agent y cookies de Meta (`fbp`, `fbc`) del visitante.',
        'accion_payload'           => '`agent_proposals.accion_payload`: JSON libre que arma el agente con la acción a ejecutar; no hay formato que lo acote.',
        'datos_de_soporte'         => '`agent_proposals.datos_de_soporte`: JSON libre del agente, misma clase que `accion_payload`.',
        'artifacts'                => '`support_codex_scheduled_upgrades.artifacts` (y la clave homónima dentro de `proof`): identificadores y ubicación de los ZIP del release.',
        'proof'                    => '`support_codex_release_claims.proof`: la prueba del release (commits, tags y artefactos). Lo útil para soporte está en las columnas de estado de la misma fila.',
        'calendar_snapshot'        => '`lead_messages.calendar_snapshot`: la agenda de Google de Lucas tal como se le pasó a la IA, con reuniones que no son del lead.',
        'accepted_endpoint_hashes' => '`support_attention_notifications.accepted_endpoint_hashes`: huellas de los endpoints Web Push de los administradores.',
        'data'                     => '`implementation_stages.data`, `ecommerce_implementation_stages.data` y `agent_implementation_stages.data` guardan credenciales (`admin_password`, el token del user setup, contraseñas de empleados). Estaban como legibles «cubiertas por la redacción por clave», y un `JSON_EXTRACT` las sacaba peladas bajo un alias. Se reserva el nombre: alcanza también a `implementation_followup_events.data` y `pipeline_activities.data`, que son limpias y se pierden a cambio de no depender de qué JSON es cuál. El estado de una implementación se lee por `claude/implementations`.',
        'detail'                   => '`support_codex_activities.detail`: JSON de formato libre que manda el monitor con lo que hizo Martín (puede incluir resultados de sus consultas). Alcanza a `implementation_followup_events.detail`.',
        'detalle'                  => '`local_actividad_clientes.detalle` y `prueba_whatsapp_envios.detalle`: detalle de formato libre de una actividad o de un envío.',
        'template_variables'       => '`lead_scheduled_messages.template_variables`: los valores con los que se completa una plantilla; van sin etiqueta (posicionales), así que si uno fuera un acceso ningún redactor de texto lo reconocería.',
        'variables'                => '`client_templates.variables`: misma clase que `template_variables`.',
        'clave'                    => 'Como clave de un JSON puede ser una contraseña. Alcanza también a `demo_chat_progresos.clave`, que no es un secreto: se pierde esa columna a cambio de no depender de qué guarda cada JSON.',
        'contrasena'               => 'Clave de JSON con una contraseña, escrita sin eñe.',
        'contraseña'               => 'Clave de JSON con una contraseña.',
        'pin'                      => 'Clave de JSON con un PIN.',
    ],

    /*
     | Columnas de texto libre o JSON que se REVISARON (11/11/2026, leyendo qué escribe el código en
     | cada una) y se dejan leer. Clave `tabla.columna`, valor el motivo. El test de build (c) exige
     | que toda columna json/text/blob de una tabla no vedada esté acá, en `columnas_reservadas` o
     | matchee el patrón.
     |
     | 🔴 CRITERIO PARA UNA COLUMNA JSON: es legible solo si, POR LO QUE ESCRIBE EL CÓDIGO, no
     | guarda credenciales ni datos de formato libre. «La cubre la redacción por clave» NO es un
     | motivo válido: la redacción es la segunda reja, no la primera.
     |
     | Que una columna sea legible no la saca de la redacción: sus valores pasan igual por el
     | redactor (claves de JSON sensibles o reservadas, etiquetas XML de credenciales, `Bearer`,
     | `clave=valor` y el resto de la línea después de «contraseña», «clave», «password», «pin»…).
     |
     | Está armado por grupos porque el motivo se repite; el resultado es un mapa plano.
     */
    'columnas_de_texto_revisadas' => array_merge(
        array_fill_keys([
            'lead_messages.content', 'lead_messages.edited_content', 'lead_scheduled_messages.content',
            'support_messages.body', 'support_messages.ai_body_before_template', 'support_messages.ai_original_body',
            'client_assistant_messages.texto',
            'demo_chat_mensajes.contenido', 'support_tickets.ai_pending_suggestion', 'whatsapp_ad_referrals.body',
            'whatsapp_ad_referrals.headline', 'lead_calls.transcript', 'lead_calls.call_summary', 'leads.call_summary',
            'recursos_preguntas.texto', 'recursos_preguntas.borrador', 'recursos_preguntas.respuesta', 'recursos_encuestas.mejora',
        ], 'Cuerpo de una conversación (con un lead, un cliente o el equipo). Es lo que soporte necesita leer; sale con «contraseña»/«clave» tapadas con la misma regla que el contexto del ticket.'),
        array_fill_keys([
            'admin_tasks.content', 'agenda_tasks.notes', 'client_employees.notes', 'client_support_contexts.ficha_operativa',
            'client_support_contexts.notas_internas', 'client_version_upgrades.notes', 'clients.cobranzas_observaciones',
            'implementations.notes', 'lead_partners.notes', 'leads.notes', 'leads.demo_summary', 'leads.demo_summary_structured',
            'lead_personalized_demo_videos.comments', 'lead_personalized_demo_videos.description', 'licencia_cuotas.observacion',
            'mensualidad_actualizaciones.observacion', 'mensualidad_pagos.observacion', 'mensualidad_periodos.observacion',
            'message_variants.notes', 'pipeline_activities.body', 'protocol_entries.notas_setter', 'user_id_blocks.notes',
            'ventas.observacion', 'support_tickets.escalation_reason', 'support_codex_operations.reconciliation_evidence',
            'support_codex_operations.reconciliation_reason', 'support_codex_scheduled_upgrades.verification_evidence',
            'agent_proposals.razonamiento', 'agent_daily_reports.executive_summary', 'lead_messages.ai_reasoning',
            'lead_messages.admin_notifications',
        ], 'Nota u observación interna escrita por el equipo o por un agente. Texto operativo; sale con «contraseña»/«clave» tapadas.'),
        array_fill_keys([
            'versions.description', 'ecommerce_versions.description', 'ia_app_versions.description', 'version_notifications.body',
            'version_commands.description', 'version_manual_tasks.description', 'version_seeders.description',
            'agent_identities.description', 'ai_system_prompts.contenido', 'client_templates.body_template',
            'client_templates.descripcion', 'followup_rules.descripcion', 'followup_templates.body_template',
            'followup_templates.components', 'followup_templates.meta_rechazo', 'implementation_stage_configs.description',
            'message_variants.body', 'pipelines.description', 'protocol_entries.descripcion', 'protocol_entries.mensaje_template',
            'recursos_videos.cuando_no', 'recursos_videos.para_que', 'recursos_videos.transcripcion', 'support_knowledge_base.content',
            'synced_github_files.content', 'task_templates.descripcion', 'support_codex_cases.mission',
        ], 'Contenido que escribe ComercioCity: novedades, descripciones, plantillas, prompts, base de conocimiento y archivos sincronizados del repo de conocimiento. No guarda datos de terceros ni credenciales.'),
        array_fill_keys([
            'client_assistant_messages.error', 'client_ecommerce_installations.failure_reason', 'client_installations.failure_reason',
            'client_upgrade_notices.error', 'client_version_upgrades.default_version_sync_message', 'clients.ai_plan_sync_message',
            'clients.pestanas_sync_message', 'clients.schedule_sync_message', 'demo_chat_conversaciones.ultimo_error',
            'demo_installations.failure_reason', 'implementation_mails.error', 'lead_eventos_meta.ultimo_error',
            'lead_messages.whatsapp_send_error', 'lead_messages.partial_send_pending', 'lead_scheduled_messages.error_text',
            'leads.demo_mail_last_error', 'leads.demo_setup_last_error', 'leads.followup_mail_last_error',
            'leads.presentation_mail_last_error', 'leads.user_setup_last_error', 'mensualidad_invoices.error_message',
            'update_commands.failure_notes', 'update_seeders.failure_notes',
            'client_assistant_messages.media_en_espera',
        ], 'Mensaje de error o de estado de un proceso (una línea de la excepción o del proveedor). Es lo primero que mira soporte; el redactor de texto tapa un Bearer o un `clave=valor` si el error los citara.'),
        array_fill_keys([
            'admin_column_preferences.properties', 'admin_tasks.todos', 'admins.cobranzas_preferencias',
            'agent_daily_reports.metrics_snapshot', 'clients.contract_clausulas_particulares',
            'clients.contract_financiacion', 'clients.contract_precios_sistemas', 'clients.contract_sistemas',
            'demo_chat_mensajes.guia', 'demo_chat_mensajes.revisiones', 'demo_chat_mensajes.videos',
            'demo_chat_progresos.segundos_vistos', 'implementation_followups.hitos', 'implementation_followups.hitos_no_aplican',
            'lead_messages.actions_override_log', 'lead_messages.applied_actions_summary', 'lead_messages.horarios_ofrecidos',
            'lead_messages.pending_actions', 'leads.contract_clausulas_particulares',
            'leads.contract_cotizacion_items', 'leads.contract_financiacion', 'leads.contract_precios_sistemas',
            'leads.contract_sistemas', 'leads.demo_plan', 'local_oficina_slots.agentes',
            'pipeline_stages.fields', 'pipelines.lost_reasons', 'recursos_progresos.vistos', 'recursos_videos.relacionados',
            'senas.aplicaciones', 'shared_product_facts.product', 'support_attention_notifications.dispatched_admin_ids',
            'support_codex_plans.pasos', 'support_codex_release_claims.develop', 'support_messages.erp_auto_reply',
            'task_templates.checklist', 'ventas.productos', 'ia_imagen_pedidos.prompt', 'ia_imagen_pedidos.uso_openai',
            'whatsapp_ad_referrals.source_url', 'whatsapp_ad_referrals.thumbnail_url', 'lead_eventos_meta.respuesta',
        ], 'JSON o texto con una estructura que arma el propio admin (preferencias, contrato, hitos, acciones, ids, conteos, URLs públicas). No recibe credenciales; sus claves pasan igual por la redacción recursiva.'),
        [
            'support_codex_mission_fix_evidence.payload' => 'Evidencia de una corrección integrada: commits, bases verificadas, informe y pruebas, con formato validado por el controlador. Ya viaja en el contexto del ticket.',
        ]
    ),

];
