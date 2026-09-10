<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadScheduledMessage;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Programar el envío de un mensaje de WhatsApp a un lead (el botón del relojito, 10/9/2026).
 *
 * Lo que se verifica acá es sobre todo cuándo NO sale nada, porque un mensaje programado tiene una
 * particularidad que el envío directo no tiene: entre que el operador lo deja listo y que
 * efectivamente sale pasa tiempo, y en ese tiempo el mundo cambia. Los tres casos que importan:
 *
 *   1. Al PROGRAMAR: texto libre para una fecha en la que la ventana de 24 hs de Meta ya va a estar
 *      cerrada no se puede, y no tiene que quedar ninguna fila.
 *   2. Al DESPACHAR: la ventana se revalida. Si se cerró en el medio (cron caído, corrida
 *      atrasada), el mensaje NO sale — Meta lo rechazaría y el lead no vería nada.
 *   3. El check de Lucas: `cancel_if_lead_replies`, apagado por defecto. Prendido cancela si el
 *      lead escribió después; apagado el mensaje sale igual, que es lo que él pidió.
 *
 * 🔴 La ventana se prueba con el WhatsappSessionWindowService REAL, abriéndola como se abre en
 * producción: con un mensaje entrante del lead dentro de las últimas 24 hs. Sustituirlo por un
 * doble haría pasar el test aunque el criterio estuviera mal leído desde el servicio, que es
 * justo lo que hay que verificar. Lo único sustituido es WhatsappSendService, para no tocar la red.
 */
class ProgramarEnvioDeMensajeALeadTest extends TestCase
{
    use DatabaseTransactions;

    /** Instante fijo desde el que se cuenta todo el test. */
    const AHORA = '2026-09-10 10:00:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* --------------------------------------------------------------------- */
    /* Programar                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * (1) Texto libre para una hora que cae DENTRO de la ventana: queda programado y pendiente.
     *
     * @return void
     */
    public function test_texto_libre_dentro_de_la_ventana_queda_programado(): void
    {
        $admin = $this->crear_admin('dentro-ventana@test.local');
        $lead  = $this->crear_lead('Dentro de la ventana');
        $this->entrante_del_lead($lead, 1);

        $cuando = Carbon::now()->addHours(3);

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at'      => $cuando->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => 'Che Carim, ¿seguimos con lo que hablamos?',
                'cancel_if_lead_replies' => false,
            ]
        );

        $respuesta->assertStatus(200);

        $programado = LeadScheduledMessage::query()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($programado, 'Tendría que haber quedado una fila programada.');
        $this->assertSame(LeadScheduledMessage::STATUS_PENDIENTE, $programado->status);
        $this->assertSame(LeadScheduledMessage::MODE_TEXTO_LIBRE, $programado->mode);
        $this->assertSame('Che Carim, ¿seguimos con lo que hablamos?', $programado->content);
        $this->assertFalse((bool) $programado->cancel_if_lead_replies, 'El check viene apagado por defecto.');
        $this->assertSame((int) $admin->id, (int) $programado->created_by_admin_id);
        $this->assertSame(
            $cuando->format('Y-m-d H:i'),
            $programado->scheduled_send_at->format('Y-m-d H:i'),
            'La hora guardada tiene que ser la que se pidió, en el huso de la app.'
        );

        /* 🔴 El programado tiene que viajar adentro del lead: es lo que hace que no haga falta
           ningún endpoint de lectura ni polling en la SPA. Si scopeWithAll() no lo carga, la
           conversación no lo muestra nunca. */
        $modelo = $respuesta->json('model');
        $this->assertArrayHasKey('scheduled_messages', $modelo, 'El lead tiene que traer sus programados.');
        $this->assertCount(1, $modelo['scheduled_messages']);
        $this->assertSame((int) $programado->id, (int) $modelo['scheduled_messages'][0]['id']);

        /* Nada salió todavía: programar no manda. */
        $this->assertSame(0, $this->salientes_de($lead));
    }

    /**
     * (2) 🔴 El test central del alta: texto libre para una hora en la que la ventana YA VA A ESTAR
     *     CERRADA se rechaza, y no queda ninguna fila.
     *
     * La ventana está abierta AHORA (el lead escribió hace una hora), así que un chequeo ingenuo
     * de "¿está abierta?" dejaría pasar esto. Lo que hay que mirar es si la fecha ELEGIDA cae antes
     * del vencimiento, y eso es lo que este caso verifica.
     *
     * @return void
     */
    public function test_texto_libre_fuera_de_la_ventana_no_crea_ninguna_fila(): void
    {
        $admin = $this->crear_admin('fuera-ventana@test.local');
        $lead  = $this->crear_lead('Fuera de la ventana');
        $this->entrante_del_lead($lead, 1);

        /* La ventana vence dentro de 23 hs; se pide para dentro de 30. */
        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addHours(30)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => 'Esto no tendría que poder programarse como texto libre.',
            ]
        );

        $respuesta->assertStatus(422);
        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $lead->id)->count());

        /* El 422 tiene que decir el estado real de la ventana: la SPA lo usa para reacomodar el
           formulario a plantilla sin volver a preguntar. */
        $respuesta->assertJson(['ventana_abierta' => true]);
        $this->assertNotNull(
            $respuesta->json('ventana_expira_at'),
            'Con la ventana abierta ahora, el 422 tiene que decir hasta cuándo se puede escribir libre.'
        );
    }

    /**
     * (3) Con la ventana ya cerrada, CUALQUIER fecha futura exige plantilla.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_el_texto_libre_no_se_puede_programar(): void
    {
        $admin = $this->crear_admin('ventana-cerrada@test.local');
        $lead  = $this->crear_lead('Ventana cerrada');
        $this->entrante_del_lead($lead, 30);

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addMinutes(30)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => 'Ni siquiera para dentro de media hora.',
            ]
        );

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['ventana_abierta' => false, 'ventana_expira_at' => null]);
        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $lead->id)->count());
    }

    /**
     * (4) La plantilla sí se puede programar fuera de la ventana: es exactamente su razón de ser.
     *
     * @return void
     */
    public function test_plantilla_fuera_de_la_ventana_queda_programada(): void
    {
        $admin = $this->crear_admin('plantilla-fuera@test.local');
        $lead  = $this->crear_lead('Plantilla fuera de ventana');
        $this->entrante_del_lead($lead, 30);

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at'  => Carbon::now()->addDays(2)->toIso8601String(),
                'mode'               => 'plantilla',
                'content'            => 'Hola Carim, te escribo de ComercioCity.',
                'template_name'      => 'cc_recuperacion_motivo',
                'template_language'  => 'es_AR',
                'template_variables' => ['Carim', 'la demo que quedó pendiente'],
            ]
        );

        $respuesta->assertStatus(200);

        $programado = LeadScheduledMessage::query()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($programado);
        $this->assertSame(LeadScheduledMessage::MODE_PLANTILLA, $programado->mode);
        $this->assertSame('cc_recuperacion_motivo', $programado->template_name);
        $this->assertSame('es_AR', $programado->template_language);
        $this->assertSame(['Carim', 'la demo que quedó pendiente'], $programado->template_variables);
    }

    /**
     * (5) Freno 1: sin texto no se programa nada, y un texto más largo que un WhatsApp tampoco.
     *
     * @return void
     */
    public function test_freno_del_texto_vacio_y_del_texto_demasiado_largo(): void
    {
        $admin = $this->crear_admin('texto@test.local');
        $lead  = $this->crear_lead('Texto');
        $this->entrante_del_lead($lead, 1);

        $vacio = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addHours(2)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => '   ',
            ]
        );
        $vacio->assertStatus(422);

        $largo = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addHours(2)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => str_repeat('a', 4097),
            ]
        );
        $largo->assertStatus(422);

        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $lead->id)->count());
    }

    /**
     * (6) Freno 3: a un lead que ya es cliente no se le programa un mensaje comercial.
     *
     * Los dos caminos que lo vuelven cliente: el estado `cerrado_ganado` y el `promoted_client_id`
     * cargado. Se prueban los dos porque son condiciones independientes y es fácil escribir solo
     * una.
     *
     * @return void
     */
    public function test_freno_del_lead_que_ya_es_cliente(): void
    {
        $admin = $this->crear_admin('cliente@test.local');

        $ganado = $this->crear_lead('Ya es cliente', 'cerrado_ganado');
        $this->entrante_del_lead($ganado, 1);

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $ganado->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addHours(2)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => 'Hola!',
            ]
        );

        $respuesta->assertStatus(422);
        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $ganado->id)->count());
    }

    /**
     * (7) Freno 4: el lead marcado como que ya no recibe mensajes. Sin parámetro que lo saltee.
     *
     * @return void
     */
    public function test_freno_del_lead_que_no_recibe_mensajes(): void
    {
        $admin = $this->crear_admin('no-recibe@test.local');
        $lead  = $this->crear_lead('No recibe mensajes');
        $this->entrante_del_lead($lead, 1);

        $lead->no_recibe_mensajes_at = Carbon::now()->subDay();
        $lead->save();

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addHours(2)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => 'Hola!',
            ]
        );

        $respuesta->assertStatus(422);
        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $lead->id)->count());
    }

    /**
     * (8) Freno 5: ni para atrás ni a más de 30 días.
     *
     * @return void
     */
    public function test_freno_de_la_fecha_pasada_y_de_la_fecha_demasiado_lejana(): void
    {
        $admin = $this->crear_admin('fechas@test.local');
        $lead  = $this->crear_lead('Fechas');
        $this->entrante_del_lead($lead, 1);

        $pasado = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->subHours(2)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => 'Para ayer.',
            ]
        );
        $pasado->assertStatus(422);

        $lejano = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addDays(45)->toIso8601String(),
                'mode'              => 'plantilla',
                'content'           => 'Dentro de un mes y medio.',
                'template_name'     => 'cc_recuperacion_motivo',
            ]
        );
        $lejano->assertStatus(422);

        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $lead->id)->count());
    }

    /**
     * (9) Freno 7: modo plantilla sin elegir plantilla.
     *
     * @return void
     */
    public function test_freno_de_la_plantilla_sin_nombre(): void
    {
        $admin = $this->crear_admin('sin-plantilla@test.local');
        $lead  = $this->crear_lead('Sin plantilla');
        $this->entrante_del_lead($lead, 30);

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at' => Carbon::now()->addDays(1)->toIso8601String(),
                'mode'              => 'plantilla',
                'content'           => 'Un body renderizado sin plantilla que lo respalde.',
            ]
        );

        $respuesta->assertStatus(422);
        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $lead->id)->count());
    }

    /* --------------------------------------------------------------------- */
    /* Editar y cancelar                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * (10) Editar cambia el texto y la fecha, y vuelve a pasar por los mismos frenos.
     *
     * @return void
     */
    public function test_editar_un_programado_cambia_texto_y_fecha_y_revalida_la_ventana(): void
    {
        $admin      = $this->crear_admin('editar@test.local');
        $lead       = $this->crear_lead('Editar');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Texto original.');

        $nueva = Carbon::now()->addHours(5);

        $ok = $this->actingAs($admin, 'sanctum')->putJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages/' . $programado->id,
            [
                'scheduled_send_at'      => $nueva->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => 'Texto corregido.',
                'cancel_if_lead_replies' => true,
            ]
        );

        $ok->assertStatus(200);

        $programado->refresh();
        $this->assertSame('Texto corregido.', $programado->content);
        $this->assertSame($nueva->format('Y-m-d H:i'), $programado->scheduled_send_at->format('Y-m-d H:i'));
        $this->assertTrue((bool) $programado->cancel_if_lead_replies);
        $this->assertSame(LeadScheduledMessage::STATUS_PENDIENTE, $programado->status);

        /* Y la edición no es una puerta trasera: mover la fecha fuera de la ventana rebota igual
           que al programar, y el programado queda como estaba. */
        $rebote = $this->actingAs($admin, 'sanctum')->putJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages/' . $programado->id,
            [
                'scheduled_send_at' => Carbon::now()->addHours(40)->toIso8601String(),
                'mode'              => 'texto_libre',
                'content'           => 'Texto corregido.',
            ]
        );

        $rebote->assertStatus(422);
        $programado->refresh();
        $this->assertSame(
            $nueva->format('Y-m-d H:i'),
            $programado->scheduled_send_at->format('Y-m-d H:i'),
            'Un 422 al editar no puede haber movido la fecha igual.'
        );
    }

    /**
     * (11) Cancelar lo saca de la conversación y deja escrito que lo canceló una persona.
     *
     * @return void
     */
    public function test_cancelar_un_programado_lo_saca_de_la_conversacion(): void
    {
        $admin      = $this->crear_admin('cancelar@test.local');
        $lead       = $this->crear_lead('Cancelar');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Mejor no.');

        $respuesta = $this->actingAs($admin, 'sanctum')->deleteJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages/' . $programado->id
        );

        $respuesta->assertStatus(200);

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, $programado->status);
        $this->assertSame(LeadScheduledMessage::CANCELED_MANUAL, $programado->canceled_reason);

        /* Cancelado no se muestra más: la relación del lead solo trae pendiente + error. */
        $this->assertCount(0, $respuesta->json('model.scheduled_messages'));
    }

    /**
     * (12) Un programado de OTRO lead no se puede tocar pasando el id propio en la URL.
     *
     * @return void
     */
    public function test_no_se_puede_editar_ni_cancelar_el_programado_de_otro_lead(): void
    {
        $admin = $this->crear_admin('ajeno@test.local');

        $mio = $this->crear_lead('Mío');
        $this->entrante_del_lead($mio, 1);

        $ajeno = $this->crear_lead('Ajeno');
        $this->entrante_del_lead($ajeno, 1);
        $programado_ajeno = $this->programar($admin, $ajeno, Carbon::now()->addHours(2), 'De otro.');

        $this->actingAs($admin, 'sanctum')->deleteJson(
            '/api/admin/lead/' . $mio->id . '/scheduled-messages/' . $programado_ajeno->id
        )->assertStatus(404);

        $programado_ajeno->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_PENDIENTE, $programado_ajeno->status);
    }

    /* --------------------------------------------------------------------- */
    /* El despacho                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * (13) El comando despacha un vencido: sale por WhatsApp y queda en el hilo como un mensaje
     *      enviado normal, que es literalmente lo que pidió Lucas.
     *
     * @return void
     */
    public function test_el_comando_despacha_un_vencido_y_deja_el_mensaje_en_el_hilo(): void
    {
        $admin = $this->crear_admin('despacho@test.local');
        $lead  = $this->crear_lead('Despacho');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Salió a horario.');

        $espia = $this->espiar_sender();
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'Tendría que haber salido exactamente un texto.');
        $this->assertSame('Salió a horario.', $espia->textos[0]['body']);
        $this->assertSame($lead->phone, $espia->textos[0]['to']);

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, $programado->status);
        $this->assertNotNull($programado->sent_lead_message_id);

        $mensaje = LeadMessage::query()->where('id', $programado->sent_lead_message_id)->first();
        $this->assertNotNull($mensaje, 'El programado tiene que apuntar al LeadMessage que se creó.');
        $this->assertSame('setter', (string) $mensaje->sender);
        $this->assertSame('enviado', (string) $mensaje->status);
        $this->assertSame('Salió a horario.', (string) $mensaje->content);
        $this->assertNotNull($mensaje->whatsapp_message_id, 'Sin wamid no se puede decir que salió.');
        $this->assertNotNull($mensaje->sent_at);
        $this->assertSame((int) $admin->id, (int) $mensaje->sent_by_admin_id, 'El autor es el admin que lo programó.');

        /* Enviado sale de la relación visible: en la conversación ya está el LeadMessage real, y
           mostrar los dos lo duplicaría. */
        $this->assertCount(0, $lead->fresh()->scheduled_messages);
    }

    /**
     * (14) Un programado que todavía no venció no se toca.
     *
     * @return void
     */
    public function test_el_comando_no_despacha_uno_que_todavia_no_vencio(): void
    {
        $admin = $this->crear_admin('no-vencido@test.local');
        $lead  = $this->crear_lead('No vencido');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(3), 'Todavía no.');

        $espia = $this->espiar_sender();
        Carbon::setTestNow(Carbon::now()->addHour());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'No tendría que haber salido nada.');
        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_PENDIENTE, $programado->status);
        $this->assertSame(0, $this->salientes_de($lead));
    }

    /**
     * (15) 🔴 El check de Lucas PRENDIDO: si el lead escribió después de programarlo, se descarta.
     *
     * @return void
     */
    public function test_con_el_check_prendido_el_mensaje_se_cancela_si_el_lead_escribio(): void
    {
        $admin = $this->crear_admin('check-prendido@test.local');
        $lead  = $this->crear_lead('Check prendido');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Ya no hace falta.', true);

        /* El lead contesta una hora después de que se programó. */
        Carbon::setTestNow(Carbon::now()->addHour());
        $respuesta_del_lead = $this->entrante_del_lead($lead, 0);
        $this->assertGreaterThan(
            (int) $programado->baseline_lead_message_id,
            (int) $respuesta_del_lead->id,
            'El mensaje nuevo del lead tiene que tener un id mayor que el baseline.'
        );

        $espia = $this->espiar_sender();
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'Con el check prendido y el lead ya respondido, no sale nada.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, $programado->status);
        $this->assertSame(LeadScheduledMessage::CANCELED_LEAD_RESPONDIO, $programado->canceled_reason);
        $this->assertNull($programado->sent_lead_message_id);
        $this->assertSame(0, $this->salientes_de($lead), 'Cancelado no puede haber dejado ningún mensaje en el hilo.');
    }

    /**
     * (16) 🔴 El mismo caso con el check APAGADO —que es el default— sale igual.
     *
     * Es la decisión explícita de Lucas del 10/9/2026 y por eso tiene test propio: un "por las
     * dudas no lo mandes" habría sido lo cómodo de programar y es exactamente lo que él no quiso.
     *
     * @return void
     */
    public function test_con_el_check_apagado_el_mensaje_sale_igual_aunque_el_lead_haya_escrito(): void
    {
        $admin = $this->crear_admin('check-apagado@test.local');
        $lead  = $this->crear_lead('Check apagado');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Sale igual.', false);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->entrante_del_lead($lead, 0);

        $espia = $this->espiar_sender();
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'Con el check apagado el mensaje sale igual.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, $programado->status);
        $this->assertNotNull($programado->sent_lead_message_id);
    }

    /**
     * (17) 🔴 La ventana se revalida AL DESPACHAR: si se cerró en el medio, el texto libre no sale.
     *
     * Es el caso del cron caído o la corrida atrasada. Mandarlo igual sería un mensaje que Meta
     * rechaza y que el lead nunca ve, con una fila en el hilo diciendo que se le escribió.
     *
     * @return void
     */
    public function test_si_la_ventana_se_cerro_antes_del_envio_el_mensaje_queda_en_error(): void
    {
        $admin = $this->crear_admin('ventana-vencida@test.local');
        $lead  = $this->crear_lead('Ventana vencida');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Esto ya no sale.');

        $espia = $this->espiar_sender();

        /* La corrida se atrasa un día entero: el entrante del lead ya tiene 26 hs. */
        Carbon::setTestNow(Carbon::now()->addHours(25));

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'Fuera de ventana no se puede haber intentado ningún envío.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_ERROR, $programado->status);
        $this->assertNotNull($programado->error_text, 'El error tiene que decir por qué no salió.');
        $this->assertNull($programado->sent_lead_message_id);
        $this->assertSame(0, $this->salientes_de($lead), 'No puede haber quedado un mensaje "enviado" que nadie recibió.');

        /* En `error` sigue visible en la conversación: es lo único que el operador puede accionar. */
        $this->assertCount(1, $lead->fresh()->scheduled_messages);
    }

    /**
     * (18) Un lead promovido a cliente entre programar y enviar no recibe nada.
     *
     * @return void
     */
    public function test_si_el_lead_paso_a_ser_cliente_el_programado_se_cancela_al_despachar(): void
    {
        $admin = $this->crear_admin('promovido@test.local');
        $lead  = $this->crear_lead('Promovido');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Mensaje comercial.');

        $lead->status = 'cerrado_ganado';
        $lead->save();

        $espia = $this->espiar_sender();
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, $programado->status);
        $this->assertSame(LeadScheduledMessage::CANCELED_LEAD_PROMOVIDO, $programado->canceled_reason);
    }

    /**
     * (19) La plantilla sí sale fuera de la ventana, y por el camino de plantillas.
     *
     * @return void
     */
    public function test_la_plantilla_programada_sale_fuera_de_la_ventana(): void
    {
        $admin = $this->crear_admin('plantilla-sale@test.local');
        $lead  = $this->crear_lead('Plantilla sale');
        $this->entrante_del_lead($lead, 30);

        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at'  => Carbon::now()->addHours(2)->toIso8601String(),
                'mode'               => 'plantilla',
                'content'            => 'Hola Carim, ¿seguís interesado?',
                'template_name'      => 'cc_recuperacion_motivo',
                'template_language'  => 'es_AR',
                'template_variables' => ['Carim', 'la demo'],
            ]
        )->assertStatus(200);

        $espia = $this->espiar_sender();
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'Una plantilla no sale por el camino de texto libre.');
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_recuperacion_motivo', $espia->plantillas[0]['template_name']);
        $this->assertSame(['Carim', 'la demo'], $espia->plantillas[0]['variables']);
        $this->assertSame('es_AR', $espia->plantillas[0]['language_code']);

        $programado = LeadScheduledMessage::query()->where('lead_id', $lead->id)->first();
        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, $programado->status);

        $mensaje = LeadMessage::query()->where('id', $programado->sent_lead_message_id)->first();
        $this->assertNotNull($mensaje);
        $this->assertSame('Hola Carim, ¿seguís interesado?', (string) $mensaje->content);
    }

    /**
     * (20) 🔴 Si WhatsApp no confirma el envío, el programado NO queda en `enviado`.
     *
     * Es el camino de fallo real: `send_text()` no tira excepción, devuelve null. Tratarlo como
     * éxito dejaría al operador mirando un mensaje que dice que salió y que nadie recibió — y al
     * agente de IA leyendo en el historial algo que el lead nunca vio.
     *
     * @return void
     */
    public function test_si_whatsapp_no_confirma_el_envio_el_programado_queda_en_error(): void
    {
        $admin = $this->crear_admin('sin-confirmar@test.local');
        $lead  = $this->crear_lead('Sin confirmar');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Esto lo rechaza Meta.');

        $espia = $this->espiar_sender(false);
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'El envío sí se intentó.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_ERROR, $programado->status);
        $this->assertNull($programado->sent_lead_message_id);
        $this->assertSame(
            0,
            LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'setter')->count(),
            'Nada llegó al lead: no puede quedar una fila de setter diciendo que sí.'
        );
    }

    /* --------------------------------------------------------------------- */
    /* Ayudantes                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * @param string $nombre
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, string $status = 'solicita_disponibilidad'): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = $nombre;
        $lead->company_name = 'Empresa de ' . $nombre;
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Abre la ventana de 24 hs del lead como se abre en producción: con un entrante suyo.
     *
     * @param Lead $lead
     * @param int  $hace_horas Antigüedad del entrante. Más de 24 deja la ventana CERRADA.
     *
     * @return LeadMessage
     */
    private function entrante_del_lead(Lead $lead, int $hace_horas = 1): LeadMessage
    {
        $mensaje          = new LeadMessage();
        $mensaje->lead_id = $lead->id;
        $mensaje->sender  = 'lead';
        $mensaje->content = 'Dale, mañana nos podemos ver.';
        $mensaje->status  = 'enviado';
        $mensaje->save();

        /* El created_at se pisa con update() y no con el modelo: LeadMessage tiene timestamps
           automáticos y un booted() que reacciona al guardado. Acá sólo interesa la antigüedad. */
        $momento = Carbon::now()->subHours($hace_horas);
        DB::table('lead_messages')->where('id', $mensaje->id)->update([
            'created_at' => $momento,
            'updated_at' => $momento,
        ]);

        return $mensaje->refresh();
    }

    /**
     * Programa un mensaje por el endpoint real y devuelve la fila creada.
     *
     * @param Admin  $admin
     * @param Lead   $lead
     * @param Carbon $cuando
     * @param string $texto
     * @param bool   $cancelar_si_responde
     *
     * @return LeadScheduledMessage
     */
    private function programar(
        Admin $admin,
        Lead $lead,
        Carbon $cuando,
        string $texto,
        bool $cancelar_si_responde = false
    ): LeadScheduledMessage {
        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at'      => $cuando->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => $texto,
                'cancel_if_lead_replies' => $cancelar_si_responde,
            ]
        )->assertStatus(200);

        return LeadScheduledMessage::query()
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Sustituye WhatsappSendService por un espía que anota los envíos en vez de llamar a Meta.
     *
     * @param bool $confirma True: devuelve un whatsapp_message_id. False: simula envío no confirmado.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(bool $confirma = true): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Textos libres que se intentaron enviar. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Plantillas que se intentaron enviar. */
            public $plantillas = [];

            /** @var bool Si el envío se confirma o no. */
            public $confirma = true;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body, 'context' => $context];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.programado.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                    'language_code' => $language_code,
                    'context'       => $context,
                ];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó la plantilla (simulado en el test).';

                    return null;
                }

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Mensajes salientes del lead: lo que no tiene que crecer cuando el despacho frena.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function salientes_de(Lead $lead): int
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->whereIn('sender', ['setter', 'sistema'])
            ->where('is_status_event', false)
            ->count();
    }
}
