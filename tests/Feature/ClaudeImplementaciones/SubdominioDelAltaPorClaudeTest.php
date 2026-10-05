<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Implementation;
use App\Models\Lead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El subdominio del alta (`POST claude/implementations`): qué nombres no puede tener un cliente y qué se vuelve
 * a mirar adentro del lock.
 *
 * El subdominio define las cuatro URLs del cliente (`<sub>`, `<sub>2`, `api-<sub>`, `api-<sub>2`) y no se
 * puede cambiar sin rehacer el hosting. Dos cosas que este test protege:
 *
 *  1. Los nombres RESERVADOS. Un cliente llamado `tienda`, `cpanel` o `autodiscover` no choca con ninguna
 *     ClientApi del admin —por eso no lo ve el chequeo de unicidad— y en cambio pisaría en la zona DNS un nombre
 *     que la plataforma o los clientes de correo ya usan. Además de la lista fija, son reservados `demo` o `ns`
 *     seguidos de dígitos (`demo2`, `ns3`: las demos y los servidores de nombres se numeran).
 *  2. 🔴 Que el chequeo del subdominio se REPITA adentro del lock global de altas. El chequeo de antes del
 *     lock mira un estado que otra promoción puede estar cambiando: dos altas con el mismo subdominio para dos
 *     leads distintos pasarían las dos el chequeo y la segunda crearía las ClientApi de un subdominio que la
 *     primera acaba de ocupar. Con el lock tomado nadie más promueve, así que lo que se ve ahí es lo que hay.
 */
class SubdominioDelAltaPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Sin clave de Anthropic para que la sugerencia use el fallback, y un admin que figure como creador de las
     * tareas de la promoción.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => '']);
        config(['services.claude_task_ingest.default_creator_admin_id' => null]);

        $this->crear_admin();
    }

    /**
     * El POST del alta.
     *
     * @param array<string, mixed> $cuerpo Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function alta(array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations', $cuerpo, $this->headers());
    }

    /**
     * Un lead listo para promover.
     *
     * @return Lead
     */
    private function lead(): Lead
    {
        return $this->crear_lead(['contact_name' => 'Rosa Fernández', 'company_name' => 'Almacén Rosa']);
    }

    /**
     * Lo que existe de lo que el alta escribe, para comparar antes y después.
     *
     * @return array<string, int>
     */
    private function foto(): array
    {
        return [
            'clientes'        => Client::count(),
            'client_apis'     => ClientApi::count(),
            'implementations' => Implementation::count(),
            'tareas'          => DB::table('admin_tasks')->count(),
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Los nombres reservados
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. Cada nombre reservado es inválido (con el motivo "reservado") en el dry-run y 422 en el real, sin
     * crear nada.
     *
     * @return void
     */
    public function test_los_nombres_reservados_no_sirven(): void
    {
        $reservados = [
            'admin', 'api', 'www', 'mail', 'smtp', 'ftp', 'webmail', 'demo', 'app', 'soporte',
            'tienda', 'imap', 'pop', 'pop3', 'cpanel', 'autodiscover', 'localhost',
            'demo1', 'demo2', 'demo15', 'ns1', 'ns2', 'ns3', 'ns10',
        ];

        foreach ($reservados as $nombre) {
            $lead  = $this->lead();
            $antes = $this->foto();

            $simulacion = $this->alta(['lead_id' => $lead->id, 'subdominio' => $nombre]);
            $simulacion->assertStatus(200);
            $simulacion->assertJsonPath('subdominio.valido', false);
            $simulacion->assertJsonPath('listo', false);
            $this->assertStringContainsString('reservado', (string) $simulacion->json('subdominio.motivo'), 'No marcó reservado a "' . $nombre . '".');

            $real = $this->alta(['lead_id' => $lead->id, 'subdominio' => $nombre, 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);
            $real->assertStatus(422);
            $this->assertSame($antes, $this->foto(), 'El alta con "' . $nombre . '" escribió algo.');
        }
    }

    /**
     * 1. Un nombre que solo se PARECE a uno reservado no lo es: `demos`, `tiendita`, `nsa`, `pops`, `demo-sur` y
     * `ns-norte` no son `demo`/`tienda`/`pop`/`ns` ni `demo`/`ns` seguidos de dígitos.
     *
     * @return void
     */
    public function test_un_nombre_parecido_a_uno_reservado_si_sirve(): void
    {
        foreach (['demos', 'tiendita', 'nsa', 'pops', 'demo-sur', 'ns-norte', 'mailing', 'apis', 'miapp', 'demo2b'] as $nombre) {
            $simulacion = $this->alta(['lead_id' => $this->lead()->id, 'subdominio' => $nombre]);

            $simulacion->assertStatus(200);
            $simulacion->assertJsonPath('subdominio.valido', true);
            $this->assertNull($simulacion->json('subdominio.motivo'), '"' . $nombre . '" no es un nombre reservado: ' . $simulacion->json('subdominio.motivo'));
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El chequeo se repite adentro del lock
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. 🔴 Si entre el chequeo de antes del lock y el lock el subdominio pasa a estar ocupado (otra promoción
     * lo tomó), el alta real es 422 con el motivo y NO crea nada. Se simula con un controlador cuyo chequeo
     * dice "libre" la primera vez y "ocupado" la segunda, que es lo que ve el que llega segundo.
     *
     * @return void
     */
    public function test_si_el_subdominio_se_ocupa_antes_del_lock_el_alta_es_422_y_no_crea_nada(): void
    {
        $this->app->bind(ClaudeImplementationOpsController::class, function () {
            return new class extends ClaudeImplementationOpsController {
                /** @var int */
                public $consultas = 0;

                protected function motivo_por_el_que_no_sirve_el_subdominio($subdominio)
                {
                    $this->consultas++;

                    return $this->consultas === 1 ? null : 'ya lo usa el cliente 9999 (en una de sus ClientApi: <sub> o <sub>2).';
                }
            };
        });

        $lead  = $this->lead();
        $antes = $this->foto();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya lo usa el cliente 9999', $respuesta->json('error'));
        $this->assertStringContainsString('No se creó nada', $respuesta->json('error'));
        $respuesta->assertJsonPath('subdominio.valido', false);
        $respuesta->assertJsonPath('subdominio.pedido', 'rosa');
        $this->assertSame($antes, $this->foto(), 'El alta escribió algo aunque el subdominio estaba ocupado.');
        $this->assertNull($lead->refresh()->promoted_client_id);
    }

    /**
     * 2. El rechazo de adentro del lock SUELTA el lock: la siguiente alta no se queda esperando diez segundos
     * por un lock que nadie va a liberar.
     *
     * @return void
     */
    public function test_el_rechazo_de_adentro_del_lock_lo_suelta(): void
    {
        $this->app->bind(ClaudeImplementationOpsController::class, function () {
            return new class extends ClaudeImplementationOpsController {
                /** @var int */
                public $consultas = 0;

                protected function motivo_por_el_que_no_sirve_el_subdominio($subdominio)
                {
                    $this->consultas++;

                    return $this->consultas === 1 ? null : 'ya lo usa el cliente 9999 (en una de sus ClientApi: <sub> o <sub>2).';
                }
            };
        });

        $this->alta(['lead_id' => $this->lead()->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa'])->assertStatus(422);

        $lock = Cache::lock('claude_implementations_alta', 5);
        $this->assertTrue($lock->get(), 'El rechazo no soltó el lock global de altas.');
        $lock->release();
    }

    /**
     * 2. El camino normal no cambia: con el subdominio libre las dos consultas dan lo mismo y el alta sale
     * (201). Y un cliente que YA existe (sin promoción, sin lock) no repite un chequeo que no le corresponde.
     *
     * @return void
     */
    public function test_con_el_subdominio_libre_el_alta_sale(): void
    {
        $respuesta = $this->alta(['lead_id' => $this->lead()->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('promovido', true);
        $this->assertSame(2, ClientApi::where('client_id', $respuesta->json('cliente.id'))->count());
    }
}
