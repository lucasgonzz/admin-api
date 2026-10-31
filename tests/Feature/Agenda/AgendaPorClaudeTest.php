<?php

namespace Tests\Feature\Agenda;

use App\Models\Admin;
use App\Models\AgendaSubtask;
use App\Models\AgendaTask;
use App\Models\AgendaTaskCompletion;

/**
 * La Agenda para Claude (`claude/agenda…` bajo `claude.task.key`), misión agenda-admin (31/10/2026).
 *
 * Claude no tiene sesión: elige la agenda con `admin_id` / `admin_name` o cae en el admin por
 * defecto de las tareas de Claude. Lo que se mide acá: la puerta (401 sin clave), el alta con
 * subpasos en strings y `created_via = claude`, la resolución del admin (por defecto, por nombre,
 * ambiguo, inexistente), la búsqueda, mover, completar y los subpasos sueltos, y que la forma de
 * la tarea sea LA MISMA que la del panel.
 */
class AgendaPorClaudeTest extends BaseDeAgenda
{
    /**
     * Sin la clave el bloque no contesta y no escribe; con la clave sí (control positivo).
     *
     * @return void
     */
    public function test_sin_clave_es_401_y_no_escribe(): void
    {
        $admin = $this->crear_admin();
        config(['services.claude_task_ingest.default_creator_admin_id' => $admin->id]);

        $this->getJson('/api/claude/agenda')->assertStatus(401);
        $this->postJson('/api/claude/agenda', ['title' => 'Sin clave'])->assertStatus(401);
        $this->postJson('/api/claude/agenda', ['title' => 'Clave mala'], ['X-Claude-Task-Key' => 'otra'])->assertStatus(401);
        $this->assertSame(0, $this->cantidad_de_tareas($admin));

        $this->getJson('/api/claude/agenda', $this->headers_claude())->assertStatus(200);
        $this->postJson('/api/claude/agenda', ['title' => 'Con clave'], $this->headers_claude())->assertStatus(201);
        $this->assertSame(1, $this->cantidad_de_tareas($admin));
    }

    /**
     * Crear con subpasos como strings y sin admin explícito: va al admin por defecto
     * (`default_creator_admin_id`), queda con `created_via = claude`, y la respuesta trae la forma
     * del contrato más `admin`.
     *
     * @return void
     */
    public function test_crear_con_subpasos_en_strings_va_al_admin_por_defecto(): void
    {
        $admin = $this->crear_admin('Lucas por defecto');
        config(['services.claude_task_ingest.default_creator_admin_id' => $admin->id]);

        $respuesta = $this->postJson('/api/claude/agenda', [
            'title'      => 'Rotar clave de kapso',
            'date'       => '2026-11-03',
            'start_time' => '10:30',
            'icon'       => 'key',
            'subtasks'   => ['Entrar a Kapso', 'Generar la clave', 'Cargarla en el admin'],
        ], $this->headers_claude());

        $respuesta->assertStatus(201);
        $this->assertSame(['id' => (int) $admin->id, 'name' => 'Lucas por defecto'], $respuesta->json('admin'));

        $modelo = (array) $respuesta->json('model');
        $this->assert_forma_de_tarea($modelo, 'en el alta de Claude');
        $this->assertSame('claude', $modelo['created_via']);
        $this->assertSame((int) $admin->id, $modelo['admin_id']);
        $this->assertSame(3, $modelo['subtasks_total']);
        $this->assertSame(0, $modelo['subtasks_done']);
        $this->assertSame('10:45', $modelo['end_time']);

        $fila = AgendaTask::find($modelo['id']);
        $this->assertSame('claude', $fila->created_via);
        $this->assertSame((int) $admin->id, (int) $fila->admin_id);
        $this->assertSame(
            ['Entrar a Kapso', 'Generar la clave', 'Cargarla en el admin'],
            AgendaSubtask::where('agenda_task_id', $fila->id)->orderBy('sort_order')->pluck('title')->all()
        );
        $this->assertSame(0, AgendaSubtask::where('agenda_task_id', $fila->id)->where('done', true)->count());

        /* La ve el admin en su panel, con la MISMA forma. */
        $del_panel = $this->como($admin)->getJson('/api/admin/agenda/' . $modelo['id'])->assertStatus(200)->json('model');
        $this->assertSame($modelo, $del_panel);
    }

    /**
     * Sin config, el admin por defecto es el marcado con `is_default_task_assignee` (mismo orden que
     * ClaudeTaskIngestController).
     *
     * @return void
     */
    public function test_sin_config_usa_el_admin_marcado_como_destinatario_por_defecto(): void
    {
        config(['services.claude_task_ingest.default_creator_admin_id' => null]);

        $otro = $this->crear_admin('Otro');
        $marcado = $this->crear_admin('Marcado');

        /* Adentro de la transacción del test: se deshace al final. */
        Admin::query()->update(['is_default_task_assignee' => false]);
        $marcado->is_default_task_assignee = true;
        $marcado->save();

        $this->postJson('/api/claude/agenda', ['title' => 'Para el marcado'], $this->headers_claude())
            ->assertStatus(201)
            ->assertJsonPath('admin.id', (int) $marcado->id);

        $this->assertSame(1, $this->cantidad_de_tareas($marcado));
        $this->assertSame(0, $this->cantidad_de_tareas($otro));
    }

    /**
     * 🔴 Un `admin_name` ambiguo es 422 con las coincidencias y NO crea; uno inexistente y un
     * `admin_id` inexistente también. Control: el nombre completo resuelve a uno y crea en SU agenda.
     *
     * @return void
     */
    public function test_admin_name_ambiguo_o_inexistente_es_422_y_no_crea(): void
    {
        $marca = 'agendita' . substr(md5(uniqid('', true)), 0, 8);
        $uno   = $this->crear_admin(ucfirst($marca) . ' Uno');
        $dos   = $this->crear_admin(ucfirst($marca) . ' Dos');

        $respuesta = $this->postJson('/api/claude/agenda', ['title' => 'Ambigua', 'admin_name' => strtoupper($marca)], $this->headers_claude());
        $respuesta->assertStatus(422)->assertJsonValidationErrors(['admin_name']);
        $coincidencias = (array) $respuesta->json('matches');
        sort($coincidencias);
        $this->assertSame([ucfirst($marca) . ' Dos', ucfirst($marca) . ' Uno'], $coincidencias);

        $this->postJson('/api/claude/agenda', ['title' => 'Nadie', 'admin_name' => $marca . ' Tres'], $this->headers_claude())
            ->assertStatus(422)->assertJsonValidationErrors(['admin_name']);

        $this->postJson('/api/claude/agenda', ['title' => 'Id falso', 'admin_id' => 999999999], $this->headers_claude())
            ->assertStatus(422)->assertJsonValidationErrors(['admin_id']);

        $this->assertSame(0, $this->cantidad_de_tareas($uno));
        $this->assertSame(0, $this->cantidad_de_tareas($dos));
        $this->assertSame(0, AgendaTask::whereIn('title', ['Ambigua', 'Nadie', 'Id falso'])->count());

        /* Control positivo: el nombre completo (en minúsculas) es uno solo. */
        $this->postJson('/api/claude/agenda', ['title' => 'Para Uno', 'admin_name' => $marca . ' uno'], $this->headers_claude())
            ->assertStatus(201)
            ->assertJsonPath('admin.id', (int) $uno->id);
        $this->assertSame(1, $this->cantidad_de_tareas($uno));
        $this->assertSame(0, $this->cantidad_de_tareas($dos));

        /* Y por id, también. */
        $this->postJson('/api/claude/agenda', ['title' => 'Para Dos', 'admin_id' => $dos->id], $this->headers_claude())
            ->assertStatus(201)
            ->assertJsonPath('model.admin_id', (int) $dos->id);
    }

    /**
     * El listado de Claude: rango por defecto hoy..hoy+7, el `admin`, y `buscar` encuentra por
     * título o notas en TODAS las tareas de ESE admin (también fuera del rango y en la bandeja),
     * no en las de otro.
     *
     * @return void
     */
    public function test_listar_y_buscar(): void
    {
        $admin = $this->crear_admin('Buscadora');
        $otro  = $this->crear_admin('Ajena');

        $lejana  = $this->crear_por_panel($admin, ['title' => 'Rotar clave de KAPSO', 'date' => '2027-01-15']);
        $bandeja = $this->crear_por_panel($admin, ['title' => 'Comprar yerba', 'notes' => 'La de kapso no, la otra']);
        $cerca   = $this->crear_por_panel($admin, ['title' => 'Llamar a Martín', 'date' => '2026-11-02']);
        $this->crear_por_panel($otro, ['title' => 'Kapso del otro', 'date' => '2026-11-02']);

        $respuesta = $this->getJson('/api/claude/agenda?admin_id=' . $admin->id . '&buscar=kapso', $this->headers_claude());
        $respuesta->assertStatus(200);

        $this->assertSame(self::HOY, $respuesta->json('desde'));
        $this->assertSame('2026-11-07', $respuesta->json('hasta'));
        $this->assertSame(self::HOY, $respuesta->json('hoy'));
        $this->assertSame(['id' => (int) $admin->id, 'name' => 'Buscadora'], $respuesta->json('admin'));

        /* En el rango por defecto está solo la cercana. */
        $this->assertSame([$cerca['id']], array_column((array) $respuesta->json('ocurrencias'), 'id'));
        $this->assertSame([$bandeja['id']], array_column((array) $respuesta->json('bandeja'), 'id'));

        /* La búsqueda no mira el rango ni otra agenda: la lejana y la de la bandeja (por las notas). */
        $ids = array_column((array) $respuesta->json('coincidencias'), 'id');
        sort($ids);
        $esperados = [$lejana['id'], $bandeja['id']];
        sort($esperados);
        $this->assertSame($esperados, $ids);
        $this->assert_forma_de_tarea((array) $respuesta->json('coincidencias.0'), 'en una coincidencia');

        /* Sin buscar no viene la clave. */
        $sin = $this->getJson('/api/claude/agenda?admin_id=' . $admin->id . '&desde=2027-01-10&hasta=2027-01-20', $this->headers_claude());
        $sin->assertStatus(200);
        $this->assertArrayNotHasKey('coincidencias', $sin->json());
        $this->assertSame([$lejana['id']], array_column((array) $sin->json('ocurrencias'), 'id'));

        /* Un rango de más de 100 días es 422, igual que en el panel. */
        $this->getJson('/api/claude/agenda?admin_id=' . $admin->id . '&desde=2026-11-01&hasta=2027-03-01', $this->headers_claude())
            ->assertStatus(422)->assertJsonValidationErrors(['hasta']);
    }

    /**
     * Mover por PATCH (a otro día y hora, y a la bandeja), con la base como prueba.
     *
     * @return void
     */
    public function test_mover_por_patch(): void
    {
        $admin = $this->crear_admin();
        $tarea = $this->crear_por_panel($admin, ['title' => 'Mandar presupuesto', 'date' => '2026-11-02', 'start_time' => '09:00']);

        $movida = $this->patchJson('/api/claude/agenda/' . $tarea['id'], ['date' => '2026-11-04', 'start_time' => '17:30'], $this->headers_claude())
            ->assertStatus(200)->json('model');
        $this->assert_forma_de_tarea($movida, 'en el PATCH de Claude');
        $this->assertSame('2026-11-04', $movida['date']);
        $this->assertSame('17:30', $movida['start_time']);

        $fila = AgendaTask::find($tarea['id']);
        $this->assertSame('2026-11-04', (string) $fila->date);
        $this->assertSame('17:30:00', (string) $fila->start_time);
        $this->assertSame('panel', $fila->created_via, 'Editarla desde Claude le cambió el origen.');

        /* Hora sin fecha: 422 y nada cambia. */
        $this->patchJson('/api/claude/agenda/' . $tarea['id'], ['date' => null, 'start_time' => '10:00'], $this->headers_claude())
            ->assertStatus(422)->assertJsonValidationErrors(['start_time']);
        $this->assertSame('2026-11-04', (string) AgendaTask::find($tarea['id'])->date);

        /* A la bandeja. */
        $this->patchJson('/api/claude/agenda/' . $tarea['id'], ['date' => null], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('model.date', null);
        $this->assertNull(AgendaTask::find($tarea['id'])->date);

        /* Un id que no existe es 404; uno que no son dígitos no llega. */
        $this->patchJson('/api/claude/agenda/999999999', ['title' => 'X'], $this->headers_claude())->assertStatus(404);
        $this->patchJson('/api/claude/agenda/' . $tarea['id'] . '-x', ['title' => 'X'], $this->headers_claude())->assertStatus(404);
        $this->assertSame('Mandar presupuesto', AgendaTask::find($tarea['id'])->title);
    }

    /**
     * Completar y descompletar desde Claude: una suelta sin fecha de ocurrencia, una repetida con
     * `date` (sin ella, 422).
     *
     * @return void
     */
    public function test_completar_por_claude(): void
    {
        $admin  = $this->crear_admin();
        $suelta = $this->crear_por_panel($admin, ['title' => 'Pagar AFIP', 'date' => '2026-11-02']);
        $serie  = $this->crear_por_panel($admin, ['title' => 'Backup', 'date' => '2026-11-02', 'repeat' => 'daily']);

        $this->postJson('/api/claude/agenda/' . $suelta['id'] . '/complete', [], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('model.completed', true);
        $this->assertNotNull(AgendaTask::find($suelta['id'])->completed_at);

        $this->postJson('/api/claude/agenda/' . $serie['id'] . '/complete', [], $this->headers_claude())
            ->assertStatus(422)->assertJsonValidationErrors(['date']);
        $this->assertSame(0, AgendaTaskCompletion::where('agenda_task_id', $serie['id'])->count());

        $this->postJson('/api/claude/agenda/' . $serie['id'] . '/complete', ['date' => '2026-11-05'], $this->headers_claude())
            ->assertStatus(200)
            ->assertJsonPath('model.occurrence_date', '2026-11-05')
            ->assertJsonPath('model.completed', true);
        $this->assertSame(1, AgendaTaskCompletion::where('agenda_task_id', $serie['id'])->count());

        $this->postJson('/api/claude/agenda/' . $suelta['id'] . '/uncomplete', [], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('model.completed', false);
        $this->assertNull(AgendaTask::find($suelta['id'])->completed_at);
    }

    /**
     * Agregar un subpaso (al final, sin tocar los otros) y tildarlo; leer y borrar por id.
     *
     * @return void
     */
    public function test_subpasos_lectura_y_borrado_por_claude(): void
    {
        $admin = $this->crear_admin();
        $tarea = $this->crear_por_panel($admin, ['title' => 'Viaje', 'date' => '2026-11-02', 'subtasks' => ['Valija']]);

        $con_nuevo = $this->postJson('/api/claude/agenda/' . $tarea['id'] . '/subtasks', ['title' => 'Pasaporte'], $this->headers_claude())
            ->assertStatus(201)->json('model');
        $this->assertSame(['Valija', 'Pasaporte'], array_column($con_nuevo['subtasks'], 'title'));
        $this->assertSame(2, AgendaSubtask::where('agenda_task_id', $tarea['id'])->count());

        $this->postJson('/api/claude/agenda/' . $tarea['id'] . '/subtasks', ['title' => ''], $this->headers_claude())
            ->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->assertSame(2, AgendaSubtask::where('agenda_task_id', $tarea['id'])->count());

        $sid = $con_nuevo['subtasks'][1]['id'];
        $this->patchJson('/api/claude/agenda/' . $tarea['id'] . '/subtasks/' . $sid, ['done' => true], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('model.subtasks_done', 1);
        $this->assertTrue((bool) AgendaSubtask::find($sid)->done);

        $leida = $this->getJson('/api/claude/agenda/' . $tarea['id'], $this->headers_claude())->assertStatus(200)->json('model');
        $this->assert_forma_de_tarea($leida, 'en el GET de Claude');
        $this->assertSame(2, $leida['subtasks_total']);

        $this->deleteJson('/api/claude/agenda/' . $tarea['id'], [], $this->headers_claude())
            ->assertStatus(200)->assertExactJson(['deleted' => true, 'id' => $tarea['id']]);
        $this->assertNull(AgendaTask::find($tarea['id']));
        $this->assertSame(0, AgendaSubtask::where('agenda_task_id', $tarea['id'])->count());

        $this->getJson('/api/claude/agenda/' . $tarea['id'], $this->headers_claude())->assertStatus(404);
    }
}
