<?php

namespace Tests\Feature\TableroDeJuan;

/**
 * CA12 (parte de tareas) de la etapa E1 (misión embudo-juan-pipeline): «Mis tareas» de Juan, arriba del tablero.
 *
 * Son dos endpoints NUEVOS y no `GET task`: el test de E5 exige 403 en `GET task` para el perfil «Sólo Leads» y
 * `Tasks.vue` trae acciones globales (reordenar, borrar, crear) que el perfil no debe tener.
 *
 *  - `GET lead-tablero/mis-tareas`: las tareas ABIERTAS asignadas al admin autenticado (por el pivote
 *    `assigned_admins` o por la columna `assigned_admin_id`), con el lead resumido, como mucho 100.
 *  - `POST lead-tablero/tareas/{id}/hecha`: la marca como hecha; si no es del admin, 403 `{code: tarea_ajena}`.
 *
 * Hoy están EN ROJO: las dos rutas no existen.
 */
class MisTareasTest extends BaseDelTableroDeJuan
{
    /**
     * Juan lista sólo sus tareas abiertas: las asignadas por el pivote y por la columna legacy, con el lead resumido;
     * no ve las hechas, las de otro admin ni las sin asignar. Un admin común también ve sólo las suyas.
     *
     * @return void
     */
    public function test_lista_solo_las_suyas(): void
    {
        $otro_admin = $this->crear_admin_del_tablero('Otro');
        $lead       = $this->crear_lead_del_tablero('contactado');

        $por_pivote = $this->crear_tarea(['lead_id' => $lead->id, 'title' => 'Llamar a la ferretería', 'created_via' => 'embudo_prueba_creada'], [$this->juan->id]);
        $por_columna = $this->crear_tarea(['title' => 'Tarea con la columna vieja', 'assigned_admin_id' => $this->juan->id]);
        $this->crear_tarea(['title' => 'Ya hecha', 'is_done' => true], [$this->juan->id]);
        $this->crear_tarea(['title' => 'De otro admin'], [$otro_admin->id]);
        $this->crear_tarea(['title' => 'Sin asignar']);

        $this->loguear_a($this->juan);
        $respuesta = $this->getJson('/api/admin/lead-tablero/mis-tareas');
        $respuesta->assertStatus(200);

        $modelos = $respuesta->json('models');
        $ids     = array_column($modelos, 'id');
        sort($ids);
        $esperados = [(int) $por_pivote->id, (int) $por_columna->id];
        sort($esperados);
        $this->assertSame($esperados, array_map('intval', $ids), 'Juan ve sólo sus tareas abiertas (por pivote o por columna).');

        /* La forma de cada tarea: lo que necesita la pantalla para abrir la conversación. */
        $tarea = null;
        foreach ($modelos as $candidata) {
            if ((int) $candidata['id'] === (int) $por_pivote->id) {
                $tarea = $candidata;
            }
        }
        $this->assertNotNull($tarea);
        foreach (['id', 'title', 'content', 'lead_id', 'created_at', 'lead'] as $clave) {
            $this->assertArrayHasKey($clave, $tarea, 'La tarea trae «' . $clave . '».');
        }
        $this->assertSame('Llamar a la ferretería', $tarea['title']);
        $this->assertSame((int) $lead->id, (int) $tarea['lead_id']);
        $this->assertSame((int) $lead->id, (int) $tarea['lead']['id']);
        $this->assertSame($lead->contact_name, $tarea['lead']['contact_name']);
        $this->assertSame($lead->company_name, $tarea['lead']['company_name']);

        /* Un admin común: la ruta es de todos y cada uno ve las suyas. */
        $this->loguear_a($otro_admin);
        $de_otro = $this->getJson('/api/admin/lead-tablero/mis-tareas');
        $de_otro->assertStatus(200);
        $this->assertCount(1, $de_otro->json('models'), 'El otro admin ve su única tarea.');
        $this->assertSame('De otro admin', $de_otro->json('models.0.title'));
    }

    /**
     * La lista corta en 100 tareas.
     *
     * @return void
     */
    public function test_la_lista_corta_en_cien(): void
    {
        for ($numero = 1; $numero <= 105; $numero++) {
            $this->crear_tarea(['title' => 'Tarea ' . $numero], [$this->juan->id]);
        }

        $this->loguear_a($this->juan);
        $respuesta = $this->getJson('/api/admin/lead-tablero/mis-tareas');

        $respuesta->assertStatus(200);
        $this->assertCount(100, $respuesta->json('models'), 'Como mucho 100 tareas.');
    }

    /**
     * Sin sesión, 401.
     *
     * @return void
     */
    public function test_sin_sesion_no_entra(): void
    {
        $this->getJson('/api/admin/lead-tablero/mis-tareas')->assertStatus(401);
    }

    /**
     * Marcar hecha una tarea propia: 200, `is_done`, `done_at` y `done_by_admin_id` = Juan. Marcar una ajena: 403 con
     * `{code: tarea_ajena}` y la tarea no cambia. La tarea hecha deja de estar en la lista.
     *
     * @return void
     */
    public function test_hecha_propia_y_ajena(): void
    {
        $otro_admin = $this->crear_admin_del_tablero('Otro');
        $propia     = $this->crear_tarea(['title' => 'Mía'], [$this->juan->id]);
        $ajena      = $this->crear_tarea(['title' => 'De otro'], [$otro_admin->id]);

        $this->loguear_a($this->juan);

        $this->postJson('/api/admin/lead-tablero/tareas/' . $ajena->id . '/hecha')
            ->assertStatus(403)
            ->assertJson(['code' => 'tarea_ajena']);
        $this->assertFalse((bool) $ajena->fresh()->is_done, 'La tarea ajena no cambia.');
        $this->assertNull($ajena->fresh()->done_by_admin_id);

        $this->postJson('/api/admin/lead-tablero/tareas/' . $propia->id . '/hecha')->assertStatus(200);

        $propia = $propia->fresh();
        $this->assertTrue((bool) $propia->is_done, 'La tarea propia queda hecha.');
        $this->assertNotNull($propia->done_at);
        $this->assertSame((int) $this->juan->id, (int) $propia->done_by_admin_id);

        $restantes = $this->getJson('/api/admin/lead-tablero/mis-tareas')->json('models');
        $this->assertSame([], $restantes, 'La tarea hecha ya no está en la lista.');
    }
}
