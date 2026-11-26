<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\TranscribirGrabacionDeLlamadaJob;
use App\Models\Lead;
use App\Models\PipelineActivity;
use App\Services\LeadLlamadaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * La transcripción de la grabación de la llamada de Juan, su resumen y el compromiso que sale de ella: criterios CA20 y CA21 de la etapa
 * E3 de la misión leads-referidos-compromisos.
 *
 * Cuando la grabación queda `pendiente`, `TranscribirGrabacionDeLlamadaJob` la transcribe con OpenAI:
 *  - con ffmpeg la parte en tramos (cortador) y manda cada uno; une los textos con un salto de párrafo y guarda la transcripción COMPLETA
 *    (una llamada de 40 minutos pasa los 20.000 caracteres que tiene la columna de `lead_llamadas`);
 *  - sin ffmpeg manda el archivo entero si es de formato nativo de OpenAI y pesa hasta 24 MB; si no, queda en `error` con un texto claro;
 *  - un tramo que falla se reintenta una vez; si vuelve a fallar, `error` («Falló el tramo 2 de 3»); sin clave, `error`;
 *  - después la IA resume la transcripción y puede sacar un compromiso: con el motor prendido y una llamada SIN fecha nace un compromiso
 *    `origen = transcripcion`; si Juan SÍ puso fecha, gana la de Juan y la propuesta de la IA queda sólo en `compromiso_propuesto`;
 *  - nada sale a WhatsApp desde la transcripción; los datos del audio y de la transcripción no van a los logs;
 *  - `reintentar` vuelve a poner en `pendiente` lo que quedó en `error` (o colgado en `transcribiendo` hace más de 30 minutos).
 *
 * Todo contra OpenAI y la IA simulados (guardián de red de la base) y con el cortador y ffmpeg simulados: no se llama a nadie de verdad.
 * Calendario real de 2026: «hoy» es el lunes 23/11 a las 12:00; los compromisos de la IA caen el martes 1/12.
 *
 * Hoy están EN ROJO: la tabla, el job, los servicios y las rutas de E3 no existen.
 */
class TranscripcionDeGrabacionDeLlamadaTest extends BaseDeGrabacionDeLlamada
{
    /**
     * Lunes 23/11 a las 12:00 con el motor de compromisos prendido y la cola falsa vacía.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->prender_el_motor();
        Queue::fake();
    }

    // ------------------------------------------------------------------------------------------
    // Armado de los casos
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead con una llamada registrada y su grabación ya subida (en `pendiente`, esperando la transcripción).
     *
     * @param array<string, mixed> $opciones `lead`, `llamada` (cuerpo de «Registrar llamada»), `nombre`, `contenido`.
     *
     * @return array{0: Lead, 1: int, 2: object} El lead, el id de la llamada y la fila de la grabación.
     */
    private function grabacion_pendiente(array $opciones = []): array
    {
        $lead     = isset($opciones['lead']) ? $opciones['lead'] : $this->lead_de_ro();
        $llamada  = $this->crear_llamada($lead, isset($opciones['llamada']) ? $opciones['llamada'] : null);
        $fila     = $this->subir_la_grabacion_entera(
            $lead,
            $llamada,
            isset($opciones['contenido']) ? $opciones['contenido'] : $this->contenido_de_audio(3000, 'm'),
            isset($opciones['nombre']) ? $opciones['nombre'] : 'llamada.m4a'
        );
        $this->assertSame('pendiente', $fila->status, 'Precondición: la grabación está lista para transcribir.');

        return [$lead, $llamada, $fila];
    }

    /**
     * Lo que contesta la IA al resumir la transcripción.
     *
     * @param array<string, mixed> $cambios Pisan o suman claves.
     *
     * @return array<string, mixed>
     */
    private function resumen_de_la_ia(array $cambios = []): array
    {
        return array_merge([
            'resumen'     => 'Ro tiene una ferretería con dos sucursales, hoy lleva todo en planillas y quiere probar el sistema esta semana.',
            'datos_clave' => ['Dos sucursales', 'Usa planillas', 'Quiere probar esta semana'],
        ], $cambios);
    }

    /**
     * El compromiso que propone la IA: llamar al lead el martes 1/12 a las 11:30.
     *
     * @param array<string, mixed> $cambios Pisan o suman claves.
     *
     * @return array<string, mixed>
     */
    private function compromiso_de_la_ia(array $cambios = []): array
    {
        return array_merge([
            'fecha' => '2026-12-01', 'hora' => '11:30', 'accion' => 'llamar',
            'cita'  => 'llamame el martes a la mañana', 'momento' => 'el martes a la mañana',
        ], $cambios);
    }

    /**
     * Prepara tres tramos (de 11, 22 y 33 bytes), OpenAI que contesta «uno», «dos» y «tres» y la IA con el resumen dado.
     *
     * @param array<string, mixed> $resumen Lo que contesta la IA al resumir.
     *
     * @return object El cortador simulado.
     */
    private function armar_tres_tramos(array $resumen)
    {
        $cortador = $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22), str_repeat('3', 33)]);
        $this->fakear_openai(['uno', 'dos', 'tres']);
        $this->fakear_la_ia_de_compromisos([$resumen]);

        return $cortador;
    }

    /**
     * Corre el job y exige que no haya escapado ninguna excepción.
     *
     * @return void
     */
    private function transcribir_sin_excepciones(): void
    {
        $excepcion = $this->correr_la_transcripcion();

        $this->assertNull($excepcion, 'El job no tendría que tirar una excepción: ' . ($excepcion !== null ? get_class($excepcion) . ' ' . $excepcion->getMessage() : ''));
    }

    // ------------------------------------------------------------------------------------------
    // CA20 — el camino feliz
    // ------------------------------------------------------------------------------------------

    /**
     * CA20. Con el cortador (fake de 3 tramos) y OpenAI simulado («uno», «dos», «tres»): `transcripcion = "uno\n\ndos\n\ntres"`, `tramos = 3`,
     * `status = lista` y `modelo` = el de la config. Cada tramo viaja en su pedido, en orden, con la clave, el modelo y el idioma español;
     * el cortador recibió el archivo original.
     *
     * @return void
     */
    public function test_tres_tramos_en_orden(): void
    {
        list($lead, $llamada, $fila) = $this->grabacion_pendiente();
        $cortador = $this->armar_tres_tramos($this->resumen_de_la_ia());

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame("uno\n\ndos\n\ntres", $listo->transcripcion);
        $this->assertSame(3, (int) $listo->tramos);
        $this->assertSame(self::MODELO_DE_LLAMADAS, $listo->modelo);
        $this->assertNotNull($listo->started_at);
        $this->assertNotNull($listo->finished_at);

        $this->assertCount(1, $cortador->llamadas, 'El cortador corta el archivo una sola vez.');
        $this->assertSame(\Illuminate\Support\Facades\Storage::disk('local')->path($fila->ruta), $cortador->llamadas[0]['ruta'], 'Se cortó la grabación que subió Juan.');
        $this->assertSame('m4a', $cortador->llamadas[0]['extension']);

        $this->assertCount(3, $this->pedidos_a_openai);
        $this->assertSame([11, 22, 33], array_column($this->pedidos_a_openai, 'bytes'), 'Cada pedido lleva SU tramo, en orden.');
        foreach ($this->pedidos_a_openai as $pedido) {
            $this->assertTrue($pedido['multipart']);
            $this->assertSame('mp3', pathinfo((string) $pedido['archivo'], PATHINFO_EXTENSION), 'Los tramos viajan como mp3.');
            $this->assertSame(self::MODELO_DE_LLAMADAS, $pedido['campos']['model'], 'El modelo sale de la config.');
            $this->assertSame('es', $pedido['campos']['language']);
            $this->assertSame('Bearer ' . self::CLAVE_DE_OPENAI, $pedido['autorizacion']);
        }
    }

    /**
     * CA20. Resumen y compromiso: la IA resume (UNA llamada que ve la transcripción), el `resumen` se guarda, la tarjeta del lead recibe la
     * nota «Grabación transcripta: <resumen>» (canal `call`) y, como la llamada NO trajo fecha y el motor está prendido, nace un compromiso
     * `origen = transcripcion` con lo que propuso la IA (fecha, hora, acción, cita y el momento en palabras). La grabación queda ligada.
     *
     * @return void
     */
    public function test_resumen_y_compromiso(): void
    {
        list($lead, $llamada, $fila) = $this->grabacion_pendiente();
        $resumen = $this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia()]);
        $this->armar_tres_tramos($resumen);

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame($resumen['resumen'], $listo->resumen);
        $this->assertSame(1, $this->llamadas_a_la_ia(), 'El resumen es UNA sola llamada a la IA.');
        $this->assertMatchesRegularExpression('/uno(\\\\n|\s)+dos(\\\\n|\s)+tres/', (string) $this->pedidos_a_la_ia[0], 'La IA ve la transcripción completa.');

        $tarjeta = $this->tarjetas_del_lead($lead)->first();
        $notas   = PipelineActivity::query()->where('opportunity_id', $tarjeta->id)->where('type', 'note')->where('channel', 'call')
            ->where('body', 'like', '%Grabación transcripta:%')->get();
        $this->assertCount(1, $notas, 'La tarjeta recibe una nota «Grabación transcripta:».');
        $this->assertStringContainsString($resumen['resumen'], (string) $notas[0]->body);

        $vigentes = $this->compromisos_de($lead, 'vigente');
        $this->assertCount(1, $vigentes, 'La llamada no tenía fecha: la transcripción deja el compromiso.');
        $compromiso = $vigentes->first();
        $this->assertSame('transcripcion', $compromiso->origen);
        $this->assertSame('llamar', $compromiso->accion);
        $this->assertSame('2026-12-01 11:30:00', $this->fecha_ar($compromiso->vence_at));
        $this->assertSame('llamame el martes a la mañana', $compromiso->cita_del_lead);
        $this->assertSame('el martes a la mañana', $compromiso->momento, 'El momento en palabras limpio va a su campo.');
        $this->assertSame((int) $compromiso->id, (int) $listo->compromiso_id, 'La grabación queda ligada al compromiso que dejó.');
    }

    /**
     * CA20 (pedido de Lucas, regla de E2). Si Juan SÍ puso fecha en la llamada (viernes 27/11 10:00), gana la de Juan: el compromiso vigente
     * es el de él, intacto; no nace otro `transcripcion` y lo que propuso la IA (martes 1/12) queda en `compromiso_propuesto` para que se vea
     * en la ficha.
     *
     * @return void
     */
    public function test_fecha_de_juan_gana(): void
    {
        list($lead, $llamada, $fila) = $this->grabacion_pendiente(['llamada' => [
            'atendio' => false, 'proxima_llamada_at' => '2026-11-27 10:00', 'notas' => 'No atendió, le dejé un mensaje.',
        ]]);
        $de_juan = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($de_juan, 'Precondición: la llamada con fecha dejó su compromiso.');
        $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia()]));

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);

        $vigentes = $this->compromisos_de($lead, 'vigente');
        $this->assertCount(1, $vigentes, 'Sigue habiendo UN vigente.');
        $this->assertSame((int) $de_juan->id, (int) $vigentes->first()->id, 'Es el de Juan.');
        $this->assertSame('2026-11-27 10:00:00', $this->fecha_ar($vigentes->first()->vence_at), 'Con su fecha, sin tocar.');
        $this->assertCount(1, $this->compromisos_de($lead), 'No nació ningún otro compromiso (ni reemplazado).');
        $this->assertSame(0, DB::table('lead_compromisos')->where('lead_id', (int) $lead->id)->where('origen', 'transcripcion')->count());

        $this->assertNotEmpty($listo->compromiso_propuesto, 'La propuesta de la IA queda a la vista en la grabación.');
        $this->assertMatchesRegularExpression('/(2026-12-01|0?1\/12|martes)/i', (string) $listo->compromiso_propuesto, 'La propuesta dice cuándo.');
        $this->assertNull($listo->compromiso_id, 'No hay compromiso nacido de la transcripción.');
    }

    /**
     * CA20 y regla de E2. El `momento` en palabras va al campo `momento` SÓLO si la IA lo da limpio: uno con cifras («el martes 1/12 a las
     * 10:30») se descarta —queda sin momento, y el compromiso nace igual con su cita y su fecha—.
     *
     * @return void
     */
    public function test_el_momento_sucio_no_va_al_campo(): void
    {
        list($lead_sucio, , $fila_sucia) = $this->grabacion_pendiente();
        $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia(['momento' => 'el martes 1/12 a las 10:30'])]));
        $this->transcribir_sin_excepciones();

        $sucio = $this->compromiso_vigente_de($lead_sucio);
        $this->assertNotNull($sucio, 'El compromiso nace igual.');
        $this->assertNull($sucio->momento, 'Un momento con cifras no sirve para el hueco de la plantilla: queda vacío.');
        $this->assertSame('llamame el martes a la mañana', $sucio->cita_del_lead, 'La cita se conserva.');
        $this->assertSame('2026-12-01 11:30:00', $this->fecha_ar($sucio->vence_at));

        list($lead_limpio, , $fila_limpia) = $this->grabacion_pendiente([
            'lead' => $this->lead_de_ro(['phone' => '+54935115' . random_int(10000, 99999)]), 'contenido' => $this->contenido_de_audio(3000, 'n'),
        ]);
        $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia(['momento' => 'mañana a la tarde'])]));
        $this->transcribir_sin_excepciones();

        $this->assertSame('mañana a la tarde', $this->compromiso_vigente_de($lead_limpio)->momento, 'El momento limpio sí se guarda.');
        $this->assertNotSame((int) $fila_sucia->id, (int) $fila_limpia->id);
    }

    /**
     * CA20 y reglas de E2. Sin hora, la fecha de la IA se completa como siempre: 10:00 para `escribir` y 18:00 para `esperar` (le da el día
     * al lead).
     *
     * @return void
     */
    public function test_la_hora_por_defecto_sigue_las_reglas_de_e2(): void
    {
        $casos = ['escribir' => '2026-12-01 10:00:00', 'esperar' => '2026-12-01 18:00:00'];
        $numero = 0;

        foreach ($casos as $accion => $esperada) {
            $numero++;
            list($lead) = $this->grabacion_pendiente([
                'lead'      => $this->lead_de_ro(['phone' => '+5493511' . $numero . random_int(1000, 9999)]),
                'contenido' => $this->contenido_de_audio(3000, $numero === 1 ? 'p' : 'q'),
            ]);
            $propuesta = $this->compromiso_de_la_ia(['accion' => $accion]);
            unset($propuesta['hora']);
            $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $propuesta]));

            $this->transcribir_sin_excepciones();

            $compromiso = $this->compromiso_vigente_de($lead);
            $this->assertNotNull($compromiso, $accion . ': nace el compromiso.');
            $this->assertSame($accion, $compromiso->accion);
            $this->assertSame($esperada, $this->fecha_ar($compromiso->vence_at), $accion . ': hora por defecto.');
        }
    }

    /**
     * CA20. Una transcripción sin compromiso (la IA no devuelve ninguno): la grabación queda `lista` con su resumen y no nace nada ni queda
     * ninguna propuesta.
     *
     * @return void
     */
    public function test_sin_compromiso_en_la_transcripcion(): void
    {
        list($lead, , $fila) = $this->grabacion_pendiente();
        $this->armar_tres_tramos($this->resumen_de_la_ia());

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status);
        $this->assertNotEmpty($listo->resumen);
        $this->assertCount(0, $this->compromisos_de($lead));
        $this->assertEmpty($listo->compromiso_propuesto);
        $this->assertNull($listo->compromiso_id);
    }

    /**
     * Lo que dice la IA no es confiable: una fecha pasada, a más de 90 días o que ni es fecha no crea compromiso; la transcripción y el
     * resumen igual quedan y el job termina sin error.
     *
     * @return void
     */
    public function test_la_fecha_inservible_de_la_ia_no_crea_compromiso(): void
    {
        $numero = 0;
        foreach (['2026-11-10', '2027-06-01', 'el martes'] as $fecha) {
            $numero++;
            list($lead, , $fila) = $this->grabacion_pendiente([
                'lead'      => $this->lead_de_ro(['phone' => '+5493512' . $numero . random_int(1000, 9999)]),
                'contenido' => $this->contenido_de_audio(3000, (string) $numero),
            ]);
            $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia(['fecha' => $fecha])]));

            $this->transcribir_sin_excepciones();

            $listo = $this->grabacion($fila->id);
            $this->assertSame('lista', $listo->status, '«' . $fecha . '»: la transcripción no se pierde. Error: ' . (string) $listo->error_text);
            $this->assertSame("uno\n\ndos\n\ntres", $listo->transcripcion);
            $this->assertNotEmpty($listo->resumen);
            $this->assertCount(0, $this->compromisos_de($lead), '«' . $fecha . '»: no nace ningún compromiso.');
        }
    }

    /**
     * Con el motor de compromisos apagado la grabación se transcribe y se resume igual, la nota queda, y no nace ningún compromiso.
     *
     * @return void
     */
    public function test_motor_apagado(): void
    {
        list($lead, , $fila) = $this->grabacion_pendiente();
        $this->motor_de_compromisos(false);
        $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia()]));

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertNotEmpty($listo->resumen);
        $this->assertSame(0, DB::table('lead_compromisos')->where('lead_id', (int) $lead->id)->count(), 'Con el motor apagado no nace nada.');
        $this->assertNull($listo->compromiso_id);
    }

    /**
     * Pedido de Lucas. Nada sale a WhatsApp desde la transcripción, ni siquiera con el compromiso de la IA para «escribir» y el envío
     * automático prendido: ni plantillas ni textos, ningún mensaje saliente nuevo en el hilo y ningún pedido de red que no sea OpenAI o la IA.
     *
     * @return void
     */
    public function test_nada_sale_a_whatsapp_desde_la_transcripcion(): void
    {
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, ¿pudiste ver lo que te pedí?', '2026-11-23 11:00:00');
        list($lead, , $fila) = $this->grabacion_pendiente(['lead' => $lead]);
        $this->prender_el_motor(['envio_automatico' => true]);
        $antes = $this->cantidad_de_salientes($lead);
        $this->armar_tres_tramos($this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia(['accion' => 'escribir'])]));

        $this->transcribir_sin_excepciones();

        $this->assertSame('lista', $this->grabacion($fila->id)->status);
        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'), 'Precondición: nació el compromiso de escribir.');
        $this->assertSame([], $this->envios->plantillas, 'Ninguna plantilla de WhatsApp.');
        $this->assertSame([], $this->envios->textos, 'Ningún texto de WhatsApp.');
        $this->assertSame($antes, $this->cantidad_de_salientes($lead), 'El hilo del lead no recibió ningún mensaje saliente.');
        $this->assertCount(0, $this->mensajes_del_motor($lead), 'Tampoco una sugerencia del motor: eso lo hace el comando a la hora acordada.');

        foreach (Http::recorded() as $par) {
            $this->assertContains(
                parse_url($par[0]->url(), PHP_URL_HOST),
                ['api.openai.com', 'api.anthropic.com'],
                'Sólo se habló con OpenAI y con la IA, no con WhatsApp/Meta: ' . $par[0]->url()
            );
        }
    }

    /**
     * Si la IA del resumen se cae, la transcripción NO se pierde: la grabación queda `lista` con el texto completo, sin resumen y sin
     * compromiso (el resumen es un agregado, la transcripción es lo valioso).
     *
     * @return void
     */
    public function test_la_ia_caida_no_pierde_la_transcripcion(): void
    {
        list($lead, , $fila) = $this->grabacion_pendiente();
        $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22), str_repeat('3', 33)]);
        $this->fakear_openai(['uno', 'dos', 'tres']);
        $this->fakear_la_ia_caida();

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame("uno\n\ndos\n\ntres", $listo->transcripcion);
        $this->assertEmpty($listo->resumen);
        $this->assertCount(0, $this->compromisos_de($lead));
    }

    /**
     * El resumen se guarda con un tope de 1.500 caracteres aunque la IA se exceda.
     *
     * @return void
     */
    public function test_el_resumen_tiene_tope(): void
    {
        list(, , $fila) = $this->grabacion_pendiente();
        $this->armar_tres_tramos($this->resumen_de_la_ia(['resumen' => str_repeat('Habló de precios y de sucursales. ', 60)]));

        $this->transcribir_sin_excepciones();

        $largo = mb_strlen((string) $this->grabacion($fila->id)->resumen);
        $this->assertGreaterThan(0, $largo);
        $this->assertLessThanOrEqual(1500, $largo, 'El resumen no pasa de 1.500 caracteres.');
    }

    /**
     * CA20 y pedido de Lucas. Una transcripción de 45.000 caracteres (con tildes y eñes) se guarda ENTERA, sin cortarse en los 20.000 de la
     * columna de la llamada, y se ve completa por el GET (también para Juan). La columna `transcripcion` de `lead_llamadas` no se toca. Y
     * `bloque_para_agente` (lo que lee Martín) usa el resumen, no los 45.000 caracteres.
     *
     * @return void
     */
    public function test_transcripcion_larga_entera(): void
    {
        list($lead, $llamada, $fila) = $this->grabacion_pendiente();
        $molde   = str_repeat('Ñandú pidió precios en pesos, además de cuánto sale la gestión. ', 400);
        $tramo_a = mb_substr($molde, 0, 14997) . 'x';
        $tramo_b = mb_substr($molde, 0, 14997) . 'y';
        $tramo_c = mb_substr($molde, 0, 15000 - mb_strlen('FIN-DE-LA-LLAMADA')) . 'FIN-DE-LA-LLAMADA';
        $esperada = $tramo_a . "\n\n" . $tramo_b . "\n\n" . $tramo_c;
        $this->assertSame(45000, mb_strlen($esperada), 'Precondición: la transcripción de prueba tiene 45.000 caracteres.');

        $resumen = rtrim(mb_substr(str_repeat('Ro quiere probar el sistema y pidió que le expliquen los paquetes. ', 30), 0, 1399)) . '.';
        $this->fakear_el_cortador(['a', 'bb', 'ccc']);
        $this->fakear_openai([$tramo_a, $tramo_b, $tramo_c]);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia(['resumen' => $resumen])]);

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame(45000, mb_strlen((string) $listo->transcripcion), 'La transcripción se guardó entera.');
        $this->assertSame($esperada, $listo->transcripcion);
        $this->assertNull(DB::table('lead_llamadas')->where('id', $llamada)->value('transcripcion'), 'La columna de 20.000 de `lead_llamadas` no se usa para esto.');

        $this->loguear_a($this->juan);
        $estado = $this->getJson($this->url_de_la_grabacion($lead->id, $llamada));
        $estado->assertStatus(200);
        $this->assertSame(45000, mb_strlen((string) $estado->json('grabacion.transcripcion')), 'Juan la ve completa.');

        $bloque = app(LeadLlamadaService::class)->bloque_para_agente($lead->fresh());
        $this->assertStringContainsString($resumen, $bloque, 'Martín lee el resumen de la grabación.');
        $this->assertStringNotContainsString('FIN-DE-LA-LLAMADA', $bloque, 'No le llegan los 45.000 caracteres.');
        $this->assertLessThan(4000, mb_strlen($bloque));
    }

    /**
     * El listado de llamadas muestra el resumen de la grabación una vez transcripta.
     *
     * @return void
     */
    public function test_el_listado_de_llamadas_muestra_el_resumen(): void
    {
        list($lead, $llamada, ) = $this->grabacion_pendiente();
        $resumen = $this->resumen_de_la_ia();
        $this->armar_tres_tramos($resumen);

        $this->transcribir_sin_excepciones();

        $this->loguear_a($this->juan);
        $listado = $this->getJson('/api/admin/lead/' . $lead->id . '/llamadas');
        $listado->assertStatus(200);
        $this->assertSame('lista', $listado->json('models.0.grabacion.status'));
        $this->assertSame($resumen['resumen'], $listado->json('models.0.grabacion.resumen'));
    }

    // ------------------------------------------------------------------------------------------
    // CA21 — los errores
    // ------------------------------------------------------------------------------------------

    /**
     * CA21. Sin ffmpeg y un m4a de 20 MB (formato nativo de OpenAI y menos de 24 MB): UN solo pedido con el archivo entero, sin pasar por el
     * cortador. `tramos = 1` y la transcripción es lo que contestó OpenAI.
     *
     * @return void
     */
    public function test_sin_ffmpeg_archivo_chico(): void
    {
        $this->fakear_ffmpeg(false);
        list($lead, , $fila) = $this->grabacion_pendiente(['contenido' => $this->contenido_de_audio(20 * self::MB, 's'), 'nombre' => 'llamada.m4a']);
        $cortador = $this->fakear_el_cortador(['no tendría que usarse']);
        $this->fakear_openai(['Texto entero de la llamada.']);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia()]);

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame('Texto entero de la llamada.', $listo->transcripcion);
        $this->assertSame(1, (int) $listo->tramos);
        $this->assertCount(1, $this->pedidos_a_openai, 'Un solo pedido a OpenAI.');
        $this->assertSame(20 * self::MB, $this->pedidos_a_openai[0]['bytes'], 'Con el archivo entero.');
        $this->assertSame('m4a', pathinfo((string) $this->pedidos_a_openai[0]['archivo'], PATHINFO_EXTENSION));
        $this->assertCount(0, $cortador->llamadas, 'Sin ffmpeg no se corta nada.');
    }

    /**
     * CA21. Sin ffmpeg y un m4a de 30 MB: no entra en un pedido de OpenAI → `error` con un texto que nombra los «24 MB» y «ffmpeg». No se
     * llama a OpenAI.
     *
     * @return void
     */
    public function test_sin_ffmpeg_archivo_grande_error(): void
    {
        $this->fakear_ffmpeg(false);
        list(, , $fila) = $this->grabacion_pendiente(['contenido' => $this->contenido_de_audio(30 * self::MB, 't')]);
        $this->fakear_openai(['no tendría que pedirse']);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia()]);

        $this->correr_la_transcripcion();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('error', $listo->status);
        $this->assertStringContainsString('24 MB', (string) $listo->error_text);
        $this->assertStringContainsString('ffmpeg', (string) $listo->error_text);
        $this->assertCount(0, $this->pedidos_a_openai, 'No se le manda a OpenAI lo que no entra.');
        $this->assertEmpty($listo->transcripcion);
        $this->assertSame(0, $this->llamadas_a_la_ia());
    }

    /**
     * CA21. Sin ffmpeg y un `.amr` (formato que OpenAI no acepta, aunque pese poco): `error` que nombra a ffmpeg y no se llama a OpenAI.
     *
     * @return void
     */
    public function test_sin_ffmpeg_amr_error(): void
    {
        $this->fakear_ffmpeg(false);
        list(, , $fila) = $this->grabacion_pendiente(['contenido' => $this->contenido_de_audio(self::MB, 'u'), 'nombre' => 'llamada.amr']);
        $this->fakear_openai(['no tendría que pedirse']);

        $this->correr_la_transcripcion();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('error', $listo->status);
        $this->assertStringContainsString('ffmpeg', (string) $listo->error_text);
        $this->assertCount(0, $this->pedidos_a_openai);
    }

    /**
     * CA21. OpenAI contesta 500 en el tramo 2 dos veces (el pedido y su único reintento): `error` «Falló el tramo 2 de 3». El tramo 2 se
     * mandó dos veces (mismo contenido), el 3 nunca, y no hay resumen ni compromiso ni transcripción a medias.
     *
     * @return void
     */
    public function test_tramo_falla_dos_veces(): void
    {
        list($lead, , $fila) = $this->grabacion_pendiente();
        $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22), str_repeat('3', 33)]);
        $this->fakear_openai(['uno', 500, 500, 'tres']);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia(['compromiso' => $this->compromiso_de_la_ia()])]);

        $this->correr_la_transcripcion();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('error', $listo->status);
        $this->assertStringContainsString('Falló el tramo 2 de 3', (string) $listo->error_text);
        $this->assertSame([11, 22, 22], array_column($this->pedidos_a_openai, 'bytes'), 'Tramo 1, tramo 2 y el reintento del tramo 2; el 3 no se manda.');
        $this->assertEmpty($listo->transcripcion, 'Nada de transcripción a medias.');
        $this->assertSame(0, $this->llamadas_a_la_ia(), 'Sin transcripción no hay resumen.');
        $this->assertCount(0, $this->compromisos_de($lead));
    }

    /**
     * CA21. Un tramo que falla UNA vez se reintenta y sigue: la transcripción sale completa, en orden, con 4 pedidos.
     *
     * @return void
     */
    public function test_un_tramo_que_falla_una_vez_se_reintenta(): void
    {
        list(, , $fila) = $this->grabacion_pendiente();
        $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22), str_repeat('3', 33)]);
        $this->fakear_openai(['uno', 500, 'dos', 'tres']);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia()]);

        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame("uno\n\ndos\n\ntres", $listo->transcripcion);
        $this->assertSame([11, 22, 22, 33], array_column($this->pedidos_a_openai, 'bytes'));
    }

    /**
     * CA21. Sin clave de OpenAI (`OPENAI_TRANSCRIPTION_API_KEY` vacía): `error` con ese nombre en el texto y ningún pedido.
     *
     * @return void
     */
    public function test_sin_clave(): void
    {
        list(, , $fila) = $this->grabacion_pendiente();
        config(['services.openai_transcription.api_key' => '']);
        $this->fakear_el_cortador([str_repeat('1', 11)]);
        $this->fakear_openai(['no tendría que pedirse']);

        $this->correr_la_transcripcion();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('error', $listo->status);
        $this->assertStringContainsString('OPENAI_TRANSCRIPTION_API_KEY', (string) $listo->error_text);
        $this->assertCount(0, $this->pedidos_a_openai);
    }

    /**
     * CA21. `reintentar`: sólo desde `error`, o desde `transcribiendo` si lleva más de 30 minutos (el worker se cayó). Desde
     * `transcribiendo` hace 10 minutos (está andando), desde `pendiente` (ya está en la cola) o desde `lista` → 422 y no se encola nada.
     * Hace 31 minutos → vuelve a `pendiente` y se encola. Desde `error` → `pendiente` y se encola.
     *
     * @return void
     */
    public function test_reintentar(): void
    {
        list($lead, $llamada, $fila) = $this->grabacion_pendiente();
        $url = $this->url_de_la_grabacion($lead->id, $llamada, '/reintentar');
        $this->loguear_a($this->admin);
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 1);

        foreach (['lista', 'pendiente'] as $status) {
            $this->poner_la_grabacion_en((int) $fila->id, $status);
            $this->postJson($url)->assertStatus(422);
            $this->assertSame($status, $this->grabacion($fila->id)->status, 'Desde «' . $status . '» no se reintenta.');
        }

        $this->poner_la_grabacion_en((int) $fila->id, 'transcribiendo', 10);
        $this->postJson($url)->assertStatus(422);
        $this->assertSame('transcribiendo', $this->grabacion($fila->id)->status, 'Hace 10 minutos está trabajando: no se toca.');
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 1);

        $this->poner_la_grabacion_en((int) $fila->id, 'transcribiendo', 31);
        $colgada = $this->postJson($url);
        $colgada->assertStatus(200);
        $this->assertSame('pendiente', $colgada->json('grabacion.status'));
        $this->assertSame('pendiente', $this->grabacion($fila->id)->status, 'Hace 31 minutos se dio por colgada.');
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 2);

        $this->poner_la_grabacion_en((int) $fila->id, 'error');
        $this->postJson($url)->assertStatus(200);
        $this->assertSame('pendiente', $this->grabacion($fila->id)->status);
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 3);
        Queue::assertPushedOn('transcripciones', TranscribirGrabacionDeLlamadaJob::class);
    }

    /**
     * CA21, secuencia completa. Falla por falta de clave (`error`), se carga la clave, «Reintentar» y el job nuevo la transcribe: termina
     * `lista` con su texto y su resumen.
     *
     * @return void
     */
    public function test_reintentar_despues_de_un_error_termina_bien(): void
    {
        list($lead, $llamada, $fila) = $this->grabacion_pendiente();
        config(['services.openai_transcription.api_key' => '']);
        $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22), str_repeat('3', 33)]);
        $this->fakear_openai(['uno', 'dos', 'tres']);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia()]);
        $this->correr_la_transcripcion();
        $this->assertSame('error', $this->grabacion($fila->id)->status, 'Precondición: falló por la clave.');

        config(['services.openai_transcription.api_key' => self::CLAVE_DE_OPENAI]);
        $this->loguear_a($this->juan);
        $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/reintentar'))->assertStatus(200);
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 2);
        $this->transcribir_sin_excepciones();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, 'Error: ' . (string) $listo->error_text);
        $this->assertSame("uno\n\ndos\n\ntres", $listo->transcripcion);
        $this->assertNotEmpty($listo->resumen);
    }

    /**
     * El job trabaja en la cola `transcripciones`, tiene 25 minutos de tiempo (una llamada de 40 minutos son varios tramos) y un solo
     * intento. Si el worker lo mata o explota, `failed()` deja la grabación en `error` (no queda colgada en `transcribiendo`).
     *
     * @return void
     */
    public function test_el_job_tiene_tiempo_de_sobra_y_si_falla_deja_error(): void
    {
        list(, , $fila) = $this->grabacion_pendiente();

        $trabajo = Queue::pushed(TranscribirGrabacionDeLlamadaJob::class)->first();
        $this->assertNotNull($trabajo);
        $this->assertSame(1500, $trabajo->timeout);
        $this->assertSame(1, $trabajo->tries);
        $this->assertSame('transcripciones', $trabajo->queue);

        $this->poner_la_grabacion_en((int) $fila->id, 'transcribiendo', 1);
        $trabajo->failed(new \RuntimeException('El worker se cayó.'));

        $this->assertSame('error', $this->grabacion($fila->id)->status);
        $this->assertNotEmpty($this->grabacion($fila->id)->error_text);
    }

    // ------------------------------------------------------------------------------------------
    // Datos personales
    // ------------------------------------------------------------------------------------------

    /**
     * Pedido de Lucas. El audio y la transcripción son datos personales y no van a los logs: ni lo que dice la persona, ni el resumen, ni el
     * nombre del archivo ni el teléfono del lead, ni en la transcripción que sale bien ni en la que falla.
     *
     * @return void
     */
    public function test_los_datos_personales_no_van_a_los_logs(): void
    {
        $dicho   = 'Hola, soy Rosario Benítez, mi DNI es 30.111.222 y mi teléfono es 3515550199.';
        $resumen = 'Rosario Benítez, con DNI 30.111.222, quiere probar el sistema el martes.';

        list(, , $fila_bien) = $this->grabacion_pendiente([
            'lead'   => $this->lead_de_ro(['phone' => '+5493515550199']),
            'nombre' => 'Llamada con Rosario Benítez 3515550199.m4a',
        ]);
        $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22)]);
        $this->fakear_openai([$dicho, 'Y además pidió factura A para el negocio de Rosario.']);
        $this->fakear_la_ia_de_compromisos([$this->resumen_de_la_ia(['resumen' => $resumen, 'compromiso' => $this->compromiso_de_la_ia()])]);
        $this->transcribir_sin_excepciones();
        $this->assertSame('lista', $this->grabacion($fila_bien->id)->status, 'Precondición: la primera salió bien.');

        list(, , $fila_mal) = $this->grabacion_pendiente([
            'lead'      => $this->lead_de_ro(['phone' => '+5493515550198']),
            'nombre'    => 'Otra llamada de Rosario Benítez.m4a',
            'contenido' => $this->contenido_de_audio(3000, 'z'),
        ]);
        $this->fakear_el_cortador([str_repeat('1', 11), str_repeat('2', 22)]);
        $this->fakear_openai([$dicho, 500, 500]);
        $this->correr_la_transcripcion();
        $this->assertSame('error', $this->grabacion($fila_mal->id)->status, 'Precondición: la segunda falló.');

        $log = $this->texto_del_log();
        foreach (['Rosario', 'Benítez', '30.111.222', '3515550199', '3515550198', 'factura A', 'quiere probar el sistema'] as $dato) {
            $this->assertStringNotContainsString($dato, $log, 'En los logs no puede aparecer «' . $dato . '».');
        }
    }
}
