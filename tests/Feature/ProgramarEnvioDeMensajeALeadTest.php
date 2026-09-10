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

    /**
     * (21) 🔴 EL test que faltaba: el mensaje SALE y el registro falla.
     *
     * Es el caso más caro del diseño, y estuvo vivo hasta el 10/9/2026: la fila quedaba en
     * `pendiente` con el mensaje ya entregado, y como `scopeVencidos()` la volvía a levantar, el
     * comando de cada minuto lo remandaba. Medido: cinco corridas, cinco WhatsApps al mismo lead.
     *
     * Lo que este test fija no es el manejo del error —eso es secundario— sino la propiedad que
     * importa: **una segunda corrida NO vuelve a enviar**.
     *
     * @return void
     */
    public function test_si_el_envio_sale_pero_el_registro_falla_no_se_reenvia(): void
    {
        $admin = $this->crear_admin('registro-falla@test.local');
        $lead  = $this->crear_lead('Registro falla');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Esto sale pero no se registra.');

        $espia = $this->espiar_sender(true);

        /* Se rompe la escritura del LeadMessage sin tocar el servicio: el hook `creating` del
           modelo tira una excepción, que es exactamente la forma del fallo real (un lock-wait sobre
           `leads` desde el hook `created`, o la unique de whatsapp_message_id). */
        LeadMessage::creating(function (LeadMessage $mensaje) {
            if ((string) $mensaje->sender === 'setter') {
                throw new \RuntimeException('Falla simulada al escribir el LeadMessage.');
            }
        });

        try {
            Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());
            $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

            $this->assertCount(1, $espia->textos, 'El mensaje salió: eso es justamente el problema.');

            $programado->refresh();
            $this->assertNotSame(
                LeadScheduledMessage::STATUS_PENDIENTE,
                $programado->status,
                '🔴 Si vuelve a `pendiente`, el comando lo remanda cada minuto para siempre.'
            );
            $this->assertSame(LeadScheduledMessage::STATUS_ERROR, $programado->status);
            $this->assertSame('wamid.programado.1', $programado->whatsapp_message_id, 'El wamid queda para reconstruirlo a mano.');

            /* La propiedad que importa: la corrida siguiente no lo vuelve a mandar. */
            Carbon::setTestNow(Carbon::now()->addMinute());
            $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

            $this->assertCount(1, $espia->textos, '🔴 Segunda corrida: el lead NO puede recibir el mismo mensaje otra vez.');
        } finally {
            LeadMessage::flushEventListeners();
        }
    }

    /**
     * (22) Un programado que quedó colgado en `enviando` se destraba solo, y no se reenvía.
     *
     * El caso es el proceso muerto entre el envío y el registro: no hay `catch` posible porque el
     * proceso ya no existe. La corrida siguiente lo pasa a `error` diciendo que NO se sabe si salió
     * — que es la verdad. Reintentar sería peor: si había salido, el lead lo recibe dos veces.
     *
     * @return void
     */
    public function test_un_programado_colgado_en_enviando_se_vence_y_no_se_reenvia(): void
    {
        $admin = $this->crear_admin('colgado@test.local');
        $lead  = $this->crear_lead('Colgado');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Quedó colgado a mitad.');

        /* Como lo dejaría un proceso muerto: reclamado hace mucho y sin cerrar. */
        DB::table('lead_scheduled_messages')->where('id', $programado->id)->update([
            'status'              => LeadScheduledMessage::STATUS_ENVIANDO,
            'dispatch_started_at' => Carbon::now()->subHours(3),
        ]);

        $espia = $this->espiar_sender(true);
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, '🔴 Un colgado NO se reintenta: podría duplicar un mensaje que ya salió.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_ERROR, $programado->status);
        $this->assertStringContainsString('NO se sabe', (string) $programado->error_text);
    }

    /**
     * (23) A un lead `cerrado_perdido` no se le programa, y si se cierra después, no le sale.
     *
     * La SPA ya deshabilitaba el botón; el backend no lo miraba en ninguna de las dos puertas, así
     * que un mensaje programado con el lead vivo salía igual después de marcarlo perdido.
     *
     * @return void
     */
    public function test_un_lead_cerrado_perdido_frena_en_las_dos_puertas(): void
    {
        $admin = $this->crear_admin('perdido@test.local');

        /* Puerta 1: programar. */
        $perdido = $this->crear_lead('Ya perdido', 'cerrado_perdido');
        $this->entrante_del_lead($perdido, 1);

        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $perdido->id . '/scheduled-messages',
            [
                'scheduled_send_at'      => Carbon::now()->addHours(2)->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => 'A un lead perdido no se le programa.',
                'cancel_if_lead_replies' => false,
            ]
        )->assertStatus(422);

        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', $perdido->id)->count());

        /* Puerta 2: despachar. Se programa con el lead vivo y se cierra después. */
        $vivo = $this->crear_lead('Se pierde después');
        $this->entrante_del_lead($vivo, 1);
        $programado = $this->programar($admin, $vivo, Carbon::now()->addHours(2), 'Se cerró en el medio.');

        $vivo->status = 'cerrado_perdido';
        $vivo->save();

        $espia = $this->espiar_sender(true);
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'A un lead que se cerró entre programar y enviar no le sale nada.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, $programado->status);
        $this->assertSame(LeadScheduledMessage::CANCELED_LEAD_CERRADO, $programado->canceled_reason);
    }

    /**
     * (24) Pegar un chat exportado NO cancela un programado con el check prendido.
     *
     * `store_message_json()` crea mensajes VIEJOS del lead con ids nuevos y sin `sent_at`. Sin la
     * guarda, importar una conversación histórica descartaba mensajes programados como si el lead
     * acabara de contestar.
     *
     * @return void
     */
    public function test_pegar_un_chat_exportado_no_cancela_un_programado(): void
    {
        $admin = $this->crear_admin('pegado@test.local');
        $lead  = $this->crear_lead('Chat pegado');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Esto tiene que salir igual.', true);

        /* Como lo deja el pegado del chat exportado: sender lead, id nuevo, SIN sent_at. */
        $pegado          = new LeadMessage();
        $pegado->lead_id = $lead->id;
        $pegado->sender  = 'lead';
        $pegado->content = 'Mensaje viejo, copiado del export de WhatsApp.';
        $pegado->status  = 'enviado';
        $pegado->save();

        $this->assertGreaterThan((int) $programado->baseline_lead_message_id, (int) $pegado->id);

        $espia = $this->espiar_sender(true);
        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, '🔴 Un mensaje pegado a mano no es el lead escribiendo: el envío sale.');

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, $programado->status);
    }

    /**
     * (25) Cancelar dos veces no pisa el motivo de la primera cancelación.
     *
     * Un `lead_respondio` convertido en `manual` le borra al operador la única explicación de por
     * qué ese mensaje no salió.
     *
     * @return void
     */
    public function test_cancelar_es_idempotente_y_no_pisa_el_motivo(): void
    {
        $admin = $this->crear_admin('idempotente@test.local');
        $lead  = $this->crear_lead('Idempotente');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Se cancela solo primero.');

        DB::table('lead_scheduled_messages')->where('id', $programado->id)->update([
            'status'          => LeadScheduledMessage::STATUS_CANCELADO,
            'canceled_reason' => LeadScheduledMessage::CANCELED_LEAD_RESPONDIO,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/lead/' . $lead->id . '/scheduled-messages/' . $programado->id)
            ->assertStatus(200);

        $programado->refresh();
        $this->assertSame(
            LeadScheduledMessage::CANCELED_LEAD_RESPONDIO,
            $programado->canceled_reason,
            'El motivo original es la única explicación que le queda al operador.'
        );
    }

    /**
     * (26) Un programado en `error` se puede corregir y vuelve a la cola.
     *
     * Es el camino que más se va a usar: quedó sin salir porque se cerró la ventana, y lo natural
     * es corregirle la fecha, no copiar el texto a mano y empezar de cero.
     *
     * @return void
     */
    public function test_un_programado_en_error_se_puede_editar_y_vuelve_a_pendiente(): void
    {
        $admin = $this->crear_admin('reintento@test.local');
        $lead  = $this->crear_lead('Reintento');
        $this->entrante_del_lead($lead, 1);
        $programado = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Primer intento.');

        DB::table('lead_scheduled_messages')->where('id', $programado->id)->update([
            'status'     => LeadScheduledMessage::STATUS_ERROR,
            'error_text' => 'La ventana se cerró antes de que saliera.',
        ]);

        $this->actingAs($admin, 'sanctum')->putJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages/' . $programado->id,
            [
                'scheduled_send_at'      => Carbon::now()->addHours(3)->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => 'Segundo intento, corregido.',
                'cancel_if_lead_replies' => false,
            ]
        )->assertStatus(200);

        $programado->refresh();
        $this->assertSame(LeadScheduledMessage::STATUS_PENDIENTE, $programado->status);
        $this->assertNull($programado->error_text, 'El motivo viejo no sobrevive a la corrección.');
        $this->assertSame('Segundo intento, corregido.', (string) $programado->content);
    }

    /**
     * (27) Varios vencidos en la misma corrida: salen todos, y uno que falla no aborta a los demás.
     *
     * Antes, la primera excepción se llevaba puesta toda la corrida y los que venían atrás se
     * quedaban sin salir ese minuto — un lead pagaba el problema de otro.
     *
     * @return void
     */
    public function test_varios_vencidos_salen_todos_en_orden(): void
    {
        $admin = $this->crear_admin('varios@test.local');
        $lead  = $this->crear_lead('Varios');
        $this->entrante_del_lead($lead, 1);

        $primero = $this->programar($admin, $lead, Carbon::now()->addHours(2), 'Primero.');
        $segundo = $this->programar($admin, $lead, Carbon::now()->addHours(3), 'Segundo.');
        $tercero = $this->programar($admin, $lead, Carbon::now()->addHours(4), 'Tercero.');

        $espia = $this->espiar_sender(true);
        Carbon::setTestNow(Carbon::now()->addHours(5));

        $this->artisan('leads:send-scheduled-messages')->assertExitCode(0);

        $this->assertCount(3, $espia->textos, 'Los tres vencidos salen en la misma corrida.');
        $this->assertSame('Primero.', $espia->textos[0]['body'], 'Salen en el orden en que el operador los quiso.');
        $this->assertSame('Segundo.', $espia->textos[1]['body']);
        $this->assertSame('Tercero.', $espia->textos[2]['body']);

        foreach ([$primero, $segundo, $tercero] as $fila) {
            $fila->refresh();
            $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, $fila->status);
        }
    }

    /**
     * (28) El borde exacto de la ventana: programar PARA el instante del vencimiento se rechaza.
     *
     * Es el caso que dictó Lucas —"si el último mensaje del lead fue a las cinco de la tarde, me
     * dejaría programar con texto libre hasta las cinco de la tarde del próximo día"— y la línea
     * que más barato se rompe en un refactor.
     *
     * @return void
     */
    public function test_el_instante_exacto_del_vencimiento_ya_es_fuera_de_ventana(): void
    {
        $admin    = $this->crear_admin('borde@test.local');
        $lead     = $this->crear_lead('Borde');
        $entrante = $this->entrante_del_lead($lead, 1);

        /* La ventana vence exactamente 24 hs después del entrante. */
        $vencimiento = Carbon::parse($entrante->created_at)->addHours(24);

        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at'      => $vencimiento->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => 'Justo en el filo.',
                'cancel_if_lead_replies' => false,
            ]
        )->assertStatus(422);

        /* Un segundo antes sí entra. */
        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/lead/' . $lead->id . '/scheduled-messages',
            [
                'scheduled_send_at'      => $vencimiento->copy()->subSecond()->toIso8601String(),
                'mode'                   => 'texto_libre',
                'content'                => 'Un segundo antes.',
                'cancel_if_lead_replies' => false,
            ]
        )->assertStatus(200);
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
        $momento = Carbon::now()->subHours($hace_horas);

        $mensaje          = new LeadMessage();
        $mensaje->lead_id = $lead->id;
        $mensaje->sender  = 'lead';
        $mensaje->content = 'Dale, mañana nos podemos ver.';
        $mensaje->status  = 'enviado';
        /* 🔴 `sent_at` cargado, como lo deja el webhook real (y el simulador del panel). No es
           decorado: es lo que distingue un mensaje que el lead mandó de uno que alguien pegó del
           chat exportado, y `lead_escribio_despues()` se apoya justamente en eso. Un entrante de
           prueba sin `sent_at` no representa lo que dice representar. */
        $mensaje->sent_at = $momento;
        $mensaje->save();

        /* El created_at se pisa con update() y no con el modelo: LeadMessage tiene timestamps
           automáticos y un booted() que reacciona al guardado. Acá sólo interesa la antigüedad. */
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
