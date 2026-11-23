<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\AdminSetting;
use App\Models\PipelineActivity;
use App\Models\TrialInstance;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use Carbon\Carbon;

/**
 * CA2 de la etapa E1 (misión embudo-juan-pipeline): el lead entra SOLO a la columna «Interesados» del tablero de
 * Juan cuando hace falta que alguien lo llame.
 *
 * Los cuatro disparadores y qué se protege de cada uno:
 *  1. `link_enviado` (por `LeadFlujoEntradaService::marcar_link_enviado`, el camino real de Martín).
 *  2. `implementacion_pedida` (por `aplicar_implementacion_pedida`). Además, si la tarjeta estaba en «En prueba por su
 *     cuenta» o «Volver a llamar», VUELVE a Interesados con la nota «Pidió la implementación»: el lead que seguía solo
 *     cambió de idea y Juan tiene que verlo.
 *  3. La alta de la prueba (`POST landing/prueba/alta`): el estado `prueba_activa` se escribe por query builder y no
 *     dispara el evento del modelo, por eso el servicio de alta tiene que llamar al tablero de forma explícita.
 *  4. Negocio grande: `cantidad_empleados >= 3` en un lead que todavía no avanzó (el umbral es 3, no 2).
 *
 * Y las tres reglas que valen para todos: no duplica (una sola tarjeta abierta por lead), un lead cerrado perdido no
 * entra, y cuando el lead pasa a `cerrado_ganado` su tarjeta va a «Cliente».
 *
 * Hoy están EN ROJO: el seeder del tablero no existe y ningún disparador mueve nada al tablero.
 */
class EntradaAInteresadosTest extends BaseDelTableroDeJuan
{
    /**
     * Siembra el tablero de Juan antes de cada test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();
    }

    /**
     * Afirma que el lead tiene UNA sola tarjeta, abierta, en la columna «Interesados», con la actividad `created`
     * («Entró solo: …») y a Juan como responsable.
     *
     * @param \App\Models\Lead $lead
     * @param string           $motivo_del_test Texto para el mensaje de falla.
     *
     * @return void
     */
    private function afirmar_que_entro_en_interesados($lead, string $motivo_del_test): void
    {
        $tarjetas = $this->tarjetas_del_lead($lead);

        $this->assertCount(1, $tarjetas, $motivo_del_test . ': tendría que haber exactamente UNA tarjeta del lead.');
        $this->assertSame('interesados', $this->clave_de_la_columna($tarjetas[0]), $motivo_del_test . ': la tarjeta entra en «Interesados».');
        $this->assertNull($tarjetas[0]->fresh()->closed_at, $motivo_del_test . ': la tarjeta está abierta.');
        $this->assertSame((int) $this->juan->id, (int) $tarjetas[0]->fresh()->owner_admin_id, $motivo_del_test . ': el responsable es Juan (primer admin de la tarea del embudo).');

        $creadas = PipelineActivity::query()->where('opportunity_id', $tarjetas[0]->id)->where('type', 'created')->get();
        $this->assertCount(1, $creadas, $motivo_del_test . ': queda una actividad `created`.');
        $this->assertStringContainsString('Entró solo', (string) $creadas[0]->body, $motivo_del_test . ': la nota del alta dice por qué entró.');
    }

    // ------------------------------------------------------------------------------------------
    // Disparador 1: link_enviado
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. El lead pasa a `link_enviado` cuando se le manda el link de la prueba (`marcar_link_enviado`) y entra a
     * «Interesados».
     *
     * @return void
     */
    public function test_link_enviado_entra(): void
    {
        /* El flujo de entrada tiene que estar prendido y el lead nacer después de que se prendió (`en_el_flujo`). */
        $this->preparar_flujo_de_entrada(true);
        AdminSetting::set(LeadFlujoEntradaService::KEY_ACTIVADO_EN, Carbon::now()->subDay()->toIso8601String());
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->assertCount(0, $this->tarjetas_del_lead($lead), 'Precondición: un lead que recién conversa no tiene tarjeta.');

        $this->assertTrue(
            LeadFlujoEntradaService::marcar_link_enviado($lead, 'Armala acá: ' . LeadOfertaDePruebaService::link()),
            'Precondición: el link enviado mueve al lead a link_enviado.'
        );
        $this->assertSame('link_enviado', (string) $lead->fresh()->status);

        $this->afirmar_que_entro_en_interesados($lead, 'link_enviado');
    }

    // ------------------------------------------------------------------------------------------
    // Disparador 2: implementacion_pedida
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. El lead pide la implementación y entra a «Interesados»; si ya tenía la tarjeta en «En prueba por su cuenta»
     * o en «Volver a llamar», VUELVE a Interesados con la nota «Pidió la implementación» (sigue habiendo una sola
     * tarjeta abierta).
     *
     * @return void
     */
    public function test_implementacion_pedida_entra_y_reabre(): void
    {
        /* a) Sin tarjeta: entra. */
        $lead = $this->crear_lead_del_tablero('contactado');
        $this->assertTrue(LeadFlujoEntradaService::aplicar_implementacion_pedida($lead, 'implementacion_pedida'));
        $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status);
        $this->afirmar_que_entro_en_interesados($lead, 'implementacion_pedida sin tarjeta');

        /* b) Con la tarjeta en las dos columnas de «espera»: vuelve a Interesados. */
        foreach (['en_prueba_sola', 'esperando_llamada'] as $columna) {
            $lead    = $this->crear_lead_del_tablero('link_enviado');
            $tarjeta = $this->dar_de_alta_en($lead, $columna);

            $this->assertTrue(LeadFlujoEntradaService::aplicar_implementacion_pedida($lead->fresh(), 'implementacion_pedida'));

            $tarjetas = $this->tarjetas_del_lead($lead);
            $this->assertCount(1, $tarjetas, 'Desde «' . $columna . '» no se crea otra tarjeta: se vuelve a usar la que hay.');
            $this->assertSame((int) $tarjeta->id, (int) $tarjetas[0]->id);
            $this->assertSame('interesados', $this->clave_de_la_columna($tarjetas[0]), 'Desde «' . $columna . '» la tarjeta vuelve a «Interesados».');

            $notas = PipelineActivity::query()->where('opportunity_id', $tarjeta->id)->pluck('body')->implode(' | ');
            $this->assertStringContainsString('Pidió la implementación', $notas, 'El movimiento deja la nota «Pidió la implementación».');
        }
    }

    // ------------------------------------------------------------------------------------------
    // Disparador 3: alta de la prueba
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. El alta real de la prueba (`POST landing/prueba/alta`) deja al lead en «Interesados».
     *
     * @return void
     */
    public function test_alta_de_prueba_entra(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);
        $this->assertGreaterThanOrEqual(2, TrialInstance::query()->where('estado', TrialInstance::ESTADO_LIBRE)->count());

        $respuesta = $this->postear_alta(['empresa' => 'Ferretería Norte', 'telefono' => '3444000001']);
        $respuesta->assertStatus(201);

        $lead = $this->lead_del_alta((string) $respuesta->json('uuid'));
        $this->assertSame('prueba_activa', (string) $lead->status, 'Precondición: el alta deja al lead en prueba_activa.');

        $this->afirmar_que_entro_en_interesados($lead, 'alta de la prueba');
    }

    // ------------------------------------------------------------------------------------------
    // Disparador 4: negocio grande
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. `cantidad_empleados = 3` en un lead que todavía no avanzó lo hace entrar; con 2 no entra, y con el lead ya
     * cerrado, en una prueba o ganado tampoco.
     *
     * @return void
     */
    public function test_negocio_grande_entra_y_chico_no(): void
    {
        /* Entra: el umbral es 3, desde cualquiera de los estados tempranos. */
        foreach (['nuevo', 'contactado', 'calificado'] as $estado) {
            $lead = $this->crear_lead_del_tablero($estado);
            $this->assertCount(0, $this->tarjetas_del_lead($lead), 'Precondición: sin empleados cargados no hay tarjeta.');

            $lead->cantidad_empleados = 3;
            $lead->save();

            $this->afirmar_que_entro_en_interesados($lead, 'negocio grande desde «' . $estado . '»');
        }

        /* No entra: 2 empleados es un negocio chico. */
        $chico                      = $this->crear_lead_del_tablero('contactado');
        $chico->cantidad_empleados = 2;
        $chico->save();
        $this->assertCount(0, $this->tarjetas_del_lead($chico), 'Con 2 empleados no entra.');

        /* No entra: el lead ya está en un estado que no es de los tempranos. */
        foreach (['cerrado_perdido', 'cerrado_ganado', 'prueba_activa'] as $estado) {
            $otro                      = $this->crear_lead_del_tablero($estado);
            $otro->cantidad_empleados = 5;
            $otro->save();
            $this->assertCount(0, $this->tarjetas_del_lead($otro), 'Un lead en «' . $estado . '» no entra por ser un negocio grande.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // Reglas generales
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. Un segundo disparador no crea otra tarjeta: el lead ya tiene una abierta.
     *
     * @return void
     */
    public function test_no_duplica(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');

        /* Primer disparador: pide la implementación. */
        $this->assertTrue(LeadFlujoEntradaService::aplicar_implementacion_pedida($lead, 'implementacion_pedida'));
        $this->assertCount(1, $this->tarjetas_del_lead($lead));

        /* Segundo disparador: un negocio grande. */
        $lead = $lead->fresh();
        $lead->cantidad_empleados = 4;
        $lead->save();
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'El negocio grande no crea otra tarjeta si ya hay una abierta.');

        /* Tercer disparador: vuelve a guardarse con el mismo estado. */
        $lead->notes = 'Una nota cualquiera';
        $lead->save();
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'Guardar el lead de nuevo no crea otra tarjeta.');

        /* Y una tarjeta ya movida a mano no se pisa: sigue donde la dejó Juan. */
        $tarjeta = $this->tarjeta_del_lead($lead);
        $this->mover_a_mano($tarjeta, 'esperando_llamada');
        $lead = $lead->fresh();
        $lead->cantidad_empleados = 6;
        $lead->save();
        $this->assertCount(1, $this->tarjetas_del_lead($lead));
        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjeta), 'Lo que ya movió Juan no se vuelve a Interesados por un guardado.');
    }

    /**
     * CA2. Un lead `cerrado_perdido` no entra al tablero, ni por el estado ni por ser un negocio grande; y si ya tenía
     * tarjeta, que el lead pase a `cerrado_perdido` no la toca.
     *
     * @return void
     */
    public function test_cerrado_no_entra(): void
    {
        $perdido = $this->crear_lead_del_tablero('cerrado_perdido');
        $perdido->cantidad_empleados = 5;
        $perdido->save();
        $this->assertCount(0, $this->tarjetas_del_lead($perdido), 'Un lead cerrado perdido no entra.');

        /* Con tarjeta previa: pasar a cerrado_perdido NO mueve la tarjeta (eso lo hace el funnel en la otra dirección). */
        $lead    = $this->crear_lead_del_tablero('link_enviado');
        $tarjeta = $this->dar_de_alta_en($lead, 'en_prueba_sola');
        $lead->status = 'cerrado_perdido';
        $lead->save();

        $this->assertSame('en_prueba_sola', $this->clave_de_la_columna($tarjeta), 'La tarjeta queda donde estaba.');
        $this->assertNull($tarjeta->fresh()->closed_at, 'Y sigue abierta.');
    }

    /**
     * CA2. Cuando el lead pasa a `cerrado_ganado` (contrató o se lo promovió), su tarjeta abierta va a «Cliente».
     *
     * @return void
     */
    public function test_ganado_va_a_cliente(): void
    {
        $lead    = $this->crear_lead_del_tablero('prueba_activa');
        $tarjeta = $this->dar_de_alta_en($lead, 'en_prueba_sola');

        /* Es lo que hace `PromoteLeadToClientService::contratar_prueba`. */
        $lead->update(['status' => 'cerrado_ganado']);

        $this->assertSame('cerrado', $this->clave_de_la_columna($tarjeta), 'La tarjeta pasa a la columna «Cliente».');
        $this->assertNotNull($tarjeta->fresh()->closed_at, 'Una tarjeta ganada queda cerrada.');
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'No se crea otra tarjeta.');
    }

    /**
     * Mueve una tarjeta a otra columna con el mismo servicio del CRM (como lo haría Juan arrastrándola).
     *
     * @param \App\Models\PipelineOpportunity $tarjeta
     * @param string                          $clave   `stage_key` destino.
     *
     * @return void
     */
    private function mover_a_mano($tarjeta, string $clave): void
    {
        app(\App\Services\Pipelines\PipelineOpportunityService::class)->mover(
            $tarjeta,
            ['stage_id' => (int) $this->etapa_por_clave($clave)->id],
            (int) $this->juan->id
        );
    }
}
