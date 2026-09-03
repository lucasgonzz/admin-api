<?php

/*
|--------------------------------------------------------------------------
| Lista blanca de lectura genérica de GET claude/query
|--------------------------------------------------------------------------
| Cada entrada declara UNA tabla, las columnas que se exponen, los filtros
| aceptados y las relaciones incluibles. Agregar un modelo son ~10 líneas acá
| y NINGÚN código: no hay un controlador por modelo.
|
| 🔴 LA GARANTÍA ES ESTRUCTURAL, NO DE REVISIÓN: `columnas` es una lista blanca
| POSITIVA. ClaudeQueryService arma `DB::table($tabla)->select($columnas)` y no
| existe ningún camino que devuelva una columna que no esté escrita acá. No hay
| `select *`, no hay modelo Eloquent serializado, no hay `$appends`. Una columna
| sensible no se filtra porque no se puede nombrar sin escribirla en este archivo.
|
| 🔴 Y encima de eso hay una segunda reja: `columnas_prohibidas` de abajo. Si
| alguien escribe igual una columna que matchea ese patrón, el endpoint devuelve
| 422 y no sirve NADA (fail-closed), y el test
| ConsultaGenericaParaClaudeTest::test_ningun_modelo_del_config_declara_una_columna_prohibida()
| rompe en el build. Las dos rejas son a propósito: la primera es la que protege,
| la segunda es la que avisa que alguien la intentó saltar.
|
| ⚠️ ESTE ARCHIVO ES DE LECTURA Y SÓLO DE LECTURA. No existe ningún POST
| claude/query ni ninguna escritura por nombre de modelo. Toda escritura va por
| un endpoint específico con sus frenos: ClaudeUpgradeOpsController son más de
| 1300 líneas casi todas de frenos, y una escritura genérica los saltearía y
| arrancaría deploys SSH sobre negocios reales.
|
| ⚠️ TODA columna y TODA enumeración de este archivo se verificó el 28/8/2026
| contra `information_schema` de `admin_testing_s4` y contra las constantes del
| código, no contra una lectura de las migraciones. Si agregás un modelo, hacé lo
| mismo: una columna que no existe hace fallar la consulta entera en runtime, y
| una enumeración inventada devuelve 422 sobre valores que sí son válidos.
*/

return [

    /*
     | Patrón de nombres de columna que este endpoint NO sirve nunca, venga como
     | venga. Es la reja de atrás, no la de adelante.
     |
     | 🔴 POR QUÉ NO DICE `_key$`, que es lo primero que uno escribe: porque
     | `client_schedule_days.day_key` —el día de la semana de un horario, que no
     | tiene nada de secreto— matchea `_key$`. Una reja que grita sobre una columna
     | legítima termina con una lista de excepciones al lado, y una lista de
     | excepciones es exactamente lo que una reja automática viene a evitar. Por eso
     | el patrón nombra `api_key$` (cubre `clients.api_key`, `clients.inbound_api_key`,
     | `whatsapp_config.kapso_api_key` y `recall_configs.recall_api_key`) y `^key$`,
     | en vez de cualquier cosa terminada en `_key`.
     |
     | `^auth$` y `p256dh` son las dos claves de cifrado de Web Push, que no tienen
     | ninguna de las palabras obvias en el nombre: es el caso que un grep de
     | "password|token" se pierde. `encrypted` cubre
     | `env_change_items.new_value_encrypted` y
     | `admin_calendar_connections.google_refresh_token_encrypted`.
     |
     | 🔴 ESTA REJA ES SOBRE CREDENCIALES, NO SOBRE "COLUMNA SENSIBLE". NO CUBRE PII
     | Y NO PUEDE CUBRIRLA: los datos personales no tienen ninguna palabra en común
     | en el nombre de la columna. Nada de esto matchea el patrón, y todo esto es
     | PII: `leads.email`, `leads.phone`, `leads.doc_number`, `clients.afip_cuit`,
     | `admins.email`, `client_employees.phone`, `support_tickets.client_user_email`.
     | Un `nombre_del_titular` que alguien agregue mañana tampoco va a matchear.
     |
     | Contra PII la ÚNICA defensa de este endpoint es la lista blanca positiva de
     | `columnas` (y `columnas_opt_in` para lo que viaja sólo si se pide): una
     | columna de datos personales no sale porque nadie la escribió, no porque esta
     | reja la ataje. Si agregás un modelo, la pregunta "¿alguna de estas columnas
     | es un dato de una persona?" hay que hacerla A MANO, columna por columna: el
     | test `test_ningun_modelo_declara_una_columna_prohibida()` no la hace por vos
     | y va a pasar en verde con `leads.email` adentro.
     */
    'columnas_prohibidas' => '/(password|passwd|secret|token|credential|api_key$|^key$|p256dh|^auth$|encrypted)/i',

    'modelos' => [

        /* ---------------------------------------------------------------
         | client — la tabla central de la operación.
         |
         | 🔴 SIN api_key NI inbound_api_key: son las dos credenciales con las
         | que el admin le pega al empresa-api de cada cliente y con las que el
         | cliente le pega de vuelta. Filtrarlas es entregar el sistema de todos
         | los negocios. Están en `clients`, NO en `client_apis`: por eso
         | `client_api` sí entra entero y éste no.
         |
         | 🔴 SIN setup_data: es un json con email y doc_number del titular
         | (ImplementationFormMapper::build_setup_data), o sea PII.
         |
         | 🔴 SIN afip_cuit, afip_razon_social, afip_condicion_iva ni afip_domicilio:
         | datos fiscales del titular. Mismo criterio que setup_data.
         |
         | 🔴 SIN precio_plan, precio_por_cuenta, precio_ecommerce,
         | precio_mercado_libre ni precio_tienda_nube: el desglose comercial de cada
         | cliente. `total_mensualidad` sí entra, que es lo que se usa para operar.
         |
         | `phone` es PII: viaja sólo con include=contacto, mismo criterio que
         | ClaudeClientOpsController::CLIENT_COLUMNS_BASE.
         --------------------------------------------------------------- */
        'client' => [
            'tabla'           => 'clients',
            'descripcion'     => 'Clientes de ComercioCity: versión actual, API activa, estado de la sincronización de horarios y datos de mensualidad.',
            'columnas'        => [
                'id', 'uuid', 'name', 'company_name', 'slug', 'is_active',
                'current_version_id', 'active_client_api_id', 'user_id',
                'shared_database_group_id', 'api_url', 'payment_expired_at',
                'cantidad_empleados', 'tiene_ecommerce', 'tiene_mercado_libre',
                'tiene_tienda_nube', 'total_mensualidad',
                'schedule_sync_status', 'schedule_sync_message', 'schedule_synced_at',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto' => ['phone'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['name', 'company_name', 'slug'],
            'filtros'         => [
                'is_active'                => ['columna' => 'is_active',                'tipo' => 'booleano'],
                'current_version_id'       => ['columna' => 'current_version_id',       'tipo' => 'entero'],
                'shared_database_group_id' => ['columna' => 'shared_database_group_id', 'tipo' => 'entero'],
                'tiene_ecommerce'          => ['columna' => 'tiene_ecommerce',          'tipo' => 'booleano'],
                /* 🔴 Enumeración REAL, verificada en ClientScheduleSyncService::ESTADO_* el 28/8/2026.
                   NO existe 'pending': los cuatro estados son success, manual_required, skipped y
                   failed, y la columna arranca en NULL (nunca se sincronizó). */
                'schedule_sync_status'     => ['columna' => 'schedule_sync_status',     'tipo' => 'en', 'valores' => ['success', 'manual_required', 'skipped', 'failed']],
                'sin_sincronizar'          => ['columna' => 'schedule_sync_status',     'tipo' => 'nulo'],
                'ids'                      => ['columna' => 'id',                       'tipo' => 'lista_de_enteros'],
                'creado_desde'             => ['columna' => 'created_at',               'tipo' => 'fecha_desde'],
                'creado_hasta'             => ['columna' => 'created_at',               'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'version_actual' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'current_version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status'],
                ],
                'apis' => [
                    'tipo'        => 'has_many', 'tabla' => 'client_apis',
                    'clave_local' => 'id', 'clave_externa' => 'client_id',
                    'columnas'    => ['id', 'uuid', 'url', 'path', 'spa_url', 'hosting_type', 'vps_path'],
                    'limite'      => 10,
                ],
            ],
            'nota' => 'Para horarios resueltos, estado del negocio ahora y próximo cierre está GET claude/clients/{id}/schedule, que resuelve la regla de precedencia. Este endpoint devuelve columnas crudas y nada más.',
        ],

        /* ---------------------------------------------------------------
         | client_api — a dónde apunta el admin para hablarle a un cliente.
         |
         | Entra ENTERA, y no es un descuido: `client_apis` NO tiene ninguna
         | credencial. Verificado el 28/8/2026 contra information_schema: sus
         | columnas son uuid, client_id, temporal_id, url, path, spa_url,
         | hosting_type y vps_path. Las api keys viven en `clients`.
         --------------------------------------------------------------- */
        'client_api' => [
            'tabla'           => 'client_apis',
            'descripcion'     => 'APIs registradas de cada cliente: URL, ruta en el hosting, tipo de hosting y SPA asociada. Una de ellas es la activa (clients.active_client_api_id).',
            'columnas'        => [
                'id', 'uuid', 'client_id', 'temporal_id',
                'url', 'path', 'spa_url', 'hosting_type', 'vps_path',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['url', 'spa_url', 'path'],
            'filtros'         => [
                'client_id'    => ['columna' => 'client_id',    'tipo' => 'entero'],
                /* Verificado: la migración pone default 'shared_hosting' y el código sólo compara
                   contra 'vps'. No hay un tercer valor. */
                'hosting_type' => ['columna' => 'hosting_type', 'tipo' => 'en', 'valores' => ['shared_hosting', 'vps']],
                'ids'          => ['columna' => 'id',           'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active', 'active_client_api_id'],
                ],
            ],
            'nota' => 'Para saber cuál es la activa hay que mirar clients.active_client_api_id: no hay ningún flag en esta tabla.',
        ],

        /* ---------------------------------------------------------------
         | version — el catálogo de versiones del ERP.
         --------------------------------------------------------------- */
        'version' => [
            'tabla'           => 'versions',
            'descripcion'     => 'Versiones publicables del ERP, con su estado y si es un hotfix.',
            'columnas'        => [
                'id', 'uuid', 'version', 'is_hotfix', 'title', 'description',
                'status', 'published_at', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['version', 'title'],
            'filtros'         => [
                /* Enumeración de MySQL, leída de information_schema: draft | published | archived. */
                'status'       => ['columna' => 'status',     'tipo' => 'en', 'valores' => ['draft', 'published', 'archived']],
                'is_hotfix'    => ['columna' => 'is_hotfix',  'tipo' => 'booleano'],
                'version'      => ['columna' => 'version',    'tipo' => 'texto_exacto'],
                'ids'          => ['columna' => 'id',         'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at', 'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at', 'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => 'GET claude/versions devuelve lo mismo con la cantidad de ítems por versión ya contada. Este endpoint son las columnas crudas.',
        ],

        /* ---------------------------------------------------------------
         | version_seeder — los seeders que trae una versión.
         |
         | ⚠️ NO tiene `is_active` (el plan lo listaba): verificado contra
         | information_schema el 28/8/2026. La que sí lo tiene es
         | version_notifications.
         --------------------------------------------------------------- */
        'version_seeder' => [
            'tabla'           => 'version_seeders',
            'descripcion'     => 'Seeders que hay que correr al instalar una versión, con su orden de ejecución y su alcance.',
            'columnas'        => [
                'id', 'uuid', 'version_id', 'seeder_class', 'description',
                'execution_order', 'is_required', 'run_scope', 'source_group_id',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['seeder_class', 'description'],
            'filtros'         => [
                'version_id'      => ['columna' => 'version_id',      'tipo' => 'entero'],
                'is_required'     => ['columna' => 'is_required',     'tipo' => 'booleano'],
                /* Verificado en ClaudeVersionItemsIngestController::normalize_run_scope(): son dos. */
                'run_scope'       => ['columna' => 'run_scope',       'tipo' => 'en', 'valores' => ['per_database', 'per_user']],
                'source_group_id' => ['columna' => 'source_group_id', 'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status'],
                ],
            ],
            'nota' => 'source_group_id es un varchar, no un id: lo escribe la ingesta de ítems para poder reejecutar el mismo payload sin duplicar.',
        ],

        /* ---------------------------------------------------------------
         | version_command — los comandos artisan que trae una versión.
         |
         | `run_manually` es el que decide si el pipeline lo corre solo o lo deja
         | anotado para que lo corra un humano. Tampoco tiene `is_active`.
         --------------------------------------------------------------- */
        'version_command' => [
            'tabla'           => 'version_commands',
            'descripcion'     => 'Comandos artisan que hay que correr al instalar una versión, con su orden y si van a mano.',
            'columnas'        => [
                'id', 'uuid', 'version_id', 'command', 'description',
                'execution_order', 'is_required', 'run_manually', 'run_scope',
                'source_group_id', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['command', 'description'],
            'filtros'         => [
                'version_id'      => ['columna' => 'version_id',      'tipo' => 'entero'],
                'is_required'     => ['columna' => 'is_required',     'tipo' => 'booleano'],
                'run_manually'    => ['columna' => 'run_manually',    'tipo' => 'booleano'],
                'run_scope'       => ['columna' => 'run_scope',       'tipo' => 'en', 'valores' => ['per_database', 'per_user']],
                'source_group_id' => ['columna' => 'source_group_id', 'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status'],
                ],
            ],
            'nota' => 'Un comando con run_manually=true NO lo corre el pipeline: queda anotado para un humano y no habilita el reintento de comandos.',
        ],

        /* ---------------------------------------------------------------
         | version_manual_task — lo que queda para hacer a mano.
         --------------------------------------------------------------- */
        'version_manual_task' => [
            'tabla'           => 'version_manual_tasks',
            'descripcion'     => 'Tareas manuales que trae una versión: lo que el pipeline no puede hacer solo.',
            'columnas'        => [
                'id', 'uuid', 'version_id', 'title', 'description',
                'execution_order', 'is_required', 'source_group_id',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['title', 'description'],
            'filtros'         => [
                'version_id'      => ['columna' => 'version_id',      'tipo' => 'entero'],
                'is_required'     => ['columna' => 'is_required',     'tipo' => 'booleano'],
                'source_group_id' => ['columna' => 'source_group_id', 'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status'],
                ],
            ],
            'nota' => 'No hay tabla de "tarea manual hecha" por cliente: esto es el catálogo de la versión, no un checklist por deployment.',
        ],

        /* ---------------------------------------------------------------
         | version_notification — las novedades que se le muestran al usuario.
         |
         | Ésta SÍ tiene `is_active`, a diferencia de seeders y comandos.
         --------------------------------------------------------------- */
        'version_notification' => [
            'tabla'           => 'version_notifications',
            'descripcion'     => 'Novedades que la versión le muestra al usuario del ERP.',
            'columnas'        => [
                'id', 'uuid', 'version_id', 'title', 'body', 'sort_order',
                'is_active', 'source_group_id', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['title', 'body'],
            'filtros'         => [
                'version_id'      => ['columna' => 'version_id',      'tipo' => 'entero'],
                'is_active'       => ['columna' => 'is_active',       'tipo' => 'booleano'],
                'source_group_id' => ['columna' => 'source_group_id', 'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status'],
                ],
            ],
            'nota' => '`body` es un text corto (el cuerpo de la novedad), no un log: por eso entra y los logs de deployment no.',
        ],

        /* ---------------------------------------------------------------
         | client_version_upgrade — la máquina de estados del deployment.
         |
         | 🔴 SIN los logs (deployment_logs.line): ese scope arrastra miles de
         | líneas con la salida cruda de `npm run build`. Para logs está
         | GET claude/upgrades/{id}/logs, que trunca por max_line_chars.
         --------------------------------------------------------------- */
        'client_version_upgrade' => [
            'tabla'           => 'client_version_upgrades',
            'descripcion'     => 'Actualizaciones de versión de un cliente, con el estado del deployment y los timestamps de cada paso.',
            'columnas'        => [
                'id', 'uuid', 'client_id', 'from_version_id', 'to_version_id',
                'status', 'target_client_api_id', 'scheduled_date', 'notes',
                'created_by_admin_id', 'created_via',
                'deployment_status', 'deployment_started_at', 'deployment_running_since',
                'started_at', 'finished_at',
                'crons_supervisor_at', 'sistema_actualizado_at', 'migraciones_corridas_at',
                'seeders_ejecutados_at', 'comandos_ejecutados_at', 'sistema_configurado_at',
                'default_version_sync_status', 'default_version_sync_message',
                'synced_at', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'client_id'         => ['columna' => 'client_id',     'tipo' => 'entero'],
                'to_version_id'     => ['columna' => 'to_version_id', 'tipo' => 'entero'],
                /* 🔴 La enumeración REAL, verificada contra la enumeración de MySQL y contra
                   UpdateController::STATUS_LABELS el 28/8/2026. NO es pending/success/failed: eso no
                   existe en el proyecto. Los terminales son 'terminada' y 'fallida'; todo lo demás es
                   un upgrade abierto. */
                'status'            => ['columna' => 'status',            'tipo' => 'en', 'valores' => ['pendiente', 'listo_para_actualizar', 'actualizandose', 'terminada', 'fallida']],
                /* Verificado en ClaudeClientOpsController::DEPLOYMENT_STATUSES. */
                'deployment_status' => ['columna' => 'deployment_status', 'tipo' => 'en', 'valores' => ['running', 'paused', 'paused_post_tasks', 'completed', 'failed']],
                'sin_deployment'    => ['columna' => 'deployment_status', 'tipo' => 'nulo'],
                'created_via'       => ['columna' => 'created_via',       'tipo' => 'texto_exacto'],
                'ids'               => ['columna' => 'id',                'tipo' => 'lista_de_enteros'],
                'creado_desde'      => ['columna' => 'created_at',        'tipo' => 'fecha_desde'],
                'creado_hasta'      => ['columna' => 'created_at',        'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active'],
                ],
                'version_destino' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'to_version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status', 'is_hotfix'],
                ],
            ],
            'nota' => 'Para el estado vivo (salud.deployment_stale, jobs_en_cola, siguiente_accion) usá GET claude/upgrades/{id}: eso se calcula, no está en columnas.',
        ],

        /* ---------------------------------------------------------------
         | update_seeder — el seeder de UNA actualización concreta.
         --------------------------------------------------------------- */
        'update_seeder' => [
            'tabla'           => 'update_seeders',
            'descripcion'     => 'Seeders de una actualización concreta, con el resultado de la corrida.',
            'columnas'        => [
                'id', 'uuid', 'client_version_upgrade_id', 'version_seeder_id',
                'status', 'executed_at', 'failure_notes', 'skipped',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'client_version_upgrade_id' => ['columna' => 'client_version_upgrade_id', 'tipo' => 'entero'],
                'version_seeder_id'         => ['columna' => 'version_seeder_id',         'tipo' => 'entero'],
                /* Enumeración de MySQL: pendiente | exitoso | fallido. */
                'status'                    => ['columna' => 'status',                    'tipo' => 'en', 'valores' => ['pendiente', 'exitoso', 'fallido']],
                'skipped'                   => ['columna' => 'skipped',                   'tipo' => 'booleano'],
                'ids'                       => ['columna' => 'id',                        'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'seeder_de_version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'version_seeders',
                    'clave_local' => 'version_seeder_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version_id', 'seeder_class', 'execution_order', 'run_scope'],
                ],
            ],
            'nota' => '🔴 Un seeder con skipped=true cuenta como COMPLETO para el reintento de comandos: no hay que leerlo como pendiente.',
        ],

        /* ---------------------------------------------------------------
         | update_command — el comando de UNA actualización concreta.
         --------------------------------------------------------------- */
        'update_command' => [
            'tabla'           => 'update_commands',
            'descripcion'     => 'Comandos de una actualización concreta, con el resultado de la corrida.',
            'columnas'        => [
                'id', 'uuid', 'client_version_upgrade_id', 'version_command_id',
                'status', 'executed_at', 'failure_notes', 'skipped',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'client_version_upgrade_id' => ['columna' => 'client_version_upgrade_id', 'tipo' => 'entero'],
                'version_command_id'        => ['columna' => 'version_command_id',        'tipo' => 'entero'],
                'status'                    => ['columna' => 'status',                    'tipo' => 'en', 'valores' => ['pendiente', 'exitoso', 'fallido']],
                'skipped'                   => ['columna' => 'skipped',                   'tipo' => 'booleano'],
                'ids'                       => ['columna' => 'id',                        'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'comando_de_version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'version_commands',
                    'clave_local' => 'version_command_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version_id', 'command', 'execution_order', 'run_manually', 'run_scope'],
                ],
            ],
            'nota' => 'Un comando retriable es el que tiene version_command_id no nulo, run_manually=false, skipped=false y status en fallido o pendiente. Ésa es la condición exacta que mira POST claude/upgrades/{id}/deploy/retry-commands.',
        ],

        /* ---------------------------------------------------------------
         | client_schedule_day — los días de horario cargados de un cliente.
         --------------------------------------------------------------- */
        'client_schedule_day' => [
            'tabla'           => 'client_schedule_days',
            'descripcion'     => 'Días de horario cargados de un cliente. Un día "todos" vale como default de la semana.',
            'columnas'        => [
                'id', 'uuid', 'client_id', 'day_key', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'client_id' => ['columna' => 'client_id', 'tipo' => 'entero'],
                /* Verificado en ClientScheduleDay::DAY_KEYS. */
                'day_key'   => ['columna' => 'day_key',   'tipo' => 'en', 'valores' => ['todos', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo']],
                'ids'       => ['columna' => 'id',        'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'rangos' => [
                    'tipo'        => 'has_many', 'tabla' => 'client_schedule_ranges',
                    'clave_local' => 'id', 'clave_externa' => 'client_schedule_day_id',
                    'columnas'    => ['id', 'start_time', 'end_time', 'sort_order'],
                    'limite'      => 10,
                ],
            ],
            'nota' => '🔴 Esto son los días CRUDOS, sin resolver la precedencia entre "todos" y el día puntual. La ventana resuelta, el estado del negocio ahora y el próximo cierre están en GET claude/clients/{id}/schedule.',
        ],

        /* ---------------------------------------------------------------
         | client_schedule_range — las franjas horarias de un día.
         --------------------------------------------------------------- */
        'client_schedule_range' => [
            'tabla'           => 'client_schedule_ranges',
            'descripcion'     => 'Franjas horarias de un día de horario: de qué hora a qué hora abre el negocio.',
            'columnas'        => [
                'id', 'uuid', 'client_schedule_day_id', 'start_time', 'end_time',
                'sort_order', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'client_schedule_day_id' => ['columna' => 'client_schedule_day_id', 'tipo' => 'entero'],
                'ids'                    => ['columna' => 'id',                     'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'dia' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'client_schedule_days',
                    'clave_local' => 'client_schedule_day_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'day_key'],
                ],
            ],
            'nota' => 'No tiene client_id propio: se llega por el día. Para filtrar por cliente, primero client_schedule_day.',
        ],

        /* ---------------------------------------------------------------
         | shared_database_group — clientes que comparten una misma base.
         |
         | ⚠️ Tabla sin `uuid`: verificado contra information_schema. Son cuatro
         | columnas y nada más.
         --------------------------------------------------------------- */
        'shared_database_group' => [
            'tabla'           => 'shared_database_groups',
            'descripcion'     => 'Grupos de clientes que comparten una misma base de datos.',
            'columnas'        => ['id', 'name', 'created_at', 'updated_at'],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['name'],
            'filtros'         => [
                'ids' => ['columna' => 'id', 'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'clientes' => [
                    'tipo'        => 'has_many', 'tabla' => 'clients',
                    'clave_local' => 'id', 'clave_externa' => 'shared_database_group_id',
                    'columnas'    => ['id', 'name', 'is_active', 'current_version_id'],
                    'limite'      => 50,
                ],
            ],
            'nota' => '🔴 Un seeder per_database corre UNA vez para todo el grupo: por eso SharedDatabaseAutoSkipService marca skipped los del resto de los clientes del grupo.',
        ],

        /* ---------------------------------------------------------------
         | client_ecommerce — la tienda de un cliente.
         |
         | 🔴 SIN ecommerce_setup_data: json de configuración de la instalación,
         | de volumen y con datos del titular.
         |
         | ⚠️ Tabla sin `uuid`.
         --------------------------------------------------------------- */
        'client_ecommerce' => [
            'tabla'           => 'client_ecommerces',
            'descripcion'     => 'Tienda (ecommerce) de un cliente: dominio, URLs y rutas en el hosting.',
            'columnas'        => [
                'id', 'client_id', 'domain', 'api_url', 'spa_url',
                'api_path', 'spa_path', 'status', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['domain', 'spa_url', 'api_url'],
            'filtros'         => [
                'client_id' => ['columna' => 'client_id', 'tipo' => 'entero'],
                /* 🔴 Enumeración de MySQL: pending | installing | active. OJO: está en INGLÉS, a
                   diferencia de client_ecommerce_installations.status, que está en español. Son dos
                   tablas del mismo pipeline con dos idiomas de estado: no las mezcles. */
                'status'    => ['columna' => 'status',    'tipo' => 'en', 'valores' => ['pending', 'installing', 'active']],
                'ids'       => ['columna' => 'id',        'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active', 'tiene_ecommerce'],
                ],
            ],
            'nota' => 'Para saber si una tienda se puede actualizar ahora mismo (y el motivo si no) está GET claude/ecommerce/stores, que evalúa las precondiciones. Acá están las columnas crudas.',
        ],

        /* ---------------------------------------------------------------
         | client_ecommerce_installation — corridas del pipeline de tienda.
         |
         | 🔴 SIN los logs (ecommerce_deployment_logs.line): mismo motivo de
         | volumen que arriba. GET claude/ecommerce/installations/{id}/logs.
         --------------------------------------------------------------- */
        'client_ecommerce_installation' => [
            'tabla'           => 'client_ecommerce_installations',
            'descripcion'     => 'Corridas de instalación (install) o actualización (update) de la tienda de un cliente.',
            'columnas'        => [
                'id', 'uuid', 'client_ecommerce_id', 'mode', 'status',
                'created_via', 'failure_reason',
                'started_at', 'finished_at', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'client_ecommerce_id' => ['columna' => 'client_ecommerce_id', 'tipo' => 'entero'],
                'mode'                => ['columna' => 'mode',                'tipo' => 'en', 'valores' => ['install', 'update']],
                'status'              => ['columna' => 'status',              'tipo' => 'en', 'valores' => ['pendiente', 'instalando', 'completada', 'fallida']],
                'created_via'         => ['columna' => 'created_via',         'tipo' => 'texto_exacto'],
                'ids'                 => ['columna' => 'id',                  'tipo' => 'lista_de_enteros'],
                'creado_desde'        => ['columna' => 'created_at',          'tipo' => 'fecha_desde'],
                'creado_hasta'        => ['columna' => 'created_at',          'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'tienda' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'client_ecommerces',
                    'clave_local' => 'client_ecommerce_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'domain', 'spa_url', 'api_url', 'status'],
                ],
            ],
            'nota' => '🔴 status="instalando" NO tiene ningún proceso que lo destrabe: no existe el equivalente de deployments:vencer-colgados para esta tabla. Ver la limitación declarada en GET claude/catalog.',
        ],

        /* ---------------------------------------------------------------
         | client_installation — instalación del ERP de un cliente.
         |
         | 🔴 SIN env_manual_values: json con valores de `.env` cargados a mano,
         | o sea configuración de producción de un negocio real.
         --------------------------------------------------------------- */
        'client_installation' => [
            'tabla'           => 'client_installations',
            'descripcion'     => 'Corridas de instalación del ERP de un cliente: completa o esqueleto.',
            'columnas'        => [
                'id', 'uuid', 'client_id', 'client_api_id', 'version_id',
                'kind', 'group_uuid', 'status', 'failure_reason',
                'started_at', 'finished_at', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'client_id'     => ['columna' => 'client_id',     'tipo' => 'entero'],
                'client_api_id' => ['columna' => 'client_api_id', 'tipo' => 'entero'],
                'version_id'    => ['columna' => 'version_id',    'tipo' => 'entero'],
                /* Verificado en ClientInstallation::KINDS. */
                'kind'          => ['columna' => 'kind',          'tipo' => 'en', 'valores' => ['completa', 'esqueleto']],
                /* Los escribe InstallationService: pendiente al crear, instalando al arrancar,
                   completada o fallida al terminar. */
                'status'        => ['columna' => 'status',        'tipo' => 'en', 'valores' => ['pendiente', 'instalando', 'completada', 'fallida']],
                'group_uuid'    => ['columna' => 'group_uuid',    'tipo' => 'texto_exacto'],
                'ids'           => ['columna' => 'id',            'tipo' => 'lista_de_enteros'],
                'creado_desde'  => ['columna' => 'created_at',    'tipo' => 'fecha_desde'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active'],
                ],
            ],
            'nota' => 'group_uuid junta las instalaciones que se dispararon en la misma tanda (una por ClientApi).',
        ],

        /* ---------------------------------------------------------------
         | client_employee — los empleados que el cliente dio de alta.
         |
         | 🔴 `phone` es PII y va opt-in: viaja sólo con include=contacto, igual
         | que en `client`. `notes` queda afuera: es texto libre sobre una persona.
         --------------------------------------------------------------- */
        'client_employee' => [
            'tabla'           => 'client_employees',
            'descripcion'     => 'Empleados cargados por un cliente, y si tienen habilitada la consulta al sistema.',
            'columnas'        => [
                'id', 'uuid', 'client_id', 'name', 'can_query_system',
                'empresa_employee_id', 'temporal_id', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto' => ['phone'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['name'],
            'filtros'         => [
                'client_id'        => ['columna' => 'client_id',        'tipo' => 'entero'],
                'can_query_system' => ['columna' => 'can_query_system', 'tipo' => 'booleano'],
                'ids'              => ['columna' => 'id',               'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active'],
                ],
            ],
            'nota' => 'empresa_employee_id es el id del empleado del lado del empresa-api del cliente, no un id de este admin.',
        ],

        /* ---------------------------------------------------------------
         | admin_task — las tareas internas del admin.
         |
         | 🔴 SIN `content` ni `todos`: el cuerpo largo de la tarea y su checklist
         | en json. Es volumen, y en una consulta de 200 filas lo es de verdad.
         |
         | ⚠️ Tabla sin `uuid`.
         --------------------------------------------------------------- */
        'admin_task' => [
            'tabla'           => 'admin_tasks',
            'descripcion'     => 'Tareas internas del admin: título, asignación y si están hechas. Sin el cuerpo ni el checklist.',
            'columnas'        => [
                'id', 'title', 'is_done', 'done_at', 'created_via',
                'created_by_admin_id', 'assigned_admin_id', 'done_by_admin_id',
                'lead_id', 'sort_order', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['title'],
            'filtros'         => [
                'is_done'           => ['columna' => 'is_done',           'tipo' => 'booleano'],
                'assigned_admin_id' => ['columna' => 'assigned_admin_id', 'tipo' => 'entero'],
                'lead_id'           => ['columna' => 'lead_id',           'tipo' => 'entero'],
                /* Es un varchar sin constante: la migración pone default 'admin' y
                   ClaudeTaskIngestController escribe 'claude'. Por eso va como texto exacto y no
                   como enumeración: declarar una lista cerrada acá sería inventarla. */
                'created_via'       => ['columna' => 'created_via',       'tipo' => 'texto_exacto'],
                'ids'               => ['columna' => 'id',                'tipo' => 'lista_de_enteros'],
                'creado_desde'      => ['columna' => 'created_at',        'tipo' => 'fecha_desde'],
                'creado_hasta'      => ['columna' => 'created_at',        'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => 'El cuerpo (`content`) y el checklist (`todos`) no se sirven acá por volumen. Para crear una tarea está POST claude/task.',
        ],

        /* ---------------------------------------------------------------
         | lead — la tabla central del pipeline comercial.
         |
         | Entra por el MISMO criterio que `client`: tabla grande, con secretos
         | y con PII, que se publica sin ellos. Hasta el 3/9/2026 estaba entera
         | en `modelos_excluidos` por dos tokens, y eso dejaba un agujero peor
         | que el que tapaba: `PATCH claude/leads/{id}` ESCRIBE `notes`,
         | `business_type`, `meeting_scheduled_at`, `demo_flexible` y las horas
         | de la demo, y ninguna de esas columnas se podía LEER por ningún lado.
         | Escribir `notes` a ciegas es peor que no poder escribirlo: esa columna
         | la llena una persona desde el panel (LeadProperties + extract_data) y
         | el PATCH hace `update()`, o sea que reemplaza. Sin lectura previa, una
         | corrida del agente le borra la nota a Lucas sin que nada lo denuncie.
         |
         | 🔴 SIN demo_ingreso_token NI demo_eventos_token: son las dos que
         | motivaron la exclusión original y siguen afuera. Abren la instancia de
         | demo del lead SIN AUTH, con sólo tener la cadena.
         | Tampoco entran `demo_ingreso_token_expira_at` ni
         | `demo_ingreso_token_revocado_at`, que no son secretas pero matchean
         | `columnas_prohibidas` por el `token` del nombre: nombrarlas devolvería
         | 422 sobre el modelo entero (fail-closed) y rompería el test del build.
         |
         | 🔴 SIN NINGUNA contract_*: son las 17 columnas del desglose comercial y
         | fiscal del contrato del lead (contract_client_razon_social,
         | contract_client_cuit, contract_precio_licencia, contract_financiacion,
         | contract_mensualidad_base, contract_clausulas_particulares y el resto).
         | Mismo criterio con el que `client` dejó afuera afip_* y los precio_*:
         | datos fiscales del titular y desglose de precios. `total_a_pagar` SÍ
         | entra, que es el equivalente de `clients.total_mensualidad` y es lo que
         | se usa para operar.
         |
         | 🔴 SIN recall_bot_id, google_event_id ni closer_hold_event_id: son
         | identificadores de servicios de terceros (Recall.ai y Google Calendar).
         | No hay ninguna lectura operativa que los necesite desde acá y sí hay
         | riesgo de que alguien los use para pegarle a esos servicios. Los de la
         | llamada se leen en `model=lead_call`, que es la fuente viva.
         |
         | `phone`, `email`, `doc_number` y las tres `address_*` son PII: viajan
         | sólo con include=contacto, mismo criterio que `clients.phone` y que
         | ClaudeLeadQueryService. Ojo con `email`: el PATCH lo escribe, así que
         | para leer lo que escribiste hace falta el include.
         |
         | `meet_url` va opt-in bajo include=agenda: el link ABRE LA VIDEOLLAMADA
         | a cualquiera que lo tenga, sin auth. Es el mismo criterio que ya se le
         | aplicó a `lead_calls.meet_url` en este mismo archivo.
         |
         | `call_summary`, `demo_summary` y `demo_summary_structured` van opt-in
         | bajo include=resumen por volumen: son tres `text` y en una página de
         | 100 filas pesan más que todo el resto junto.
         | 🔴 Y OJO CON `call_summary`: la fuente viva del resumen de la llamada
         | es `lead_calls.call_summary`, NO esta columna. CallSummaryService
         | escribe sobre `lead_calls` y LeadController lee
         | `$lead->calls()->whereNotNull('call_summary')`. La de `leads` quedó por
         | compatibilidad y sólo tiene datos de leads viejos: si está vacía no
         | significa que no hubo llamada, significa que mires `model=lead_call`.
         |
         | Lo demás que no está en `columnas` está en un include por RUIDO, no por
         | secreto: `automatizaciones` son los interruptores y las marcas de
         | "ya se envió" del ciclo de demo, `formulario` son las respuestas del
         | formulario de demo (cómo trabaja el negocio), `plan` el json del plan de
         | la demo, y `setup` los reintentos y los `*_last_error` de la instalación
         | y de los mails.
         --------------------------------------------------------------- */
        'lead' => [
            'tabla'           => 'leads',
            'descripcion'     => 'Leads del pipeline comercial: estado, agenda de la demo, notas del setter, flags de automatización y a qué cliente se promovió. Es la tabla que escribe PATCH claude/leads/{id}.',
            'columnas'        => [
                'id', 'uuid', 'contact_name', 'company_name', 'business_type', 'status',
                'notes', 'created_by_admin_id', 'notify_admin_id', 'welcome_variant_id',
                'target_client_id', 'promoted_client_id',

                'meeting_scheduled_at', 'demo_id', 'demo_date', 'demo_start_time',
                'demo_end_time', 'demo_flexible', 'demo_experiencia', 'perfil_lead',
                'api_url', 'user_name', 'user_id', 'total_a_pagar',

                'demo_ingreso_confirmado', 'demo_ingreso_confirmado_at',
                'demo_terminada_confirmada', 'demo_terminada_confirmada_at',
                'demo_setup_status', 'user_setup_status',
                'demo_summary_generated_at', 'demo_summary_manual',
                'intro_visto_pct', 'intro_visto_at',

                'closer_called_at', 'closer_notified_at', 'closer_alert_sent_at',
                'closer_alert_accepted_at', 'closer_delay_message_sent_at',
                'closer_no_show_rescheduled_at',

                'claude_auto_reply', 'requiere_intervencion_humana',
                'requiere_verificacion_mensajes', 'requiere_seguimiento',
                'notificar_mensajes', 'tiene_sugerencia_pendiente',
                'tiene_seguimiento_sin_ver',

                'first_message_at', 'last_message_at', 'pinned_at',
                'pendiente_revision_at', 'no_recibe_mensajes_at',
                'no_recibe_mensajes_motivo',

                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto'         => ['phone', 'email', 'doc_number', 'address_1', 'address_2', 'address_3'],
                'agenda'           => ['meet_url'],
                'resumen'          => ['call_summary', 'demo_summary', 'demo_summary_structured'],
                'automatizaciones' => [
                    'automatizaciones_demo_activas', 'auto_recordatorio_demo',
                    'auto_check_ingreso_demo', 'auto_check_fin_demo', 'auto_resumen_closer',
                    'recordatorio_demo_enviado', 'recordatorio_manana_enviado',
                    'recordatorio_demo_enviado_at', 'recordatorio_demo_manual',
                    'demo_check_ingreso_enviado', 'demo_check_ingreso_enviado_at',
                    'demo_check_ingreso_manual', 'demo_fin_seguimiento_enviado',
                    'demo_pendiente_terminar_notificado', 'demo_no_ingreso_notificado',
                    'demo_fin_check_enviado', 'demo_fin_check_reprogramado_para',
                    'presentation_mail_sent_at', 'followup_mail_sent_at', 'demo_mail_sent_at',
                ],
                'formulario'       => [
                    'use_deposits', 'use_price_lists', 'price_type_1', 'price_type_2',
                    'price_type_3', 'iva_included', 'ventas_con_fecha_de_entrega', 'cajas',
                    'usar_codigos_de_barra', 'codigos_de_barra_por_defecto',
                    'consultora_de_precios', 'imagenes', 'produccion', 'ask_amount_in_vender',
                    'redondear_centenas_en_vender', 'omitir_cuentas_corrientes',
                    'costos_en_dolares', 'descuentos_por_metodo_pago',
                    'usa_cuentas_corrientes_proveedores', 'usa_presupuestos', 'registra_compras',
                    'usa_ecommerce', 'demo_form_completado_at', 'demo_form_editado_admin_at',
                ],
                'plan'             => ['demo_plan', 'demo_plan_congelado_at'],
                'setup'            => [
                    'demo_setup_intentos', 'demo_setup_last_run_at', 'demo_setup_last_run_manual',
                    'demo_setup_last_error', 'user_setup_last_run_at', 'user_setup_last_error',
                    'demo_mail_last_error', 'presentation_mail_last_error',
                    'followup_mail_last_error',
                ],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['contact_name', 'company_name', 'business_type'],
            'filtros'         => [
                /* 🔴 Enumeración REAL, tomada de LeadPipelineStatus::DEFAULT_STATUSES (los 15 slugs
                   con los que se siembra `lead_pipeline_statuses`), verificada el 3/9/2026 leyendo
                   la constante, no las migraciones. Es la MISMA lista que declara el filtro
                   `estado` de followup_rule, y tiene que seguir siéndolo.
                   ⚠️ La fuente viva es `LeadPipelineStatus::all_slugs()`, que lee la TABLA y sólo
                   cae a la constante si está vacía, y `ensure_exists()` puede dar de alta un slug
                   nuevo en runtime con lo que devolvió Claude. Si filtrás por un estado y te vuelve
                   422, mirá primero `model=lead_pipeline_status`: ésa es la lista del momento.
                   Ojo con `mail2_enviado`: sigue en el catálogo por historia pero Lucas lo dejó de
                   usar (SLUGS_HIDDEN_FROM_SELECT), así que filtrar por él devuelve poco y viejo. */
                'status'                         => ['columna' => 'status',                         'tipo' => 'en', 'valores' => ['nuevo', 'contactado', 'calificado', 'solicita_disponibilidad', 'demo_agendada', 'ingresando_demo', 'demo_en_curso', 'demo_pendiente_de_ingreso', 'demo_pendiente_de_terminar', 'demo_realizada', 'closer_activo', 'mail2_enviado', 'cerrado_ganado', 'cerrado_perdido', 'en_pausa']],
                'business_type'                  => ['columna' => 'business_type',                  'tipo' => 'texto'],
                'demo_id'                        => ['columna' => 'demo_id',                        'tipo' => 'entero'],
                'target_client_id'               => ['columna' => 'target_client_id',               'tipo' => 'entero'],
                'notify_admin_id'                => ['columna' => 'notify_admin_id',                'tipo' => 'entero'],
                'demo_desde'                     => ['columna' => 'demo_date',                      'tipo' => 'fecha_desde'],
                'demo_hasta'                     => ['columna' => 'demo_date',                      'tipo' => 'fecha_hasta'],
                'sin_demo'                       => ['columna' => 'demo_date',                      'tipo' => 'nulo'],
                /* 🔴 UN SOLO filtro para las dos puntas de `promoted_client_id`, y es a propósito.
                   El tipo `nulo` de ClaudeQueryService resuelve `=1` como whereNull y `=0` como
                   whereNotNull, así que `sin_promover=1` trae los que todavía no se promovieron y
                   `sin_promover=0` trae los que sí. Un filtro que se llamara `promovido` sería una
                   trampa: `promovido=1` devolvería justo los NO promovidos. Es la misma forma que
                   ya usan `sin_agendar` de lead_call y `sin_sincronizar` de client. Para filtrar
                   por A QUÉ cliente se promovió está `promovido_a`, que es el id. */
                'sin_promover'                   => ['columna' => 'promoted_client_id',             'tipo' => 'nulo'],
                'promovido_a'                    => ['columna' => 'promoted_client_id',             'tipo' => 'entero'],
                /* Misma mecánica: `recibe_mensajes=1` son los que NO tienen la marca de
                   inalcanzable (no_recibe_mensajes_at en NULL) y `=0` son los que sí la tienen. Esa
                   marca la pone una persona, y es la guarda que hay que mirar ANTES de proponer
                   cualquier saliente. */
                'recibe_mensajes'                => ['columna' => 'no_recibe_mensajes_at',          'tipo' => 'nulo'],
                'mensaje_desde'                  => ['columna' => 'last_message_at',                'tipo' => 'fecha_desde'],
                'mensaje_hasta'                  => ['columna' => 'last_message_at',                'tipo' => 'fecha_hasta'],
                'claude_auto_reply'              => ['columna' => 'claude_auto_reply',              'tipo' => 'booleano'],
                'requiere_intervencion_humana'   => ['columna' => 'requiere_intervencion_humana',   'tipo' => 'booleano'],
                'requiere_verificacion_mensajes' => ['columna' => 'requiere_verificacion_mensajes', 'tipo' => 'booleano'],
                'requiere_seguimiento'           => ['columna' => 'requiere_seguimiento',           'tipo' => 'booleano'],
                'demo_ingreso_confirmado'        => ['columna' => 'demo_ingreso_confirmado',        'tipo' => 'booleano'],
                'demo_terminada_confirmada'      => ['columna' => 'demo_terminada_confirmada',      'tipo' => 'booleano'],
                'ids'                            => ['columna' => 'id',                             'tipo' => 'lista_de_enteros'],
                'creado_desde'                   => ['columna' => 'created_at',                     'tipo' => 'fecha_desde'],
                'creado_hasta'                   => ['columna' => 'created_at',                     'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'demo' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'demos',
                    'clave_local' => 'demo_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'nombre', 'erp_spa_url', 'ecommerce_spa_url'],
                ],
                'cliente_promovido' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'promoted_client_id', 'clave_externa' => 'id',
                    /* Sin api_key ni setup_data: una relación filtra tan fácil como la tabla
                       principal, y es exactamente el criterio de la entrada `client`. */
                    'columnas'    => ['id', 'uuid', 'name', 'company_name', 'slug', 'is_active'],
                ],
                'llamadas' => [
                    'tipo'        => 'has_many', 'tabla' => 'lead_calls',
                    'clave_local' => 'id', 'clave_externa' => 'lead_id',
                    /* Sin meet_url ni call_summary: mismo criterio que la entrada lead_call, donde
                       los dos van opt-in. */
                    'columnas'    => ['id', 'estado', 'scheduled_at', 'started_at', 'created_at'],
                    'limite'      => 10,
                ],
            ],
            'nota' => 'NO reemplaza a GET claude/leads y no se contradicen: aquél es el camino cuando hay algo CALCULADO (include=conteos, demo, contrato), porque resuelve la relación y arma los totales; éste devuelve columnas CRUDAS de `leads` y nada más, incluidas las que aquél no expone. La razón por la que existe: PATCH claude/leads/{id} escribe notes, contact_name, company_name, business_type, email, demo_date, demo_start_time, demo_end_time, demo_flexible y meeting_scheduled_at, y sin este modelo no había forma de leer lo que se estaba por pisar. 🔴 `notes` la llena una persona desde el panel y el PATCH reemplaza el valor entero: leela ANTES de escribirla. Para `email` hace falta include=contacto. El resumen de la llamada del closer NO está acá: está en model=lead_call (leads.call_summary quedó por compatibilidad).',
        ],

        /* ---------------------------------------------------------------
         | followup_rule — la CADENCIA de los seguimientos automáticos.
         |
         | Entra entera: ocho columnas, ninguna con dato de una persona.
         | `descripcion` es una frase interna sobre el estado ("No respondió el
         | mensaje de bienvenida"), no texto sobre un lead.
         --------------------------------------------------------------- */
        'followup_rule' => [
            'tabla'           => 'followup_rules',
            'descripcion'     => 'Cadencia de los seguimientos automáticos por estado del lead: cuántas horas espera el cron y cuántos seguimientos manda como máximo.',
            'columnas'        => [
                'id', 'estado', 'horas_espera', 'max_followups', 'activa',
                'descripcion', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['estado', 'descripcion'],
            'filtros'         => [
                /* 🔴 Enumeración REAL, tomada de LeadPipelineStatus::DEFAULT_STATUSES (los 15 slugs
                   con los que se siembra `lead_pipeline_statuses`). Acá SÍ va cerrada porque una
                   regla de cadencia con un estado que no es del pipeline nunca la levanta el cron:
                   LeadFollowupService hace `FollowupRule::where('activa', true)->get()->keyBy('estado')`
                   y la busca por el status del lead.
                   ⚠️ La fuente viva es `LeadPipelineStatus::all_slugs()`, que lee la TABLA y sólo cae
                   a la constante si está vacía, y `ensure_exists()` puede dar de alta un slug nuevo
                   en runtime. Si filtrás por un slug y la respuesta es 422, mirá primero
                   `model=lead_pipeline_status`: ésa es la lista de verdad del momento. */
                'estado'       => ['columna' => 'estado',     'tipo' => 'en', 'valores' => ['nuevo', 'contactado', 'calificado', 'solicita_disponibilidad', 'demo_agendada', 'ingresando_demo', 'demo_en_curso', 'demo_pendiente_de_ingreso', 'demo_pendiente_de_terminar', 'demo_realizada', 'closer_activo', 'mail2_enviado', 'cerrado_ganado', 'cerrado_perdido', 'en_pausa']],
                'activa'       => ['columna' => 'activa',     'tipo' => 'booleano'],
                'ids'          => ['columna' => 'id',         'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at', 'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at', 'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 UNA regla activa por estado, y la unicidad no la garantiza ningún índice: LeadFollowupService hace keyBy("estado") sobre las activas, así que si hubiera dos para el mismo estado una pisa a la otra en silencio y no hay error en ningún lado. Si contás dos activas con el mismo `estado`, eso ya es el bug.',
        ],

        /* ---------------------------------------------------------------
         | followup_template — la plantilla Meta que se manda en cada día del
         | seguimiento. Entra entera: `body_template` es el cuerpo de una
         | plantilla aprobada por Meta (texto nuestro, con placeholders), no una
         | conversación con nadie.
         |
         | ⚠️ Los cuatro atributos derivados del modelo Eloquent (`categoria`,
         | `categoria_label`, `categoria_orden`, `variables`) NO viajan por acá:
         | son `$appends` y este endpoint usa DB::table, sin modelo. Se calculan
         | de `estado` + `solo_si_ingreso_confirmado` + `template_name`, así que
         | con esas tres columnas se reconstruyen del otro lado.
         --------------------------------------------------------------- */
        'followup_template' => [
            'tabla'           => 'followup_templates',
            'descripcion'     => 'Plantillas Meta aprobadas para los seguimientos automáticos: qué plantilla corresponde a qué estado y a qué día de la instancia de seguimiento.',
            'columnas'        => [
                'id', 'estado', 'dia_numero', 'template_name', 'body_template',
                'language_code', 'activa', 'solo_si_ingreso_confirmado',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['template_name', 'body_template', 'estado'],
            'filtros'         => [
                /* 🔴 ACÁ NO VA UNA ENUMERACIÓN CERRADA, Y ES LA ASIMETRÍA DELIBERADA CON
                   followup_rule. `followup_templates.estado` mezcla slugs del pipeline con
                   centinelas que NO son estados de ningún lead y que existen a propósito: los
                   seeders cargan `manual_recuperacion`, `manual_check_demo` y `recordatorio`
                   además de nuevo / contactado / calificado / demo_agendada / demo_realizada /
                   mail2_enviado (verificado el 3/9/2026 en FollowupTemplatesSeeder,
                   FollowupTemplatesRecordatorioSeeder y en FollowupTemplate::getCategoriaAttribute()).
                   Declarar `en` con los slugs del pipeline haría 422 sobre valores que son válidos
                   y que están cargados hoy. */
                'estado'                     => ['columna' => 'estado',                     'tipo' => 'texto_exacto'],
                'template_name'              => ['columna' => 'template_name',              'tipo' => 'texto_exacto'],
                'dia_numero'                 => ['columna' => 'dia_numero',                 'tipo' => 'entero'],
                'activa'                     => ['columna' => 'activa',                     'tipo' => 'booleano'],
                'solo_si_ingreso_confirmado' => ['columna' => 'solo_si_ingreso_confirmado', 'tipo' => 'booleano'],
                'language_code'              => ['columna' => 'language_code',              'tipo' => 'texto_exacto'],
                'ids'                        => ['columna' => 'id',                         'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => 'Los dos seguimientos de demo_agendada se separan por `solo_si_ingreso_confirmado`, no por el estado: mismo `estado`, dos ramas. Para escribir una plantilla está POST claude/followup-templates, que es idempotente por `template_name` y también actualiza `activa` y `body_template` de una fila que ya existe.',
        ],

        /* ---------------------------------------------------------------
         | lead_call — la llamada del closer con un lead.
         |
         | 🔴 SIN `transcript`: es un longText con la conversación ENTERA entre
         | dos personas reales, palabra por palabra. Es lo peor de los dos
         | mundos —volumen y datos de una persona— y no hay ninguna lectura
         | operativa que lo necesite.
         |
         | `call_summary` (el resumen estructurado que extrae Claude) y `meet_url`
         | van opt-in, por dos motivos distintos: el resumen sigue siendo el
         | contenido de una conversación privada, y el link de Meet ABRE LA
         | LLAMADA a cualquiera que lo tenga, sin auth ninguna. Ninguno de los
         | dos hace falta para saber si una llamada existe y en qué estado está.
         --------------------------------------------------------------- */
        'lead_call' => [
            'tabla'           => 'lead_calls',
            'descripcion'     => 'Llamadas del closer con un lead: cuándo se agendó, cuándo arrancó, y si ya llegó la transcripción de Recall.ai.',
            'columnas'        => [
                'id', 'lead_id', 'google_event_id', 'recall_bot_id', 'estado',
                'scheduled_at', 'started_at', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'resumen' => ['call_summary'],
                'agenda'  => ['meet_url'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'lead_id'         => ['columna' => 'lead_id',         'tipo' => 'entero'],
                /* 🔴 Enumeración REAL, verificada el 3/9/2026 sobre los únicos dos lugares que la
                   escriben: LeadCallService (tres `create` con 'pendiente') y CallSummaryService
                   línea 140 ('completada', al llegar la transcripción). No hay 'cancelada' ni
                   'en_curso': no existen en el proyecto. */
                'estado'          => ['columna' => 'estado',          'tipo' => 'en', 'valores' => ['pendiente', 'completada']],
                'recall_bot_id'   => ['columna' => 'recall_bot_id',   'tipo' => 'texto_exacto'],
                'google_event_id' => ['columna' => 'google_event_id', 'tipo' => 'texto_exacto'],
                'sin_agendar'     => ['columna' => 'scheduled_at',    'tipo' => 'nulo'],
                'agendada_desde'  => ['columna' => 'scheduled_at',    'tipo' => 'fecha_desde'],
                'agendada_hasta'  => ['columna' => 'scheduled_at',    'tipo' => 'fecha_hasta'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
                'creado_desde'    => ['columna' => 'created_at',      'tipo' => 'fecha_desde'],
                'creado_hasta'    => ['columna' => 'created_at',      'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'socios' => [
                    'tipo'        => 'has_many', 'tabla' => 'lead_partners',
                    'clave_local' => 'id', 'clave_externa' => 'lead_call_id',
                    /* Sin `phone` ni `notes`: mismo criterio que la entrada lead_partner. Una
                       relación filtra tan fácil como la tabla principal. */
                    'columnas'    => ['id', 'lead_id', 'name', 'source', 'pending_confirmation'],
                    'limite'      => 10,
                ],
            ],
            'nota' => 'Un lead puede tener N llamadas: las columnas viejas de `leads` (meet_url, recall_bot_id, call_summary) quedaron por compatibilidad y NO son la fuente. estado="pendiente" significa creada sin transcripción todavía, no fallida.',
        ],

        /* ---------------------------------------------------------------
         | lead_demo_hito — el roadmap de la demo de un lead, hito por hito.
         |
         | Entra entera: son marcas de avance sobre NUESTRA demo (qué clip vio,
         | qué acción hizo). Nada de lo que hay acá es un dato de la persona.
         --------------------------------------------------------------- */
        'lead_demo_hito' => [
            'tabla'           => 'lead_demo_hitos',
            'descripcion'     => 'Hitos del roadmap de la demo de un lead: qué tutorial vio, qué acción hizo, y dónde se trabó.',
            'columnas'        => [
                'id', 'lead_id', 'orden', 'tipo', 'seccion', 'clip_id', 'titulo',
                'evento_esperado', 'estado', 'tutorial_visto_at', 'accion_hecha_at',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['titulo', 'seccion', 'clip_id'],
            'filtros'         => [
                'lead_id'         => ['columna' => 'lead_id',         'tipo' => 'entero'],
                /* Verificado en LeadDemoHito::TIPO_INGRESO y ::TIPO_TUTORIAL. Son dos. */
                'tipo'            => ['columna' => 'tipo',            'tipo' => 'en', 'valores' => ['ingreso', 'tutorial']],
                /* Verificado en LeadDemoHito::PESO_ESTADOS, que además es lo que hace verificable la
                   regla de que un hito nunca retrocede: se compara la posición, no el orden en que
                   llegan los eventos. */
                'estado'          => ['columna' => 'estado',          'tipo' => 'en', 'valores' => ['pendiente', 'parcial', 'completo']],
                /* `seccion`, `clip_id` y `evento_esperado` salen del plan de demo congelado
                   (DemoHitosService los copia de la sección y del clip), así que NO son una
                   enumeración del código: cambian con el plan. Van como texto exacto a propósito. */
                'seccion'         => ['columna' => 'seccion',         'tipo' => 'texto_exacto'],
                'clip_id'         => ['columna' => 'clip_id',         'tipo' => 'texto_exacto'],
                'evento_esperado' => ['columna' => 'evento_esperado', 'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 estado="parcial" es el dato que vale: vio el tutorial y NO llegó el evento de negocio esperado. Ahí es exactamente donde el lead se trabó en la demo.',
        ],

        /* ---------------------------------------------------------------
         | lead_partner — el socio del lead que apareció en la llamada o en
         | WhatsApp.
         |
         | 🔴 `phone` es de una persona que ni siquiera es el lead: va opt-in,
         | mismo criterio que `client.phone` y `client_employee.phone`.
         |
         | 🔴 `notes` también sale de la proyección base: es texto libre que
         | escribió el closer SOBRE otra persona. `client_employee.notes`
         | directamente no se expone por este mismo motivo; acá va opt-in porque
         | en el panel del closer es la mitad del valor de la fila.
         --------------------------------------------------------------- */
        'lead_partner' => [
            'tabla'           => 'lead_partners',
            'descripcion'     => 'Socios de un lead detectados en la llamada o en WhatsApp, y si el closer ya los confirmó.',
            'columnas'        => [
                'id', 'lead_id', 'lead_call_id', 'name', 'source',
                'pending_confirmation', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto' => ['phone'],
                'notas'    => ['notes'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['name'],
            'filtros'         => [
                'lead_id'              => ['columna' => 'lead_id',              'tipo' => 'entero'],
                'lead_call_id'         => ['columna' => 'lead_call_id',         'tipo' => 'entero'],
                /* 🔴 Enumeración REAL, verificada el 3/9/2026 sobre los tres únicos escritores:
                   'call_transcript' (CallSummaryService línea 172), 'whatsapp_suggestion'
                   (LeadAiService línea 6281) y 'manual', que es el default de la columna y lo que
                   queda cuando lo carga el closer a mano. */
                'source'               => ['columna' => 'source',               'tipo' => 'en', 'valores' => ['manual', 'call_transcript', 'whatsapp_suggestion']],
                'pending_confirmation' => ['columna' => 'pending_confirmation', 'tipo' => 'booleano'],
                'sin_llamada'          => ['columna' => 'lead_call_id',         'tipo' => 'nulo'],
                'ids'                  => ['columna' => 'id',                   'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'llamada' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'lead_calls',
                    'clave_local' => 'lead_call_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'lead_id', 'estado', 'scheduled_at', 'started_at'],
                ],
            ],
            'nota' => 'pending_confirmation=true es un socio SUGERIDO por la IA que el closer todavía no confirmó: no lo leas como un socio real. lead_call_id nulo son los históricos previos al refactor de múltiples llamadas, o los cargados a mano.',
        ],

        /* ---------------------------------------------------------------
         | lead_pipeline_status — el catálogo de estados del pipeline.
         |
         | Entra entera: siete columnas de catálogo, sin nada de nadie. Es la
         | tabla que hace verificables a las enumeraciones de estado del resto
         | de este bloque, en vez de que haya que creerles.
         --------------------------------------------------------------- */
        'lead_pipeline_status' => [
            'tabla'           => 'lead_pipeline_statuses',
            'descripcion'     => 'Catálogo de estados del pipeline comercial: slug, etiqueta, color del badge y orden de la grilla.',
            'columnas'        => ['id', 'slug', 'label', 'color', 'sort_order', 'created_at', 'updated_at'],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['slug', 'label'],
            'filtros'         => [
                /* Texto exacto y NO una enumeración cerrada: esta tabla ES la enumeración. Fijarle
                   una lista al filtro sería declararla dos veces, y la copia se desactualiza sola. */
                'slug' => ['columna' => 'slug', 'tipo' => 'texto_exacto'],
                'ids'  => ['columna' => 'id',   'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 ÉSTA es la fuente de verdad viva de los slugs del pipeline: LeadPipelineStatus::all_slugs() lee esta tabla y sólo cae a la constante DEFAULT_STATUSES si está vacía, y ensure_exists() puede dar de alta un slug nuevo en runtime con el estado que devolvió Claude. Si un filtro `en` de otro modelo te rechaza un estado, consultá acá antes de asumir que ese estado no existe. El orden de la grilla es sort_order, no id. Y ojo con `mail2_enviado`: sigue en el catálogo por historia pero Lucas lo dejó de usar (SLUGS_HIDDEN_FROM_SELECT).',
        ],

        /* ---------------------------------------------------------------
         | lead_personalized_demo_video — los videos que van en el "Mail 1 - DEMO".
         |
         | `video_url` entra: es un link a un tutorial NUESTRO (Loom/YouTube), no
         | un recurso del lead.
         |
         | 🔴 `comments` sale de la base y va opt-in: es el brief interno del
         | equipo sobre ese lead en particular —texto libre escrito por una
         | persona sobre otra—, y el propio modelo aclara que no se envía en el
         | mail. Mismo criterio que `lead_partner.notes`.
         --------------------------------------------------------------- */
        'lead_personalized_demo_video' => [
            'tabla'           => 'lead_personalized_demo_videos',
            'descripcion'     => 'Videos tutoriales personalizados que se le muestran a un lead en el mail de la demo, con su orden.',
            'columnas'        => [
                'id', 'uuid', 'lead_id', 'title', 'description', 'video_url',
                'sort_order', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'notas' => ['comments'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['title', 'description'],
            'filtros'         => [
                'lead_id'      => ['columna' => 'lead_id',    'tipo' => 'entero'],
                'ids'          => ['columna' => 'id',         'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at', 'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at', 'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => 'Dentro del mail se ordenan por sort_order, no por id. El orden por defecto de este endpoint es por id: si querés el orden real del mail, traé la página entera de un lead y ordenala vos por sort_order.',
        ],

        /* ---------------------------------------------------------------
         | lead_message_attachment — el archivo que mandó un lead por WhatsApp.
         |
         | 🔴 SIN `path`, y no es por volumen: WhatsappInboundMediaService guarda
         | estos archivos con `Storage::disk('public')` y escribe `disk` =
         | 'public' (líneas 236-240 y 301-305). O sea que el valor de `path` es
         | exactamente lo que falta para armar /storage/<path>, que sirve el
         | archivo SIN pasar por auth. El camino legítimo es la URL firmada que
         | arma el accessor `public_url` del modelo, que caduca; publicar el path
         | la saltearía entera. Y estamos hablando de los audios, las fotos y los
         | documentos que mandó una persona real.
         |
         | 🔴 `original_filename` va opt-in: es el nombre que le puso el lead a SU
         | archivo ("dni-juan.pdf", "factura de mi negocio.pdf"), o sea un dato de
         | la persona metido en el nombre.
         |
         | ⚠️ Los tres atributos derivados del modelo (`public_url`,
         | `display_filename`, `download_url`) no viajan por acá: son `$appends` y
         | este endpoint usa DB::table, sin modelo Eloquent.
         --------------------------------------------------------------- */
        'lead_message_attachment' => [
            'tabla'           => 'lead_message_attachments',
            'descripcion'     => 'Adjuntos de los mensajes de un lead: de qué mensaje cuelgan, de qué tipo son y cuánto pesan. Sin la ruta al archivo.',
            'columnas'        => [
                'id', 'lead_message_id', 'disk', 'mime', 'size',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'archivo' => ['original_filename'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'lead_message_id' => ['columna' => 'lead_message_id', 'tipo' => 'entero'],
                /* `mime` es lo que declaró Meta en el webhook (audio/ogg, image/jpeg,
                   application/pdf...): no hay ninguna constante del proyecto que lo acote, así que
                   una lista cerrada acá sería inventada. */
                'mime'            => ['columna' => 'mime',            'tipo' => 'texto_exacto'],
                'disk'            => ['columna' => 'disk',            'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
                'creado_desde'    => ['columna' => 'created_at',      'tipo' => 'fecha_desde'],
                'creado_hasta'    => ['columna' => 'created_at',      'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => 'Esto dice QUE hay un adjunto y de qué tipo, no cómo abrirlo: la ruta al archivo no se sirve a propósito (ver el comentario de arriba). Para el mensaje que lo trajo está GET claude/messages.',
        ],

        /* ---------------------------------------------------------------
         | message_variant — las variantes de mensaje del A/B testing de
         | bienvenida, con sus contadores.
         |
         | Entra entera: `body` es NUESTRO copy y `notes` son notas sobre la
         | variante, no sobre una persona. Es una tabla chica (un puñado de
         | filas) y el cuerpo es justamente lo que se compara.
         --------------------------------------------------------------- */
        'message_variant' => [
            'tabla'           => 'message_variants',
            'descripcion'     => 'Variantes de mensaje para el A/B testing de la bienvenida, con los contadores de enviados, respondidos, agendados y asistidos.',
            'columnas'        => [
                'id', 'slug', 'name', 'message_type', 'body', 'delay_seconds',
                'active', 'sent_count', 'responded_count', 'scheduled_count',
                'attended_count', 'notes', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['slug', 'name', 'body'],
            'filtros'         => [
                'slug'         => ['columna' => 'slug',         'tipo' => 'texto_exacto'],
                /* Texto exacto y NO enumeración: MessageVariantController lo valida como
                   `string|max:40`, la migración le pone default 'welcome_with_name' y
                   MessageVariantSeeder carga 'welcome'. No hay lista cerrada en ningún lado, así que
                   la que escribiera acá me la estaría inventando. Mismo criterio que
                   admin_task.created_via. */
                'message_type' => ['columna' => 'message_type', 'tipo' => 'texto_exacto'],
                'active'       => ['columna' => 'active',       'tipo' => 'booleano'],
                'ids'          => ['columna' => 'id',           'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => 'Los cuatro contadores son acumulados que se incrementan en vivo (increment_sent, increment_responded, ...), no un snapshot de un período: las tasas las calcula el agente analizador a partir de ellos. pick_active_variant() elige AL AZAR entre las activas del mismo message_type.',
        ],

        /* ---------------------------------------------------------------
         | whatsapp_ad_referral — qué aviso de Meta trajo a cada teléfono
         | (Click-to-WhatsApp).
         |
         | 🔴 SIN `raw`: es el json crudo del bloque `referral` de Meta, o sea el
         | mismo contenido que ya está desarmado en las otras columnas, más el
         | peso. Duplicado y volumen, las dos cosas.
         |
         | 🔴 `phone` y `wamid` viajan JUNTOS en el opt-in de contacto, y el
         | segundo no es un exceso: el wamid de Meta no es un id opaco, su parte
         | base64 lleva adentro el número del que escribió. Dejarlo en la
         | proyección base sería devolver el teléfono por la ventana de al lado
         | mientras la puerta está cerrada.
         |
         | `ctwa_clid` va en su propio opt-in: es el click id con el que Meta
         | atribuye la conversión. Sirve para depurar atribución y para nada más.
         --------------------------------------------------------------- */
        'whatsapp_ad_referral' => [
            'tabla'           => 'whatsapp_ad_referrals',
            'descripcion'     => 'Clics en anuncios Click-to-WhatsApp: qué aviso, qué creatividad y cuándo, para atribuir de dónde salió cada lead.',
            'columnas'        => [
                'id', 'source_id', 'source_type', 'source_url', 'headline',
                'body', 'media_type', 'thumbnail_url', 'received_at',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto'   => ['phone', 'wamid'],
                'atribucion' => ['ctwa_clid'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['headline', 'body', 'source_url'],
            'filtros'         => [
                'source_id'      => ['columna' => 'source_id',   'tipo' => 'texto_exacto'],
                /* 🔴 `source_type` y `media_type` llegan CRUDOS del bloque `referral` de Meta
                   (MetaRawWebhookController, líneas 262 y 266) y no hay ninguna constante del
                   proyecto que los acote. Una lista cerrada acá sería la documentación de Meta
                   copiada de memoria: si Meta manda un valor más, el filtro daría 422 sobre filas
                   que existen. Por eso van como texto exacto. */
                'source_type'    => ['columna' => 'source_type', 'tipo' => 'texto_exacto'],
                'media_type'     => ['columna' => 'media_type',  'tipo' => 'texto_exacto'],
                'recibido_desde' => ['columna' => 'received_at', 'tipo' => 'fecha_desde'],
                'recibido_hasta' => ['columna' => 'received_at', 'tipo' => 'fecha_hasta'],
                'ids'            => ['columna' => 'id',          'tipo' => 'lista_de_enteros'],
                'creado_desde'   => ['columna' => 'created_at',  'tipo' => 'fecha_desde'],
                'creado_hasta'   => ['columna' => 'created_at',  'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 NO tiene lead_id, y es a propósito: el referral llega ANTES de que el lead exista y el vínculo es por teléfono normalizado. Para cruzarlo con un lead hay que pedir include=contacto y matchear el teléfono a mano. Y ojo: sólo el webhook CRUDO de Meta trae este bloque; el payload procesado de Kapso no lo incluye, así que una fila que falta puede ser eso y no que el lead no vino de un aviso.',
        ],

        /* ---------------------------------------------------------------
         | agent_proposal — lo que el agente analizador propone y Lucas aprueba
         | o rechaza.
         |
         | 🔴 SIN `accion_payload`, Y ÉSTE ES EL ÚNICO SECRETO DE VERDAD DE TODO
         | ESTE BLOQUE. Para una propuesta de tipo `cambiar_setting`,
         | AgentProposal::apply() hace `AdminSetting::set($payload['key'],
         | $payload['value'])`: o sea que el payload lleva el VALOR de una
         | admin_setting. Y `admin_settings.value` ya está en `modelos_excluidos`
         | de este mismo archivo por guardar implementation_google_api_key_default
         | e implementation_google_api_key_demo. Servir el payload sería servir
         | exactamente la columna que este archivo dice que no sirve, con otro
         | nombre encima. Encima NO matchea `columnas_prohibidas` (se llama
         | `accion_payload`), así que la única reja que lo ataja es no escribirlo
         | acá.
         |
         | `razonamiento` y `datos_de_soporte` van opt-in: es el análisis largo
         | del agente sobre leads concretos, y en un listado de 100 propuestas
         | pesa de verdad.
         --------------------------------------------------------------- */
        'agent_proposal' => [
            'tabla'           => 'agent_proposals',
            'descripcion'     => 'Propuestas del agente analizador: qué propone, de qué reporte salió y si se aprobó o se rechazó. Sin el payload de la acción.',
            'columnas'        => [
                'id', 'report_id', 'tipo', 'descripcion', 'estado',
                'aprobada_at', 'rechazada_at', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'detalle' => ['razonamiento', 'datos_de_soporte'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['descripcion'],
            'filtros'         => [
                'report_id'    => ['columna' => 'report_id',  'tipo' => 'entero'],
                /* Texto exacto: AgentProposalController lo valida como `string|max:40`, o sea que es
                   abierto. Los tres que AgentProposal::apply() sabe ejecutar son 'cambiar_setting',
                   'desactivar_variante' y 'nueva_variante'; cualquier otro cae en el `default` del
                   switch. */
                'tipo'         => ['columna' => 'tipo',       'tipo' => 'texto_exacto'],
                /* 🔴 Enumeración REAL, verificada el 3/9/2026 sobre los tres únicos escritores:
                   'pendiente' (AgentProposalController línea 85, al crear), 'rechazada' (línea 137)
                   y 'aprobada' (AgentProposal::apply()). No hay 'aplicada' ni 'fallida'. */
                'estado'       => ['columna' => 'estado',     'tipo' => 'en', 'valores' => ['pendiente', 'aprobada', 'rechazada']],
                'ids'          => ['columna' => 'id',         'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at', 'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at', 'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'reporte' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'agent_daily_reports',
                    'clave_local' => 'report_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'report_date', 'report_type', 'alert_count', 'active_leads_count'],
                ],
            ],
            'nota' => '🔴 Una propuesta de tipo desconocido igual queda en estado "aprobada" aunque apply() no haya hecho nada: el switch tiene un `default: break` y después marca aprobada igual. Leer estado="aprobada" NO alcanza para saber que la acción se ejecutó.',
        ],

        /* ---------------------------------------------------------------
         | agent_daily_report — el reporte diario/semanal del agente analizador.
         |
         | `file_path` entra: es una ruta dentro del disco `local` de Laravel
         | (GenerateDailyAgentReportCommand usa Storage::exists / Storage::delete
         | sin disco explícito, y filesystems.default es 'local'), que NO está
         | publicado por HTTP. El markdown se baja por GET agent-report/{id}/download,
         | detrás de auth. Es el caso opuesto a lead_message_attachments.path, que
         | sí abriría el archivo: por eso uno entra y el otro no.
         |
         | `metrics_snapshot` va opt-in por volumen: es el json entero de métricas
         | del día.
         --------------------------------------------------------------- */
        'agent_daily_report' => [
            'tabla'           => 'agent_daily_reports',
            'descripcion'     => 'Reportes diarios y semanales del agente analizador: resumen ejecutivo, cantidad de alertas y de leads activos.',
            'columnas'        => [
                'id', 'report_date', 'report_type', 'file_path',
                'executive_summary', 'alert_count', 'active_leads_count',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'metricas' => ['metrics_snapshot'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['executive_summary'],
            'filtros'         => [
                /* Enumeración de MySQL, leída de information_schema el 3/9/2026:
                   enum('daily','weekly'). No es un varchar: son exactamente esos dos. */
                'report_type'  => ['columna' => 'report_type', 'tipo' => 'en', 'valores' => ['daily', 'weekly']],
                'fecha_desde'  => ['columna' => 'report_date', 'tipo' => 'fecha_desde'],
                'fecha_hasta'  => ['columna' => 'report_date', 'tipo' => 'fecha_hasta'],
                'sin_archivo'  => ['columna' => 'file_path',   'tipo' => 'nulo'],
                'ids'          => ['columna' => 'id',          'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at',  'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at',  'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'propuestas' => [
                    'tipo'        => 'has_many', 'tabla' => 'agent_proposals',
                    'clave_local' => 'id', 'clave_externa' => 'report_id',
                    /* Sin accion_payload, por lo mismo que en la entrada agent_proposal. */
                    'columnas'    => ['id', 'tipo', 'descripcion', 'estado', 'aprobada_at', 'rechazada_at'],
                    'limite'      => 20,
                ],
            ],
            'nota' => 'fecha_desde/fecha_hasta filtran por `report_date` (el día que el reporte cubre); creado_desde/creado_hasta, por cuándo se generó la fila. No son lo mismo cuando un reporte se regenera a mano.',
        ],

        /* ---------------------------------------------------------------
         | agent_identity — la identidad del agente de ventas (Martín) que Claude
         | usa en WhatsApp.
         |
         | `description` va opt-in y no en la proyección base: es el texto entero
         | de la identidad, que se inyecta como encabezado del system prompt en
         | CADA llamada a la API de Anthropic. No es un secreto —es nuestro— pero
         | pesa, y en un listado no lo necesita nadie: para saber cuál está activa
         | alcanza con `name` y `activa`.
         --------------------------------------------------------------- */
        'agent_identity' => [
            'tabla'           => 'agent_identities',
            'descripcion'     => 'Identidades del agente de ventas que Claude usa en WhatsApp. Sólo una está activa a la vez.',
            'columnas'        => ['id', 'name', 'activa', 'created_at', 'updated_at'],
            'columnas_opt_in' => [
                'prompt' => ['description'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 50,
            'limite_max'      => 100,
            'busqueda'        => ['name'],
            'filtros'         => [
                'activa' => ['columna' => 'activa', 'tipo' => 'booleano'],
                'ids'    => ['columna' => 'id',     'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 ESTA TABLA NO SE ESCRIBE POR API, Y NO ES UN OLVIDO: AgentPromptSyncService la PISA cada 10 minutos con el contenido de `agentes/lead/identidad.md` del repo de conocimiento. Un POST acá sería una escritura que desaparece sola sin que nada lo denuncie. El camino de escritura es commitear al repo; este endpoint existe justamente para verificar QUÉ quedó sincronizado.',
        ],

        /* ---------------------------------------------------------------
         | ai_system_prompt — el esqueleto del system prompt de Claude.
         |
         | 🔴 `contenido` es un longText con el system prompt ENTERO y va opt-in,
         | nunca en la proyección base: en un listado de 50 filas serían 50 system
         | prompts completos para una consulta que casi siempre quiere saber una
         | sola cosa (cuál está activo y de cuándo es).
         --------------------------------------------------------------- */
        'ai_system_prompt' => [
            'tabla'           => 'ai_system_prompts',
            'descripcion'     => 'Versiones del system prompt de Claude para el agente de leads. Sólo una está activa a la vez.',
            'columnas'        => ['id', 'descripcion', 'activa', 'created_at', 'updated_at'],
            'columnas_opt_in' => [
                'prompt' => ['contenido'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 50,
            'limite_max'      => 100,
            'busqueda'        => ['descripcion'],
            'filtros'         => [
                'activa'       => ['columna' => 'activa',     'tipo' => 'booleano'],
                'ids'          => ['columna' => 'id',         'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at', 'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at', 'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 Mismo caso que agent_identity: AgentPromptSyncService lo pisa cada 10 minutos desde `agentes/lead/instrucciones_operativas.md`, así que no hay endpoint de escritura a propósito. Además el modelo fuerza un solo activo desde un hook saving(): al marcar uno activo, desactiva a los demás. Para leer el prompt completo hace falta include=prompt.',
        ],

        /* ---------------------------------------------------------------
         | protocol_entry — las entradas del protocolo de ventas que Claude
         | consume al sugerir respuestas.
         |
         | 🔴 `mensaje_template` y `notas_setter` salen de la proyección base, y
         | el motivo no es el peso: LeadSuggestionSendService::record_setter_correction()
         | crea entradas automáticas donde `mensaje_template` es EL MENSAJE QUE EL
         | SETTER LE MANDÓ A UN LEAD REAL, y `notas_setter` es "Mensaje original
         | de Claude: ..." de esa misma conversación. O sea que en esas filas las
         | dos columnas son contenido de una conversación con una persona, no una
         | plantilla genérica. Van opt-in, mismo criterio que el resto de la PII
         | de este archivo.
         --------------------------------------------------------------- */
        'protocol_entry' => [
            'tabla'           => 'protocol_entries',
            'descripcion'     => 'Entradas del protocolo de ventas que Claude lee al sugerir respuestas: categoría, a qué estado del lead aplican y si están activas.',
            'columnas'        => [
                'id', 'categoria', 'estado_aplicable', 'followup_numero',
                'titulo', 'descripcion', 'activa', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'texto' => ['mensaje_template', 'notas_setter'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['titulo', 'descripcion'],
            'filtros'         => [
                /* Enumeración REAL, verificada en ProtocolEntryProperties::all(), que es el esquema
                   declarativo que gobierna el ABM del protocolo en admin-spa: son tres. */
                'categoria'        => ['columna' => 'categoria',        'tipo' => 'en', 'valores' => ['etapa_principal', 'seguimiento', 'situacion_frecuente']],
                /* 🔴 Los 15 slugs de LeadPipelineStatus::DEFAULT_STATUSES, NO los 6 del select de
                   admin-spa. El select de ProtocolEntryProperties ofrece seis, pero
                   LeadSuggestionSendService escribe acá `$lead->status` tal cual, que puede ser
                   CUALQUIER estado del pipeline: cerrar la lista en los seis del select dejaría
                   afuera filas que existen.
                   ⚠️ La fuente viva sigue siendo `model=lead_pipeline_status`: ensure_exists() puede
                   dar de alta un slug nuevo en runtime. Si un valor da 422, consultá ahí. */
                'estado_aplicable' => ['columna' => 'estado_aplicable', 'tipo' => 'en', 'valores' => ['nuevo', 'contactado', 'calificado', 'solicita_disponibilidad', 'demo_agendada', 'ingresando_demo', 'demo_en_curso', 'demo_pendiente_de_ingreso', 'demo_pendiente_de_terminar', 'demo_realizada', 'closer_activo', 'mail2_enviado', 'cerrado_ganado', 'cerrado_perdido', 'en_pausa']],
                /* La columna es nullable y ese null significa "aplica a todos los estados", que es
                   una consulta distinta de filtrar por uno. */
                'sin_estado'       => ['columna' => 'estado_aplicable', 'tipo' => 'nulo'],
                'followup_numero'  => ['columna' => 'followup_numero',  'tipo' => 'entero'],
                'activa'           => ['columna' => 'activa',           'tipo' => 'booleano'],
                'ids'              => ['columna' => 'id',               'tipo' => 'lista_de_enteros'],
                'creado_desde'     => ['columna' => 'created_at',       'tipo' => 'fecha_desde'],
                'creado_hasta'     => ['columna' => 'created_at',       'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => 'A diferencia de agent_identity y ai_system_prompt, ESTA tabla sí se escribe: no la sincroniza nadie desde GitHub (tiene ABM propio en ProtocolEntryController). Ojo con las entradas de categoría "situacion_frecuente" con activa=false: muchas son correcciones automáticas del setter guardadas para revisar, no protocolo aprobado.',
        ],

        /* ---------------------------------------------------------------
         | support_ticket — la bandeja de soporte, por el ERP y por WhatsApp.
         |
         | 🔴 SIN client_user_email NI whatsapp_phone en las columnas base: son
         | el mail y el teléfono de una persona del cliente, o sea PII. Viajan
         | sólo con include=contacto, mismo criterio que `clients.phone` y
         | `client_employees.phone`. El comentario de `columnas_prohibidas` ya
         | nombra `support_tickets.client_user_email` como el caso testigo de que
         | la reja automática NO cubre PII: acá está la defensa que sí la cubre.
         |
         | ⚠️ `client_user_name` SÍ va en las base, y es deliberado, no un
         | descuido: el proyecto trata el nombre distinto del identificador de
         | contacto (`client_employees.name` está en base y `client_employees.phone`
         | es opt-in). Sin el nombre no se puede leer una bandeja.
         |
         | `ai_pending_suggestion` va opt-in aparte: es el CUERPO del mensaje que
         | el agente tiene listo para mandarle a ese cliente, no metadato del
         | ticket, y en una página de 100 filas son 100 mensajes enteros. Para
         | saber si hay una pendiente alcanzan `ai_suggestion_send_at` y el filtro
         | `sin_sugerencia_pendiente`, que están en base.
         --------------------------------------------------------------- */
        'support_ticket' => [
            'tabla'           => 'support_tickets',
            'descripcion'     => 'Tickets de soporte de un cliente, abiertos desde el ERP o desde WhatsApp: estado, asignación, escalado y los dos interruptores del agente.',
            'columnas'        => [
                'id', 'uuid', 'client_id', 'client_employee_id', 'client_user_id',
                'client_user_name', 'assigned_admin_id', 'name', 'status', 'source',
                'opened_at', 'closed_at', 'last_client_message_at',
                'ai_suggestion_send_at', 'alert_sent_at',
                'escalated_at', 'escalation_reason',
                'claude_auto_reply', 'requiere_verificacion_mensajes',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto'   => ['client_user_email', 'whatsapp_phone'],
                'sugerencia' => ['ai_pending_suggestion'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['name', 'client_user_name', 'escalation_reason'],
            'filtros'         => [
                'client_id'                      => ['columna' => 'client_id',                      'tipo' => 'entero'],
                'client_employee_id'             => ['columna' => 'client_employee_id',             'tipo' => 'entero'],
                'assigned_admin_id'              => ['columna' => 'assigned_admin_id',              'tipo' => 'entero'],
                'sin_asignar'                    => ['columna' => 'assigned_admin_id',              'tipo' => 'nulo'],
                /* 🔴 Enumeración REAL, verificada el 3/9/2026 en el código y no en la base: la
                   columna es un varchar(20) sin enum de MySQL. El default de la migración es 'open'
                   (2026_04_25_141000_create_support_tickets_table) y el único otro valor que el
                   proyecto escribe es 'closed' (SendSupportAiSuggestion, cierre automático); todas
                   las comparaciones del código son contra esos dos.
                   ⚠️ SupportTicketController::update() toma el `status` del SPA sin lista blanca, así
                   que un tercer valor en la base sería un dato roto, no un estado del proyecto. */
                'status'                         => ['columna' => 'status',                         'tipo' => 'en', 'valores' => ['open', 'closed']],
                /* Enumeración de MySQL, leída de information_schema: enum('erp','whatsapp'). */
                'source'                         => ['columna' => 'source',                         'tipo' => 'en', 'valores' => ['erp', 'whatsapp']],
                'sin_escalar'                    => ['columna' => 'escalated_at',                   'tipo' => 'nulo'],
                'sin_sugerencia_pendiente'       => ['columna' => 'ai_pending_suggestion',          'tipo' => 'nulo'],
                'claude_auto_reply'              => ['columna' => 'claude_auto_reply',              'tipo' => 'booleano'],
                'requiere_verificacion_mensajes' => ['columna' => 'requiere_verificacion_mensajes', 'tipo' => 'booleano'],
                'ids'                            => ['columna' => 'id',                             'tipo' => 'lista_de_enteros'],
                'creado_desde'                   => ['columna' => 'created_at',                     'tipo' => 'fecha_desde'],
                'creado_hasta'                   => ['columna' => 'created_at',                     'tipo' => 'fecha_hasta'],
                'ultimo_mensaje_desde'           => ['columna' => 'last_client_message_at',         'tipo' => 'fecha_desde'],
                'ultimo_mensaje_hasta'           => ['columna' => 'last_client_message_at',         'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active'],
                ],
                'mensajes' => [
                    'tipo'        => 'has_many', 'tabla' => 'support_messages',
                    'clave_local' => 'id', 'clave_externa' => 'support_ticket_id',
                    'columnas'    => ['id', 'sender_type', 'kind', 'delivered_at', 'read_at', 'created_at'],
                    'limite'      => 20,
                ],
            ],
            'nota' => '🔴 El include `mensajes` trae los PRIMEROS 20 por id, o sea el ARRANQUE del hilo y no lo último: el has_many ordena por id ascendente y recorta en memoria. Para el tramo reciente consultá model=support_message con support_ticket_id y order=desc. Y no trae el texto: el cuerpo de cada mensaje vive en support_message con include=cuerpo.',
        ],

        /* ---------------------------------------------------------------
         | support_message — cada mensaje de un ticket.
         |
         | ⚠️ `body` es longText y es la conversación entera con un cliente: va
         | OPT-IN (include=cuerpo), no en las columnas base. El motivo es de
         | volumen y no de secreto — una página de 200 mensajes con el cuerpo
         | adentro es una respuesta de megabytes, y la mayoría de las consultas
         | sobre esta tabla son de forma ("cuántos sin leer", "quién contestó
         | último"), no de contenido. Es el mismo criterio con el que
         | `admin_task` dejó `content` afuera.
         |
         | Los dos borradores del agente van en su propio include: son la
         | trazabilidad de la sugerencia, no el mensaje que se mandó.
         | `ai_original_body` es el texto tal como lo escribió el agente antes de
         | que un humano lo edite; `ai_body_before_template` es el texto antes de
         | envolverlo en la plantilla aprobada de Meta.
         --------------------------------------------------------------- */
        'support_message' => [
            'tabla'           => 'support_messages',
            'descripcion'     => 'Mensajes de un ticket de soporte: quién lo mandó, de qué tipo es y en qué estado de entrega quedó. El cuerpo viaja sólo con include=cuerpo.',
            'columnas'        => [
                'id', 'uuid', 'support_ticket_id',
                'sender_type', 'sender_admin_id', 'sender_admin_uuid', 'kind',
                'is_ai_suggestion_draft', 'ai_auto_send_at', 'ai_generated_at',
                'delivered_at', 'read_at', 'synced_to_client_at',
                'remote_delivery_status', 'whatsapp_message_id',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'cuerpo'        => ['body'],
                'borradores_ia' => ['ai_original_body', 'ai_body_before_template'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            /* ⚠️ La búsqueda por `q` pega sobre `body` AUNQUE no se haya pedido include=cuerpo: el
               LIKE es un where, no una columna de la proyección. O sea que se puede confirmar que un
               texto está en un mensaje sin verlo. Se deja así a propósito —buscar por cualquier otra
               columna de esta tabla no sirve para nada— pero que quede escrito. */
            'busqueda'        => ['body'],
            'filtros'         => [
                'support_ticket_id'      => ['columna' => 'support_ticket_id',      'tipo' => 'entero'],
                'sender_admin_id'        => ['columna' => 'sender_admin_id',        'tipo' => 'entero'],
                /* Enumeración REAL, verificada el 3/9/2026 sobre TODOS los puntos que escriben la
                   tabla (SupportMessageController, InboundSupportMessageController,
                   WhatsappWebhookController, SupportTemplateSendService, SupportWhatsappOpenerService
                   y los tres servicios de sugerencia): son dos, 'admin' y 'user'. No existe 'system'
                   ni 'claude' — un mensaje que escribió el agente se guarda como 'admin' y se
                   distingue por `ai_generated_at`. */
                'sender_type'            => ['columna' => 'sender_type',            'tipo' => 'en', 'valores' => ['admin', 'user']],
                /* 🔴 `kind` NO se declara como enumeración, y es a propósito. Es un varchar(20) con
                   default 'text' y sin lista blanca en ningún lado: el alta manual y la del ERP lo
                   toman del request tal cual, y el webhook de WhatsApp guarda el tipo crudo del
                   mensaje entrante recortado a 20 caracteres. Los valores que se ven hoy son text,
                   image, audio y document, pero WhatsApp puede mandar uno nuevo mañana y una lista
                   cerrada acá lo volvería inconsultable. Mismo criterio que `admin_tasks.created_via`:
                   declarar la lista sería inventarla. */
                'kind'                   => ['columna' => 'kind',                   'tipo' => 'texto_exacto'],
                'is_ai_suggestion_draft' => ['columna' => 'is_ai_suggestion_draft', 'tipo' => 'booleano'],
                'sin_leer'               => ['columna' => 'read_at',                'tipo' => 'nulo'],
                'sin_entregar'           => ['columna' => 'delivered_at',           'tipo' => 'nulo'],
                'sin_sincronizar'        => ['columna' => 'synced_to_client_at',    'tipo' => 'nulo'],
                'sin_escribir_el_agente' => ['columna' => 'ai_generated_at',        'tipo' => 'nulo'],
                /* 🔴 Enumeración de UN solo valor, y no es un error: 'not_received' es la ÚNICA marca
                   que el código escribe en esta columna (verificado el 3/9/2026 sobre los diez puntos
                   que la tocan). NULL NO significa "sin dato": significa que el mensaje salió bien —
                   SupportMessageController lo lee justo así
                   (`$entregado = $message->remote_delivery_status !== 'not_received'`) y varios
                   servicios la vuelven a null cuando el envío se destraba. O sea que este filtro es
                   "los que NO llegaron a WhatsApp". */
                'remote_delivery_status' => ['columna' => 'remote_delivery_status', 'tipo' => 'en', 'valores' => ['not_received']],
                'whatsapp_message_id'    => ['columna' => 'whatsapp_message_id',    'tipo' => 'texto_exacto'],
                'ids'                    => ['columna' => 'id',                     'tipo' => 'lista_de_enteros'],
                'creado_desde'           => ['columna' => 'created_at',             'tipo' => 'fecha_desde'],
                'creado_hasta'           => ['columna' => 'created_at',             'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'ticket' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'support_tickets',
                    'clave_local' => 'support_ticket_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'client_id', 'name', 'status', 'source'],
                ],
                'adjuntos' => [
                    'tipo'        => 'has_many', 'tabla' => 'support_message_attachments',
                    'clave_local' => 'id', 'clave_externa' => 'support_message_id',
                    'columnas'    => ['id', 'mime', 'size', 'created_at'],
                    'limite'      => 10,
                ],
            ],
            'nota' => 'El include `adjuntos` NO trae `disk` ni `path` a propósito: la ruta del archivo es opt-in en support_message_attachment y ahí está escrito por qué. Un mensaje con is_ai_suggestion_draft=true todavía no se mandó: es el borrador que espera aprobación.',
        ],

        /* ---------------------------------------------------------------
         | support_message_attachment — los archivos que viajan en un mensaje.
         |
         | 🔴 `disk` y `path` van OPT-IN, y el motivo no es el volumen: el alta
         | guarda en el disco `public` (SupportMessageController::store hace
         | `->store($directory, 'public')`), que Laravel sirve por `/storage/...`
         | SIN autenticación. O sea que `path` no es un identificador opaco: es la
         | mitad de una URL que abre el archivo. El archivo ya está público con o
         | sin este endpoint —así lo muestra el SPA—, pero repartir la ruta en una
         | consulta genérica es repartir el contenido, y el contenido son capturas
         | del sistema de un cliente.
         |
         | En base quedan `mime` y `size`, que alcanzan para saber que hay un
         | adjunto y de qué tipo es sin entregar por dónde se abre.
         --------------------------------------------------------------- */
        'support_message_attachment' => [
            'tabla'           => 'support_message_attachments',
            'descripcion'     => 'Adjuntos de un mensaje de soporte: tipo y tamaño. La ruta del archivo viaja sólo con include=archivo.',
            'columnas'        => [
                'id', 'support_message_id', 'mime', 'size', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'archivo' => ['disk', 'path'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'support_message_id' => ['columna' => 'support_message_id', 'tipo' => 'entero'],
                /* `texto` (LIKE) y no `texto_exacto`: lo que se quiere preguntar es "los que son
                   imagen", o sea mime=image, y no el mime completo con el subtipo. */
                'mime'               => ['columna' => 'mime',               'tipo' => 'texto'],
                'ids'                => ['columna' => 'id',                 'tipo' => 'lista_de_enteros'],
                'creado_desde'       => ['columna' => 'created_at',         'tipo' => 'fecha_desde'],
                'creado_hasta'       => ['columna' => 'created_at',         'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'mensaje' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'support_messages',
                    'clave_local' => 'support_message_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'support_ticket_id', 'sender_type', 'kind', 'created_at'],
                ],
            ],
            'nota' => 'No tiene support_ticket_id propio: se llega por el mensaje. Para los adjuntos de un ticket, primero support_message con support_ticket_id y después esta tabla con esos ids.',
        ],

        /* ---------------------------------------------------------------
         | support_knowledge_base — el ABM de artículos de soporte del panel.
         |
         | ⚠️ LEELA SABIENDO QUÉ ES: verificado el 3/9/2026 con un grep sobre
         | `app/`, esta tabla NO la lee ningún servicio — el único que la toca es
         | su propio ABM (SupportKnowledgeBaseController, cuatro rutas del panel).
         | El conocimiento con el que el agente de soporte contesta NO sale de
         | acá. Cargar un artículo en esta tabla no cambia nada de lo que el
         | agente sabe.
         |
         | `content` va opt-in por volumen: es el cuerpo del artículo, un text
         | entero por fila. El título y `is_active` alcanzan para inventariar.
         --------------------------------------------------------------- */
        'support_knowledge_base' => [
            'tabla'           => 'support_knowledge_base',
            'descripcion'     => 'Artículos de la base de conocimiento de soporte del panel. Ningún servicio la lee: es un ABM y nada más. El cuerpo viaja sólo con include=contenido.',
            'columnas'        => [
                'id', 'title', 'is_active', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contenido' => ['content'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['title', 'content'],
            'filtros'         => [
                'is_active'    => ['columna' => 'is_active',  'tipo' => 'booleano'],
                'ids'          => ['columna' => 'id',         'tipo' => 'lista_de_enteros'],
                'creado_desde' => ['columna' => 'created_at', 'tipo' => 'fecha_desde'],
                'creado_hasta' => ['columna' => 'created_at', 'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 No la confundas con el conocimiento del agente de soporte, que se arma en otro lado. Un artículo desactivado acá tampoco cambia nada: no hay consumidor.',
        ],

        /* ---------------------------------------------------------------
         | client_support_context — la ficha de contexto por cliente.
         |
         | 🔴 LOS DOS TEXTOS NO SON EQUIVALENTES, Y ÉSA ES LA RAZÓN DE SER DE LA
         | TABLA: `ficha_operativa` se inyecta en el prompt del agente de soporte
         | en CADA consulta sobre ese cliente; `notas_internas` NO se inyecta
         | nunca — es para el operador humano (juicios sobre la persona, temas
         | comerciales). El camino que llega al prompt hace un SELECT de una sola
         | columna, así que la nota ni siquiera está en memoria cuando se arma el
         | prompt. Por eso acá son DOS includes distintos y no uno solo: pedir
         | `include=ficha` es pedir exactamente lo que el agente lee.
         |
         | ⚠️ Que las dos sean opt-in NO contradice a GET claude/client-context,
         | que devuelve las dos siempre y sigue siendo el camino para leer antes
         | de pisar una ficha. Acá van opt-in por volumen: cada una admite hasta
         | 20.000 caracteres y este endpoint pagina de a 100 filas.
         --------------------------------------------------------------- */
        'client_support_context' => [
            'tabla'           => 'client_support_contexts',
            'descripcion'     => 'Fichas de contexto por cliente para el agente de soporte. `ficha_operativa` (include=ficha) es la que llega al prompt; `notas_internas` (include=notas) no llega nunca.',
            'columnas'        => [
                'id', 'client_id', 'created_via', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'ficha' => ['ficha_operativa'],
                'notas' => ['notas_internas'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['ficha_operativa', 'notas_internas'],
            'filtros'         => [
                'client_id'           => ['columna' => 'client_id',       'tipo' => 'entero'],
                /* Varchar sin lista cerrada: ClientSupportContext::CREATED_VIA_CLAUDE ('claude') es la
                   única constante, y la estampa POST claude/client-context sólo en el alta. Una fila
                   cargada por otro camino puede traerlo en null. Mismo criterio de `texto_exacto` que
                   `admin_tasks.created_via`. */
                'created_via'         => ['columna' => 'created_via',     'tipo' => 'texto_exacto'],
                'sin_ficha_operativa' => ['columna' => 'ficha_operativa', 'tipo' => 'nulo'],
                'sin_notas_internas'  => ['columna' => 'notas_internas',  'tipo' => 'nulo'],
                'ids'                 => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
                'creado_desde'        => ['columna' => 'created_at',      'tipo' => 'fecha_desde'],
                'creado_hasta'        => ['columna' => 'created_at',      'tipo' => 'fecha_hasta'],
                'actualizado_desde'   => ['columna' => 'updated_at',      'tipo' => 'fecha_desde'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active'],
                ],
            ],
            'nota' => '🔴 Nada calculable vive en esta tabla: tickets abiertos, antigüedad, versión que corre y veces que se escaló los arma SupportClientContextService leyendo la base al momento de construir el prompt. Si una ficha tuviera un encabezado que los repite, ese encabezado está viejo. Para cargar o pisar una ficha está POST claude/client-context, que es idempotente por client_id.',
        ],

        /* ---------------------------------------------------------------
         | client_template — las plantillas de Meta para escribirle a un CLIENTE.
         |
         | Entra ENTERA: verificado columna por columna el 3/9/2026 contra
         | information_schema, no hay ninguna credencial ni ningún dato de una
         | persona. Son plantillas aprobadas en Meta, con su cuerpo, su categoría
         | y sus variables.
         |
         | ⚠️ NO son las de leads. `followup_templates` es otro juego: ésas las
         | levanta el motor de seguimiento automático del pipeline comercial y
         | derivan la categoría del estado del lead. Acá la categoría es una
         | columna que carga quien crea la plantilla.
         --------------------------------------------------------------- */
        'client_template' => [
            'tabla'           => 'client_templates',
            'descripcion'     => 'Plantillas de Meta aprobadas para escribirle a un cliente desde la bandeja de soporte, con su cuerpo, su categoría y sus variables.',
            'columnas'        => [
                'id', 'template_name', 'language_code',
                'categoria', 'categoria_label', 'categoria_orden',
                'titulo', 'body_template', 'descripcion', 'variables', 'activa',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['template_name', 'titulo', 'body_template', 'descripcion'],
            'filtros'         => [
                'activa'        => ['columna' => 'activa',        'tipo' => 'booleano'],
                /* Varchar(60) libre: la define quien carga la plantilla (POST claude/client-templates
                   la valida como `required|string|max:60` y nada más). Declarar una lista cerrada
                   sería inventarla, y quedaría vieja la primera vez que alguien agrupa distinto. */
                'categoria'     => ['columna' => 'categoria',     'tipo' => 'texto_exacto'],
                'language_code' => ['columna' => 'language_code', 'tipo' => 'texto_exacto'],
                'template_name' => ['columna' => 'template_name', 'tipo' => 'texto_exacto'],
                'ids'           => ['columna' => 'id',            'tipo' => 'lista_de_enteros'],
                'creado_desde'  => ['columna' => 'created_at',    'tipo' => 'fecha_desde'],
                'creado_hasta'  => ['columna' => 'created_at',    'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => '⚠️ Acá salen las columnas CRUDAS: /query lee con DB::table, así que los accessors del modelo ClientTemplate (categoria vacía => "otras", categoria_label derivada del slug, titulo derivado del template_name) NO se aplican y una fila incompleta devuelve el vacío tal cual. GET claude/client-templates devuelve el modelo Eloquent con esos accessors puestos y agrupado por categoría, y es el que hay que mirar antes de cargar un lote con POST.',
        ],

        /* ---------------------------------------------------------------
         | mensualidad_invoice — las facturas que ComercioCity le emite a un
         | cliente por su mensualidad (Factura C contra AFIP/WSFE).
         |
         | 🔴 SIN `request` NI `response`: son dos longText con el sobre SOAP
         | CRUDO de la llamada a AFIP, y ese sobre lleva el nodo Auth con el Token
         | y el Sign de la sesión WSAA (AfipWsfeService::call_soap los mete en cada
         | request y después guarda `__getLastRequest()` tal cual). Con eso se le
         | factura a nombre de ComercioCity hasta que el ticket de acceso vence.
         | Ninguna de las dos matchea `columnas_prohibidas` —se llaman `request` y
         | `response`—: es exactamente el caso que la reja de atrás no ataja y la
         | lista blanca sí.
         |
         | 🔴 SIN `cuit_cliente`, `doc_tipo` NI `doc_nro`: son los datos fiscales
         | del receptor, y `cuit_cliente`/`doc_nro` se escriben los dos desde
         | `clients.afip_cuit`, que la entrada `client` de este mismo archivo ya
         | dejó afuera por ser dato fiscal del titular. Dejarlos entrar acá sería
         | devolver por la ventana lo que se sacó por la puerta.
         |
         | 🔴 SIN `cuit_negocio`: es el CUIT de ComercioCity, o sea el MISMO valor
         | en todas las filas (sale de comerciocity_afip_config.cuit). No informa
         | nada por fila y es un identificador fiscal: si hace falta, está en
         | `comerciocity_afip_config` con include=fiscal.
         |
         | `error_message` SÍ entra: es el motivo legible del rechazo —los códigos
         | y mensajes del nodo Observaciones de AFIP, o el getMessage() de la
         | excepción de red—, no el sobre. Verificado en
         | AfipFacturacionService::extraer_error_legible().
         --------------------------------------------------------------- */
        'mensualidad_invoice' => [
            'tabla'           => 'mensualidad_invoices',
            'descripcion'     => 'Comprobantes de mensualidad emitidos contra AFIP por cada cliente: período, importes, CAE y el motivo si AFIP lo rechazó. Sin el SOAP crudo ni los datos fiscales del receptor.',
            'columnas'        => [
                'id', 'client_id', 'periodo',
                'cbte_tipo', 'cbte_letra', 'cbte_numero', 'punto_venta',
                'importe_total', 'imp_neto', 'imp_iva', 'condicion_iva_receptor_id',
                'cae', 'cae_expired_at', 'resultado', 'error_message',
                'afip_produccion', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['periodo', 'cae', 'error_message'],
            'filtros'         => [
                'client_id'       => ['columna' => 'client_id',       'tipo' => 'entero'],
                /* Es un varchar(7) con el período en formato YYYY-MM, no una fecha: por eso
                   texto_exacto y no fecha_desde. */
                'periodo'         => ['columna' => 'periodo',         'tipo' => 'texto_exacto'],
                /* 🔴 Enumeración REAL, verificada el 3/9/2026 contra el docblock de
                   App\Models\MensualidadInvoice y contra AfipFacturacionService, que es el único que
                   escribe la columna: 'A' es autorizado por AFIP y 'R' es rechazado. No hay un tercer
                   valor — un intento que ni siquiera llegó a AFIP también se guarda como 'R', con el
                   motivo en `error_message`. */
                'resultado'       => ['columna' => 'resultado',       'tipo' => 'en', 'valores' => ['A', 'R']],
                /* NO va como enumeración a propósito: hoy es siempre 'C' (Factura C de
                   Monotributista, AfipFacturacionService::CBTE_TIPO_FACTURA_C), pero la condición de
                   IVA de comerciocity_afip_config está preparada para pasar a Responsable Inscripto,
                   y ese día aparecen 'A' y 'B'. Una lista cerrada de un solo valor haría 422 sobre
                   comprobantes reales el mismo día del cambio. */
                'cbte_letra'      => ['columna' => 'cbte_letra',      'tipo' => 'texto_exacto'],
                'cbte_tipo'       => ['columna' => 'cbte_tipo',       'tipo' => 'entero'],
                'punto_venta'     => ['columna' => 'punto_venta',     'tipo' => 'entero'],
                'afip_produccion' => ['columna' => 'afip_produccion', 'tipo' => 'booleano'],
                'sin_cae'         => ['columna' => 'cae',             'tipo' => 'nulo'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
                'creado_desde'    => ['columna' => 'created_at',      'tipo' => 'fecha_desde'],
                'creado_hasta'    => ['columna' => 'created_at',      'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'is_active', 'total_mensualidad', 'payment_expired_at'],
                ],
            ],
            'nota' => '🔴 Cada fila es un INTENTO de emisión, no una factura: puede haber varias por client_id + periodo si alguna salió rechazada. La que cuenta como "ya facturado" es resultado="A" con `cae` no nulo — filtrar sólo por período devuelve también los rechazos. El token que abre el PDF de una factura vive en otra tabla y está excluida a propósito (MensualidadInvoicePdfAccessToken).',
        ],

        /* ---------------------------------------------------------------
         | comerciocity_afip_config — la config fiscal propia de ComercioCity.
         |
         | 🔴 SE MIRÓ COLUMNA POR COLUMNA BUSCANDO UNA CREDENCIAL Y NO HAY
         | NINGUNA, y por eso el modelo entra en vez de irse a `modelos_excluidos`.
         | El certificado y la clave privada de AFIP de ComercioCity NO viven en
         | esta tabla: son archivos en el disco del admin y los administra
         | AfipCertificateProvisionService, que los copia por SFTP al servidor de
         | cada cliente. En la tabla no está ni el contenido ni la ruta de ninguno
         | de los dos. `logo_path` es lo único que parece una ruta y es el logo que
         | se imprime en el PDF de la factura, bajo public/afip.
         |
         | Lo que sí hay son los datos fiscales del titular —cuit, razón social,
         | domicilio comercial e ingresos brutos—, que van OPT-IN (include=fiscal)
         | con el mismo criterio con el que la entrada `client` dejó afuera
         | `afip_cuit` y `afip_razon_social`. La diferencia con aquéllos es que acá
         | el titular es ComercioCity y esos datos se imprimen en cada factura que
         | emite: por eso opt-in y no exclusión.
         |
         | ⚠️ Es una config de UNA SOLA FILA: ComerciocityAfipConfig::current()
         | hace firstOrCreate sin condiciones. Los límites son bajos a propósito.
         --------------------------------------------------------------- */
        'comerciocity_afip_config' => [
            'tabla'           => 'comerciocity_afip_config',
            'descripcion'     => 'Configuración fiscal propia de ComercioCity (una sola fila): condición de IVA, punto de venta y si se factura contra producción u homologación. Los datos del titular viajan con include=fiscal.',
            'columnas'        => [
                'id', 'condicion_iva', 'punto_venta', 'inicio_actividades',
                'afip_produccion', 'logo_path', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'fiscal' => ['cuit', 'razon_social', 'domicilio_comercial', 'ingresos_brutos'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 10,
            'limite_max'      => 50,
            'filtros'         => [
                'afip_produccion' => ['columna' => 'afip_produccion', 'tipo' => 'booleano'],
                /* Varchar(60) que el panel carga desde un select. El default con el que nace la fila
                   es 'Monotributista' (ComerciocityAfipConfig::current()) y el select está preparado
                   para Responsable Inscripto, pero la lista vive en el SPA y no en este repo: por eso
                   texto_exacto y no una enumeración que sería una copia desactualizable. */
                'condicion_iva'   => ['columna' => 'condicion_iva',   'tipo' => 'texto_exacto'],
                'ids'             => ['columna' => 'id',              'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 `afip_produccion` es el interruptor que decide si una emisión va contra AFIP de verdad o contra homologación, y queda copiado en cada mensualidad_invoices.afip_produccion al momento de emitir: una factura vieja emitida en homologación NO cambia porque después se prenda el interruptor. Los certificados AFIP no están en esta tabla: son archivos del disco del admin que administra AfipCertificateProvisionService.',
        ],

        /* ---------------------------------------------------------------
         | demo — las instancias de demostración del ERP y de la tienda.
         |
         | 🔴 SIN api_key: es la clave server-to-server con la que el admin le
         | pide a la empresa-api de esta demo el branding al compilar la tienda.
         | Es el equivalente exacto de `clients.api_key`, que ya está afuera por
         | lo mismo (verificado en DemoProperties, campo 'api_key', descripción
         | "Clave server-to-server..."). Además matchea la reja `api_key$` de
         | columnas_prohibidas: declararla rompe el build.
         |
         | ✅ Las cuatro URLs SÍ entran, y no es un descuido: son públicas por
         | diseño (a la del SPA entra el lead) y son lo único con lo que se
         | confirma CONTRA QUÉ demo se está operando. POST claude/demo-updates
         | pide `confirm_demo_name` y el valor que espera es justamente
         | `erp_spa_url`.
         |
         | ⚠️ Ya existe GET claude/demos, y este modelo NO lo reemplaza: aquél
         | calcula `ultima_actualizacion` y `tiene_una_viva` cruzando demo_updates;
         | esto son las columnas crudas de la tabla, con filtros y paginación.
         --------------------------------------------------------------- */
        'demo' => [
            'tabla'           => 'demos',
            'descripcion'     => 'Instancias de demo: URLs del ERP y de la tienda, tipo de hosting y rutas en el VPS. Para saber si una demo tiene una actualización viva está GET claude/demos, que lo calcula.',
            'columnas'        => [
                'id', 'uuid', 'nombre', 'user_id',
                'erp_spa_url', 'erp_api_url', 'erp_hosting_type', 'erp_vps_path',
                'ecommerce_spa_url', 'ecommerce_api_url', 'ecommerce_hosting_type', 'ecommerce_vps_path',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['nombre', 'erp_spa_url', 'erp_api_url', 'ecommerce_spa_url'],
            'filtros'         => [
                'user_id'                => ['columna' => 'user_id',                'tipo' => 'entero'],
                /* Enumeración REAL, verificada en DemoProperties el 3/9/2026: el desplegable de
                   los dos campos tiene exactamente dos opciones, shared_hosting y vps, y
                   DemoPathResolver sólo compara contra 'vps'. */
                'erp_hosting_type'       => ['columna' => 'erp_hosting_type',       'tipo' => 'en', 'valores' => ['shared_hosting', 'vps']],
                'ecommerce_hosting_type' => ['columna' => 'ecommerce_hosting_type', 'tipo' => 'en', 'valores' => ['shared_hosting', 'vps']],
                'ids'                    => ['columna' => 'id',                     'tipo' => 'lista_de_enteros'],
                'creado_desde'           => ['columna' => 'created_at',             'tipo' => 'fecha_desde'],
                'creado_hasta'           => ['columna' => 'created_at',             'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'actualizaciones' => [
                    'tipo'        => 'has_many', 'tabla' => 'demo_updates',
                    'clave_local' => 'id', 'clave_externa' => 'demo_id',
                    'columnas'    => ['id', 'uuid', 'version_id', 'status', 'started_at', 'finished_at'],
                    'limite'      => 10,
                ],
            ],
            'nota' => '🔴 Una demo NO es un Client: tiene modelo y pipeline propios. Para actualizar una está POST claude/demo-updates (pide confirm_demo_name = erp_spa_url y dry_run=false), y para correr un comando de la lista blanca, POST claude/demo-commands. ecommerce_hosting_type=vps hace que el pipeline de la tienda se niegue a arrancar, a propósito.',
        ],

        /* ---------------------------------------------------------------
         | demo_update — el pipeline de actualización de UNA demo.
         |
         | 🔴 SIN `log`: es un longtext con la salida cruda de la sesión SSH
         | (upload_spa, upload_api, npm run build). Mismo criterio de volumen que
         | deployment_logs y ecommerce_deployment_logs, que están en
         | modelos_excluidos. Acá el log no es una tabla aparte: es una columna, y
         | por eso se excluye la columna y no el modelo.
         |
         | ⚠️ Ya existen GET claude/demo-updates y GET claude/demo-updates/{id}:
         | ésos arman la fila enriquecida (con la demo y la versión resueltas) y
         | son los que hay que usar para seguir una corrida. Esto son las columnas
         | crudas, con filtros y paginación por cursor.
         --------------------------------------------------------------- */
        'demo_update' => [
            'tabla'           => 'demo_updates',
            'descripcion'     => 'Corridas de actualización de una demo, con su estado y los timestamps de la corrida. Sin el log: para eso está GET claude/demo-updates/{id}.',
            'columnas'        => [
                'id', 'uuid', 'demo_id', 'version_id', 'created_by_admin_id',
                'status', 'started_at', 'finished_at', 'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'demo_id'             => ['columna' => 'demo_id',             'tipo' => 'entero'],
                'version_id'          => ['columna' => 'version_id',          'tipo' => 'entero'],
                'created_by_admin_id' => ['columna' => 'created_by_admin_id', 'tipo' => 'entero'],
                /* 🔴 Enumeración REAL, verificada el 3/9/2026 contra la enumeración de MySQL
                   (enum('pendiente','ejecutandose','completado','fallido')) y contra
                   DemoUpdateService, que escribe 'ejecutandose' al arrancar (línea 129),
                   'completado' al terminar bien (147) y 'fallido' en el catch (161). OJO: el
                   participio va en MASCULINO —completado/fallido—, a diferencia de
                   client_installations y demo_installations, que usan completada/fallida. Son dos
                   vocabularios distintos en el mismo proyecto: no los mezcles. */
                'status'              => ['columna' => 'status',              'tipo' => 'en', 'valores' => ['pendiente', 'ejecutandose', 'completado', 'fallido']],
                'ids'                 => ['columna' => 'id',                  'tipo' => 'lista_de_enteros'],
                'creado_desde'        => ['columna' => 'created_at',          'tipo' => 'fecha_desde'],
                'creado_hasta'        => ['columna' => 'created_at',          'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'demo' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'demos',
                    'clave_local' => 'demo_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'nombre', 'erp_spa_url', 'erp_hosting_type'],
                ],
                'version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status', 'is_hotfix'],
                ],
            ],
            'nota' => 'Los estados activos (los que significan "hay una corrida viva") son pendiente y ejecutandose: es la misma lista que mira ClaudeDemoOpsController::ESTADOS_ACTIVOS para no dejar arrancar dos actualizaciones sobre la misma demo.',
        ],

        /* ---------------------------------------------------------------
         | demo_media — el mapa slot_id => url de las piezas de la demo.
         |
         | ⚠️ Tabla sin `uuid` y SIN `demo_id`: es un mapa PLANO y global, no una
         | tabla por demo. Verificado contra information_schema el 3/9/2026: son
         | cinco columnas y ya. Una fila ausente para un slot significa "sin media
         | cargada", no un error.
         |
         | ⚠️ Ya existen GET claude/demo-media y PUT claude/demo-media, que son
         | los que hay que usar para leer y escribir el mapa completo (el PUT
         | valida el slot_id contra el catálogo sincronizado). Esto sirve para lo
         | que aquéllos no hacen: filtrar, paginar y sobre todo pedir los slots
         | que quedaron SIN url (filtro `sin_url`).
         --------------------------------------------------------------- */
        'demo_media' => [
            'tabla'           => 'demo_media',
            'descripcion'     => 'Mapa plano slot_id => url de las piezas multimedia de la demo. Para el ABM completo están GET/PUT claude/demo-media.',
            'columnas'        => ['id', 'slot_id', 'url', 'created_at', 'updated_at'],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['slot_id', 'url'],
            'filtros'         => [
                /* 🔴 `slot_id` NO es una enumeración y no se declara como tal: los ids válidos
                   salen de DemoCatalogoService::slots(), que es el catálogo SINCRONIZADO desde el
                   repo de conocimiento. O sea que la lista cambia sin tocar este repo. Declararla
                   cerrada acá la dejaría vieja en silencio, que es justo lo que este archivo
                   promete que no pasa. Va como texto exacto. */
                'slot_id' => ['columna' => 'slot_id', 'tipo' => 'texto_exacto'],
                'sin_url' => ['columna' => 'url',     'tipo' => 'nulo'],
                'ids'     => ['columna' => 'id',      'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 Si el catálogo de la demo no está sincronizado, DemoMediaController::update_json rechaza CUALQUIER slot_id: DemoCatalogoService::slots() devuelve [] y la regla in: no matchea nada. Una fila que no está acá no es un error: es un slot sin media cargada.',
        ],

        /* ---------------------------------------------------------------
         | demo_installation — instalación desde cero del ERP de una demo.
         |
         | 🔴 SIN env_manual_values: json con valores de `.env` cargados a mano, o
         | sea configuración de servidor. Mismo criterio, y la misma columna, que
         | `client_installations.env_manual_values`, que ya está afuera.
         |
         | 🔴 Los logs de esta tabla NO son una columna: viven en
         | demo_installation_logs, que va a modelos_excluidos por volumen.
         --------------------------------------------------------------- */
        'demo_installation' => [
            'tabla'           => 'demo_installations',
            'descripcion'     => 'Corridas de instalación desde cero del ERP de una demo, con su estado y el motivo de la falla.',
            'columnas'        => [
                'id', 'uuid', 'demo_id', 'version_id', 'created_by_admin_id',
                'status', 'failure_reason', 'started_at', 'finished_at',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['failure_reason'],
            'filtros'         => [
                'demo_id'             => ['columna' => 'demo_id',             'tipo' => 'entero'],
                'version_id'          => ['columna' => 'version_id',          'tipo' => 'entero'],
                'created_by_admin_id' => ['columna' => 'created_by_admin_id', 'tipo' => 'entero'],
                /* 🔴 Enumeración REAL, verificada en DemoInstallation::STATUSES el 3/9/2026
                   (STATUS_PENDIENTE, STATUS_INSTALANDO, STATUS_COMPLETADA, STATUS_FALLIDA). Acá el
                   participio va en FEMENINO, al revés que demo_updates.status. */
                'status'              => ['columna' => 'status',              'tipo' => 'en', 'valores' => ['pendiente', 'instalando', 'completada', 'fallida']],
                'ids'                 => ['columna' => 'id',                  'tipo' => 'lista_de_enteros'],
                'creado_desde'        => ['columna' => 'created_at',          'tipo' => 'fecha_desde'],
                'creado_hasta'        => ['columna' => 'created_at',          'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'demo' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'demos',
                    'clave_local' => 'demo_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'nombre', 'erp_spa_url', 'erp_hosting_type'],
                ],
                'version' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'versions',
                    'clave_local' => 'version_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'version', 'title', 'status', 'is_hotfix'],
                ],
            ],
            'nota' => '🔴 No hay endpoint para RE-arrancar una instalación: crear la fila dispara el pipeline, cuya etapa run_demo_setup le hace migrate:fresh a la base de la demo. Un re-arranque sobre la misma fila sería vaciarle la base a una demo que ya está andando.',
        ],

        /* ---------------------------------------------------------------
         | demo_evento_recibido — el crudo que reporta la instancia de demo.
         |
         | `datos` va OPT-IN, no afuera: es la carga libre del evento, con techo
         | de 4 KB por fila (DemoEventosController::MAX_BYTES_DATOS) y sin
         | esquema fijo. En un listado de 200 filas es volumen; pedido a
         | propósito es lo que alimenta el brief del closer.
         |
         | SIN relación a `leads`, y ahora el motivo es OTRO: hasta el 3/9/2026 era
         | que la tabla Lead estaba excluida entera y una relación la habría abierto
         | de costado. Desde que `lead` entró a `modelos` eso ya no aplica, pero la
         | relación sigue sin estar por volumen: la entrada `lead` publica más de 50
         | columnas y repetirlas en cada evento de una demo —que son cientos por
         | lead— es ruido puro. El lead_id se resuelve con una segunda consulta a
         | `model=lead&ids=...`, o con GET claude/leads si querés los conteos.
         --------------------------------------------------------------- */
        'demo_evento_recibido' => [
            'tabla'           => 'demo_eventos_recibidos',
            'descripcion'     => 'Eventos crudos que la instancia de demo reporta por /api/demo-eventos, por lead. El uuid es único y ahí vive la idempotencia del canal.',
            'columnas'        => [
                'id', 'lead_id', 'uuid', 'nombre', 'clip_id',
                'ocurrido_at', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'datos' => ['datos'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'busqueda'        => ['nombre', 'clip_id'],
            'filtros'         => [
                'lead_id'         => ['columna' => 'lead_id',     'tipo' => 'entero'],
                /* 🔴 `nombre` NO se declara como enumeración A PROPÓSITO, y está escrito en el
                   código: RunDemoSetup dice que filtrar por nombre "ataría la guarda a un
                   vocabulario que todavía se está escribiendo (misión 50)". La única lista cerrada
                   que existe es la inversa —EVENTOS_QUE_NO_PRUEBAN_PRESENCIA, los que NO emite el
                   lead— y no es la lista de valores válidos. Va como texto exacto: una enumeración
                   inventada acá devolvería 422 sobre eventos que sí son válidos. */
                'nombre'          => ['columna' => 'nombre',      'tipo' => 'texto_exacto'],
                'clip_id'         => ['columna' => 'clip_id',     'tipo' => 'texto_exacto'],
                'uuid'            => ['columna' => 'uuid',        'tipo' => 'texto_exacto'],
                'sin_ocurrido_at' => ['columna' => 'ocurrido_at', 'tipo' => 'nulo'],
                'ocurrido_desde'  => ['columna' => 'ocurrido_at', 'tipo' => 'fecha_desde'],
                'ocurrido_hasta'  => ['columna' => 'ocurrido_at', 'tipo' => 'fecha_hasta'],
                'ids'             => ['columna' => 'id',          'tipo' => 'lista_de_enteros'],
                'creado_desde'    => ['columna' => 'created_at',  'tipo' => 'fecha_desde'],
                'creado_hasta'    => ['columna' => 'created_at',  'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 demo.setup.completado lo emite el propio admin-api al terminar de armar la instancia, NO el lead: está en RunDemoSetup::EVENTOS_QUE_NO_PRUEBAN_PRESENCIA. Contar filas de esta tabla como "hay alguien adentro de la demo" sin excluirlo da siempre que sí.',
        ],

        /* ---------------------------------------------------------------
         | implementation — la implementación guiada de un cliente.
         |
         | 🔴 SIN form_token: es el UUID v4 que abre el formulario público de
         | configuración FUERA de auth:sanctum (ImplementationFormController, y
         | routes/api.php lo dice con todas las letras: "El cliente accede con un
         | link único que contiene form_token; no requiere Sanctum"). Servirlo es
         | entregar el formulario de configuración de un cliente real. Además
         | matchea la reja `token` de columnas_prohibidas: declararlo rompe el
         | build, que es exactamente lo que tiene que pasar.
         |
         | `migration_contact_phone` es PII —el teléfono del responsable de la
         | migración— y va opt-in, mismo criterio que `clients.phone`.
         |
         | `notes` SÍ entra: es un text de notas operativas sobre la
         | implementación, no sobre una persona (y verificado el 3/9/2026, hoy no
         | lo escribe ningún camino del código: es un campo previsto que quedó sin
         | uso). Distinto de `client_employees.notes`, que es texto libre sobre un
         | empleado y por eso está afuera.
         --------------------------------------------------------------- */
        'implementation' => [
            'tabla'           => 'implementations',
            'descripcion'     => 'Implementación guiada de un cliente: etapa actual, estado, modo de automatización y si el formulario de configuración ya fue enviado.',
            'columnas'        => [
                'id', 'client_id', 'assigned_admin_id', 'current_stage', 'status',
                'automation_mode', 'user_setup_executed_at', 'form_submitted_at',
                'started_at', 'completed_at', 'notes', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto' => ['migration_contact_phone'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['notes'],
            'filtros'         => [
                'client_id'         => ['columna' => 'client_id',              'tipo' => 'entero'],
                'assigned_admin_id' => ['columna' => 'assigned_admin_id',      'tipo' => 'entero'],
                'current_stage'     => ['columna' => 'current_stage',          'tipo' => 'entero'],
                /* Enumeración de MySQL, leída de information_schema el 3/9/2026:
                   enum('pending','in_progress','completed','paused'). Está en INGLÉS, al revés que
                   client_version_upgrades.status, que está en español. */
                'status'            => ['columna' => 'status',                 'tipo' => 'en', 'valores' => ['pending', 'in_progress', 'completed', 'paused']],
                /* 🔴 Enumeración REAL, verificada en ImplementationController::update_automation_mode()
                   el 3/9/2026: `in_array($mode, ['manual', 'auto'], true)` o 422. La columna es un
                   varchar(20), no un enum de MySQL, así que la lista sale del código. */
                'automation_mode'   => ['columna' => 'automation_mode',        'tipo' => 'en', 'valores' => ['manual', 'auto']],
                'sin_formulario'    => ['columna' => 'form_submitted_at',      'tipo' => 'nulo'],
                'sin_user_setup'    => ['columna' => 'user_setup_executed_at', 'tipo' => 'nulo'],
                'ids'               => ['columna' => 'id',                     'tipo' => 'lista_de_enteros'],
                'creado_desde'      => ['columna' => 'created_at',             'tipo' => 'fecha_desde'],
                'creado_hasta'      => ['columna' => 'created_at',             'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'company_name', 'is_active'],
                ],
                'etapas' => [
                    'tipo'        => 'has_many', 'tabla' => 'implementation_stages',
                    'clave_local' => 'id', 'clave_externa' => 'implementation_id',
                    'columnas'    => ['id', 'stage_number', 'status', 'started_at', 'completed_at', 'alert_count'],
                    'limite'      => 10,
                ],
            ],
            'nota' => '🔴 Hay UNA implementación por client_id. automation_mode=manual (el default) NO significa parada: significa que cada paso lo dispara una persona desde el panel. Las respuestas del formulario NO se sirven acá: viven en implementation_stages.data.form_responses y son PII (ver la nota de implementation_stage).',
        ],

        /* ---------------------------------------------------------------
         | implementation_message — la conversación de WhatsApp de una
         | implementación.
         |
         | ⚠️ `body` es un `text` (hasta 64 KB por fila) y va OPT-IN, no en la
         | proyección base: son mensajes armados por plantilla, y un listado de
         | 100 filas con el cuerpo entero es el mismo problema de volumen que
         | `admin_tasks.content`. El opt-in es por VOLUMEN y no por secreto, y por
         | eso `busqueda` sí mira `body`: buscar una frase adentro de la
         | conversación es exactamente para lo que se necesita, y no filtra
         | cuerpos que no se pidieron.
         |
         | 🔴 `phone` es PII y va opt-in aparte, mismo criterio que `clients.phone`
         | y `client_employees.phone`.
         --------------------------------------------------------------- */
        'implementation_message' => [
            'tabla'           => 'implementation_messages',
            'descripcion'     => 'Mensajes de WhatsApp de una implementación, por etapa y dirección. El cuerpo viaja sólo con include=cuerpo.',
            'columnas'        => [
                'id', 'implementation_id', 'stage_number', 'direction',
                'whatsapp_message_id', 'sent_at', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'cuerpo'   => ['body'],
                'contacto' => ['phone'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['body'],
            'filtros'         => [
                'implementation_id' => ['columna' => 'implementation_id', 'tipo' => 'entero'],
                'stage_number'      => ['columna' => 'stage_number',      'tipo' => 'entero'],
                /* Enumeración de MySQL, leída de information_schema: enum('inbound','outbound'). */
                'direction'         => ['columna' => 'direction',         'tipo' => 'en', 'valores' => ['inbound', 'outbound']],
                'sin_enviar'        => ['columna' => 'sent_at',           'tipo' => 'nulo'],
                'ids'               => ['columna' => 'id',                'tipo' => 'lista_de_enteros'],
                'creado_desde'      => ['columna' => 'created_at',        'tipo' => 'fecha_desde'],
                'creado_hasta'      => ['columna' => 'created_at',        'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'implementacion' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'implementations',
                    'clave_local' => 'implementation_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'current_stage', 'status', 'automation_mode'],
                ],
            ],
            'nota' => 'Es la conversación de la IMPLEMENTACIÓN, no la del lead: los mensajes comerciales están en GET claude/messages. whatsapp_message_id es el id del proveedor, sirve para cruzar con el webhook.',
        ],

        /* ---------------------------------------------------------------
         | implementation_stage — el estado de cada etapa de una implementación.
         |
         | 🔴 SIN `data`, y no es volumen: es PII. El stage 1 guarda ahí
         | `form_responses`, las respuestas crudas del formulario del cliente, y
         | de ahí sale lo que ImplementationFormMapper::build_setup_data() copia a
         | `clients.setup_data` — que ya está afuera de este endpoint por lo
         | mismo: `email` y `doc_number` del titular, más domicilio, redes y los
         | teléfonos de los empleados. Excluir `clients.setup_data` y servir el
         | json del que sale sería tapar la puerta y dejar la ventana.
         --------------------------------------------------------------- */
        'implementation_stage' => [
            'tabla'           => 'implementation_stages',
            'descripcion'     => 'Estado de cada etapa (1-8) de una implementación, con sus tiempos y cuántas alertas se mandaron. Sin `data`: ahí viven las respuestas del formulario, que son PII.',
            'columnas'        => [
                'id', 'implementation_id', 'stage_number', 'status',
                'started_at', 'completed_at', 'last_alert_sent_at', 'alert_count',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'implementation_id' => ['columna' => 'implementation_id',  'tipo' => 'entero'],
                'stage_number'      => ['columna' => 'stage_number',       'tipo' => 'entero'],
                /* Enumeración de MySQL, leída de information_schema el 3/9/2026:
                   enum('pending','in_progress','completed','skipped'). 🔴 Tiene un cuarto valor
                   —'skipped'— que ecommerce_implementation_stages NO tiene. No son la misma
                   enumeración aunque las dos tablas sean del mismo pipeline. */
                'status'            => ['columna' => 'status',             'tipo' => 'en', 'valores' => ['pending', 'in_progress', 'completed', 'skipped']],
                'sin_alertas'       => ['columna' => 'last_alert_sent_at', 'tipo' => 'nulo'],
                'ids'               => ['columna' => 'id',                 'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'implementacion' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'implementations',
                    'clave_local' => 'implementation_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'current_stage', 'status', 'automation_mode'],
                ],
            ],
            'nota' => '🔴 `data` no se sirve por PII (form_responses del stage 1: email y doc_number del titular). El resumen legible del formulario lo arma ImplementationFormMapper::build_summary() y se ve en el panel; no hay endpoint claude/* que lo devuelva.',
        ],

        /* ---------------------------------------------------------------
         | implementation_stage_config — la definición de las etapas.
         |
         | Entra ENTERA: es configuración del proceso, no datos de nadie.
         | ⚠️ Tabla sin `uuid`.
         --------------------------------------------------------------- */
        'implementation_stage_config' => [
            'tabla'           => 'implementation_stage_configs',
            'descripcion'     => 'Definición de cada etapa de la implementación: nombre, umbral de alerta en horas, si está automatizada y si está activa.',
            'columnas'        => [
                'id', 'stage_number', 'name', 'description',
                'alert_threshold_hours', 'is_automated', 'active',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['name', 'description'],
            'filtros'         => [
                'stage_number' => ['columna' => 'stage_number', 'tipo' => 'entero'],
                'is_automated' => ['columna' => 'is_automated', 'tipo' => 'booleano'],
                'active'       => ['columna' => 'active',       'tipo' => 'booleano'],
                'ids'          => ['columna' => 'id',           'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => 'Es el CATÁLOGO de etapas, uno solo para todas las implementaciones: no hay una fila por cliente. alert_threshold_hours es un decimal(5,2), o sea que admite fracciones de hora.',
        ],

        /* ---------------------------------------------------------------
         | implementation_payment_method_option — los métodos de pago que ofrece
         | el formulario de configuración.
         |
         | 🔴 SIN la columna `key`, Y NO PORQUE SEA UN SECRETO. Sus valores son
         | efectivo, debito, credito, transferencia, cheque y mercado_pago
         | (ImplementationPaymentMethodOptionSeeder): no hay nada que proteger.
         | Queda afuera porque la columna se llama literalmente `key` y matchea
         | `^key$` de columnas_prohibidas, que es fail-closed: declararla haría
         | que el modelo devuelva 422 sin servir NADA, y rompe
         | ConsultaGenericaParaClaudeTest en el build.
         | Es un falso positivo de la reja, y se resuelve NO escribiendo la
         | columna, no aflojando el patrón: `^key$` se eligió así a propósito (en
         | vez de `_key$`) y tocarlo por una tabla de seis filas sería empezar
         | justamente la lista de excepciones que el comentario de arriba dice que
         | hay que evitar. Los seis valores quedan escritos en la `nota`, que es
         | donde el que consulta los va a ir a buscar.
         |
         | ⚠️ Tabla sin `uuid`.
         --------------------------------------------------------------- */
        'implementation_payment_method_option' => [
            'tabla'           => 'implementation_payment_method_options',
            'descripcion'     => 'Métodos de pago que el formulario de configuración le ofrece al cliente, con su etiqueta visible y su orden. Sin la columna `key` (ver la nota).',
            'columnas'        => ['id', 'label', 'position', 'created_at', 'updated_at'],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['label'],
            'filtros'         => [
                'ids' => ['columna' => 'id', 'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => '🔴 La columna `key` (el valor estable que se guarda) NO se sirve: se llama igual que el patrón ^key$ de columnas_prohibidas y el endpoint es fail-closed. No es un secreto — los seis valores sembrados son efectivo, debito, credito, transferencia, cheque y mercado_pago, y hay que mantenerlos en sincronía con CurrentAcountPaymentMethodSeeder de empresa-api. Para ver la key de una fila concreta está el ABM del panel.',
        ],

        /* ---------------------------------------------------------------
         | ecommerce_implementation — la implementación guiada de la TIENDA.
         |
         | ✅ VERIFICADO EL 3/9/2026: acá NO hay ningún equivalente de
         | `implementations.form_token`. La tabla tiene diez columnas y ninguna es
         | un token, una clave ni un identificador público; y todas las rutas
         | `ecommerce-implementation/*` de routes/api.php viven adentro del grupo
         | autenticado — no existe el hermano público de
         | ImplementationFormController. La configuración de la tienda no se junta
         | con un formulario web sino conversando por WhatsApp
         | (EcommerceImplementationConversationService), así que no hace falta
         | ningún link sin auth. Queda escrito para que el próximo no tenga que
         | volver a buscarlo.
         |
         | `migration_contact_phone` es PII y va opt-in, igual que en
         | `implementation`.
         --------------------------------------------------------------- */
        'ecommerce_implementation' => [
            'tabla'           => 'ecommerce_implementations',
            'descripcion'     => 'Implementación guiada de la tienda (ecommerce) de un cliente: etapa actual, estado y tienda asociada.',
            'columnas'        => [
                'id', 'client_id', 'client_ecommerce_id', 'status', 'current_stage',
                'assigned_admin_id', 'started_at', 'completed_at',
                'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'contacto' => ['migration_contact_phone'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'filtros'         => [
                'client_id'           => ['columna' => 'client_id',           'tipo' => 'entero'],
                'client_ecommerce_id' => ['columna' => 'client_ecommerce_id', 'tipo' => 'entero'],
                'assigned_admin_id'   => ['columna' => 'assigned_admin_id',   'tipo' => 'entero'],
                'current_stage'       => ['columna' => 'current_stage',       'tipo' => 'entero'],
                /* 🔴 Enumeración de MySQL, leída de information_schema el 3/9/2026:
                   enum('pending','in_progress','completed'). Son TRES: NO tiene el 'paused' de
                   implementations.status. */
                'status'              => ['columna' => 'status',              'tipo' => 'en', 'valores' => ['pending', 'in_progress', 'completed']],
                'sin_tienda'          => ['columna' => 'client_ecommerce_id', 'tipo' => 'nulo'],
                'ids'                 => ['columna' => 'id',                  'tipo' => 'lista_de_enteros'],
                'creado_desde'        => ['columna' => 'created_at',          'tipo' => 'fecha_desde'],
                'creado_hasta'        => ['columna' => 'created_at',          'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'cliente' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'clients',
                    'clave_local' => 'client_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'uuid', 'name', 'company_name', 'is_active', 'tiene_ecommerce'],
                ],
                'tienda' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'client_ecommerces',
                    'clave_local' => 'client_ecommerce_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'domain', 'spa_url', 'api_url', 'status'],
                ],
            ],
            'nota' => '🔴 Es la IMPLEMENTACIÓN (juntar la configuración de la tienda con el cliente), no la INSTALACIÓN (subir el código): eso es client_ecommerce_installation, otra tabla y otro pipeline. client_ecommerce_id puede ser NULL mientras la tienda todavía no existe.',
        ],

        /* ---------------------------------------------------------------
         | ecommerce_implementation_message — la conversación de la tienda.
         |
         | ⚠️ `body` es un `text` y va OPT-IN por el mismo motivo de volumen que
         | implementation_messages.body, y con la misma consecuencia: `busqueda`
         | sí lo mira, porque el opt-in es por tamaño y no por secreto.
         |
         | ⚠️ A diferencia de implementation_messages, esta tabla NO tiene
         | `phone`: verificado contra information_schema el 3/9/2026. Por eso acá
         | no hay opt-in de contacto — no es que se olvidó.
         --------------------------------------------------------------- */
        'ecommerce_implementation_message' => [
            'tabla'           => 'ecommerce_implementation_messages',
            'descripcion'     => 'Mensajes de WhatsApp de la implementación de una tienda, por etapa y dirección. El cuerpo viaja sólo con include=cuerpo.',
            'columnas'        => [
                'id', 'ecommerce_implementation_id', 'stage_number', 'direction',
                'whatsapp_message_id', 'sent_at', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'cuerpo' => ['body'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'desc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['body'],
            'filtros'         => [
                'ecommerce_implementation_id' => ['columna' => 'ecommerce_implementation_id', 'tipo' => 'entero'],
                'stage_number'                => ['columna' => 'stage_number',                'tipo' => 'entero'],
                /* Enumeración de MySQL, leída de information_schema: enum('inbound','outbound'). */
                'direction'                   => ['columna' => 'direction',                   'tipo' => 'en', 'valores' => ['inbound', 'outbound']],
                'sin_enviar'                  => ['columna' => 'sent_at',                     'tipo' => 'nulo'],
                'ids'                         => ['columna' => 'id',                          'tipo' => 'lista_de_enteros'],
                'creado_desde'                => ['columna' => 'created_at',                  'tipo' => 'fecha_desde'],
                'creado_hasta'                => ['columna' => 'created_at',                  'tipo' => 'fecha_hasta'],
            ],
            'relaciones'      => [
                'implementacion' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'ecommerce_implementations',
                    'clave_local' => 'ecommerce_implementation_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'client_ecommerce_id', 'current_stage', 'status'],
                ],
            ],
            'nota' => 'Ésta es la conversación por la que se junta la configuración de la tienda (dominio, colores, quiénes somos): la resuelve EcommerceImplementationConversationService de a una pregunta por vez.',
        ],

        /* ---------------------------------------------------------------
         | ecommerce_implementation_stage — el estado de cada etapa de la tienda.
         |
         | `data` va OPT-IN y NO afuera, al revés que implementation_stages.data,
         | y la diferencia está medida: acá el json guarda la configuración de la
         | TIENDA —domain, suggested_domain, colores, quienes_somos, instagram,
         | facebook, logo_url, current_question— y nada de eso es un dato personal
         | del titular. Verificado el 3/9/2026 recorriendo las escrituras de
         | EcommerceImplementationConversationService: no hay `email` ni
         | `doc_number`, que es lo que sí tiene el formulario del ERP. El opt-in
         | es por volumen y por texto libre (`quienes_somos`).
         --------------------------------------------------------------- */
        'ecommerce_implementation_stage' => [
            'tabla'           => 'ecommerce_implementation_stages',
            'descripcion'     => 'Estado de cada etapa de la implementación de una tienda. La configuración juntada (dominio, colores, quiénes somos) viaja con include=datos.',
            'columnas'        => [
                'id', 'ecommerce_implementation_id', 'stage_number', 'status',
                'started_at', 'completed_at', 'created_at', 'updated_at',
            ],
            'columnas_opt_in' => [
                'datos' => ['data'],
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 200,
            'limite_max'      => 500,
            'filtros'         => [
                'ecommerce_implementation_id' => ['columna' => 'ecommerce_implementation_id', 'tipo' => 'entero'],
                'stage_number'                => ['columna' => 'stage_number',                'tipo' => 'entero'],
                /* 🔴 Enumeración de MySQL, leída de information_schema el 3/9/2026:
                   enum('pending','in_progress','completed'). Son TRES: NO tiene el 'skipped' de
                   implementation_stages.status. Pedir status=skipped acá es 422, y está bien que
                   lo sea. */
                'status'                      => ['columna' => 'status',                      'tipo' => 'en', 'valores' => ['pending', 'in_progress', 'completed']],
                'ids'                         => ['columna' => 'id',                          'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [
                'implementacion' => [
                    'tipo'        => 'belongs_to', 'tabla' => 'ecommerce_implementations',
                    'clave_local' => 'ecommerce_implementation_id', 'clave_externa' => 'id',
                    'columnas'    => ['id', 'client_id', 'client_ecommerce_id', 'current_stage', 'status'],
                ],
            ],
            'nota' => 'El avance de la conversación se sigue por data.current_question (include=datos): "completed" ahí significa que la etapa terminó de juntar todo. El logo cargado vive en data.logo_url del stage 1.',
        ],

        /* ---------------------------------------------------------------
         | ecommerce_implementation_stage_config — la definición de las etapas de
         | la tienda.
         |
         | Mismo molde que implementation_stage_configs, con una diferencia real:
         | acá `description` es un varchar(191) y allá es un `text`. Verificado
         | contra information_schema el 3/9/2026.
         |
         | ⚠️ Tabla sin `uuid`.
         --------------------------------------------------------------- */
        'ecommerce_implementation_stage_config' => [
            'tabla'           => 'ecommerce_implementation_stage_configs',
            'descripcion'     => 'Definición de cada etapa de la implementación de una tienda: nombre, umbral de alerta en horas, si está automatizada y si está activa.',
            'columnas'        => [
                'id', 'stage_number', 'name', 'description',
                'alert_threshold_hours', 'is_automated', 'active',
                'created_at', 'updated_at',
            ],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 100,
            'limite_max'      => 300,
            'busqueda'        => ['name', 'description'],
            'filtros'         => [
                'stage_number' => ['columna' => 'stage_number', 'tipo' => 'entero'],
                'is_automated' => ['columna' => 'is_automated', 'tipo' => 'booleano'],
                'active'       => ['columna' => 'active',       'tipo' => 'booleano'],
                'ids'          => ['columna' => 'id',           'tipo' => 'lista_de_enteros'],
            ],
            'relaciones'      => [],
            'nota' => 'Catálogo único para todas las implementaciones de tienda: no hay una fila por cliente. Es la tabla hermana de implementation_stage_configs, pero las etapas NO son las mismas ni son la misma cantidad.',
        ],
    ],

    /*
     | Modelos que NO están en /query, con el motivo. El catálogo lo publica tal
     | cual: un "no está" con motivo escrito vale mil veces más que un 422 pelado.
     |
     | 🔴 El motivo `secreto` nombra SIEMPRE la columna concreta que lo justifica.
     | Un "por las dudas" no es un motivo: el que venga después no puede saber si
     | revisar de nuevo o confiar.
     */
    'modelos_excluidos' => [
        'Admin'                            => ['motivo' => 'secreto', 'columna' => 'admins.password / admins.remember_token', 'usar' => 'GET claude/admins'],
        'AdminCalendarConnection'          => ['motivo' => 'secreto', 'columna' => 'admin_calendar_connections.google_refresh_token_encrypted'],
        'AdminPushSubscription'            => ['motivo' => 'secreto', 'columna' => 'admin_push_subscriptions.p256dh y admin_push_subscriptions.auth (claves de cifrado de Web Push, sin ninguna palabra obvia en el nombre)'],
        'AdminSetting'                     => ['motivo' => 'secreto', 'columna' => 'admin_settings.value (guarda implementation_google_api_key_default e implementation_google_api_key_demo)'],
        'ClientSshCredential'              => ['motivo' => 'secreto', 'columna' => 'client_ssh_credentials.password (cast encrypted)'],
        'WhatsappConfig'                   => ['motivo' => 'secreto', 'columna' => 'whatsapp_config.kapso_api_key, .webhook_secret, .meta_webhook_token'],
        'RecallConfig'                     => ['motivo' => 'secreto', 'columna' => 'recall_configs.recall_api_key, .webhook_secret'],
        'MensualidadInvoicePdfAccessToken' => ['motivo' => 'secreto', 'columna' => 'mensualidad_invoice_pdf_access_tokens.token (abre el PDF de una factura fuera de auth:sanctum)'],
        'EnvTemplate'                      => ['motivo' => 'secreto', 'columna' => 'env_templates.value'],
        'EnvChangeBatch'                   => ['motivo' => 'secreto', 'columna' => 'env_change_batches.token'],
        'EnvChangeItem'                    => ['motivo' => 'secreto', 'columna' => 'env_change_items.new_value_encrypted, .new_value_sha256, .old_value_masked (valores del .env de clientes reales)'],
        /* 🔴 NO TIENE MODELO EN app/Models/ —la clase es de Sanctum, vive en vendor—, y por eso
           figuraba en NINGUNA de las dos listas: ni servible ni denunciado. Se declara igual, que es
           todo el punto de esta lista: el que amplíe `modelos` mañana tiene que encontrar escrito
           por qué esta tabla no va, en vez de mirar que "no está" y sumarla sin pensarlo. */
        'PersonalAccessToken'              => ['motivo' => 'secreto', 'columna' => 'personal_access_tokens.token (el hash del token de Sanctum con el que se autentica el panel; también matchea `columnas_prohibidas`)'],

        'SyncedGithubFile'                 => ['motivo' => 'volumen', 'columna' => 'synced_github_files.content (longText con archivos enteros)'],
        'DeploymentLog'                    => ['motivo' => 'volumen', 'columna' => 'deployment_logs.line', 'usar' => 'GET claude/upgrades/{id}/logs'],
        /* ⚠️ El motivo declarado sigue siendo el volumen, pero no es lo único que hay
           que saber de esta tabla, y por eso va la `nota`: sus líneas traen los
           comandos remotos CRUDOS, sin sanear. `EcommerceInstallationService`
           loguea cada comando entero antes de correrlo (`$this->log($step, '$ ' .
           $command)`, línea 2046), y uno de esos comandos es el `printf '%s'
           '<contenido del .env del SPA>'` que escribe el `.env` de tienda-spa
           (líneas 538-543). O sea: el contenido de ese archivo termina escrito en
           `ecommerce_deployment_logs.line`, y `max_line_chars` del endpoint de logs
           tiene piso pero no techo.
           🔴 VERIFICADO QUE NO ES UNA FUGA, y por eso el comportamiento NO se cambia:
           ese `.env` sólo lleva variables `VUE_APP_*`, que Vite compila DENTRO del
           bundle público de la tienda — son claves de frontend, públicas por diseño.
           El `.env` del servidor (`DB_PASSWORD`, `APP_KEY`) lo escribe
           `EnvSshService::write_env_vars`, que no loguea nada.
           La nota existe para que el próximo que lea estas líneas no asuma que
           están filtradas, y para que el que agregue un `printf` de otro archivo al
           pipeline sepa que lo está publicando en el log. */
        'EcommerceDeploymentLog'           => ['motivo' => 'volumen', 'columna' => 'ecommerce_deployment_logs.line', 'usar' => 'GET claude/ecommerce/installations/{id}/logs', 'nota' => 'Además del volumen: estas líneas traen los comandos remotos CRUDOS, sin filtrar ni enmascarar (incluido el printf que escribe el .env del SPA). Hoy ese .env sólo tiene variables VUE_APP_*, que ya viajan dentro del bundle público de la tienda, así que no hay secreto expuesto; el .env del servidor lo escribe EnvSshService, que no loguea. Leelas sabiendo qué son: la salida cruda de una sesión SSH, no un log saneado.'],

        'LeadMessage'                      => ['motivo' => 'duplicado', 'usar' => 'GET claude/messages'],
        'AdminColumnPreference'            => ['motivo' => 'sin valor operativo (preferencias de columnas de la SPA)'],


        'DemoInstallationLog'              => ['motivo' => 'volumen', 'columna' => 'demo_installation_logs.line', 'nota' => 'Mismo caso que DeploymentLog y EcommerceDeploymentLog: una fila por línea de la sesión SSH de la instalación de una demo (upload_spa, upload_api, npm run build), y son miles por corrida. A diferencia de esos dos, ESTA tabla todavía no tiene un endpoint claude/* que la sirva truncada: los logs de una instalación de demo se miran desde el panel del admin.'],
        /* 🔴 NO ESTÁ EXCLUIDA POR UN SECRETO NI POR VOLUMEN: está excluida porque este endpoint no
           la puede paginar sin mentir. Es el único modelo de la tanda del 3/9/2026 que se dejó
           afuera por una razón de forma y no de contenido, así que el motivo va explicado entero. */
        'LeadAdminNotification'            => ['motivo' => 'sin clave de cursor única', 'columna' => 'lead_admin_notifications: clave primaria compuesta (lead_id, admin_id), sin `id` ni timestamps', 'nota' => 'La paginación de claude/query es por cursor sobre UNA columna, y `lead_id` se repite cuando hay más de un admin suscrito al mismo lead. Una página que cortara adentro de un lead dejaría afuera a los admins restantes SIN QUE NADA LO DENUNCIE: la respuesta se vería completa. Es exactamente la clase de error que este archivo existe para no cometer, así que se prefiere no publicar el modelo antes que publicarlo pudiendo mentir. Si algún día hace falta, el arreglo es una migración que le dé un `id` a la tabla, no un cambio en este config.'],
    ],

    /*
     | Lo que hay que saber sobre TODO lo que no está en ninguna de las dos listas
     | de arriba. Se publica tal cual en GET claude/catalog.
     |
     | 🔴 Ya no queda casi nada: al 3/9/2026 son 7 los modelos de app/Models/ que no
     | figuran en ninguna de las dos listas (81 archivos en app/Models/, 56 en
     | `modelos` y 18 en `modelos_excluidos`), y están enumerados uno por uno en la
     | nota de abajo. NO están excluidos por tener un secreto conocido: están fuera
     | porque no se verificaron columna por columna, y meter uno sin verificar es
     | exactamente lo que este archivo promete que no pasa. Agregar uno son ~25
     | líneas acá y un DESCRIBE de la tabla, no una misión.
     */
    'nota_de_exclusiones' => 'Los modelos que no figuran ni en `modelos` ni en `modelos_excluidos` están fuera de la tanda: sin secreto conocido, pero sin verificar columna por columna. El 3/9/2026 entraron los cinco bloques probables (comercial y agentes, soporte, demos, implementaciones y facturación) y con eso se cerraron las tres advertencias que esta nota tenía escritas: MensualidadInvoice, Implementation y SupportTicket ya están en `modelos`, con `request`/`response`, `form_token` y la PII afuera o en opt-in. Ese mismo día Lead pasó de `modelos_excluidos` a `modelos`: los dos tokens que lo excluían (demo_ingreso_token, demo_eventos_token) y las 17 contract_* quedan afuera por lista blanca, igual que api_key y afip_* en client, y la PII viaja sólo con include=contacto. Lo que queda sin declarar son SIETE modelos y se dejaron a propósito: marcas de lectura de la SPA (client_notification_reads, lead_manual_unread_marks, lead_message_reads), estado efímero (support_typing_states), notificaciones de tareas (admin_task_notifications), plantillas de tarea (task_templates) y bloques de user_id (user_id_blocks). No cuentan como "sin declarar" las tablas pivote de modelos que ya están (admin_task_assignees, client_version_upgrade_versions, version_item_clients), que no tienen modelo propio, ni las tablas de infraestructura de Laravel (jobs, failed_jobs, migrations), que no son datos del negocio. personal_access_tokens tampoco tiene modelo en app/Models/ —la clase es de Sanctum— pero SÍ está declarada en `modelos_excluidos`, porque guarda el hash del token con el que se autentica el panel y no queremos que el próximo la sume sin pensarlo. Agregar uno son ~25 líneas acá y un DESCRIBE de la tabla, no una misión.',
];
