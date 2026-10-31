<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaTask;
use App\Models\AgendaTaskCompletion;

/**
 * Las tareas repetidas de la Agenda (misión agenda-admin, 31/10/2026).
 *
 * Una repetida es UNA fila (la serie) y sus ocurrencias se calculan al leer, en
 * `AgendaService::ocurrencias_en_rango()`. Lo que se mide acá:
 *
 *  - la expansión diaria, semanal y mensual, con rangos que empiezan antes, adentro y después de
 *    la base, y el corte en `repeat_until` inclusive;
 *  - 🔴 la mensual del 31 NO se desborda (no salta a marzo desde febrero) y vuelve al 31 en los
 *    meses largos, porque cada ocurrencia se calcula desde la base y no desde la anterior;
 *  - hecho/no hecho es POR OCURRENCIA: marcar una no marca las otras;
 *  - una fecha que no es una ocurrencia real es 422 (con su control positivo);
 *  - editar la serie la mueve entera.
 */
class AgendaRepetidasTest extends BaseDeAgenda
{
    /**
     * Diaria: un día tras otro desde la base hasta `repeat_until` inclusive, aunque el rango pida
     * más; nada antes de la base.
     *
     * @return void
     */
    public function test_diaria_se_expande_dia_por_dia_hasta_repeat_until(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, [
            'title'        => 'Revisar tickets',
            'date'         => '2026-11-01',
            'start_time'   => '09:30',
            'repeat'       => 'daily',
            'repeat_until' => '2026-11-05',
        ]);

        $this->assertTrue($serie['is_recurring']);
        $this->assertSame(1, AgendaTask::where('id', $serie['id'])->count(), 'La serie no es una sola fila.');

        $listado = $this->listar($admin, '2026-10-28', '2026-11-10');
        $this->assertSame(
            ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'],
            $this->fechas_de($listado, $serie['id'])
        );

        /* Cada ocurrencia dice su fecha y la base de la serie, con la forma del contrato. */
        foreach ($listado['ocurrencias'] as $ocurrencia) {
            $this->assert_forma_de_tarea($ocurrencia, 'en una ocurrencia expandida');
            $this->assertSame('2026-11-01', $ocurrencia['date']);
            $this->assertSame('09:30', $ocurrencia['start_time']);
            $this->assertTrue($ocurrencia['is_recurring']);
        }

        /* Un rango que empieza en el medio de la serie. */
        $this->assertSame(['2026-11-04', '2026-11-05'], $this->fechas_de($this->listar($admin, '2026-11-04', '2026-11-20'), $serie['id']));

        /* Y uno que queda entero después del fin. */
        $this->assertSame([], $this->fechas_de($this->listar($admin, '2026-11-06', '2026-11-20'), $serie['id']));

        /* Sin fin: sigue. */
        $sin_fin = $this->crear_por_panel($admin, ['title' => 'Sin fin', 'date' => '2026-11-01', 'repeat' => 'daily']);
        $this->assertCount(31, $this->fechas_de($this->listar($admin, '2027-01-01', '2027-01-31'), $sin_fin['id']));
    }

    /**
     * Semanal: cada 7 días desde la base, también cuando el rango arranca lejos de la base.
     *
     * @return void
     */
    public function test_semanal_cae_cada_siete_dias(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Reunión de equipo', 'date' => '2026-11-02', 'repeat' => 'weekly']);

        $this->assertSame(
            ['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30'],
            $this->fechas_de($this->listar($admin, '2026-11-01', '2026-11-30'), $serie['id'])
        );

        $this->assertSame(['2026-11-16'], $this->fechas_de($this->listar($admin, '2026-11-10', '2026-11-20'), $serie['id']));

        /* Lejos de la base (más de un año después): sigue cayendo en lunes. */
        $this->assertSame(
            ['2028-01-03', '2028-01-10'],
            $this->fechas_de($this->listar($admin, '2028-01-01', '2028-01-12'), $serie['id'])
        );
    }

    /**
     * 🔴 Mensual del 31: cae el último día de los meses cortos y VUELVE al 31, sin desbordarse al
     * mes siguiente. Y `repeat_until` corta inclusive.
     *
     * @return void
     */
    public function test_mensual_del_31_no_se_desborda_y_vuelve_al_31(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Cerrar el mes', 'date' => '2026-10-31', 'repeat' => 'monthly', 'repeat_until' => '2027-03-31']);
        $id    = $serie['id'];

        $this->assertSame(['2026-10-31'], $this->fechas_de($this->listar($admin, '2026-10-01', '2026-10-31'), $id));
        $this->assertSame(['2026-11-30'], $this->fechas_de($this->listar($admin, '2026-11-01', '2026-11-30'), $id));
        $this->assertSame(['2026-12-31'], $this->fechas_de($this->listar($admin, '2026-12-01', '2026-12-31'), $id));
        $this->assertSame(['2027-02-28'], $this->fechas_de($this->listar($admin, '2027-02-01', '2027-02-28'), $id));
        /* Si se hubiera desbordado, febrero habría dado 2027-03-03 y marzo arrancaría desde el 28. */
        $this->assertSame(['2027-03-31'], $this->fechas_de($this->listar($admin, '2027-03-01', '2027-03-31'), $id));
        /* Después de repeat_until, nada. */
        $this->assertSame([], $this->fechas_de($this->listar($admin, '2027-04-01', '2027-04-30'), $id));

        /* Un rango de varios meses da una por mes. */
        $this->assertSame(
            ['2026-11-30', '2026-12-31', '2027-01-31'],
            $this->fechas_de($this->listar($admin, '2026-11-01', '2027-02-01'), $id)
        );

        /* Y marcar la del 28 de febrero vale (es ocurrencia real); el 3 de marzo no. */
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2027-03-03'])
            ->assertStatus(422)->assertJsonValidationErrors(['date']);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2027-02-28'])
            ->assertStatus(200)->assertJsonPath('model.occurrence_date', '2027-02-28');
    }

    /**
     * 🔴 Marcar una ocurrencia no marca las otras, y desmarcarla vuelve todo a cero. Marcarla dos
     * veces no crea dos filas.
     *
     * @return void
     */
    public function test_completar_una_ocurrencia_no_marca_las_otras(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Tomar la pastilla', 'date' => '2026-11-01', 'start_time' => '08:00', 'repeat' => 'daily']);
        $id    = $serie['id'];

        $hecha = $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-03'])->assertStatus(200)->json('model');
        $this->assert_forma_de_tarea($hecha, 'al completar una ocurrencia');
        $this->assertSame('2026-11-03', $hecha['occurrence_date']);
        $this->assertSame('2026-11-01', $hecha['date']);
        $this->assertTrue($hecha['completed']);
        $this->assertNotNull($hecha['completed_at']);

        /* Idempotente. */
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-03'])->assertStatus(200);

        $this->assertSame(1, AgendaTaskCompletion::where('agenda_task_id', $id)->count());
        $this->assertSame('2026-11-03', (string) AgendaTaskCompletion::where('agenda_task_id', $id)->value('occurrence_date'));
        $this->assertNull(AgendaTask::find($id)->completed_at, 'Una repetida no tiene que usar completed_at.');

        $listado = $this->listar($admin, '2026-11-01', '2026-11-05');
        $hechas  = [];
        foreach ($listado['ocurrencias'] as $ocurrencia) {
            $hechas[$ocurrencia['occurrence_date']] = $ocurrencia['completed'];
        }
        $this->assertSame([
            '2026-11-01' => false,
            '2026-11-02' => false,
            '2026-11-03' => true,
            '2026-11-04' => false,
            '2026-11-05' => false,
        ], $hechas);

        /* Sin las completadas, falta solo esa. */
        $this->assertSame(
            ['2026-11-01', '2026-11-02', '2026-11-04', '2026-11-05'],
            $this->fechas_de($this->listar($admin, '2026-11-01', '2026-11-05', ['incluir_completadas' => '0']), $id)
        );

        /* Desmarcar (dos veces: idempotente). */
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/uncomplete', ['date' => '2026-11-03'])
            ->assertStatus(200)->assertJsonPath('model.completed', false);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/uncomplete', ['date' => '2026-11-03'])
            ->assertStatus(200)->assertJsonPath('model.completed', false);
        $this->assertSame(0, AgendaTaskCompletion::where('agenda_task_id', $id)->count());
    }

    /**
     * Una fecha que no es ocurrencia real (otro día de la semana, antes de la base, después del
     * fin) o que falta es 422 y no escribe nada. Control: una ocurrencia real pasa.
     *
     * @return void
     */
    public function test_una_fecha_que_no_es_ocurrencia_es_422(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Sacar la basura', 'date' => '2026-11-02', 'repeat' => 'weekly', 'repeat_until' => '2026-11-30']);
        $id    = $serie['id'];

        foreach (['2026-11-03', '2026-10-26', '2026-12-07'] as $fecha) {
            $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => $fecha])
                ->assertStatus(422)->assertJsonValidationErrors(['date']);
        }

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete')
            ->assertStatus(422)->assertJsonValidationErrors(['date']);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '09/11/2026'])
            ->assertStatus(422)->assertJsonValidationErrors(['date']);

        $this->assertSame(0, AgendaTaskCompletion::where('agenda_task_id', $id)->count());

        /* Control positivo: el lunes 9 sí es ocurrencia, y también la última (30, inclusive). */
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-09'])->assertStatus(200);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-30'])->assertStatus(200);
        $this->assertSame(2, AgendaTaskCompletion::where('agenda_task_id', $id)->count());
    }

    /**
     * Editar la serie la edita entera: cambiar la hora cambia todas las ocurrencias, cambiar la
     * fecha base corre todas.
     *
     * @return void
     */
    public function test_editar_la_serie_mueve_todas_las_ocurrencias(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Stand-up', 'date' => '2026-11-02', 'start_time' => '10:00', 'repeat' => 'weekly']);
        $id    = $serie['id'];

        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['start_time' => '11:15', 'date' => '2026-11-03'])->assertStatus(200);

        $listado = $this->listar($admin, '2026-11-01', '2026-11-30');
        $this->assertSame(['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'], $this->fechas_de($listado, $id));
        foreach ($listado['ocurrencias'] as $ocurrencia) {
            $this->assertSame('11:15', $ocurrencia['start_time']);
            $this->assertSame('11:30', $ocurrencia['end_time']);
        }

        $this->assertSame(1, AgendaTask::where('id', $id)->count());
    }

    /**
     * Desmarcar una ocurrencia con DOS marcadas borra solo la pedida.
     *
     * @return void
     */
    public function test_desmarcar_una_ocurrencia_con_dos_marcadas_borra_solo_la_pedida(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Caminar', 'date' => '2026-11-01', 'repeat' => 'daily']);
        $id    = $serie['id'];

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-02'])->assertStatus(200);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-04'])->assertStatus(200);
        $this->assertSame(2, AgendaTaskCompletion::where('agenda_task_id', $id)->count());

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/uncomplete', ['date' => '2026-11-04'])
            ->assertStatus(200)->assertJsonPath('model.completed', false);

        $this->assertSame(
            ['2026-11-02'],
            AgendaTaskCompletion::where('agenda_task_id', $id)->pluck('occurrence_date')->map(function ($f) {
                return substr((string) $f, 0, 10);
            })->all()
        );
        $this->assertSame(['2026-11-02'], $this->fechas_hechas($this->listar($admin, '2026-11-01', '2026-11-05'), $id));
    }

    /**
     * 🔴 El escenario medido por la revisión independiente (31/10/2026): semanal con base lunes
     * 2026-11-02, marcado el 11-09. Correr la base al martes 11-03 tiene que correr lo hecho al
     * 11-10 (misma ocurrencia, la segunda de la serie), sin dejar la fila del 11-09 huérfana; y
     * pasar después a diaria NO puede hacer reaparecer el 11-09 como hecho sin que nadie lo marque.
     *
     * @return void
     */
    public function test_mover_la_serie_corre_lo_hecho_y_no_deja_huerfanas(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Reunión semanal', 'date' => '2026-11-02', 'repeat' => 'weekly']);
        $id    = $serie['id'];

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-09'])->assertStatus(200);

        /* Base +1 día: lo hecho se corre +1 día. */
        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => '2026-11-03'])->assertStatus(200);
        $this->assertSame(['2026-11-10'], $this->fechas_hechas($this->listar($admin, '2026-11-01', '2026-11-30'), $id));
        $this->assertSame(['2026-11-10'], $this->filas_hechas($id), 'Quedó la fila vieja del 11-09.');

        /* Cambiar a diaria: el 11-10 sigue siendo una ocurrencia real y queda; el 11-09 NO reaparece. */
        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['repeat' => 'daily'])->assertStatus(200);
        $this->assertSame(['2026-11-10'], $this->fechas_hechas($this->listar($admin, '2026-11-01', '2026-11-30'), $id));
        $this->assertSame(['2026-11-10'], $this->filas_hechas($id));
    }

    /**
     * 🔴 Mover una serie corre también su FIN. Diaria del 01/11 al 07/11 con el 07/11 hecho, corrida
     * +1 día: sigue teniendo 7 ocurrencias (02/11 al 08/11), el fin queda en el 08/11 y lo hecho pasa
     * al 08/11. Antes quedaba con 6 y la marca del 07/11 se perdía. Hacia atrás corre igual.
     *
     * @return void
     */
    public function test_mover_la_serie_corre_tambien_su_fin(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Semana de cierre', 'date' => '2026-11-01', 'repeat' => 'daily', 'repeat_until' => '2026-11-07']);
        $id    = $serie['id'];

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-07'])->assertStatus(200);

        $movida = $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => '2026-11-02'])->assertStatus(200)->json('model');
        $this->assertSame('2026-11-02', $movida['date']);
        $this->assertSame('2026-11-08', $movida['repeat_until']);
        $this->assertSame('2026-11-08', (string) AgendaTask::find($id)->repeat_until);

        $listado = $this->listar($admin, '2026-10-25', '2026-11-15');
        $this->assertSame(
            ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'],
            $this->fechas_de($listado, $id)
        );
        $this->assertSame(['2026-11-08'], $this->fechas_hechas($listado, $id));
        $this->assertSame(['2026-11-08'], $this->filas_hechas($id), 'La marca del último día se perdió al mover la serie.');

        /* Hacia atrás: −3 días corre base, fin y lo hecho −3 días. */
        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => '2026-10-30'])->assertStatus(200);
        $this->assertSame('2026-11-05', (string) AgendaTask::find($id)->repeat_until);
        $this->assertCount(7, $this->fechas_de($this->listar($admin, '2026-10-25', '2026-11-15'), $id));
        $this->assertSame(['2026-11-05'], $this->filas_hechas($id));
    }

    /**
     * Control positivo: si el mismo PUT trae `repeat_until`, gana lo que vino (también null = sin
     * fin), y el fin NO se corre solo.
     *
     * @return void
     */
    public function test_un_repeat_until_explicito_al_mover_la_serie_se_respeta(): void
    {
        $admin = $this->crear_admin();

        $serie = $this->crear_por_panel($admin, ['title' => 'Con fin pedido', 'date' => '2026-11-01', 'repeat' => 'daily', 'repeat_until' => '2026-11-07']);
        $this->como($admin)->postJson('/api/admin/agenda/' . $serie['id'] . '/complete', ['date' => '2026-11-07'])->assertStatus(200);

        $this->como($admin)->putJson('/api/admin/agenda/' . $serie['id'], ['date' => '2026-11-02', 'repeat_until' => '2026-11-05'])
            ->assertStatus(200)->assertJsonPath('model.repeat_until', '2026-11-05');
        $this->assertSame('2026-11-05', (string) AgendaTask::find($serie['id'])->repeat_until);
        $this->assertSame(
            ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'],
            $this->fechas_de($this->listar($admin, '2026-10-25', '2026-11-15'), $serie['id'])
        );
        /* La hecha del 07/11 (k = 6) caería el 08/11, fuera del fin pedido: se borra. */
        $this->assertSame([], $this->filas_hechas($serie['id']));

        $sin_fin = $this->crear_por_panel($admin, ['title' => 'Sin fin pedido', 'date' => '2026-11-01', 'repeat' => 'daily', 'repeat_until' => '2026-11-07']);
        $this->como($admin)->putJson('/api/admin/agenda/' . $sin_fin['id'], ['date' => '2026-11-02', 'repeat_until' => null])
            ->assertStatus(200)->assertJsonPath('model.repeat_until', null);
        $this->assertNull(AgendaTask::find($sin_fin['id'])->repeat_until);
    }

    /**
     * Mismo tipo, mensual: el remapeo es por índice y respeta el sin-desborde. Base 15 → 31: la
     * del 15/11 pasa al 30/11 y la del 15/2 pasa al 28/2.
     *
     * @return void
     */
    public function test_mover_una_mensual_remapea_por_indice_sin_desborde(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Liquidar sueldos', 'date' => '2026-10-15', 'repeat' => 'monthly']);
        $id    = $serie['id'];

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-15'])->assertStatus(200);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2027-02-15'])->assertStatus(200);

        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['date' => '2026-10-31'])->assertStatus(200);

        $this->assertSame(['2026-11-30', '2027-02-28'], $this->filas_hechas($id));
        $this->assertSame(['2027-02-28'], $this->fechas_hechas($this->listar($admin, '2027-02-01', '2027-02-28'), $id));
        $this->assertSame(['2026-11-30'], $this->fechas_hechas($this->listar($admin, '2026-11-01', '2026-11-30'), $id));
    }

    /**
     * Cambiar el TIPO de repetición conserva solo las hechas que siguen siendo ocurrencias reales
     * de la serie nueva y borra el resto. Semanal desde el 2/11 → mensual: el 2/11 sigue (es la
     * base), el 9/11 y el 16/11 no son del mes y se borran. Lo mismo al acortar la serie con un
     * cambio de tipo y un fin: lo que queda fuera del fin se borra.
     *
     * @return void
     */
    public function test_cambiar_el_tipo_conserva_solo_las_ocurrencias_reales(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Revisar stock', 'date' => '2026-11-02', 'repeat' => 'weekly']);
        $id    = $serie['id'];

        foreach (['2026-11-02', '2026-11-09', '2026-11-16'] as $fecha) {
            $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => $fecha])->assertStatus(200);
        }

        $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['repeat' => 'monthly'])->assertStatus(200);
        $this->assertSame(['2026-11-02'], $this->filas_hechas($id));

        /* Control: si el nuevo tipo sí contiene las fechas (semanal → diaria), se conservan todas. */
        $otra = $this->crear_por_panel($admin, ['title' => 'Otra', 'date' => '2026-11-02', 'repeat' => 'weekly']);
        foreach (['2026-11-02', '2026-11-09'] as $fecha) {
            $this->como($admin)->postJson('/api/admin/agenda/' . $otra['id'] . '/complete', ['date' => $fecha])->assertStatus(200);
        }
        $this->como($admin)->putJson('/api/admin/agenda/' . $otra['id'], ['repeat' => 'daily'])->assertStatus(200);
        $this->assertSame(['2026-11-02', '2026-11-09'], $this->filas_hechas($otra['id']));

        /* Y si el cambio de tipo viene con un fin, lo que queda fuera del fin se borra. */
        $corta = $this->crear_por_panel($admin, ['title' => 'Corta', 'date' => '2026-11-02', 'repeat' => 'weekly']);
        foreach (['2026-11-02', '2026-11-09'] as $fecha) {
            $this->como($admin)->postJson('/api/admin/agenda/' . $corta['id'] . '/complete', ['date' => $fecha])->assertStatus(200);
        }
        $this->como($admin)->putJson('/api/admin/agenda/' . $corta['id'], ['repeat' => 'daily', 'repeat_until' => '2026-11-05'])->assertStatus(200);
        $this->assertSame(['2026-11-02'], $this->filas_hechas($corta['id']), 'La del 11-09 quedó fuera del fin y no se borró.');
    }

    /**
     * Las fechas de las ocurrencias HECHAS de una tarea en un listado.
     *
     * @param array $listado Respuesta de GET agenda.
     * @param int   $id      Id de la tarea.
     *
     * @return array<int, string>
     */
    private function fechas_hechas(array $listado, int $id): array
    {
        $fechas = [];
        foreach ((array) $listado['ocurrencias'] as $ocurrencia) {
            if ((int) $ocurrencia['id'] === $id && $ocurrencia['completed']) {
                $fechas[] = $ocurrencia['occurrence_date'];
            }
        }

        return $fechas;
    }

    /**
     * Las fechas de las filas de `agenda_task_completions` de una tarea, ordenadas.
     *
     * @param int $id Id de la tarea.
     *
     * @return array<int, string>
     */
    private function filas_hechas(int $id): array
    {
        return AgendaTaskCompletion::where('agenda_task_id', $id)
            ->orderBy('occurrence_date')
            ->pluck('occurrence_date')
            ->map(function ($fecha) {
                return substr((string) $fecha, 0, 10);
            })
            ->values()
            ->all();
    }

    /**
     * Pasar de repetida a suelta conserva lo hecho en la fecha base (y limpia las ocurrencias);
     * pasar de suelta hecha a repetida deja hecha la ocurrencia de la base. "Hecha" nunca queda
     * en dos lugares.
     *
     * @return void
     */
    public function test_cambiar_la_repeticion_conserva_lo_hecho_en_la_fecha_base(): void
    {
        $admin = $this->crear_admin();
        $serie = $this->crear_por_panel($admin, ['title' => 'Regar', 'date' => '2026-11-02', 'repeat' => 'daily']);
        $id    = $serie['id'];

        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-02'])->assertStatus(200);
        $this->como($admin)->postJson('/api/admin/agenda/' . $id . '/complete', ['date' => '2026-11-04'])->assertStatus(200);

        $suelta = $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['repeat' => 'none'])->assertStatus(200)->json('model');
        $this->assertTrue($suelta['completed']);
        $this->assertNotNull(AgendaTask::find($id)->completed_at);
        $this->assertSame(0, AgendaTaskCompletion::where('agenda_task_id', $id)->count());

        $de_nuevo = $this->como($admin)->putJson('/api/admin/agenda/' . $id, ['repeat' => 'weekly'])->assertStatus(200)->json('model');
        $this->assertTrue($de_nuevo['completed'], 'La ocurrencia de la base perdió lo hecho.');
        $this->assertNull(AgendaTask::find($id)->completed_at);
        $this->assertSame(1, AgendaTaskCompletion::where('agenda_task_id', $id)->count());
        $this->assertSame('2026-11-02', (string) AgendaTaskCompletion::where('agenda_task_id', $id)->value('occurrence_date'));
    }
}
