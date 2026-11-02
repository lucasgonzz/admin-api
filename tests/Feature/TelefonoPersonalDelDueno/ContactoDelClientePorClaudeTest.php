<?php

namespace Tests\Feature\TelefonoPersonalDelDueno;

use App\Models\Client;
use App\Models\ClientContactChange;
use App\Models\ClientEmployee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `GET|PATCH claude/clients/{id}/contacto` (misión telefono-personal-del-dueno, 2/11/2026): ver y cambiar el
 * teléfono del negocio, el personal del dueño y el interruptor del asistente, con `dry_run`, validación y rastro.
 *
 * Lo que se cuida, en orden: que `dry_run` NO escriba, que el formato y los duplicados frenen ANTES de escribir,
 * que cada campo cambiado deje su fila de rastro, y que el booleano no se rompa con el string "0".
 *
 * Los teléfonos de las pruebas son distintos en los últimos ocho dígitos de cualquier ficha que haya quedado en
 * la base del slot.
 */
class ContactoDelClientePorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-contacto';

    const NEGOCIO  = '+5493445667788';
    const PERSONAL = '+5493415883322';
    const OTRO     = '+5493476112233';

    /**
     * Setea la clave de ingesta: el middleware es fail-closed y en el .env del slot está vacía.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Cliente activo.
     *
     * @param array<string, mixed> $atributos Sobrescriben los del cliente.
     *
     * @return Client
     */
    private function cliente(array $atributos = []): Client
    {
        $client                  = new Client();
        $client->name            = 'Mayorista de prueba';
        $client->company_name    = 'Mayorista de prueba S.R.L.';
        $client->phone           = self::NEGOCIO;
        $client->is_active       = true;
        $client->api_key         = 'clave-del-cliente';
        $client->inbound_api_key = 'clave-inbound';

        foreach ($atributos as $clave => $valor) {
            $client->{$clave} = $valor;
        }

        $client->save();

        return $client->fresh();
    }

    /**
     * PATCH al contacto del cliente.
     *
     * @param Client|int           $client Cliente o id.
     * @param array<string, mixed> $cuerpo Cuerpo del pedido.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function patch_contacto($client, array $cuerpo)
    {
        $id = $client instanceof Client ? $client->id : $client;

        return $this->patchJson('/api/claude/clients/' . $id . '/contacto', $cuerpo, $this->headers());
    }

    // ---------------------------------------------------------------------------------------------
    // Acceso y GET
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin la clave de ingesta es 401 (como todo el bloque claude/*).
     *
     * @return void
     */
    public function test_sin_la_clave_es_401(): void
    {
        $client = $this->cliente();

        $this->getJson('/api/claude/clients/' . $client->id . '/contacto')->assertStatus(401);
        $this->patchJson('/api/claude/clients/' . $client->id . '/contacto', ['phone' => self::OTRO])->assertStatus(401);
    }

    /**
     * El GET devuelve los dos teléfonos, el efectivo, el interruptor, la cantidad de empleados con teléfono y los
     * cambios recientes; nunca los números de los empleados.
     *
     * @return void
     */
    public function test_get_devuelve_el_contacto(): void
    {
        $client = $this->cliente(['asistente_phone' => self::PERSONAL, 'asistente_whatsapp_activo' => true]);

        $empleado            = new ClientEmployee();
        $empleado->client_id = $client->id;
        $empleado->name      = 'Brisa';
        $empleado->phone     = self::OTRO;
        $empleado->save();

        $r = $this->getJson('/api/claude/clients/' . $client->id . '/contacto', $this->headers());

        $r->assertStatus(200)
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('phone', self::NEGOCIO)
            ->assertJsonPath('asistente_phone', self::PERSONAL)
            ->assertJsonPath('telefono_personal_efectivo', self::PERSONAL)
            ->assertJsonPath('asistente_whatsapp_activo', true)
            ->assertJsonPath('empleados_con_telefono', 1)
            ->assertJsonPath('cambios_recientes', []);

        $this->assertStringNotContainsString(self::OTRO, (string) $r->getContent());
    }

    /**
     * Sin teléfono personal, el efectivo es el del negocio y `asistente_phone` viaja null.
     *
     * @return void
     */
    public function test_get_sin_telefono_personal_el_efectivo_es_el_del_negocio(): void
    {
        $client = $this->cliente();

        $this->getJson('/api/claude/clients/' . $client->id . '/contacto', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('asistente_phone', null)
            ->assertJsonPath('telefono_personal_efectivo', self::NEGOCIO)
            ->assertJsonPath('asistente_whatsapp_activo', false);
    }

    /**
     * Un cliente que no existe es 404, por id y por uuid.
     *
     * @return void
     */
    public function test_cliente_inexistente_es_404(): void
    {
        $this->getJson('/api/claude/clients/99999999/contacto', $this->headers())->assertStatus(404);
        $this->patch_contacto(99999999, ['phone' => self::OTRO])->assertStatus(404);
        $this->getJson('/api/claude/clients/no-existe-este-uuid/contacto', $this->headers())->assertStatus(404);
    }

    /**
     * La ficha del cliente (`GET claude/clients/{id}`) suma las dos claves nuevas y conserva `phone`.
     *
     * @return void
     */
    public function test_la_ficha_suma_asistente_phone_y_el_interruptor(): void
    {
        $client = $this->cliente(['asistente_phone' => self::PERSONAL, 'asistente_whatsapp_activo' => true]);

        $this->getJson('/api/claude/clients/' . $client->id, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('client.phone', self::NEGOCIO)
            ->assertJsonPath('client.asistente_phone', self::PERSONAL)
            ->assertJsonPath('client.asistente_whatsapp_activo', true);
    }

    /**
     * `claude/query?model=client`: el teléfono personal es opt-in bajo su propio include y el interruptor viaja en
     * las columnas base; `contacto` sigue devolviendo solo `phone`.
     *
     * @return void
     */
    public function test_la_consulta_generica_expone_el_telefono_personal_solo_con_su_include(): void
    {
        $client = $this->cliente(['asistente_phone' => self::PERSONAL, 'asistente_whatsapp_activo' => true]);

        $base = $this->getJson('/api/claude/query?model=client&ids=' . $client->id, $this->headers());
        $base->assertStatus(200);
        $this->assertArrayNotHasKey('asistente_phone', (array) $base->json('data.0'));
        $this->assertArrayNotHasKey('phone', (array) $base->json('data.0'));
        $this->assertEquals(1, $base->json('data.0.asistente_whatsapp_activo'));

        $contacto = $this->getJson('/api/claude/query?model=client&include=contacto&ids=' . $client->id, $this->headers());
        $contacto->assertStatus(200)->assertJsonPath('data.0.phone', self::NEGOCIO);
        $this->assertArrayNotHasKey('asistente_phone', (array) $contacto->json('data.0'));

        $personal = $this->getJson('/api/claude/query?model=client&include=contacto_personal&ids=' . $client->id, $this->headers());
        $personal->assertStatus(200)->assertJsonPath('data.0.asistente_phone', self::PERSONAL);
    }

    // ---------------------------------------------------------------------------------------------
    // PATCH: dry_run y escritura
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 `dry_run=true` devuelve el antes y el después y NO escribe nada, ni la ficha ni el rastro.
     *
     * @return void
     */
    public function test_dry_run_no_escribe(): void
    {
        $client = $this->cliente();

        $r = $this->patch_contacto($client, ['asistente_phone' => self::PERSONAL, 'dry_run' => true]);

        $r->assertStatus(200)
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('cambios.asistente_phone.antes', null)
            ->assertJsonPath('cambios.asistente_phone.despues', self::PERSONAL);

        $this->assertNull($client->fresh()->asistente_phone);
        $this->assertSame(0, ClientContactChange::where('client_id', $client->id)->count());
    }

    /**
     * Sin dry_run escribe, deja una fila de rastro por campo cambiado (con el motivo y el origen) y lo devuelve.
     *
     * @return void
     */
    public function test_escribe_y_deja_rastro_por_cada_campo(): void
    {
        $client = $this->cliente();

        $r = $this->patch_contacto($client, [
            'asistente_phone'           => '+54 9 341 588-3322',
            'asistente_whatsapp_activo' => true,
            'motivo'                    => 'El dueño escribe desde su personal',
        ]);

        $r->assertStatus(200)
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('cambios.asistente_phone.despues', self::PERSONAL)
            ->assertJsonPath('cambios.asistente_whatsapp_activo.antes', false)
            ->assertJsonPath('cambios.asistente_whatsapp_activo.despues', true)
            ->assertJsonPath('contacto.telefono_personal_efectivo', self::PERSONAL);

        $fresco = $client->fresh();
        $this->assertSame(self::PERSONAL, $fresco->asistente_phone);
        $this->assertTrue($fresco->asistente_whatsapp_activo);
        $this->assertSame(self::NEGOCIO, $fresco->phone, 'El teléfono del negocio no se tocó.');

        $filas = ClientContactChange::where('client_id', $client->id)->orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertSame('asistente_phone', $filas[0]->campo);
        $this->assertNull($filas[0]->valor_anterior);
        $this->assertSame(self::PERSONAL, $filas[0]->valor_nuevo);
        $this->assertSame('claude', $filas[0]->origen);
        $this->assertSame('El dueño escribe desde su personal', $filas[0]->motivo);
        $this->assertSame('asistente_whatsapp_activo', $filas[1]->campo);
        $this->assertSame('0', $filas[1]->valor_anterior);
        $this->assertSame('1', $filas[1]->valor_nuevo);

        /* Y el GET lo muestra en los cambios recientes. */
        $this->getJson('/api/claude/clients/' . $client->id . '/contacto', $this->headers())
            ->assertJsonCount(2, 'cambios_recientes');
    }

    /**
     * Cambiar el teléfono del negocio lo escribe y avisa que también cambia el del dueño si no tiene personal.
     *
     * @return void
     */
    public function test_cambiar_phone_lo_escribe_y_advierte(): void
    {
        $client = $this->cliente();

        $r = $this->patch_contacto($client, ['phone' => self::OTRO]);

        $r->assertStatus(200)->assertJsonPath('aplicado', true);
        $this->assertSame(self::OTRO, $client->fresh()->phone);
        $this->assertStringContainsString('también cambia el teléfono', implode(' ', $r->json('advertencias')));
    }

    /**
     * Borrar el teléfono personal (null o "") vuelve al del negocio y deja el rastro.
     *
     * @return void
     */
    public function test_borrar_el_telefono_personal(): void
    {
        foreach ([null, ''] as $vacio) {
            $client = $this->cliente(['asistente_phone' => self::PERSONAL]);

            $r = $this->patch_contacto($client, ['asistente_phone' => $vacio]);

            $r->assertStatus(200)
                ->assertJsonPath('aplicado', true)
                ->assertJsonPath('cambios.asistente_phone.antes', self::PERSONAL)
                ->assertJsonPath('cambios.asistente_phone.despues', null)
                ->assertJsonPath('contacto.telefono_personal_efectivo', self::NEGOCIO);

            $this->assertNull($client->fresh()->asistente_phone);
            $this->assertFalse($client->fresh()->tiene_telefono_personal_propio());
        }
    }

    /**
     * Reenviar lo que ya tiene el cliente no escribe ni deja rastro.
     *
     * @return void
     */
    public function test_reenviar_lo_mismo_no_cambia_nada(): void
    {
        $client = $this->cliente(['asistente_phone' => self::PERSONAL]);

        $this->patch_contacto($client, ['asistente_phone' => self::PERSONAL, 'phone' => self::NEGOCIO])
            ->assertStatus(200)
            ->assertJsonPath('aplicado', false);

        $this->assertSame(0, ClientContactChange::where('client_id', $client->id)->count());
    }

    /**
     * 🔴 El string "0" es FALSO: apaga el interruptor (y no lo deja prendido por ser un string no vacío).
     *
     * @return void
     */
    public function test_el_string_cero_apaga_el_interruptor(): void
    {
        $client = $this->cliente(['asistente_whatsapp_activo' => true]);

        $this->patch_contacto($client, ['asistente_whatsapp_activo' => '0'])
            ->assertStatus(200)
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('cambios.asistente_whatsapp_activo.despues', false);

        $fresco = $client->fresh();
        $this->assertFalse($fresco->asistente_whatsapp_activo);
        $this->assertSame(0, (int) \DB::table('clients')->where('id', $client->id)->value('asistente_whatsapp_activo'));
    }

    /**
     * El string "1" y el booleano true prenden el interruptor.
     *
     * @return void
     */
    public function test_uno_y_true_prenden_el_interruptor(): void
    {
        foreach (['1', true, 1] as $verdadero) {
            $client = $this->cliente();

            $this->patch_contacto($client, ['asistente_whatsapp_activo' => $verdadero])->assertStatus(200);

            $this->assertTrue($client->fresh()->asistente_whatsapp_activo);
        }
    }

    /**
     * Un valor que no es booleano en el interruptor es 422.
     *
     * @return void
     */
    public function test_un_interruptor_que_no_es_booleano_es_422(): void
    {
        $client = $this->cliente();

        $this->patch_contacto($client, ['asistente_whatsapp_activo' => 'quizás'])->assertStatus(422);
        $this->assertFalse($client->fresh()->asistente_whatsapp_activo);
    }

    // ---------------------------------------------------------------------------------------------
    // PATCH: validación
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin ningún campo para cambiar es 422.
     *
     * @return void
     */
    public function test_sin_ningun_campo_es_422(): void
    {
        $client = $this->cliente();

        $this->patch_contacto($client, ['motivo' => 'solo el motivo'])->assertStatus(422);
        $this->patch_contacto($client, [])->assertStatus(422);
    }

    /**
     * Un formato que no es internacional es 422 y no escribe nada, ni en `phone` ni en `asistente_phone`.
     *
     * @return void
     */
    public function test_un_formato_invalido_es_422_y_no_escribe(): void
    {
        $client = $this->cliente();

        foreach (['3415883322', '0341 588-3322', '+54 341', '+1234567890123456', '+54abc3415883322', 'hola'] as $malo) {
            $this->patch_contacto($client, ['asistente_phone' => $malo])
                ->assertStatus(422)
                ->assertJsonPath('campo', 'asistente_phone');
            $this->patch_contacto($client, ['phone' => $malo])
                ->assertStatus(422)
                ->assertJsonPath('campo', 'phone');
        }

        $fresco = $client->fresh();
        $this->assertNull($fresco->asistente_phone);
        $this->assertSame(self::NEGOCIO, $fresco->phone);
        $this->assertSame(0, ClientContactChange::where('client_id', $client->id)->count());
    }

    /**
     * Un número de otro país se guarda tal cual, sin pasarlo por el normalizador argentino.
     *
     * @return void
     */
    public function test_un_numero_de_otro_pais_se_guarda_sin_argentinizarlo(): void
    {
        $client = $this->cliente();

        $this->patch_contacto($client, ['asistente_phone' => '+1 (555) 123-4567'])->assertStatus(200);

        $this->assertSame('+15551234567', $client->fresh()->asistente_phone);
    }

    /**
     * El teléfono del negocio no se puede dejar vacío.
     *
     * @return void
     */
    public function test_el_telefono_del_negocio_no_se_puede_vaciar(): void
    {
        $client = $this->cliente();

        $this->patch_contacto($client, ['phone' => ''])->assertStatus(422);
        $this->patch_contacto($client, ['phone' => null])->assertStatus(422);

        $this->assertSame(self::NEGOCIO, $client->fresh()->phone);
    }

    /**
     * 🔴 Un número que ya es de OTRO cliente activo (phone o asistente_phone) es 422 y no escribe nada.
     *
     * @return void
     */
    public function test_un_numero_de_otro_cliente_activo_es_422(): void
    {
        $this->cliente(['phone' => self::OTRO, 'name' => 'Otro cliente']);
        $otro_personal = '+5493416007070';
        $this->cliente(['phone' => '+5493415005050', 'asistente_phone' => $otro_personal, 'name' => 'Otro con personal']);
        $client = $this->cliente();

        foreach ([self::OTRO, $otro_personal] as $ajeno) {
            $r = $this->patch_contacto($client, ['asistente_phone' => $ajeno]);

            $r->assertStatus(422)->assertJsonPath('motivo', 'numero_de_otro_cliente');
        }

        $this->assertNull($client->fresh()->asistente_phone);
        $this->assertSame(0, ClientContactChange::where('client_id', $client->id)->count());
    }

    /**
     * Un número de un cliente DADO DE BAJA no cuenta como choque (el webhook no lo reconoce).
     *
     * @return void
     */
    public function test_un_numero_de_un_cliente_dado_de_baja_no_choca(): void
    {
        $this->cliente(['phone' => self::OTRO, 'is_active' => false, 'name' => 'Dado de baja']);
        $client = $this->cliente();

        $this->patch_contacto($client, ['asistente_phone' => self::OTRO])->assertStatus(200);
    }

    /**
     * 🔴 Un número que ya es de un empleado es 422: el agente personal no reconocería al dueño.
     *
     * @return void
     */
    public function test_un_numero_de_un_empleado_es_422(): void
    {
        $client = $this->cliente();

        $empleado            = new ClientEmployee();
        $empleado->client_id = $client->id;
        $empleado->name      = 'Brisa';
        $empleado->phone     = self::PERSONAL;
        $empleado->save();

        $this->patch_contacto($client, ['asistente_phone' => self::PERSONAL])
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'numero_de_un_empleado');

        $this->assertNull($client->fresh()->asistente_phone);
    }

    /**
     * El teléfono personal igual al del negocio del MISMO cliente no es un choque (solo advierte).
     *
     * @return void
     */
    public function test_el_personal_igual_al_del_negocio_del_mismo_cliente_solo_advierte(): void
    {
        $client = $this->cliente();

        $r = $this->patch_contacto($client, ['asistente_phone' => self::NEGOCIO]);

        $r->assertStatus(200);
        $this->assertStringContainsString('es igual a `phone`', implode(' ', $r->json('advertencias')));
    }

    // ---------------------------------------------------------------------------------------------
    // PATCH: el interruptor
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Prender el interruptor en un cliente sin ningún teléfono es 422.
     *
     * @return void
     */
    public function test_prender_el_interruptor_sin_ningun_telefono_es_422(): void
    {
        $client = $this->cliente(['phone' => null]);

        $this->patch_contacto($client, ['asistente_whatsapp_activo' => true])
            ->assertStatus(422)
            ->assertJsonPath('campo', 'asistente_whatsapp_activo');

        $this->assertFalse($client->fresh()->asistente_whatsapp_activo);
    }

    /**
     * Prender el interruptor y cargar el teléfono en el mismo pedido es válido (se valida contra el resultado).
     *
     * @return void
     */
    public function test_prender_el_interruptor_cargando_el_telefono_en_el_mismo_pedido(): void
    {
        $client = $this->cliente(['phone' => null]);

        $this->patch_contacto($client, ['asistente_whatsapp_activo' => true, 'asistente_phone' => self::PERSONAL])
            ->assertStatus(200)
            ->assertJsonPath('aplicado', true);

        $this->assertTrue($client->fresh()->asistente_whatsapp_activo);
    }

    /**
     * Prender el interruptor sin el número del asistente listo ni paquete de IA avisa, pero no frena.
     *
     * @return void
     */
    public function test_prender_el_interruptor_advierte_si_el_canal_no_esta_listo(): void
    {
        $client = $this->cliente(['api_key' => '']);

        $r = $this->patch_contacto($client, ['asistente_whatsapp_activo' => true]);

        $r->assertStatus(200)->assertJsonPath('aplicado', true);

        $texto = implode(' ', $r->json('advertencias'));
        $this->assertStringContainsString('número del asistente no está listo', $texto);
        $this->assertStringContainsString('api_key', $texto);
        $this->assertStringContainsString('paquete de IA', $texto);
        $this->assertTrue($client->fresh()->asistente_whatsapp_activo);
    }
}
