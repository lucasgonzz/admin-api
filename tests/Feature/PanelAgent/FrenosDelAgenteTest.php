<?php

namespace Tests\Feature\PanelAgent;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión admin-control-total — los frenos del agente: lo que duele (borrado, plata, terceros, masiva,
 * credenciales) no se ejecuta sin el código de confirmación ligado al pedido exacto; el dry run no ejecuta
 * nada; las rutas excluidas dan 403.
 *
 * Cubre CA4, CA5, CA6, CA7 y CA8 del PLAN.
 */
class FrenosDelAgenteTest extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /**
     * Cierra el reloj congelado que algunos tests fijan con `Carbon::setTestNow`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * CA4 — Un DELETE del agente sin código devuelve 428 con el preview completo y no borra nada.
     *
     * @return void
     */
    public function test_un_delete_sin_codigo_devuelve_428_y_no_borra(): void
    {
        $agente   = $this->crear_agente();
        $token    = $this->token_de($agente);
        $variante = $this->crear_variante();

        $respuesta = $this->pedir_como($token, 'DELETE', '/api/admin/message-variant/' . $variante->id);

        $respuesta->assertStatus(428);
        $respuesta->assertJsonPath('frenada', true);
        $respuesta->assertJsonPath('code', 'agente_confirmacion_requerida');
        $respuesta->assertJsonPath('categoria', 'borrado');
        $respuesta->assertJsonPath('metodo', 'DELETE');
        $respuesta->assertJsonPath('ruta', 'message-variant/{id}');
        $respuesta->assertJsonPath('accion', 'MessageVariantController@destroy_json');

        $cuerpo = $respuesta->json();
        $this->assertNotEmpty($cuerpo['motivo'], 'El preview explica por qué frena.');
        $this->assertSame(16, strlen($cuerpo['codigo_confirmacion']), 'El código de confirmación tiene 16 caracteres.');
        $this->assertNotEmpty($cuerpo['como_confirmar'], 'El preview dice cómo confirmar.');
        $this->assertArrayHasKey('path', $cuerpo);
        $this->assertArrayHasKey('payload', $cuerpo);

        $this->assertDatabaseHas('message_variants', ['id' => $variante->id]);

        $fila = $this->ultima_auditoria_de($agente);
        $this->assertSame('frenada', $fila->result);
        $this->assertSame(1, (int) $fila->braked);
        $this->assertSame(428, (int) $fila->status_code);
        $this->assertSame('borrado', $fila->category);
    }

    /**
     * CA5 — Con el código del preview el pedido se ejecuta y la auditoría lo marca confirmado.
     *
     * El controlador de variantes responde 204 al borrar (el PLAN decía 200; el controlador real no se toca).
     *
     * @return void
     */
    public function test_con_el_codigo_del_preview_se_ejecuta_y_se_audita(): void
    {
        $agente   = $this->crear_agente();
        $token    = $this->token_de($agente);
        $variante = $this->crear_variante();
        $uri      = '/api/admin/message-variant/' . $variante->id;

        $preview = $this->pedir_como($token, 'DELETE', $uri);
        $preview->assertStatus(428);
        $codigo = $preview->json('codigo_confirmacion');

        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $codigo])->assertStatus(204);

        $this->assertDatabaseMissing('message_variants', ['id' => $variante->id]);

        $fila = $this->ultima_auditoria_de($agente);
        $this->assertSame(1, (int) $fila->confirmed);
        $this->assertSame('ejecutada', $fila->result);
        $this->assertSame(204, (int) $fila->status_code);
        $this->assertSame('borrado', $fila->category);
    }

    /**
     * CA5 (negativos) — Un código inválido, ajeno (otro pedido, otro cuerpo) o vencido no ejecuta nada.
     *
     * @return void
     */
    public function test_codigos_invalidos_ajenos_o_vencidos_no_ejecutan(): void
    {
        $agente = $this->crear_agente();
        $token  = $this->token_de($agente);

        $variante   = $this->crear_variante();
        $otra       = $this->crear_variante();
        $uri        = '/api/admin/message-variant/' . $variante->id;
        $codigo_ok  = $this->pedir_como($token, 'DELETE', $uri)->json('codigo_confirmacion');
        $codigo_otra = $this->pedir_como($token, 'DELETE', '/api/admin/message-variant/' . $otra->id)->json('codigo_confirmacion');
        $this->assertNotSame($codigo_ok, $codigo_otra, 'Cada pedido tiene su propio código.');

        // 1) El valor «si» no es un código.
        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => 'si'])->assertStatus(428);
        $this->assertDatabaseHas('message_variants', ['id' => $variante->id]);

        // 2) El código de OTRA variante no sirve para esta.
        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $codigo_otra])->assertStatus(428);
        $this->assertDatabaseHas('message_variants', ['id' => $variante->id]);

        // 3) Un código obtenido con un cuerpo no vale para el mismo pedido con OTRO cuerpo.
        $lead = $this->crear_lead();
        $uri_lead = '/api/admin/lead/' . $lead->id . '/send-direct-message';
        $codigo_cuerpo_a = $this->pedir_como($token, 'POST', $uri_lead, ['content' => 'Mensaje A'])->json('codigo_confirmacion');
        $this->assertSame(16, strlen($codigo_cuerpo_a));
        $this->pedir_como($token, 'POST', $uri_lead, ['content' => 'Mensaje B'], ['X-Agente-Confirmar' => $codigo_cuerpo_a])
            ->assertStatus(428);
        $this->assertSame(0, DB::table('lead_messages')->where('lead_id', $lead->id)->count(), 'No se creó ningún mensaje.');

        // 4) El código vence: 31 minutos después ya no vale (la ventana es de 15 min, se acepta la actual y la anterior).
        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $codigo_ok])->assertStatus(428);
        $this->assertDatabaseHas('message_variants', ['id' => $variante->id]);
        Carbon::setTestNow();

        // Control: el mismo código, sin vencer, SÍ ejecuta (descarta que los 428 anteriores fueran por otra causa).
        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $codigo_ok])->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $variante->id]);
    }

    /**
     * CA6 — Plata, terceros, masiva y credenciales frenan con 428 y no producen ningún efecto.
     *
     * @return void
     */
    public function test_plata_terceros_masiva_y_credenciales_frenan_sin_efectos(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();
        $lead    = $this->crear_lead();
        $api     = $this->crear_api_de_cliente($cliente);

        $pagos_antes    = DB::table('mensualidad_pagos')->count();
        $mensajes_antes = DB::table('lead_messages')->count();

        // Plata: registrar un pago de la mensualidad.
        $plata = $this->pedir_como($token, 'POST', '/api/admin/client/' . $cliente->id . '/mensualidad/pagos', [
            'periodo' => '2026-11',
            'monto'   => 1000,
        ]);
        $plata->assertStatus(428);
        $plata->assertJsonPath('categoria', 'plata');
        $this->assertSame($pagos_antes, DB::table('mensualidad_pagos')->count(), 'No se registró ningún pago.');

        // Terceros: un mensaje directo a un lead.
        $terceros = $this->pedir_como($token, 'POST', '/api/admin/lead/' . $lead->id . '/send-direct-message', [
            'content' => 'Hola, esto no tiene que salir.',
        ]);
        $terceros->assertStatus(428);
        $terceros->assertJsonPath('categoria', 'terceros');
        $this->assertSame($mensajes_antes, DB::table('lead_messages')->count(), 'No se creó ningún lead_message.');
        // (El freno de HTTP de TestCase hace fallar este test en el tearDown si algo hubiera intentado salir a internet.)

        // Masiva.
        $masiva = $this->pedir_como($token, 'POST', '/api/admin/mass-update/client', [
            'ids' => [$cliente->id],
            'props_to_update' => [['key' => 'is_active', 'value' => false]],
        ]);
        $masiva->assertStatus(428);
        $masiva->assertJsonPath('categoria', 'masiva');
        $this->assertSame(1, (int) DB::table('clients')->where('id', $cliente->id)->value('is_active'), 'El cliente sigue activo.');

        // Credenciales: GET que devuelve secretos descifrados.
        $credenciales = $this->pedir_como($token, 'GET', '/api/admin/client-apis/' . $api->getRouteKey() . '/hosting-credentials');
        $credenciales->assertStatus(428);
        $credenciales->assertJsonPath('categoria', 'credenciales');
        $this->assertArrayNotHasKey('provisioning_secrets', $credenciales->json(), 'El preview no devuelve los secretos.');

        // Los cuatro quedaron auditados como frenados.
        $frenadas = $this->auditoria_de($agente)->where('result', 'frenada');
        $this->assertCount(4, $frenadas);
        foreach ($frenadas as $fila) {
            $this->assertSame(1, (int) $fila->braked);
            $this->assertSame(428, (int) $fila->status_code);
        }
    }

    /**
     * CA7 — El dry run devuelve el preview sin ejecutar nada; en una ruta frenada trae un código que sirve para confirmar.
     *
     * @return void
     */
    public function test_dry_run_devuelve_preview_sin_ejecutar(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();

        // Ruta libre: 200 con el preview, sin ejecutar.
        $libre = $this->pedir_como(
            $token,
            'PUT',
            '/api/admin/client/' . $cliente->id,
            ['is_active' => false],
            ['X-Agente-Dry-Run' => 'si']
        );
        $libre->assertOk();
        $libre->assertJsonPath('frenada', false);
        $libre->assertJsonPath('categoria', 'escritura');
        $this->assertSame(1, (int) DB::table('clients')->where('id', $cliente->id)->value('is_active'), 'El dry run no ejecuta.');

        $fila = $this->ultima_auditoria_de($agente);
        $this->assertSame('dry_run', $fila->result);
        $this->assertSame(1, (int) $fila->dry_run);

        // Ruta frenada: el dry run trae el código de confirmación y tampoco ejecuta.
        $variante = $this->crear_variante();
        $uri      = '/api/admin/message-variant/' . $variante->id;
        $frenada  = $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Dry-Run' => 'si']);
        $frenada->assertOk();
        $frenada->assertJsonPath('frenada', true);
        $frenada->assertJsonPath('categoria', 'borrado');
        $this->assertSame(16, strlen($frenada->json('codigo_confirmacion')));
        $this->assertDatabaseHas('message_variants', ['id' => $variante->id]);

        // Y ese código es el mismo que acepta el pedido real.
        $this->pedir_como($token, 'DELETE', $uri, [], ['X-Agente-Confirmar' => $frenada->json('codigo_confirmacion')])
            ->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $variante->id]);
    }

    /**
     * CA8 — Las rutas excluidas (`logout`, `PUT me`) devuelven 403 y el token sigue sirviendo.
     *
     * @return void
     */
    public function test_las_rutas_excluidas_devuelven_403(): void
    {
        $agente = $this->crear_agente('Nombre original del agente');
        $token  = $this->token_de($agente);

        $logout = $this->pedir_como($token, 'POST', '/api/admin/logout');
        $logout->assertStatus(403);
        $logout->assertJsonPath('code', 'agente_excluida');
        $this->assertSame('excluida', $this->ultima_auditoria_de($agente)->result);

        // El token sigue vivo: el logout del panel no se ejecutó.
        $this->pedir_como($token, 'GET', '/api/admin/me')->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $agente->id)->count());

        $perfil = $this->pedir_como($token, 'PUT', '/api/admin/me', ['is_default_task_assignee' => true]);
        $perfil->assertStatus(403);
        $perfil->assertJsonPath('code', 'agente_excluida');
        $this->assertSame('Nombre original del agente', $agente->fresh()->name);
        $this->assertSame(0, (int) $agente->fresh()->is_default_task_assignee, 'El perfil del agente no cambió.');
    }
}
