<?php

/**
 * Configuración del acceso de agentes (Claude Code, OpenClaw) al panel del admin (misión admin-control-total).
 *
 * Un agente es un `Admin` con `es_agente = true` que entra por la misma API que admin-spa (`api/admin/*`)
 * con un token de Sanctum. El middleware `PanelAgentGuard` audita cada pedido suyo y frena las categorías
 * que duelen hasta que se confirme el pedido exacto. Sin secretos en este archivo.
 */
return [

    /*
    | Tope de pedidos por minuto de cada agente (cubeta propia, `panel-agent:{admin_id}`). El limitador `api`
    | del panel sigue contando al agente aparte y nunca es el que corta primero.
    */
    'rate_limit_per_minute' => (int) env('PANEL_AGENT_RATE_LIMIT_PER_MINUTE', 120),

    /*
    | Ventana del código de confirmación, en minutos. Se acepta la ventana actual y la anterior: un código
    | vale entre `ttl` y `2 * ttl` minutos y sólo para el mismo método, ruta y cuerpo.
    */
    'confirmation_ttl_minutes' => 15,

    /* Caracteres máximos que se guardan del extracto de la respuesta en la auditoría. */
    'response_excerpt_chars' => 2000,

    /* Caracteres máximos que se guardan del payload (query + body) en la auditoría. */
    'payload_max_chars' => 20000,

    /*
    | Las claves (de payload o de respuesta) que coinciden con este patrón se guardan como «[REDACTADO]».
    | No distingue mayúsculas.
    */
    'redact_key_pattern' => '/(pass|token|secret|key|clave|auth|private|cert|credential|cookie|session)/i',

    /* Categorías que no se ejecutan sin el código de confirmación del preview. */
    'braked_categories' => ['credenciales', 'borrado', 'masiva', 'plata', 'terceros'],

    /*
    | Categorías en orden de prioridad: gana la primera que coincide. Los patrones se evalúan sobre la clave
    | «MÉTODO ruta» (la ruta sin `api/admin/`). `except` saca de la categoría lo que coincide con alguno de
    | esos patrones. Si ninguna coincide: GET = lectura, el resto = escritura.
    | Los patrones por palabra hacen que una ruta NUEVA del panel con `send-`, `pago`, `deploy/`, etc. nazca
    | frenada sin tocar este archivo.
    */
    'categories' => [
        // Rutas que no tienen sentido para un agente (sesión propia, push del navegador, OAuth de calendario).
        'excluida' => [
            'patterns' => [
                '~^(POST|PUT) (logout|me)$~',
                '~^[A-Z]+ push/~',
                '~^GET calendar/google/\{admin_id\}/connect$~',
            ],
        ],
        // Usuarios, claves y accesos de clientes, certificados y variables de entorno.
        'credenciales' => [
            'patterns' => [
                '~^(POST|PUT|PATCH|DELETE) admin-user~',
                '~^[A-Z]+ client-api~',
                '~^[A-Z]+ client/\{clientId\}/apis~',
                '~^GET client-apis/\{client_api\}/hosting-credentials$~',
                '~^(POST|PUT) comerciocity-afip-config~',
                '~^PUT settings/implementation-(google-api-key|serper-api-key|zipnova|mercadopago)~',
                '~^PUT whatsapp-config$~',
                '~^(POST|PUT|PATCH) env-template(/bulk-update)?$~',
                '~/env-values$~',
            ],
        ],
        'borrado' => [
            'patterns' => [
                '~^DELETE ~',
                '~^POST .*/(borrar|eliminar|revocar)$~',
            ],
        ],
        'masiva' => [
            'patterns' => [
                '~^POST mass-update/~',
                '~^POST lead/batch-recover-unanswered$~',
                '~^POST env-bulk/apply$~',
                '~^POST env-template/apply-diff-all/~',
            ],
        ],
        'plata' => [
            'patterns' => [
                '~^(POST|PUT|PATCH) .*(mensualidad|factura|/pagos?\b|licencias|ventas|cuotas|cobro-dudoso|comision|senas|link-pago|contrato)~',
                '~^(POST|PUT) (client/\{client\w*\}/)?ai(-image)?-plan~',
            ],
        ],
        // POST que sólo leen (búsquedas, previews): van antes de «terceros» para no frenarse por error.
        'lectura' => [
            'patterns' => [
                '~^POST search(-from-modal)?/~',
                '~^POST update/preview$~',
                '~^POST env-bulk/preview$~',
                '~^POST client/suggest-subdomain$~',
                '~^POST env-template/check-diff~',
                '~^POST env-template/probar-clave$~',
            ],
        ],
        // Mensajes y mails a leads y clientes, deploys, instalaciones y sincronizaciones hacia sistemas de clientes.
        'terceros' => [
            'patterns' => [
                '~^(POST|PUT|PATCH) .*(send-|-mail$|/messages$|/message$|/enviar$|deploy/|/sync$|start|advance-stage|actions/\{action\}$|apply-diff|run-|promote|approve|recall-bot|calls/|offer-demo|avisar|prueba/instancias/|extensiones/acceso|agentes-ia$|link-dueno|pending-seeders/run|demo-token/reemitir|force-|check-demo|simulate-inbound|resume-with-claude|retry|reaction|modelos-ia|candado-sesion$|horarios$|followup-template|scheduled-messages|whatsapp-onboarding)~',
                '~^POST (clients/\{client\}/)?installations$~',
                '~^POST demo-installation$~',
                '~^POST support-ticket$~',
            ],
            'except' => [
                '~cancel-auto-send~',
                '~^POST calendar/google/~',
            ],
        ],
    ],

    /* Por qué frena cada categoría (aparece en el preview y en el catálogo). */
    'reasons' => [
        'credenciales' => 'Toca o devuelve claves, accesos, usuarios o certificados: se confirma el pedido exacto antes de ejecutarlo.',
        'borrado'      => 'Borra o revoca datos y no se puede deshacer: se confirma el pedido exacto antes de ejecutarlo.',
        'masiva'       => 'Cambia muchos registros o sistemas de una vez: se confirma el pedido exacto antes de ejecutarlo.',
        'plata'        => 'Mueve plata (mensualidades, facturas, pagos, licencias, comisiones): se confirma el pedido exacto antes de ejecutarlo.',
        'terceros'     => 'Llega a clientes, leads o sistemas de terceros (mensajes, mails, deploys, instalaciones): se confirma el pedido exacto antes de ejecutarlo.',
        'excluida'     => 'Operación sin sentido para un agente (sesión propia, notificaciones push o calendario del navegador): nunca se ejecuta.',
    ],

    /*
    | Notas por operación, con la clave exacta «MÉTODO ruta» (ruta sin `api/admin/`). Se muestran en el
    | catálogo para que el agente no tropiece con una trampa conocida.
    */
    'notes' => [
        'PUT ai-system-prompt' => 'Se pisa cada 10 minutos por agent-prompts:sync; el prompt se edita en el repo de conocimiento, no acá.',
        'PUT settings/agent-identity' => 'Se pisa cada 10 minutos por agent-prompts:sync; la identidad se edita en el repo de conocimiento, no acá.',
    ],
];
