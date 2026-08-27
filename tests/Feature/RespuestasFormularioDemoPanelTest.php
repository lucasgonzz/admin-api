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
use Illuminate\Support\Facades\Queue;
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
     * Deja el catálogo sincronizado VACÍO, que es lo que `DemoCatalogoService::get()` devuelve
     * también cuando el JSON es inválido o el archivo nunca se sincronizó. A partir de ahí
     * `DemoPlanResolver::resolver()` devuelve `null` y no hay plan que se pueda armar.
     *
     * Se rompe DESPUÉS de haber congelado el plan a propósito: el caso que interesa es el lead
     * que YA tiene roadmap y el catálogo que se rompe (o se deja de sincronizar) más tarde.
     *
     * @return void
     */
    private function romper_catalogo(): void
    {
        $synced = SyncedGithubFile::obtener_por_key(DemoCatalogoService::SYNCED_FILE_KEY);

        if ($synced === null) {
            $synced            = new SyncedGithubFile();
            $synced->key       = DemoCatalogoService::SYNCED_FILE_KEY;
            $synced->repo_path = 'contexto/demo_catalogo.json';
        }

        $synced->content = '';
        $synced->save();
    }

    /**
     * Deja un catálogo que es JSON VÁLIDO y no está vacío, pero del que no sale ni una sección
     * utilizable: los nombres de `orden_secciones` no cruzan con ningún `clips[].seccion`.
     *
     * Es el caso del typo al editar `demo_catalogo.json` —el archivo se sincroniza a producción sin
     * deploy—, y es distinto del catálogo sin sincronizar de `romper_catalogo()`: acá
     * `DemoCatalogoService::get()` devuelve contenido, así que `DemoPlanResolver::resolver()` NO
     * devuelve `null`. Devuelve un plan perfectamente formado con `secciones: []`, que es lo que
     * hace que preguntar sólo por `null` no alcance para proteger el roadmap del lead.
     *
     * @return void
     */
    private function sembrar_catalogo_sin_secciones_utilizables(): void
    {
        $catalogo = [
            'version' => 2,
            // El typo: las dos secciones existen en el catálogo con OTRO nombre.
            'orden_secciones' => ['S1 – Listado', 'S4 – Compras'],
            'clips'           => [
                ['id' => '1.1', 'seccion' => 'S1 - Listado', 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'articulo.creado'],
                ['id' => '4.1', 'seccion' => 'S4 - Compras', 'titulo' => 'Cargar una compra', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'compra.creada'],
            ],
            'condiciones_secciones' => [],
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

        /* Las dos marcas conviven, y la tarjeta lo declara SIN elegir un ganador: escribieron las
           dos puntas. Que el lead completó el formulario se sigue afirmando, con su fecha. */
        $this->assertSame('ambos', $respuesta->json('model.demo_form_panel.origen'));
        $this->assertTrue($respuesta->json('model.demo_form_panel.completado_por_lead'));
        $this->assertSame('2026-08-20 15:30:00', $respuesta->json('model.demo_form_panel.completado_at'));
        $this->assertNotNull($respuesta->json('model.demo_form_panel.editado_admin_at'));
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

    /**
     * 8. 🔴 CON EL CATÁLOGO SIN SINCRONIZAR, GUARDAR NO SE LLEVA PUESTO EL ROADMAP.
     *
     * El defecto que cubre: el endpoint borraba y PERSISTÍA el plan y los hitos antes de
     * re-congelar, y `DemoPlanResolver::congelar_en_memoria()` devuelve `false` en dos casos
     * distintos —el plan ya estaba congelado, o el catálogo vino vacío y `resolver()` dio `null`—.
     * En el segundo, la regeneración se salteaba y la transacción commiteaba igual, sin una sola
     * excepción: el lead quedaba con `demo_plan = NULL`, `demo_plan_congelado_at = NULL` y cero
     * hitos, y como el plan se congela una sola vez, no volvía a armarse nunca.
     *
     * Éste es el caso del catálogo que NO resuelve. El hermano —catálogo que resuelve pero no
     * produce ninguna sección utilizable, que es el que se colaba por el chequeo de `null`— es el
     * caso 13.
     *
     * Sin el arreglo este test falla en las tres aserciones del plan y los hitos.
     *
     * @return void
     */
    public function test_con_el_catalogo_sin_sincronizar_el_put_deja_el_plan_y_los_hitos_intactos(): void
    {
        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead                    = $this->crear_lead();
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        $this->congelar_plan_previo($lead);
        $lead = $lead->fresh();

        $plan_viejo   = $lead->demo_plan;
        $hitos_viejos = LeadDemoHito::where('lead_id', $lead->id)->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($plan_viejo, 'El plan previo no se congeló: el caso no se está reproduciendo.');
        $this->assertNotEmpty($hitos_viejos, 'El plan previo no generó hitos: el caso no se está reproduciendo.');

        // El catálogo se deja de sincronizar DESPUÉS de que el lead ya tenía su roadmap.
        $this->romper_catalogo();

        $respuesta = $this->guardar_desde_el_panel($lead, ['tipo_precios' => 'listas']);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertSame($plan_viejo, $lead->demo_plan, 'Guardar con el catálogo roto se llevó puesto el plan del lead.');
        $this->assertNotNull($lead->demo_plan_congelado_at, 'El lead quedó sin fecha de congelamiento: el plan no se puede volver a armar nunca.');
        $this->assertSame(
            '2026-08-01 10:00:00',
            $lead->demo_plan_congelado_at->format('Y-m-d H:i:s'),
            'La fecha de congelamiento se movió sin que hubiera un plan nuevo.'
        );
        $this->assertSame(
            $hitos_viejos,
            LeadDemoHito::where('lead_id', $lead->id)->orderBy('id')->pluck('id')->all(),
            'Los hitos se borraron y no se regeneraron: el lead quedó sin roadmap.'
        );

        /* Y las respuestas sí se guardaron. Es lo único que Lucas pidió de este endpoint y no
           puede fallar porque el catálogo no esté sincronizado. */
        $this->assertTrue((bool) $lead->use_price_lists, 'La respuesta editada no se guardó.');
        $this->assertNotNull($lead->demo_form_editado_admin_at, 'No quedó la marca de edición manual.');
    }

    /**
     * 9. 🔴 EL PANEL NO CONGELA EL ROADMAP DE UN LEAD QUE TODAVÍA NO LO TIENE, Y POR ESO EL LEAD
     *    PUEDE ARMARLO DESPUÉS CON SUS PROPIAS RESPUESTAS.
     *
     * El defecto que cubre: el endpoint congelaba el plan por primera vez con las respuestas del
     * admin. Como `congelar_en_memoria()` no re-resuelve un plan ya congelado y el endpoint público
     * no puede hacerlo por diseño, el lead que después contestaba lo contrario pisaba las columnas
     * —el merge del formulario público lo deja ganar— pero se quedaba con el roadmap del admin para
     * siempre. El payload salía contradiciéndose: `respuestas_formulario.registra_compras = true`
     * con un `demo_plan` armado con `false`.
     *
     * Sin el arreglo este test falla en la primera aserción (el plan queda congelado) y, aunque se
     * la saltee, en la del clip de compras (el plan del lead nunca llega a existir).
     *
     * @return void
     */
    public function test_el_panel_no_congela_el_roadmap_y_el_lead_lo_arma_despues_con_sus_respuestas(): void
    {
        Queue::fake();

        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead                    = $this->crear_lead();
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        // El admin contesta desde la tarjeta que el lead NO registra compras.
        $this->guardar_desde_el_panel($lead, ['registra_compras' => false])->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertNull(
            $lead->demo_plan_congelado_at,
            'El panel congeló el roadmap: el formulario del lead ya no va a poder armarlo con sus respuestas.'
        );
        $this->assertNull($lead->demo_plan, 'El panel dejó un plan congelado con las respuestas del admin.');
        $this->assertSame(0, LeadDemoHito::where('lead_id', $lead->id)->count(), 'El panel generó hitos de un roadmap que no le corresponde congelar.');

        // Pero la respuesta del admin sí quedó guardada y es la que vale hasta que el lead conteste.
        $this->assertFalse((bool) $lead->registra_compras, 'La respuesta del admin no se guardó.');

        // Ahora entra el lead a la página inmersiva y contesta lo contrario: SÍ registra compras.
        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', [
            'registra_compras' => true,
        ])->assertStatus(200);

        $lead = $lead->fresh();

        // Gana el lead, y gana también en el roadmap: es el punto entero de la corrección.
        $this->assertTrue((bool) $lead->registra_compras, 'El formulario del lead no pisó la respuesta del admin.');
        $this->assertNotNull($lead->demo_plan_congelado_at, 'El formulario del lead no congeló el plan.');
        $this->assertContains(
            '4.1',
            $this->clips_del_plan($lead),
            'El roadmap del lead se armó sin la sección de Compras, que es justo lo que el lead pidió.'
        );

        $titulos = LeadDemoHito::where('lead_id', $lead->id)->pluck('titulo')->all();
        $this->assertContains('Cargar una compra', $titulos, 'Los hitos no reflejan las respuestas del lead.');

        /* Y el plan congelado guarda las respuestas con las que se resolvió: es lo que después
           viaja en el payload junto a `respuestas_formulario`, y las dos tienen que decir lo
           mismo. Con el plan congelado por el panel, acá decía `false` y el payload salía
           contradiciéndose. */
        $plan = $lead->demo_plan;
        $this->assertTrue(
            $plan['respuestas']['registra_compras'],
            'El plan quedó congelado con la respuesta del admin y no con la del lead.'
        );
    }

    /**
     * 10. 🔴 EL AVISO NO LE ATRIBUYE AL ADMIN LAS RESPUESTAS QUE EL LEAD ESCRIBIÓ DESPUÉS.
     *
     * El defecto que cubre: `origen` se desempataba comparando `demo_form_completado_at` contra
     * `demo_form_editado_admin_at`, pero la primera se sella en el PRIMER envío del lead y no se
     * mueve en los reenvíos. La secuencia lead 10:00 → admin 11:00 → lead 12:00 devolvía `admin`,
     * y la tarjeta mostraba "Modificado por vos el 11:00" arriba de respuestas que el lead acababa
     * de escribir a las 12:00 y que decían lo contrario de las del admin.
     *
     * Lo que sí se puede afirmar siempre —y es lo que Lucas pidió que el aviso diga— es que el lead
     * completó el formulario. Eso se verifica acá también.
     *
     * Sin el arreglo este test falla en `assertNotSame('admin', ...)`.
     *
     * @return void
     */
    public function test_el_aviso_no_le_atribuye_al_admin_lo_que_el_lead_escribio_despues(): void
    {
        Queue::fake();

        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead = $this->crear_lead();

        // 10:00 — el lead contesta que NO registra compras.
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00'));
        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', [
            'registra_compras' => false,
        ])->assertStatus(200);

        // 11:00 — el admin lo corrige desde la tarjeta: SÍ registra compras.
        Carbon::setTestNow(Carbon::parse('2026-08-20 11:00:00'));
        $this->guardar_desde_el_panel($lead, ['registra_compras' => true])->assertStatus(200);

        // 12:00 — el lead vuelve a la página y reafirma que NO. Su reenvío pisa las columnas, pero
        // `demo_form_completado_at` NO se mueve: sigue marcando las 10:00.
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00'));
        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', [
            'registra_compras' => false,
        ])->assertStatus(200);

        $lead = $lead->fresh();

        // Precondiciones del caso: las columnas son las del lead y la fecha quedó vieja.
        $this->assertFalse((bool) $lead->registra_compras, 'El reenvío del lead no pisó la respuesta del admin: el caso no se está reproduciendo.');
        $this->assertSame('2026-08-20 10:00:00', $lead->demo_form_completado_at->format('Y-m-d H:i:s'), 'La fecha de completado se movió en el reenvío: el caso no se está reproduciendo.');
        $this->assertSame('2026-08-20 11:00:00', $lead->demo_form_editado_admin_at->format('Y-m-d H:i:s'));

        $detalle = $this->getJson('/api/admin/lead/' . $lead->id);
        $detalle->assertStatus(200);

        $panel = $detalle->json('model.demo_form_panel');

        $this->assertNotSame(
            'admin',
            $panel['origen'],
            'La tarjeta le atribuye al admin respuestas que el lead escribió después: la fecha de completado marca su primer envío, no el último.'
        );
        $this->assertSame('ambos', $panel['origen'], 'Escribieron las dos puntas y el aviso tiene que declararlo sin elegir un ganador.');

        // Las dos fechas viajan para que la tarjeta las muestre juntas.
        $this->assertSame('2026-08-20 10:00:00', $panel['completado_at']);
        $this->assertSame('2026-08-20 11:00:00', $panel['editado_admin_at']);

        // Y lo que sí se afirma siempre: el lead completó el formulario.
        $this->assertTrue($panel['completado_por_lead'], 'Se perdió el dato que Lucas pidió: si el lead completó el formulario o no.');
        $this->assertTrue($panel['editado_por_admin']);

        // Lo que se muestra es lo que está guardado hoy, que es lo último que escribió el lead.
        $this->assertFalse($panel['respuestas']['registra_compras'], 'La tarjeta no muestra las respuestas que están realmente persistidas.');
    }

    /**
     * 11. 🔴 EL "GUARDAR" GENERAL DEL MODAL TAMBIÉN CUENTA: escribe `use_deposits` /
     *     `use_price_lists` Y MARCA la edición manual.
     *
     * El defecto que cubre: el grupo Demo del meta tiene dos checkboxes editables sobre las mismas
     * columnas que escribe la tarjeta (`usa_depositos` → `use_deposits`, `tipo_precios` →
     * `use_price_lists`). Tocarlos y apretar el "Guardar" general del modal escribía las columnas
     * SIN marcar `demo_form_editado_admin_at`, así que `respuestas_efectivas()` seguía devolviendo
     * los defaults del catálogo, el demo setup ignoraba el cambio y la tarjeta de al lado mostraba
     * el valor viejo. Dos controles para el mismo dato y sólo uno contaba.
     *
     * Se probó cerrarlo sacando los dos campos del meta —`ModelPropertiesHelper::set_from_request()`
     * es una lista blanca sobre `properties()`— y se revirtió el mismo día: la tarjeta sólo es
     * editable para la dinámica NUEVA y el default de todo lead es la ACTUAL, así que esos dos
     * checkboxes eran la única puerta desde el SPA para la mayoría de los leads; y las dos columnas
     * alimentan además `RunUserSetupService::build_payload()`, o sea el setup del cliente real.
     * Se cierra del otro lado: el guardado genérico marca la edición.
     *
     * La segunda mitad del test cubre el efecto lateral de marcar: `respuestas_efectivas()` deja de
     * devolver los defaults del catálogo y pasa a leer las nueve columnas. Eso es inofensivo hoy
     * porque la migración de las seis columnas nuevas copió el default del catálogo en cada una, y
     * la única que discrepa —`usa_depositos`, que `Lead::booted()` fuerza a `true`— es justo la que
     * el checkbox le está mostrando tildada a Lucas. La aserción final es el canario de esa
     * igualdad: si alguien cambia un default del catálogo sin migración (o al revés), rompe acá y
     * no en la demo de un lead.
     *
     * @return void
     */
    public function test_el_update_generico_del_modal_escribe_las_columnas_y_marca_la_edicion_manual(): void
    {
        $this->autenticar_admin();

        $lead               = $this->crear_lead();
        $lead->price_type_1 = 'Mayorista';
        $lead->save();

        $this->assertNull($lead->fresh()->demo_form_editado_admin_at, 'El lead ya venía marcado: el caso no se está reproduciendo.');
        $this->assertFalse((bool) $lead->fresh()->use_price_lists, 'El lead ya venía con listas de precios: el caso no se está reproduciendo.');

        /* El modal manda el borrador entero: `use_deposits` viaja con el valor que ya tenía y
           `use_price_lists` es lo único que Lucas tildó. */
        $respuesta = $this->putJson('/api/admin/lead/' . $lead->id, [
            'use_deposits'    => true,
            'use_price_lists' => true,
            'price_type_1'    => 'Minorista',
        ]);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertTrue((bool) $lead->use_price_lists, 'El update genérico del modal dejó de escribir use_price_lists.');
        $this->assertNotNull(
            $lead->demo_form_editado_admin_at,
            'El update genérico escribió la columna sin marcar la edición manual: el demo setup la va a ignorar igual.'
        );

        /* Y los nombres de las listas de precios se siguen editando ahí, como siempre: son otro
           dato y no salen del formulario de la demo. */
        $this->assertSame('Minorista', $lead->price_type_1, 'Se rompió la edición de los nombres de las listas de precios.');

        /* 🔴 Lo que el demo setup va a usar de acá en adelante: la respuesta que Lucas cambió, y
           las otras ocho tal como estaban a la vista. `usa_depositos` sale `true` porque es lo que
           decía el checkbox de al lado, y no `false` como el catálogo: después de guardar, la
           tarjeta y el checkbox dicen lo mismo. */
        $esperadas = array_merge(LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO, [
            'tipo_precios'  => 'listas',
            'usa_depositos' => true,
        ]);
        $efectivas = LeadDemoFormMapper::respuestas_efectivas($lead);
        ksort($esperadas);
        ksort($efectivas);

        $this->assertSame(
            $esperadas,
            $efectivas,
            'Marcar la edición manual movió respuestas que nadie tocó: las columnas dejaron de coincidir con los defaults del catálogo.'
        );
    }

    /**
     * 12. Guardar el modal SIN tocar los dos checkboxes no marca ninguna edición manual.
     *
     * Es la otra mitad del caso 11 y la razón por la que la comparación se hace entre COLUMNAS y no
     * entre respuestas efectivas. `Lead::booted()` fuerza `use_deposits = true` al crear el lead,
     * mientras que el default del catálogo es `usa_depositos => false`: un lead recién creado tiene
     * la columna y la respuesta efectiva en desacuerdo desde que nace. Comparando contra la
     * efectiva, cualquier guardado del modal —cambiar el teléfono, corregir el nombre— marcaría
     * edición manual del formulario y congelaría para siempre las nueve columnas crudas del lead.
     *
     * El PUT manda las dos claves porque el modal manda el borrador entero en cada guardado; lo que
     * define que no hubo edición es que llegan con el MISMO valor que ya tenían.
     *
     * @return void
     */
    public function test_guardar_el_modal_sin_tocar_los_checkboxes_no_marca_edicion_manual(): void
    {
        $this->autenticar_admin();

        $lead = $this->crear_lead();

        // La precondición del caso: la columna dice `true` y el default del catálogo dice `false`.
        $this->assertTrue((bool) $lead->fresh()->use_deposits, 'El lead nuevo no nació con use_deposits en true: el caso no se está reproduciendo.');
        $this->assertFalse(LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO['usa_depositos'], 'El default del catálogo cambió: el caso ya no distingue columnas de defaults.');

        $respuesta = $this->putJson('/api/admin/lead/' . $lead->id, [
            // El borrador entero, con los dos checkboxes tal como los trajo el modal.
            'use_deposits'    => true,
            'use_price_lists' => false,
            // Lo único que Lucas cambió es un campo que no tiene nada que ver con el formulario.
            'contact_name'    => 'Juana Pérez Gómez',
        ]);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertSame('Juana Pérez Gómez', $lead->contact_name, 'El guardado genérico del modal dejó de funcionar.');
        $this->assertNull(
            $lead->demo_form_editado_admin_at,
            'Guardar el modal sin tocar los checkboxes marcó una edición manual del formulario que nadie hizo.'
        );

        /* Y la consecuencia de esa marca fantasma, que es lo que realmente duele: las respuestas
           efectivas tienen que seguir siendo los defaults del catálogo. */
        $this->assertSame(
            LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO,
            LeadDemoFormMapper::respuestas_efectivas($lead),
            'El guardado genérico congeló las columnas crudas del lead como si alguien hubiera contestado el formulario.'
        );
    }

    /**
     * 13. 🔴 UN CATÁLOGO QUE ES JSON VÁLIDO PERO NO PRODUCE NINGUNA SECCIÓN TAMPOCO PUEDE
     *     LLEVARSE PUESTO EL ROADMAP DEL LEAD.
     *
     * El defecto que cubre, y es el hermano angosto del caso 8: la protección de la re-congelación
     * preguntaba `DemoPlanResolver::resolver($lead) === null`, y `resolver()` devuelve `null` SÓLO
     * cuando `DemoCatalogoService::get()` da `[]` (archivo sin sincronizar o JSON inválido). Un
     * catálogo bien formado pero inservible —`orden_secciones: []`, o los nombres de las secciones
     * cambiados por un typo, que dejan de cruzar con `clips[].seccion`— devuelve un plan legítimo
     * con `secciones: []`. Ese plan pasaba el `!== null`, reemplazaba el plan congelado del lead y
     * dejaba su roadmap en un solo hito (el de ingreso, que `DemoHitosService::generar()` crea
     * siempre). HTTP 200, y ni una línea de error.
     *
     * El criterio nuevo mira si el plan SIRVE: al menos un clip de núcleo entre sus secciones, que
     * es exactamente lo que `generar()` recorre para armar el roadmap.
     *
     * Sin el arreglo este test falla en las tres aserciones del plan y los hitos.
     *
     * @return void
     */
    public function test_con_un_catalogo_valido_pero_sin_secciones_el_put_deja_el_plan_y_los_hitos_intactos(): void
    {
        $this->autenticar_admin();
        $this->sembrar_catalogo();

        $lead                    = $this->crear_lead();
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        $this->congelar_plan_previo($lead);
        $lead = $lead->fresh();

        $plan_viejo   = $lead->demo_plan;
        $hitos_viejos = LeadDemoHito::where('lead_id', $lead->id)->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($plan_viejo, 'El plan previo no se congeló: el caso no se está reproduciendo.');
        $this->assertGreaterThan(1, count($hitos_viejos), 'El roadmap previo tiene un solo hito: el caso no distingue un plan bueno de uno vacío.');

        /* El typo entra DESPUÉS de que el lead ya tenía su roadmap, que es como pasa de verdad: el
           catálogo se edita en el repo y se sincroniza a producción sin deploy. */
        $this->sembrar_catalogo_sin_secciones_utilizables();

        /* Y el plan que sale de ese catálogo es válido y vacío a la vez: no es `null`, que es
           justamente lo que hacía que el chequeo viejo lo diera por bueno. */
        $plan_nuevo = DemoPlanResolver::resolver($lead);
        $this->assertNotNull($plan_nuevo, 'El catálogo del caso no está resolviendo: se está probando el caso 8, no éste.');
        $this->assertSame([], $plan_nuevo['secciones'], 'El catálogo del caso produjo secciones: no reproduce el plan vacío.');

        $respuesta = $this->guardar_desde_el_panel($lead, ['tipo_precios' => 'listas']);
        $respuesta->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertSame($plan_viejo, $lead->demo_plan, 'Un catálogo sin secciones utilizables se llevó puesto el plan del lead.');
        $this->assertSame(
            '2026-08-01 10:00:00',
            $lead->demo_plan_congelado_at->format('Y-m-d H:i:s'),
            'La fecha de congelamiento se movió por un plan que no sirve.'
        );
        $this->assertSame(
            $hitos_viejos,
            LeadDemoHito::where('lead_id', $lead->id)->orderBy('id')->pluck('id')->all(),
            'Los hitos se rehicieron con un plan vacío: el lead quedó con un roadmap de un solo punto.'
        );

        /* Y las respuestas sí se guardaron: es lo único que Lucas pidió de este endpoint y no puede
           fallar porque el catálogo esté mal editado. */
        $this->assertTrue((bool) $lead->use_price_lists, 'La respuesta editada no se guardó.');
        $this->assertNotNull($lead->demo_form_editado_admin_at, 'No quedó la marca de edición manual.');
    }
}
