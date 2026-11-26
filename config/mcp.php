<?php

/*
 | Conector MCP de sólo lectura (misión dots-openclaw, 25/11/2026).
 |
 | Deja que ChatGPT (y Dots, el agente de ChatGPT de Lucas) lea el estado de las misiones, del Workboard, de las
 | tareas de OpenClaw y de la Agenda del admin que autorizó. Es un servidor OAuth 2.1 mínimo propio (registro
 | dinámico de clientes, código con PKCE S256, tokens opacos guardados sólo como hash) más un endpoint MCP
 | `POST /api/mcp` (JSON-RPC por HTTP, sin sesión). NADA de lo que se expone escribe.
 |
 | 🔴 Todas las claves tienen un valor por defecto: el deploy del admin no toca el `.env` de producción, así que
 | el conector tiene que andar sin ninguna variable. Las variables sirven sólo para pisar el valor.
 */
return [

    /*
     | Interruptor general. Con `false` los metadatos, el registro, la autorización, el token y el endpoint MCP
     | contestan 404 (el conector queda como si no existiera).
     */
    'enabled' => (bool) env('MCP_ENABLED', true),

    /*
     | Issuer del servidor de autorización, SIN path. Es el origen público del admin-api: de acá salen todas las
     | URLs absolutas que se publican (nunca de `url()` ni del request, porque con `/public` Laravel cambia su base).
     */
    'issuer' => rtrim((string) env('MCP_ISSUER', 'https://api-admin.comerciocity.com'), '/'),

    /*
     | Recurso protegido: la URL del endpoint MCP. Es la audiencia de los tokens (el parámetro `resource` de
     | OAuth tiene que coincidir con este valor exacto).
     */
    'resource' => rtrim((string) env('MCP_RESOURCE', 'https://api-admin.comerciocity.com/api/mcp'), '/'),

    /*
     | Hosts permitidos para los `redirect_uri` de un cliente registrado (coincidencia exacta del host, sólo
     | https, sin fragmento). Hoy sólo el de ChatGPT.
     */
    'redirect_hosts' => ['chatgpt.com'],

    /*
     | Rutas permitidas de un `redirect_uri` (expresiones regulares sobre el path). Son las dos que documenta
     | OpenAI: el redirect estable de la plataforma de conectores y el de callback por conector.
     */
    'redirect_paths' => [
        '#^/connector_platform_oauth_redirect$#',
        '#^/connector/oauth/[A-Za-z0-9_-]{1,120}$#',
    ],

    /*
     | Emails de admin que pueden conectar el conector (separados por coma). Vacío = cualquier admin que pase la
     | política (no `solo_leads`, no `es_agente`).
     */
    'admin_emails' => (string) env('MCP_ADMIN_EMAILS', ''),

    /*
     | WhatsApp directo desde Dotin/ChatGPT (misión dotin-whatsapp-admin, 26/11/2026).
     |
     | 🔴 Nace APAGADO: con `whatsapp_enabled = false` los dos endpoints (`/api/mcp/whatsapp` y
     | `/api/mcp/whatsapp-envio`), sus metadatos y sus scopes no existen y los tokens de WhatsApp ya emitidos dejan
     | de servir. Se prende en el `.env` de producción (`MCP_WHATSAPP_ENABLED=true`) con el OK de Lucas.
     */
    'whatsapp_enabled' => (bool) env('MCP_WHATSAPP_ENABLED', false),

    /* Recurso (audiencia de los tokens) del endpoint de LECTURA de WhatsApp: scope `whatsapp.read`. */
    'whatsapp_read_resource' => rtrim((string) env('MCP_WHATSAPP_READ_RESOURCE', 'https://api-admin.comerciocity.com/api/mcp/whatsapp'), '/'),

    /* Recurso (audiencia de los tokens) del endpoint de ENVÍO de WhatsApp: scope `whatsapp.send`. */
    'whatsapp_send_resource' => rtrim((string) env('MCP_WHATSAPP_SEND_RESOURCE', 'https://api-admin.comerciocity.com/api/mcp/whatsapp-envio'), '/'),

    /*
     | Usuarios del admin (separados por coma) que pueden conectar los conectores de WhatsApp. 🔴 Vacío = NADIE
     | (falla cerrada): son los chats personales de Lucas.
     */
    'whatsapp_admin_emails' => (string) env('MCP_WHATSAPP_ADMIN_EMAILS', ''),

    /* Segundos de vida del código de confirmación de un envío (se prepara, Lucas confirma, se envía). */
    'whatsapp_confirm_ttl_seg' => 600,

    /* Cuentas que se pueden LEER por el conector (nunca `asistente`: son los dueños con su IA). */
    'whatsapp_cuentas_lectura' => ['personal', 'principal'],

    /* Cuentas desde las que se puede ENVIAR por el conector (el principal queda fuera: es de clientes y leads). */
    'whatsapp_cuentas_envio' => ['personal'],

    /* Scope de lectura que otorga el conector. */
    'scope' => 'comerciocity.read',

    /* Segundos de vida del código de autorización (un solo uso). */
    'ttl_code_seg' => 300,

    /* Segundos de vida del token de acceso. */
    'ttl_access_seg' => 3600,

    /* Días de vida del refresh token (se rota en cada uso). */
    'ttl_refresh_dias' => 30,

    /* Tamaño máximo del cuerpo de un POST a `/api/mcp`, en bytes (256 KiB). */
    'max_body_bytes' => 262144,

    /* Orígenes web que pueden llamar al endpoint MCP desde un navegador; sin `Origin` (servidor a servidor) pasa. */
    'allowed_origins' => ['https://chatgpt.com', 'https://chat.openai.com'],

    /*
     | Intentos fallidos de login en la pantalla de consentimiento antes de bloquear a esa combinación email + IP,
     | y minutos de bloqueo.
     */
    'login_max_intentos' => 5,
    'login_bloqueo_min'  => 15,
];
