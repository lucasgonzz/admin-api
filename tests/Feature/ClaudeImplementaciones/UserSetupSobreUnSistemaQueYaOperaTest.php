<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\Implementation;
use App\Services\ImplementationUserSetupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * El user setup no se aplica sobre un sistema que ya opera, y le pega a la URL REAL (misión
 * `implementar-cliente`, revisión del 5/10/2026).
 *
 * 🔴 El user setup arranca con `migrate:fresh` del otro lado: re-aplicarlo sobre un sistema con datos es
 * destruirlos. El revisor independiente encontró que el candado era de UN solo camino
 * (`implementations.user_setup_executed_at`) y que el endpoint no miraba si el sistema ya operaba. Estos tests
 * fijan los tres chequeos nuevos y la URL del destino:
 *
 *  1. `instalacion_de_esta_implementacion`: la instalación completada es posterior al arranque de la
 *     implementación (una anterior es un sistema que ya existía: instalado por afuera, por /instalar-cliente,
 *     a mano o por otra implementación).
 *  2. `sin_sistema_vivo`: el cliente no tiene actualizaciones registradas (`client_version_upgrades`), el mismo
 *     chequeo que el alta y `install`.
 *  3. `lead_sin_user_setup`: el lead del que salió el cliente no tiene el user setup aplicado por el camino
 *     de leads (`RunUserSetupService`), que llama al MISMO endpoint remoto y no escribe el candado de acá.
 *  4. El endpoint sale NORMALIZADO como en todos los demás llamados del admin al sistema de un cliente: en
 *     hosting compartido con `/public` (los clientes nuevos guardan `client_apis.url` sin él y con la URL cruda
 *     el POST daba 404: medido contra quino y doblep), en VPS sin él, y siempre una sola vez.
 *  5. Los flags que le piden a empresa-api VACIAR una base con datos (`forzar_borrado_total`,
 *     `confirmar_base_de_datos`) nunca viajan, aunque estén en `setup_data`; y el dry-run tapa la dirección y las
 *     redes del negocio igual que `GET ?include=formulario`.
 */
class UserSetupSobreUnSistemaQueYaOperaTest extends BaseDeImplementaciones
{
    /** URL de la API activa del cliente de prueba, SIN `/public` (como las que escribe el alta). */
    const URL_API = 'https://api-panchito.ejemplo.test';

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación lista para configurar: etapa 2, formulario enviado, las dos APIs y la instalación real
     * completada en la API activa (creada DESPUÉS del arranque de la implementación, como la deja `install`).
     *
     * @param array<string, mixed> $opciones `hosting` (shared_hosting|vps), `url_de_la_activa` (pisa la de la API
     *                                       activa), `setup_data` (se suma al del formulario).
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(array $opciones = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente->setup_data = array_merge([
            'company_name'    => 'Panchito S.A.',
            'email'           => 'panchito@ejemplo.test',
            'doc_number'      => '20304050607',
            'use_price_lists' => true,
            'price_lists'     => "Minorista\nMayorista",
            'use_deposits'    => false,
            'iva_included'    => true,
        ], isset($opciones['setup_data']) ? $opciones['setup_data'] : []);
        $cliente->save();

        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito', isset($opciones['hosting']) ? $opciones['hosting'] : 'shared_hosting');

        ClientApi::where('id', $cliente->active_client_api_id)->update(['url' => isset($opciones['url_de_la_activa']) ? $opciones['url_de_la_activa'] : self::URL_API]);

        $implementacion                    = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_instalacion($cliente, ['status' => 'completada', 'kind' => 'completa']);

        return ['cliente' => $cliente->refresh(), 'implementacion' => $implementacion->refresh()];
    }

    /**
     * El POST del user setup.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function configurar(Implementation $implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/user-setup', $cuerpo, $this->headers());
    }

    /**
     * El pedido real: dry_run=false con la confirmación.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function aplicar(Implementation $implementation)
    {
        return $this->configurar($implementation, ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);
    }

    /**
     * El chequeo con ese nombre del dry-run.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta La respuesta.
     * @param string                           $nombre    El chequeo.
     *
     * @return array<string, mixed>
     */
    private function chequeo($respuesta, string $nombre): array
    {
        foreach ($respuesta->json('chequeos') as $chequeo) {
            if ($chequeo['chequeo'] === $nombre) {
                return $chequeo;
            }
        }

        $this->fail('La respuesta no trae el chequeo ' . $nombre);
    }

    /**
     * El registro del user setup de la etapa 2.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    private function registro(Implementation $implementation): array
    {
        $data = $this->data_de_la_etapa($implementation, 2);

        return isset($data['user_setup']) ? $data['user_setup'] : [];
    }

    /* ------------------------------------------------------------------------------------------
     | Los tres chequeos nuevos
     |----------------------------------------------------------------------------------------- */

    /**
     * El camino normal: nueve chequeos, los tres nuevos entre ellos, y todos en verde.
     *
     * @return void
     */
    public function test_el_dry_run_trae_los_nueve_chequeos_y_todos_en_verde(): void
    {
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], []);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('listo', true);

        $nombres = array_column($respuesta->json('chequeos'), 'chequeo');

        $this->assertSame(
            ['formulario_enviado', 'etapa_2', 'instalacion_completada', 'client_api_activa', 'instalacion_de_esta_implementacion', 'sin_sistema_vivo', 'lead_sin_user_setup', 'sin_aplicar_antes', 'sin_setup_en_curso'],
            $nombres
        );

        foreach ($respuesta->json('chequeos') as $chequeo) {
            $this->assertTrue($chequeo['ok'], $chequeo['chequeo'] . ': ' . $chequeo['detalle']);
        }
    }

    /**
     * 🔴 Una instalación completada ANTERIOR al arranque de la implementación es un sistema que ya existía: el dry-run
     * dice `listo: false`, el real es 422, no se encola nada y no se escribe el registro.
     *
     * @return void
     */
    public function test_una_instalacion_anterior_al_arranque_de_la_implementacion_frena(): void
    {
        Queue::fake();
        $e = $this->escenario();

        DB::table('implementations')->where('id', $e['implementacion']->id)->update(['started_at' => now()->addMinutes(30)]);
        $implementacion = $e['implementacion']->refresh();

        $dry = $this->configurar($implementacion, []);
        $dry->assertStatus(200);
        $dry->assertJsonPath('listo', false);
        $this->assertFalse($this->chequeo($dry, 'instalacion_de_esta_implementacion')['ok']);
        $this->assertStringContainsString('ANTERIOR al arranque', $this->chequeo($dry, 'instalacion_de_esta_implementacion')['detalle']);

        $real = $this->aplicar($implementacion);
        $real->assertStatus(422);
        $this->assertStringContainsString('instalacion_de_esta_implementacion', $this->cuerpo($real));
        $this->assertSame([], $this->registro($implementacion), 'El real escribió el registro con un chequeo en false.');
        Queue::assertNothingPushed();
    }

    /**
     * Sin instalación completada el chequeo nuevo "no aplica" (el de `instalacion_completada` ya está en false): no se
     * reporta dos veces lo mismo.
     *
     * @return void
     */
    public function test_sin_instalacion_el_chequeo_de_esta_implementacion_no_aplica(): void
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');
        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);

        $respuesta = $this->configurar($implementacion, []);

        $this->assertFalse($this->chequeo($respuesta, 'instalacion_completada')['ok']);
        $this->assertTrue($this->chequeo($respuesta, 'instalacion_de_esta_implementacion')['ok']);
        $this->assertStringContainsString('No aplica', $this->chequeo($respuesta, 'instalacion_de_esta_implementacion')['detalle']);
    }

    /**
     * 🔴 Un cliente con actualizaciones registradas es un sistema vivo: el user setup no se aplica.
     *
     * @return void
     */
    public function test_un_cliente_con_actualizaciones_registradas_frena(): void
    {
        Queue::fake();
        $e = $this->escenario();

        ClientVersionUpgrade::create(['client_id' => $e['cliente']->id, 'to_version_id' => $this->crear_version()->id, 'status' => 'pendiente']);

        $dry = $this->configurar($e['implementacion'], []);
        $dry->assertJsonPath('listo', false);
        $this->assertFalse($this->chequeo($dry, 'sin_sistema_vivo')['ok']);
        $this->assertStringContainsString('sistema vivo', $this->chequeo($dry, 'sin_sistema_vivo')['detalle']);

        $real = $this->aplicar($e['implementacion']);
        $real->assertStatus(422);
        $this->assertStringContainsString('sin_sistema_vivo', $this->cuerpo($real));
        $this->assertSame([], $this->registro($e['implementacion']));
        Queue::assertNothingPushed();
    }

    /**
     * 🔴 El user setup aplicado por el camino de LEADS (el de /instalar-cliente) frena este: llama al mismo endpoint
     * remoto y no escribe el candado de la implementación. Frenan `ejecutandose`, `exitoso` y `sin_confirmar` (la
     * llamada salió y no se sabe cómo terminó); no frenan `pendiente`, `fallido` ni la ausencia de lead.
     *
     * @return void
     */
    public function test_el_user_setup_del_lead_frena_segun_su_estado(): void
    {
        Queue::fake();

        $casos = ['ejecutandose' => false, 'exitoso' => false, 'sin_confirmar' => false, 'pendiente' => true, 'fallido' => true];

        foreach ($casos as $estado_del_lead => $deberia_pasar) {
            $e = $this->escenario();
            $this->crear_lead(['promoted_client_id' => $e['cliente']->id, 'user_setup_status' => $estado_del_lead]);

            $dry     = $this->configurar($e['implementacion'], []);
            $chequeo = $this->chequeo($dry, 'lead_sin_user_setup');

            $this->assertSame($deberia_pasar, $chequeo['ok'], 'Lead en "' . $estado_del_lead . '": ' . $chequeo['detalle']);
            $dry->assertJsonPath('listo', $deberia_pasar);

            if (! $deberia_pasar) {
                $this->assertStringContainsString('"' . $estado_del_lead . '"', $chequeo['detalle']);
                $this->aplicar($e['implementacion'])->assertStatus(422);
                $this->assertSame([], $this->registro($e['implementacion']));
            }
        }

        Queue::assertNothingPushed();
    }

    /**
     * Sin lead (el cliente se creó directo) el chequeo pasa.
     *
     * @return void
     */
    public function test_sin_lead_el_chequeo_del_lead_pasa(): void
    {
        $e = $this->escenario();

        $this->assertTrue($this->chequeo($this->configurar($e['implementacion'], []), 'lead_sin_user_setup')['ok']);
    }

    /* ------------------------------------------------------------------------------------------
     | La URL del destino
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 En hosting compartido el destino lleva `/public` aunque `client_apis.url` no lo traiga; y si ya lo trae, una
     * sola vez. En VPS no lleva ninguno (y si el valor guardado lo trae, se lo saca).
     *
     * @return void
     */
    public function test_el_destino_se_normaliza_como_en_el_resto_del_admin(): void
    {
        $casos = [
            'shared sin /public'   => ['shared_hosting', self::URL_API,                self::URL_API . '/public/api/admin-sync/user-setup'],
            'shared con /public'   => ['shared_hosting', self::URL_API . '/public',    self::URL_API . '/public/api/admin-sync/user-setup'],
            'shared con barra'     => ['shared_hosting', self::URL_API . '/public/',   self::URL_API . '/public/api/admin-sync/user-setup'],
            'vps sin /public'      => ['vps',            self::URL_API,                self::URL_API . '/api/admin-sync/user-setup'],
            'vps con /public'      => ['vps',            self::URL_API . '/public',    self::URL_API . '/api/admin-sync/user-setup'],
        ];

        foreach ($casos as $nombre => [$hosting, $url_guardada, $esperado]) {
            $e = $this->escenario(['hosting' => $hosting, 'url_de_la_activa' => $url_guardada]);

            $dry = $this->configurar($e['implementacion'], []);

            $dry->assertJsonPath('destino.endpoint', $esperado);
            $this->assertTrue($this->chequeo($dry, 'client_api_activa')['ok'], $nombre);
        }
    }

    /**
     * El servicio llama al MISMO destino que muestra el dry-run (el job usa el servicio): hosting compartido y VPS.
     *
     * @return void
     */
    public function test_el_servicio_llama_al_destino_normalizado(): void
    {
        $casos = [
            'shared' => ['shared_hosting', self::URL_API . '/public/api/admin-sync/user-setup'],
            'vps'    => ['vps',            self::URL_API . '/api/admin-sync/user-setup'],
        ];

        foreach ($casos as $nombre => [$hosting, $esperado]) {
            $e = $this->escenario(['hosting' => $hosting]);

            $pedidos = new \stdClass();
            $pedidos->urls = [];

            Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
            Http::fake(function ($pedido) use ($pedidos) {
                $pedidos->urls[] = $pedido->url();

                return Http::response(['ok' => true], 200);
            });

            $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

            $this->assertTrue($resultado['ok'], $nombre . ': ' . $resultado['message']);
            $this->assertSame([$esperado], $pedidos->urls, $nombre);
        }
    }

    /**
     * Una URL que no es http(s) válida no se llama (sin cliente con API activa utilizable): mismo mensaje que sin API.
     *
     * @return void
     */
    public function test_una_url_invalida_no_se_llama(): void
    {
        $e = $this->escenario(['url_de_la_activa' => 'ftp://api-panchito.ejemplo.test']);

        Http::fake();

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('todavía no tiene una client_api activa', $resultado['message']);
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | El payload
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 `forzar_borrado_total` y `confirmar_base_de_datos` NUNCA viajan, aunque `setup_data` (un JSON del cliente que
     * un edit del admin puede completar con cualquier clave) los traiga: saltearían la guarda `base_con_datos` de
     * empresa-api. Lo demás de `setup_data` sigue viajando.
     *
     * @return void
     */
    public function test_los_flags_de_borrado_total_no_viajan(): void
    {
        $e = $this->escenario(['setup_data' => [
            'forzar_borrado_total'    => true,
            'confirmar_base_de_datos' => 'panchito',
            'dato_comun'              => 'sigue viajando',
        ]]);

        $payload = (new ImplementationUserSetupService())->build_payload($e['cliente']);

        $this->assertArrayNotHasKey('forzar_borrado_total', $payload);
        $this->assertArrayNotHasKey('confirmar_base_de_datos', $payload);
        $this->assertSame('sigue viajando', $payload['dato_comun']);

        /* Y el POST real tampoco los lleva. */
        $enviado = null;
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(function ($pedido) use (&$enviado) {
            $enviado = $pedido->data();

            return Http::response(['ok' => true], 200);
        });

        (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

        $this->assertIsArray($enviado);
        $this->assertArrayNotHasKey('forzar_borrado_total', $enviado);
        $this->assertArrayNotHasKey('confirmar_base_de_datos', $enviado);
    }

    /**
     * El dry-run tapa enteras la dirección del negocio y sus redes (igual que `GET ?include=formulario`) y las muestra
     * con `include=contacto`.
     *
     * @return void
     */
    public function test_el_dry_run_tapa_la_direccion_y_las_redes(): void
    {
        $e = $this->escenario(['setup_data' => [
            'address_company' => 'Av. Siempre Viva 742',
            'facebook'        => 'facebook.com/panchito',
            'instagram'       => '@panchito',
        ]]);

        $tapado = $this->configurar($e['implementacion'], []);

        $tapado->assertJsonPath('payload.address_company', '***');
        $tapado->assertJsonPath('payload.facebook', '***');
        $tapado->assertJsonPath('payload.instagram', '***');
        $this->assertStringNotContainsString('Siempre Viva', $this->cuerpo($tapado));

        $abierto = $this->configurar($e['implementacion'], ['include' => 'contacto']);

        $abierto->assertJsonPath('payload.address_company', 'Av. Siempre Viva 742');
        $abierto->assertJsonPath('payload.facebook', 'facebook.com/panchito');
        $abierto->assertJsonPath('payload.instagram', '@panchito');
    }
}
