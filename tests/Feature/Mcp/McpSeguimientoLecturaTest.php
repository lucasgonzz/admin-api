<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Missions\MissionFixture;
use Tests\TestCase;

/**
 * CA10 de la misión dotin-misiones-admin: el seguimiento del Admin llega a Dotin por las herramientas de LECTURA
 * del conector (`list_missions`, `get_mission`, `get_request_status`), con el mismo token de consulta de siempre.
 *
 * Escena (reloj 26/11/2026 12:00 ART): el reporte del CA6 de `McpFixture` (misiones `cc:a`..`cc:d`, cola `e`) más
 * filas de seguimiento publicadas por el endpoint real de la Gamer:
 *  - `cc:a`: activa al día (está en la escena) · `cc:c`: esperando decisión con una pregunta ordinaria (está en la escena);
 *  - `cc:solo`: esperando decisión, SÓLO en el Admin, de otro pedido · `cc:viejo`: activa, reportada hace 30 min y sin
 *    actividad real reciente, SÓLO en el Admin · `cc:listo`: terminada, SÓLO en el Admin.
 * `progreso` de `get_request_status` no cambia de forma ni de valores (lo fija `McpHerramientasTest`).
 */
class McpSeguimientoLecturaTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;
    use MissionFixture;

    /** Pedido de la misión sólo-Admin. */
    const PEDIDO_SOLO = '20261126-1300-solo-del-admin';

    /** @var string `access_token` del admin dueño de la Agenda. */
    private $token;

    /** @var string uid de la pregunta de `cc:c`. */
    private $pregunta_de_c;

    /** @var string uid de la pregunta de `cc:solo`. */
    private $pregunta_de_solo;

    /**
     * Arma la escena del CA6, publica el seguimiento y saca un token de consulta.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Primero la clave de misiones y después la del reporte v2 (que gana): `misiones_headers()` lee la vigente.
        $this->misiones_preparar();
        $this->preparar_mcp();

        $admin      = $this->admin_de_mcp();
        $otro_admin = $this->admin_de_mcp();
        $this->armar_escena_del_ca6($admin, $otro_admin);

        // `cc:a`: activa y al día, con actividad real reciente.
        $this->misiones_put('a', [
            'estado' => 'activa', 'fase' => 'construccion', 'ejecucion_n' => 1, 'pedido_id' => null,
            'ultima_actividad_real_at' => $this->iso_ar('2026-11-26 11:58:00'),
        ])->assertStatus(200);

        // `cc:c`: esperando decisión, del pedido de prueba, con pregunta ordinaria.
        $this->pregunta_de_c = $this->misiones_con_pregunta('c', 'ordinaria', ['pedido_id' => self::$pedido_de_prueba, 'ultimo_avance' => 'Esperando tu decisión']);

        // `cc:solo`: sólo en el Admin, de otro pedido, con pregunta.
        $this->pregunta_de_solo = $this->misiones_con_pregunta('solo', 'ordinaria', ['pedido_id' => self::PEDIDO_SOLO, 'titulo' => 'Misión sólo del Admin']);

        // `cc:viejo`: reportó a las 11:30 (30 min) y su última actividad real fue a las 11:29: desactualizada y activa sin señal.
        $this->misiones_put('viejo', [
            'estado' => 'activa', 'fase' => 'plan', 'ejecucion_n' => 1, 'pedido_id' => null,
            'ultima_actividad_real_at' => $this->iso_ar('2026-11-26 11:29:00'),
        ], '2026-11-26 11:30:00')->assertStatus(200);

        // `cc:listo`: terminada.
        $this->misiones_put('listo', ['estado' => 'terminada', 'evidencia' => $this->misiones_evidencia(), 'pedido_id' => null], '2026-11-26 11:45:00')->assertStatus(200);

        $this->misiones_congelar('2026-11-26 12:00:00');
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
     * Los ids de una lista de misiones, ordenados.
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
     * `list_missions` agrega `seguimiento` a las misiones con fila y las que están SÓLO en el Admin al final (con
     * `fuente = admin` y el estado legacy por el mapa del plan); las terminadas sólo si se piden.
     *
     * @return void
     */
    public function test_list_missions_con_seguimiento_y_solo_admin(): void
    {
        $lista = $this->datos_de_herramienta($this->token, 'list_missions');
        $this->assertSame(['cc:a', 'cc:b', 'cc:c', 'cc:solo', 'cc:viejo'], $this->ids_de($lista['misiones']), 'Las terminadas no salen por defecto.');

        $por_id = [];
        foreach ($lista['misiones'] as $mision) {
            $por_id[$mision['id']] = $mision;
        }

        // Con fila: seguimiento; sin fila: la misma forma de hoy.
        $this->assertSame('activa', $por_id['cc:a']['seguimiento']['estado']);
        $this->assertSame('al_dia', $por_id['cc:a']['seguimiento']['vigencia']);
        $this->assertSame('esperando_decision', $por_id['cc:c']['seguimiento']['estado']);
        $this->assertArrayNotHasKey('seguimiento', $por_id['cc:b']);
        $this->assertSame('esperando_lucas', $por_id['cc:c']['estado'], 'El estado legacy de la escena no cambia.');

        // Sólo-Admin: al final, con fuente y estado legacy mapeado.
        $this->assertSame('admin', $por_id['cc:solo']['fuente']);
        $this->assertSame('esperando_lucas', $por_id['cc:solo']['estado']);
        $this->assertSame('esperando_decision', $por_id['cc:solo']['seguimiento']['estado']);
        $this->assertSame('Misión sólo del Admin', $por_id['cc:solo']['titulo']);
        $this->assertSame(self::PEDIDO_SOLO, $por_id['cc:solo']['pedido_id']);
        $this->assertSame('trabajando', $por_id['cc:viejo']['estado']);
        $this->assertSame('desactualizada', $por_id['cc:viejo']['seguimiento']['vigencia']);
        $ultimas = array_slice(array_column($lista['misiones'], 'id'), -2);
        sort($ultimas);
        $this->assertSame(['cc:solo', 'cc:viejo'], $ultimas, 'Las sólo-Admin van agregadas al final.');

        // Con `incluir_terminadas` aparece la terminada sólo-Admin, con su estado legacy.
        $con_terminadas = $this->datos_de_herramienta($this->token, 'list_missions', ['incluir_terminadas' => true]);
        $listas = [];
        foreach ($con_terminadas['misiones'] as $mision) {
            $listas[$mision['id']] = $mision;
        }
        $this->assertArrayHasKey('cc:listo', $listas);
        $this->assertSame('terminada', $listas['cc:listo']['estado']);
        $this->assertSame('terminada', $listas['cc:listo']['seguimiento']['estado']);
    }

    /**
     * El filtro nuevo `seguimiento`: por estado visible y `desactualizada` por vigencia; un valor inválido es error de herramienta.
     *
     * @return void
     */
    public function test_filtro_seguimiento(): void
    {
        $desactualizadas = $this->datos_de_herramienta($this->token, 'list_missions', ['seguimiento' => 'desactualizada']);
        $this->assertSame(['cc:viejo'], $this->ids_de($desactualizadas['misiones']));

        $esperando = $this->datos_de_herramienta($this->token, 'list_missions', ['seguimiento' => 'esperando_decision']);
        $this->assertSame(['cc:c', 'cc:solo'], $this->ids_de($esperando['misiones']));

        $activas = $this->datos_de_herramienta($this->token, 'list_missions', ['seguimiento' => 'activa']);
        $this->assertSame(['cc:a'], $this->ids_de($activas['misiones']), 'activa no incluye a la que figura «activa sin señal».');

        $sin_senal = $this->datos_de_herramienta($this->token, 'list_missions', ['seguimiento' => 'activa_sin_senal']);
        $this->assertSame(['cc:viejo'], $this->ids_de($sin_senal['misiones']));

        $terminadas = $this->datos_de_herramienta($this->token, 'list_missions', ['seguimiento' => 'terminada']);
        $this->assertSame(['cc:listo'], $this->ids_de($terminadas['misiones']), 'Pedirlas por seguimiento las incluye.');

        $invalido = $this->llamar_herramienta($this->token, 'list_missions', ['seguimiento' => 'inventado']);
        $this->assertTrue($invalido['isError']);
    }

    /**
     * `get_mission`: la pregunta con sus opciones, el impacto, la recomendada y `puede_responder`; la sólo-Admin se arma desde
     * la fila; una que no existe da error.
     *
     * @return void
     */
    public function test_get_mission_pregunta_con_impacto(): void
    {
        $solo = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'solo']);
        $this->assertSame('cc:solo', $solo['id']);
        $this->assertSame('admin', $solo['fuente']);

        $seguimiento = $solo['seguimiento'];
        $this->assertSame('esperando_decision', $seguimiento['estado']);
        $this->assertArrayHasKey('eventos', $seguimiento, 'El detalle trae los últimos eventos.');
        $this->assertLessThanOrEqual(10, count($seguimiento['eventos']));

        $pregunta = $seguimiento['pregunta'];
        $this->assertSame($this->pregunta_de_solo, $pregunta['question_uid']);
        $this->assertSame(1, $pregunta['revision']);
        $this->assertSame('ordinaria', $pregunta['tipo']);
        $this->assertSame('abierta', $pregunta['estado']);
        $this->assertSame('A', $pregunta['recomendada']);
        $this->assertTrue($pregunta['puede_responder']);
        $this->assertCount(2, $pregunta['opciones']);
        $this->assertSame('A', $pregunta['opciones'][0]['clave']);
        $this->assertSame('La misión sigue sola con su recomendación', $pregunta['opciones'][0]['impacto']);
        $this->assertNull($pregunta['respuesta']);

        // Una misión de la escena con fila: lo de siempre más el seguimiento.
        $de_c = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'cc:c']);
        $this->assertSame('esperando_lucas', $de_c['estado']);
        $this->assertSame($this->pregunta_de_c, $de_c['seguimiento']['pregunta']['question_uid']);
        $this->assertArrayHasKey('fase_historial', $de_c);

        // Una de la escena SIN fila: igual que hoy, sin seguimiento.
        $de_b = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'b']);
        $this->assertArrayNotHasKey('seguimiento', $de_b);
        $this->assertTrue($de_b['demorada']);

        $inexistente = $this->llamar_herramienta($this->token, 'get_mission', ['id' => 'no-existe']);
        $this->assertTrue($inexistente['isError']);
    }

    /**
     * Una pregunta sensible se muestra pero `puede_responder` es falso; una ya respondida tampoco se puede responder de nuevo.
     *
     * @return void
     */
    public function test_pregunta_sensible_no_se_puede_responder(): void
    {
        $sensible = $this->misiones_con_pregunta('delicada', 'sensible');
        $this->misiones_sembrar_respuesta('solo', $this->pregunta_de_solo);

        $delicada = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'delicada']);
        $this->assertSame($sensible, $delicada['seguimiento']['pregunta']['question_uid']);
        $this->assertSame('sensible', $delicada['seguimiento']['pregunta']['tipo']);
        $this->assertFalse($delicada['seguimiento']['pregunta']['puede_responder']);

        $respondida = $this->datos_de_herramienta($this->token, 'get_mission', ['id' => 'solo'])['seguimiento']['pregunta'];
        $this->assertSame('respondida', $respondida['estado']);
        $this->assertFalse($respondida['puede_responder']);
        $this->assertSame('registrada', $respondida['respuesta']['estado']);
        $this->assertSame('A', $respondida['respuesta']['opcion']);
    }

    /**
     * `get_request_status`: `progreso` idéntico al de hoy (sólo se agregan claves hermanas) y el seguimiento y las preguntas abiertas aparte.
     *
     * @return void
     */
    public function test_request_status_progreso_intacto_y_seguimiento(): void
    {
        $estado = $this->datos_de_herramienta($this->token, 'get_request_status', ['pedido_id' => self::$pedido_de_prueba]);

        // El mismo `progreso` que fija McpHerramientasTest::test_estado_de_pedido.
        $this->assertSame([
            'total' => 4, 'terminadas' => 1, 'trabajando' => 0, 'esperando_lucas' => 1, 'trabadas' => 0,
            'en_cola' => 1, 'agenda_pendientes' => 1, 'agenda_hechas' => 0,
        ], $estado['progreso']);

        $claves = ['en_cola', 'activa', 'activa_sin_senal', 'pausada_tecnica', 'esperando_decision', 'terminada', 'detenida', 'desactualizadas'];
        $this->assertEqualsCanonicalizing($claves, array_keys($estado['progreso_seguimiento']));
        $this->assertSame(1, $estado['progreso_seguimiento']['esperando_decision']);
        $this->assertSame(0, $estado['progreso_seguimiento']['desactualizadas']);

        $this->assertCount(1, $estado['preguntas_abiertas']);
        $abierta = $estado['preguntas_abiertas'][0];
        $this->assertSame('cc:c', $abierta['mission_id']);
        $this->assertSame($this->pregunta_de_c, $abierta['question_uid']);
        $this->assertSame(1, $abierta['revision']);
        $this->assertSame('ordinaria', $abierta['tipo']);
        $this->assertSame('A', $abierta['recomendada']);
        $this->assertTrue($abierta['puede_responder']);
        $this->assertCount(2, $abierta['opciones']);
        foreach (['mission_id', 'question_uid', 'revision', 'tipo', 'pregunta', 'opciones', 'recomendada', 'puede_responder'] as $clave) {
            $this->assertArrayHasKey($clave, $abierta);
        }

        // Un pedido que sólo existe en el Admin: la misión se suma a `misiones` y cuenta en el seguimiento.
        $del_admin = $this->datos_de_herramienta($this->token, 'get_request_status', ['pedido_id' => self::PEDIDO_SOLO]);
        $this->assertSame(['cc:solo'], array_column($del_admin['misiones'], 'id'));
        $this->assertSame(1, $del_admin['progreso_seguimiento']['esperando_decision']);
        $this->assertSame($this->pregunta_de_solo, $del_admin['preguntas_abiertas'][0]['question_uid']);

        // Sin pedido sigue listando los pedidos vistos.
        $pedidos = $this->datos_de_herramienta($this->token, 'get_request_status');
        $this->assertContains(self::$pedido_de_prueba, array_column($pedidos['pedidos'], 'pedido_id'));
    }

    /**
     * Nada de lo que sale por la lectura lleva el texto de una respuesta ni el pedido literal: sólo el estado de la respuesta.
     *
     * @return void
     */
    public function test_la_lectura_no_filtra_el_texto_de_la_respuesta(): void
    {
        $this->misiones_sembrar_respuesta('solo', $this->pregunta_de_solo, ['opcion' => null, 'texto' => 'texto-privado-de-lucas-123']);

        $detalle = $this->llamar_herramienta($this->token, 'get_mission', ['id' => 'solo']);
        $lista   = $this->llamar_herramienta($this->token, 'list_missions');
        $estado  = $this->llamar_herramienta($this->token, 'get_request_status', ['pedido_id' => self::PEDIDO_SOLO]);

        // La respuesta es de Lucas y Dotin la registra, así que el detalle puede mostrar su estado y su canal...
        $this->assertSame('registrada', $detalle['structuredContent']['seguimiento']['pregunta']['respuesta']['estado']);
        // ...pero el texto libre no viaja en listas ni en el avance de un pedido.
        $this->assertStringNotContainsString('texto-privado-de-lucas-123', json_encode($lista));
        $this->assertStringNotContainsString('texto-privado-de-lucas-123', json_encode($estado));
    }
}
