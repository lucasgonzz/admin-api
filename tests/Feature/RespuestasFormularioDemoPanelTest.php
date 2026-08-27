<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoHitosService;
use App\Services\DemoPlanResolver;
use App\Services\LeadDemoFormMapper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las respuestas del formulario de configuración de la demo, editables a mano desde el modal del
 * lead (misión del 27/8/2026).
 *
 * Pedido de Lucas: *"quiero también desde ahí poder modificar las respuestas de ese formulario, ya
 * sea que el lead le haya contestado o que estén por defecto (...) para que cuando ejecute correr
 * demo setup de forma manual, utilice esos datos que yo puedo haber modificado manualmente"*.
 *
 * 🔴 La última mitad de esa frase es la que justifica la misión entera y es el caso 3 de este
 * archivo. Hasta esta misión, `LeadDemoFormMapper::respuestas_efectivas()` decidía con una sola
 * marca (`demo_form_completado_at`): un lead que no completó el formulario recibía los defaults del
 * catálogo aunque las columnas dijeran otra cosa. O sea que guardar la edición manual sin una marca
 * nueva era guardar en un lugar que el demo setup no mira.
 *
 * Todo entra por el REQUEST HTTP, como el resto de los tests del panel: el endpoint es lo que se
 * está agregando, y probar el mapper directo dejaría sin ejercer la validación, la guardia de
 * dinámica, el append de la respuesta y la transacción del roadmap.
 */
class RespuestasFormularioDemoPanelTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Admin autenticado por Sanctum, como exige el grupo de rutas del panel.
     *
     * @return Admin
     */
    private function autenticar_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Lead de la dinámica NUEVA, sin formulario completado: el estado en el que Lucas abre el modal
     * y ve la tarjeta con los valores por defecto.
     *
     * 🔴 No se toca ninguna de las nueve columnas de respuestas a mano. Interesa justamente el lead
     * recién creado: el hook `creating` del modelo le deja `use_deposits = true`, que es lo
     * contrario del default del catálogo (`usa_depositos => false`). Esa discrepancia es lo que
     * distingue "el panel muestra los defaults" de "el panel muestra las columnas".
     *
     * @param string|null $experiencia Dinámica a forzar; null = la nueva.
     *
     * @return Lead
     */
    private function crear_lead(?string $experiencia = null): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Juana Pérez';
        $lead->company_name     = 'Distribuidora Pérez';
        $lead->demo_experiencia = $experiencia === null ? Lead::EXPERIENCIA_NUEVA : $experiencia;
        $lead->save();

        return $lead;
    }

    /**
     * Guarda respuestas desde el panel.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $respuestas Subconjunto de las nueve.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar_desde_el_panel(Lead $lead, array $respuestas)
    {
        return $this->putJson('/api/admin/lead/' . $lead->id . '/demo-form', $respuestas);
    }

    /**
     * Siembra el catálogo en `synced_github_files`, que es de donde lo lee `DemoCatalogoService`.
     * Sin esto el resolver devuelve null y no hay plan que congelar.
     *
     * Recorte del catálogo real, calcado en estructura del que usa DemoPlanYHitosTest. Los clips
     * 1.2 y 1.3 son los que importan: uno vive con `tipo_precios=unico` y el otro con
     * `tipo_precios=listas`, así que el plan congelado delata con cuál de las dos respuestas se
     * resolvió.
     *
     * @return void
     */
    private function sembrar_catalogo(): void
    {
        $catalogo = [
            'version'         => 2,
            'orden_secciones' => ['S1 - Listado', 'S4 - Compras'],
            'clips'           => [
                ['id' => '1.1', 'seccion' => 'S1 - Listado', 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'articulo.creado'],
                ['id' => '1.2', 'seccion' => 'S1 - Listado', 'titulo' => 'El precio', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => 'tipo_precios=unico', 'evento_esperado' => null],
                ['id' => '1.3', 'seccion' => 'S1 - Listado', 'titulo' => 'Multiples listas', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => 'tipo_precios=listas', 'evento_esperado' => null],
                ['id' => '4.1', 'seccion' => 'S4 - Compras', 'titulo' => 'Cargar una compra', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'compra.creada'],
            ],
            'condiciones_secciones' => [
                'S1 - Listado' => null,
                'S4 - Compras' => 'registra_compras',
            ],
        ];

        $synced = SyncedGithubFile::obtener_por_key(DemoCatalogoService::SYNCED_FILE_KEY);
        if ($synced === null) {
            $synced            = new SyncedGithubFile();
            $synced->key       = DemoCatalogoService::SYNCED_FILE_KEY;
            $synced->repo_path = 'contexto/demo_catalogo.json';
        }

        $synced->content = json_encode($catalogo);
        $synced->save();
    }

    /**
     * Congela el plan del lead y le genera los hitos, tal como habría quedado si el formulario se
     * hubiese enviado antes de la edición manual.
     *
     * La fecha de congelamiento se fuerza al pasado a propósito: los dos congelamientos de un mismo
     * test caen en el mismo segundo y el timestamp no alcanzaría para distinguirlos.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function congelar_plan_previo(Lead $lead): void
    {
        DemoPlanResolver::congelar_en_memoria($lead);
        $lead->save();
        DemoHitosService::generar($lead);

        $lead->demo_plan_congelado_at = Carbon::parse('2026-08-01 10:00:00');
        $lead->save();
    }

    /**
     * Ids de los clips que quedaron en el plan congelado del lead.
     *
     * @param Lead $lead
     *
     * @return array<int, string>
     */
    private function clips_del_plan(Lead $lead): array
    {
        $ids  = [];
        $plan = $lead->demo_plan;

        foreach ((isset($plan['secciones']) ? $plan['secciones'] : []) as $seccion) {
            foreach ((isset($seccion['clips']) ? $seccion['clips'] : []) as $clip) {
                $ids[] = $clip['id'];
            }
        }

        return $ids;
    }

    /**
     * Demo con las cuatro URLs que el setup necesita para armar el payload.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * 1. La tarjeta de un lead que todavía no completó el formulario muestra los DEFAULTS DEL
     *    CATÁLOGO, no las columnas.
     *
     * No es un detalle cosmético: es lo que después se guarda. Si la tarjeta mostrara las columnas
     * crudas, Lucas cambiaría una respuesta y al guardar se llevaría puestas las otras ocho tal
     * como estaban en la base, que no es lo que vio en pantalla.
     *
     * @return void
     */
    public function test_el_detalle_de_un_lead_sin_formulario_trae_los_defaults_del_catalogo(): void
    {
        $this->autenticar_admin();

        $lead = $this->crear_lead();

        // Precondición del caso, y el punto entero de la aserción de abajo: la columna dice una
        // cosa y el catálogo la contraria.
        $this->assertTrue((bool) $lead->fresh()->use_deposits, 'El lead nuevo no nació con use_deposits en true: el caso ya no distingue columnas de defaults.');

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id);
        $respuesta->assertStatus(200);

        $this->assertSame(
            LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO,
            $respuesta->json('model.demo_form_panel.respuestas'),
            'La tarjeta no está mostrando los defaults del catálogo.'
        );

        $this->assertFalse(
            $respuesta->json('model.demo_form_panel.respuestas.usa_depositos'),
            'La tarjeta está leyendo la columna use_deposits en vez del default del catálogo.'
        );

        $this->assertSame('defaults', $respuesta->json('model.demo_form_panel.origen'));
        $this->assertFalse($respuesta->json('model.demo_form_panel.completado_por_lead'));
        $this->assertFalse($respuesta->json('model.demo_form_panel.editado_por_admin'));
        $this->assertTrue($respuesta->json('model.demo_form_panel.editable'));
    }

    /**
     * 2. Guardar desde el panel persiste las nueve respuestas —las dos que se cambiaron y las siete
     *    que estaban a la vista— y deja la marca de edición manual.
     *
     * @return void
     */
    public function test_el_put_persiste_las_respuestas_y_marca_la_edicion_manual(): void
    {
        $this->autenticar_admin();

        $lead = $this->crear_lead();

        $respuesta = $this->guardar_desde_el_panel($lead, [
            'tipo_precios'  => 'listas',
            'usa_depositos' => true,
        ]);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        // Las dos que se cambiaron, ya traducidas a sus columnas legadas.
        $this->assertTrue((bool) $lead->use_price_lists, 'tipo_precios=listas no llegó a use_price_lists.');
        $this->assertTrue((bool) $lead->use_deposits, 'usa_depositos=true no llegó a use_deposits.');

        /* Y las que NO se mandaron: quedan con el default del catálogo, que es lo que la tarjeta
           mostraba. La invertida es la que más importa: `usa_cuentas_corrientes_clientes` vale true
           por default, así que `omitir_cuentas_corrientes` tiene que quedar en false. */
        $this->assertTrue((bool) $lead->descuentos_por_metodo_pago);
        $this->assertTrue((bool) $lead->registra_compras);
        $this->assertTrue((bool) $lead->usa_ecommerce);
        $this->assertFalse((bool) $lead->costos_en_dolares);
        $this->assertFalse((bool) $lead->usa_presupuestos);
        $this->assertFalse((bool) $lead->omitir_cuentas_corrientes, 'La respuesta de cuentas corrientes se guardó sin invertir.');

        $this->assertNotNull($lead->demo_form_editado_admin_at, 'No quedó la marca de edición manual: el demo setup va a seguir usando los defaults.');
        $this->assertNull($lead->demo_form_completado_at, 'La edición manual marcó el formulario como completado por el lead.');

        // La respuesta del PUT trae la tarjeta ya actualizada: el SPA fusiona con Object.assign y
        // sin esta clave el modal se quedaría diciendo que el lead no completó nada.
        $this->assertSame('admin', $respuesta->json('model.demo_form_panel.origen'));
        $this->assertTrue($respuesta->json('model.demo_form_panel.editado_por_admin'));
        $this->assertSame('listas', $respuesta->json('model.demo_form_panel.respuestas.tipo_precios'));
        $this->assertNotNull($respuesta->json('model.demo_form_panel.editado_admin_at'));

        // Queda constancia en el hilo, como evento de sistema y sin admin que lo firme.
        $mensaje = LeadMessage::where('lead_id', $lead->id)->orderByDesc('id')->first();
        $this->assertNotNull($mensaje, 'La edición no dejó constancia en el hilo del lead.');
        $this->assertSame('sistema', $mensaje->sender);
        $this->assertTrue((bool) $mensaje->is_status_event);
        $this->assertStringContainsString('tipo_precios', (string) $mensaje->content);
    }

    /**
     * 3. 🔴 EL TEST QUE JUSTIFICA LA MISIÓN. Después de la edición manual, el demo setup arma la
     *    instancia con esas respuestas y no con los defaults.
     *
     * Se verifica sobre el payload que sale hacia la instancia —el contrato real con
     * `empresa-api`— y no sobre `respuestas_efectivas()` a secas: lo que Lucas pidió es que "cuando
     * ejecute correr demo setup" se usen sus datos, y entre el mapper y ese POST hay tres capas más.
     *
     * @return void
     */
    public function test_el_demo_setup_usa_las_respuestas_editadas_a_mano(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead = $this->crear_lead();

        $lead->demo_id           = $this->crear_demo()->id;
        $lead->demo_date         = '2026-08-27';
        $lead->demo_start_time   = '09:00';
        $lead->demo_end_time     = '23:00';
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        $this->guardar_desde_el_panel($lead, [
            'tipo_precios'  => 'listas',
            'usa_depositos' => true,
        ])->assertStatus(200);

        // El mapper, primero: sin esto el resto no puede estar bien.
        $efectivas = LeadDemoFormMapper::respuestas_efectivas($lead->fresh());
        $this->assertSame('listas', $efectivas['tipo_precios'], 'respuestas_efectivas() sigue devolviendo los defaults después de la edición manual.');
        $this->assertTrue($efectivas['usa_depositos']);

        $this->postJson('/api/admin/lead/' . $lead->id . '/run-demo-setup')->assertStatus(200);

        /* Se captura el payload en vez de aserverar adentro del callback: si algo no coincide, un
           `assertSent` que devuelve false sólo dice "no se envió la request esperada" y no cuál de
           las claves salió mal. */
        $payload = null;
        Http::assertSent(function ($request) use (&$payload) {
            if (strpos($request->url(), '/api/admin-sync/demo-setup') !== false) {
                $payload = $request->data();
            }

            return true;
        });

        $this->assertNotNull($payload, 'El setup no le mandó el payload a la instancia.');

        $this->assertSame('listas', $payload['respuestas_formulario']['tipo_precios'], 'La instancia se está armando con los defaults, no con lo que se editó a mano.');
        $this->assertTrue($payload['respuestas_formulario']['usa_depositos']);

        // Los tres legados que el payload recalcula desde esas mismas respuestas.
        $this->assertTrue($payload['use_price_lists'], 'El legado use_price_lists no siguió a tipo_precios.');
        $this->assertTrue($payload['use_deposits'], 'El legado use_deposits no siguió a usa_depositos.');
        $this->assertTrue($payload['usan_cuentas_corrientes'], 'El legado usan_cuentas_corrientes no siguió a la respuesta del formulario.');

        /* `demo_form_completado` sigue en false y así tiene que quedar: el lead no completó nada.
           Es la clave que distingue "lo contestó el lead" de "lo cargó el admin", y el contrato con
           empresa-api no cambia en esta misión. */
        $this->assertFalse($payload['demo_form_completado'], 'La edición manual se está reportando como formulario completado por el lead.');
    }

    /**
     * 4. Sobre un lead que SÍ completó el formulario, el panel pisa sus respuestas y no le mueve la
     *    fecha de completado.
     *
     * Esa fecha no es decorativa: `RunDemoSetupService::evaluar_disparo()` cambia de rama según
     * ella, así que moverla desde el panel adelantaría el armado automático de la instancia.
     *
     * @return void
     */
    public function test_el_put_pisa_las_respuestas_del_lead_sin_mover_la_fecha_de_completado(): void
    {
        $this->autenticar_admin();

        $lead = $this->crear_lead();

        // El lead contestó: precio único y sin ecommerce.
        LeadDemoFormMapper::to_lead($lead, array_merge(LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO, [
            'tipo_precios' => 'unico',
            'usa_ecommerce' => false,
        ]));
        $lead->demo_form_completado_at = Carbon::parse('2026-08-20 15:30:00');
        $lead->save();

        $completado_at = $lead->fresh()->demo_form_completado_at;

        $respuesta = $this->guardar_desde_el_panel($lead, ['usa_ecommerce' => true]);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertTrue((bool) $lead->usa_ecommerce, 'El panel no pisó la respuesta del lead.');
        $this->assertFalse((bool) $lead->use_price_lists, 'El panel cambió una respuesta que el lead había contestado y que no se tocó.');

        $this->assertSame(
            $completado_at->format('Y-m-d H:i:s'),
            $lead->demo_form_completado_at->format('Y-m-d H:i:s'),
            'La edición manual movió demo_form_completado_at, que decide el disparo automático del setup.'
        );

        $this->assertNotNull($lead->demo_form_editado_admin_at);

        /* Las dos marcas conviven y la más reciente manda: la tarjeta tiene que decir que lo último
           que se guardó lo puso el admin, sin perder la fecha en que el lead contestó. */
        $this->assertSame('admin', $respuesta->json('model.demo_form_panel.origen'));
        $this->assertTrue($respuesta->json('model.demo_form_panel.completado_por_lead'));
        $this->assertSame('2026-08-20 15:30:00', $respuesta->json('model.demo_form_panel.completado_at'));
    }

    /**
     * 5. Con el setup todavía en `pendiente`, editar las respuestas RE-CONGELA el roadmap: el plan
     *    se resuelve de nuevo y los hitos se regeneran.
     *
     * Es seguro justamente porque el setup no corrió: el lead no pudo entrar a la demo ni marcar
     * ningún hito, así que no hay progreso que pisar.
     *
     * @return void
     */
    public function test_con_el_setup_pendiente_el_put_recongela_el_roadmap(): void
    {
        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead                    = $this->crear_lead();
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        $this->congelar_plan_previo($lead);
        $lead = $lead->fresh();

        // Precondición: el plan viejo se resolvió con precio único.
        $this->assertContains('1.2', $this->clips_del_plan($lead), 'El plan previo no se congeló con precio único: el caso no se está reproduciendo.');
        $hitos_viejos = LeadDemoHito::where('lead_id', $lead->id)->pluck('id')->all();
        $this->assertNotEmpty($hitos_viejos, 'El plan previo no generó hitos.');

        $this->guardar_desde_el_panel($lead, ['tipo_precios' => 'listas'])->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertNotSame(
            '2026-08-01 10:00:00',
            $lead->demo_plan_congelado_at->format('Y-m-d H:i:s'),
            'El plan no se re-congeló: el roadmap quedó armado con las respuestas viejas.'
        );

        $clips = $this->clips_del_plan($lead);
        $this->assertContains('1.3', $clips, 'El plan nuevo no trae el clip de listas de precios.');
        $this->assertNotContains('1.2', $clips, 'El plan nuevo se quedó con el clip de precio único.');

        $hitos_nuevos = LeadDemoHito::where('lead_id', $lead->id)->pluck('id')->all();
        $this->assertNotEmpty($hitos_nuevos, 'Los hitos no se regeneraron: el roadmap quedó vacío.');
        $this->assertEmpty(array_intersect($hitos_viejos, $hitos_nuevos), 'Los hitos viejos siguen ahí: DemoHitosService::generar() no crea nada si ya existen.');

        $titulos = LeadDemoHito::where('lead_id', $lead->id)->pluck('titulo')->all();
        $this->assertContains('Multiples listas', $titulos, 'Los hitos nuevos no reflejan las respuestas editadas.');
    }

    /**
     * 6. Con el setup ya corrido, editar las respuestas NO toca el plan ni los hitos.
     *
     * El lead pudo haber entrado a la demo y marcado hitos: rehacer el recorrido los dejaría
     * apuntando a clips que salieron del plan, que es lo que la regla de "nunca retroceder"
     * prohíbe. Las respuestas sí se guardan —el próximo setup las va a usar—, y la tarjeta avisa
     * que el roadmap quedó viejo.
     *
     * @return void
     */
    public function test_con_el_setup_exitoso_el_put_no_toca_el_plan(): void
    {
        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead                    = $this->crear_lead();
        $lead->demo_setup_status = 'exitoso';
        $lead->save();

        $this->congelar_plan_previo($lead);
        $lead = $lead->fresh();

        $plan_viejo   = $lead->demo_plan;
        $hitos_viejos = LeadDemoHito::where('lead_id', $lead->id)->orderBy('id')->pluck('id')->all();

        $respuesta = $this->guardar_desde_el_panel($lead, ['tipo_precios' => 'listas']);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertSame(
            '2026-08-01 10:00:00',
            $lead->demo_plan_congelado_at->format('Y-m-d H:i:s'),
            'El plan se re-congeló con el setup ya corrido: los hitos marcados quedarían apuntando a clips que ya no existen.'
        );
        $this->assertSame($plan_viejo, $lead->demo_plan, 'El contenido del plan cambió con el setup ya corrido.');
        $this->assertSame($hitos_viejos, LeadDemoHito::where('lead_id', $lead->id)->orderBy('id')->pluck('id')->all(), 'Los hitos se regeneraron con el setup ya corrido.');

        // Pero la respuesta sí se guardó: es lo que va a usar la próxima corrida del setup.
        $this->assertTrue((bool) $lead->use_price_lists, 'La respuesta editada no se guardó.');
        $this->assertNotNull($lead->demo_form_editado_admin_at);

        // Y la tarjeta trae con qué avisar que el recorrido quedó viejo.
        $this->assertTrue($respuesta->json('model.demo_form_panel.plan_congelado'));
        $this->assertSame('exitoso', $respuesta->json('model.demo_form_panel.setup_estado'));
    }

    /**
     * 7. Un lead de la dinámica ACTUAL no tiene formulario que editar: 422 y no se escribe nada.
     *
     * @return void
     */
    public function test_un_lead_de_la_dinamica_actual_no_puede_editar_el_formulario(): void
    {
        $this->autenticar_admin();

        $lead                  = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL);
        $lead->use_price_lists = false;
        $lead->save();

        $respuesta = $this->guardar_desde_el_panel($lead, ['tipo_precios' => 'listas']);
        $respuesta->assertStatus(422);

        $lead = $lead->fresh();

        $this->assertFalse((bool) $lead->use_price_lists, 'El endpoint escribió las columnas de un lead de la dinámica actual.');
        $this->assertNull($lead->demo_form_editado_admin_at, 'Quedó la marca de edición manual en un lead sin formulario.');
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count(), 'Se escribió un mensaje en el hilo de un lead que no tiene formulario.');

        // Y el detalle de ese lead trae la tarjeta marcada como no editable, para que el modal
        // muestre el cartel en vez del formulario.
        $detalle = $this->getJson('/api/admin/lead/' . $lead->id);
        $detalle->assertStatus(200);
        $this->assertFalse($detalle->json('model.demo_form_panel.editable'));
    }
}
