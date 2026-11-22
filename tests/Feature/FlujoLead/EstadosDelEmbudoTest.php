<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\TrialInstance;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingService;
use Database\Seeders\LeadPipelineStatusEmbudoSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Estados del embudo y bloque del agente (misión embudo-whatsapp-precios, etapa E2, criterios CA11, CA14 y CA15
 * del PLAN; §2.2 y §2.2.1).
 *
 * Los estados se MAPEAN, no se renombran: `contactado` se llama «Conversando», `prueba_activa` se llama «En
 * prueba», y nacen `link_enviado` e `implementacion_pedida`. Ningún lead cambia de estado por esto. El link de la
 * prueba sale a pedido (lo dice el bloque del agente) y, al enviarse, pasa al lead a `link_enviado`. Quien pide la
 * demo explícitamente sigue el flujo de la demo de hoy.
 *
 * Hoy están EN ROJO: no existen `LeadFlujoEntradaService`, el seeder del embudo ni la transición al enviar.
 */
class EstadosDelEmbudoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // CA11 — el link a pedido
    // ------------------------------------------------------------------------------------------

    /**
     * CA11. Con el interruptor prendido y el lead en `contactado`, el contexto del turno trae el bloque del flujo:
     * el link textual de la prueba, `estado_sugerido: "link_enviado"`, «No le pidas hora» e `implementacion_pedida`;
     * y NO trae el bloque de la oferta de la web («PRUEBA GRATIS (lead de la web»), aunque al lead se le haya hecho
     * esa oferta: el del flujo la reemplaza. Apagado, el bloque del flujo no aparece.
     *
     * @return void
     */
    public function test_el_bloque_del_flujo_manda_el_link_solo_a_pedido(): void
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_de_sistema($lead, $this->texto_llegada_1(), LeadWhatsappOnboardingService::KIND_WELCOME);
        /* Un mensaje de la oferta de hoy (sin el link en el texto, para que el link buscado no salga del hilo). */
        $this->mensaje_de_sistema($lead, 'Tu prueba la creás desde acá, en 2 minutos', LeadWhatsappOnboardingService::KIND_PRUEBA_LINK);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 4000');

        $this->assertNotSame(
            '',
            LeadOfertaDePruebaService::bloque_para_el_agente($lead->fresh()),
            'Precondición: con la oferta hecha, el bloque de la oferta de hoy existiría si nadie lo reemplazara.'
        );

        $contexto = $this->contexto_del_turno($lead->fresh());

        $this->assertStringContainsString(LeadOfertaDePruebaService::link(), $contexto, 'El bloque del flujo tiene que traer el link textual.');
        $this->assertStringContainsString('estado_sugerido: "link_enviado"', $contexto);
        $this->assertStringContainsString('No le pidas hora', $contexto);
        $this->assertStringContainsString('implementacion_pedida', $contexto);
        $this->assertStringNotContainsString('PRUEBA GRATIS (lead de la web', $contexto, 'El bloque del flujo reemplaza al de la oferta de la web.');

        /* Apagado, vuelve el bloque de hoy y no hay rastro del flujo. */
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
        $apagado = $this->contexto_del_turno($lead->fresh());
        $this->assertStringContainsString('PRUEBA GRATIS (lead de la web', $apagado);
        $this->assertStringNotContainsString('estado_sugerido: "link_enviado"', $apagado);
    }

    /**
     * CA11 (§2.2). Si el pool de pruebas no tiene lugar, el bloque NO le pasa el link al lead: dice que Juan le
     * escribe para darle el acceso y marca `requiere_intervencion_humana: true`.
     *
     * Criterio sacado del texto de §2.2 (bloque para el agente), no de la lista de CA.
     *
     * @return void
     */
    public function test_sin_lugar_en_el_pool_el_bloque_no_pasa_el_link(): void
    {
        TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])
            ->update(['estado' => TrialInstance::ESTADO_FALLIDA]);

        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_de_sistema($lead, $this->texto_llegada_1(), LeadWhatsappOnboardingService::KIND_WELCOME);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 4000');

        $this->exigir_clase(LeadFlujoEntradaService::class, 'bloque del agente (§2.2)');
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($lead->fresh());

        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $bloque, 'Sin lugar en el pool no se manda el link.');
        $this->assertStringContainsString('Juan te escribe para darte el acceso', $bloque);
        $this->assertStringContainsString('requiere_intervencion_humana: true', $bloque);
    }

    /**
     * CA11. Un mensaje saliente cuyo texto contiene el link de la prueba pasa al lead de `nuevo`, `contactado` o
     * `calificado` a `link_enviado` AL ENVIARSE (también si el setter editó la sugerencia), y queda el evento de
     * estado en el hilo. Un mensaje sin el link no lo mueve.
     *
     * @return void
     */
    public function test_enviar_el_link_pasa_a_link_enviado(): void
    {
        foreach (['nuevo', 'contactado', 'calificado'] as $estado) {
            $lead = $this->crear_lead_de_flujo($estado);
            $this->mensaje_del_lead($lead, 'Dale, quiero probar');
            $sugerencia = $this->crear_sugerencia($lead, 'Genial, creá tu prueba acá: ' . LeadOfertaDePruebaService::link());

            (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($sugerencia);

            $this->assertSame('link_enviado', (string) $lead->fresh()->status, "Desde `{$estado}` el link enviado tiene que dejar el lead en link_enviado.");
            $evento = LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->latest('id')->first();
            $this->assertNotNull($evento, "Desde `{$estado}` tiene que quedar el evento de estado en el hilo.");
        }

        /* El texto lo editó el setter al aprobar: lo que cuenta es lo que sale. */
        $editado = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($editado, 'Dale, quiero probar');
        $sugerida = $this->crear_sugerencia($editado, 'Texto original sin link.');
        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($sugerida, 'Armala acá: ' . LeadOfertaDePruebaService::link());
        $this->assertSame('link_enviado', (string) $editado->fresh()->status, 'Cubre también al setter que edita la sugerencia.');

        /* Un mensaje sin el link no cambia nada. */
        $sin_link = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($sin_link, 'Contame más');
        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($this->crear_sugerencia($sin_link, 'Claro, te cuento más.'));
        $this->assertSame('contactado', (string) $sin_link->fresh()->status);
    }

    /**
     * CA11. Si el lead está en la demo (`demo_agendada`) o más adelante (`closer_activo`), enviar el link no lo
     * mueve: el flujo de la demo no se toca. La precondición (un lead en `contactado` SÍ se mueve) hace que el test
     * no pase por vacío antes de que exista la transición.
     *
     * @return void
     */
    public function test_el_link_no_mueve_a_un_lead_en_demo(): void
    {
        $control = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($control, 'Quiero probar');
        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($this->crear_sugerencia($control, 'Acá: ' . LeadOfertaDePruebaService::link()));
        $this->assertSame('link_enviado', (string) $control->fresh()->status, 'Precondición: desde contactado el link sí mueve al lead.');

        foreach (['demo_agendada', 'closer_activo'] as $estado) {
            $lead = $this->crear_lead_de_flujo($estado);
            $this->mensaje_del_lead($lead, 'Quiero probar');

            (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($this->crear_sugerencia($lead, 'Acá: ' . LeadOfertaDePruebaService::link()));

            $this->assertSame($estado, (string) $lead->fresh()->status, "El lead en `{$estado}` no tiene que cambiar por enviarle el link.");
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA14 — el seeder de estados
    // ------------------------------------------------------------------------------------------

    /**
     * CA14. `LeadPipelineStatusEmbudoSeeder` es idempotente: corrido dos veces deja `link_enviado` e
     * `implementacion_pedida` una sola vez cada uno (con su color y su grupo), cambia la etiqueta de `contactado`
     * a «Conversando» y la de `prueba_activa` a «En prueba» sólo si tenían la etiqueta vieja por defecto, y NO pisa
     * una edición a mano («Respondió»). Y las constantes para las bases nuevas ya traen todo eso.
     *
     * @return void
     */
    public function test_el_seeder_es_idempotente_y_no_pisa_etiquetas_editadas(): void
    {
        $this->exigir_clase(LeadPipelineStatusEmbudoSeeder::class, 'seeder standalone de estados (CA14)');

        /* Catálogo base de hoy, con las etiquetas viejas por defecto y sin los dos estados nuevos. */
        $this->seed(LeadPipelineStatusSeeder::class);
        LeadPipelineStatus::query()->whereIn('slug', ['link_enviado', 'implementacion_pedida'])->delete();
        LeadPipelineStatus::query()->where('slug', 'contactado')->update(['label' => 'Contactado']);
        LeadPipelineStatus::query()->where('slug', 'prueba_activa')->update(['label' => 'Prueba activa']);

        $this->seed(LeadPipelineStatusEmbudoSeeder::class);
        $this->seed(LeadPipelineStatusEmbudoSeeder::class);

        $this->assertSame(1, LeadPipelineStatus::query()->where('slug', 'link_enviado')->count(), 'link_enviado una sola vez.');
        $this->assertSame(1, LeadPipelineStatus::query()->where('slug', 'implementacion_pedida')->count(), 'implementacion_pedida una sola vez.');
        $this->assertSame('Conversando', LeadPipelineStatus::label_for('contactado'));
        $this->assertSame('En prueba', LeadPipelineStatus::label_for('prueba_activa'));
        $this->assertSame('#0dcaf0', LeadPipelineStatus::query()->where('slug', 'link_enviado')->value('color'));
        $this->assertSame('#fd7e14', LeadPipelineStatus::query()->where('slug', 'implementacion_pedida')->value('color'));

        /* Las etiquetas editadas a mano no se pisan, ni siquiera con el seeder corrido otra vez. */
        LeadPipelineStatus::query()->where('slug', 'contactado')->update(['label' => 'Respondió']);
        LeadPipelineStatus::query()->where('slug', 'prueba_activa')->update(['label' => 'Probando']);
        $this->seed(LeadPipelineStatusEmbudoSeeder::class);
        $this->assertSame('Respondió', LeadPipelineStatus::label_for('contactado'), 'Una etiqueta editada a mano no se pisa.');
        $this->assertSame('Probando', LeadPipelineStatus::label_for('prueba_activa'));
        $this->assertSame(1, LeadPipelineStatus::query()->where('slug', 'link_enviado')->count());

        /* Para las bases nuevas (LeadPipelineStatusSeeder los toma de las constantes). */
        $this->assertSame('Conversando', LeadPipelineStatus::DEFAULT_STATUSES['contactado']);
        $this->assertSame('En prueba', LeadPipelineStatus::DEFAULT_STATUSES['prueba_activa']);
        $this->assertArrayHasKey('link_enviado', LeadPipelineStatus::DEFAULT_STATUSES);
        $this->assertArrayHasKey('implementacion_pedida', LeadPipelineStatus::DEFAULT_STATUSES);
        $this->assertSame('#0dcaf0', LeadPipelineStatus::DEFAULT_COLORS['link_enviado']);
        $this->assertSame('#fd7e14', LeadPipelineStatus::DEFAULT_COLORS['implementacion_pedida']);
        $this->assertSame('Prueba', LeadPipelineStatus::DEFAULT_STATUS_GROUPS['link_enviado']);
        $this->assertSame('Cierre', LeadPipelineStatus::DEFAULT_STATUS_GROUPS['implementacion_pedida']);
    }

    /**
     * CA14. El seeder no toca ningún lead: el conteo de leads por estado es idéntico antes y después.
     *
     * @return void
     */
    public function test_ningun_lead_cambia_de_estado(): void
    {
        $this->exigir_clase(LeadPipelineStatusEmbudoSeeder::class, 'seeder standalone de estados (CA14)');

        foreach (['nuevo', 'contactado', 'contactado', 'calificado', 'prueba_activa', 'demo_agendada', 'cerrado_ganado'] as $estado) {
            $this->crear_lead_de_flujo($estado);
        }

        $conteo = function () {
            return DB::table('leads')->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->orderBy('status')->pluck('total', 'status')->all();
        };

        $antes = $conteo();
        $this->seed(LeadPipelineStatusEmbudoSeeder::class);
        $this->seed(LeadPipelineStatusEmbudoSeeder::class);

        $this->assertSame($antes, $conteo(), 'El seeder de estados no puede mover ningún lead.');
    }

    // ------------------------------------------------------------------------------------------
    // CA15 — la demo a pedido
    // ------------------------------------------------------------------------------------------

    /**
     * CA15. Con el interruptor prendido, el bloque del flujo dice «SALVO que el lead pida EXPLICITAMENTE una DEMO»
     * (la demo por turno sólo a pedido). Y un lead en `solicita_disponibilidad` NO recibe el bloque del flujo: su
     * contexto es idéntico al de hoy (el mismo con el interruptor apagado), con el bloque de la demo de siempre.
     *
     * @return void
     */
    public function test_el_lead_que_pide_la_demo_sigue_el_flujo_de_la_demo(): void
    {
        $this->exigir_clase(LeadFlujoEntradaService::class, 'bloque del agente (CA15)');

        $conversando = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_de_sistema($conversando, $this->texto_llegada_1(), LeadWhatsappOnboardingService::KIND_WELCOME);
        $this->mensaje_del_lead($conversando, 'Ferretería, unos 4000');

        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($conversando->fresh());
        $this->assertStringContainsString('SALVO que el lead pida EXPLICITAMENTE una DEMO', $bloque);

        /* El que ya pidió la demo: sin bloque del flujo, y el contexto no cambia por el interruptor. */
        $con_demo = $this->crear_lead_de_flujo('solicita_disponibilidad');
        $this->mensaje_de_sistema($con_demo, $this->texto_llegada_1(), LeadWhatsappOnboardingService::KIND_WELCOME);
        $this->mensaje_del_lead($con_demo, 'Quiero ver la demo, ¿cuándo pueden?');

        $this->assertSame('', LeadFlujoEntradaService::bloque_para_el_agente($con_demo->fresh()), 'Pidió la demo: no recibe el bloque del flujo.');

        $con_flujo = $this->contexto_del_turno($con_demo->fresh());
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
        $sin_flujo = $this->contexto_del_turno($con_demo->fresh());

        $this->assertStringNotContainsString('PUNTOS OBLIGATORIOS DE ESTE TURNO', $con_flujo);
        $this->assertSame($sin_flujo, $con_flujo, 'El contexto del lead en la demo no puede cambiar por el flujo nuevo.');
    }

    // ------------------------------------------------------------------------------------------
    // Helpers propios
    // ------------------------------------------------------------------------------------------

    /**
     * Una sugerencia pendiente de aprobación (la que genera el agente) para el lead.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function crear_sugerencia(Lead $lead, string $texto): LeadMessage
    {
        return LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'content' => $texto,
            'status' => 'sugerido', 'is_followup' => false, 'is_status_event' => false,
            'requiere_verificacion' => false, 'suggested_lead_status' => null,
        ]);
    }
}
