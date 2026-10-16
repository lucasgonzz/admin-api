<?php

namespace Tests\Feature;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\EnvTemplate;
use App\Models\Version;
use App\Services\DemoInstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El modelo EnvTemplate guarda CIFRADAS las variables sensibles y las lee descifradas.
 *
 * Lo que se prueba es la promesa de la misión: en la base nunca queda el secreto en claro, el resto
 * del backend (que lee `$template->value`) sigue viendo el valor real, y el valor no sale en ninguna
 * serialización. Los valores son falsos y obviamente de prueba.
 */
class PlantillaEnvCifradaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /* Hermético: sin depender de filas ajenas de env_templates (se revierte con la transacción). */
        EnvTemplate::query()->delete();
    }

    /**
     * Valor falso de 28 caracteres (supera el mínimo para mostrar la cola).
     *
     * @var string
     */
    private const CLAVE_DE_PRUEBA = 'sk-ant-test-0123456789ABCDEF';

    public function test_una_variable_sensible_se_guarda_cifrada_y_se_lee_descifrada(): void
    {
        $plantilla = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);

        /* En la base: con prefijo, distinta del original y sin contenerlo. */
        $crudo = DB::table('env_templates')->where('id', $plantilla->id)->value('value');
        $this->assertStringStartsWith('enc:v1:', $crudo);
        $this->assertNotSame(self::CLAVE_DE_PRUEBA, $crudo);
        $this->assertStringNotContainsString(self::CLAVE_DE_PRUEBA, $crudo);

        /* Leída por el modelo, sale el original. */
        $this->assertSame(self::CLAVE_DE_PRUEBA, EnvTemplate::find($plantilla->id)->value);
    }

    public function test_una_variable_no_sensible_queda_en_claro(): void
    {
        $plantilla = $this->crear('MAIL_HOST', 'smtp.ejemplo.test');

        $this->assertSame(
            'smtp.ejemplo.test',
            DB::table('env_templates')->where('id', $plantilla->id)->value('value')
        );
        $this->assertSame('smtp.ejemplo.test', EnvTemplate::find($plantilla->id)->value);
    }

    public function test_un_valor_en_claro_de_antes_de_la_migracion_se_lee_bien(): void
    {
        /* Fila de una sensible escrita en claro por fuera del modelo (como estaba antes de cifrar). */
        $id = DB::table('env_templates')->insertGetId([
            'key'                 => 'DEEPSEEK_API_KEY',
            'value'               => 'sk-legado-en-claro-123456',
            'group'               => 'ia',
            'scope'               => 'empresa',
            'is_common'           => false,
            'is_manual_on_create' => false,
            'sort_order'          => 1,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->assertSame('sk-legado-en-claro-123456', EnvTemplate::find($id)->value);
    }

    public function test_un_payload_cifrado_roto_devuelve_null_sin_tirar_excepcion_ni_loguear_el_valor(): void
    {
        $plantilla = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);

        DB::table('env_templates')->where('id', $plantilla->id)->update(['value' => 'enc:v1:esto-no-es-un-payload-valido']);

        /* Se espía el log: tiene que avisar, y el aviso no puede traer el valor. */
        $mensajes = [];
        Log::shouldReceive('error')->andReturnUsing(function ($mensaje, $contexto = []) use (&$mensajes) {
            $mensajes[] = $mensaje . ' ' . json_encode($contexto);
        });

        $this->assertNull(EnvTemplate::find($plantilla->id)->value);
        $this->assertNotEmpty($mensajes);
        $this->assertStringNotContainsString('esto-no-es-un-payload-valido', implode(' ', $mensajes));
    }

    public function test_actualizar_el_valor_lo_vuelve_a_cifrar(): void
    {
        $plantilla = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);
        $crudo_1   = DB::table('env_templates')->where('id', $plantilla->id)->value('value');

        $plantilla        = EnvTemplate::find($plantilla->id);
        $plantilla->value = 'sk-ant-test-OTRA-clave-9999';
        $plantilla->save();

        $crudo_2 = DB::table('env_templates')->where('id', $plantilla->id)->value('value');
        $this->assertStringStartsWith('enc:v1:', $crudo_2);
        $this->assertNotSame($crudo_1, $crudo_2);
        $this->assertStringNotContainsString('OTRA-clave', $crudo_2);
        $this->assertSame('sk-ant-test-OTRA-clave-9999', EnvTemplate::find($plantilla->id)->value);
    }

    public function test_guardar_otra_columna_no_cifra_dos_veces_el_valor(): void
    {
        $plantilla = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);

        /* Se re-guarda la fila cargada cambiándole sólo las notas: el valor no se toca. */
        $plantilla        = EnvTemplate::find($plantilla->id);
        $plantilla->notes = 'cambio solo las notas';
        $plantilla->save();

        $crudo = DB::table('env_templates')->where('id', $plantilla->id)->value('value');
        $this->assertSame(1, substr_count($crudo, 'enc:v1:'));
        $this->assertSame(self::CLAVE_DE_PRUEBA, EnvTemplate::find($plantilla->id)->value);
    }

    public function test_un_valor_ya_cifrado_asignado_a_mano_no_se_cifra_de_nuevo(): void
    {
        $plantilla = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);
        $crudo     = DB::table('env_templates')->where('id', $plantilla->id)->value('value');

        /* Otra fila a la que se le copia el crudo cifrado: re-guardarla no lo toca. */
        $otra = $this->crear('OPENAI_API_KEY', 'placeholder-que-se-pisa-0000');
        DB::table('env_templates')->where('id', $otra->id)->update(['value' => $crudo]);

        $otra        = EnvTemplate::find($otra->id);
        $otra->notes = 'x';
        $otra->save();

        $this->assertSame($crudo, DB::table('env_templates')->where('id', $otra->id)->value('value'));
    }

    public function test_un_valor_vacio_o_solo_espacios_se_guarda_null_en_una_sensible(): void
    {
        $vacia    = $this->crear('ANTHROPIC_API_KEY', '');
        $espacios = $this->crear('OPENAI_API_KEY', '   ');

        $this->assertNull(DB::table('env_templates')->where('id', $vacia->id)->value('value'));
        $this->assertNull(DB::table('env_templates')->where('id', $espacios->id)->value('value'));
        $this->assertNull(EnvTemplate::find($vacia->id)->value);
    }

    public function test_la_serializacion_de_una_sensible_no_contiene_el_valor(): void
    {
        $plantilla = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);
        $plantilla = EnvTemplate::find($plantilla->id);

        $json = $plantilla->toJson();
        $this->assertStringNotContainsString(self::CLAVE_DE_PRUEBA, $json);
        $this->assertStringNotContainsString('0123456789ABCDEF', $json);
        /* Ni cifrado: el crudo tampoco sale. */
        $this->assertStringNotContainsString('enc:v1:', $json);

        $array = $plantilla->toArray();
        $this->assertArrayNotHasKey('value', $array);
        $this->assertTrue($array['value_sensitive']);
        $this->assertTrue($array['value_set']);
        /* 28 caracteres >= 16: se muestra la cola de 4. */
        $this->assertSame('CDEF', $array['value_tail']);

        /* Una colección (lo que devuelve el index) también. */
        $this->assertStringNotContainsString(self::CLAVE_DE_PRUEBA, EnvTemplate::where('id', $plantilla->id)->get()->toJson());
    }

    public function test_una_sensible_con_valor_corto_no_muestra_cola_y_una_sin_valor_no_esta_cargada(): void
    {
        $corta = EnvTemplate::find($this->crear('PUSHER_APP_SECRET', 'corto-1234')->id);
        $sin   = EnvTemplate::find($this->crear('DEEPSEEK_API_KEY', null)->id);

        $this->assertTrue($corta->toArray()['value_set']);
        $this->assertNull($corta->toArray()['value_tail']);

        $this->assertFalse($sin->toArray()['value_set']);
        $this->assertNull($sin->toArray()['value_tail']);
    }

    public function test_la_serializacion_de_una_no_sensible_trae_su_valor_y_value_sensitive_false(): void
    {
        $plantilla = EnvTemplate::find($this->crear('MAIL_HOST', 'smtp.ejemplo.test')->id);

        $array = $plantilla->toArray();

        $this->assertSame('smtp.ejemplo.test', $array['value']);
        $this->assertFalse($array['value_sensitive']);
        $this->assertArrayNotHasKey('value_set', $array);
    }

    public function test_la_lectura_por_el_modelo_que_hacen_los_servicios_devuelve_el_valor_descifrado(): void
    {
        $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA, 'tienda');

        /* Es como leen DeploymentService / InstallationService: por el modelo, con el cast a string. */
        $leida = EnvTemplate::where('scope', 'tienda')->where('key', 'ANTHROPIC_API_KEY')->first();
        $this->assertSame(self::CLAVE_DE_PRUEBA, (string) ($leida->value ?? ''));
    }

    public function test_una_sensible_se_guarda_sin_espacios_ni_tabs_al_borde(): void
    {
        $con_espacio = $this->crear('ANTHROPIC_API_KEY', 'sk-ant-test-xxx ');
        $con_tab     = $this->crear('OPENAI_API_KEY', "\tsk-openai-test-yyy\t");

        $this->assertSame('sk-ant-test-xxx', EnvTemplate::find($con_espacio->id)->value);
        $this->assertSame('sk-openai-test-yyy', EnvTemplate::find($con_tab->id)->value);

        /* Re-guardar con espacios también recorta. */
        $recargada        = EnvTemplate::find($con_espacio->id);
        $recargada->value = '  sk-ant-test-otra  ';
        $recargada->save();
        $this->assertSame('sk-ant-test-otra', EnvTemplate::find($con_espacio->id)->value);

        /* Una no sensible no se toca: el recorte es sólo de los secretos. */
        $no_sensible = $this->crear('MAIL_FROM_NAME', 'Comercio City ');
        $this->assertSame('Comercio City ', EnvTemplate::find($no_sensible->id)->value);
    }

    public function test_esta_ilegible_distingue_un_cifrado_roto_de_un_vacio_o_un_legado_en_claro(): void
    {
        $rota   = $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);
        $sana   = $this->crear('OPENAI_API_KEY', self::CLAVE_DE_PRUEBA);
        $vacia  = $this->crear('DEEPSEEK_API_KEY', null);
        $legado = $this->crear('SERPER_API_KEY', 'se-reemplaza-por-legado-0000');
        $no_sen = $this->crear('MAIL_HOST', 'smtp.ejemplo.test');

        DB::table('env_templates')->where('id', $rota->id)->update(['value' => 'enc:v1:payload-roto']);
        DB::table('env_templates')->where('id', $legado->id)->update(['value' => 'sk-legado-en-claro-123456']);
        /* Una no sensible con el prefijo no cuenta: la guarda es sólo de secretos. */
        DB::table('env_templates')->where('id', $no_sen->id)->update(['value' => 'enc:v1:payload-roto']);

        $this->assertTrue(EnvTemplate::find($rota->id)->esta_ilegible());
        $this->assertFalse(EnvTemplate::find($sana->id)->esta_ilegible());
        $this->assertFalse(EnvTemplate::find($vacia->id)->esta_ilegible());
        $this->assertFalse(EnvTemplate::find($legado->id)->esta_ilegible());
        $this->assertFalse(EnvTemplate::find($no_sen->id)->esta_ilegible());

        /* La serialización lo informa y no la presenta como cargada. */
        $array = EnvTemplate::find($rota->id)->toArray();
        $this->assertTrue($array['value_ilegible']);
        $this->assertFalse($array['value_set']);
        $this->assertNull($array['value_tail']);
        $this->assertFalse(EnvTemplate::find($sana->id)->toArray()['value_ilegible']);
    }

    public function test_las_contrasenas_no_muestran_cola_y_el_resto_de_las_sensibles_si(): void
    {
        $contrasena = EnvTemplate::find($this->crear('MAIL_PASSWORD', 'contrasena-larga-de-prueba-1234')->id)->toArray();
        $pass_corta = EnvTemplate::find($this->crear('DB_PASS', 'contrasena-larga-de-prueba-5678')->id)->toArray();
        $secreto    = EnvTemplate::find($this->crear('PUSHER_APP_SECRET', 'secreto-largo-de-prueba-9999')->id)->toArray();

        $this->assertTrue($contrasena['value_set']);
        $this->assertNull($contrasena['value_tail']);
        $this->assertNull($pass_corta['value_tail']);

        $this->assertSame('9999', $secreto['value_tail']);
    }

    public function test_un_sensible_cifrado_sale_descifrado_por_el_armado_del_env_de_una_demo(): void
    {
        /* El constructor del servicio exige una credencial SSH del tipo de hosting; no se usa. */
        if (! ClientSshCredential::where('type', 'shared_hosting')->exists()) {
            ClientSshCredential::create([
                'type' => 'shared_hosting', 'host' => 'hosting.invalido.local', 'port' => 65002,
                'username' => 'usuario-de-prueba', 'password' => 'no-se-usa-' . Str::random(8),
            ]);
        }

        $this->crear('ANTHROPIC_API_KEY', self::CLAVE_DE_PRUEBA);
        $this->crear('MAIL_HOST', 'smtp.ejemplo.test');

        $demo = Demo::create([
            'user_id'           => 4242,
            'erp_spa_url'       => 'https://demo-cifrado.comerciocity.com',
            'erp_api_url'       => 'https://api-demo-cifrado.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo-cifrado.comerciocity.com',
            'ecommerce_api_url' => 'https://api-tienda-demo-cifrado.comerciocity.com',
        ]);
        /* El servicio exige una versión asociada para construirse. */
        $version = Version::create([
            'version' => '9.9.' . random_int(1000, 999999), 'title' => 'Versión de prueba', 'status' => 'published',
        ]);
        $instalacion = DemoInstallation::create([
            'demo_id' => $demo->id, 'version_id' => $version->id, 'status' => DemoInstallation::STATUS_PENDIENTE,
        ]);

        $vars = (new DemoInstallationService($instalacion))->build_env_vars_to_write();

        /* Al .env de la demo llega la clave real, no el texto cifrado de la base. */
        $this->assertSame(self::CLAVE_DE_PRUEBA, $vars['ANTHROPIC_API_KEY']);
        $this->assertSame('smtp.ejemplo.test', $vars['MAIL_HOST']);
        $this->assertStringNotContainsString('enc:v1:', implode("\n", $vars));
    }

    /**
     * Crea una fila de la plantilla por el modelo (que es lo que dispara el cifrado).
     *
     * @param  string       $key
     * @param  string|null  $value
     * @param  string       $scope
     * @return EnvTemplate
     */
    private function crear(string $key, $value, string $scope = 'empresa'): EnvTemplate
    {
        return EnvTemplate::create([
            'key'                 => $key,
            'value'               => $value,
            'group'               => 'ia',
            'scope'               => $scope,
            'is_common'           => false,
            'is_manual_on_create' => false,
            'sort_order'          => 1,
        ]);
    }
}
