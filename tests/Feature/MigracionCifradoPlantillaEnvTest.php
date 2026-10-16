<?php

namespace Tests\Feature;

use App\Models\EnvTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La migración que cifra los valores sensibles que la plantilla .env guardaba en claro.
 *
 * Se inserta con DB::table (como estaban las filas antes) y se corre up()/down() de la migración real.
 * Las filas de cada prueba se buscan por id: la tabla puede tener otras filas que no son de la prueba.
 */
class MigracionCifradoPlantillaEnvTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /* Hermético: sin depender de filas ajenas de env_templates (se revierte con la transacción). */
        EnvTemplate::query()->delete();
    }

    public function test_up_cifra_la_sensible_en_claro_y_no_toca_la_no_sensible(): void
    {
        $sensible    = $this->insertar_en_claro('ANTHROPIC_API_KEY', 'sk-ant-test-migracion-0001');
        $no_sensible = $this->insertar_en_claro('MAIL_HOST', 'smtp.ejemplo.test');
        $vacia       = $this->insertar_en_claro('OPENAI_API_KEY', null);

        $this->migracion()->up();

        $crudo = $this->crudo($sensible);
        $this->assertStringStartsWith('enc:v1:', $crudo);
        $this->assertStringNotContainsString('sk-ant-test-migracion-0001', $crudo);
        $this->assertSame('sk-ant-test-migracion-0001', EnvTemplate::find($sensible)->value);

        $this->assertSame('smtp.ejemplo.test', $this->crudo($no_sensible));
        $this->assertNull($this->crudo($vacia));
    }

    public function test_una_segunda_corrida_no_vuelve_a_cifrar(): void
    {
        $id = $this->insertar_en_claro('DEEPSEEK_API_KEY', 'sk-ds-test-migracion-0002');

        $this->migracion()->up();
        $primera = $this->crudo($id);

        $this->migracion()->up();

        /* Idéntico byte a byte: si se hubiera re-cifrado, el IV nuevo lo cambiaría. */
        $this->assertSame($primera, $this->crudo($id));
        $this->assertSame(1, substr_count($this->crudo($id), 'enc:v1:'));
        $this->assertSame('sk-ds-test-migracion-0002', EnvTemplate::find($id)->value);
    }

    public function test_down_deja_en_claro_lo_cifrado_y_no_toca_el_resto(): void
    {
        $sensible    = $this->insertar_en_claro('PUSHER_APP_SECRET', 'secreto-de-prueba-migracion');
        $no_sensible = $this->insertar_en_claro('MAIL_HOST', 'smtp.ejemplo.test');

        $this->migracion()->up();
        $this->assertStringStartsWith('enc:v1:', $this->crudo($sensible));

        $this->migracion()->down();

        $this->assertSame('secreto-de-prueba-migracion', $this->crudo($sensible));
        $this->assertSame('smtp.ejemplo.test', $this->crudo($no_sensible));
    }

    public function test_down_deja_como_esta_un_valor_que_no_se_puede_descifrar(): void
    {
        $id = $this->insertar_en_claro('ANTHROPIC_API_KEY', 'enc:v1:payload-roto');

        $this->migracion()->down();

        $this->assertSame('enc:v1:payload-roto', $this->crudo($id));
    }

    public function test_up_respeta_una_fila_que_el_modelo_ya_cifro(): void
    {
        /* Fila creada por el modelo después del deploy y antes de correr la migración. */
        $plantilla = EnvTemplate::create([
            'key' => 'ANTHROPIC_API_KEY', 'value' => 'sk-ant-test-migracion-0003', 'group' => 'ia',
            'scope' => 'empresa', 'is_common' => false, 'is_manual_on_create' => false, 'sort_order' => 1,
        ]);
        $antes = $this->crudo($plantilla->id);

        $this->migracion()->up();

        $this->assertSame($antes, $this->crudo($plantilla->id));
        $this->assertSame('sk-ant-test-migracion-0003', Crypt::decryptString(substr($this->crudo($plantilla->id), 7)));
    }

    /**
     * Carga la migración real (clase anónima) para correr up()/down().
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    private function migracion()
    {
        return require base_path('database/migrations/2026_10_16_100000_cifrar_valores_sensibles_de_env_templates.php');
    }

    /**
     * Inserta una fila con el valor en claro, salteando el modelo (que lo cifraría).
     *
     * @param  string       $key
     * @param  string|null  $value
     * @return int
     */
    private function insertar_en_claro(string $key, $value): int
    {
        return DB::table('env_templates')->insertGetId([
            'key'                 => $key,
            'value'               => $value,
            'group'               => 'ia',
            'scope'               => 'empresa',
            'is_common'           => false,
            'is_manual_on_create' => false,
            'sort_order'          => 1,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /**
     * Valor crudo de la columna, sin pasar por el accessor del modelo.
     *
     * @param  int  $id
     * @return string|null
     */
    private function crudo(int $id)
    {
        return DB::table('env_templates')->where('id', $id)->value('value');
    }
}
