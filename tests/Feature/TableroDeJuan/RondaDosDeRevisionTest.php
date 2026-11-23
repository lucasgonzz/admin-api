<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\PipelineActivity;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PanelAgent\AgentePanelTestHelpers;

/**
 * Ronda 2 de la revisión de E1/E1b (misión embudo-juan-pipeline): un test por cada punto que la revisión rechazó o anotó.
 *
 * 1. CA41 literal: «Ferretería López» y «Ferretería López Hnos» con `q=Ferretería López` es `ambiguo`, aunque el primero
 *    le saque 20 puntos al segundo: si otro candidato contiene TODO el texto buscado, no hay unicidad.
 * 2. Un teléfono de menos de 8 dígitos no puntúa por teléfono (no da 100 ni `unico` por coincidencia de sufijo).
 * 3. Un audio ya cargado, reprocesado cuando su `proxima_llamada_at` ya pasó, responde 200 `duplicada: true` y no 422.
 * 4. «Implementación acordada» no le manda a Juan el aviso «Nueva tarea asignada» de una tarea que nace cerrada.
 * 5. «Ya contrató» deja UNA tarea `tablero_ya_contrato` por lead, asignada a quien resuelve el resolver existente.
 * 6. Una llamada sobre un lead cuya tarjeta está en «Cliente» no abre una tarjeta nueva en «Interesados».
 * 7. `lead-tablero/tareas/{id}/hecha` devuelve `done_at`.
 *
 * Son tests nuevos: no tocan los de `7849e2aa` ni `232338d0`.
 */
class RondaDosDeRevisionTest extends BaseDelTableroDeJuan
{
    use AgentePanelTestHelpers;

    /**
     * Siembra el tablero y loguea a un admin común.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();
        $this->loguear_a($this->crear_admin_del_tablero('Lucas'));
    }

    /**
     * @param \App\Models\Lead     $lead
     * @param array<string, mixed> $payload
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function llamar($lead, array $payload)
    {
        return $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', $payload);
    }

    /**
     * Punto 1.
     *
     * @return void
     */
    public function test_ferreteria_lopez_literal_es_ambiguo(): void
    {
        $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Marta', 'company_name' => 'Ferretería López']);
        $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Pablo', 'company_name' => 'Ferretería López Hnos']);

        $respuesta = $this->getJson('/api/admin/lead-tablero/candidatos?' . http_build_query(['q' => 'Ferretería López']));

        $respuesta->assertStatus(200);
        $this->assertSame('ambiguo', $respuesta->json('decision'), 'Dos candidatos contienen todo el texto buscado: no hay unicidad.');
    }

    /**
     * Punto 2.
     *
     * @return void
     */
    public function test_telefono_con_menos_de_ocho_digitos_no_puntua(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Damián', 'company_name' => 'Gradom', 'phone' => '5491164648197']);

        foreach (['8197', '648197', '8'] as $corto) {
            $respuesta = $this->getJson('/api/admin/lead-tablero/candidatos?' . http_build_query(['telefono' => $corto]));
            $respuesta->assertStatus(200);
            $this->assertSame('sin_candidatos', $respuesta->json('decision'), 'Con «' . $corto . '» no se puede identificar a nadie.');
            $this->assertSame([], $respuesta->json('models'));
        }

        $completo = $this->getJson('/api/admin/lead-tablero/candidatos?' . http_build_query(['telefono' => '64648197']));
        $this->assertSame((int) $lead->id, (int) $completo->json('models.0.lead_id'), 'Con 8 dígitos sí.');
    }

    /**
     * Punto 3.
     *
     * @return void
     */
    public function test_audio_reprocesado_con_la_fecha_vencida_es_duplicado(): void
    {
        $agente = $this->crear_agente('Agente de WhatsApp');
        $token  = $this->token_de($agente);
        $lead   = $this->crear_lead_del_tablero('contactado');
        $this->dar_de_alta_en($lead, 'interesados');

        $payload = [
            'atendio'            => true,
            'resultado'          => 'volver_a_llamar',
            'proxima_llamada_at' => '2026-11-23 15:00',
            'origen'             => 'audio_juan',
            'origen_clave'       => 'wa:vencido',
            'notas'              => 'Volver a llamar hoy a la tarde',
        ];

        $uri = '/api/admin/lead/' . $lead->id . '/llamadas';
        $this->pedir_como($token, 'POST', $uri, $payload)->assertStatus(201);

        $this->clavar_el_reloj('2026-11-23 16:30:00');

        $segunda = $this->pedir_como($token, 'POST', $uri, $payload);

        $segunda->assertStatus(200);
        $this->assertTrue((bool) $segunda->json('duplicada'));
        $this->assertSame(1, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());
    }

    /**
     * Punto 4.
     *
     * @return void
     */
    public function test_implementacion_acordada_no_avisa_a_juan_de_una_tarea_que_nace_cerrada(): void
    {
        $lead = $this->crear_lead_del_tablero('link_enviado');
        $this->dar_de_alta_en($lead, 'interesados');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'implementacion_acordada'])->assertStatus(201);

        $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_impl_pedida')->get();
        $this->assertCount(1, $tareas, 'Se mantiene el estado final del plan: una tarea del embudo, ya hecha.');
        $this->assertTrue((bool) $tareas[0]->is_done);
        $this->assertSame(
            0,
            AdminTaskNotification::query()->where('admin_task_id', $tareas[0]->id)->count(),
            'Nadie recibe el aviso «Nueva tarea asignada» de una tarea que se cierra en el mismo pedido.'
        );
    }

    /**
     * Punto 5.
     *
     * @return void
     */
    public function test_ya_contrato_deja_una_sola_tarea_asignada_a_quien_corresponde(): void
    {
        $setter = $this->crear_admin_del_tablero('Quien recibe las tareas de Lucas');
        $setter->es_setter = true;
        $setter->save();

        $lead = $this->crear_lead_del_tablero('implementacion_pedida');
        $this->dar_de_alta_en($lead, 'interesados');

        $this->llamar($lead, ['atendio' => true, 'resultado' => 'ya_contrato'])->assertStatus(201);
        $this->llamar($lead, ['atendio' => true, 'resultado' => 'ya_contrato'])->assertStatus(201);

        $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'tablero_ya_contrato')->where('is_done', false)->get();
        $this->assertCount(1, $tareas, 'Una sola tarea abierta por lead.');
        $this->assertSame([(int) $setter->id], $tareas[0]->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all(), 'Asignada a quien resuelve AdminTaskAssignmentResolver::for_lead_task().');
        $this->assertSame((int) $setter->id, (int) $tareas[0]->assigned_admin_id);
    }

    /**
     * Punto 6.
     *
     * @return void
     */
    public function test_llamada_sobre_un_lead_cliente_no_abre_otra_tarjeta(): void
    {
        $lead    = $this->crear_lead_del_tablero('contactado');
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');
        $this->llamar($lead, ['atendio' => true, 'resultado' => 'ya_contrato'])->assertStatus(201);
        $this->assertSame('cerrado', $this->clave_de_la_columna($tarjeta));

        $respuesta = $this->llamar($lead, ['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Llamé para cobrar la otra mitad.']);

        $respuesta->assertStatus(201);
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'No se abre otra tarjeta en Interesados.');
        $this->assertSame('cerrado', $this->clave_de_la_columna($tarjeta), 'La tarjeta ganada no se mueve.');
        $this->assertNotNull($tarjeta->fresh()->closed_at);
        $this->assertSame(2, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());

        $notas = PipelineActivity::query()->where('opportunity_id', $tarjeta->id)->where('type', 'note')->where('channel', 'call')->pluck('body')->implode(' | ');
        $this->assertStringContainsString('Llamé para cobrar la otra mitad.', $notas, 'Queda en el historial de la tarjeta.');
    }

    /**
     * Punto 7.
     *
     * @return void
     */
    public function test_hecha_devuelve_done_at(): void
    {
        $tarea = $this->crear_tarea(['title' => 'Mía'], [$this->juan->id]);
        $this->loguear_a($this->juan);

        $respuesta = $this->postJson('/api/admin/lead-tablero/tareas/' . $tarea->id . '/hecha');

        $respuesta->assertStatus(200);
        $this->assertTrue((bool) $respuesta->json('model.is_done'));
        $this->assertNotEmpty($respuesta->json('model.done_at'), 'El contrato §2.5 pide `done_at`.');
    }
}
