<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\ImplementationMessage;
use Carbon\Carbon;

/**
 * Registrar lo que se hizo por fuera por `claude/*`: `POST claude/implementations/{id}/actions`.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 Que SOLO registre: no manda ningún mensaje. (El texto va al hilo como saliente; nada sale a
 *     ninguna red.)
 *  2. 🔴 Que el saliente del hilo lleve SIEMPRE `whatsapp_message_id = waweb-<uuid>`, nunca null: un
 *     saliente con id nulo se lee como un envío fallido.
 *  3. 🔴 La idempotencia: la misma acción con el mismo texto en los últimos 10 minutos no se duplica,
 *     ni la entrada ni el mensaje; pasado ese tiempo sí es otro envío.
 *  4. Que el registro sea el MISMO que escribe el panel, para que su checklist se tilde.
 *  5. Que el teléfono se resuelva como el panel (el pedido de archivos al responsable de migración) y
 *     se normalice a E.164.
 */
class RegistroDeAccionesPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Una implementación en la etapa 1 con su cliente (con teléfono).
     *
     * @return \App\Models\Implementation
     */
    private function implementacion()
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['phone' => '3415559999']);

        return $this->crear_implementacion($cliente);
    }

    /**
     * El POST de registrar una acción.
     *
     * @param \App\Models\Implementation $implementation La implementación.
     * @param array<string, mixed>       $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function registrar($implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/actions', $cuerpo, $this->headers());
    }

    /**
     * Cuántas entradas tiene `data.actions` una etapa.
     *
     * @param \App\Models\Implementation $implementation La implementación.
     * @param int                        $etapa          La etapa.
     *
     * @return int
     */
    private function entradas_de($implementation, int $etapa): int
    {
        $data = $this->data_de_la_etapa($implementation, $etapa);

        return isset($data['actions']) ? count($data['actions']) : 0;
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos del pedido
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin la clave, 401.
     *
     * @return void
     */
    public function test_sin_clave_devuelve_401(): void
    {
        $this->postJson('/api/claude/implementations/1/actions', ['accion' => 'nota', 'canal' => 'otro'])->assertStatus(401);
    }

    /**
     * Un parámetro desconocido es 422 y no registra nada: un `content` o un `enviar` suelen ser alguien
     * esperando que acá se mande el mensaje.
     *
     * @return void
     */
    public function test_un_parametro_desconocido_es_422_y_no_registra_nada(): void
    {
        $impl = $this->implementacion();

        $respuesta = $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola', 'enviar' => true]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('enviar', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->entradas_de($impl, 1));
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $impl->id)->count());
    }

    /**
     * `accion` y `canal` son obligatorios y de una lista cerrada. `user_setup` no se puede registrar por
     * acá: lo escribe su propio endpoint.
     *
     * @return void
     */
    public function test_accion_y_canal_son_obligatorios_y_de_una_lista_cerrada(): void
    {
        $impl = $this->implementacion();

        $this->registrar($impl, ['canal' => 'otro'])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'nota'])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'user_setup', 'canal' => 'otro'])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'crear_instalacion', 'canal' => 'otro'])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'nota', 'canal' => 'paloma'])->assertStatus(422);

        $respuesta = $this->registrar($impl, ['accion' => 'inventada', 'canal' => 'otro']);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('valor que no está permitido', $this->cuerpo($respuesta));
    }

    /**
     * Con WhatsApp Web el texto es obligatorio (es el cuerpo del mensaje del hilo); con otro canal no.
     *
     * @return void
     */
    public function test_el_texto_es_obligatorio_con_whatsapp_web(): void
    {
        $impl = $this->implementacion();

        $sin = $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web']);
        $sin->assertStatus(422);
        $this->assertStringContainsString('es obligatorio cuando', $this->cuerpo($sin));

        $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => '   '])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'mail'])->assertStatus(201);
    }

    /**
     * La etapa tiene que ir de 1 a 8 y el texto no puede pasar de 4000 caracteres.
     *
     * @return void
     */
    public function test_la_etapa_y_el_texto_tienen_tope(): void
    {
        $impl = $this->implementacion();

        $this->registrar($impl, ['accion' => 'nota', 'canal' => 'otro', 'etapa' => 0])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'nota', 'canal' => 'otro', 'etapa' => 9])->assertStatus(422);
        $this->registrar($impl, ['accion' => 'nota', 'canal' => 'otro', 'texto' => str_repeat('a', 4001)])->assertStatus(422);
    }

    /**
     * Una implementación inexistente es 404.
     *
     * @return void
     */
    public function test_una_implementacion_inexistente_es_404(): void
    {
        $this->postJson('/api/claude/implementations/99999999/actions', ['accion' => 'nota', 'canal' => 'otro'], $this->headers())->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Registrar
     |----------------------------------------------------------------------------------------- */

    /**
     * 1, 2. Un WhatsApp Web: deja la entrada en el registro de la etapa actual y el saliente en el hilo,
     * con `whatsapp_message_id = waweb-<uuid>` (nunca null), el teléfono normalizado y el texto.
     *
     * @return void
     */
    public function test_un_whatsapp_web_deja_la_entrada_y_el_saliente_con_id(): void
    {
        $impl = $this->implementacion();

        $respuesta = $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola Panchito, soy Lucas de ComercioCity.']);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('registrada', true);
        $respuesta->assertJsonPath('ya_registrada', false);
        $respuesta->assertJsonPath('accion', 'presentacion');
        $respuesta->assertJsonPath('canal', 'whatsapp_web');
        $respuesta->assertJsonPath('etapa', 1);

        /* La entrada: la forma del panel más el canal y el origen. */
        $data = $this->data_de_la_etapa($impl, 1);
        $this->assertCount(1, $data['actions']);
        $entrada = $data['actions'][0];
        $this->assertSame('presentacion', $entrada['action']);
        $this->assertSame(1, $entrada['stage']);
        $this->assertSame('whatsapp_web', $entrada['canal']);
        $this->assertSame('claude', $entrada['origen']);
        $this->assertNotEmpty($entrada['at']);
        $this->assertSame($entrada['at'], $respuesta->json('at'));

        /* El saliente del hilo. */
        $mensaje = ImplementationMessage::where('implementation_id', $impl->id)->first();
        $this->assertNotNull($mensaje);
        $this->assertSame('outbound', $mensaje->direction);
        $this->assertSame(1, (int) $mensaje->stage_number);
        $this->assertSame('Hola Panchito, soy Lucas de ComercioCity.', $mensaje->body);
        $this->assertSame('+5493415559999', $mensaje->phone, 'El teléfono del cliente tiene que salir normalizado a E.164.');
        $this->assertNotNull($mensaje->sent_at);

        $this->assertNotNull($mensaje->whatsapp_message_id, 'Un saliente con id nulo se lee como envío fallido.');
        $this->assertSame(1, preg_match('/^waweb-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $mensaje->whatsapp_message_id));

        $respuesta->assertJsonPath('mensaje.id', (int) $mensaje->id);
        $respuesta->assertJsonPath('mensaje.whatsapp_message_id', $mensaje->whatsapp_message_id);
        $respuesta->assertJsonPath('mensaje.telefono', '+5493415559999');
    }

    /**
     * 1. Registrar NO manda nada: ninguna llamada HTTP sale a ninguna red.
     *
     * @return void
     */
    public function test_registrar_no_manda_nada_a_ninguna_red(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $impl = $this->implementacion();
        $this->registrar($impl, ['accion' => 'form_link', 'canal' => 'whatsapp_web', 'texto' => 'Acá está el formulario'])->assertStatus(201);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * Los canales que no son WhatsApp Web dejan la entrada y NO crean ningún mensaje.
     *
     * @return void
     */
    public function test_mail_llamada_y_otro_no_crean_mensaje(): void
    {
        $impl = $this->implementacion();

        foreach (['mail' => 'acceso', 'llamada' => 'entrega', 'otro' => 'listo'] as $canal => $accion) {
            $this->registrar($impl, ['accion' => $accion, 'canal' => $canal])->assertStatus(201)->assertJsonPath('mensaje', null);
        }

        $this->assertSame(3, $this->entradas_de($impl, 1));
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $impl->id)->count());
    }

    /**
     * Sin `etapa`, en la etapa actual; con `etapa`, en esa.
     *
     * @return void
     */
    public function test_la_etapa_por_defecto_es_la_actual_y_se_puede_elegir_otra(): void
    {
        $impl = $this->llevar_a_la_etapa($this->implementacion(), 3);

        $this->registrar($impl, ['accion' => 'pedir_archivos', 'canal' => 'mail'])->assertStatus(201)->assertJsonPath('etapa', 3);
        $this->registrar($impl, ['accion' => 'nota', 'canal' => 'otro', 'etapa' => 5, 'texto' => 'para la entrega'])->assertStatus(201)->assertJsonPath('etapa', 5);

        $this->assertSame(1, $this->entradas_de($impl, 3));
        $this->assertSame(1, $this->entradas_de($impl, 5));
        $this->assertSame(0, $this->entradas_de($impl, 1));
        $this->assertSame(5, $this->data_de_la_etapa($impl, 5)['actions'][0]['stage']);
    }

    /**
     * Una nota guarda su texto en la entrada (es lo único que es); los demás no repiten el texto.
     *
     * @return void
     */
    public function test_una_nota_guarda_su_texto(): void
    {
        $impl = $this->implementacion();

        $this->registrar($impl, ['accion' => 'nota', 'canal' => 'llamada', 'texto' => 'Habló con el hijo del dueño'])->assertStatus(201);
        $this->registrar($impl, ['accion' => 'progreso', 'canal' => 'mail', 'texto' => 'Resumen del avance'])->assertStatus(201);

        $entradas = $this->data_de_la_etapa($impl, 1)['actions'];
        $this->assertSame('Habló con el hijo del dueño', $entradas[0]['texto']);
        $this->assertArrayNotHasKey('texto', $entradas[1]);
    }

    /**
     * Se suma a lo que ya hay en el `data` de la etapa: ni pisa ni reordena lo del panel.
     *
     * @return void
     */
    public function test_se_suma_a_lo_que_ya_hay_en_la_etapa(): void
    {
        $impl = $this->implementacion();
        $this->escribir_data_de_la_etapa($impl, 1, [
            'form_responses' => ['company_name' => 'Panchito S.A.'],
            'actions'        => [['action' => 'presentacion', 'stage' => 1, 'at' => '2026-10-04T10:00:00.000000Z']],
        ]);

        $this->registrar($impl, ['accion' => 'form_link', 'canal' => 'mail'])->assertStatus(201);

        $data = $this->data_de_la_etapa($impl, 1);
        $this->assertSame(['company_name' => 'Panchito S.A.'], $data['form_responses']);
        $this->assertCount(2, $data['actions']);
        $this->assertSame('presentacion', $data['actions'][0]['action']);
        $this->assertArrayNotHasKey('origen', $data['actions'][0]);
        $this->assertSame('form_link', $data['actions'][1]['action']);
    }

    /* ------------------------------------------------------------------------------------------
     | El teléfono
     |----------------------------------------------------------------------------------------- */

    /**
     * El `telefono` pedido se normaliza a E.164 y gana sobre el del cliente.
     *
     * @return void
     */
    public function test_el_telefono_pedido_se_normaliza_y_gana(): void
    {
        $impl = $this->implementacion();

        $this->registrar($impl, ['accion' => 'entrega', 'canal' => 'whatsapp_web', 'texto' => 'Ya podés entrar', 'telefono' => '341 555-1234'])->assertStatus(201);

        $this->assertSame('+5493415551234', ImplementationMessage::where('implementation_id', $impl->id)->value('phone'));
    }

    /**
     * El pedido de archivos va al responsable de migración (como en el panel); si no hay, al dueño.
     *
     * @return void
     */
    public function test_el_pedido_de_archivos_va_al_responsable_de_migracion(): void
    {
        $impl                          = $this->implementacion();
        $impl->migration_contact_phone = '+5493415550101';
        $impl->save();

        $this->registrar($impl, ['accion' => 'pedir_archivos', 'canal' => 'whatsapp_web', 'texto' => 'Mandame los Excel'])->assertStatus(201);
        $this->registrar($impl, ['accion' => 'progreso', 'canal' => 'whatsapp_web', 'texto' => 'Vamos por la etapa 3'])->assertStatus(201);

        $telefonos = ImplementationMessage::where('implementation_id', $impl->id)->orderBy('id')->pluck('phone')->all();
        $this->assertSame(['+5493415550101', '+5493415559999'], $telefonos);
    }

    /**
     * Sin teléfono (ni pedido ni del cliente), un WhatsApp Web es 422 y no registra nada: el hilo no
     * tendría a quién mostrarle el mensaje.
     *
     * @return void
     */
    public function test_sin_telefono_el_whatsapp_web_es_422(): void
    {
        $cliente = $this->crear_cliente('Sin Teléfono', ['phone' => '']);
        $impl    = $this->crear_implementacion($cliente);

        $respuesta = $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('telefono', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->entradas_de($impl, 1));
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $impl->id)->count());

        /* Pero un mail no necesita teléfono. */
        $this->registrar($impl, ['accion' => 'acceso', 'canal' => 'mail'])->assertStatus(201);
    }

    /* ------------------------------------------------------------------------------------------
     | Idempotencia
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. 🔴 La misma acción con el mismo texto en los últimos 10 minutos devuelve 200
     * `{ya_registrada: true}` y NO duplica ni la entrada ni el mensaje.
     *
     * @return void
     */
    public function test_la_misma_accion_dentro_de_diez_minutos_no_se_duplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $impl   = $this->implementacion();
        $cuerpo = ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola Panchito'];

        $primera = $this->registrar($impl, $cuerpo);
        $primera->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:09:00'));

        $segunda = $this->registrar($impl, $cuerpo);
        $segunda->assertStatus(200);
        $segunda->assertJsonPath('ya_registrada', true);
        $segunda->assertJsonPath('registrada', false);
        $segunda->assertJsonPath('at', $primera->json('at'));
        $segunda->assertJsonPath('mensaje', null);

        $this->assertSame(1, $this->entradas_de($impl, 1), 'Se duplicó la entrada del registro.');
        $this->assertSame(1, ImplementationMessage::where('implementation_id', $impl->id)->count(), 'Se duplicó el mensaje del hilo.');
    }

    /**
     * 3. Pasados los 10 minutos es OTRO envío (el mismo texto se puede mandar de nuevo a propósito, p. ej.
     * un recordatorio).
     *
     * @return void
     */
    public function test_pasados_diez_minutos_es_otro_envio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $impl   = $this->implementacion();
        $cuerpo = ['accion' => 'form_link', 'canal' => 'whatsapp_web', 'texto' => 'Acá está el formulario'];

        $this->registrar($impl, $cuerpo)->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:11:00'));

        $this->registrar($impl, $cuerpo)->assertStatus(201);

        $this->assertSame(2, $this->entradas_de($impl, 1));
        $this->assertSame(2, ImplementationMessage::where('implementation_id', $impl->id)->count());
    }

    /**
     * 3. Cambiar la acción, el canal, el texto o la etapa es otra acción: no se confunde con la anterior.
     *
     * @return void
     */
    public function test_cambiar_accion_canal_texto_o_etapa_es_otra_accion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $impl = $this->implementacion();
        $base = ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola'];

        $this->registrar($impl, $base)->assertStatus(201);
        $this->registrar($impl, array_merge($base, ['accion' => 'form_link']))->assertStatus(201);
        $this->registrar($impl, array_merge($base, ['canal' => 'mail']))->assertStatus(201);
        $this->registrar($impl, array_merge($base, ['texto' => 'Hola otra vez']))->assertStatus(201);
        $this->registrar($impl, array_merge($base, ['etapa' => 2]))->assertStatus(201);

        $this->assertSame(4, $this->entradas_de($impl, 1));
        $this->assertSame(1, $this->entradas_de($impl, 2));
        $this->assertSame(4, ImplementationMessage::where('implementation_id', $impl->id)->count());
    }

    /**
     * Una entrada igual escrita por el PANEL no cuenta como "ya registrada": las escribe otro camino.
     *
     * @return void
     */
    public function test_una_entrada_del_panel_no_cuenta_para_la_idempotencia(): void
    {
        $impl = $this->implementacion();
        $this->escribir_data_de_la_etapa($impl, 1, ['actions' => [['action' => 'presentacion', 'stage' => 1, 'at' => now()->toISOString()]]]);

        $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'mail'])->assertStatus(201);

        $this->assertSame(2, $this->entradas_de($impl, 1));
    }

    /* ------------------------------------------------------------------------------------------
     | Integración con el panel y con el estado
     |----------------------------------------------------------------------------------------- */

    /**
     * 4. El registro es el MISMO que escribe el panel: su estado de acciones ve la ejecución
     * (`last_executed_at`), que es de donde sale el "hecho el…" y el tilde del checklist.
     *
     * @return void
     */
    public function test_el_panel_ve_la_accion_registrada(): void
    {
        $impl = $this->implementacion();

        $antes = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/implementation/' . $impl->id . '/actions');
        $this->assertNull($this->accion_del_panel($antes, 'presentacion')['last_executed_at']);

        $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Hola'])->assertStatus(201);

        $despues = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/implementation/' . $impl->id . '/actions');
        $this->assertNotNull($this->accion_del_panel($despues, 'presentacion')['last_executed_at'], 'El panel no ve la acción registrada por Claude.');
        $this->assertNull($this->accion_del_panel($despues, 'form_link')['last_executed_at']);
    }

    /**
     * La entrada de una acción del estado del panel, por su clave.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta La respuesta de `GET admin/implementation/{id}/actions`.
     * @param string                           $clave     La acción.
     *
     * @return array<string, mixed>
     */
    private function accion_del_panel($respuesta, string $clave): array
    {
        $respuesta->assertStatus(200);

        foreach ($respuesta->json('actions') as $accion) {
            if ($accion['key'] === $clave) {
                return $accion;
            }
        }

        $this->fail('El panel no devuelve la acción ' . $clave);
    }

    /**
     * El estado por `GET` muestra la acción con su canal y su origen, y el saliente baja los entrantes sin
     * responder a cero.
     *
     * @return void
     */
    public function test_el_estado_muestra_la_accion_y_el_saliente_responde_a_los_entrantes(): void
    {
        $impl = $this->implementacion();
        $this->crear_mensaje($impl, 'inbound', now()->subMinutes(30), 'wamid.1', 'Hola, ¿cuándo arrancamos?');

        $this->getJson('/api/claude/implementations/' . $impl->id, $this->headers())->assertJsonPath('entrantes.sin_responder', 1);

        $this->registrar($impl, ['accion' => 'presentacion', 'canal' => 'whatsapp_web', 'texto' => 'Arrancamos hoy'])->assertStatus(201);

        $estado = $this->getJson('/api/claude/implementations/' . $impl->id, $this->headers());
        $estado->assertJsonPath('entrantes.sin_responder', 0);
        $estado->assertJsonPath('etapas.0.acciones.0.accion', 'presentacion');
        $estado->assertJsonPath('etapas.0.acciones.0.canal', 'whatsapp_web');
        $estado->assertJsonPath('etapas.0.acciones.0.origen', 'claude');
    }
}
