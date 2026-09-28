<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\ImplementationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La clave de Serper en la configuración de implementaciones del admin (misión
 * serper-en-user-setup, 28/9/2026).
 *
 * Calcada de la API key de Google: dos settings (clientes y demos), un GET y un PUT por cada uno, y
 * las dos entradas en el lote de GET /settings/implementation. Lo que estas pruebas protegen, en
 * orden de importancia:
 *
 *  1. 🔴 **Que una clave mal copiada NO se guarde.** El error se paga caro y tarde: un cliente nuevo
 *     que no puede buscar imágenes, días después de haberla cargado.
 *  2. **Que null o vacía BORREN la clave**: es la forma de volver a la SERPER_API_KEY del .env de
 *     cada sistema.
 *  3. **Que la caída de demos a clientes viva en ImplementationSettings::get_serper_api_key_demo()**,
 *     que es lo que viaja en el demo-setup, y NO en el GET: el campo del panel tiene que verse vacío
 *     cuando las demos no tienen una clave propia.
 *
 * El memo de admin_settings es estático y sobrevive al rollback de la transacción (un rollback no
 * dispara los eventos del modelo), así que se vacía antes y después de cada test.
 */
class ClaveDeSerperEnLaConfiguracionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave de clientes de ejemplo: 40 caracteres hexadecimales, la forma de las claves de Serper.
     */
    const CLAVE_CLIENTES = '0123456789abcdef0123456789abcdef01234567';

    /**
     * Clave de demos de ejemplo, distinta de la de clientes para poder distinguir cuál viajó.
     */
    const CLAVE_DEMOS = 'fedcba9876543210fedcba9876543210fedcba98';

    /**
     * Ruta del setting de clientes.
     */
    const RUTA_CLIENTES = '/api/admin/settings/implementation-serper-api-key-default';

    /**
     * Ruta del setting de demos.
     */
    const RUTA_DEMOS = '/api/admin/settings/implementation-serper-api-key-demo';

    /**
     * Arranca sin ninguna clave de Serper cargada, sea cual sea el estado de la base del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Borrado masivo: no dispara los eventos del modelo, por eso el flush va explícito después.
        AdminSetting::whereIn('key', [
            'implementation_serper_api_key_default',
            'implementation_serper_api_key_demo',
        ])->delete();

        AdminSetting::flush_memo();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminSetting::flush_memo();

        parent::tearDown();
    }

    /**
     * Admin autenticado del panel.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Valor crudo guardado en admin_settings para una key (null si no hay fila).
     *
     * Se lee sin memo a propósito: lo que importa es lo que quedó en la base.
     *
     * @param string $key
     *
     * @return string|null
     */
    private function guardado(string $key)
    {
        return AdminSetting::where('key', $key)->value('value');
    }

    /**
     * Sin nada cargado, las dos rutas devuelven cadena vacía (el estado de un admin recién
     * desplegado: el user-setup no manda el campo y cada sistema usa su .env).
     *
     * @return void
     */
    public function test_sin_nada_cargado_las_dos_claves_vienen_vacias(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_CLIENTES)
            ->assertStatus(200)
            ->assertExactJson(['api_key' => '']);

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_DEMOS)
            ->assertStatus(200)
            ->assertExactJson(['api_key' => '']);
    }

    /**
     * Guardar la clave de clientes la deja en admin_settings y la devuelven el PUT, el GET y el
     * getter que usa el user-setup.
     *
     * @return void
     */
    public function test_guardar_la_clave_de_clientes_y_volver_a_leerla(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::RUTA_CLIENTES, ['api_key' => self::CLAVE_CLIENTES])
            ->assertStatus(200)
            ->assertExactJson(['api_key' => self::CLAVE_CLIENTES]);

        $this->assertSame(self::CLAVE_CLIENTES, $this->guardado('implementation_serper_api_key_default'));

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_CLIENTES)
            ->assertStatus(200)
            ->assertExactJson(['api_key' => self::CLAVE_CLIENTES]);

        $this->assertSame(self::CLAVE_CLIENTES, ImplementationSettings::get_serper_api_key_default());
    }

    /**
     * 🔴 Una clave mal copiada no se guarda, en ninguna de las dos rutas, y la que estaba cargada
     * queda intacta.
     *
     * Cada valor es un error de copiado real: cortada, con algo pegado de más, con un guion o un
     * espacio en el medio, con un símbolo, o directamente algo que no es texto.
     *
     * @return void
     */
    public function test_una_clave_mal_copiada_no_se_guarda(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);

        $invalidas = [
            'cortada (31 caracteres)'       => substr(self::CLAVE_CLIENTES, 0, 31),
            'demasiado larga (65)'          => str_repeat('a', 65),
            'con un guion'                  => '0123456789abcdef-0123456789abcdef0123456',
            'con un espacio en el medio'    => '0123456789abcdef 0123456789abcdef0123456',
            'con un símbolo'                => '0123456789abcdef0123456789abcdef0123456!',
            'con un salto de línea adentro' => "0123456789abcdef\n0123456789abcdef0123456",
            'un número'                     => 1234567890,
            'un array'                      => [self::CLAVE_CLIENTES],
        ];

        foreach ([self::RUTA_CLIENTES, self::RUTA_DEMOS] as $ruta) {
            foreach ($invalidas as $caso => $valor) {
                $respuesta = $this->actingAs($admin, 'sanctum')->putJson($ruta, ['api_key' => $valor]);

                $this->assertSame(422, $respuesta->status(), "La clave '{$caso}' se aceptó en {$ruta}.");
                $respuesta->assertJsonValidationErrors('api_key');
            }
        }

        $this->assertSame(
            self::CLAVE_CLIENTES,
            $this->guardado('implementation_serper_api_key_default'),
            'Un PUT rechazado pisó la clave de clientes que estaba cargada.'
        );
        $this->assertSame(
            self::CLAVE_DEMOS,
            $this->guardado('implementation_serper_api_key_demo'),
            'Un PUT rechazado pisó la clave de demos que estaba cargada.'
        );
    }

    /**
     * Un PUT sin el campo api_key se rechaza ("present"): no hay forma de borrar la clave por
     * omisión, solo mandándola en null o vacía a propósito.
     *
     * @return void
     */
    public function test_un_put_sin_el_campo_no_toca_la_clave(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::RUTA_CLIENTES, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_key');

        $this->assertSame(self::CLAVE_CLIENTES, $this->guardado('implementation_serper_api_key_default'));
    }

    /**
     * null, cadena vacía o solo espacios borran la clave: se guarda '' y el getter devuelve ''.
     *
     * Es la forma de volver al .env de cada sistema, así que tiene que andar por los tres caminos
     * que puede tomar el SPA.
     *
     * @return void
     */
    public function test_null_vacia_o_solo_espacios_borran_la_clave(): void
    {
        $admin = $this->crear_admin();

        foreach (['null' => null, 'vacía' => '', 'solo espacios' => '   '] as $caso => $valor) {
            AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

            $this->actingAs($admin, 'sanctum')
                ->putJson(self::RUTA_CLIENTES, ['api_key' => $valor])
                ->assertStatus(200)
                ->assertExactJson(['api_key' => '']);

            $this->assertSame('', $this->guardado('implementation_serper_api_key_default'), "Con {$caso} no se borró la clave.");
            $this->assertSame('', ImplementationSettings::get_serper_api_key_default(), "Con {$caso} el getter sigue viendo una clave.");
        }
    }

    /**
     * Una clave copiada con un espacio pegado a los costados se acepta y se guarda limpia (el
     * middleware TrimStrings la recorta antes de validar).
     *
     * @return void
     */
    public function test_una_clave_con_espacios_a_los_costados_se_guarda_limpia(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::RUTA_CLIENTES, ['api_key' => '  ' . self::CLAVE_CLIENTES . ' '])
            ->assertStatus(200)
            ->assertExactJson(['api_key' => self::CLAVE_CLIENTES]);

        $this->assertSame(self::CLAVE_CLIENTES, $this->guardado('implementation_serper_api_key_default'));
    }

    /**
     * La clave de demos se guarda, se lee y se borra igual que la de clientes, y sin tocarla.
     *
     * @return void
     */
    public function test_la_clave_de_demos_se_guarda_y_se_borra_sin_tocar_la_de_clientes(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::RUTA_DEMOS, ['api_key' => self::CLAVE_DEMOS])
            ->assertStatus(200)
            ->assertExactJson(['api_key' => self::CLAVE_DEMOS]);

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_DEMOS)
            ->assertStatus(200)
            ->assertExactJson(['api_key' => self::CLAVE_DEMOS]);

        $this->assertSame(self::CLAVE_DEMOS, $this->guardado('implementation_serper_api_key_demo'));
        $this->assertSame(self::CLAVE_CLIENTES, $this->guardado('implementation_serper_api_key_default'), 'Guardar la de demos tocó la de clientes.');

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::RUTA_DEMOS, ['api_key' => null])
            ->assertStatus(200)
            ->assertExactJson(['api_key' => '']);

        $this->assertSame('', $this->guardado('implementation_serper_api_key_demo'));
        $this->assertSame(self::CLAVE_CLIENTES, $this->guardado('implementation_serper_api_key_default'), 'Borrar la de demos borró la de clientes.');
    }

    /**
     * 🔴 La caída: sin clave propia de demos, lo que viaja a una demo es la de clientes; con clave
     * propia, la propia; sin ninguna, cadena vacía (y el demo-setup no manda el campo).
     *
     * Hay una sola cuenta de Serper, así que sin esta caída cargar una sola clave dejaría a todas
     * las demos sin Serper.
     *
     * @return void
     */
    public function test_la_clave_de_demos_cae_a_la_de_clientes_si_esta_vacia(): void
    {
        // Sin ninguna.
        $this->assertSame('', ImplementationSettings::get_serper_api_key_demo());

        // Solo la de clientes: las demos reciben esa.
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);
        $this->assertSame(self::CLAVE_CLIENTES, ImplementationSettings::get_serper_api_key_demo());

        // Con una propia de demos, gana la propia.
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);
        $this->assertSame(self::CLAVE_DEMOS, ImplementationSettings::get_serper_api_key_demo());

        // Una de demos que quedó en blanco (borrada) vuelve a caer a la de clientes.
        AdminSetting::set('implementation_serper_api_key_demo', '');
        $this->assertSame(self::CLAVE_CLIENTES, ImplementationSettings::get_serper_api_key_demo());

        // Y la caída es solo para las demos: la de clientes nunca toma la de demos.
        AdminSetting::set('implementation_serper_api_key_default', '');
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);
        $this->assertSame('', ImplementationSettings::get_serper_api_key_default());
    }

    /**
     * El GET de demos (y su entrada del lote) devuelve la clave GUARDADA para demos, no la
     * efectiva: con solo la de clientes cargada, viene vacía.
     *
     * Es lo que hace que el campo del panel se vea vacío cuando las demos usan la de clientes —que
     * es lo que dice su etiqueta— y que guardarlo sin tocar no copie la de clientes como propia.
     *
     * @return void
     */
    public function test_el_get_de_demos_devuelve_la_guardada_y_no_la_efectiva(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_DEMOS)
            ->assertStatus(200)
            ->assertExactJson(['api_key' => '']);

        $this->assertSame(
            '',
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/admin/settings/implementation')
                ->assertStatus(200)
                ->json('settings.implementation-serper-api-key-demo.api_key'),
            'El lote mostró la clave efectiva de demos en vez de la guardada.'
        );

        // Mientras tanto, lo que viajaría a una demo es la de clientes.
        $this->assertSame(self::CLAVE_CLIENTES, ImplementationSettings::get_serper_api_key_demo());
    }

    /**
     * El lote trae las dos claves con el mismo cuerpo que sus GET de a uno.
     *
     * @return void
     */
    public function test_el_lote_trae_las_dos_claves_con_el_mismo_cuerpo_que_sus_get(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);
        AdminSetting::flush_memo();

        $en_lote = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/settings/implementation')
            ->assertStatus(200)
            ->json('settings');

        $this->assertSame(['api_key' => self::CLAVE_CLIENTES], $en_lote['implementation-serper-api-key-default']);
        $this->assertSame(['api_key' => self::CLAVE_DEMOS], $en_lote['implementation-serper-api-key-demo']);

        $this->assertSame(
            $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_CLIENTES)->json(),
            $en_lote['implementation-serper-api-key-default']
        );
        $this->assertSame(
            $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_DEMOS)->json(),
            $en_lote['implementation-serper-api-key-demo']
        );
    }

    /**
     * Sin sesión no se leen ni se escriben: devuelven una clave de un servicio pago.
     *
     * @return void
     */
    public function test_sin_sesion_no_se_leen_ni_se_escriben(): void
    {
        foreach ([self::RUTA_CLIENTES, self::RUTA_DEMOS] as $ruta) {
            $this->getJson($ruta)->assertStatus(401);
            $this->putJson($ruta, ['api_key' => self::CLAVE_CLIENTES])->assertStatus(401);
        }

        $this->assertNull($this->guardado('implementation_serper_api_key_default'), 'Un PUT sin sesión guardó la clave de clientes.');
        $this->assertNull($this->guardado('implementation_serper_api_key_demo'), 'Un PUT sin sesión guardó la clave de demos.');
    }
}
