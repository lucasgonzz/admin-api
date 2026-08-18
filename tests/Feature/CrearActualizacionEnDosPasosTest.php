<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El flujo web en dos pasos de `updates/preview` → `updates`: el paso de confirmación
 * que esta misión agregó para que el admin pueda destildar hotfixes antes de crear la
 * actualización.
 *
 * Antes, `UpdateController::store` calculaba el rango UNA sola vez y persistía todo lo
 * que encontraba entre `from` y `to` (por `id`, sin filtrar `status`). Ahora el admin
 * confirma un subconjunto en `updates/preview`, y `store()` sólo persiste EXACTAMENTE
 * ese subconjunto — nunca vuelve a calcular el rango para decidir qué guardar.
 */
class CrearActualizacionEnDosPasosTest extends TestCase
{
    use DatabaseTransactions;

    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'dos-pasos-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    private function crear_cliente(?int $current_version_id): Client
    {
        $client                     = new Client();
        $client->name               = 'Cliente de prueba';
        $client->slug                = 'cliente-dos-pasos-' . Str::random(8);
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->current_version_id = $current_version_id;
        $client->is_active          = true;
        $client->save();

        return $client;
    }

    private function crear_version(string $codigo, bool $is_hotfix = false): Version
    {
        $version            = new Version();
        $version->uuid      = (string) Str::uuid();
        $version->version   = $codigo;
        $version->title     = 'Versión ' . $codigo;
        $version->status    = 'published';
        $version->published_at = now();
        $version->is_hotfix = $is_hotfix;
        $version->save();

        return $version;
    }

    private function agregar_seeder(Version $version): VersionSeeder
    {
        $seeder                  = new VersionSeeder();
        $seeder->uuid            = (string) Str::uuid();
        $seeder->version_id      = $version->id;
        $seeder->seeder_class    = 'Database\\Seeders\\Fake' . Str::random(6) . 'Seeder';
        $seeder->execution_order = 1;
        $seeder->save();

        return $seeder;
    }

    private function agregar_command(Version $version): VersionCommand
    {
        $command                  = new VersionCommand();
        $command->uuid            = (string) Str::uuid();
        $command->version_id      = $version->id;
        $command->command         = 'comando:falso-' . Str::random(6);
        $command->execution_order = 1;
        $command->save();

        return $command;
    }

    /**
     * Arma el escenario base: cliente en 3.3.0, rango publicado hasta 3.3.3 con un
     * hotfix intermedio (3.3.1.1), cada versión troncal con su propio seeder y comando.
     *
     * @return array{admin: Admin, client: Client, v_3_3_1: Version, v_3_3_1_1: Version, v_3_3_2: Version, v_3_3_3: Version, seeder_3_3_1: VersionSeeder, seeder_hotfix: VersionSeeder, seeder_3_3_2: VersionSeeder, command_3_3_1: VersionCommand}
     */
    private function armar_escenario(): array
    {
        $from = $this->crear_version('3.3.0');

        $v_3_3_1   = $this->crear_version('3.3.1');
        $v_3_3_1_1 = $this->crear_version('3.3.1.1', true);
        $v_3_3_2   = $this->crear_version('3.3.2');
        $v_3_3_3   = $this->crear_version('3.3.3');

        $seeder_3_3_1  = $this->agregar_seeder($v_3_3_1);
        $seeder_hotfix = $this->agregar_seeder($v_3_3_1_1);
        $seeder_3_3_2  = $this->agregar_seeder($v_3_3_2);

        $command_3_3_1 = $this->agregar_command($v_3_3_1);

        $admin  = $this->crear_admin();
        $client = $this->crear_cliente($from->id);

        return compact(
            'admin', 'client', 'v_3_3_1', 'v_3_3_1_1', 'v_3_3_2', 'v_3_3_3',
            'seeder_3_3_1', 'seeder_hotfix', 'seeder_3_3_2', 'command_3_3_1'
        );
    }

    /**
     * `POST updates/preview` responde 200 y la vista trae exactamente las candidatas del
     * rango (troncales + hotfix intermedio), en orden semántico.
     *
     * @return void
     */
    public function test_preview_responde_200_con_las_candidatas_esperadas(): void
    {
        $e = $this->armar_escenario();

        $response = $this->actingAs($e['admin'])->post('updates/preview', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['v_3_3_3']->id,
        ]);

        $response->assertStatus(200);
        $response->assertViewIs('updates.preview');
        $response->assertViewHas('candidates', function ($candidates) {
            return $candidates->pluck('version')->all() === ['3.3.1', '3.3.1.1', '3.3.2', '3.3.3'];
        });
        $response->assertSee('3.3.1.1');
        $response->assertSee('3.3.3');
    }

    /**
     * `POST updates` con un subconjunto tildado (sin el hotfix 3.3.1.1) persiste
     * EXACTAMENTE ese subconjunto en la pivot `client_version_upgrade_versions`, y crea
     * `UpdateSeeder`/`UpdateCommand` SOLO de esas versiones — el seeder del hotfix NO
     * tildado no puede existir en el upgrade resultante.
     *
     * @return void
     */
    public function test_store_persiste_exactamente_el_subconjunto_confirmado(): void
    {
        $e = $this->armar_escenario();

        $response = $this->actingAs($e['admin'])->post('updates', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['v_3_3_3']->id,
            // El admin destildó el hotfix: sólo van las 3 troncales.
            'version_ids'   => [$e['v_3_3_1']->id, $e['v_3_3_2']->id, $e['v_3_3_3']->id],
            'notes'         => 'Actualización de prueba',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect();

        $upgrade_id = (int) DB::table('client_version_upgrades')->latest('id')->value('id');
        $this->assertNotSame(0, $upgrade_id);

        $ids_confirmados = DB::table('client_version_upgrade_versions')
            ->where('client_version_upgrade_id', $upgrade_id)
            ->pluck('version_id')
            ->map('intval')
            ->sort()
            ->values()
            ->all();

        $esperados = collect([$e['v_3_3_1']->id, $e['v_3_3_2']->id, $e['v_3_3_3']->id])->sort()->values()->all();
        $this->assertSame($esperados, $ids_confirmados, 'La pivot no quedó con exactamente el subconjunto confirmado.');
        $this->assertNotContains((int) $e['v_3_3_1_1']->id, $ids_confirmados, 'El hotfix no tildado quedó en la pivot.');

        // UpdateSeeder: sólo los de las versiones confirmadas.
        $this->assertSame(
            1,
            UpdateSeeder::where('client_version_upgrade_id', $upgrade_id)
                ->where('version_seeder_id', $e['seeder_3_3_1']->id)->count()
        );
        $this->assertSame(
            1,
            UpdateSeeder::where('client_version_upgrade_id', $upgrade_id)
                ->where('version_seeder_id', $e['seeder_3_3_2']->id)->count()
        );
        $this->assertSame(
            0,
            UpdateSeeder::where('client_version_upgrade_id', $upgrade_id)
                ->where('version_seeder_id', $e['seeder_hotfix']->id)->count(),
            'Se creó un UpdateSeeder de una versión (hotfix) que NO estaba en el subconjunto confirmado.'
        );

        // UpdateCommand: sólo el de la versión confirmada que tiene comando.
        $this->assertSame(
            1,
            UpdateCommand::where('client_version_upgrade_id', $upgrade_id)
                ->where('version_command_id', $e['command_3_3_1']->id)->count()
        );
    }

    /**
     * `POST updates` con un `version_id` que no pertenece al rango calculado (una versión
     * publicada muy por fuera del rango entre `from` y `to`) devuelve 422: `store()`
     * valida pertenencia contra `candidatesBetween`, nunca agrega lo que no calculó.
     *
     * @return void
     */
    public function test_store_con_version_fuera_del_rango_devuelve_422(): void
    {
        $e = $this->armar_escenario();

        $fuera_de_rango = $this->crear_version('9.9.9');

        $response = $this->actingAs($e['admin'])->post('updates', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['v_3_3_3']->id,
            'version_ids'   => [$e['v_3_3_1']->id, $e['v_3_3_3']->id, $fuera_de_rango->id],
        ]);

        $response->assertStatus(422);
    }
}
