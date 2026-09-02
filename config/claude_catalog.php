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
        'GET api/claude/templates' => [
            'para_que'     => 'Catálogo de plantillas Meta aprobadas, para saber qué se puede mandar y con qué variables.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],

        /* ---------------------------------------------------------- Leads: envío */

        'POST api/claude/leads/{id}/send-template' => [
            'para_que'     => 'Manda una plantilla Meta a UN lead real por WhatsApp y registra el mensaje en su conversación.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'El lead se nombra por id: no hay forma de mandarle a "los que cumplan un filtro".',
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
                'Un solo mensaje por turno de conversación: si ya se le respondió después de su último mensaje, es 422. Para saltearlo hay que repetir la llamada con permitir_varios_por_turno, que queda escrito en el pedido. Reemplaza al cooldown de 24 hs de send-template, que acá dejaría muda la conversación en curso que este endpoint existe para sostener.',
                'El lead se nombra por id: no hay forma de mandarle a "los que cumplan un filtro".',
                'El lead tiene que tener teléfono cargado: sin él es 422 y no se crea absolutamente nada.',
                'El texto no puede venir vacío.',
                '🔴 Un lead en cerrado_ganado o ya promovido a cliente no recibe nada, y acá NO hay include_closed que lo habilite: a un cliente se le responde por el hilo de soporte.',
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
        'GET api/claude/versions' => [
            'para_que'     => 'Catálogo de versiones con la cantidad de ítems por versión ya contada. Sin paginación: son pocas filas.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
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
            'para_que'     => 'Estado detallado de un upgrade: el endpoint de poleo. Trae la salud calculada (deployment_stale, jobs en cola) y la siguiente acción sugerida.',
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
            'para_que'     => 'Candidatas de versión entre la versión actual de un cliente y la versión destino, con las que el panel marcaría por defecto.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'POST api/claude/upgrades' => [
            'para_que'     => 'Crea UN ClientVersionUpgrade con las versiones confirmadas y sus UpdateSeeder y UpdateCommand. No arranca ningún deployment.',
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
                ['nombre' => 'target_client_api_id', 'obligatorio' => false, 'validacion' => 'nullable|integer|min:1', 'que_es' => 'API destino del deployment. Tiene que ser del mismo cliente. Si no viene, la resuelve el servicio.'],
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
                '🔴 NO arranca ningún deployment. El gate de horario y allow_deploy_to_active_api son por cliente: veinte clientes son veinte jornadas distintas y no hay lote posible ahí.',
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
            'para_que'     => 'Arranca el pipeline PRE-CIERRE: compila la SPA, la sube, sube la API, corre las migraciones y frena esperando los crons. Se puede correr con el negocio abierto porque no toca el sistema en uso.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'No arranca si ya hay un deployment activo (running, paused o paused_post_tasks).',
                /* Estaba en el código (ClaudeUpgradeOpsController, el `empty($upgrade->target_client_api_id)`)
                   y no acá. Es un 422 que frena el arranque, o sea un freno. */
                'Exige target_client_api_id cargado en el upgrade: sin API destino no arranca y devuelve 422 sin encolar nada.',
                'allow_deploy_to_active_api: si la API destino es la que está en producción, hay que pedirlo explícito.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request.',
                '⚠️ BORRA los logs del intento anterior, igual que el botón del panel. Si querés el log de un intento fallido, leelo ANTES de reintentar.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade a arrancar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade. El rechazo NO revela cuál era el correcto.'],
                ['nombre' => 'allow_deploy_to_active_api', 'obligatorio' => false, 'validacion' => 'nullable|boolean', 'que_es' => '🔴 Sólo hace falta si la API destino ES la activa en producción (pasa cuando el cliente tiene una sola ClientApi). Sin esto, ese caso es 422.'],
            ],
        ],
        'POST api/claude/upgrades/{id}/mark-crons' => [
            'para_que'     => '🔴 SÓLO REGISTRA QUE UN HUMANO YA MOVIÓ LOS CRONS: escribe (o borra) crons_supervisor_at, que es el timestamp de esa confirmación. MARCARLO NO MUEVE NADA. Mover los crons y el supervisor del cliente es entrar al panel de Hostinger y hacerlo a mano, y es el paso por el que ninguna actualización de empresa se completa sin una persona. Marcar sin haberlos movido habilita el post-cierre igual y deja al cliente con los crons apuntando a la instancia vieja.',
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
        'POST api/claude/upgrades/{id}/deploy/start-post-closure' => [
            'para_que'     => 'Arranca las tareas POST-CIERRE (seeders y comandos) sobre el sistema EN USO del cliente. Sólo con la jornada terminada.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'El deployment tiene que estar en paused: no arranca sobre cualquier estado.',
                'crons_supervisor_at tiene que estar marcado. ⚠️ Que esté marcado NO significa que los crons se hayan movido: mark-crons sólo registra que alguien dice que sí.',
                'Gate de horario: si el negocio está abierto, o si no tiene horarios cargados, no arranca. Se saltea sólo con force y un force_reason de al menos 10 caracteres, que queda en el log diario.',
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
                '🔴 Gate de horario, que el panel NO tiene: run_commands corre sobre el sistema en uso del cliente, así que es la segunda mitad del post-cierre. Se saltea sólo con force y force_reason, y queda en el log diario. El panel lo aprieta un humano que sabe si el local está lleno; claude/* no tiene esa información salvo que la mire.',
                'No borra los logs, a diferencia de deploy/start. Se declara en la respuesta.',
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
                'Encola con onConnection("database"): nunca corre el pipeline adentro del request.',
            ],
            'parametros'   => [
                ['nombre' => '{id} (en la ruta)', 'obligatorio' => true, 'validacion' => 'segmento de la URL; acepta id numérico o uuid', 'que_es' => 'El upgrade a completar.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente del upgrade. ⚠️ Es el ÚNICO parámetro de cuerpo: acá no hay force ni gate de horario.'],
            ],
        ],

        /* ---------------------------------------------------------- Tiendas (ecommerce) */

        'GET api/claude/ecommerce/stores' => [
            'para_que'     => 'Tiendas configuradas de los clientes, con su última corrida y si se pueden actualizar ahora mismo (y el motivo cuando no). Es lo que hay que mirar antes de disparar una actualización.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/ecommerce/installations' => [
            'para_que'     => 'Corridas del pipeline de tienda (install o update), paginadas por cursor y filtrables por cliente, modo, estado y origen.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/ecommerce/installations/{id}' => [
            'para_que'     => 'Ficha de una corrida con la salud calculada (no persistida): minutos en curso, jobs en cola y si está colgada. Acepta id o uuid.',
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
            'para_que'     => 'Dispara la actualización de la tienda de UN cliente: clona y compila tienda-spa en el VPS de builds y sube SPA y API por SFTP. Siempre a lo último de master: no hay selección de versión.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'La tienda tiene que estar configurada: spa_url, api_url y dominio resoluble.',
                'Tienen que estar cargadas las credenciales SSH del VPS de builds y las del hosting compartido.',
                'No puede haber otra corrida en curso para esa tienda.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request. El panel lo despacha pelado y con QUEUE_CONNECTION=sync eso correría el pipeline entero adentro del request HTTP.',
                '🔴 Nunca crea una instalación inicial: siempre mode="update". Es una decisión de Lucas y tiene su test.',
            ],
            'parametros'   => [
                ['nombre' => 'client_id', 'obligatorio' => true, 'validacion' => 'required|integer|min:1', 'que_es' => 'El cliente cuya tienda se actualiza. GET claude/ecommerce/stores dice cuáles se pueden actualizar ahora y por qué no las otras.'],
                ['nombre' => 'confirm_client_name', 'obligatorio' => true, 'validacion' => 'required|string|max:190', 'que_es' => 'El nombre exacto del cliente. ⚠️ Acá NO hay dry_run: el freno del endpoint individual es el nombre, igual que en send-template de a uno.'],
            ],
        ],
        'POST api/claude/ecommerce/updates/batch' => [
            'para_que'     => 'Dispara la actualización de hasta cinco tiendas nombradas una por una.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'Sólo client_ids explícitos: el lote NO acepta filtros. Es la misma regla del lote de leads, y acá pesa más porque arranca pipelines SSH.',
                'dry_run por defecto: si no se pide lo contrario, simula y no crea ninguna corrida.',
                'confirm_client_count tiene que coincidir exactamente con la cantidad simulada.',
                'confirm_token con hash_equals sobre el id y el nombre normalizado de cada cliente.',
                '🔴 Tope MAX_LOTE_ECOMMERCE = 5, y el número es derivado y no elegido a ojo: queue:work corre cada minuto SIN withoutOverlapping(), varias corridas compiten por el lock del clone de tienda-spa, que espera hasta 1800 s y después tira RuntimeException. Con ~6 min de lock por corrida, la sexta supera los 30 minutos y muere sola.',
                'Cooldown de 6 horas por tienda para las corridas creadas por Claude.',
                'Todas las precondiciones del de a uno se evalúan por cliente y lo que no pasa queda como omitido, con el motivo.',
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
                ['nombre' => 'confirm_token', 'obligatorio' => false, 'validacion' => 'nullable|string|max:64 — obligatorio cuando dry_run=false', 'que_es' => 'El token de la simulación, comparado con hash_equals sobre el id y el nombre normalizado de cada cliente.'],
            ],
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
        '🔴 NINGUNA ACTUALIZACIÓN DE EMPRESA SE COMPLETA SIN INTERVENCIÓN HUMANA. Completar una son CINCO pasos por cliente: deploy/start → MOVER LOS CRONS Y EL SUPERVISOR A MANO EN EL PANEL DE HOSTINGER → mark-crons → deploy/start-post-closure → deploy/configure-system. El paso 2 NO tiene endpoint y no lo va a tener: mark-crons sólo REGISTRA que una persona ya lo hizo. Además el paso 4 sólo corre con ESE negocio cerrado, así que cada cliente tiene su propia ventana horaria. Para veinte clientes: ~80 llamadas HTTP, 20 intervenciones manuales y 20 momentos distintos.',
        'Ninguna ruta claude/* arranca un deployment en lote. El gate de horario y allow_deploy_to_active_api son por cliente, así que después de POST claude/upgrades/batch hay que llamar deploy/start uno por uno. Y crear el lote es sólo el primero de los cinco pasos de arriba: POST claude/upgrades/batch deja actualizaciones creadas, no clientes actualizados.',
        'Ninguna ruta claude/* hace la instalación INICIAL de una tienda ni la instalación del ERP de un cliente nuevo: sólo actualizaciones.',
        'GET claude/query es sólo lectura y su lista blanca son los modelos que se verificaron columna por columna. Los que faltan no están prohibidos: están sin verificar. El motivo de cada exclusión se publica en la sección `query` de este catálogo.',
        'El limitador de tasa agrupa por IP cuando no hay usuario de Sanctum, y claude/* nunca lo tiene. Conviene hacer pocas llamadas grandes y no muchas chicas.',
    ],
];
