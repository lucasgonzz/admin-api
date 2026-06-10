<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder as VersionSeederModel;
use Illuminate\Database\Seeder;

/**
 * Seeder incremental: agrega versiones posteriores a 2.1.1 desde el Excel
 * Actualizaciones.xlsx (hojas Versiones, Seeders, Comandos, Notificaciones).
 *
 * Ejecutar DESPUÉS de RealVersionesProductionSeeder, cuando admin ya tiene
 * cargado el histórico hasta 2.1.1 inclusive.
 *
 * Ejecutar: php artisan db:seed --class=RealVersionesIncrementalSeeder
 *
 * Incorpora:
 *   - Correcciones/backfill de ítems faltantes en versiones <= 2.1.1
 *   - Versiones nuevas: 2.1.2, 2.1.3, 2.1.4, 3.0.1
 */
class RealVersionesIncrementalSeeder extends Seeder
{
    /**
     * Última versión ya cargada por RealVersionesProductionSeeder en admin.
     * Solo se agregan versiones estrictamente posteriores.
     */
    const LAST_LOADED_VERSION = '2.1.1';

    /**
     * Punto de entrada del seeder incremental.
     * Carga clientes existentes, sincroniza versiones nuevas y actualiza
     * current_version_id solo de los clientes que avanzaron según el Excel.
     *
     * @return void
     */
    public function run()
    {
        /* Mapa subdominio → Client (ya creados por el seeder inicial) */
        $clients = $this->load_clients();

        if (empty($clients)) {
            $this->command->error('No se encontraron clientes. Ejecutá primero RealVersionesProductionSeeder.');
            return;
        }

        /* Primero: correcciones de ítems que faltaron al correr el seeder inicial */
        $all_version_batches = array_merge(
            $this->correction_versions(),
            $this->incremental_versions()
        );

        foreach ($all_version_batches as $version_data) {

            /* Versión padre: clave única por código semver */
            $version_model = Version::firstOrCreate(
                ['version' => $version_data['version']],
                [
                    'title'        => $version_data['title'] ?? ('Versión ' . $version_data['version']),
                    'description'  => $version_data['description'] ?? null,
                    'status'       => $version_data['status'] ?? 'published',
                    'published_at' => $version_data['published_at'] ?? now(),
                ]
            );

            $this->sync_notifications($version_model, $version_data['notifications'] ?? [], $clients);
            $this->sync_seeders($version_model, $version_data['seeders'] ?? []);
            $this->sync_commands($version_model, $version_data['commands'] ?? []);
            $this->sync_manual_tasks($version_model, $version_data['manual_tasks'] ?? []);

            $this->command->info('Versión ' . $version_model->version . ' sincronizada.');
        }

        /* Actualizamos versión actual solo de clientes que avanzaron */
        $this->update_client_versions($clients);

        $this->command->info('Actualización incremental completada (desde ' . self::LAST_LOADED_VERSION . ').');
    }

    // =========================================================================
    // CLIENTES (solo lectura)
    // =========================================================================

    /**
     * Carga clientes existentes en admin usando el mismo mapa subdominio → nombre
     * que RealVersionesProductionSeeder. No crea clientes ni ClientApi.
     *
     * @return array<string, Client>  Mapa subdominio → instancia Client
     */
    private function load_clients(): array
    {
        /* Mismo mapa que RealVersionesProductionSeeder: clave = subdominio */
        $clients_data = [
            'servian'       => 'Servian',
            'masquito'      => 'Masquito',
            'sanblas'       => 'SanBlas',
            '2r'            => '2R',
            'ferretotal'    => 'Ferretotal',
            'trama'         => 'Trama',
            'golden-breeze' => 'Golden Breeze',
            'leudinox'      => 'Leudinox',
            'panchito'      => 'Panchito',
            'distri-creo'   => 'Distri-Creo',
            'golonorte'     => 'GoloNorte',
            'innovate'      => 'Innovate',
            'rober'         => 'Rober',
            'san-cayetano'  => 'San Cayetano',
            'truvari'       => 'Truvari',
            'lamartina'     => 'La Martina',
            'arfren'        => 'Arfren',
            'empresa'       => 'Empresa',
            'hipermax'      => 'Empresa - HiperMax',
            'fenix'         => 'Empresa - Fenix',
            'galvan'        => 'Empresa - Galvan',
            'cf'            => 'CF2',
            'chevrocar'     => 'ChevroCar',
            '3dtisk'        => '3DTisk',
            'oliva'         => 'Oliva',
            'ffperformance' => 'FFPerformance',
            'ht5'           => 'HT5',
            'mbmalizia'     => 'MBMalizia',
            'ananda'        => 'Ananda',
            'ferremas'      => 'FerreMas',
            'lacarra'       => 'Lacarra',
            'demo'          => 'DEMO',
            'demo2'         => 'DEMO2',
            'hb'            => 'HBDistribuciones',
        ];

        /* Mapa resultado: subdominio → Client encontrado en BD */
        $clients = [];

        foreach ($clients_data as $subdomain_slug => $name) {
            $client = Client::where('name', $name)->first();

            if ($client) {
                $clients[$subdomain_slug] = $client;
            } else {
                $this->command->warn("Cliente no encontrado: $name ($subdomain_slug)");
            }
        }

        return $clients;
    }

    /**
     * Actualiza current_version_id de los clientes que avanzaron según el Excel
     * (marcas en hojas Seeders, Comandos y Notificaciones para versiones >= 2.1.2).
     *
     * @param  array<string, Client> $clients
     * @return void
     */
    private function update_client_versions(array $clients): void
    {
        /* Versión actual deducida del Excel actualizado (solo clientes que cambiaron) */
        $updated_current_versions = [
            'empresa'    => '2.1.3',
            'ferretotal' => '2.1.3',
            'golonorte'  => '2.1.3',
            'trama'      => '2.1.3',
            'truvari'    => '2.1.4',
            'servian'    => '2.1.4',
            'fenix'      => '2.1.4',
        ];

        /* Precargamos version_string → id */
        $version_map = Version::pluck('id', 'version')->all();

        foreach ($updated_current_versions as $slug => $version_str) {
            if (!isset($clients[$slug])) {
                continue;
            }
            if (!isset($version_map[$version_str])) {
                $this->command->warn("Versión $version_str no encontrada para cliente $slug");
                continue;
            }

            $clients[$slug]->update(['current_version_id' => $version_map[$version_str]]);
            $this->command->info("Cliente $slug → versión $version_str");
        }
    }

    // =========================================================================
    // SINCRONIZACIÓN DE ÍTEMS
    // =========================================================================

    /**
     * Sincroniza las notificaciones de una versión.
     *
     * @param  Version               $version_model
     * @param  array<int, array>     $notifications
     * @param  array<string, Client> $clients
     * @return void
     */
    private function sync_notifications(Version $version_model, array $notifications, array $clients): void
    {
        /* sort_order auto-incremental por versión */
        $order = 1;

        foreach ($notifications as $notification) {
            $notif = VersionNotification::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'title'      => $notification['title'],
                ],
                [
                    'body'       => $notification['body'] ?? '',
                    'sort_order' => $notification['sort_order'] ?? $order,
                    'is_active'  => true,
                ]
            );

            if (!empty($notification['restricted_to_client_slug'])) {
                $slug = $notification['restricted_to_client_slug'];

                if (isset($clients[$slug])) {
                    $notif->restrictedClients()->syncWithoutDetaching([$clients[$slug]->id]);
                }
            }

            $order++;
        }
    }

    /**
     * Sincroniza los seeders de una versión.
     *
     * @param  Version           $version_model
     * @param  array<int, array> $seeders
     * @return void
     */
    private function sync_seeders(Version $version_model, array $seeders): void
    {
        foreach ($seeders as $seeder) {
            VersionSeederModel::firstOrCreate(
                [
                    'version_id'   => $version_model->id,
                    'seeder_class' => $seeder['seeder_class'],
                ],
                [
                    'description'     => $seeder['description'] ?? null,
                    'execution_order' => (int) ($seeder['execution_order'] ?? 0),
                    'is_required'     => $seeder['is_required'] ?? true,
                    'run_scope'       => $seeder['run_scope'] ?? 'per_database',
                ]
            );
        }
    }

    /**
     * Sincroniza los comandos artisan de una versión.
     *
     * @param  Version           $version_model
     * @param  array<int, array> $commands
     * @return void
     */
    private function sync_commands(Version $version_model, array $commands): void
    {
        foreach ($commands as $command) {
            VersionCommand::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'command'    => $command['command'],
                ],
                [
                    'description'     => $command['description'] ?? null,
                    'execution_order' => (int) ($command['execution_order'] ?? 0),
                    'is_required'     => $command['is_required'] ?? true,
                    'run_scope'       => $command['run_scope'] ?? 'per_user',
                ]
            );
        }
    }

    /**
     * Sincroniza las tareas manuales de una versión.
     *
     * @param  Version           $version_model
     * @param  array<int, array> $manual_tasks
     * @return void
     */
    private function sync_manual_tasks(Version $version_model, array $manual_tasks): void
    {
        foreach ($manual_tasks as $manual_task) {
            VersionManualTask::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'title'      => $manual_task['title'],
                ],
                [
                    'description'     => $manual_task['description'] ?? null,
                    'execution_order' => (int) ($manual_task['execution_order'] ?? 0),
                    'is_required'     => $manual_task['is_required'] ?? true,
                ]
            );
        }
    }

    // =========================================================================
    // CORRECCIONES (backfill versiones <= 2.1.1)
    // =========================================================================

    /**
     * Ítems detectados faltantes al auditar el Excel vs RealVersionesProductionSeeder.
     * Usa firstOrCreate: seguro re-ejecutar aunque el seeder inicial ya corrió.
     *
     * @return array<int, array<string, mixed>>
     */
    private function correction_versions(): array
    {
        return [

            // -----------------------------------------------------------------
            // 2.0.1 — Notificaciones de pedidos online faltantes para Galvan y Ferretotal
            // -----------------------------------------------------------------
            [
                'version' => '2.0.1',
                'title'   => 'Versión 2.0.1',
                'notifications' => [
                    [
                        'title'                     => 'Pedidos online: asignar estados libremente (Galvan)',
                        'body'                       => "Ahora vas a poder asignar libremente el estado que quieras a los pedidos de la tienda online.\n\nCuando cambies de \"Sin confirmar\" a cualquier otro estado que no sea \"Cancelado\", el sistema va a proceder a crear la venta correspondiente.",
                        'restricted_to_client_slug'  => 'galvan',
                    ],
                    [
                        'title'                     => 'Pedidos online: asignar estados libremente (Ferretotal)',
                        'body'                       => "Ahora vas a poder asignar libremente el estado que quieras a los pedidos de la tienda online.\n\nCuando cambies de \"Sin confirmar\" a cualquier otro estado que no sea \"Cancelado\", el sistema va a proceder a crear la venta correspondiente.",
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.1.1 — Seeders que debían estar y pueden faltar en admin si el seeder
            //          inicial se corrió antes de incluirlos en el array
            // -----------------------------------------------------------------
            [
                'version' => '2.1.1',
                'title'   => 'Versión 2.1.1',
                'seeders' => [
                    ['seeder_class' => 'PermisosVerStockSeeder',                         'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PermissionVenderDiscountStockIvaAplicadoSeeder',  'execution_order' => 2, 'run_scope' => 'per_database'],
                ],
                'commands' => [
                    ['command' => 'php artisan check_to_pay_id_de_ventas_eliminadas {user_id?}', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
            ],
        ];
    }

    // =========================================================================
    // VERSIONES NUEVAS (posteriores a 2.1.1)
    // =========================================================================

    /**
     * Versiones nuevas del Excel Actualizaciones.xlsx (Claude).
     * Fuente: filas con versión > 2.1.1 en hojas Versiones, Seeders,
     * Comandos y Notificaciones.
     *
     * @return array<int, array<string, mixed>>
     */
    private function incremental_versions(): array
    {
        return [

            // -----------------------------------------------------------------
            // 2.1.2 — Registrada en planilla sin ítems de despliegue
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.2',
                'title'       => 'Versión 2.1.2',
                'description' => 'Versión registrada en planilla sin seeders, comandos ni notificaciones.',
            ],

            // -----------------------------------------------------------------
            // 2.1.3
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.3',
                'title'       => 'Versión 2.1.3',
                'description' => 'Etiquetas configurables, rotación de imágenes en tablas, búsqueda por código de barras en VENDER y actualizaciones masivas en segundo plano.',

                'seeders' => [
                    ['seeder_class' => 'ExtencionBarCodeEnVenderSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'EtiquetaMedidasSeeder',          'execution_order' => 2, 'run_scope' => 'per_database'],
                ],

                'notifications' => [
                    [
                        'title' => 'Imagenes en tablas: rotacion automatica',
                        'body'  => "En las tablas del sistema (listados y buscador), cuando un articulo tiene varias fotos, se muestra la primera y cada 2 segundos pasa a la siguiente hasta recorrerlas todas.\n\nAl volver a la primera imagen, la rotacion se detiene sola.\n\nSi pasas el mouse sobre la foto, avanza a la siguiente; para ver otra mas, saca el mouse y volve a posarlo.\n\nAl hacer clic en la imagen, seguis pudiendo abrir la vista ampliada como antes.",
                    ],
                    [
                        'title' => 'Etiquetas de articulos configurables: elegis medida, campos y orden antes de generar el PDF',
                        'body'  => "En el listado de articulos, al usar Codigos de barra (Etiquetas) ya no se descarga el PDF al instante: se abre un panel para armar la etiqueta como necesites.\n\nPodes elegir entre medidas guardadas (por ejemplo 100x50, 80x55, 90x45, 120x60 en pixeles) o crear una medida propia con nombre, ancho y alto.\n\nMarcas que datos van en la etiqueta - nombre, codigo de barras, SKU, precio, categoria, marca, codigo de proveedor, fecha del dia, nombre del negocio - y los ordenas arrastrando: ese orden es el que se ve de arriba hacia abajo en la etiqueta.\n\nHay una vista previa orientativa con el tamano y los textos que elegiste, para revisar antes de imprimir.\n\nAl confirmar Generar PDF, se abre el archivo con esas opciones. Las medidas estandar no se pueden borrar; las que creas vos si.",
                    ],
                    [
                        'title' => 'Actualizaciones masivas de articulos en segundo plano, con historial y reversion',
                        'body'  => "Al actualizar articulos seleccionados o filtrados, el proceso corre en segundo plano: podes seguir trabajando y te avisamos con una notificacion cuando termine.\n\nEn el menu Crear del listado, nueva opcion Historial de actualizaciones masivas: ves fecha, quien la hizo, cuantos articulos se tocaron y el detalle de cada cambio (propiedad, valor anterior y nuevo).\n\nSi algo no quedo como esperabas, podes Revertir una actualizacion desde ese historial; la reversion tambien corre en segundo plano y te notifica al finalizar.",
                    ],
                    [
                        'title'                     => 'Nueva opcion en Configuracion Online: mostrar u ocultar la seccion Catalogo en la tienda',
                        'body'                       => "En Configuracion Online podes activar \"Mostrar seccion Catalogo en la Tienda\" para que aparezca el enlace Catalogo en el navbar de la tienda online.\n\nPor defecto la opcion viene desactivada: las tiendas existentes no muestran Catalogo hasta que la actives y guardes.\n\nSi esta desactivada, el enlace no se ve en el menu; la pagina /catalogo puede seguir accesible por URL directa.",
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Se achico el espacio entre secciones en el inicio de la tienda online',
                        'body'                       => 'Se achico el espacio entre secciones en el inicio de la tienda online.',
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Busqueda por codigo de barra en el buscador por nombre de VENDER',
                        'body'                       => "Con una sola palabra o codigo escaneado, la busqueda busca coincidencia exacta; con varias palabras, tambien puede encontrar codigos parciales.",
                        'restricted_to_client_slug'  => 'servian',
                    ],
                    [
                        'title'                     => 'Listado de articulos: el buscador puede filtrar la tabla para trabajar en lote sobre los resultados',
                        'body'                       => "En el listado podes buscar por nombre, codigo o proveedor (y por categoria/stock) y los resultados se muestran directo en la tabla, sin abrir la pestana de seleccion de un solo articulo.\n\nSobre esos articulos filtrados podes usar las mismas acciones de siempre que no dependen de la pestana de filtros: generar etiquetas, PDF, exportar a Excel, etc., y tambien marcar manualmente los que quieras en la tabla.\n\nLas opciones Actualizar y Eliminar del menu de \"filtrados\" no aparecen en esta busqueda rapida, porque esas acciones masivas requieren los criterios del filtro avanzado. Para actualizar o eliminar varios articulos de una busqueda asi, seleccionalos en la tabla y usa el menu de Seleccion.\n\nEl boton para limpiar la busqueda y volver al listado completo queda al lado de Buscar cuando hay resultados activos de esta busqueda.",
                        'restricted_to_client_slug'  => 'servian',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.1.4
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.4',
                'title'       => 'Versión 2.1.4',
                'description' => 'WhatsApp con diseño PDF configurable y plantilla predeterminada para PDF tabla de articulos.',

                'commands' => [
                    ['command' => 'php artisan pdf-column-profiles:set-whatsapp-defaults', 'execution_order' => 1, 'run_scope' => 'per_user'],
                    ['command' => 'php artisan pdf-column-profiles:sync-article-setup',    'execution_order' => 2, 'run_scope' => 'per_user'],
                ],

                'notifications' => [
                    [
                        'title' => 'WhatsApp en ventas: ahora envia el comprobante con el diseño PDF que ustedes eligen (remito o factura ARCA)',
                        'body'  => "Al usar el boton de WhatsApp en una venta, el enlace del comprobante usa un perfil de columnas PDF configurado, no un formato fijo generico.\n\nPodes marcar un perfil como \"Predeterminado WhatsApp (remito)\" para ventas sin factura electronica (comprobante habitual con precios).\n\nPodes marcar otro perfil -con \"Factura de ARCA\" activado- como \"Predeterminado WhatsApp (factura)\" para cuando la venta tenga factura con CAE; en ese caso solo se envia el diseno fiscal, no el de remito.",
                    ],
                    [
                        'title' => 'PDF tabla de articulos: plantilla predeterminada, validacion de anchos y sincronizacion al actualizar',
                        'body'  => "En Configuracion -> Generales -> Perfiles de columnas PDF (modelo Articulo) podes definir columnas, orden y anchos.\n\nTras actualizar el sistema, cada empresa recibe el perfil \"Lista de articulos\" con tres columnas visibles por defecto: Imagenes (40 mm), Nombre del articulo (120 mm, con salto de linea) y Precio final (40 mm), en ese orden.\n\nEn el listado de articulos, en el menu de seleccionados o filtrados, la seccion PDF tabla (plantillas) permite generar el PDF con los articulos elegidos o con todos los del filtro activo.",
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 3.0.1
            // -----------------------------------------------------------------
            [
                'version'     => '3.0.1',
                'title'       => 'Versión 3.0.1',
                'description' => 'PDF tabular de articulos con mas control de columnas, encabezados configurables e importacion Excel asistida por IA.',

                'seeders' => [
                    ['seeder_class' => 'ExtencionEmpresaAiExcelImportSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],

                'notifications' => [
                    [
                        'title' => 'PDF tabular de articulos: mas control de columnas y encabezados',
                        'body'  => "En Configuracion -> Generales -> Perfiles PDF de articulos podes definir, por columna, el tamano de letra y la alineacion horizontal (izquierda, centro o derecha). Si dejas la alineacion en \"Automatica\", se mantiene el criterio habitual (precios a la derecha, textos largos a la izquierda, etc.).\n\nNuevo campo \"Letra encabezado columnas (pt)\": todos los titulos de columna (Imagen, Nombre, Precio, Stock, etc.) usan el mismo tamano, independiente del tamano de cada columna en los datos.",
                    ],
                    [
                        'title' => 'Buscador rapido en Clientes, Proveedores y Cajas',
                        'body'  => "Ahora podes buscar por texto directamente desde el listado, sin abrir el formulario de filtros.\n\nEscribi al menos 2 caracteres y presiona Enter o el boton Buscar para ver los resultados en la tabla.\n\nEn Clientes podes buscar por nombre, email, telefono, direccion, CUIL, CUIT, DNI, razon social y otros datos.\n\nEn Proveedores podes buscar por nombre, telefono, direccion, email, razon social, CUIT y observaciones.\n\nCuando hay una busqueda activa, aparece un boton para limpiar y volver al listado completo.",
                    ],
                ],
            ],
        ];
    }
}
