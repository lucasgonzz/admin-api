<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * La re-evaluación de un compromiso cuando cambió el contexto: criterio CA14 de la etapa E2 de la misión
 * leads-referidos-compromisos. Es lo que distingue a esto de «una automatización barata» (el pedido de Lucas).
 *
 * A la hora de un compromiso el motor mira si algo cambió desde que se creó (la «huella»): un mensaje del lead, un mensaje
 * nuestro ya enviado, una llamada registrada o un cambio de estado del lead. Los eventos de estado del hilo y las
 * sugerencias sin enviar NO son cambios. Si no cambió nada, ejecuta lo acordado (otros tests). Si cambió algo, le pasa el
 * contexto completo a la IA, que decide con UNA llamada y un JSON `{decision, vence_at?, accion?, motivo}`:
 *  - `reprogramar`: el compromiso viejo queda `reprogramado` y nace uno nuevo `vigente` (`origen = reevaluacion`) para la
 *    fecha que dijo la IA, con huella nueva (así no se vuelve a re-evaluar solo);
 *  - `cancelar`: queda `cancelado` con el motivo;
 *  - `ejecutar`: se ejecuta como si no hubiera cambios;
 *  - cualquier otra cosa (JSON roto, decisión desconocida, sin fecha, fecha pasada o a más de 90 días, HTTP 500): el
 *    compromiso pasa a `revisar` y Juan recibe una tarea «Revisá a …». NUNCA un mensaje al lead desde una falla.
 * La salida de la IA se trata como no confiable: nada de lo que devuelva puede mandar un mensaje a un lead que no recibe
 * mensajes.
 *
 * Fechas: calendario real de 2026. El compromiso se crea el jueves 26/11 para el viernes 27/11 a las 10:00; el jueves por la
 * tarde el lead escribe pidiendo otra fecha.
 *
 * Hoy están EN ROJO: no existen la tabla `lead_compromisos` ni el comando `leads:compromisos`.
 */
class ReevaluacionDeCompromisosTest extends BaseDeReferidosYCompromisos
{
    /** El viernes a las 10:00. */
    private const VIERNES = '2026-11-27 10:00';

    /** Un segundo después de las 10:00 del viernes. */
    private const VIERNES_YA = '2026-11-27 10:00:30';

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

    /**
     * Arma un compromiso para el viernes a las 10:00 y, después, un cambio en el hilo: el jueves a las 17:00 el lead escribe
     * pidiendo otra fecha. El reloj queda en el jueves a las 17:00.
     *
     * @param string               $accion    `llamar` o `escribir`.
     * @param array<string, mixed> $atributos Pisan los datos del lead.
     *
     * @return array{0: Lead, 1: object} Lead y la fila del compromiso.
     */
    private function armar_con_un_mensaje_nuevo(string $accion = 'escribir', array $atributos = []): array
    {
        list($lead, $fila) = $this->armar_sin_cambios($accion, $atributos);
        $this->ir_a('2026-11-26 17:00:00');
        $this->entrante_a_las($lead, 'Che, mejor escribime la semana que viene, el jueves.', '2026-11-26 17:00:00');

        return [$lead->fresh(), $fila];
    }

    /**
     * Arma un compromiso para el viernes a las 10:00 sin ningún cambio posterior. El reloj queda en el jueves a las 12:00.
     *
     * @param string               $accion    `llamar` o `escribir`.
     * @param array<string, mixed> $atributos Pisan los datos del lead.
     *
     * @return array{0: Lead, 1: object}
     */
    private function armar_sin_cambios(string $accion = 'escribir', array $atributos = []): array
    {
        $this->ir_a('2026-11-26 12:00:00');
        $lead = $this->lead_de_ro(array_merge(['phone' => '+54935112' . random_int(10000, 99999)], $atributos));
        $this->entrante_a_las($lead, 'Mirá, lo hablo con mi socio y mañana te escribo yo.', '2026-11-26 11:30:00');
        $fila = $this->crear_compromiso_por_el_panel($lead, [
            'vence_at' => self::VIERNES, 'accion' => $accion, 'resumen' => 'Retomar lo del local y la carga doble.', 'cita_del_lead' => 'mañana te escribo yo',
        ]);

        return [$lead->fresh(), $fila];
    }

    /**
     * Mueve el reloj y corre el comando una vez.
     *
     * @param string $momento
     *
     * @return void
     */
    private function llegar_la_hora(string $momento = self::VIERNES_YA): void
    {
        $this->ir_a($momento);
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
    }

    // ------------------------------------------------------------------------------------------
    // Las tres decisiones
    // ------------------------------------------------------------------------------------------

    /**
     * CA14. Hubo un mensaje del lead después de la huella y la IA decide `reprogramar` al jueves 3/12 a las 15:00: el viejo
     * queda `reprogramado` (apuntando al nuevo), el nuevo es el único `vigente`, de origen `reevaluacion`, con esa hora y
     * esa acción. El viernes no sale nada. La IA recibió el contexto completo (el mensaje nuevo y lo que se había
     * acordado). Y el nuevo no se re-evalúa solo: el jueves 3/12 se ejecuta con la IA sólo para redactar.
     *
     * @return void
     */
    public function test_reprogramar(): void
    {
        list($lead, $fila) = $this->armar_con_un_mensaje_nuevo('escribir');
        $this->fakear_la_ia_de_compromisos([[
            'decision' => 'reprogramar', 'vence_at' => '2026-12-03 15:00', 'accion' => 'escribir', 'motivo' => 'Pidió que le escriban el jueves de la semana que viene.',
        ]]);

        $this->llegar_la_hora();

        $viejo = $this->compromiso((int) $fila->id);
        $this->assertSame('reprogramado', $viejo->status);
        $vigentes = $this->compromisos_de($lead, 'vigente');
        $this->assertCount(1, $vigentes, 'Hay un solo vigente.');
        $nuevo = $vigentes->first();
        $this->assertSame('reevaluacion', $nuevo->origen);
        $this->assertSame('2026-12-03 15:00:00', $this->fecha_ar($nuevo->vence_at));
        $this->assertSame('escribir', $nuevo->accion);
        $this->assertSame((int) $nuevo->id, (int) $viejo->reemplazado_por_id);
        $this->assertSame(1, $this->llamadas_a_la_ia(), 'Una sola llamada a la IA.');
        $this->assertStringContainsString('mejor escribime la semana que viene, el jueves', $this->pedidos_a_la_ia[0], 'La IA ve lo que cambió.');
        $this->assertStringContainsString('mañana te escribo yo', $this->pedidos_a_la_ia[0], 'Y lo que se había acordado.');
        $this->assertCount(0, $this->mensajes_del_motor($lead), 'El viernes no sale nada.');
        $this->assertCount(0, $this->tareas_del_motor($lead));
        $this->assertSame([], $this->envios->textos);

        /* El jueves 3/12 sale el mensaje: la huella del nuevo ya incluye el mensaje del lead, así que no se re-evalúa. */
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);
        $this->llegar_la_hora('2026-12-03 15:00:30');
        $this->assertSame(1, $this->llamadas_a_la_ia(), 'Sólo para redactar el mensaje: no hay una segunda re-evaluación.');
        $this->assertCount(1, $this->mensajes_del_motor($lead), 'El jueves sale la sugerencia.');
        $this->assertSame('ejecutado', $this->compromiso((int) $nuevo->id)->status);
    }

    /**
     * CA14. La IA decide `cancelar` (el lead dijo que ya no quiere): queda `cancelado` con el motivo y no hay vigente, ni
     * tarea, ni mensaje.
     *
     * @return void
     */
    public function test_cancelar(): void
    {
        list($lead, $fila) = $this->armar_con_un_mensaje_nuevo('escribir');
        $this->fakear_la_ia_de_compromisos([['decision' => 'cancelar', 'motivo' => 'El lead avisó que ya resolvió con otro sistema.']]);

        $this->llegar_la_hora();

        $cancelado = $this->compromiso((int) $fila->id);
        $this->assertSame('cancelado', $cancelado->status);
        $this->assertStringContainsString('ya resolvió con otro sistema', (string) $cancelado->resultado, 'El motivo queda guardado.');
        $this->assertNull($this->compromiso_vigente_de($lead));
        $this->assertCount(0, $this->tareas_del_motor($lead));
        $this->assertCount(0, $this->mensajes_del_motor($lead));
        $this->assertSame([], $this->envios->textos);
    }

    /**
     * CA14. La IA decide `ejecutar` (lo que cambió no toca lo acordado): se ejecuta como sin cambios. Un `llamar` deja la
     * tarea para Juan; un `escribir` con la ventana abierta usa una segunda llamada a la IA para redactar y queda como
     * sugerencia.
     *
     * @return void
     */
    public function test_ejecutar(): void
    {
        list($para_llamar, $llamar) = $this->armar_con_un_mensaje_nuevo('llamar');
        $this->fakear_la_ia_de_compromisos([['decision' => 'ejecutar', 'motivo' => 'Sólo agradeció; sigue en pie la llamada.']]);
        $this->llegar_la_hora();

        $this->assertSame('ejecutado', $this->compromiso((int) $llamar->id)->status);
        $this->assertCount(1, $this->tareas_del_motor($para_llamar), 'Un llamar ejecutado deja la tarea.');
        $this->assertSame(1, $this->llamadas_a_la_ia());

        list($para_escribir, $escribir) = $this->armar_con_un_mensaje_nuevo('escribir');
        $this->fakear_la_ia_de_compromisos([
            ['decision' => 'ejecutar', 'motivo' => 'Sigue en pie.'],
            $this->texto_valido_de_martin(),
        ]);
        $this->llegar_la_hora();

        $this->assertSame('ejecutado', $this->compromiso((int) $escribir->id)->status);
        $this->assertSame(2, $this->llamadas_a_la_ia(), 'Una para decidir y otra para redactar.');
        $mensajes = $this->mensajes_del_motor($para_escribir);
        $this->assertCount(1, $mensajes);
        $this->assertSame('sugerido', (string) $mensajes->first()->status);
        $this->assertSame([], $this->envios->textos);
    }

    // ------------------------------------------------------------------------------------------
    // Cuando la IA falla
    // ------------------------------------------------------------------------------------------

    /**
     * CA14. Si la re-evaluación no da una decisión que se pueda creer —JSON roto, decisión desconocida, `reprogramar` sin
     * fecha, fecha pasada, fecha a más de 90 días o la IA caída (HTTP 500)— el compromiso pasa a `revisar` y Juan recibe una
     * tarea «Revisá a Ro…». Al lead no le sale NADA.
     *
     * @return void
     */
    public function test_falla_de_ia_va_a_revisar(): void
    {
        $casos = [
            'JSON roto'                      => ['esto no es un json {decision: reprogramar'],
            'decisión desconocida'           => [['decision' => 'hacer_magia', 'motivo' => 'x']],
            'reprogramar sin fecha'          => [['decision' => 'reprogramar', 'motivo' => 'Quiere otro día.']],
            'reprogramar con fecha pasada'   => [['decision' => 'reprogramar', 'vence_at' => '2026-11-20 10:00', 'accion' => 'escribir', 'motivo' => 'Otro día.']],
            'reprogramar a más de 90 días'   => [['decision' => 'reprogramar', 'vence_at' => '2027-03-15 10:00', 'accion' => 'escribir', 'motivo' => 'Otro día.']],
            'la IA no contesta (HTTP 500)'   => [],
        ];

        foreach ($casos as $nombre => $respuestas) {
            list($lead, $fila) = $this->armar_con_un_mensaje_nuevo('escribir');
            $this->fakear_la_ia_de_compromisos($respuestas);

            $this->llegar_la_hora();

            $this->assertSame('revisar', $this->compromiso((int) $fila->id)->status, $nombre . ': va a revisar.');
            $tareas = $this->tareas_del_motor($lead);
            $this->assertCount(1, $tareas, $nombre . ': una tarea para Juan.');
            $this->assertStringStartsWith('Revisá a Ro', (string) $tareas->first()->title, $nombre);
            $this->assertSame([(int) $this->juan->id], $this->asignados_de($tareas->first()), $nombre);
            $this->assertCount(0, $this->compromisos_de($lead, 'vigente'), $nombre . ': no queda uno nuevo inventado.');
            $this->assertCount(0, $this->mensajes_del_motor($lead), $nombre . ': ningún mensaje.');
            $this->assertSame(0, $this->cantidad_de_salientes($lead), $nombre . ': nada al lead.');
            $this->assertSame([], $this->envios->textos, $nombre);
            $this->assertSame([], $this->envios->plantillas, $nombre);
        }
    }

    /**
     * La IA decide `ejecutar` sobre un lead que NO recibe mensajes: no sale nada ni se sugiere nada, ni con el envío
     * automático prendido. La decisión de la IA no puede saltear esa regla.
     *
     * @return void
     */
    public function test_ejecutar_tampoco_escribe_a_quien_no_recibe_mensajes(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        list($lead, $fila) = $this->armar_con_un_mensaje_nuevo('escribir', ['no_recibe_mensajes_at' => \Carbon\Carbon::parse('2026-11-20 10:00:00', self::$ZONA_DEL_TABLERO)]);
        $this->fakear_la_ia_de_compromisos([['decision' => 'ejecutar', 'motivo' => 'Sigue en pie.'], $this->texto_valido_de_martin()]);

        $this->llegar_la_hora();

        $this->assertSame([], $this->envios->textos, 'No sale nada por WhatsApp.');
        $this->assertSame([], $this->envios->plantillas);
        $this->assertCount(0, $this->mensajes_del_motor($lead), 'Ni una sugerencia.');
        $this->assertNotContains($this->compromiso((int) $fila->id)->status, ['vigente', 'procesando']);
    }

    // ------------------------------------------------------------------------------------------
    // Qué cuenta como un cambio
    // ------------------------------------------------------------------------------------------

    /**
     * Qué es «un cambio» (PLAN §2, `hubo_cambios`): un mensaje del lead, un mensaje nuestro YA ENVIADO, una llamada nueva o
     * un cambio de estado del lead disparan la re-evaluación (la IA se llama una vez). Un evento de estado del hilo o una
     * sugerencia sin enviar NO son cambios: el compromiso se ejecuta directo (tarea de `llamar`) y la IA no se llama.
     *
     * @return void
     */
    public function test_que_cuenta_como_un_cambio(): void
    {
        $con_cambio = [
            'un mensaje del lead' => function (Lead $lead) {
                $this->entrante_a_las($lead, 'Hola, ¿seguimos para mañana?', '2026-11-26 15:00:00');
            },
            'un mensaje nuestro ya enviado' => function (Lead $lead) {
                $this->saliente_a_las($lead, 'Hola Ro, te escribí para confirmarte lo de mañana.', '2026-11-26 15:00:00');
            },
            'una llamada nueva' => function (Lead $lead) {
                $this->insertar_llamada($lead, ['atendio' => 1, 'resultado' => 'sigue_solo', 'notas' => 'Hablamos un rato por otra cosa.']);
            },
            'un cambio de estado del lead' => function (Lead $lead) {
                Lead::query()->whereKey($lead->id)->update(['status' => 'link_enviado']);
            },
        ];
        foreach ($con_cambio as $nombre => $cambio) {
            list($lead, $fila) = $this->armar_sin_cambios('llamar');
            $this->ir_a('2026-11-26 15:00:00');
            $cambio($lead);
            $this->fakear_la_ia_de_compromisos([['decision' => 'cancelar', 'motivo' => 'Prueba.']]);

            $this->llegar_la_hora();

            $this->assertSame(1, $this->llamadas_a_la_ia(), $nombre . ': dispara la re-evaluación.');
            $this->assertSame('cancelado', $this->compromiso((int) $fila->id)->status, $nombre);
        }

        $sin_cambio = [
            'un evento de estado del hilo' => function (Lead $lead) {
                $this->mensaje_de_sistema($lead, 'Pasó a Conversando', 'cambio_de_estado', 'enviado');
                DB::table('lead_messages')->where('lead_id', (int) $lead->id)->where('content', 'Pasó a Conversando')->update(['is_status_event' => 1]);
            },
            'una sugerencia sin enviar' => function (Lead $lead) {
                $this->mensaje_de_sistema($lead, 'Hola Ro, ¿cómo va todo?', null, 'sugerido');
            },
        ];
        foreach ($sin_cambio as $nombre => $novedad) {
            list($lead, $fila) = $this->armar_sin_cambios('llamar');
            $this->ir_a('2026-11-26 15:00:00');
            $novedad($lead);
            $this->fakear_la_ia_de_compromisos([]);

            $this->llegar_la_hora();

            $this->assertSame(0, $this->llamadas_a_la_ia(), $nombre . ': no es un cambio.');
            $this->assertSame('ejecutado', $this->compromiso((int) $fila->id)->status, $nombre);
            $this->assertCount(1, $this->tareas_del_motor($lead), $nombre . ': se ejecuta directo.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // El ejemplo de Lucas
    // ------------------------------------------------------------------------------------------

    /**
     * El ejemplo de Lucas, de punta a punta: llamada el martes y «llamame el viernes». El miércoles el lead escribe «mejor
     * llamame el lunes a las 11». El viernes a las 10:00 el motor ve que cambió el contexto, la IA lo pasa al lunes y NO se
     * le asigna ninguna llamada a Juan; el lunes 30/11 a las 11:00 sí (sin volver a consultar a la IA).
     *
     * @return void
     */
    public function test_ejemplo_del_lunes(): void
    {
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, ¿me pueden llamar?', '2026-11-24 11:00:00');
        $this->registrar_llamada_por_el_panel($lead, [
            'atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => self::VIERNES, 'notas' => 'Pidió que lo llame el viernes.',
        ])->assertStatus(201);
        $viernes = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($viernes, 'Precondición: el martes quedó el compromiso del viernes.');

        $this->ir_a('2026-11-25 16:00:00');
        $this->entrante_a_las($lead, 'Mirá, mejor llamame el lunes a las 11, el viernes no llego.', '2026-11-25 16:00:00');
        $this->fakear_la_ia_de_compromisos([[
            'decision' => 'reprogramar', 'vence_at' => '2026-11-30 11:00', 'accion' => 'llamar', 'motivo' => 'Pidió que lo llamen el lunes a las 11.',
        ]]);

        $this->llegar_la_hora();

        $this->assertCount(0, $this->tareas_del_motor($lead), 'El viernes no se le asigna ninguna llamada a Juan.');
        $this->assertSame('reprogramado', $this->compromiso((int) $viernes->id)->status);
        $lunes = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($lunes);
        $this->assertSame('2026-11-30 11:00:00', $this->fecha_ar($lunes->vence_at));

        $this->llegar_la_hora('2026-11-30 11:00:30');

        $tareas = $this->tareas_del_motor($lead);
        $this->assertCount(1, $tareas, 'El lunes sí.');
        $this->assertSame('Llamar a Ro (Ferretería de Ro): quedaron para hoy', (string) $tareas->first()->title);
        $this->assertSame(1, $this->llamadas_a_la_ia(), 'La IA se consultó una sola vez: el lunes ya no hay cambios.');
        $this->assertSame('ejecutado', $this->compromiso((int) $lunes->id)->status);
    }
}
