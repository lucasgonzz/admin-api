<?php

namespace Tests\Feature\PanelAgent;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión admin-control-total — el comando `agente:token`: alta, rotación, revocación, salida mínima para
 * canalizar a un almacén de secretos, rechazo de emails ajenos y listado sin valores.
 *
 * Cubre CA12 del PLAN.
 */
class ComandoTokenDeAgenteTest extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /** Email que el comando le asigna al agente «Claude Code». */
    private const EMAIL_CLAUDE_CODE = 'agente.claude-code@agentes.comerciocity.invalid';

    /** Forma de un token de Sanctum 2: `id|` + 40 caracteres. */
    private const PATRON_TOKEN = '/\d+\|[A-Za-z0-9]{40}/';

    /**
     * Corre el comando y devuelve [código de salida, salida].
     *
     * @param  array<string, mixed> $parametros Argumentos y opciones de artisan.
     * @return array{0: int, 1: string}
     */
    private function correr_comando(array $parametros): array
    {
        $codigo = Artisan::call('agente:token', $parametros);

        return [$codigo, Artisan::output()];
    }

    /**
     * Extrae el token de la salida del comando (null si no hay ninguno).
     *
     * @param  string $salida Salida del comando.
     * @return string|null
     */
    private function token_en(string $salida)
    {
        return preg_match(self::PATRON_TOKEN, $salida, $coincidencia) === 1 ? $coincidencia[0] : null;
    }

    /**
     * Alta: crea el admin agente, imprime un token que sirve para el panel, y no deja el token en los logs.
     *
     * @return void
     */
    public function test_el_comando_da_de_alta_al_agente_y_emite_un_token_que_sirve(): void
    {
        list($codigo, $salida) = $this->correr_comando(['nombre' => 'Claude Code']);

        $this->assertSame(0, $codigo, $salida);

        $agente = Admin::where('email', self::EMAIL_CLAUDE_CODE)->first();
        $this->assertNotNull($agente, 'Se creó el admin agente con el email del slug.');
        $this->assertSame(1, (int) $agente->es_agente);
        $this->assertSame('Agente Claude Code', $agente->name);

        $token = $this->token_en($salida);
        $this->assertNotNull($token, 'La salida imprime el token una vez.');

        $this->pedir_como($token, 'GET', '/api/admin/me')->assertOk()->assertJsonPath('admin.email', self::EMAIL_CLAUDE_CODE);

        // El token no queda en ningún log de la aplicación.
        foreach (glob(storage_path('logs/*')) ?: [] as $archivo) {
            if (is_file($archivo) && filesize($archivo) < 60 * 1024 * 1024) {
                $this->assertFalse($this->contiene(file_get_contents($archivo), $token), 'El token no puede estar en ' . basename($archivo));
            }
        }
    }

    /**
     * Rotación: correrlo de nuevo invalida el token anterior, emite uno nuevo y no duplica al admin.
     *
     * @return void
     */
    public function test_correrlo_de_nuevo_rota_el_token_sin_duplicar_al_agente(): void
    {
        list(, $salida_1) = $this->correr_comando(['nombre' => 'Claude Code']);
        $token_viejo = $this->token_en($salida_1);

        list($codigo, $salida_2) = $this->correr_comando(['nombre' => 'Claude Code']);
        $token_nuevo = $this->token_en($salida_2);

        $this->assertSame(0, $codigo);
        $this->assertNotNull($token_viejo);
        $this->assertNotNull($token_nuevo);
        $this->assertNotSame($token_viejo, $token_nuevo);

        $this->pedir_como($token_viejo, 'GET', '/api/admin/me')->assertStatus(401);
        $this->pedir_como($token_nuevo, 'GET', '/api/admin/me')->assertOk();

        $this->assertSame(1, Admin::where('email', self::EMAIL_CLAUDE_CODE)->count(), 'Sigue habiendo un solo admin con ese email.');
        $agente = Admin::where('email', self::EMAIL_CLAUDE_CODE)->first();
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $agente->id)->count(), 'Un token vivo por agente.');
    }

    /**
     * `--revocar`: borra los tokens y no emite ninguno.
     *
     * @return void
     */
    public function test_revocar_deja_al_agente_sin_tokens(): void
    {
        list(, $salida) = $this->correr_comando(['nombre' => 'Claude Code']);
        $token = $this->token_en($salida);
        $this->pedir_como($token, 'GET', '/api/admin/me')->assertOk();

        list($codigo, $salida_revocar) = $this->correr_comando(['nombre' => 'Claude Code', '--revocar' => true]);

        $this->assertSame(0, $codigo, $salida_revocar);
        $this->assertNull($this->token_en($salida_revocar), 'Revocar no imprime ningún token.');
        $this->pedir_como($token, 'GET', '/api/admin/me')->assertStatus(401);

        $agente = Admin::where('email', self::EMAIL_CLAUDE_CODE)->first();
        $this->assertNotNull($agente, 'Revocar no borra al admin agente.');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $agente->id)->count());
    }

    /**
     * `--solo-token`: la salida es exactamente una línea con el token (para canalizar a un almacén de secretos).
     *
     * @return void
     */
    public function test_solo_token_imprime_una_unica_linea_con_el_token(): void
    {
        list($codigo, $salida) = $this->correr_comando(['nombre' => 'OpenClaw', '--solo-token' => true]);

        $this->assertSame(0, $codigo, $salida);

        $lineas = array_values(array_filter(explode("\n", str_replace("\r", '', $salida)), function ($linea) {
            return trim($linea) !== '';
        }));
        $this->assertCount(1, $lineas, 'Una sola línea de salida.');
        $this->assertSame(1, preg_match('/^\d+\|[A-Za-z0-9]{40}$/', trim($lineas[0])), 'Esa línea es sólo el token.');

        $this->pedir_como(trim($lineas[0]), 'GET', '/api/admin/me')
            ->assertOk()
            ->assertJsonPath('admin.email', 'agente.openclaw@agentes.comerciocity.invalid');
    }

    /**
     * Si el email ya pertenece a un admin que NO es agente, el comando falla y no toca nada.
     *
     * @return void
     */
    public function test_con_el_email_ocupado_por_un_humano_falla_y_no_emite_tokens(): void
    {
        $humano = Admin::create([
            'name'     => 'Humano con el email del agente',
            'email'    => self::EMAIL_CLAUDE_CODE,
            'password' => bcrypt('no-importa-en-este-test'),
        ]);

        list($codigo, $salida) = $this->correr_comando(['nombre' => 'Claude Code']);

        $this->assertNotSame(0, $codigo, 'Tiene que terminar con error.');
        $this->assertNull($this->token_en($salida), 'No imprime ningún token.');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $humano->id)->count());
        $this->assertSame(0, (int) $humano->fresh()->es_agente, 'No lo convierte en agente.');
        $this->assertSame('Humano con el email del agente', $humano->fresh()->name, 'No lo modifica.');
    }

    /**
     * `--listar`: muestra agentes y tokens vivos, pero nunca un valor de token.
     *
     * @return void
     */
    public function test_listar_muestra_agentes_sin_imprimir_tokens(): void
    {
        list(, $salida) = $this->correr_comando(['nombre' => 'Claude Code']);
        $token = $this->token_en($salida);
        $this->assertNotNull($token);
        $secreto = substr($token, strpos($token, '|') + 1);
        $hash    = hash('sha256', $secreto);

        list($codigo, $listado) = $this->correr_comando(['nombre' => 'Claude Code', '--listar' => true]);

        $this->assertSame(0, $codigo, $listado);
        $this->assertTrue($this->contiene($listado, 'claude-code'), 'El listado nombra al agente.');
        $this->assertNull($this->token_en($listado), 'El listado no imprime ningún token.');
        $this->assertFalse($this->contiene($listado, $secreto), 'Ni el secreto del token.');
        $this->assertFalse($this->contiene($listado, $hash), 'Ni su hash.');

        // Listar no rota ni revoca: el token sigue sirviendo.
        $this->pedir_como($token, 'GET', '/api/admin/me')->assertOk();
    }
}
