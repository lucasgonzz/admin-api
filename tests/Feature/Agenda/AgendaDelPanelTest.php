<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaSubtask;
use App\Models\AgendaTask;
use App\Models\AgendaTaskCompletion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La Agenda del panel (`admin/agenda…` bajo `auth:sanctum`), contra el contrato del plan de la
 * misión agenda-admin (31/10/2026): es lo que consume el SPA.
 *
 * Cubre el ABM, la tenencia (una tarea de otro admin es 404 en TODAS las rutas), mover de día y
 * de hora y a la bandeja, el reemplazo de subpasos y el tildado suelto, completar/descompletar
 * idempotente, duplicar, y las validaciones del contrato con su control positivo cada una.
 */
class AgendaDelPanelTest extends BaseDeAgenda
{
    /* ------------------------------------------------------------------------------------------
     | La puerta
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin sesión el panel no contesta; con sesión sí (control positivo).
     *
     * @return void
     */
    public function test_sin_sesion_el_panel_devuelve_401(): void
    {
        $this->getJson('/api/admin/agenda?desde=2026-11-01&hasta=2026-11-07')->assertStatus(401);
        $this->postJson('/api/admin/agenda', ['title' => 'Sin sesión'])->assertStatus(401);

        $admin = $this->crear_admin();
        $this->como($admin)->getJson('/api/admin/agenda?desde=2026-11-01&hasta=2026-11-07')->assertStatus(200);
    }

    /* ------------------------------------------------------------------------------------------
     | Alta y lectura
     |----------------------------------------------------------------------------------------- */

    /**
     * El alta guarda todo lo que vino, responde con la forma exacta del contrato, y la tarea
     * aparece igual en `GET agenda/{id}` y en el listado del rango.
     *
     * @return void
     */
    public function test_crear_una_tarea_con_subpasos_y_leerla(): void
    {
        $admin = $this->crear_admin();

        $modelo = $this->crear_por_panel($admin, [
            'title'            => 'Rotar clave de kapso',
            'notes'            => 'Pedirla en el panel de Kapso.',
            'date'             => '2026-11-02',
            'start_time'       => '10:30',
            'duration_minutes' => 15,
            'color'            => 'sky',
            'icon'             => 'airplane',
            'subtasks'         => ['Entrar a Kapso', ['title' => 'Generar la clave', 'done' => true]],
        ]);

        $this->assert_forma_de_tarea($modelo, 'en el alta');
        $this->assertSame('Rotar clave de kapso', $modelo['title']);
        $this->assertSame((int) $admin->id, $modelo['admin_id']);
        $this->assertSame('2026-11-02', $modelo['date']);
        $this->assertSame('2026-11-02', $modelo['occurrence_date']);
        $this->assertSame('10:30', $modelo['start_time']);
        $this->assertSame('10:45', $modelo['end_time']);
        $this->assertFalse($modelo['all_day']);
        $this->assertSame('sky', $modelo['color']);
        $this->assertSame('airplane', $modelo['icon']);
        $this->assertSame('none', $modelo['repeat']);
        $this->assertFalse($modelo['is_recurring']);
        $this->assertFalse($modelo['completed']);
        $this->assertNull($modelo['completed_at']);
        $this->assertSame(2, $modelo['subtasks_total']);
        $this->assertSame(1, $modelo['subtasks_done']);
        $this->assertSame('Entrar a Kapso', $modelo['subtasks'][0]['title']);
        $this->assertFalse($modelo['subtasks'][0]['done']);
        $this->assertTrue($modelo['subtasks'][1]['done']);
        $this->assertSame('panel', $modelo['created_via']);

        /* Lo que quedó en la base. */
        $fila = AgendaTask::find($modelo['id']);
        $this->assertNotNull($fila);
        $this->assertSame((int) $admin->id, (int) $fila->admin_id);
        $this->assertSame('2026-11-02', (string) $fila->date);
        $this->assertSame('10:30:00', (string) $fila->start_time);
        $this->assertSame('panel', $fila->created_via);
        $this->assertSame(2, AgendaSubtask::where('agenda_task_id', $fila->id)->count());

        /* La misma tarea, por las dos lecturas. */
        $leida = $this->como($admin)->getJson('/api/admin/agenda/' . $modelo['id'])->assertStatus(200)->json('model');
        $this->assertSame($modelo, $leida);

        $listado = $this->listar($admin, '2026-11-01', '2026-11-07');
        $this->assertSame(['2026-11-01', '2026-11-07', self::HOY], [$listado['desde'], $listado['hasta'], $listado['hoy']]);
        $this->assertSame(['2026-11-02'], $this->fechas_de($listado, $modelo['id']));
        $this->assertSame([], $listado['bandeja']);
    }

    /**
     * Sin fecha la tarea va a la bandeja; con fecha y sin hora (y no de todo el día) arranca a
     * las 09:00; de todo el día no tiene hora. Valores por defecto del contrato.
     *
     * @return void
     */
    public function test_bandeja_hora_por_defecto_y_todo_el_dia(): void
    {
        $admin = $this->crear_admin();

        $bandeja = $this->crear_por_panel($admin, ['title' => 'Algún día']);
        $this->assertNull($bandeja['date']);
        $this->assertNull($bandeja['occurrence_date']);
        $this->assertNull($bandeja['start_time']);
        $this->assertNull($bandeja['end_time']);
        $this->assertSame(15, $bandeja['duration_minutes']);
        $this->assertSame('salmon', $bandeja['color']);
        $this->assertSame('check2-square', $bandeja['icon']);

        $sin_hora = $this->crear_por_panel($admin, ['title' => 'Con fecha sin hora', 'date' => '2026-11-03']);
        $this->assertSame('09:00', $sin_hora['start_time']);
        $this->assertSame('09:15', $sin_hora['end_time']);
        $this->assertSame('09:00:00', (string) AgendaTask::find($sin_hora['id'])->start_time);

        $todo_el_dia = $this->crear_por_panel($admin, ['title' => 'Feriado', 'date' => '2026-11-03', 'start_time' => '11:00', 'all_day' => true]);
        $this->assertTrue($todo_el_dia['all_day']);
        $this->assertNull($todo_el_dia['start_time']);
        $this->assertNull($todo_el_dia['end_time']);
        $this->assertNull(AgendaTask::find($todo_el_dia['id'])->start_time);

        /* La hora de fin puede pasar de medianoche: se informa la hora igual. */
        $tarde = $this->crear_por_panel($admin, ['title' => 'Trasnoche', 'date' => '2026-11-03', 'start_time' => '23:45', 'duration_minutes' => 30]);
        $this->assertSame('00:15', $tarde['end_time']);

        /* En el listado: la bandeja aparte, y el día ordenado con la de todo el día primero. */
        $listado = $this->listar($admin, '2026-11-03', '2026-11-03');
        $this->assertSame([$bandeja['id']], array_column($listado['bandeja'], 'id'));
        $this->assertSame(
            [$todo_el_dia['id'], $sin_hora['id'], $tarde['id']],
            array_column($listado['ocurrencias'], 'id')
        );
    }

    /* ------------------------------------------------------------------------------------------
     | Tenencia
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Una tarea de otro admin es 404 en TODAS las rutas con {id}, y ninguna la toca. El dueño
     * la sigue leyendo (control positivo) y no aparece en el listado del otro.
     *
     * @return void
     */
    public function test_una_tarea_de_otro_admin_es_404_en_todas_las_rutas(): void
    {
        $duena = $this->crear_admin('Dueña');
        $otro  = $this->crear_admin('Otro');

        $modelo = $this->crear_por_panel($duena, [
            'title'    => 'Mía',
            'date'     => '2026-11-02',
            'subtasks' => ['Paso'],
        ]);
        $id         = $modelo['id'];
        $subtask_id = $modelo['subtasks'][0]['id'];

        $this->como($otro)->getJson('/api/admin/agenda/' . $id)->assertStatus(404);
        $this->como($otro)->putJson('/api/admin/agenda/' . $id, ['title' => 'Robada'])->assertStatus(404);
        $this->como($otro)->postJson('/api/admin/agenda/' . $id . '/complete')->assertStatus(404);
        $this->como($otro)->postJson('/api/admin/agenda/' . $id . '/uncomplete')->assertStatus(404);
        $this->como($otro)->postJson('/api/admin/agenda/' . $id . '/duplicate')->assertStatus(404);
        $this->como($otro)->putJson('/api/admin/agenda/' . $id . '/subtasks/' . $subtask_id, ['done' => true])->assertStatus(404);
        $this->como($otro)->deleteJson('/api/admin/agenda/' . $id)->assertStatus(404);

        /* Nada cambió en la base. */
        $fila = AgendaTask::find($id);
        $this->assertNotNull($fila, 'El DELETE de otro admin borró la tarea.');
        $this->assertSame('Mía', $fila->title);
        $this->assertNull($fila->completed_at);
        $this->assertFalse((bool) AgendaSubtask::find($subtask_id)->done);
        $this->assertSame(0, $this->cantidad_de_tareas($otro), 'El duplicate de otro admin creó una copia.');

        /* Y el otro no la ve en su listado. */
        $this->assertSame([], $this->fechas_de($this->listar($otro, '2026-11-01', '2026-11-07'), $id));

        /* Control positivo: la dueña sí. */
        $this->como($duena)->getJson('/api/admin/agenda/' . $id)->assertStatus(200);
        $this->assertSame(['2026-11-02'], $this->fechas_de($this->listar($duena, '2026-11-01', '2026-11-07'), $id));
    }

    /**
     * Un {id} que no son dígitos no llega al controlador (whereNumber), y uno que no existe es 404.
     *
     * @return void
     */
    public function test_un_id_que_no_es_numero_o_no_existe_es_404(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'Una']);

        $this->como($admin)->getJson('/api/admin/agenda/' . $modelo['id'] . '-borrame')->assertStatus(404);
        $this->como($admin)->deleteJson('/api/admin/agenda/' . $modelo['id'] . '-borrame')->assertStatus(404);
        $this->como($admin)->getJson('/api/admin/agenda/999999999')->assertStatus(404);

        $this->assertNotNull(AgendaTask::find($modelo['id']));
        $this->como($admin)->getJson('/api/admin/agenda/' . $modelo['id'])->assertStatus(200);
    }

    /* ------------------------------------------------------------------------------------------
     | Mover
     |----------------------------------------------------------------------------------------- */

    /**
     * Mover a otro día y hora, a la bandeja y de vuelta a un día: cada paso queda en la base y el
     * listado lo refleja.
     *
     * @return void
     */
    public function test_mover_a_otra_fecha_y_hora_y_a_la_bandeja(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'Llamar al contador', 'date' => '2026-11-02', 'start_time' => '10:00']);
        $id     = $modelo['id'];

        /* A otro día y otra hora. */
        $movida = $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => '2026-11-05', 'start_time' => '14:00'])
            ->assertStatus(200)->json('model');
        $this->assertSame('2026-11-05', $movida['date']);
        $this->assertSame('14:00', $movida['start_time']);
        $this->assertSame('Llamar al contador', $movida['title'], 'La edición parcial pisó el título.');

        $fila = AgendaTask::find($id);
        $this->assertSame('2026-11-05', (string) $fila->date);
        $this->assertSame('14:00:00', (string) $fila->start_time);

        $listado = $this->listar($admin, '2026-11-01', '2026-11-07');
        $this->assertSame(['2026-11-05'], $this->fechas_de($listado, $id));

        /* A la bandeja: se limpian la hora y la repetición. */
        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['repeat' => 'weekly'])->assertStatus(200);
        $en_bandeja = $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => null])->assertStatus(200)->json('model');
        $this->assertNull($en_bandeja['date']);
        $this->assertNull($en_bandeja['start_time']);
        $this->assertSame('none', $en_bandeja['repeat']);

        $fila = AgendaTask::find($id);
        $this->assertNull($fila->date);
        $this->assertNull($fila->start_time);
        $this->assertSame('none', $fila->repeat);

        $listado = $this->listar($admin, '2026-11-01', '2026-11-30');
        $this->assertSame([], $this->fechas_de($listado, $id));
        $this->assertSame([$id], array_column($listado['bandeja'], 'id'));

        /* De la bandeja a un día sin hora: arranca a las 09:00. */
        $programada = $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => '2026-11-06'])->assertStatus(200)->json('model');
        $this->assertSame('09:00', $programada['start_time']);
        $this->assertSame('2026-11-06', (string) AgendaTask::find($id)->date);
    }

    /**
     * "Pasar a todo el día" limpia la hora; mandar una hora a una de todo el día la saca de todo
     * el día.
     *
     * @return void
     */
    public function test_todo_el_dia_limpia_la_hora_y_mandar_una_hora_lo_saca(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'Reunión', 'date' => '2026-11-02', 'start_time' => '10:00']);

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'], ['all_day' => true])->assertStatus(200);
        $fila = AgendaTask::find($modelo['id']);
        $this->assertTrue((bool) $fila->all_day);
        $this->assertNull($fila->start_time);

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'], ['start_time' => '16:30'])->assertStatus(200);
        $fila = AgendaTask::find($modelo['id']);
        $this->assertFalse((bool) $fila->all_day);
        $this->assertSame('16:30:00', (string) $fila->start_time);
    }

    /* ------------------------------------------------------------------------------------------
     | Subpasos
     |----------------------------------------------------------------------------------------- */

    /**
     * `subtasks` en el PUT REEMPLAZA el conjunto: con id se actualiza, sin id se crea, el que falta
     * se borra. Sin `subtasks` en el body no se tocan (control). Un id de otra tarea es 422 y no
     * cambia nada.
     *
     * @return void
     */
    public function test_subtasks_en_el_put_reemplaza_el_conjunto(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'Mudanza', 'date' => '2026-11-02', 'subtasks' => ['Cajas', 'Flete', 'Llaves']]);
        $id     = $modelo['id'];
        list($cajas, $flete, $llaves) = array_column($modelo['subtasks'], 'id');

        /* Control: editar otra cosa no toca los subpasos. */
        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['title' => 'Mudanza al centro'])->assertStatus(200);
        $this->assertSame(3, AgendaSubtask::where('agenda_task_id', $id)->count());

        /* Un id de OTRA tarea: 422 y no se toca nada. */
        $otra = $this->crear_por_panel($admin, ['title' => 'Otra', 'subtasks' => ['Ajeno']]);
        $ajeno = $otra['subtasks'][0]['id'];

        $this->como($admin)->putJson('/api/admin/agenda/' . $id, [
            'title'    => 'No debería guardarse',
            'subtasks' => [['id' => $ajeno, 'title' => 'Robado', 'done' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['subtasks.0.id']);

        $this->assertSame('Mudanza al centro', AgendaTask::find($id)->title, 'El 422 dejó escrito el título.');
        $this->assertSame(3, AgendaSubtask::where('agenda_task_id', $id)->count());
        $this->assertSame('Ajeno', AgendaSubtask::find($ajeno)->title);

        /* El reemplazo de verdad. */
        $editada = $this->como($admin)->putJson('/api/admin/agenda/' . $id, [
            'subtasks' => [
                ['id' => $llaves, 'title' => 'Llaves (las dos copias)', 'done' => true],
                ['title' => 'Avisar al consorcio', 'done' => false],
            ],
        ])->assertStatus(200)->json('model');

        $this->assertSame(2, $editada['subtasks_total']);
        $this->assertSame(1, $editada['subtasks_done']);
        $this->assertSame(['Llaves (las dos copias)', 'Avisar al consorcio'], array_column($editada['subtasks'], 'title'));
        $this->assertSame($llaves, $editada['subtasks'][0]['id'], 'El subpaso con id se recreó en vez de actualizarse.');

        $this->assertNull(AgendaSubtask::find($cajas));
        $this->assertNull(AgendaSubtask::find($flete));
        $this->assertTrue((bool) AgendaSubtask::find($llaves)->done);
        $this->assertSame(0, (int) AgendaSubtask::find($llaves)->sort_order);
        $this->assertSame(2, AgendaSubtask::where('agenda_task_id', $id)->count());

        /* Lista vacía: borra todos. */
        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['subtasks' => []])->assertStatus(200);
        $this->assertSame(0, AgendaSubtask::where('agenda_task_id', $id)->count());
    }

    /**
     * Tildar un subpaso suelto. Uno que no es de la tarea es 404 y no se toca (control: el propio).
     *
     * @return void
     */
    public function test_tildar_y_editar_un_subpaso_suelto(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'Viaje', 'date' => '2026-11-02', 'subtasks' => ['Valija', 'Pasaporte']]);
        $otra   = $this->crear_por_panel($admin, ['title' => 'Otra', 'subtasks' => ['Ajeno']]);

        $sid   = $modelo['subtasks'][1]['id'];
        $ajeno = $otra['subtasks'][0]['id'];

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'] . '/subtasks/' . $ajeno, ['done' => true])->assertStatus(404);
        $this->assertFalse((bool) AgendaSubtask::find($ajeno)->done);

        $respuesta = $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'] . '/subtasks/' . $sid, ['done' => true])->assertStatus(200);
        $this->assert_forma_de_tarea((array) $respuesta->json('model'), 'al tildar un subpaso');
        $this->assertSame(1, $respuesta->json('model.subtasks_done'));
        $this->assertTrue((bool) AgendaSubtask::find($sid)->done);

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'] . '/subtasks/' . $sid, ['title' => 'Pasaporte vigente'])->assertStatus(200);
        $fila = AgendaSubtask::find($sid);
        $this->assertSame('Pasaporte vigente', $fila->title);
        $this->assertTrue((bool) $fila->done, 'Editar el texto destildó el subpaso.');

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'] . '/subtasks/' . $sid, ['title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->assertSame('Pasaporte vigente', AgendaSubtask::find($sid)->title);
    }

    /* ------------------------------------------------------------------------------------------
     | Hecha / no hecha
     |----------------------------------------------------------------------------------------- */

    /**
     * Completar y descompletar son idempotentes: repetir no cambia nada (ni la hora en que se
     * marcó) y devuelve 200.
     *
     * @return void
     */
    public function test_completar_y_descompletar_son_idempotentes(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'Pagar luz', 'date' => '2026-11-02', 'start_time' => '08:00']);
        $id     = $modelo['id'];

        $hecha = $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete')->assertStatus(200)->json('model');
        $this->assert_forma_de_tarea($hecha, 'al completar');
        $this->assertTrue($hecha['completed']);
        $this->assertNotNull($hecha['completed_at']);

        $marcada_en = AgendaTask::find($id)->completed_at;
        $this->assertNotNull($marcada_en);
        $this->assertSame(self::AHORA, $marcada_en->format('Y-m-d H:i:s'));

        /* Una hora después, completar de nuevo no la "re-marca". */
        Carbon::setTestNow(Carbon::parse('2026-10-31 11:00:00', 'America/Argentina/Buenos_Aires'));
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete')->assertStatus(200)->assertJsonPath('model.completed', true);
        $this->assertSame(self::AHORA, AgendaTask::find($id)->completed_at->format('Y-m-d H:i:s'));

        $no_hecha = $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/uncomplete', ['date' => '2026-11-02'])->assertStatus(200)->json('model');
        $this->assertFalse($no_hecha['completed']);
        $this->assertNull($no_hecha['completed_at']);
        $this->assertNull(AgendaTask::find($id)->completed_at);

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/uncomplete')->assertStatus(200)->assertJsonPath('model.completed', false);
        $this->assertNull(AgendaTask::find($id)->completed_at);

        /* 🔴 En una NO repetida el `date` del body se ignora (decisión del 31/10/2026): el id ya
           identifica la tarea, y el SPA puede mandar la fecha vieja después de moverla sin
           refrescar. Antes esto era 422 y el operador no podía tildarla. */
        $con_fecha_vieja = $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-03'])
            ->assertStatus(200)->json('model');
        $this->assertTrue($con_fecha_vieja['completed']);
        $this->assertSame('2026-11-02', $con_fecha_vieja['occurrence_date'], 'La fecha del body se coló en la respuesta.');
        $this->assertNotNull(AgendaTask::find($id)->completed_at);
        $this->assertSame('2026-11-02', (string) AgendaTask::find($id)->date, 'La fecha del body movió la tarea.');

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/uncomplete', ['date' => '1999-99-99'])
            ->assertStatus(200)->assertJsonPath('model.completed', false);
        $this->assertNull(AgendaTask::find($id)->completed_at);

        /* incluir_completadas=false la saca del listado solo mientras está hecha. */
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete')->assertStatus(200);
        $this->assertSame([], $this->fechas_de($this->listar($admin, '2026-11-01', '2026-11-07', ['incluir_completadas' => 'false']), $id));
        $this->assertSame(['2026-11-02'], $this->fechas_de($this->listar($admin, '2026-11-01', '2026-11-07', ['incluir_completadas' => 'true']), $id));
    }

    /**
     * 🔴 `complete` toma `lockForUpdate` sobre la fila de la tarea antes de escribir, en una
     * repetida y en una suelta. Es lo que serializa dos `complete` simultáneos de la misma
     * ocurrencia para que no inserten dos filas (la tabla no tiene índice único y la migración ya
     * corrió en bases locales). Dos requests concurrentes no se pueden armar adentro de un test de
     * un solo proceso, así que se afirma el lock en el log de consultas; el resultado (una sola
     * fila) se afirma igual.
     *
     * @return void
     */
    public function test_completar_toma_el_lock_de_la_tarea(): void
    {
        $admin  = $this->crear_admin();
        $serie  = $this->crear_por_panel($admin, ['title' => 'Serie', 'date' => '2026-11-02', 'repeat' => 'daily']);
        $suelta = $this->crear_por_panel($admin, ['title' => 'Suelta', 'date' => '2026-11-02']);

        foreach ([[$serie['id'], ['date' => '2026-11-03']], [$suelta['id'], []]] as list($id, $body)) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', $body)->assertStatus(200);

            $bloqueos = array_filter(DB::getQueryLog(), function ($consulta) {
                $sql = strtolower((string) $consulta['query']);

                return strpos($sql, '`agenda_tasks`') !== false && strpos($sql, 'for update') !== false;
            });

            DB::disableQueryLog();

            $this->assertNotEmpty($bloqueos, 'El complete de la tarea ' . $id . ' no tomó el lock de la fila de la tarea.');
        }

        $this->como($admin)->postJson('/api/admin/agenda/' . $serie['id'] . '/complete', ['date' => '2026-11-03'])->assertStatus(200);
        $this->assertSame(1, AgendaTaskCompletion::where('agenda_task_id', $serie['id'])->count());
    }

    /**
     * La bandeja también respeta `incluir_completadas=false` (control: con true aparece).
     *
     * @return void
     */
    public function test_la_bandeja_respeta_incluir_completadas(): void
    {
        $admin   = $this->crear_admin();
        $hecha   = $this->crear_por_panel($admin, ['title' => 'Hecha en la bandeja']);
        $abierta = $this->crear_por_panel($admin, ['title' => 'Abierta en la bandeja']);

        $this->como($admin)->postJson('/api/admin/agenda/' . $hecha['id'] . '/complete')->assertStatus(200);
        $this->assertNotNull(AgendaTask::find($hecha['id'])->completed_at);

        $sin = $this->listar($admin, '2026-11-01', '2026-11-07', ['incluir_completadas' => 'false']);
        $this->assertSame([$abierta['id']], array_column($sin['bandeja'], 'id'));

        $con = $this->listar($admin, '2026-11-01', '2026-11-07', ['incluir_completadas' => '1']);
        $this->assertSame([$hecha['id'], $abierta['id']], array_column($con['bandeja'], 'id'));
        $this->assertTrue($con['bandeja'][0]['completed']);
    }

    /**
     * Tope de 100 subpasos por tarea: 101 es 422 y no crea; 100 pasa.
     *
     * @return void
     */
    public function test_tope_de_cien_subpasos(): void
    {
        $admin = $this->crear_admin();

        $ciento_uno = [];
        for ($i = 1; $i <= 101; $i++) {
            $ciento_uno[] = 'Paso ' . $i;
        }

        $this->como($admin)->postJson('/api/admin/agenda', ['title' => 'Demasiados', 'subtasks' => $ciento_uno])
            ->assertStatus(422)->assertJsonValidationErrors(['subtasks']);
        $this->assertSame(0, $this->cantidad_de_tareas($admin));

        $modelo = $this->crear_por_panel($admin, ['title' => 'Justo cien', 'subtasks' => array_slice($ciento_uno, 0, 100)]);
        $this->assertSame(100, $modelo['subtasks_total']);
        $this->assertSame(100, AgendaSubtask::where('agenda_task_id', $modelo['id'])->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Duplicar y borrar
     |----------------------------------------------------------------------------------------- */

    /**
     * Duplicar copia título, datos y subpasos, pero sin tildar y no hecha. La original no cambia.
     *
     * @return void
     */
    public function test_duplicar_copia_sin_marcar(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, [
            'title'    => 'Revisar backups',
            'date'     => '2026-11-02',
            'color'    => 'emerald',
            'icon'     => 'hdd',
            'subtasks' => [['title' => 'Admin', 'done' => true], 'Clientes'],
        ]);
        $this->como($admin)->postJson('/api/admin/agenda/' . $modelo['id'] . '/complete')->assertStatus(200);

        $copia = $this->como($admin)->postJson('/api/admin/agenda/' . $modelo['id'] . '/duplicate')->assertStatus(201)->json('model');

        $this->assert_forma_de_tarea($copia, 'al duplicar');
        $this->assertNotSame($modelo['id'], $copia['id']);
        $this->assertSame('Revisar backups', $copia['title']);
        $this->assertSame('2026-11-02', $copia['date']);
        $this->assertSame('emerald', $copia['color']);
        $this->assertSame('hdd', $copia['icon']);
        $this->assertFalse($copia['completed']);
        $this->assertSame(2, $copia['subtasks_total']);
        $this->assertSame(0, $copia['subtasks_done']);

        $this->assertSame(2, $this->cantidad_de_tareas($admin));
        $this->assertNull(AgendaTask::find($copia['id'])->completed_at);
        $this->assertNotNull(AgendaTask::find($modelo['id'])->completed_at, 'Duplicar desmarcó la original.');
        $this->assertSame(1, AgendaSubtask::where('agenda_task_id', $modelo['id'])->where('done', true)->count());
        $this->assertSame(0, AgendaSubtask::where('agenda_task_id', $copia['id'])->where('done', true)->count());
    }

    /**
     * Duplicar una repetida copia la serie (misma repetición y fin) pero NO lo hecho: ninguna
     * ocurrencia de la copia está hecha, y las de la original siguen igual.
     *
     * @return void
     */
    public function test_duplicar_una_repetida_copia_la_serie_sin_lo_hecho(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Regar', 'date' => '2026-11-02', 'start_time' => '07:00', 'repeat' => 'weekly', 'repeat_until' => '2026-11-30']);

        $this->como($admin)->postJson('/api/admin/agenda/' . $serie['id'] . '/complete', ['date' => '2026-11-09'])->assertStatus(200);

        $copia = $this->como($admin)->postJson('/api/admin/agenda/' . $serie['id'] . '/duplicate')->assertStatus(201)->json('model');

        $this->assertTrue($copia['is_recurring']);
        $this->assertSame('weekly', $copia['repeat']);
        $this->assertSame('2026-11-30', $copia['repeat_until']);
        $this->assertSame('07:00', $copia['start_time']);
        $this->assertSame(0, AgendaTaskCompletion::where('agenda_task_id', $copia['id'])->count());
        $this->assertSame(1, AgendaTaskCompletion::where('agenda_task_id', $serie['id'])->count());

        $listado = $this->listar($admin, '2026-11-01', '2026-11-30');
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30'], $this->fechas_de($listado, $copia['id']));
        foreach ($listado['ocurrencias'] as $ocurrencia) {
            $esperado = (int) $ocurrencia['id'] === (int) $serie['id'] && $ocurrencia['occurrence_date'] === '2026-11-09';
            $this->assertSame($esperado, $ocurrencia['completed'], 'Ocurrencia ' . $ocurrencia['id'] . ' del ' . $ocurrencia['occurrence_date']);
        }
    }

    /**
     * Borrar se lleva los subpasos y las ocurrencias hechas, y nada más (control: otra tarea).
     *
     * @return void
     */
    public function test_borrar_se_lleva_subpasos_y_ocurrencias_hechas(): void
    {
        $admin  = $this->crear_admin();
        $serie  = $this->crear_por_panel($admin, ['title' => 'Gimnasio', 'date' => '2026-11-02', 'repeat' => 'daily', 'subtasks' => ['Toalla']]);
        $otra   = $this->crear_por_panel($admin, ['title' => 'Queda', 'date' => '2026-11-02', 'subtasks' => ['Sigue']]);

        $this->como($admin)->postJson('/api/admin/agenda/' . $serie['id'] . '/complete', ['date' => '2026-11-03'])->assertStatus(200);
        $this->assertSame(1, AgendaTaskCompletion::where('agenda_task_id', $serie['id'])->count());

        $this->como($admin)->deleteJson('/api/admin/agenda/' . $serie['id'])
            ->assertStatus(200)
            ->assertExactJson(['deleted' => true, 'id' => $serie['id']]);

        $this->assertNull(AgendaTask::find($serie['id']));
        $this->assertSame(0, AgendaSubtask::where('agenda_task_id', $serie['id'])->count());
        $this->assertSame(0, AgendaTaskCompletion::where('agenda_task_id', $serie['id'])->count());

        $this->assertNotNull(AgendaTask::find($otra['id']));
        $this->assertSame(1, AgendaSubtask::where('agenda_task_id', $otra['id'])->count());

        $this->como($admin)->getJson('/api/admin/agenda/' . $serie['id'])->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Validaciones (cada 422 con su control positivo)
     |----------------------------------------------------------------------------------------- */

    /**
     * Cada regla del contrato: el 422 con el error en el campo, en criollo, sin escribir nada; y
     * el mismo pedido corregido, que sí crea.
     *
     * @return void
     */
    public function test_validaciones_del_alta_con_su_control_positivo(): void
    {
        $admin = $this->crear_admin();

        $casos = [
            'hora sin fecha' => [
                ['title' => 'X', 'start_time' => '10:00'],
                'start_time',
                ['title' => 'X', 'start_time' => '10:00', 'date' => '2026-11-02'],
            ],
            'repetición sin fecha' => [
                ['title' => 'X', 'repeat' => 'daily'],
                'repeat',
                ['title' => 'X', 'repeat' => 'daily', 'date' => '2026-11-02'],
            ],
            'fin de la repetición antes de la fecha' => [
                ['title' => 'X', 'date' => '2026-11-02', 'repeat' => 'weekly', 'repeat_until' => '2026-11-01'],
                'repeat_until',
                ['title' => 'X', 'date' => '2026-11-02', 'repeat' => 'weekly', 'repeat_until' => '2026-11-02'],
            ],
            'color fuera de la paleta' => [
                ['title' => 'X', 'color' => 'fucsia'],
                'color',
                ['title' => 'X', 'color' => 'emerald'],
            ],
            'ícono con caracteres inválidos' => [
                ['title' => 'X', 'icon' => 'bi-Airplane!'],
                'icon',
                ['title' => 'X', 'icon' => 'airplane'],
            ],
            'ícono de más de 60' => [
                ['title' => 'X', 'icon' => str_repeat('a', 61)],
                'icon',
                ['title' => 'X', 'icon' => str_repeat('a', 60)],
            ],
            'título vacío' => [
                ['title' => '   '],
                'title',
                ['title' => 'Con título'],
            ],
            'duración fuera de rango' => [
                ['title' => 'X', 'duration_minutes' => 4],
                'duration_minutes',
                ['title' => 'X', 'duration_minutes' => 5],
            ],
            'repetición desconocida' => [
                ['title' => 'X', 'date' => '2026-11-02', 'repeat' => 'yearly'],
                'repeat',
                ['title' => 'X', 'date' => '2026-11-02', 'repeat' => 'monthly'],
            ],
            'fecha que no existe' => [
                ['title' => 'X', 'date' => '2026-02-30'],
                'date',
                ['title' => 'X', 'date' => '2026-02-28'],
            ],
            'hora mal escrita' => [
                ['title' => 'X', 'date' => '2026-11-02', 'start_time' => '25:00'],
                'start_time',
                ['title' => 'X', 'date' => '2026-11-02', 'start_time' => '23:00'],
            ],
            'subpaso sin texto' => [
                ['title' => 'X', 'subtasks' => ['Bien', ['title' => '']]],
                'subtasks.1.title',
                ['title' => 'X', 'subtasks' => ['Bien', ['title' => 'También']]],
            ],
        ];

        foreach ($casos as $nombre => list($malo, $campo, $bueno)) {
            $antes = $this->cantidad_de_tareas($admin);

            $respuesta = $this->como($admin)->postJson('/api/admin/agenda', $malo);
            $respuesta->assertStatus(422);
            $respuesta->assertJsonValidationErrors([$campo]);

            $mensaje = (string) $respuesta->json('message');
            $this->assertNotSame('', $mensaje, 'Caso «' . $nombre . '»: el 422 vino sin message.');
            $this->assertStringNotContainsString('The given data', $mensaje, 'Caso «' . $nombre . '»: el message quedó en inglés.');
            $this->assertSame($antes, $this->cantidad_de_tareas($admin), 'Caso «' . $nombre . '»: el 422 escribió igual.');

            /* Control positivo: el mismo pedido, corregido, crea. */
            $this->como($admin)->postJson('/api/admin/agenda', $bueno)->assertStatus(201);
            $this->assertSame($antes + 1, $this->cantidad_de_tareas($admin), 'Caso «' . $nombre . '»: el control positivo no creó.');
        }
    }

    /**
     * Las mismas reglas valen en la edición, sobre el estado FINAL: una hora a una tarea de la
     * bandeja es 422; la misma hora con fecha pasa.
     *
     * @return void
     */
    public function test_validaciones_de_la_edicion_sobre_el_estado_final(): void
    {
        $admin  = $this->crear_admin();
        $modelo = $this->crear_por_panel($admin, ['title' => 'En la bandeja']);

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'], ['start_time' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors(['start_time']);
        $this->assertNull(AgendaTask::find($modelo['id'])->start_time);

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'], ['date' => null, 'repeat' => 'daily'])
            ->assertStatus(422)->assertJsonValidationErrors(['repeat']);
        $this->assertSame('none', AgendaTask::find($modelo['id'])->repeat);

        $this->como($admin)->putJson('/api/admin/agenda/' . $modelo['id'], ['start_time' => '10:00', 'date' => '2026-11-04'])->assertStatus(200);
        $this->assertSame('10:00:00', (string) AgendaTask::find($modelo['id'])->start_time);

        /* Mover la fecha de una serie más allá de su fin es 422 (la serie quedaría vacía). */
        $serie = $this->crear_por_panel($admin, ['title' => 'Serie', 'date' => '2026-11-02', 'repeat' => 'daily', 'repeat_until' => '2026-11-10']);
        $this->como($admin)->putJson('/api/admin/agenda/' . $serie['id'], ['date' => '2026-11-11'])
            ->assertStatus(422)->assertJsonValidationErrors(['repeat_until']);
        $this->assertSame('2026-11-02', (string) AgendaTask::find($serie['id'])->date);

        $this->como($admin)->putJson('/api/admin/agenda/' . $serie['id'], ['date' => '2026-11-10'])->assertStatus(200);
        $this->assertSame('2026-11-10', (string) AgendaTask::find($serie['id'])->date);
    }

    /**
     * El rango del listado: obligatorio en el panel, `hasta` no antes de `desde` y como mucho 100
     * días. Control: exactamente 100 días pasa.
     *
     * @return void
     */
    public function test_el_rango_del_listado_se_valida(): void
    {
        $admin = $this->crear_admin();

        $this->como($admin)->getJson('/api/admin/agenda?hasta=2026-11-07')
            ->assertStatus(422)->assertJsonValidationErrors(['desde']);
        $this->como($admin)->getJson('/api/admin/agenda?desde=2026-11-01')
            ->assertStatus(422)->assertJsonValidationErrors(['hasta']);
        $this->como($admin)->getJson('/api/admin/agenda?desde=2026-11-07&hasta=2026-11-01')
            ->assertStatus(422)->assertJsonValidationErrors(['hasta']);
        $this->como($admin)->getJson('/api/admin/agenda?desde=2026-11-01&hasta=2027-02-10')
            ->assertStatus(422)->assertJsonValidationErrors(['hasta']);
        $this->como($admin)->getJson('/api/admin/agenda?desde=2026-11-01&hasta=2026-11-07&incluir_completadas=quizas')
            ->assertStatus(422)->assertJsonValidationErrors(['incluir_completadas']);

        /* 2026-11-01 + 100 días = 2027-02-09. */
        $this->como($admin)->getJson('/api/admin/agenda?desde=2026-11-01&hasta=2027-02-09')->assertStatus(200);
    }
}
