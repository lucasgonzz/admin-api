<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA6 de la misión dots-openclaw: las ocho herramientas de lectura contra un reporte v2 real (`POST
 * claude/local/oficina`) y la Agenda en base, con el reloj en el 26/11/2026 12:00 ART.
 *
 * Forma de las respuestas que fijan estos tests (el plan fija las claves de la tabla de §2.2 y estos nombres para
 * las que el plan sólo describe): `list_missions` => `misiones` y `misiones_recorte`; `get_mission` => los campos
 * de la misión en la raíz; `get_request_status` => `progreso` (con pedido) o `pedidos` (sin pedido); `get_workboard`
 * => `conteo` y `tarjetas`; `get_scheduled_tasks` => `gateway` y `tareas`; `get_mission_queue` => `en_espera`,
 * `fallidas`, `ultimo_chequeo`, `sin_senal`; `get_overview` => `por_estado`, `por_origen`, `demoradas`,
 * `esperando_lucas`, `cola.en_espera` (entero), `agenda.hoy_pendientes` (entero), `snapshot_at` y `sin_senal`.
 * La Agenda se verifica por el texto devuelto para no atar la forma interna de cada tarea.
 */
class McpHerramientasTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;

    /** @var string El `access_token` del admin dueño de la Agenda. */
    private $token;

    /**
     * Arma la escena del CA6 y un token del admin dueño de la Agenda.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_mcp();

        $admin       = $this->admin_de_mcp();
        $otro_admin  = $this->admin_de_mcp();
        $this->armar_escena_del_ca6($admin, $otro_admin);
        $this->token = $this->token_para($admin)['access_token'];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->soltar_reloj();

        parent::tearDown();
    }

    /**
     * Los ids de una lista de misiones.
     *
     * @param array<int, array<string, mixed>> $misiones Lista devuelta por la herramienta.
     *
     * @return array<int, string>
     */
    private function ids_de(array $misiones)
    {
        $ids = array_column($misiones, 'id');
        sort($ids);

        return $ids;
    }

    /**
     * Filtros de `list_missions`: demoradas, por estado, por defecto sin terminadas y por pedido con terminadas.
     *
     * @return void
     */
    public function test_list_missions_filtros(): void
    {
        $demoradas = $this->datos_de_herramienta($this->token, 'list_missions', ['solo_demoradas' => true]);
        $this->assertSame(['cc:b'], $this->ids_de($demoradas['misiones']));
        $this->assertArrayHasKey('misiones_recorte', $demoradas);
        $this->assertArrayHasKey('snapshot_at', $demoradas);
        $this->assertArrayHasKey('sin_senal', $demoradas);

        $esperando = $this->datos_de_herramienta($this->token, 'list_missions', ['estado' => 'esperando_lucas']);
        $this->assertSame(['cc:c'], $this->ids_de($esperando['misiones']));

        $por_defecto = $this->datos_de_herramienta($this->token, 'list_missions');
        $this->assertSame(['cc:a', 'cc:b', 'cc:c'], $this->ids_de($por_defecto['misiones']), 'Por defecto no salen las terminadas.');

        $del_pedido = $this->datos_de_herramienta($this->token, 'list_missions', ['pedido_id' => '20261126-0930-precios', 'incluir_terminadas' => true]);
        $this->assertSame(['cc:c', 'cc:d'], $this->ids_de($del_pedido['misiones']));
        $this->assertSame('20261126-0930-precios', $del_pedido['misiones'][0]['pedido_id']);
    }

    /**
     * `get_mission` acepta el slug pelado y devuelve la demora y el historial de fases; una misión que no está
     * da `isError`.
     *
     * @return void
     */
    public function test_get_mission_demora(): void
    {
        $mision = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'b']);

        $this->assertSame('cc:b', $mision['id']);
        $this->assertTrue($mision['demorada']);
        $this->assertSame(300, $mision['minutos_en_etapa']);
        $this->assertSame(240, $mision['umbral_min']);
        $this->assertNotEmpty($mision['fase_historial']);

        $con_prefijo = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'cc:a']);
        $this->assertFalse($con_prefijo['demorada']);
        $this->assertSame(60, $con_prefijo['minutos_en_etapa']);

        $inexistente = $this->llamar_herramienta($this->token, 'get_mission', ['id' => 'no-existe']);
        $this->assertTrue($inexistente['isError']);
    }

    /**
     * `get_request_status` junta misiones, cola y tareas de Agenda del pedido en un progreso exacto; sin id lista
     * los pedidos vistos.
     *
     * @return void
     */
    public function test_estado_de_pedido(): void
    {
        $estado = $this->datos_de_herramienta($this->token, 'get_request_status', ['pedido_id' => '20261126-0930-precios']);

        $this->assertSame([
            'total' => 4, 'terminadas' => 1, 'trabajando' => 0, 'esperando_lucas' => 1, 'trabadas' => 0,
            'en_cola' => 1, 'agenda_pendientes' => 1, 'agenda_hechas' => 0,
        ], $estado['progreso']);

        $pedidos = $this->datos_de_herramienta($this->token, 'get_request_status');
        $this->assertContains('20261126-0930-precios', array_column($pedidos['pedidos'], 'pedido_id'));
    }

    /**
     * La Agenda que sale es la del admin del token: está "Llamar a Juan" y no "Secreto ajeno" (de otro admin).
     *
     * @return void
     */
    public function test_agenda_solo_del_admin(): void
    {
        $resultado = $this->llamar_herramienta($this->token, 'get_agenda', ['rango' => 'hoy']);
        $this->assertNotTrue($resultado['isError'] ?? false);

        $texto = json_encode($resultado['structuredContent'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Llamar a Juan', $texto);
        $this->assertStringNotContainsString('Secreto ajeno', $texto);
        $this->assertStringNotContainsString('Secreto ajeno', json_encode($resultado, JSON_UNESCAPED_UNICODE), 'Tampoco en el texto de content.');
    }

    /**
     * `get_overview`: conteos por estado, demoradas, esperando a Lucas, cola y Agenda de hoy.
     *
     * @return void
     */
    public function test_overview(): void
    {
        $resumen = $this->datos_de_herramienta($this->token, 'get_overview');

        $this->assertSame(2, $resumen['por_estado']['trabajando']);
        $this->assertCount(1, $resumen['demoradas']);
        $this->assertSame('cc:b', $resumen['demoradas'][0]['id']);
        $this->assertCount(1, $resumen['esperando_lucas']);
        $this->assertSame('cc:c', $resumen['esperando_lucas'][0]['id']);
        $this->assertSame(1, $resumen['cola']['en_espera']);
        $this->assertSame(1, $resumen['agenda']['hoy_pendientes']);
        $this->assertArrayHasKey('por_origen', $resumen);
        $this->assertArrayHasKey('snapshot_at', $resumen);
        $this->assertArrayHasKey('sin_senal', $resumen);
    }

    /**
     * `get_workboard` (con y sin filtro), `get_scheduled_tasks` y `get_mission_queue` devuelven lo que trae el
     * reporte.
     *
     * @return void
     */
    public function test_workboard_tareas_y_cola(): void
    {
        $listas = $this->datos_de_herramienta($this->token, 'get_workboard', ['estado' => 'ready']);
        $this->assertSame(['t-2', 't-3'], array_column($listas['tarjetas'], 'id'));
        $this->assertSame(['ready', 'ready'], array_column($listas['tarjetas'], 'estado'));
        $this->assertArrayHasKey('conteo', $listas);
        $this->assertSame(1, $listas['conteo']['running']);

        $todas = $this->datos_de_herramienta($this->token, 'get_workboard');
        $this->assertCount(4, $todas['tarjetas']);

        $tareas = $this->datos_de_herramienta($this->token, 'get_scheduled_tasks');
        $this->assertSame('ok', $tareas['gateway']['estado']);
        $this->assertSame(['c-1', 'c-2'], array_column($tareas['tareas'], 'id'));
        $this->assertSame(['Tarea c-1', 'Tarea de comando'], array_column($tareas['tareas'], 'nombre'));
        $this->assertTrue($tareas['tareas'][0]['habilitada']);

        $cola = $this->datos_de_herramienta($this->token, 'get_mission_queue');
        $this->assertCount(1, $cola['en_espera']);
        $this->assertSame('e', $cola['en_espera'][0]['slug']);
        $this->assertSame(['memoria'], $cola['en_espera'][0]['motivos']);
        $this->assertSame('20261126-0930-precios', $cola['en_espera'][0]['pedido_id']);
        $this->assertSame(0, $cola['fallidas']);
        $this->assertNotNull($cola['ultimo_chequeo']);
        $this->assertFalse($cola['sin_senal']);
    }

    /**
     * Un argumento fuera de rango es un `isError` con el rango válido en el texto, no una excepción JSON-RPC.
     *
     * @return void
     */
    public function test_argumento_invalido(): void
    {
        $resultado = $this->llamar_herramienta($this->token, 'list_missions', ['limite' => 500]);

        $this->assertTrue($resultado['isError']);
        $texto = (string) $resultado['content'][0]['text'];
        $this->assertStringContainsString('1', $texto);
        $this->assertStringContainsString('60', $texto);

        // Control positivo: el límite dentro del rango anda.
        $this->datos_de_herramienta($this->token, 'list_missions', ['limite' => 60]);
    }
}
