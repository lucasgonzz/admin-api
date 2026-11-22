<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Lead;
use App\Services\AdminTaskAssignmentResolver;
use App\Services\LeadAiService;
use App\Services\LeadTareaVendedorService;
use Tests\Feature\Prueba\BaseDePruebaGratuita;

/**
 * Tarea del embudo (la «tarea para Juan») y a quién se le asigna (misión embudo-whatsapp-precios, etapa E2,
 * criterios CA12, CA13 y CA33 del PLAN; §2.2).
 *
 * Cuando el lead pide que se lo carguen (`implementacion_pedida`) o crea su prueba, se crea UNA `AdminTask` por
 * lead y motivo (`created_via = embudo_<motivo>`), con push y aviso en la pila, asignada a todos los admins con
 * el perfil «Sólo Leads» (E5) o `es_vendedor`; si no hay ninguno, a los setters como hasta hoy.
 *
 * Extiende `BaseDePruebaGratuita` porque el CA13 prueba el alta real de la prueba (`POST landing/prueba/alta`).
 * La IA va simulada con `Http::fake` sobre api.anthropic.com. Ningún envío sale de la máquina.
 *
 * Hoy están EN ROJO: no existen `LeadTareaVendedorService` ni `AdminTaskAssignmentResolver::for_embudo_task()`,
 * el alta de la prueba no crea tarea y la transición `implementacion_pedida` no se aplica antes de aprobar.
 */
class TareaParaElVendedorTest extends BaseDePruebaGratuita
{
    use ArmadoDelFlujoDeEntrada;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* El Http de la base ya atiende Meta y los ERP: acá sólo se suma la IA simulada. El resto del armado del
           flujo (pool propio, variantes) no se usa: el alta trae su propia instancia libre. */
        $this->fakear_http_del_flujo(false);
        $this->sembrar_protocolo_de_leads();
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
        $this->limpiar_roles_de_admins();
    }

    // ------------------------------------------------------------------------------------------
    // CA12 — implementación pedida
    // ------------------------------------------------------------------------------------------

    /**
     * CA12. El agente devuelve `estado_sugerido: "implementacion_pedida"` con verificación pendiente: el lead queda
     * en `implementacion_pedida` ANTES de aprobar (la sugerencia sigue pendiente), se crea UNA `AdminTask` con
     * `created_via = embudo_implementacion_pedida` y el `lead_id`, asignada al perfil Leads (Juan) y con su aviso
     * (push y pila) creado por `AdminTaskNotificationService`.
     *
     * @return void
     */
    public function test_implementacion_pedida_se_aplica_antes_de_aprobar_y_crea_una_tarea(): void
    {
        $juan = $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);
        $lead = $this->lead_que_pide_la_implementacion();

        $this->fakear_ia([$this->paquete_de_implementacion_pedida()]);
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertSame('sugerido', (string) $mensaje->status, 'La sugerencia sigue pendiente de aprobación.');
        $this->assertTrue((bool) $mensaje->requiere_verificacion, 'Precondición: el paquete quedó retenido para verificar.');
        $this->assertSame(
            'implementacion_pedida',
            (string) $lead->fresh()->status,
            'El estado se aplica apenas el agente lo sugiere, sin esperar la aprobación.'
        );

        $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_implementacion_pedida')->get();
        $this->assertCount(1, $tareas, 'Tiene que crearse una sola tarea del embudo.');
        $this->assertStringContainsString('pidió la implementación', (string) $tareas[0]->title);
        $this->assertSame([(int) $juan->id], $this->asignados_de($tareas[0]), 'La tarea va al perfil Leads.');
        $this->assertSame(
            1,
            AdminTaskNotification::query()->where('admin_task_id', $tareas[0]->id)->where('admin_id', $juan->id)->count(),
            'AdminTaskNotificationService tiene que haber creado el aviso (con su push) para Juan.'
        );

        /* Si se repite (el agente vuelve a sugerir lo mismo), no se crea otra. */
        $this->fakear_ia([$this->paquete_de_implementacion_pedida()]);
        app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);
        $this->assertSame(
            1,
            AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_implementacion_pedida')->count(),
            'Repetir el pedido no duplica la tarea.'
        );
    }

    /**
     * CA12. Idempotencia directa del servicio: llamar dos veces a `LeadTareaVendedorService::crear` con el mismo
     * lead y motivo deja una sola tarea; un motivo distinto crea otra.
     *
     * @return void
     */
    public function test_no_duplica(): void
    {
        $this->exigir_clase(LeadTareaVendedorService::class, 'tarea del embudo (CA12)');
        $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);

        $lead = $this->crear_lead_de_flujo('contactado');

        LeadTareaVendedorService::crear($lead, 'implementacion_pedida');
        LeadTareaVendedorService::crear($lead->fresh(), 'implementacion_pedida');

        $this->assertSame(1, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_implementacion_pedida')->count());

        LeadTareaVendedorService::crear($lead->fresh(), 'prueba_creada');

        $this->assertSame(1, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_prueba_creada')->count(), 'Un motivo distinto es otra tarea.');
        $this->assertSame(2, AdminTask::query()->where('lead_id', $lead->id)->count());
    }

    /**
     * CA12 + CA33. Sin ningún admin del perfil Leads ni vendedor, la tarea va a los setters (`es_setter`), como
     * `for_lead_task()` de hoy.
     *
     * @return void
     */
    public function test_sin_vendedor_va_a_los_setters(): void
    {
        $setter = $this->crear_admin_con_roles('Setter', ['es_setter' => true]);
        $this->crear_admin_con_roles('Otro admin', []);
        $lead = $this->lead_que_pide_la_implementacion();

        $this->fakear_ia([$this->paquete_de_implementacion_pedida()]);
        app(LeadAiService::class)->generate_suggestion($lead, false);

        $tarea = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_implementacion_pedida')->first();
        $this->assertNotNull($tarea, 'Tiene que haberse creado la tarea.');
        $this->assertSame([(int) $setter->id], $this->asignados_de($tarea));
    }

    // ------------------------------------------------------------------------------------------
    // CA13 — prueba creada
    // ------------------------------------------------------------------------------------------

    /**
     * CA13. El alta feliz (`POST landing/prueba/alta`) crea una `AdminTask` con `created_via = embudo_prueba_creada`,
     * título «Llamar a <nombre> (<empresa>): creó su prueba» y asignada al perfil Leads, tanto con el interruptor
     * del flujo APAGADO como PRENDIDO (es un aviso interno: no cambia lo que ve el lead).
     *
     * @return void
     */
    public function test_el_alta_de_la_prueba_crea_la_tarea_con_el_interruptor_apagado(): void
    {
        $juan = $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);

        foreach ([['0', 'Ferretería Norte', '3444000001'], ['1', 'Ferretería Sur', '3444000002']] as $caso) {
            list($interruptor, $empresa, $telefono) = $caso;
            AdminSetting::set('lead_whatsapp_flujo_entrada_activo', $interruptor);

            $respuesta = $this->postear_alta(['empresa' => $empresa, 'telefono' => $telefono]);
            $respuesta->assertStatus(201);
            $lead = $this->lead_del_alta((string) $respuesta->json('uuid'));

            $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_prueba_creada')->get();
            $this->assertCount(1, $tareas, "Con el interruptor en {$interruptor} el alta tiene que crear una tarea.");
            $this->assertStringContainsString('Llamar a', (string) $tareas[0]->title);
            $this->assertStringContainsString($empresa, (string) $tareas[0]->title);
            $this->assertStringContainsString('creó su prueba', (string) $tareas[0]->title);
            $this->assertSame([(int) $juan->id], $this->asignados_de($tareas[0]), 'La tarea va al perfil Leads.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA33 — a quién va la tarea
    // ------------------------------------------------------------------------------------------

    /**
     * CA33. `for_embudo_task()` devuelve a todos los admins con `solo_leads` O `es_vendedor`.
     *
     * @return void
     */
    public function test_la_tarea_va_al_perfil_leads_y_a_los_vendedores(): void
    {
        $this->assertTrue(method_exists(AdminTaskAssignmentResolver::class, 'for_embudo_task'), 'Falta AdminTaskAssignmentResolver::for_embudo_task().');

        $leads    = $this->crear_admin_con_roles('Perfil Leads', ['solo_leads' => true]);
        $vendedor = $this->crear_admin_con_roles('Vendedor', ['es_vendedor' => true]);
        $this->crear_admin_con_roles('Setter', ['es_setter' => true]);
        $this->crear_admin_con_roles('Otro', []);

        $ids = AdminTaskAssignmentResolver::for_embudo_task();
        sort($ids);

        $this->assertSame([(int) $leads->id, (int) $vendedor->id], $ids);
    }

    /**
     * CA33. Con un solo admin del perfil Leads (el caso de Juan) la tarea va sólo a él, aunque haya setters.
     *
     * @return void
     */
    public function test_solo_juan(): void
    {
        $this->assertTrue(method_exists(AdminTaskAssignmentResolver::class, 'for_embudo_task'), 'Falta AdminTaskAssignmentResolver::for_embudo_task().');

        $juan = $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);
        $this->crear_admin_con_roles('Setter', ['es_setter' => true]);

        $this->assertSame([(int) $juan->id], AdminTaskAssignmentResolver::for_embudo_task());
    }

    /**
     * CA33. Sin ningún admin del perfil Leads ni vendedor, devuelve los setters, igual que `for_lead_task()`.
     *
     * @return void
     */
    public function test_sin_ninguno_va_a_los_setters(): void
    {
        $this->assertTrue(method_exists(AdminTaskAssignmentResolver::class, 'for_embudo_task'), 'Falta AdminTaskAssignmentResolver::for_embudo_task().');

        $setter = $this->crear_admin_con_roles('Setter', ['es_setter' => true]);
        $this->crear_admin_con_roles('Otro', []);

        $this->assertSame([(int) $setter->id], AdminTaskAssignmentResolver::for_embudo_task());
        $this->assertSame(AdminTaskAssignmentResolver::for_lead_task(), AdminTaskAssignmentResolver::for_embudo_task());
    }

    /**
     * CA33. El admin del perfil Leads ve el aviso de su tarea en `GET task-notification/pending`, con
     * `task.lead_id` (así el SPA abre la conversación). Requiere E5 (ya mergeada).
     *
     * @return void
     */
    public function test_el_perfil_leads_ve_el_aviso_con_el_lead(): void
    {
        $this->exigir_clase(LeadTareaVendedorService::class, 'tarea del embudo (CA33)');

        $juan = $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);
        $lead = $this->crear_lead_de_flujo('contactado');

        LeadTareaVendedorService::crear($lead, 'implementacion_pedida');

        $respuesta = $this->actingAs($juan, 'sanctum')->getJson('/api/admin/task-notification/pending');
        $respuesta->assertStatus(200);

        $avisos = $respuesta->json('models');
        $this->assertCount(1, $avisos, 'Juan tiene que tener el aviso de la tarea.');
        $this->assertSame((int) $lead->id, (int) $avisos[0]['task']['lead_id'], 'El aviso trae el lead para abrir la conversación.');
    }

    // ------------------------------------------------------------------------------------------
    // Helpers propios
    // ------------------------------------------------------------------------------------------

    /**
     * Lead en `contactado` que acaba de pedir que se lo carguen.
     *
     * @return Lead
     */
    private function lead_que_pide_la_implementacion(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        /* El escudo de la conversación (verificación de mensajes): retiene la sugerencia hasta que una persona la
           apruebe. Es el mecanismo real de «verificación pendiente»: el `requiere_verificacion` que pide la IA por
           su cuenta no retiene (LeadAiService lo mueve a `requiere_verificacion_pedida_por_claude`). */
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Prefiero que me lo carguen ustedes');

        return $lead->fresh();
    }

    /**
     * El paquete del agente cuando el lead pide la implementación: le avisa que lo llama Juan, sin pedir hora. La
     * retención para verificar la pone el escudo del lead (ver `lead_que_pide_la_implementacion`).
     *
     * @return array<string, mixed>
     */
    private function paquete_de_implementacion_pedida(): array
    {
        return $this->paquete_ia(
            'Perfecto, hoy te llama Juan, del equipo, para dejarlo armado con vos.',
            'implementacion_pedida',
            'conversacional',
            []
        );
    }

    /**
     * Ids de los admins asignados a la tarea, ordenados.
     *
     * @param AdminTask $tarea
     *
     * @return array<int, int>
     */
    private function asignados_de(AdminTask $tarea): array
    {
        $ids = $tarea->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all();
        sort($ids);

        return $ids;
    }
}
