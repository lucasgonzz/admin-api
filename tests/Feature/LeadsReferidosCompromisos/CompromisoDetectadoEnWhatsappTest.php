<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\DetectarCompromisoEnMensajeJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;

/**
 * El compromiso que el lead dice por WhatsApp («el martes te escribo»): criterio CA15 de la etapa E2 de la misión
 * leads-referidos-compromisos.
 *
 * Cuando entra un mensaje del lead, el webhook (con el motor y la detección con IA prendidos) despacha
 * `DetectarCompromisoEnMensajeJob` con 90 segundos de demora. El job filtra PRIMERO con una expresión (días de la semana,
 * «mañana», «pasado», «la semana que viene», fechas dd/mm, «te escribo/aviso/llamo», «llamame/escribime», «a la
 * tarde/noche»): sólo si pega llama a la IA (una vez, con la fecha y la hora actuales y los últimos 15 mensajes). Si la IA
 * dice que hay compromiso, se crea (`origen = ia_mensaje`, `origen_id` = el mensaje); un `esperar` sin hora vence a las
 * 18:00 (se le da el día al lead).
 *
 * Lo que se fija además:
 *  - la salida de la IA es no confiable: una fecha pasada, una a más de 90 días, un JSON roto o la IA caída NO crean nada, y
 *    el job nunca tira una excepción (el webhook no puede romperse por esto);
 *  - la huella del compromiso incluye el mensaje que lo originó (si no, se re-evaluaría solo desde el primer minuto);
 *  - con la detección apagada, o el motor apagado, el job ni se despacha.
 *
 * Fechas: calendario real de 2026. El lead escribe el jueves 26/11 a las 12:00 y dice «el martes»: martes 1/12.
 *
 * Hoy están EN ROJO: no existen la tabla `lead_compromisos`, el job ni el despacho desde el webhook.
 */
class CompromisoDetectadoEnWhatsappTest extends BaseDeReferidosYCompromisos
{
    /** Teléfono de Ro (formato de Kapso). */
    private const TELEFONO_DE_RO = '+5493511230045';

    /**
     * Jueves 26/11 a las 12:00, con el motor y la detección prendidos, y a Ro conversando.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ir_a('2026-11-26 12:00:00');
        $this->prender_el_motor(['deteccion' => true]);
    }

    /**
     * Ro, conversando, con un mensaje nuestro anterior.
     *
     * @return Lead
     */
    private function ro_conversando(): Lead
    {
        $lead = $this->lead_de_ro(['phone' => self::TELEFONO_DE_RO]);
        $this->saliente_a_las($lead, 'Hola Ro, ¿pudiste ver cómo funciona?', '2026-11-26 09:00:00');

        return $lead;
    }

    /**
     * Ro escribe por el webhook real y devuelve el mensaje que quedó guardado.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function ro_escribe(Lead $lead, string $texto): LeadMessage
    {
        $this->postear_kapso(self::TELEFONO_DE_RO, $texto)->assertStatus(200);

        $mensaje = LeadMessage::query()->where('lead_id', (int) $lead->id)->where('sender', 'lead')->orderByDesc('id')->first();
        $this->assertNotNull($mensaje, 'El mensaje del lead quedó guardado.');
        $this->assertSame($texto, (string) $mensaje->content);

        return $mensaje;
    }

    /**
     * Corre el último job de detección que quedó en la cola falsa (si hay uno) como lo correría un worker.
     *
     * @return int Cuántos jobs de detección había encolados en total.
     */
    private function correr_la_deteccion(): int
    {
        $jobs = $this->jobs_encolados(DetectarCompromisoEnMensajeJob::class);
        if ($jobs->count() > 0) {
            $this->correr_job($jobs->last());
        }

        return $jobs->count();
    }

    // ------------------------------------------------------------------------------------------
    // El caso feliz
    // ------------------------------------------------------------------------------------------

    /**
     * CA15. «lo miro el finde y el martes te escribo» un jueves: el webhook despacha el job (con 90 segundos de demora), la
     * IA contesta `esperar` para el martes 1/12 y queda el compromiso `esperar`, `origen = ia_mensaje`, `origen_id` = el
     * mensaje, a las 18:00, con la cita del lead. La IA recibió la fecha de hoy y el mensaje. La huella ya incluye el
     * mensaje que lo originó.
     *
     * @return void
     */
    public function test_martes_te_escribo(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'esperar', 'cita' => 'el martes te escribo']]);

        $mensaje = $this->ro_escribe($lead, 'lo miro el finde y el martes te escribo');

        $jobs = $this->jobs_encolados(DetectarCompromisoEnMensajeJob::class);
        $this->assertCount(1, $jobs, 'El webhook despacha la detección.');
        $demora = $jobs->first()->delay;
        $segundos = $demora instanceof \DateTimeInterface ? Carbon::now()->diffInSeconds(Carbon::instance($demora), false) : (int) $demora;
        $this->assertSame(90, $segundos, 'Con 90 segundos de demora (para que lleguen los mensajes que vienen pegados).');
        $this->assertCount(0, $this->compromisos_de($lead), 'Todavía no hay compromiso: lo crea el job.');

        $this->correr_la_deteccion();

        $this->assertSame(1, $this->llamadas_a_la_ia(), 'Una sola llamada a la IA.');
        $this->assertStringContainsString('lo miro el finde y el martes te escribo', $this->pedidos_a_la_ia[0], 'La IA recibe el mensaje.');
        $this->assertTrue(
            strpos($this->pedidos_a_la_ia[0], '2026-11-26') !== false || strpos($this->pedidos_a_la_ia[0], '26/11/2026') !== false,
            'La IA recibe la fecha de hoy (sin ella no sabe cuál es «el martes»).'
        );
        $compromisos = $this->compromisos_de($lead, 'vigente');
        $this->assertCount(1, $compromisos);
        $compromiso = $compromisos->first();
        $this->assertSame('esperar', $compromiso->accion);
        $this->assertSame('ia_mensaje', $compromiso->origen);
        $this->assertSame((int) $mensaje->id, (int) $compromiso->origen_id);
        $this->assertSame('2026-12-01 18:00:00', $this->fecha_ar($compromiso->vence_at), 'Sin hora, el «esperar» vence a las 18:00: se le da el día al lead.');
        $this->assertStringContainsString('el martes te escribo', (string) $compromiso->cita_del_lead);
        $this->assertGreaterThanOrEqual(
            (int) $mensaje->id,
            (int) $compromiso->baseline_lead_message_id,
            'La huella incluye el mensaje que originó el compromiso: si no, se re-evaluaría solo.'
        );
        $this->assertSame('contactado', $compromiso->estado_lead_al_crear);
    }

    /**
     * CA15. Si la IA trae la hora («mañana a las 15:30 te llamo») se respeta: vence a esa hora, y la acción que dijo.
     *
     * @return void
     */
    public function test_con_hora_explicita(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-11-27', 'hora' => '15:30', 'accion' => 'llamar', 'cita' => 'mañana a las 15:30 llamame']]);

        $this->ro_escribe($lead, 'mañana a las 15:30 llamame');
        $this->correr_la_deteccion();

        $compromiso = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($compromiso);
        $this->assertSame('llamar', $compromiso->accion);
        $this->assertSame('2026-11-27 15:30:00', $this->fecha_ar($compromiso->vence_at));
    }

    /**
     * CA15 y regla de «un solo vigente». Un compromiso detectado reemplaza al vigente que hubiera (gana el último, como el
     * lunes de Lucas): el anterior queda `reemplazado`.
     *
     * @return void
     */
    public function test_el_detectado_reemplaza_al_vigente(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $anterior = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00', 'accion' => 'llamar']);
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'esperar', 'cita' => 'el martes te escribo']]);

        $this->ro_escribe($lead, 'mejor el martes te escribo yo');
        $this->correr_la_deteccion();

        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'), 'Sigue habiendo uno solo.');
        $this->assertSame('reemplazado', $this->compromiso((int) $anterior->id)->status);
        $this->assertSame('ia_mensaje', $this->compromiso_vigente_de($lead)->origen);
    }

    // ------------------------------------------------------------------------------------------
    // El filtro previo
    // ------------------------------------------------------------------------------------------

    /**
     * CA15. Un mensaje sin ninguna expresión de tiempo o promesa («dale, gracias») NO llama a la IA ni crea compromiso: el
     * filtro previo ahorra la llamada (y el costo) en casi todos los mensajes.
     *
     * @return void
     */
    public function test_sin_expresion_no_llama_ia(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'esperar', 'cita' => 'x']]);

        foreach (['dale, gracias', 'buenísimo, me interesa', 'ok', '¿cuánto sale por mes?'] as $texto) {
            $this->ro_escribe($lead, $texto);
            $this->correr_la_deteccion();
        }

        $this->assertSame(0, $this->llamadas_a_la_ia(), 'Ninguno de estos mensajes llega a la IA.');
        $this->assertCount(0, $this->compromisos_de($lead));
    }

    /**
     * CA15. El filtro previo deja pasar las expresiones del PLAN: días de la semana, mañana, pasado mañana, la semana que
     * viene, una fecha dd/mm, «te escribo/aviso/llamo», «llamame/escribime» y «a la tarde/noche». Cada una llama a la IA
     * exactamente una vez.
     *
     * @return void
     */
    public function test_el_filtro_deja_pasar_las_expresiones_de_tiempo(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $textos = [
            'el lunes lo vemos', 'te escribo mañana', 'pasado mañana te llamo', 'la semana que viene te confirmo',
            'lo vemos el 3/12', 'después de las fiestas te aviso', 'llamame cuando puedas', 'escribime a la noche', 'hablamos a la tarde',
        ];
        $respuestas = [];
        foreach ($textos as $unused) {
            $respuestas[] = ['hay_compromiso' => false];
        }
        $this->fakear_la_ia_de_compromisos($respuestas);

        foreach ($textos as $indice => $texto) {
            $this->ro_escribe($lead, $texto);
            $this->correr_la_deteccion();
            $this->assertSame($indice + 1, $this->llamadas_a_la_ia(), '«' . $texto . '» tiene que llegar a la IA.');
        }

        $this->assertCount(0, $this->compromisos_de($lead), 'La IA dijo que no en todos.');
    }

    // ------------------------------------------------------------------------------------------
    // La IA no confiable
    // ------------------------------------------------------------------------------------------

    /**
     * CA15. La IA dice que no hay compromiso («el martes voy al médico»): no se crea nada.
     *
     * @return void
     */
    public function test_ia_dice_que_no(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => false]]);

        $this->ro_escribe($lead, 'el martes voy al médico, qué lío');
        $this->correr_la_deteccion();

        $this->assertSame(1, $this->llamadas_a_la_ia(), 'Pegó el filtro: se le preguntó a la IA.');
        $this->assertCount(0, $this->compromisos_de($lead), 'La IA dijo que no.');
    }

    /**
     * La salida de la IA no es confiable: una fecha que ya pasó, una a más de 90 días, un JSON roto, una acción inventada o
     * la IA caída (HTTP 500) NO crean ningún compromiso, y el job no tira una excepción (el webhook no se rompe).
     *
     * @return void
     */
    public function test_una_respuesta_mala_no_crea_nada_y_no_rompe(): void
    {
        $this->exigir_tabla_de_compromisos();
        $casos = [
            'fecha pasada'          => [['hay_compromiso' => true, 'fecha' => '2026-11-20', 'accion' => 'esperar', 'cita' => 'x']],
            'más de 90 días'        => [['hay_compromiso' => true, 'fecha' => '2027-03-15', 'accion' => 'esperar', 'cita' => 'x']],
            'JSON roto'             => ['{hay_compromiso: si, la fecha es el martes'],
            'acción inventada'      => [['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'hacer_magia', 'cita' => 'x']],
            'sin fecha'             => [['hay_compromiso' => true, 'accion' => 'esperar', 'cita' => 'x']],
            'la IA caída (HTTP 500)' => [],
        ];

        foreach ($casos as $nombre => $respuestas) {
            $lead = $this->lead_de_ro(['phone' => '+54935123' . random_int(10000, 99999)]);
            $mensaje = $this->mensaje_del_lead($lead, 'el martes te escribo');
            $this->fakear_la_ia_de_compromisos($respuestas);

            /* Se corre como lo corre un worker (el contenedor resuelve las dependencias de `handle`). */
            $this->correr_job(new DetectarCompromisoEnMensajeJob((int) $lead->id, (int) $mensaje->id));

            $this->assertCount(0, $this->compromisos_de($lead), $nombre . ': no se crea un compromiso.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // Interruptores
    // ------------------------------------------------------------------------------------------

    /**
     * CA15. Con la detección apagada —o con el motor apagado— el webhook no despacha el job y la IA no se llama, aunque el
     * mensaje diga «el martes te escribo». Todo como hoy.
     *
     * @return void
     */
    public function test_deteccion_apagada(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->ro_conversando();
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'esperar', 'cita' => 'x']]);

        $this->prender_el_motor(['deteccion' => false]);
        $this->ro_escribe($lead, 'el martes te escribo');
        $this->assertCount(0, $this->jobs_encolados(DetectarCompromisoEnMensajeJob::class), 'Con la detección apagada no se despacha.');

        $this->prender_el_motor(['deteccion' => true]);
        $this->motor_de_compromisos(false);
        $this->ro_escribe($lead, 'el martes te escribo, de verdad');
        $this->assertCount(0, $this->jobs_encolados(DetectarCompromisoEnMensajeJob::class), 'Con el motor apagado tampoco.');

        $this->assertSame(0, $this->llamadas_a_la_ia());
        $this->assertCount(0, $this->compromisos_de($lead));
    }
}
