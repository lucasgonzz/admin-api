<?php

namespace Tests\Feature;

use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoHitosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La dinámica de demo ACTUAL tiene que quedar intacta: ninguna rama de la misión 48 puede
 * alcanzar a un lead con `demo_experiencia = 'actual'`.
 *
 * Estos tests existen porque la primera versión de la misión SÍ la alcanzaba, y no por un camino
 * teórico: el endpoint del formulario de la página inmersiva es público y resuelve el lead por
 * uuid sin mirar la dinámica, así que le congelaba el plan y le creaba los hitos a cualquiera.
 * Es alcanzable de verdad porque el interruptor por lead existe justamente para pilotear la
 * dinámica nueva con leads elegidos a mano — o sea que volver un lead a 'actual' es el rollback
 * previsto, y ese lead ya tiene su link circulando por WhatsApp.
 */
class DemoDinamicaActualIntactaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Siembra un catálogo mínimo: sin él el resolver devuelve null y estos tests pasarían por el
     * motivo equivocado.
     */
    private function sembrar_catalogo(): void
    {
        $catalogo = [
            'version'               => 2,
            'orden_secciones'       => ['S1 - Listado'],
            'clips'                 => [
                ['id' => '1.1', 'seccion' => 'S1 - Listado', 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true, 'condicion' => null, 'evento_esperado' => 'articulo.creado'],
            ],
            'condiciones_secciones' => ['S1 - Listado' => null],
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
     * Lead con la dinámica dada.
     *
     * @param string|null $dinamica
     *
     * @return Lead
     */
    private function crear_lead($dinamica): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Juana Pérez';
        $lead->demo_experiencia = $dinamica;
        $lead->save();

        return $lead;
    }

    /**
     * El caso central: un lead 'actual' completa el formulario de la página y NO se le congela
     * ningún plan ni se le crea ningún hito.
     */
    public function test_un_lead_de_la_dinamica_actual_no_recibe_plan_ni_hitos(): void
    {
        $this->sembrar_catalogo();
        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL);

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', [
            'tipo_precios' => 'unico',
        ])->assertStatus(200);

        $lead->refresh();

        $this->assertNull($lead->demo_plan);
        $this->assertNull($lead->demo_plan_congelado_at);
        $this->assertSame(0, LeadDemoHito::where('lead_id', $lead->id)->count());

        // Y el formulario se guardó igual: la dinámica actual sigue funcionando como siempre.
        $this->assertNotNull($lead->demo_form_completado_at);
    }

    /**
     * Y tampoco con la columna en null o con un valor desconocido, que es el fallback duro de
     * `demo_experiencia_efectiva()` — el que protege a los leads reales mientras la dinámica
     * nueva no esté activada para todos.
     */
    public function test_la_dinamica_nula_o_desconocida_tampoco_recibe_plan(): void
    {
        $this->sembrar_catalogo();

        // El valor desconocido va corto a propósito: la columna `demo_experiencia` es angosta y
        // lo que se prueba es el fallback de `demo_experiencia_efectiva()`, no el largo del campo.
        foreach ([null, 'rara'] as $dinamica) {
            $lead = $this->crear_lead($dinamica);

            $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', [
                'tipo_precios' => 'unico',
            ])->assertStatus(200);

            $lead->refresh();
            $this->assertNull($lead->demo_plan_congelado_at);
            $this->assertSame(0, LeadDemoHito::where('lead_id', $lead->id)->count());
        }
    }

    /**
     * El botón de ingreso tampoco puede mover nada en un lead 'actual'.
     */
    public function test_marcar_ingreso_pulsado_no_hace_nada_en_la_dinamica_actual(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL);

        // Se le fabrica un hito a mano —el estado que la primera versión dejaba— para comprobar
        // que la guardia lo protege aunque el hito exista.
        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 1, 'tipo' => LeadDemoHito::TIPO_INGRESO,
            'seccion' => null, 'clip_id' => null, 'titulo' => 'Entrar a la demo',
            'evento_esperado' => DemoHitosService::EVENTO_INGRESO,
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);

        $this->assertFalse(DemoHitosService::marcar_ingreso_pulsado($lead));

        $hito = LeadDemoHito::where('lead_id', $lead->id)->first();
        $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $hito->estado);
    }

    /**
     * Un lead al que se le corrió el setup con la dinámica nueva y después se lo volvió a
     * 'actual' conserva un token válido (no vence solo). Volver a 'actual' apaga el canal.
     */
    public function test_volver_un_lead_a_la_dinamica_actual_apaga_el_canal_de_eventos(): void
    {
        $lead                     = $this->crear_lead(Lead::EXPERIENCIA_NUEVA);
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        $evento = [
            'uuid' => (string) Str::uuid(), 'nombre' => 'articulo.creado',
            'clip_id' => null, 'ocurrido_at' => '2026-08-13T14:31:02-03:00', 'datos' => [],
        ];

        // Con la dinámica nueva, el canal acepta.
        $this->postJson('/api/demo-eventos', ['eventos' => [$evento]], [
            'X-Demo-Eventos-Key' => $lead->demo_eventos_token,
        ])->assertStatus(200);

        // Rollback del piloto: el mismo token deja de valer.
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        $evento['uuid'] = (string) Str::uuid();
        $this->postJson('/api/demo-eventos', ['eventos' => [$evento]], [
            'X-Demo-Eventos-Key' => $lead->demo_eventos_token,
        ])->assertStatus(401);

        $this->assertSame(1, DemoEventoRecibido::where('lead_id', $lead->id)->count());
    }

    /**
     * El token no sale al serializar el modelo. `Lead` tiene `$guarded = []`, así que sin
     * `$hidden` cualquier columna nueva viaja a todo lo que serialice el lead entero —incluido
     * el `broadcastWith()` de LeadSuggestionCreated, que emite sobre un canal público.
     */
    public function test_el_token_de_eventos_no_sale_al_serializar_el_lead(): void
    {
        $lead                     = $this->crear_lead(Lead::EXPERIENCIA_NUEVA);
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        $this->assertArrayNotHasKey('demo_eventos_token', $lead->toArray());
        $this->assertStringNotContainsString($lead->demo_eventos_token, $lead->toJson());

        // Pero el valor sigue disponible como atributo: es de donde lo toma el payload del setup.
        $this->assertNotEmpty($lead->demo_eventos_token);
    }
}
