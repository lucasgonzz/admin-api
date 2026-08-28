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
        'Lead'                             => ['motivo' => 'secreto', 'columna' => 'leads.demo_ingreso_token, leads.demo_eventos_token', 'usar' => 'GET claude/leads'],

        'SyncedGithubFile'                 => ['motivo' => 'volumen', 'columna' => 'synced_github_files.content (longText con archivos enteros)'],
        'DeploymentLog'                    => ['motivo' => 'volumen', 'columna' => 'deployment_logs.line', 'usar' => 'GET claude/upgrades/{id}/logs'],
        'EcommerceDeploymentLog'           => ['motivo' => 'volumen', 'columna' => 'ecommerce_deployment_logs.line', 'usar' => 'GET claude/ecommerce/installations/{id}/logs'],

        'LeadMessage'                      => ['motivo' => 'duplicado', 'usar' => 'GET claude/messages'],
        'AdminColumnPreference'            => ['motivo' => 'sin valor operativo (preferencias de columnas de la SPA)'],

    ],

    /*
     | Lo que hay que saber sobre TODO lo que no está en ninguna de las dos listas
     | de arriba. Se publica tal cual en GET claude/catalog.
     |
     | 🔴 Los ~43 modelos restantes de app/Models/ NO están excluidos por tener un
     | secreto conocido: están fuera de esta tanda porque no se verificaron columna
     | por columna, y meter uno sin verificar es exactamente lo que este archivo
     | promete que no pasa. Agregar uno son ~10 líneas acá y un DESCRIBE de la
     | tabla, no una misión.
     */
    'nota_de_exclusiones' => 'Los modelos que no figuran ni en `modelos` ni en `modelos_excluidos` están fuera de la tanda: sin secreto conocido, pero sin verificar columna por columna. Tres tienen la advertencia ya escrita para el que los sume: MensualidadInvoice (`request` y `response` son longText con el SOAP crudo a AFIP, que lleva el token y el sign de la sesión WSAA), Implementation (`form_token` abre el formulario público sin auth) y SupportTicket (`client_user_email` es PII y va opt-in).',
];
