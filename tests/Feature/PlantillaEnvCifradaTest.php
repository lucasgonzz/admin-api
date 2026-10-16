<?php

namespace Tests\Feature;

use App\Models\EnvTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
