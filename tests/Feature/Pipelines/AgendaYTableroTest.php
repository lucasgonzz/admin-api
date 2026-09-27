<?php

namespace Tests\Feature\Pipelines;

use App\Models\Admin;
use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;

/**
 * El tablero (endpoint #10) y la agenda (#14): baldes, filtros, resumen, orden y última actividad.
 *
 * Lo que se protege:
 *  1. 🔴 Los baldes con el reloj clavado, incluido el caso borde: una próxima acción de FECHA sola
 *     (00:00) de hoy es "hoy", no "vencida"; el día 7 es "semana" y el 8 es "más adelante".
 *  2. 🔴 Que el filtro, el resumen y la vista Agenda cuenten lo mismo (los tres preguntan al mismo
 *     helper).
 *  3. Que el resumen ignore la búsqueda y los demás filtros de la vista, salvo responsable y tipo
 *     de sujeto (el embudo no se achica al buscar un nombre).
 *  4. Que la última actividad salga bien y sin N+1.
 */
class AgendaYTableroTest extends BaseDePipelines
{
    /**
     * @var Admin
     */
    private $lucas;

    /**
     * @var Admin
     */
    private $thomas;

    /**
     * @var Pipeline
     */
    private $pipeline;

    /**
     * Ids de las oportunidades del escenario, por letra.
     *
     * @var array<string, int>
     */
    private $ids = [];

    /**
     * El escenario (con el reloj en el 27/9/2026 10:00):
     *
     *   A cliente  Lucas   próxima 26/9 18:00        → overdue
     *   B cliente  Lucas   próxima 27/9 (sin hora)   → today (00:00 de hoy NO es vencida)
     *   C lead     Lucas   próxima 27/9 08:00        → today (ya pasó la hora, pero es hoy)
     *   D cliente  Lucas   próxima 28/9 09:00        → week  (y está en "Contactado")
     *   E cliente  Thomas  próxima 4/10 23:59        → week  (hoy + 7, último día de la semana)
     *   F cliente  Thomas  próxima 5/10 00:00        → later (el día 8)
     *   G lead     Thomas  sin próxima               → none
     *   H cliente  Lucas   Ganado                    → closed
     *   I cliente  Lucas   Perdido "Precio"          → closed
     *   J cliente  Lucas   Perdido "Precio"          → closed
     *   K lead     Lucas   Perdido "No lo necesita"  → closed
     *
     * Los cierres se hacen a horas distintas (para el orden secundario del tablero) y al final el
     * reloj queda en las 11:00 del mismo día.
     *
     * @return void
     */
    private function armar_escenario()
    {
        $this->lucas    = $this->admin_logueado('Lucas');
        $this->thomas   = $this->crear_admin('Thomas');
        $this->pipeline = $this->crear_pipeline();

        $sujetos = [
            'A' => $this->crear_cliente(['name' => 'Alfa Tablero']),
            'B' => $this->crear_cliente(['name' => 'Bravo Tablero']),
            'C' => $this->crear_lead(['company_name' => 'Charlie Kiosco', 'contact_name' => 'Carla']),
            'D' => $this->crear_cliente(['name' => 'Delta Tablero']),
            'E' => $this->crear_cliente(['name' => 'Eco Tablero']),
            'F' => $this->crear_cliente(['name' => 'Foxtrot Tablero']),
            'G' => $this->crear_lead(['company_name' => '', 'contact_name' => 'Gustavo Golf']),
            'H' => $this->crear_cliente(['name' => 'Hotel Tablero']),
            'I' => $this->crear_cliente(['name' => 'India Tablero']),
            'J' => $this->crear_cliente(['name' => 'Juliett Tablero']),
            'K' => $this->crear_lead(['company_name' => 'Kilo Almacén', 'contact_name' => 'Karina']),
        ];

        $de_lucas  = ['A', 'B', 'C', 'D', 'H', 'I', 'J', 'K'];
        $de_thomas = ['E', 'F', 'G'];

        foreach ([[$de_lucas, $this->lucas], [$de_thomas, $this->thomas]] as $grupo) {
            list($letras, $owner) = $grupo;

            $subjects = [];
            foreach ($letras as $letra) {
                $sujeto     = $sujetos[$letra];
                $subjects[] = ['type' => $sujeto instanceof \App\Models\Client ? 'client' : 'lead', 'id' => $sujeto->id];
            }

            $creadas = $this->alta($this->pipeline, $subjects, ['owner_admin_id' => $owner->id])->assertStatus(201)->json('created');
            foreach ($letras as $indice => $letra) {
                $this->ids[$letra] = $creadas[$indice]['id'];
            }
        }

        $proximas = [
            'A' => '2026-09-26 18:00',
            'B' => '2026-09-27',
            'C' => '2026-09-27 08:00',
            'D' => '2026-09-28 09:00',
            'E' => '2026-10-04 23:59',
            'F' => '2026-10-05 00:00',
        ];
        foreach ($proximas as $letra => $fecha) {
            $this->putJson('/api/admin/pipeline-opportunities/' . $this->ids[$letra], ['next_action_at' => $fecha])->assertStatus(200);
        }

        $this->mover($this->ids['D'], ['stage_id' => $this->etapa($this->pipeline, 'Contactado')->id, 'fields' => ['canal' => 'Llamada']])
            ->assertStatus(200);

        $perdido = $this->etapa($this->pipeline, 'Perdido')->id;

        $this->clavar_reloj('2026-09-27 10:10:00');
        $this->mover($this->ids['K'], ['stage_id' => $perdido, 'lost_reason' => 'No lo necesita'])->assertStatus(200);
        $this->clavar_reloj('2026-09-27 10:20:00');
        $this->mover($this->ids['I'], ['stage_id' => $perdido, 'lost_reason' => 'Precio'])->assertStatus(200);
        $this->clavar_reloj('2026-09-27 10:30:00');
        $this->mover($this->ids['H'], ['stage_id' => $this->etapa($this->pipeline, 'Ganado')->id, 'fields' => ['paquete' => 'Pro']])->assertStatus(200);
        $this->clavar_reloj('2026-09-27 10:40:00');
        $this->mover($this->ids['J'], ['stage_id' => $perdido, 'lost_reason' => 'Precio'])->assertStatus(200);

        $this->clavar_reloj('2026-09-27 11:00:00');
    }

    /**
     * El tablero del pipeline del escenario, con una query string.
     *
     * @param string $query
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function tablero($query = '')
    {
        return $this->getJson('/api/admin/pipelines/' . $this->pipeline->id . '/opportunities' . ($query !== '' ? '?' . $query : ''));
    }

    /**
     * Letras del escenario en el orden en que vinieron.
     *
     * @param array<int, array<string, mixed>> $oportunidades
     *
     * @return array<int, string>
     */
    private function letras(array $oportunidades)
    {
        $por_id = array_flip($this->ids);
        $letras = [];
        foreach ($oportunidades as $op) {
            $letras[] = isset($por_id[$op['id']]) ? $por_id[$op['id']] : '?';
        }

        return $letras;
    }

    /**
     * 1. 🔴 Los baldes con el reloj clavado, incluidos los bordes.
     *
     * @return void
     */
    public function test_baldes_de_agenda_con_el_reloj_clavado(): void
    {
        $this->armar_escenario();

        $baldes = [];
        foreach ($this->tablero()->assertStatus(200)->json('opportunities') as $op) {
            $baldes[array_flip($this->ids)[$op['id']]] = $op['agenda_bucket'];
        }
        ksort($baldes);

        $this->assertSame([
            'A' => 'overdue',
            'B' => 'today',
            'C' => 'today',
            'D' => 'week',
            'E' => 'week',
            'F' => 'later',
            'G' => 'none',
            'H' => 'closed',
            'I' => 'closed',
            'J' => 'closed',
            'K' => 'closed',
        ], $baldes);
    }

    /**
     * 2. La vista Agenda: solo abiertas de pipelines no archivados, en sus cinco baldes y en orden
     *    de próxima acción, cada una con `pipeline: {id, name}`. Filtra por responsable y pipeline.
     *
     * @return void
     */
    public function test_vista_agenda(): void
    {
        $this->armar_escenario();

        /* Otro pipeline, archivado, con una vencida: no aparece. */
        $archivado = $this->crear_pipeline();
        $vieja     = $this->alta_de_uno($archivado, $this->crear_cliente());
        $this->putJson('/api/admin/pipeline-opportunities/' . $vieja['id'], ['next_action_at' => '2026-09-20'])->assertStatus(200);
        $this->putJson('/api/admin/pipelines/' . $archivado->id, ['archived' => true])->assertStatus(200);

        $agenda = $this->getJson('/api/admin/pipeline-opportunities/agenda?pipeline_id=' . $this->pipeline->id)->assertStatus(200);

        $this->assertSame(['overdue', 'today', 'week', 'later', 'none'], array_keys($agenda->json()));
        $this->assertSame(['A'], $this->letras($agenda->json('overdue')));
        $this->assertSame(['B', 'C'], $this->letras($agenda->json('today')));
        $this->assertSame(['D', 'E'], $this->letras($agenda->json('week')));
        $this->assertSame(['F'], $this->letras($agenda->json('later')));
        $this->assertSame(['G'], $this->letras($agenda->json('none')));
        $this->assertSame(['id' => $this->pipeline->id, 'name' => $this->pipeline->name], $agenda->json('overdue.0.pipeline'));

        /* Sin filtro de pipeline, la vencida del archivado tampoco está. */
        $todas = $this->getJson('/api/admin/pipeline-opportunities/agenda')->assertStatus(200);
        $this->assertNotContains($vieja['id'], array_column($todas->json('overdue'), 'id'));

        /* Por responsable. */
        $de_thomas = $this->getJson('/api/admin/pipeline-opportunities/agenda?owner_admin_id=' . $this->thomas->id)->assertStatus(200);
        $this->assertSame([], $de_thomas->json('overdue'));
        $this->assertSame([], $de_thomas->json('today'));
        $this->assertSame(['E'], $this->letras($de_thomas->json('week')));
        $this->assertSame(['F'], $this->letras($de_thomas->json('later')));
        $this->assertSame(['G'], $this->letras($de_thomas->json('none')));
    }

    /**
     * 3. Los filtros del tablero: estado, tipo de sujeto, responsable, búsqueda, agenda y etapa.
     *
     * @return void
     */
    public function test_filtros_del_tablero(): void
    {
        $this->armar_escenario();

        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G'], $this->letras($this->tablero('estado=abiertas')->json('opportunities')));
        $this->assertSame(['H'], $this->letras($this->tablero('estado=ganadas')->json('opportunities')));
        $this->assertSame(['K', 'I', 'J'], $this->letras($this->tablero('estado=perdidas')->json('opportunities')));
        $this->assertCount(11, $this->tablero('estado=todas')->json('opportunities'));

        $this->assertSame(['C', 'G', 'K'], $this->letras($this->tablero('subject_type=lead')->json('opportunities')));
        $this->assertSame(['E', 'F', 'G'], $this->letras($this->tablero('owner_admin_id=' . $this->thomas->id)->json('opportunities')));

        /* Búsqueda por nombre del sujeto: cliente por nombre, lead por empresa o por contacto. */
        $this->assertSame(['D'], $this->letras($this->tablero('q=delta')->json('opportunities')));
        $this->assertSame(['C'], $this->letras($this->tablero('q=Charlie')->json('opportunities')));
        $this->assertSame(['G'], $this->letras($this->tablero('q=Gustavo')->json('opportunities')));
        $this->assertSame(['K'], $this->letras($this->tablero('q=Karina')->json('opportunities')));

        $this->assertSame(['B', 'C'], $this->letras($this->tablero('agenda=today')->json('opportunities')));
        $this->assertSame(['A'], $this->letras($this->tablero('agenda=overdue')->json('opportunities')));
        $this->assertSame(['G'], $this->letras($this->tablero('agenda=none')->json('opportunities')));

        $this->assertSame(['D'], $this->letras($this->tablero('stage_id=' . $this->etapa($this->pipeline, 'Contactado')->id)->json('opportunities')));

        /* Filtros combinados. */
        $this->assertSame(['E'], $this->letras($this->tablero('owner_admin_id=' . $this->thomas->id . '&agenda=week')->json('opportunities')));

        /* Un filtro con valor inválido es 422, no una lista plausible. */
        $this->tablero('estado=cerradas')->assertStatus(422);
        $this->tablero('agenda=later')->assertStatus(422);
    }

    /**
     * 4. El resumen: embudo por etapa (`ahora` y `pasaron`), abiertas / ganadas / perdidas,
     *    motivos y agenda. La búsqueda y los demás filtros de la vista NO lo cambian; el
     *    responsable y el tipo de sujeto, sí.
     *
     * @return void
     */
    public function test_resumen_del_tablero(): void
    {
        $this->armar_escenario();

        $resumen = $this->tablero()->assertStatus(200)->json('resumen');

        $this->assertSame(7, $resumen['abiertas']);
        $this->assertSame(1, $resumen['ganadas']);
        $this->assertSame(3, $resumen['perdidas']);
        $this->assertSame([
            ['motivo' => 'Precio', 'cantidad' => 2],
            ['motivo' => 'No lo necesita', 'cantidad' => 1],
        ], $resumen['motivos_perdida']);
        $this->assertSame(['overdue' => 1, 'today' => 2, 'week' => 2, 'none' => 1], $resumen['agenda']);

        $embudo = $this->embudo($resumen);
        $this->assertSame(['ahora' => 6, 'pasaron' => 11], $embudo['Por contactar']);
        $this->assertSame(['ahora' => 1, 'pasaron' => 1], $embudo['Contactado']);
        $this->assertSame(['ahora' => 0, 'pasaron' => 0], $embudo['Calificado']);
        $this->assertSame(['ahora' => 1, 'pasaron' => 1], $embudo['Ganado']);
        $this->assertSame(['ahora' => 3, 'pasaron' => 3], $embudo['Perdido']);
        $this->assertCount(count($this->etapas_de_prueba()), $resumen['por_etapa'], 'Todas las etapas, también las vacías.');

        /* La búsqueda, el estado, la agenda y la etapa achican la lista, no el resumen. */
        $filtrado = $this->tablero('q=delta&estado=abiertas&agenda=week&stage_id=' . $this->etapa($this->pipeline, 'Contactado')->id);
        $this->assertCount(1, $filtrado->json('opportunities'));
        $this->assertSame($resumen, $filtrado->json('resumen'));

        /* El responsable, sí. */
        $de_thomas = $this->tablero('owner_admin_id=' . $this->thomas->id)->json('resumen');
        $this->assertSame(3, $de_thomas['abiertas']);
        $this->assertSame(0, $de_thomas['perdidas']);
        $this->assertSame([], $de_thomas['motivos_perdida']);
        $this->assertSame(['overdue' => 0, 'today' => 0, 'week' => 1, 'none' => 1], $de_thomas['agenda']);
        $this->assertSame(['ahora' => 3, 'pasaron' => 3], $this->embudo($de_thomas)['Por contactar']);

        /* Y el tipo de sujeto, también. */
        $de_leads = $this->tablero('subject_type=lead')->json('resumen');
        $this->assertSame(2, $de_leads['abiertas']);
        $this->assertSame(1, $de_leads['perdidas']);
        $this->assertSame([['motivo' => 'No lo necesita', 'cantidad' => 1]], $de_leads['motivos_perdida']);
        $this->assertSame(['ahora' => 2, 'pasaron' => 3], $this->embudo($de_leads)['Por contactar']);
        $this->assertSame(['ahora' => 1, 'pasaron' => 1], $this->embudo($de_leads)['Perdido']);
    }

    /**
     * 5. Orden del tablero: próxima acción ascendente con las que no tienen al final; entre esas,
     *    las que llevan más tiempo en su etapa primero.
     *
     * @return void
     */
    public function test_orden_del_tablero(): void
    {
        $this->armar_escenario();

        /* G entró a su etapa a las 10:00; K a las 10:10, I a las 10:20, H a las 10:30, J a las 10:40. */
        $this->assertSame(
            ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'K', 'I', 'H', 'J'],
            $this->letras($this->tablero()->json('opportunities'))
        );
    }

    /**
     * 6. `last_activity`: la de mayor `occurred_at` (un cambio de etapa posterior le gana a una
     *    nota; una nota con fecha vieja no le gana al alta), y en UNA consulta para toda la lista:
     *    el tablero de 10 oportunidades hace las mismas consultas que el de 2.
     *
     * @return void
     */
    public function test_last_activity_correcta_y_sin_consultas_por_fila(): void
    {
        $this->admin_logueado('Lucas');
        $pipeline = $this->crear_pipeline();

        $movida = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $vieja  = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->clavar_reloj('2026-09-27 10:05:00');
        $this->postJson('/api/admin/pipeline-opportunities/' . $movida['id'] . '/notes', ['body' => 'Primero una nota'])->assertStatus(201);
        $this->postJson('/api/admin/pipeline-opportunities/' . $vieja['id'] . '/notes', [
            'body'        => 'Nota de la semana pasada',
            'occurred_at' => '2026-09-20 12:00',
        ])->assertStatus(201);

        $this->clavar_reloj('2026-09-27 10:10:00');
        $this->mover($movida['id'], ['stage_id' => $this->etapa($pipeline, 'Contactado')->id, 'fields' => ['canal' => 'WhatsApp']])->assertStatus(200);

        $url = '/api/admin/pipelines/' . $pipeline->id . '/opportunities';

        $por_id = [];
        foreach ($this->getJson($url)->assertStatus(200)->json('opportunities') as $op) {
            $por_id[$op['id']] = $op['last_activity'];
        }

        $this->assertSame('stage_change', $por_id[$movida['id']]['type']);
        $this->assertSame('2026-09-27 10:10:00', $por_id[$movida['id']]['occurred_at']);
        $this->assertSame('Lucas', $por_id[$movida['id']]['admin_name']);
        $this->assertSame('created', $por_id[$vieja['id']]['type']);

        /* Sin N+1: mismas consultas con 2 que con 10 oportunidades (todas de clientes). */
        $consultas_con_2 = $this->contar_consultas($url);
        $this->assertGreaterThan(3, $consultas_con_2, 'El log de consultas no midió nada: la comparación sería vacía.');

        $subjects = [];
        for ($i = 0; $i < 8; $i++) {
            $subjects[] = ['type' => 'client', 'id' => $this->crear_cliente()->id];
        }
        $creadas = $this->alta($pipeline, $subjects, ['note' => 'Tanda'])->assertStatus(201)->json('created');
        foreach (array_slice($creadas, 0, 3) as $op) {
            $this->postJson('/api/admin/pipeline-opportunities/' . $op['id'] . '/notes', ['body' => 'Nota'])->assertStatus(201);
        }

        $consultas_con_10 = $this->contar_consultas($url);

        $this->assertCount(10, $this->getJson($url)->json('opportunities'));
        $this->assertSame($consultas_con_2, $consultas_con_10, 'El tablero hace consultas por fila (N+1).');
    }

    /**
     * 8. `pasaron` cuenta oportunidades DISTINTAS: una que entra dos veces a la misma etapa (ida y
     *    vuelta) cuenta 1, no 2. Y la que volvió a "Por contactar" cuenta 1 ahí aunque haya entrado
     *    por el alta y por un movimiento.
     *
     * @return void
     */
    public function test_pasaron_cuenta_una_vez_aunque_entre_dos_veces(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $ida_y_vuelta = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $una_vez      = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $contactado = $this->etapa($pipeline, 'Contactado')->id;
        $calificado = $this->etapa($pipeline, 'Calificado')->id;

        $this->mover($ida_y_vuelta['id'], ['stage_id' => $contactado, 'fields' => ['canal' => 'WhatsApp']])->assertStatus(200);
        $this->mover($ida_y_vuelta['id'], ['stage_id' => $this->etapa($pipeline, 'Por contactar')->id])->assertStatus(200);
        $this->mover($ida_y_vuelta['id'], ['stage_id' => $contactado, 'fields' => ['canal' => 'Llamada']])->assertStatus(200);
        $this->mover($ida_y_vuelta['id'], ['stage_id' => $calificado, 'fields' => ['usa_sistema' => true]])->assertStatus(200);

        $this->mover($una_vez['id'], ['stage_id' => $contactado, 'fields' => ['canal' => 'Llamada']])->assertStatus(200);

        $nombres = [];
        foreach ($pipeline->stages as $etapa) {
            $nombres[$etapa->id] = $etapa->name;
        }

        $embudo = [];
        foreach ($this->getJson('/api/admin/pipelines/' . $pipeline->id . '/opportunities')->assertStatus(200)->json('resumen.por_etapa') as $fila) {
            $embudo[$nombres[$fila['stage_id']]] = ['ahora' => $fila['ahora'], 'pasaron' => $fila['pasaron']];
        }

        $this->assertSame(['ahora' => 0, 'pasaron' => 2], $embudo['Por contactar']);
        $this->assertSame(['ahora' => 1, 'pasaron' => 2], $embudo['Contactado'], 'La de ida y vuelta entró dos veces y cuenta una.');
        $this->assertSame(['ahora' => 1, 'pasaron' => 1], $embudo['Calificado']);
    }

    /**
     * 7. `days_in_stage`: días enteros entre la entrada a la etapa y ahora.
     *
     * @return void
     */
    public function test_dias_en_la_etapa(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $this->clavar_reloj('2026-09-20 09:00:00');
        $semana = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->clavar_reloj('2026-09-26 11:00:00');
        $ayer = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->clavar_reloj(self::HOY);

        $dias = [];
        foreach ($this->getJson('/api/admin/pipelines/' . $pipeline->id . '/opportunities')->assertStatus(200)->json('opportunities') as $op) {
            $dias[$op['id']] = $op['days_in_stage'];
        }

        $this->assertSame(7, $dias[$semana['id']]);
        $this->assertSame(0, $dias[$ayer['id']], 'Entró hace 23 horas: todavía no cumplió un día entero.');
    }

    /**
     * Cuántas consultas SQL hace un GET.
     *
     * @param string $url
     *
     * @return int
     */
    private function contar_consultas($url)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url)->assertStatus(200);

        $cantidad = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $cantidad;
    }

    /**
     * El embudo del resumen, por nombre de etapa.
     *
     * @param array<string, mixed> $resumen
     *
     * @return array<string, array{ahora: int, pasaron: int}>
     */
    private function embudo(array $resumen)
    {
        $nombres = [];
        foreach ($this->pipeline->stages as $etapa) {
            $nombres[$etapa->id] = $etapa->name;
        }

        $embudo = [];
        foreach ($resumen['por_etapa'] as $fila) {
            $embudo[$nombres[$fila['stage_id']]] = ['ahora' => $fila['ahora'], 'pasaron' => $fila['pasaron']];
        }

        return $embudo;
    }
}
