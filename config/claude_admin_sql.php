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
| resultado). Y hay dos tests de build que lo cotejan contra el esquema REAL
| (`Tests\Feature\AdminSqlDeSoloLecturaParaSoporteTest`):
|   a) toda tabla con una columna `value`, `payload` o terminada en `_values` tiene que estar en
|      `tablas_vedadas` o en `tablas_revisadas`, con su motivo. Una migración que agregue una tabla
|      así sin decidir acá qué se hace con ella ROMPE EL BUILD;
|   b) en toda tabla NO vedada, toda columna cuyo nombre matchee `patron_sensible` sale redactada.
|
| ⚠️ Las barreras son por NOMBRE (de tabla y de columna). Un secreto guardado en una columna con
| un nombre inocente no lo ve nadie: por eso una tabla nueva con secretos se agrega a
| `tablas_vedadas`, y una columna nueva con un secreto se nombra de modo que matchee el patrón
| (`*_token`, `*_secret`, `*_cifrada`, …) o se agrega su tabla acá.
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

];
