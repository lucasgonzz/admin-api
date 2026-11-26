<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\DetectarCompromisoEnMensajeJob;
use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\DB;

/**
 * Correcciones de la ronda 2 de revisión de E2 (misión leads-referidos-compromisos): lo que las miradas A y C rechazaron y las
 * notas menores de B. Cada test fija un hallazgo:
 *
 *  - C1: con la plantilla REAL `cc_coord_dia_acordado` ({{2}} = el momento, en las palabras del lead) lo que se ve en la sugerencia
 *    y lo que se manda al aprobarla son lo mismo; si no se puede completar de punta a punta, la plantilla no se usa.
 *  - A4: ventana cerrada sin plantilla usable → el borrador de la IA queda como sugerencia, NUNCA sale solo, y además hay tarea a Juan.
 *  - A1/C2: «mañana» y «pasado mañana» pasan el filtro previo a la IA (la ñ).
 *  - A3: un compromiso en `revisar` se cierra cuando hay contacto nuevo o nace otro compromiso.
 *  - Menores: un colgado no deja dos abiertos; una llamada con una fecha que el motor rechaza igual cumple al vigente; el job va por
 *    la cola `database`; un compromiso de un admin «Sólo Leads» nunca sale solo; el freno de «es un cliente»; con el motor apagado
 *    el panel lo avisa (`motor_activo`).
 *
 * Calendario real de 2026. Hoy están EN ROJO.
 */
class RondaDosDeRevisionDeE2Test extends BaseDeReferidosYCompromisos
{
    /** El viernes a las 10:00. */
    private const VIERNES = '2026-11-27 10:00';

    /** Un segundo después de las 10:00 del viernes. */
    private const VIERNES_YA = '2026-11-27 10:00:30';

    /** El cuerpo REAL de `cc_coord_dia_acordado` (K/comercial/plantillas_chequeo_diario.md, A1). */
    private const CUERPO_REAL = 'Hola {{1}}! Te escribo como quedamos: me habías dicho que podías hacer la demo de ComercioCity {{2}}. ¿Sigue en pie? Decime el horario que te queda cómodo y te dejo el acceso listo.';

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
     * Registra `cc_coord_dia_acordado` con su cuerpo real.
     *
     * @param string $meta_status
     * @param string $cuerpo
     *
     * @return FollowupTemplate
     */
    private function plantilla_real(string $meta_status = 'APPROVED', string $cuerpo = self::CUERPO_REAL): FollowupTemplate
    {
        return FollowupTemplate::create([
            'template_name' => 'cc_coord_dia_acordado', 'estado' => 'manual_compromiso', 'dia_numero' => 1, 'language_code' => 'es_AR',
            'body_template' => $cuerpo, 'activa' => $meta_status === 'APPROVED', 'meta_status' => $meta_status,
        ]);
    }

    /**
     * Un `escribir` para el viernes 10:00 con la ventana cerrada (el lead habló el martes) y, si se pide, lo que dijo.
     *
     * @param string               $cita      Lo que dijo el lead ('' = nada).
     * @param array<string, mixed> $atributos Pisan los del lead.
     *
     * @return array{0: Lead, 1: object}
     */
    private function escribir_con_la_ventana_cerrada(string $cita = 'el viernes a la mañana', array $atributos = []): array
    {
        $this->ir_a('2026-11-24 12:00:00');
        $lead = $this->lead_de_ro(array_merge(['phone' => '+54935113' . random_int(10000, 99999)], $atributos));
        $this->entrante_a_las($lead, 'Dale, lo vemos el viernes.', '2026-11-24 11:00:00');
        $fila = $this->crear_compromiso_por_el_panel($lead, [
            'vence_at' => self::VIERNES, 'accion' => 'escribir', 'resumen' => 'Retomar la demo.', 'cita_del_lead' => $cita, 'momento' => $cita,
        ]);

        return [$lead->fresh(), $fila];
    }

    /**
     * Mueve el reloj y corre el comando.
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
    // C1 — la plantilla real
    // ------------------------------------------------------------------------------------------

    /**
     * C1. Con el cuerpo real (dos variables) la sugerencia muestra el texto COMPLETO (sin huecos) y al aprobarla sale por Meta con
     * las mismas variables: {{1}} = el nombre y {{2}} = lo que dijo el lead.
     *
     * @return void
     */
    public function test_la_plantilla_real_se_ve_y_se_manda_igual(): void
    {
        $this->plantilla_real();
        list($lead, $fila) = $this->escribir_con_la_ventana_cerrada('el viernes a la mañana');
        $this->fakear_la_ia_de_compromisos([]);

        $this->llegar_la_hora();

        $mensaje = $this->mensajes_del_motor($lead)->first();
        $this->assertNotNull($mensaje, 'Con la plantilla completable queda la sugerencia.');
        $this->assertStringNotContainsString('{{', (string) $mensaje->content);
        $this->assertStringContainsString('podías hacer la demo de ComercioCity el viernes a la mañana.', (string) $mensaje->content, 'Se ve lo mismo que va a salir.');
        $this->assertSame([], $this->envios->plantillas, 'Todavía no salió nada.');

        $this->loguear_a($this->admin);
        $this->putJson('/api/admin/lead-message/' . $mensaje->id . '/approve')->assertStatus(200);

        $this->assertCount(1, $this->envios->plantillas, 'Al aprobar sale la plantilla.');
        $this->assertSame('cc_coord_dia_acordado', $this->envios->plantillas[0]['template']);
        $this->assertSame(['Ro', 'el viernes a la mañana'], $this->envios->plantillas[0]['variables'], 'Las variables son las de la sugerencia.');
        $this->assertSame('enviado', (string) $mensaje->fresh()->status);
    }

    /**
     * C1. Si la plantilla no se puede completar de punta a punta (falta lo que dijo el lead para {{2}}, el cuerpo está vacío o no
     * está aprobada) NO se usa: nada con `followup_template_id`, nada por Meta y una tarea «Escribile a…» para Juan.
     *
     * @return void
     */
    public function test_una_plantilla_que_no_se_puede_completar_no_se_usa(): void
    {
        $casos = [
            'sin lo que dijo el lead para {{2}}' => ['APPROVED', self::CUERPO_REAL, ''],
            'cuerpo vacío'                       => ['APPROVED', '', 'el viernes'],
            'no aprobada'                        => ['PENDING', self::CUERPO_REAL, 'el viernes'],
        ];

        foreach ($casos as $nombre => $caso) {
            FollowupTemplate::query()->where('template_name', 'cc_coord_dia_acordado')->delete();
            $this->plantilla_real($caso[0], $caso[1]);
            list($lead, $fila) = $this->escribir_con_la_ventana_cerrada($caso[2]);
            $this->fakear_la_ia_de_compromisos([]);

            $this->llegar_la_hora();

            $this->assertSame(0, LeadMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('followup_template_id')->count(), $nombre . ': no hay mensaje por plantilla.');
            $this->assertSame([], $this->envios->plantillas, $nombre);
            $tareas = $this->tareas_del_motor($lead);
            $this->assertCount(1, $tareas, $nombre . ': queda una tarea para Juan.');
            $this->assertStringStartsWith('Escribile a Ro', (string) $tareas->first()->title, $nombre);
        }
    }

    // ------------------------------------------------------------------------------------------
    // A4 — el borrador con la ventana cerrada y sin plantilla
    // ------------------------------------------------------------------------------------------

    /**
     * A4. Ventana cerrada + plantilla no aprobada + IA con un texto válido + envío automático PRENDIDO: el borrador queda como
     * sugerencia, NO sale nada por WhatsApp y además nace la tarea «Escribile a…» para Juan (nadie queda sin contacto).
     *
     * @return void
     */
    public function test_el_borrador_con_la_ventana_cerrada_no_sale_solo_y_hay_tarea(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        $this->plantilla_real('PENDING');
        list($lead, $fila) = $this->escribir_con_la_ventana_cerrada('el viernes');
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        $this->llegar_la_hora();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes, 'El borrador queda como sugerencia.');
        $this->assertSame('sugerido', (string) $mensajes->first()->status);
        $this->assertSame([], $this->envios->textos, 'Nunca sale solo.');
        $this->assertSame([], $this->envios->plantillas);
        $tareas = $this->tareas_del_motor($lead);
        $this->assertCount(1, $tareas, 'Y Juan tiene su tarea.');
        $this->assertStringStartsWith('Escribile a Ro', (string) $tareas->first()->title);
        $this->assertSame('ejecutado', $this->compromiso((int) $fila->id)->status);
    }

    /**
     * A4. Lo mismo con la IA caída: ni borrador ni envío, sólo la tarea.
     *
     * @return void
     */
    public function test_con_la_ia_caida_queda_la_tarea(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        $this->plantilla_real('PENDING');
        list($lead) = $this->escribir_con_la_ventana_cerrada('el viernes');
        $this->fakear_la_ia_caida();

        $this->llegar_la_hora();

        $this->assertCount(0, $this->mensajes_del_motor($lead));
        $this->assertCount(1, $this->tareas_del_motor($lead));
        $this->assertSame([], $this->envios->textos);
        $this->assertSame([], $this->envios->plantillas);
    }

    // ------------------------------------------------------------------------------------------
    // A1 / C2 — la ñ
    // ------------------------------------------------------------------------------------------

    /**
     * A1/C2. «mañana» y «pasado mañana» pasan el filtro previo aunque sean lo único que dice la frase.
     *
     * @return void
     */
    public function test_manana_y_pasado_manana_pasan_el_filtro(): void
    {
        foreach (['hablamos mañana', 'mañana lo veo con mi socio', 'pasado mañana', 'MAÑANA te cuento'] as $texto) {
            $this->assertTrue(DetectarCompromisoEnMensajeJob::el_texto_sugiere_un_compromiso($texto), '«' . $texto . '» tiene que pasar el filtro.');
        }
        $this->assertFalse(DetectarCompromisoEnMensajeJob::el_texto_sugiere_un_compromiso('dale, gracias'));
    }

    /**
     * A1/C2. De punta a punta: «hablamos mañana» llega a la IA (una llamada) y crea el compromiso.
     *
     * @return void
     */
    public function test_hablamos_manana_llega_a_la_ia(): void
    {
        $this->prender_el_motor(['deteccion' => true]);
        $lead    = $this->lead_de_ro();
        $mensaje = $this->mensaje_del_lead($lead, 'hablamos mañana');
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-11-25', 'accion' => 'esperar', 'cita' => 'hablamos mañana']]);

        $this->correr_job(new DetectarCompromisoEnMensajeJob((int) $lead->id, (int) $mensaje->id));

        $this->assertSame(1, $this->llamadas_a_la_ia());
        $this->assertNotNull($this->compromiso_vigente_de($lead));
    }

    // ------------------------------------------------------------------------------------------
    // A3 — `revisar`
    // ------------------------------------------------------------------------------------------

    /**
     * A3. Un compromiso en `revisar` se cierra (`cumplido`) cuando Juan registra una llamada sin fecha nueva.
     *
     * @return void
     */
    public function test_revisar_se_cierra_con_una_llamada(): void
    {
        $lead = $this->lead_de_ro();
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);
        DB::table('lead_compromisos')->where('id', (int) $fila->id)->update(['status' => 'revisar']);

        $this->registrar_llamada_por_el_panel($lead, ['atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Hablamos: va bien.'])->assertStatus(201);

        $this->assertSame('cumplido', $this->compromiso((int) $fila->id)->status, 'Hubo contacto: el que estaba para revisar ya no hace falta.');
    }

    /**
     * A3. Un compromiso en `revisar` queda `reemplazado` cuando nace otro (por el panel o por una llamada con fecha).
     *
     * @return void
     */
    public function test_revisar_queda_reemplazado_cuando_nace_otro(): void
    {
        $lead = $this->lead_de_ro();
        $viejo = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);
        DB::table('lead_compromisos')->where('id', (int) $viejo->id)->update(['status' => 'revisar']);

        $nuevo = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-30 11:00']);

        $this->assertSame('reemplazado', $this->compromiso((int) $viejo->id)->status);
        $this->assertSame((int) $nuevo->id, (int) $this->compromiso((int) $viejo->id)->reemplazado_por_id);
        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'));
    }

    // ------------------------------------------------------------------------------------------
    // Menores
    // ------------------------------------------------------------------------------------------

    /**
     * Un colgado que se recupera cuando el lead ya tiene otro vigente queda `reemplazado`: nunca dos abiertos.
     *
     * @return void
     */
    public function test_un_colgado_no_deja_dos_abiertos(): void
    {
        $lead = $this->lead_de_ro();
        $colgado = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'llamar']);
        DB::table('lead_compromisos')->where('id', (int) $colgado->id)->update(['status' => 'procesando', 'procesando_desde' => '2026-11-27 09:00:00']);
        $otro = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-12-02 11:00', 'accion' => 'llamar']);
        $this->ir_a('2026-11-27 10:30:00');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());

        $this->assertSame('reemplazado', $this->compromiso((int) $colgado->id)->status);
        $this->assertSame((int) $otro->id, (int) $this->compromiso((int) $colgado->id)->reemplazado_por_id);
        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'));
        $this->assertCount(0, $this->tareas_del_motor($lead));
    }

    /**
     * Una llamada cuya fecha el motor rechaza (a más de 90 días) se registra igual y cumple al vigente anterior: hubo contacto.
     *
     * @return void
     */
    public function test_una_llamada_con_fecha_rechazada_igual_cumple_al_vigente(): void
    {
        $lead = $this->lead_de_ro();
        $vigente = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);

        $this->registrar_llamada_por_el_panel($lead, ['atendio' => false, 'proxima_llamada_at' => '2027-03-15 10:00', 'notas' => 'No atendió.'])->assertStatus(201);

        $this->assertSame('cumplido', $this->compromiso((int) $vigente->id)->status);
        $this->assertCount(0, $this->compromisos_de($lead, 'vigente'), 'La fecha lejana no creó otro.');
    }

    /**
     * El job de detección va por la cola `database` (en `sync` correría adentro del webhook).
     *
     * @return void
     */
    public function test_el_job_de_deteccion_va_por_la_cola_database(): void
    {
        $this->prender_el_motor(['deteccion' => true]);
        $lead = $this->lead_de_ro(['phone' => '+5493511230045']);
        $this->saliente_a_las($lead, 'Hola Ro', '2026-11-24 09:00:00');

        $this->postear_kapso('+5493511230045', 'el martes te escribo')->assertStatus(200);

        $jobs = $this->jobs_encolados(DetectarCompromisoEnMensajeJob::class);
        $this->assertCount(1, $jobs);
        $this->assertSame('database', $jobs->first()->connection);
    }

    /**
     * Un compromiso que cargó (o pasó a `escribir`) un admin «Sólo Leads» NUNCA sale solo: queda sugerencia aunque el envío
     * automático esté prendido.
     *
     * @return void
     */
    public function test_lo_de_un_admin_solo_leads_nunca_sale_solo(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);

        /* Creado por Juan. */
        $lead = $this->lead_de_ro(['phone' => '+54935114' . random_int(10000, 99999)]);
        $this->entrante_a_las($lead, 'Dale, mañana te escribo.', '2026-11-24 11:30:00');
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-25 10:00', 'accion' => 'escribir', 'resumen' => 'Retomar.'], $this->juan);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);
        $this->llegar_la_hora('2026-11-25 10:00:30');
        $this->assertSame([], $this->envios->textos, 'Lo que cargó Juan no sale solo.');
        $this->assertCount(1, $this->mensajes_del_motor($lead));
        $this->assertSame('sugerido', (string) $this->mensajes_del_motor($lead)->first()->status);

        /* Creado por Lucas como `llamar` y pasado a `escribir` por Juan. */
        $this->ir_a('2026-11-24 12:00:00');
        $otro = $this->lead_de_ro(['phone' => '+54935115' . random_int(10000, 99999)]);
        $this->entrante_a_las($otro, 'Dale, mañana te escribo.', '2026-11-24 11:30:00');
        $fila = $this->crear_compromiso_por_el_panel($otro, ['vence_at' => '2026-11-25 10:00', 'accion' => 'llamar', 'resumen' => 'Llamar.']);
        $this->loguear_a($this->juan);
        $this->putJson('/api/admin/lead-compromisos/' . $fila->id, ['accion' => 'escribir'])->assertStatus(200);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);
        $this->llegar_la_hora('2026-11-25 10:00:30');
        $this->assertSame([], $this->envios->textos, 'Lo que pasó a escribir Juan tampoco.');
        $this->assertCount(1, $this->mensajes_del_motor($otro));
    }

    /**
     * El número de un cliente no recibe mensajes del motor (el mismo freno que los otros envíos automáticos): se cancela.
     *
     * @return void
     */
    public function test_al_telefono_de_un_cliente_no_se_le_escribe(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        $cliente = $this->crear_cliente_que_refiere();
        $lead = $this->lead_de_ro(['phone' => (string) $cliente->phone]);
        $this->entrante_a_las($lead, 'Mañana te escribo.', '2026-11-26 11:30:00');
        $this->ir_a('2026-11-26 12:00:00');
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES, 'accion' => 'escribir']);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        $this->llegar_la_hora();

        $this->assertSame([], $this->envios->textos);
        $this->assertCount(0, $this->mensajes_del_motor($lead));
        $this->assertSame('cancelado', $this->compromiso((int) $fila->id)->status);
    }

    /**
     * Con el motor apagado, el listado del panel lo dice (`motor_activo = false`) para que la pantalla no muestre un compromiso
     * como si se fuera a ejecutar; con el motor prendido dice `true`.
     *
     * @return void
     */
    public function test_el_panel_avisa_si_el_motor_esta_apagado(): void
    {
        $lead = $this->lead_de_ro();
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);
        $this->loguear_a($this->admin);

        $this->getJson('/api/admin/lead/' . $lead->id . '/compromisos')->assertStatus(200)->assertJsonPath('motor_activo', true);

        $this->motor_de_compromisos(false);
        $this->getJson('/api/admin/lead/' . $lead->id . '/compromisos')->assertStatus(200)->assertJsonPath('motor_activo', false);
    }
}
