<?php

namespace Database\Seeders;

use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder as VersionSeederModel;
use Illuminate\Database\Seeder;

/**
 * Carga en admin-api las versiones desplegadas en producción (empresa-api),
 * con sus seeders, comandos artisan, tareas manuales y notificaciones a usuarios.
 *
 * Origen: planilla de despliegue (pestañas Seeders, Comandos y Notificaciones).
 * Ejecutar: php artisan db:seed --class=VersionProductionSeeder
 */
class VersionProductionSeeder extends Seeder
{
    /**
     * Inserta o actualiza versiones y sus ítems hijos sin duplicar registros.
     *
     * @return void
     */
    public function run()
    {
        // Recorremos cada versión definida en el array de producción
        foreach ($this->production_versions() as $version_data) {
            // Versión padre: clave única por código semver
            $version_model = Version::firstOrCreate(
                ['version' => $version_data['version']],
                [
                    'title' => $version_data['title'] ?? ('Versión ' . $version_data['version']),
                    'description' => $version_data['description'] ?? null,
                    'status' => $version_data['status'] ?? 'published',
                    'published_at' => $version_data['published_at'] ?? now(),
                ]
            );

            $this->sync_notifications($version_model, $version_data['notifications'] ?? []);
            $this->sync_seeders($version_model, $version_data['seeders'] ?? []);
            $this->sync_commands($version_model, $version_data['commands'] ?? []);
            $this->sync_manual_tasks($version_model, $version_data['manual_tasks'] ?? []);

            $this->command->info('Versión ' . $version_model->version . ' sincronizada.');
        }
    }

    /**
     * Notificaciones de la versión (título + cuerpo, orden por sort_order).
     *
     * @param Version $version_model
     * @param array<int, array<string, mixed>> $notifications
     * @return void
     */
    private function sync_notifications(Version $version_model, array $notifications)
    {
        foreach ($notifications as $notification) {
            VersionNotification::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'title' => $notification['title'],
                ],
                [
                    'body' => $notification['body'] ?? '',
                    'sort_order' => (int) ($notification['sort_order'] ?? 0),
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Seeders de empresa-api asociados a la versión.
     *
     * @param Version $version_model
     * @param array<int, array<string, mixed>> $seeders
     * @return void
     */
    private function sync_seeders(Version $version_model, array $seeders)
    {
        foreach ($seeders as $seeder) {
            VersionSeederModel::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'seeder_class' => $seeder['seeder_class'],
                ],
                [
                    'description' => $seeder['description'] ?? null,
                    'execution_order' => (int) ($seeder['execution_order'] ?? 0),
                    'is_required' => $seeder['is_required'] ?? true,
                    'run_scope' => $seeder['run_scope'] ?? 'per_database',
                ]
            );
        }
    }

    /**
     * Comandos artisan a ejecutar en el deploy de la versión.
     *
     * @param Version $version_model
     * @param array<int, array<string, mixed>> $commands
     * @return void
     */
    private function sync_commands(Version $version_model, array $commands)
    {
        foreach ($commands as $command) {
            VersionCommand::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'command' => $command['command'],
                ],
                [
                    'description' => $command['description'] ?? null,
                    'execution_order' => (int) ($command['execution_order'] ?? 0),
                    'is_required' => $command['is_required'] ?? true,
                    'run_scope' => $command['run_scope'] ?? 'per_user',
                ]
            );
        }
    }

    /**
     * Tareas manuales (no automatizables por artisan).
     *
     * @param Version $version_model
     * @param array<int, array<string, mixed>> $manual_tasks
     * @return void
     */
    private function sync_manual_tasks(Version $version_model, array $manual_tasks)
    {
        foreach ($manual_tasks as $manual_task) {
            VersionManualTask::firstOrCreate(
                [
                    'version_id' => $version_model->id,
                    'title' => $manual_task['title'],
                ],
                [
                    'description' => $manual_task['description'] ?? null,
                    'execution_order' => (int) ($manual_task['execution_order'] ?? 0),
                    'is_required' => $manual_task['is_required'] ?? true,
                ]
            );
        }
    }

    /**
     * Definición de versiones en producción desde 1.0.1.
     * Cada entrada puede incluir: seeders, commands, manual_tasks, notifications.
     *
     * @return array<int, array<string, mixed>>
     */
    private function production_versions(): array
    {
        return [
            [
                'version' => '1.0.1',
                'title' => 'Versión 1.0.1',
                'description' => 'Despliegue inicial de datos y extensiones base.',
                'seeders' => [
                    ['seeder_class' => 'MonedaSeeder', 'execution_order' => 1],
                    ['seeder_class' => 'ExtNTDescriptionSeeder', 'execution_order' => 12],
                    ['seeder_class' => 'CerrarVentasExtencionSeeder', 'execution_order' => 13],
                    ['seeder_class' => 'providers_article_price_from_costo_mas_iva_seeder', 'execution_order' => 14],
                    ['seeder_class' => 'ConceptoAjusteSeeder', 'execution_order' => 17],
                    ['seeder_class' => 'ExtencionDesEnVenderSeeder', 'execution_order' => 20],
                ],
                'commands' => [
                    ['command' => 'php artisan iniciar_credit_accounts {user_id?} {client_id?}', 'execution_order' => 2],
                    ['command' => 'php artisan set_article_purchases_address_id {user_id?} {sale_id?}', 'execution_order' => 3],
                    ['command' => 'php artisan set_afip_tickets_data {user_id?}', 'execution_order' => 4],
                    ['command' => 'php artisan set_article_provider_codes {article_id?}', 'execution_order' => 5],
                    ['command' => 'php artisan set_articles_prices {user_id?}', 'execution_order' => 6],
                    ['command' => 'php artisan set_costo_ventas {user_id?} {sale_id?}', 'execution_order' => 7],
                    ['command' => 'php artisan check_to_pay_id_de_ventas_eliminadas {user_id?}', 'execution_order' => 8],
                    ['command' => 'php artisan set_sales_total_factuado', 'execution_order' => 10],
                    ['command' => 'php artisan set_afip_ticket_nota_credito_data', 'execution_order' => 11],
                    ['command' => 'php artisan set_provider_order_afip_tickets_user_id {user_id?}', 'execution_order' => 15],
                    ['command' => 'php artisan set_iva_condition_slugs', 'execution_order' => 16],
                    ['command' => 'php artisan set_sub_total_sales {user_id?}', 'execution_order' => 18],
                    ['command' => 'php artisan set_payment_method_types', 'execution_order' => 19],
                ],
                'manual_tasks' => [
                    [
                        'title' => 'Subir logo de ARCA a public/afip',
                        'description' => 'Subir logo de arca a public/afip (a ambos sub dominios, osea al 1 como al 2), sacar el logo de ffpe',
                        'execution_order' => 9,
                    ],
                ],
            ],
            [
                'version' => '1.0.2',
                'title' => 'Versión 1.0.2',
                'seeders' => [
                    ['seeder_class' => 'ExtVentasConEstadosSeeder', 'execution_order' => 1],
                ],
            ],
            [
                'version' => '1.1.0',
                'title' => 'Versión 1.1.0',
                'notifications' => [
                    [
                        'title' => 'Establece el orden de las columnas como vos quieras',
                        'body' => "Agregamos la funcionalidad para que puedas configurar cada modulo del sistema como necesites.\n\n"
                            . "Vas a poder elegir el: orden, tamaño y saltos de linea para cada columna de las tablas.\n\n"
                            . "Enterate como funciona con este video: https://drive.google.com/file/d/1XY72WDNu-QRbgI7ED4JZFnICNxPI-OPb/view?usp=sharing\n\n"
                            . "Lo mismo aplica para los resultados de busqueda, enterate como funciona con este video: https://drive.google.com/file/d/1cqKIlZ9FyQV25buDgJbrm0vNNBLOAxET/view?usp=sharing",
                        'sort_order' => 1,
                    ],
                ],
            ],
            [
                'version' => '1.1.1',
                'title' => 'Versión 1.1.1',
                'seeders' => [
                    ['seeder_class' => 'SheetTypeSeeder', 'execution_order' => 1],
                    ['seeder_class' => 'PdfColumnOptionSeeder', 'execution_order' => 2],
                    ['seeder_class' => 'PdfColumnProfileSeeder', 'execution_order' => 3],
                ],
                'notifications' => [
                    [
                        'title' => 'Ampliar contenido dentro de formularios',
                        'body' => "Dentro de los formularios, vas a poder ampliar la informacion de las tablas.\n\n"
                            . "Por ejemplo dentro de articulos -> descuentos, compras a proveedores -> facturas, etc.",
                        'sort_order' => 1,
                    ],
                ],
            ],
            [
                'version' => '1.1.2',
                'title' => 'Versión 1.1.2',
                'notifications' => [
                    [
                        'title' => 'Nuevas columnas por cada sucursal en "movimientos de depositos"',
                        'body' => 'Cuando estes armando un "movimiento de depositos" desde el LISTADO, vas a poder ver por cada articulo del movimiento el stock que tiene actualmente en cada deposito.',
                        'sort_order' => 1,
                    ],
                ],
            ],
            [
                'version' => '1.1.3',
                'title' => 'Versión 1.1.3',
                'commands' => [
                    ['command' => 'php artisan check_extencion_listas_de_precios', 'execution_order' => 1],
                ],
                'notifications' => [
                    [
                        'title' => 'Opcion "Aplicar IVA" en modulo VENDER',
                        'body' => "Cuando estes creado o actualizando una venta, vas a poder elegir si queres que se aplique el iva a los productos (opcion por defecto), o si queres que no se aplique.\n\n"
                            . "Si permanece activado, el precio de venta va a ser el que ya venis trabajando, el precio final del articulo.\n\n"
                            . "Si lo desactivas, el sistema va a calcular el precio que el articulo deberia de tener para que, una vez sumado el iva, de como resultado el precio final.\n\n"
                            . "Es el mismo calculo que hace en la factura para mostrar el precio del articulo sin iva, para que luego el precio del articulo con iva sea igual al precio final.\n\n"
                            . "Si desactivas esta opcion y facturas la venta, el sistema no le va a \"sumar\" el iva a los precios, va a usar el precio final de la venta como el precio con el iva incluido.\n\n"
                            . "Ejemplo: tu articulo tiene un precio final de \$1.000, si creas una venta con la opcion \"precios con iva\" activada, el precio del articulo va a ser \$1.000, y si facturas la venta en la factura va a aparecer precio sin iva: \$826.45, monto iva: \$173.55, precio con iva: \$1.000 (valor original al que se vendio).\n\n"
                            . "Pero si desactivas la opcion de \"precio con iva\", el precio de venta va a ser \$826.45. Y si facturas la venta, en la factura aparecera como: precio sin iva: \$683.02, monto iva: \$143.43, precio con iva: \$826.45 (valor original al que se vendio).",
                        'sort_order' => 1,
                    ],
                ],
            ],
            [
                'version' => '1.1.5',
                'title' => 'Versión 1.1.5',
                'seeders' => [
                    ['seeder_class' => 'PdfColumnSinPreciosSeeder', 'execution_order' => 1],
                ],
                'commands' => [
                    ['command' => 'php artisan set_sales_ganancia', 'execution_order' => 1],
                ],
                'notifications' => [
                    [
                        'title' => 'Actualizar una venta sin cliente asignado',
                        'body' => 'Si hiciste una venta sin asignarle un cliente y necesitas actualizarla, vas a poder hacerlo siempre y cuando no tenga una caja asignada, y tenga un unico metodo de pago asignado',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Total vendido a C/C en ventas',
                        'body' => 'En el modulo de VENTAS, arriba a la izquierda, debajo del "Total" vendido, va a aparecer el monto vendido a cuenta corriente.',
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'version' => '1.1.7',
                'title' => 'Versión 1.1.7',
                'seeders' => [
                    ['seeder_class' => 'InputsSizeSeeder', 'execution_order' => 1],
                ],
                'notifications' => [
                    [
                        'title' => 'Opcion para imprimir la fecha corriente en PDF de ventas, en lugar de la fecha en que se genero la venta',
                        'body' => 'Si queres activar esta opcion, pedinos para que te lo configuremos',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Nueva opcion para elegir el tamaño de los elementos',
                        'body' => "Si queres que los componentes del sistema tengan un tamaño mas chico para ver mas informacion dentro de la pantalla, vas a tener esta nueva opcion para configurarlo.\n\n"
                            . 'Esta opcion esta disponible desde Configuracion -> Interfaz -> Tamaño de los componentes',
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'version' => '1.1.8',
                'title' => 'Versión 1.1.8',
                'seeders' => [
                    ['seeder_class' => 'ExtencionAdjuntarArchivosSeeder', 'execution_order' => 1],
                ],
                'notifications' => [
                    [
                        'title' => 'Restaurar importacion de Excel',
                        'body' => 'Luego de hacer una importacion, desde el "Historial de importaciones" vas a poder hacer un rollback para reestablecer los articulos a como estaban antes de hacer la importacion',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Nuevos campos para actualizar masivamente',
                        'body' => 'Desde el Listado, luego de hacer un filtrado o una seleccion manual, vas a tener la posibilidad de actualizar masivamente opciones como: 1. Disponibilidad en la tienda online 2. Aplicar IVA 3. Costos en dolares Y todas las opciones de tipo "Check" que antes no habia',
                        'sort_order' => 2,
                    ],
                    [
                        'title' => 'Exportar excel de ventas con detalle de articulos',
                        'body' => 'Debajo del total en VENDER, vas a tener un nuevo boton verde "Excel full", el cual te va a generar un excel con los datos de todos los articulos vendidos de las ventas que estes viendo en el modulo de VENTAS',
                        'sort_order' => 3,
                    ],
                ],
            ],
            [
                'version' => '1.1.9',
                'title' => 'Versión 1.1.9',
                'seeders' => [
                    ['seeder_class' => 'PermissionProhibirListaPreciosVender', 'execution_order' => 1],
                ],
                'commands' => [
                    ['command' => 'php artisan set_user_activity {user_id?}', 'execution_order' => 1],
                    ['command' => 'php artisan check_rounding_env_variables', 'execution_order' => 2],
                ],
                'notifications' => [
                    [
                        'title' => 'Opcion para ampliar las imagenes de los articulos',
                        'body' => 'Desde el LISTADO o cuando busques un articulo desde VENDER, vas a poder hacer click sobre la imagen de un articulo para poder verla en un tamaño ampliado',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Stock en vender',
                        'body' => 'Cuando busques un articulo por su codigo de barras, al lado del campo donde ingresas el codigo de barras, se te va a informar el stock del articulo, y entre parentesis, el stock en la sucursal seleccionada para vender',
                        'sort_order' => 2,
                    ],
                    [
                        'title' => 'Importar excel de articulos "recibidos" en compras a proveedores',
                        'body' => 'Desde el modulo de Proveedores -> compras, vas a poder importar un archivo excel para indicar los articulos comprados, y tambien los articulos recibidos. El sistema te va a mostrar las diferencias entre lo pedido y lo recibido de tu proveedor para que puedas verlo facilmente. Te dejamos este tutorial para que entiendas como usarlo: https://drive.google.com/drive/folders/1P4FxZ6osYE4gCqlYuzKgyuLkIeLXevem?usp=sharing',
                        'sort_order' => 3,
                    ],
                    [
                        'title' => 'Se soluciono la descarga de recursos en el telefono',
                        'body' => '',
                        'sort_order' => 4,
                    ],
                    [
                        'title' => 'Envio de Mails de ventas',
                        'body' => 'Luego de crear o actualizar una venta, si el cliente seleccionado tiene indicado un email, vas a poder activar la opcion de "Enviar correo al cliente", desde el modulo de VENDER, debajo del cliente seleccionado. Al activarlo, se enviara un correo al cliente indicandole sobre su nueva venta, y dejando dos links para que pueda: ver el comprobante, y ver su resumen de la cuenta corriente.',
                        'sort_order' => 5,
                    ],
                    [
                        'title' => 'Crear conceptos de movimientos de caja personalizados',
                        'body' => 'Desde ABM -> Tesoreria -> Conceptos movimiento caja vas a poder crear y editar los conceptos que necesites',
                        'sort_order' => 6,
                    ],
                    [
                        'title' => 'Atajo para agregar articulos de Sugerencia de Stock a Movimiento de deposito',
                        'body' => 'Cuando estes viendo los articulos de una sugerencia de stock, vas a tener una nueva columna a la izquierda para ir tildando los articulos que quieras agregar a un "Movimiento de depositos" de forma automatica.',
                        'sort_order' => 7,
                    ],
                ],
            ],
            [
                'version' => '1.2.0',
                'title' => 'Versión 1.2.0',
                'seeders' => [
                    ['seeder_class' => 'ConsolidacionSeeder', 'execution_order' => 1],
                ],
            ],
            [
                'version' => '1.2.1',
                'title' => 'Versión 1.2.1',
                'seeders' => [
                    ['seeder_class' => 'ExtencionHideIvaDiscountStockVenderSeeder', 'execution_order' => 1],
                ],
            ],
            [
                'version' => '1.2.2',
                'title' => 'Versión 1.2.2',
                'seeders' => [
                    ['seeder_class' => 'ExtencionDuplicarPresupuestosSeeder', 'execution_order' => 1],
                    ['seeder_class' => 'ExtencionEnviarMailClientesSeeder', 'execution_order' => 2],
                ],
                'commands' => [
                    ['command' => 'php artisan init_article_pdf', 'execution_order' => 1],
                ],
            ],
            [
                'version' => '2.0.1',
                'title' => 'Versión 2.0.1',
                'seeders' => [
                    ['seeder_class' => 'TiendaNubeOrderStatusSeeder', 'execution_order' => 1],
                    ['seeder_class' => 'ConceptoMovimientoCajaCompensacionSeeder', 'execution_order' => 2],
                ],
            ],
            [
                'version' => '2.0.2',
                'title' => 'Versión 2.0.2',
                'seeders' => [
                    ['seeder_class' => 'ExtensionSupportChatSeeder', 'execution_order' => 1],
                ],
                'commands' => [
                    ['command' => 'php artisan set_all_users_activity_minutes', 'execution_order' => 1],
                ],
            ],
            [
                'version' => '2.1.0',
                'title' => 'Versión 2.1.0',
                'description' => 'Versión registrada sin ítems de despliegue en la planilla.',
            ],
            [
                'version' => '2.1.1',
                'title' => 'Versión 2.1.1',
                'commands' => [
                    ['command' => 'php artisan check_to_pay_id_de_ventas_eliminadas {user_id?}', 'execution_order' => 1],
                ],
            ],
            [
                'version' => '2.1.2',
                'title' => 'Versión 2.1.2',
                'seeders' => [
                    ['seeder_class' => 'ExtencionBarCodeEnVenderSeeder', 'execution_order' => 1],
                ],
            ],
        ];
    }
}
