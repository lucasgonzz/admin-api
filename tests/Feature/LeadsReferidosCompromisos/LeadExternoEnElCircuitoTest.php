<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadFlujoEntradaService;

/**
 * Un lead que llegó por otro medio entra al MISMO circuito que cualquier lead: criterios CA4 y CA5 de la etapa E1
 * de la misión leads-referidos-compromisos.
 *
 * El defecto que se arregla (PLAN §1.2): a un referido al que ya se le escribió, o que tiene una nota en el hilo,
 * el onboarding genérico no le corre (hay historial) y la IA tampoco le contesta el primer mensaje (el scheduler
 * espera un segundo inbound), así que su primer mensaje quedaba SIN RESPUESTA. Además, Martín no sabía quién lo
 * había referido y le iba a presentar ComercioCity de cero, que es lo que el recurso `referidos` prohíbe.
 *
 *  - CA4: el primer mensaje de un lead externo no dispara la bienvenida genérica, agenda la IA y no pisa el origen.
 *    Un lead `desconocido` en la misma situación sigue como hoy (control de regresión: pasa hoy y tiene que seguir
 *    pasando).
 *  - CA5: el contexto del turno de Martín trae «CÓMO LLEGÓ ESTE LEAD» con quien lo refirió y lo ya hablado
 *    (recortado a 4.000 caracteres); el de un lead común no. Con el flujo de entrada prendido, a un lead externo
 *    no se le debe la `presentacion`.
 *
 * Todo el camino del webhook es real (`POST webhook/whatsapp` firmado); la cola es falsa y no sale nada a la red.
 *
 * Hoy están EN ROJO los de lead externo (el alta, el bloque y la regla no existen); el del lead común es el control.
 */
class LeadExternoEnElCircuitoTest extends BaseDeReferidosYCompromisos
{
    /** Teléfono de Ro en formato E.164, como lo manda Kapso. */
    private const TELEFONO_DE_RO = '+5493484521016';

    /**
     * Da de alta a Ro como referida (por el endpoint real) y devuelve su lead.
     *
     * @param array<string, mixed> $cambios Pisan el cuerpo del alta.
     *
     * @return Lead
     */
    private function dar_de_alta_a_ro(array $cambios = []): Lead
    {
        $this->exigir_columnas_de_referido();
        $this->loguear_a($this->admin);

        $cliente   = $this->crear_cliente_que_refiere();
        $respuesta = $this->postJson('/api/admin/lead/externo', $this->cuerpo_de_alta_externa(array_merge([
            'phone'                  => self::TELEFONO_DE_RO,
            'referido_por_client_id' => (int) $cliente->id,
        ], $cambios)));
        $respuesta->assertStatus(201);

        return Lead::query()->findOrFail((int) $respuesta->json('model.id'));
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — el primer mensaje
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. Ro (referida, en `nuevo`) manda su primer mensaje por el webhook real: no se le crea ningún mensaje de
     * bienvenida ni se le manda el auto, `responde_el_primer_mensaje` da true y queda agendado el job de la IA, y
     * el origen sigue siendo `referido`.
     *
     * @return void
     */
    public function test_primer_inbound_sin_onboarding_y_con_ia(): void
    {
        $lead = $this->dar_de_alta_a_ro();
        $this->postear_kapso(self::TELEFONO_DE_RO, 'Hola, me pasó tu número Cliente CF, quiero ver cómo funciona')->assertStatus(200);

        $this->assertSame(
            0,
            LeadMessage::query()->where('lead_id', (int) $lead->id)->where('sender', 'sistema')->where('is_status_event', false)->count(),
            'No sale ni la bienvenida ni el auto: a quien viene recomendado no se le presenta ComercioCity de cero.'
        );
        $this->assertSame([], $this->envios->textos, 'El onboarding genérico no manda ningún texto.');
        $this->assertTrue(
            LeadAiSuggestionScheduler::responde_el_primer_mensaje((int) $lead->id),
            'La IA contesta el primer mensaje de un lead externo (si no, queda sin respuesta).'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $this->jobs_encolados(GenerateLeadAiSuggestionJob::class)->count(),
            'Tiene que quedar agendada la sugerencia de la IA.'
        );
        $this->assertSame('referido', $lead->fresh()->origen, 'El primer mensaje no pisa el origen cargado al dar de alta.');
    }

    /**
     * Control de regresión de CA4: un lead común (`desconocido`, sin historial) que escribe por primera vez sigue
     * como hoy. Corre el onboarding (hay un mensaje de sistema del onboarding o un envío), la IA NO se agenda con el
     * primer mensaje y `responde_el_primer_mensaje` da false. Pasa hoy y tiene que seguir pasando.
     *
     * @return void
     */
    public function test_lead_comun_sigue_igual(): void
    {
        $lead = $this->crear_lead_del_tablero('nuevo', ['phone' => self::TELEFONO_DE_RO, 'origen' => 'desconocido']);

        $this->postear_kapso(self::TELEFONO_DE_RO, 'Hola, buenas tardes')->assertStatus(200);

        $this->assertFalse(
            LeadAiSuggestionScheduler::responde_el_primer_mensaje((int) $lead->id),
            'Un lead común no tiene la IA en el primer mensaje: lo atiende el onboarding.'
        );
        $this->assertSame(0, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class)->count(), 'La IA no se agenda con el primer mensaje de un lead común.');
        $this->assertGreaterThanOrEqual(
            1,
            LeadMessage::query()->where('lead_id', (int) $lead->id)->where('sender', 'sistema')->count()
                + count($this->envios->textos),
            'El onboarding de siempre corre: saluda al lead.'
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA5 — Martín y el flujo de entrada
    // ------------------------------------------------------------------------------------------

    /**
     * CA5, primera parte. El contexto del turno de Martín para un lead externo trae «CÓMO LLEGÓ ESTE LEAD», el
     * nombre de quien lo refirió y lo ya hablado; si el contexto pasa de 4.000 caracteres se recorta con «…». El
     * de un lead común NO trae el bloque.
     *
     * @return void
     */
    public function test_bloque_de_martin(): void
    {
        $largo = str_repeat('a', 4000) . str_repeat('b', 1000);
        $lead  = $this->dar_de_alta_a_ro(['contexto_previo' => $largo]);

        $contexto = $this->contexto_del_turno($lead->fresh());

        $this->assertStringContainsString('CÓMO LLEGÓ ESTE LEAD', $contexto);
        $this->assertStringContainsString('CF', $contexto, 'El bloque nombra a quien lo refirió.');
        $this->assertStringContainsString(str_repeat('a', 3999), $contexto, 'Lo ya hablado entra (hasta 4.000 caracteres).');
        $this->assertStringNotContainsString(str_repeat('b', 20), $contexto, 'Lo que pasa de 4.000 caracteres se recorta.');
        $this->assertStringContainsString('…', $contexto, 'El recorte se marca con «…».');
        $this->assertStringContainsString('No le presentes ComercioCity desde cero', $contexto, 'Martín recibe la instrucción de no presentarse de cero.');

        $comun = $this->crear_lead_del_tablero('contactado', ['phone' => '+5493511112222']);
        $this->assertStringNotContainsString('CÓMO LLEGÓ ESTE LEAD', $this->contexto_del_turno($comun), 'Un lead común no lleva el bloque.');
    }

    /**
     * CA5, segunda parte. Con el flujo de entrada prendido, `puntos_debidos` de un lead externo en `nuevo` no
     * incluye `presentacion` (ya sabe qué es ComercioCity) pero sí lo que sigue (`prueba_y_rubro`); el de un lead
     * común en `nuevo` incluye los dos.
     *
     * @return void
     */
    public function test_sin_presentacion_en_puntos_debidos(): void
    {
        /* Prender el flujo y esperar: sólo están «en el flujo» los leads creados DESPUÉS del encendido. */
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '1');
        LeadFlujoEntradaService::sellar_encendido();
        $this->avanzar(60);

        $externo = $this->dar_de_alta_a_ro();
        $comun   = $this->crear_lead_de_flujo('nuevo');

        $this->assertSame('nuevo', $externo->fresh()->status, 'Precondición: el lead externo nace en «nuevo».');
        $this->assertContains('presentacion', LeadFlujoEntradaService::puntos_debidos($comun->fresh()), 'Control: a un lead común se le debe la presentación.');

        $debidos = LeadFlujoEntradaService::puntos_debidos($externo->fresh());
        $this->assertNotContains('presentacion', $debidos, 'A un lead externo no se le vuelve a presentar ComercioCity.');
        $this->assertContains('prueba_y_rubro', $debidos, 'El resto del flujo sigue igual: la prueba y el rubro.');
    }
}
