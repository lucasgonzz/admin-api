<?php

namespace Tests\Feature;

use App\Models\DemoMedia;
use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Qué claves del payload del demo setup recibe cada dinámica (misión 48, pieza 4).
 *
 * El requisito duro: un lead de la dinámica ACTUAL no recibe ni una clave nueva. Se prueba acá y
 * no en el unit test que ya existe (`RunDemoSetupServiceRespuestasTest`) porque el bloque de la
 * dinámica nueva ahora resuelve el mapa de multimedia contra la base.
 */
class DemoSetupPayloadDinamicaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead con la dinámica pedida y el canal de eventos ya emitido.
     *
     * @param string $dinamica
     *
     * @return Lead
     */
    private function crear_lead(string $dinamica): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Juana Pérez';
        $lead->demo_experiencia   = $dinamica;
        $lead->demo_eventos_token = Str::random(64);
        $lead->demo_plan          = ['version_catalogo' => 2, 'secciones' => []];
        $lead->save();

        return $lead;
    }

    /**
     * Invoca el bloque de claves de la dinámica nueva (protegido: no es API del servicio).
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function claves(Lead $lead): array
    {
        $metodo = new ReflectionMethod(RunDemoSetupService::class, 'claves_de_la_dinamica_nueva');
        $metodo->setAccessible(true);

        return $metodo->invoke(new RunDemoSetupService(), $lead);
    }

    /**
     * Caso 14: el lead de la dinámica actual no recibe ninguna de las claves nuevas — ni siquiera
     * teniendo token y plan cargados en sus columnas.
     */
    public function test_la_dinamica_actual_no_recibe_ninguna_clave_nueva(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL);

        $this->assertSame([], $this->claves($lead));
    }

    /**
     * Y tampoco con la columna en null o con un valor desconocido: es el fallback duro de
     * `demo_experiencia_efectiva()`, que es lo que protege a los leads reales de producción.
     */
    public function test_la_dinamica_nula_o_desconocida_tampoco_recibe_nada(): void
    {
        $lead_nulo = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL);
        $lead_nulo->demo_experiencia = null;
        $this->assertSame([], $this->claves($lead_nulo));

        $lead_raro = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL);
        $lead_raro->demo_experiencia = 'una-dinamica-que-no-existe';
        $this->assertSame([], $this->claves($lead_raro));
    }

    /**
     * La dinámica nueva sí recibe las cuatro claves, y el mapa de multimedia se resuelve en el
     * momento (no viene congelado dentro del plan).
     */
    public function test_la_dinamica_nueva_recibe_el_canal_el_plan_y_las_urls(): void
    {
        $media           = new DemoMedia();
        $media->slot_id  = 'intro';
        $media->url      = 'https://cdn.example.com/intro.mp4';
        $media->save();

        $lead   = $this->crear_lead(Lead::EXPERIENCIA_NUEVA);
        $claves = $this->claves($lead);

        $this->assertSame($lead->demo_eventos_token, $claves['demo_eventos_token']);
        $this->assertStringEndsWith('/api/demo-eventos', $claves['demo_eventos_url']);
        $this->assertSame(2, $claves['demo_plan']['version_catalogo']);
        $this->assertSame('https://cdn.example.com/intro.mp4', $claves['demo_media_urls']['intro']);

        // Y sigue trayendo las respuestas del formulario, que ya viajaban antes de esta misión.
        $this->assertCount(9, $claves['respuestas_formulario']);
    }

    /**
     * El token del canal de eventos NO se expone en el endpoint público de la página inmersiva.
     * Ese payload lo ve cualquiera con el uuid del lead.
     */
    public function test_el_token_de_eventos_no_viaja_en_el_endpoint_publico(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA);

        $respuesta = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->assertStatus(200);

        $this->assertStringNotContainsString($lead->demo_eventos_token, $respuesta->getContent());
    }
}
