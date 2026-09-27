<?php

namespace Tests\Feature\Pipelines;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineFieldsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo compartido por las pruebas del CRM de pipelines (misión pipelines-crm, 27/9/2026).
 *
 * Tres decisiones que valen para todas:
 *
 * 1. **El reloj está clavado** en el 27/9/2026 a las 10:00 de Argentina. La agenda (vencida / hoy
 *    / semana), los días en la etapa y "la nota no puede ser futura" dependen de "ahora"; una
 *    prueba con la fecha real pasa hoy y falla mañana. `Carbon::setTestNow()` es lo que lee
 *    `AppTime::now()` en testing (no hay reloj virtual de base).
 * 2. **Los payloads son los que manda la SPA**, formatos incluidos: `Y-m-d` para fecha,
 *    `Y-m-d H:i` para fecha y hora, las claves `subjects`, `fields`, `lost_reason`,
 *    `next_action_at`. Probar el back con un payload que el front nunca manda es probar otra cosa
 *    (APRENDER_NO_PARCHEAR.md).
 * 3. **Nada sale a la red** (el CRM no manda mensajes, pero el comodín de `Http::fake()` lo
 *    garantiza igual).
 *
 * No hay `database/factories/` en este repo: los ayudantes arman los modelos a mano.
 */
abstract class BaseDePipelines extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de todas las pruebas. */
    const HOY = '2026-09-27 10:00:00';

    /** Zona de la app. */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clavar_reloj(self::HOY);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Clava el reloj en un momento (hora de Argentina).
     *
     * @param string $momento `Y-m-d H:i:s`
     *
     * @return void
     */
    protected function clavar_reloj($momento)
    {
        Carbon::setTestNow(Carbon::parse($momento, self::ZONA));
    }

    /* ------------------------------------------------------------------------------------------
     | Admins
     |----------------------------------------------------------------------------------------- */

    /**
     * Admin logueado por Sanctum: todo el módulo vive bajo auth:sanctum.
     *
     * @param string $nombre
     *
     * @return Admin
     */
    protected function admin_logueado($nombre = 'Lucas')
    {
        $admin = $this->crear_admin($nombre);

        $this->loguear_como($admin);

        return $admin;
    }

    /**
     * Un admin sin loguear.
     *
     * @param string $nombre
     *
     * @return Admin
     */
    protected function crear_admin($nombre = 'Lucas')
    {
        $admin           = new Admin();
        $admin->name     = $nombre;
        $admin->email    = 'pipelines-' . Str::random(10) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cambia el admin logueado. `forgetGuards()` porque el guard de Sanctum cachea el usuario
     * entre requests del mismo test.
     *
     * @param Admin $admin
     *
     * @return void
     */
    protected function loguear_como(Admin $admin)
    {
        Auth::forgetGuards();
        Sanctum::actingAs($admin);
    }

    /* ------------------------------------------------------------------------------------------
     | Sujetos
     |----------------------------------------------------------------------------------------- */

    /**
     * Un cliente activo con teléfono y mail.
     *
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Client
     */
    protected function crear_cliente(array $atributos = [])
    {
        $sufijo = Str::random(8);

        $client               = new Client();
        $client->name         = 'Contacto ' . $sufijo;
        $client->company_name = 'Comercio ' . $sufijo . ' SRL';
        $client->slug         = 'pipelines-' . strtolower($sufijo);
        $client->is_active    = true;
        $client->phone        = '+54 9 11 5555-' . random_int(1000, 9999);
        $client->email        = 'cliente-' . strtolower($sufijo) . '@test.local';

        foreach ($atributos as $campo => $valor) {
            $client->{$campo} = $valor;
        }

        $client->save();

        return $client->fresh();
    }

    /**
     * Un lead con empresa, contacto, teléfono y mail.
     *
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Lead
     */
    protected function crear_lead(array $atributos = [])
    {
        $sufijo = Str::random(8);

        $lead               = new Lead();
        $lead->contact_name = 'Persona ' . $sufijo;
        $lead->company_name = 'Negocio ' . $sufijo;
        $lead->phone        = '549351' . random_int(1000000, 9999999);
        $lead->email        = 'lead-' . strtolower($sufijo) . '@test.local';
        $lead->status       = 'nuevo';

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $lead->save();

        return $lead->fresh();
    }

    /* ------------------------------------------------------------------------------------------
     | Pipelines
     |----------------------------------------------------------------------------------------- */

    /**
     * Las etapas del pipeline de prueba: cubren los siete tipos de campo, los dos campos agenda
     * (fecha y fecha y hora), un obligatorio de cada clase y las tres clases de etapa.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function etapas_de_prueba()
    {
        return [
            ['name' => 'Por contactar', 'type' => 'open', 'color' => '#adb5bd', 'fields' => []],
            ['name' => 'Contactado', 'type' => 'open', 'color' => '#0dcaf0', 'fields' => [
                ['key' => 'canal', 'label' => 'Canal', 'type' => 'select', 'required' => true, 'options' => ['WhatsApp', 'Llamada']],
            ]],
            ['name' => 'Calificado', 'type' => 'open', 'color' => '#0d6efd', 'fields' => [
                ['key' => 'empleados', 'label' => 'Empleados', 'type' => 'number'],
                ['key' => 'usa_sistema', 'label' => 'Usa otro sistema', 'type' => 'boolean', 'required' => true],
                ['key' => 'fecha_alta', 'label' => 'Fecha de alta', 'type' => 'date'],
                ['key' => 'comentario', 'label' => 'Comentario', 'type' => 'text'],
            ]],
            ['name' => 'Reunión agendada', 'type' => 'open', 'color' => '#6f42c1', 'fields' => [
                ['key' => 'fecha_reunion', 'label' => 'Fecha y hora', 'type' => 'datetime', 'required' => true, 'agenda' => true],
                ['key' => 'que_quiere_ver', 'label' => 'Qué quiere ver', 'type' => 'textarea'],
                ['key' => 'participantes', 'label' => 'Quién participa', 'type' => 'text'],
            ]],
            ['name' => 'Más adelante', 'type' => 'open', 'color' => '#ffc107', 'fields' => [
                ['key' => 'fecha_retomar', 'label' => 'Retomar el', 'type' => 'date', 'required' => true, 'agenda' => true],
            ]],
            ['name' => 'Ganado', 'type' => 'won', 'color' => '#198754', 'fields' => [
                ['key' => 'paquete', 'label' => 'Paquete', 'type' => 'text'],
            ]],
            ['name' => 'Perdido', 'type' => 'lost', 'color' => '#dc3545', 'fields' => []],
        ];
    }

    /**
     * Un pipeline armado a mano (no por la API: es preparación, no lo que se prueba), con las
     * definiciones de campos normalizadas por el mismo servicio que usa la API.
     *
     * @param array<int, array<string, mixed>>|null $etapas    Null = `etapas_de_prueba()`.
     * @param array<string, mixed>                  $atributos Pisan los defaults del pipeline.
     *
     * @return Pipeline Con `stages` cargadas.
     */
    protected function crear_pipeline($etapas = null, array $atributos = [])
    {
        $etapas = $etapas === null ? $this->etapas_de_prueba() : $etapas;
        $campos = app(PipelineFieldsService::class);

        $pipeline = Pipeline::create(array_merge([
            'name'         => 'Campaña de prueba ' . Str::random(6),
            'description'  => 'Pipeline de las pruebas.',
            'lost_reasons' => ['Precio', 'No lo necesita', 'Otro'],
            'sort_order'   => 50,
        ], $atributos));

        foreach (array_values($etapas) as $orden => $etapa) {
            PipelineStage::create([
                'pipeline_id' => $pipeline->id,
                'name'        => $etapa['name'],
                'color'       => $etapa['color'],
                'type'        => $etapa['type'],
                'sort_order'  => $orden,
                'fields'      => $campos->normalizar_definicion($etapa['fields'], 'fields'),
            ]);
        }

        return $pipeline->fresh('stages');
    }

    /**
     * Una etapa del pipeline por nombre.
     *
     * @param Pipeline $pipeline
     * @param string   $nombre
     *
     * @return PipelineStage
     */
    protected function etapa(Pipeline $pipeline, $nombre)
    {
        $etapa = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('name', $nombre)->first();

        $this->assertNotNull($etapa, 'No existe la etapa "' . $nombre . '" en el pipeline de la prueba.');

        return $etapa;
    }

    /* ------------------------------------------------------------------------------------------
     | Llamadas a la API (con los payloads de la SPA)
     |----------------------------------------------------------------------------------------- */

    /**
     * Alta masiva, tal cual la manda el `AddModal`.
     *
     * @param Pipeline                          $pipeline
     * @param array<int, array<string, mixed>>  $subjects `[{type, id}]`
     * @param array<string, mixed>              $extra    stage_id, owner_admin_id, note.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function alta(Pipeline $pipeline, array $subjects, array $extra = [])
    {
        return $this->postJson('/api/admin/pipelines/' . $pipeline->id . '/opportunities', array_merge([
            'subjects' => $subjects,
        ], $extra));
    }

    /**
     * Alta de UN sujeto, afirmando el 201. Devuelve la oportunidad creada (JSON).
     *
     * @param Pipeline             $pipeline
     * @param Client|Lead          $sujeto
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function alta_de_uno(Pipeline $pipeline, $sujeto, array $extra = [])
    {
        $tipo      = $sujeto instanceof Client ? 'client' : 'lead';
        $respuesta = $this->alta($pipeline, [['type' => $tipo, 'id' => $sujeto->id]], $extra);

        $respuesta->assertStatus(201);
        $this->assertCount(1, $respuesta->json('created'), 'El alta de un sujeto tendría que crear una oportunidad.');

        return $respuesta->json('created.0');
    }

    /**
     * Mover, tal cual lo manda el `MoveModal`.
     *
     * @param int                  $oportunidad_id
     * @param array<string, mixed> $payload
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mover($oportunidad_id, array $payload)
    {
        return $this->postJson('/api/admin/pipeline-opportunities/' . $oportunidad_id . '/move', $payload);
    }

    /**
     * La oportunidad recién leída de la base.
     *
     * @param int $id
     *
     * @return PipelineOpportunity|null
     */
    protected function oportunidad($id)
    {
        return PipelineOpportunity::query()->find($id);
    }
}
