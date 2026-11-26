<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * La ejecución de un compromiso a su hora (`leads:compromisos`, el comando que corre cada minuto): criterios CA11, CA12 y
 * CA13 de la etapa E2 de la misión leads-referidos-compromisos.
 *
 * Cuando llega la hora de un compromiso vigente SIN cambios desde que se creó (ninguna novedad en el hilo ni en las
 * llamadas, mismo estado del lead), el motor ejecuta lo acordado:
 *  - `llamar`: una tarea para Juan (`created_via = embudo_compromiso`), idempotente; registrar una llamada la cierra;
 *  - `escribir` y `esperar`: un mensaje de Martín que retoma lo último que dijo el lead. Con la ventana de 24 h abierta lo
 *    redacta la IA (una sola llamada, con el hilo completo); con la ventana cerrada sale la plantilla
 *    `cc_coord_dia_acordado`. Mientras el envío automático esté apagado (default) el mensaje queda como SUGERENCIA
 *    (`sugerido`, con verificación): nada sale a WhatsApp. Prendido, sale. Si no hay un texto válido ni una plantilla
 *    aprobada, NUNCA se manda algo que no se pueda justificar: queda una tarea para Juan.
 *  - Fuera de la franja de 9 a 21 h, el mensaje se corre al próximo inicio de franja.
 *
 * Y las garantías que se aprendieron en E1 y que acá se fijan: un compromiso no se ejecuta dos veces (dos corridas del
 * comando, o una corrida que ya lo tomó); un lead que no recibe mensajes o que ya es cliente NO recibe un mensaje aunque
 * el envío automático esté prendido; con el motor apagado el comando no hace nada.
 *
 * Fechas: calendario real de 2026. Para `llamar`, la llamada es el martes 24/11 y la hora es el viernes 27/11 a las 10:00;
 * para `escribir`, el lead habla el jueves 26/11 y la hora es el viernes 27/11 a las 10:00.
 *
 * Hoy están EN ROJO: no existen la tabla `lead_compromisos`, el comando `leads:compromisos` ni el servicio.
 */
class EjecucionDeCompromisosTest extends BaseDeReferidosYCompromisos
{
    /** El viernes a las 10:00, la hora de los compromisos de estos tests. */
    private const VIERNES = '2026-11-27 10:00';

    /** Un segundo después de las 10:00 del viernes: la hora ya llegó. */
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

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Deja el escenario de un compromiso `escribir`: el jueves 26/11 a las 12:00 el lead habló (a las 11:30 si la ventana va
     * abierta, el martes si va cerrada) y se crea un compromiso `escribir` para el viernes 27/11 a las 10:00. El reloj
     * queda en el jueves.
     *
     * @param bool                 $ventana_abierta Si el último mensaje del lead es de menos de 24 h antes de la hora.
     * @param array<string, mixed> $atributos       Pisan los datos del lead.
     * @param array<string, mixed> $compromiso      Pisan el cuerpo del compromiso.
     *
     * @return array{0: Lead, 1: object} Lead y la fila del compromiso.
     */
    private function armar_un_escribir(bool $ventana_abierta = true, array $atributos = [], array $compromiso = []): array
    {
        $this->ir_a('2026-11-26 12:00:00');
        $lead = $this->lead_de_ro(array_merge(['phone' => '+54935111' . random_int(10000, 99999)], $atributos));
        $this->entrante_a_las(
            $lead,
            'Mirá, lo hablo con mi socio y mañana te escribo yo.',
            $ventana_abierta ? '2026-11-26 11:30:00' : '2026-11-24 11:00:00'
        );

        $fila = $this->crear_compromiso_por_el_panel($lead, array_merge([
            'vence_at' => self::VIERNES, 'accion' => 'escribir', 'resumen' => 'Retomar lo del local y la carga doble.', 'cita_del_lead' => 'mañana te escribo yo',
        ], $compromiso));

        return [$lead->fresh(), $fila];
    }

    /**
     * Mueve el reloj al momento y corre el comando una vez.
     *
     * @param string $momento Por defecto, el viernes a las 10:00:30.
     *
     * @return void
     */
    private function llegar_la_hora(string $momento = self::VIERNES_YA): void
    {
        $this->ir_a($momento);
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
    }

    /**
     * Registra la plantilla de los compromisos (una variable: el nombre) como la deja la sincronización con Meta.
     *
     * @param string $nombre
     * @param string $meta_status `APPROVED`, `PENDING`…
     *
     * @return FollowupTemplate
     */
    private function plantilla_de_compromiso(string $nombre = 'cc_coord_dia_acordado', string $meta_status = 'APPROVED'): FollowupTemplate
    {
        return FollowupTemplate::create([
            'template_name' => $nombre, 'estado' => 'manual_compromiso', 'dia_numero' => 1, 'language_code' => 'es_AR',
            'body_template' => 'Hola {{1}}! Quedamos en que hoy retomábamos lo que hablamos. ¿Te parece que lo vemos ahora?',
            'activa' => $meta_status === 'APPROVED', 'meta_status' => $meta_status,
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // CA11 — llamar
    // ------------------------------------------------------------------------------------------

    /**
     * CA11. Un compromiso `llamar` sin cambios: antes de la hora el comando no hace nada; a la hora deja UNA tarea para
     * Juan (`created_via = embudo_compromiso`, título «Llamar a Ro (Ferretería de Ro): quedaron para hoy», con el resumen,
     * la cita del lead, la última llamada, los últimos mensajes y el link a la conversación) y el compromiso queda
     * `ejecutado` con su `admin_task_id`. Correr el comando de nuevo no crea otra. No se le escribe nada al lead y no se
     * llama a la IA.
     *
     * @return void
     */
    public function test_llamar_crea_tarea_idempotente(): void
    {
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, quiero ver cómo funciona la caja', '2026-11-24 11:00:00');
        $this->insertar_llamada($lead, ['atendio' => 1, 'resultado' => 'sigue_solo', 'notas' => 'Le gustó el módulo de caja, quiere probarlo solo']);
        $fila = $this->crear_compromiso_por_el_panel($lead, [
            'vence_at' => self::VIERNES, 'accion' => 'llamar', 'resumen' => 'Llamarlo para ver cómo le fue con la caja.', 'cita_del_lead' => 'llamame el viernes a la mañana',
        ]);
        $salientes = $this->cantidad_de_salientes($lead);
        $this->fakear_la_ia_de_compromisos([]);

        /* Antes de la hora: nada. */
        $this->llegar_la_hora('2026-11-27 09:59:30');
        $this->assertCount(0, $this->tareas_del_motor($lead), 'Antes de la hora no se hace nada.');
        $this->assertSame('vigente', $this->compromiso((int) $fila->id)->status);

        /* A la hora: una tarea para Juan. */
        $this->llegar_la_hora();
        $tareas = $this->tareas_del_motor($lead);
        $this->assertCount(1, $tareas, 'A la hora queda una tarea.');
        $tarea = $tareas->first();
        $this->assertSame('Llamar a Ro (Ferretería de Ro): quedaron para hoy', (string) $tarea->title);
        $this->assertFalse((bool) $tarea->is_done);
        $this->assertSame([(int) $this->juan->id], $this->asignados_de($tarea), 'La tarea es de Juan.');
        $this->assertStringContainsString('Llamarlo para ver cómo le fue con la caja.', (string) $tarea->content, 'Lleva el resumen.');
        $this->assertStringContainsString('llamame el viernes a la mañana', (string) $tarea->content, 'Lleva lo que dijo el lead.');
        $this->assertStringContainsString('Le gustó el módulo de caja', (string) $tarea->content, 'Lleva la última llamada.');
        $this->assertStringContainsString('Hola, quiero ver cómo funciona la caja', (string) $tarea->content, 'Lleva los últimos mensajes.');
        $this->assertStringContainsString('/leads/' . $lead->id . '/conversacion', (string) $tarea->content, 'Lleva el link a la conversación.');

        $ejecutado = $this->compromiso((int) $fila->id);
        $this->assertSame('ejecutado', $ejecutado->status);
        $this->assertSame((int) $tarea->id, (int) $ejecutado->admin_task_id);
        $this->assertNotNull($ejecutado->ejecutado_at);
        $this->assertSame($salientes, $this->cantidad_de_salientes($lead), 'Llamar no le escribe al lead.');
        $this->assertSame(0, $this->llamadas_a_la_ia(), 'Sin cambios no hace falta la IA.');
        $this->assertSame([], $this->envios->textos);
        $this->assertSame([], $this->envios->plantillas);

        /* Otra corrida del comando: sigue habiendo una sola tarea. */
        $this->llegar_la_hora('2026-11-27 10:01:30');
        $this->llegar_la_hora('2026-11-27 10:02:30');
        $this->assertCount(1, $this->tareas_del_motor($lead), 'El compromiso no se ejecuta dos veces.');
    }

    /**
     * CA11. Un compromiso que nace de «Registrar llamada» no se re-evalúa a sí mismo: la huella incluye la llamada que lo
     * creó, así que el viernes se EJECUTA (tarea) y no se manda a la IA. Es el camino real del ejemplo de Lucas.
     *
     * @return void
     */
    public function test_el_compromiso_de_una_llamada_se_ejecuta_sin_reevaluarse(): void
    {
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, ¿me pueden llamar?', '2026-11-24 11:00:00');
        $this->registrar_llamada_por_el_panel($lead, [
            'atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => self::VIERNES, 'notas' => 'Pidió que lo llame el viernes.',
        ])->assertStatus(201);
        $this->fakear_la_ia_de_compromisos([]);

        $this->llegar_la_hora();

        $this->assertSame(0, $this->llamadas_a_la_ia(), 'La llamada que creó el compromiso no cuenta como un cambio.');
        $this->assertCount(1, $this->tareas_del_motor($lead), 'Se ejecuta: tarea para Juan.');
        $this->assertSame('ejecutado', $this->compromisos_de($lead)->first()->status);
    }

    /**
     * CA11. La tarea del compromiso se cierra sola cuando Juan registra la llamada (la cierra por el prefijo `embudo_` de
     * `created_via`): queda `is_done = true`.
     *
     * @return void
     */
    public function test_tarea_se_cierra_al_registrar_llamada(): void
    {
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola', '2026-11-24 11:00:00');
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        $this->llegar_la_hora();
        $tarea = $this->tareas_del_motor($lead)->first();
        $this->assertNotNull($tarea, 'Precondición: el compromiso dejó su tarea.');
        $this->assertFalse((bool) $tarea->is_done);

        $this->ir_a('2026-11-27 11:00:00');
        $this->registrar_llamada_por_el_panel($lead, ['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Lo llamé: va bien.'])->assertStatus(201);

        $this->assertTrue((bool) AdminTask::query()->findOrFail((int) $tarea->id)->is_done, 'Registrar la llamada cierra la tarea del compromiso.');
    }

    // ------------------------------------------------------------------------------------------
    // Que no se ejecute dos veces
    // ------------------------------------------------------------------------------------------

    /**
     * Una corrida que ya tomó el compromiso (`procesando`, hace un minuto) lo deja en paz: otra corrida del comando no lo
     * ejecuta ni crea una segunda tarea. Es el candado contra corridas dobles.
     *
     * @return void
     */
    public function test_un_compromiso_en_proceso_no_se_toma_dos_veces(): void
    {
        $lead = $this->lead_de_ro();
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        $this->ir_a(self::VIERNES_YA);
        DB::table('lead_compromisos')->where('id', (int) $fila->id)->update(['status' => 'procesando', 'procesando_desde' => '2026-11-27 09:59:30']);

        $this->assertSame(0, $this->correr_el_comando_de_compromisos());

        $this->assertSame('procesando', $this->compromiso((int) $fila->id)->status, 'Lo está ejecutando otra corrida: no se toca.');
        $this->assertCount(0, $this->tareas_del_motor($lead), 'Y no se crea una tarea de más.');
    }

    /**
     * Un compromiso `procesando` hace más de 15 minutos (la corrida murió) vuelve a la cola y se ejecuta UNA vez, contando el
     * intento; al tercer intento fallido deja de reintentarse: pasa a `revisar` con una tarea «Revisá a …» para Juan.
     *
     * @return void
     */
    public function test_un_compromiso_colgado_se_recupera_y_al_tercer_intento_va_a_revisar(): void
    {
        $lead = $this->lead_de_ro();
        $colgado = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        $this->ir_a('2026-11-27 10:30:00');
        DB::table('lead_compromisos')->where('id', (int) $colgado->id)->update(['status' => 'procesando', 'procesando_desde' => '2026-11-27 10:00:00', 'intentos' => 0]);

        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
        $this->ir_a('2026-11-27 10:31:30');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());

        $recuperado = $this->compromiso((int) $colgado->id);
        $this->assertSame('ejecutado', $recuperado->status, 'Se recuperó y se ejecutó.');
        $this->assertSame(1, (int) $recuperado->intentos, 'Se contó el intento.');
        $this->assertCount(1, $this->tareas_del_motor($lead), 'Con una sola tarea.');

        $tercero = $this->lead_de_ro(['phone' => '+5493511110777']);
        $this->ir_a('2026-11-24 12:00:00');
        $trabado = $this->crear_compromiso_por_el_panel($tercero, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        $this->ir_a('2026-11-27 10:30:00');
        DB::table('lead_compromisos')->where('id', (int) $trabado->id)->update(['status' => 'procesando', 'procesando_desde' => '2026-11-27 10:00:00', 'intentos' => 2]);
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());

        $this->assertSame('revisar', $this->compromiso((int) $trabado->id)->status, 'Al tercer intento deja de reintentarse.');
        $tareas = $this->tareas_del_motor($tercero);
        $this->assertCount(1, $tareas);
        $this->assertStringStartsWith('Revisá a Ro', (string) $tareas->first()->title);
        $this->assertSame(0, $this->cantidad_de_salientes($tercero), 'Nunca un mensaje al lead desde una falla.');
    }

    /**
     * El compromiso que fue reemplazado por otro (el lunes de Lucas) NO se ejecuta a su hora: el viernes no pasa nada
     * porque el vigente es el del lunes.
     *
     * @return void
     */
    public function test_el_reemplazado_no_se_ejecuta(): void
    {
        $lead = $this->lead_de_ro();
        $viernes = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        $this->ir_a('2026-11-25 16:00:00');
        $lunes = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-30 11:00', 'accion' => 'llamar']);

        $this->llegar_la_hora();

        $this->assertSame('reemplazado', $this->compromiso((int) $viernes->id)->status);
        $this->assertCount(0, $this->tareas_del_motor($lead), 'El viernes no se ejecuta nada.');

        $this->llegar_la_hora('2026-11-30 11:00:30');
        $this->assertCount(1, $this->tareas_del_motor($lead), 'El lunes sí.');
        $this->assertSame('ejecutado', $this->compromiso((int) $lunes->id)->status);
    }

    /**
     * Con el motor APAGADO el comando no hace nada: un compromiso vencido sigue vigente, sin tarea ni mensaje.
     *
     * @return void
     */
    public function test_con_el_motor_apagado_el_comando_no_hace_nada(): void
    {
        $lead = $this->lead_de_ro();
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        $this->motor_de_compromisos(false);

        $this->llegar_la_hora();

        $this->assertSame('vigente', $this->compromiso((int) $fila->id)->status, 'El motor apagado no ejecuta ni toca nada.');
        $this->assertCount(0, $this->tareas_del_motor($lead));
        $this->assertSame(0, $this->llamadas_a_la_ia());
    }

    // ------------------------------------------------------------------------------------------
    // CA12 — escribir, con el envío automático apagado (sugerencia)
    // ------------------------------------------------------------------------------------------

    /**
     * CA12. `escribir` con la ventana de 24 h abierta y la IA devolviendo un texto válido: queda UN mensaje del sistema
     * `sugerido`, con verificación, `system_message_kind = lead_compromiso` y `ai_reasoning` que empieza con «Compromiso #».
     * Nada sale a WhatsApp. La IA se llamó una sola vez y recibió el hilo completo y la cita del lead.
     *
     * @return void
     */
    public function test_escribir_sugerido_ventana_abierta(): void
    {
        list($lead, $fila) = $this->armar_un_escribir(true);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        $this->llegar_la_hora();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes, 'Queda una sugerencia en el hilo.');
        $mensaje = $mensajes->first();
        $this->assertSame('sistema', (string) $mensaje->sender);
        $this->assertSame('sugerido', (string) $mensaje->status);
        $this->assertTrue((bool) $mensaje->requiere_verificacion, 'Pasa por la aprobación de una persona.');
        $this->assertSame($this->texto_valido_de_martin(), (string) $mensaje->content);
        $this->assertStringStartsWith('Compromiso #' . $fila->id, (string) $mensaje->ai_reasoning);
        $this->assertSame([], $this->envios->textos, 'Nada sale a WhatsApp mientras el envío automático esté apagado.');
        $this->assertSame([], $this->envios->plantillas);

        $this->assertSame(1, $this->llamadas_a_la_ia(), 'Una sola llamada a la IA.');
        $this->assertStringContainsString('lo hablo con mi socio y mañana te escribo yo', $this->pedidos_a_la_ia[0], 'La IA recibe lo último que dijo el lead.');
        $this->assertStringContainsString('Retomar lo del local y la carga doble.', $this->pedidos_a_la_ia[0], 'Y el resumen del compromiso.');

        $ejecutado = $this->compromiso((int) $fila->id);
        $this->assertSame('ejecutado', $ejecutado->status);
        $this->assertSame((int) $mensaje->id, (int) $ejecutado->lead_message_id);
    }

    /**
     * CA12. `escribir` con la ventana CERRADA y la plantilla `cc_coord_dia_acordado` aprobada: queda una sugerencia que es un
     * seguimiento por plantilla (`is_followup` y `followup_template_id` de esa plantilla), sin llamar a la IA y sin
     * mandar nada a WhatsApp.
     *
     * @return void
     */
    public function test_escribir_plantilla_ventana_cerrada(): void
    {
        $plantilla = $this->plantilla_de_compromiso();
        list($lead, $fila) = $this->armar_un_escribir(false);
        $this->fakear_la_ia_de_compromisos([]);

        $this->llegar_la_hora();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes);
        $mensaje = $mensajes->first();
        $this->assertSame('sugerido', (string) $mensaje->status);
        $this->assertTrue((bool) $mensaje->requiere_verificacion);
        $this->assertTrue((bool) $mensaje->is_followup);
        $this->assertSame((int) $plantilla->id, (int) $mensaje->followup_template_id);
        $this->assertStringStartsWith('Compromiso #' . $fila->id, (string) $mensaje->ai_reasoning);
        $this->assertSame(0, $this->llamadas_a_la_ia(), 'Con la ventana cerrada no hace falta redactar nada.');
        $this->assertSame([], $this->envios->textos);
        $this->assertSame([], $this->envios->plantillas, 'La sugerencia no sale sola.');
        $this->assertSame('ejecutado', $this->compromiso((int) $fila->id)->status);
    }

    /**
     * El nombre de la plantilla es configurable (`lead_compromiso_plantilla`): si Lucas pone otra, se usa esa y no la de
     * fábrica.
     *
     * @return void
     */
    public function test_la_plantilla_se_toma_de_la_configuracion(): void
    {
        $propia = $this->plantilla_de_compromiso('cc_mi_dia_acordado');
        AdminSetting::set('lead_compromiso_plantilla', 'cc_mi_dia_acordado');
        list($lead) = $this->armar_un_escribir(false);

        $this->llegar_la_hora();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes);
        $this->assertSame((int) $propia->id, (int) $mensajes->first()->followup_template_id, 'Se usa la plantilla configurada.');
    }

    /**
     * CA12. Cuando no hay con qué escribir algo que se pueda justificar, NO se manda ni se sugiere nada: queda una tarea
     * «Escribile a Ro…» para Juan y el compromiso se da por ejecutado con esa tarea. Los casos: ventana cerrada sin la
     * plantilla aprobada; y ventana abierta con una IA que devuelve un texto con un link, con «2 hs», vacío o de más de 600
     * caracteres. Nunca se llamó a `send_text` ni a `send_template`.
     *
     * @return void
     */
    public function test_sin_plantilla_o_texto_invalido_tarea(): void
    {
        $casos = [
            'ventana cerrada y la plantilla pendiente en Meta' => [false, null],
            'texto con un link'                                 => [true, 'Ro, te dejo el acceso para que arranques hoy: https://comerciocity.com/prueba y me contás cómo te va.'],
            'texto con un plazo en horas'                       => [true, 'Ro, quedamos en que te llamaba en 2 hs, ¿te sirve que lo veamos por acá mientras tanto?'],
            'texto vacío'                                       => [true, ''],
            'texto de más de 600 caracteres'                    => [true, str_repeat('Ro, retomamos lo del local y la carga doble. ', 15)],
        ];
        $this->plantilla_de_compromiso('cc_coord_dia_acordado', 'PENDING');

        foreach ($casos as $nombre => $caso) {
            list($lead, $fila) = $this->armar_un_escribir($caso[0]);
            $this->fakear_la_ia_de_compromisos($caso[1] === null ? [] : [$caso[1]]);

            $this->llegar_la_hora();

            $this->assertCount(0, $this->mensajes_del_motor($lead), $nombre . ': no se sugiere nada.');
            $this->assertSame(0, $this->cantidad_de_salientes($lead), $nombre . ': el hilo no tiene nada nuestro.');
            $tareas = $this->tareas_del_motor($lead);
            $this->assertCount(1, $tareas, $nombre . ': queda una tarea para Juan.');
            $this->assertStringStartsWith('Escribile a Ro', (string) $tareas->first()->title, $nombre);
            $this->assertSame([(int) $this->juan->id], $this->asignados_de($tareas->first()), $nombre);
            $this->assertSame((int) $tareas->first()->id, (int) $this->compromiso((int) $fila->id)->admin_task_id, $nombre);
            $this->assertSame([], $this->envios->textos, $nombre . ': nada por WhatsApp.');
            $this->assertSame([], $this->envios->plantillas, $nombre . ': nada por WhatsApp.');
        }
    }

    /**
     * CA12. Un compromiso `esperar` vencido SIN mensaje del lead se ejecuta igual que `escribir`: se le da el día al lead
     * (vence a las 18:00 del día pactado) y, como no escribió, Martín lo retoma.
     *
     * @return void
     */
    public function test_esperar_vencido(): void
    {
        $this->ir_a('2026-11-26 19:30:00');
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Dale, lo miro con mi socio y el viernes te aviso.', '2026-11-26 19:00:00');
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 18:00', 'accion' => 'esperar', 'resumen' => 'Esperar que el lead avise el viernes.', 'cita_del_lead' => 'el viernes te aviso']);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        $this->llegar_la_hora('2026-11-27 18:00:30');

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes, 'Pasó el día y no avisó: Martín lo retoma.');
        $this->assertSame('sugerido', (string) $mensajes->first()->status);
        $this->assertSame([], $this->envios->textos);
        $this->assertSame('ejecutado', $this->compromiso((int) $fila->id)->status);
    }

    // ------------------------------------------------------------------------------------------
    // CA13 — envío automático
    // ------------------------------------------------------------------------------------------

    /**
     * CA13. Con el envío automático PRENDIDO: con la ventana abierta sale el texto de la IA por `send_text` (una vez) y el
     * mensaje queda `enviado`; con la ventana cerrada y la plantilla aprobada sale por `send_template` (una vez), con el
     * nombre del lead como variable.
     *
     * @return void
     */
    public function test_envio_automatico(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        $this->plantilla_de_compromiso();

        list($abierta, $fila_abierta) = $this->armar_un_escribir(true);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);
        $this->llegar_la_hora();

        $this->assertCount(1, $this->envios->textos, 'Con la ventana abierta sale el texto, una vez.');
        $this->assertSame($this->texto_valido_de_martin(), $this->envios->textos[0]['body']);
        $this->assertSame(preg_replace('/\D/', '', (string) $abierta->phone), preg_replace('/\D/', '', $this->envios->textos[0]['to']));
        $this->assertSame([], $this->envios->plantillas);
        $enviado = $this->mensajes_del_motor($abierta)->first();
        $this->assertNotNull($enviado);
        $this->assertSame('enviado', (string) $enviado->status, 'El mensaje queda enviado.');
        $this->assertNotEmpty((string) $enviado->whatsapp_message_id);
        $this->assertSame('ejecutado', $this->compromiso((int) $fila_abierta->id)->status);

        list($cerrada, $fila_cerrada) = $this->armar_un_escribir(false);
        $this->fakear_la_ia_de_compromisos([]);
        $this->llegar_la_hora();

        $this->assertCount(1, $this->envios->textos, 'El de la ventana abierta no se repite.');
        $this->assertCount(1, $this->envios->plantillas, 'Con la ventana cerrada sale la plantilla, una vez.');
        $this->assertSame('cc_coord_dia_acordado', $this->envios->plantillas[0]['template']);
        $this->assertSame(['Ro'], $this->envios->plantillas[0]['variables'], 'La variable es el primer nombre del lead.');
        $this->assertSame(preg_replace('/\D/', '', (string) $cerrada->phone), preg_replace('/\D/', '', $this->envios->plantillas[0]['to']));
        $this->assertSame('enviado', (string) $this->mensajes_del_motor($cerrada)->first()->status);
        $this->assertSame('ejecutado', $this->compromiso((int) $fila_cerrada->id)->status);
    }

    /**
     * CA13. Un compromiso que vence a las 22:30 (fuera de la franja de 9 a 21 h) NO sale de noche: el compromiso sigue
     * `vigente` con el `vence_at` corrido a las 9:00 del día siguiente (con el margen de la franja), y sale entonces.
     *
     * @return void
     */
    public function test_fuera_de_franja(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        list($lead, $fila) = $this->armar_un_escribir(true, [], ['vence_at' => '2026-11-26 22:30']);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        $this->llegar_la_hora('2026-11-26 22:30:30');

        $this->assertSame([], $this->envios->textos, 'De noche no sale nada.');
        $this->assertCount(0, $this->mensajes_del_motor($lead));
        $corrido = $this->compromiso((int) $fila->id);
        $this->assertSame('vigente', $corrido->status, 'El compromiso sigue vigente.');
        $nuevo = $this->fecha_ar($corrido->vence_at);
        $this->assertGreaterThanOrEqual('2026-11-27 09:00:00', $nuevo, 'Se corre al inicio de la franja del día siguiente.');
        $this->assertLessThanOrEqual('2026-11-27 09:05:00', $nuevo);

        $this->llegar_la_hora('2026-11-27 09:06:00');

        $this->assertCount(1, $this->envios->textos, 'A primera hora sale.');
        $this->assertSame('ejecutado', $this->compromiso((int) $fila->id)->status);
    }

    // ------------------------------------------------------------------------------------------
    // Quién NO recibe un mensaje (aprendizaje de E1)
    // ------------------------------------------------------------------------------------------

    /**
     * Un compromiso `escribir` que vence sobre un lead que NO recibe mensajes (`no_recibe_mensajes_at`) o que ya es cliente
     * (ganado o promovido a cliente) no genera NINGÚN mensaje, ni siquiera con el envío automático prendido, la ventana
     * abierta y una IA que devuelve un texto perfecto: no sale nada por WhatsApp, no queda una sugerencia y el
     * compromiso se cierra (no queda reintentándose cada minuto).
     *
     * @return void
     */
    public function test_a_quien_no_recibe_o_ya_es_cliente_no_se_le_escribe(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        $cliente = $this->crear_cliente_que_refiere();
        $casos = [
            'no recibe mensajes' => ['no_recibe_mensajes_at' => \Carbon\Carbon::parse('2026-11-20 10:00:00', self::$ZONA_DEL_TABLERO)],
            'cliente ganado'     => ['status' => 'cerrado_ganado'],
            'promovido a cliente' => ['promoted_client_id' => (int) $cliente->id],
        ];

        foreach ($casos as $nombre => $atributos) {
            list($lead, $fila) = $this->armar_un_escribir(true, $atributos);
            $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

            $this->llegar_la_hora();

            $this->assertSame([], $this->envios->textos, $nombre . ': nada por WhatsApp.');
            $this->assertSame([], $this->envios->plantillas, $nombre . ': nada por WhatsApp.');
            $this->assertCount(0, $this->mensajes_del_motor($lead), $nombre . ': ni siquiera una sugerencia.');
            $this->assertNotContains($this->compromiso((int) $fila->id)->status, ['vigente', 'procesando'], $nombre . ': el compromiso se cierra, no queda reintentándose.');
        }
    }
}
