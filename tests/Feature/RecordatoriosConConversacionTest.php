<?php

namespace Tests\Feature;

use App\Console\Commands\SendDemoReminders;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadReminderComposer;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Los recordatorios de demo teniendo en cuenta la conversación con el lead (misión
 * recordatorios-demo-con-conversacion, 16/10/2026, pedido de Lucas): con la ventana de 24 hs de Meta
 * abierta el texto lo redacta la IA retomando el chat; con la ventana cerrada sale la plantilla ya
 * armada; y cualquier falla de la IA degrada a lo que salía antes.
 *
 * Todo es hermético: la IA y WhatsApp están simulados. Cada método cubre varios escenarios en UNA
 * corrida del comando (leads con nombres distintos; el simulador de HTTP contesta según el nombre
 * que ve en el pedido) porque arrancar la app cuesta ~15 segundos por test.
 */
class RecordatoriosConConversacionTest extends TestCase
{
    use DatabaseTransactions;

    const PLANTILLA_MANANA = 'cc_recordatorio_manana_demo_v2';

    const PLANTILLA_PRE_DEMO = 'cc_recordatorio_demo_';

    /** Un texto de la mañana que cumple todas las reglas (dice la hora real: 10:00). */
    const TEXTO_MANANA_OK = 'Guillermo, te escribo por lo de hoy: a las 10:00 es tu demo y vas a poder ver cómo llevar el stock de la ferretería. Cualquier duda, escribime por acá. 🙂';

    /** Un texto de minutos antes que cumple todas las reglas (sin ninguna hora). */
    const TEXTO_PRE_DEMO_OK = 'Guillermo, en unos minutos ya podés entrar a tu demo; te dejé el acceso en el mail. Cualquier duda mientras la recorrés, escribime por acá.';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fijar_ahora('2026-10-16 09:00:00');

        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_MANANA_HORA, '09:00');
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_SILENCIO_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_IA_ACTIVO, '1');
        /* El resumen matinal a los admins no es parte de esto: se marca como ya enviado hoy. */
        AdminSetting::set('demo_resumen_admins_ultimo_envio', '2026-10-16');

        config([
            'services.lead_ai.proveedor'     => 'deepseek',
            'services.deepseek.api_key'      => 'sk-deepseek-test',
            'services.deepseek.base_url'     => 'https://api.deepseek.com/anthropic',
            'services.deepseek.model_leads'  => 'deepseek-flash',
            'services.deepseek.verify_ssl'   => true,
            'services.deepseek.ca_bundle'    => null,
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Recordatorio de la mañana
    // ---------------------------------------------------------------------------------------------

    /**
     * (1) Ventana abierta: sale el texto de la IA, y el pedido a la IA lleva el chat (sin lo borrado,
     *     los errores internos ni las reacciones), los datos verificados y NO lleva herramientas.
     *     El hilo guarda el texto realmente enviado.
     *
     * @return void
     */
    public function test_manana_con_la_ventana_abierta_sale_el_texto_de_la_ia_con_el_chat_en_el_pedido(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->simular_http(['Guillermo' => $this->respuesta_ia(self::TEXTO_MANANA_OK)]);

        $lead = $this->crear_lead('Guillermo González', '5493519990001');
        $this->mensaje($lead, 'lead', Carbon::now()->subHours(5), 'Hola, tengo una ferretería y quiero ver cómo llevo el stock');
        $this->mensaje($lead, 'setter', Carbon::now()->subHours(4), 'Buenísimo, te agendo la demo', 'wamid.previo');

        $borrado = $this->mensaje($lead, 'lead', Carbon::now()->subHours(3), 'SECRETO_BORRADO');
        $borrado->deleted_from_context = true;
        $borrado->save();

        $error = $this->mensaje($lead, 'sistema', Carbon::now()->subHours(3), 'ERROR_INTERNO');
        $error->is_error = true;
        $error->is_status_event = true;
        $error->save();

        $reaccion = $this->mensaje($lead, 'lead', Carbon::now()->subHours(2), 'REACCION_PULGAR');
        $reaccion->kind = 'reaction';
        $reaccion->save();

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertSame(self::TEXTO_MANANA_OK, $espia->textos[0]['body']);
        $this->assertSame('5493519990001', $espia->textos[0]['to']);
        $this->assertCount(0, $this->plantillas_de($espia, self::PLANTILLA_MANANA), 'Con la ventana abierta y la IA bien no sale la plantilla.');
        $this->assertTrue((bool) $lead->refresh()->recordatorio_manana_enviado);

        $guardado = LeadMessage::where('lead_id', $lead->id)->where('content', self::TEXTO_MANANA_OK)->first();
        $this->assertNotNull($guardado, 'El hilo tiene que guardar el texto que realmente se mandó.');
        $this->assertSame('sistema', $guardado->sender);
        $this->assertSame('wamid.texto.1', $guardado->whatsapp_message_id);

        $pedidos = $this->pedidos_a_la_ia();
        $this->assertCount(1, $pedidos);
        $pedido = $pedidos[0];
        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', $pedido->url());
        $this->assertTrue($pedido->hasHeader('x-api-key', 'sk-deepseek-test'));
        $this->assertSame('deepseek-flash', $pedido['model']);
        $this->assertSame(['type' => 'disabled'], $pedido['thinking'], 'DeepSeek trae el razonamiento prendido: hay que apagarlo.');
        $this->assertArrayNotHasKey('tools', $pedido->data(), 'Un recordatorio no puede tener herramientas: no puede hacer nada más que escribir.');

        $contenido = (string) $pedido['messages'][0]['content'];
        $this->assertStringContainsString('ferretería y quiero ver cómo llevo el stock', $contenido);
        $this->assertStringContainsString('Buenísimo, te agendo la demo', $contenido);
        $this->assertStringNotContainsString('SECRETO_BORRADO', $contenido);
        $this->assertStringNotContainsString('ERROR_INTERNO', $contenido);
        $this->assertStringNotContainsString('REACCION_PULGAR', $contenido);
        $this->assertStringContainsString('Primer nombre del lead: Guillermo', $contenido);
        $this->assertStringContainsString('Hora de la demo: hoy a las 10:00', $contenido);
        $this->assertStringContainsString('Hablaron hoy en este chat: sí', $contenido);
    }

    /**
     * (2) Ventana cerrada: sale la plantilla de siempre, con la IA sin tocar. Un lead sin nombre
     *     recibe "Cliente" (Meta rechaza un parámetro vacío).
     *
     * @return void
     */
    public function test_manana_con_la_ventana_cerrada_sale_la_plantilla_y_no_se_llama_a_la_ia(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->simular_http([]);

        $con_nombre = $this->crear_lead('Guillermo González', '5493519990002');
        $this->mensaje($con_nombre, 'lead', Carbon::now()->subDays(3), 'Hola, quiero la demo');

        $sin_nombre = $this->crear_lead('', '5493519990003');
        $this->mensaje($sin_nombre, 'lead', Carbon::now()->subDays(3), 'Hola');

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $this->pedidos_a_la_ia(), 'Con la ventana cerrada no hay nada que redactar.');

        $plantillas = $this->plantillas_de($espia, self::PLANTILLA_MANANA);
        $this->assertCount(2, $plantillas);
        $por_telefono = [];
        foreach ($plantillas as $p) {
            $por_telefono[$p['to']] = $p;
        }
        $this->assertSame(['Guillermo', '10:00'], $por_telefono['5493519990002']['variables']);
        $this->assertSame('es_AR', $por_telefono['5493519990002']['language_code']);
        $this->assertSame(['Cliente', '10:00'], $por_telefono['5493519990003']['variables']);

        $this->assertTrue((bool) $con_nombre->refresh()->recordatorio_manana_enviado);
        $this->assertTrue((bool) $sin_nombre->refresh()->recordatorio_manana_enviado);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $con_nombre->id,
            'sender'  => 'sistema',
            'content' => "Hola Guillermo! Te recuerdo que hoy a las 10:00 tenés agendada la demo de ComercioCity. 👋\n\n"
                . "Revisá el mail que te enviamos para tener todo listo antes de entrar.\n\n"
                . "Reservá unos 60 minutos sin interrupciones para aprovecharla al máximo.\n\n"
                . "¡Cualquier consulta estoy por acá! 😊",
        ]);
    }

    /**
     * (3) Cada forma en que la IA puede fallar o portarse mal degrada a la plantilla, y el
     *     recordatorio sale igual: saldo (402), error del proveedor, respuesta vacía, timeout, un
     *     link, una hora inventada, delatar que es una IA, y no decir la hora real.
     *
     * @return void
     */
    public function test_manana_cada_falla_de_la_ia_cae_a_la_plantilla(): void
    {
        $espia = $this->espiar_whatsapp();

        $respuestas = [
            'Anibal'   => Http::response(['error' => ['message' => 'Insufficient Balance']], 402),
            'Benito'   => Http::response(['error' => 'boom'], 500),
            'Carlos'   => Http::response(['content' => []], 200),
            'Dario'    => $this->respuesta_ia('Dario, hoy a las 10:00 es la demo: entrá desde https://comerciocity.com/demo y escribime por acá.'),
            'Esteban'  => $this->respuesta_ia('Esteban, te recuerdo que hoy a las 11:30 tenés la demo. Cualquier duda, escribime por acá.'),
            'Fabian'   => $this->respuesta_ia('Soy un asistente virtual y te recuerdo que hoy a las 10:00 tenés la demo. Escribime por acá si tenés dudas.'),
            'Gaston'   => $this->respuesta_ia('Gaston, te recuerdo que hoy tenés tu demo de ComercioCity. Cualquier duda, escribime por acá cuando quieras.'),
            'Hector'   => new ConnectionException('cURL error 28: timeout'),
        ];
        $this->simular_http($respuestas);

        $leads = [];
        $i = 10;
        foreach (array_keys($respuestas) as $nombre) {
            $lead = $this->crear_lead($nombre . ' Pérez', '54935199900' . $i++);
            $this->mensaje($lead, 'lead', Carbon::now()->subHours(2), 'Hola, ¿cómo va lo de la demo?');
            $leads[$nombre] = $lead;
        }

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'Ninguno de los textos de la IA era válido: no tiene que salir ninguno.');
        /* La IA se consultó para todos. El del timeout no cuenta: Laravel no registra en
         * Http::recorded() un pedido cuyo simulador tiró una excepción antes de contestar. */
        $this->assertCount(count($respuestas) - 1, $this->pedidos_a_la_ia());

        $plantillas = $this->plantillas_de($espia, self::PLANTILLA_MANANA);
        $this->assertCount(count($respuestas), $plantillas, 'Cada recordatorio tiene que haber salido igual, por la plantilla.');

        foreach ($leads as $nombre => $lead) {
            $this->assertTrue((bool) $lead->refresh()->recordatorio_manana_enviado, "{$nombre}: tiene que quedar marcado.");
        }
    }

    /**
     * (4) El escudo de verificación del lead NO frena a la IA (ver el docblock de
     *     LeadReminderService: el modelo lo prende solo al entrar a demo_agendada, así que respetarlo
     *     dejaría la función apagada para casi todos). Lo que sí la frena es el interruptor global, y
     *     la falta de clave del proveedor activo: en los dos casos sale la plantilla.
     *
     * @return void
     */
    public function test_manana_el_escudo_no_frena_a_la_ia_pero_el_interruptor_y_la_falta_de_clave_si(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->simular_http([
            'Anselmo'  => $this->respuesta_ia(str_replace('Guillermo', 'Anselmo', self::TEXTO_MANANA_OK)),
            'Zacarias' => $this->respuesta_ia(str_replace('Guillermo', 'Zacarias', self::TEXTO_MANANA_OK)),
        ]);

        /* Con el escudo prendido (lo prende solo el modelo al entrar a demo_agendada)... */
        $con_escudo = $this->crear_lead('Anselmo Gómez', '5493519990021');
        $this->assertTrue((bool) $con_escudo->requiere_verificacion_mensajes, 'Premisa del test: el modelo prendió el escudo solo.');
        $this->mensaje($con_escudo, 'lead', Carbon::now()->subHours(1), 'Hola');

        /* ...y con el escudo apagado a mano después de crearlo. */
        $sin_escudo = $this->crear_lead('Zacarias Gómez', '5493519990022');
        $sin_escudo->requiere_verificacion_mensajes = false;
        $sin_escudo->save();
        $this->mensaje($sin_escudo, 'lead', Carbon::now()->subHours(1), 'Hola');

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(2, $this->pedidos_a_la_ia(), 'Los dos se consultan: el escudo no cuenta.');
        $this->assertCount(2, $espia->textos);
        $this->assertCount(0, $this->plantillas_de($espia, self::PLANTILLA_MANANA));

        /* Interruptor global apagado. */
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_IA_ACTIVO, '0');
        $this->assertFalse(LeadDemoSettings::get_recordatorio_ia_activo());
        $apagado = $this->crear_lead('Bernardo Gómez', '5493519990023');
        $this->mensaje($apagado, 'lead', Carbon::now()->subHours(1), 'Hola');

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(2, $this->pedidos_a_la_ia(), 'Apagada la IA no hay pedidos nuevos.');
        $this->assertCount(1, $this->plantillas_de($espia, self::PLANTILLA_MANANA));

        /* Interruptor prendido otra vez, pero el proveedor activo no tiene clave. */
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_IA_ACTIVO, '1');
        $this->assertTrue(LeadDemoSettings::get_recordatorio_ia_activo());
        config(['services.deepseek.api_key' => '', 'services.anthropic.api_key' => '']);
        $sin_clave = $this->crear_lead('Cirilo Gómez', '5493519990024');
        $this->mensaje($sin_clave, 'lead', Carbon::now()->subHours(1), 'Hola');

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(2, $this->pedidos_a_la_ia(), 'Sin clave no se hace ningún pedido.');
        $this->assertCount(2, $this->plantillas_de($espia, self::PLANTILLA_MANANA));
        $this->assertTrue((bool) $sin_clave->refresh()->recordatorio_manana_enviado);

        /* El interruptor está en la lista de ajustes que ve el panel. */
        $this->assertArrayHasKey('recordatorio_ia_activo', LeadDemoSettings::to_array());
    }

    /**
     * (5) Si Meta rechaza el texto libre aunque la ventana figure abierta, el recordatorio no se
     *     pierde: sale la plantilla.
     *
     * @return void
     */
    public function test_manana_si_meta_rechaza_el_texto_libre_sale_la_plantilla(): void
    {
        $espia = $this->espiar_whatsapp();
        $espia->fallar_textos = true;
        $this->simular_http(['Guillermo' => $this->respuesta_ia(self::TEXTO_MANANA_OK)]);

        $lead = $this->crear_lead('Guillermo González', '5493519990031');
        $this->mensaje($lead, 'lead', Carbon::now()->subHours(1), 'Hola');

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'Se intentó el texto libre una vez.');
        $this->assertCount(1, $this->plantillas_de($espia, self::PLANTILLA_MANANA));
        $this->assertTrue((bool) $lead->refresh()->recordatorio_manana_enviado);
    }

    // ---------------------------------------------------------------------------------------------
    // Recordatorio de minutos antes (todos los leads van por la dinámica nueva desde el 14/10/2026)
    // ---------------------------------------------------------------------------------------------

    /**
     * (6) La demo ya arrancó (10:10) y son las 10:45, con silencio en el chat y la ventana abierta:
     *     sale el texto de la IA, y si la IA falla o se pasa de lista (una hora inventada), el texto
     *     fijo de siempre.
     *
     * @return void
     */
    public function test_pre_demo_con_la_ventana_abierta_sale_la_ia_y_si_falla_el_texto_fijo(): void
    {
        $this->fijar_ahora('2026-10-16 10:45:00');
        $espia = $this->espiar_whatsapp();

        $this->simular_http([
            'Guillermo' => $this->respuesta_ia(self::TEXTO_PRE_DEMO_OK),
            'Benito'    => Http::response(['error' => 'boom'], 500),
            'Cirilo'    => $this->respuesta_ia('Cirilo, la demo arranca a las 15:30, cualquier duda escribime por acá.'),
        ]);

        $ok = $this->crear_lead('Guillermo González', '5493519990041', '10:10', ['demo_mail_sent_at' => Carbon::now()->subMinutes(40)]);
        $this->mensaje($ok, 'lead', Carbon::now()->subMinutes(120), 'Hola');
        $falla = $this->crear_lead('Benito Pérez', '5493519990042', '10:10', ['demo_mail_sent_at' => Carbon::now()->subMinutes(40)]);
        $this->mensaje($falla, 'lead', Carbon::now()->subMinutes(120), 'Hola');
        $hora_inventada = $this->crear_lead('Cirilo Pérez', '5493519990043', '10:10');
        $this->mensaje($hora_inventada, 'lead', Carbon::now()->subMinutes(120), 'Hola');

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(0, $espia->plantillas, 'Con la ventana abierta no sale plantilla.');
        $this->assertCount(3, $this->pedidos_a_la_ia());

        $por_telefono = [];
        foreach ($espia->textos as $t) {
            $por_telefono[$t['to']] = $t['body'];
        }
        $this->assertCount(3, $por_telefono);
        $this->assertSame(self::TEXTO_PRE_DEMO_OK, $por_telefono['5493519990041']);

        /* Los dos que cayeron al respaldo reciben el texto fijo de la dinámica nueva. */
        foreach (['5493519990042', '5493519990043'] as $telefono) {
            $this->assertStringContainsString('escribime por acá', $por_telefono[$telefono]);
            $this->assertStringContainsString('Cualquier duda que te surja mientras recorrés el sistema', $por_telefono[$telefono]);
        }
        $this->assertStringContainsString('están en el mail que te mandamos', $por_telefono['5493519990042']);
        $this->assertStringContainsString('el link que te pasé más arriba', $por_telefono['5493519990043']);

        foreach ([$ok, $falla, $hora_inventada] as $lead) {
            $this->assertTrue((bool) $lead->refresh()->recordatorio_demo_enviado);
        }
    }

    /**
     * (7) Si ni el texto de la IA ni el texto fijo pueden salir con la ventana abierta, no se marca
     *     y se reintenta en el próximo tick —como siempre en esta dinámica— y NO se cae a la
     *     plantilla (que avisa a los admins en cada fallo).
     *
     * @return void
     */
    public function test_pre_demo_si_no_sale_ningun_texto_libre_no_se_marca_y_se_reintenta(): void
    {
        $this->fijar_ahora('2026-10-16 10:45:00');
        $espia = $this->espiar_whatsapp();
        $espia->fallar_textos = true;
        $this->simular_http(['Guillermo' => $this->respuesta_ia(self::TEXTO_PRE_DEMO_OK)]);

        $lead = $this->crear_lead('Guillermo González', '5493519990051', '10:10');
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(120), 'Hola');

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertFalse((bool) $lead->refresh()->recordatorio_demo_enviado);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->where('sender', 'sistema')->count());
        $this->assertCount(2, $espia->textos, 'Se intentó el de la IA y después el fijo.');
    }

    /**
     * (8) La rama "clásica" de SendDemoReminders es código muerto desde el 14/10/2026 (ningún lead la
     *     alcanza), pero se mantiene coherente por si vuelve: la misma regla de canal.
     *
     * @return void
     */
    public function test_pre_demo_la_rama_clasica_usa_la_misma_regla_de_canal(): void
    {
        $this->fijar_ahora('2026-10-16 10:45:00');
        $espia = $this->espiar_whatsapp();
        $this->simular_http(['Guillermo' => $this->respuesta_ia(self::TEXTO_PRE_DEMO_OK)]);

        $abierta = $this->crear_lead('Guillermo González', '5493519990061', '11:00');
        $this->mensaje($abierta, 'lead', Carbon::now()->subMinutes(120), 'Hola');
        $cerrada = $this->crear_lead('Ramiro Pérez', '5493519990062', '11:00');
        $this->mensaje($cerrada, 'lead', Carbon::now()->subDays(3), 'Hola');

        $comando = $this->app->make(SendDemoReminders::class);
        $metodo  = new ReflectionMethod(SendDemoReminders::class, 'send_reminder_message');
        $metodo->setAccessible(true);
        $metodo->invoke($comando, $abierta);
        $metodo->invoke($comando, $cerrada);

        $this->assertCount(1, $espia->textos);
        $this->assertSame(self::TEXTO_PRE_DEMO_OK, $espia->textos[0]['body']);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(self::PLANTILLA_PRE_DEMO, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Ramiro'], $espia->plantillas[0]['variables']);
    }

    // ---------------------------------------------------------------------------------------------
    // Proveedor, historial y validador
    // ---------------------------------------------------------------------------------------------

    /**
     * (9) El proveedor sale de LeadAiProveedor: si el activo es Claude, el pedido va a Anthropic, con
     *     su clave y su modelo y SIN la clave `thinking` (que es solo de DeepSeek).
     *
     * @return void
     */
    public function test_con_claude_como_proveedor_el_pedido_va_a_anthropic_sin_thinking(): void
    {
        config([
            'services.lead_ai.proveedor'  => 'anthropic',
            'services.deepseek.api_key'   => '',
            'services.anthropic.api_key'  => 'sk-ant-test',
            'services.anthropic.model'    => 'claude-modelo-de-prueba',
        ]);
        $espia = $this->espiar_whatsapp();
        $this->simular_http([
            'Guillermo' => $this->respuesta_ia(self::TEXTO_MANANA_OK),
            'Ignacio'   => $this->respuesta_ia(str_replace('Guillermo', 'Ignacio', self::TEXTO_MANANA_OK)),
        ]);

        $lead = $this->crear_lead('Guillermo González', '5493519990071');
        $this->mensaje($lead, 'lead', Carbon::now()->subHours(1), 'Hola');

        /* Ignacio escribió ayer a las 13:00 (hace 20 horas: la ventana sigue abierta) y hoy nadie
         * habló: el pedido tiene que decirlo, para que la IA salude. */
        $ayer = $this->crear_lead('Ignacio Gómez', '5493519990072');
        $this->mensaje($ayer, 'lead', Carbon::now()->subHours(20), 'Hola, ¿cuándo es la demo?');

        $this->artisan('leads:send-morning-demo-reminder')->assertExitCode(0);

        $pedidos = $this->pedidos_a_la_ia();
        $this->assertCount(2, $pedidos);
        $this->assertSame('https://api.anthropic.com/v1/messages', $pedidos[0]->url());
        $this->assertTrue($pedidos[0]->hasHeader('x-api-key', 'sk-ant-test'));
        $this->assertSame('claude-modelo-de-prueba', $pedidos[0]['model']);
        $this->assertArrayNotHasKey('thinking', $pedidos[0]->data());
        $this->assertCount(2, $espia->textos);

        $por_nombre = [];
        foreach ($pedidos as $pedido) {
            $contenido = (string) $pedido['messages'][0]['content'];
            $por_nombre[strpos($contenido, 'Primer nombre del lead: Ignacio') !== false ? 'Ignacio' : 'Guillermo'] = $contenido;
        }
        $this->assertStringContainsString('Hablaron hoy en este chat: sí', $por_nombre['Guillermo']);
        $this->assertStringContainsString('Hablaron hoy en este chat: no', $por_nombre['Ignacio']);
    }

    /**
     * (10) El historial extraído de build_user_content() es el mismo que lee el agente: el refactor
     *      no cambió qué ve la IA del chat.
     *
     * @return void
     */
    public function test_el_historial_extraido_sigue_siendo_el_que_lee_el_agente(): void
    {
        $lead = $this->crear_lead('Guillermo González', '5493519990081');
        $this->mensaje($lead, 'lead', Carbon::now()->subHours(3), 'Tengo una ferretería');
        $this->mensaje($lead, 'setter', Carbon::now()->subHours(2), 'Contame más', 'wamid.x');
        $borrado = $this->mensaje($lead, 'lead', Carbon::now()->subHours(1), 'NO_VA');
        $borrado->deleted_from_context = true;
        $borrado->save();

        $servicio  = app(LeadAiService::class);
        $historial = $servicio->historial_de_la_conversacion($lead->fresh());

        $this->assertStringContainsString('LEAD: Tengo una ferretería', $historial);
        $this->assertStringContainsString('SETTER: Contame más', $historial);
        $this->assertStringNotContainsString('NO_VA', $historial);

        $metodo = new ReflectionMethod(LeadAiService::class, 'build_user_content');
        $metodo->setAccessible(true);
        $contenido = $metodo->invoke($servicio, $lead->fresh(), false);

        $this->assertStringContainsString($historial, $contenido, 'build_user_content() tiene que seguir llevando el mismo historial.');
    }

    /**
     * (11) El validador acepta lo bueno y rechaza lo inventado, caso por caso. Es la defensa real:
     *      la salida de la IA está influida por texto que escribe el lead.
     *
     * @return void
     */
    public function test_el_validador_acepta_lo_bueno_y_rechaza_lo_inventado(): void
    {
        $composer = new LeadReminderComposer(app(LeadAiService::class));
        $diez     = ['h' => 10, 'i' => 0, 'hhmm' => '10:00'];
        $diez_y_media = ['h' => 10, 'i' => 30, 'hhmm' => '10:30'];

        $casos = [
            // [descripción, texto, hora, motivo esperado (null = válido)]
            ['la hora real en formato HH:MM', self::TEXTO_MANANA_OK, $diez, null],
            ['la hora real "a las 10 hs"', 'Dale Guillermo, hoy a las 10 hs arrancás la demo. Escribime por acá si te surge algo.', $diez, null],
            ['la hora real con hs pegado a HH:MM', 'Guillermo, a las 10:00 hs te espero en la demo. Si necesitás algo, escribime por acá.', $diez, null],
            ['la hora real con minutos distintos de cero', 'Guillermo, hoy a las 10:30 tenés la demo. Cualquier duda, escribime por acá.', $diez_y_media, null],
            ['minutos antes sin ninguna hora', self::TEXTO_PRE_DEMO_OK, null, null],
            ['"esta mañana" no es "mañana" como día', 'Che, esta mañana hablamos del stock; en unos minutos podés entrar a tu demo. Escribime por acá.', null, null],
            ['muy corto', 'Hola!', null, 'muy_corto'],
            ['muy largo', str_repeat('palabra ', 100), null, 'muy_largo'],
            ['link con https', 'Entrá a https://comerciocity.com cuando quieras, te espero en la demo por acá.', null, 'link'],
            ['dominio pelado', 'Entrá a comerciocity.com cuando quieras y escribime por acá ante cualquier duda.', null, 'link'],
            ['lista con guiones', "Te recuerdo:\n- Entrá al mail\n- Abrí el botón\nCualquier duda escribime por acá.", null, 'formato'],
            ['negrita', 'Te recuerdo que la *demo* ya está disponible. Escribime por acá si tenés dudas.', null, 'formato'],
            ['encabezado de charla', 'Claro, acá va el mensaje: Guillermo, en unos minutos arrancás la demo, escribime por acá.', null, 'encabezado'],
            ['delata que es un bot', 'Soy un bot y te aviso que en unos minutos arranca tu demo. Escribime por acá si hay dudas.', null, 'delata_ia'],
            ['recordatorio automático', 'Este es un recordatorio automático: en unos minutos arranca tu demo, escribime por acá.', null, 'delata_ia'],
            ['día de la semana', 'Guillermo, te espero el jueves para la demo. Cualquier duda, escribime por acá.', null, 'dia_de_la_semana'],
            ['fecha numérica', 'Guillermo, te espero el 20/10 para la demo. Cualquier duda, escribime por acá.', null, 'fecha'],
            ['"mañana" como día', 'Guillermo, si hoy no podés lo vemos mañana en la demo. Cualquier duda, escribime por acá.', null, 'manana_como_dia'],
            ['hora inventada en minutos antes', 'Guillermo, la demo arranca a las 15:30, cualquier duda escribime por acá.', null, 'hora_inventada'],
            ['plazo en minutos inventado', 'Guillermo, la demo arranca en 15 minutos, cualquier duda escribime por acá.', null, 'hora_inventada'],
            ['la hora real más otra inventada', 'Guillermo, hoy a las 10:00 o a las 11:30 tenés la demo; escribime por acá.', $diez, 'hora_inventada'],
            ['otra hora en lugar de la real', 'Guillermo, hoy a las 19:00 tenés la demo, escribime por acá cualquier duda.', $diez, 'falta_la_hora'],
            ['sin decir la hora real', 'Guillermo, hoy tenés tu demo de ComercioCity. Cualquier duda, escribime por acá.', $diez, 'falta_la_hora'],
            ['"a las 10" sin minutos cuando la demo es 10:30', 'Guillermo, hoy a las 10 tenés la demo. Cualquier duda, escribime por acá.', $diez_y_media, 'falta_la_hora'],
        ];

        foreach ($casos as $caso) {
            list($descripcion, $texto, $hora, $esperado) = $caso;
            $this->assertSame($esperado, $composer->validar_texto($texto, $hora), 'Caso: ' . $descripcion);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------------------------------

    /**
     * @param string $cuando Fecha y hora de Argentina.
     *
     * @return void
     */
    private function fijar_ahora(string $cuando): void
    {
        Carbon::setTestNow(Carbon::parse($cuando, 'America/Argentina/Buenos_Aires'));
    }

    /**
     * Respuesta de la API de la IA con un texto.
     *
     * @param string $texto
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function respuesta_ia(string $texto)
    {
        return Http::response(['content' => [['type' => 'text', 'text' => $texto]]], 200);
    }

    /**
     * Simula la red: a la IA le contesta según el primer nombre del lead que ve en el pedido (o tira
     * la excepción que se le indique); a cualquier otra cosa (los avisos por socket, por ejemplo) le
     * contesta que está todo bien.
     *
     * @param array<string, mixed> $respuestas_por_nombre Respuesta o Throwable por primer nombre.
     *
     * @return void
     */
    private function simular_http(array $respuestas_por_nombre): void
    {
        Http::fake(function ($request) use ($respuestas_por_nombre) {
            $url = $request->url();
            if (strpos($url, 'api.deepseek.com') === false && strpos($url, 'api.anthropic.com') === false) {
                return Http::response(['ok' => true], 200);
            }

            $contenido = (string) ($request['messages'][0]['content'] ?? '');
            foreach ($respuestas_por_nombre as $nombre => $respuesta) {
                if (strpos($contenido, 'Primer nombre del lead: ' . $nombre . "\n") !== false) {
                    if ($respuesta instanceof \Throwable) {
                        throw $respuesta;
                    }

                    return $respuesta;
                }
            }

            return Http::response(['error' => 'sin respuesta simulada para este lead'], 500);
        });
    }

    /**
     * Los pedidos que salieron hacia la IA (DeepSeek o Claude), en orden.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_a_la_ia(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            $url = $par[0]->url();
            if (strpos($url, 'api.deepseek.com') !== false || strpos($url, 'api.anthropic.com') !== false) {
                $pedidos[] = $par[0];
            }
        }

        return $pedidos;
    }

    /**
     * Las plantillas enviadas con un nombre dado (el resumen a admins y otras no cuentan).
     *
     * @param object $espia
     * @param string $nombre
     *
     * @return array<int, array<string, mixed>>
     */
    private function plantillas_de($espia, string $nombre): array
    {
        return array_values(array_filter($espia->plantillas, function ($p) use ($nombre) {
            return $p['template_name'] === $nombre;
        }));
    }

    /**
     * @return WhatsappSendService Espía que anota todo lo que se le manda por WhatsApp.
     */
    private function espiar_whatsapp(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            /** @var array<int, array<string, mixed>> */
            public $plantillas = [];

            /** @var bool Si es true, send_text devuelve null (Meta lo rechazó). */
            public $fallar_textos = false;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return $this->fallar_textos ? null : 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables, 'language_code' => $language_code];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Lead en demo_agendada hoy, con las automatizaciones prendidas. (Desde el 14/10/2026 todos los
     * leads usan la dinámica nueva: el hook `saving` del modelo lo fuerza.)
     *
     * @param string               $nombre
     * @param string               $telefono
     * @param string               $inicio
     * @param array<string, mixed> $extra Atributos a pisar.
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, string $telefono, string $inicio = '10:00', array $extra = []): Lead
    {
        $lead                                = new Lead();
        $lead->uuid                          = (string) Str::uuid();
        $lead->contact_name                  = $nombre;
        $lead->company_name                  = 'Ferretería de prueba';
        $lead->business_type                 = 'ferretería';
        $lead->phone                         = $telefono;
        $lead->status                        = 'demo_agendada';
        $lead->demo_id                       = 1;
        $lead->demo_date                     = '2026-10-16';
        $lead->demo_start_time               = $inicio;
        $lead->demo_end_time                 = '16:10';
        $lead->demo_flexible                 = true;
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_recordatorio_demo        = true;
        $lead->recordatorio_demo_enviado     = false;
        $lead->recordatorio_manana_enviado   = false;
        $lead->tiene_sugerencia_pendiente    = false;
        foreach ($extra as $atributo => $valor) {
            $lead->{$atributo} = $valor;
        }
        $lead->save();

        return $lead->refresh();
    }

    /**
     * @param Lead        $lead
     * @param string      $sender
     * @param Carbon      $cuando
     * @param string      $contenido
     * @param string|null $whatsapp_message_id Si salió por WhatsApp, su id.
     *
     * @return LeadMessage
     */
    private function mensaje(Lead $lead, string $sender, Carbon $cuando, string $contenido = 'Mensaje', ?string $whatsapp_message_id = null): LeadMessage
    {
        $mensaje                      = new LeadMessage();
        $mensaje->lead_id             = $lead->id;
        $mensaje->sender              = $sender;
        $mensaje->status              = 'enviado';
        $mensaje->is_followup         = false;
        $mensaje->content             = $contenido;
        $mensaje->whatsapp_message_id = $whatsapp_message_id;
        $mensaje->created_at          = $cuando;
        $mensaje->updated_at          = $cuando;
        $mensaje->save();

        return $mensaje;
    }
}
