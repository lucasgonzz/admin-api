<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Un upgrade en `terminada` deja al cliente registrado en la versión de destino.
 *
 * 🔴 Hasta el 28/8/2026 eso lo hacía UN SOLO método en todo el admin:
 * `PublishVersionService::syncExisting()`, que es el botón aparte "sincronizar al cliente". Los
 * otros dos caminos que dejan un upgrade en `terminada` no tocaban `clients.current_version_id`, y
 * los dos dejaron un cliente real desalineado en producción:
 *
 *  1. **El pipeline de deployment.** `step_complete()` promovía `active_client_api_id` del cliente
 *     y no movía la versión. Cliente Servian, upgrade 56 del 1/8/2026: deployment `completed` con
 *     los seis pasos hechos, y el cliente siguió figurando en 3.3.1 con la 3.3.3 arriba.
 *  2. **La edición a mano del select "Estado"** en la grilla del admin-spa, que pasa por el
 *     `update_json` genérico. Cliente ananda, upgrade 72 del 24/8/2026 — y ahí el efecto fue peor
 *     que cosmético: la versión desalineada es la que el admin propone como ORIGEN del próximo
 *     update, y un origen equivocado ya hizo fallar un deployment de ese mismo cliente en agosto
 *     (`informes/20260821-hotfix-pago-cc-multimoneda-ananda-v2011.md`).
 *
 * La derivación vive en un hook del modelo justamente para que ningún camino nuevo pueda volver a
 * dejarla desalineada sin que nada avise.
 */
class VersionDelClienteAlTerminarUpgradeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * `versions.version` es UNIQUE: cada versión del test necesita su propio código.
     *
     * @var int
     */
    private $contador_de_versiones = 0;

    /**
     * El camino de la grilla del admin-spa: se cambia el select "Estado" a Terminada y nada más.
     * Es el caso ananda.
     *
     * @return void
     */
    public function test_marcar_terminada_a_mano_alinea_la_version_del_cliente()
    {
        $upgrade = $this->crear_upgrade();
        $client  = $upgrade->client;

        $this->assertSame(
            (int) $upgrade->from_version_id,
            (int) $client->fresh()->current_version_id,
            'precondición: el cliente arranca en la versión de origen'
        );

        $upgrade->update(['status' => 'terminada']);

        $this->assertSame(
            (int) $upgrade->to_version_id,
            (int) $client->fresh()->current_version_id,
            'marcar el upgrade Terminada deja al cliente en la versión de destino'
        );
    }

    /**
     * El camino del pipeline: `step_complete()` es la última etapa del deployment. Es el caso
     * Servian. Se invoca por reflexión porque es privada y porque el resto del pipeline es SSH:
     * esta etapa no toca la red, solo escribe estado.
     *
     * @return void
     */
    public function test_el_deployment_completo_cierra_el_upgrade_y_alinea_la_version()
    {
        $upgrade = $this->crear_upgrade(['status' => 'actualizandose']);
        $client  = $upgrade->client;

        $this->crear_credencial_ssh('shared_hosting');

        $service = new DeploymentService($upgrade);

        $step_complete = new ReflectionMethod(DeploymentService::class, 'step_complete');
        $step_complete->setAccessible(true);
        $step_complete->invoke($service);

        $upgrade_fresco = $upgrade->fresh();

        $this->assertSame('completed', $upgrade_fresco->deployment_status);
        $this->assertSame('terminada', $upgrade_fresco->status, 'el deployment completo cierra el upgrade');
        $this->assertNotNull($upgrade_fresco->finished_at);

        $client_fresco = $client->fresh();

        $this->assertSame(
            (int) $upgrade->to_version_id,
            (int) $client_fresco->current_version_id,
            'el deployment completo deja al cliente en la versión que acaba de instalar'
        );

        $this->assertSame(
            (int) $upgrade->target_client_api_id,
            (int) $client_fresco->active_client_api_id,
            'y sigue promoviendo la API destino a activa, como antes'
        );
    }

    /**
     * 🔴 La protección que hace seguro que el hook corra en cualquier save(): marcar `terminada` un
     * upgrade viejo no puede retroceder a un cliente que ya subió por otro camino.
     *
     * La comparación es por VALOR semántico y no por `id` de la fila de `versions`: con hotfixes de
     * por medio (una "3.3.1.1" cargada después de una "3.3.2") el `id` no refleja el orden.
     *
     * @return void
     */
    public function test_un_upgrade_viejo_terminado_no_baja_la_version_del_cliente()
    {
        $upgrade = $this->crear_upgrade();
        $client  = $upgrade->client;

        /* El cliente ya subió a una versión posterior, cargada DESPUÉS que la de destino. */
        $mas_nueva = $this->crear_version('9.9.9');
        $client->update(['current_version_id' => $mas_nueva->id]);

        $upgrade->update(['status' => 'terminada']);

        $this->assertSame(
            (int) $mas_nueva->id,
            (int) $client->fresh()->current_version_id,
            'el upgrade viejo no puede bajar al cliente'
        );
    }

    /**
     * El hook solo mira los save() que MUEVEN el status a `terminada`. Un upgrade que se guarda por
     * cualquier otro motivo —marcar un paso, anotar una nota, pasar a `fallida`— no toca al cliente
     * ni paga las consultas de la alineación.
     *
     * @return void
     */
    public function test_un_save_que_no_termina_el_upgrade_no_toca_la_version_del_cliente()
    {
        $upgrade = $this->crear_upgrade();
        $client  = $upgrade->client;

        $version_previa = (int) $client->fresh()->current_version_id;

        /* Otro status. */
        $upgrade->update(['status' => 'actualizandose']);
        $this->assertSame($version_previa, (int) $client->fresh()->current_version_id);

        /* Un campo cualquiera, sin tocar el status. */
        $upgrade->update(['migraciones_corridas_at' => now()]);
        $this->assertSame($version_previa, (int) $client->fresh()->current_version_id);

        /* Y un save() sobre un upgrade que YA estaba en terminada tampoco vuelve a escribir:
           el cliente pudo haber subido después por otro camino y no se lo pisa. */
        $upgrade->update(['status' => 'terminada']);
        $mas_nueva = $this->crear_version('8.8.8');
        $client->update(['current_version_id' => $mas_nueva->id]);

        $upgrade->update(['notes' => 'una nota cualquiera']);

        $this->assertSame(
            (int) $mas_nueva->id,
            (int) $client->fresh()->current_version_id,
            'guardar un upgrade ya terminado no reescribe la versión del cliente'
        );
    }

    /**
     * El barrido para atrás: repara a los clientes que quedaron desalineados antes de que
     * existiera el hook, y no escribe nada sin `--aplicar`.
     *
     * @return void
     */
    public function test_el_comando_realinea_a_los_clientes_que_quedaron_desalineados()
    {
        $upgrade = $this->crear_upgrade();
        $client  = $upgrade->client;

        /* Se reproduce el estado viejo: upgrade terminado, cliente sin mover. Sin disparar el
           hook, que es justo lo que no existía cuando esos datos se escribieron. */
        ClientVersionUpgrade::where('id', $upgrade->id)->update(['status' => 'terminada']);

        $this->assertSame((int) $upgrade->from_version_id, (int) $client->fresh()->current_version_id);

        /* Modo reporte: no escribe. */
        $this->artisan('realinear_version_de_clientes')->assertExitCode(0);

        $this->assertSame(
            (int) $upgrade->from_version_id,
            (int) $client->fresh()->current_version_id,
            'sin --aplicar el comando no toca la base'
        );

        $this->artisan('realinear_version_de_clientes', ['--aplicar' => true])->assertExitCode(0);

        $this->assertSame(
            (int) $upgrade->to_version_id,
            (int) $client->fresh()->current_version_id,
            'con --aplicar deja al cliente en la versión del upgrade terminado'
        );

        /* Idempotente: la segunda corrida no tiene nada que hacer. */
        $this->artisan('realinear_version_de_clientes', ['--aplicar' => true])->assertExitCode(0);

        $this->assertSame(
            (int) $upgrade->to_version_id,
            (int) $client->fresh()->current_version_id
        );
    }

    /**
     * Upgrade `pendiente` con su cliente en la versión de origen y una API destino propia.
     *
     * @param  array $atributos Sobrescriben los del upgrade.
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(array $atributos = []): ClientVersionUpgrade
    {
        $this->contador_de_versiones += 2;

        $from = $this->crear_version('7.' . $this->contador_de_versiones . '.0');
        $to   = $this->crear_version('7.' . $this->contador_de_versiones . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente de prueba';
        $client->company_name       = 'Empresa de prueba';
        $client->slug               = 'cliente-version-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-destino.ejemplo.test';
        $api->path         = 'ejemplo/' . Str::random(6);
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return ClientVersionUpgrade::create(array_merge([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $api->id,
        ], $atributos));
    }

    /**
     * Versión publicada del catálogo.
     *
     * @param  string $codigo Número de versión.
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = 'published';
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * El constructor de DeploymentService exige una credencial SSH del tipo de la API destino.
     * No se usa en `step_complete()`, que no toca la red.
     *
     * @param  string $type shared_hosting | vps
     * @return void
     */
    private function crear_credencial_ssh(string $type)
    {
        if (ClientSshCredential::where('type', $type)->exists()) {
            return;
        }

        $credential           = new ClientSshCredential();
        $credential->type     = $type;
        $credential->host     = 'ssh.ejemplo.test';
        $credential->port     = 22;
        $credential->username = 'usuario';
        $credential->password = 'clave';
        $credential->save();
    }
}
