<?php

namespace Tests\Feature\Agenda;

use App\Models\Admin;
use App\Models\AgendaTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responsable, origen del pedido y vínculo de las tareas de la Agenda (misión agenda-openclaw-empuje, 25/11/2026).
 *
 * Lo que fija este archivo (criterios CA1 a CA5 del plan):
 *  - Cada tarea suma `assignee` (owner|openclaw), `origin` (panel|dots|conversacion|openclaw), `link_type`
 *    (workboard|mision|null) y `link_ref`, con defaults que dejan a un cliente viejo viendo lo de siempre.
 *  - `GET claude/agenda` filtra por `assignee` y, con `vencidas=true`, suma la clave `vencidas`.
 *  - Todo 422 llega SIN escribir y cada uno con su control positivo (un 422 solo no distingue «el freno frenó»
 *    de «el escenario estaba mal armado»).
 *  - El catálogo documenta lo nuevo.
 *
 * Reloj de BaseDeAgenda: sábado 31/10/2026 10:00 ART (`HOY` = 2026-10-31).
 */
class AgendaResponsableOrigenYVinculoTest extends BaseDeAgenda
{
    /** Ruta de Claude. */
    const RUTA = '/api/claude/agenda';

    /**
     * Un admin que además es el admin por defecto de las tareas de Claude.
     *
     * @return Admin
     */
    private function admin_por_defecto(): Admin
    {
        $admin = $this->crear_admin('Lucas dueño');
        config(['services.claude_task_ingest.default_creator_admin_id' => $admin->id]);

        return $admin;
    }

    /**
     * Crea una tarea por Claude en la agenda de un admin y devuelve el model (afirmando el 201).
     *
     * @param Admin $admin Dueño.
     * @param array $datos Body del alta (sin `admin_id`).
     *
     * @return array
     */
    private function crear_por_claude(Admin $admin, array $datos): array
    {
        $respuesta = $this->postJson(self::RUTA, array_merge(['admin_id' => $admin->id], $datos), $this->headers_claude());
        $respuesta->assertStatus(201);

        return (array) $respuesta->json('model');
    }

    /**
     * CA1: sin los campos nuevos el alta por Claude queda con los defaults; el panel fuerza `origin = panel`
     * aunque mande otra cosa; la forma tiene las 28 claves en los dos lados; el listado de Claude sin
     * parámetros nuevos NO trae `vencidas`.
     *
     * @return void
     */
    public function test_defaults_y_forma_en_panel_y_claude(): void
    {
        $admin = $this->admin_por_defecto();

        $respuesta = $this->postJson(self::RUTA, ['title' => 'Tarea de un cliente viejo'], $this->headers_claude());
        $respuesta->assertStatus(201);

        $modelo = (array) $respuesta->json('model');
        $this->assert_forma_de_tarea($modelo, 'en el alta de Claude');
        $this->assertSame('owner', $modelo['assignee']);
        $this->assertSame('conversacion', $modelo['origin']);
        $this->assertNull($modelo['link_type']);
        $this->assertNull($modelo['link_ref']);

        $fila = AgendaTask::find($modelo['id']);
        $this->assertSame('owner', $fila->assignee);
        $this->assertSame('conversacion', $fila->origin);
        $this->assertNull($fila->link_type);
        $this->assertNull($fila->link_ref);

        /* El panel: origin = panel SIEMPRE, aunque el body diga otra cosa (control: sin origin también). */
        $panel = $this->crear_por_panel($admin, ['title' => 'Del panel', 'origin' => 'dots', 'assignee' => 'openclaw']);
        $this->assert_forma_de_tarea($panel, 'en el alta del panel');
        $this->assertSame('panel', $panel['origin']);
        $this->assertSame('openclaw', $panel['assignee']);
        $this->assertSame('panel', AgendaTask::find($panel['id'])->origin);

        $panel_simple = $this->crear_por_panel($admin, ['title' => 'Del panel sin nada']);
        $this->assertSame('owner', $panel_simple['assignee']);
        $this->assertSame('panel', $panel_simple['origin']);
        $this->assertNull($panel_simple['link_type']);

        /* Listado de Claude sin parámetros nuevos: misma respuesta de siempre, sin la clave `vencidas`. */
        $listado = $this->getJson(self::RUTA . '?admin_id=' . $admin->id . '&desde=2026-10-31&hasta=2026-11-02', $this->headers_claude());
        $listado->assertStatus(200);
        $this->assertArrayNotHasKey('vencidas', (array) $listado->json());
        $this->assertArrayHasKey('bandeja', (array) $listado->json());
        foreach ((array) $listado->json('bandeja') as $tarea) {
            $this->assert_forma_de_tarea($tarea, 'en la bandeja del listado');
        }
    }

    /**
     * CA2 (alta y edición): los cuatro valores se guardan, se pueden cambiar con PATCH, `origin` no se
     * edita (se ignora con 200) y `link_type = null` limpia también `link_ref`.
     *
     * @return void
     */
    public function test_alta_edicion_y_validacion_de_responsable_origen_y_vinculo(): void
    {
        $admin = $this->admin_por_defecto();

        $modelo = $this->crear_por_claude($admin, [
            'title'     => 'Revisar la tienda de Truvari',
            'assignee'  => 'openclaw',
            'origin'    => 'dots',
            'link_type' => 'workboard',
            'link_ref'  => '9c16cbb9-a2bd-485b-a579-439c9f5ac0ee',
        ]);
        $this->assert_forma_de_tarea($modelo, 'del alta con los cuatro campos');
        $id = (int) $modelo['id'];

        $fila = AgendaTask::find($id);
        $this->assertSame('openclaw', $fila->assignee);
        $this->assertSame('dots', $fila->origin);
        $this->assertSame('workboard', $fila->link_type);
        $this->assertSame('9c16cbb9-a2bd-485b-a579-439c9f5ac0ee', $fila->link_ref);

        /* PATCH cambia responsable y vínculo; `origin` se ignora (200 y sigue «dots»). */
        $this->patchJson(self::RUTA . '/' . $id, [
            'assignee'  => 'owner',
            'link_type' => 'mision',
            'link_ref'  => 'agenda-openclaw-empuje',
            'origin'    => 'panel',
        ], $this->headers_claude())->assertStatus(200)
            ->assertJsonPath('model.assignee', 'owner')
            ->assertJsonPath('model.link_type', 'mision')
            ->assertJsonPath('model.link_ref', 'agenda-openclaw-empuje')
            ->assertJsonPath('model.origin', 'dots');

        $fila = AgendaTask::find($id);
        $this->assertSame('owner', $fila->assignee);
        $this->assertSame('mision', $fila->link_type);
        $this->assertSame('agenda-openclaw-empuje', $fila->link_ref);
        $this->assertSame('dots', $fila->origin, 'origin no se edita.');

        /* link_type = null limpia los dos. */
        $this->patchJson(self::RUTA . '/' . $id, ['link_type' => null], $this->headers_claude())
            ->assertStatus(200)
            ->assertJsonPath('model.link_type', null)
            ->assertJsonPath('model.link_ref', null);
        $fila = AgendaTask::find($id);
        $this->assertNull($fila->link_type);
        $this->assertNull($fila->link_ref);

        /* Los otros dos orígenes válidos entran (control positivo de los 422 de abajo). */
        $this->assertSame('conversacion', $this->crear_por_claude($admin, ['title' => 'o1', 'origin' => 'conversacion'])['origin']);
        $this->assertSame('openclaw', $this->crear_por_claude($admin, ['title' => 'o2', 'origin' => 'openclaw'])['origin']);

        /* 422 en el alta: nada se escribe. Cada caso con su body inválido. */
        $antes = $this->cantidad_de_tareas($admin);
        $casos = [
            'assignee' => ['assignee' => 'lucas'],
            'origin'   => ['origin' => 'panel'],
            'origin_x' => ['origin' => 'x'],
            'link_type' => ['link_type' => 'jira', 'link_ref' => 'ABC-1'],
            'sin_ref'  => ['link_type' => 'workboard'],
            'sin_tipo' => ['link_ref' => 'abc'],
            'ref_rara' => ['link_type' => 'mision', 'link_ref' => 'a b/c'],
            'ref_larga' => ['link_type' => 'mision', 'link_ref' => str_repeat('a', 121)],
        ];
        foreach ($casos as $nombre => $extra) {
            $this->postJson(self::RUTA, array_merge(['admin_id' => $admin->id, 'title' => 'Inválida ' . $nombre], $extra), $this->headers_claude())
                ->assertStatus(422);
        }
        $this->assertSame($antes, $this->cantidad_de_tareas($admin), 'Los 422 no escriben.');

        /* Control positivo: la de 120 caracteres con los caracteres permitidos entra. */
        $largo = str_repeat('a', 117) . '.:-';
        $ok    = $this->crear_por_claude($admin, ['title' => 'Referencia de 120', 'link_type' => 'mision', 'link_ref' => $largo]);
        $this->assertSame($largo, $ok['link_ref']);

        /* 422 en la edición: la fila queda como estaba. */
        $edicion = [
            ['assignee' => 'lucas'],
            ['link_type' => 'jira'],
            ['link_type' => 'mision'],
            ['link_ref' => 'abc'],
            ['link_type' => 'mision', 'link_ref' => 'a b/c'],
        ];
        $antes_fila = AgendaTask::find($id)->only(['assignee', 'origin', 'link_type', 'link_ref']);
        foreach ($edicion as $body) {
            $this->patchJson(self::RUTA . '/' . $id, $body, $this->headers_claude())->assertStatus(422);
        }
        $this->assertSame($antes_fila, AgendaTask::find($id)->only(['assignee', 'origin', 'link_type', 'link_ref']));
    }

    /**
     * CA2 (duplicar): la copia conserva el responsable, NO el vínculo, y es del panel.
     *
     * @return void
     */
    public function test_duplicar_copia_responsable_sin_vinculo(): void
    {
        $admin  = $this->admin_por_defecto();
        $modelo = $this->crear_por_claude($admin, [
            'title'     => 'Tarea de OpenClaw',
            'date'      => '2026-11-02',
            'assignee'  => 'openclaw',
            'origin'    => 'dots',
            'link_type' => 'workboard',
            'link_ref'  => 'tarjeta-123',
        ]);

        $copia = $this->como($admin)->postJson('/api/admin/agenda/' . $modelo['id'] . '/duplicate')
            ->assertStatus(201)->json('model');

        $this->assertNotSame($modelo['id'], $copia['id']);
        $this->assertSame('openclaw', $copia['assignee']);
        $this->assertNull($copia['link_type']);
        $this->assertNull($copia['link_ref']);
        $this->assertSame('panel', $copia['origin']);

        $fila = AgendaTask::find($copia['id']);
        $this->assertSame('openclaw', $fila->assignee);
        $this->assertNull($fila->link_type);
        $this->assertNull($fila->link_ref);
        $this->assertSame('panel', $fila->origin);

        /* La original no cambió. */
        $this->assertSame('workboard', AgendaTask::find($modelo['id'])->link_type);
    }

    /**
     * CA3: filtro por responsable y vencidas. Admin X con A..G y un H de otro admin.
     *
     * @return void
     */
    public function test_filtro_por_responsable_y_vencidas(): void
    {
        $x    = $this->admin_por_defecto();
        $otro = $this->crear_admin('Otro admin');

        $a = $this->crear_por_claude($x, ['title' => 'A owner vencida', 'date' => '2026-10-29']);
        $b = $this->crear_por_claude($x, ['title' => 'B openclaw vencida', 'date' => '2026-10-30', 'assignee' => 'openclaw']);
        $c = $this->crear_por_claude($x, ['title' => 'C owner hecha', 'date' => '2026-10-28']);
        $this->postJson(self::RUTA . '/' . $c['id'] . '/complete', [], $this->headers_claude())->assertStatus(200);
        $d = $this->crear_por_claude($x, ['title' => 'D owner hoy 10:00', 'date' => '2026-10-31', 'start_time' => '10:00']);
        $e = $this->crear_por_claude($x, ['title' => 'E openclaw hoy', 'date' => '2026-10-31', 'assignee' => 'openclaw']);
        $f = $this->crear_por_claude($x, ['title' => 'F diaria', 'date' => '2026-10-25', 'repeat' => 'daily']);
        $g = $this->crear_por_claude($x, ['title' => 'G bandeja owner']);
        $h = $this->crear_por_claude($otro, ['title' => 'H de otro admin', 'date' => '2026-10-20']);

        $base = self::RUTA . '?admin_id=' . $x->id . '&desde=2026-10-31&hasta=2026-10-31&incluir_completadas=0';
        $ids  = function (array $lista) {
            $resultado = array_map(function ($t) {
                return (int) $t['id'];
            }, $lista);
            sort($resultado);

            return $resultado;
        };

        /* Todas: ocurrencias de hoy [D, E, F]; vencidas [A, B] por fecha; bandeja [G]. */
        $todo = $this->getJson($base . '&vencidas=1', $this->headers_claude());
        $todo->assertStatus(200);
        $this->assertSame($ids([$d, $e, $f]), $ids((array) $todo->json('ocurrencias')));
        $this->assertSame([(int) $a['id'], (int) $b['id']], array_map(function ($t) {
            return (int) $t['id'];
        }, (array) $todo->json('vencidas')), 'Las vencidas van por fecha y sin la repetida, la hecha ni la de otro admin.');
        $this->assertSame([(int) $g['id']], $ids((array) $todo->json('bandeja')));
        foreach ((array) $todo->json('vencidas') as $vencida) {
            $this->assert_forma_de_tarea($vencida, 'en vencidas');
        }

        /* Sólo de OpenClaw: [E], vencidas [B], bandeja vacía. */
        $solo = $this->getJson($base . '&vencidas=true&assignee=openclaw', $this->headers_claude());
        $solo->assertStatus(200);
        $this->assertSame([(int) $e['id']], $ids((array) $solo->json('ocurrencias')));
        $this->assertSame([(int) $b['id']], $ids((array) $solo->json('vencidas')));
        $this->assertSame([], (array) $solo->json('bandeja'));

        /* Sólo del dueño, sin vencidas pedidas: la clave no aparece. */
        $dueno = $this->getJson($base . '&assignee=owner', $this->headers_claude());
        $dueno->assertStatus(200);
        $this->assertSame($ids([$d, $f]), $ids((array) $dueno->json('ocurrencias')));
        $this->assertArrayNotHasKey('vencidas', (array) $dueno->json());

        /* vencidas=0 y vencidas=false: sin clave. */
        $this->assertArrayNotHasKey('vencidas', (array) $this->getJson($base . '&vencidas=0', $this->headers_claude())->json());
        $this->assertArrayNotHasKey('vencidas', (array) $this->getJson($base . '&vencidas=false', $this->headers_claude())->json());

        /* El panel devuelve las cuatro claves en cada ocurrencia. */
        $panel = $this->listar($x, '2026-10-31', '2026-10-31');
        $this->assertNotEmpty($panel['ocurrencias']);
        foreach ($panel['ocurrencias'] as $ocurrencia) {
            foreach (['assignee', 'origin', 'link_type', 'link_ref'] as $clave) {
                $this->assertArrayHasKey($clave, $ocurrencia, 'El panel trae ' . $clave);
            }
        }
    }

    /**
     * CA3 (tope): las vencidas llegan como mucho a 200, las más viejas primero.
     *
     * @return void
     */
    public function test_las_vencidas_tienen_tope_de_doscientas(): void
    {
        $x = $this->admin_por_defecto();

        $filas = [];
        for ($i = 0; $i < 205; $i++) {
            $filas[] = [
                'admin_id'   => $x->id,
                'title'      => 'Vencida ' . $i,
                'date'       => '2026-09-01',
                'created_via' => 'claude',
                'created_at' => '2026-09-01 10:00:00',
                'updated_at' => '2026-09-01 10:00:00',
            ];
        }
        AgendaTask::insert($filas);

        $respuesta = $this->getJson(self::RUTA . '?admin_id=' . $x->id . '&vencidas=true', $this->headers_claude());
        $respuesta->assertStatus(200);
        $this->assertCount(200, (array) $respuesta->json('vencidas'));
    }

    /**
     * CA3 (errores): valores inválidos de los filtros son 422 con el error en la clave del parámetro, y el
     * mismo pedido con valores válidos es 200 (control positivo).
     *
     * @return void
     */
    public function test_filtros_invalidos_son_422(): void
    {
        $x = $this->admin_por_defecto();

        $this->getJson(self::RUTA . '?admin_id=' . $x->id . '&assignee=openclaw&vencidas=true', $this->headers_claude())->assertStatus(200);

        $this->getJson(self::RUTA . '?admin_id=' . $x->id . '&assignee=x', $this->headers_claude())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignee']);

        $this->getJson(self::RUTA . '?admin_id=' . $x->id . '&vencidas=quizas', $this->headers_claude())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vencidas']);
    }

    /**
     * CA4: la migración deja las cuatro columnas con sus defaults, completa `origin` de lo creado por Claude
     * antes de la misión y se puede correr dos veces. (Sólo DML: nada de DDL dentro de la transacción del
     * test, porque un ALTER confirmaría la transacción.)
     *
     * @return void
     */
    public function test_migracion_agrega_columnas_y_completa_el_origen(): void
    {
        foreach (['assignee', 'origin', 'link_type', 'link_ref'] as $columna) {
            $this->assertTrue(Schema::hasColumn('agenda_tasks', $columna), 'Falta la columna ' . $columna);
        }

        $admin = $this->crear_admin();
        $ahora = '2026-10-31 10:00:00';

        /* Filas «de antes»: sin tocar las columnas nuevas, así que quedan con sus defaults. */
        $id_claude = DB::table('agenda_tasks')->insertGetId([
            'admin_id' => $admin->id, 'title' => 'Vieja de Claude', 'created_via' => 'claude', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $id_panel = DB::table('agenda_tasks')->insertGetId([
            'admin_id' => $admin->id, 'title' => 'Vieja del panel', 'created_via' => 'panel', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $fila = DB::table('agenda_tasks')->where('id', $id_claude)->first();
        $this->assertSame('owner', $fila->assignee, 'Default de assignee.');
        $this->assertSame('panel', $fila->origin, 'Default de origin.');
        $this->assertNull($fila->link_type);
        $this->assertNull($fila->link_ref);

        /* Una de Claude que ya tiene origen «dots» no se pisa. */
        $id_dots = DB::table('agenda_tasks')->insertGetId([
            'admin_id' => $admin->id, 'title' => 'De Dots', 'created_via' => 'claude', 'origin' => 'dots', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $ruta = base_path('database/migrations/2026_11_26_110000_add_assignee_origin_link_to_agenda_tasks_table.php');
        $this->assertFileExists($ruta);
        require_once $ruta;
        $migracion = new \AddAssigneeOriginLinkToAgendaTasksTable();

        $migracion->up();
        $migracion->up();

        $this->assertSame('conversacion', DB::table('agenda_tasks')->where('id', $id_claude)->value('origin'));
        $this->assertSame('panel', DB::table('agenda_tasks')->where('id', $id_panel)->value('origin'));
        $this->assertSame('dots', DB::table('agenda_tasks')->where('id', $id_dots)->value('origin'));
    }

    /**
     * CA5: el catálogo documenta los parámetros nuevos y la convención `frena` de las etapas de agentes.
     *
     * @return void
     */
    public function test_el_catalogo_documenta_los_campos_nuevos(): void
    {
        $endpoints = (array) config('claude_catalog.endpoints');

        $nombres = function (string $ruta) use ($endpoints): array {
            $this->assertArrayHasKey($ruta, $endpoints);
            $parametros = (array) ($endpoints[$ruta]['parametros'] ?? []);

            return array_map(function ($p) {
                return $p['nombre'];
            }, $parametros);
        };

        $get = $nombres('GET api/claude/agenda');
        $this->assertContains('assignee', $get);
        $this->assertContains('vencidas', $get);

        $post = $nombres('POST api/claude/agenda');
        foreach (['assignee', 'origin', 'link_type', 'link_ref'] as $campo) {
            $this->assertContains($campo, $post, 'POST agenda documenta ' . $campo);
        }

        $patch = $nombres('PATCH api/claude/agenda/{id}');
        foreach (['assignee', 'link_type', 'link_ref'] as $campo) {
            $this->assertContains($campo, $patch, 'PATCH agenda documenta ' . $campo);
        }
        $this->assertNotContains('origin', $patch, 'origin no se edita.');

        $texto = json_encode($endpoints['POST api/claude/agent-implementations/{id}/stages/{n}'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('frena_nota', $texto);
        $this->assertStringContainsString('frena', $texto);

        $texto_get = json_encode($endpoints['GET api/claude/agenda'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('vencidas', $texto_get);
    }
}
