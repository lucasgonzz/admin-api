<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use App\Services\VersionItemSanitizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La prevención en origen de los dos defectos que voltearon la actualización de masquito el
 * 3/9/2026: un `seeder_class` con namespace y un `php artisan migrate` sin `--force`.
 *
 * 🔴 Lo que estos tests fijan de verdad es que la regla vale en las TRES puertas que escriben
 * `version_seeders` y `version_commands` — la ingesta de Claude y los dos controladores del panel —
 * y no en una sola. Tres copias de la misma validación divergen, y revisar dos y no la tercera no
 * produce ninguna señal: es la lección de los tres closures que dejó escrita APRENDER_NO_PARCHEAR.
 */
class SaneamientoDeItemsDeVersionTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave del endpoint de ingesta durante el test. */
    const CLAVE = 'clave-de-prueba-saneamiento';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /* ------------------------------------------------------- la regla, en un solo lugar */

    /**
     * El prefijo por defecto se saca: es el que `db:seed --class=` resuelve solo.
     *
     * @return void
     */
    public function test_saca_el_namespace_por_defecto_del_seeder()
    {
        $this->assertSame(
            'ExtencionTrackingBuyersSeeder',
            VersionItemSanitizer::sanear_seeder_class('Database\\Seeders\\ExtencionTrackingBuyersSeeder')
        );

        // Y el que ya viene limpio no se toca.
        $this->assertSame(
            'ExtencionAsistenteIaSeeder',
            VersionItemSanitizer::sanear_seeder_class('ExtencionAsistenteIaSeeder')
        );
    }

    /**
     * 🔴 Un sub-namespace REAL no se recorta: se rechaza.
     *
     * Recortar hasta la última barra dejaría `FooSeeder` para
     * `Database\Seeders\Demo\FooSeeder`, que artisan no resuelve. Sería el defecto opuesto al que
     * se está arreglando, y silencioso igual.
     *
     * @return void
     */
    public function test_un_sub_namespace_real_se_rechaza_en_vez_de_recortarse()
    {
        $motivo = VersionItemSanitizer::motivo_de_rechazo_de_seeder('Database\\Seeders\\Demo\\FooSeeder');

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('sub-namespace', $motivo);

        // Y el caso normal no se rechaza.
        $this->assertNull(
            VersionItemSanitizer::motivo_de_rechazo_de_seeder('Database\\Seeders\\ExtencionPuntosClientesSeeder')
        );
    }

    /**
     * A los artisan confirmables les falta `--force` y se les completa.
     *
     * @return void
     */
    public function test_completa_el_force_de_los_comandos_confirmables()
    {
        $this->assertSame(
            'php artisan migrate --force',
            VersionItemSanitizer::sanear_comando('php artisan migrate')
        );
        $this->assertSame(
            'php artisan db:seed --class=Xxx --force',
            VersionItemSanitizer::sanear_comando('php artisan db:seed --class=Xxx')
        );

        // El que ya lo trae no se duplica.
        $this->assertSame(
            'php artisan migrate --force',
            VersionItemSanitizer::sanear_comando('php artisan migrate --force')
        );

        // Y lo que no es artisan no se toca.
        $this->assertSame(
            'composer install --no-dev',
            VersionItemSanitizer::sanear_comando('composer install --no-dev')
        );
    }

    /**
     * 🔴 Un comando destructivo NO se completa con `--force`: se rechaza.
     *
     * Hoy un `migrate:fresh` sin `--force` se cancela solo por falta de confirmación. Completarlo
     * lo volvería EJECUTABLE contra la base de un negocio, o sea que el "arreglo" sería mucho peor
     * que el problema.
     *
     * @return void
     */
    public function test_los_destructivos_se_rechazan_y_nunca_se_completan()
    {
        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'] as $sub) {
            $comando = 'php artisan ' . $sub;

            $motivo = VersionItemSanitizer::motivo_de_rechazo_de_comando($comando);
            $this->assertNotNull($motivo, "El destructivo {$sub} no se rechazó.");

            $this->assertFalse(
                VersionItemSanitizer::necesita_force($comando),
                "Al destructivo {$sub} se le iba a agregar --force."
            );
            $this->assertSame(
                $comando,
                VersionItemSanitizer::sanear_comando($comando),
                "El destructivo {$sub} fue modificado."
            );
        }
    }

    /**
     * 🔴 El borde de palabra importa: sin él, `migrate` matchea dentro de `migrate:fresh`.
     *
     * @return void
     */
    public function test_migrate_no_matchea_dentro_de_migrate_fresh()
    {
        $this->assertTrue(VersionItemSanitizer::necesita_force('php artisan migrate'));
        $this->assertFalse(VersionItemSanitizer::necesita_force('php artisan migrate:fresh'));
        $this->assertSame('migrate:fresh', VersionItemSanitizer::subcomando_destructivo('php artisan migrate:fresh'));
        $this->assertNull(VersionItemSanitizer::subcomando_destructivo('php artisan migrate'));
    }

    /* ------------------------------------------------------------ puerta 1: la ingesta */

    /**
     * La ingesta rechaza un `seeder_class` que no se puede ejecutar, sin escribir nada.
     *
     * @return void
     */
    public function test_la_ingesta_rechaza_un_seeder_irresoluble()
    {
        $version = $this->crear_version();

        $antes = VersionSeeder::where('version_id', $version->id)->count();

        $this->postJson('/api/claude/version-items', [
            'source_group_id' => 'test-saneamiento',
            'version_id'      => $version->id,
            'seeders'         => [
                ['seeder_class' => 'Database\\Seeders\\Demo\\FooSeeder'],
            ],
        ], $this->headers())->assertStatus(422);

        $this->assertSame(
            $antes,
            VersionSeeder::where('version_id', $version->id)->count(),
            'La ingesta escribió el seeder pese a rechazarlo.'
        );
    }

    /**
     * La ingesta rechaza un comando destructivo.
     *
     * @return void
     */
    public function test_la_ingesta_rechaza_un_comando_destructivo()
    {
        $version = $this->crear_version();

        $this->postJson('/api/claude/version-items', [
            'source_group_id' => 'test-saneamiento',
            'version_id'      => $version->id,
            'commands'        => [
                ['command' => 'php artisan migrate:fresh'],
            ],
        ], $this->headers())->assertStatus(422);
    }

    /**
     * 🔴 EL TEST QUE MÁS IMPORTA DE LOS DE LA INGESTA — el saneamiento no rompe la idempotencia.
     *
     * `upsert_items()` calcula la clave natural del payload CRUDO, antes de armar la fila. Si el
     * saneamiento se hiciera adentro del `data_row_builder`, el upsert buscaría por el valor sucio
     * —que nunca existe, porque se guarda el limpio— y crearía una fila nueva en CADA publicación.
     * Este test publica dos veces lo mismo y exige que siga habiendo una sola fila.
     *
     * @return void
     */
    public function test_publicar_dos_veces_el_mismo_seeder_con_namespace_no_lo_duplica()
    {
        $version = $this->crear_version();

        $payload = [
            'source_group_id' => 'test-saneamiento',
            'version_id'      => $version->id,
            'seeders'         => [
                ['seeder_class' => 'Database\\Seeders\\ExtencionMotorDeOfertasSeeder'],
            ],
        ];

        $this->postJson('/api/claude/version-items', $payload, $this->headers())->assertOk();
        $this->postJson('/api/claude/version-items', $payload, $this->headers())->assertOk();

        $filas = VersionSeeder::where('version_id', $version->id)
            ->where('seeder_class', 'ExtencionMotorDeOfertasSeeder')
            ->get();

        $this->assertCount(1, $filas, 'La segunda publicación duplicó el seeder.');
        $this->assertSame('ExtencionMotorDeOfertasSeeder', $filas->first()->seeder_class);
    }

    /**
     * Y lo mismo del lado de los comandos: se guarda con `--force` y no se duplica.
     *
     * @return void
     */
    public function test_la_ingesta_guarda_el_comando_con_force_y_no_lo_duplica()
    {
        $version = $this->crear_version();

        $payload = [
            'source_group_id' => 'test-saneamiento',
            'version_id'      => $version->id,
            'commands'        => [
                ['command' => 'php artisan migrate'],
            ],
        ];

        $this->postJson('/api/claude/version-items', $payload, $this->headers())->assertOk();
        $this->postJson('/api/claude/version-items', $payload, $this->headers())->assertOk();

        $filas = VersionCommand::where('version_id', $version->id)
            ->where('command', 'php artisan migrate --force')
            ->get();

        $this->assertCount(1, $filas, 'La segunda publicación duplicó el comando.');
        $this->assertSame(
            0,
            VersionCommand::where('version_id', $version->id)->where('command', 'php artisan migrate')->count(),
            'Quedó guardado el comando sin --force.'
        );
    }

    /* -------------------------------------------------- puertas 2 y 3: los del panel */

    /**
     * El panel guarda el seeder saneado.
     *
     * @return void
     */
    public function test_el_panel_guarda_el_seeder_sin_el_namespace()
    {
        $version = $this->crear_version();
        $this->actuando_como_admin();

        $this->post(route('versions.seeders.store', $version->id), [
            'seeder_class' => 'Database\\Seeders\\ExtencionPuntosClientesSeeder',
            'description'  => 'de prueba',
        ]);

        $this->assertSame(
            1,
            VersionSeeder::where('version_id', $version->id)
                ->where('seeder_class', 'ExtencionPuntosClientesSeeder')
                ->count(),
            'El panel no saneó el seeder_class.'
        );
    }

    /**
     * El panel guarda el comando con `--force`.
     *
     * @return void
     */
    public function test_el_panel_guarda_el_comando_con_force()
    {
        $version = $this->crear_version();
        $this->actuando_como_admin();

        $this->post(route('versions.commands.store', $version->id), [
            'command'     => 'php artisan migrate',
            'description' => 'de prueba',
        ]);

        $this->assertSame(
            1,
            VersionCommand::where('version_id', $version->id)
                ->where('command', 'php artisan migrate --force')
                ->count(),
            'El panel no completó el --force.'
        );
    }

    /* ------------------------------------------------------------------------ helpers */

    /**
     * Las rutas del panel viven bajo middleware('auth'), o sea sesion web: sin esto el POST
     * termina en un redirect al login y el test pasaria por no escribir nada, que es justo lo
     * contrario de lo que quiere probar.
     *
     * @return Admin
     */
    private function actuando_como_admin()
    {
        $admin           = new Admin();
        $admin->name     = 'Admin del saneamiento';
        $admin->email    = 'saneamiento-' . random_int(1000, 999999) . '@ejemplo.test';
        $admin->password = bcrypt('secreto');
        $admin->save();

        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @return array
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * @return Version
     */
    private function crear_version()
    {
        $version               = new Version();
        $version->version      = '7.' . random_int(100, 999) . '.0';
        $version->title        = 'Version de prueba del saneamiento';
        $version->status       = 'draft';
        $version->save();

        return $version;
    }
}
