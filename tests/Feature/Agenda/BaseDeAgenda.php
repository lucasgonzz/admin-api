<?php

namespace Tests\Feature\Agenda;

use App\Models\Admin;
use App\Models\AgendaTask;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Lo compartido por las pruebas de la Agenda del admin (misión agenda-admin, 31/10/2026).
 *
 * Tres decisiones que valen para todas:
 *
 * 1. **El reloj está clavado** en el sábado 31/10/2026 a las 10:00 de Buenos Aires, con
 *    `Carbon::setTestNow()`, que es lo que lee `AppTime::now()` en testing (no hay reloj virtual de
 *    base fuera de local). Así "hoy" y el rango por defecto de Claude son previsibles.
 * 2. **Se mide lo que queda en la base**, no solo la respuesta: cada prueba que escribe verifica la
 *    fila resultante. Una respuesta linda con la base sin tocar es exactamente el error que se busca.
 * 3. **Cada caso negativo trae su control positivo**: un 422 o un 404 solo no distingue "el freno
 *    frenó" de "el escenario estaba mal armado y fallaba por cualquier otra cosa".
 *
 * No hay `database/factories/` en este repo: los ayudantes arman los modelos a mano. Todo corre
 * adentro de la transacción del test (`DatabaseTransactions`), así que nada queda escrito.
 */
abstract class BaseDeAgenda extends TestCase
{
    use DatabaseTransactions;

    /** El "ahora" de todas las pruebas: sábado 31/10/2026, 10:00 en Buenos Aires. */
    const AHORA = '2026-10-31 10:00:00';

    /** El "hoy" que sale de AHORA. */
    const HOY = '2026-10-31';

    /** Clave de ingesta del bloque claude/* (en el .env del slot está vacía y el middleware es fail-closed). */
    const CLAVE = 'clave-de-prueba-agenda-admin';

    /** Las claves exactas de la forma de una tarea (contrato del plan, igual en panel y Claude). */
    const CLAVES_DE_TAREA = [
        'id', 'admin_id', 'title', 'notes', 'date', 'occurrence_date', 'start_time', 'end_time',
        'all_day', 'duration_minutes', 'color', 'icon', 'repeat', 'repeat_until', 'is_recurring',
        'completed', 'completed_at', 'subtasks', 'subtasks_done', 'subtasks_total', 'sort_order',
        'created_via', 'created_at', 'updated_at',
    ];

    /**
     * Reloj clavado y clave de Claude configurada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Suelta el reloj.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Un admin nuevo, con un email único para no chocar con el fixture de la base del slot.
     *
     * @param string $nombre Nombre visible.
     *
     * @return Admin
     */
    protected function crear_admin(string $nombre = 'Lucas'): Admin
    {
        return Admin::create([
            'name'     => $nombre,
            'email'    => 'agenda-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * El cliente HTTP del test con la sesión de Sanctum de ese admin.
     *
     * @param Admin $admin
     *
     * @return $this
     */
    protected function como(Admin $admin)
    {
        return $this->actingAs($admin, 'sanctum');
    }

    /**
     * Headers con la clave de ingesta de Claude.
     *
     * @return array<string, string>
     */
    protected function headers_claude(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Crea una tarea por el panel y devuelve el `model` de la respuesta (afirmando el 201).
     *
     * @param Admin $admin Dueño.
     * @param array $datos Body del alta.
     *
     * @return array El model serializado.
     */
    protected function crear_por_panel(Admin $admin, array $datos): array
    {
        $respuesta = $this->como($admin)->postJson('/api/admin/agenda', $datos);
        $respuesta->assertStatus(201);

        return (array) $respuesta->json('model');
    }

    /**
     * Lista la agenda del panel en un rango y devuelve el JSON (afirmando el 200).
     *
     * @param Admin  $admin  Dueño.
     * @param string $desde  Primer día.
     * @param string $hasta  Último día.
     * @param array  $extra  Parámetros adicionales de la query.
     *
     * @return array
     */
    protected function listar(Admin $admin, string $desde, string $hasta, array $extra = []): array
    {
        $query = http_build_query(array_merge(['desde' => $desde, 'hasta' => $hasta], $extra));

        $respuesta = $this->como($admin)->getJson('/api/admin/agenda?' . $query);
        $respuesta->assertStatus(200);

        return (array) $respuesta->json();
    }

    /**
     * Las fechas de las ocurrencias de UNA tarea en un listado, en el orden en que vinieron.
     *
     * @param array $listado Respuesta de GET agenda.
     * @param int   $id      Id de la tarea.
     *
     * @return array<int, string>
     */
    protected function fechas_de(array $listado, int $id): array
    {
        $fechas = [];
        foreach ((array) $listado['ocurrencias'] as $ocurrencia) {
            if ((int) $ocurrencia['id'] === $id) {
                $fechas[] = $ocurrencia['occurrence_date'];
            }
        }

        return $fechas;
    }

    /**
     * Cuántas tareas tiene un admin en la base.
     *
     * @param Admin $admin
     *
     * @return int
     */
    protected function cantidad_de_tareas(Admin $admin): int
    {
        return AgendaTask::where('admin_id', $admin->id)->count();
    }

    /**
     * Afirma que el array tiene EXACTAMENTE las claves de la forma de una tarea del contrato.
     *
     * @param array  $modelo El model serializado.
     * @param string $donde  Para el mensaje de error.
     *
     * @return void
     */
    protected function assert_forma_de_tarea(array $modelo, string $donde = ''): void
    {
        $claves = array_keys($modelo);
        sort($claves);

        $esperadas = self::CLAVES_DE_TAREA;
        sort($esperadas);

        $this->assertSame($esperadas, $claves, 'La forma de la tarea no es la del contrato ' . $donde);
    }
}
