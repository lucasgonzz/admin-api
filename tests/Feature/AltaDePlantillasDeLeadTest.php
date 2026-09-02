<?php

namespace Tests\Feature;

use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Services\LeadFollowupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El alta en lote de plantillas de LEAD (`POST claude/followup-templates`), con las diez del
 * chequeo diario del 2/9/2026 (`comercial/plantillas_chequeo_diario.md` del repo de conocimiento)
 * como caso real.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 LA IDEMPOTENCIA POR `template_name`. El alta no la hace una persona en una pantalla —no
 *     existe esa pantalla para plantillas de lead—: la hace Claude reenviando el lote entero cada
 *     vez que corrige un texto. Si reenviar duplica, `find_template_for()` (que ordena por
 *     `dia_numero` e indexa 1-based) puede terminar devolviendo la fila vieja en vez de la
 *     corregida, o mostrando la plantilla dos veces en `GET claude/templates`.
 *  2. Que el alta sea ADITIVA: un lote parcial no puede llevarse puestas las plantillas que ya
 *     estaban.
 *  3. 🔴 QUE LAS PLANTILLAS `manual_*` NUNCA LAS DISPARE `LeadFollowupService`. Es la decisión
 *     central del chequeo diario: un `estado` que no es un status real del pipeline no puede
 *     matchear la búsqueda por igualdad de `find_template_for()`, así que el cron automático de
 *     cada 2 horas jamás las levanta solo. Sin esta garantía, `cc_coord_dia_acordado` le saldría a
 *     ciegas a cualquier lead que llegara a `manual_coordinacion` (que nunca pasa, pero si algún
 *     día un status real coincidiera con el nombre, el lead recibiría un mensaje que asume un
 *     acuerdo que el sistema no verificó).
 *  4. Que el catálogo (`config/claude_catalog.php`) siga describiendo la ruta nueva: lo prueba
 *     `CatalogoDeEndpointsDeClaudeTest`, no este archivo, pero un olvido acá rompería ESE test.
 */
class AltaDePlantillasDeLeadTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-followup-templates';

    /**
     * Setea la clave de ingesta.
     *
     * En el `.env.testing` del slot la clave está vacía y el middleware es fail-closed, así que sin
     * esto todos los tests del bloque claude/* darían 401 y estarían midiendo el middleware en vez
     * del endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Las diez plantillas del chequeo diario, tal cual las cargó Lucas en Meta el 2/9/2026.
     *
     * @return array<int, array<string, mixed>>
     */
    private function las_diez_del_chequeo_diario(): array
    {
        return [
            ['template_name' => 'cc_coord_dia_acordado', 'estado' => 'manual_coordinacion', 'dia_numero' => 1, 'body_template' => 'Hola {{1}}! Te escribo como quedamos: me habías dicho que podías hacer la demo de ComercioCity {{2}}. ¿Sigue en pie? Decime el horario que te queda cómodo y te dejo el acceso listo.'],
            ['template_name' => 'cc_coord_horario_sin_confirmar', 'estado' => 'manual_coordinacion', 'dia_numero' => 2, 'body_template' => 'Hola {{1}}! Te había pasado unos horarios para la demo de ComercioCity y quedó sin cerrar cuál te servía. ¿Te sigue viniendo bien {{2}}? Si preferís otro momento, decime y lo acomodo.'],
            ['template_name' => 'cc_coord_espera_lead', 'estado' => 'manual_coordinacion', 'dia_numero' => 3, 'body_template' => 'Hola {{1}}! ¿Cómo venís con {{2}}? Te escribo por si ya lo pudiste resolver y querés que coordinemos la demo de ComercioCity. Si todavía estás con eso, sin problema: avisame cuando te acomodes.'],
            ['template_name' => 'cc_coord_consulta_sin_cerrar', 'estado' => 'manual_coordinacion', 'dia_numero' => 4, 'body_template' => 'Hola {{1}}! Quedamos hablando de {{2}} y no llegamos a cerrarlo. ¿Te quedó alguna duda con eso? Si querés lo vemos, y si preferís verlo funcionando te dejo el acceso a la demo cuando me digas.'],
            ['template_name' => 'cc_closer_confirmar_horario', 'estado' => 'manual_closer', 'dia_numero' => 1, 'body_template' => 'Hola {{1}}! Quedamos en hablar {{2}} para ver la implementación en tu negocio. Para no cruzarnos, decime a qué hora te queda cómodo y te llamo. Son 15 o 20 minutos.'],
            ['template_name' => 'cc_respuesta_pendiente', 'estado' => 'manual_closer', 'dia_numero' => 2, 'body_template' => 'Hola {{1}}! Te debía la respuesta sobre {{2}} y no quiero que quede colgada: {{3}} Si querés lo charlamos con más detalle cuando me digas.'],
            ['template_name' => 'cc_post_llamada_retomar', 'estado' => 'manual_closer', 'dia_numero' => 3, 'body_template' => 'Hola {{1}}! Quedé pensando en lo que hablamos, sobre todo en {{2}}. ¿Pudiste verlo con tu gente? Cualquier número o detalle que necesites para decidir, te lo paso enseguida.'],
            ['template_name' => 'cc_post_llamada_ultimo', 'estado' => 'manual_closer', 'dia_numero' => 4, 'body_template' => 'Hola {{1}}! No quiero seguir insistiendo, así que éste es mi último mensaje por ahora. Si en algún momento querés retomar lo de ComercioCity, escribime y lo vemos sin vueltas. Quedamos a disposición.'],
            ['template_name' => 'cc_nutre_contenido', 'estado' => 'manual_nutricion', 'dia_numero' => 1, 'body_template' => 'Hola {{1}}! Me quedé pensando en lo que me contaste sobre {{2}}. Grabé algo corto que muestra justo eso funcionando, por si te sirve verlo: {{3}} Cualquier cosa que te surja, escribime.'],
            ['template_name' => 'cc_nutre_testimonio', 'estado' => 'manual_nutricion', 'dia_numero' => 2, 'body_template' => 'Hola {{1}}! Te comparto la experiencia de una clienta nuestra que estaba igual que vos con {{2}}. Son un par de minutos y te va a servir más que cualquier cosa que te cuente yo: {{3}} Si te dan ganas de verlo en tu negocio, avisame.'],
        ];
    }

    /**
     * Carga las diez contra el endpoint y devuelve la respuesta.
     *
     * @param array<int, array<string, mixed>>|null $templates Lote a mandar; por defecto, las diez.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function cargar($templates = null)
    {
        return $this->withHeaders($this->headers())->postJson('/api/claude/followup-templates', [
            'templates' => $templates ?? $this->las_diez_del_chequeo_diario(),
        ]);
    }

    /**
     * Sin la clave del header no entra nada.
     *
     * @return void
     */
    public function test_sin_la_clave_el_endpoint_de_claude_rechaza()
    {
        $response = $this->postJson('/api/claude/followup-templates', [
            'templates' => [$this->las_diez_del_chequeo_diario()[0]],
        ]);

        $response->assertStatus(401);

        $this->assertSame(
            0,
            FollowupTemplate::where('template_name', 'cc_coord_dia_acordado')->count(),
            'Se cargó una plantilla sin mandar la clave del header.'
        );
    }

    /**
     * El alta crea las diez, con estado, día, body y los defaults correctos.
     *
     * @return void
     */
    public function test_el_alta_crea_las_diez_plantillas_del_chequeo_diario()
    {
        $response = $this->cargar();

        $response->assertStatus(200);
        $response->assertJsonPath('resultados.creadas', 10);
        $response->assertJsonPath('resultados.actualizadas', 0);

        $this->assertSame(
            10,
            FollowupTemplate::whereIn('template_name', array_column($this->las_diez_del_chequeo_diario(), 'template_name'))->count(),
            'No se crearon las diez plantillas.'
        );

        $primera = FollowupTemplate::where('template_name', 'cc_coord_dia_acordado')->first();
        $this->assertNotNull($primera);
        $this->assertSame('manual_coordinacion', $primera->estado);
        $this->assertSame(1, $primera->dia_numero);
        $this->assertSame('es_AR', $primera->language_code, 'El idioma no cayó al default es_AR.');
        $this->assertTrue($primera->activa, 'La plantilla nueva no quedó activa por default.');
        $this->assertFalse($primera->solo_si_ingreso_confirmado, 'solo_si_ingreso_confirmado no cayó al default false.');
        $this->assertStringContainsString('{{2}}', $primera->body_template);
    }

    /**
     * 🔴 El test central: reenviar el mismo lote actualiza las filas, nunca crea una segunda.
     *
     * @return void
     */
    public function test_reenviar_el_mismo_lote_actualiza_y_no_duplica()
    {
        $this->cargar()->assertStatus(200);

        $lote_corregido = $this->las_diez_del_chequeo_diario();
        $lote_corregido[0]['body_template'] = 'Hola {{1}}! Texto corregido para probar la actualización. {{2}}';

        $segundo = $this->cargar($lote_corregido);

        $segundo->assertStatus(200);
        $segundo->assertJsonPath('resultados.creadas', 0);
        $segundo->assertJsonPath('resultados.actualizadas', 10);

        $this->assertSame(
            1,
            FollowupTemplate::where('template_name', 'cc_coord_dia_acordado')->count(),
            'Reenviar el mismo lote dejó una plantilla duplicada.'
        );

        $actualizada = FollowupTemplate::where('template_name', 'cc_coord_dia_acordado')->first();
        $this->assertStringContainsString(
            'Texto corregido',
            $actualizada->body_template,
            'La segunda corrida no actualizó el body_template.'
        );
    }

    /**
     * Un lote parcial no se lleva puestas las plantillas que no vinieron en el payload.
     *
     * @return void
     */
    public function test_el_alta_nunca_borra_las_que_no_vinieron_en_el_payload()
    {
        $this->cargar()->assertStatus(200);

        $this->cargar([$this->las_diez_del_chequeo_diario()[0]])->assertStatus(200);

        $this->assertSame(
            10,
            FollowupTemplate::whereIn('template_name', array_column($this->las_diez_del_chequeo_diario(), 'template_name'))->count(),
            'Un lote de una sola plantilla borró las otras nueve.'
        );
    }

    /**
     * 🔴 Ninguno de los tres `estado` manuales del chequeo diario es un status real del pipeline.
     *
     * Es la propiedad que hace que `LeadFollowupService::find_template_for()` —que busca
     * `where('estado', $lead->status)`— jamás pueda devolver una de estas diez para un lead real:
     * `$lead->status` siempre es uno de `LeadPipelineStatus::all_slugs()`, y ninguno de esos slugs
     * puede ser igual a un `estado` que no está en esa lista.
     *
     * @return void
     */
    public function test_los_estados_manuales_no_son_estados_reales_del_pipeline()
    {
        $this->cargar()->assertStatus(200);

        $estados_cargados = FollowupTemplate::whereIn('template_name', array_column($this->las_diez_del_chequeo_diario(), 'template_name'))
            ->pluck('estado')
            ->unique();

        $this->assertSame(
            ['manual_coordinacion', 'manual_closer', 'manual_nutricion'],
            $estados_cargados->values()->all(),
            'Los estados cargados no son los tres esperados: revisá el payload de prueba.'
        );

        $slugs_reales = LeadPipelineStatus::all_slugs();

        foreach ($estados_cargados as $estado) {
            $this->assertNotContains(
                $estado,
                $slugs_reales,
                "El estado '{$estado}' coincide con un status real del pipeline: LeadFollowupService lo dispararía solo."
            );
        }
    }

    /**
     * 🔴 Extremo a extremo: un lead en un status REAL del pipeline, con una plantilla real Y las
     * diez manuales cargadas a la vez, nunca recibe una plantilla manual del cron automático —
     * recibe la suya, la del estado real.
     *
     * @return void
     */
    public function test_leadfollowupservice_nunca_dispara_una_plantilla_manual_para_un_lead_real()
    {
        $this->cargar()->assertStatus(200);

        // 🔴 Se usa un estado que NO tiene plantillas ya sembradas en la base del slot (a diferencia
        // de 'nuevo', 'contactado', 'calificado', etc., que FollowupTemplatesSeeder ya carga), para
        // que la única plantilla candidata en juego sea la que crea este test — sin eso, un empate
        // de dia_numero contra una fila ya sembrada haría que el orden de desempate (no garantizado
        // por SQL) decidiera cuál gana, y el test mediría esa ambigüedad en vez de la garantía real.
        $estado_de_prueba = 'estado_de_prueba_sin_seed_' . uniqid();

        $template_real = FollowupTemplate::create([
            'estado'        => $estado_de_prueba,
            'dia_numero'    => 1,
            'template_name' => 'cc_seg_prueba_sin_fuga',
            'body_template' => 'Hola {{1}}! Plantilla real de prueba.',
            'language_code' => 'es_AR',
            'activa'        => true,
        ]);

        FollowupRule::create([
            'estado'        => $estado_de_prueba,
            'horas_espera'  => 0,
            'max_followups' => 5,
            'activa'        => true,
        ]);

        $lead                = new Lead();
        $lead->phone         = '+5493410000000';
        $lead->contact_name  = 'Prueba Chequeo Diario';
        $lead->status        = $estado_de_prueba;
        $lead->save();

        // No importa si el envío real sale o falla (no se mockea WhatsappSendService a propósito:
        // lo único que se verifica es QUÉ PLANTILLA se resolvió, no si Kapso la aceptó).
        app(LeadFollowupService::class)->process_single_lead($lead);

        $mensaje = \App\Models\LeadMessage::where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->first();

        $this->assertNotNull($mensaje, 'El lead de prueba con regla activa no generó ningún seguimiento.');
        $this->assertSame(
            (int) $template_real->id,
            (int) $mensaje->followup_template_id,
            'El seguimiento automático usó una plantilla distinta de la real del estado de prueba — posible fuga de una plantilla manual.'
        );

        $nombres_manuales = array_column($this->las_diez_del_chequeo_diario(), 'template_name');
        $this->assertNotContains(
            FollowupTemplate::find($mensaje->followup_template_id)->template_name,
            $nombres_manuales,
            'LeadFollowupService disparó una plantilla manual del chequeo diario sola.'
        );
    }

    /**
     * Las diez, una vez cargadas, aparecen en GET claude/templates — que es el problema original
     * que motivó este endpoint (no había forma de darlas de alta y por eso no aparecían).
     *
     * @return void
     */
    public function test_las_diez_aparecen_en_get_claude_templates()
    {
        $this->cargar()->assertStatus(200);

        $response = $this->withHeaders($this->headers())->getJson('/api/claude/templates');
        $response->assertStatus(200);

        $nombres = array_column((array) $response->json('data'), 'template_name');

        foreach (array_column($this->las_diez_del_chequeo_diario(), 'template_name') as $nombre) {
            $this->assertContains($nombre, $nombres, "'{$nombre}' no aparece en GET claude/templates.");
        }
    }
}
