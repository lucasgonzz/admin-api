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
                'El mensaje enviado queda registrado en la conversación del lead: no hay envío sin rastro.',
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
        'POST api/claude/clients/{id}/schedule/sync' => [
            'para_que'     => 'Reintenta el push de los horarios del cliente a su empresa-api.',
            'escribe'      => true,
            'peligrosidad' => 'baja',
            'frenos'       => [
                'Idempotente: reenvía los horarios que el admin ya tiene cargados, no los modifica.',
                'Nunca hace el HTTP adentro del request: encola SyncClientScheduleJob.',
                'Todos los desenlaces terminan escritos en clients.schedule_sync_status: no hay camino silencioso.',
            ],
        ],
        'GET api/claude/versions' => [
            'para_que'     => 'Catálogo de versiones con la cantidad de ítems por versión ya contada. Sin paginación: son pocas filas.',
            'escribe'      => false,
            'peligrosidad' => 'lectura',
            'frenos'       => [],
        ],
        'GET api/claude/upgrades' => [
            'para_que'     => 'Listado de actualizaciones filtrable y paginado por cursor.',
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
                'No arranca el deployment: eso es una llamada aparte, con sus propios frenos.',
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
                '🔴 NO arranca ningún deployment. El gate de horario y allow_deploy_to_active_api son por cliente: veinte clientes son veinte jornadas distintas y no hay lote posible ahí.',
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/start' => [
            'para_que'     => 'Arranca el pipeline PRE-CIERRE: compila la SPA, la sube, sube la API, corre las migraciones y frena esperando los crons. Se puede correr con el negocio abierto porque no toca el sistema en uso.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'No arranca si ya hay un deployment activo (running, paused o paused_post_tasks).',
                'allow_deploy_to_active_api: si la API destino es la que está en producción, hay que pedirlo explícito.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request.',
            ],
        ],
        'POST api/claude/upgrades/{id}/mark-crons' => [
            'para_que'     => 'Marca (o desmarca) crons_supervisor_at, que es la confirmación humana de que los crons y el supervisor del cliente quedaron configurados. Es el paso que habilita el post-cierre.',
            'escribe'      => true,
            'peligrosidad' => 'media',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'Su único efecto es un timestamp en el upgrade: no arranca ni detiene ningún pipeline.',
            ],
        ],
        'POST api/claude/upgrades/{id}/deploy/start-post-closure' => [
            'para_que'     => 'Arranca las tareas POST-CIERRE (seeders y comandos) sobre el sistema EN USO del cliente. Sólo con la jornada terminada.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'El deployment tiene que estar en paused: no arranca sobre cualquier estado.',
                'crons_supervisor_at tiene que estar marcado.',
                'Gate de horario: si el negocio está abierto, o si no tiene horarios cargados, no arranca. Se saltea sólo con force y un force_reason de al menos 10 caracteres, que queda en el log diario.',
                'Encola con onConnection("database"): nunca corre el pipeline SSH adentro del request.',
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
        ],
        'POST api/claude/upgrades/{id}/deploy/configure-system' => [
            'para_que'     => 'Arranca la etapa final: actualiza la versión por defecto del cliente y completa el upgrade.',
            'escribe'      => true,
            'peligrosidad' => 'alta',
            'frenos'       => [
                'confirm_client_name exacto, sin revelar el nombre correcto cuando falla.',
                'Encola con onConnection("database"): nunca corre el pipeline adentro del request.',
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
                'Los N despachos van juntos al final, después de crear las N filas: si el presupuesto corta a la mitad, no queda ninguna corrida sin job ni ningún job sin corrida.',
                'Nunca crea una instalación inicial: siempre mode="update".',
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
        'El gate de horario usa config("app.timezone"), que es global. Un cliente en otra franja horaria se evalúa con la hora del servidor, no con la suya.',
        'deployment_stale (15 minutos) es un umbral de AVISO y el del vencimiento (45) es el DESTRUCTIVO. Que un deployment aparezca stale no significa que se pueda vencer: son dos números distintos a propósito.',
        'Ninguna ruta claude/* arranca un deployment en lote. El gate de horario y allow_deploy_to_active_api son por cliente, así que después de POST claude/upgrades/batch hay que llamar deploy/start uno por uno.',
        'Ninguna ruta claude/* hace la instalación INICIAL de una tienda ni la instalación del ERP de un cliente nuevo: sólo actualizaciones.',
        'GET claude/query es sólo lectura y su lista blanca son los modelos que se verificaron columna por columna. Los que faltan no están prohibidos: están sin verificar. El motivo de cada exclusión se publica en la sección `query` de este catálogo.',
        'El limitador de tasa agrupa por IP cuando no hay usuario de Sanctum, y claude/* nunca lo tiene. Conviene hacer pocas llamadas grandes y no muchas chicas.',
    ],
];
