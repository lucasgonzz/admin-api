<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * El compromiso que nace en «Registrar llamada» (`POST admin/lead/{id}/llamadas`): criterio CA9 de la etapa E2 de la
 * misión leads-referidos-compromisos.
 *
 * Es la fuente más importante de compromisos: Juan llama, el lead le dice «llamame el viernes», y Juan lo anota en la
 * fecha de la llamada. Hasta hoy esa fecha (`lead_llamadas.proxima_llamada_at`) no la lee ningún comando. Desde E2, con
 * el motor prendido (`lead_compromisos_activo`):
 *  - una llamada con `proxima_llamada_at` crea UN compromiso `llamar` (`origen = llamada`, `origen_id` = la llamada) con la
 *    huella del momento (último mensaje, última llamada —la que se acaba de guardar, para que ella misma no cuente como un
 *    «cambio»— y estado del lead);
 *  - una segunda llamada con otra fecha REEMPLAZA al vigente (siempre hay como mucho UNO por lead: gana el último, es el
 *    caso del lunes de Lucas);
 *  - una llamada sin fecha (la que dice «sigue solo con la prueba») cierra el vigente como `cumplido` (hubo contacto) y no
 *    crea otro: la fecha automática del día 5 de ese resultado NO es un compromiso, nadie la acordó;
 *  - un audio de Juan reprocesado (idempotente por `origen_clave`) no crea un segundo compromiso;
 *  - con el motor apagado la llamada se registra EXACTAMENTE como hoy y no nace nada.
 *
 * Fechas: calendario real de 2026 (ver `BaseDeReferidosYCompromisos`). «Hoy» es el martes 24/11 a las 12:00 y el lead le
 * pidió a Juan que lo llame el viernes 27/11.
 *
 * Hoy están EN ROJO: la tabla `lead_compromisos` no existe y `LeadLlamadaService::registrar` no crea nada.
 */
class CompromisoDesdeLlamadaTest extends BaseDeReferidosYCompromisos
{
    /** El viernes que acuerda Juan con el lead, a las 10:00. */
    private const VIERNES = '2026-11-27 10:00';

    /** El lunes que reemplaza al viernes (el caso de Lucas), a las 11:00. */
    private const LUNES = '2026-11-30 11:00';

    /**
     * Martes 24/11 a las 12:00, con el motor prendido.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ir_a('2026-11-24 12:00:00');
        $this->prender_el_motor();
    }

    // ------------------------------------------------------------------------------------------
    // Nace el compromiso
    // ------------------------------------------------------------------------------------------

    /**
     * CA9. Juan llama el martes, el lead no atiende y Juan anota «volver a llamar el viernes 10:00»: queda 1 compromiso
     * `vigente` `llamar` de origen `llamada`, con `origen_id` = la llamada, el viernes a las 10:00, a nombre de Juan y con la
     * huella (último mensaje del lead, la llamada recién guardada, estado `contactado`).
     *
     * @return void
     */
    public function test_no_atendio_crea_compromiso(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, ¿me podrían llamar cuando puedan?', '2026-11-24 09:30:00');
        $ultimo = $this->saliente_a_las($lead, 'Dale Ro, te llamo hoy.', '2026-11-24 09:40:00');
        $mensaje_del_lead = DB::table('lead_messages')->where('lead_id', (int) $lead->id)->where('sender', 'lead')->max('id');
        $this->assertGreaterThan(0, (int) $mensaje_del_lead);
        $this->assertGreaterThan(0, (int) $ultimo->id);

        $respuesta = $this->registrar_llamada_por_el_panel($lead, [
            'atendio' => false, 'proxima_llamada_at' => self::VIERNES, 'notas' => 'No atendió, le dejé un mensaje.',
        ]);

        $respuesta->assertStatus(201);
        $llamada_id = (int) $respuesta->json('llamada.id');
        $vigentes   = $this->compromisos_de($lead, 'vigente');
        $this->assertCount(1, $vigentes, 'Una llamada con fecha deja exactamente un compromiso vigente.');

        $compromiso = $vigentes->first();
        $this->assertSame('llamar', $compromiso->accion);
        $this->assertSame('llamada', $compromiso->origen);
        $this->assertSame($llamada_id, (int) $compromiso->origen_id, 'El compromiso apunta a la llamada que lo originó.');
        $this->assertSame('2026-11-27 10:00:00', $this->fecha_ar($compromiso->vence_at));
        $this->assertSame((int) $this->juan->id, (int) $compromiso->created_by_admin_id, 'Lo creó Juan al registrar la llamada.');
        $this->assertSame('contactado', $compromiso->estado_lead_al_crear);
        $this->assertSame((int) $ultimo->id, (int) $compromiso->baseline_lead_message_id, 'La huella toma el último mensaje del hilo.');
        $this->assertSame(
            $llamada_id,
            (int) $compromiso->baseline_llamada_id,
            'La huella incluye la llamada recién guardada: si no, la propia llamada contaría como un «cambio» y el compromiso se re-evaluaría solo.'
        );
        $this->assertSame(1, DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());
    }

    /**
     * CA9. El camino «atendió y quedaron en volver a llamar» (`resultado = volver_a_llamar`, con fecha) también crea el
     * compromiso `llamar`.
     *
     * @return void
     */
    public function test_atendio_y_quedaron_en_volver_a_llamar(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();

        $this->registrar_llamada_por_el_panel($lead, [
            'atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => self::VIERNES, 'notas' => 'Me pidió que lo llame el viernes.',
        ])->assertStatus(201);

        $compromiso = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($compromiso, 'Quedaron en hablar el viernes: hay compromiso.');
        $this->assertSame('llamar', $compromiso->accion);
        $this->assertSame('2026-11-27 10:00:00', $this->fecha_ar($compromiso->vence_at));
    }

    // ------------------------------------------------------------------------------------------
    // Siempre uno solo
    // ------------------------------------------------------------------------------------------

    /**
     * CA9 y ejemplo de Lucas. Una segunda llamada con otra fecha (el lead habló con Juan el miércoles y quedaron para el lunes)
     * REEMPLAZA al vigente: el del viernes queda `reemplazado` con `reemplazado_por_id`, el del lunes es el único `vigente`.
     *
     * @return void
     */
    public function test_segunda_llamada_reemplaza(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();

        $this->registrar_llamada_por_el_panel($lead, ['atendio' => false, 'proxima_llamada_at' => self::VIERNES, 'notas' => 'No atendió.'])->assertStatus(201);
        $viernes = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($viernes);

        $this->ir_a('2026-11-25 16:00:00');
        $this->registrar_llamada_por_el_panel($lead, [
            'atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => self::LUNES, 'notas' => 'Hablamos: prefiere el lunes.',
        ])->assertStatus(201);

        $vigentes = $this->compromisos_de($lead, 'vigente');
        $this->assertCount(1, $vigentes, 'Siempre hay como mucho UN compromiso vigente por lead.');
        $lunes = $vigentes->first();
        $this->assertNotSame((int) $viernes->id, (int) $lunes->id);
        $this->assertSame('2026-11-30 11:00:00', $this->fecha_ar($lunes->vence_at));

        $viejo = $this->compromiso((int) $viernes->id);
        $this->assertSame('reemplazado', $viejo->status, 'El del viernes ya no es el vigente.');
        $this->assertSame((int) $lunes->id, (int) $viejo->reemplazado_por_id);
        $this->assertCount(2, $this->compromisos_de($lead), 'El historial conserva los dos.');
    }

    /**
     * CA9. Una llamada atendida que termina en «sigue solo con la prueba» (sin fecha del pedido) CIERRA el compromiso vigente
     * como `cumplido` —hubo contacto— y no crea otro. Sin vigente previo, tampoco nace nada: la fecha automática del día 5 de
     * ese resultado no es un compromiso.
     *
     * @return void
     */
    public function test_sigue_solo_cumple(): void
    {
        $this->exigir_tabla_de_compromisos();
        $con_vigente = $this->lead_de_ro();
        $this->registrar_llamada_por_el_panel($con_vigente, ['atendio' => false, 'proxima_llamada_at' => self::VIERNES, 'notas' => 'No atendió.'])->assertStatus(201);
        $vigente = $this->compromiso_vigente_de($con_vigente);
        $this->assertNotNull($vigente);

        $this->ir_a('2026-11-25 10:00:00');
        $this->registrar_llamada_por_el_panel($con_vigente, ['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Ya está probando solo.'])->assertStatus(201);

        $this->assertSame('cumplido', $this->compromiso((int) $vigente->id)->status, 'Hubo contacto: el compromiso se cumplió.');
        $this->assertNull($this->compromiso_vigente_de($con_vigente), 'Y no nace otro.');
        $this->assertCount(1, $this->compromisos_de($con_vigente), 'No se creó un compromiso por la fecha automática del día 5.');

        $sin_vigente = $this->lead_de_ro(['phone' => '+5493511110099']);
        $this->registrar_llamada_por_el_panel($sin_vigente, ['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Sigue solo.'])->assertStatus(201);
        $this->assertCount(0, $this->compromisos_de($sin_vigente), 'Sin fecha del pedido y sin vigente, la llamada no crea nada.');
    }

    /**
     * Idempotencia. Un audio de Juan reprocesado (`origen = audio_juan` con la misma `origen_clave`) responde «duplicada» y
     * NO crea un segundo compromiso ni reemplaza al primero.
     *
     * @return void
     */
    public function test_un_audio_repetido_no_crea_otro_compromiso(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead   = $this->lead_de_ro();
        $cuerpo = [
            'atendio' => false, 'proxima_llamada_at' => self::VIERNES, 'notas' => 'Audio de Juan: no atendió.',
            'origen' => 'audio_juan', 'origen_clave' => 'sha256-del-audio-de-ro-0001',
        ];

        $this->registrar_llamada_por_el_panel($lead, $cuerpo)->assertStatus(201);
        $primero = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($primero);

        $this->ir_a('2026-11-24 12:05:00');
        $this->registrar_llamada_por_el_panel($lead, $cuerpo)->assertStatus(200);

        $this->assertCount(1, $this->compromisos_de($lead), 'El mismo audio dos veces deja un solo compromiso.');
        $this->assertSame((int) $primero->id, (int) $this->compromiso_vigente_de($lead)->id, 'Y el vigente sigue siendo el primero.');
        $this->assertSame(1, DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // Motor apagado
    // ------------------------------------------------------------------------------------------

    /**
     * CA9. Con el motor apagado la llamada se registra como hoy (201, fila en `lead_llamadas`, tarjeta en «Volver a llamar»
     * con la fecha dada) y no nace ningún compromiso.
     *
     * Las primeras aserciones son un CONTROL: pasan hoy y tienen que seguir pasando. Lo que falla hoy es que la tabla no
     * existe (la última aserción).
     *
     * @return void
     */
    public function test_motor_apagado(): void
    {
        $this->motor_de_compromisos(false);
        $lead    = $this->lead_de_ro();
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');

        $respuesta = $this->registrar_llamada_por_el_panel($lead, ['atendio' => false, 'proxima_llamada_at' => self::VIERNES, 'notas' => 'No atendió.']);

        $respuesta->assertStatus(201);
        $this->assertSame(1, DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count(), 'Control: la llamada queda registrada como hoy.');
        $this->assertSame('esperando_llamada', $this->clave_de_la_columna($tarjeta), 'Control: la tarjeta va a «Volver a llamar» como hoy.');
        $this->assertSame('2026-11-27 10:00:00', $this->proxima_accion_de($tarjeta), 'Control: la próxima acción de la tarjeta es la fecha dada.');

        $this->assertCount(0, $this->compromisos_de($lead), 'Con el motor apagado no nace ningún compromiso.');
    }
}
