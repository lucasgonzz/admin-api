<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Models\VersionNotification;
use App\Services\AvisoDeActualizacionService;
use App\Services\WhatsappSendService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Andamiaje común de los tests del aviso de actualización al cliente.
 *
 * 🔴 `Queue::fake()` va en el `setUp()` de todos, y no es comodidad: el hook `saved` de
 * `ClientVersionUpgrade` despacha el job a la conexión `database`, así que sin esto cada upgrade
 * cerrado en un test escribiría una fila en la tabla `jobs` de la base del slot. Los tests que
 * necesitan que el aviso corra de verdad llaman al servicio a mano, que es además la forma de
 * poder inyectarle el WhatsApp y la ventana de mentira.
 */
abstract class BaseDelAviso extends TestCase
{
    use DatabaseTransactions;

    /**
     * `versions.version` es UNIQUE: cada versión de cada test necesita su propio código.
     *
     * @var int
     */
    private $contador_de_versiones = 0;

    /**
     * WhatsApp de mentira compartido por el test, para poder leerlo en las aserciones.
     *
     * @var WhatsappDeMentira
     */
    protected $whatsapp;

    /**
     * Ventana de 24 hs de mentira. Arranca CERRADA.
     *
     * @var VentanaDeMentira
     */
    protected $ventana;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->whatsapp = new WhatsappDeMentira();
        $this->ventana  = new VentanaDeMentira();

        /*
         * Y los mismos dos, en el contenedor. Hace falta para los tests del comando de reintento:
         * ahí el `AvisoDeActualizacionService` lo arma Laravel al inyectarlo en el
         * `handle()` del comando, y sin estas dos líneas se construiría con los servicios REALES
         * —o sea, saliendo a Kapso desde un test—. El resolver de casilla va real a propósito: lo
         * que se falsea de él es el HTTP, con `Http::fake()`.
         */
        $this->app->instance(WhatsappSendService::class, $this->whatsapp);
        $this->app->instance(WhatsappSessionWindowService::class, $this->ventana);
    }

    /**
     * El servicio con el WhatsApp y la ventana de mentira ya enchufados.
     *
     * El resolver de casilla va real a propósito: la cascada de tres pasos es justamente lo que
     * más hay que probar, y lo que se falsea de ella es el HTTP con `Http::fake()`.
     *
     * @return AvisoDeActualizacionService
     */
    protected function servicio(): AvisoDeActualizacionService
    {
        return new AvisoDeActualizacionService(null, $this->whatsapp, $this->ventana);
    }

    /**
     * Cliente con su ClientApi, para que el resolver de casilla tenga a dónde preguntar.
     *
     * La API va como `vps` para que la URL no lleve `/public` y el `Http::fake()` de cada test sea
     * legible.
     *
     * @param array<string, mixed> $atributos Sobrescriben los del cliente.
     *
     * @return Client
     */
    protected function crear_cliente(array $atributos = []): Client
    {
        $client = new Client();
        $client->name            = 'Dueño de prueba';
        $client->company_name    = 'Negocio de prueba';
        $client->slug            = 'cliente-aviso-' . Str::random(8);
        $client->api_key         = 'clave-api-' . Str::random(6);
        $client->inbound_api_key = 'clave-inbound';
        $client->phone           = '3444111222';
        $client->is_active       = true;

        foreach ($atributos as $clave => $valor) {
            $client->{$clave} = $valor;
        }

        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-cliente-de-prueba.test';
        $api->path         = 'prueba/' . Str::random(6);
        $api->hosting_type = 'vps';
        $api->save();

        return $client->fresh();
    }

    /**
     * Versión publicada del catálogo, con código propio.
     *
     * @return Version
     */
    protected function crear_version(): Version
    {
        $this->contador_de_versiones++;

        $version               = new Version();
        $version->version      = '7.' . $this->contador_de_versiones . '.' . random_int(100, 999);
        $version->title        = 'Versión de prueba';
        $version->status       = 'published';
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * Novedad de una versión.
     *
     * @param Version     $version
     * @param string      $title
     * @param string      $body
     * @param int         $sort_order
     * @param Client|null $solo_para Cliente al que se restringe la novedad. Null = para todos.
     *
     * @return VersionNotification
     */
    protected function crear_novedad(
        Version $version,
        string $title,
        string $body,
        int $sort_order = 0,
        ?Client $solo_para = null
    ): VersionNotification {
        $novedad             = new VersionNotification();
        $novedad->version_id = $version->id;
        $novedad->title      = $title;
        $novedad->body       = $body;
        $novedad->sort_order = $sort_order;
        $novedad->is_active  = true;
        $novedad->save();

        if ($solo_para instanceof Client) {
            $novedad->restrictedClients()->attach($solo_para->id);
        }

        return $novedad;
    }

    /**
     * Actualización `pendiente` del cliente, con las versiones confirmadas en la pivot.
     *
     * Nace `pendiente` y no `terminada` a propósito: el hook solo mira los `save()` que MUEVEN el
     * status, así que cada test la cierra cuando quiere.
     *
     * @param Client                 $client
     * @param array<int, Version>    $versiones Las que trae el upgrade; la última es el destino.
     * @param array<string, mixed>   $atributos Sobrescriben los del upgrade.
     *
     * @return ClientVersionUpgrade
     */
    protected function crear_upgrade(Client $client, array $versiones, array $atributos = []): ClientVersionUpgrade
    {
        $origen  = $this->crear_version();
        $destino = end($versiones);
        $api     = $client->client_apis()->first();

        $upgrade = ClientVersionUpgrade::create(array_merge([
            'client_id'            => $client->id,
            'from_version_id'      => $origen->id,
            'to_version_id'        => $destino->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => is_null($api) ? null : $api->id,
        ], $atributos));

        $upgrade->confirmed_versions()->sync(collect($versiones)->pluck('id')->all());

        return $upgrade->fresh();
    }

    /**
     * URL exacta a la que el resolver de casilla le pregunta al cliente. Para el `Http::fake()`.
     *
     * @return string
     */
    protected function url_del_contacto(): string
    {
        return 'https://api-cliente-de-prueba.test/api/admin-sync/contacto-dueno';
    }
}
