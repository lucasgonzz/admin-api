<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Client;
use App\Models\Implementation;
use Illuminate\Support\Facades\Queue;

/**
 * El payload que muestra el dry-run de `POST claude/implementations/{id}/user-setup` y los datos personales
 * del dueño que lleva adentro.
 *
 * El dry-run devuelve el payload REAL (el mismo que viajaría a empresa-api) para que se mire antes de aplicar.
 * Ese payload trae el mail, el documento y el teléfono del dueño del negocio, y quien lo lee es una sesión de
 * Claude que después lo pega en una conversación. Mirar el payload para decidir no necesita esos tres datos
 * enteros: por defecto salen ENMASCARADOS (`p***@dominio`, `***4567`) y enteros solo si se pide
 * `include=contacto`, igual que el contacto en `GET claude/implementations/{id}`.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Que por defecto ninguno de los tres datos salga entero en NINGÚN lado de la respuesta.
 *  2. Que `include=contacto` los muestre enteros (en el cuerpo, como lista o como texto).
 *  3. Que lo que no es personal (la razón social, el user_id, las listas de precios) no se toque, y que un
 *     dato vacío o corto no rompa el enmascarado.
 *  4. Que `include` sea parte de la lista blanca de la ruta y que un valor que no existe sea 422.
 */
class PayloadDelUserSetupSinDatosPersonalesPorDefectoTest extends BaseDeImplementaciones
{
    /** El documento del dueño tal como lo cargó el formulario. */
    const DOCUMENTO = '20304050607';

    /** La casilla del dueño tal como la cargó el formulario. */
    const CORREO = 'panchito@ejemplo.test';

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación LISTA PARA CONFIGURAR: etapa 2, formulario enviado, las dos APIs, la instalación
     * real completada y un `setup_data` con los tres datos personales del dueño.
     *
     * @param array<string, mixed> $setup_data Lo que se pisa del `setup_data`.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(array $setup_data = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['phone' => '+5493415554567']);
        $cliente->setup_data = array_merge([
            'company_name'    => 'Panchito S.A.',
            'email'           => self::CORREO,
            'doc_number'      => self::DOCUMENTO,
            'use_price_lists' => true,
            'price_lists'     => "Minorista\nMayorista",
        ], $setup_data);
        $cliente->save();
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_instalacion($cliente, ['status' => 'completada', 'kind' => 'completa']);

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh()];
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

    /* ------------------------------------------------------------------------------------------
     | Por defecto, enmascarado
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Sin `include`, el payload del dry-run trae el mail, el documento y el teléfono enmascarados, y NO
     * aparecen enteros en ningún lado de la respuesta (ni en otro campo, ni repetidos en el texto).
     *
     * @return void
     */
    public function test_por_defecto_el_payload_trae_el_mail_el_documento_y_el_telefono_enmascarados(): void
    {
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], []);
        $payload   = $respuesta->json('payload');

        $respuesta->assertStatus(200);
        $this->assertSame('p***@ejemplo.test', $payload['email']);
        $this->assertSame('***0607', $payload['doc_number']);
        $this->assertSame('***4567', $payload['phone']);

        $cuerpo = $this->cuerpo($respuesta);
        $this->assertStringNotContainsString(self::DOCUMENTO, $cuerpo, 'El documento salió entero.');
        $this->assertStringNotContainsString(self::CORREO, $cuerpo, 'La casilla salió entera.');
        $this->assertStringNotContainsString('+5493415554567', $cuerpo, 'El teléfono salió entero.');
        $this->assertStringNotContainsString('5493415554567', $cuerpo, 'El teléfono salió entero (sin el +).');
    }

    /**
     * 3. Lo que no es personal queda como estaba: la razón social, el user_id y las listas de precios.
     *
     * @return void
     */
    public function test_lo_que_no_es_personal_no_se_toca(): void
    {
        $e = $this->escenario();

        $payload = $this->configurar($e['implementacion'], [])->json('payload');

        $this->assertSame('Panchito S.A.', $payload['company_name']);
        $this->assertSame((int) $e['cliente']->user_id, $payload['user_id']);
        $this->assertSame('Panchito Gómez', $payload['name']);
        $this->assertSame('Minorista', $payload['price_type_1']);
        $this->assertSame('Mayorista', $payload['price_type_2']);
    }

    /**
     * 3. Un dato vacío queda vacío y uno demasiado corto sale tapado entero: el enmascarado no inventa ni
     * deja ver lo que no puede mostrar a medias.
     *
     * @return void
     */
    public function test_un_dato_vacio_o_corto_no_rompe_el_enmascarado(): void
    {
        $vacio = $this->escenario(['email' => '', 'doc_number' => '']);
        $payload = $this->configurar($vacio['implementacion'], [])->json('payload');
        $this->assertSame('', $payload['doc_number']);
        $this->assertTrue($payload['email'] === '' || $payload['email'] === null);

        $corto = $this->escenario(['doc_number' => '123']);
        $this->assertSame('***', $this->configurar($corto['implementacion'], [])->json('payload.doc_number'));

        /* Un documento con puntos y guiones se enmascara por sus dígitos. */
        $con_formato = $this->escenario(['doc_number' => '20-30405060-7']);
        $this->assertSame('***0607', $this->configurar($con_formato['implementacion'], [])->json('payload.doc_number'));
    }

    /* ------------------------------------------------------------------------------------------
     | Con include=contacto, entero
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. Con `include=contacto` (como texto) el payload trae los tres datos enteros: es lo que se pide a
     * propósito cuando hace falta verlos.
     *
     * @return void
     */
    public function test_con_include_contacto_los_trae_enteros(): void
    {
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], ['include' => 'contacto']);
        $payload   = $respuesta->json('payload');

        $respuesta->assertStatus(200);
        $this->assertSame(self::CORREO, $payload['email']);
        $this->assertSame(self::DOCUMENTO, $payload['doc_number']);
        $this->assertSame('+5493415554567', $payload['phone']);
    }

    /**
     * 2. Lo mismo si el include viene como lista.
     *
     * @return void
     */
    public function test_con_include_contacto_como_lista_tambien(): void
    {
        $e = $this->escenario();

        $payload = $this->configurar($e['implementacion'], ['include' => ['contacto']])->json('payload');

        $this->assertSame(self::DOCUMENTO, $payload['doc_number']);
    }

    /**
     * 4. Un `include` que no existe es 422 con la lista de los válidos, y no se simula nada.
     *
     * @return void
     */
    public function test_un_include_que_no_existe_es_422(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], ['include' => 'todo']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('includes_validos', ['contacto']);
        Queue::assertNothingPushed();
    }

    /**
     * 4. `include` es parte de la lista blanca de la ruta: no es un parámetro "de más".
     *
     * @return void
     */
    public function test_include_esta_en_la_lista_blanca(): void
    {
        $e = $this->escenario();

        $this->configurar($e['implementacion'], ['include' => 'contacto'])->assertStatus(200);

        $de_mas = $this->configurar($e['implementacion'], ['mostrar' => 'todo']);
        $de_mas->assertStatus(422);
        $this->assertContains('include', $de_mas->json('parametros_aceptados'));
    }
}
