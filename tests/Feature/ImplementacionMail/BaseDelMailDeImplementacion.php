<?php

namespace Tests\Feature\ImplementacionMail;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Fakes\ServidorSmtpFake;
use Tests\TestCase;

/**
 * Andamiaje común de los tests del mail de hito de la implementación.
 *
 * Lo que arma: un cliente con su API activa (con la dirección de su sistema), una implementación
 * con sus ocho etapas en el estado que cada test pida, el link base del formulario y datos de
 * ejemplo válidos para cada hito. Todo dentro de la transacción del test.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDelMailDeImplementacion extends TestCase
{
    use DatabaseTransactions;

    /**
     * Dirección del sistema del cliente de prueba.
     */
    const URL_DEL_SISTEMA = 'https://losandes.comerciocity.com';

    /**
     * Base del link del formulario, tal como la guarda el admin en `implementation_form_url`.
     */
    const URL_DEL_FORMULARIO = 'https://admin.comerciocity.com/configuracion';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // El link del formulario es URL base + token. Sin esta setting el link es null.
        AdminSetting::updateOrCreate(['key' => 'implementation_form_url'], ['value' => self::URL_DEL_FORMULARIO]);
    }

    /**
     * Los servidores SMTP de mentira que levantó el test, para bajarlos al terminar.
     *
     * @var array<int, ServidorSmtpFake>
     */
    private $servidores_smtp = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->servidores_smtp as $servidor) {
            $servidor->bajar();
        }

        $this->servidores_smtp = [];

        parent::tearDown();
    }

    /**
     * Levanta un servidor SMTP de verdad (un proceso aparte, en 127.0.0.1) y apunta el mailer `admin`
     * a él. Si en este entorno no se puede lanzar un proceso, el test se saltea.
     *
     * @param string $modo ServidorSmtpFake::MODO_RECHAZA (550 en cada RCPT) | MODO_ACEPTA.
     *
     * @return ServidorSmtpFake
     */
    protected function levantar_un_smtp(string $modo): ServidorSmtpFake
    {
        $servidor = ServidorSmtpFake::levantar($modo);

        if ($servidor === null) {
            $this->markTestSkipped('No se pudo lanzar el servidor SMTP de prueba en este entorno.');
        }

        $this->servidores_smtp[] = $servidor;

        $servidor->apuntar_el_mailer('admin');

        return $servidor;
    }

    /**
     * Cliente de prueba con su API activa.
     *
     * @param array<string, mixed> $atributos Sobrescriben los del cliente.
     * @param bool                 $con_api   false para un cliente sin ninguna API (sin dirección de sistema).
     *
     * @return Client
     */
    protected function crear_cliente(array $atributos = [], bool $con_api = true): Client
    {
        $client                  = new Client();
        $client->name            = 'Martina Gómez';
        $client->company_name    = 'Ferretería Los Andes';
        $client->slug            = 'impl-mail-' . Str::random(8);
        $client->api_key         = 'clave-api-' . Str::random(6);
        $client->inbound_api_key = 'clave-inbound';
        $client->phone           = '3444111222';
        $client->is_active       = true;

        foreach ($atributos as $clave => $valor) {
            $client->{$clave} = $valor;
        }

        $client->save();

        if ($con_api) {
            $api               = new ClientApi();
            $api->client_id    = $client->id;
            $api->url          = 'https://api-losandes.comerciocity.com';
            $api->path         = 'losandes/api';
            $api->spa_url      = self::URL_DEL_SISTEMA;
            $api->hosting_type = 'vps';
            $api->save();

            $client->active_client_api_id = $api->id;
            $client->save();
        }

        return $client->fresh();
    }

    /**
     * Implementación del cliente con sus ocho etapas.
     *
     * @param Client                $client
     * @param array<int, string>    $etapas    Estado de cada etapa por número (status del panel:
     *                                         completed | in_progress | skipped | pending). Las que
     *                                         no se nombran quedan pendientes. Con `null` no se
     *                                         crea ninguna fila.
     * @param array<string, mixed>  $atributos Sobrescriben los de la implementación.
     *
     * @return Implementation
     */
    protected function crear_implementacion(Client $client, ?array $etapas = [], array $atributos = []): Implementation
    {
        $impl = Implementation::create(array_merge([
            'client_id'     => $client->id,
            'current_stage' => 1,
            'status'        => 'in_progress',
            'form_token'    => 'tok' . Str::random(10),
        ], $atributos));

        if ($etapas !== null) {
            $this->poner_las_etapas($impl, $etapas);
        }

        return $impl->fresh();
    }

    /**
     * Deja las ocho etapas de la implementación en el estado pedido, creando las filas que falten.
     *
     * @param Implementation     $impl
     * @param array<int, string> $etapas Estado por número de etapa. Las que no se nombran quedan pendientes.
     *
     * @return void
     */
    protected function poner_las_etapas(Implementation $impl, array $etapas): void
    {
        for ($numero = 1; $numero <= 8; $numero++) {
            ImplementationStage::updateOrCreate(
                ['implementation_id' => $impl->id, 'stage_number' => $numero],
                ['status' => isset($etapas[$numero]) ? $etapas[$numero] : 'pending']
            );
        }
    }

    /**
     * Mapa de estados de etapas: las primeras `$completas` completadas, `$en_curso` en curso, las
     * `$salteadas` salteadas y el resto pendientes. Es lo que se pasa a `crear_implementacion()`.
     *
     * @param int                $completas
     * @param int|null           $en_curso
     * @param array<int, int>    $salteadas
     *
     * @return array<int, string>
     */
    protected function estados(int $completas, ?int $en_curso = null, array $salteadas = []): array
    {
        $mapa = [];

        for ($numero = 1; $numero <= 8; $numero++) {
            if (in_array($numero, $salteadas, true)) {
                $mapa[$numero] = 'skipped';
            } elseif ($numero <= $completas) {
                $mapa[$numero] = 'completed';
            } elseif ($numero === $en_curso) {
                $mapa[$numero] = 'in_progress';
            } else {
                $mapa[$numero] = 'pending';
            }
        }

        return $mapa;
    }

    /**
     * Lead promovido al cliente, con su email. Es la cuarta fuente de la casilla.
     *
     * @param Client $client
     * @param string $email
     *
     * @return Lead
     */
    protected function crear_lead_promovido(Client $client, string $email): Lead
    {
        $lead                     = new Lead();
        $lead->phone              = '+5493417778899';
        $lead->email              = $email;
        $lead->contact_name       = 'Martina Gómez';
        $lead->status             = 'cerrado_ganado';
        $lead->promoted_client_id = $client->id;
        $lead->save();

        return $lead;
    }

    /**
     * Datos válidos de ejemplo para un hito, los mismos números del prototipo.
     *
     * @param string $hito
     *
     * @return array<string, mixed>
     */
    protected function datos_de_ejemplo(string $hito): array
    {
        switch ($hito) {
            case 'acceso':
                return ['articulos' => 2418];

            case 'imagenes':
                return ['con_foto' => 1904, 'total' => 2418, 'a_revisar' => 132];

            case 'categorias':
                return ['opciones' => $this->opciones_de_ejemplo()];

            case 'listo':
                return ['resumen' => [
                    'articulos' => 2418, 'con_foto' => 2286, 'categorias' => 14,
                    'marcas' => 63, 'clientes' => 412, 'proveedores' => 38,
                ]];

            default:
                return [];
        }
    }

    /**
     * Las tres opciones de categorías de ejemplo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function opciones_de_ejemplo(): array
    {
        return [
            [
                'nombre' => 'Por tipo de producto', 'categorias' => 14,
                'base' => 'Agrupa por lo que el producto es, como en el mostrador.',
                'ejemplos' => ['Fijaciones', 'Herramientas manuales', 'Pinturas', 'Electricidad'],
            ],
            [
                'nombre' => 'Por uso', 'categorias' => 9,
                'base' => 'Agrupa por lo que el cliente quiere hacer.',
                'ejemplos' => ['Baño', 'Cocina', 'Jardín', 'Obra'],
            ],
            [
                'nombre' => 'Por rubro y detalle', 'categorias' => 22,
                'base' => 'Más fino: cada rubro se abre en subcategorías.',
                'ejemplos' => ['Tornillería > Autoperforantes', 'Pintura > Látex'],
            ],
        ];
    }

    /**
     * Estado de las etapas que corresponde a cada hito, como en el prototipo.
     *
     * @param string $hito
     *
     * @return array<int, string>
     */
    protected function estados_del_hito(string $hito): array
    {
        switch ($hito) {
            case 'bienvenida':
                return $this->estados(0, 1);

            case 'instalado':
                return $this->estados(2, 3);

            case 'listo':
                return $this->estados(5, 6);

            default:
                return $this->estados(3, 4);
        }
    }
}
