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
