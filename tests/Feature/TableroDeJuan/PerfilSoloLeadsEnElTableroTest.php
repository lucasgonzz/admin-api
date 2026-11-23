<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\Pipeline;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\AdminSoloLeadsPolicy;
use App\Services\Pipelines\PipelineOpportunityService;
use Carbon\Carbon;
use Database\Seeders\PipelineAgentesSeeder;
use Illuminate\Support\Facades\Route;

/**
 * CA12 de la etapa E1 (misión embudo-juan-pipeline): el perfil «Sólo Leads» (Juan) entra al tablero del pipeline de
 * leads, a sus tarjetas, a «Registrar llamada» y a «Mis tareas», y a NADA más del CRM.
 *
 * Qué se protege:
 *  1. Lo que se abre (200/201): el tablero del pipeline de leads, la ficha, mover, notas, el PUT de la próxima acción,
 *     registrar y listar llamadas, mis tareas y marcar hecha una propia.
 *  2. Lo que sigue cerrado (403 `{code: solo_leads}`): el tablero y las tarjetas de OTRO pipeline (la regla se resuelve
 *     contra la base, no sólo por la URL), borrar, alta masiva, candidatos, la configuración de pipelines y etapas, el
 *     listado de admins y `GET task` (el test de E5 lo exige en 403).
 *  3. Una tarea ajena es 403 con `{code: tarea_ajena}`.
 *  4. La salida del tablero sale filtrada pero útil (`stage.name`, `subject.name`, `last_activity`) y sin la Meet.
 *  5. `GET pipelines` lista sólo el pipeline de leads; el PUT no deja cambiar el responsable pero sí la próxima acción.
 *  6. La lista nueva de la política (`RUTAS_DEL_TABLERO`) es exactamente la del plan y cada entrada es una ruta real con
 *     el middleware del perfil; `RUTAS_PERMITIDAS` no cambia.
 *  7. Un admin común no nota nada (ve todos los pipelines).
 *
 * Hoy están EN ROJO los casos que abren el tablero (ninguna ruta del tablero está en la lista del perfil y las rutas nuevas
 * no existen) y los que dependen del seeder del tablero. Los casos de 403 son además guardas de regresión: hoy ya dan 403
 * porque el perfil no tiene nada del CRM, y tienen que seguir dándolo cuando se abra el tablero.
 */
class PerfilSoloLeadsEnElTableroTest extends BaseDelTableroDeJuan
{
    /** @var Pipeline El pipeline «Leads». */
    private $pipeline_de_leads_sembrado;

    /** @var Pipeline El pipeline «Agentes» (otro pipeline del CRM, vedado para el perfil). */
    private $pipeline_de_agentes;

    /** @var PipelineOpportunity Tarjeta de un lead, en el pipeline de leads. */
    private $tarjeta_de_lead;

    /** @var PipelineOpportunity Tarjeta de un cliente, en el pipeline de agentes. */
    private $tarjeta_de_agentes;

    /** @var \App\Models\Lead */
    private $lead;

    /** @var \App\Models\AdminTask Tarea asignada a Juan. */
    private $tarea_propia;

    /** @var \App\Models\AdminTask Tarea asignada a otro admin. */
    private $tarea_ajena;

    /** @var \App\Models\Admin Otro admin (responsable que Juan no puede asignar). */
    private $otro_admin;

    /**
     * Arma el tablero, el pipeline de agentes, una tarjeta en cada uno, las tareas y loguea a Juan.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline_de_leads_sembrado = $this->sembrar_el_tablero();

        $this->seed(PipelineAgentesSeeder::class);
        $this->pipeline_de_agentes = Pipeline::query()->where('slug', 'agentes')->firstOrFail();

        $this->otro_admin = $this->crear_admin_del_tablero('Otro admin');

        /* La tarjeta del lead: con responsable y próxima acción, así el PUT puede tocar sólo la nota. */
        $this->lead            = $this->crear_lead_del_tablero('contactado');
        $this->tarjeta_de_lead = $this->dar_de_alta_en($this->lead, 'interesados');
        PipelineOpportunity::query()->whereKey($this->tarjeta_de_lead->id)->update([
            'owner_admin_id'     => $this->juan->id,
            'next_action_at'     => Carbon::parse('2026-11-25 11:00:00', self::$ZONA_DEL_TABLERO),
            'next_action_note'   => 'Llamar',
            'next_action_source' => 'manual',
        ]);

        /* La tarjeta del otro pipeline (un cliente en «Agentes»). */
        $cliente  = $this->crear_cliente_del_tablero();
        $resultado = app(PipelineOpportunityService::class)->alta_masiva(
            $this->pipeline_de_agentes,
            [['type' => 'client', 'id' => (int) $cliente->id]],
            null,
            null,
            'Alta de prueba',
            null
        );
        $this->tarjeta_de_agentes = PipelineOpportunity::query()->findOrFail((int) $resultado['creadas'][0]);

        $this->tarea_propia = $this->crear_tarea(['title' => 'Tarea de Juan', 'lead_id' => $this->lead->id], [$this->juan->id]);
        $this->tarea_ajena  = $this->crear_tarea(['title' => 'Tarea de otro'], [$this->otro_admin->id]);

        $this->loguear_a($this->juan);
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * Reemplaza los marcadores `{nombre}` de una uri o de un valor del payload por los ids reales de este test.
     *
     * @param mixed $valor Cadena con marcadores, o cualquier otro valor (se devuelve igual).
     *
     * @return mixed
     */
    private function resolver($valor)
    {
        if (is_array($valor)) {
            $resuelto = [];
            foreach ($valor as $clave => $interno) {
                $resuelto[$clave] = $this->resolver($interno);
            }

            return $resuelto;
        }

        if (! is_string($valor) || strpos($valor, '{') === false) {
            return $valor;
        }

        $ids = [
            '{pipeline_lead}'    => (int) $this->pipeline_de_leads_sembrado->id,
            '{pipeline_agentes}' => (int) $this->pipeline_de_agentes->id,
            '{op_lead}'          => (int) $this->tarjeta_de_lead->id,
            '{op_agentes}'       => (int) $this->tarjeta_de_agentes->id,
            '{lead}'             => (int) $this->lead->id,
            '{tarea_propia}'     => (int) $this->tarea_propia->id,
            '{tarea_ajena}'      => (int) $this->tarea_ajena->id,
            '{otro_admin}'       => (int) $this->otro_admin->id,
            '{stage_volver}'     => (int) $this->etapa_por_clave('esperando_llamada')->id,
        ];

        /* Un valor que ES un marcador (ej. el `stage_id` del move) tiene que volver como entero, no como texto. */
        if (isset($ids[$valor])) {
            return $ids[$valor];
        }

        return strtr($valor, array_map('strval', $ids));
    }

    /**
     * Foto de lo que el perfil NO tiene que poder cambiar con un pedido rechazado.
     *
     * @return array<string, mixed>
     */
    private function foto_del_crm(): array
    {
        return [
            'pipelines'     => Pipeline::query()->count(),
            'etapas'        => PipelineStage::query()->count(),
            'oportunidades' => PipelineOpportunity::query()->count(),
            'tarjeta_lead'  => PipelineOpportunity::query()->whereKey($this->tarjeta_de_lead->id)->value('stage_id'),
            'tarjeta_ag'    => PipelineOpportunity::query()->whereKey($this->tarjeta_de_agentes->id)->first()->only(['stage_id', 'owner_admin_id', 'next_action_note']),
            'tarea_ajena'   => $this->tarea_ajena->fresh()->only(['is_done', 'title']),
            'tareas'        => \App\Models\AdminTask::query()->count(),
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Lo que se abre
    // ------------------------------------------------------------------------------------------

    /**
     * Cada ruta del tablero con su pedido: nombre => [método, uri con marcadores, payload, status esperado].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int}>
     */
    public function rutas_abiertas_provider(): array
    {
        return [
            'GET pipelines/meta'                  => ['GET', '/api/admin/pipelines/meta', [], 200],
            'GET pipelines'                       => ['GET', '/api/admin/pipelines', [], 200],
            'GET pipelines/{lead}/opportunities'  => ['GET', '/api/admin/pipelines/{pipeline_lead}/opportunities', [], 200],
            'GET pipeline-opportunities/{id}'     => ['GET', '/api/admin/pipeline-opportunities/{op_lead}', [], 200],
            'PUT pipeline-opportunities/{id}'     => ['PUT', '/api/admin/pipeline-opportunities/{op_lead}', ['next_action_note' => 'Llamar a las 11'], 200],
            'POST pipeline-opportunities/move'    => ['POST', '/api/admin/pipeline-opportunities/{op_lead}/move', ['stage_id' => '{stage_volver}'], 200],
            'POST pipeline-opportunities/notes'   => ['POST', '/api/admin/pipeline-opportunities/{op_lead}/notes', ['body' => 'Hablamos por teléfono', 'channel' => 'call'], 201],
            'POST lead/{id}/llamadas'             => ['POST', '/api/admin/lead/{lead}/llamadas', ['atendio' => false, 'proxima_llamada_at' => '2026-11-25 11:00'], 201],
            'GET lead/{id}/llamadas'              => ['GET', '/api/admin/lead/{lead}/llamadas', [], 200],
            'GET lead-tablero/mis-tareas'         => ['GET', '/api/admin/lead-tablero/mis-tareas', [], 200],
            'POST lead-tablero/tareas/{id}/hecha' => ['POST', '/api/admin/lead-tablero/tareas/{tarea_propia}/hecha', [], 200],
        ];
    }

    /**
     * CA12. Cada ruta del tablero responde con su status normal para el perfil «Sólo Leads».
     *
     * @dataProvider rutas_abiertas_provider
     *
     * @param string               $metodo
     * @param string               $uri
     * @param array<string, mixed> $payload
     * @param int                  $status_esperado
     *
     * @return void
     */
    public function test_el_perfil_entra_al_tablero(string $metodo, string $uri, array $payload, int $status_esperado): void
    {
        $respuesta = $this->json($metodo, $this->resolver($uri), $this->resolver($payload));

        $this->assertSame(
            $status_esperado,
            $respuesta->getStatusCode(),
            $metodo . ' ' . $uri . ' tendría que dar ' . $status_esperado . ' para Juan; dio ' . $respuesta->getStatusCode() . ': ' . substr((string) $respuesta->getContent(), 0, 300)
        );
    }

    /**
     * CA12. El tablero que ve Juan llega filtrado pero con lo que pinta la tarjeta: etapa, sujeto, última actividad,
     * y sin la videollamada (`call`).
     *
     * @return void
     */
    public function test_la_tarjeta_llega_con_lo_que_pinta_la_pantalla_y_sin_la_meet(): void
    {
        $respuesta = $this->getJson('/api/admin/pipelines/' . $this->pipeline_de_leads_sembrado->id . '/opportunities');
        $respuesta->assertStatus(200);

        $tarjetas = $respuesta->json('opportunities');
        $this->assertNotEmpty($tarjetas, 'El tablero trae las tarjetas.');

        $tarjeta = $tarjetas[0];
        $this->assertSame('Interesados', $tarjeta['stage']['name'], 'Llega la etapa con su nombre.');
        $this->assertNotEmpty($tarjeta['subject']['name'], 'Llega el nombre del sujeto.');
        $this->assertNotNull($tarjeta['last_activity'], 'Llega la última actividad.');
        $this->assertArrayHasKey('owner', $tarjeta);
        $this->assertArrayNotHasKey('call', $tarjeta, 'La Meet con su transcripción queda afuera.');
        $this->assertArrayNotHasKey('call', $tarjeta['subject']);
        $this->assertArrayHasKey('resumen', $respuesta->json(), 'El resumen del tablero llega.');
    }

    /**
     * CA12. `GET pipelines` para Juan lista sólo el pipeline `lead`, aunque haya otros; un admin común ve todos.
     *
     * @return void
     */
    public function test_get_pipelines_lista_solo_el_de_leads(): void
    {
        $this->crear_pipeline_extra();

        $para_juan = $this->getJson('/api/admin/pipelines');
        $para_juan->assertStatus(200);
        $this->assertSame(
            [(int) $this->pipeline_de_leads_sembrado->id],
            array_map('intval', array_column($para_juan->json('pipelines'), 'id')),
            'Juan ve sólo el pipeline de leads.'
        );

        $this->loguear_a($this->otro_admin);
        $para_otro = $this->getJson('/api/admin/pipelines');
        $para_otro->assertStatus(200);
        $ids = array_map('intval', array_column($para_otro->json('pipelines'), 'id'));
        $this->assertContains((int) $this->pipeline_de_leads_sembrado->id, $ids);
        $this->assertContains((int) $this->pipeline_de_agentes->id, $ids, 'Un admin común sigue viendo todos los pipelines.');
        $this->assertGreaterThanOrEqual(3, count($ids));

        /* Y el admin común entra al tablero de Agentes sin problema. */
        $this->getJson('/api/admin/pipelines/' . $this->pipeline_de_agentes->id . '/opportunities')->assertStatus(200);
    }

    /**
     * Un tercer pipeline cualquiera, para que «sólo el de leads» no dependa de cuántos hay sembrados.
     *
     * @return void
     */
    private function crear_pipeline_extra(): void
    {
        Pipeline::create([
            'name'         => 'Pipeline extra de prueba',
            'description'  => 'Para el test.',
            'lost_reasons' => ['Otro'],
            'sort_order'   => 90,
        ]);
    }

    /**
     * CA12. El PUT de la oportunidad le deja a Juan cambiar la próxima acción pero NO el responsable: el `owner_admin_id`
     * que mande se descarta y la nota se guarda.
     *
     * @return void
     */
    public function test_el_put_no_cambia_el_responsable_pero_si_la_proxima_accion(): void
    {
        $this->putJson('/api/admin/pipeline-opportunities/' . $this->tarjeta_de_lead->id, [
            'owner_admin_id'   => $this->otro_admin->id,
            'next_action_note' => 'n',
        ])->assertStatus(200);

        $tarjeta = $this->tarjeta_de_lead->fresh();
        $this->assertSame((int) $this->juan->id, (int) $tarjeta->owner_admin_id, 'El responsable no cambia.');
        $this->assertSame('n', (string) $tarjeta->next_action_note, 'La nota de la próxima acción sí se guarda.');
    }

    // ------------------------------------------------------------------------------------------
    // Lo que sigue cerrado
    // ------------------------------------------------------------------------------------------

    /**
     * Lo que Juan no puede hacer: nombre => [método, uri con marcadores, payload].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public function rutas_cerradas_provider(): array
    {
        return [
            'tablero de OTRO pipeline'                => ['GET', '/api/admin/pipelines/{pipeline_agentes}/opportunities', []],
            'ficha de una tarjeta de OTRO pipeline'   => ['GET', '/api/admin/pipeline-opportunities/{op_agentes}', []],
            'PUT sobre una tarjeta de OTRO pipeline'  => ['PUT', '/api/admin/pipeline-opportunities/{op_agentes}', ['next_action_note' => 'x']],
            'mover una tarjeta de OTRO pipeline'      => ['POST', '/api/admin/pipeline-opportunities/{op_agentes}/move', ['stage_id' => 1]],
            'nota en una tarjeta de OTRO pipeline'    => ['POST', '/api/admin/pipeline-opportunities/{op_agentes}/notes', ['body' => 'x']],
            'borrar una tarjeta'                      => ['DELETE', '/api/admin/pipeline-opportunities/{op_lead}', []],
            'alta masiva en el pipeline de leads'     => ['POST', '/api/admin/pipelines/{pipeline_lead}/opportunities', ['subjects' => []]],
            'candidatos del pipeline de leads'        => ['GET', '/api/admin/pipelines/{pipeline_lead}/candidates', []],
            'lista general de tarjetas'               => ['GET', '/api/admin/pipeline-opportunities', []],
            'agenda de tarjetas'                      => ['GET', '/api/admin/pipeline-opportunities/agenda', []],
            'crear un pipeline'                       => ['POST', '/api/admin/pipelines', ['name' => 'Otro']],
            'editar el pipeline de leads'             => ['PUT', '/api/admin/pipelines/{pipeline_lead}', ['name' => 'Hackeado']],
            'agregar una etapa'                       => ['POST', '/api/admin/pipelines/{pipeline_lead}/stages', ['name' => 'Nueva']],
            'listado de admins'                       => ['GET', '/api/admin/admin', []],
            'GET task'                                => ['GET', '/api/admin/task', []],
            'PUT task'                                => ['PUT', '/api/admin/task/{tarea_propia}', ['title' => 'Hackeada']],
        ];
    }

    /**
     * CA12. Cada pedido vedado recibe 403 con `{code: solo_leads}` y no cambia nada en el CRM ni en las tareas.
     *
     * @dataProvider rutas_cerradas_provider
     *
     * @param string               $metodo
     * @param string               $uri
     * @param array<string, mixed> $payload
     *
     * @return void
     */
    public function test_el_perfil_sigue_sin_acceso_al_resto(string $metodo, string $uri, array $payload): void
    {
        $antes = $this->foto_del_crm();

        $respuesta = $this->json($metodo, $this->resolver($uri), $this->resolver($payload));

        $this->assertSame(403, $respuesta->getStatusCode(), $metodo . ' ' . $uri . ' tendría que dar 403 para Juan; dio ' . $respuesta->getStatusCode());
        $this->assertSame('solo_leads', $respuesta->json('code'), 'El 403 trae el código del perfil.');
        $this->assertEquals($antes, $this->foto_del_crm(), 'Un pedido rechazado no deja efectos.');
    }

    /**
     * CA12. Marcar hecha una tarea de OTRO admin es 403 con `{code: tarea_ajena}` (la ruta SÍ está permitida: el rechazo
     * lo da el controlador, no el perfil) y la tarea no cambia.
     *
     * @return void
     */
    public function test_marcar_hecha_una_tarea_ajena_da_tarea_ajena(): void
    {
        $respuesta = $this->postJson('/api/admin/lead-tablero/tareas/' . $this->tarea_ajena->id . '/hecha');

        $respuesta->assertStatus(403);
        $this->assertSame('tarea_ajena', $respuesta->json('code'));
        $this->assertFalse((bool) $this->tarea_ajena->fresh()->is_done);
    }

    /**
     * CA12. Mover una tarjeta de leads cambia la etapa de verdad (no sólo contesta 200).
     *
     * @return void
     */
    public function test_mover_una_tarjeta_de_leads_la_mueve(): void
    {
        $this->postJson('/api/admin/pipeline-opportunities/' . $this->tarjeta_de_lead->id . '/move', [
            'stage_id' => (int) $this->etapa_por_clave('esperando_llamada')->id,
        ])->assertStatus(200);

        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($this->tarjeta_de_lead));
    }

    // ------------------------------------------------------------------------------------------
    // La lista de la política
    // ------------------------------------------------------------------------------------------

    /**
     * CA12. `RUTAS_DEL_TABLERO` es exactamente la lista de §2.1.7 del plan, y cada entrada es una ruta real que lleva el
     * middleware `admin.solo_leads` (si no, el filtro nunca la vería).
     *
     * @return void
     */
    public function test_lista_del_tablero_exacta_y_existente(): void
    {
        $this->assertTrue(defined(AdminSoloLeadsPolicy::class . '::RUTAS_DEL_TABLERO'), 'Falta AdminSoloLeadsPolicy::RUTAS_DEL_TABLERO.');

        $esperadas = [
            'GET api/admin/pipelines/meta',
            'GET api/admin/pipelines',
            'GET api/admin/pipelines/{id}/opportunities',
            'GET api/admin/pipeline-opportunities/{id}',
            'PUT api/admin/pipeline-opportunities/{id}',
            'POST api/admin/pipeline-opportunities/{id}/move',
            'POST api/admin/pipeline-opportunities/{id}/notes',
            'POST api/admin/lead/{id}/llamadas',
            'GET api/admin/lead/{id}/llamadas',
            'GET api/admin/lead-tablero/mis-tareas',
            'POST api/admin/lead-tablero/tareas/{id}/hecha',
        ];

        $reales = constant(AdminSoloLeadsPolicy::class . '::RUTAS_DEL_TABLERO');
        sort($reales);
        sort($esperadas);
        $this->assertSame($esperadas, array_values($reales), 'La lista del tablero es exactamente la del plan.');

        /* Cada entrada existe, con su método, y lleva el middleware del perfil. */
        $registradas = [];
        foreach (Route::getRoutes() as $ruta) {
            foreach (array_diff($ruta->methods(), ['HEAD']) as $metodo) {
                $registradas[$metodo . ' ' . $ruta->uri()] = $ruta->gatherMiddleware();
            }
        }
        foreach ($esperadas as $entrada) {
            $this->assertArrayHasKey($entrada, $registradas, 'La ruta «' . $entrada . '» no existe.');
            $this->assertContains('admin.solo_leads', $registradas[$entrada], 'La ruta «' . $entrada . '» no lleva el middleware admin.solo_leads.');
        }
    }

    /**
     * CA12. La lista vieja (`RUTAS_PERMITIDAS`, 23 rutas) NO cambia: el tablero se agrega en una lista aparte para que los
     * tests de E5 que la fijan sigan verdes; y la política acepta la unión de las dos.
     *
     * @return void
     */
    public function test_la_lista_vieja_no_cambia_y_la_politica_acepta_la_union(): void
    {
        $this->assertCount(23, AdminSoloLeadsPolicy::RUTAS_PERMITIDAS, 'RUTAS_PERMITIDAS sigue teniendo las 23 rutas de E5.');
        $this->assertNotContains('POST api/admin/lead/{id}/llamadas', AdminSoloLeadsPolicy::RUTAS_PERMITIDAS);

        $politica = new AdminSoloLeadsPolicy();
        $this->assertTrue($politica->permite('GET', 'api/admin/lead/chats', []), 'Lo de antes sigue permitido.');
        $this->assertTrue($politica->permite('POST', 'api/admin/lead/{id}/llamadas', ['id' => '5']), 'Registrar llamada está permitido.');
        $this->assertTrue($politica->permite('GET', 'api/admin/pipelines/{id}/opportunities', ['id' => '5']));
        $this->assertFalse($politica->permite('DELETE', 'api/admin/pipeline-opportunities/{id}', ['id' => '5']), 'Borrar sigue vedado.');
        $this->assertFalse($politica->permite('POST', 'api/admin/pipelines/{id}/opportunities', ['id' => '5']), 'El alta masiva sigue vedada.');
        $this->assertFalse($politica->permite('GET', 'api/admin/task', []), 'GET task sigue vedado.');
    }

    /**
     * CA12. El cuerpo del PUT de la oportunidad se filtra a la próxima acción: el responsable se descarta.
     *
     * @return void
     */
    public function test_la_politica_filtra_el_cuerpo_del_put_de_la_oportunidad(): void
    {
        $filtrado = (new AdminSoloLeadsPolicy())->filtrar_cuerpo('PUT', 'api/admin/pipeline-opportunities/{id}', [
            'owner_admin_id'   => 3,
            'next_action_note' => 'n',
            'next_action_at'   => '2026-11-25 11:00',
            'stage_id'         => 9,
        ]);

        $this->assertSame(['next_action_note' => 'n', 'next_action_at' => '2026-11-25 11:00'], $filtrado);
    }
}
