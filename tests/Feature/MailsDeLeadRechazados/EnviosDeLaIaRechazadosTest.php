<?php

namespace Tests\Feature\MailsDeLeadRechazados;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Mail\LeadDemoAccesoMail;
use App\Mail\LeadDemoMail;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoDirectaService;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Los tres envíos de mail a un lead que dispara la IA (`LeadAiService::apply_parsed_response()`, que corre
 * al aprobar un paquete de acciones desde el panel), cuando el servidor de correo RECHAZA la casilla.
 *
 * Son los puntos 7, 8 y 9 de la misión mails-a-leads-rechazados-por-smtp (6/10/2026), con cinco
 * disparadores distintos:
 *
 *   7. Mail 1 (dinámica vieja), FORZADO por el admin al aprobar (`enviar_mail_demo`).
 *   8. Mail 1 (dinámica vieja), REENVIADO a pedido del lead (`reenviar_mail_demo`).
 *   9. Carta de acceso (dinámica nueva), por tres caminos:
 *        9a. al ASIGNAR la demo directa;
 *        9e. cuando el EMAIL llega después (`guardar_email`);
 *        9r. a pedido del lead (`reenviar_mail_demo`).
 *
 * El defecto, distinto al del controlador: acá un `catch` ya existía pero era SOLO un `Log::error`. Con un
 * 550 en el RCPT TO SwiftMailer no tira, así que `demo_mail_sent_at` quedaba con fecha (y el evento
 * "Mail de demo enviado" en el mensaje) para un mail que nunca salió. Y si tiraba, la ficha tampoco se
 * enteraba: quedaba en "Pendiente" sin ninguna explicación.
 *
 * 🔴 **Lo que se rompe río abajo con un rechazo anotado como enviado**: `SendDemoReminders` le escribe al
 * lead por WhatsApp según `! empty(demo_mail_sent_at)` ("los accesos a tu demo están en el mail que te
 * mandamos"), y el contexto que se le arma al agente dice "Mail 1 enviado: si". Ver
 * `RecordatorioTrasUnMailRechazadoTest`.
 *
 * Por disparador, cinco casos (corren con un data provider; el armado de cada disparador es lo que cambia):
 *   (a) el servidor RECHAZA: `demo_mail_sent_at` sin cambiar, `demo_mail_last_error` con el motivo (sin la
 *       casilla entera), el evento de FALLO en `admin_notifications` del mensaje y NO el de éxito;
 *   (b) el control con un servidor que ACEPTA: sale, con fecha, sin error y con el evento de éxito;
 *   (c) el control con `Mail::fake()`: un mail de la misma clase al email del lead (fija "no cambia lo que
 *       se manda ni a quién");
 *   (d) un envío exitoso DESPUÉS de uno rechazado limpia el error;
 *   (e) una falla que NO es un rechazo (el SMTP no responde) también queda anotada: hoy la ficha queda en
 *       "Pendiente" sin explicación.
 */
class EnviosDeLaIaRechazadosTest extends BaseDeMailsDeLead
{
    /** El "hoy" de todos los casos, un martes a media mañana (el de `DemoDirectaTest`). */
    const AHORA = '2026-09-08 10:00:00';

    /** Un envío anterior que sí salió, de hace dos horas: pasa de largo la guardia anti-ráfaga de 5 minutos. */
    const ENVIADO_ANTES = '2026-09-08 08:00:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));

        // Nada sale a ningún lado: el flujo de la IA toca WhatsApp, Pusher y Calendar según el caso.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Los cinco disparadores de mail de la IA, para el data provider.
     *
     * @return array<string, array<int, string>>
     */
    public static function disparadores(): array
    {
        return [
            'punto 7: Mail 1 forzado por el admin'          => ['mail_1_forzado'],
            'punto 8: Mail 1 reenviado a pedido del lead'   => ['mail_1_reenviado'],
            'punto 9a: carta al asignar la demo'            => ['carta_por_asignacion'],
            'punto 9e: carta cuando llega el email nuevo'   => ['carta_por_email_nuevo'],
            'punto 9r: carta reenviada a pedido del lead'   => ['carta_por_reenvio'],
        ];
    }

    /**
     * Los disparadores donde el paquete de acciones trae `guardar_email`: el email llega después y el mail sale por
     * ese motivo. Son los únicos donde el resumen de "acciones ejecutadas" de la burbuja dice
     * "Enviar mail de acceso a la demo a <casilla>" (`LeadMessage::build_actions_summary()` agrega esa línea solo con
     * `guardar_email`), así que son los únicos donde se puede probar que se corrige.
     *
     * @return array<string, array<int, string>>
     */
    public static function disparadores_con_email_nuevo(): array
    {
        return [
            'punto 7: Mail 1 cuando llega el email nuevo'   => ['mail_1_por_email_nuevo'],
            'punto 9e: carta cuando llega el email nuevo'   => ['carta_por_email_nuevo'],
        ];
    }

    /**
     * Lead de la dinámica nueva SIN demo asignada todavía: el que recibe la carta al asignarse la demo directa.
     *
     * 🔴 La dinámica se fija DESPUÉS del primer `save()`: el hook `creating` del modelo estampa la dinámica
     * por defecto al nacer.
     *
     * @return Lead
     */
    private function crear_lead_nuevo_sin_demo(): Lead
    {
        $lead = $this->crear_lead(['status' => 'calificado']);

        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $this->con_un_mensaje_entrante($lead->refresh());
    }

    /**
     * Arma el caso: el lead que lo dispara, el paquete de acciones que aprueba el panel, la clase de mail
     * que tiene que salir y los eventos que quedan en el mensaje.
     *
     * @param string $clave Una de las claves de `disparadores()`.
     *
     * @return array{lead: Lead, pendientes: array<string, mixed>, final_actions: array<string, mixed>, reintento: array<string, mixed>, mailable: string, enviado_antes: string|null, evento_ok: string, evento_fallo: string, evento_falla: string}
     */
    private function armar(string $clave): array
    {
        switch ($clave) {
            case 'mail_1_forzado':
                // Dinámica vieja, ya agendada, con el mail todavía sin salir: el admin tilda "enviar Mail 1".
                $lead = $this->con_un_mensaje_entrante($this->crear_lead_de_la_dinamica_vieja());

                return [
                    'lead'          => $lead,
                    'pendientes'    => [],
                    'final_actions' => $this->final_actions(['enviar_mail_demo' => true]),
                    'reintento'     => $this->final_actions(['enviar_mail_demo' => true]),
                    'mailable'      => LeadDemoMail::class,
                    'enviado_antes' => null,
                    // Con el mail forzado y sin email nuevo, el evento de éxito que ya existe se llama "reagendado".
                    'evento_ok'     => 'Mail de demo reenviado (reagendado)',
                    'evento_fallo'  => 'Mail de demo no enviado: el servidor de correo rechazó la casilla',
                    'evento_falla'  => 'Mail de demo no enviado: falló el envío',
                ];

            case 'mail_1_reenviado':
                // Dinámica vieja con el Mail 1 ya enviado hace horas: el lead pide que se lo reenvíen.
                $lead = $this->con_un_mensaje_entrante($this->crear_lead_de_la_dinamica_vieja([
                    'demo_mail_sent_at' => self::ENVIADO_ANTES,
                ]));

                return [
                    'lead'          => $lead,
                    'pendientes'    => [],
                    'final_actions' => $this->final_actions(['reenviar_mail_demo' => true]),
                    'reintento'     => $this->final_actions(['reenviar_mail_demo' => true]),
                    'mailable'      => LeadDemoMail::class,
                    'enviado_antes' => self::ENVIADO_ANTES,
                    'evento_ok'     => 'Mail de demo reenviado (pedido del lead)',
                    'evento_fallo'  => 'Mail de demo no enviado: el servidor de correo rechazó la casilla',
                    'evento_falla'  => 'Mail de demo no enviado: falló el envío',
                ];

            case 'carta_por_asignacion':
                // Dinámica nueva sin demo: al aprobar `{ahora: true}` se asigna la instancia y sale la carta.
                $demo = $this->crear_demo(1);
                $lead = $this->crear_lead_nuevo_sin_demo();

                return [
                    'lead'          => $lead,
                    'pendientes'    => ['agendar_demo' => [
                        'ahora'               => true,
                        'demo_id_previsto'    => $demo->id,
                        'url_tienda_prevista' => DemoDirectaService::url_tienda($demo),
                    ]],
                    'final_actions' => $this->final_actions(['agendar_demo' => ['ahora' => true], 'enviar_mail_demo' => true]),
                    // La demo ya quedó asignada en el primer intento: el segundo va por el reenvío a pedido.
                    'reintento'     => $this->final_actions(['reenviar_mail_demo' => true]),
                    'mailable'      => LeadDemoAccesoMail::class,
                    'enviado_antes' => null,
                    'evento_ok'     => 'Carta de acceso enviada',
                    'evento_fallo'  => 'Carta de acceso no enviada: el servidor de correo rechazó la casilla',
                    'evento_falla'  => 'Carta de acceso no enviada: falló el envío',
                ];

            case 'carta_por_email_nuevo':
                // Dinámica nueva con la demo ya asignada y SIN email: el lead lo pasa después y llega como `guardar_email`.
                $lead = $this->con_un_mensaje_entrante($this->crear_lead_de_la_dinamica_nueva(['email' => null]));

                return [
                    'lead'          => $lead,
                    'pendientes'    => ['guardar_email' => self::CASILLA],
                    'final_actions' => $this->final_actions(['guardar_email' => self::CASILLA, 'enviar_mail_demo' => true]),
                    // El email ya quedó guardado en el primer intento: el segundo va por el reenvío a pedido.
                    'reintento'     => $this->final_actions(['reenviar_mail_demo' => true]),
                    'mailable'      => LeadDemoAccesoMail::class,
                    'enviado_antes' => null,
                    'evento_ok'     => 'Carta de acceso enviada',
                    'evento_fallo'  => 'Carta de acceso no enviada: el servidor de correo rechazó la casilla',
                    'evento_falla'  => 'Carta de acceso no enviada: falló el envío',
                ];

            case 'mail_1_por_email_nuevo':
                // Dinámica vieja, ya agendada y SIN email: el lead lo pasa después y llega como `guardar_email`. Es el único
                // camino del Mail 1 donde el paquete trae `guardar_email`, y por eso el único donde el resumen de acciones
                // de la burbuja dice "Enviar mail de acceso a la demo a <casilla>" (ver los tests del resumen).
                $lead = $this->con_un_mensaje_entrante($this->crear_lead_de_la_dinamica_vieja(['email' => null]));

                return [
                    'lead'          => $lead,
                    'pendientes'    => ['guardar_email' => self::CASILLA],
                    'final_actions' => $this->final_actions(['guardar_email' => self::CASILLA, 'enviar_mail_demo' => true]),
                    'reintento'     => $this->final_actions(['enviar_mail_demo' => true]),
                    'mailable'      => LeadDemoMail::class,
                    'enviado_antes' => null,
                    'evento_ok'     => 'Mail de demo enviado',
                    'evento_fallo'  => 'Mail de demo no enviado: el servidor de correo rechazó la casilla',
                    'evento_falla'  => 'Mail de demo no enviado: falló el envío',
                ];

            default:
                // Dinámica nueva con la carta ya enviada hace horas: el lead pide que se la reenvíen.
                $lead = $this->con_un_mensaje_entrante($this->crear_lead_de_la_dinamica_nueva([
                    'demo_mail_sent_at' => self::ENVIADO_ANTES,
                ]));

                return [
                    'lead'          => $lead,
                    'pendientes'    => [],
                    'final_actions' => $this->final_actions(['reenviar_mail_demo' => true]),
                    'reintento'     => $this->final_actions(['reenviar_mail_demo' => true]),
                    'mailable'      => LeadDemoAccesoMail::class,
                    'enviado_antes' => self::ENVIADO_ANTES,
                    'evento_ok'     => 'Carta de acceso reenviada (pedido del lead)',
                    'evento_fallo'  => 'Carta de acceso no enviada: el servidor de correo rechazó la casilla',
                    'evento_falla'  => 'Carta de acceso no enviada: falló el envío',
                ];
        }
    }

    /**
     * Los eventos que quedaron anotados en `admin_notifications` del mensaje. Son un dato del mensaje (API y base):
     * la burbuja del panel NO dibuja los que tienen `admins` vacío (`admin_notifications_parsed` los filtra), ni los
     * de "enviado" de siempre ni los de "no enviado" de esta misión.
     *
     * @param LeadMessage $mensaje
     *
     * @return array<int, string>
     */
    private function eventos_de(LeadMessage $mensaje): array
    {
        $eventos = [];

        foreach ((array) $mensaje->admin_notifications as $anotacion) {
            $eventos[] = (string) $anotacion['evento'];
        }

        return $eventos;
    }

    /**
     * Ningún evento de "no enviado" en el mensaje: el envío salió.
     *
     * @param array<int, string> $eventos
     *
     * @return void
     */
    private function afirmar_que_no_hay_evento_de_fallo(array $eventos): void
    {
        foreach ($eventos as $evento) {
            $this->assertStringNotContainsString('no enviado', $evento, 'El mail salió: no puede haber un evento de fallo.');
        }
    }

    /**
     * (a) El centro del defecto: el servidor contesta 550, `send()` vuelve normal, y el envío NO puede quedar
     * como hecho. `demo_mail_sent_at` queda como estaba (nulo si nunca salió; igual al anterior si ya había
     * salido uno), el motivo —sin la casilla entera— queda en `demo_mail_last_error` (la tarjeta del panel pasa
     * a "Fallido") y el mensaje anota el evento de FALLO y NO el de éxito.
     *
     * @dataProvider disparadores
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_rechazo_del_servidor_no_queda_como_enviado(string $clave): void
    {
        $caso = $this->armar($clave);

        $this->el_servidor_rechaza();

        $mensaje = $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);

        // Primero lo que importa: la ficha. Si el servidor dijo 550 y la ficha tiene fecha de envío, el sistema miente.
        $this->afirmar_que_el_envio_no_cuenta_como_hecho($caso['lead'], 'demo_mail_sent_at', 'demo_mail_last_error', $caso['enviado_antes']);

        $eventos = $this->eventos_de($mensaje);
        $this->assertContains($caso['evento_fallo'], $eventos, 'El mensaje tiene que dejar anotado que el mail no salió.');
        $this->assertNotContains($caso['evento_ok'], $eventos, 'No puede decir "enviado" un mail que el servidor rechazó.');

        // El evento de fallo no es una notificación a admins: `admins` vacío, como el de éxito.
        foreach ((array) $mensaje->admin_notifications as $anotacion) {
            if ($anotacion['evento'] === $caso['evento_fallo']) {
                $this->assertSame([], $anotacion['admins']);
            }
        }
    }

    /**
     * (a) Un rechazo no rompe el flujo de la IA ni cambia nada más del lead: el paquete se aplica igual
     * (las acciones pendientes se consumen) y el lead NO se manda a intervención humana. Mandar un mail
     * rechazado a un humano sería una regla de negocio nueva, y no se pidió.
     *
     * @dataProvider disparadores
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_rechazo_no_rompe_el_flujo_ni_escala_a_un_humano(string $clave): void
    {
        $caso = $this->armar($clave);

        $this->el_servidor_rechaza();

        $mensaje = $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);

        $this->assertNull($mensaje->pending_actions, 'Las acciones se aplicaron: el rechazo del mail no frena el resto del paquete.');
        $this->assertFalse((bool) $caso['lead']->fresh()->requiere_intervencion_humana, 'Un mail rechazado no es motivo de intervención humana.');
    }

    /**
     * (a) El rechazo de la carta por asignación no deshace la asignación: la demo SÍ quedó asignada al lead
     * (es lo que ya había pasado cuando se intentó el mail), así que el reintento por reenvío tiene a dónde
     * apuntar. Lo único que no ocurrió es el mail.
     *
     * @return void
     */
    public function test_el_rechazo_de_la_carta_no_deshace_la_asignacion_de_la_demo(): void
    {
        $caso = $this->armar('carta_por_asignacion');

        $this->el_servidor_rechaza();

        $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);

        $fresco = $caso['lead']->fresh();
        $this->assertNotNull($fresco->demo_id, 'La demo quedó asignada: el mail es lo único que no salió.');
        $this->assertSame('demo_agendada', $fresco->status);
        $this->assertNull($fresco->demo_mail_sent_at);
    }

    /**
     * (b) El control: con un servidor que ACEPTA a todos, el mismo camino sale bien: fecha de envío, sin
     * error y con el evento de éxito (y ninguno de fallo). Sin esto, un arreglo que marcara todo como
     * rechazado pasaría el test de arriba.
     *
     * @dataProvider disparadores
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_sale_y_queda_enviado(string $clave): void
    {
        $caso = $this->armar($clave);

        $this->el_servidor_acepta();

        $mensaje = $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);

        $this->afirmar_que_el_envio_cuenta_como_hecho($caso['lead'], 'demo_mail_sent_at', 'demo_mail_last_error');

        $eventos = $this->eventos_de($mensaje);
        $this->assertContains($caso['evento_ok'], $eventos);
        $this->afirmar_que_no_hay_evento_de_fallo($eventos);

        if ($caso['enviado_antes'] !== null) {
            $this->assertNotSame(
                $caso['enviado_antes'],
                $caso['lead']->fresh()->demo_mail_sent_at->format('Y-m-d H:i:s'),
                'Un reenvío que salió bien actualiza la fecha.'
            );
        }
    }

    /**
     * (c) El control de "qué sale y a quién": con `Mail::fake()` sale exactamente UN mail, de la clase de ese
     * disparador, al email del lead, y ningún otro. Fija que el arreglo no cambia lo que se manda ni a
     * quién, y que bajo el falso (que responde `failures()` vacío) el helper no tira.
     *
     * @dataProvider disparadores
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_sale_exactamente_un_mail_de_la_clase_esperada_al_email_del_lead(string $clave): void
    {
        $caso = $this->armar($clave);

        Mail::fake();

        $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);

        Mail::assertSent($caso['mailable'], 1);
        Mail::assertSent($caso['mailable'], function (Mailable $mail) {
            return $mail->hasTo(self::CASILLA) && count($mail->to) === 1;
        });
        $this->assertCount(1, Mail::sent(Mailable::class), 'No sale ningún otro mail además del esperado.');

        $this->afirmar_que_el_envio_cuenta_como_hecho($caso['lead'], 'demo_mail_sent_at', 'demo_mail_last_error');
    }

    /**
     * (d) Un envío que sale bien DESPUÉS de uno rechazado limpia el error: sin esto la tarjeta del panel
     * quedaría en "Fallido" para siempre, porque ese estado tiene prioridad sobre la fecha de envío. Y el
     * éxito de la IA nunca limpiaba `demo_mail_last_error` (antes la IA no dejaba nunca un error ahí).
     *
     * @dataProvider disparadores
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_envio_exitoso_despues_de_un_rechazo_limpia_el_error(string $clave): void
    {
        $caso = $this->armar($clave);

        $this->el_servidor_rechaza();

        $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);
        $this->afirmar_que_el_envio_no_cuenta_como_hecho($caso['lead'], 'demo_mail_sent_at', 'demo_mail_last_error', $caso['enviado_antes']);

        // Se corrige la casilla en la ficha y esta vez el servidor la acepta (con el falso, que no rechaza nada).
        Mail::fake();

        $segundo = $this->aprobar($caso['lead'], [], $caso['reintento']);

        $this->afirmar_que_el_envio_cuenta_como_hecho($caso['lead'], 'demo_mail_sent_at', 'demo_mail_last_error');
        $this->afirmar_que_no_hay_evento_de_fallo($this->eventos_de($segundo));
        Mail::assertSent($caso['mailable'], 1);
    }

    /**
     * (e) Una falla que NO es un rechazo (el servidor no responde, la conexión se cae) también queda anotada:
     * hoy el `catch` de la IA era solo un `Log::error` y la ficha quedaba en "Pendiente" sin ninguna
     * explicación. Ahora `demo_mail_last_error` lleva el mensaje de la falla, sin fecha de envío, y el
     * mensaje deja el evento "falló el envío" (distinto al del rechazo, para que se vea la diferencia).
     *
     * @dataProvider disparadores
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_una_falla_que_no_es_un_rechazo_tambien_queda_anotada(string $clave): void
    {
        $caso = $this->armar($clave);

        // La conexión con el servidor de correo se cae antes de mandar nada.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection could not be established with host smtp.ejemplo.test'));

        $mensaje = $this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']);

        $fresco = $caso['lead']->fresh();

        if ($caso['enviado_antes'] === null) {
            $this->assertNull($fresco->demo_mail_sent_at, 'No salió: no puede tener fecha de envío.');
        } else {
            $this->assertSame($caso['enviado_antes'], $fresco->demo_mail_sent_at->format('Y-m-d H:i:s'));
        }

        $this->assertSame('Connection could not be established with host smtp.ejemplo.test', $fresco->demo_mail_last_error);

        $eventos = $this->eventos_de($mensaje);
        $this->assertContains($caso['evento_falla'], $eventos);
        $this->assertNotContains($caso['evento_ok'], $eventos);
        $this->assertNotContains($caso['evento_fallo'], $eventos, 'No es un rechazo del servidor: el evento dice "falló el envío".');
    }

    /**
     * 🔴 El `catch` de la IA NO puede romper el flujo, y anotar el fallo tampoco: `anotar_el_mail_que_no_salio()`
     * tiene su propio `try/catch` que loguea y traga. Hoy un `catch` de estos no puede tirar nada (es solo un
     * log), y no puede empezar a poder: si anotar el fallo en la ficha tirara —la base caída justo ahí, una
     * columna que falta—, el error subiría hasta `apply_pending_actions()` y el admin vería un 500 por un mail
     * que ni siquiera era lo importante.
     *
     * Se prueba por reflexión porque el método es privado y no hay otra forma de hacer que el `update()` falle
     * justo ahí sin tocar la base.
     *
     * @return void
     */
    public function test_anotar_el_fallo_no_puede_romper_el_flujo_aunque_la_ficha_no_se_pueda_escribir(): void
    {
        // Un lead cuya ficha NO se puede escribir: el `update()` tira.
        $lead = new class extends Lead {
            /**
             * Simula la base caída: el `update()` de la ficha tira, que es justo lo que el método bajo prueba tiene que aguantar.
             *
             * @param array<string, mixed> $attributes
             * @param array<string, mixed> $options
             *
             * @return bool Nunca llega a devolver: siempre tira.
             */
            public function update(array $attributes = [], array $options = [])
            {
                throw new \RuntimeException('la base se cayó');
            }
        };
        $lead->id = 987;

        $metodo = new \ReflectionMethod(LeadAiService::class, 'anotar_el_mail_que_no_salio');
        $metodo->setAccessible(true);

        $eventos   = [];
        $argumentos = [$lead, new MailRechazadoPorElServidorException([self::CASILLA]), 'Mail de demo no enviado', &$eventos];

        // No tira, aunque la ficha no se pueda escribir.
        $metodo->invokeArgs(new LeadAiService(), $argumentos);

        // Y el evento igual quedó anotado en el mensaje (se arma en memoria, antes de tocar la base).
        $this->assertSame(
            [['evento' => 'Mail de demo no enviado: el servidor de correo rechazó la casilla', 'admins' => []]],
            $eventos
        );
    }

    /**
     * Las líneas del resumen de "acciones ejecutadas" que quedaron guardadas en el mensaje.
     *
     * @param LeadMessage $mensaje
     *
     * @return array<int, string>
     */
    private function resumen_de(LeadMessage $mensaje): array
    {
        return array_map('strval', (array) $mensaje->fresh()->applied_actions_summary);
    }

    /**
     * (f) 🔴 El resumen de "acciones ejecutadas" de la burbuja NO puede decir que se manda un mail que el servidor
     * rechazó. `LeadMessage::build_actions_summary()` lo arma con el paquete de acciones ANTES de mandar nada y
     * `apply_parsed_response()` lo guarda con el mensaje: si el paquete trae `guardar_email` (el camino normal de la
     * dinámica nueva) dice "Enviar mail de acceso a la demo a <casilla>" y la burbuja lo muestra con su tilde. Sin
     * la corrección, con un 550 la tarjeta de la ficha decía "Fallido" y la conversación decía que se mandó (y
     * repetía la casilla entera): el mismo "enviado" falso en otra pantalla. Lo encontró el chequeo independiente;
     * el relevamiento de "qué hace el resto del flujo con un enviado" había mirado las marcas de la ficha y no esto.
     *
     * La línea se REEMPLAZA (no se borra), así que se ve que se intentó y que no salió, y la nueva no lleva la casilla.
     *
     * Solo corre con los disparadores donde esa línea EXISTE: los que traen `guardar_email` en el paquete. En los otros
     * el resumen no la tiene y el test no podría ponerse rojo nunca (PHPUnit lo marcaría "risky" por no afirmar nada).
     *
     * @dataProvider disparadores_con_email_nuevo
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_el_resumen_de_acciones_no_dice_que_se_mando_un_mail_que_el_servidor_rechazo(string $clave): void
    {
        $caso = $this->armar($clave);

        $this->el_servidor_rechaza();

        $resumen = $this->resumen_de($this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']));

        // Lo que no puede quedar: la línea que dice que se manda el mail, ni la casilla entera repetida en la conversación.
        $lineas_que_mienten = array_values(array_filter($resumen, function ($linea) {
            return strpos($linea, LeadAiService::PREFIJO_RESUMEN_MAIL_DE_ACCESO) !== false || strpos($linea, self::CASILLA) !== false;
        }));
        $this->assertSame([], $lineas_que_mienten, 'El mail no salió: el resumen no puede decir que se manda ni repetir la casilla.');

        // La línea se reemplazó (no se borró): se ve que se intentó y que el servidor rechazó la casilla.
        $this->assertContains('No se pudo enviar el mail de acceso a la demo (el servidor de correo rechazó la casilla)', $resumen);
    }

    /**
     * (f, control) Con un servidor que ACEPTA, el resumen queda como siempre: dice que se manda el mail de acceso a
     * esa casilla. Sin esto, un arreglo que reemplazara la línea siempre pasaría el test de arriba.
     *
     * @dataProvider disparadores_con_email_nuevo
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_el_resumen_conserva_la_linea_del_mail_de_acceso(string $clave): void
    {
        $caso = $this->armar($clave);

        $this->el_servidor_acepta();

        $resumen = $this->resumen_de($this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']));

        $this->assertContains(LeadAiService::PREFIJO_RESUMEN_MAIL_DE_ACCESO . self::CASILLA, $resumen);
        $this->assertNotContains('No se pudo enviar el mail de acceso a la demo (el servidor de correo rechazó la casilla)', $resumen);
    }

    /**
     * (f) Una falla que NO es un rechazo (la conexión se cae) también corrige el resumen: el mail tampoco salió.
     * La línea nueva no dice "el servidor rechazó la casilla" porque no fue eso.
     *
     * @dataProvider disparadores_con_email_nuevo
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_una_falla_que_no_es_un_rechazo_tambien_corrige_el_resumen(string $clave): void
    {
        $caso = $this->armar($clave);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection could not be established with host smtp.ejemplo.test'));

        $resumen = $this->resumen_de($this->aprobar($caso['lead'], $caso['pendientes'], $caso['final_actions']));

        $this->assertContains('No se pudo enviar el mail de acceso a la demo', $resumen);
        $this->assertNotContains('No se pudo enviar el mail de acceso a la demo (el servidor de correo rechazó la casilla)', $resumen, 'No fue un rechazo del servidor.');

        $lineas_que_mienten = array_values(array_filter($resumen, function ($linea) {
            return strpos($linea, LeadAiService::PREFIJO_RESUMEN_MAIL_DE_ACCESO) !== false;
        }));
        $this->assertSame([], $lineas_que_mienten);
    }

    /**
     * (f) El acople por texto entre dos archivos, fijado: la corrección busca la línea por su comienzo
     * (`LeadAiService::PREFIJO_RESUMEN_MAIL_DE_ACCESO`) y la arma `LeadMessage::build_actions_summary()`. Si alguien
     * cambia el texto en el modelo y no en el servicio, este test se pone rojo; sin él, la corrección dejaría de
     * encontrar la línea y la burbuja volvería a decir en silencio que se mandó un mail rechazado.
     *
     * @return void
     */
    public function test_la_linea_del_resumen_que_se_corrige_es_la_que_arma_el_modelo(): void
    {
        $resumen = LeadMessage::build_actions_summary(['guardar_email' => self::CASILLA], null);

        $this->assertContains(
            LeadAiService::PREFIJO_RESUMEN_MAIL_DE_ACCESO . self::CASILLA,
            $resumen,
            'LeadMessage dejó de armar la línea con el comienzo que busca LeadAiService.'
        );
    }
}
