<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Events\ImplementationMessageReceived;
use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use Illuminate\Support\Facades\Event;

/**
 * El teléfono y el hilo de lo que se registra con `POST claude/implementations/{id}/actions` y
 * `canal=whatsapp_web`: qué formato queda guardado, qué se considera "el mismo mensaje", qué vuelve en la
 * respuesta y quién se entera en el panel.
 *
 * El registro de una acción es la huella de un WhatsApp que la skill YA mandó por WhatsApp Web, y crea el
 * saliente del hilo con ese texto. Lo que se protege, en orden de importancia:
 *
 *  1. Que el teléfono se normalice con `WhatsappNormalizer`, el mismo con el que el webhook guarda lo que
 *     ENTRA: el hilo y la ventana de 24 h comparan teléfonos como texto, y un saliente guardado con otro
 *     formato que el entrante de la misma persona los trata como dos personas. (`ArgentinePhoneNormalizer`, que
 *     era el que usaba esta ruta, difiere: no saca el 0 de marcado local, y trata distinto el 15.)
 *  2. 🔴 Que la idempotencia incluya al destinatario: el mismo texto a DOS teléfonos distintos (el dueño y
 *     el responsable de migración, por ejemplo) son dos mensajes, y el segundo no puede descartarse como
 *     repetición del primero. Al MISMO teléfono, sí sigue siendo una repetición.
 *  3. Que la respuesta no devuelva el teléfono entero (`***9999`): lo lee una sesión de Claude que lo pega en
 *     una conversación, y para saber a quién salió alcanza con el final.
 *  4. Que el saliente avise al panel (el evento Pusher del hilo, `ImplementationMessageReceived`) para que el
 *     mensaje aparezca sin recargar —como cuando lo manda el propio panel—, que no lo haga si no hay mensaje
 *     (otro canal, o una repetición) y que un broadcast caído no rompa el registro: lo que se registra es
 *     algo que ya pasó, y no se puede deshacer porque falle un aviso.
 */
class TelefonoEHiloDeLasAccionesPorClaudeTest extends BaseDeImplementaciones
{
    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación en la etapa 1 con un cliente que tiene teléfono.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['phone' => '3415559999']);

        return ['cliente' => $cliente, 'implementacion' => $this->crear_implementacion($cliente)];
    }

    /**
     * El POST de registrar una acción.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function registrar(Implementation $implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/actions', $cuerpo, $this->headers());
    }

    /**
     * Los teléfonos de los salientes de la implementación, en orden.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<int, string>
     */
    private function telefonos_de_los_salientes(Implementation $implementation): array
    {
        return ImplementationMessage::where('implementation_id', $implementation->id)->where('direction', 'outbound')->orderBy('id')->pluck('phone')->all();
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El formato del teléfono
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. El `telefono` pedido se normaliza con `WhatsappNormalizer` y no con `ArgentinePhoneNormalizer`: con un
     * número con el 0 de marcado local o con el 15, que son los que los dos tratan distinto, el que queda
     * guardado es el que el webhook le asignaría al entrante de esa misma persona.
     *
     * @return void
     */
    public function test_el_telefono_se_normaliza_como_el_webhook(): void
    {
        $e = $this->escenario();

        foreach (['011 4444-5555', '1512345678', '+54 9 341 555-1234', '5493415551234'] as $crudo) {
            $this->registrar($e['implementacion'], ['accion' => 'progreso', 'canal' => 'whatsapp_web', 'texto' => 'Hola ' . $crudo, 'telefono' => $crudo])->assertStatus(201);
        }

        $esperados = [
            WhatsappNormalizer::normalize('011 4444-5555'),
            WhatsappNormalizer::normalize('1512345678'),
            WhatsappNormalizer::normalize('+54 9 341 555-1234'),
            WhatsappNormalizer::normalize('5493415551234'),
        ];

        $this->assertSame($esperados, $this->telefonos_de_los_salientes($e['implementacion']));
        $this->assertSame('+5491144445555', $esperados[0], 'El 0 de marcado local tiene que salir.');
        $this->assertSame('+5493415551234', $esperados[2]);
        $this->assertSame('+5493415551234', $esperados[3]);
    }

    /**
     * 1. El teléfono del cliente (cuando no se pide uno) pasa por el mismo normalizador.
     *
     * @return void
     */
    public function test_el_telefono_del_cliente_pasa_por_el_mismo_normalizador(): void
    {
        $cliente = $this->crear_cliente('Con Cero', ['phone' => '0341 555-9999']);
        $impl    = $this->crear_implementacion($cliente);

        $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola'])->assertStatus(201);

        $this->assertSame([WhatsappNormalizer::normalize('0341 555-9999')], $this->telefonos_de_los_salientes($impl));
        $this->assertSame('+5493415559999', $this->telefonos_de_los_salientes($impl)[0]);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. La idempotencia incluye el destinatario
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. 🔴 El mismo texto, por el mismo canal, para la misma acción y etapa, a DOS teléfonos distintos son dos
     * mensajes: los dos se registran y los dos salientes quedan en el hilo. Al MISMO teléfono, la segunda vez
     * sigue siendo una repetición (200, ya_registrada) y no duplica nada.
     *
     * @return void
     */
    public function test_el_mismo_texto_a_dos_telefonos_son_dos_mensajes(): void
    {
        $e      = $this->escenario();
        $cuerpo = ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola, soy Lucas de ComercioCity.'];

        $this->registrar($e['implementacion'], array_merge($cuerpo, ['telefono' => '3415550001']))->assertStatus(201);
        $this->registrar($e['implementacion'], array_merge($cuerpo, ['telefono' => '3415550002']))->assertStatus(201);

        $this->assertSame(['+5493415550001', '+5493415550002'], $this->telefonos_de_los_salientes($e['implementacion']));

        /* Repetir a cualquiera de los dos es una repetición. */
        foreach (['3415550001', '3415550002'] as $telefono) {
            $repetida = $this->registrar($e['implementacion'], array_merge($cuerpo, ['telefono' => $telefono]));
            $repetida->assertStatus(200);
            $repetida->assertJsonPath('ya_registrada', true);
        }

        $this->assertCount(2, $this->telefonos_de_los_salientes($e['implementacion']));

        /* Y la huella del panel tiene las dos entradas. */
        $acciones = $this->data_de_la_etapa($e['implementacion']->refresh(), 1)['actions'];
        $this->assertCount(2, $acciones);
    }

    /**
     * 2. El mismo teléfono escrito de dos maneras es el mismo destinatario: `341 555-0001` y `3415550001` son
     * una repetición, porque la clave usa el teléfono YA normalizado.
     *
     * @return void
     */
    public function test_el_mismo_telefono_escrito_distinto_es_una_repeticion(): void
    {
        $e      = $this->escenario();
        $cuerpo = ['accion' => 'form_link', 'canal' => 'whatsapp_web', 'texto' => 'Este es el formulario'];

        $this->registrar($e['implementacion'], array_merge($cuerpo, ['telefono' => '341 555-0001']))->assertStatus(201);
        $this->registrar($e['implementacion'], array_merge($cuerpo, ['telefono' => '3415550001']))->assertStatus(200)->assertJsonPath('ya_registrada', true);

        $this->assertCount(1, $this->telefonos_de_los_salientes($e['implementacion']));
    }

    /**
     * 2. Sin teléfono pedido, la repetición se sigue detectando contra el del cliente (el destinatario por
     * defecto es siempre el mismo).
     *
     * @return void
     */
    public function test_sin_telefono_pedido_la_repeticion_se_detecta_igual(): void
    {
        $e      = $this->escenario();
        $cuerpo = ['accion' => 'progreso', 'canal' => 'whatsapp_web', 'texto' => 'Seguimos con la etapa 2'];

        $this->registrar($e['implementacion'], $cuerpo)->assertStatus(201);
        $this->registrar($e['implementacion'], $cuerpo)->assertStatus(200)->assertJsonPath('ya_registrada', true);

        $this->assertCount(1, $this->telefonos_de_los_salientes($e['implementacion']));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. La respuesta no trae el teléfono entero
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. 🔴 `mensaje.telefono` sale enmascarado (`***9999`) y el teléfono entero no aparece en ningún lado de
     * la respuesta. En la base sí queda entero: lo necesita el hilo.
     *
     * @return void
     */
    public function test_la_respuesta_no_trae_el_telefono_entero(): void
    {
        $e = $this->escenario();

        $respuesta = $this->registrar($e['implementacion'], ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola']);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('mensaje.telefono', '***9999');
        $this->assertStringNotContainsString('3415559999', $this->cuerpo($respuesta));
        $this->assertSame(['+5493415559999'], $this->telefonos_de_los_salientes($e['implementacion']));
    }

    /* ------------------------------------------------------------------------------------------
     | 4. El hilo se entera
     |----------------------------------------------------------------------------------------- */

    /**
     * 4. Con `whatsapp_web` se emite el evento Pusher del hilo con la implementación y el saliente recién
     * creado, UNA vez: el panel lo agrega al hilo abierto sin recargar, como cuando lo manda él.
     *
     * @return void
     */
    public function test_se_emite_el_evento_del_hilo_con_el_saliente(): void
    {
        Event::fake([ImplementationMessageReceived::class]);
        $e = $this->escenario();

        $respuesta = $this->registrar($e['implementacion'], ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola']);
        $respuesta->assertStatus(201);

        $mensaje_id = (int) $respuesta->json('mensaje.id');

        Event::assertDispatchedTimes(ImplementationMessageReceived::class, 1);
        Event::assertDispatched(ImplementationMessageReceived::class, function ($evento) use ($e, $mensaje_id) {
            return $evento->implementation_id === (int) $e['implementacion']->id && $evento->implementation_message_id === $mensaje_id;
        });
    }

    /**
     * 4. No se emite nada si no hay mensaje: ni con otro canal (mail, llamada, otro) ni en una repetición
     * (200, ya_registrada).
     *
     * @return void
     */
    public function test_no_se_emite_sin_mensaje_nuevo(): void
    {
        Event::fake([ImplementationMessageReceived::class]);
        $e = $this->escenario();

        foreach (['mail', 'llamada', 'otro'] as $canal) {
            $this->registrar($e['implementacion'], ['accion' => 'nota', 'canal' => $canal, 'texto' => 'Nota por ' . $canal])->assertStatus(201);
        }

        Event::assertNotDispatched(ImplementationMessageReceived::class);

        $cuerpo = ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola'];
        $this->registrar($e['implementacion'], $cuerpo)->assertStatus(201);
        $this->registrar($e['implementacion'], $cuerpo)->assertStatus(200);

        Event::assertDispatchedTimes(ImplementationMessageReceived::class, 1);
    }

    /**
     * 4. 🔴 Un broadcast caído (Pusher sin red, sin credencial) NO rompe el registro: es 201, el saliente y la
     * huella quedan escritos. Lo que se registra ya pasó, y no se puede deshacer porque falle el aviso.
     *
     * @return void
     */
    public function test_un_broadcast_caido_no_rompe_el_registro(): void
    {
        Event::listen(ImplementationMessageReceived::class, function () {
            throw new \RuntimeException('Pusher caído');
        });

        $e = $this->escenario();

        $respuesta = $this->registrar($e['implementacion'], ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola']);

        $respuesta->assertStatus(201);
        $this->assertSame(['+5493415559999'], $this->telefonos_de_los_salientes($e['implementacion']));
        $this->assertCount(1, $this->data_de_la_etapa($e['implementacion']->refresh(), 1)['actions']);
    }
}
