<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder as VersionSeederModel;
use Illuminate\Database\Seeder;

/**
 * Siembra los clientes reales de producción, todas las versiones desplegadas
 * y los ítems asociados a cada versión: seeders, comandos artisan, tareas
 * manuales y notificaciones.
 *
 * Origen de datos: planilla "Actualizaciones.xlsx" (hojas Versiones, Seeders,
 * Comandos y Notificaciones).
 *
 * Ejecutar: php artisan db:seed --class=RealVersionesProductionSeeder
 *
 * Criterios de run_scope por hoja:
 *   Hoja Seeders  → celda col-B verde (bg=15)  = per_user  / sin color = per_database
 *   Hoja Comandos → celda col-B verde (bg=15)  = per_database / sin color = per_user
 */
class RealVersionesProductionSeeder extends Seeder
{
    /**
     * Punto de entrada del seeder.
     * Crea clientes, versiones con todos sus ítems y fija la versión actual
     * de cada cliente.
     *
     * @return void
     */
    public function run()
    {
        /* Creamos los clientes de producción y guardamos el mapa slug → Client */
        $clients = $this->create_clients();

        /* Creamos versiones e ítems asociados */
        foreach ($this->production_versions() as $version_data) {

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

        /* Asignamos current_version_id a cada cliente */
        $this->set_client_versions($clients);

        $this->command->info('Clientes actualizados con su versión actual.');
    }

    // =========================================================================
    // CLIENTES
    // =========================================================================

    /**
     * Crea o recupera todos los clientes reales de producción.
     * Usa el nombre canónico como clave de búsqueda.
     *
     * @return array<string, Client>  Mapa slug → instancia Client
     */
    private function create_clients(): array
    {
        /* Mapa: slug interno → nombre a mostrar en la BD */
        /* Clave del array = subdominio para ClientApi (no se guarda en clients.slug) */
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
            'ananda'          => 'Ananda',
            'ferremas'      => 'FerreMas',
            'lacarra'       => 'Lacarra',
            'demo'          => 'DEMO',
            'demo2'         => 'DEMO2',
            'hb'            => 'HBDistribuciones',
        ];

        /* Mapa resultado: subdominio → Client */
        $clients = [];

        foreach ($clients_data as $subdomain_slug => $name) {
            /* Cliente por nombre canónico; no persistimos slug en la tabla clients */
            $clients[$subdomain_slug] = Client::firstOrCreate(
                ['name' => $name],
                ['is_active' => true]
            );

            /* Dos endpoints por cliente: producción y réplica "2" */
            $this->sync_client_apis($clients[$subdomain_slug], $subdomain_slug);
        }

        return $clients;
    }

    /**
     * Crea o recupera las dos ClientApi de un cliente según su subdominio.
     *
     * Instancia 1: api-{slug}.comerciocity.com + {slug}.comerciocity.com
     * Instancia 2: api-{slug}2.comerciocity.com + {slug}2.comerciocity.com
     *
     * @param  Client  $client
     * @param  string  $subdomain_slug  Subdominio (clave del array clients_data)
     * @return void
     */
    private function sync_client_apis(Client $client, string $subdomain_slug): void
    {
        /* Definición de los dos entornos por cliente */
        $client_api_endpoints = [
            [
                'url'     => 'https://api-' . $subdomain_slug . '.comerciocity.com/public',
                'path'    => $subdomain_slug . '/api',
                'spa_url' => 'https://' . $subdomain_slug . '.comerciocity.com',
            ],
            [
                'url'     => 'https://api-' . $subdomain_slug . '2.comerciocity.com/public',
                'path'    => $subdomain_slug . '2/api',
                'spa_url' => 'https://' . $subdomain_slug . '2.comerciocity.com',
            ],
        ];

        foreach ($client_api_endpoints as $endpoint) {
            ClientApi::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'url'       => $endpoint['url'],
                ],
                [
                    'path'         => $endpoint['path'],
                    'spa_url'      => $endpoint['spa_url'],
                    'hosting_type' => 'shared_hosting',
                ]
            );
        }
    }

    /**
     * Actualiza la versión actual de cada cliente en base a la última versión
     * que tiene completamente ejecutada, deducida desde las hojas de la planilla.
     *
     * @param  array<string, Client> $clients
     * @return void
     */
    private function set_client_versions(array $clients): void
    {
        /* Versión actual deducida por cliente desde la planilla (hojas Seeders,
         * Comandos y Notificaciones, columna del cliente pintada en verde).
         * Mapa: slug → version string */
        $current_versions = [
            'servian'          => '1.1.9',
            'masquito'         => '1.1.5',
            'sanblas'          => '1.1.9',
            '2r'               => '2.0.1',
            'ferretotal'       => '2.0.2',
            'trama'            => '2.1.0',
            'golden-breeze'    => '2.0.2',
            'leudinox'         => '1.0.1',
            'panchito'         => '1.0.1',
            'distri-creo'      => '2.1.1',
            'golonorte'        => '1.0.1',
            'innovate'         => '1.0.1',
            'rober'            => '1.2.0',
            'san-cayetano'     => '2.0.2',
            'truvari'          => '2.1.1',
            'lamartina'        => '1.2.2',
            'arfren'           => '2.0.2',
            'empresa'          => '2.0.2',
            'hipermax'         => '1.0.1',
            'fenix'            => '2.1.0',
            'galvan'           => '1.1.7',
            'cf'               => '1.0.1',
            'chevrocar'        => '1.0.1',
            '3dtisk'           => '2.0.1',
            'oliva'            => '1.0.1',
            'ffperformance'    => '1.0.1',
            'ht5'              => '2.0.2',
            'mbmalizia'        => '1.0.1',
            'ananda'           => '2.0.2',
            'ferremas'         => '1.1.8',
            'lacarra'          => '1.0.1',
            'demo2'            => '1.1.9',
            'hb'               => '1.2.0',
        ];

        /* Precargamos el mapa version_string → id para evitar N+1 */
        $version_map = Version::pluck('id', 'version')->all();

        foreach ($current_versions as $slug => $version_str) {
            if (!isset($clients[$slug])) {
                continue;
            }
            if (!isset($version_map[$version_str])) {
                $this->command->warn("Version $version_str no encontrada para cliente $slug");
                continue;
            }

            $clients[$slug]->update(['current_version_id' => $version_map[$version_str]]);
        }
    }

    // =========================================================================
    // SINCRONIZACIÓN DE ÍTEMS
    // =========================================================================

    /**
     * Sincroniza las notificaciones de una versión.
     * Si la notificación tiene 'restricted_to_client_slug', se vincula al
     * cliente correspondiente usando el pivote version_item_clients.
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
            /* Creamos o recuperamos la notificación por versión + título */
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

            /* Si la notificación está restringida a un cliente específico,
             * sincronizamos el pivote version_item_clients */
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
     * Sincroniza los seeders (clases artisan db:seed) de una versión.
     * run_scope: 'per_user' para los marcados en verde en la hoja Seeders;
     *            'per_database' para los sin color (la mayoría).
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
     * run_scope: 'per_database' para los marcados en verde en la hoja Comandos;
     *            'per_user' para los sin color (la mayoría).
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
     * Sincroniza las tareas manuales (acciones no automatizables) de una versión.
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
    // DEFINICIÓN DE VERSIONES
    // =========================================================================

    /**
     * Retorna el array completo de versiones de producción con todos sus ítems.
     * Fuente: planilla Actualizaciones.xlsx.
     *
     * Estructura de cada versión:
     *   - version       (string) código semver
     *   - title         (string) título descriptivo
     *   - description   (string|null)
     *   - seeders[]     → seeder_class, run_scope, execution_order
     *   - commands[]    → command, run_scope, execution_order
     *   - manual_tasks[] → title, description, execution_order
     *   - notifications[] → title, body, sort_order, restricted_to_client_slug?
     *
     * @return array<int, array<string, mixed>>
     */
    private function production_versions(): array
    {
        return [

            // -----------------------------------------------------------------
            // 1.0.1 — Primera versión subida
            // -----------------------------------------------------------------
            [
                'version'     => '1.0.1',
                'title'       => 'Versión 1.0.1',
                'description' => 'Primera versión subida. Importación de Excel optimizada con procesos de supervisor en paralelo.',

                /* --- SEEDERS (hoja Seeders) --------------------------------- */
                'seeders' => [
                    /* Sin color en col-B → per_database */
                    ['seeder_class' => 'ext_mercado_libre',                     'execution_order' => 1,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'ext_buscar_por_categoria_en_vender',    'execution_order' => 2,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'vender_cambiar_address_id',             'execution_order' => 3,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'MeliConceptoStockMovement',             'execution_order' => 4,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'SaleChannelSeeder',                     'execution_order' => 5,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtencionBalanzaBarCodeSeeder',         'execution_order' => 6,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'PermissionArticleEditStockSeeder',      'execution_order' => 7,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'NuevosPermisosListadoSeeder',           'execution_order' => 8,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtencionArticlePriceRangeSeeder',      'execution_order' => 9,  'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtencionArticuloMultiProveedorSeeder', 'execution_order' => 10, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtencionResumenCajaSeeder',            'execution_order' => 11, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'VendedorEnSalePdfSeeder',               'execution_order' => 12, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PLUBalanzaBarCodeSeeder',               'execution_order' => 13, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PermissionPaymentPlanSeeder',           'execution_order' => 14, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PermissionInfoFacturacion',             'execution_order' => 15, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PermissionLimpiarVentaSeeder',          'execution_order' => 16, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'prohibir_eliminar_articulos_de_venta_seeder', 'execution_order' => 17, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtNTDescriptionSeeder',                'execution_order' => 18, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'VariosPreciosExtencionSeeder',          'execution_order' => 19, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'CAPaymentMethodTypeSeeder',             'execution_order' => 20, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtencionDesEnVenderSeeder',            'execution_order' => 21, 'run_scope' => 'per_database'],
                ],

                /* --- COMANDOS (hoja Comandos) -------------------------------- */
                /* Verde (bg=15) en col-B → per_database; sin color → per_user  */
                'commands' => [
                    ['command' => 'php artisan db:seed --class=MonedaSeeder',                                         'execution_order' => 1,  'run_scope' => 'per_database'],
                    ['command' => 'php artisan iniciar_credit_accounts {user_id?} {client_id?}',                      'execution_order' => 2,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_article_purchases_address_id {user_id?} {sale_id?}',               'execution_order' => 3,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_afip_tickets_data {user_id?}',                                     'execution_order' => 4,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_article_provider_codes {article_id?}',                             'execution_order' => 5,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_articles_prices {user_id?}',                                       'execution_order' => 6,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_costo_ventas {user_id?} {sale_id?}',                               'execution_order' => 7,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan check_to_pay_id_de_ventas_eliminadas {user_id?}',                      'execution_order' => 8,  'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_sales_total_factuado',                                             'execution_order' => 10, 'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_afip_ticket_nota_credito_data',                                    'execution_order' => 11, 'run_scope' => 'per_user'],
                    ['command' => 'php artisan db:seed --class=ExtNTDescriptionSeeder',                               'execution_order' => 12, 'run_scope' => 'per_database'],
                    ['command' => 'php artisan db:seed --class=CerrarVentasExtencionSeeder',                          'execution_order' => 13, 'run_scope' => 'per_database'],
                    ['command' => 'php artisan db:seed --class=providers_article_price_from_costo_mas_iva_seeder',    'execution_order' => 14, 'run_scope' => 'per_database'],
                    ['command' => 'php artisan set_provider_order_afip_tickets_user_id {user_id?}',                   'execution_order' => 15, 'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_iva_condition_slugs',                                              'execution_order' => 16, 'run_scope' => 'per_database'],
                    ['command' => 'php artisan db:seed --class=ConceptoAjusteSeeder',                                 'execution_order' => 17, 'run_scope' => 'per_database'],
                    ['command' => 'php artisan set_sub_total_sales {user_id?}',                                       'execution_order' => 18, 'run_scope' => 'per_user'],
                    ['command' => 'php artisan set_payment_method_types',                                             'execution_order' => 19, 'run_scope' => 'per_database'],
                ],

                /* --- TAREAS MANUALES ---------------------------------------- */
                'manual_tasks' => [
                    [
                        'title'           => 'Subir logo de ARCA a public/afip',
                        'description'     => 'Subir logo de arca a public/afip (a ambos sub dominios, osea al 1 como al 2), sacar el logo de ffperformance/api/public/afip/logo.jpg',
                        'execution_order' => 9,
                    ],
                    [
                        'title'           => 'Agregar carpeta ws_sr_constancia_inscripcion a public/afip/wsaa',
                        'description'     => 'Agregar carpeta ws_sr_constancia_inscripcion a public/afip/wsaa (a la version 1 y a la version 2)',
                        'execution_order' => 22,
                    ],
                    [
                        'title'           => 'Setear users/google_cuota en 100',
                        'description'     => 'Setear el campo users/google_cuota en 100 para todos los usuarios de cada base de datos',
                        'execution_order' => 23,
                    ],
                ],

                /* --- NOTIFICACIONES ----------------------------------------- */
                'notifications' => [
                    /* A TODOS */
                    [
                        'title' => 'Total Vendido Bruto / Total Vendido Neto en Reportes',
                        'body'  => "En reportes cambio el nombre para el dato de todo lo que se vendio, antes figuraba como \"Ingresos Brutos\", ahora van a verlo como \"Total Vendido Bruto\".\n\nEn caso de que se hayan generado devoluciones (notas de credito), va a mostrarse el dato de \"Total Vendido Neto\", que es el \"Total Vendido Bruto\" - \"Devoluciones\"",
                    ],
                    [
                        'title' => 'Buscar cliente o proveedor por CUIT',
                        'body'  => 'Ahora van a poder obtener la informacion de un cliente o de un proveedor en base a su CUIT. La misma funcionalidad que esta en el modulo de VENDER ahora esta disponible en el formulario para crear clientes y proveedores',
                    ],
                    [
                        'title' => 'Historial de cambios por actualizacion de venta',
                        'body'  => 'Cuando actualicen una venta, van a poder ver para cada actualizacion el detalle de que articulos se agregaron, quitaron, o cambiaron la cantidad',
                    ],
                    [
                        'title' => 'Importe facturado por punto de venta en Reportes',
                        'body'  => "En el modulo de reportes, se agrego una seccion dentro de \"Generales\" donde se detalla el importe facturado con cada punto de venta, para cada tipo de comprobante.\nPara ver esta nueva informacion, el empleado debera de contar con el permiso \"Ver informacion de facturacion\" dentro de los permisos de \"Reportes\".",
                    ],
                    [
                        'title' => 'Permiso para limpiar venta en VENDER',
                        'body'  => 'Se agrego un nuevo permiso para controlar si los empleados pueden o no usar el boton de Limpiar una venta en VENDER. Este permiso va como "Usar boton de limpiar venta" dentro de los permisos de VENDER.',
                    ],
                    [
                        'title' => 'PDF desglosado de cuenta corriente',
                        'body'  => 'Ademas de generar un pdf con la informacion de una cuenta corriente, ahora tienen otra opcion para generar un pdf con el desglose de los articulos de cada venta de la cuenta corriente, esta opcion esta en el mismo lugar del boton de imprimir la cuenta corriente',
                    ],
                    [
                        'title' => 'Buscar articulos por codigo de barras en pedidos a proveedores',
                        'body'  => 'Ahora van a poder buscar articulos por el codigo de barras en los pedidos a proveedores',
                    ],
                    [
                        'title' => 'Seleccion automatica de tipo de comprobante A (ARCA)',
                        'body'  => 'Debido a los cambios en la reglamentacion de ARCA, cuando quieran facturar desde un punto de venta de Responsable Inscripto a un Monotributista, el sistema va a seleccionar automaticamente el tipo de comprobante A',
                    ],
                    [
                        'title' => 'Control de autorizacion para eliminar articulo de una venta',
                        'body'  => "Se agrego una medida de seguridad para controlar que los cajeros no eliminen articulos de una venta sin autorizacion:\nPara esto hay un nuevo permiso en la seccion de permisos de VENDER con el nombre \"Prohibir eliminar articulo de una venta sin autorizacion\". Si se le asigna este permiso a un empleado, cuando quiera eliminar un articulo de una venta el sistema va a pedir una clave.\nEsta clave es elegida desde la configuracion, dentro de las configuraciones de VENDER con el titulo \"Clave para poder eliminar un articulo en VENDER\".",
                    ],
                    [
                        'title' => 'Correccion: acceso a cuenta corriente desde Alertas',
                        'body'  => 'Se corrigio el acceso a la cuenta corriente desde el modulo de Alertas',
                    ],
                    [
                        'title' => 'Ver ventas con acopios de un articulo desde el Listado',
                        'body'  => 'Se agrego un nuevo boton a cada articulo en el modulo de Listado para poder ver las ventas en las que tenga unidades acopiadas, con acceso a las ventas y las cuentas corrientes de los clientes con los acopios',
                    ],
                    [
                        'title' => 'Memoria de columnas visibles por modulo',
                        'body'  => 'Ahora cuando indiques que columnas queres que se muestren en la tabla, se va a guardar en memoria para que la proxima vez que ingreses lo veas de la misma forma que lo dejaste.',
                    ],
                    [
                        'title' => 'Facturacion multiple por venta',
                        'body'  => "Reestructuramos el modulo de facturacion para que puedas emitir mas de una factura por venta, aca te explicamos como funciona a partir de ahora: https://drive.google.com/drive/folders/19CD4sTN9HtduvxEr3_3nOIsmDJKvFaDD?usp=sharing",
                    ],
                    [
                        'title' => 'Ofertas para VENDER (renombrado de Rangos de precio)',
                        'body'  => 'Cambiamos el nombre de "Rangos de precio" por "Ofertas para VENDER" en los articulos. Este campo es el utilizado para crear promociones de venta por cantidad vendida',
                    ],
                    [
                        'title' => 'Busqueda automatica de imagenes por codigo de barras',
                        'body'  => "Agregamos una capa de automatizacion a la hora de buscar imagenes con Google.\n\nCuando ingreses a un articulo desde el LISTADO, vas a tener 2 nuevos botones: \"Automatica\" y \"Manual\".\n\nSi precionas \"Automatica\", se abrira la ventana para buscar la imagen y se procedera a buscar automaticamente por el codigo de barras, luego en X segundos se seleccionara la primer imagen, se recortara y guardara automaticamente.\n\nEl tiempo que tarda en ejecutarse el flujo se puede cambiar desde la misma ventana de seleccionar la imagen.",
                    ],
                    [
                        'title' => 'Guardado de fecha al agregar un articulo a una venta',
                        'body'  => "Cuando agregues un articulo a una venta ya creada, el sistema va a guardar la fecha en la que agregaste ese articulo y la va a mostrar en la impresion desglosada de la cuenta corriente.",
                    ],
                    [
                        'title' => 'Registro del empleado que anota un pago',
                        'body'  => "Desde la cuenta corriente y desde el modulo de comprobantes/pagos de clientes vas a ver el dato de quien fue el empleado que registro un pago en la cuenta corriente",
                    ],
                    [
                        'title' => 'Cambio de nombre: Pedidos → Compras a Proveedor',
                        'body'  => "Desde el modulo de proveedores vas a ver la antigua seccion de \"Pedidos\" como \"Compras\". Y todos los pedidos creados vas a verlos como compras. La informacion sigue siendo la misma pero vas a identificarlos con este nuevo termino.",
                    ],
                    [
                        'title' => 'Descuentos en compras a proveedores',
                        'body'  => "Ademas de poder indicar un porcentaje de descuento individual por cada articulo, ahora vas a poder cargar los descuentos que quieras a cada compra. Estos descuentos pueden ser un porcentaje o un monto fijo, y se aplican a el total de la compra.",
                    ],
                    [
                        'title' => 'Filtrar compras facturadas y no facturadas',
                        'body'  => "Desde el modulo de proveedores seccion compras, vas a tener un nuevo filtro para mostrar las ventas \"Con y sin factura\", \"Solo con factura\" y \"Solo sin factura\".\n\nTambien vas a tener un indicador del TOTAL de las compras que estas viendo.",
                    ],
                    [
                        'title' => 'IMPORTANTE: Redefinicion del modulo de compras a proveedores',
                        'body'  => "Cambiamos las configuraciones de las compras a proveedores para que tengas mas control sobre como registras tus compras.\n\nTe recomendamos que veas los tutoriales de como funcionan las compras aca: https://drive.google.com/drive/folders/1OZ2efzFJNXCG5V2n3gKLfyIyV_eM8BE-?usp=sharing\n\nLas compras que cargaste hasta el momento van a seguir igual, pero si las actualizas van a modificarse con estas nuevas reglas!",
                    ],
                    [
                        'title' => 'Nueva funcion de LIBRO DE IVA COMPRAS en Reportes',
                        'body'  => "Desde el modulo de reportes, en la seccion \"General\", en la tarjeta de Iva Credito vas a tener un nuevo boton de \"Libro Iva\".\n\nCuando lo preciones se va a generar un PDF con la informacion de tu IVA Compra en base al rango de fecha que hayas seleccionado en el modulo de reportes.\n\nProximamente tambien estara disponible el LIBRO IVA VENTAS.",
                    ],
                    [
                        'title' => 'Multiples metodos de pago con diferentes monedas',
                        'body'  => "Tanto si haces ventas en pesos o en dolares, vas a poder indicar en un unico movimiento de pago distintos metodos de pago con distintas monedas.\n\nAl momento de indicar un monto en una moneda distinta a la original, vas a poder establecer la cotizacion para ver el monto cotizado.\n\nTe recomendamos este tutorial para que veas como cargar un pago a cuenta corriente con multiples monedas: https://drive.google.com/file/d/1kbkksZ3cACQ_GSw1bgxyy1OEf2PaYTI8/view?usp=drive_link",
                    ],
                    [
                        'title' => 'Correccion: multiples metodos de pago en Gastos',
                        'body'  => "Ahora no vas a indicar el total directamente de un gasto, sino que vas a indicar los metodos de pago utilizados y el sistema calculara el total del gasto en base a la informacion indicada.",
                    ],
                    [
                        'title' => 'Configurar que direcciones mostrar en comprobantes de ventas',
                        'body'  => "Desde la Configuracion -> Vender, vas a poder configurar la \"Direccion comercial para mostrar en los comprobantes de venta\", si completas este campo se va a mostrar siempre en tus comprobantes de ventas, junto con la direccion de la sucursal desde donde se hizo la venta.\n\nA la derecha, vas a ver la otra nueva opcion de \"Mostrar la direccion de todas las sucursales en los comprobantes de venta\", si la activas van a mostrarse todas las sucursales en los comprobantes de ventas que generes.",
                    ],
                    [
                        'title' => 'Nueva dinamica para errores de ARCA',
                        'body'  => "Cambiamos el diseno de como ves las facturas en el sistema. Cada factura va a estar enmarcada dentro de una tarjeta con su respectiva informacion: Nro de factura, opcion para imprimirla.\n\nSi hubo algun error al obtener el CAE, vas a ver un boton verde para \"Consultar\" el comprobante ante ARCA. Si el comprobante se logro autorizar, se va a actualizar el CAE en la factura del sistema.\n\nRecorda que una venta puede tener 1 o mas facturas asociadas.",
                    ],
                    [
                        'title' => 'Metodos de pago personalizados (tipo Cheque o Tarjeta)',
                        'body'  => "Ahora vas a poder agregar metodos de pago e indicar si son de tipo \"Cheque\" o de tipo \"Tarjeta de credito\".\n\nIndicando este dato, vas a poder indicar la informacion complementaria como: los datos del cheque en caso de que el metodo de pago sea de tipo \"cheque\", o la cantidad de cuotas en caso de que el metodo de pago sea de tipo \"tarjeta de credito\".",
                    ],

                    /* Especificas por cliente */
                    [
                        'title'                      => 'Nombre del empleado en movimientos entre caja',
                        'body'                       => 'En los movimientos entre caja, van a ver el nombre del empleado al que pertenece la caja junto al nombre de la caja para evitar confusiones',
                        'restricted_to_client_slug'  => 'lamartina',
                    ],
                    [
                        'title'                      => 'Indicar moneda en gastos / Notas de credito en dolares',
                        'body'                       => "Van a poder indicar la moneda cuando carguen un nuevo gasto. Esto se usara en el modulo de reportes para separar tanto los gastos como la rentabilidad en pesos y en dolares\n\nCuando generen una nota de credito a una venta en dolares, el importe de esa nota de credito se va a mostrar como \"Devoluciones USD\" en reportes.\n\nA los empleados ya les aparece completada la cotizacion del dolar desde el modulo de VENDER\n\nEn el PDF de las ventas, va a aparecer el simbolo de dolar para los precios individuales de los productos y para los descuentos y recargos",
                        'restricted_to_client_slug'  => '2r',
                    ],
                    [
                        'title'                      => 'Articulos en memoria para busqueda rapida en VENDER',
                        'body'                       => "Cuando se busque un articulo en VENDER, se van a utilizar los articulos guardados en memoria para evitar la descarga cuando se busque un articulo.\nImportante: si hay un cambio en los articulos (precio, stock, etc.), los cajeros no lo van a ver reflejado hasta que actualicen la pagina y se vuelvan a descargar los productos.",
                        'restricted_to_client_slug'  => 'lamartina',
                    ],
                    [
                        'title'                      => 'Filtrar cheques por cliente o proveedor',
                        'body'                       => 'Cuando filtren los cheques van a poder hacerlo tambien en base al cliente del que se recibio o al proveedor al que se le emitio',
                        'restricted_to_client_slug'  => 'golden-breeze',
                    ],
                    [
                        'title'                      => 'Agregar Descripciones a notas de credito',
                        'body'                       => "Ahora van a poder agregar \"Descripciones\" a las notas de credito, para poder computar devoluciones por acuerdos comerciales. Aca tienen un tutorial de como hacerlo: https://drive.google.com/drive/folders/1B2djNCqyW3gRPAzgPKTUIcO53oGUAkEY?usp=sharing",
                        'restricted_to_client_slug'  => 'golden-breeze',
                    ],
                    [
                        'title'                      => 'Cerrar ventas (para no poder seguir actualizandolas)',
                        'body'                       => "Se agrego la funcionalidad de \"Cerrar ventas\".\nDesde el modulo de ventas o desde la cuenta corriente, para las ventas que no esten facturadas y hayan pasado a la cuenta corriente, vas a ver un boton para cerrar la venta en caso de que no este cerrada, y si ya esta cerrada, una etiqueta indicando que esta cerrada.",
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                    [
                        'title'                      => 'Reimportacion de Excel para negocios con codigos de proveedor repetidos (Ferretotal)',
                        'body'                       => 'Reconfiguramos la importacion de Excel para los negocios con "Codigos de proveedor repetidos". Mira este video donde te explicamos como importar tus archivos excel correctamente: https://drive.google.com/drive/folders/1OgqsCear4GjWzbj-r39ta2SP80ZZrr16?usp=sharing',
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                    [
                        'title'                      => 'Reimportacion de Excel para negocios con codigos de proveedor repetidos (SanBlas)',
                        'body'                       => 'Reconfiguramos la importacion de Excel para los negocios con "Codigos de proveedor repetidos". Mira este video donde te explicamos como importar tus archivos excel correctamente: https://drive.google.com/drive/folders/1OgqsCear4GjWzbj-r39ta2SP80ZZrr16?usp=sharing',
                        'restricted_to_client_slug'  => 'sanblas',
                    ],
                    [
                        'title'                      => 'Ver unidades acopiadas y entregadas en el Listado',
                        'body'                       => 'Desde el LISTADO, cuando ves las ventas acopiadas de un articulo, vas a ver dos nuevas columnas indicando para cada articulo la cantidad acopiada y la cantidad entregada. Y un total de las unidades en acopio de ese articulo',
                        'restricted_to_client_slug'  => 'sanblas',
                    ],
                    [
                        'title'                      => 'Configuracion de precios por listas para FFPerformance',
                        'body'                       => "Se te configuraron todos los precios de tus productos. A todos se les puso un:\n60% Minorista\n44% Mayorista\n83.33% Repro dealer\nTanto en pesos como en dolar",
                        'restricted_to_client_slug'  => 'ffperformance',
                    ],
                    [
                        'title'                      => 'Establecer el orden de los articulos por defecto en VENDER (Panchito)',
                        'body'                       => "Desde el listado, vas a poder indicar la posicion en la que queres que aparezcan los articulos por defecto en vender.\n\nEn lugar de activar la propiedad \"Por defecto en vender\", vas a tener un campo para completar la posicion en la que queres que aparezca.\n\nSi lo dejas en blanco sin completar seria el equivalente a que este desactivado.",
                        'restricted_to_client_slug'  => 'panchito',
                    ],
                    [
                        'title'                      => 'Establecer el orden de los articulos por defecto en VENDER (HiperMax)',
                        'body'                       => "Desde el listado, vas a poder indicar la posicion en la que queres que aparezcan los articulos por defecto en vender.\n\nEn lugar de activar la propiedad \"Por defecto en vender\", vas a tener un campo para completar la posicion en la que queres que aparezca.\n\nSi lo dejas en blanco sin completar seria el equivalente a que este desactivado.",
                        'restricted_to_client_slug'  => 'hipermax',
                    ],
                    [
                        'title'                      => 'Nuevo Modulo de produccion V2',
                        'body'                       => "Vas a ingresar al modulo de produccion desde \"Produccion V2\", aca te dejo unos videos para que veas como utilizar las Recetas y Lotes de produccion junto con todo lo que se agrego, cualquier duda me consultas: https://drive.google.com/drive/folders/1ie-xYvSo7vl_dBCmhJkDuPm7YTnsm-en?usp=sharing",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                      => 'Cotizacion automatica entre monedas por lista de precios (2R)',
                        'body'                       => "Ahora podes elegir, en cada lista de precios, si el precio en ARS se cotiza desde USD o si el precio en USD se cotiza desde ARS.\n\nMirate este video para entender como funciona: https://drive.google.com/file/d/1mDEzSh39XDyu9GimAuQjWl1OAiRA4Lz0/view?usp=sharing",
                        'restricted_to_client_slug'  => '2r',
                    ],
                    [
                        'title'                      => 'Cotizacion automatica entre monedas por lista de precios (Ananda)',
                        'body'                       => "Ahora podes elegir, en cada lista de precios, si el precio en ARS se cotiza desde USD o si el precio en USD se cotiza desde ARS.\n\nMirate este video para entender como funciona: https://drive.google.com/file/d/1mDEzSh39XDyu9GimAuQjWl1OAiRA4Lz0/view?usp=sharing",
                        'restricted_to_client_slug'  => 'ananda',
                    ],
                    [
                        'title'                      => 'Desactivar nombre del empleado en PDF de ventas (Arfren)',
                        'body'                       => 'Desactivar configuracion de usuario: "Mostrar el nombre del empleado que realizo la venta en los PDF de las ventas"',
                        'restricted_to_client_slug'  => 'arfren',
                    ],
                    [
                        'title'                      => 'Nuevo buscador en LISTADO',
                        'body'                       => "Agregamos el mismo buscador del modulo de VENDER al LISTADO.\n\nVas a poder buscar por nombre, codigo de proveedor, descripcion y combinar con la categoria y disponibilidad de stock en un mismo lugar.",
                        'restricted_to_client_slug'  => 'servian',
                    ],
                    [
                        'title'                      => 'Separar insumos de articulos en el Listado',
                        'body'                       => "Ahora los insumos no te van a aparecer junto con los articulos cuando ingreses al listado.\n\nCada articulo va a tener una nueva propiedad \"Es insumo\", la cual podes activar o desactivar.\n\nLos articulos que la tengan activada, no van a aparecer en el Listado ni bien ingreses, salvo que realices un filtrado.",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                      => 'Gestionar ventas por Estado de venta',
                        'body'                       => "Con este nuevo modulo vas a poder gestionar tus ventas segun los estados que necesites.\n\nEn este video te explicamos todo lo referente a este nuevo modulo: https://drive.google.com/drive/folders/18fFxvj_K6DkQ39GLBKTRWA4KvUfiCs8b?usp=sharing",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                      => 'Corregimos el agregar combos al actualizar una venta',
                        'body'                       => 'Habia un problema cuando querian actualizar una venta que ya tenia un combo, quedo solucionado.',
                        'restricted_to_client_slug'  => 'empresa',
                    ],
                    [
                        'title'                      => 'Indicar a que sucursal pertenece una caja',
                        'body'                       => "Si indican este dato en una caja desde el modulo de tesoreria, esta caja estara disponible en el modulo de VENDER solo si la sucursal seleccionada en VENDER es la misma que la de la caja.\n\nSi no indican la sucursal a una caja, estara disponible siempre en vender (siempre que este abierta).",
                        'restricted_to_client_slug'  => 'empresa',
                    ],
                    [
                        'title'                      => 'IMPORTANTE: Recordatorio configuracion para actualizar ventas',
                        'body'                       => "Cuando le den click a actualizar venta, y se los lleve al modulo de VENDER con la informacion de la venta a actualizar, los precios de los articulos no van a ser los que tenian al momento de generar la venta, sino que se utilizaran los precios actuales.",
                        'restricted_to_client_slug'  => 'empresa',
                    ],
                    [
                        'title'                      => 'Metodos de pago personalizados para cheques (Golden Breeze)',
                        'body'                       => 'Con la nueva funcionalidad de metodos de pago personalizados vas a poder agregar los metodos de pago personalizados para cheques',
                        'restricted_to_client_slug'  => 'golden-breeze',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.0.2
            // -----------------------------------------------------------------
            [
                'version'     => '1.0.2',
                'title'       => 'Versión 1.0.2',
                'description' => 'Se agrego rama de ht5: manejo de venta por estados de ventas y separacion de insumos del listado.',
                'seeders' => [
                    ['seeder_class' => 'ExtVentasConEstadosSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.0
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.0',
                'title'       => 'Versión 1.1.0',
                'description' => 'Se agrego rama order_de_columnas: se puede elegir el orden, tamaño, posicion, visibilidad y salto de linea de las columnas.',
                'notifications' => [
                    [
                        'title' => 'Establece el orden de las columnas como vos quieras',
                        'body'  => "Agregamos la funcionalidad para que puedas configurar cada modulo del sistema como necesites.\n\nVas a poder elegir el: orden, tamaño y saltos de linea para cada columna de las tablas.\n\nEnterate como funciona con este video: https://drive.google.com/file/d/1XY72WDNu-QRbgI7ED4JZFnICNxPI-OPb/view?usp=sharing\n\nLo mismo aplica para los resultados de busqueda, enterate como funciona con este video: https://drive.google.com/file/d/1cqKIlZ9FyQV25buDgJbrm0vNNBLOAxET/view?usp=sharing",
                    ],
                    [
                        'title'                     => 'Tutorial de configuracion de busqueda en VENDER (Oliva)',
                        'body'                       => 'Con este video vas a poder configurar como queres que se vean los resultados de busqueda en el modulo de VENDER: https://drive.google.com/file/d/1cqKIlZ9FyQV25buDgJbrm0vNNBLOAxET/view?usp=sharing',
                        'restricted_to_client_slug'  => 'oliva',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.1
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.1',
                'title'       => 'Versión 1.1.1',
                'description' => 'Se agrego estilo personalizable de PDF de ventas: remitos y facturas. Funcionalidad para ampliar datos hasMany en model form.',
                'seeders' => [
                    /* Sin color → per_database */
                    ['seeder_class' => 'SheetTypeSeeder',       'execution_order' => 1, 'run_scope' => 'per_database'],
                    /* Verde (bg=15) en hoja Seeders → per_user */
                    ['seeder_class' => 'PdfColumnOptionSeeder', 'execution_order' => 2, 'run_scope' => 'per_user'],
                    ['seeder_class' => 'PdfColumnProfileSeeder','execution_order' => 3, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'Ampliar contenido dentro de formularios',
                        'body'  => "Dentro de los formularios, vas a poder ampliar la informacion de las tablas.\n\nPor ejemplo dentro de articulos -> descuentos, compras a proveedores -> facturas, etc.",
                    ],
                    [
                        'title'                     => 'Formato del PDF de ventas y facturas personalizado (Arfren)',
                        'body'                       => "Quitamos las columnas que no querias que se muestren\nAgrandamos el logo\nMostramos el total de la factura solo al final de la ultima pagina\nAgrandamos tu logo en los PDF",
                        'restricted_to_client_slug'  => 'arfren',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.2
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.2',
                'title'       => 'Versión 1.1.2',
                'description' => 'Las cajas para mostrar en vender se filtran segun la sucursal y la moneda. Se muestran los stock por sucursal en los articulos de un Movimiento de depositos.',
                'notifications' => [
                    [
                        'title' => 'Nuevas columnas por cada sucursal en movimientos de depositos',
                        'body'  => 'Cuando estes armando un "movimiento de depositos" desde el LISTADO, vas a poder ver por cada articulo del movimiento el stock que tiene actualmente en cada deposito.',
                    ],
                    [
                        'title'                     => 'Control de cajas por empleado',
                        'body'                       => "Para controlar que cajas puede usar cada empleado vas a ingresar al modulo de Tesoreria, y cuando ingreses en una caja vas a tener una lista de \"Empleados con acceso\". Ahi vas a cargar el o los empleados que quieras que puedan utilizar la caja.\n\nSi dejas la lista de \"Empleados con acceso\" vacia, cualquier empleado va a poder usarla.",
                        'restricted_to_client_slug'  => 'ananda',
                    ],
                    [
                        'title'                     => 'Mostrar todas las sucursales en PDF de ventas',
                        'body'                       => 'Cuando generes un PDF, van a aparecer todas las sucursales (independientemente de donde se haya realizado la venta).',
                        'restricted_to_client_slug'  => 'masquito',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.3
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.3',
                'title'       => 'Versión 1.1.3',
                'description' => 'Opcion para aplicar iva en VENDER. Opcion para descontar stock en VENDER. Seccion insumos en produccion.',
                'commands' => [
                    ['command' => 'php artisan check_extencion_listas_de_precios', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'Opcion "Aplicar IVA" en modulo VENDER',
                        'body'  => "Cuando estes creado o actualizando una venta, vas a poder elegir si queres que se aplique el iva a los productos (opcion por defecto), o si queres que no se aplique.\n\nSi permanece activado, el precio de venta va a ser el que ya venis trabajando, el precio final del articulo.\n\nSi lo desactivas, el sistema va a calcular el precio que el articulo deberia de tener para que, una vez sumado el iva, de como resultado el precio final.",
                    ],
                    [
                        'title'                     => 'Opcion para descontar stock en una venta',
                        'body'                       => "Desde el modulo de VENDER, cuando estes creando una venta vas a tener disponible un check para indicar si queres que se descuente o no el stock de los articulos vendidos.\n\nSi lo desactivas, el sistema no descontara el stock.\n\nUna vez que actives la opcion, NO SE PODRA DESACTIVAR.",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Nueva seccion "Insumos" en modulo de Produccion',
                        'body'                       => "Desde el modulo de produccion vas a tener la nueva seccion \"Insumos\" donde se van a listar todos los articulos que tengan activada la propiedad \"Es un insumo\".\n\nDesde ahi tambien vas a poder dar de alta nuevos insumos.",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.5
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.5',
                'title'       => 'Versión 1.1.5',
                'description' => 'Se reconstruyo el store con _base_factory. Se corrigio facturas para que aparezcan descuentos y recargos. Se refactorizo AfipHelper.',
                'seeders' => [
                    /* Verde en hoja Seeders → per_user */
                    ['seeder_class' => 'PdfColumnSinPreciosSeeder', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
                'commands' => [
                    ['command' => 'php artisan set_sales_ganancia', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'Actualizar una venta sin cliente asignado',
                        'body'  => 'Si hiciste una venta sin asignarle un cliente y necesitas actualizarla, vas a poder hacerlo siempre y cuando no tenga una caja asignada, y tenga un unico metodo de pago asignado',
                    ],
                    [
                        'title' => 'Total vendido a C/C en ventas',
                        'body'  => 'En el modulo de VENTAS, arriba a la izquierda, debajo del "Total" vendido, va a aparecer el monto vendido a cuenta corriente.',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.6
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.6',
                'title'       => 'Versión 1.1.6',
                'description' => 'Se corrigio importacion de excel (descuentos, recargos y provider_code repetidos).',
            ],

            // -----------------------------------------------------------------
            // 1.1.7
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.7',
                'title'       => 'Versión 1.1.7',
                'description' => 'Se guarda la descripcion del precio de las ventas de VENDER. Opcion de que fecha mostrar en PdfColumnProfile. Personalizacion del valor de la mensualidad.',
                'seeders' => [
                    /* Sin color → per_database */
                    ['seeder_class' => 'InputsSizeSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                    /* Especifico de Empresa - Fenix (col 23 en hoja Seeders) */
                    ['seeder_class' => 'PdfColumnProfileComisionesSeeder', 'execution_order' => 2, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title' => 'Opcion para imprimir la fecha corriente en PDF de ventas',
                        'body'  => 'Si queres activar esta opcion, pedinos para que te lo configuremos',
                    ],
                    [
                        'title' => 'Nueva opcion para elegir el tamaño de los elementos',
                        'body'  => "Si queres que los componentes del sistema tengan un tamaño mas chico para ver mas informacion dentro de la pantalla, vas a tener esta nueva opcion para configurarlo.\n\nEsta opcion esta disponible desde Configuracion -> Interfaz -> Tamaño de los componentes",
                    ],
                    [
                        'title'                     => 'Modulo de Devoluciones corregido',
                        'body'                       => "Ya podes crear nuevas devoluciones agregando manualmente los articulos y el cliente que necesites, sin necesidad de hacerlo sobre una venta",
                        'restricted_to_client_slug'  => 'fenix',
                    ],
                    [
                        'title'                     => 'Comisiones y costos en PDF de ventas',
                        'body'                       => "Corregimos como aparecen los costos y comisiones en el PDF de las ventas.\n\nAhora vas a tener una nueva opcion para imprimir llamada \"Remito costos\", ahi vas a ver las comisiones y los costos",
                        'restricted_to_client_slug'  => 'fenix',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.8
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.8',
                'title'       => 'Versión 1.1.8',
                'description' => 'Rollback de importacion de articulos.',
                'seeders' => [
                    ['seeder_class' => 'ExtencionAdjuntarArchivosSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title' => 'Restaurar importacion de Excel',
                        'body'  => "Luego de hacer una importacion, desde el \"Historial de importaciones\" vas a poder hacer un rollback para reestablecer los articulos a como estaban antes de hacer la importacion",
                    ],
                    [
                        'title' => 'Nuevos campos para actualizar masivamente',
                        'body'  => "Desde el Listado, luego de hacer un filtrado o una seleccion manual, vas a tener la posibilidad de actualizar masivamente opciones como:\n1. Disponibilidad en la tienda online\n2. Aplicar IVA\n3. Costos en dolares\nY todas las opciones de tipo \"Check\" que antes no habia",
                    ],
                    [
                        'title' => 'Exportar excel de ventas con detalle de articulos',
                        'body'  => 'Debajo del total en VENDER, vas a tener un nuevo boton verde "Excel full", el cual te va a generar un excel con los datos de todos los articulos vendidos de las ventas que estes viendo en el modulo de VENTAS',
                    ],
                    [
                        'title'                     => 'Adjuntar archivos a los items de una venta',
                        'body'                       => "Cuando estes creando/actualizando una venta, vas a poder agregar archivos a cada item, desde una nueva columna a la izquierda de la tabla de los articulos en el modulo VENDER.\n\nAca te dejamos un video para que veas como usar esta funcionalidad: https://drive.google.com/drive/folders/1zQETwUsOKazNgLTr0Bt2Hzw6p-FUAq_5?usp=sharing",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Buscar cliente por numero de documento en VENDER',
                        'body'                       => 'Ahora podes buscar un cliente por su numero de documento directamente desde el modulo de VENDER',
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Excel de articulos vendidos disponible (Golden Breeze)',
                        'body'                       => 'El nuevo Excel full con detalle de articulos vendidos te va a servir para lo que nos habias pedido de exportar el detalle de los articulos vendidos',
                        'restricted_to_client_slug'  => 'golden-breeze',
                    ],
                    [
                        'title'                     => 'Correccion uso de columnas guardadas para importar Excel',
                        'body'                       => 'Ya podes volver a utilizar las configuraciones de columnas guardadas para importar un excel',
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.1.9
            // -----------------------------------------------------------------
            [
                'version'     => '1.1.9',
                'title'       => 'Versión 1.1.9',
                'description' => 'Ampliar imagenes. Importar unidades pedidas y recibidas en pedido a proveedor. Control para el login de usuarios, no se permiten cuentas en mas de un dispositivo en simultaneo.',
                'seeders' => [
                    ['seeder_class' => 'PermissionProhibirListaPreciosVender', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
                'commands' => [
                    ['command' => 'php artisan set_user_activity {user_id?}',       'execution_order' => 1, 'run_scope' => 'per_user'],
                    ['command' => 'php artisan check_rounding_env_variables',        'execution_order' => 2, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'Opcion para ampliar las imagenes de los articulos',
                        'body'  => 'Desde el LISTADO o cuando busques un articulo desde VENDER, vas a poder hacer click sobre la imagen de un articulo para poder verla en un tamaño ampliado',
                    ],
                    [
                        'title' => 'Stock en vender',
                        'body'  => 'Cuando busques un articulo por su codigo de barras, al lado del campo donde ingresas el codigo de barras, se te va a informar el stock del articulo, y entre parentesis, el stock en la sucursal seleccionada para vender',
                    ],
                    [
                        'title' => 'Importar excel de articulos "recibidos" en compras a proveedores',
                        'body'  => "Desde el modulo de Proveedores -> compras, vas a poder importar un archivo excel para indicar los articulos comprados, y tambien los articulos recibidos. El sistema te va a mostrar las diferencias entre lo pedido y lo recibido de tu proveedor para que puedas verlo facilmente.\n\nTe dejamos este tutorial para que entiendas como usarlo: https://drive.google.com/drive/folders/1P4FxZ6osYE4gCqlYuzKgyuLkIeLXevem?usp=sharing",
                    ],
                    [
                        'title' => 'Se soluciono la descarga de recursos en el telefono',
                        'body'  => 'Se soluciono la descarga de recursos en el telefono',
                    ],
                    [
                        'title' => 'Envio de Mails de ventas',
                        'body'  => "Luego de crear o actualizar una venta, si el cliente seleccionado tiene indicado un email, vas a poder activar la opcion de \"Enviar correo al cliente\", desde el modulo de VENDER, debajo del cliente seleccionado. Al activarlo, se enviara un correo al cliente indicandole sobre su nueva venta, y dejando dos links para que pueda: ver el comprobante, y ver su resumen de la cuenta corriente.",
                    ],
                    [
                        'title' => 'Crear conceptos de movimientos de caja personalizados',
                        'body'  => 'Desde ABM -> Tesoreria -> Conceptos movimiento caja vas a poder crear y editar los conceptos que necesites',
                    ],
                    [
                        'title' => 'Atajo para agregar articulos de Sugerencia de Stock a Movimiento de deposito',
                        'body'  => "Cuando estes viendo los articulos de una sugerencia de stock, vas a tener una nueva columna a la izquierda para ir tildando los articulos que quieras agregar a un \"Movimiento de depositos\" de forma automatica.\n\nPara cuando termines de indicar los articulos que queres agregar, preciones el nuevo boton \"Crear movimiento de depositos\", para que el sistema te cree de forma automatica un nuevo movimiento de deposito con todos los articulos que seleccionaste.",
                    ],
                    [
                        'title'                     => 'Nuevo permiso "Prohibir cambiar la lista de precios en VENDER"',
                        'body'                       => "Si queres que tus empleados no puedan cambiar la lista de precio en vender, asignales este permiso.\n\nDe esa forma, cuando esten en VENDER, van a usar siempre la ultima lista de precio, o la lista que tenga asignada el cliente.",
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                    [
                        'title'                     => 'Nueva opcion "Pausar precio" de un articulo en la tienda',
                        'body'                       => "En cada articulo vas a tener un nuevo check en la seccion de \"Tienda online\" llamado \"Pausar precio\".\n\nSi lo activas, no se va a mostrar el precio del articulo en la tienda, en su lugar se va a mostrar un texto personalizado, y no se va a permitir que se agregue al carrito.\n\nEl texto que se va a mostrar lo podes configurar desde Configuracion online -> Opciones -> Texto para precio pausado",
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Logo del e-commerce configurable (Truvari)',
                        'body'                       => "Ahora el e-commerce va a utilizar el logo que tengas configurado desde la configuracion general de la cuenta.\n\nSi queres usar un logo distinto para la tienda, tenes una nueva opcion para elegirlo en Configuracion online -> Diseño -> Logo de la tienda.",
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Scroll automatico en la tienda online',
                        'body'                       => "Te configuramos la tienda para que cuando inicie, a los 2 segundos, comience a scrollear con una velocidad determinada (esto se puede configurar).\n\nEste comportamiento va a ocurrir hasta que el usuario realice una accion en la pagina, scrollee o clickee algo.",
                        'restricted_to_client_slug'  => 'fenix',
                    ],
                    [
                        'title'                     => 'Logo del e-commerce configurable (Fenix)',
                        'body'                       => "Ahora el e-commerce va a utilizar el logo que tengas configurado desde la configuracion general de la cuenta.\n\nSi queres usar un logo distinto para la tienda, tenes una nueva opcion para elegirlo en Configuracion online -> Diseño -> Logo de la tienda.",
                        'restricted_to_client_slug'  => 'fenix',
                    ],
                    [
                        'title'                     => 'Logo del e-commerce configurable (Arfren)',
                        'body'                       => "Ahora el e-commerce va a utilizar el logo que tengas configurado desde la configuracion general de la cuenta.\n\nSi queres usar un logo distinto para la tienda, tenes una nueva opcion para elegirlo en Configuracion online -> Diseño -> Logo de la tienda.",
                        'restricted_to_client_slug'  => 'arfren',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.2.0
            // -----------------------------------------------------------------
            [
                'version'     => '1.2.0',
                'title'       => 'Versión 1.2.0',
                'description' => 'Se guarda LOG completo en VENDER. Consolidar varias ventas en una unica factura.',
                'seeders' => [
                    ['seeder_class' => 'ConsolidacionSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title' => 'Nuevo campo "Nombre dueño" en puntos de venta ARCA',
                        'body'  => 'Nuevo campo opcional para que complete con informacion que quiera que salga impresa en la factura junto con la razon social.',
                    ],
                    [
                        'title'                     => 'Consolidar varias ventas en una unica factura',
                        'body'                       => "Con esta opcion van a poder \"unificar\" varias ventas sin facturar en una unica factura.\n\nTe dejamos este tutorial para que veas como hacerlo: https://drive.google.com/drive/folders/1UnaKcBXpTH82i5nD3uyYsV0QQOa-rToS?usp=sharing",
                        'restricted_to_client_slug'  => 'rober',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.2.1
            // -----------------------------------------------------------------
            [
                'version'     => '1.2.1',
                'title'       => 'Versión 1.2.1',
                'seeders' => [
                    ['seeder_class' => 'ExtencionHideIvaDiscountStockVenderSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title'                     => 'Exportacion de excel en background',
                        'body'                       => "Debido a la cantidad de articulos que intentabas exportar el sistema colapsaba, con esta nueva funcionalidad la exportacion se procesara en segundo plano para poder gestionar bien la cantidad de articulos.\n\nUna vez que termine, se te notificara para que puedas descargar el archivo generado.",
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                    [
                        'title'                     => 'Quedo corregido el atajo del buscador por nombre en el Listado',
                        'body'                       => 'Quedo corregido el atajo del buscador por nombre en el Listado',
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Nueva configuracion aplicada: IVA luego del margen de ganancia',
                        'body'                       => 'Los precios van a seguir igual ya que el orden de los factores no cambia el resultado, lo que si va a cambiar es el costo real del articulo, ahora va a estar mas barato ya que no se le va a sumar el IVA para calcular el costo real.',
                        'restricted_to_client_slug'  => 'empresa',
                    ],
                    [
                        'title'                     => 'Ya no aparece la opcion de descontar stock o precios con IVA en VENDER',
                        'body'                       => 'Ya no les va a aparecer la opcion de descontar stock o de precios con iva en VENDER',
                        'restricted_to_client_slug'  => 'san-cayetano',
                    ],
                    [
                        'title'                     => 'Las descripciones de los campos ahora aparecen al hacer click en el titulo',
                        'body'                       => 'Las descripciones de los campos en los formularios ahora aparecen cuando se les hace click al titulo del campo',
                        'restricted_to_client_slug'  => '2r',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 1.2.2
            // -----------------------------------------------------------------
            [
                'version'     => '1.2.2',
                'title'       => 'Versión 1.2.2',
                'description' => 'Descontar stock y sale_type_id en presupuestos. Se corrigio y agrego el descontar_iva a presupuestos.',
                'seeders' => [
                    ['seeder_class' => 'ExtencionDuplicarPresupuestosSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'ExtencionEnviarMailClientesSeeder',   'execution_order' => 2, 'run_scope' => 'per_database'],
                ],
                'commands' => [
                    ['command' => 'php artisan init_article_pdf', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'PDF para ofertas de articulos',
                        'body'  => "Se agrego una nueva opcion al listado para imprimir una hoja A4 con ofertas de los articulos seleccionados o filtrados.\n\nDentro del desplegable de filtrados o seleccionados, vas a ver una opcion con el nombre de \"PDF ofertas (plantillas)\".",
                    ],
                    [
                        'title'                     => 'Indicar el "Estado" y "Descontar stock" en los presupuestos',
                        'body'                       => 'Los valores que indiques en estas nuevas propiedades, seran utilizados al momento de confirmar un presupuesto y crear la venta',
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Edicion de etiqueta de envio',
                        'body'                       => "Desde el modulo de ventas, para cada venta vas a tener un boton para generar un PDF de la etiqueta de envio, con los datos del remitente y del destinatario.\n\nPor defecto, los datos del destinatario seran los datos del cliente, en caso de que quieras modificar estos datos, al lado del boton para generar la etiqueta vas a tener un nuevo boton para crear los datos del destinatario.\n\nEn cuanto al remitente, vas a poder crear todas las direcciones que necesites en ABM -> Ventas -> Remitentes.",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Duplicar presupuestos',
                        'body'                       => "En el modulo de presupuestos, vas a tener un nuevo boton al comienzo de la tabla para duplicar un presupuesto.\n\nEl sistema va a crear una copia del presupuesto con los mismos articulos, cliente y demas datos, asignandole siempre el estado \"Sin confirmar\".",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Confirmar masivamente pedidos de tienda nube',
                        'body'                       => "Desde el modulo de tienda nube -> pedidos, vas a poder seleccionar todos los pedidos que quieras y, dentro del desplegable de opciones, vas a tener una nueva opcion titulada \"Confirmar pedidos y crear ventas\".",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Enviar masivamente correos electronicos sobre las ventas',
                        'body'                       => "Desde el modulo de VENTAS, vas a poder seleccionar manualemente las ventas que quieras, y en el desplegable de opciones vas a tener la nueva opcion de \"Enviar correo a clientes\"",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Alertas personalizadas por venta',
                        'body'                       => "Ademas de poder configurar los dias a partir de los cuales se alerta sobre las ventas no cobradas en la configuracion general, ahora vas a tener la opcion de indicar para cada venta en especifico, a partir de cuantos dias queres que comience a alertarse sobre su falta de cobro.\n\nDesde el modulo de VENDER, en la seccion de cliente, luego de seleccionar el cliente vas a ver un nuevo campo para indicar este valor.",
                        'restricted_to_client_slug'  => 'ht5',
                    ],
                    [
                        'title'                     => 'Indicar la "Medida" en los articulos',
                        'body'                       => "Cuando abran para editar un articulo, van a tener un nuevo campo llamado \"medida\", en la seccion de \"Stock\", donde ademas de indicar la unidad de medida de un articulo (kilo, gramo, litro, etc) van a indicar la cantidad que contiene cada articulo.\n\nEste dato sera utilizado cuando se generen las etiquetas de los articulos para que se muestre el precio por kilo o por litro.",
                        'restricted_to_client_slug'  => 'lamartina',
                    ],
                    [
                        'title'                     => 'Correccion de los filtros en GASTOS',
                        'body'                       => 'Se corrigio el comportamiento de los filtros en el modulo de Gastos',
                        'restricted_to_client_slug'  => 'masquito',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.0.1
            // -----------------------------------------------------------------
            [
                'version'     => '2.0.1',
                'title'       => 'Versión 2.0.1',
                'seeders' => [
                    ['seeder_class' => 'TiendaNubeOrderStatusSeeder',             'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'ConceptoMovimientoCajaCompensacionSeeder','execution_order' => 2, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title' => 'Cambios en la interfaz',
                        'body'  => "Cambiamos la organizacion cuando ingresar en un registro (articulos, clientes, etc). Ahora vas a ver la informacion por \"paginas\". En la barra superior vas a ver los nombres de las secciones de cada registro para desplazarte mas rapido a la seccion que necesites.\n\nCambiamos los iconos de la barra de navegacion.",
                    ],
                    [
                        'title' => 'Al eliminar una venta podes compensar la caja',
                        'body'  => "Al eliminar una venta, un gasto o un pago en cuenta corriente podes marcar si queres compensar la caja (movimiento inverso por cada medio de pago con caja).\n\nEn el mismo modal de confirmacion de borrado aparece la opcion \"Compensar caja\"; por defecto viene marcada y podes desmarcarla si no queres registrar ese movimiento.\n\nSi la dejas marcada, el sistema revisa que todas las cajas involucradas esten abiertas; si alguna esta cerrada, no se elimina el registro y te avisa cuales tenes que abrir primero.",
                    ],
                    [
                        'title' => 'Cuenta siempre al dia entre usuarios',
                        'body'  => "Si hace cambios en la configuracion de la cuenta, el resto recibe un aviso con el detalle (por ejemplo cotizacion del dolar).\n\nLa sesion se actualiza sola para que trabajen con los mismos datos sin cerrar sesion.\n\nEn pagos y metodos de pago, la cotizacion del dolar se alinea con la del titular cuando la cambia.",
                    ],
                    [
                        'title'                     => 'Pedidos online: asignar estados libremente',
                        'body'                       => "Ahora vas a poder asignar libremente el estado que quieras a los pedidos de la tienda online.\n\nCuando cambies de \"Sin confirmar\" a cualquier otro estado que no sea \"Cancelado\", el sistema va a proceder a crear la venta correspondiente.",
                        'restricted_to_client_slug'  => 'fenix',
                    ],
                    [
                        'title'                     => 'Pedidos online: asignar estados libremente (Truvari)',
                        'body'                       => "Ahora vas a poder asignar libremente el estado que quieras a los pedidos de la tienda online.\n\nCuando cambies de \"Sin confirmar\" a cualquier otro estado que no sea \"Cancelado\", el sistema va a proceder a crear la venta correspondiente.",
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Buscador de clientes integrado con AFIP/ARCA',
                        'body'                       => "Actualizamos la forma de buscar clientes al vender: la consulta a ARCA/AFIP por CUIT o DNI quedo integrada en el mismo buscador de cliente.\n\nUn solo lugar para buscar por nombre, datos del cliente, CUIT o DNI.\n\nSi no hay resultados y el texto coincide con un CUIT o DNI, un segundo Enter dispara la consulta a AFIP.",
                        'restricted_to_client_slug'  => '2r',
                    ],
                    [
                        'title'                     => 'Nuevas columnas codigo de barras y numero en reportes de ventas de articulos',
                        'body'                       => 'Se agregaron las columnas codigo de barras y numero en la tabla de los reportes de ventas de articulos',
                        'restricted_to_client_slug'  => 'arfren',
                    ],
                    [
                        'title'                     => 'Exportacion Excel de notas de credito con datos AFIP',
                        'body'                       => "En el archivo que descargas desde el listado de notas de credito, despues de \"Nro venta\" aparecen dos columnas nuevas: \"Nro factura\" (comprobante con letra, punto de venta y numero) y \"CAE\".",
                        'restricted_to_client_slug'  => 'arfren',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.0.2
            // -----------------------------------------------------------------
            [
                'version'     => '2.0.2',
                'title'       => 'Versión 2.0.2',
                'seeders' => [
                    ['seeder_class' => 'ExtensionSupportChatSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
                'commands' => [
                    ['command' => 'php artisan set_all_users_activity_minutes', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'Nuevo Libro IVA de ventas (debito) en Reportes',
                        'body'  => "En la tarjeta Iva Debito de Reportes tenes el boton Libro Iva, igual que en Iva Credito.\n\nDescargas un PDF con las facturas AFIP del periodo y las notas de credito facturadas.\n\nEl listado muestra fecha emitida, fecha registrada, comprobante, cliente, importes de IVA por alicuota y total.",
                    ],
                    [
                        'title' => 'Nuevo boton en el listado de articulos para actualizar la lista',
                        'body'  => "En la barra superior del listado (antes del buscador) aparece el boton \"Actualizar\".\n\nAl pulsarlo se recarga el listado con los articulos modificados recientemente, sin tener que salir y volver a entrar a la pantalla.",
                    ],
                    [
                        'title' => 'Aplicar margen de ganancia de proveedor desactivado por defecto',
                        'body'  => "Cuando crees un articulo, la opcion \"Aplicar margen de ganancia de proveedor\" va a estar destildada por defecto. Antes aparecia activada por defecto.\n\nLos articulos que ya tienen esta opcion activada, la van a seguir teniendo activada.",
                    ],
                    [
                        'title' => 'Historial de exportaciones Excel',
                        'body'  => "Al exportar clientes, proveedores o articulos desde el menu Crear, la exportacion sigue procesandose en segundo plano y te avisamos cuando el Excel esta listo.\n\nDebajo de Exportar aparece la opcion Historial de exportaciones: abris un listado con fecha, quien la hizo, estado y cantidad de registros.\n\nSi la exportacion termino bien, podes descargar el mismo archivo desde el historial sin tener que exportar de nuevo.",
                    ],
                    [
                        'title' => 'Exportacion a Excel de cheques filtrados en Reportes',
                        'body'  => "En la barra que aparece al filtrar cheques hay un boton Excel para descargar el listado actual.\n\nEl Excel incluye todas las columnas del cheque (numero, tipo, cliente, proveedor, banco, montos, fechas, estados, etc.) y al final una fila con el total de montos.",
                    ],
                    [
                        'title'                     => 'Alta de articulos: chequeo de codigo de proveedor repetido respeta tu configuracion',
                        'body'                       => "Si tenes activada la opcion \"Permitir codigos de proveedor repetidos en articulos\", al crear un articulo ya no se valida que el codigo de proveedor sea unico.\n\nSi esa opcion esta desactivada (comportamiento habitual), el sistema sigue avisando cuando el codigo de proveedor ya existe en otro articulo.",
                        'restricted_to_client_slug'  => 'ferretotal',
                    ],
                    [
                        'title'                     => 'Totales al final en los Excel de ventas (simple y completo)',
                        'body'                       => "Al exportar ventas con Excel o Excel full, la ultima fila del archivo muestra Total con la suma de importes en pesos y en dolares, segun la moneda de cada venta.",
                        'restricted_to_client_slug'  => 'golden-breeze',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.1.0
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.0',
                'title'       => 'Versión 2.1.0',
                'description' => 'Version registrada. Incluye impresion de compras a proveedor en PDF.',
                'notifications' => [
                    [
                        'title' => 'Nueva opcion para imprimir compras a proveedor en PDF',
                        'body'  => "En el modulo de compras a proveedores, al abrir una compra vas a ver el boton Imprimir en el formulario.\n\nEl PDF incluye datos del proveedor, numero y fecha de la compra, comprobante y estado (si estan cargados).\n\nTambien muestra el detalle de articulos: codigo de barras, codigo del proveedor, nombre, costo, cantidad pedida, notas y total por linea.",
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.1.1
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.1',
                'title'       => 'Versión 2.1.1',
                'seeders' => [
                    ['seeder_class' => 'PermisosVerStockSeeder',                          'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PermissionVenderDiscountStockIvaAplicadoSeeder',   'execution_order' => 2, 'run_scope' => 'per_database'],
                ],
                'commands' => [
                    ['command' => 'php artisan check_to_pay_id_de_ventas_eliminadas {user_id?}', 'execution_order' => 1, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'Buscador por Nro de factura en Ventas',
                        'body'  => "En el listado de ventas hay un buscador compacto (icono de factura) junto a los filtros de facturacion.\n\nIngresa el Nro de factura y presiona Enter: el sistema busca en todo el historial, no solo en las ventas del dia o rango de fechas que tengas cargado.\n\nPara volver al listado normal, borra el numero del buscador o usa \"Limpiar filtros\".",
                    ],
                    [
                        'title' => 'Metodo de pago en Vender: se mantiene tu eleccion al volver al modulo',
                        'body'  => "Al entrar a vender por primera vez, o si aun no elegiste metodo de pago, se asigna el metodo por defecto de tu empresa.\n\nSi cambias el metodo de pago, sales a otro modulo y vuelves a vender, se conserva el metodo que habias seleccionado.\n\nDespues de confirmar una venta, el metodo de pago vuelve al predeterminado para la siguiente operacion.",
                    ],
                    [
                        'title' => 'Nuevo permiso para editar stock por sucursal',
                        'body'  => "Permiso para editar stock solo de la sucursal a la que pertenece el empleado.\n\nDe esta forma, los permisos quedarian:\nVer stock solo de su sucursal: El empleado solo podra ver el stock de su sucursal.\nModificar stock: Permite que el empleado modifique el stock, de cualquier sucursal.\nModificar stock solo de su sucursal: El empleado solo podra modificar el stock de su sucursal.",
                    ],
                    [
                        'title' => 'Configurar las columnas de las tablas de articulos en pedidos, ventas y formularios',
                        'body'  => "En cada tabla de relacion (por ejemplo articulos de un pedido a proveedor o de una venta) tenes un boton para elegir que columnas ver, en que orden, con que ancho y si el texto hace salto de linea.\n\nLa configuracion es por relacion: lo que definis en pedidos a proveedor no cambia lo de ventas.\n\nSi no configuras nada, se mantiene el diseno definido en el sistema como hasta ahora.",
                    ],
                    [
                        'title' => 'Renovamos el diseno de los formularios',
                        'body'  => "Los titulos de cada campo ahora son mas compactos y legibles, con un estilo uniforme en todo el sistema.\n\nLos datos de solo lectura se muestran en bloques grises suaves; si un campo no tiene informacion, veras \"Sin datos\" en lugar de quedar vacio.\n\nLas fechas de creacion y actualizacion del registro quedaron alineadas con el mismo estilo, al pie del formulario.",
                    ],
                    [
                        'title' => 'Nuevo boton para enviar comprobante por WhatsApp en VENDER',
                        'body'  => "Luego de hacer una venta, al lado del boton de imprimir la venta, en caso de que el cliente de la venta tenga indicado el numero de whatsapp, vas a tener disponible el boton de whatsapp para enviar el comprobante al cliente.",
                    ],
                    [
                        'title' => 'Crear articulo al vuelo en VENDER',
                        'body'  => "Si buscas por nombre un articulo y no hay resultados, podes volver a presionar ENTER para que se cree en el momento y usarlo en la venta.\n\nAl no tener un precio configurado, vas a poder indicar el precio personalizado para esa venta.\n\nLuego podes indicar el precio correspondiente del articulo desde el modulo de LISTADO para que quede configurado para el futuro.",
                    ],
                    [
                        'title' => 'Nuevos permisos en Vender: "Descontar stock" y "Precios con IVA"',
                        'body'  => "Ahora podes controlar por usuario quien ve y usa cada checkbox del remito al vender.\n\nDescontar stock: permiso \"Descontar stock en VENDER\" - sin el, no aparece la opcion para descontar stock al guardar la venta.\n\nPrecios con IVA: permiso \"Usar precios con IVA en VENDER\" - sin el, no aparece la opcion para marcar precios con IVA incluido.",
                    ],
                    [
                        'title' => 'PDF de ofertas con listas de precio',
                        'body'  => "Si tu empresa usa listas de precio, al generar un PDF de ofertas desde el listado de articulos veras una opcion por cada plantilla y por cada lista.\n\nAl elegir una lista, el PDF muestra el precio final de esa lista para los articulos seleccionados o filtrados.",
                    ],
                    [
                        'title'                     => 'Zoom en fotos de articulos y compartir por WhatsApp en la tienda',
                        'body'                       => "En la vista de un producto, desde el celular podes hacer pellizco sobre la imagen para agrandarla y arrastrarla para ver el detalle.\n\nDebajo del boton de agregar al carrito hay un boton Compartir que abre WhatsApp con el nombre del producto y el link de la publicacion.",
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Configuracion del tamaño de letra en la descripcion de articulos de la tienda',
                        'body'                       => "En Configuracion Online podes definir el tamaño de letra (en pixeles) que se usa en la descripcion de cada articulo en la tienda.\n\nSi no configuras nada o el valor no es valido, se usa 16 px por defecto.",
                        'restricted_to_client_slug'  => 'truvari',
                    ],
                    [
                        'title'                     => 'Costos en compras a proveedor: hasta 4 decimales',
                        'body'                       => "En cada articulo de una compra podes cargar el costo con hasta 4 decimales (por ejemplo 10,1234).\n\nEn pantalla se muestran 2 decimales por defecto; los 3ro y 4to solo aparecen si los cargaste.",
                        'restricted_to_client_slug'  => 'distri-creo',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.1.2 — Sin ítems en la planilla
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.2',
                'title'       => 'Versión 2.1.2',
                'description' => 'Version registrada sin items de despliegue en la planilla.',
            ],

            // -----------------------------------------------------------------
            // 2.1.3
            // -----------------------------------------------------------------
            [
                'version'     => '2.1.3',
                'title'       => 'Versión 2.1.3',
                'seeders' => [
                    ['seeder_class' => 'ExtencionBarCodeEnVenderSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'EtiquetaMedidasSeeder',          'execution_order' => 2, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title' => 'Imagenes en tablas: rotacion automatica',
                        'body'  => "En las tablas del sistema (listados y buscador), cuando un articulo tiene varias fotos, se muestra la primera y cada 2 segundos pasa a la siguiente hasta recorrerlas todas.\n\nAl volver a la primera imagen, la rotacion se detiene sola.\n\nSi pasas el mouse sobre la foto, avanza a la siguiente.",
                    ],
                    [
                        'title' => 'Etiquetas de articulos configurables',
                        'body'  => "En el listado de articulos, al usar Codigos de barra (Etiquetas) ya no se descarga el PDF al instante: se abre un panel para armar la etiqueta como necesites.\n\nPodes elegir entre medidas guardadas o crear una medida propia con nombre, ancho y alto.\n\nMarcas que datos van en la etiqueta - nombre, codigo de barras, SKU, precio, categoria, marca, fecha del dia, nombre del negocio - y los ordenas arrastrando.\n\nHay una vista previa orientativa antes de imprimir.",
                    ],
                    [
                        'title'                     => 'Nueva opcion: mostrar u ocultar la seccion Catalogo en la tienda',
                        'body'                       => "En Configuracion Online podes activar \"Mostrar seccion Catalogo en la Tienda\" para que aparezca el enlace Catalogo en el navbar de la tienda online.\n\nPor defecto la opcion viene desactivada: las tiendas existentes no muestran Catalogo hasta que la actives y guardes.",
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
                        'title'                     => 'Listado de articulos: buscador puede filtrar la tabla para trabajo en lote',
                        'body'                       => "En el listado podes buscar por nombre, codigo o proveedor (y por categoria/stock) y los resultados se muestran directo en la tabla, sin abrir la pestana de seleccion de un solo articulo.\n\nSobre esos articulos filtrados podes usar las mismas acciones de siempre: generar etiquetas, PDF, exportar a Excel, etc.",
                        'restricted_to_client_slug'  => 'servian',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.0.7 — Columna Imagen en PDF tabla de artículos
            // -----------------------------------------------------------------
            [
                'version'     => '2.0.7',
                'title'       => 'Versión 2.0.7',
                'description' => 'Catálogo PDF: columna Imágenes (primera imagen del artículo) en plantillas article.',
                'seeders' => [
                    ['seeder_class' => 'PdfColumnOptionSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                ],
                'notifications' => [
                    [
                        'title' => 'Columna Imagen en PDF de artículos',
                        'body'  => "En perfiles PDF de artículos podés activar la columna Imágenes: muestra la primera foto del artículo en el listado PDF.\n\nConfigurala en Configuración → Generales → Perfiles de columnas PDF (modelo Artículo).",
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // 2.0.6 — PDF tabla de artículos con plantillas
            // -----------------------------------------------------------------
            [
                'version'     => '2.0.6',
                'title'       => 'Versión 2.0.6',
                'description' => 'Plantillas PDF tabulares para listado de artículos (PdfColumnProfile model_name article).',
                'seeders' => [
                    ['seeder_class' => 'PdfColumnOptionSeeder', 'execution_order' => 1, 'run_scope' => 'per_database'],
                    ['seeder_class' => 'PdfColumnProfileArticleSeeder', 'execution_order' => 2, 'run_scope' => 'per_user'],
                ],
                'notifications' => [
                    [
                        'title' => 'PDF tabla de artículos con plantillas',
                        'body'  => "En Configuración → Generales → Perfiles de columnas PDF podés crear plantillas para el modelo Artículo: columnas visibles, orden, ancho, pie de página e imagen de cabecera.\n\nEn el listado, dentro del menú de seleccionados o filtrados, aparece la sección \"PDF tabla (plantillas)\" para generar el PDF con los artículos elegidos o con todos los que devuelve el filtro activo.",
                    ],
                ],
            ],
        ];
    }
}
