<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoPlanResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El plan de demo resuelto y congelado, y los hitos que nacen de él (misión 48, piezas 1 a 3).
 *
 * Se le pega al endpoint público del formulario —el mismo que usa la página inmersiva— y se
 * verifica el estado que queda en la base, que es donde vive el contrato que consumen las
 * misiones 49, 50 y 51.
 */
class DemoPlanYHitosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Siembra el catálogo v2 en `synced_github_files`, que es de donde lo lee
     * `DemoCatalogoService`. Sin esto el resolver devuelve null a propósito.
     *
     * Es un recorte del catálogo real (`contexto/demo_catalogo.json` v2) con las secciones y los
     * clips que los casos de esta prueba necesitan: las condiciones de sección de S4 y S6, las
     * cuatro variantes de precios de S1, y una sección hecha sólo de biblioteca.
     *
     * @param array<string, mixed>|null $catalogo Catálogo a sembrar; null = el de abajo.
     */
    private function sembrar_catalogo(?array $catalogo = null): void
    {
        if ($catalogo === null) {
            $catalogo = $this->catalogo_de_prueba();
        }

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
     * Catálogo de prueba, calcado en estructura del real.
     *
     * @return array<string, mixed>
     */
    private function catalogo_de_prueba(): array
    {
        return [
            'version'         => 2,
            'orden_secciones' => [
                'S1 - Listado',
                'S4 - Compras',
                'S6 - Ecommerce',
                'S7 - Solo biblioteca',
            ],
            'clips' => [
                ['id' => '1.1', 'seccion' => 'S1 - Listado', 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'articulo.creado'],
                ['id' => '1.2', 'seccion' => 'S1 - Listado', 'titulo' => 'El precio', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => 'tipo_precios=unico', 'evento_esperado' => null],
                ['id' => '1.3', 'seccion' => 'S1 - Listado', 'titulo' => 'Multiples listas', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => 'tipo_precios=listas', 'evento_esperado' => null],
                ['id' => '1.4', 'seccion' => 'S1 - Listado', 'titulo' => 'Dolares sobre unico', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => 'costos_en_dolares && tipo_precios=unico', 'evento_esperado' => null],
                ['id' => '1.5', 'seccion' => 'S1 - Listado', 'titulo' => 'Dolares sobre listas', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => 'costos_en_dolares && tipo_precios=listas', 'evento_esperado' => null],
                ['id' => '1.9', 'seccion' => 'S1 - Listado', 'titulo' => 'Stock por depositos', 'tipo' => 'biblioteca', 'practica' => false, 'condicion' => 'usa_depositos', 'evento_esperado' => null],
                ['id' => '4.1', 'seccion' => 'S4 - Compras', 'titulo' => 'Cargar una compra', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'compra.creada'],
                ['id' => '6.1', 'seccion' => 'S6 - Ecommerce', 'titulo' => 'Publicar en la tienda', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => null],
                ['id' => '6.2', 'seccion' => 'S6 - Ecommerce', 'titulo' => 'Procesar un pedido', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'pedido.procesado'],
                ['id' => '7.1', 'seccion' => 'S7 - Solo biblioteca', 'titulo' => 'Material opcional', 'tipo' => 'biblioteca', 'practica' => false, 'condicion' => null, 'evento_esperado' => null],
            ],
            'condiciones_secciones' => [
                'S1 - Listado'         => null,
                'S4 - Compras'         => 'registra_compras',
                'S6 - Ecommerce'       => 'usa_ecommerce',
                'S7 - Solo biblioteca' => null,
            ],
        ];
    }

    /**
     * Lead mínimo con la dinámica de demo nueva activada.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Juana Pérez';
        $lead->company_name     = 'Distribuidora Pérez';
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead;
    }

    /**
     * Envía el formulario de la página inmersiva con las respuestas dadas.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $respuestas
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_formulario(Lead $lead, array $respuestas)
    {
        return $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', $respuestas);
    }

    /**
     * Ids de los clips que quedaron en el plan, sin importar la sección.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<int, string>
     */
    private function ids_de_clips(array $plan): array
    {
        $ids = [];
        foreach ($plan['secciones'] as $seccion) {
            foreach ($seccion['clips'] as $clip) {
                $ids[] = $clip['id'];
            }
        }

        return $ids;
    }

    /**
     * Ids de las secciones del plan.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<int, string>
     */
    private function ids_de_secciones(array $plan): array
    {
        $ids = [];
        foreach ($plan['secciones'] as $seccion) {
            $ids[] = $seccion['id'];
        }

        return $ids;
    }

    /**
     * Caso 1: sin ecommerce, la sección S6 no aparece y no hay hitos de clips 6.x.
     */
    public function test_sin_ecommerce_no_hay_seccion_de_ecommerce_ni_sus_hitos(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['usa_ecommerce' => false])->assertStatus(200);

        $lead->refresh();
        $this->assertNotContains('S6 - Ecommerce', $this->ids_de_secciones($lead->demo_plan));

        $clips_de_hitos = LeadDemoHito::where('lead_id', $lead->id)->pluck('clip_id')->all();
        $this->assertNotContains('6.1', $clips_de_hitos);
        $this->assertNotContains('6.2', $clips_de_hitos);
    }

    /**
     * Caso 2: sin compras, la sección S4 no aparece.
     */
    public function test_sin_compras_no_hay_seccion_de_compras(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['registra_compras' => false])->assertStatus(200);

        $lead->refresh();
        $this->assertNotContains('S4 - Compras', $this->ids_de_secciones($lead->demo_plan));
    }

    /**
     * Caso 3: con listas de precios entra el clip de listas y no el de precio único.
     */
    public function test_con_listas_de_precios_entra_el_clip_de_listas_y_no_el_de_unico(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['tipo_precios' => 'listas'])->assertStatus(200);

        $lead->refresh();
        $ids = $this->ids_de_clips($lead->demo_plan);
        $this->assertContains('1.3', $ids);
        $this->assertNotContains('1.2', $ids);
    }

    /**
     * Caso 4: la condición con `&&` se evalúa entera — dólares sobre precio único trae 1.4 y no 1.5.
     */
    public function test_costos_en_dolares_con_precio_unico_trae_el_clip_de_unico(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, [
            'costos_en_dolares' => true,
            'tipo_precios'      => 'unico',
        ])->assertStatus(200);

        $lead->refresh();
        $ids = $this->ids_de_clips($lead->demo_plan);
        $this->assertContains('1.4', $ids);
        $this->assertNotContains('1.5', $ids);
    }

    /**
     * Caso 5: el lead que nunca completó el formulario congela el plan con los defaults del
     * catálogo al correrle el setup — no con las columnas apagadas con las que nació.
     */
    public function test_lead_sin_formulario_congela_con_los_defaults_del_catalogo(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->assertNull($lead->demo_plan_congelado_at);

        // Se invoca el congelamiento tal como lo hace RunDemoSetupService antes de armar el
        // payload, sin disparar la llamada HTTP a la instancia (que no existe en el test).
        $this->congelar_como_el_setup($lead);

        $lead->refresh();
        $this->assertNotNull($lead->demo_plan_congelado_at);

        $respuestas = $lead->demo_plan['respuestas'];
        $this->assertTrue($respuestas['descuentos_por_metodo_pago']);
        $this->assertTrue($respuestas['usa_ecommerce']);
        $this->assertFalse($respuestas['usa_presupuestos']);
    }

    /**
     * Caso 6: recién congelado, todos los hitos están en `pendiente` y el primero es el de ingreso.
     */
    public function test_los_hitos_nacen_todos_pendientes_y_el_primero_es_el_de_ingreso(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['tipo_precios' => 'unico'])->assertStatus(200);

        $hitos = LeadDemoHito::where('lead_id', $lead->id)->orderBy('orden')->get();

        $this->assertGreaterThan(1, $hitos->count());
        foreach ($hitos as $hito) {
            $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $hito->estado);
        }

        $primero = $hitos->first();
        $this->assertSame(LeadDemoHito::TIPO_INGRESO, $primero->tipo);
        $this->assertSame('Entrar a la demo', $primero->titulo);

        // Los clips de biblioteca no generan hito: 1.9 no aparece aunque el lead use depósitos.
        $this->assertNotContains('7.1', $hitos->pluck('clip_id')->all());
    }

    /**
     * Caso 7: reenviar el formulario con otras respuestas no re-congela el plan ni toca los hitos.
     */
    public function test_el_reenvio_del_formulario_no_recongela_el_plan(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['tipo_precios' => 'unico', 'usa_ecommerce' => true])->assertStatus(200);

        $lead->refresh();
        $congelado_original = $lead->demo_plan_congelado_at->format('Y-m-d H:i:s');
        $hitos_originales   = LeadDemoHito::where('lead_id', $lead->id)->orderBy('orden')->pluck('clip_id')->all();

        // Segundo envío, con respuestas distintas: cambia de precio único a listas y apaga el
        // ecommerce, que en un plan re-resuelto sacaría una sección entera.
        $this->enviar_formulario($lead, ['tipo_precios' => 'listas', 'usa_ecommerce' => false])->assertStatus(200);

        $lead->refresh();
        $this->assertSame($congelado_original, $lead->demo_plan_congelado_at->format('Y-m-d H:i:s'));

        $hitos_despues = LeadDemoHito::where('lead_id', $lead->id)->orderBy('orden')->pluck('clip_id')->all();
        $this->assertSame($hitos_originales, $hitos_despues);

        // El plan sigue siendo el de precio único, aunque el lead ahora diga listas.
        $ids = $this->ids_de_clips($lead->demo_plan);
        $this->assertContains('1.2', $ids);
        $this->assertNotContains('1.3', $ids);
    }

    /**
     * Caso 13: sin catálogo sincronizado el resolver devuelve null y el lead queda SIN plan —
     * no con un plan vacío, que sería indistinguible de "este lead no usa nada".
     */
    public function test_sin_catalogo_sincronizado_el_lead_queda_sin_plan(): void
    {
        // A propósito no se siembra el catálogo. Si quedó uno de otra prueba, se vacía.
        $synced = SyncedGithubFile::obtener_por_key(DemoCatalogoService::SYNCED_FILE_KEY);
        if ($synced !== null) {
            $synced->content = '';
            $synced->save();
        }

        $lead = $this->crear_lead();

        $this->assertNull(DemoPlanResolver::resolver($lead));

        $this->enviar_formulario($lead, ['tipo_precios' => 'unico'])->assertStatus(200);

        $lead->refresh();
        $this->assertNull($lead->demo_plan);
        $this->assertNull($lead->demo_plan_congelado_at);
        $this->assertSame(0, LeadDemoHito::where('lead_id', $lead->id)->count());

        // Y el formulario se guardó igual: la falta de roadmap no puede tumbar el flujo.
        $this->assertNotNull($lead->demo_form_completado_at);
    }

    /**
     * Una condición que la gramática del catálogo no admite excluye el clip Y queda declarada en
     * `condiciones_invalidas`, por los dos caminos: clip y sección.
     */
    public function test_una_condicion_invalida_excluye_y_queda_declarada(): void
    {
        $catalogo = $this->catalogo_de_prueba();

        // Condición de clip con un operador que la gramática no tiene.
        $catalogo['clips'][] = [
            'id' => '9.1', 'seccion' => 'S1 - Listado', 'titulo' => 'Clip roto',
            'tipo' => 'nucleo', 'practica' => true,
            'condicion' => 'usa_ecommerce || registra_compras', 'evento_esperado' => null,
        ];

        // Condición de sección que nombra un campo que no existe entre las nueve respuestas.
        $catalogo['orden_secciones'][]                      = 'S8 - Rota';
        $catalogo['condiciones_secciones']['S8 - Rota']      = 'campo_que_no_existe';
        $catalogo['clips'][]                                 = [
            'id' => '8.1', 'seccion' => 'S8 - Rota', 'titulo' => 'Clip de sección rota',
            'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => null,
        ];

        $this->sembrar_catalogo($catalogo);
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['tipo_precios' => 'unico'])->assertStatus(200);

        $lead->refresh();
        $plan = $lead->demo_plan;

        // Ninguno de los dos entró al plan.
        $this->assertNotContains('9.1', $this->ids_de_clips($plan));
        $this->assertNotContains('S8 - Rota', $this->ids_de_secciones($plan));

        // Y los dos quedaron declarados, cada uno con su tipo.
        $declarados = [];
        foreach ($plan['condiciones_invalidas'] as $invalida) {
            $declarados[$invalida['tipo']][] = $invalida['id'];
        }

        $this->assertContains('9.1', $declarados['clip']);
        $this->assertContains('S8 - Rota', $declarados['seccion']);
    }

    /**
     * Una sección que se queda sin ningún clip de núcleo no aparece en el plan, aunque le hayan
     * sobrevivido clips de biblioteca.
     */
    public function test_una_seccion_solo_de_biblioteca_no_aparece(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead();

        $this->enviar_formulario($lead, ['usa_depositos' => true])->assertStatus(200);

        $lead->refresh();
        $this->assertNotContains('S7 - Solo biblioteca', $this->ids_de_secciones($lead->demo_plan));
    }

    /**
     * Congela el plan del lead por el mismo camino que usa `RunDemoSetupService::run()` antes de
     * armar el payload, sin disparar la llamada HTTP a la instancia.
     *
     * @param Lead $lead
     */
    private function congelar_como_el_setup(Lead $lead): void
    {
        $metodo = new \ReflectionMethod(\App\Services\RunDemoSetupService::class, 'congelar_plan_si_falta');
        $metodo->setAccessible(true);
        $metodo->invoke(new \App\Services\RunDemoSetupService(), $lead);
    }
}
