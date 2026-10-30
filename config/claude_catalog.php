<?php

/*
|--------------------------------------------------------------------------
| Lo DECLARADO del catálogo de GET claude/catalog
|--------------------------------------------------------------------------
| 🔴 Este archivo NO es la lista de rutas. La lista de rutas la deriva
| ClaudeCatalogService::rutas_registradas() de `app('router')->getRoutes()`,
| que es la única fuente que no puede mentir. Los modelos de `/query` los
| deriva de `config/claude_query.php`, que es el mismo archivo que sirve las
| consultas.
|
| Acá vive lo único que NO se puede derivar: para qué sirve cada endpoint, si
| escribe, qué tan peligroso es y qué frenos tiene. Sacarlo del docblock por
| reflexión sería frágil de verdad, y sacarlo del nombre de la ruta sería
| adivinar.
|
| 🔴 CÓMO SE NOTA QUE ESTE ARCHIVO QUEDÓ VIEJO. La clave de cada entrada es
| `"MÉTODO api/claude/uri"`, exactamente como la registra el router (el grupo
| mete el prefijo `api`, así que `$route->uri()` devuelve `api/claude/...`).
| ClaudeCatalogService::cotejar() compara las dos listas y publica el resultado
| en `salud_del_catalogo`:
|   - una ruta viva que nadie describió cae en `sin_descripcion` y se sirve
|     igual, con `para_que: null`. El catálogo denuncia su propio desactualizado
|     en vez de romperse;
|   - una entrada de acá que apunta a una ruta borrada o renombrada cae en
|     `declaradas_que_ya_no_existen`.
| Y arriba de eso hay un test que afirma que las dos listas están vacías, así
| que agregar una ruta `claude/*` sin describirla acá ROMPE EL BUILD.
|
| ⚠️ El test y el controlador llaman al MISMO método del servicio. Si el test
| recorriera las rutas por su cuenta habría dos definiciones de "las rutas de
| Claude" y se desincronizarían, que es exactamente la clase de error que este
| catálogo viene a matar.
*/

return [

    'auth' => [
        'header'     => 'X-Claude-Task-Key',
        'middleware' => 'claude.task.key',
        'nota'       => 'Fail-closed: si CLAUDE_TASK_INGEST_KEY está vacía en el .env, TODO claude/* devuelve 401 en vez de quedar abierto. La comparación es con hash_equals, no con ===.',
    ],

    'rate_limit' => [
        'grupo' => 'api',
        'nota'  => 'El limitador del grupo api agrupa por usuario de Sanctum y, sin usuario, por IP: claude/* cae siempre en el balde de la IP. Conviene hacer pocas llamadas grandes (limit alto, include) y no muchas chicas. El tope sale de API_RATE_LIMIT_PER_MINUTE y por defecto es 60 fuera de local.',
    ],

    /*
     | Indexado por "MÉTODO api/claude/uri", tal cual lo registra el router.
     |
     | `peligrosidad`: lectura | baja | media | alta.
     |   - lectura: no escribe nada.
     |   - baja: escribe filas del admin y nada más. No toca el sistema de ningún cliente.
     |   - media: escribe filas que gobiernan un deployment, pero no lo arranca.
     |   - alta: arranca o modifica un pipeline que corre por SSH sobre el hosting de un negocio.
     |
     | 🔴 LA ESCALA MIDE EL DAÑO SOBRE EL SISTEMA DE UN CLIENTE, Y ESO DEJA UN EJE AFUERA: el
     | daño sobre una PERSONA. `POST claude/leads/{id}/send-template`, `POST claude/leads/{id}/message`
     | y `POST claude/followup-rules` son todas `baja` con toda razón —escriben filas del admin y
     | ningún negocio se entera— y las tres terminan en un WhatsApp a alguien real; la última, sin
     | que nadie apriete nada, porque cambia la cadencia con la que el cron le escribe a TODOS los
     | leads de un estado. No se agregó un nivel nuevo para no reetiquetar rutas que ya están en
     | uso y que ninguna sesión leería de nuevo: lo que gobierna ese riesgo son los `frenos`, que
     | en esas tres son los más duros del bloque. Si algún día se le suma un eje a la escala,
     | éste es el que falta.
     |
     | 🔴 `frenos` NO puede estar vacío en una ruta con `escribe => true`. Hay un test que lo
     | afirma: una escritura sin ningún freno declarado es o un error de este archivo o un
     | endpoint que hay que revisar.
     |
     | 🔴 `parametros` TAMPOCO puede estar vacío en una ruta con `escribe => true`, y hay un
     | segundo test que lo afirma. El motivo es concreto: hasta que se agregó, el catálogo
     | publicaba método, ruta, para qué sirve y frenos, y NINGÚN parámetro. Para
     | `POST claude/upgrades/batch`, `to_version_id` —que es obligatorio— no aparecía en ninguna
     | parte del catálogo, así que la única forma de enterarse de los parámetros de un POST que
     | arranca SSH sobre el hosting de un negocio era mandar uno mal y leer el 422. Eso rompía
     | la promesa central de este endpoint: "un request y sé todo lo que puedo pedir". Para
     | `/query` estaba cumplida (los filtros se derivan del config); para las escrituras, no.
     |
     | Forma de cada parámetro: `nombre`, `obligatorio` (bool), `validacion` (la regla de Laravel
     | REAL del controlador, copiada tal cual) y `que_es` (una línea).
     |
     | ⚠️ ESTO SE ESCRIBE A MANO Y POR LO TANTO PUEDE MENTIR, que es peor que faltar. Cada
     | entrada de acá se verificó contra el `validate()` / `validar_o_422()` del controlador el
     | 28/8/2026. Si tocás una regla de validación, tocá también esta lista: un catálogo que
     | miente hace perder más tiempo que uno incompleto, porque el que lo lee deja de dudar.
     |
     | ⚠️ Las rutas de LECTURA todavía no declaran `parametros`: se publican con `null`, no con
     | `[]`, para que "no está declarado" no se lea como "no tiene". Sus filtros están descriptos
     | en `GET claude/schema` (leads) y `GET claude/ops-schema` (clientes y actualizaciones).
     */
    'endpoints' => [

        'GET api/claude/support-codex/settings' => [
            'para_que' => 'Estado del puente de soporte local con Codex y fecha de corte de mensajes anteriores.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => [],
        ],
        'PUT api/claude/support-codex/settings' => [
            'para_que' => 'Activa o pausa el circuito de soporte desde la suscripción de Codex. La primera activación fija el corte sin tomar backlog.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => ['Requiere enabled explícito; no toca leads ni el número del asistente.'],
            'parametros' => [
                ['nombre' => 'enabled', 'obligatorio' => true, 'validacion' => 'required|boolean', 'que_es' => 'Activa o pausa el puente.'],
            ],
        ],
        'GET api/claude/support-codex/pending' => [
            'para_que' => 'Lista hasta 50 tickets con mensajes nuevos o confirmación pendiente vencida, sin reservas ocupadas ni envíos ambiguos.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => [],
        ],
        'GET api/claude/support-codex/tickets/{id}' => [
            'para_que' => 'Contexto del ticket: cliente, últimos 100 mensajes reales, adjuntos, misión preparada, ventana WhatsApp, la implementación del cliente y previous_tickets (resumen de hasta 5 tickets anteriores, de id menor que el actual: id, status, opened_at, closed_at, mensajes; el detalle con GET .../history); en los mensajes del equipo lo que sigue a «contraseña»/«clave» llega como [oculta]; sin claves.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => [],
        ],
        'GET api/claude/support-codex/tickets/{id}/history' => [
            'para_que' => 'Los tickets ANTERIORES del cliente del ticket (id menor que el actual; hasta 5, el más nuevo primero, en cualquier estado) con su contacto y sus últimos 30 mensajes reales (orden cronológico, sin borradores) y adjuntos, para saber dónde quedó una conversación ya cerrada. Si el contacto del ticket es un empleado, solo los tickets de ese empleado; si es el dueño, todos. El download_path de cada adjunto apunta a la ruta de adjuntos de SU ticket. En los mensajes del equipo (sender_type admin) lo que sigue a «contraseña»/«clave» llega como [oculta]. Solo lectura, sin reserva; el cliente sale del ticket, nunca de un parámetro.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => [],
        ],
        'POST api/claude/support-codex/tickets/{id}/implementation-note' => [
            'para_que' => 'Martín deja asentada una nota en la implementación del SISTEMA en curso del cliente del ticket («mandó la lista de precios», «queda en mandar las fotos el lunes»): solo AGREGA a data.actions[] de la etapa {action:"nota", stage, at, canal:"whatsapp", origen:"martin", texto, support_ticket_id}. No cambia estados ni etapas ni manda nada. Responde {registrada, implementation_id, etapa, at}: 201 con registrada=true, o 200 con registrada=false si ya estaba.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => [
                'Reserva vigente del ticket (lease_token): si no, 423 invalid_lease.',
                'El cliente y la implementación salen del ticket, nunca del body. Sin implementación del sistema in_progress: 409 sin_implementacion_abierta. Etapa sin fila: 422 sin_etapa.',
                'Idempotente: el mismo texto de Martín, del mismo ticket, en la misma etapa, no se duplica (200, registrada=false, con el at de la que ya estaba).',
            ],
            'parametros' => [
                ['nombre' => 'lease_token', 'obligatorio' => true, 'validacion' => 'required|string|min:32|max:128', 'que_es' => 'Reserva vigente de este ticket.'],
                ['nombre' => 'texto', 'obligatorio' => true, 'validacion' => 'required|string|min:1|max:1000', 'que_es' => 'La nota.'],
                ['nombre' => 'etapa', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|integer|between:1,7', 'que_es' => 'La etapa donde se anota. Por defecto la actual de la implementación.'],
            ],
        ],
        'GET api/claude/support-codex/tickets/{id}/attachments/{attachment_id}' => [
            'para_que' => 'Descarga autenticada de un adjunto que pertenece a ese ticket, para imágenes o transcripción local de audios.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => [],
        ],
        'GET api/claude/support-codex/operations/{operation_id}' => [
            'para_que' => 'Recupera resultado durable de un envío; una entrega ambigua nunca habilita un reintento automático.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => [],
        ],
        'POST api/claude/support-codex/operations/{operation_id}/reconcile' => [
            'para_que' => 'Concilia manualmente una entrega incierta tras verificarla, sin reenviar ni cerrar automáticamente el ticket.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => ['Decisión expresa de Lucas, motivo y evidencia/ID WhatsApp para confirmar envío; ninguna reserva vigente.'],
            'parametros' => [
                ['nombre' => 'outcome', 'obligatorio' => true, 'validacion' => 'required|in:sent,not_sent', 'que_es' => 'Resultado verificado manualmente.'],
                ['nombre' => 'reason', 'obligatorio' => true, 'validacion' => 'required|string|min:10|max:2000', 'que_es' => 'Motivo auditado de la conciliación.'],
                ['nombre' => 'confirmed_by', 'obligatorio' => true, 'validacion' => 'required|in:Lucas González', 'que_es' => 'Decisión explícita de Lucas, no del cliente ni del agente.'],
                ['nombre' => 'whatsapp_message_id', 'obligatorio' => false, 'validacion' => 'required_if:outcome,sent|nullable|string|max:255', 'que_es' => 'ID del envío confirmado, obligatorio para sent.'],
                ['nombre' => 'evidence', 'obligatorio' => false, 'validacion' => 'required_if:outcome,sent|nullable|string|min:15|max:3000', 'que_es' => 'Evidencia de la verificación, obligatoria para sent.'],
            ],
        ],
        'POST api/claude/support-codex/tickets/{id}/claim' => [
            'para_que' => 'Reserva por 15 minutos un ticket para un worker, o renueva su reserva vinculando la conversación de Codex.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => ['Bloqueo de fila, último mensaje esperado, token secreto y fecha de vencimiento.'],
            'parametros' => [
                ['nombre' => 'worker_id', 'obligatorio' => true, 'validacion' => 'required|string|max:150', 'que_es' => 'Identidad del monitor local.'],
                ['nombre' => 'expected_message_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'Último mensaje real esperado.'],
                ['nombre' => 'lease_token', 'obligatorio' => false, 'validacion' => 'sometimes|string|min:32|max:128', 'que_es' => 'Token anterior, solo para renovar.'],
                ['nombre' => 'thread_id', 'obligatorio' => false, 'validacion' => 'sometimes|string|max:150', 'que_es' => 'Conversación persistente en Codex.'],
            ],
        ],
        'POST api/claude/support-codex/tickets/{id}/reply' => [
            'para_que' => 'Envía una respuesta de Martín, pide confirmación, guarda una misión para Lucas o cierra después de confirmación clara del cliente.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => ['Reserva vigente, mensaje actual, clave idempotente durable, ventana WhatsApp y cierre solo con confirmación. No ejecuta misiones.'],
            'parametros' => [
                ['nombre' => 'lease_token', 'obligatorio' => true, 'validacion' => 'required|string|min:32|max:128', 'que_es' => 'Reserva de este ticket.'],
                ['nombre' => 'expected_message_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'Último mensaje sobre el que se responde.'],
                ['nombre' => 'operation_id', 'obligatorio' => true, 'validacion' => 'required|string|max:120|regex:/^[a-zA-Z0-9_-]+$/', 'que_es' => 'Clave idempotente que no se reutiliza para otro contenido.'],
                ['nombre' => 'action', 'obligatorio' => true, 'validacion' => 'required|in:responder,solicitar_confirmacion,cerrar,escalar', 'que_es' => 'Transición del caso.'],
                ['nombre' => 'body', 'obligatorio' => true, 'validacion' => 'required|string|max:3500', 'que_es' => 'Texto al cliente.'],
                ['nombre' => 'mission', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:30000', 'que_es' => 'Misión preparada; obligatoria para escalar, nunca ejecutada acá.'],
                ['nombre' => 'thread_id', 'obligatorio' => false, 'validacion' => 'sometimes|string|max:150', 'que_es' => 'Conversación persistente en Codex.'],
                ['nombre' => 'confirmation_message_id', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|integer|min:1', 'que_es' => 'Mensaje del cliente con confirmación clara, obligatorio al cerrar.'],
            ],
        ],
        'POST api/claude/support-codex/tickets/{id}/release' => [
            'para_que' => 'Libera una reserva y opcionalmente pausa el caso para revisión humana.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => ['Exige el token vigente del ticket; no borra la conversación.'],
            'parametros' => [
                ['nombre' => 'lease_token', 'obligatorio' => true, 'validacion' => 'required|string|min:32|max:128', 'que_es' => 'Reserva actual.'],
                ['nombre' => 'thread_id', 'obligatorio' => false, 'validacion' => 'sometimes|string|max:150', 'que_es' => 'Vincula la conversación sin enviar mensaje.'],
                ['nombre' => 'reason', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:500', 'que_es' => 'Motivo visible de la pausa.'],
                ['nombre' => 'manual_review', 'obligatorio' => false, 'validacion' => 'sometimes|boolean', 'que_es' => 'True mantiene el caso fuera de la cola automática.'],
            ],
        ],
        'POST api/claude/support-codex/tickets/{id}/resume' => [
            'para_que' => 'Acción expresa de Lucas para retomar el caso sin responder backlog, o pedir prueba después de terminar una misión.',
            'escribe' => true, 'peligrosidad' => 'baja',
            'frenos' => ['Exige mensaje actual, ninguna reserva ocupada ni envío ambiguo. No manda un mensaje por sí solo.'],
            'parametros' => [
                ['nombre' => 'expected_message_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'Último mensaje observado.'],
                ['nombre' => 'reason', 'obligatorio' => true, 'validacion' => 'required|string|max:500', 'que_es' => 'Motivo de la decisión explícita.'],
                ['nombre' => 'request_confirmation', 'obligatorio' => false, 'validacion' => 'sometimes|boolean', 'que_es' => 'Pide prueba al cliente dentro de la ventana.'],
            ],
        ],
        'POST api/claude/support-codex/tickets/{id}/diagnostic' => [
            'para_que' => 'Consulta de solo lectura por keywords al ERP del cliente del ticket; sin IA API ni secretos en la respuesta.',
            'escribe' => false, 'peligrosidad' => 'lectura', 'frenos' => ['Reserva vigente y cliente/URL resueltos exclusivamente del ticket.'],
        ],

        /* ---------------------------------------------------------- Auto-descripción */

        'GET api/claude/catalog' => [
            'para_que'     => 'Este índice: qué endpoints existen hoy, para qué sirve cada uno, qué frenos tiene, y toda la superficie de GET claude/query (modelos, columnas, filtros, relaciones y los modelos excluidos con el motivo). Las rutas se derivan de las registradas, no de una lista escrita a mano.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/ops-schema' => [
            'para_que'     => 'Auto-descripción del sub-bloque de clientes y actualizaciones: filtros, enumeraciones, la máquina de estados del deployment y los frenos de escritura. Es el schema viejo y sigue siendo el más detallado de esa área.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/schema' => [
            'para_que'     => 'Auto-descripción del bloque de leads: filtros válidos, estados del pipeline, valores de sender y delivery, includes disponibles y las trampas del dato. No se confunde con ops-schema, que es el de clientes.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],

        /* ---------------------------------------------------------- Lectura genérica */

        'GET api/claude/query' => [
            'para_que'     => 'Lectura genérica de las tablas del admin por lista blanca: elegís un `model` del config, las columnas salen de una proyección declarada y los filtros son los que ese modelo declara. Para lo que sólo hace falta leer columnas, sin que haya que escribir un endpoint nuevo.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],

        /* ---------------------------------------------------------- Ingesta desde la conversación */

        'GET api/claude/admins' => [
            'para_que'     => 'Lista de admins para resolver a quién asignar una tarea sin adivinar ids. Sólo campos no sensibles: sin email y sin teléfono.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/task' => [
            'para_que'     => 'Crea una tarea de admin desde la conversación, con la asignación resuelta por id, por nombre o por el flag de todos los setters, y dispara las notificaciones in-app y Web Push.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Su único efecto es una fila en admin_tasks más sus notificaciones: no toca el sistema de ningún cliente ni abre ninguna conexión SSH.',
                'La tarea queda marcada con created_via = "claude", así que siempre se puede distinguir de una cargada a mano.',
                /* Estaba en el código y no acá: es lo que impide asignarle una tarea al admin equivocado. */
                'Un nombre de assigned_admin_names[] que no matchea, o que matchea a más de un admin, es 422 y NO crea la tarea. Asignar al admin equivocado sería peor que no asignar.',
            ],
            'parametros'   => [
                ['nombre' => 'title', 'obligatorio' => true, 'validacion' => 'required|string|max:500', 'que_es' => 'Título de la tarea.'],
                ['nombre' => 'content', 'obligatorio' => false, 'validacion' => 'nullable|string', 'que_es' => 'Cuerpo largo de la tarea.'],
                ['nombre' => 'todos[]', 'obligatorio' => false, 'validacion' => 'nullable|array, cada ítem required|string|max:500', 'que_es' => 'Checklist: array de strings simples, que se guardan como {text, done}.'],
                ['nombre' => 'assigned_admin_ids[]', 'obligatorio' => false, 'validacion' => 'nullable|array, cada ítem integer', 'que_es' => 'A quién se le asigna, por id. GET claude/admins los lista.'],
                ['nombre' => 'assigned_admin_names[]', 'obligatorio' => false, 'validacion' => 'nullable|array, cada ítem string', 'que_es' => 'A quién se le asigna, por nombre (match difuso). Un nombre ambiguo o sin match devuelve 422 y NO crea la tarea.'],
                ['nombre' => 'assign_to_setters', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Atajo: se la asigna a todos los admins marcados como setter.'],
            ],
        ],
        'GET api/claude/draft-version' => [
            'para_que'     => 'La versión en estado draft de id más alto, con cuántos ítems tiene cargados por tipo. Sirve para diagnosticar el estado antes de escribir con version-items.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/version-items' => [
            'para_que'     => 'Carga notificaciones, seeders, comandos y tareas manuales sobre una versión, desde el loop de Claude Code.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Es aditiva e idempotente: reejecutar el mismo payload (mismo source_group_id y mismas claves naturales) actualiza en vez de duplicar.',
                'NUNCA borra filas existentes, ni siquiera las que no vinieron en el payload.',
                'Escribe el catálogo de una versión: no toca ninguna actualización ya creada ni ningún cliente.',
                /* Los tres de abajo estaban en el código y no acá. El segundo es el que más importa
                   saber antes de llamar: este endpoint puede CREAR una versión. */
                'Si los cuatro arrays de ítems vienen vacíos o ausentes, es 422 y no se toca nada: no crea una versión borrador por una llamada vacía.',
                '⚠️ Siempre escribe sobre la versión en estado DRAFT de id más alto, y si no existe ninguna, LA CREA. No se elige la versión por parámetro. GET claude/draft-version dice cuál es antes de escribir.',
                'Toda la escritura (la posible creación de la versión más los cuatro upserts) va en UNA transacción: un fallo a mitad no deja la versión con la mitad de los ítems del grupo.',
            ],
            'parametros'   => [
                ['nombre' => 'source_group_id', 'obligatorio' => true, 'validacion' => 'required|string|max:120', 'que_es' => 'Id del grupo de claude-comerciocity que origina la ingesta. Es la clave de trazabilidad Y de idempotencia: se guarda en cada fila.'],
                ['nombre' => 'notifications[]', 'obligatorio' => false, 'validacion' => 'nullable|array. Por ítem: title required|string|max:200, body required|string, sort_order nullable|integer', 'que_es' => 'Novedades de la versión.'],
                ['nombre' => 'seeders[]', 'obligatorio' => false, 'validacion' => 'nullable|array. Por ítem: seeder_class required|string|max:200, description nullable|string, run_scope nullable|in:per_database,per_user, is_required nullable|boolean', 'que_es' => 'Seeders a correr en la actualización.'],
                ['nombre' => 'commands[]', 'obligatorio' => false, 'validacion' => 'nullable|array. Por ítem: command required|string|max:255, description nullable|string, run_scope nullable|in:per_database,per_user, run_manually nullable|boolean', 'que_es' => 'Comandos de artisan. run_manually=true los saca del reintento automático.'],
                ['nombre' => 'manual_tasks[]', 'obligatorio' => false, 'validacion' => 'nullable|array. Por ítem: title required|string|max:200, description nullable|string', 'que_es' => 'Tareas que un humano tiene que hacer durante la actualización.'],
                ['nombre' => 'client_ids[]', 'obligatorio' => false, 'validacion' => 'nullable|array, cada ítem integer', 'que_es' => 'Clientes a los que aplica el ítem, cuando no aplica a todos.'],
            ],
        ],
        'GET api/claude/client-templates' => [
            'para_que'     => 'Plantillas de soporte cargadas para los clientes, para ver el estado antes de escribir.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/client-templates' => [
            'para_que'     => 'Alta de un lote de plantillas de CLIENTE (soporte). No tienen nada que ver con las de lead, que las levanta el motor de seguimiento automático.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Idempotente por template_name: reenviar la misma plantilla actualiza la fila, nunca crea una segunda.',
                'Nunca borra las plantillas que no vinieron en el payload.',
                'No envía nada: sólo deja las plantillas cargadas.',
            ],
            'parametros'   => [
                ['nombre' => 'templates[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1', 'que_es' => 'El lote de plantillas de CLIENTE (soporte). No son las de lead.'],
                ['nombre' => 'templates[].template_name', 'obligatorio' => true, 'validacion' => 'required|string|max:120', 'que_es' => 'Clave natural: es por lo que la carga es idempotente.'],
                ['nombre' => 'templates[].categoria', 'obligatorio' => true, 'validacion' => 'required|string|max:60', 'que_es' => 'Categoría de la plantilla.'],
                ['nombre' => 'templates[].language_code', 'obligatorio' => false, 'validacion' => 'nullable|string|max:10', 'que_es' => 'Código de idioma (ej. es_AR).'],
                ['nombre' => 'templates[].categoria_label / categoria_orden', 'obligatorio' => false, 'validacion' => 'nullable|string|max:120 / nullable|integer|min:1|max:9999', 'que_es' => 'Cómo se muestra y se ordena la categoría en el panel.'],
                ['nombre' => 'templates[].titulo / body_template / descripcion', 'obligatorio' => false, 'validacion' => 'nullable|string|max:200 / nullable|string / nullable|string', 'que_es' => 'Título, cuerpo con {{1}}, {{2}}… y descripción.'],
                ['nombre' => 'templates[].variables[]', 'obligatorio' => false, 'validacion' => 'nullable|array. Por ítem: placeholder required|string|max:20, label required|string|max:120, field nullable|string|max:60, ai_suggestable nullable|boolean', 'que_es' => 'Las variables del cuerpo, con su etiqueta y de qué campo se llenan.'],
                ['nombre' => 'templates[].activa', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Si la plantilla queda disponible.'],
            ],
        ],
        'GET api/claude/client-context' => [
            'para_que'     => 'Las fichas de contexto por cliente que el agente de soporte inyecta en cada consulta. Devuelve los DOS campos (ficha_operativa y notas_internas) para poder leer antes de pisar: el consumidor que tiene prohibida la nota interna es el prompt del agente, no esta lectura.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/client-context' => [
            'para_que'     => 'Carga de las fichas de contexto por cliente. `ficha_operativa` se inyecta en el prompt del agente de soporte en cada consulta sobre ese cliente; `notas_internas` NO se inyecta nunca y es para el operador humano. 🔴 Nada calculable va acá (tickets abiertos, antigüedad, versión que corre, mensajes, escalados): eso lo lee SupportClientContextService de la base al armar el prompt, así que guardarlo sería garantizar que quede viejo. Si tu ficha trae un encabezado con esos datos, sacalo antes de subirla.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Idempotente por client_id: reenviar la ficha de un cliente actualiza la fila, nunca crea una segunda. La garantía es el índice único de la tabla más un lockForUpdate, no el código.',
                'Nunca borra las fichas que no vinieron en el payload.',
                'Un campo ausente NO pisa con null lo que ya estaba; mandar null explícito sí lo borra.',
                'Una entrada que no nombra ninguno de los dos campos es 422: no dice nada.',
                'Una entrada con los dos campos en null sobre un cliente que TODAVÍA no tiene ficha es 422 (crearía una ficha vacía). Sobre una ficha que ya existe, ese mismo payload es un borrado legítimo.',
                'Un client_id repetido dentro del mismo lote es 422: el resultado dependería del orden.',
                'Tope de 100 entradas por lote.',
                'created_via se estampa sólo en el alta: actualizar una ficha no le cambia el origen.',
                'No envía nada ni toca el sistema de ningún cliente: sólo deja las fichas cargadas.',
                'Tope de 20.000 caracteres por campo: la ficha se inyecta entera en cada consulta del agente.',
                'El log de auditoría lleva los client_id y los contadores, nunca el texto de las fichas. Una QueryException tampoco lo filtra: el mensaje de Laravel trae el SQL con los bindings interpolados y por eso se captura y se loguea sólo el SQLSTATE.',
            ],
            'parametros'   => [
                ['nombre' => 'entries[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1|max:100', 'que_es' => 'El lote de fichas, una por cliente.'],
                ['nombre' => 'entries[].client_id', 'obligatorio' => true, 'validacion' => 'required|integer|exists:clients,id', 'que_es' => 'Clave natural: es por lo que la carga es idempotente.'],
                ['nombre' => 'entries[].ficha_operativa', 'obligatorio' => false, 'validacion' => 'nullable|string|max:20000', 'que_es' => '🔴 Markdown libre, y es LO ÚNICO que llega al prompt del agente. Cómo se comunica el cliente, qué módulos usa, qué conviene evitar. Nada que se pueda calcular. El tope existe porque se inyecta entera en cada consulta.'],
                ['nombre' => 'entries[].notas_internas', 'obligatorio' => false, 'validacion' => 'nullable|string|max:20000', 'que_es' => '🔴 Markdown libre que NO se inyecta nunca en el prompt. Para el operador humano: juicios sobre la persona, temas comerciales.'],
            ],
        ],
        'GET api/claude/demo-media' => [
            'para_que'     => 'Multimedia de la demo: qué hay apuntado en cada slot. Es el mismo GET que la pantalla /multimedia-demo del admin.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'PUT api/claude/demo-media' => [
            'para_que'     => 'Apunta las URLs de los clips de la demo publicados en R2. Es el último paso del pipeline de /filmar: sin esto, un clip publicado queda invisible para el lead.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'La validación y el guardado se delegan en Api\\DemoMediaController: no hay dos definiciones de "slot válido".',
                'Sólo apunta URLs de multimedia: no toca el sistema de ningún cliente.',
            ],
            'parametros'   => [
                ['nombre' => 'slot_id', 'obligatorio' => true, 'validacion' => 'required|string|in:<los slots de DemoCatalogoService::slots()>', 'que_es' => 'Qué slot de la demo se apunta. La lista válida sale del catálogo de la demo, no de una constante copiada acá: GET claude/demo-media la devuelve.'],
                ['nombre' => 'url', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500, y si no viene vacía tiene que ser una URL válida', 'que_es' => '⚠️ Mandar "" (o no mandarla) BORRA la fila del slot y lo devuelve al placeholder. No es un no-op.'],
            ],
        ],

        /* ------------------------------------------- Demos: actualizar la version */

        'GET api/claude/demos' => [
            'para_que'     => 'Las demos que existen, con su URL, su hosting y si tienen una actualizacion en curso. Es el punto de entrada: de aca sale el demo_id y la URL con la que se confirma.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/demo-updates' => [
            'para_que'     => 'Las ultimas actualizaciones de demo, opcionalmente filtradas por demo.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => 'demo_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|exists:demos,id', 'que_es' => 'Filtra por una demo.'],
                ['nombre' => 'limit', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1|max:100', 'que_es' => 'Cuantas devolver (default 20).'],
            ],
        ],
        'GET api/claude/demo-updates/{id}' => [
            'para_que'     => 'El detalle de una actualizacion: estado, cola del log y las señales de salud que distinguen "todavia no arranco" de "no hay worker y no va a arrancar nunca", que desde afuera se ven igual.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/demo-updates' => [
            'para_que'     => 'Actualiza la version de una demo: crea el DemoUpdate y encola el pipeline (compile_spa, upload_spa, upload_api, run_migrations, restart_queue_workers, verify_demo). Devuelve 202 y NO espera: el pipeline SSH nunca corre adentro del request.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'dry_run viene en true por defecto: sin dry_run=false explicito no se escribe ni se encola nada.',
                'confirm_demo_name tiene que coincidir con la erp_spa_url de la demo. El error NO dice cual es la correcta: es un freno, no un formulario a completar.',
                'Rechaza si esa demo ya tiene una actualizacion en pendiente o ejecutandose: dos pipelines sobre la misma demo se pisan los archivos.',
                'El dispatch va con onConnection(database) explicito. En sync correria el pipeline SSH entero adentro del request HTTP, donde lo mata max_execution_time.',
            ],
            'parametros'   => [
                ['nombre' => 'demo_id', 'obligatorio' => true, 'validacion' => 'required|integer|exists:demos,id', 'que_es' => 'Que demo se actualiza. Sale de GET claude/demos.'],
                ['nombre' => 'version_id', 'obligatorio' => true, 'validacion' => 'required|integer|exists:versions,id', 'que_es' => 'A que version. Sale de GET claude/versions.'],
                ['nombre' => 'confirm_demo_name', 'obligatorio' => false, 'validacion' => 'nullable|string. Obligatorio en la practica cuando dry_run=false', 'que_es' => 'La erp_spa_url de la demo, tal cual figura en GET claude/demos.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '⚠️ Default TRUE. Sin dry_run=false no pasa nada: se devuelve lo que se haria.'],
            ],
        ],

        'POST api/claude/demo-commands' => [
            'para_que'     => 'Corre UN comando de artisan de la lista blanca sobre el servidor de una demo (sembrar la trazabilidad del clip 4.4, reiniciar los workers de cola, limpiar cachés). Es SINCRONO y devuelve la salida: estos comandos tardan segundos.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'LISTA BLANCA de comandos, y patron cerrado para los argumentos. Un endpoint que acepte comando libre es una shell remota con otro nombre. La lista vive en DemoCommandRunner::COMANDOS_PERMITIDOS.',
                'dry_run viene en true por defecto: sin dry_run=false explicito no se corre nada.',
                'confirm_demo_name tiene que coincidir con la erp_spa_url de la demo, y el error no dice cual es la correcta.',
                'La ruta de la API en el servidor sale del mismo DemoPathResolver que usa el pipeline de actualizacion: no hay dos definiciones de donde vive una demo.',
            ],
            'parametros'   => [
                ['nombre' => 'demo_id', 'obligatorio' => true, 'validacion' => 'required|integer|exists:demos,id', 'que_es' => 'Sobre que demo se corre. Sale de GET claude/demos.'],
                ['nombre' => 'comando', 'obligatorio' => true, 'validacion' => 'required|string, y tiene que estar en la lista blanca', 'que_es' => 'Hoy: demo:sembrar-trazabilidad, queue:restart, config:clear, cache:clear, route:clear, view:clear. Un 422 devuelve la lista completa.'],
                ['nombre' => 'argumentos', 'obligatorio' => false, 'validacion' => 'nullable|string, y tiene que matchear el patron del comando', 'que_es' => 'Ej: "--article_id=43". Todo lo que no matchea se rechaza ANTES de tocar el SSH.'],
                ['nombre' => 'confirm_demo_name', 'obligatorio' => false, 'validacion' => 'nullable|string. Obligatorio cuando dry_run=false', 'que_es' => 'La erp_spa_url de la demo.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '⚠️ Default TRUE. Sin dry_run=false no se corre nada.'],
            ],
        ],

        /* ---------------------------------------------------------- Leads: lectura */

        'GET api/claude/leads' => [
            'para_que'     => 'Listado de leads filtrable y paginado por cursor, con proyección flaca e include opcional. Los tokens de demo NO viajan.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/leads/{id}/messages' => [
            'para_que'     => 'Conversación completa de un lead, paginada por cursor.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/messages' => [
            'para_que'     => 'Mensajes CRUZADOS entre leads. Es la consulta que resuelve el caso de los seguimientos que no se pudieron entregar.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/metrics' => [
            'para_que'     => 'Agregados de leads, embudo, tasas de respuesta y salud de seguimientos. Todo se calcula en SQL: no devuelve ninguna fila cruda.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/audiencias' => [
            'para_que'     => 'Públicos personalizados para Meta armados con el pipeline: conversaron_sin_agendar (por el último mensaje DEL LEAD en los últimos N días, o sin límite con dias=0), agendaron_sin_entrar, agendaron (los que agendaron alguna vez, sin ventana) y clientes (leads promovidos o cerrado_ganado más la tabla clients). Una fila por persona, deduplicada, con teléfono y mail en SHA-256 normalizados como pide Meta; un celular argentino trae además phone_sha256_sin_9 (la variante 54 sin el 9). Los teléfonos que no encajan en ningún formato no se adivinan: se cuentan en descartados_por_telefono. Nunca devuelve un dato en claro. Con formato=csv devuelve el archivo listo para subir (phone,email; el celular argentino en dos filas, con y sin el 9).',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => 'etapa', 'obligatorio' => true, 'validacion' => 'conversaron_sin_agendar | agendaron_sin_entrar | agendaron | clientes', 'que_es' => 'Qué público. Una etapa inválida devuelve 422 con etapas_validas y la descripción de cada una.'],
                ['nombre' => 'dias', 'obligatorio' => false, 'validacion' => 'entero 0..365, default 14', 'que_es' => 'Ventana de conversaron_sin_agendar; 0 = sin límite de tiempo («escribieron alguna vez y no agendaron»). Las otras etapas la ignoran.'],
                ['nombre' => 'formato', 'obligatorio' => false, 'validacion' => 'json | csv, default json', 'que_es' => 'JSON: {etapa, dias, cantidad, filas: [{phone_sha256, email_sha256, phone_sha256_sin_9}], descripcion, descartados_por_telefono}. CSV: phone,email, con el celular argentino en dos filas.'],
            ],
        ],
        'GET api/claude/templates' => [
            'para_que'     => 'Catálogo de plantillas Meta aprobadas, para saber qué se puede mandar y con qué variables.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/templates/meta' => [
            'para_que'     => 'La BIBLIOTECA de plantillas con su estado en Meta y, sobre todo, con `uso`: el texto corto que dice CUÁNDO usar cada una. Es por donde se elige plantilla cuando hay que escribirle a un lead fuera de la ventana de 24 hs. Lee la tabla local, que templates:sync-meta refresca cada hora; para el estado de UNA al instante está el refresh.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'nullable|string|max:20', 'que_es' => 'Filtra por meta_status: PENDING, APPROVED, REJECTED, PAUSED, DISABLED.'],
                ['nombre' => 'category', 'obligatorio' => false, 'validacion' => 'nullable|string|max:20', 'que_es' => 'Filtra por meta_category: UTILITY o MARKETING. Define el costo (marketing se cobra siempre).'],
                ['nombre' => 'activa', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Filtra por el interruptor local.'],
                ['nombre' => 'estado', 'obligatorio' => false, 'validacion' => 'nullable|string|max:40', 'que_es' => 'Filtra por el `estado` de la fila (los manual_* son las de envío a mano).'],
            ],
        ],
        'POST api/claude/templates/meta' => [
            'para_que'     => 'CREA una plantilla en Meta (vía el proxy de Kapso) y la registra. Es lo que permite tener una plantilla nueva sin que nadie entre a Meta Business Suite con el navegador. 🔴 No confundir con POST claude/followup-templates, que sólo REGISTRA una que ya está aprobada.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'dry_run por defecto: sin dry_run=false explícito no se llama a Meta ni se escribe ninguna fila. Devuelve el payload exacto que se mandaría.',
                '🔴 Un template_name que ya tiene fila es 422 y NO se reenvía a Meta: no hay upsert. Crear dos veces la misma consume cupo del tope de 100 creaciones por hora por WABA. Para traer el estado de una que ya existe, el refresh.',
                '🔴 La fila nace con activa=false y meta_status=PENDING, incluso si Meta contestara APPROVED. La enciende templates:sync-meta o el refresh, nunca este endpoint.',
                'Las reglas de formato de Meta se validan ANTES de salir a la red: nombre ^[a-z0-9_]+$, cuerpo de hasta 1024 caracteres, numeración {{1}}..{{n}} sin saltos, y los tres defectos de rechazo automático (variable al principio, variable al final, dos variables seguidas).',
                'Un ejemplo por variable, obligatorio: Meta no puede revisar una plantilla sin muestras.',
                '🔴 El `estado` se fuerza a manual_*: uno que coincida con un slug real del pipeline haría que LeadFollowupService (cada 2 horas) le empiece a mandar la plantilla sola a todos los leads de ese estado.',
                'Sólo el componente BODY con parámetros posicionales: es lo único que WhatsappSendService::send_template() sabe enviar. Una plantilla con header o botones con variables quedaría aprobada e imposible de mandar.',
                'Con 401/403 de Meta la respuesta trae motivo=sin_permiso_en_meta y un plan_b: cargarla a mano con el procedimiento de contexto/cuentas_meta.md. No hay reintento que sirva.',
            ],
            'parametros'   => [
                ['nombre' => 'name', 'obligatorio' => true, 'validacion' => 'required|string|max:120, y ^[a-z0-9_]+$', 'que_es' => 'Nombre con el que queda en Meta. Convención: prefijo cc_ y versionado con _v2. Un nombre publicado no se reusa.'],
                ['nombre' => 'category', 'obligatorio' => true, 'validacion' => 'required, UTILITY o MARKETING', 'que_es' => 'UTILITY cuando el mensaje retoma algo que el lead pidió (es gratis dentro de la ventana y aprueba más rápido); MARKETING para nutrición (se cobra siempre y choca con el tope por destinatario 131049).'],
                ['nombre' => 'body', 'obligatorio' => true, 'validacion' => 'required|string, hasta 1024 caracteres', 'que_es' => 'El texto con sus {{n}} posicionales. Para que sea REUTILIZABLE, las variables van en el MEDIO: {{1}} el nombre, {{2}} el asunto concreto.'],
                ['nombre' => 'ejemplos[]', 'obligatorio' => true, 'validacion' => 'uno por variable, en orden; cada ítem required|string|max:120', 'que_es' => 'La muestra de cada hueco. Meta la exige para revisar, y queda guardada: es lo que después rotula cada variable en GET claude/templates/meta.'],
                ['nombre' => 'uso', 'obligatorio' => true, 'validacion' => 'required|string|max:255', 'que_es' => 'Texto corto de CUÁNDO usarla. Es lo que le deja elegir plantilla a /leads sin leer el cuerpo de las treinta y ocho.'],
                ['nombre' => 'language', 'obligatorio' => false, 'validacion' => 'nullable|string|max:10 (default es_AR)', 'que_es' => 'Idioma con el que se aprueba. Queda guardado y es el que usa el envío: no se puede mandar con otro.'],
                ['nombre' => 'estado', 'obligatorio' => false, 'validacion' => 'nullable|string|max:40, tiene que empezar con manual_', 'que_es' => 'Si no viene, se deriva del nombre (cc_x -> manual_x). Ver el freno.'],
                ['nombre' => 'dia_numero', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1 (default 1)', 'que_es' => 'Orden dentro del estado. Para una plantilla manual no significa nada, pero la columna es NOT NULL.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito no se crea nada en Meta.'],
            ],
        ],
        'POST api/claude/templates/meta/{name}/refresh' => [
            'para_que'     => 'Consulta en Meta el estado de UNA plantilla y lo baja a la fila, activándola si pasó a APPROVED. Es lo que permite crear una plantilla y volver a mirarla un rato después en la misma corrida, sin esperar la hora del scheduler. Meta no manda webhooks de estado de plantilla: hay que consultar.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Sólo actualiza una fila que ya existe: un nombre no registrado es 404 y no crea nada.',
                '🔴 Si Meta NO devuelve la plantilla, NO se cambia meta_status ni activa: sólo se sella meta_synced_at y se deja el motivo escrito. Una consulta vacía no es un rechazo (puede ser el filtro name del proxy, una página cortada o un error que contestó 200), y apagar una plantilla que funciona dejaría a /leads sin poder escribirle a nadie fuera de la ventana, en silencio.',
                'APPROVED activa la fila; REJECTED y DISABLED la desactivan y guardan el motivo; PAUSED se registra y no toca activa (la pausa de Meta es temporal y se levanta sola).',
                'No crea ni borra nada en Meta.',
            ],
            'parametros'   => [
                ['nombre' => '{name} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL, restringido a [a-z0-9_]+', 'que_es' => 'Nombre exacto de la plantilla. Un nombre con otros caracteres es 404 de Laravel y no llega al controlador.'],
            ],
        ],
        'POST api/claude/followup-templates' => [
            'para_que'     => 'Alta de un lote de plantillas de LEAD (followup_templates), para que aparezcan en GET claude/templates y en el selector del panel. No tienen nada que ver con client-templates, que es el alta de plantillas de SOPORTE (client_templates). 🔴 REGISTRA una plantilla que YA está aprobada en Meta; la que la CREA en Meta es POST claude/templates/meta.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Idempotente por template_name: reenviar la misma plantilla actualiza la fila, nunca crea una segunda.',
                'Nunca borra las plantillas que no vinieron en el payload.',
                'No envía nada: sólo deja las plantillas cargadas. El envío real es POST claude/leads/{id}/send-template o el lote.',
                '🔴 Un `estado` que no es un status real del pipeline (ver GET claude/schema, pipeline_statuses) nunca puede matchear la búsqueda de LeadFollowupService::find_template_for(), así que esa plantilla jamás la dispara el cron automático: queda disponible sólo para envío manual. Es la convención deliberada de las plantillas `manual_*` del chequeo diario.',
            ],
            'parametros'   => [
                ['nombre' => 'templates[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1', 'que_es' => 'El lote de plantillas de LEAD. No son las de soporte.'],
                ['nombre' => 'templates[].template_name', 'obligatorio' => true, 'validacion' => 'required|string|max:120', 'que_es' => 'Clave natural: es por lo que la carga es idempotente. Tiene que coincidir exacto con el nombre aprobado en Meta.'],
                ['nombre' => 'templates[].estado', 'obligatorio' => true, 'validacion' => 'required|string|max:40', 'que_es' => '🔴 NO tiene que ser un status del pipeline a propósito cuando la plantilla es de uso manual (prefijo `manual_`, ver el freno de arriba). Si coincidiera con un status real, LeadFollowupService la dispararía sola.'],
                ['nombre' => 'templates[].dia_numero', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'Orden dentro del estado: qué plantilla corresponde al primer, segundo... seguimiento de ese estado.'],
                ['nombre' => 'templates[].body_template', 'obligatorio' => true, 'validacion' => 'required|string', 'que_es' => 'Texto exacto aprobado en Meta, con sus {{n}} tal cual. El accessor `variables` del modelo los deriva de acá.'],
                ['nombre' => 'templates[].language_code', 'obligatorio' => false, 'validacion' => 'nullable|string|max:10', 'que_es' => 'Código de idioma (default es_AR).'],
                ['nombre' => 'templates[].activa', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Si la plantilla queda disponible para enviar (default true).'],
                ['nombre' => 'templates[].solo_si_ingreso_confirmado', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Sólo aplica al estado demo_agendada: bifurca el seguimiento automático según si el lead confirmó ingreso a la demo (default false).'],
            ],
        ],
        'POST api/claude/followup-rules' => [
            'para_que'     => 'La otra mitad del motor de seguimientos: la CADENCIA por estado (followup_rules). Cada cuántas horas se insiste y cuántas veces antes de pasar el lead a En Pausa. followup-templates es QUÉ texto sale; esto es CUÁNDO y CUÁNTAS VECES.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Idempotente por estado, que es la clave real de la tabla (índice único en followup_rules.estado, y LeadFollowupService la indexa con keyBy(\'estado\')): reenviar la misma regla actualiza la fila, nunca crea una segunda.',
                'Nunca borra las reglas que no vinieron en el payload. Para apagar una se manda con activa=false, que es reversible.',
                'dry_run por defecto: si no se pide lo contrario, simula y no escribe nada. La simulación devuelve, por cada regla, el valor actual, el propuesto y CUÁNTOS LEADS hay hoy en ese estado — que es a cuánta gente real le cambia la cadencia.',
                'confirm_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals: si alguna regla cambió entre la simulación y la confirmación, no pasa.',
                '🔴 Un `estado` que no es un slug real del pipeline es 422 con la lista de los válidos. Es la ASIMETRÍA DELIBERADA con POST claude/followup-templates: allá un estado inexistente es válido a propósito (las plantillas manual_* quedan inertes y disponibles para envío manual), acá no, porque una regla con estado inexistente queda cargada pareciendo que anda y el cron no la levanta nunca.',
                '🔴 horas_espera entre 3 y 720, y los dos números salen del cron, no de un gusto. El mínimo de 1 que había NO frenaba el martilleo que decía frenar: leads:check-followups corre everyTwoHours() (Kernel.php ~23) y process_lead() (~224) compara $hours < horas_espera, así que con 1 o con 2 cada corrida cumple la condición y la cadencia real pasa a ser un mensaje cada dos horas — diez mensajes en veinte horas a una persona. 3 es el primer valor que obliga a saltear una corrida. El máximo de 720 (30 días) es por 4294967295: entra en la columna int unsigned, así que la regla quedaba activa y visible en el panel esperando 490.000 años, el mismo modo de fallo que un estado inventado. Y 4294967296 no entra: pasaba la simulación con 200 y reventaba con 500 al escribir.',
                '🔴 El rango de horas_espera se chequea sobre el valor RESUELTO, no sobre el que vino: una regla vieja por debajo del mínimo no se puede dejar prendida editándole otro campo, porque el arrastre traería el valor viejo. Si la regla queda con activa=false se deja pasar a propósito: apagar una regla rota tiene que ser siempre posible.',
                '🔴 activa es OBLIGATORIO y explícito (era nullable con default true). La asimetría con protocol-entries estaba al revés del riesgo: una entrada de protocolo es texto que lee una persona, una regla hace que el cron le escriba por WhatsApp a leads reales.',
                '🔴 La simulación devuelve, además de leads_en_ese_estado, un disparo_inmediato por regla: cuántos de esos leads reciben el seguimiento en la PRÓXIMA corrida del cron (dentro de las 2 horas), no dentro de las horas_espera. LeadFollowupService::last_message_at() cae a lead.created_at cuando el lead no tiene mensajes, así que prender una regla en un estado con leads parados hace meses les manda a todos ya.',
                '🔴 Apagar la ÚLTIMA regla activa deja el motor de seguimientos mudo: la simulación y la respuesta lo dicen en motor_de_seguimientos (reglas_activas_hoy / reglas_activas_despues / advertencia), y aplicarlo exige confirm_apagar_todas=true además del confirm_token. Un lote de baja no puede salir tan barato como uno de alta.',
                '🔴 El confirm_token cubre también leads_en_ese_estado, disparo_inmediato y el conteo de reglas activas: si entra gente al estado entre la simulación y la confirmación, el token no coincide. Antes se podía simular con 1 lead en el estado, meter 5 más y escribir con el mismo token.',
                'El confirm_token se arma sobre una estructura serializada entera (json_encode), no concatenando con separadores. Acá el estado es un slug normalizado a [a-z0-9_] y no se podía forjar, pero el criterio quedó igual que en protocol-entries, donde sí se podía y está medido.',
                '⚠️ Reintentar la misma llamada cuando ya no hay nada que cambiar da 200 sin escribir: el caso cambiarian=0 se resuelve ANTES de comparar confirm_count. Antes ese reintento daba 422.',
                'max_followups máximo 10.',
                'Al CREAR una regla, horas_espera y max_followups son obligatorios: una cadencia a medias no es una cadencia. Al editar se pueden omitir y se arrastra lo que había.',
                'Un estado repetido dentro del mismo lote es 422: dos filas para el mismo estado se pisarían sin que se sepa cuál ganó.',
                'Tope MAX_BATCH = 50 reglas por llamada.',
                'La simulación avisa si el estado es cerrado_ganado, cerrado_perdido o en_pausa: LeadFollowupService::process_all_active_leads() los saltea siempre, así que una regla para esos estados es inerte.',
            ],
            'parametros'   => [
                ['nombre' => 'reglas[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1', 'que_es' => 'El lote de reglas de cadencia. Una por estado del pipeline.'],
                ['nombre' => 'reglas[].estado', 'obligatorio' => true, 'validacion' => 'required|string|max:40', 'que_es' => '🔴 Clave natural: es por lo que la carga es idempotente. Tiene que ser un slug REAL del pipeline (ver GET claude/schema, pipeline_statuses); un slug inventado es 422.'],
                ['nombre' => 'reglas[].horas_espera', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:3|max:720 — obligatorio al crear la regla', 'que_es' => 'Horas sin respuesta antes de disparar el seguimiento siguiente. Mínimo 3 porque el cron corre cada 2 horas: con 1 o con 2 manda en cada corrida. Máximo 720 (30 días): más que eso la regla queda activa y visible pero no dispara nunca. El rango se valida sobre el valor resuelto, así que el arrastre no lo saltea.'],
                ['nombre' => 'reglas[].max_followups', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1|max:10 — obligatorio al crear la regla', 'que_es' => 'Cuántos seguimientos se mandan antes de pasar el lead a En Pausa. Máximo 10.'],
                ['nombre' => 'reglas[].activa', 'obligatorio' => true, 'validacion' => 'required|boolean', 'que_es' => '🔴 Obligatorio y explícito: una regla NO se prende sola, porque prenderla hace que el cron le escriba a leads reales. Apagarla (false) es la forma de sacar un estado del seguimiento automático sin borrar la fila.'],
                ['nombre' => 'reglas[].descripcion', 'obligatorio' => false, 'validacion' => 'nullable|string|max:2000', 'que_es' => 'Para qué es esta cadencia. No la lee ningún motor: es para el que la mire después.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO se escribe nada: devuelve el diff por regla, cuántos leads hay en cada estado, a cuántos de ésos les dispara YA (disparo_inmediato) y cómo queda el motor de seguimientos.'],
                ['nombre' => 'confirm_apagar_todas', 'obligatorio' => false, 'validacion' => 'nullable|boolean — DEFAULT false', 'que_es' => '🔴 Hace falta SÓLO cuando el lote apaga la última regla activa. Sin él ese lote es 422: el cron seguiría corriendo cada dos horas sin mandarle un seguimiento a nadie y nada lo avisaría.'],
                ['nombre' => 'confirm_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Tiene que coincidir exacto con el cambiarian que devolvió la simulación.'],
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token que devolvió la simulación. Se compara con hash_equals.'],
            ],
        ],
        'POST api/claude/protocol-entries' => [
            'para_que'     => 'Alta y edición en lote de las entradas del PROTOCOLO DE VENTAS (protocol_entries): las etapas, los seguimientos y las situaciones frecuentes que el panel le muestra al setter y contra las que se arma una respuesta.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                '🔴 Idempotente por `titulo`, y la clave está MEDIDA, no elegida: sobre el protocolo real son 46 filas con 46 títulos distintos y sólo 23 ternas (categoria, estado_aplicable, followup_numero) distintas — hay cuatro entradas con (etapa_principal, contactado, null). Usar la terna como clave haría que cargar "Etapa 2B" pisara "Etapa 2A" en silencio.',
                '⚠️ No hay índice único en protocol_entries.titulo (verificado con SHOW INDEX: la columna no tiene NINGÚN índice). Adentro de una misma llamada está cubierto: la escritura resuelve cada fila con la comparación de MySQL y lockForUpdate(), y da vuelta el lote entero con 422 si dos títulos del payload caen en la misma fila. Lo que NO cubre nada es la concurrencia entre dos llamadas: dos altas simultáneas del mismo título crean dos filas. El arreglo que corresponde es un índice UNIQUE sobre titulo con la colación de la columna, y no se hizo porque esta misión no lleva migraciones. followup_rules.estado sí lo tiene, y por eso a la cadencia esto no le pasa.',
                '🔴 descripcion, mensaje_template y notas_setter se validan en BYTES contra el largo real de la columna (TEXT = 65.535 bytes), no en caracteres. El max:20000 anterior lo cuenta Laravel en caracteres: 20.000 emoji son 20.000 caracteres y 80.000 bytes, así que pasaban la validación y devolvían 500 al escribir. (titulo no entra en esta cuenta: un varchar(N) de MySQL cuenta caracteres, así que ahí el max:255 mide lo mismo que la columna.)',
                'El confirm_token se arma sobre una estructura serializada entera (json_encode), no concatenando con separadores: titulo es texto libre de 255 caracteres y no se valida contra ":" ni "|", así que un lote de UNA entrada con el título forjado fabricaba el mismo token que otro lote de dos y escribía con él. Está medido.',
                '⚠️ Reintentar la misma llamada cuando ya no hay nada que cambiar da 200 sin escribir: el caso cambiarian=0 se resuelve ANTES de comparar confirm_count. Antes ese reintento daba 422.',
                'Nunca borra las entradas que no vinieron en el payload. Para sacar una de circulación se manda con activa=false.',
                'dry_run por defecto: si no se pide lo contrario, simula y devuelve el diff entrada por entrada sin escribir nada.',
                'confirm_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals: si alguna entrada cambió entre la simulación y la confirmación, no pasa.',
                '🔴 `activa` es obligatorio y explícito: una entrada nueva NO se activa sola. LeadSuggestionSendService::record_setter_correction() crea entradas con activa=false justamente porque son correcciones pendientes de revisión, y un default true acá le pisaría el criterio.',
                'La `categoria` tiene que ser una de las tres reales (etapa_principal, seguimiento, situacion_frecuente): con otra, la entrada queda invisible en el panel.',
                '`estado_aplicable` tiene que ser un slug real del pipeline o venir vacío (= aplica a todos). Un slug inventado no le aplica a ningún lead.',
                'Al CREAR una entrada, descripcion y mensaje_template son obligatorios (las dos columnas son NOT NULL). Al editar se pueden omitir y se arrastra lo que había.',
                '🔴 Un título repetido dentro del mismo lote es 422, y "repetido" es lo que considera repetido MYSQL, no PHP: protocol_entries.titulo es utf8mb4_unicode_ci, o sea que no distingue mayúsculas ni acentos. Medido: un lote con "ZZ Objecion de precio" y "zz objecion de precio" decía cambiarian=2, contestaba {"creadas":1,"actualizadas":1} y dejaba UNA sola fila en la base.',
                '🔴 Un título retipeado sin acentos ACTUALIZA la fila que ya existe, no crea una nueva: para MySQL son el mismo título. La simulación lo dice en titulo_en_base y en una advertencia, y el título guardado NO se reescribe (renombrar por una tilde comida sería indistinguible de un error de tipeo; para renombrar, el ABM del panel). 31 de los 46 títulos reales del protocolo llevan tilde o eñe. Antes de este arreglo ese caso daba simulación accion=crear con actual todo en null y después pisaba la fila BORRÁNDOLE estado_aplicable, followup_numero y notas_setter.',
                'Tope MAX_BATCH = 100 entradas por llamada.',
                '🔴 NO existe el endpoint hermano para agent_identities ni ai_system_prompts, y es a propósito: AgentPromptSyncService los pisa cada 10 minutos desde agentes/lead/identidad.md y agentes/lead/instrucciones_operativas.md del repo de conocimiento. Un POST sobre ellos sería una escritura que desaparece sola. Esas dos se leen por GET claude/query y se escriben commiteando al repo.',
            ],
            'parametros'   => [
                ['nombre' => 'entradas[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1', 'que_es' => 'El lote de entradas del protocolo.'],
                ['nombre' => 'entradas[].titulo', 'obligatorio' => true, 'validacion' => 'required|string|max:255 (la columna es varchar(255) y MySQL cuenta caracteres, así que el max mide lo mismo)', 'que_es' => '🔴 Clave natural: es por lo que la carga es idempotente, y es lo que el ABM del panel muestra como identidad de la entrada. Se compara COMO LA COMPARA MYSQL (utf8mb4_unicode_ci): sin distinguir mayúsculas ni acentos. Mandar el mismo título sin tildes actualiza la fila que ya existe y no crea una segunda; la simulación lo avisa en titulo_en_base.'],
                ['nombre' => 'entradas[].categoria', 'obligatorio' => true, 'validacion' => 'required|string|max:40, una de: etapa_principal | seguimiento | situacion_frecuente', 'que_es' => 'Dónde entra la entrada en el protocolo. Otra categoría es 422: el panel no la mostraría.'],
                ['nombre' => 'entradas[].activa', 'obligatorio' => true, 'validacion' => 'required|boolean', 'que_es' => '🔴 Obligatorio y explícito: una entrada nueva no se activa sola.'],
                ['nombre' => 'entradas[].estado_aplicable', 'obligatorio' => false, 'validacion' => 'nullable|string|max:40, slug real del pipeline', 'que_es' => 'Estado del lead al que aplica. Vacío = a todos. Un slug inventado es 422.'],
                ['nombre' => 'entradas[].followup_numero', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1|max:255', 'que_es' => 'Qué seguimiento de ese estado es. Vacío = no es un seguimiento numerado.'],
                ['nombre' => 'entradas[].descripcion', 'obligatorio' => false, 'validacion' => 'nullable|string, tope real 65.535 BYTES (la columna es TEXT) — obligatorio al crear', 'que_es' => 'Cuándo se usa esta entrada. Columna NOT NULL. El límite es en bytes: un emoji ocupa cuatro.'],
                ['nombre' => 'entradas[].mensaje_template', 'obligatorio' => false, 'validacion' => 'nullable|string, tope real 65.535 BYTES (la columna es TEXT) — obligatorio al crear', 'que_es' => 'El texto sugerido. Columna NOT NULL. El límite es en bytes: un emoji ocupa cuatro.'],
                ['nombre' => 'entradas[].notas_setter', 'obligatorio' => false, 'validacion' => 'nullable|string, tope real 65.535 BYTES (la columna es TEXT)', 'que_es' => 'Nota para el setter que va a usar la entrada. Mandarla en null la vacía. El límite es en bytes.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO se escribe nada: devuelve el diff por entrada.'],
                ['nombre' => 'confirm_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Tiene que coincidir exacto con el cambiarian que devolvió la simulación.'],
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token que devolvió la simulación. Se compara con hash_equals.'],
            ],
        ],

        /* ---------------------------------------------------------- Leads: envío */

        'POST api/claude/leads/{id}/send-template' => [
            'para_que'     => 'Manda una plantilla Meta a UN lead real por WhatsApp y registra el mensaje en su conversación.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'El lead se nombra por id: no hay forma de mandarle a "los que cumplan un filtro".',
                '🔴 La plantilla tiene que estar REGISTRADA, activa y APROBADA en Meta (meta_status=APPROVED): si no, 422 y no se envía nada. Hasta el 13/10/2026 no se validaba nada, y una plantilla que Meta no conocía fallaba en Kapso y este endpoint contestaba 200 con enviado=false, que desde afuera se lee como un éxito parcial. El 422 nombra el paso para salir (refresh, el listado o el alta).',
                'El language_code sale de la FILA de la plantilla, no del request: Meta la aprobó para un idioma y es el único con el que la entrega. Si el llamador manda otro, se ignora y la respuesta dice con cuál salió.',
                'Cooldown de 24 horas por lead. Para saltearlo hay que repetir la llamada con ignorar_cooldown, que queda escrito en el pedido.',
                /* Estaba en el código y no acá. */
                'El lead tiene que tener teléfono cargado: sin él es 422 y no se crea absolutamente nada.',
                'El content renderizado no puede venir vacío: sin él el mensaje quedaría registrado en blanco.',
                'El mensaje enviado queda registrado en la conversación del lead: no hay envío sin rastro.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL', 'que_es' => 'Id del lead destinatario. Se nombra de a uno: no hay forma de mandarle a "los que cumplan un filtro".'],
                ['nombre' => 'template_name', 'obligatorio' => true, 'validacion' => 'required|string|max:255', 'que_es' => 'Nombre de la plantilla Meta aprobada. GET claude/templates las lista.'],
                ['nombre' => 'content', 'obligatorio' => true, 'validacion' => 'required|string', 'que_es' => 'El texto YA renderizado que se guarda en la conversación. Es obligatorio: sin él el mensaje quedaría registrado vacío.'],
                ['nombre' => 'language_code', 'obligatorio' => false, 'validacion' => 'nullable|string|max:20', 'que_es' => 'Idioma de la plantilla (ej. es_AR).'],
                ['nombre' => 'variables[]', 'obligatorio' => false, 'validacion' => 'nullable|array', 'que_es' => 'Array POSICIONAL con los valores de {{1}}, {{2}}…'],
                ['nombre' => 'followup_template_id', 'obligatorio' => false, 'validacion' => 'nullable|integer', 'que_es' => 'Plantilla de seguimiento de la que sale este envío, para atribuirlo.'],
                ['nombre' => 'context', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500', 'que_es' => 'Por qué se manda. Queda registrado con el envío.'],
                ['nombre' => 'ignorar_cooldown', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Saltea el cooldown de 24 hs de ese lead. Queda escrito en el pedido.'],
            ],
        ],
        'POST api/claude/send-template-batch' => [
            'para_que'     => 'Manda una plantilla Meta a un LOTE de leads nombrados uno por uno. Es el endpoint de recuperación del pipeline comercial.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'Sólo lead_ids explícitos: el lote NO acepta filtros, así que un filtro mal escrito no se puede convertir en un envío masivo.',
                '🔴 La plantilla tiene que estar REGISTRADA, activa y APROBADA en Meta, y se valida UNA vez para todo el lote antes de resolver destinatarios. Acá pesa más que en el endpoint individual: sin esto, una plantilla rechazada producía cincuenta LeadMessage rojos en cincuenta conversaciones y cincuenta avisos a los admins.',
                'El language_code sale de la FILA de la plantilla, no del request.',
                'dry_run por defecto: si no se pide lo contrario, simula y no manda nada.',
                'confirm_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals: un lote que cambió entre la simulación y la confirmación no pasa.',
                'Tope MAX_BATCH = 50 leads por llamada.',
                'Cooldown de 24 horas por lead, y los estados cerrados quedan afuera salvo include_closed.',
                'Presupuesto de 50 segundos con reserva de 35 por envío: corta limpio y devuelve los no procesados en vez de que lo mate el request.',
                'Corte al primer fallo: si un envío falla, el lote se detiene.',
            ],
            'parametros'   => [
                ['nombre' => 'lead_ids[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1, cada ítem required|integer|min:1', 'que_es' => '🔴 Los destinatarios, nombrados uno por uno. NO hay filtros: es lo que impide que un filtro mal escrito se convierta en un envío masivo.'],
                ['nombre' => 'template_name', 'obligatorio' => true, 'validacion' => 'required|string|max:255', 'que_es' => 'Plantilla Meta aprobada, la misma para todo el lote.'],
                ['nombre' => 'content_template', 'obligatorio' => true, 'validacion' => 'required|string', 'que_es' => 'El texto con {{1}}, {{2}}… que se guarda como cuerpo de cada mensaje.'],
                ['nombre' => 'language_code', 'obligatorio' => false, 'validacion' => 'nullable|string|max:20', 'que_es' => 'Idioma de la plantilla.'],
                ['nombre' => 'followup_template_id', 'obligatorio' => false, 'validacion' => 'nullable|integer', 'que_es' => 'Plantilla de seguimiento de la que sale el lote.'],
                ['nombre' => 'variables_por_lead', 'obligatorio' => false, 'validacion' => 'nullable|array', 'que_es' => 'Valores explícitos por lead, indexados por lead_id.'],
                ['nombre' => 'variables_desde_lead[]', 'obligatorio' => false, 'validacion' => 'nullable|array, cada ítem required|string|max:60', 'que_es' => 'Campos del lead de los que se sacan las variables posicionales.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO se manda nada: devuelve a quién le llegaría y con qué texto.'],
                ['nombre' => 'confirm_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Tiene que coincidir exacto con la cantidad que devolvió la simulación.'],
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token que devolvió la simulación. Se compara con hash_equals: si el conjunto cambió, no pasa.'],
                ['nombre' => 'include_closed', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Incluye los leads en estados cerrados, que por defecto quedan omitidos.'],
                ['nombre' => 'context', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500', 'que_es' => 'Por qué se manda el lote. Queda registrado.'],
            ],
        ],

        'POST api/claude/leads/{id}/message' => [
            'para_que'     => 'Manda un mensaje de TEXTO LIBRE a UN lead real por WhatsApp, sólo mientras la ventana de 24 hs de Meta esté abierta. Es la contraparte de send-template para el otro lado de esa ventana: adentro, el texto libre sale y una plantilla queda fría; afuera, el texto libre no sale y lo único que llega es una plantilla aprobada.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                '🔴 La ventana de 24 hs manda: con la ventana cerrada es 422, no se llama al sender y no se crea ninguna fila. La respuesta trae last_inbound_at y nombra el endpoint de plantillas, porque no es un error de quien llamó: es el estado del mundo.',
                'La ventana la resuelve WhatsappSessionWindowService, el mismo que usa el resto del sistema: el criterio no se recalcula acá.',
                'Un solo mensaje por turno de conversación: si ya se le respondió después de su último mensaje, es 422 y la respuesta dice QUIÉN ocupó el turno (Claude en un intento anterior, o una persona). Para saltearlo hay que repetir la llamada con permitir_varios_por_turno, que queda escrito en el pedido. Reemplaza al cooldown de 24 hs de send-template, que acá dejaría muda la conversación en curso que este endpoint existe para sostener.',
                '🔴 El turno se mira por TELÉFONO, no por lead_id: dos filas de lead con el mismo número comparten la conversación y el freno. Si el número tiene una conversación viva que no está en lead_messages (soporte o implementación), también frena, porque desde acá no se puede saber de quién es el turno.',
                'Lock por lead durante el chequeo y el envío: dos llamadas simultáneas no mandan dos mensajes. La segunda recibe 409 sin enviar nada.',
                'Tope de 4096 caracteres, que es el largo máximo de un mensaje de texto de WhatsApp.',
                'El lead se nombra por id: no hay forma de mandarle a "los que cumplan un filtro".',
                'El lead tiene que tener teléfono cargado: sin él es 422 y no se crea absolutamente nada.',
                'El texto no puede venir vacío.',
                '🔴 Un lead en cerrado_ganado o ya promovido a cliente no recibe nada, y acá NO hay include_closed que lo habilite: a un cliente se le responde por el hilo de soporte.',
                '🔴 Un lead con no_recibe_mensajes_at cargado no recibe nada, y NINGÚN parámetro lo saltea. Esa marca la pone una persona mirando la conversación y sólo se saca desde el panel.',
                'El mensaje enviado queda registrado en la conversación del lead: no hay envío sin rastro.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL', 'que_es' => 'Id del lead destinatario. Se nombra de a uno: no hay forma de mandarle a "los que cumplan un filtro".'],
                ['nombre' => 'content', 'obligatorio' => true, 'validacion' => 'required|string', 'que_es' => 'El texto del mensaje, tal cual lo va a leer el lead. No lleva variables ni plantilla: es texto libre.'],
                ['nombre' => 'context', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500', 'que_es' => 'Por qué se manda. Va en el aviso a los admins si el envío falla.'],
                ['nombre' => 'permitir_varios_por_turno', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Saltea el freno de un mensaje por turno. Queda escrito en el pedido.'],
            ],
        ],

        /* -------------------------------------------------- Pipeline de leads: escritura del estado */

        'POST api/claude/leads/{id}/status' => [
            'para_que'     => 'Mueve UN lead de estado del pipeline y deja el evento en su conversación. Es la contraparte de escritura del análisis: sirve para acomodar el estado a la situación real cuando el lead quedó en un tramo que ya no corresponde.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'El slug destino tiene que existir en el catálogo del pipeline: no se inventan estados.',
                'cerrado_ganado no se puede asignar desde acá: cuelga de la promoción a Client (contrato y alta).',
                'Un lead ya promovido a cliente, o en cerrado_ganado, no se mueve.',
                'Si el lead ya estaba en ese estado no se escribe nada ni se registra evento.',
            ],
            'parametros'   => [
                ['nombre' => 'status', 'obligatorio' => true, 'validacion' => 'required|string|max:80', 'que_es' => 'Slug del estado destino. Los válidos salen de claude/schema (pipeline_statuses).'],
                ['nombre' => 'motivo', 'obligatorio' => false, 'validacion' => 'nullable|string|max:300', 'que_es' => 'Por qué se mueve. Queda escrito en el evento de la conversación.'],
                ['nombre' => 'registrar_evento', 'obligatorio' => false, 'validacion' => 'nullable|boolean — DEFAULT true', 'que_es' => 'Si deja el mensaje is_status_event con el cambio.'],
            ],
        ],
        'POST api/claude/leads/status-batch' => [
            'para_que'     => 'Mueve un LOTE de leads, cada uno a su propio estado, nombrados uno por uno. Es lo que permite repasar todas las conversaciones y dejar los estados acomodados de una.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'Sólo una lista explícita de {lead_id, status}: el lote NO acepta filtros, así que un filtro mal escrito no puede barrer la tabla.',
                'dry_run por defecto: si no se pide lo contrario, simula y no escribe nada.',
                'confirm_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals: si la lista o algún destino cambió entre la simulación y la confirmación, no pasa.',
                'Tope MAX_BATCH = 200 leads por llamada.',
                'Un slug inválido en una sola fila aborta el lote entero: es error de armado, no un lead salteable.',
                'cerrado_ganado no se asigna, y los leads ganados o ya promovidos a cliente se omiten.',
            ],
            'parametros'   => [
                ['nombre' => 'cambios[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1, cada ítem con lead_id required|integer|min:1 y status required|string|max:80', 'que_es' => '🔴 LISTA de {lead_id, status, motivo}, no un mapa: en un mapa con claves numéricas correlativas el JSON se decodifica como lista y las claves se corren.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO se escribe nada: devuelve qué lead cambiaría y a qué estado.'],
                ['nombre' => 'confirm_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Tiene que coincidir exacto con el cambiarian que devolvió la simulación.'],
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token que devolvió la simulación. Se compara con hash_equals.'],
                ['nombre' => 'registrar_evento', 'obligatorio' => false, 'validacion' => 'nullable|boolean — DEFAULT true', 'que_es' => 'Si deja el mensaje is_status_event en cada lead movido.'],
            ],
        ],

        /* --------------------------------------------- Leads: agenda (lectura) y evento del closer */

        'GET api/claude/leads/{id}/availability' => [
            'para_que'     => 'Las instancias de demo del pool y los horarios LIBRES de cada una, por fecha, con el turno del propio lead excluido del bloqueo contra sí mismo. 🔴 Es lo primero que hay que pedir antes de agendar: PATCH claude/leads/{id} rechaza con 422 cualquier horario que no figure acá, y los demo_id válidos salen de la clave `demos` de esta respuesta. Delega en el mismo cálculo que consume el panel (LeadController::panel_availability_json): no hay una segunda definición de "slot válido".',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL, sólo dígitos', 'que_es' => 'Lead sobre el que se calcula la disponibilidad. Se excluye su propio turno para que no aparezca bloqueado contra sí mismo.'],
            ],
        ],
        'POST api/claude/leads/{id}/calendar-event' => [
            'para_que'     => '(Re)crea el evento de Google Calendar del closer para este lead y, con eso, su link de Meet — son la misma operación en Google Calendar. Es lo que hay que llamar DESPUÉS de reagendar: ni el panel ni el PATCH mueven el evento del closer, y hasta el 3/9/2026 el consejo era usar POST admin/lead/{id}/force-calendar-event, que vive detrás de auth:sanctum y una sesión con X-Claude-Task-Key no puede llamar.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                '🔴 confirmar_reemplazo_del_meet es OBLIGATORIO cuando el lead ya tiene evento: recrearlo borra el anterior y genera un google_event_id y un meet_url NUEVOS, así que el link de Meet que el lead ya recibió (se lo manda CloserAlertService por WhatsApp) deja de servir, y si el lead tiene email cargado Google le manda otra invitación.',
                'Sin demo_date y demo_start_time cargados es 422: no hay con qué calcular el horario del evento del closer.',
                'Nunca deja dos eventos para el mismo lead: recreate_event_for_lead() borra el anterior antes de crear el nuevo.',
                'El lead se nombra por id y el {id} está restringido a dígitos.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL, sólo dígitos', 'que_es' => 'Lead cuyo evento del closer se (re)crea.'],
                ['nombre' => 'confirmar_reemplazo_del_meet', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT false, obligatorio si el lead YA tiene evento', 'que_es' => 'Confirma que se acepta perder el link de Meet actual. Sin esto, un reintento de una llamada cortada le quemaría el link al lead sin haberlo pedido.'],
            ],
        ],

        /* --------------------------------------------- Leads: escritura de campos y agenda */

        'PATCH api/claude/leads/{id}' => [
            'para_que'     => 'Escribe los campos descriptivos y de agenda de UN lead por lista blanca cerrada: contact_name, company_name, business_type, notes, email, demo_id, demo_date, demo_start_time, demo_end_time, demo_flexible y meeting_scheduled_at. Es lo que permite AGENDAR una demo desde afuera y guardar el dolor que el lead contó en la conversación (el lugar de eso es `notes`: NO existe columna `dolor`). 🔴 Antes de agendar, pedí GET claude/leads/{id}/availability: de ahí salen el demo_id y el horario que este endpoint acepta.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                '🔴 Lista blanca CERRADA: un campo que no está declarado es 422 con la lista de los válidos en el cuerpo, nunca se ignora en silencio. El 422 trae además el motivo de los prohibidos más probables (status, phone, contract_*, promoted_client_id, user_id, los tokens de demo y los flags de automatización), así que dice por dónde va y no sólo "ese no".',
                'Los campos viajan adentro del objeto `campos`, no sueltos en el body: así dry_run y confirm_count no tienen que excluirse a mano de la lista blanca, y una lista blanca con excepciones deja de ser una lista blanca.',
                '`status` NO se escribe desde acá: para eso está POST claude/leads/{id}/status, que tiene sus propios frenos (no asigna cerrado_ganado y deja el evento en la conversación).',
                '`phone` NO se escribe desde acá: cambiarlo redirige TODOS los envíos futuros de ese lead a otro número. Tampoco `doc_number`, que es la contraseña con la que el lead entra a la demo.',
                'dry_run por defecto: si no se pide lo contrario, devuelve el DIFF campo por campo (actual → propuesto) y no escribe absolutamente nada. 🔴 La simulación corre TODOS los frenos, incluidos los de agenda: un dry_run en 200 que después revienta al aplicar no sirve para nada.',
                'confirm_count tiene que coincidir exactamente con la cantidad de campos que CAMBIAN según la simulación.',
                'Un lead ya promovido a cliente (promoted_client_id no nulo) no se toca — mismo criterio que ClaudeLeadsPipelineController.',
                'Un campo que llega con el valor que ya tiene se reporta en `sin_cambio` y no cuenta como escritura: reintentar la misma llamada es seguro.',
                'El lead se nombra por id: no hay forma de editarle campos a "los que cumplan un filtro". Y el {id} de la ruta está restringido a dígitos: antes, `leads/26648-borrame` editaba el lead 26648.',
                '🔴 AGENDAR NO ES ESCRIBIR UNA FECHA. Escribir `demo_date` sin que el lead tenga `demo_id` (ni venga uno en el payload) es 422: sin INSTANCIA asignada no sale el mail de demo (LeadController::send_demo_mail_json lo rechaza) ni hay sobre qué emitir el token de ingreso, así que quedaría una fecha escrita y ninguna demo agendada.',
                '🔴 EL HORARIO SE VALIDA CONTRA LA GRILLA REAL, adentro del lock `demo_slot_hold_{demo_id}` (el mismo nombre que toma LeadAiService al asignar un turno). El cálculo es el de LeadAiService::build_availability_json() con exclude_lead_id = el propio lead, o sea el mismo que consume el panel: filtra por instancia, por rangos ocupados de otros leads y por el calendario del closer. Sin esto el endpoint podía pisarle el turno a otro lead sobre la misma instancia y nada lo denunciaba. Si el horario no está libre, el 422 trae la lista de los que sí lo están para esa fecha.',
                '🔴 El turno resultante no puede quedar en el PASADO: sobre un lead en demo_agendada eso desarma la guarda de LeadFollowupService::process_lead() y el cron le manda la tanda de seguimiento por una demo que nunca ocurrió. Se fuerza con permitir_fecha_pasada=true, sólo para corregir un registro viejo.',
                '🔴 Cambiar el TURNO (demo_date, demo_start_time o demo_end_time) resetea recordatorio_demo_enviado, recordatorio_manana_enviado y demo_fin_check_reprogramado_para, por la MISMA definición que usa el panel (App\\Services\\LeadRescheduleFlagsService). Sin ese reset, la demo nueva no recibiría su recordatorio y el check de fin quedaría trabado en un timestamp del horario viejo. El dry_run muestra qué flags se van a resetear.',
                '🔴 `notes` REEMPLAZA lo que haya escrito: no agrega ni concatena. Esa columna la llena Lucas a mano desde el panel, así que escribirla sin leerla antes le borra la nota a una persona. Leela primero con GET claude/query?model=lead o con el propio dry_run (la devuelve en diff.notes.actual) y mandá el texto COMPLETO ya integrado. La respuesta trae esa advertencia cuando el lead ya tenía nota.',
                'Los valores se validan contra la columna REAL: los formatos de fecha son una lista cerrada compartida con GET claude/query (nada de "tomorrow 2026-09-15" ni "2026-09-15 +3 months", que Carbon::parse acepta y guarda otra cosa), meeting_scheduled_at tiene que entrar en el rango de un `timestamp` (1970-2038) y `notes` se mide en BYTES contra los 65.535 de un TEXT. Los tres eran 500 antes del 3/9/2026.',
                '⚠️ Lo que NO hace, igual que el panel: no mueve el evento de Google Calendar del closer al reagendar (para eso está POST claude/leads/{id}/calendar-event, que SÍ se puede llamar con X-Claude-Task-Key) ni le avisa al lead por WhatsApp del cambio de horario (DemoScheduledWhatsappService sólo dispara desde el flujo del agente).',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL, sólo dígitos', 'que_es' => 'Id del lead a editar. Se nombra de a uno: no hay lote.'],
                ['nombre' => 'campos', 'obligatorio' => true, 'validacion' => 'required|array|min:1', 'que_es' => '🔴 Objeto con los campos a escribir. Cualquier clave que no esté en la lista blanca es 422.'],
                ['nombre' => 'campos.contact_name', 'obligatorio' => false, 'validacion' => 'texto, máx 150 caracteres', 'que_es' => 'Nombre de la persona, para corregir lo que el lead dijo en la conversación.'],
                ['nombre' => 'campos.company_name', 'obligatorio' => false, 'validacion' => 'texto, máx 150 caracteres', 'que_es' => 'Nombre del negocio.'],
                ['nombre' => 'campos.business_type', 'obligatorio' => false, 'validacion' => 'texto, máx 80 caracteres', 'que_es' => 'Rubro.'],
                ['nombre' => 'campos.notes', 'obligatorio' => false, 'validacion' => 'texto, máx 20000 caracteres Y máx 65535 BYTES (la columna es TEXT y un emoji ocupa cuatro)', 'que_es' => '🔴 El lugar del proyecto para el "dolor detectado": no existe columna `dolor`. 🔴 REEMPLAZA lo que haya: leelo antes de escribirlo (GET claude/query?model=lead, o el dry_run de acá, que lo devuelve en diff.notes.actual) o le borrás la nota a una persona.'],
                ['nombre' => 'campos.email', 'obligatorio' => false, 'validacion' => 'texto, máx 150, con formato de mail (FILTER_VALIDATE_EMAIL)', 'que_es' => 'Mail del lead, que se carga después de la conversación.'],
                ['nombre' => 'campos.demo_id', 'obligatorio' => false, 'validacion' => 'id numérico de una instancia de demo existente', 'que_es' => '🔴 La INSTANCIA del pool sobre la que corre la demo. OBLIGATORIA para agendar: sin ella no sale el mail de demo ni hay sobre qué emitir el token de ingreso. Los válidos salen de GET claude/leads/{id}/availability (clave `demos`).'],
                ['nombre' => 'campos.demo_date', 'obligatorio' => false, 'validacion' => 'fecha absoluta Y-m-d, sin hora y sin expresiones relativas', 'que_es' => '🔴 Cambiarlo es REAGENDAR: dispara el reset de los flags de recordatorio, exige demo_id y valida el horario contra la grilla.'],
                ['nombre' => 'campos.demo_start_time', 'obligatorio' => false, 'validacion' => 'texto HH:MM o HH:MM:SS en 24 horas', 'que_es' => 'Hora de inicio de la demo. 🔴 Es la que se compara contra la grilla, y cambiarla sola también es reagendar.'],
                ['nombre' => 'campos.demo_end_time', 'obligatorio' => false, 'validacion' => 'texto HH:MM o HH:MM:SS en 24 horas', 'que_es' => 'Hora de fin de la demo. Cambiarla también es reagendar: el check de fin se calcula contra ella.'],
                ['nombre' => 'campos.demo_flexible', 'obligatorio' => false, 'validacion' => 'booleano — NO admite null (la columna es NOT NULL)', 'que_es' => 'Si el lead aceptó una franja flexible en vez de un horario fijo.'],
                ['nombre' => 'campos.meeting_scheduled_at', 'obligatorio' => false, 'validacion' => 'fecha y hora absoluta (Y-m-d H:i y variantes), dentro del rango de un timestamp de MySQL (1970-2038)', 'que_es' => 'La videollamada con el closer.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO se escribe nada: devuelve el diff campo por campo, el turno resultante y las advertencias.'],
                ['nombre' => 'confirm_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Cantidad de campos que CAMBIAN. Tiene que coincidir exacto con el cambiarian de la simulación.'],
                ['nombre' => 'permitir_horario_fuera_de_grilla', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT false', 'que_es' => 'Escribe el turno aunque el horario no figure entre los libres, que es lo mismo que hace el panel al forzar. NUNCA saltea el lock de la demo: dos requests sobre la misma instancia se siguen serializando.'],
                ['nombre' => 'permitir_fecha_pasada', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT false', 'que_es' => 'Deja escribir un turno que ya pasó. Es para corregir un registro viejo, no para agendar: sobre un lead en demo_agendada una fecha pasada dispara la tanda de seguimiento del cron.'],
            ],
        ],

        /* ---------------------------------------------------------- Clientes y versiones: lectura */

        'GET api/claude/clients' => [
            'para_que'     => 'Listado de clientes filtrable y paginado por cursor, con proyección flaca e include opcional. Las api keys NO viajan.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/clients/{id}' => [
            'para_que'     => 'Ficha completa de un cliente: APIs, horarios crudos y resueltos, estado del negocio ahora, próximo cierre y los últimos upgrades.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/clients/{id}/schedule' => [
            'para_que'     => 'Horarios de un cliente con la regla de precedencia YA RESUELTA: los días cargados, la ventana vigente, si el negocio está abierto en este instante y el próximo cierre. Es lo que hay que mirar antes de arrancar cualquier etapa post-cierre.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'PUT api/claude/clients/{id}/schedule' => [
            'para_que'     => 'Carga o reemplaza los horarios comerciales de un cliente sin pasar por el modal del admin. Es lo que hace que el gate de horario del post-cierre pueda decidir: un cliente sin horarios queda `sin_configurar`, y `sin_configurar` rechaza igual que `abierto`.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito valida el payload entero y no escribe una fila.',
                'confirm_client_name obligatorio cuando dry_run es false, y el error no revela el nombre correcto.',
                'Toda validación que falla es 422 y no escribe nada: se valida el conjunto entero antes de abrir la transacción.',
                'El push al empresa-api del cliente se encola, nunca corre adentro del request.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente cuyos horarios se reemplazan.'],
                ['nombre' => 'dias', 'obligatorio' => true, 'validacion' => 'array (puede venir vacío)', 'que_es' => '🔴 El conjunto COMPLETO: lo que no viaja acá se borra. Cada ítem es {"dia": <una de day_keys>, "rangos": [{"desde":"HH:MM","hasta":"HH:MM"}]}. Un día con "rangos": [] es CERRADO; un día que no aparece hereda de la fila "todos" y, si no hay, queda SIN CONFIGURAR (que no es cerrado). Un rango no cruza la medianoche: hasta > desde, y dos rangos del mismo día no se solapan.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano, 1/0', 'que_es' => 'Por defecto true. En true valida todo, devuelve dias_antes y dias_despues, y no escribe nada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'string de hasta 190; tiene que coincidir con clients.name (trim + minúsculas)', 'que_es' => 'Obligatorio cuando dry_run es false. Es la redundancia contra escribirle los horarios al cliente equivocado: el id numérico no tiene ninguna.'],
            ],
        ],
        'POST api/claude/clients/{id}/schedule/sync' => [
            'para_que'     => 'Reintenta el push de los horarios del cliente a su empresa-api.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Idempotente: reenvía los horarios que el admin ya tiene cargados, no los modifica.',
                'Nunca hace el HTTP adentro del request: encola SyncClientScheduleJob.',
                'Todos los desenlaces terminan escritos en clients.schedule_sync_status: no hay camino silencioso.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente cuyos horarios se reenvían. ⚠️ Es el ÚNICO parámetro: este endpoint no lee cuerpo, así que mandar un body no cambia nada.'],
            ],
        ],
        /* Ventas y comisiones (misión ventas-y-comisiones, 28/10/2026). */
        'GET api/claude/clients/{id}/ventas' => [
            'para_que'     => 'Las ventas de un cliente (lo que se le vendió: sistema de gestión, e-commerce, agentes, desarrollo a medida u otro), cada una con sus cuotas, lo que cobró y lo que falta (`resumen`) y la comisión del vendedor (`comision`: total, liberada a medida que paga el cliente, pagada, a_pagar, por_liberar). Además: `cuotas_sin_venta` (cuotas viejas sin agrupar), `vendedores`, `productos` (clave → etiqueta), `precios` de lista (gestion 800, ecommerce 500, agentes 500, los_tres 1500, contado_descuento 10, en USD), `porcentaje_por_defecto` (15), `porcentaje_con_gestion` (30 si el cliente está en Suscripción), `es_suscripcion` y `vendedor_por_defecto`. Es el mismo JSON que la pestaña Ventas del admin. Cobro dudoso: en la raíz vienen `cobro_dudoso`, `cobro_dudoso_desde` y `cobro_dudoso_motivo` (la marca del cliente), y en cada venta `cobro_dudoso` y `resumen.en_espera` / `resumen.por_cobrar` (`pendiente = en_espera + por_cobrar`: lo de un cliente de dudoso cobro está en stand-by, ni a cobrar ni perdido). Mirá esto antes de dar de alta una venta para no duplicarla.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/clients/{id}/ventas' => [
            'para_que'     => 'Da de alta una venta a un cliente, con sus cuotas y la comisión del vendedor, con origen=claude. Sin `monto_total` sale el precio de lista (los tres sistemas = 1500 en 3 cuotas; contado = 10 % menos en 1 cuota; gestión 800, e-commerce 500, agentes 500); desarrollo y otro exigen importe. Sin `cuotas`, el total se reparte en `cantidad_cuotas` cuotas iguales mensuales desde `primer_vencimiento` (la última absorbe el redondeo). El dry_run devuelve {dry_run:true, escribio:false, client, venta (la que se crearía, con id null), nota}; el real devuelve 201 con {dry_run:false, escribio:true, venta} más la lista entera del cliente (la misma forma que el GET).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito arma la venta entera (cuotas y comisión) con el mismo servicio que después guarda, y no escribe una fila.',
                'confirm_client_name obligatorio cuando dry_run es false: tiene que coincidir con clients.name (recorte y minúsculas) y el error no revela el nombre correcto.',
                'Toda validación que falla es 422 y no escribe nada, también en el dry_run: si vienen `cuotas` y `monto_total` y la suma no coincide, 422; desarrollo u otro sin importe, 422; total en cero, 422.',
                'Alta de cuotas y venta en una sola transacción: o queda todo o no queda nada.',
                'No toca el sistema del cliente ni le manda nada a nadie: escribe solo filas del admin (ventas y licencia_cuotas).',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El cliente al que se le vende.'],
                ['nombre' => 'productos', 'obligatorio' => true, 'validacion' => 'array, al menos uno, sin repetidos; cada uno en gestion|ecommerce|agentes|desarrollo|otro', 'que_es' => 'Lo que se vende.'],
                ['nombre' => 'descripcion', 'obligatorio' => false, 'validacion' => 'texto, hasta 255', 'que_es' => 'Qué se vendió, en palabras. Mandala siempre para desarrollo y otro.'],
                ['nombre' => 'fecha', 'obligatorio' => false, 'validacion' => 'fecha', 'que_es' => 'Fecha de la venta; default hoy.'],
                ['nombre' => 'monto_total', 'obligatorio' => false, 'validacion' => 'número >= 0', 'que_es' => 'Total de la venta. Sin él, el precio de lista (en USD); obligatorio con desarrollo u otro y en cualquier venta en ARS (los precios de lista son en dólares). Si vienen `cuotas`, tiene que coincidir con su suma.'],
                ['nombre' => 'moneda', 'obligatorio' => false, 'validacion' => 'USD|ARS', 'que_es' => 'Moneda de la venta y de su comisión; default USD.'],
                ['nombre' => 'contado', 'obligatorio' => false, 'validacion' => 'booleano', 'que_es' => 'Pago de contado: 10 % de descuento sobre el precio de lista y una sola cuota.'],
                ['nombre' => 'cuotas', 'obligatorio' => false, 'validacion' => 'array de {monto, vencimiento?}, 1 a 60', 'que_es' => 'Las cuotas una por una. Si vienen, su suma es el total.'],
                ['nombre' => 'cantidad_cuotas', 'obligatorio' => false, 'validacion' => 'entero 1 a 60', 'que_es' => 'En cuántas cuotas iguales se reparte el total si no vienen `cuotas`. Default 1; 3 para los tres sistemas sin contado.'],
                ['nombre' => 'primer_vencimiento', 'obligatorio' => false, 'validacion' => 'fecha', 'que_es' => 'Vencimiento de la primera cuota repartida; las siguientes, un mes después cada una. Default la fecha de la venta.'],
                ['nombre' => 'vendedor_admin_id', 'obligatorio' => false, 'validacion' => 'entero, admin existente, o null', 'que_es' => 'Vendedor al que se le liquida la comisión. Ausente = el vendedor por defecto (el único admin marcado como vendedor, si hay uno solo); null explícito = sin vendedor.'],
                ['nombre' => 'comision_porcentaje', 'obligatorio' => false, 'validacion' => 'número 0 a 100', 'que_es' => 'Porcentaje de comisión. Default 15; 30 si la venta incluye gestión y el cliente está en Suscripción.'],
                ['nombre' => 'observacion', 'obligatorio' => false, 'validacion' => 'texto', 'que_es' => 'Nota libre de la venta.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano; default true', 'que_es' => 'true = solo muestra la venta que se crearía.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'texto; obligatorio con dry_run=false', 'que_es' => 'El nombre del cliente (clients.name), para confirmar contra quién se escribe.'],
            ],
        ],
        /* Pagos y ajustes de las ventas (misión cobro-dudoso-y-pagos-ventas, 30/10/2026): lo que hasta
           entonces solo hacía el panel. Los cuatro reusan los servicios del admin y llevan los frenos del bloque. */
        'POST api/claude/clients/{id}/cuotas/{cuotaId}/pago' => [
            'para_que'     => 'Registra el pago (entero o parcial) de UNA cuota de un cliente: es lo que hace el botón de cobrar de la pestaña Ventas. La cuota tiene que ser DEL cliente de la ruta (si no, 404). Sin `monto` paga LO QUE FALTA de esa cuota y queda `pagada`; con `monto` suma ese importe a lo ya pagado (parcial si no alcanza; si supera lo que falta de la cuota, 422, y un `monto` vacío también es 422: para pagar lo que falta se omite la clave); `completa=true` la da por pagada aunque falte (un descuento, una diferencia de cambio). Al cobrar se libera la comisión del vendedor de la venta, proporcional a lo cobrado. El dry_run devuelve {dry_run:true, escribio:false, client, monto, cuota_antes, cuota_despues, venta (cómo quedaría, con su comisión), nota}; el real devuelve {dry_run:false, escribio:true, cuota, venta} más la lista entera del cliente (la misma forma que GET clients/{id}/ventas).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito corre el mismo servicio que escribe adentro de una transacción que se deshace, y no deja ni una fila.',
                'confirm_client_name obligatorio cuando dry_run es false: tiene que coincidir con clients.name (recorte y minúsculas) y el error no revela el nombre correcto.',
                'La cuota se busca dentro del cliente: una cuota de otro cliente es 404, igual que una inexistente.',
                'Una cuota ya pagada (nada pendiente) no recibe otro pago: 422. Es lo que evita duplicar un cobro si Claude reintenta.',
                'No toca el sistema del cliente ni le manda nada a nadie: escribe solo la fila de licencia_cuotas.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El cliente dueño de la cuota.'],
                ['nombre' => '{cuotaId} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La cuota (id de licencia_cuotas, el de GET clients/{id}/ventas → ventas[].cuotas[].id).'],
                ['nombre' => 'monto', 'obligatorio' => false, 'validacion' => 'número 0.01 a 999999999', 'que_es' => 'Lo que entró AHORA (se suma a lo ya pagado), en la moneda de la cuota. Sin él, lo que falta de la cuota.'],
                ['nombre' => 'fecha_pago', 'obligatorio' => false, 'validacion' => 'fecha', 'que_es' => 'Cuándo se cobró; default hoy.'],
                ['nombre' => 'observacion', 'obligatorio' => false, 'validacion' => 'texto, hasta 1000', 'que_es' => 'Reemplaza la nota de la cuota si viene; vacía o ausente = no la toca.'],
                ['nombre' => 'completa', 'obligatorio' => false, 'validacion' => 'booleano', 'que_es' => 'Dar la cuota por pagada aunque lo pagado no llegue a su monto.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano; default true', 'que_es' => 'true = solo muestra cómo quedaría.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'texto; obligatorio con dry_run=false', 'que_es' => 'El nombre del cliente (clients.name), para confirmar contra quién se escribe.'],
            ],
        ],
        'POST api/claude/clients/{id}/cuotas/{cuotaId}/eliminar' => [
            'para_que'     => 'Elimina UNA cuota de un cliente (una cuota mal cargada o que ya no corresponde). Si la cuota es de una venta, el total de la venta y su comisión BAJAN en lo que valía (el total de una venta es siempre la suma de sus cuotas). Se RECHAZA con 422 una cuota con plata cobrada: borrarla sería borrar un cobro real. El dry_run devuelve {dry_run:true, escribio:false, client, cuota, venta_antes, venta_despues (total y comisión de la venta antes y después; null si la cuota es suelta), nota}; el real devuelve {dry_run:false, escribio:true, cuota_eliminada, venta} más la lista entera del cliente.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: simula la baja adentro de una transacción que se deshace y muestra el total y la comisión de la venta antes y después.',
                'confirm_client_name obligatorio cuando dry_run es false: tiene que coincidir con clients.name (recorte y minúsculas) y el error no revela el nombre correcto.',
                'La cuota se busca dentro del cliente: una cuota de otro cliente es 404.',
                '🔴 Una cuota con plata cobrada (monto_cobrado > 0, incluida una `pagada`) es 422 y no se borra: no es un ajuste, es deshacer un cobro.',
                'No toca el sistema del cliente: borra solo la fila de licencia_cuotas y recalcula la venta con el mismo servicio del panel.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El cliente dueño de la cuota.'],
                ['nombre' => '{cuotaId} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La cuota a eliminar.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano; default true', 'que_es' => 'true = solo muestra cómo quedaría la venta.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'texto; obligatorio con dry_run=false', 'que_es' => 'El nombre del cliente (clients.name), para confirmar contra quién se escribe.'],
            ],
        ],
        'POST api/claude/ventas/{ventaId}/comision/pagos' => [
            'para_que'     => 'Registra un pago al vendedor de una venta, a cuenta de su comisión (lo que hace el botón de pagar comisión de Cobranzas → Comisiones). La comisión se libera a medida que el cliente paga; sin `monto` se paga TODO lo que está a pagar hoy (`comision.a_pagar` de GET clients/{id}/ventas). Con `monto` explícito se puede pagar más de lo liberado (un adelanto): se registra igual y la respuesta marca `excede: true`. El cliente se saca de la venta: `confirm_client_name` es el nombre del cliente de la venta. El dry_run devuelve {dry_run:true, escribio:false, client, venta_id, pago, excede, comision_antes, comision_despues, nota}; el real devuelve 201 con {dry_run:false, escribio:true, pago, excede, venta} más la lista entera del cliente.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: registra el pago adentro de una transacción que se deshace y muestra la comisión antes y después.',
                'confirm_client_name obligatorio cuando dry_run es false: tiene que coincidir con el nombre del cliente de la venta (recorte y minúsculas) y el error no lo revela.',
                'Una venta SIN vendedor es 422: no hay a quién pagarle.',
                'Sin `monto` y sin nada a pagar es 422: no se registran pagos de cero.',
                'No toca el sistema del cliente ni le manda nada a nadie: escribe solo una fila de comision_pagos.',
            ],
            'parametros'   => [
                ['nombre' => '{ventaId} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La venta cuya comisión se paga (id de GET clients/{id}/ventas → ventas[].id).'],
                ['nombre' => 'monto', 'obligatorio' => false, 'validacion' => 'número 0.01 a 999999999', 'que_es' => 'Lo que se le paga ahora, en la moneda de la venta. Sin él, todo lo que está a pagar.'],
                ['nombre' => 'fecha', 'obligatorio' => false, 'validacion' => 'fecha', 'que_es' => 'Cuándo se le pagó; default hoy.'],
                ['nombre' => 'nota', 'obligatorio' => false, 'validacion' => 'texto, hasta 255', 'que_es' => 'Nota libre del pago.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano; default true', 'que_es' => 'true = solo muestra el pago y la comisión resultante.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'texto; obligatorio con dry_run=false', 'que_es' => 'El nombre del cliente de la venta (clients.name), para confirmar contra quién se escribe.'],
            ],
        ],
        'POST api/claude/clients/{id}/cobro-dudoso' => [
            'para_que'     => 'Enciende o apaga la marca de «cliente de dudoso cobro» (stand-by): la deuda pendiente de sus ventas deja de contar como plata a cobrar (`resumen.por_cobrar`) y pasa a `resumen.en_espera`, sin darse por perdida; `resumen.pendiente` sigue siendo el total. Es una marca del CLIENTE y afecta solo a ventas y cuotas de licencia (las mensualidades tienen su propia marca) y NO cambia la comisión del vendedor. Encender fija `cobro_dudoso_desde` en hoy (si ya estaba encendido conserva la fecha) y guarda el motivo si viene; apagar deja los tres campos en false/null/null. El dry_run devuelve {dry_run:true, escribio:false, client, marca_antes, marca_despues, resumen_por_moneda_antes, resumen_por_moneda_despues, nota}; el real devuelve {dry_run:false, escribio:true} más la lista entera del cliente (con cobro_dudoso, cobro_dudoso_desde y cobro_dudoso_motivo en la raíz).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: muestra la marca y el resumen por moneda antes y después, y no escribe.',
                'confirm_client_name obligatorio cuando dry_run es false: tiene que coincidir con clients.name (recorte y minúsculas) y el error no revela el nombre correcto.',
                'Reversible: apagarla devuelve la deuda a «por cobrar»; no borra ni modifica ninguna venta, cuota ni comisión.',
                'No toca el sistema del cliente ni le manda nada a nadie: escribe solo tres columnas de clients.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El cliente a marcar o desmarcar.'],
                ['nombre' => 'cobro_dudoso', 'obligatorio' => true, 'validacion' => 'booleano', 'que_es' => 'true = marcar como de dudoso cobro; false = quitar la marca.'],
                ['nombre' => 'motivo', 'obligatorio' => false, 'validacion' => 'texto, hasta 255', 'que_es' => 'Por qué se lo marca. Vacío o ausente = no cambia el motivo que ya hubiera.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano; default true', 'que_es' => 'true = solo muestra cómo quedaría.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'texto; obligatorio con dry_run=false', 'que_es' => 'El nombre del cliente (clients.name), para confirmar contra quién se escribe.'],
            ],
        ],
        'POST api/claude/clients/{id}/catalogo/clave' => [
            'para_que'     => 'Deja la clave de la API del cliente (clients.api_key, la que el admin ya tiene) escrita como ADMIN_API_INBOUND_KEY en el .env de CADA frente (client_apis) de ese cliente, por SSH. Es lo que le da al motor de /categorizar acceso a las rutas admin-sync/catalogo/* del cliente SIN que la clave pase por la máquina de Lucas: el admin la ESCRIBE en el servidor del cliente. Respuesta 200: {client_id, dry_run, api_key_en_el_admin: presente|falta|generada, frentes: [{client_api_id, hosting_type, path, es_la_activa, estado: igual|falta|distinta|sin_env|error, accion: ninguna|escribir|escrita|fallo, error}], listo}. `estado` es lo que se ENCONTRÓ y `accion` lo que se hizo (o se haría en dry_run); un frente `distinta` (su .env ya tiene OTRA clave) NO se reemplaza salvo que venga `pisar_distintas: true`: queda `estado: distinta`, `accion: ninguna`, `error: tiene otra clave; para reemplazarla, pisar_distintas: true` y cuenta en contra de `listo`. El dry_run predice lo mismo que haría aplicar con los mismos parámetros: sin el campo (o en false) el frente sale así, con `pisar_distintas: true` sale `accion: escribir`. `es_la_activa` marca EL frente al que le habla el admin: el que resuelve ClientEmpresaApiUrlResolver, el MISMO al que le pega el puente (la API activa del cliente si tiene una URL válida; si no, la primera con URL válida). Ojo: puede no coincidir con `es_la_activa` de la ficha claude/clients/{id}, que mira solo clients.active_client_api_id. `listo` depende de ese frente: con el activo determinado es true cuando ESE frente tiene la clave (`igual`, o `escrita` en esta llamada) y NINGÚN frente quedó en `falta` ni en `distinta` sin escribir; un frente INACTIVO en `error` o `sin_env` se informa con su `error` pero NO traba `listo` (el puente no le habla, y si trabara dejaría al cliente sin listo para siempre), y el ACTIVO en `error` o `sin_env` lo deja en false. Si no se puede determinar cuál es el activo (ningún frente tiene una URL válida) vale la regla de siempre: al menos un frente `igual` o `escrita` y ninguno en `falta`, `distinta` o `error`, con `sin_env` neutral y todos `sin_env` en false. En dry_run el cálculo es sobre lo que se encontró: un `falta` o `distinta` (activo o no) deja `listo` en false porque no se escribió nada. Errores: 404 cliente_inexistente, 409 sin_frentes, 422 validacion (con `detalle` por campo) y 500 clave_no_guardada (falló la base al guardar la clave recién generada: texto fijo, sin nada de lo que dijo la base, y no se escribió nada en ningún servidor); en todos, `error` es el código y `mensaje` el texto. La respuesta NUNCA lleva el valor de ninguna clave.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'dry_run por defecto true: lee el .env de cada frente y dice qué escribiría (estado y accion por frente); no respalda, no escribe y no genera ninguna clave. Con la clave del admin vacía lo dice (api_key_en_el_admin=falta).',
                'confirm_client_name obligatorio con dry_run=false: tiene que coincidir con clients.name (recorte y minúsculas) y el error no revela el nombre correcto. Es el mismo freno que PUT claude/clients/{id}/schedule.',
                'Lista cerrada de parámetros: cualquier otra clave es 422 validacion. Y dry_run solo acepta true, false, 1 o 0: un valor que no se entiende es 422 y NO un "aplicar".',
                '🔴 Antes de escribir en un frente se respalda su .env (.env.bak-<AAAAMMDDHHMMSS>) y después se RELEE el archivo: si la variable no quedó escrita, ese frente es estado=error y no se da por hecho. Nunca crea un .env: un frente sin .env es estado=sin_env y no se toca (escribir ahí dejaría un archivo en el servidor equivocado); un frente inactivo sin .env no traba `listo`, y el activo sin .env lo deja en false.',
                '🔴 Un frente que ya tiene OTRA clave (estado=distinta) no se pisa si no se pidió: sin pisar_distintas=true queda accion=ninguna con el motivo en `error` y listo=false, porque esa otra clave la puede estar usando alguien que el admin no conoce. Un frente `falta` (sin la variable, o vacía) se escribe siempre. El dry_run predice lo mismo que haría aplicar con los mismos parámetros: sin pisar_distintas el frente distinta sale con accion=ninguna y ese motivo, y con pisar_distintas=true sale con accion=escribir.',
                '🔴 Verifica que el .env sea de ESTE cliente: si trae USER_ID y no coincide con clients.user_id (comparado como texto y sin espacios), el frente queda estado=error con el motivo `dueno_distinto` y no se escribe nada (la carpeta apunta al sistema de otro dueño, o está mal cargada en el admin). Sin USER_ID en el .env, o con la variable vacía, se sigue como siempre; con el cliente sin user_id cargado y un .env que sí lo trae, también es error.',
                '🔴 No opera en una carpeta que no se pueda identificar: un frente de shared con el path vacío, `/` o con segmentos vacíos, `.` o `..` (`x/..` se resuelve a la raíz de la cuenta compartida, `../x` sale de ella), o de VPS con un vps_path que no sea un nombre simple (con `/`, `\` o `.`/`..`), queda estado=error con el motivo y no se lee ni se escribe nada. Las barras de los extremos del path no cuentan.',
                '🔴 `listo` mira al frente ACTIVO (es_la_activa: el mismo al que le habla el puente), no a todos: un frente inactivo en error o sin_env se informa pero no traba, y uno inactivo en falta o distinta SIN escribir sí traba (quedaría a medias: el día que lo activen no tiene la clave). Sin un activo determinable vale la regla de siempre (al menos uno con la clave y ninguno en falta, distinta o error).',
                'Un frente que falla (SSH caído, sin permisos, ruta imposible de resolver) NO frena a los otros: queda estado=error con el motivo y los demás siguen. Si es el frente ACTIVO, listo=false; si es uno inactivo, se informa con su error y no traba listo.',
                '🔴 Ni la respuesta, ni los mensajes de error, ni el log llevan el valor de ninguna clave (ni la del admin ni la que ya estaba en el .env): solo estados. Todo texto que viene del servidor pasa por un tapador de claves antes de salir.',
                'Con la clave del admin vacía y dry_run=false, la genera (Str::random(40), igual que el alta del cliente) y la guarda con un UPDATE condicional —dos llamadas simultáneas no pueden generar dos claves distintas— ANTES de escribir en el primer servidor; la respuesta dice api_key_en_el_admin=generada.',
                'Idempotente: un frente cuyo .env ya tiene la clave igual (hash_equals) no se respalda ni se toca. Se escribe en TODOS los frentes del cliente, no solo en el activo: en shared son dos carpetas y una rotación puede activar cualquiera.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente cuya clave se escribe en el .env de sus frentes.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano estricto (true, false, 1, 0) — 🔴 DEFAULT true', 'que_es' => 'En true (el default) solo mira y dice qué escribiría. Con false respalda y escribe de verdad; exige confirm_client_name.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'string de hasta 190; tiene que coincidir con clients.name (trim + minúsculas)', 'que_es' => 'Obligatorio cuando dry_run es false. Es la redundancia contra escribirle la clave al cliente equivocado: el id numérico no tiene ninguna.'],
                ['nombre' => 'pisar_distintas', 'obligatorio' => false, 'validacion' => 'booleano estricto (true, false, 1, 0) — 🔴 DEFAULT false', 'que_es' => 'Un frente cuyo .env ya tiene OTRA clave (estado distinta) solo se escribe con true; sin él (o en false) queda accion=ninguna con el motivo en `error` y listo=false. El dry_run predice lo mismo que haría aplicar con el mismo valor: sin el campo sale accion=ninguna con ese motivo, con true sale accion=escribir. Los frentes `falta` e `igual` no dependen de este campo.'],
            ],
        ],
        'POST api/claude/clients/{id}/catalogo/puente' => [
            'para_que'     => 'PUENTE hacia las nueve rutas admin-sync/catalogo/* del empresa-api de UN cliente: reenvía un pedido de una lista blanca con la clave del cliente (clients.api_key, header X-Admin-Api-Key) y devuelve lo que contestó. Es lo que le deja al motor de /categorizar usar el catálogo del cliente sin que la clave del cliente pase nunca por la máquina de Lucas. Pedido: {metodo: GET|POST, ruta: "/<ruta>[?query]", cuerpo?: objeto o lista (solo POST)}; la ruta es relativa a /api/admin-sync/catalogo y su query se reenvía tal cual. Lista blanca (método + ruta; {n} = dígitos): GET /resumen, GET /articulos, POST /categorias/propuestas, GET /categorias/propuestas/actual, GET /categorias/propuestas/{n}, POST /categorias/propuestas/{n}/asignaciones, GET /categorias/propuestas/{n}/pendientes, POST /categorias/propuestas/{n}/listo y POST /categorias/propuestas/{n}/descartar. 🔴 Cuando el admin LLEGÓ al cliente contesta SIEMPRE 200 {puente: true, status: <el HTTP del cliente>, cuerpo: <su JSON decodificado o null>, cuerpo_crudo: <hasta 2000 caracteres si no era JSON, si no null>}: un 401, un 404 (su versión todavía no tiene la ruta) o un 500 del cliente viajan ADENTRO, en `status`. Cuando el admin NO pudo: 404 cliente_inexistente, 409 sin_api_key, 409 sin_url, 422 validacion (con `detalle` por campo) o 502 cliente_no_responde (timeout o conexión). En todos esos, `error` es el código y `mensaje` el texto; un 404 SIN `error` es el de Laravel (este admin no tiene la ruta).',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                '🔴 Lista blanca de nueve rutas (método + ruta, con regex anclada): cualquier otra combinación es 422 validacion y NO se le pega al cliente (rutas_permitidas viaja en la respuesta). No es un proxy general: el path lo fija la lista —no hay forma de llegar a otra ruta del cliente con `..` o una barra de más— y solo la query viaja libre. Es la única defensa que importa, porque el admin lleva la clave de cada cliente.',
                'Lista cerrada de parámetros (metodo, ruta, cuerpo): cualquier otra clave es 422 validacion. La ruta lleva `/` inicial, hasta 2000 caracteres y ningún carácter de control ni `#`; `cuerpo` tiene que ser un objeto o una lista y solo va en un POST (en un GET con contenido es 422).',
                'Un byte que no es UTF-8 válido en `metodo`, `ruta` o `cuerpo` (por ejemplo un %FF crudo que llega por la query) es 422 validacion, nunca un 500: lo que se repite en un mensaje se sanea. Un {id} de la ruta que no es un número ni un uuid es 404 cliente_inexistente sin consultar la base.',
                '🔴 `_method` en la query de `ruta` o en el PRIMER nivel de `cuerpo` es 422 validacion, sin importar mayúsculas ni cómo venga escrito en la query (`.method`, `%5Fmethod`, `_method[]`: PHP le cambia el nombre antes de que Laravel lo vea). El cliente es una app Laravel y en un POST toma `_method` como el método REAL: sin esta guarda, un POST de la lista blanca podría llegar como un DELETE y esquivar la lista de métodos. Más adentro del cuerpo no tiene efecto y no se mira.',
                '🔴 Ni la respuesta, ni los mensajes de error, ni el log llevan la clave del cliente: el cuerpo que devuelve el cliente se tapa ANTES de decodificarlo y las excepciones de HTTP también. El log de cada llamada lleva método, ruta SIN query, status y milisegundos: nunca el cuerpo ni la clave. Y las URLs que cita el mensaje de una falla de red (en la respuesta y en el log) salen sin su query, solo con el path.',
                'No sigue redirecciones: Guzzle no saca un header propio como X-Admin-Api-Key al redirigir a otro host, así que un 3xx vuelve como respuesta (con su `status`) en vez de mandar la clave del cliente a donde apunte.',
                'No reintenta, ni un 5xx ni un timeout: un POST del catálogo (crear una propuesta, cargar asignaciones) no es idempotente del otro lado, y quien sabe cuál pedido se puede repetir es el motor. Timeout de 60 s por llamada (el default de services.client_api.catalogo_timeout: esa clave no está definida en config/services.php, así que vale 60).',
                'Sin api_key en el admin es 409 sin_api_key y sin una URL de empresa-api resoluble es 409 sin_url, ANTES de llamar a nadie. Se le habla a la API activa del cliente (la que sirve hoy).',
                'El cuerpo de un POST viaja como el JSON ORIGINAL: un objeto con claves numéricas ({"12": "Fijaciones"}, artículo → categoría) llega como objeto y no como lista, y un {} vuelve como {}. Un cuerpo ausente viaja como {}.',
                'Cada llamada cuenta contra el limitador de tasa del grupo api (60 req/min por IP en producción, y claude/* no tiene usuario de Sanctum): un motor que baja miles de artículos y sube asignaciones tiene que espaciar los pedidos o reintentar un 429.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente cuyo empresa-api se consulta.'],
                ['nombre' => 'metodo', 'obligatorio' => true, 'validacion' => 'GET o POST (sin importar mayúsculas); otro método es 422', 'que_es' => 'El método con el que el admin le pega al cliente.'],
                ['nombre' => 'ruta', 'obligatorio' => true, 'validacion' => 'string de hasta 2000, con `/` inicial, sin caracteres de control ni `#`; el path tiene que estar en la lista blanca para ese método', 'que_es' => 'Ruta relativa a /api/admin-sync/catalogo, con su query si la trae (/articulos?desde_id=100&limite=500). La query se reenvía tal cual.'],
                ['nombre' => 'cuerpo', 'obligatorio' => false, 'validacion' => 'objeto o lista JSON; solo en un POST (en un GET con contenido es 422)', 'que_es' => 'El cuerpo del POST, tal cual lo recibe la ruta del cliente (por ejemplo las asignaciones de una propuesta).'],
            ],
        ],
        'POST api/claude/clients/{id}/admin-sync/puente' => [
            'para_que'     => 'PUENTE hacia siete rutas admin-sync/* del empresa-api de UN cliente: reenvía un pedido de una lista blanca con la clave del cliente (clients.api_key, header X-Admin-Api-Key) y devuelve lo que contestó. Es lo que le deja a /mostrador (las seis rutas admin-sync/mostrador/*) y a los pasos manuales de update-default-version de /implementar e /instalar-cliente pegarle a un cliente que ya EXIGE la clave, sin que la clave del cliente pase nunca por la máquina de Lucas. Pedido: {metodo: GET|POST|PUT, ruta: "/<ruta>[?query]", cuerpo?: objeto o lista (solo POST y PUT)}; la ruta es relativa a /api/admin-sync y su query se reenvía tal cual. Lista blanca (método + ruta; {n} = dígitos): GET /mostrador/duenos, POST /mostrador/hechos, GET /mostrador/reportes/{n}, PUT /mostrador/reportes/{n}, GET /mostrador/contexto/{n}, PUT /mostrador/memoria/{n} y PUT /update-default-version. 🔴 Cuando el admin LLEGÓ al cliente contesta SIEMPRE 200 {puente: true, status: <el HTTP del cliente>, cuerpo: <su JSON decodificado o null>, cuerpo_crudo: <hasta 2000 caracteres si no era JSON, si no null>}: un 401, un 404 (su versión todavía no tiene la ruta), un 202 (POST /mostrador/hechos con un catálogo grande: se hace polling con GET /mostrador/reportes/{n}) o un 500 del cliente viajan ADENTRO, en `status`. Cuando el admin NO pudo: 404 cliente_inexistente, 409 sin_api_key, 409 sin_url, 422 validacion (con `detalle` por campo) o 502 cliente_no_responde (timeout o conexión). En todos esos, `error` es el código y `mensaje` el texto; un 404 SIN `error` es el de Laravel (este admin no tiene la ruta).',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                '🔴 Lista blanca de siete entradas (método + ruta, con regex anclada): cualquier otra combinación es 422 validacion y NO se le pega al cliente (rutas_permitidas viaja en la respuesta). No es un proxy general: el path lo fija la lista —no hay forma de llegar a otra ruta del cliente con `..` o una barra de más— y solo la query viaja libre. NO incluye ninguna de las rutas que escriben datos del negocio (sistema-query, ai-excel-import, mensualidad-update...): esas tienen su propio servicio en el admin. Es la única defensa que importa, porque el admin lleva la clave de cada cliente.',
                'Lista cerrada de parámetros (metodo, ruta, cuerpo): cualquier otra clave es 422 validacion. La ruta lleva `/` inicial, hasta 2000 caracteres y ningún carácter de control ni `#`; `cuerpo` tiene que ser un objeto o una lista y solo va en un POST o un PUT (en un GET con contenido es 422).',
                'Un byte que no es UTF-8 válido en `metodo`, `ruta` o `cuerpo` es 422 validacion, nunca un 500. Un {id} de la ruta que no es un número ni un uuid es 404 cliente_inexistente sin consultar la base.',
                '🔴 `_method` en la query de `ruta` o en el PRIMER nivel de `cuerpo` es 422 validacion, sin importar mayúsculas ni cómo venga escrito (`.method`, `%5Fmethod`, `_method[]`): el cliente es una app Laravel y en un POST toma `_method` como el método REAL, y sin esta guarda un POST de la lista blanca podría llegar como un DELETE.',
                '🔴 Ni la respuesta, ni los mensajes de error, ni el log llevan la clave del cliente: el cuerpo que devuelve el cliente se tapa ANTES de decodificarlo y las excepciones de HTTP también. El log de cada llamada lleva método, ruta SIN query, status y milisegundos: nunca el cuerpo ni la clave.',
                'No sigue redirecciones: Guzzle no saca un header propio como X-Admin-Api-Key al redirigir a otro host, así que un 3xx vuelve como respuesta (con su `status`) en vez de mandar la clave del cliente a donde apunte.',
                'No reintenta, ni un 5xx ni un timeout: POST /mostrador/hechos dispara un cálculo y PUT /update-default-version escribe a dónde manda el login; quien sabe cuál pedido se puede repetir es quien llama. Timeout de 600 s por llamada (el default de services.client_api.admin_sync_puente_timeout, el mismo techo que usa mostrador.php contra el cliente).',
                'Sin api_key en el admin es 409 sin_api_key y sin una URL de empresa-api resoluble es 409 sin_url, ANTES de llamar a nadie. Se le habla a la API activa del cliente (la que sirve hoy).',
                'El cuerpo de un POST/PUT viaja como el JSON ORIGINAL: un objeto con claves numéricas llega como objeto y no como lista, y un {} vuelve como {}. Un cuerpo ausente viaja como {}.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente cuyo empresa-api se consulta.'],
                ['nombre' => 'metodo', 'obligatorio' => true, 'validacion' => 'GET, POST o PUT (sin importar mayúsculas); otro método es 422', 'que_es' => 'El método con el que el admin le pega al cliente.'],
                ['nombre' => 'ruta', 'obligatorio' => true, 'validacion' => 'string de hasta 2000, con `/` inicial, sin caracteres de control ni `#`; el path tiene que estar en la lista blanca para ese método', 'que_es' => 'Ruta relativa a /api/admin-sync, con su query si la trae (/mostrador/duenos). La query se reenvía tal cual.'],
                ['nombre' => 'cuerpo', 'obligatorio' => false, 'validacion' => 'objeto o lista JSON; solo en un POST o un PUT (en un GET con contenido es 422)', 'que_es' => 'El cuerpo del POST/PUT, tal cual lo recibe la ruta del cliente (por ejemplo los hechos de un dueño, o el texto de un informe).'],
            ],
        ],
        'POST api/claude/clients/{id}/admin-sync/exigir-clave' => [
            'para_que'     => 'MIRA (`ver`, el default), PRENDE (`prender`) o APAGA (`apagar`) la exigencia de la clave de admin-sync en el .env de CADA frente (client_apis) de UN cliente, por SSH: ADMIN_SYNC_REQUIRE_API_KEY (todo el grupo admin-sync) y ADMIN_SYNC_SETUP_REQUIRE_API_KEY (el user setup). Es el único paso que CIERRA las rutas admin-sync/* de un cliente (hoy responden sin clave en toda la flota) y se hace UNO A LA VEZ, con prueba y vuelta atrás. Pedido: {accion?: ver|prender|apagar, confirm_client_name?}. Respuesta 200: {client_id, accion, estado, listo, motivo, frentes[], prueba}. Cada frente: {client_api_id, hosting_type, path, es_la_activa, clave: igual|falta|distinta|sin_env|error (ADMIN_API_INBOUND_KEY del .env contra clients.api_key, con hash_equals; nunca devuelve ningún valor), salida: cargada|falta|sin_env|error (si el .env tiene ADMIN_API_OUTBOUND_KEY con algún valor; nunca el valor), exigencia: sin_definir|true|false|invalido|null, exigencia_setup: ídem, accion: ninguna|ya_estaba|escrito|revertido|fallo|reversion_fallo, error}. `estado`: visto | prendido | apagado | abortado (una precondición falló: no se escribió NADA) | revertido (la prueba con clave o una escritura falló y se restauró todo) | revertido_con_fallas (y la restauración de algún frente también falló: mirar a mano, el respaldo .env.bak-* existe) | sin_efecto (quedó prendido pero un pedido SIN clave no dio 401: config cacheado o una versión vieja del cliente) | sin_verificar (quedó prendido y la clave entra, pero no se pudo confirmar el 401 sin clave). `listo`: en prender solo estado=prendido, en apagar solo apagado, en ver que TODOS los frentes estén en clave=igual y el cliente tenga clave (las condiciones para prender; la salida NO es una precondición: la escribe el propio prender). `exigencia` (ADMIN_SYNC_REQUIRE_API_KEY) es true/false SOLO si el texto es exactamente eso (con o sin paréntesis, sin importar mayúsculas); off, no, 1, 0 o cualquier otro texto es `invalido`. `prueba`: {con_clave: {ruta, status, resultado}, sin_clave: {ruta, status, resultado, veredicto: cerrada|sin_efecto|sin_verificar}} si llegó a correr. Errores: 404 cliente_inexistente, 409 sin_frentes, 422 validacion (con `detalle` por campo); `error` es el código y `mensaje` el texto.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                '`accion` por defecto `ver`: lee el .env de cada frente y lo informa; NO respalda, NO escribe y NO borra el config cacheado. Un frente que falla (SSH caído, sin .env, de otro dueño) queda con clave=error y NO frena la lectura de los demás. `accion` solo acepta ver, prender o apagar: un valor que no se entiende es 422 y NO un "prender".',
                'confirm_client_name obligatorio con prender y apagar: tiene que coincidir con clients.name (recorte y minúsculas) y el error no revela el nombre correcto. Es el mismo freno que POST claude/clients/{id}/catalogo/clave. Lista cerrada de parámetros (accion, confirm_client_name): cualquier otra clave es 422 validacion.',
                '🔴 PRECONDICIONES de prender, todas o NO escribe nada (estado=abortado, con el motivo por frente): clients.api_key no vacía; un frente activo contra el cual probar (el que resuelve ClientEmpresaApiUrlResolver, el mismo al que le habla el admin); TODOS los frentes en clave=igual (uno falta/distinta/sin_env/error aborta: exigir una clave que el frente no tiene deja al cliente sin canal con el admin); y ningún .env con un USER_ID que no sea el de este cliente (dueno_distinto, la misma regla que catalogo/clave). Antes hay que correr catalogo/clave.',
                '🔴 Por frente, prender RESPALDA el .env (.env.bak-<AAAAMMDDHHMMSS>), escribe las dos variables en true (write_env_vars_for relee el archivo y lanza si no quedó) y BORRA el config cacheado (bootstrap/cache/config.php, que es lo que hace config:clear): con el config cacheado la variable no se lee y la prueba daría un falso verde. Un frente que ya tiene las dos en true no se vuelve a escribir.',
                '🔴 LA CLAVE DE SALIDA: con la exigencia prendida, el empresa-api NO manda nada al admin (mensajes de soporte, acuses de lectura de novedades) si su .env no tiene ADMIN_API_OUTBOUND_KEY. Por eso prender la escribe (= clients.inbound_api_key) en CADA frente que no la tenga, en la misma tanda respaldo → escritura → cache que las banderas; si el cliente no tiene inbound_api_key la genera (40 caracteres) y la guarda ANTES de escribir en el primer servidor (un prender abortado no genera nada). Si el .env ya la tiene con cualquier valor, NO se toca. La vuelta atrás NO la restaura (es inocua; sacarla volvería a cortar la salida) y apagar tampoco la toca: el motivo de una reversión lo dice. El admin hoy no valida esa clave de entrada.',
                '🔴 LA PRUEBA, solo contra el frente ACTIVO: (i) GET admin-sync/mensualidad-info CON la clave — un 401, un 5xx, un 3xx o una falla de red REVIERTE todo lo escrito (restaura los valores previos de las dos variables en cada frente, una variable que no estaba vuelve como false explícito, y borra el config cacheado) y devuelve estado=revertido; 2xx, 404 o 422 pasan, y ante un 404 se prueba también GET admin-sync/employees antes de darlo por pasado. (ii) GET admin-sync/mensualidad-info SIN el header — tiene que dar 401; un 2xx o un 422 es estado=sin_efecto (NO revierte, pero se informa fuerte) y cualquier otra cosa es sin_verificar.',
                'apagar deja las dos variables en false EXPLÍCITO (no las borra): así queda apagada a propósito aun con la semántica de empresa-api donde "sin definir + clave cargada" ya exige. No pide la clave ni la prueba: tiene que poder hacerse justo cuando la clave está mal. Si un frente no se puede leer o escribir, no escribe en ninguno (o restaura lo ya escrito).',
                '🔴 Ni la respuesta, ni los mensajes de error, ni el log llevan el valor de ninguna clave (ni la del admin ni la del .env): solo estados, ids y los booleanos de las dos exigencias. Todo texto que viene del servidor pasa por un tapador de claves antes de salir.',
                'Una operación = un cliente. El plan de la misión es el canario (el cliente propio, id 58), después bloques de 5, siempre de noche y observando el Log::warning de 401 del empresa-api entre tanda y tanda. Las demos NO se prenden.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente cuya exigencia de clave se mira o se cambia en todos sus frentes.'],
                ['nombre' => 'accion', 'obligatorio' => false, 'validacion' => 'ver, prender o apagar — 🔴 DEFAULT ver', 'que_es' => 'ver solo mira; prender exige la clave en todos los frentes (con prueba y vuelta atrás); apagar la deja en false.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'string de hasta 190; tiene que coincidir con clients.name (trim + minúsculas)', 'que_es' => 'Obligatorio con prender y apagar. Es la redundancia contra operar sobre el cliente equivocado.'],
            ],
        ],
        'POST api/claude/clients/{id}/acceso-soporte' => [
            'para_que'     => 'ACCESO DE SOPORTE FIRMADO al sistema de UN cliente: le pide al empresa-api del cliente (POST /api/admin-sync/acceso-soporte, firmado con clients.api_key en X-Admin-Api-Key) un token de UN SOLO USO que vence a los pocos minutos, y devuelve la URL de entrada del SPA de ESE mismo frente para abrirla en un navegador. Entra como el DUEÑO del cliente (no se crea ningún usuario en su base) y la sesión no le toma el candado de sesión única. Es lo que deja a un agente entrar al sistema de cualquier cliente sin que Lucas inicie sesión y sin que nadie escriba una contraseña. Pedido: {dry_run?: bool (DEFAULT true), confirm_client_name?: string (obligatorio si dry_run=false), solicitante?: string ≤ 60 (default "claude"), motivo?: string ≤ 120}; lista cerrada, una clave de más es 422. Respuesta 200 con dry_run=true: {client_id, dry_run: true, listo, motivo, frente: {client_api_id, hosting_type, api_host, spa_url}}; NO emite token y NO le pega al cliente: dice a qué frente se le pediría el acceso y si está todo para pedirlo (listo=false con un texto en `motivo` si falta la api_key, la URL de la API o el spa_url del frente). Respuesta 200 con dry_run=false: {client_id, dry_run: false, url: "https://<spa del frente>/acceso-soporte#t=<token>", vence_en_segundos, expira_en (ISO-8601 UTC), usuario_id, frente}. 🔴 El token va en el FRAGMENTO (#t=) y no en la query: el fragmento no viaja al servidor ni queda en los access logs del hosting. Quien abre la URL no debe imprimirla ni guardarla: el acceso es de un solo uso y vence solo. Errores (`error` es el código estable y `mensaje` el texto; los 409/502 de servicio traen además client_id y frente): 404 cliente_inexistente; 422 validacion (con `detalle` por campo); 409 sin_frentes (el cliente no tiene ninguna ClientApi), sin_api_key, sin_url, sin_spa_url; 409 version_vieja (el cliente contestó 404/405 sin `code`: su versión no tiene la ruta; actualizarlo o entrar con el login maestro); 409 clave_rechazada (el cliente contestó 401: su ADMIN_API_INBOUND_KEY no coincide o falta, se arregla con catalogo/clave); 409 dueno_no_resoluble (el cliente no pudo decidir cuál es su dueño); 409 acceso_desactivado (ACCESO_SOPORTE_HABILITADO=false en su .env); 502 cliente_no_responde (timeout o conexión); 502 respuesta_invalida (un 200 sin token válido, un 3xx, un 5xx).',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                '`dry_run` es true por defecto y estricto: solo true, false, 1 o 0 (y sus textos). Un valor que no se entiende es 422 y NO un "emitir". Con dry_run=true NO se le pega al cliente y no se emite ningún token: solo se resuelve el frente.',
                'confirm_client_name obligatorio con dry_run=false: tiene que coincidir con clients.name (recorte y minúsculas) y el error NO revela el nombre correcto. Es el mismo freno que POST claude/clients/{id}/catalogo/clave. Un cliente sin nombre cargado no se puede confirmar y se dice por qué.',
                'Lista cerrada de parámetros (dry_run, confirm_client_name, solicitante, motivo): cualquier otra clave es 422 validacion. solicitante (≤ 60) y motivo (≤ 120) son para la auditoría del cliente y el log del admin: tienen que ser texto en UTF-8 válido, los caracteres de control y los saltos de línea se reemplazan por un espacio, y uno más largo que el máximo es 422 (no se recorta en silencio).',
                '🔴 La FIRMA es la clave compartida: el pedido al cliente lleva clients.api_key en X-Admin-Api-Key y el empresa-api no emite nada sin ella (también la exige una instancia sin clave cargada). Sin api_key en el admin es 409 sin_api_key y sin URL de API resoluble es 409 sin_url, ANTES de llamar a nadie.',
                '🔴 EL MISMO FRENTE: la URL de la API y el spa_url salen de la MISMA ClientApi (la que resuelve ClientEmpresaApiUrlResolver, a la que le habla el admin para todo lo saliente). El token solo vale en el frente que lo emitió; si ese frente no tiene un spa_url válido es 409 sin_spa_url y NO se usa el SPA de otro frente. Con dos frentes se usa el activo.',
                '🔴 UN SOLO USO y vence solo: el empresa-api guarda únicamente el hash (sha256) del token, lo vence a los 120 s (configurable de 30 a 600) y lo consume de forma atómica en el primer canje. Queda una fila de auditoría en el cliente (quién pidió, motivo, IP del pedido, cuándo y desde dónde se canjeó).',
                '🔴 Ni la respuesta de error, ni el mensaje, ni el log llevan clients.api_key ni el token. El log de cada pedido real lleva SOLO client_id, client_api_id, solicitante, motivo, host del frente, estado y milisegundos: nunca el cuerpo que contestó el cliente ni la URL de entrada. El token vive en la variable y en el campo `url` de la respuesta de éxito, que sale con Cache-Control: no-store. Los textos de una falla de red pasan por un tapador de claves.',
                'No sigue redirecciones (Guzzle no saca un header propio como X-Admin-Api-Key al redirigir a otro host: un 3xx vuelve como 502 respuesta_invalida en vez de mandar la clave del cliente a donde apunte la redirección) y NO reintenta, ni un 5xx ni un timeout: emitir no es idempotente, cada pedido deja un token vivo y una fila de auditoría en el cliente. Timeout de 15 s.',
                'Compatible hacia atrás con un cliente en una versión vieja: si su empresa-api no tiene la ruta (404/405 sin `code`) la respuesta es 409 version_vieja con el camino alternativo, y no se rompe nada. Nada de esto toca el login maestro del cliente.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El cliente al que se le pide el acceso de soporte.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'booleano estricto (true, false, 1, 0) — 🔴 DEFAULT true', 'que_es' => 'true solo resuelve el frente y dice si está listo (no emite nada). false pide el token de verdad.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'string de hasta 190; tiene que coincidir con clients.name (trim + minúsculas)', 'que_es' => 'Obligatorio con dry_run=false. Es la redundancia contra pedir el acceso al cliente equivocado.'],
                ['nombre' => 'solicitante', 'obligatorio' => false, 'validacion' => 'string de hasta 60, UTF-8 válido, sin caracteres de control; default "claude"', 'que_es' => 'Quién pide el acceso (queda en la auditoría del cliente).'],
                ['nombre' => 'motivo', 'obligatorio' => false, 'validacion' => 'string de hasta 120, UTF-8 válido, sin caracteres de control', 'que_es' => 'Para qué se entra (queda en la auditoría del cliente).'],
            ],
        ],
        'GET api/claude/versions' => [
            'para_que'     => 'Catálogo de versiones con la cantidad de ítems por versión ya contada. Sin paginación: son pocas filas.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/versions' => [
            'para_que'     => 'Da de alta una versión nueva (código, título, descripción y estado inicial). Es el alta explícita: no depende de que POST claude/version-items cree una draft de rebote.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Es un INSERT de una sola fila en `versions`: no toca ningún cliente, no arranca SSH, no encola nada. `Version` no tiene observer ni `boot()`, y ningún comando programado actúa solo sobre versiones publicadas.',
                'El código de versión es único (`versions.version` tiene índice unique) y tiene que matchear el mismo regex que exige el panel humano: al menos 3 componentes numéricos separados por puntos.',
                '🔴 `is_hotfix` SIEMPRE se autocalcula del código (más de 3 componentes = hotfix); no hay parámetro para forzarlo en el alta, igual que `VersionController@store`. Fue justamente el override en el alta lo que causó el bug del 18/8/2026 (toda versión creada desde el SPA quedaba is_hotfix=false).',
                'Sin dry_run ni confirm_*: a diferencia de POST claude/upgrades, acá no hay ningún cliente ni deployment involucrado que un id equivocado pueda dañar.',
            ],
            'parametros'   => [
                ['nombre' => 'version', 'obligatorio' => true, 'validacion' => 'required|string|max:30|unique:versions,version|regex de al menos 3 componentes numéricos', 'que_es' => 'Código de la versión, ej. "4.0.3". Tiene que ser único en toda la tabla.'],
                ['nombre' => 'title', 'obligatorio' => false, 'validacion' => 'nullable|string|max:200', 'que_es' => 'Título visible en el panel.'],
                ['nombre' => 'description', 'obligatorio' => false, 'validacion' => 'nullable|string|max:5000', 'que_es' => 'Descripción larga.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'nullable|string|in:draft,published,archived', 'que_es' => 'Estado inicial. Default draft, igual que el panel humano (VersionProperties).'],
            ],
        ],
        'POST api/claude/versions/{id}/status' => [
            'para_que'     => 'Cambia el estado de una versión ya existente (borrador / publicada / archivada).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Sin máquina de estados: acepta cualquier transición del enum, igual que VersionController@update_json en el panel humano — no se inventa una regla más estricta que la que ya rige ahí.',
                'Al pasar a `published` sin `published_at` previo, lo setea a `now()`. En cualquier otra transición no toca ese campo.',
                '⚠️ Publicar una versión NO la despliega a ningún cliente ni dispara nada por sí sola: sólo habilita que POST claude/upgrades pueda apuntarle (esa ruta rechaza con 422 toda versión que no esté published). Para llevarla a un cliente hace falta esa llamada aparte, con sus propios frenos.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'La versión a la que se le cambia el estado. GET claude/versions la resuelve.'],
                ['nombre' => 'status', 'obligatorio' => true, 'validacion' => 'required|string|in:draft,published,archived', 'que_es' => 'El estado nuevo.'],
            ],
        ],
        'PATCH api/claude/versions/{id}' => [
            'para_que'     => 'Edita el código, el título, la descripción y/o el estado de una versión ya existente.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Todos los campos son `sometimes`: si no mandás ninguno, 422 (nada que actualizar).',
                'El código de versión respeta el mismo regex que el alta y es único excluyendo esta misma versión — '
                    . 'salvo que el código que mandás sea IDÉNTICO al ya persistido, en cuyo caso no se exige el regex '
                    . '(mismo criterio que VersionController::validate_version_payload en el panel humano, para no '
                    . 'bloquear la edición de una versión legacy con formato viejo, ej. "3.3").',
                '🔴 `is_hotfix` se recalcula SOLO si `version` cambia, y SIN override: a diferencia del panel humano, '
                    . 'claude/* nunca permite forzarlo distinto del cálculo automático.',
                'Al pasar `status` a `published` sin `published_at` previo ni `published_at` explícito en la misma '
                    . 'llamada, se setea `now()`; cualquier otra transición no lo toca. Un `published_at` explícito '
                    . 'con valor se usa TAL CUAL y le gana al now() automático — mismo criterio que '
                    . 'VersionController::extract_data() en el panel humano, y la única forma de corregir esa fecha '
                    . 'una vez estampada.',
                'La versión se resuelve ANTES de exigir que haya algún campo: un PATCH con cuerpo vacío sobre un id '
                    . 'inexistente contesta 404, no 422.',
                'Sin dry_run ni confirm_*: es un UPDATE de una sola fila, sin cascada ni efecto en otras tablas — '
                    . 'mismo criterio de riesgo que el alta.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'La versión a editar. GET claude/versions la resuelve.'],
                ['nombre' => 'version', 'obligatorio' => false, 'validacion' => 'sometimes|required|string|max:30, regex si cambia, único excluyendo esta versión', 'que_es' => 'Código nuevo de la versión, ej. "4.0.3".'],
                ['nombre' => 'title', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:200', 'que_es' => 'Título visible en el panel.'],
                ['nombre' => 'description', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:5000', 'que_es' => 'Descripción larga.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'sometimes|required|string|in:draft,published,archived', 'que_es' => 'Estado nuevo.'],
                ['nombre' => 'published_at', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|date', 'que_es' => 'Fecha de publicación. Con valor se usa tal cual; sin ella y con status=published sin fecha previa, se setea now(). Vacío o null se IGNORA (nunca borra la fecha ya guardada), igual que el panel humano.'],
            ],
        ],
        'DELETE api/claude/versions/{id}' => [
            'para_que'     => 'Borra una versión, con el mismo mecanismo (y las mismas cascadas) que ya usa el panel humano.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                '🔴 BLOQUEO DURO, SIN FLAG QUE LO DESTRABE: si la versión tiene filas en `demo_updates`, NO SE BORRA. '
                    . 'Esa FK se declaró sin onDelete, o sea RESTRICT: el delete moriría con una QueryException 1451 y '
                    . 'un 500 crudo. Se rechaza con 422 tanto en el dry_run (que además lo dice en `se_puede_borrar`) '
                    . 'como en el borrado real, antes de tocar la base. La versión se puede archivar, no borrar.',
                'dry_run por defecto TRUE: sin dry_run=false explícito no borra nada, y devuelve el impacto real medido '
                    . 'contra la base — clientes que quedarían con current_version_id null; upgrades que se borrarían '
                    . 'en cascada como destino; upgrades que quedarían con from_version_id null; los conteos propios '
                    . 'de notifications/seeders/commands/manual_tasks; las cascadas de SEGUNDO orden '
                    . '(update_seeders y update_commands, que cuelgan de los seeders/comandos de esta versión y de los '
                    . 'upgrades que la tienen como destino, y client_notification_reads, que cuelgan de sus '
                    . 'notificaciones); el bloqueante demo_updates; y, sólo informativo, '
                    . 'client_version_upgrade_versions.',
                'confirm_version_code obligatorio cuando dry_run=false: tiene que coincidir con el CÓDIGO de la '
                    . 'versión (ej. "4.0.3"), no con el id ni el uuid — es lo que un humano reconoce. El rechazo no '
                    . 'revela el código correcto.',
                'confirm_borra_historial (booleano true/1) obligatorio SOLO cuando el borrado se lleva puesto '
                    . 'HISTORIAL DE TERCEROS, o sea si CUALQUIERA de estos es mayor que cero: '
                    . 'upgrades_hacia_esta_version, update_seeders, update_commands o client_notification_reads. '
                    . 'Atarlo a una sola tabla dejaba pasar sin confirmar una versión que no es destino de ningún '
                    . 'upgrade pero sí tiene lecturas de sus notificaciones. Los conteos PROPIOS de la versión '
                    . '(notifications, seeders, commands, manual_tasks) no disparan el freno: son contenido de la '
                    . 'versión, no historial de clientes.',
                '🔴 El borrado es EN CASCADA por las FK ya definidas en la base: client_version_upgrades.to_version_id '
                    . 'es ON DELETE CASCADE (se pierden esas filas, y con ellas sus update_seeders/update_commands), '
                    . 'from_version_id y clients.current_version_id son ON DELETE SET NULL. Este endpoint no inventa '
                    . 'el comportamiento: hereda el que ya tiene VersionController::destroy_json() del panel humano, '
                    . 'con los frenos que un proceso automático necesita y una pantalla no.',
                '⚠️ client_version_upgrade_versions se INFORMA pero no frena ni se limpia: esa tabla puente no tiene '
                    . 'ninguna clave foránea, así que sus filas quedan huérfanas apuntando a un version_id que ya no '
                    . 'existe. Se cuenta para que el borrado no lo esconda; resolverlo es otra misión.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'La versión a borrar. GET claude/versions la resuelve.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO borra nada. Con dry_run devuelve el impacto medido y se_puede_borrar.'],
                ['nombre' => 'confirm_version_code', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30 — obligatorio cuando dry_run=false', 'que_es' => 'El código exacto de la versión (ej. "4.0.3"), no el id ni el uuid. El rechazo NO revela el código correcto.'],
                ['nombre' => 'confirm_borra_historial', 'obligatorio' => false, 'validacion' => 'nullable|boolean (true/1) — obligatorio solo si hay historial de terceros: upgrades hacia esta versión, update_seeders, update_commands o client_notification_reads', 'que_es' => 'Confirma que sabés que se borra historial real de clientes (qué actualización corrió qué, y quién leyó qué notificación), no solo la fila de versions.'],
            ],
        ],
        'GET api/claude/upgrades' => [
            /* `ids` y `created_via` son lo que hace poleable un lote de una sola vez: ver la
               respuesta 201 de POST claude/upgrades/batch, que devuelve la llamada ya armada. */
            'para_que'     => 'Listado de actualizaciones filtrable y paginado por cursor. Filtros: client_id, ids (lista, para polear un lote entero de una), created_via, status, deployment_status, to_version_id, scheduled_date_from/to, activos.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/upgrades/{id}' => [
            'para_que'     => 'Estado detallado de un upgrade: el endpoint de poleo. Trae la salud calculada (deployment_stale, jobs en cola) y la siguiente acción sugerida. Desde el 14/10/2026 trae también upgrade.modo (en_el_lugar | rotacion; null en un upgrade completed, porque su destino ya pasó a ser el activo), upgrade.frente_principal_client_api_id, upgrade.advertencia_frente_secundario y target_client_api.es_el_frente_principal. En el lugar y failed, siguiente_accion trae resume_from_step con la etapa que falló.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/upgrades/{id}/logs' => [
            'para_que'     => 'Logs del deployment de un upgrade, paginados por cursor y con las líneas truncadas por max_line_chars. Es el único camino a los logs: la tabla no está en /query, por volumen.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],

        /* ---------------------------------------------------------- Clientes y versiones: escritura */

        'POST api/claude/upgrades/preview' => [
            'para_que'     => 'Candidatas de versión entre la versión actual de un cliente y la versión destino, con las que el panel marcaría por defecto. Trae también frente_principal_client_api_id (la API destino por defecto), active_client_api_id y modo: en_el_lugar si el cliente ya atiende desde su frente principal, rotacion si atiende desde el otro (la última rotación al principal). Acepta target_client_api_id opcional para calcular el modo de otra API, y trae advertencia_frente_secundario si esa API no es ni el principal ni la activa (no frena).',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/upgrades' => [
            'para_que'     => 'Crea UN ClientVersionUpgrade con las versiones confirmadas y sus UpdateSeeder y UpdateCommand. No arranca ningún deployment. La simulación trae modo, frente_principal_client_api_id y advertencia_frente_secundario; la creación real, upgrade.modo y upgrade.advertencia_frente_secundario (es el payload de GET claude/upgrades/{id}).',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'dry_run por defecto: si no se pide lo contrario, simula y no crea nada.',
                'confirm_client_name tiene que coincidir con el nombre del cliente, y el rechazo no revela cuál era el correcto.',
                'confirm_version_count tiene que coincidir exactamente con la cantidad de versiones que se van a instalar.',
                /* Los tres de abajo estaban en el código y no en este archivo. Ver
                   rechazar_si_no_esta_publicada(), rechazar_si_la_api_destino_no_es_del_cliente() y
                   ClientVersionUpgradeCreationService::resolve_confirmed_version_ids(). */
                'La versión destino tiene que estar en status "published": una versión sin publicar no se le instala a nadie.',
                'target_client_api_id, si viene, tiene que ser una ClientApi DEL cliente. Una API de otro cliente es 422 y no crea nada.',
                'confirmed_version_ids sólo puede traer versiones del rango entre la actual del cliente y la destino: una versión de afuera es 422 antes de tocar la base.',
                'No arranca el deployment: eso es una llamada aparte, con sus propios frenos.',
            ],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => true, 'validacion' => 'required|exists:clients,id', 'que_es' => 'El cliente al que se le crea la actualización.'],
                ['nombre' => 'to_version_id', 'obligatorio' => true, 'validacion' => 'required|exists:versions,id', 'que_es' => 'Versión destino. Tiene que estar published.'],
                ['nombre' => 'confirmed_version_ids[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1, cada ítem integer', 'que_es' => 'Las versiones del camino que se van a instalar. POST claude/upgrades/preview devuelve las candidatas y cuáles marcaría el panel.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO crea nada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'nullable|string|max:190 — obligatorio cuando dry_run=false', 'que_es' => 'El nombre exacto del cliente. El rechazo NO revela cuál era el correcto.'],
                ['nombre' => 'confirm_version_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1 — obligatorio cuando dry_run=false', 'que_es' => 'Cantidad exacta de versiones a confirmar. La simulación la devuelve.'],
                ['nombre' => 'target_client_api_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'API destino del deployment. Tiene que ser del mismo cliente. Si no viene, es el FRENTE PRINCIPAL del cliente (desde el 14/10/2026: ya no "la que no es la activa"). Si el cliente atiende desde su principal, eso es un despliegue en el lugar; si atiende desde el otro, es la última rotación al principal.'],
                ['nombre' => 'notes', 'obligatorio' => false, 'validacion' => 'nullable|string', 'que_es' => 'Notas de la actualización.'],
                ['nombre' => 'scheduled_date', 'obligatorio' => false, 'validacion' => 'nullable|date_format:Y-m-d', 'que_es' => 'Fecha planificada. No dispara nada: es informativa.'],
            ],
        ],
        'POST api/claude/upgrades/batch' => [
            'para_que'     => 'Crea la MISMA actualización de versión para un conjunto de clientes, en una sola llamada. Devuelve la lista de deploy/start a llamar después, en orden.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'dry_run por defecto: si no se pide lo contrario, simula y no crea nada.',
                'confirm_client_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals, calculado sobre el id, el nombre normalizado y el conjunto de versiones de cada cliente: si la lista cambió entre la simulación y la confirmación, no pasa. Es el equivalente en lote de confirm_client_name, que en un lote no se puede espejar.',
                'Tope MAX_LOTE_CLIENTES = 25.',
                'Cooldown de 24 horas por (cliente, versión destino) para las actualizaciones creadas por Claude.',
                'Los clientes inactivos quedan omitidos salvo include_inactivos.',
                'Presupuesto de 50 segundos con reserva de 5 por cliente: corta limpio y devuelve los no procesados.',
                'Un cliente raro (versión fuera de rango, sin ClientApi, con un deployment en curso) queda omitido con el motivo escrito y NO voltea el lote entero.',
                'La versión destino tiene que estar en status "published", igual que en el alta de a uno.',
                'client_ids[] y el selector por versión (from_version_id / from_version) son excluyentes: los dos juntos, o ninguno, es 422.',
                '🔴 NO arranca ningún deployment. El gate de horario (del post-cierre, y del deploy/start cuando va en el lugar) y allow_deploy_to_active_api son por cliente: veinte clientes son veinte jornadas distintas y no hay lote posible ahí.',
                'La API destino de cada cliente es su frente principal (el mismo default que el alta de a uno): cada candidato trae modo, en_el_lugar o rotacion.',
                '🔴 Y crear NO es actualizar: completar cada actualización son cinco pasos por cliente y uno de ellos es MANUAL (mover los crons en Hostinger). La respuesta los devuelve en `pasos_para_completar`. Ver limitaciones_conocidas.',
            ],
            'parametros'   => [
                ['nombre' => 'to_version_id', 'obligatorio' => true, 'validacion' => 'required|exists:versions,id', 'que_es' => '🔴 EL ÚNICO OBLIGATORIO. Versión destino, la misma para todo el lote. Tiene que estar published.'],
                ['nombre' => 'client_ids[]', 'obligatorio' => false, 'validacion' => 'nullable|array|min:1, cada ítem required|integer|min:1', 'que_es' => 'Selección explícita de clientes. Excluyente con el selector por versión; sin uno de los dos, es 422.'],
                ['nombre' => 'from_version_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'Selector: todos los clientes cuya versión ACTUAL es esta. Excluyente con client_ids[].'],
                ['nombre' => 'from_version', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30', 'que_es' => 'Lo mismo que from_version_id pero por número de versión (ej. "3.3.4").'],
                ['nombre' => 'politica_de_versiones', 'obligatorio' => false, 'validacion' => 'nullable|string|in:sugeridas_del_panel,todas_las_candidatas — default sugeridas_del_panel', 'que_es' => 'Qué versiones del camino se confirman por cliente. "sugeridas_del_panel" usa la misma regla que marca por defecto el panel: troncal sí, hotfix no, destino siempre.'],
                ['nombre' => 'include_inactivos', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false', 'que_es' => 'Sin esto, los clientes con is_active=0 quedan como omitidos.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO crea nada: devuelve los candidatos, las versiones de cada uno y el confirm_token.'],
                ['nombre' => 'confirm_client_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Cantidad exacta de clientes a los que se les crearía. Tiene que coincidir con la simulación.'],
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token de la simulación, comparado con hash_equals. Es el equivalente en lote de confirm_client_name: incorpora el id, el nombre normalizado y las versiones de cada cliente.'],
                ['nombre' => 'notes', 'obligatorio' => false, 'validacion' => 'nullable|string', 'que_es' => 'Notas, iguales para todas las actualizaciones del lote.'],
                ['nombre' => 'scheduled_date', 'obligatorio' => false, 'validacion' => 'nullable|date_format:Y-m-d', 'que_es' => 'Fecha planificada, igual para todas. No dispara nada.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/start' => [
            'para_que'     => 'Arranca el pipeline PRE-CIERRE: compila la SPA, la sube, sube la API, completa el .env del frente destino con las claves que le faltan respecto del frente activo (sync_env_keys, desde el 10/9/2026 y para cualquier versión destino: agrega las que faltan o están vacías, nunca pisa un valor, y aparta las propias del frente como URL/DOMAIN/PREFIX/COOKIE/STATEFUL/APP_NAME salvo las compartidas de la lista explícita; lo apartado se nombra en el log), corre las migraciones y frena esperando los crons. En una ROTACIÓN (destino que no es la activa) se puede correr con el negocio abierto porque no toca el sistema en uso. 🔴 EN EL LUGAR (destino = activa = frente principal, desde el 14/10/2026) sí lo toca: antes de pisar nada respalda código, SPA y base en ~/cc-respaldos/<carpeta>/ del servidor (etapa backup_in_place; reusa un respaldo de este upgrade que ya esté en el disco SOLO si este upgrade ya tocó el sistema vivo —la marca TOCADO en ese respaldo, que se escribe junto con la línea "Sistema vivo tocado: <etapa>" del log, o el sistema en mantenimiento—; si no tocó nada, respalda de nuevo y el viejo queda para la retención; si falla, el deployment queda failed sin haber tocado nada), baja y sube la API al hosting ANTES de tocar el SPA, pone el sistema en mantenimiento (php artisan down) justo antes de descomprimir la API, corre migraciones y SEEDERS adentro del mantenimiento, reinicia el worker, lo saca (php artisan up) y marca solo crons_supervisor_at (shared) o vps_supervisor_moved_at (VPS); los COMANDOS corren DESPUÉS, con el sistema arriba (si uno falla, el cliente sigue atendiendo y el camino es retry-commands), y recién ahí pausa. El post-cierre en el lugar no vuelve a correr lo que ya salió. La última rotación al frente principal también se respalda (las migraciones pegan sobre la base viva). La respuesta trae modo, frente_principal_client_api_id y advertencia_frente_secundario.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'No arranca si ya hay un deployment activo (running, paused o paused_post_tasks).',
                /* Estaba en el código (ClaudeUpgradeOpsController, el `empty($upgrade->target_client_api_id)`)
                   y no acá. Es un 422 que frena el arranque, o sea un freno. */
                'Exige target_client_api_id cargado en el upgrade: sin API destino no arranca y devuelve 422 sin encolar nada.',
                '🔴 Destino = API activa = frente principal (en el lugar): pasa SIN allow_deploy_to_active_api pero con el GATE DE HORARIO del en el lugar: sin force pasa si el negocio está cerrado y la jornada de hoy terminó, o si todavía no abrió hoy y faltan al menos 120 minutos para la PRIMERA apertura (la madrugada). Un receso entre turnos sigue rechazando aunque falten más de 2 horas; sin_configurar también rechaza. Se saltea con force + force_reason, que queda en el log diario.',
                'Una REANUDACIÓN (resume_from_step) en el lugar pasa sin gate SOLO si este upgrade ya tocó el sistema vivo (la línea "Sistema vivo tocado: …" de sus logs; si un start limpio la borró, el job la vuelve a escribir como "heredado de un intento anterior" al encontrar el down o un respaldo con TOCADO) Y no hubo un artisan up exitoso después de esa marca, Y la etapa es anterior al artisan up (o la del up, restart_queue_workers): ahí el gate frenaría la terminación de un despliegue que dejó al cliente a medias. Queda en el log diario. Si no tocó nada, o si se reanudan run_commands o pause_for_crons (con el cliente ya arriba), lleva el gate del en el lugar. siguiente_accion dice en gate_de_horario si aplica.',
                '🔴 EN EL LUGAR, resume_from_step POSTERIOR a la etapa que falló (la más reciente entre "Etapa que falló" y "Etapa en curso", contando solo las líneas posteriores al último artisan up exitoso; si hubo un up y nada después —timeout en un comando—, la etapa es run_commands), o posterior a upload_spa sin que el SPA nuevo se haya desplegado, es 422 sin encolar nada: saltearía lo que quedó sin hacer, o terminaría con el SPA viejo y la API nueva. Una etapa anterior siempre se acepta.',
                'Destino = API activa pero NO el frente principal (el cliente atiende desde su frente 2): 422 salvo allow_deploy_to_active_api=true. Lo que corresponde es crear el upgrade hacia el frente principal: es la última rotación.',
                'allow_deploy_to_active_api=true se sigue aceptando siempre (compatibilidad): con ella el start pasa sin gate, como antes, y deja una línea en el log diario.',
                'Si se respalda (en el lugar o última rotación al principal), resume_from_step desde una etapa posterior al respaldo exige que el upgrade tenga un respaldo completo EN EL DISCO del cliente; si no, el job falla sin tocar nada y hay que reanudar desde backup_in_place.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request.',
                '⚠️ BORRA los logs del intento anterior, igual que el botón del panel — SALVO que mandes resume_from_step, que justamente los conserva. Si querés el log de un intento fallido, leelo ANTES de reintentar sin resume_from_step.',
                '🔴 Desde la 4.0.23 el SPA y la API se BAJAN del release de GitHub (assets empresa-spa-v{version}-dist.zip y empresa-api-v{version}.zip). Si el asset no está, la etapa FALLA: ya NO se compila en el VPS de builds (decisión de Lucas, 9/9/2026). El mensaje del error dice qué archivo buscó y en qué tag. La salida de emergencia es DEPLOY_PERMITIR_BUILD_EN_VPS=true en el .env del admin, y se saca después.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade a arrancar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade. El rechazo NO revela cuál era el correcto.'],
                ['nombre' => 'allow_deploy_to_active_api', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Desde el 14/10/2026 sólo hace falta si la API destino ES la activa y NO es el frente principal del cliente (atiende desde su frente 2). Sin esto, ese caso es 422. Para el en el lugar sobre el principal no hace falta (lo cuida el gate de horario); si viene en true igual se acepta, y el start pasa sin gate.'],
                ['nombre' => 'resume_from_step', 'obligatorio' => false, 'validacion' => 'nullable|in:compile_spa,backup_in_place,upload_spa,upload_api,sync_env_keys,sync_pusher_template,run_migrations, y SOLO EN EL LUGAR también run_seeders,run_commands,restart_queue_workers,pause_for_crons — 422 si el valor no está en esa lista, y 422 si el deployment anterior NO quedó failed', 'que_es' => 'REANUDA un pre-cierre fallado desde esa etapa en vez de rehacerlo entero, y CONSERVA los logs del intento anterior. Sirve cuando el corte fue de una etapa sola (el release todavía no tenía su asset, se cayó el SSH al escribir config.js): reanudar por la etapa equivocada vuelve a bajar y desplegar el SPA por nada. sync_env_keys es la etapa que completa el .env del destino desde el activo (nunca falla el deploy: si no pudo, deja un warning en el log con el motivo); reanudar desde ahí sirve cuando la API ya está subida y lo que se quiere es volver a completar el .env antes de migrar. backup_in_place (en el lugar) sirve cuando falló el respaldo y se arregló la causa. En el lugar se reanuda desde la ETAPA QUE FALLÓ (siguiente_accion la dice): termina el despliegue y hace php artisan up después de los seeders y el reinicio del worker; reanudar desde una etapa posterior a la que falló puede migrar sobre código a medio descomprimir. La respuesta trae desde_etapa. Nunca llega al post-cierre: el pipeline corta igual en pause_for_crons.'],
                ['nombre' => 'force', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Sólo cuenta en un arranque limpio EN EL LUGAR: saltea el gate de horario (pisa el código vivo con el negocio abierto o por abrir en menos de 120 minutos). Exige force_reason. En una rotación y en una reanudación se ignora.'],
                ['nombre' => 'force_reason', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500 — obligatorio con force=true en el lugar, mínimo 10 caracteres', 'que_es' => 'Por qué se saltea el gate. Queda como warning en el log diario cuando el gate hubiera rechazado.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/mark-crons' => [
            'para_que'     => '🔴 SÓLO REGISTRA QUE UN HUMANO YA MOVIÓ LOS CRONS: escribe (o borra) crons_supervisor_at, que es el timestamp de esa confirmación. MARCARLO NO MUEVE NADA. Mover los crons y el supervisor del cliente es entrar al panel de Hostinger y hacerlo a mano, y es el paso por el que ninguna actualización de empresa se completa sin una persona. Marcar sin haberlos movido habilita el post-cierre igual y deja al cliente con los crons apuntando a la instancia vieja. En un despliegue EN EL LUGAR (desde el 14/10/2026) no hay crons que mover: el pipeline marca crons_supervisor_at solo al llegar a la pausa, y este endpoint no hace falta.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'Su único efecto es un timestamp en el upgrade: no arranca ni detiene ningún pipeline.',
                '⚠️ No verifica NADA sobre los crons reales del cliente: no puede. Es una afirmación humana, y el freno que la sostiene es que alguien tiene que escribirla.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade a marcar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade.'],
                ['nombre' => 'unmark', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Con true BORRA crons_supervisor_at (vuelve a null) en vez de marcarlo.'],
            ],
        ],
        /* ⚠️ Esta entrada faltaba en master (la ruta existe desde el 5/9/2026 y el test del catálogo
           estaba rojo por ella). Se agregó en la misión versiones-tienda (1/10/2026) para dejar el
           catálogo en verde, verificada contra ClaudeUpgradeOpsController::mark_vps_supervisor_json(). */
        'POST api/claude/upgrades/{id}/mark-vps-supervisor' => [
            'para_que'     => '🔴 SÓLO REGISTRA QUE UN HUMANO YA MUDÓ EL SUPERVISOR DEL VPS: escribe (o borra) vps_supervisor_moved_at. Es el equivalente de mark-crons para clientes con la API destino en VPS, donde no hay crons de Hostinger sino un .conf de supervisor que se reapunta con vps-supervisor.ps1 -Accion mudar (drena la cola vieja antes). El gate del post-cierre exige este campo en vez de crons_supervisor_at cuando la API destino es VPS. Marcarlo NO mueve nada.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'Sólo para upgrades con la API destino en VPS: en otro hosting es 422 y manda a mark-crons.',
                'Su único efecto es un timestamp en el upgrade: no arranca ni detiene ningún pipeline.',
                '⚠️ No verifica NADA sobre el supervisor real del VPS: es una afirmación humana. Marcarlo sin haber corrido la mudanza deja el worker en el frente equivocado y el post-cierre arranca igual.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade a marcar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade.'],
                ['nombre' => 'unmark', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Con true BORRA vps_supervisor_moved_at (vuelve a null) en vez de marcarlo.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/start-post-closure' => [
            'para_que'     => 'Arranca las tareas POST-CIERRE (seeders y comandos) sobre el sistema EN USO del cliente. Sólo con la jornada terminada.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'El deployment tiene que estar en paused: no arranca sobre cualquier estado.',
                'crons_supervisor_at tiene que estar marcado (vps_supervisor_moved_at si la API destino es VPS). ⚠️ Que esté marcado NO significa que los crons se hayan movido: mark-crons sólo registra que alguien dice que sí. En el lugar lo marca el pipeline, porque no hay nada que mover.',
                'Gate de horario: si el negocio está abierto, o si no tiene horarios cargados, no arranca. Se saltea sólo con force y un force_reason de al menos 10 caracteres, que queda en el log diario. EN EL LUGAR no hay gate si no queda ningún seeder ni comando pendiente de este upgrade (ya corrieron en el pre-cierre y este tramo no los vuelve a correr); si queda alguno, va el gate de siempre.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade cuyo post-cierre se arranca.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade.'],
                ['nombre' => 'force', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Saltea el GATE DE HORARIO: corre seeders y comandos sobre el sistema en uso aunque el negocio esté abierto. Exige force_reason.'],
                ['nombre' => 'force_reason', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500 — obligatorio con force=true, mínimo 10 caracteres', 'que_es' => 'Por qué se saltea el gate. Queda como warning en el log diario cuando el gate hubiera rechazado.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/retry-commands' => [
            'para_que'     => 'Reintenta los comandos automatizados desde el primero fallido o pendiente, sin volver a correr los seeders.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'Rechaza si el deployment está running. paused y paused_post_tasks SÍ pasan: es exactamente lo que hace el botón del panel.',
                'Exige los seeders completos. Un seeder marcado skipped cuenta como completo.',
                'Exige al menos un comando retriable: con version_command, sin run_manually, sin skipped y en estado fallido o pendiente.',
                '🔴 Gate de horario, que el panel NO tiene: run_commands corre sobre el sistema en uso del cliente, así que es la segunda mitad del post-cierre. Se saltea sólo con force y force_reason, y queda en el log diario. El panel lo aprieta un humano que sabe si el local está lleno; claude/* no tiene esa información salvo que la mire. EN EL LUGAR la regla es la del en el lugar: pasa con la jornada terminada o en la madrugada con al menos 120 minutos antes de la primera apertura; un receso entre turnos rechaza.',
                'No borra los logs, a diferencia de deploy/start. Se declara en la respuesta.',
                'EN EL LUGAR, si el despliegue no llegó al artisan up (no tiene la marca que el pipeline pone con el up), 422: el cliente puede estar en mantenimiento, y lo que lo termina es deploy/start con resume_from_step=<la etapa que falló>. Un comando del en el lugar que falló DESPUÉS del up sí pasa: el cliente está atendiendo, y este reintento es su camino.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade cuyos comandos se reintentan.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade.'],
                ['nombre' => 'force', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Saltea el gate de horario. run_commands corre sobre el sistema en uso: es la segunda mitad del post-cierre.'],
                ['nombre' => 'force_reason', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500 — obligatorio con force=true, mínimo 10 caracteres', 'que_es' => 'Por qué se saltea el gate. Queda en el log diario.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/expire-stuck' => [
            'para_que'     => 'Vence un deployment que quedó colgado en running y lo deja en failed, con el motivo escrito como línea de log, para poder arrancar otro.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'El deployment tiene que estar en running: cualquier otro estado es 422 sin tocar nada.',
                '🔴 Exige el umbral DESTRUCTIVO (el de VencerDeploymentsColgados, 45 minutos), no el de reporte (deployment_stale, 15). Vencer marca failed y habilita arrancar otro: con el umbral de aviso quedarían dos DeploymentService por SSH sobre el mismo hosting.',
                'Sin deployment_running_since no hay ancla y por lo tanto no hay medición: se rechaza y sólo se sale con force.',
                'force exige un force_reason de al menos 10 caracteres y deja un warning en el log diario. Un freno que se saltea sin dejar rastro no es un freno.',
                'No reimplementa el vencimiento: llama al mismo VencerDeploymentsColgados que corre el scheduler, con su claim atómico. Si el worker cambió el estado en el medio, devuelve 409 y no toca nada.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade colgado que se quiere destrabar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade.'],
                ['nombre' => 'force', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Saltea la comparación contra el umbral destructivo (45 min) y el caso "sin deployment_running_since, o sea sin medición posible". NO saltea el claim atómico.'],
                ['nombre' => 'force_reason', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500 — obligatorio con force=true, mínimo 10 caracteres', 'que_es' => 'Por qué se vence antes de tiempo. Queda como warning en el log diario.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/configure-system' => [
            'para_que'     => 'Arranca la etapa final: actualiza la versión por defecto del cliente y completa el upgrade.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                /* Estaba en el código (ClaudeUpgradeOpsController, el chequeo de deployment_status)
                   y no acá: un endpoint de peligrosidad alta declaraba dos frenos y tenía tres. */
                'El deployment tiene que estar en paused_post_tasks o failed: cualquier otro estado es 422 y no encola nada. Es lo que impide arrancar la etapa final sobre un deployment que todavía está corriendo o que nunca pasó por el post-cierre.',
                '🔴 EN EL LUGAR, si el despliegue no llegó al artisan up (no tiene la marca que el pipeline pone con el up), 422 sin encolar nada: cerraría el upgrade con el cliente en mantenimiento (y movería current_version y mandaría el mail al dueño). Lo mismo el botón del panel. Y el job, antes de update_default_version y de complete, mira storage/framework/down en el disco: si existe, falla sin cerrar.',
                'Encola con onConnection("database"): nunca corre el pipeline adentro del request.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade a completar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade. ⚠️ Es el ÚNICO parámetro de cuerpo: acá no hay force ni gate de horario.'],
            ],
        ],

        /* ---------------------------------------------------------- Tiendas (ecommerce) */

        'GET api/claude/ecommerce/stores' => [
            'para_que'     => 'Tiendas configuradas de los clientes, con su última corrida, la versión de ecommerce que tienen instalada (ecommerce_version: {id, version} o null si se instalaron por la vía vieja) y si se pueden actualizar ahora mismo (y el motivo cuando no). Es lo que hay que mirar antes de disparar una actualización.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/ecommerce/stores' => [
            'para_que'     => '🔴 REGISTRA la tienda de un cliente (dominio + las dos URLs) y nada más: no instala código, no toca DNS ni servidores, y no prende clients.tiene_ecommerce. Es el paso que antes sólo se podía hacer desde el modal del cliente en el panel, y que frenaba a la mitad una instalación llevada desde la raíz del pool.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'Lista blanca de parámetros: cualquier cosa de más es 422 y no escribe nada.',
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'Un cliente no puede tener dos tiendas: si ya tiene una, 422 con la fila que ya existe.',
                'El dominio va pelado y válido, y NO puede ser de comerciocity.com/.store/.com.ar: esas zonas son del ERP y de las demos.',
                'El dominio no puede estar cargado en otra tienda, sea de cliente o de demo.',
                'dry_run por defecto true: la primera llamada devuelve exactamente lo que escribiría, incluidos los paths derivados.',
                '🔴 No crea ninguna instalación: registrar la tienda e instalarla son dos operaciones distintas. La segunda es POST claude/ecommerce/installs (desde el 2/10/2026), con sus propios frenos.',
            ],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'El cliente dueño de la tienda. Tiene que existir y no tener ya una tienda cargada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente (clients.name), comparado con trim + minúsculas.'],
                ['nombre' => 'domain', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El dominio propio del cliente, pelado: "mitienda.com.ar". Sin esquema ni barra final.'],
                ['nombre' => 'spa_url', 'obligatorio' => false, 'validacion' => 'nullable|string|max:190', 'que_es' => 'Default https://{domain}. Se manda explícita sólo si la tienda no vive en la raíz del dominio.'],
                ['nombre' => 'api_url', 'obligatorio' => false, 'validacion' => 'nullable|string|max:190', 'que_es' => '🔴 Default https://api.{domain}, que es la convención de las tiendas nuevas del shared. El modal del panel, si la dejan vacía, usa la convención VIEJA {spa_url}/api y eso rompe todo lo que sigue sin un solo error visible.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Default true. Con false escribe la fila y devuelve 201.'],
            ],
        ],
        'GET api/claude/ecommerce/installations' => [
            'para_que'     => 'Corridas del pipeline de tienda (install o update), paginadas por cursor y filtrables por cliente, modo, estado y origen. Cada una trae ecommerce_version ({id, version} o null): la versión que despliega.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/ecommerce/installations/{id}' => [
            'para_que'     => 'Ficha de una corrida con la salud calculada (no persistida): minutos en curso, jobs en cola y si está colgada, y la versión de ecommerce que despliega (ecommerce_version). Acepta id o uuid.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/ecommerce/installations/{id}/logs' => [
            'para_que'     => 'Logs de una corrida de tienda, paginados por cursor y con las líneas truncadas por max_line_chars. Mismo contrato que los logs de upgrade.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/ecommerce/updates' => [
            'para_que'     => 'Dispara la actualización de la tienda de UN cliente a una VERSIÓN de ecommerce (la pedida, o la última publicada): baja tienda-spa-v{V}-dist.zip y tienda-api-v{V}.zip del release de GitHub, genera íconos y og:image en una carpeta propia de la corrida en el VPS de builds, personaliza el dist (tokens + config.js) y sube SPA y API por SFTP. No compila en el VPS. La respuesta trae ecommerce_version.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'La tienda tiene que estar configurada: spa_url, api_url y dominio resoluble.',
                'Tienen que estar cargadas las credenciales SSH del VPS de builds y las del hosting compartido.',
                'No puede haber otra corrida en curso para esa tienda.',
                'Si se pide una versión (ecommerce_version_id o version), tiene que existir y estar published, y si vienen los dos tienen que ser la misma: si no, 422 y no se encola nada.',
                '🔴 Sin versión publicada (y con DEPLOY_PERMITIR_BUILD_EN_VPS apagada, el default) la corrida se crea igual pero FALLA al arrancar, sin tocar ningún servidor, con un mensaje que dice cómo publicar. Si al release le falta un asset, también falla al arrancar, nombrando repo, tag y asset. La respuesta lo avisa en nota_version.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request. El panel lo despacha pelado y con QUEUE_CONNECTION=sync eso correría el pipeline entero adentro del request HTTP.',
                '🔴 Nunca crea una instalación inicial: siempre mode="update". Es una decisión de Lucas y tiene su test.',
            ],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'El cliente cuya tienda se actualiza. GET claude/ecommerce/stores dice cuáles se pueden actualizar ahora y por qué no las otras.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente. ⚠️ Acá NO hay dry_run: el freno del endpoint individual es el nombre, igual que en send-template de a uno.'],
                ['nombre' => 'ecommerce_version_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'La versión de ecommerce a desplegar (GET claude/ecommerce/versions). Tiene que estar published. Sin ella ni version, la última publicada.'],
                ['nombre' => 'version', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30', 'que_es' => 'Alternativa a ecommerce_version_id: el código ("1.0.0"). Si vienen los dos y no son la misma, 422.'],
            ],
        ],
        'POST api/claude/ecommerce/updates/batch' => [
            'para_que'     => 'Dispara la actualización de hasta cinco tiendas nombradas una por una, todas a la MISMA versión de ecommerce (la pedida, o la última publicada). La simulación y la respuesta traen ecommerce_version.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'Sólo client_ids explícitos: el lote NO acepta filtros. Es la misma regla del lote de leads, y acá pesa más porque arranca pipelines SSH.',
                'dry_run por defecto: si no se pide lo contrario, simula y no crea ninguna corrida.',
                'confirm_client_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals sobre el id y el nombre normalizado de cada cliente.',
                '🔴 Tope MAX_LOTE_ECOMMERCE = 5, y el número es derivado y no elegido a ojo: queue:work corre cada minuto SIN withoutOverlapping(), varias corridas compiten por el lock del clone de tienda-spa, que espera hasta 1800 s y después tira RuntimeException. Con ~6 min de lock por corrida, la sexta supera los 30 minutos y muere sola. ⚠️ Desde la misión versiones-tienda (1/10/2026) la vía de artefacto NO toma ese lock (no compila); el tope se mantiene igual porque la vía vieja sigue existiendo detrás de DEPLOY_PERMITIR_BUILD_EN_VPS.',
                'Cooldown de 6 horas por tienda para las corridas creadas por Claude.',
                'Todas las precondiciones del de a uno se evalúan por cliente y lo que no pasa queda como omitido, con el motivo.',
                'La versión (ecommerce_version_id o version) es UNA para todo el lote y se valida antes de mirar las tiendas: si no existe, no está published o los dos parámetros no coinciden, 422 y cero corridas. Si se pidió explícita, entra en el confirm_token: una simulación con una versión no confirma un lote con otra.',
                /* ⚠️ Acá decía "si el presupuesto corta a la mitad", copiado del lote de upgrades:
                   este endpoint NO tiene presupuesto de tiempo (son cinco tiendas y sólo inserts).
                   Y el orden garantiza una sola de las dos mitades, no las dos: ver el comentario de
                   la escritura real en ClaudeEcommerceOpsController. */
                'Los N despachos van juntos al final, después de crear las N filas: así ningún job puede quedar apuntando a una fila que la transacción revirtió. ⚠️ No garantiza lo inverso: si falla el k-ésimo dispatch, las filas k..N quedan en "pendiente" sin job y hay que borrarlas a mano.',
                'Nunca crea una instalación inicial: siempre mode="update".',
            ],
            'parametros'   => [
                ['nombre' => 'client_ids[]', 'obligatorio' => true, 'validacion' => 'required|array|min:1, cada ítem required|integer|min:1', 'que_es' => '🔴 Los clientes, nombrados uno por uno. NO acepta filtros: acá un filtro mal escrito serían pipelines SSH sobre negocios que nadie eligió. Tope de 5.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Sin dry_run=false explícito NO crea ninguna corrida: devuelve qué tiendas se actualizarían, cuáles quedan omitidas y con qué motivo, y el confirm_token.'],
                ['nombre' => 'confirm_client_count', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:0 — obligatorio cuando dry_run=false', 'que_es' => 'Cantidad exacta de tiendas que se actualizarían. Tiene que coincidir con la simulación.'],
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token de la simulación, comparado con hash_equals sobre el id y el nombre normalizado de cada cliente (y la versión, si se pidió una).'],
                ['nombre' => 'ecommerce_version_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'La versión de ecommerce de TODO el lote. Tiene que estar published. Sin ella ni version, la última publicada. No es un filtro: elige qué se despliega, no a quién.'],
                ['nombre' => 'version', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30', 'que_es' => 'Alternativa a ecommerce_version_id: el código ("1.0.0"). Si vienen los dos y no son la misma, 422.'],
            ],
        ],

        'POST api/claude/ecommerce/installs' => [
            'para_que'     => '🔴 INSTALA DESDE CERO la tienda de UN cliente (decisión de Lucas, 2/10/2026): es la única ruta claude/* que crea una corrida mode="install". Baja los artefactos de la versión de ecommerce (la pedida o la última publicada), sube SPA y API al hosting del negocio, ESCRIBE el .env de tienda-api (base de datos y APP_KEY copiadas de la empresa del cliente) y finaliza. La tienda tiene que estar registrada antes con POST claude/ecommerce/stores.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no crea nada y devuelve lo que haría (cliente, dominio, paths, versión y precondiciones).',
                'Lista blanca de parámetros: cualquier otra clave es 422 y no se crea nada.',
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'La tienda tiene que estar registrada (POST claude/ecommerce/stores) y en status "pending": si está "active" ya está instalada (422: se usa POST claude/ecommerce/updates) y si está "installing" hay una corrida en curso (422).',
                'Las precondiciones de la actualización: dominio, spa_url y api_url; credenciales SSH del VPS de builds y del hosting compartido; ninguna corrida pendiente ni en curso.',
                'Las de instalación del panel (la misma clase, EcommerceInstallPrerequisites): plantilla de .env de tienda cargada y API de empresa activa del cliente, de donde salen DB_* y APP_KEY.',
                '🔴 La empresa del cliente tiene que vivir en el hosting compartido (client_apis.hosting_type = shared_hosting): este pipeline sube tienda-api siempre al shared y le copia el .env de la empresa, así que con la empresa en el VPS la tienda quedaría apuntando a otro MySQL. Un cliente de VPS es 422 y va por el carril manual (/instalar-tienda, Carril B, en CloudPanel). El dry_run lo muestra en hosting_type_empresa.',
                'Una versión de ecommerce publicada: la pedida (si no existe, no está published o id y código no coinciden, 422) o la última. Sin ninguna publicada, 422 sin crear la corrida.',
                'El re-chequeo (tienda en pending y sin corrida pendiente ni en curso) y el alta van en una transacción con la fila de la tienda bloqueada (lockForUpdate): dos POST simultáneos con dry_run=false no crean dos instalaciones.',
                'Crea UNA sola corrida mode="install", created_via="claude", y la encola después del commit con onConnection("database"): nunca corre el pipeline adentro del request.',
            ],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'El cliente cuya tienda (ya registrada y en pending) se instala.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente (clients.name), comparado con trim + minúsculas.'],
                ['nombre' => 'ecommerce_version_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'La versión de ecommerce a instalar (GET claude/ecommerce/versions). Tiene que estar published. Sin ella ni version, la última publicada.'],
                ['nombre' => 'version', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30', 'que_es' => 'Alternativa a ecommerce_version_id: el código ("1.0.0"). Si vienen los dos y no son la misma, 422.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false crea la corrida y la encola (202). Sin él, simula y no escribe nada.'],
            ],
        ],

        /* ---------------------------------------------------------- Versiones de ecommerce */

        'GET api/claude/ecommerce/versions' => [
            'para_que'     => 'Versiones de ecommerce (tienda-spa + tienda-api), de la más nueva a la más vieja por orden semántico del código. status=published por defecto (las únicas que se despliegan), o draft / archived / all. Trae ultima_publicada: la que usa una actualización que no pide versión.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/ecommerce/versions' => [
            'para_que'     => 'Da de alta una versión de ecommerce: el puntero a tienda-spa-v{V}-dist.zip y tienda-api-v{V}.zip del release del tag v{V} de cada repo. Es el paso que sigue al commit vacío [release:X.Y.Z] en master de los dos repos de tienda, cuando los dos workflows de GitHub Actions terminaron verdes.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'version con el formato de VersionNumberComparator (al menos tres números: 1.0.0) y única en ecommerce_versions.',
                '🔴 Si queda published (el default) y verify_artifacts no es false, verifica en GitHub que estén los DOS assets del release: si falta uno, 422 nombrando repo, tag y asset; si GitHub falla de otra manera (token, red), 502. En los dos casos NO se escribe nada.',
                'Un borrador (status=draft) no se verifica: se verifica al pasarlo a published con el PATCH.',
                'Es una fila del admin: no toca ningún servidor. Desplegarla es POST claude/ecommerce/updates.',
            ],
            'parametros'   => [
                ['nombre' => 'version', 'obligatorio' => true, 'validacion' => 'required|string|max:30|regex:VersionNumberComparator::VALID_REGEX|unique:ecommerce_versions,version', 'que_es' => 'El código, IGUAL al del commit [release:X.Y.Z] de los dos repos de tienda: arma el tag (v{V}) y el nombre de los assets.'],
                ['nombre' => 'title', 'obligatorio' => false, 'validacion' => 'nullable|string|max:200', 'que_es' => 'Título corto.'],
                ['nombre' => 'description', 'obligatorio' => false, 'validacion' => 'nullable|string|max:5000', 'que_es' => 'Qué trae la versión.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'nullable|string|in:draft,published,archived', 'que_es' => 'Default published (con published_at = ahora).'],
                ['nombre' => 'verify_artifacts', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => 'Default true. Con false publica sin mirar GitHub: si falta un asset, la primera actualización falla al arrancar.'],
            ],
        ],
        'PATCH api/claude/ecommerce/versions/{id}' => [
            'para_que'     => 'Edita título, descripción y/o estado de una versión de ecommerce. Al pasarla a published estampa published_at si no tenía.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                '🔴 El código (version) NO se edita: mandarlo es 422. Arma el tag y el nombre de los assets, y las tiendas y corridas apuntan a la fila. Si está mal, se archiva y se carga la correcta.',
                'Al pasar a published una versión que no lo estaba, verifica los dos assets del release igual que el alta (salvo verify_artifacts=false): si falta uno, 422; si GitHub falla, 502; y no se cambia nada.',
                'Sin ningún campo editable en el cuerpo, 422.',
                'Es una fila del admin: no toca ningún servidor.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La versión a editar.'],
                ['nombre' => 'title', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:200', 'que_es' => 'Título corto.'],
                ['nombre' => 'description', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:5000', 'que_es' => 'Qué trae la versión.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'sometimes|required|string|in:draft,published,archived', 'que_es' => 'Nuevo estado. Archivar una versión no toca las tiendas que la tienen instalada: sólo deja de desplegarse.'],
                ['nombre' => 'verify_artifacts', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|boolean', 'que_es' => 'Default true. Sólo cuenta al pasar a published.'],
            ],
        ],

        /* ---------------------------------------------------------- App del agente del dueño: imágenes y versiones (misión cruzada app-ia-y-memoria, 29/10/2026) */

        'GET api/claude/ia-imagenes/pendientes' => [
            'para_que'     => 'Los pedidos de imagen de la app ia.comerciocity.com para que la PC de Lucas los genere con su cuenta de ChatGPT: estado pendiente, o reservado con el lease vencido y con intentos por gastar; hasta 20, el más viejo primero. Responde {pedidos: [{id, uuid, client: {id, slug, nombre}, prompt, tamano (cuadrada|vertical|horizontal), estilo, intentos, created_at}]}. Antes de listar da por fallidos los colgados (lease vencido sin intentos, o más viejos que IA_IMAGENES_VENCE_HORAS, default 6) y se lo avisa al cliente.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/ia-imagenes/{id}/reservar' => [
            'para_que'     => 'Reserva un pedido de imagen para un worker por 15 minutos. Responde 200 {lease_token (64 caracteres, en claro solo esta vez), lease_expires_at, pedido}. Con el lease_token vigente del mismo worker_id, RENUEVA la reserva (mismo token, sin gastar un intento).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Todo bajo lockForUpdate; el lease se guarda como sha256, nunca en claro.',
                '423 {code: reservado} si otro worker tiene la reserva vigente; 409 {code: no_pendiente} si ya no está pendiente (lista, fallida, entregada) o gastó sus 2 intentos; 404 {code: pedido_inexistente}.',
                'Cada reserva nueva suma un intento: con 2 gastados el pedido no se vuelve a reservar.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El pedido (ia_imagen_pedidos.id).'],
                ['nombre' => 'worker_id', 'obligatorio' => true, 'validacion' => 'required|string|max:150', 'que_es' => 'Identificador del proceso de la PC.'],
                ['nombre' => 'lease_token', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|min:32|max:128', 'que_es' => 'Para renovar la reserva vigente propia.'],
            ],
        ],
        'POST api/claude/ia-imagenes/{id}/resultado' => [
            'para_que'     => 'Multipart: la PC entrega la imagen generada (png, jpg, jpeg o webp, hasta 15 MB). El admin la guarda en su disco privado (storage/app/ia_imagenes/{client_id}/{uuid}.{ext}; ia:imagenes-vencer la borra del disco a los 7 días de entregada), deja el pedido en lista y encola EntregarImagenIaJob, que se la manda al empresa-api del cliente (POST api/admin-sync/ia-imagenes/{uuid}/resultado, 1 vuelta + 5 reintentos a 1/5/15/60/240 min). Responde 200 {estado: lista}.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Exige la reserva vigente (lease_token del pedido, sin vencer): si no, 423 {code: lease_invalido}.',
                'Idempotente: la misma entrega otra vez con el mismo lease devuelve 200 {estado: lista, estado_actual, duplicado: true} sin guardar ni encolar de nuevo.',
                'El tipo se valida por el contenido del archivo (mimes), no por la extensión del nombre.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El pedido.'],
                ['nombre' => 'lease_token', 'obligatorio' => true, 'validacion' => 'required|string|min:32|max:128', 'que_es' => 'El token de la reserva.'],
                ['nombre' => 'imagen', 'obligatorio' => true, 'validacion' => 'required|file|mimes:png,jpg,jpeg,webp|max:15360', 'que_es' => 'La imagen generada.'],
            ],
        ],
        'POST api/claude/ia-imagenes/{id}/fallo' => [
            'para_que'     => 'La PC no pudo generar la imagen. Con menos de 2 intentos gastados el pedido vuelve a pendiente (200 {estado: pendiente}); si no, queda fallido y se le avisa al empresa-api del cliente como estado=fallida con el error (200 {estado: fallida}).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Exige la reserva vigente: si no, 423 {code: lease_invalido}.',
                'Idempotente: el mismo fallo otra vez con el mismo lease devuelve 200 con el estado actual y duplicado: true.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'El pedido.'],
                ['nombre' => 'lease_token', 'obligatorio' => true, 'validacion' => 'required|string|min:32|max:128', 'que_es' => 'El token de la reserva.'],
                ['nombre' => 'error', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500', 'que_es' => 'Qué pasó (lo ve el dueño si el pedido queda fallido).'],
            ],
        ],
        'GET api/claude/ia/versions' => [
            'para_que'     => 'Versiones de la app ia-spa (ia.comerciocity.com), de la más nueva a la más vieja por orden semántico. status=all por defecto (o published / draft). Trae ultima_publicada y ultima_desplegada (la de deployed_at más reciente).',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/ia/versions' => [
            'para_que'     => 'Da de alta una versión de ia-spa: el puntero al asset ia-spa-v{V}-dist.zip del release del tag v{V} de lucasgonzz/ia-spa. Es el paso que sigue al commit vacío [release:X.Y.Z] en master de ia-spa, con el workflow de GitHub Actions verde (lo hace ia-release.py publicar).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'version con el formato de VersionNumberComparator (1.0.0) y única en ia_app_versions.',
                '🔴 Si queda published (el default) y verify_artifacts no es false, verifica en GitHub el asset: si falta, 422 {code: artefacto_faltante}; si GitHub falla de otra manera, 502 {code: github_no_responde}. En los dos casos NO se escribe nada.',
                'Es una fila del admin: no toca ningún servidor. El deploy lo hace ia-release.py desplegar.',
            ],
            'parametros'   => [
                ['nombre' => 'version', 'obligatorio' => true, 'validacion' => 'required|string|max:30|regex:VersionNumberComparator::VALID_REGEX|unique:ia_app_versions,version', 'que_es' => 'El código, igual al del commit [release:X.Y.Z].'],
                ['nombre' => 'title', 'obligatorio' => false, 'validacion' => 'nullable|string|max:200', 'que_es' => 'Título corto.'],
                ['nombre' => 'description', 'obligatorio' => false, 'validacion' => 'nullable|string|max:5000', 'que_es' => 'Qué trae la versión.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'nullable|string|in:draft,published — default published', 'que_es' => 'Un borrador no se verifica.'],
                ['nombre' => 'verify_artifacts', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default true', 'que_es' => 'false publica sin mirar GitHub (solo si ya lo verificaste a mano).'],
            ],
        ],
        'PATCH api/claude/ia/versions/{id}' => [
            'para_que'     => 'Edita título, descripción, estado y/o deployed_at de una versión de ia-spa. Al pasarla a published estampa published_at. deployed_at lo manda ia-release.py desplegar cuando https://ia.comerciocity.com/version.json ya devuelve esa versión (null lo borra).',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                '🔴 El código (version) NO se edita: 422 {code: version_no_editable}.',
                'Al pasar a published una versión que no lo estaba, verifica el asset igual que el alta (salvo verify_artifacts=false): 422 o 502 y no se cambia nada.',
                'deployed_at con fecha solo en una versión published: si no, 422 {code: no_publicada}. Sin ningún campo editable, 422 {code: sin_campos}.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La versión.'],
                ['nombre' => 'title', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:200', 'que_es' => 'Título corto.'],
                ['nombre' => 'description', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|string|max:5000', 'que_es' => 'Qué trae la versión.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'sometimes|required|string|in:draft,published', 'que_es' => 'Nuevo estado.'],
                ['nombre' => 'deployed_at', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|date', 'que_es' => 'Cuándo quedó servida en el VPS (ISO 8601).'],
                ['nombre' => 'verify_artifacts', 'obligatorio' => false, 'validacion' => 'sometimes|nullable|boolean', 'que_es' => 'Default true. Solo cuenta al pasar a published.'],
            ],
        ],

        /* ---------------------------------------------------------- Implementaciones de clientes nuevos (misión implementar-cliente, 5/10/2026) */

        'GET api/claude/implementations/{id}' => [
            'para_que'     => 'El estado completo de UNA implementación de cliente nuevo, en una sola llamada: la etapa en la que está y cómo va cada una de las siete (con las acciones que se registraron; solo una implementación anterior al 15/10/2026 que ya llegó a la etapa 8 la trae como octava), dónde vive el sistema del cliente, las instalaciones y su estado, si el user setup corrió (sin_correr | en_curso | ok | error), los mails de hito que ya salieron y si el cliente escribió algo que nadie contestó (`entrantes`: mientras la implementación está abierta, lo que el cliente le escribe al número del sistema cae al hilo de la implementación y nadie lo mira). Trae el form_link (el link del formulario que se le manda al cliente). El bloque `cliente` trae `contract_sistemas` (los sistemas del contrato: gestion, ecommerce, agentes; null si el contrato no lo dice) y `tienda_contratada` (true si incluye ecommerce, false si la lista está cargada y no lo incluye, null si no se sabe: preguntale a Lucas). Cada instalación trae `colgada` (una instalando sin actividad hace más de 60 minutos: ni logs ni escrituras de la fila) y `ultima_actividad_at`. El contacto y el resumen del formulario NO vienen por defecto: se piden con include.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación (implementation.id).'],
                ['nombre' => 'include', 'obligatorio' => false, 'validacion' => 'csv o lista; cada valor en contacto, formulario o logs', 'que_es' => 'contacto: teléfono y mail del cliente y el teléfono del responsable de migración. formulario: el resumen de lo que el cliente cargó (sin el DNI ni el teléfono de los empleados ni la dirección ni las redes del negocio salvo que también pidas contacto; el mail y el documento del dueño no salen nunca). logs: las últimas 40 líneas del log de cada instalación.'],
            ],
        ],
        'GET api/claude/implementations' => [
            'para_que'     => 'Lo mismo que GET claude/implementations/{id}, pero buscando por client_id o por lead_id (exactamente uno) cuando no se conoce el id de la implementación: la skill arranca con un negocio o un lead, no con un id. Con lead_id se resuelve por el cliente al que se promovió ese lead. 404 si el lead todavía no se promovió o si el cliente no tiene implementación.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'El cliente cuya implementación se quiere ver. Exactamente uno de client_id o lead_id.'],
                ['nombre' => 'lead_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'El lead que compró: se resuelve por su cliente promovido. Exactamente uno de client_id o lead_id.'],
                ['nombre' => 'include', 'obligatorio' => false, 'validacion' => 'csv o lista; cada valor en contacto, formulario o logs', 'que_es' => 'Igual que en GET claude/implementations/{id}.'],
            ],
        ],
        'POST api/claude/implementations' => [
            'para_que'     => '"Tal lead compró": da de alta una implementación. Con lead_id promueve el lead a cliente (el mismo servicio del botón "Promover a cliente": crea el Client con su user_id, las tres tareas de lead_a_cliente y las DOS ClientApi estándar <sub> y <sub>2, ambas en el hosting compartido) y arranca la implementación (las siete etapas, la etapa 1 en curso y el token del formulario); si el lead ya estaba promovido, usa ese cliente. Con client_id arranca la implementación de un cliente que ya existe y no tiene. 🔴 La implementación nace SIEMPRE en modo manual y sin plantilla de bienvenida: los mensajes al cliente los manda la skill por WhatsApp Web, con el ok de Lucas. El subdominio (de él salen las cuatro URLs del cliente) lo definen Lucas y la skill juntos: el dry_run sugiere uno y dice si el pedido sirve. Solo escribe filas del admin: no toca ningún servidor.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no crea nada y devuelve lo que haría —el cliente, el subdominio pedido y el sugerido, las cuatro URLs, la versión que se instalaría y los avisos— y en `bloqueos` todo lo que haría fallar al alta real.',
                'Lista blanca de parámetros: cualquier otra clave es 422 y no se crea nada.',
                'Exactamente uno de lead_id o client_id: los dos juntos pueden apuntar a negocios distintos (422).',
                'confirm_nombre exacto (razón social del lead o del cliente, o el nombre del contacto si está vacía; trim + minúsculas), obligatorio con dry_run=false. No revela el correcto cuando falla.',
                '409 si el cliente ya tiene una implementación, con su id y su etapa: no se crea otra. La tabla no tiene índice único por cliente, así que el alta real bloquea la fila del cliente y vuelve a mirar adentro de la transacción: dos POST simultáneos no crean dos.',
                'El subdominio, si hay que promover, es obligatorio para confirmar y tiene que: ser [a-z0-9-] de 1 a 20 caracteres y empezar por letra o número; no terminar en guion; no ser un nombre reservado (admin, api, www, mail, smtp, ftp, webmail, demo, app, soporte, tienda, imap, pop, pop3, cpanel, autodiscover, localhost, y demo o ns seguidos de números); dejar la base u767360347_<sub> dentro de los 32 caracteres de MySQL; y no estar usado por ninguna ClientApi del admin ni como <sub> ni como <sub>2. 🔴 Se chequea solo contra el admin, no contra Hostinger: lo vuelve a verificar la instalación. 🔴 Y el chequeo se REPITE adentro del lock global de altas: si otra promoción ocupó el subdominio entre medio, 422 y no se crea nada.',
                '🔴 Un cliente que YA existe (client_id, o un lead ya promovido a él) con un sistema vivo —cualquier fila en client_version_upgrades— es 422 (en el dry_run, listo=false con el bloqueo): no se arranca una implementación sobre un negocio que opera.',
                'Sin ningún admin que pueda figurar como creador de las tareas de la promoción (CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID, el admin por defecto de tareas o el primero), 422.',
                'La promoción y la implementación van en UNA transacción: si algo falla no queda un cliente sin tareas ni ClientApis (el panel sí puede dejarlo así). Y mientras promueve toma un lock global, porque el bloque de user_id del cliente nuevo se calcula sin lock.',
                'Si la URL base del formulario (implementation_form_url) está vacía, el alta se hace pero form_link sale null y va un aviso explícito.',
            ],
            'parametros'   => [
                ['nombre' => 'lead_id', 'obligatorio' => false, 'validacion' => 'required_without:client_id|nullable|integer|min:1', 'que_es' => 'El lead que compró. Si ya tiene cliente promovido se usa ese y el subdominio se ignora.'],
                ['nombre' => 'client_id', 'obligatorio' => false, 'validacion' => 'required_without:lead_id|nullable|integer|min:1', 'que_es' => 'Un cliente que ya existe y todavía no tiene implementación. Exactamente uno de lead_id o client_id.'],
                ['nombre' => 'subdominio', 'obligatorio' => false, 'validacion' => 'nullable|string|regex:/^[a-z0-9][a-z0-9-]{0,19}$/', 'que_es' => 'El slug de las URLs: <sub>.comerciocity.com, <sub>2, api-<sub> y api-<sub>2. Solo cuando hay que promover; obligatorio para el alta real, y el dry_run sugiere uno si falta.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false da el alta (201). Sin él, simula y no escribe nada.'],
                ['nombre' => 'confirm_nombre', 'obligatorio' => false, 'validacion' => 'required_if:dry_run,false|nullable|string|max:190', 'que_es' => 'El nombre del negocio del lead o del cliente (company_name, o el del contacto si está vacío). Obligatorio con dry_run=false.'],
            ],
        ],
        'POST api/claude/implementations/{id}/advance' => [
            'para_que'     => 'Avanza la implementación a la etapa siguiente: cierra la actual (completed, o skipped si no aplica —p. ej. ARCA— con saltar=true), deja en curso la que sigue y guarda una nota opcional en esa etapa. Hace lo que "Avanzar etapa" del panel, con TRES diferencias deliberadas: 🔴 NO dispara handle_stage_advance (entrar a la etapa 2 no crea la instalación: la crea POST claude/implementations/{id}/install, con la versión y el aprovisionamiento correctos), 🔴 desde la última etapa (la 7, vinculación con ARCA) CIERRA la implementación (status=completed, completed_at) dejando current_stage en 7 —el panel no la puede cerrar; una implementación anterior al 15/10/2026 que ya está en la 8 se sigue cerrando igual, y la etapa 8 que traiga pendiente queda skipped— y 🔴 cerrar la etapa 2 con un user setup EN CURSO (no colgado) da 409: el panel deja avanzar mientras el sistema del cliente se está vaciando y re-sembrando (migrate:fresh). Cerrarla importa: mientras esté abierta, todo lo que el cliente le escribe al número de WhatsApp del sistema cae al hilo de la implementación y no llega a soporte ni al asistente. No manda ningún mensaje ni toca ningún servidor.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no avanza nada y devuelve de qué etapa a cuál pasaría, cómo la marcaría y si cerraría la implementación.',
                'Lista blanca de parámetros: cualquier otra clave es 422 y no se avanza nada.',
                '🔴 etapa_actual (obligatoria) tiene que ser la etapa real: si no, 409 con la verdadera y no se hace nada. Es lo que evita avanzar dos veces por un reintento o por dos sesiones que miraron el mismo estado.',
                'Una implementación ya completed no avanza: 409.',
                '🔴 Cerrar la etapa 2 con un user setup EN CURSO es 409 y no se hace nada: avanzar mientras el job espera en la cola o corre pasaría al negocio a una etapa donde ya se le cargan datos mientras su sistema se vacía (migrate:fresh). Uno colgado (más de 45 minutos sin señal) no frena: ahí hay que resolver (conciliar o reintentar), no esperar.',
                'El cierre de la etapa y la apertura de la siguiente van en una transacción con la fila de la implementación bloqueada, y se vuelve a verificar la etapa adentro: dos POST simultáneos no avanzan dos etapas.',
                'No valida que la etapa esté lista (el panel tampoco), pero AVISA en `avisos`, sin frenar, lo que detecta que falta: formulario sin enviar al cerrar la 1; sistema sin instalar o user setup sin aplicar al cerrar la 2; y que cerrar la 7 (la última) cierra la implementación.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => 'etapa_actual', 'obligatorio' => true, 'validacion' => 'required|integer|between:1,8', 'que_es' => 'La etapa que se está cerrando (el flujo tiene siete; la validación acepta hasta 8 solo para poder cerrar una implementación anterior al 15/10/2026 que quedó en la 8). Tiene que coincidir con current_stage: si no, 409.'],
                ['nombre' => 'saltar', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false', 'que_es' => 'true = la etapa queda skipped en vez de completed (no aplica). Se avanza igual.'],
                ['nombre' => 'nota', 'obligatorio' => false, 'validacion' => 'nullable|string|max:500', 'que_es' => 'Se agrega a data.notas[] de la etapa que se cierra, con la fecha y origen "claude".'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false avanza (200). Sin él, simula y no escribe nada.'],
            ],
        ],
        'POST api/claude/implementations/{id}/actions' => [
            'para_que'     => 'REGISTRA una acción que se hizo por fuera del admin —un WhatsApp mandado por WhatsApp Web, un mail, una llamada— para que el panel y el estado la vean. 🔴 NO manda nada: el mensaje ya salió, desde la skill y con el ok de Lucas. Agrega {action, stage, at, canal, origen:"claude"} a data.actions[] de la etapa (por defecto la actual): es el mismo registro que escriben las acciones del panel, así que su checklist se tilda (presentacion, form_link, pedir_archivos y entrega son las claves que lee la SPA). Con canal=whatsapp_web además crea el implementation_messages SALIENTE con el texto, para que el hilo muestre lo que se le mandó al cliente y `entrantes` sepa que ya se le respondió. Es idempotente por 10 minutos.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'No tiene dry_run: solo escribe la huella de algo que ya pasó, y no manda ningún mensaje ni toca ningún servidor.',
                'Lista blanca de parámetros: cualquier otra clave es 422 (un `content` o un `enviar` suelen ser alguien esperando que acá se mande el mensaje).',
                '🔴 El saliente del hilo lleva SIEMPRE whatsapp_message_id = "waweb-<uuid>", nunca null: un saliente con id nulo se lee como envío fallido (la tabla no tiene columna de estado) y no contaría como respuesta en `entrantes`.',
                '🔴 Idempotente: la misma acción, por el mismo canal, para la misma etapa, con el mismo texto y AL MISMO TELÉFONO (ya normalizado), en los últimos 10 minutos devuelve 200 {ya_registrada:true} sin duplicar ni la entrada ni el mensaje; vale reintentar una llamada que cortó el timeout. El destinatario es parte de la clave: el mismo texto a dos teléfonos distintos (el dueño y el responsable de migración) son dos mensajes. La comprobación y el alta van en una transacción con la fila de la etapa bloqueada: dos llamadas simultáneas tampoco duplican.',
                'Con whatsapp_web hace falta un teléfono: el de `telefono` (se normaliza con WhatsappNormalizer, el MISMO con el que el webhook normaliza lo que entra, para que el hilo compare teléfonos iguales: saca el 0 de marcado local y arma el +549) o, si no, el del cliente —el pedido de archivos va al responsable de migración, como en el panel—. Sin ninguno, 422 y no se registra nada. La respuesta devuelve el teléfono ENMASCARADO (mensaje.telefono = ***9999): el entero queda solo en la base.',
                'Con whatsapp_web, después del commit se emite el evento Pusher del hilo (ImplementationMessageReceived) con el saliente creado, para que el panel lo agregue al hilo abierto sin recargar, como cuando lo manda el propio panel. No se emite con otro canal ni en una repetición, y un Pusher caído no rompe el registro (se loguea).',
                'user_setup y crear_instalacion NO se pueden registrar por acá: las escriben sus propios endpoints.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => 'accion', 'obligatorio' => true, 'validacion' => 'required|string|in:presentacion,form_link,progreso,pedir_archivos,entrega,acceso,imagenes,categorias,descripciones,listo,nota', 'que_es' => 'Qué se hizo.'],
                ['nombre' => 'canal', 'obligatorio' => true, 'validacion' => 'required|string|in:whatsapp_web,mail,llamada,otro', 'que_es' => 'Por dónde. Solo whatsapp_web crea además el saliente del hilo.'],
                ['nombre' => 'texto', 'obligatorio' => false, 'validacion' => 'required_if:canal,whatsapp_web|nullable|string|max:4000', 'que_es' => 'El texto que se mandó. Obligatorio con whatsapp_web (es el cuerpo del mensaje del hilo). Con accion=nota es la nota.'],
                ['nombre' => 'telefono', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30', 'que_es' => 'A quién se le mandó (solo cuenta con whatsapp_web). Se normaliza a E.164. Sin él, el del cliente.'],
                ['nombre' => 'etapa', 'obligatorio' => false, 'validacion' => 'nullable|integer|between:1,8', 'que_es' => 'La etapa en cuyo registro se anota (1 a 7; la 8 solo existe en una implementación anterior al 15/10/2026). Por defecto la actual.'],
            ],
        ],
        'POST api/claude/implementations/{id}/install' => [
            'para_que'     => '🔴 INSTALA el sistema (empresa-api + empresa-spa) del cliente de la implementación, y el admin aprovisiona: crea el par de instalaciones —la real en la API activa y el esqueleto del subdominio hermano— con provision_hosting_type = shared_hosting (los cuatro subdominios, la base y el cron en Hostinger), sube el SPA y la API por SFTP, escribe el .env y finaliza. Es el equivalente de "Nueva instalación" + "Iniciar" del módulo de Instalaciones del panel, de una vez. SIEMPRE con la ÚLTIMA versión publicada (no la que quedó fijada al promover, que puede tener semanas) y SOLO si el admin tiene el token de Hostinger: sin él el chequeo token_de_hostinger sale en false con "sin token de Hostinger en el admin: instalar con /instalar-cliente". Se encola en la conexión database con 202 y tarda ~15 minutos: se mira con GET claude/implementations/{id}?include=logs. Una instalación pendiente sin arrancar (la que deja el panel al avanzar a la etapa 2) se reutiliza; una fallida no (se crea un par nuevo). Al terminar la instalación REAL deja clients.current_version_id en la versión que instaló (así la primera actualización del cliente parte de ahí y no de la versión con la que se promovió el lead).',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no crea ni encola nada y devuelve los NUEVE chequeos con su detalle, lo que crearía y con qué valores completaría las variables del .env.',
                'Lista blanca de parámetros: cualquier otra clave es 422 y no se instala nada. No se acepta version, provision_hosting_type, kind ni nada que elija lo que está fijado a propósito.',
                'confirm_client_name exacto (clients.name, trim + minúsculas), obligatorio con dry_run=false. No revela el correcto cuando falla.',
                'Los nueve chequeos, todos en el dry_run y TODOS tienen que dar ok para el real (si no, 422 con la lista): (1) la implementación está en la etapa 2 y el formulario se envió; (2) la estructura del hosting —las cinco guardas de HostingProvisioningStructure: exactamente 2 ClientApi, el par <slug>/<slug>2, el slug [a-z0-9-] de hasta 20, los hosts api-<slug>, la base dentro de los 32 caracteres— más que ninguna API figure en el VPS; (3) las URLs de las dos APIs en https; (4) hay una versión publicada; (5) hay credencial SSH de tipo shared_hosting; (6) el token de Hostinger del admin está cargado; (7) las instalaciones previas; (8) 🔴 sin_sistema_vivo: el cliente no tiene NINGUNA fila en client_version_upgrades (un sistema al que se le actualizó la versión ya estuvo en producción, y reinstalar sobre un negocio que opera le pisa el .env y la base; la tabla clients no tiene sistema_configurado_at: esa marca vive en las actualizaciones); (9) las variables manuales del .env. Como señal ADICIONAL e informativa —no entra en listo— el dry_run consulta GET <api del cliente>/api/version-activa con 5 s de techo y, si responde 200 con una default_version, avisa en `avisos` que ya hay un sistema andando ahí (`senales.version_activa`); el real no la consulta.',
                '🔴 Instalaciones previas: una instalando es 409 (no se pisa un pipeline vivo) y una completada es 422 ("ya está instalado": reinstalar le pisa el .env a un negocio que ya anda). Una pendiente se reutiliza con la última versión; una fallida queda de historial y se crea un par nuevo. 🔴 Una instalando que nadie va a terminar (el worker murió sin pasar por failed()) se ve en GET claude/implementations/{id} como colgada=true —más de 60 minutos sin actividad: ni logs ni escrituras de la fila— y se destraba con marcar_colgadas=true: si TODAS las instalando del cliente están colgadas las pasa a fallida con "colgada: sin actividad desde <fecha>" y sigue con un par nuevo; si UNA tiene actividad reciente, 409 como siempre, y si la real ya está completada no reinstala. No salta ningún otro chequeo (si otro falla, no se marca nada).',
                'Variables manuales del .env (is_manual_on_create): con aprovisionamiento quedan exceptuadas DB_DATABASE, DB_USERNAME y DB_PASSWORD (las genera el pipeline); DB_CONNECTION, DB_HOST y DB_PORT se completan con el valor de la plantilla o, si no lo trae, el estándar del compartido (mysql, 127.0.0.1, 3306), y el dry_run muestra con qué; cualquier otra variable manual sin valor frena.',
                'El re-chequeo y el alta van en UNA transacción con la implementación bloqueada (lockForUpdate): dos POST simultáneos no crean dos pares. Las filas pasan a instalando ahí mismo y el job se despacha DESPUÉS del commit.',
                '🔴 Se encola con onConnection("database") y 202: nunca corre el pipeline adentro del request. El job (EjecutarInstalacionDeImplementacionJob) tiene $timeout 2300, por debajo del retry_after de la cola (2400) —el job de grupo del panel declara 5700 y en esa conexión terminaría en failed_jobs sin haber fallado—; si el worker lo corta, failed() deja las filas en fallida con el motivo en vez de dejarlas instalando para siempre (necesita pcntl en el CLI del servidor).',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación cuyo cliente se instala.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false crea el par y lo encola (202). Sin él, simula y no escribe nada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'required_if:dry_run,false|nullable|string|max:190', 'que_es' => 'El nombre exacto del cliente (clients.name). Obligatorio con dry_run=false.'],
                ['nombre' => 'marcar_colgadas', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false', 'que_es' => 'true = si TODAS las instalaciones en instalando del cliente están colgadas (más de 60 minutos sin actividad), pasarlas a fallida ("colgada: sin actividad desde <fecha>") y seguir con un par nuevo. Con una viva, 409. El dry_run dice si lo haría (se_crearia.modo = marca_las_colgadas_como_fallidas_y_crea_el_par).'],
            ],
        ],
        'POST api/claude/implementations/{id}/user-setup' => [
            'para_que'     => '🔴🔴 CONFIGURA el sistema del cliente con lo que cargó en el formulario (listas de precios, sucursales, IVA, el usuario dueño con su documento, la tienda...): le pega a admin-sync/user-setup de su empresa-api con el payload armado desde clients.setup_data. DEL OTRO LADO ARRANCA CON migrate:fresh --force: LE VACÍA LA BASE AL CLIENTE. Por eso este camino NO TIENE "forzar" (el botón del panel sí): una vez aplicado (user_setup_executed_at lleno) no se vuelve a correr por acá, y no hay que agregarle un forzar. Corre en la cola database (202) y no dentro del request: la llamada HTTP va con 1200 segundos de techo (el panel usa 15, con los que corta la espera mientras el setup sigue corriendo del otro lado). El dry_run devuelve el payload REAL con las claves de servicios pagos tapadas y el mail, el documento y el teléfono del dueño ENMASCARADOS (p***@dominio, ***4567) y la dirección y las redes del negocio tapadas (***); enteros solo con include=contacto; el resultado se lee en user_setup de GET claude/implementations/{id} (en_curso → ok | error).',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no encola nada y devuelve los chequeos y el payload que viajaría (con las claves de Serper/Google tapadas). 🔴 El mail, el documento y el teléfono del dueño salen ENMASCARADOS (p***@dominio, ***4567), y la dirección y las redes del negocio tapadas (***), salvo con include=contacto: quien lee la respuesta es una sesión que la pega en una conversación, y para decidir si se aplica no hacen falta enteros.',
                'Lista blanca de parámetros: cualquier otra clave es 422 y no se aplica nada. 🔴 No existe ni se acepta force/forzar (reintentar y conciliar NO son un forzar: solo valen tras un error, nunca con el candado lleno).',
                'confirm_client_name exacto (clients.name), obligatorio con dry_run=false. No revela el correcto cuando falla.',
                'Nueve chequeos (con uno en false el real es 422): el formulario se envió; 🔴 la implementación está EXACTAMENTE en la etapa 2 (chequeo etapa_2, como install: antes no hay sistema instalado, y después el negocio puede estar operando y migrate:fresh le borraría lo que cargó); la última instalación completa de la API activa está completada; la API activa tiene URL (el destino que se muestra y al que se llama es el NORMALIZADO como en todos los demás llamados del admin: con /public en hosting compartido, sin él en VPS); 🔴 la instalación completada es POSTERIOR al arranque de la implementación (instalacion_de_esta_implementacion: una anterior es un sistema que ya existía, instalado por afuera de este camino); 🔴 el cliente no tiene un sistema vivo (sin_sistema_vivo: ninguna actualización registrada en client_version_upgrades, el mismo chequeo que el alta y install); 🔴 el user setup no se aplicó ya por el camino de leads (lead_sin_user_setup: leads.user_setup_status del lead promovido en ejecutandose, exitoso o sin_confirmar; ese camino llama al mismo endpoint remoto y no escribe el candado); user_setup_executed_at está vacío; no hay otro en curso.',
                '🔴 Ya aplicado (user_setup_executed_at lleno) es 422 sin vuelta, con la fecha y el motivo: "re-aplicarlo vacía la base del cliente (migrate:fresh): si de verdad hace falta, se hace desde el panel, con una persona mirando".',
                '🔴 Tras un error del último intento NO se repite la llamada tal cual: el real es 422 explicando que el intento anterior pudo haber corrido del otro lado (un 502, un timeout o un worker muerto no lo descartan) y solo sigue con UNO de dos parámetros, excluyentes y con confirm_client_name: reintentar=true (el dueño NO existe en el sistema del cliente: vuelve a despachar el job, con migrate:fresh del otro lado) o conciliar=true (el dueño YA existe: NO llama al cliente, llena user_setup_executed_at, deja el estado en ok con la nota "conciliado: el dueño ya existía en el sistema del cliente" y registra la acción user_setup con canal claude). Sin un error previo los dos son 422. El dry_run dice cuál corresponde (corresponde, parametros_para_aplicar, opciones) y qué pasó con el intento anterior (ultimo_intento, con puede_haber_corrido).',
                'Otro user setup en curso es 409 (y advance da 409 al cerrar la etapa 2 con uno en curso). Uno que dice en_curso hace más de 45 minutos —contados desde `llamada_iniciada_at` si el job ya llamó, y si no desde que se encoló: con la cola atrasada el job puede tardar en arrancar— se da por colgado: no hubo señal en 45 minutos (NO se afirma que el job no pueda seguir vivo). Se lee por `llamada_iniciada_at`, que el job escribe bajo lock ANTES de llamar: SIN la marca el job nunca llamó al cliente (la cola estaba parada o atrasada, no salió nada) y la llamada normal vuelve a intentar —es seguro porque cada intento lleva un token (su iniciado_at) y el job viejo, si arranca, se descarta solo sin llamar al cliente—; CON la marca la llamada salió y se cortó sin dejar el resultado: PUDO HABER CORRIDO (puede_haber_corrido=true) y se trata como un error (conciliar o reintentar; la llamada normal es 422). Si el cliente en realidad seguía corriendo uno, empresa-api contesta 409 y vuelve como error, sin reintento.',
                'El registro en_curso (stage 2 data.user_setup) se escribe en una transacción con la implementación bloqueada ANTES de despachar: dos llamadas no despachan dos jobs.',
                '🔴 Se encola con onConnection("database") y 202. El job (EjecutarUserSetupDeImplementacionJob, tries 1, timeout 1500 < retry_after 2400) llama a ImplementationUserSetupService::trigger_user_setup con 1200 s; en éxito llena user_setup_executed_at y registra la acción user_setup (canal "claude"); en error deja el motivo y `puede_haber_corrido`. 🔴 El job NO es re-ejecutable: lleva como token el iniciado_at del registro que lo despachó y solo llama al cliente si, bajo lock, el candado está vacío y el registro sigue en_curso con ESE token (si no, se descarta y lo deja dicho en el log); al terminar vuelve a mirar y no pisa a un intento más nuevo ni a un candado que se llenó mientras tanto. 🔴 Además revalida bajo lock que la implementación siga en la etapa 2 en curso (si alguien la avanzó mientras el job esperaba en la cola, NO llama a nadie y deja el registro en error con puede_haber_corrido=false) y escribe llamada_iniciada_at antes de llamar.',
                '🔴 Un error NO prueba que no corrió: un 502, 503, 504 o 524 (el proxy cortó pero el origen sigue), un timeout, un 409, cualquier otro 5xx y que el worker haya matado el job (failed()) PUDIERON HABER CORRIDO del otro lado —puede_haber_corrido=true—, y el motivo manda a mirar si el dueño existe en el sistema del cliente ANTES de reintentar (si existe, conciliar; si no, reintentar: un reintento le vuelve a vaciar la base). Solo un 4xx (rechazo antes de ejecutar nada) o que ni haya salido la llamada dan puede_haber_corrido=false.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación cuyo sistema se configura.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false encola la configuración (202). Sin él, simula y no escribe nada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'required_if:dry_run,false|nullable|string|max:190', 'que_es' => 'El nombre exacto del cliente (clients.name). Obligatorio con dry_run=false.'],
                ['nombre' => 'include', 'obligatorio' => false, 'validacion' => 'csv o lista; solo contacto', 'que_es' => 'contacto: el dry-run muestra enteros el mail, el documento, el teléfono, la dirección y las redes dentro del payload (por defecto salen enmascarados o tapados). Solo afecta a lo que se muestra; no cambia lo que se aplica.'],
                ['nombre' => 'reintentar', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false; excluyente con conciliar; solo si el último intento terminó en error o quedó colgado DESPUÉS de llamar al cliente', 'que_es' => 'Vuelve a despachar el user setup tras un error o un colgado que llegó a llamar (con un token nuevo). Usalo cuando el dueño NO existe en el sistema del cliente. Exige dry_run=false y confirm_client_name; sin uno de esos dos casos previos es 422.'],
                ['nombre' => 'conciliar', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false; excluyente con reintentar; solo si el último intento terminó en error o quedó colgado DESPUÉS de llamar al cliente', 'que_es' => 'NO llama al cliente: da por aplicado un user setup que terminó en error (o quedó colgado después de llamar) pero que sí corrió del otro lado (el dueño YA existe en su sistema, con sus listas de precios y sus depósitos). Llena user_setup_executed_at, deja el estado en ok con la nota "conciliado: el dueño ya existía en el sistema del cliente" y registra la acción user_setup (canal claude). Exige dry_run=false y confirm_client_name; sin uno de esos dos casos previos es 422.'],
            ],
        ],
        'POST api/claude/implementations/{id}/mail' => [
            'para_que'     => 'Manda (o simula) el MAIL de un hito de la implementación al dueño del negocio —bienvenida, instalando, instalado, acceso, imagenes, categorias o listo—, con la línea de progreso de las siete etapas (el estado real al momento del envío). Sale síncrono por el mailer admin (admin@comerciocity.com) y queda registrado en implementation_mails. El dry_run (default) devuelve el mail armado —asunto, html, la casilla enmascarada— y lo que le FALTA para poder mandarse en `faltan` (email, form_link, url_sistema); un hito que ya salió se avisa en `ya_enviado`; y si el mailer admin no tiene credencial, `mailer.listo` es false y `listo` también (el real daría estado error). Si el mail sale y clients.email estaba vacía, se guarda (así el aviso de actualización tampoco queda sin_mail). El armado, la casilla y el envío viven en ImplementacionMailService; esta ruta pone los frenos y la respuesta. A quién le llega: `email` (si se pasó) → clients.email → setup_data.email del formulario → email del lead promovido.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no manda ni escribe nada (ni en implementation_mails ni en la ficha del cliente) y devuelve el mail para que Lucas lo vea.',
                'Lista blanca de parámetros: cualquier otra clave es 422. No hay `para`, `asunto` ni `html`: el texto, la casilla y el remitente no se eligen desde afuera.',
                'Los datos del hito se validan POR HITO antes de armar nada (422 con `errores` por campo y motivo faltan_datos): una clave que el hito no conoce también es error. bienvenida, instalando e instalado no llevan datos; acceso: articulos (int ≥ 0, obligatorio); imagenes: con_foto y total (ints, obligatorios; el total tiene que ser mayor que cero y con_foto no puede superarlo), a_revisar (int, opcional; no puede superar el total) y fuentes (texto ≤ 160, opcional); categorias: opciones (obligatorio: una opción —la lista de categorías que trajo el dueño, que el mail le pide revisar y confirmar— o 2 o 3 —dos o tres sistemas para elegir; desde el 6/10/2026 /categorizar arma DOS por defecto y tres solo si se pide—; cero o cuatro es 422 con "Tienen que ser una, dos o tres opciones (llegaron N)"; con nombres distintos entre sí, cada una con nombre ≤ 60, base ≤ 280, categorias int ≥ 1 y hasta 4 ejemplos ≤ 40; con 2 o 3 el asunto, el preheader y la introducción del mail dicen "dos" o "tres" según cuántas lleguen, y con una sola opción son otros textos genéricos —"Tu catálogo, ordenado en categorías"—, la tarjeta sale sin "Opción 1" y el cierre va en singular; el admin anterior al 7/10/2026 rechaza una sola opción con 422 "Tienen que ser dos o tres opciones") y como_elegir (≤ 220, opcional); listo: resumen ({articulos, con_foto, categorias, marcas, clientes, proveedores}, ints opcionales; con_foto no puede superar a articulos), recursos_url (url http/https sin usuario ni comillas, opcional) y arca (booleano, opcional: false saca del mail la línea de la facturación electrónica, para un cliente que no factura así). `nota` (un párrafo personal de hasta 600 caracteres; se le quitan los caracteres de control y de dirección del texto) lo acepta todo hito.',
                'confirm_client_name exacto (clients.name), obligatorio con dry_run=false. No revela el correcto cuando falla.',
                '🔴 Un solo envío por (implementación, hito): si ya salió y no viene reenviar=true es 422 con motivo ya_enviado (dice cuándo y a quién, con la casilla enmascarada). Un hito en error se puede reintentar sin reenviar. reenviar=true suma `reenvios`.',
                '🔴 Hay un lock por (implementación, hito) contra el doble envío —una llamada cortada por timeout y reintentada mandaría el mail dos veces, y un mail a un cliente real no se deshace—: con otro envío del mismo hito en vuelo, 409 con motivo envio_en_curso y no se manda nada.',
                'Sin casilla en ninguna parte, 422 con motivo sin_mail y faltan ["email"]: pasá `email`. Sin form_link (bienvenida) o sin dirección de sistema del cliente (acceso, imagenes, listo), 422 con motivo faltan_datos y la lista en `faltan`. instalando e instalado no piden nada de la implementación.',
                'Un envío que FALLA (SMTP caído, sin MAIL_ADMIN_USERNAME/MAIL_ADMIN_PASSWORD en el .env del admin, o la casilla RECHAZADA por el servidor de correo —un 550 no tira ninguna excepción: se lee de failures() del mailer—) NO tira: vuelve 200 con enviado=false, estado="error" y el motivo (con la casilla enmascarada), y queda registrado. Nada se guarda en la ficha si el mail no salió. Ante una casilla rechazada se le pide al cliente la correcta y se reintenta con `email`. "Enviado" significa aceptado por el servidor de correo, no entregado: los rebotes asincrónicos llegan a la bandeja de admin@comerciocity.com. 🔴 Si el mail SALIÓ pero no se pudo anotar en implementation_mails, vuelve 200 con enviado=true y un `aviso` (no reenviar): nunca un 500 ni un error que invite a mandarlo dos veces.',
                'Las excepciones de negocio llevan su status (422, o 409 para envio_en_curso) y los campos error, message (el mismo texto), motivo, errores y faltan.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación cuyo dueño recibe el mail.'],
                ['nombre' => 'hito', 'obligatorio' => true, 'validacion' => 'required|string|in:bienvenida,instalando,instalado,acceso,imagenes,categorias,listo', 'que_es' => 'Qué mail: bienvenida (arrancamos, con el link del formulario), instalando (recibimos el formulario y ya estamos instalando: pide los archivos, sin el centro de recursos; desde el 14/10/2026 es el que va en ese momento), instalado (el sistema ya está instalado; ya no se usa: queda por compatibilidad), acceso (la primera tanda de artículos está cargada; ya no se usa), imagenes (cuántos tienen foto), categorias (una opción —la lista del dueño— o dos o tres opciones para elegir) o listo (la entrega: lo cargado, cómo entrar —dirección, usuario = documento, contraseña = la del WhatsApp; nunca la contraseña—, los tutoriales y lo que sigue, sin la videollamada). El admin anterior al 14/10/2026 rechaza instalando con 422 (hito inválido).'],
                ['nombre' => 'datos', 'obligatorio' => false, 'validacion' => 'nullable|array — validado por hito (ver frenos)', 'que_es' => 'Los datos propios del hito (cantidades, las opciones de categorías —una, dos o tres—, la nota). Cada hito acepta solo los suyos más `nota`.'],
                ['nombre' => 'email', 'obligatorio' => false, 'validacion' => 'nullable|email:rfc|max:150', 'que_es' => 'La casilla a la que sale. Pisa a la de la ficha y, si el mail sale, se guarda en clients.email (varchar 150: por eso el tope).'],
                ['nombre' => 'reenviar', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false', 'que_es' => 'true = volver a mandar un hito que ya salió (suma reenvios).'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false lo manda de verdad (200). Sin él, devuelve el mail armado y no escribe nada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'required_if:dry_run,false|nullable|string|max:190', 'que_es' => 'El nombre exacto del cliente (clients.name). Obligatorio con dry_run=false.'],
            ],
        ],
        /* El WhatsApp de cada hito por el ticket de soporte (misión hitos-de-implementacion-por-soporte,
           26/10/2026). Sus parámetros son la lista blanca PARAMETROS_DEL_WHATSAPP del controlador. */
        'POST api/claude/implementations/{id}/whatsapp' => [
            'para_que'     => 'Manda (o simula) el WhatsApp de un HITO de la implementación por el TICKET DE SOPORTE del contacto, por el número de Kapso del admin, en vez de por WhatsApp Web: así la conversación entera (lo que se le dijo y lo que contesta) queda en el ticket donde la lee Martín. Envía con SupportWhatsappOpenerService::open(), el mismo del botón «Nueva conversación por WhatsApp» de la bandeja: reusa el ticket ABIERTO de ese contacto o abre uno, manda texto libre si la ventana de 24 h está abierta (partido si el texto lleva el separador renglón en blanco/---/renglón en blanco) y la plantilla cc_soporte_apertura con el texto adentro si está cerrada (el cliente recibe «Hola <contacto>, te escribe <admin> del equipo de soporte de ComercioCity.» + el texto + «Respondé este mensaje y seguimos por acá.»). El remitente es el admin con el que firma claude/* (CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID, el asignado por defecto o el primero). Si el cliente lo recibió, suma la acción a data.actions[] de la etapa con el mismo formato que POST .../actions más canal "whatsapp", support_ticket_id, support_message_id y operation_id (así el checklist del panel se tilda igual). NO crea implementation_messages ni hace falta registrarlo aparte con /actions. El dry_run (default) devuelve el ticket que se usaría (ticket_id, reusaria_ticket), la ventana (ventana_abierta, ventana_vence_at), uso_plantilla, partes, vista_previa (lo que recibiría el cliente), destino {telefono enmascarado, contacto, rol}, respuesta_ira_a, martin_atendiendo, martin_pendiente, impedimentos, avisos y listo. El real devuelve {dry_run:false, repetida, operation_id, accion, etapa, enviado, estado (sent|failed|partial|unknown), uso_plantilla, ventana_abierta, ticket_id, message_id (support_messages.id), whatsapp_message_id, reusado, error, respuesta_ira_a, avisos, at}.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'dry_run por defecto true: sin dry_run=false explícito no manda ni escribe nada y devuelve lo que recibiría el cliente para que Lucas lo apruebe.',
                'Lista blanca de parámetros: cualquier otra clave es 422 (no hay plantilla, ticket_id ni forzar: el ticket y texto-o-plantilla los decide el opener de soporte).',
                'confirm_client_name exacto (clients.name) y operation_id, obligatorios con dry_run=false. No revela el nombre correcto cuando falla.',
                '🔴 Destino: `telefono` tiene que ser un contacto del cliente (ClientPhoneDirectory::resolve_for_client: el dueño, un empleado o el lead promovido; el mismo criterio que el webhook) → si no, 422 motivo telefono_ajeno. Sin `telefono`, el del dueño (clients.phone) → 422 motivo sin_contacto si no tiene uno válido. Cliente dado de baja: 422 cliente_inactivo (el webhook no lo reconoce y su respuesta caería en leads). Etapa sin fila: 422 sin_etapa.',
                '🔴 Ventana cerrada y texto de más de 600 caracteres (aplanado como lo deja la plantilla): 422 motivo texto_largo_para_plantilla, con largo y tope, sin mandar nada (la plantilla lo cortaría con «…»). El dry_run lo avisa en impedimentos y listo=false.',
                '🔴 Si el ticket que se reusaría tiene un caso de Martín (support_codex_cases) con reserva vigente: 423 motivo martin_atendiendo, con reserva_hasta, sin mandar nada. El caso NO pasa a manual_review: este envío no es «Lucas tomó la atención», y cuando el cliente conteste Martín lo atiende como siempre.',
                '🔴 Si el ticket tiene algo esperando a Martín (inbound, follow_up o mission_completed): 423 motivo martin_pendiente, con reason, sin mandar nada: el mensaje del equipo taparía el entrante y Martín no lo contestaría nunca. Con el caso en manual_review sale igual, con avisos ["martin_en_pausa"] (la respuesta del cliente la atiende Lucas).',
                '🔴 respuesta_ira_a (ticket | hilo_de_la_implementacion | hilo_de_la_tienda | asistente) es adónde caería lo que conteste el cliente, con la misma decisión que el webhook: ticket con el soporte de Codex prendido y la implementación del sistema en curso en modo manual (o la tienda de la etapa 2 en adelante), o sin implementación en curso. Si no es ticket: 422 motivo respuesta_al_hilo sin mandar nada (la conversación quedaría partida).',
                '🔴 Idempotente por operation_id (8 a 80 caracteres, letras, números, guión y guión bajo), guardada en implementation_stages.data.whatsapp_ops de la etapa del hito: la misma clave con el mismo contenido (texto, accion, etapa y destinatario normalizado) devuelve 200 con el resultado guardado y repetida=true, sin reenviar ni volver a mirar ventana o reserva; con otro contenido, 409 motivo operation_id_reusado; si la primera sigue mandando (o su proceso se cortó en el medio), 409 motivo envio_en_curso. La búsqueda y la reserva van en una transacción con la implementación bloqueada: dos llamadas simultáneas no mandan dos veces. Un operation_id nuevo por mensaje.',
                'Un envío que falla (Kapso o Meta) NO tira: 200 con enviado=false, estado=failed y el error; no registra la acción. 🔴 Un failed no se reintenta solo (la misma clave devuelve el failed guardado): reintentar es una decisión, con una operation_id nueva. estado=partial (ventana abierta, texto partido, salieron las primeras partes) SÍ registra la acción: el cliente ya recibió algo; las partes que faltan quedan en el ticket para reintentarlas desde la bandeja. estado=unknown: el envío tiró una excepción y no se sabe si salió; mirá el ticket antes de mandar nada.',
                'Corre síncrono adentro del request (como el botón de la bandeja): con texto partido son hasta unos segundos por parte.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => 'texto', 'obligatorio' => true, 'validacion' => 'required|string|max:4000 (no vacío)', 'que_es' => 'El mensaje al cliente, tal como lo aprobó Lucas. Con la ventana cerrada va adentro de la plantilla y no puede pasar de 600 caracteres.'],
                ['nombre' => 'accion', 'obligatorio' => true, 'validacion' => 'required|string|in:presentacion,form_link,progreso,pedir_archivos,entrega,acceso,imagenes,categorias,descripciones,listo,nota', 'que_es' => 'Qué hito es (las mismas claves que POST .../actions). Es la clave que queda en data.actions[] y tilda el checklist del panel. nota es un mensaje real al cliente sin hito propio en el checklist (ARCA en la etapa 7, centro de recursos en la 6, artículos nuevos cargados): se registra igual que las demás, y el texto queda en el ticket, no en la entrada.'],
                ['nombre' => 'etapa', 'obligatorio' => false, 'validacion' => 'nullable|integer|between:1,8', 'que_es' => 'La etapa en cuyo registro se anota la acción y se guarda la operación. Por defecto la actual.'],
                ['nombre' => 'telefono', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30 — tiene que ser un contacto del cliente', 'que_es' => 'A quién (en cualquier formato; se resuelve contra los contactos del cliente). Sin él, el dueño (clients.phone).'],
                ['nombre' => 'operation_id', 'obligatorio' => false, 'validacion' => 'required_if:dry_run,false|nullable|string|min:8|max:80|regex:/^[A-Za-z0-9_-]+$/', 'que_es' => 'Clave idempotente del envío, una nueva por mensaje. Obligatoria con dry_run=false. En el dry_run, si se pasa, operacion_previa dice si ya existe y si es el mismo contenido.'],
                ['nombre' => 'dry_run', 'obligatorio' => false, 'validacion' => 'nullable|boolean — 🔴 DEFAULT true', 'que_es' => 'Con false lo manda de verdad (200). Sin él, devuelve lo que haría y no escribe nada.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => false, 'validacion' => 'required_if:dry_run,false|nullable|string|max:190', 'que_es' => 'El nombre exacto del cliente (clients.name). Obligatorio con dry_run=false.'],
            ],
        ],
        /* Implementaciones de Agentes de IA (misión implementacion-agentes-ia, 28/10/2026). Van después de
           'POST api/claude/implementations/{id}/whatsapp'. */
        'GET api/claude/agent-implementations' => [
            'para_que'     => 'Las implementaciones de Agentes de IA (conectar el número de WhatsApp del cliente a sus agentes, ocho etapas), más nuevas primero, filtradas por client_id y/o status. Hasta 100, sin paginar. Cada una con el estado completo: columnas (id, client_id, status, camino, current_stage, kapso_customer_id, phone_number_id, waba_id, business_portfolio_id, started_at, completed_at, created_at, updated_at) + etapas [{numero, nombre, estado, data, started_at, completed_at}] (data sin whatsapp_ops, {} si vacía) + eventos (los últimos 50, más nuevos primero: id, etapa, kind, source, texto, created_at) + cliente {id, name}. Respuesta {data: [impl]}.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'Solo las de ese cliente.'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'nullable|string|in:in_progress,completed,cancelled', 'que_es' => 'Solo las de ese estado.'],
            ],
        ],
        'GET api/claude/agent-implementations/{id}' => [
            'para_que'     => 'El estado completo de UNA implementación de Agentes de IA ({model: impl}, misma forma que el listado). 404 si no existe.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
            ],
        ],
        'POST api/claude/agent-implementations' => [
            'para_que'     => 'Da de alta la implementación de Agentes de IA de un cliente con sus ocho etapas (Preparación, Entrenamiento, Conexión con Meta, Conexión al sistema, Plantillas, Agente y pruebas, Pago y verificación, En marcha): la 1 en curso con el camino en su data y el resto pending. Deja un evento de avance. Solo escribe filas del admin. 201 {model}.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Lista blanca de parámetros: cualquier otra clave es 422 y no se crea nada.',
                'Una sola abierta por cliente: si ya tiene una in_progress, 409 {error: ya_tiene_una_abierta, id} y no se crea nada (para empezar de nuevo, se cancela con PATCH). El chequeo va con la fila del cliente bloqueada: dos altas simultáneas no crean dos.',
                '404 si el cliente no existe.',
            ],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'El cliente.'],
                ['nombre' => 'camino', 'obligatorio' => true, 'validacion' => 'required|string|in:nuevo,propio', 'que_es' => 'nuevo = línea nueva sin historial; propio = el número que el negocio ya usa.'],
            ],
        ],
        'PATCH api/claude/agent-implementations/{id}' => [
            'para_que'     => 'Cambia el camino, carga los ids de Kapso y Meta en sus columnas y/o cierra la implementación (completed o cancelled, con completed_at). Solo toca lo que vino; un null explícito borra el id. Deja eventos dato (nombra qué se cargó, nunca los valores) y avance (al cerrar). 200 {model}.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Lista blanca de parámetros: cualquier otra clave es 422.',
                'status solo acepta completed o cancelled: abrir es el alta.',
                'Una implementación ya cerrada (completed o cancelled) no cambia de status: 409 implementacion_cerrada sin escribir nada (mandar el mismo status que ya tiene no hace nada). Los demás campos se pueden corregir.',
                'La bitácora no guarda los ids de Meta ni el teléfono de contacto: viaja al contexto de Martín. contact_phone sale enmascarado en el estado.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => 'camino', 'obligatorio' => false, 'validacion' => 'nullable|string|in:nuevo,propio', 'que_es' => 'El camino.'],
                ['nombre' => 'kapso_customer_id', 'obligatorio' => false, 'validacion' => 'nullable|string|max:80', 'que_es' => 'El cliente de Kapso.'],
                ['nombre' => 'phone_number_id', 'obligatorio' => false, 'validacion' => 'nullable|string|max:60', 'que_es' => 'El id del número en Meta (que exista = conectado).'],
                ['nombre' => 'waba_id', 'obligatorio' => false, 'validacion' => 'nullable|string|max:60', 'que_es' => 'La cuenta de WhatsApp Business.'],
                ['nombre' => 'business_portfolio_id', 'obligatorio' => false, 'validacion' => 'nullable|string|max:60', 'que_es' => 'El portfolio comercial de Meta.'],
                ['nombre' => 'contact_phone', 'obligatorio' => false, 'validacion' => 'nullable|string|max:30', 'que_es' => 'Teléfono al que van los WhatsApp de la implementación en vez del de la ficha (camino propio: el número del negocio deja de tener la app). Tiene que ser un contacto del cliente (empleado, ficha o lead promovido).'],
                ['nombre' => 'status', 'obligatorio' => false, 'validacion' => 'nullable|string|in:completed,cancelled', 'que_es' => 'Cierra la implementación.'],
            ],
        ],
        'POST api/claude/agent-implementations/{id}/stages/{n}' => [
            'para_que'     => 'Registra una etapa (1 a 8): su estado, lo que se suma a su data (se MEZCLA: las claves nuevas pisan las del mismo nombre, el resto queda) y una nota opcional (evento nota). Si el estado cambió deja un evento avance. Recalcula current_stage = la primera etapa que no está completed ni skipped (8 si todas). in_progress pone started_at; completed/skipped ponen completed_at; pending limpia las dos. 200 {model}.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Lista blanca de parámetros: cualquier otra clave es 422.',
                'Etapa fuera de 1–8: 422 motivo etapa_fuera_de_rango, sin escribir nada.',
                'Implementación cerrada (completed o cancelled): 409 implementacion_cerrada, sin escribir nada.',
                'data tiene que ser un objeto (una lista es 422 data_no_es_objeto) y no puede traer whatsapp_ops (422 clave_reservada: es la memoria de la idempotencia de los envíos).',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => '{n} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; 1 a 8', 'que_es' => 'La etapa.'],
                ['nombre' => 'status', 'obligatorio' => true, 'validacion' => 'required|string|in:pending,in_progress,completed,skipped', 'que_es' => 'El estado de la etapa.'],
                ['nombre' => 'data', 'obligatorio' => false, 'validacion' => 'nullable|array (objeto JSON)', 'que_es' => 'Lo que se registra en la etapa (claves sugeridas en AgentImplementation::STAGES).'],
                ['nombre' => 'nota', 'obligatorio' => false, 'validacion' => 'nullable|string|max:1000', 'que_es' => 'Una nota que queda como evento de la etapa.'],
            ],
        ],
        'POST api/claude/agent-implementations/{id}/events' => [
            'para_que'     => 'Agrega un evento a la bitácora de la implementación de Agentes de IA (la que lee Martín en el contexto del ticket). 201 {event: {id, etapa, kind, source, texto, created_at}}.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Lista blanca de parámetros: cualquier otra clave es 422.',
                'texto obligatorio, no vacío y de hasta 1000 caracteres. No escribas teléfonos ni ids de Meta: viaja al contexto de Martín (igual se enmascaran los números largos).',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => 'texto', 'obligatorio' => true, 'validacion' => 'required|string|max:1000', 'que_es' => 'El texto del evento.'],
                ['nombre' => 'etapa', 'obligatorio' => false, 'validacion' => 'nullable|integer|between:1,8', 'que_es' => 'La etapa a la que se refiere (sin ella, general).'],
                ['nombre' => 'kind', 'obligatorio' => false, 'validacion' => 'nullable|string|in:nota,avance,whatsapp,dato — default nota', 'que_es' => 'El tipo de evento.'],
                ['nombre' => 'source', 'obligatorio' => false, 'validacion' => 'nullable|string|in:martin,claude,panel — default claude', 'que_es' => 'Quién lo deja asentado.'],
            ],
        ],
        'POST api/claude/agent-implementations/{id}/whatsapp' => [
            'para_que'     => 'Manda un WhatsApp al DUEÑO del cliente por su TICKET DE SOPORTE (número de Kapso del admin, SupportWhatsappOpenerService::open(): reusa el ticket abierto o abre uno; texto libre con la ventana abierta, plantilla cc_soporte_apertura si está cerrada) y lo deja en la bitácora como evento whatsapp si el cliente lo recibió (sin el cuerpo: «Mensaje enviado por el ticket #N (etapa X, NNN caracteres)»; el texto queda en el ticket). La operación queda en agent_implementation_stages.data.whatsapp_ops de la etapa (o de la actual). Respuesta {dry_run:false, repetida, operation_id, etapa, enviado, estado (sent|failed|partial|unknown), uso_plantilla, ventana_abierta, ticket_id, message_id, whatsapp_message_id, reusado, error, respuesta_ira_a, avisos, at}.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'Lista blanca de parámetros: cualquier otra clave es 422 (no hay dry_run ni telefono). Destino: el contact_phone de la implementación si está cargado (si no es contacto del cliente, 422 telefono_ajeno); si no, el dueño (clients.phone).',
                '🔴 Circuito de Codex apagado: 422 motivo codex_apagado (nadie atendería la respuesta del ticket): lo manda Lucas desde la bandeja de soporte del admin.',
                'Cliente inexistente 404; dado de baja 422 cliente_inactivo; sin teléfono válido del dueño 422 sin_contacto; sin admin remitente 422 sin_admin.',
                '🔴 Mismos frenos que POST claude/implementations/{id}/whatsapp, con la misma cuenta (ImplementationSupportWhatsappService::plan()): ventana cerrada y texto de más de 600 → 422 texto_largo_para_plantilla; la respuesta no caería en el ticket → 422 respuesta_al_hilo; Martín con reserva vigente → 423 martin_atendiendo; un entrante esperando a Martín → 423 martin_pendiente. En ninguno se manda nada.',
                '🔴 Idempotente por operation_id (8 a 80, letras, números, guión y guión bajo): misma clave y mismo contenido (texto, etapa y destinatario) → 200 con lo guardado y repetida=true sin reenviar; otro contenido → 409 operation_id_reusado; todavía mandando → 409 envio_en_curso. Búsqueda y reserva con la implementación bloqueada.',
                'Un envío que falla NO tira: 200 con enviado=false y no deja evento; la misma clave devuelve el failed guardado (reintentar es con una operation_id nueva).',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; id numérico', 'que_es' => 'La implementación.'],
                ['nombre' => 'operation_id', 'obligatorio' => true, 'validacion' => 'required|string|min:8|max:80|regex:/^[A-Za-z0-9_-]+$/', 'que_es' => 'Clave idempotente del envío, una nueva por mensaje.'],
                ['nombre' => 'texto', 'obligatorio' => true, 'validacion' => 'required|string|max:4000 (no vacío)', 'que_es' => 'El mensaje al dueño, tal como lo aprobó Lucas. Con la ventana cerrada no puede pasar de 600 caracteres.'],
                ['nombre' => 'etapa', 'obligatorio' => false, 'validacion' => 'nullable|integer|between:1,8', 'que_es' => 'La etapa donde se guarda la operación y el evento. Por defecto la actual.'],
            ],
        ],
        /* Centro de recursos para clientes (misión centro-de-recursos-clientes, 13/10/2026). Va al final de
           `endpoints`, después de 'POST api/claude/implementations/{id}/mail'. */
        'POST api/claude/recursos/catalogo' => [
            'para_que'     => 'Carga el catálogo del centro de recursos (recursos.comerciocity.com): los videos aprobados que publicar_centro.py ya subió a R2, con sus textos (para qué, cuándo no, transcripción) y relacionados. Upsert por `codigo`: crea los nuevos, actualiza los que cambiaron y no toca los que vinieron iguales. Con desactivar_faltantes=true los activos que NO vinieron quedan activo=false. Borra la caché del catálogo público y del índice del buscador. Devuelve {creados, actualizados, sin_cambios, desactivados}.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                '🔴 Ningún video se borra nunca: el que deja de venir queda inactivo (tiene avance, votos, preguntas y encuestas de usuarios colgando). Si vuelve a venir, se reactiva.',
                'Se valida TODO el payload antes de escribir (422 con `errores` por campo, motivo datos_invalidos): un video roto no deja el catálogo cargado a medias. `videos` tiene que traer al menos uno (una lista vacía con desactivar_faltantes apagaría el centro entero).',
                'Lista blanca de parámetros: cualquier clave que no sea carpetas, videos o desactivar_faltantes es 422. Dentro de cada video las claves desconocidas (como `_fuente`) se ignoran.',
                'desactivar_faltantes es false por defecto: sin pedirlo explícito no se desactiva nada.',
                'Solo escribe filas del admin (recursos_videos). No toca el sistema de ningún cliente.',
            ],
            'parametros'   => [
                ['nombre' => 'videos', 'obligatorio' => true, 'validacion' => 'required|array|min:1|max:2000; cada uno: codigo (string ≤ 20, único en el payload), titulo (string ≤ 255), carpeta (int ≥ 0: la carpeta 0 es «Empezá por acá»), carpeta_nombre (string ≤ 255; si falta, sale de `carpetas`), orden (int ≥ 0), duracion (segundos > 0), url (http/https ≤ 500), y opcionales seccion (≤ 255), para_que, cuando_no, transcripcion (texto), relacionados (lista de códigos), miniatura_url (http/https ≤ 500), version (≤ 16)', 'que_es' => 'Los videos del catálogo, en el formato que arma publicar_centro.py.'],
                ['nombre' => 'carpetas', 'obligatorio' => false, 'validacion' => 'nullable|array; cada una {nro: int ≥ 0, nombre: string}', 'que_es' => 'Nombres de las carpetas, para los videos que no traen carpeta_nombre.'],
                ['nombre' => 'desactivar_faltantes', 'obligatorio' => false, 'validacion' => 'nullable|boolean — default false', 'que_es' => 'true = los videos activos que no vienen en este payload quedan inactivos.'],
            ],
        ],
        'GET api/claude/recursos/resumen' => [
            'para_que'     => 'Totales del centro de recursos sin entrar al panel: videos activos e inactivos, usuarios (total, dueños, clientes, activos en 7 días), videos empezados y terminados, votos positivos y negativos, encuestas, y preguntas pendientes, respondidas y ocultas.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
    ],

    /*
     | Lo que este bloque NO puede hacer, o hace de una manera que conviene saber antes. Son los
     | ⚠️ que hoy viven sueltos en los docblocks: acá se publican para que el que consulta la API no
     | tenga que leer el código para enterarse.
     */
    'limitaciones_conocidas' => [
        '🔴 El panel del admin despacha RunEcommerceInstallationJob INLINE, sin onConnection("database") (Api\\EcommerceInstallationController, líneas 95, 154 y 212). Con QUEUE_CONNECTION=sync eso corre el pipeline SSH entero adentro del request del panel. Los claude/* encolan explícito. Mientras haya una corrida de Claude en curso, no toques el botón del panel: las dos compiten por el mismo lock de build.',
        '🔴 Una corrida de ecommerce colgada en status="instalando" NO la destraba nadie: no existe el equivalente de deployments:vencer-colgados para client_ecommerce_installations. La salud de la corrida la REPORTA, pero destrabarla es a mano. Y mientras esté colgada, esa tienda no acepta otra corrida.',
        /* Asimetría deliberada, no un olvido: ver ClaudeEcommerceOpsController::ESTADOS_QUE_OCUPAN_LA_TIENDA.
           El panel no se endurece porque cambiaría un botón que se usa a mano. */
        '🔴 "Ya hay una corrida en curso para esta tienda" es MÁS DURO en claude/* que en el panel: acá cuentan también las corridas en status="pendiente" (encoladas y todavía sin worker), y en el panel sólo las "instalando". Es lo que evita que un reintento de POST claude/ecommerce/updates dentro del minuto —cuando el HTTP dio timeout— cree una segunda corrida sobre la misma tienda. Consecuencia: el panel puede arrancar una corrida que claude/* estaba frenando.',
        'El gate de horario usa config("app.timezone"), que es global. Un cliente en otra franja horaria se evalúa con la hora del servidor, no con la suya.',
        'deployment_stale (15 minutos) es un umbral de AVISO y el del vencimiento (45) es el DESTRUCTIVO. Que un deployment aparezca stale no significa que se pueda vencer: son dos números distintos a propósito.',
        /* 🔴 Esta es la limitación más cara del bloque entero y hasta hoy no estaba acá: vivía sólo
           en el `para_que` de mark-crons, que es donde menos se la busca. Un lote de veinte
           actualizaciones NO es "actualizar veinte clientes". */
        '🔴 NINGUNA ROTACIÓN DE EMPRESA SE COMPLETA SIN INTERVENCIÓN HUMANA. Completar una son CINCO pasos por cliente: deploy/start → MOVER LOS CRONS Y EL SUPERVISOR A MANO EN EL PANEL DE HOSTINGER → mark-crons → deploy/start-post-closure → deploy/configure-system. El paso 2 NO tiene endpoint y no lo va a tener: mark-crons sólo REGISTRA que una persona ya lo hizo. Además el paso 4 sólo corre con ESE negocio cerrado, así que cada cliente tiene su propia ventana horaria. Para veinte clientes: ~80 llamadas HTTP, 20 intervenciones manuales y 20 momentos distintos. Desde el 14/10/2026 (un solo frente por cliente) la actualización EN EL LUGAR no tiene los pasos 2 y 3 —no hay frente que mudar y el pipeline marca los crons solo—, pero su deploy/start lleva el mismo gate de horario que el paso 4.',
        'Ninguna ruta claude/* arranca un deployment en lote. El gate de horario (del post-cierre, y del deploy/start en el lugar) y allow_deploy_to_active_api son por cliente, así que después de POST claude/upgrades/batch hay que llamar deploy/start uno por uno. Y crear el lote es sólo el primero de los cinco pasos de arriba: POST claude/upgrades/batch deja actualizaciones creadas, no clientes actualizados.',
        'La instalación INICIAL de una tienda tiene UNA sola ruta, POST claude/ecommerce/installs (decisión de Lucas, 2/10/2026), con dry_run por defecto y sólo sobre una tienda registrada y en pending. La instalación del ERP de un cliente nuevo es POST claude/implementations/{id}/install (misión implementar-cliente, 5/10/2026), con dry_run por defecto y solo con el token de Hostinger del admin: es otra tabla (client_installations) y otro job que el de la tienda.',
        '🔴 El user setup de una implementación NO se puede re-aplicar por claude/*: del otro lado arranca con migrate:fresh --force y le vacía la base al cliente. POST claude/implementations/{id}/user-setup no tiene forzar —un force es 422— y con user_setup_executed_at lleno es 422 sin vuelta; si de verdad hace falta, se hace desde el panel, con una persona mirando.',
        '🔴 Adónde va lo que el cliente le escribe al número de WhatsApp del sistema mientras una implementación está abierta (status in_progress), desde el 26/10/2026: con el soporte de Codex PRENDIDO (PUT claude/support-codex/settings), la implementación del sistema en modo manual y la de la tienda de la etapa 2 en adelante van a un TICKET DE SOPORTE que atiende Martín con el contexto de la implementación (si están las dos en curso decide la del sistema). Con el circuito APAGADO, o con el sistema en modo auto o la tienda en la etapa 1, cae al hilo de la implementación como antes: no llega a soporte ni al asistente, en modo manual ese hilo no contesta, y GET claude/implementations/{id} lo mide en `entrantes.sin_responder`. Los hitos de la implementación salen por POST claude/implementations/{id}/whatsapp, por el ticket de soporte del contacto (no por WhatsApp Web). Avanzar desde la última etapa (la 7; la 8 en una implementación anterior al 15/10/2026) con POST .../advance cierra la implementación.',
        'GET claude/query es sólo lectura y su lista blanca son los modelos que se verificaron columna por columna. Los que faltan no están prohibidos: están sin verificar. El motivo de cada exclusión se publica en la sección `query` de este catálogo.',
        'El limitador de tasa agrupa por IP cuando no hay usuario de Sanctum, y claude/* nunca lo tiene. Conviene hacer pocas llamadas grandes y no muchas chicas.',
        /* No es una ruta claude/* (no entra en `endpoints`, que se coteja contra el router), pero sí lo que hay que saber
           para diagnosticar a un dueño que no puede entrar a la app (misión cruzada app-ia-y-memoria, 29/10/2026). */
        'La app del agente (ia.comerciocity.com) entra por POST api/ia/login, PÚBLICO y fuera de claude/*: {usuario, password, negocio?, dispositivo?} → 200 {token, api_url, negocio, usuario, capacidades}. Códigos (con {code, message}): 422 negocio_requerido (el usuario no está en el directorio: que escriba su sistema) o validacion · 404 negocio_no_encontrado · 401 credenciales_invalidas (solo si TODOS los sistemas probados lo dijeron) · 403 sin_extension / no_es_dueno · 502 sistema_no_disponible (algún sistema no respondió: red, 5xx, 3xx, timeout o presupuesto de 45 s) · 503 sistema_no_configurado (la instalación del cliente está mal configurada: su empresa-api contestó 401 clave_invalida —clients.api_key no es su ADMIN_API_INBOUND_KEY, se arregla con la exigencia de admin-sync— o 409 dueno_no_resoluble —base compartida sin USER_ID en el .env—) · 409 version_vieja (algún sistema contestó 404) · 429 demasiados_intentos (10/min por IP, 5/min por usuario; header Retry-After, expuesto por CORS). La contraseña no se guarda ni se loguea: para diagnosticar se mira el log «IaLoginService: intento contra el sistema de un cliente» (client_id, status, code).',
    ],
];
