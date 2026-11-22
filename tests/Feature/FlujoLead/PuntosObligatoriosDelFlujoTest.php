<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\KnowledgeGroundingGate;
use App\Services\LeadAiService;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadSuggestionSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Puntos obligatorios que siguen la conversación (misión embudo-whatsapp-precios, etapa E2, criterios CA27 a
 * CA32 del PLAN; §2.2.2, decisión P3 de Lucas del 21/11/2026).
 *
 * Lo que Lucas pidió: Martín responde lo que el lead pregunta y después manda sólo lo que todavía no dijo, sin
 * repetir. Si el lead pregunta «¿en qué consiste ComercioCity?», Martín contesta eso y NO manda además la
 * bienvenida que lo explica de nuevo. Si no pregunta, salen los textos literales.
 *
 * Cómo se prueba, con el interruptor prendido, 7 días de prueba, los precios por defecto de E1 y la IA simulada
 * (`Http::fake` sobre api.anthropic.com, sin llamar al modelo real):
 *  - CA27 `tiene_pregunta` clasifica la tabla de nueve textos.
 *  - CA28 el que llega preguntando no recibe ningún literal; se agenda la IA y el contexto trae los puntos del
 *    paso 1.
 *  - CA29 si la IA ya cubrió un punto no se repite; si le falta uno, se completa SÓLO ese, al final y separado
 *    por una línea `---` (burbujas separadas al enviarse).
 *  - CA30 el que contesta preguntando en el paso 2 recibe su respuesta y después sólo lo que falta del paso 2.
 *  - CA31 el gate de respaldo sigue valiendo: si frena, no se agrega ningún literal.
 *  - CA32 el registro de lo entregado sale del hilo (sólo cuenta lo efectivamente enviado).
 *
 * Hoy están EN ROJO: `LeadFlujoEntradaService` no existe, así que no hay clasificación, ni puntos, ni completado.
 */
class PuntosObligatoriosDelFlujoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Texto precargado de un anuncio que Lucas carga en `lead_whatsapp_flujo_textos_precargados` (tiene «?»). */
    private const PRECARGADO_CON_SIGNO = '¿Me pasan información de ComercioCity?';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        AdminSetting::set('lead_whatsapp_flujo_textos_precargados', self::PRECARGADO_CON_SIGNO . "\nHola, vi el video");
        $this->sembrar_protocolo_de_leads();
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
    // CA27 — ¿preguntó algo?
    // ------------------------------------------------------------------------------------------

    /**
     * CA27. `LeadFlujoEntradaService::tiene_pregunta` devuelve el valor esperado para cada fila de la tabla del
     * PLAN: false para un saludo, la frase de la web, el rubro con la cantidad, un texto precargado de un anuncio
     * (aunque tenga «?») y «sale mucho»; true cuando hay un signo de pregunta o una expresión de pregunta.
     *
     * @dataProvider provider_de_textos
     *
     * @param string $texto Lo que escribe el lead.
     * @param bool   $esperado Si se considera una pregunta.
     *
     * @return void
     */
    public function test_tiene_pregunta_clasifica_la_tabla(string $texto, bool $esperado): void
    {
        $this->exigir_clase(LeadFlujoEntradaService::class, 'clasificador de preguntas (CA27)');

        $this->assertSame($esperado, LeadFlujoEntradaService::tiene_pregunta($texto), "Texto: «{$texto}»");
    }

    /**
     * Las nueve filas de CA27.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public function provider_de_textos(): array
    {
        return [
            'saludo'                          => ['Hola', false],
            'frase de la web'                 => ['Hola, quiero probar ComercioCity', false],
            'rubro y cantidad'                => ['Ferretería, unos 4000', false],
            'precargado de un anuncio con ?'  => [self::PRECARGADO_CON_SIGNO, false],
            'en qué consiste, con signos'     => ['che, ¿en qué consiste ComercioCity?', true],
            'en que consiste, sin signos'     => ['en que consiste', true],
            'precio'                          => ['precio', true],
            'cuánto sale, con ?'              => ['Ferretería, 4000. Cuánto sale?', true],
            '«sale mucho» no es pregunta'     => ['Ferretería, sale mucho la pintura', false],
        ];
    }

    // ------------------------------------------------------------------------------------------
    // CA28 — llega preguntando
    // ------------------------------------------------------------------------------------------

    /**
     * CA28. El primer mensaje «che, ¿en qué consiste ComercioCity?» NO dispara ningún mensaje de sistema del flujo
     * (ni `llegada_1` ni `whatsapp_entrada_2`), SÍ agenda la sugerencia de IA para ese primer inbound, y el contexto
     * del turno trae «PUNTOS OBLIGATORIOS DE ESTE TURNO» con `presentacion` y `prueba_y_rubro` (sus textos
     * literales) y sin el texto de `respuesta_1`.
     *
     * @return void
     */
    public function test_llega_preguntando_no_sale_literal_y_agenda_ia_con_los_puntos_del_paso_1(): void
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, 'che, ¿en qué consiste ComercioCity?')->assertStatus(200);

        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();

        $this->assertCount(0, $this->jobs_encolados(SendLeadPresentationMessageJob::class), 'No tiene que encolarse la llegada literal.');
        $this->assertSame(0, $this->mensajes_de_sistema($lead)->count(), 'Ni la llegada ni el auto: le contesta la IA.');
        $this->assertCount(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class), 'Se agenda la IA también para el primer inbound.');

        $contexto = $this->contexto_del_turno($lead->fresh());

        $this->assertStringContainsString('PUNTOS OBLIGATORIOS DE ESTE TURNO', $contexto);
        $this->assertStringContainsString('presentacion', $contexto);
        $this->assertStringContainsString('prueba_y_rubro', $contexto);
        $this->assertStringContainsString($this->texto_llegada_1(), $contexto, 'Trae el texto literal de referencia de `presentacion`.');
        $this->assertStringContainsString($this->texto_llegada_2(), $contexto, 'Trae el texto literal de referencia de `prueba_y_rubro`.');
        $this->assertStringNotContainsString('Tenés dos formas de arrancar', $contexto, 'Todavía no se deben las dos formas.');
    }

    // ------------------------------------------------------------------------------------------
    // CA29 — no repite lo que ya contestó
    // ------------------------------------------------------------------------------------------

    /**
     * CA29, primer caso. La IA contestó qué es ComercioCity Y pidió rubro y cantidad: cubrió los dos puntos del
     * paso 1. El mensaje guardado es IGUAL al de la IA (no se agregó nada), no contiene el texto de `llegada_1`, y
     * al enviarse sale en dos burbujas.
     *
     * @return void
     */
    public function test_la_ia_ya_cubrio_todo_no_se_agrega_nada(): void
    {
        $this->exigir_clase(LeadFlujoEntradaService::class, 'puntos debidos (CA29)');

        $lead = $this->lead_que_llega_preguntando();
        $this->assertSame(
            ['presentacion', 'prueba_y_rubro'],
            LeadFlujoEntradaService::puntos_debidos($lead),
            'Precondición: al que llega preguntando se le deben los dos puntos del paso 1.'
        );

        $texto_ia = "ComercioCity es una plataforma de gestión para comercios: sistema, tienda online y agentes de IA.\n"
            . "---\n"
            . 'Lo podés probar gratis 7 días. ¿Qué rubro trabajás y cuántos productos manejás?';
        $this->fakear_ia([$this->paquete_ia($texto_ia, 'nuevo')]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertSame(trim($texto_ia), trim((string) $mensaje->content), 'No se agrega nada si la IA ya cubrió los puntos.');
        $this->assertStringNotContainsString('Soy Martín, de ComercioCity, una plataforma', (string) $mensaje->content, 'No repite la presentación.');

        $espia = $this->espiar_whatsapp();
        (new LeadSuggestionSendService($espia))->send_suggestion($mensaje);
        $this->assertCount(2, $espia->textos, 'Sale en burbujas separadas, una por cada parte.');
    }

    /**
     * CA29, segundo caso. La IA sólo explicó qué es ComercioCity: falta `prueba_y_rubro`. El mensaje guardado es el
     * de la IA + «\n---\n» + el texto literal de `llegada_2`; NO trae `llegada_1`; el razonamiento dice «Flujo de
     * entrada: se agregaron prueba_y_rubro»; y al enviarse salen dos burbujas.
     *
     * @return void
     */
    public function test_la_ia_explico_que_es_y_se_agrega_solo_llegada_2(): void
    {
        $this->exigir_clase(LeadFlujoEntradaService::class, 'puntos debidos (CA29)');

        $lead = $this->lead_que_llega_preguntando();
        $this->assertContains('prueba_y_rubro', LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: se debe `prueba_y_rubro`.');

        $texto_ia = 'ComercioCity es una plataforma de gestión para comercios, con sistema, tienda online y agentes de IA.';
        $this->fakear_ia([$this->paquete_ia($texto_ia, 'nuevo')]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertSame($texto_ia . "\n---\n" . $this->texto_llegada_2(), trim((string) $mensaje->content));
        $this->assertStringNotContainsString('Soy Martín, de ComercioCity, una plataforma', (string) $mensaje->content, 'No repite la presentación.');
        $this->assertStringContainsString('Flujo de entrada: se agregaron prueba_y_rubro', (string) $mensaje->ai_reasoning);

        $espia = $this->espiar_whatsapp();
        (new LeadSuggestionSendService($espia))->send_suggestion($mensaje);
        $this->assertSame([$texto_ia, $this->texto_llegada_2()], $this->cuerpos_enviados($espia), 'Salen dos burbujas: la de la IA y la agregada.');
    }

    // ------------------------------------------------------------------------------------------
    // CA30 — contesta preguntando, paso 2
    // ------------------------------------------------------------------------------------------

    /**
     * CA30. Lead en `contactado` con los dos puntos del paso 1 enviados. Responde «Ferretería, 4000. ¿Cuánto
     * sale?»: no se encola `SendLeadDosFormasJob`, sí se agenda la IA; la IA contesta el precio sin las dos formas
     * ni la página, y el mensaje guardado es su texto + `respuesta_1` + `respuesta_2`, en ese orden y separados
     * por `---`.
     *
     * @return void
     */
    public function test_paso_2_con_pregunta_completa_dos_formas_y_pagina_en_orden(): void
    {
        $lead = $this->lead_que_responde_el_paso_1_preguntando();

        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'Con pregunta no sale el literal.');
        $this->assertCount(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class), 'Con pregunta se agenda la IA.');

        $texto_ia = 'El plan Inicial sale $55.900 por mes, y los primeros 3 meses con 50 % off.';
        $this->fakear_ia([$this->paquete_ia($texto_ia, 'contactado', KnowledgeGroundingGate::TIPO_AFIRMACION, ['precios_vigentes'])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(
            $texto_ia . "\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2(),
            trim((string) $mensaje->content),
            'La IA contesta el precio y después van las dos formas y la página, en ese orden.'
        );
    }

    /**
     * CA30. Si la IA ya incluyó «$628.400» y «gratis» (o sea, dio las dos formas), sólo se agrega `respuesta_2`.
     *
     * @return void
     */
    public function test_paso_2_si_la_ia_dio_las_dos_formas_solo_va_la_pagina(): void
    {
        $lead = $this->lead_que_responde_el_paso_1_preguntando();

        $texto_ia = 'El plan Inicial sale $55.900 por mes. Podés arrancar gratis por tu cuenta, o te lo cargamos nosotros con un pago único de $628.400.';
        $this->fakear_ia([$this->paquete_ia($texto_ia, 'contactado', KnowledgeGroundingGate::TIPO_AFIRMACION, ['precios_vigentes'])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(
            $texto_ia . "\n---\n" . $this->texto_respuesta_2(),
            trim((string) $mensaje->content),
            'Si la IA ya dio las dos formas, sólo falta la página.'
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA31 — el gate sigue valiendo
    // ------------------------------------------------------------------------------------------

    /**
     * CA31. La IA afirma un precio con `fuentes_kb: ["precios"]` sin haber leído el recurso: el gate frena. El
     * mensaje guardado es el de espera (`MENSAJE_DE_ESPERA_SIN_RESPALDO`), con `requiere_intervencion_humana` y
     * `requiere_verificacion`, y SIN ningún literal agregado. Los puntos siguen debidos.
     *
     * @return void
     */
    public function test_el_gate_frena_y_no_se_agrega_ningun_literal(): void
    {
        $this->exigir_clase(LeadFlujoEntradaService::class, 'puntos debidos (CA31)');

        $lead = $this->lead_que_responde_el_paso_1_preguntando();

        $this->fakear_ia([$this->paquete_ia('El plan Inicial sale $55.900 por mes.', 'contactado', KnowledgeGroundingGate::TIPO_AFIRMACION, ['precios'])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO, (string) $mensaje->content, 'El gate frena y no se agrega nada.');
        $this->assertTrue((bool) $mensaje->requiere_verificacion);
        $this->assertTrue((bool) $lead->fresh()->requiere_intervencion_humana);
        $this->assertStringNotContainsString('Tenés dos formas de arrancar', (string) $mensaje->content);
        $this->assertStringNotContainsString('comerciocity.com', (string) $mensaje->content);

        $this->assertSame(
            ['dos_formas', 'pagina'],
            LeadFlujoEntradaService::puntos_debidos($lead->fresh()),
            'Lo que frenó el gate no cuenta como entregado: los puntos siguen debidos.'
        );
    }

    /**
     * CA31. El mismo paquete citando `precios_vigentes` pasa el gate y se completa como en CA30.
     *
     * @return void
     */
    public function test_con_precios_vigentes_pasa_y_se_completa(): void
    {
        $lead = $this->lead_que_responde_el_paso_1_preguntando();

        $texto_ia = 'El plan Inicial sale $55.900 por mes.';
        $this->fakear_ia([$this->paquete_ia($texto_ia, 'contactado', KnowledgeGroundingGate::TIPO_AFIRMACION, ['precios_vigentes'])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertNotSame(LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO, (string) $mensaje->content, 'Citar precios_vigentes respalda la afirmación.');
        $this->assertSame(
            $texto_ia . "\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2(),
            trim((string) $mensaje->content)
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA32 — el registro sale del hilo
    // ------------------------------------------------------------------------------------------

    /**
     * CA32. Un mensaje de la IA YA ENVIADO que contiene «$628.400» y «gratis» cuenta como `dos_formas` entregado:
     * el siguiente inbound sin pregunta hace que `SendLeadDosFormasJob` mande SÓLO `respuesta_2`.
     *
     * @return void
     */
    public function test_un_punto_enviado_por_la_ia_no_se_repite_en_el_literal(): void
    {
        $mensajes = $this->mandar_el_siguiente_inbound_sin_pregunta('enviado');

        $this->assertSame([$this->texto_respuesta_2()], $mensajes, 'Las dos formas ya las dijo la IA: sólo va la página.');
    }

    /**
     * CA32. La misma sugerencia PENDIENTE de aprobación, o RECHAZADA, no cuenta como entregada: el job manda las
     * dos (`respuesta_1` y `respuesta_2`).
     *
     * @return void
     */
    public function test_una_sugerencia_pendiente_no_cuenta(): void
    {
        foreach (['sugerido', 'rechazado'] as $status) {
            $mensajes = $this->mandar_el_siguiente_inbound_sin_pregunta($status);

            $this->assertSame(
                [$this->texto_respuesta_1(), $this->texto_respuesta_2()],
                $mensajes,
                "Una sugerencia en estado `{$status}` no salió: las dos formas siguen debidas."
            );
        }
    }

    /**
     * CA32. Con el interruptor apagado, `puntos_debidos` es `[]` y el contexto no trae el bloque de puntos. Con el
     * interruptor prendido pero el lead ya con su prueba (`prueba_activa`), tampoco se deben puntos.
     *
     * @return void
     */
    public function test_apagado_no_hay_puntos(): void
    {
        $this->exigir_clase(LeadFlujoEntradaService::class, 'puntos debidos (CA32)');

        $lead = $this->lead_que_llega_preguntando();
        $this->assertNotEmpty(LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: prendido, al que llega preguntando se le deben puntos.');
        $this->assertStringContainsString('PUNTOS OBLIGATORIOS DE ESTE TURNO', $this->contexto_del_turno($lead->fresh()));

        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($lead->fresh()), 'Apagado no se debe ningún punto.');
        $this->assertStringNotContainsString('PUNTOS OBLIGATORIOS DE ESTE TURNO', $this->contexto_del_turno($lead->fresh()));

        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '1');
        $con_prueba = $this->crear_lead_de_flujo('prueba_activa');
        $this->mensaje_del_lead($con_prueba, 'che, ¿cómo ingreso?');
        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($con_prueba->fresh()), 'Con la prueba creada no se deben puntos.');
    }

    // ------------------------------------------------------------------------------------------
    // Helpers propios
    // ------------------------------------------------------------------------------------------

    /**
     * Lead nuevo cuyo primer mensaje es una pregunta (llegó preguntando; no salió ningún literal).
     *
     * @return Lead
     */
    private function lead_que_llega_preguntando(): Lead
    {
        $lead = $this->crear_lead_de_flujo('nuevo');
        $this->mensaje_del_lead($lead, 'che, ¿en qué consiste ComercioCity?');

        return $lead->fresh();
    }

    /**
     * Lead en `contactado` con los dos puntos del paso 1 ya enviados, que responde por el webhook real
     * «Ferretería, 4000. ¿Cuánto sale?» (una pregunta en el paso 2).
     *
     * @return Lead
     */
    private function lead_que_responde_el_paso_1_preguntando(): Lead
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(120);

        $this->postear_kapso($telefono, 'Ferretería, 4000. ¿Cuánto sale?')->assertStatus(200);

        return $lead->fresh();
    }

    /**
     * Arma un lead que ya recibió el paso 1, preguntó por el precio y a quien la IA le contestó con las dos formas
     * (en el estado indicado); después manda por el webhook un mensaje SIN pregunta, corre el job de las dos
     * formas y devuelve lo que ese job mandó.
     *
     * @param string $status Estado del mensaje de la IA: `enviado`, `sugerido` o `rechazado`.
     *
     * @return array<int, string> Cuerpos que mandó el job, en orden.
     */
    private function mandar_el_siguiente_inbound_sin_pregunta(string $status): array
    {
        $this->exigir_clase(SendLeadDosFormasJob::class, 'job de las dos formas (CA32)');

        /* Cola limpia: el helper se llama más de una vez por test y cada vuelta cuenta sus propios jobs. */
        Queue::fake();

        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Dale, ¿cuánto sale?');
        $this->avanzar(60);
        $this->mensaje_de_sistema(
            $lead,
            'El plan Inicial sale $55.900. Podés arrancar gratis por tu cuenta, o te lo cargamos con un pago único de $628.400.',
            null,
            $status
        );
        $this->avanzar(60);

        $this->postear_kapso($telefono, 'Ok, gracias')->assertStatus(200);

        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'El inbound sin pregunta tiene que encolar las dos formas.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());

        return $this->cuerpos_enviados($espia);
    }
}
