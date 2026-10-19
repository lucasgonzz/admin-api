<?php

namespace Tests\Feature;

use App\Helpers\WhatsappNormalizer;
use App\Http\Controllers\DemoExperienciaController;
use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\DemoEventoRecibido;
use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\ClientPhoneDirectory;
use App\Services\FranjaHorariaDeSeguimientos;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadDemoSettings;
use App\Services\LeadFollowupService;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los seguimientos automáticos de lead no le escriben al número de un cliente ni salen de madrugada
 * (misión asistente-arreglos-segunda-prueba, N11, 19/10/2026).
 *
 * El caso real que lo destapó: el 18/10/2026 a las 00:00 `leads:check-followups` (cada dos horas, sin
 * franja) le mandó «Hace un par de días te escribimos…» al 3444622139, que es el teléfono del dueño
 * de demo3 (cliente 58 del admin), porque el lead 644 seguía vivo con ese mismo número. Dos defectos
 * en un mensaje: le escribió a un cliente como si fuera un lead, y lo hizo a medianoche.
 *
 * Lo que estos tests fijan, en orden de importancia:
 *
 *  1. 🔴 QUE EL NÚMERO DE UN CLIENTE ACTIVO NO RECIBA SEGUIMIENTOS AUTOMÁTICOS, aunque esté cargado en
 *     otro formato (`3444622139` en un lado, `+5493444622139` en el otro). Ídem empleados de un cliente
 *     activo, admins y leads promovidos.
 *  2. 🔴 QUE LA COMPARACIÓN SEA ESTRICTA: un lead real que coincide con un cliente solo en los últimos
 *     ocho dígitos (lo que da `phones_match()`) SÍ recibe su seguimiento. La guarda no puede callarle
 *     a un lead de verdad.
 *  3. 🔴 QUE DE NOCHE NO SALGA NADA: a las 00:00 y a las 03:00 el cron no procesa; a las 10:00 sí.
 *  4. Que el auto-envío de respaldo de un seguimiento retenido que vence de noche se reprograme a las
 *     09:00 (con el token vivo hasta entonces) y que a las 09:00 salga; que un job que ya es la
 *     reprogramación no se reprograme en cadena.
 *  5. Que el seguimiento único de la página sin demo no salga de noche, no se marque, y la primera
 *     corrida de la mañana lo mande.
 *  6. Que el botón manual (`force_followup_now`) siga mandando a cualquier hora: no es automático.
 *
 * El envío se sustituye a nivel WhatsappSendService (espía), como en ElCronNoTocaAlLeadQueContestoTest:
 * ningún test sale a la red (además, `Http::fake()`).
 */
class SeguimientosNoVanADuenosNiDeMadrugadaTest extends TestCase
{
    use DatabaseTransactions;

    /** Zona horaria de todos los relojes del test (la del admin). */
    const TZ = 'America/Argentina/Buenos_Aires';

    /** Día de referencia de los casos: un lunes cualquiera. */
    const DIA = '2026-10-19';

    /** Horas hacia atrás con las que se fecha el lead: bien pasadas las 48 de la regla de prueba. */
    const HORAS_ATRAS = 72;

    /** Teléfono del dueño del caso real (demo3, cliente 58), tal como suele estar cargado en la ficha. */
    const TELEFONO_DEL_DUENO_LOCAL = '3444622139';

    /** El mismo teléfono en E.164, que es como suele quedar guardado en el lead. */
    const TELEFONO_DEL_DUENO_E164 = '+5493444622139';

    /**
     * Lead real de otra provincia que comparte con el dueño SOLO los últimos ocho dígitos (44622139):
     * Buenos Aires (11) contra Concordia (344). `phones_match()` los da por iguales; no lo son.
     */
    const TELEFONO_PARECIDO = '+5491144622139';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->a_las('10:00:00');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------------------------------
     | 1 y 2. La guarda del teléfono en el cron de seguimientos
     * ------------------------------------------------------------------------------------------- */

    /**
     * 🔴 El caso real, en los dos sentidos del formato: el cliente activo con `3444622139` y el lead con
     * `+5493444622139`, y al revés. Ninguno recibe seguimiento, y el cron no escribe NADA en su hilo
     * (ni seguimiento ni pausa) ni le cambia el estado.
     *
     * @return void
     */
    public function test_el_numero_de_un_cliente_activo_en_otro_formato_no_recibe_seguimiento(): void
    {
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();

        $this->crear_cliente(self::TELEFONO_DEL_DUENO_LOCAL, true);
        $lead_e164 = $this->crear_lead(self::TELEFONO_DEL_DUENO_E164);

        $otro_dueno_e164 = '+5493415550001';
        $this->crear_cliente($otro_dueno_e164, true);
        $lead_local = $this->crear_lead('3415550001');

        $mensajes_antes = $this->mensajes_de([$lead_e164, $lead_local]);

        $stats = app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertFalse($stats['fuera_de_franja']);
        $this->assertSame([], $this->envios_a($espia, self::TELEFONO_DEL_DUENO_E164));
        $this->assertSame([], $this->envios_a($espia, $otro_dueno_e164));
        $this->assertSame($mensajes_antes, $this->mensajes_de([$lead_e164, $lead_local]), 'El cron no tiene que escribir nada en el hilo de un cliente.');
        $this->assertSame('nuevo', $lead_e164->fresh()->status);
        $this->assertSame('nuevo', $lead_local->fresh()->status);
    }

    /**
     * Empleados de un cliente activo y admins: tampoco reciben. Y un cliente DADO DE BAJA no cuenta:
     * el que dejó de ser cliente puede volver a ser un lead y recibir sus seguimientos.
     *
     * @return void
     */
    public function test_empleados_y_admins_no_reciben_y_un_cliente_dado_de_baja_si(): void
    {
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();

        $cliente = $this->crear_cliente('+5493415550002', true);
        $this->crear_empleado($cliente, '0341 555-0003');
        $lead_empleado = $this->crear_lead('+5493415550003');

        $this->crear_admin('3415550004');
        $lead_admin = $this->crear_lead('+5493415550004');

        $this->crear_cliente('+5493415550005', false);
        $lead_ex_cliente = $this->crear_lead('+5493415550005');

        app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertSame([], $this->envios_a($espia, '+5493415550003'), 'El empleado de un cliente activo no es un lead.');
        $this->assertSame([], $this->envios_a($espia, '+5493415550004'), 'Un admin no es un lead.');
        $this->assertCount(1, $this->envios_a($espia, '+5493415550005'), 'Un cliente dado de baja vuelve a ser un lead más.');
        $this->assertSame(0, $this->seguimientos_en_el_hilo($lead_empleado));
        $this->assertSame(0, $this->seguimientos_en_el_hilo($lead_admin));
        $this->assertSame(1, $this->seguimientos_en_el_hilo($lead_ex_cliente));
    }

    /**
     * 🔴 La comparación es ESTRICTA. Un lead real que comparte con el dueño solo los últimos ocho
     * dígitos recibe su seguimiento. El control de que el caso es el que importa: `phones_match()`
     * (el comparador laxo que NO hay que usar acá) sí los daría por iguales.
     *
     * @return void
     */
    public function test_un_lead_que_coincide_solo_en_los_ultimos_ocho_digitos_si_recibe(): void
    {
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();

        $this->crear_cliente(self::TELEFONO_DEL_DUENO_LOCAL, true);
        $lead = $this->crear_lead(self::TELEFONO_PARECIDO);

        $this->assertTrue(
            WhatsappNormalizer::phones_match(self::TELEFONO_DEL_DUENO_LOCAL, self::TELEFONO_PARECIDO),
            'Montaje inválido: el caso tiene que ser uno en el que el comparador laxo se equivoca.'
        );

        app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertCount(1, $this->envios_a($espia, self::TELEFONO_PARECIDO));
        $this->assertSame(1, $this->seguimientos_en_el_hilo($lead));
    }

    /**
     * Un lead promovido a cliente no recibe seguimientos automáticos, tenga el teléfono que tenga. Y
     * otro lead con el MISMO número que ese lead promovido (el dueño que volvió a entrar por un anuncio)
     * tampoco: el webhook usa ese teléfono para reconocer al cliente cuando la ficha no lo tiene.
     *
     * @return void
     */
    public function test_un_lead_promovido_y_otro_con_su_numero_no_reciben(): void
    {
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();

        $cliente = $this->crear_cliente(null, true);

        $promovido                     = $this->crear_lead('+5493415550006');
        $promovido->promoted_client_id = $cliente->id;
        $promovido->save();

        $mismo_numero = $this->crear_lead('3415550006');

        $directorio = new ClientPhoneDirectory();
        $this->assertTrue($directorio->es_lead_de_un_cliente($promovido->fresh()));
        $this->assertTrue($directorio->es_lead_de_un_cliente($mismo_numero->fresh()));

        app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertSame([], $this->envios_a($espia, '+5493415550006'));
        $this->assertSame(0, $this->seguimientos_en_el_hilo($promovido));
        $this->assertSame(0, $this->seguimientos_en_el_hilo($mismo_numero));
    }

    /**
     * 🔴 Revisión independiente, sonda 4: fichas cargadas a mano con el 15 del celular o con dos
     * números en el mismo campo. La guarda tiene que callar al dueño igual —el destino es el número
     * completo, sin el 15— y seguir sin callar a un lead que solo coincide en los últimos 8 dígitos.
     *
     * @return void
     */
    public function test_la_ficha_con_el_15_o_con_varios_numeros_tambien_calla_al_dueno(): void
    {
        $this->crear_cliente('(0345) 15-462-2139', true);
        $this->crear_cliente('351 15 4622139', true);
        $this->crear_cliente('+54 381 15 4622139', true);
        $this->crear_cliente('11 15 4444-5555', true);
        $this->crear_cliente('2966 15 123456', true);
        $this->crear_cliente('3424622139 / 3424000000', true);
        $con_empleado = $this->crear_cliente(null, true);
        $this->crear_empleado($con_empleado, '3434000001, 3434000002; 3434000003 y 3434000004');

        /* Un número sin código de área no se puede resolver a un número completo: se deja como está
           (documentado en ClientPhoneDirectory). Lo que NO se hace es caer a comparar sufijos. */
        $this->crear_cliente('4777888', true);

        $directorio = new ClientPhoneDirectory();

        $callados = [
            '+5493454622139' => '(0345) 15-462-2139',
            '+5493514622139' => '351 15 4622139',
            '+5493814622139' => '+54 381 15 4622139',
            '+5491144445555' => '11 15 4444-5555 (área de 2 dígitos)',
            '+5492966123456' => '2966 15 123456 (área de 4 dígitos)',
            '+5493424622139' => 'primer número de «3424622139 / 3424000000»',
            '+5493424000000' => 'segundo número de «3424622139 / 3424000000»',
            '+5493434000001' => 'empleado, separado por coma',
            '+5493434000002' => 'empleado, separado por punto y coma',
            '+5493434000003' => 'empleado, antes de « y »',
            '+5493434000004' => 'empleado, después de « y »',
        ];
        foreach ($callados as $telefono => $formato) {
            $this->assertTrue($directorio->es_telefono_de_cliente($telefono), "Ficha «{$formato}»: {$telefono} es el dueño y tiene que quedar callado.");
        }

        /* Control: solo los últimos 8 dígitos en común con «(0345) 15-462-2139» → es otro número. */
        $this->assertTrue(WhatsappNormalizer::phones_match('3454622139', '+5491154622139'), 'Montaje: el comparador laxo los confundiría.');
        $this->assertFalse($directorio->es_telefono_de_cliente('+5491154622139'), 'Un lead que coincide solo en los últimos 8 dígitos no es el dueño.');

        /* El número sin área no calla a nadie: ni a uno que termina igual. */
        $this->assertFalse($directorio->es_telefono_de_cliente('+5493414777888'));

        /* Y de punta a punta, por el cron: el dueño con el 15 en la ficha no recibe; el parecido, sí. */
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();
        $this->crear_lead('+5493454622139');
        $this->crear_lead('+5491154622139');

        app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertSame([], $this->envios_a($espia, '+5493454622139'));
        $this->assertCount(1, $this->envios_a($espia, '+5491154622139'));
    }

    /* ---------------------------------------------------------------------------------------------
     | 3. La franja horaria del cron
     * ------------------------------------------------------------------------------------------- */

    /**
     * 🔴 A las 00:00 (la hora del caso real) y a las 03:00 el cron no procesa a nadie; a las 10:00 el
     * mismo lead recibe su seguimiento. De noche no se escribe nada en el hilo: la corrida de la mañana
     * lo toma igual que lo habría tomado la de la noche.
     *
     * @return void
     */
    public function test_de_madrugada_no_sale_nada_y_a_las_diez_si(): void
    {
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();
        $lead = $this->crear_lead('+5493415550007');

        foreach (['00:00:00', '03:00:00'] as $hora) {
            $this->a_las($hora);
            $stats = app(LeadFollowupService::class)->process_all_active_leads();

            $this->assertTrue($stats['fuera_de_franja'], "A las {$hora} el cron tiene que estar fuera de la franja.");
            $this->assertSame(0, $stats['processed'], "A las {$hora} no se procesa ningún lead.");
            $this->assertSame([], $espia->envios, "A las {$hora} no sale nada.");
            $this->assertSame(0, $this->seguimientos_en_el_hilo($lead));
        }

        $this->a_las('10:00:00');
        $stats = app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertFalse($stats['fuera_de_franja']);
        $this->assertSame(1, $stats['suggestions']);
        $this->assertCount(1, $this->envios_a($espia, '+5493415550007'));
    }

    /**
     * Los bordes de la franja, con nombre: arranca a las 09:00 en punto y termina a las 21:00 (a las
     * 21:00 en punto ya está cerrada). Y el próximo momento permitido de cada hora de la noche.
     *
     * @return void
     */
    public function test_los_bordes_de_la_franja(): void
    {
        $this->assertSame(9, FranjaHorariaDeSeguimientos::HORA_DE_INICIO);
        $this->assertSame(21, FranjaHorariaDeSeguimientos::HORA_DE_FIN);

        $this->assertFalse(FranjaHorariaDeSeguimientos::esta_dentro($this->momento('08:59:59')));
        $this->assertTrue(FranjaHorariaDeSeguimientos::esta_dentro($this->momento('09:00:00')));
        $this->assertTrue(FranjaHorariaDeSeguimientos::esta_dentro($this->momento('20:59:59')));
        $this->assertFalse(FranjaHorariaDeSeguimientos::esta_dentro($this->momento('21:00:00')));
        $this->assertFalse(FranjaHorariaDeSeguimientos::esta_dentro($this->momento('00:00:00')));

        /* Un instante en UTC se mide en hora de Argentina: 13:00 UTC son las 10:00 de acá. */
        $this->assertTrue(FranjaHorariaDeSeguimientos::esta_dentro(Carbon::parse(self::DIA . ' 13:00:00', 'UTC')));
        $this->assertFalse(FranjaHorariaDeSeguimientos::esta_dentro(Carbon::parse(self::DIA . ' 03:00:00', 'UTC')));

        $manana_a_las_nueve = Carbon::parse(self::DIA . ' 09:00:00', self::TZ)->addDay();
        $hoy_a_las_nueve    = Carbon::parse(self::DIA . ' 09:00:00', self::TZ);

        $this->assertTrue(FranjaHorariaDeSeguimientos::proximo_momento_permitido($this->momento('23:30:00'))->equalTo($manana_a_las_nueve));
        $this->assertTrue(FranjaHorariaDeSeguimientos::proximo_momento_permitido($this->momento('21:00:00'))->equalTo($manana_a_las_nueve));
        $this->assertTrue(FranjaHorariaDeSeguimientos::proximo_momento_permitido($this->momento('03:00:00'))->equalTo($hoy_a_las_nueve));
        $this->assertTrue(FranjaHorariaDeSeguimientos::proximo_momento_permitido($this->momento('10:15:00'))->equalTo($this->momento('10:15:00')));

        /* Para reprogramar, la apertura más el minuto de margen; de día, el mismo momento. */
        $this->assertSame(60, FranjaHorariaDeSeguimientos::MARGEN_AL_ABRIR_SEGUNDOS);
        $this->assertTrue(FranjaHorariaDeSeguimientos::momento_para_reprogramar($this->momento('23:30:00'))->equalTo($manana_a_las_nueve->copy()->addMinute()));
        $this->assertTrue(FranjaHorariaDeSeguimientos::momento_para_reprogramar($this->momento('10:15:00'))->equalTo($this->momento('10:15:00')));

        /* La espera se redondea para arriba: 1,5 segundos son 2, nunca 1. */
        $this->assertSame(2, FranjaHorariaDeSeguimientos::segundos_hasta(
            Carbon::parse(self::DIA . ' 20:50:00.500000', self::TZ),
            Carbon::parse(self::DIA . ' 20:50:02', self::TZ)
        ));
    }

    /**
     * 6. El botón manual de forzar seguimiento NO es automático: a las 00:00 sigue mandando.
     *
     * @return void
     */
    public function test_el_boton_manual_sigue_mandando_de_noche(): void
    {
        $espia = $this->espiar_sender();
        $this->sembrar_regla_y_plantilla();
        $lead = $this->crear_lead('+5493415550008');

        $this->a_las('00:00:00');
        $outcome = app(LeadFollowupService::class)->force_followup_now($lead);

        $this->assertSame('suggestion', $outcome['result']);
        $this->assertSame('template', $outcome['via']);
        $this->assertCount(1, $this->envios_a($espia, '+5493415550008'));
    }

    /* ---------------------------------------------------------------------------------------------
     | 4. El auto-envío de respaldo de un seguimiento retenido
     * ------------------------------------------------------------------------------------------- */

    /**
     * Un seguimiento retenido para verificación a las 20:50, con 30 minutos de respaldo, vencería a las
     * 21:20: se reprograma a las 09:01:00 del día siguiente —la apertura de la franja más el margen de
     * un minuto— (el contador del panel ya lo muestra así) y el job sale con esa demora. A esa hora el
     * job encuentra su token vivo y manda.
     *
     * @return void
     */
    public function test_el_respaldo_de_un_seguimiento_que_venceria_de_noche_se_reprograma_a_la_manana(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);
        $lead = $this->crear_lead('+5493415550009');

        $this->a_las('20:50:00');
        $mensaje = $this->seguimiento_retenido($lead);
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);

        $manana_nueve_y_uno = Carbon::parse(self::DIA . ' 09:01:00', self::TZ)->addDay();

        $this->assertTrue(
            $mensaje->fresh()->ai_auto_send_at->equalTo($manana_nueve_y_uno),
            'El respaldo que vencía a las 21:20 tiene que quedar para las 09:01 del día siguiente.'
        );

        $job = $this->unico_job_encolado();
        $this->assertTrue($job->delay->equalTo($manana_nueve_y_uno));
        $this->assertSame(1, $this->propiedad($job, 'reprogramaciones_por_la_franja'), 'Movido por la franja: el job ya cuenta como una reprogramación.');

        $this->a_las('09:01:00', 1);
        $job->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $this->assertCount(1, $this->envios_a($espia, '+5493415550009'), 'A las 09:01 el token sigue vivo y el seguimiento sale.');
        $this->assertSame('enviado', $mensaje->fresh()->status);
    }

    /**
     * 🔴 Revisión independiente, sonda 2: con microsegundos en el reloj (como en producción),
     * `diffInSeconds()` truncaba y el respaldo quedaba para las 08:59:59,x — fuera de la franja, ya
     * marcado, y no salía nunca. Ahora la demora se redondea para arriba y hay un minuto de margen: el
     * vencimiento cae siempre adentro de la franja, tanto al programar como al reprogramar desde el job.
     *
     * @return void
     */
    public function test_con_microsegundos_el_respaldo_nunca_queda_antes_de_la_apertura(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $this->demora_de_respaldo(30);

        Carbon::setTestNow(Carbon::parse(self::DIA . ' 20:50:00.654321', self::TZ));
        $mensaje = $this->seguimiento_retenido($this->crear_lead('+5493415550015'));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);

        $job = $this->unico_job_encolado();
        $this->assertTrue(FranjaHorariaDeSeguimientos::esta_dentro($job->delay), 'Programado: el job tiene que vencer DENTRO de la franja, no a las 08:59:59.');
        $this->assertTrue($job->delay->gte(Carbon::parse(self::DIA . ' 09:01:00', self::TZ)->addDay()));
        $this->assertTrue(FranjaHorariaDeSeguimientos::esta_dentro($mensaje->fresh()->ai_auto_send_at));

        /* Reprogramado desde el job que corrió de noche, también con microsegundos. */
        $otro = $this->seguimiento_retenido($this->crear_lead('+5493415550016'));
        Carbon::setTestNow(Carbon::parse(self::DIA . ' 20:00:00.250000', self::TZ));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($otro);
        $de_dia = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->last();

        Carbon::setTestNow(Carbon::parse(self::DIA . ' 21:15:00.999999', self::TZ));
        $de_dia->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $reprogramado = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->last();
        $this->assertNotSame($de_dia, $reprogramado, 'Tenía que encolarse la reprogramación.');
        $this->assertTrue(FranjaHorariaDeSeguimientos::esta_dentro($reprogramado->delay), 'Reprogramado: tiene que vencer dentro de la franja.');
    }

    /**
     * Controles del mismo armado: a las 10:00 el respaldo de 30 minutos queda a las 10:30 (no se toca),
     * y a las 20:50 un mensaje retenido que NO es seguimiento (una respuesta conversacional) tampoco se
     * mueve. La franja es de los seguimientos, no de la conversación.
     *
     * @return void
     */
    public function test_de_dia_o_si_no_es_seguimiento_el_respaldo_no_se_mueve(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $this->demora_de_respaldo(30);

        $this->a_las('10:00:00');
        $de_dia = $this->seguimiento_retenido($this->crear_lead('+5493415550010'));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($de_dia);
        $this->assertTrue($de_dia->fresh()->ai_auto_send_at->equalTo($this->momento('10:30:00')));

        $this->a_las('20:50:00');
        $conversacion              = $this->seguimiento_retenido($this->crear_lead('+5493415550011'));
        $conversacion->is_followup = false;
        $conversacion->followup_template_id = null;
        $conversacion->save();
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($conversacion);
        $this->assertTrue($conversacion->fresh()->ai_auto_send_at->equalTo($this->momento('21:20:00')));

        foreach (Queue::pushed(AutoSendLeadAiSuggestionJob::class) as $job) {
            $this->assertSame(0, $this->propiedad($job, 'reprogramaciones_por_la_franja'));
        }
    }

    /**
     * El job que se programó de día pero corre de noche (un worker atrasado, o un job encolado antes de
     * esta guarda) no manda: se reprograma a las 09:01 con un job nuevo, y el viejo queda obsoleto. Si
     * ese job nuevo TAMBIÉN corre fuera de la franja (por ejemplo, el worker lo toma a las 08:59:59 con
     * el reloj corrido), no se abandona: se vuelve a reprogramar, y a las 09:01 sale una sola vez.
     *
     * @return void
     */
    public function test_el_job_que_corre_fuera_de_la_franja_se_vuelve_a_reprogramar(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);

        $this->a_las('20:00:00');
        $mensaje = $this->seguimiento_retenido($this->crear_lead('+5493415550012'));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);
        $original = $this->unico_job_encolado();
        $this->assertSame(0, $this->propiedad($original, 'reprogramaciones_por_la_franja'));

        /* El worker lo toma recién a las 21:15: ya cerró la franja. */
        $this->a_las('21:15:00');
        $original->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $manana_nueve_y_uno = Carbon::parse(self::DIA . ' 09:01:00', self::TZ)->addDay();
        $this->assertSame([], $espia->envios, 'De noche el respaldo no manda.');
        $this->assertSame('sugerido', $mensaje->fresh()->status);
        $this->assertTrue($mensaje->fresh()->ai_auto_send_at->equalTo($manana_nueve_y_uno));

        $jobs = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->values();
        $this->assertCount(2, $jobs, 'Tiene que haber exactamente un job nuevo: la reprogramación.');
        $primera = $jobs[1];
        $this->assertTrue($primera->delay->equalTo($manana_nueve_y_uno));
        $this->assertSame(1, $this->propiedad($primera, 'reprogramaciones_por_la_franja'));

        /* El job viejo ya no vale: su token se invalidó al reprogramar. */
        $this->a_las('09:01:00', 1);
        $original->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));
        $this->assertSame([], $espia->envios, 'El job viejo quedó obsoleto: no manda dos veces.');

        /* La reprogramación corre a las 08:59:59 (fuera): se vuelve a reprogramar, no se abandona. */
        $this->a_las('08:59:59', 1);
        $primera->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));
        $this->assertSame([], $espia->envios);

        $jobs = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->values();
        $this->assertCount(3, $jobs, 'La reprogramación que corrió fuera de la franja se reprograma otra vez.');
        $segunda = $jobs[2];
        $this->assertSame(2, $this->propiedad($segunda, 'reprogramaciones_por_la_franja'));
        $this->assertTrue($segunda->delay->equalTo($manana_nueve_y_uno), 'De madrugada, la apertura de ese mismo día más el margen.');

        /* Y a las 09:01 sale, una sola vez. */
        $this->a_las('09:01:00', 1);
        $primera->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));
        $segunda->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));
        $this->assertCount(1, $espia->envios);
        $this->assertSame('enviado', $mensaje->fresh()->status);
    }

    /**
     * El tope: un job que ya se reprogramó TOPE veces y vuelve a correr fuera de la franja no encola
     * otro (con la cola `sync` de los tests, donde la demora no existe, sería un bucle). No manda: le
     * saca el contador y deja la sugerencia en la bandeja para una persona.
     *
     * @return void
     */
    public function test_con_el_tope_de_reprogramaciones_queda_en_la_bandeja(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);

        /* Programado a las 07:30 (queda para las 09:01, con el token vivo hasta entonces): así el token
           sigue vigente cuando el job del tope corre a las 08:00 y la única razón para no mandar es el
           tope, no un token vencido. */
        $this->a_las('07:30:00');
        $mensaje = $this->seguimiento_retenido($this->crear_lead('+5493415550017'));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);
        $token = $this->propiedad($this->unico_job_encolado(), 'auto_send_token');

        $en_el_tope = new AutoSendLeadAiSuggestionJob(
            (int) $mensaje->id,
            (int) $token,
            AutoSendLeadAiSuggestionJob::TOPE_DE_REPROGRAMACIONES_POR_LA_FRANJA
        );

        $this->a_las('08:00:00');
        $en_el_tope->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $this->assertSame([], $espia->envios);
        $this->assertCount(1, Queue::pushed(AutoSendLeadAiSuggestionJob::class), 'En el tope no se encola otra reprogramación.');
        $this->assertSame('sugerido', $mensaje->fresh()->status, 'Queda en la bandeja para una persona.');
        $this->assertNull($mensaje->fresh()->ai_auto_send_at, 'Sin contador: no se va a mandar sola.');
    }

    /**
     * El respaldo de un seguimiento retenido de un lead que es cliente no se programa, y si ya estaba
     * programado (de antes de la guarda) el job no manda: deja la sugerencia para que la vea una
     * persona, sin contador.
     *
     * @return void
     */
    public function test_el_respaldo_no_le_escribe_a_un_cliente(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);
        $this->crear_cliente(self::TELEFONO_DEL_DUENO_LOCAL, true);

        $mensaje = $this->seguimiento_retenido($this->crear_lead(self::TELEFONO_DEL_DUENO_E164));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);

        Queue::assertNotPushed(AutoSendLeadAiSuggestionJob::class);
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);

        /* Un job que ya estaba en la cola desde antes: se arma igual que lo armaría el scheduler, con
           el token vigente, para que la única razón para no mandar sea la guarda. */
        $otro_lead = $this->crear_lead('+5493415550013');
        $previo    = $this->seguimiento_retenido($otro_lead);
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($previo);
        $job = $this->unico_job_encolado();

        /* Después de programado, el número pasa a ser de un cliente (se lo cargó en la ficha). */
        $this->crear_cliente('+5493415550013', true);
        $job->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $this->assertSame([], $espia->envios);
        $this->assertSame('sugerido', $previo->fresh()->status, 'La sugerencia queda para una persona.');
        $this->assertNull($previo->fresh()->ai_auto_send_at, 'Sin contador: no se va a mandar sola.');
    }

    /**
     * 🔴 Revisión independiente, sonda 1: el respaldo que la franja pasó a la mañana vuelve a mirar si
     * corresponde antes de mandar. Retenido a las 20:50 (queda para la mañana), el lead escribe a las
     * 23:10 y el agente le contesta: a la mañana la plantilla NO sale (un seguimiento retenido no lo
     * borra `clear_stale_pending_suggestions()`, que filtra `is_followup = false`). Se le saca el
     * contador y queda en la bandeja para una persona.
     *
     * @return void
     */
    public function test_el_retenido_pasado_a_la_manana_no_sale_si_el_lead_escribio_de_noche(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);
        $lead = $this->crear_lead('+5493415550014');

        $this->a_las('20:50:00');
        $mensaje = $this->seguimiento_retenido($lead);
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);
        $job = $this->unico_job_encolado();

        $this->a_las('23:10:00');
        $this->mensaje_suelto($lead, 'lead', 'Hola, sí, contame');
        $this->mensaje_suelto($lead, 'sistema', 'Dale, te cuento cómo funciona.');

        $this->a_las('09:05:00', 1);
        $job->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $this->assertSame([], $espia->envios, 'El lead ya escribió: la plantilla de seguimiento no tiene que salir.');
        $this->assertSame('sugerido', $mensaje->fresh()->status, 'Queda en la bandeja para una persona.');
        $this->assertNull($mensaje->fresh()->ai_auto_send_at, 'Sin contador: no se va a mandar sola.');
    }

    /**
     * Los demás motivos por los que un seguimiento retenido ya no se auto-envía al vencer, aunque sea
     * de día: el lead pasó a cerrado o en pausa, pide intervención humana, tiene la respuesta automática
     * apagada o está marcado como "no recibe mensajes". Control: el mismo armado sin ninguno de esos
     * motivos sale.
     *
     * @return void
     */
    public function test_el_retenido_no_sale_si_el_lead_ya_no_esta_para_seguimientos(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);

        $casos = [
            'en pausa'              => ['status' => 'en_pausa'],
            'cerrado'               => ['status' => 'cerrado_perdido'],
            'intervención humana'   => ['requiere_intervencion_humana' => true],
            'sin respuesta automática' => ['claude_auto_reply' => false],
            'no recibe mensajes'    => ['no_recibe_mensajes_at' => Carbon::now()->subHour()],
        ];

        $numero = 20;
        foreach ($casos as $nombre => $cambios) {
            $numero++;
            $telefono = '+54934155500' . $numero;
            $lead     = $this->crear_lead($telefono);
            $mensaje  = $this->seguimiento_retenido($lead);
            app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);
            $job = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->last();

            /* El cambio llega DESPUÉS de programado el respaldo: el scheduler no lo pudo ver. */
            foreach ($cambios as $campo => $valor) {
                $lead->{$campo} = $valor;
            }
            $lead->save();

            $job->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

            $this->assertSame([], $this->envios_a($espia, $telefono), "Lead {$nombre}: el respaldo no tiene que salir.");
            $this->assertSame('sugerido', $mensaje->fresh()->status, "Lead {$nombre}: queda en la bandeja.");
            $this->assertNull($mensaje->fresh()->ai_auto_send_at, "Lead {$nombre}: sin contador.");
        }

        $control = $this->crear_lead('+5493415550030');
        $mensaje = $this->seguimiento_retenido($control);
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);
        Queue::pushed(AutoSendLeadAiSuggestionJob::class)->last()
            ->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));

        $this->assertCount(1, $this->envios_a($espia, '+5493415550030'), 'Sin ningún motivo, el respaldo sale como siempre.');
    }

    /**
     * 🔴 Revisión independiente, sonda 3: la cadencia cuenta desde que el seguimiento SALIÓ, no desde
     * que se creó. F1 se crea retenido a las 20:50, la franja lo pasa a la mañana y sale a las 09:01;
     * a las 10:00 el cron no puede ver «13 horas desde el último mensaje» y mandar F2 una hora después
     * de F1. `last_message_at()` usa `COALESCE(sent_at, created_at)` para los salientes.
     *
     * @return void
     */
    public function test_la_cadencia_cuenta_desde_que_salio_el_retenido_y_no_desde_que_se_creo(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->demora_de_respaldo(30);
        FollowupRule::query()->updateOrCreate(
            ['estado' => 'nuevo'],
            ['horas_espera' => 12, 'max_followups' => 3, 'activa' => true]
        );
        $lead = $this->crear_lead('+5493415550018');

        $this->a_las('20:50:00');
        $f1 = $this->seguimiento_retenido($lead);

        /* La plantilla del segundo seguimiento, para que F2 (si saliera) salga por el espía. */
        $segunda                = new FollowupTemplate();
        $segunda->estado        = 'nuevo';
        $segunda->dia_numero    = 2;
        $segunda->template_name = 'cc_seg_prueba_nuevo_d2';
        $segunda->language_code = 'es_AR';
        $segunda->body_template = 'Hola {{1}}! Te vuelvo a escribir.';
        $segunda->activa        = true;
        $segunda->save();

        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($f1);
        $job = $this->unico_job_encolado();

        $this->a_las('09:01:00', 1);
        $job->handle(app(LeadSuggestionSendService::class), app(LeadAiSuggestionAutoSendScheduler::class));
        $this->assertCount(1, $this->envios_a($espia, '+5493415550018'), 'Montaje: F1 tiene que salir a las 09:01.');
        $this->assertFalse((bool) $lead->fresh()->tiene_sugerencia_pendiente, 'Montaje: ya no hay nada pendiente.');

        $this->a_las('10:00:00', 1);
        app(LeadFollowupService::class)->process_all_active_leads();

        $this->assertCount(1, $this->envios_a($espia, '+5493415550018'), 'F2 no puede salir una hora después de F1.');
        $this->assertSame(1, $this->seguimientos_en_el_hilo($lead));
    }

    /* ---------------------------------------------------------------------------------------------
     | 5. El seguimiento único de la página sin demo
     * ------------------------------------------------------------------------------------------- */

    /**
     * A las 23:00 el lead cumple todo (abrió la página hace 150 minutos, silencio, ventana abierta) pero
     * no se le escribe ni se lo marca; a las 09:05 del día siguiente la corrida lo toma y le manda el
     * único mensaje. Así se verifica que postergarlo no lo pierde mientras la ventana de 24 hs siga
     * abierta.
     *
     * @return void
     */
    public function test_la_pagina_sin_demo_no_sale_de_noche_y_la_manana_la_toma(): void
    {
        $espia = $this->preparar_pagina_sin_demo();

        $this->a_las('23:00:00');
        $lead = $this->lead_de_pagina('5493519999999');
        $this->mensaje_de_pagina($lead, 'lead', $this->momento('20:00:00'));
        $this->mensaje_de_pagina($lead, 'sistema', $this->momento('20:10:00'));
        $this->apertura($lead, $this->momento('20:30:00'));

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertSame([], $espia->textos, 'De noche no se le escribe.');
        $this->assertNull($lead->fresh()->pagina_seguimiento_enviado_at, 'Y no se lo marca: la mañana lo tiene que tomar.');

        $this->a_las('09:05:00', 1);
        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertNotNull($lead->fresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * Página sin demo de un lead que es cliente (por teléfono o promovido): no sale y no se marca.
     *
     * @return void
     */
    public function test_la_pagina_sin_demo_no_le_escribe_a_un_cliente(): void
    {
        $espia = $this->preparar_pagina_sin_demo();
        $this->a_las('14:00:00');

        $this->crear_cliente(self::TELEFONO_DEL_DUENO_LOCAL, true);
        $dueno = $this->lead_de_pagina(self::TELEFONO_DEL_DUENO_E164);

        $cliente                    = $this->crear_cliente(null, true);
        $promovido                  = $this->lead_de_pagina('5493519999998');
        $promovido->promoted_client_id = $cliente->id;
        $promovido->save();

        $control = $this->lead_de_pagina(self::TELEFONO_PARECIDO);

        foreach ([$dueno, $promovido, $control] as $lead) {
            $this->mensaje_de_pagina($lead, 'lead', $this->momento('11:00:00'));
            $this->mensaje_de_pagina($lead, 'sistema', $this->momento('11:10:00'));
            $this->apertura($lead, $this->momento('11:30:00'));
        }

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'Solo el lead real (el que se parece en los últimos ocho dígitos) recibe.');
        $this->assertSame(
            preg_replace('/\D+/', '', self::TELEFONO_PARECIDO),
            preg_replace('/\D+/', '', $espia->textos[0]['to'])
        );
        $this->assertNull($dueno->fresh()->pagina_seguimiento_enviado_at);
        $this->assertNull($promovido->fresh()->pagina_seguimiento_enviado_at);
    }

    /* ---------------------------------------------------------------------------------------------
     | Armado
     * ------------------------------------------------------------------------------------------- */

    /**
     * Mueve el reloj (Carbon y, por lo tanto, AppTime::now()) a una hora del día de referencia.
     *
     * @param string $hora       Hora en formato H:i:s, en hora de Argentina.
     * @param int    $dias_despues Días a sumar al día de referencia.
     *
     * @return void
     */
    private function a_las(string $hora, int $dias_despues = 0): void
    {
        Carbon::setTestNow(Carbon::parse(self::DIA . ' ' . $hora, self::TZ)->addDays($dias_despues));
    }

    /**
     * Un instante del día de referencia, en hora de Argentina.
     *
     * @param string $hora H:i:s.
     *
     * @return Carbon
     */
    private function momento(string $hora): Carbon
    {
        return Carbon::parse(self::DIA . ' ' . $hora, self::TZ);
    }

    /**
     * Regla y plantilla de seguimiento para el estado `nuevo`: 48 h de espera, un seguimiento.
     * `updateOrCreate` porque `estado` es único; las plantillas que ya hubiera para el estado se
     * apagan para que `find_template_for()` resuelva la de este test. La transacción deshace todo.
     *
     * @return FollowupTemplate
     */
    private function sembrar_regla_y_plantilla(): FollowupTemplate
    {
        FollowupRule::query()->updateOrCreate(
            ['estado' => 'nuevo'],
            ['horas_espera' => 48, 'max_followups' => 1, 'activa' => true]
        );

        return $this->plantilla_de('nuevo');
    }

    /**
     * Plantilla activa de seguimiento para un estado (apaga las demás del estado).
     *
     * @param string $estado
     *
     * @return FollowupTemplate
     */
    private function plantilla_de(string $estado): FollowupTemplate
    {
        FollowupTemplate::query()->where('estado', $estado)->update(['activa' => false]);

        $template                = new FollowupTemplate();
        $template->estado        = $estado;
        $template->dia_numero    = 1;
        $template->template_name = 'cc_seg_prueba_' . $estado . '_d1';
        $template->language_code = 'es_AR';
        $template->body_template = 'Hola {{1}}! Hace un par de días te escribimos.';
        $template->activa        = true;
        $template->save();

        return $template;
    }

    /**
     * Lead en `nuevo`, creado hace HORAS_ATRAS y sin mensajes: cumple la regla de 48 h.
     *
     * @param string $telefono
     *
     * @return Lead
     */
    private function crear_lead(string $telefono): Lead
    {
        $lead               = new Lead();
        $lead->phone        = $telefono;
        $lead->contact_name = 'Marina';
        $lead->status       = 'nuevo';
        $lead->created_at   = Carbon::now()->subHours(self::HORAS_ATRAS);
        $lead->save();

        return $lead;
    }

    /**
     * @param string|null $telefono  Teléfono de la ficha (null: sin teléfono, como un cliente que lo
     *                               tiene solo en el lead del que nació).
     * @param bool        $is_active
     *
     * @return Client
     */
    private function crear_cliente(?string $telefono, bool $is_active): Client
    {
        $client               = new Client();
        $client->name         = 'Dueño de prueba';
        $client->company_name = 'Comercio de prueba ' . Str::random(6);
        $client->phone        = $telefono;
        $client->is_active    = $is_active;
        $client->save();

        return $client;
    }

    /**
     * @param Client $client
     * @param string $telefono
     *
     * @return ClientEmployee
     */
    private function crear_empleado(Client $client, string $telefono): ClientEmployee
    {
        $employee            = new ClientEmployee();
        $employee->client_id = $client->id;
        $employee->name      = 'Empleado de prueba';
        $employee->phone     = $telefono;
        $employee->save();

        return $employee;
    }

    /**
     * @param string $telefono
     *
     * @return Admin
     */
    private function crear_admin(string $telefono): Admin
    {
        $admin               = new Admin();
        $admin->name         = 'Admin de prueba';
        $admin->email        = 'admin.' . Str::random(8) . '@prueba.test';
        $admin->password     = bcrypt('secret');
        $admin->phone_number = $telefono;
        $admin->save();

        return $admin;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra qué se le pidió mandar (plantillas).
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Plantillas que se pidió enviar. */
            public $envios = [];

            /**
             * @param string      $to
             * @param string      $template_name
             * @param array       $variables
             * @param string      $language_code
             * @param string|null $context
             *
             * @return string|null
             */
            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                return 'wamid.ESPIA.' . uniqid('', true);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Envíos del espía cuyo destino es, dígito a dígito y ya normalizado, el teléfono pedido.
     *
     * @param WhatsappSendService $espia
     * @param string              $telefono
     *
     * @return array<int, array<string, mixed>>
     */
    private function envios_a(WhatsappSendService $espia, string $telefono): array
    {
        $buscado = WhatsappNormalizer::normalize($telefono);

        return array_values(array_filter($espia->envios, function ($envio) use ($buscado) {
            return WhatsappNormalizer::normalize((string) $envio['to']) === $buscado;
        }));
    }

    /**
     * Cantidad de filas del hilo de cada lead, para comprobar que el cron no escribió nada.
     *
     * @param array<int, Lead> $leads
     *
     * @return array<int, int>
     */
    private function mensajes_de(array $leads): array
    {
        $conteo = [];
        foreach ($leads as $lead) {
            $conteo[$lead->id] = LeadMessage::query()->where('lead_id', $lead->id)->count();
        }

        return $conteo;
    }

    /**
     * @param Lead $lead
     *
     * @return int
     */
    private function seguimientos_en_el_hilo(Lead $lead): int
    {
        return LeadMessage::query()->where('lead_id', $lead->id)->where('is_followup', true)->count();
    }

    /**
     * Mensaje del hilo fechado AHORA (el reloj del test), en cualquier dirección.
     *
     * @param Lead   $lead
     * @param string $sender  'lead' | 'sistema' | 'setter'.
     * @param string $content
     *
     * @return LeadMessage
     */
    private function mensaje_suelto(Lead $lead, string $sender, string $content): LeadMessage
    {
        $mensaje                      = new LeadMessage();
        $mensaje->lead_id             = $lead->id;
        $mensaje->sender              = $sender;
        $mensaje->content             = $content;
        $mensaje->status              = 'enviado';
        $mensaje->is_followup         = false;
        $mensaje->whatsapp_message_id = $sender === 'lead' ? null : 'wamid.suelto.' . Str::random(6);
        $mensaje->save();

        return $mensaje;
    }

    /**
     * Demora del respaldo de los mensajes retenidos para verificación, en minutos.
     *
     * @param int $minutos
     *
     * @return void
     */
    private function demora_de_respaldo(int $minutos): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_VERIFICACION_AGENDAMIENTO_AUTO_SEND_DELAY_MINUTES, (string) $minutos);
    }

    /**
     * Seguimiento por plantilla retenido para verificación, como lo deja
     * `LeadFollowupService::create_pending_followup_for_verification()`.
     *
     * @param Lead $lead
     *
     * @return LeadMessage
     */
    private function seguimiento_retenido(Lead $lead): LeadMessage
    {
        $template = $this->plantilla_de('nuevo');

        $lead->tiene_sugerencia_pendiente = true;
        $lead->save();

        return LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Hola Marina! Hace un par de días te escribimos.',
            'status'                => 'sugerido',
            'is_followup'           => true,
            'followup_template_id'  => $template->id,
            'requiere_verificacion' => true,
            'whatsapp_message_id'   => null,
            'sent_at'               => null,
        ]);
    }

    /**
     * El único AutoSendLeadAiSuggestionJob encolado hasta ahora.
     *
     * @return AutoSendLeadAiSuggestionJob
     */
    private function unico_job_encolado(): AutoSendLeadAiSuggestionJob
    {
        $jobs = Queue::pushed(AutoSendLeadAiSuggestionJob::class);
        $this->assertCount(1, $jobs);

        return $jobs->first();
    }

    /**
     * Lee una propiedad privada de un objeto (los jobs guardan su estado en privadas).
     *
     * @param object $objeto
     * @param string $nombre
     *
     * @return mixed
     */
    private function propiedad($objeto, string $nombre)
    {
        $reflexion = new \ReflectionProperty($objeto, $nombre);
        $reflexion->setAccessible(true);

        return $reflexion->getValue($objeto);
    }

    /**
     * Configuración de `leads:check-pagina-sin-demo` como en CheckPaginaSinDemoTest: 120 minutos de
     * espera desde la apertura, 10 de silencio, sin eventos de página ajenos, y un espía de texto libre.
     *
     * @return WhatsappSendService
     */
    private function preparar_pagina_sin_demo(): WhatsappSendService
    {
        AdminSetting::set(LeadDemoSettings::KEY_PAGINA_SEGUIMIENTO_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_CHECK_INGRESO_SILENCIO_MINUTOS, '10');
        DemoEventoRecibido::whereIn('nombre', DemoExperienciaController::EVENTOS_PAGINA)->delete();

        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Lead de la dinámica nueva, contactado, sin demo.
     *
     * @param string $telefono
     *
     * @return Lead
     */
    private function lead_de_pagina(string $telefono): Lead
    {
        $lead                             = new Lead();
        $lead->uuid                       = (string) Str::uuid();
        $lead->contact_name               = 'Guillermo González';
        $lead->company_name               = 'Ferretería de prueba';
        $lead->phone                      = $telefono;
        $lead->status                     = 'contactado';
        $lead->demo_experiencia           = Lead::EXPERIENCIA_NUEVA;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * @param Lead   $lead
     * @param Carbon $cuando
     *
     * @return DemoEventoRecibido
     */
    private function apertura(Lead $lead, Carbon $cuando): DemoEventoRecibido
    {
        return DemoEventoRecibido::create([
            'lead_id'     => $lead->id,
            'uuid'        => (string) Str::uuid(),
            'nombre'      => DemoExperienciaController::EVENTO_PAGINA_ABIERTA,
            'clip_id'     => null,
            'ocurrido_at' => $cuando,
            'datos'       => null,
        ]);
    }

    /**
     * @param Lead   $lead
     * @param string $sender
     * @param Carbon $cuando
     *
     * @return LeadMessage
     */
    private function mensaje_de_pagina(Lead $lead, string $sender, Carbon $cuando): LeadMessage
    {
        $mensaje                      = new LeadMessage();
        $mensaje->lead_id             = $lead->id;
        $mensaje->sender              = $sender;
        $mensaje->status              = 'enviado';
        $mensaje->is_followup         = false;
        $mensaje->content             = 'Mensaje de ' . $sender;
        $mensaje->whatsapp_message_id = $sender === 'lead' ? null : 'wamid.previo.' . Str::random(6);
        $mensaje->created_at          = $cuando;
        $mensaje->updated_at          = $cuando;
        $mensaje->save();

        return $mensaje;
    }
}
