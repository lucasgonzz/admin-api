<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Services\DemoHitosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/admin/lead/{id}/demo-roadmap` — los cinco campos de detalle del recorrido que se
 * agregaron el 1/9/2026 (`visto`, `porcentaje_visto`, `tour_iniciado`, `probado`,
 * `porcentaje_tour`).
 *
 * Pedido de Lucas: *"quiero que cuando ya vio el video (...) me aparezca la información de que lo
 * vio y la información de si lo probó. Y en el caso de que no haya terminado de verlo, que me
 * aparezca el porcentaje"*. El dato ya estaba guardado en `demo_eventos_recibidos` desde la misión
 * 48 y no lo leía nadie.
 *
 * Va en un archivo aparte de `DemoRoadmapEndpointTest` a propósito: aquél verifica el contrato
 * viejo del payload y tiene que poder seguir corriendo verde sin enterarse de esta misión. Si los
 * dos vivieran juntos, un cambio acá que rompiera el contrato viejo se leería como un archivo con
 * rojos y no como lo que sería.
 *
 * 🔴 Los tres casos que este archivo existe para cubrir, y que son los que se pagan caro:
 *   1. Una `empresa` VIEJA no emite `clip.progreso` ni `tour.completado`. Los campos tienen que
 *      caer al comportamiento de siempre y nunca dar `null` ni dividir por cero.
 *   2. `datos` es json libre que entró desde el navegador de un lead: absurdos, faltantes y tipos
 *      equivocados no pueden tirar un endpoint que se poléa cada diez segundos.
 *   3. El detalle se resuelve con UNA sola consulta más, no con una por hito.
 */
class DemoRoadmapDetalleDeRecorridoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin autenticado, igual que el resto del panel.
     *
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'roadmap-detalle-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Lead con la dinámica nueva y un plan congelado de dos secciones y tres clips de núcleo, con
     * sus hitos ya generados. Mismo andamiaje que `DemoRoadmapEndpointTest`.
     *
     * @return Lead
     */
    private function crear_lead_con_plan(): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Juana Pérez';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_ingreso_token = Str::random(64);
        $lead->demo_eventos_token = Str::random(64);

        $lead->demo_plan = [
            'version_catalogo' => 2,
            'resuelto_at'      => '2026-09-01 10:00:00',
            'respuestas'       => ['tipo_precios' => 'unico'],
            'secciones'        => [
                ['id' => 'S1 - Listado', 'orden' => 1, 'clips' => [
                    ['id' => '1.1', 'orden' => 1, 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true, 'evento_esperado' => 'articulo.creado'],
                    ['id' => '1.6', 'orden' => 2, 'titulo' => 'Actualizacion masiva', 'tipo' => 'nucleo', 'practica' => true, 'evento_esperado' => null],
                ]],
                ['id' => 'S2 - Vender', 'orden' => 2, 'clips' => [
                    ['id' => '2.1', 'orden' => 1, 'titulo' => 'Armar una venta', 'tipo' => 'nucleo', 'practica' => true, 'evento_esperado' => 'venta.creada'],
                ]],
            ],
            'condiciones_invalidas' => [],
            'totales'               => ['secciones' => 2, 'clips_nucleo' => 3, 'clips_biblioteca' => 0],
        ];
        $lead->demo_plan_congelado_at = now();

        $lead->save();

        DemoHitosService::generar($lead);

        return $lead;
    }

    /**
     * Escribe un evento crudo tal como lo dejaría `DemoEventosController::store_json()`.
     *
     * @param Lead        $lead
     * @param string      $nombre
     * @param string|null $clip_id
     * @param mixed       $datos   Se guarda tal cual, incluso si es basura: es justamente lo que
     *                             hay que poder probar.
     *
     * @return void
     */
    private function evento(Lead $lead, string $nombre, $clip_id, $datos): void
    {
        DemoEventoRecibido::create([
            'lead_id'     => $lead->id,
            'uuid'        => (string) Str::uuid(),
            'nombre'      => $nombre,
            'clip_id'     => $clip_id,
            'ocurrido_at' => now(),
            'datos'       => $datos,
        ]);
    }

    /**
     * El hito de un clip dentro del payload.
     *
     * @param \Illuminate\Testing\TestResponse $r
     * @param string                           $clip_id
     *
     * @return array<string, mixed>
     */
    private function hito_de(\Illuminate\Testing\TestResponse $r, string $clip_id): array
    {
        foreach ($r->json('hitos') as $hito) {
            if (isset($hito['clip_id']) && $hito['clip_id'] === $clip_id) {
                return $hito;
            }
        }

        $this->fail('No vino ningún hito del clip ' . $clip_id . ' en el payload.');
    }

    /**
     * Pega al endpoint autenticado.
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir_roadmap(Lead $lead)
    {
        return $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);
    }

    /**
     * 🔴 EL CASO DE LA `empresa` VIEJA, que es el que este endpoint tiene que sobrevivir durante
     * todo el tiempo que va del release de empresa al `/deploy-admin` (y son dos despliegues
     * independientes, así que nunca llegan juntos).
     *
     * Sin un solo evento nuevo en la tabla, los cinco campos tienen que estar, con los valores que
     * el admin ya sabía dar antes de esta misión: nada de `null`, nada de campos ausentes.
     */
    public function test_sin_ningun_evento_nuevo_los_cinco_campos_traen_los_defaults(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertFalse($hito['visto']);
        $this->assertSame(0, $hito['porcentaje_visto']);
        $this->assertFalse($hito['tour_iniciado']);
        $this->assertFalse($hito['probado']);
        $this->assertSame(0, $hito['porcentaje_tour']);

        // Y ninguno es null, que es la mitad del contrato: el panel los compara con `> 0`.
        foreach (['visto', 'porcentaje_visto', 'tour_iniciado', 'probado', 'porcentaje_tour'] as $campo) {
            $this->assertNotNull($hito[$campo], 'campo: ' . $campo);
        }
    }

    /**
     * La `empresa` vieja SÍ manda `clip.terminado`, que es lo que mueve el hito y escribe
     * `tutorial_visto_at`. Con eso solo, el porcentaje visto tiene que ser 100 — el comportamiento
     * exacto de antes de esta misión.
     */
    public function test_un_clip_terminado_sin_eventos_de_progreso_da_visto_y_cien_por_ciento(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        DemoHitosService::aplicar($lead, [
            'nombre'      => 'clip.terminado',
            'clip_id'     => '1.1',
            'ocurrido_at' => '2026-09-01 10:12:00',
        ]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertTrue($hito['visto']);
        $this->assertSame(100, $hito['porcentaje_visto']);

        // Y el estado del hito sigue siendo el que decidió DemoHitosService, sin tocar.
        $this->assertSame(LeadDemoHito::ESTADO_PARCIAL, $hito['estado']);
    }

    /**
     * Video a medias: se muestra el MÁXIMO de los `clip.progreso`, no el último que entró. Los
     * eventos no llegan ordenados (el emisor reintenta y la red reordena) y además el lead puede
     * retroceder el video; lo que interesa es hasta dónde llegó.
     */
    public function test_el_porcentaje_visto_es_el_maximo_de_los_clip_progreso(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 30]);
        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 70]);
        // Este entra ÚLTIMO y es más chico: si se tomara el último, acá daría 40.
        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 40]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertFalse($hito['visto']);
        $this->assertSame(70, $hito['porcentaje_visto']);
        // Y el hito sigue pendiente: un `clip.progreso` no mueve ningún estado.
        $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $hito['estado']);
    }

    /**
     * El hito visto manda sobre los eventos de progreso: si `clip.terminado` llegó, el lead vio el
     * video entero aunque el último progreso registrado diga 70.
     */
    public function test_el_hito_visto_manda_sobre_el_progreso_registrado(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 70]);
        DemoHitosService::aplicar($lead, ['nombre' => 'clip.terminado', 'clip_id' => '1.1', 'ocurrido_at' => '2026-09-01 10:12:00']);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertTrue($hito['visto']);
        $this->assertSame(100, $hito['porcentaje_visto']);
    }

    /**
     * Arrancó el tour y lo abandonó sin que llegara ningún `tour.completado`: se ve que empezó, y
     * nada más. Es la diferencia entre "no lo probó" y "lo probó y no le salió".
     */
    public function test_un_tour_iniciado_sin_completado_se_ve_como_empezado(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'tour.iniciado', '1.1', ['pasos' => 8]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertTrue($hito['tour_iniciado']);
        $this->assertFalse($hito['probado']);
        $this->assertSame(0, $hito['porcentaje_tour']);
    }

    /**
     * Tour terminado de verdad: `probado` y el porcentaje en 100, aunque el conteo de pasos dé
     * otra cosa (un paso que no encuentra su elemento se saltea, así que `mostrados` puede ser
     * menor que `pasos` en un tour que el motor considera completo).
     */
    public function test_un_tour_completado_marca_probado_y_cien_por_ciento(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'tour.iniciado', '1.1', ['pasos' => 8]);
        $this->evento($lead, 'tour.completado', '1.1', [
            'completo'  => true,
            'pasos'     => 8,
            'mostrados' => 7,
            'salteados' => 1,
        ]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertTrue($hito['tour_iniciado']);
        $this->assertTrue($hito['probado']);
        $this->assertSame(100, $hito['porcentaje_tour']);

        /* 🔴 Y el estado del hito NO se movió: `tour.completado` no lo declara ningún
         * `evento_esperado` del catálogo y esta misión no cambió eso. El detalle se agrega al
         * costado del estado, no lo reemplaza. */
        $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $hito['estado']);
    }

    /**
     * Tour cortado a mitad: `probado` en false y el porcentaje contado sobre los pasos mostrados.
     * 3 de 8 son 37,5, que redondea a 38.
     */
    public function test_un_tour_cortado_a_mitad_muestra_el_porcentaje_recorrido(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'tour.completado', '1.1', [
            'completo'  => false,
            'pasos'     => 8,
            'mostrados' => 3,
            'salteados' => 0,
        ]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertFalse($hito['probado']);
        $this->assertSame(38, $hito['porcentaje_tour']);
    }

    /**
     * Dos corridas del mismo tour: se muestra la mejor. El lead puede arrancar y cortar un tour
     * varias veces sobre el mismo clip, y lo que se quiere saber es hasta dónde llegó.
     */
    public function test_entre_dos_corridas_del_mismo_tour_gana_la_que_llego_mas_lejos(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'tour.completado', '1.1', ['completo' => false, 'pasos' => 10, 'mostrados' => 8]);
        $this->evento($lead, 'tour.completado', '1.1', ['completo' => false, 'pasos' => 10, 'mostrados' => 2]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertSame(80, $hito['porcentaje_tour']);
    }

    /**
     * `completo` se compara ESTRICTO contra `true`. Un `"true"` de string o un `1` no marcan
     * probado: marcarían como recorrido entero un tour que el lead abandonó, y ese es justo el
     * dato que el closer va a usar para hablar con él.
     */
    public function test_un_completo_que_no_es_booleano_verdadero_no_marca_probado(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'tour.completado', '1.1', ['completo' => 'true', 'pasos' => 4, 'mostrados' => 4]);
        $this->evento($lead, 'tour.completado', '1.6', ['completo' => 1, 'pasos' => 4, 'mostrados' => 4]);

        $r = $this->pedir_roadmap($lead);

        $this->assertFalse($this->hito_de($r, '1.1')['probado']);
        $this->assertFalse($this->hito_de($r, '1.6')['probado']);

        // El porcentaje sí se cuenta: los pasos son legibles y ese dato es verdadero igual.
        $this->assertSame(100, $this->hito_de($r, '1.1')['porcentaje_tour']);
    }

    /**
     * 🔴 `datos` es json libre que entró desde el navegador de un lead. Ninguna de estas formas
     * puede tirar el endpoint ni devolver `null`, y NINGUNA puede dividir por cero.
     *
     * Cada caso va sobre su propio lead para que un clip no arrastre el máximo de otro.
     *
     * @dataProvider datos_basura
     *
     * @param mixed $datos          Lo que se guarda en el evento.
     * @param int   $tour_esperado  Porcentaje de tour que tiene que salir.
     */
    public function test_datos_basura_no_rompen_el_endpoint($datos, int $tour_esperado): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'tour.completado', '1.1', $datos);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertSame($tour_esperado, $hito['porcentaje_tour']);
        $this->assertFalse($hito['probado']);
        $this->assertNotNull($hito['porcentaje_tour']);
    }

    /**
     * Las formas de `datos` que un navegador puede mandar y el endpoint tiene que aguantar.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public function datos_basura(): array
    {
        return [
            'pasos en cero (division por cero)' => [['completo' => false, 'pasos' => 0, 'mostrados' => 5], 0],
            'pasos negativo'                    => [['completo' => false, 'pasos' => -4, 'mostrados' => 2], 0],
            'pasos null'                        => [['completo' => false, 'pasos' => null, 'mostrados' => 2], 0],
            'pasos no numerico'                 => [['completo' => false, 'pasos' => 'ocho', 'mostrados' => 2], 0],
            'sin pasos ni mostrados'            => [['completo' => false], 0],
            'mostrados null'                    => [['completo' => false, 'pasos' => 8, 'mostrados' => null], 0],
            'mostrados negativo (clampea a 0)'  => [['completo' => false, 'pasos' => 8, 'mostrados' => -3], 0],
            'mostrados mayor que pasos'         => [['completo' => false, 'pasos' => 4, 'mostrados' => 99], 100],
            'numeros como string'               => [['completo' => false, 'pasos' => '8', 'mostrados' => '4'], 50],
            'datos vacio'                       => [[], 0],
            'datos null'                        => [null, 0],
            'datos escalar (json no-array)'     => [5, 0],
            'datos string'                      => ['tour', 0],
            'pasos como array'                  => [['completo' => false, 'pasos' => [8], 'mostrados' => 2], 0],
        ];
    }

    /**
     * Lo mismo para `clip.progreso`: absurdos clampeados a 0..100 y nunca `null`.
     */
    public function test_un_porcentaje_absurdo_se_clampea_entre_cero_y_cien(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 5000]);
        $this->evento($lead, 'clip.progreso', '1.6', ['porcentaje' => -80]);
        $this->evento($lead, 'clip.progreso', '2.1', ['porcentaje' => 'mucho']);

        $r = $this->pedir_roadmap($lead);

        $this->assertSame(100, $this->hito_de($r, '1.1')['porcentaje_visto']);
        $this->assertSame(0, $this->hito_de($r, '1.6')['porcentaje_visto']);
        $this->assertSame(0, $this->hito_de($r, '2.1')['porcentaje_visto']);
    }

    /**
     * Un porcentaje con decimales redondea, y llega como entero al panel (que lo imprime tal cual
     * detrás de un `%`).
     */
    public function test_un_porcentaje_con_decimales_llega_redondeado_y_entero(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 66.7]);

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        $this->assertSame(67, $hito['porcentaje_visto']);
        $this->assertIsInt($hito['porcentaje_visto']);
    }

    /**
     * Los eventos de un clip no se mezclan con los de otro. Parece obvio y es lo que rompe si el
     * agrupado en PHP se hiciera por el orden de las filas en vez de por `clip_id`.
     */
    public function test_el_detalle_de_un_clip_no_se_mezcla_con_el_de_otro(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $this->evento($lead, 'clip.progreso', '1.1', ['porcentaje' => 90]);
        $this->evento($lead, 'tour.completado', '2.1', ['completo' => true, 'pasos' => 5, 'mostrados' => 5]);

        $r = $this->pedir_roadmap($lead);

        $uno_uno = $this->hito_de($r, '1.1');
        $dos_uno = $this->hito_de($r, '2.1');

        $this->assertSame(90, $uno_uno['porcentaje_visto']);
        $this->assertFalse($uno_uno['probado']);

        $this->assertSame(0, $dos_uno['porcentaje_visto']);
        $this->assertTrue($dos_uno['probado']);

        // Y el clip del medio, sin ningún evento, queda en cero por todos lados.
        $this->assertSame(0, $this->hito_de($r, '1.6')['porcentaje_visto']);
        $this->assertFalse($this->hito_de($r, '1.6')['tour_iniciado']);
    }

    /**
     * El hito de ingreso NO lleva ninguno de los cinco campos: no tiene video ni tour, y un
     * "Visto 0%" ahí no sería un cero, sería afirmar que hay algo que ver.
     */
    public function test_el_hito_de_ingreso_no_lleva_los_campos_de_detalle(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $ingreso = $this->pedir_roadmap($lead)->json('hitos.0');

        $this->assertSame(LeadDemoHito::TIPO_INGRESO, $ingreso['tipo']);

        foreach (['visto', 'porcentaje_visto', 'tour_iniciado', 'probado', 'porcentaje_tour'] as $campo) {
            $this->assertArrayNotHasKey($campo, $ingreso, 'campo: ' . $campo);
        }
    }

    /**
     * Los campos que ya existían siguen exactamente donde estaban. Es la mitad del contrato hacia
     * atrás: el `admin-spa` viejo puede quedar cacheado en el navegador después de un
     * `/deploy-admin` y tiene que seguir dibujando igual.
     */
    public function test_los_campos_viejos_del_payload_no_cambiaron(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        $hito = $this->hito_de($this->pedir_roadmap($lead), '1.1');

        foreach (['orden', 'tipo', 'seccion', 'clip_id', 'titulo', 'estado', 'evento_esperado', 'tutorial_visto_at', 'accion_hecha_at'] as $campo) {
            $this->assertArrayHasKey($campo, $hito, 'campo: ' . $campo);
        }

        $this->assertSame('tutorial', $hito['tipo']);
        $this->assertSame('S1 - Listado', $hito['seccion']);
        $this->assertSame('articulo.creado', $hito['evento_esperado']);
    }

    /**
     * 🔴 El detalle cuesta UNA consulta más, no una por hito.
     *
     * Este endpoint se poléa cada diez segundos por cada lead abierto (540 veces por lead y por
     * sesión). Con tres clips y ~15 eventos, una implementación por hito daría 5 y con un plan
     * real serían ~20 cada diez segundos. Se cuentan sólo las tablas del endpoint: las de Sanctum
     * son del middleware y no son lo que esta misión puede empeorar.
     */
    public function test_el_endpoint_hace_tres_consultas_sin_importar_cuantos_eventos_haya(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_con_plan();

        // Un lead que miró toda la demo: varios progresos y varias corridas de tour por clip.
        foreach (['1.1', '1.6', '2.1'] as $clip) {
            foreach ([10, 20, 30, 40, 50] as $pct) {
                $this->evento($lead, 'clip.progreso', $clip, ['porcentaje' => $pct]);
            }
            $this->evento($lead, 'tour.iniciado', $clip, ['pasos' => 6]);
            $this->evento($lead, 'tour.completado', $clip, ['completo' => true, 'pasos' => 6, 'mostrados' => 6]);
        }

        $consultas = [];

        DB::listen(function ($query) use (&$consultas) {
            foreach (['leads', 'lead_demo_hitos', 'demo_eventos_recibidos'] as $tabla) {
                if (strpos($query->sql, '`' . $tabla . '`') !== false) {
                    $consultas[] = $tabla;

                    return;
                }
            }
        });

        $this->pedir_roadmap($lead);

        $this->assertSame(
            ['leads', 'lead_demo_hitos', 'demo_eventos_recibidos'],
            $consultas,
            'El endpoint tiene que hacer exactamente tres consultas propias, en ese orden. Salió: '
                . implode(', ', $consultas)
        );
    }

    /**
     * Y con un lead SIN plan no se consultan los eventos siquiera: es el estado normal de casi
     * todos los leads y el que más se abre desde el panel, así que ahí el endpoint tiene que
     * seguir costando exactamente lo mismo que antes de esta misión.
     */
    public function test_un_lead_sin_hitos_no_paga_la_consulta_de_eventos(): void
    {
        $this->autenticar();

        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Sin plan';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_ingreso_token = Str::random(64);
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        $consultas = [];

        DB::listen(function ($query) use (&$consultas) {
            if (strpos($query->sql, '`demo_eventos_recibidos`') !== false) {
                $consultas[] = $query->sql;
            }
        });

        $this->pedir_roadmap($lead);

        $this->assertSame([], $consultas, 'Un lead sin hitos no tiene por qué consultar eventos.');
    }
}
