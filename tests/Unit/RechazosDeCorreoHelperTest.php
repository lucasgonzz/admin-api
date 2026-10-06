<?php

namespace Tests\Unit;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FallaSiElServidorSmtpNoArranca;
use Tests\Fakes\ServidorSmtpFake;
use Tests\TestCase;

/**
 * `RechazosDeCorreoHelper`: leer qué casillas rechazó el servidor de correo en el último envío, y
 * convertir ese rechazo en una excepción para los envíos que no tienen donde anotarlo de otra forma.
 *
 * El defecto de fondo (misión mails-a-leads-rechazados-por-smtp, 6/10/2026): cuando el servidor SMTP
 * contesta 550 en el RCPT TO, SwiftMailer NO tira excepción. `send()` vuelve normal y las casillas
 * rechazadas quedan en `failures()` del mailer. Quien solo atrapa la excepción da por enviado un mail
 * que nunca salió. Los envíos a leads usan el mailer POR DEFECTO (`Mail::to()`), no el `admin`, y hasta
 * esta misión el helper solo sabía leer un mailer con nombre.
 *
 * Dos clases de test, y las dos hacen falta:
 *   - con un servidor SMTP de verdad (`ServidorSmtpFake`, un proceso aparte que contesta 550): es lo
 *     único que prueba que SwiftMailer se porta así y que el helper lo ve en el mailer correcto;
 *   - con un Mockery de la fachada o con `Mail::fake()`: rápido, y fija los bordes (sin repetidos, sin
 *     la casilla en el mensaje, qué pasa cuando el mailer no puede decir nada).
 */
class RechazosDeCorreoHelperTest extends TestCase
{
    // Si el servidor SMTP de prueba no arranca y el entorno podía lanzarlo, el test FALLA (no se saltea en silencio).
    use FallaSiElServidorSmtpNoArranca;

    /**
     * Los servidores SMTP de mentira que levantó el test, para bajarlos al terminar.
     *
     * @var array<int, ServidorSmtpFake>
     */
    private $servidores_smtp = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->servidores_smtp as $servidor) {
            $servidor->bajar();
        }

        $this->servidores_smtp = [];

        parent::tearDown();
    }

    /**
     * Levanta un servidor SMTP de verdad y apunta el mailer dado a él. Si este entorno no puede lanzar
     * procesos, el test se saltea; si puede y el servidor no arranca, el test FALLA (ver el trait).
     *
     * Hay que llamarlo ANTES de que el test use el mailer por primera vez: el administrador de mails
     * guarda cada mailer ya armado.
     *
     * @param string $modo   ServidorSmtpFake::MODO_RECHAZA | MODO_ACEPTA.
     * @param string $mailer Nombre del mailer en `config/mail.php` (`smtp` es el de los leads, `admin` el de los clientes).
     *
     * @return ServidorSmtpFake
     */
    private function levantar_un_smtp(string $modo, string $mailer): ServidorSmtpFake
    {
        $servidor = ServidorSmtpFake::levantar($modo);

        if ($servidor === null) {
            $this->el_servidor_smtp_no_arranco();
        }

        $this->servidores_smtp[] = $servidor;

        $servidor->apuntar_el_mailer($mailer);

        return $servidor;
    }

    /**
     * Lo mismo, pero dejando ese SMTP como el mailer POR DEFECTO: el que usa `Mail::to()`, que es el
     * que usan los mails a leads. En `phpunit.xml` el default es `array`, que nunca da rechazos.
     *
     * @param string $modo ServidorSmtpFake::MODO_RECHAZA | MODO_ACEPTA.
     *
     * @return ServidorSmtpFake
     */
    private function levantar_un_smtp_por_defecto(string $modo): ServidorSmtpFake
    {
        $servidor = $this->levantar_un_smtp($modo, 'smtp');

        config(['mail.default' => 'smtp']);

        return $servidor;
    }

    /**
     * Manda un mail mínimo, por el mailer por defecto (como `Mail::to()`) o por uno con nombre.
     *
     * @param string      $para   Casilla de destino.
     * @param string|null $mailer null = el mailer por defecto.
     *
     * @return void
     */
    private function mandar_un_mail_de_prueba(string $para, ?string $mailer = null): void
    {
        $armar = function ($mensaje) use ($para) {
            $mensaje->to($para)->subject('Mail de prueba');
        };

        if ($mailer === null) {
            Mail::raw('Cuerpo de prueba.', $armar);

            return;
        }

        Mail::mailer($mailer)->raw('Cuerpo de prueba.', $armar);
    }

    /**
     * La premisa de la que depende todo el diseño: `Mail::mailer(null)` es el MISMO objeto que usa
     * `Mail::to()`, y el mailer por defecto es el que dice `mail.default`. Si una actualización de
     * Laravel rompe esto, leer `failures()` dejaría de mirar el mailer que mandó el mail y el helper
     * devolvería vacío sobre un rechazo real, sin ningún aviso: este test es el que lo grita.
     *
     * @return void
     */
    public function test_el_mailer_sin_nombre_es_el_mismo_objeto_que_usa_mail_to(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertSame(Mail::mailer(), Mail::mailer(null));
        $this->assertSame(Mail::mailer('smtp'), Mail::mailer(null));
        $this->assertNotSame(Mail::mailer('admin'), Mail::mailer(null), 'El mailer admin es otro: no hay que confundirlos.');
    }

    /**
     * El centro del cambio: sin nombre, el helper lee el mailer POR DEFECTO. Contra un SMTP de verdad
     * que contesta 550, `send()` vuelve normal y la casilla queda en `failures()`.
     *
     * @return void
     */
    public function test_del_ultimo_envio_sin_nombre_lee_el_mailer_por_defecto_contra_un_smtp_real(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->mandar_un_mail_de_prueba('no-existe@ejemplo.test');

        $this->assertSame(['no-existe@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());
        $this->assertSame(['no-existe@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio(null));
    }

    /**
     * Compatibilidad: los dos servicios que ya usaban el helper (`ImplementacionMailService` y
     * `AvisoDeActualizacionService`) lo llaman con `'admin'` y siguen leyendo SU mailer.
     *
     * @return void
     */
    public function test_del_ultimo_envio_con_nombre_sigue_leyendo_ese_mailer(): void
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA, 'admin');

        $this->mandar_un_mail_de_prueba('no-existe@ejemplo.test', 'admin');

        $this->assertSame(['no-existe@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio('admin'));
    }

    /**
     * Cada mailer tiene SU lista: un rechazo del mailer `admin` no se ve leyendo el de los leads, ni al
     * revés. Es la razón de que la lectura reciba el nombre en vez de usar `Mail::failures()` a secas
     * (que va siempre al mailer por defecto).
     *
     * @return void
     */
    public function test_los_rechazos_de_un_mailer_no_se_ven_desde_el_otro(): void
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA, 'admin');
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->mandar_un_mail_de_prueba('del-admin@ejemplo.test', 'admin');
        $this->mandar_un_mail_de_prueba('del-lead@ejemplo.test');

        $this->assertSame(['del-admin@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio('admin'));
        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio(), 'El de los leads aceptó la suya.');
    }

    /**
     * Contra un SMTP que acepta, no hay nada rechazado y `fallar_si_hubo_rechazos()` deja pasar.
     * Sin este control, un arreglo que tirara siempre pasaría el test del rechazo.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_acepta_no_hay_rechazos_y_no_tira(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->mandar_un_mail_de_prueba('lead@ejemplo.test');

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * Contra un SMTP de verdad que contesta 550, `fallar_si_hubo_rechazos()` tira la excepción propia,
     * con el motivo fijo y sin repetir la casilla en el mensaje.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_contesta_550_tira_la_excepcion_sin_la_casilla(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->mandar_un_mail_de_prueba('dueno.privado@ejemplo.test');

        try {
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            $this->fail('El servidor dijo 550: tenía que tirar MailRechazadoPorElServidorException.');
        } catch (MailRechazadoPorElServidorException $excepcion) {
            $this->assertSame(MailRechazadoPorElServidorException::MOTIVO, $excepcion->getMessage());
            $this->assertStringNotContainsString('dueno.privado@ejemplo.test', $excepcion->getMessage());
            $this->assertSame(['dueno.privado@ejemplo.test'], $excepcion->rechazadas());
        }
    }

    /**
     * El helper lee el ÚLTIMO envío: el mailer reinicia la lista en cada `send()`. Un envío que sale bien
     * después de uno rechazado, POR EL MISMO MAILER, no arrastra el rechazo viejo.
     *
     * 🔴 Tiene que ser el mismo objeto mailer. Una versión anterior de este test cambiaba de servidor con
     * `Mail::purge('smtp')`, que descarta el mailer cacheado: el segundo envío corría sobre un mailer NUEVO, con la
     * lista vacía de fábrica, y el `assertSame([], …)` pasaba igual aunque nadie reiniciara nada (el chequeo
     * independiente lo midió). Acá, en cambio, se le cambia el puerto al transporte del MISMO mailer para que el
     * segundo envío vaya al servidor que acepta, y se comprueba que la instancia sea la misma. Lo que se prueba es
     * `Illuminate\Mail\Mailer::sendSwiftMessage()` (`$this->failedRecipients = []`): SwiftMailer no reinicia por su
     * cuenta la variable que recibe por referencia, así que sin ese reinicio un envío bueno heredaría el rechazo de
     * uno anterior y se daría por fallido.
     *
     * @return void
     */
    public function test_un_envio_que_sale_bien_despues_de_uno_rechazado_no_arrastra_el_rechazo(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $mailer = Mail::mailer('smtp');

        $this->mandar_un_mail_de_prueba('uno@ejemplo.test');
        $this->assertSame(['uno@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());

        // El MISMO mailer, ahora contra un servidor que acepta: se lanza otro y se le cambia el puerto al transporte.
        $acepta = ServidorSmtpFake::levantar(ServidorSmtpFake::MODO_ACEPTA);

        if ($acepta === null) {
            $this->el_servidor_smtp_no_arranco();
        }

        $this->servidores_smtp[] = $acepta;
        $mailer->getSwiftMailer()->getTransport()->setPort($acepta->puerto);

        $this->mandar_un_mail_de_prueba('dos@ejemplo.test');

        $this->assertSame($mailer, Mail::mailer('smtp'), 'Tiene que ser la MISMA instancia: lo que se prueba es el reinicio de la lista, no un mailer nuevo.');
        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio(), 'El envío que salió bien no hereda el rechazo del anterior.');
    }

    /**
     * Lo mismo, con dos rechazos seguidos por el mismo mailer: la lista es la del ÚLTIMO envío y no la suma de los
     * dos. Si el mailer acumulara, acá habría dos casillas.
     *
     * @return void
     */
    public function test_la_lista_es_la_del_ultimo_envio_y_no_acumula_los_anteriores(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->mandar_un_mail_de_prueba('uno@ejemplo.test');
        $this->mandar_un_mail_de_prueba('dos@ejemplo.test');

        $this->assertSame(['dos@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * Si `failures()` no se puede leer, el helper no inventa un rechazo (devuelve vacío) pero TAMPOCO se calla: deja
     * un aviso en el log. Sin él, un upgrade de Laravel o un typo que rompa la lectura devolvería a los nueve
     * envíos a leads y a los dos servicios a darse por enviados en silencio, que es justo el defecto que el helper
     * existe para evitar.
     *
     * @return void
     */
    public function test_si_no_se_puede_leer_failures_no_inventa_un_rechazo_pero_lo_deja_en_el_log(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andThrow(new \RuntimeException('el mailer no responde failures()'));

        Log::shouldReceive('warning')->once()->with(
            \Mockery::on(function ($mensaje) {
                return is_string($mensaje) && strpos($mensaje, 'no se pudo leer failures()') !== false;
            }),
            \Mockery::on(function ($contexto) {
                return is_array($contexto)
                    && $contexto['mailer'] === '(por defecto)'
                    && $contexto['error'] === 'el mailer no responde failures()';
            })
        );

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * Sin repetidos, recortadas y sin lo que no es una casilla (vacíos, arrays, null): lo que devuelve
     * es una lista limpia, en el orden en que llegaron.
     *
     * @return void
     */
    public function test_la_lista_sale_sin_repetidos_recortada_y_sin_basura(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn([
            '  uno@ejemplo.test ',
            'uno@ejemplo.test',
            '',
            '   ',
            ['no-es-escalar@ejemplo.test'],
            null,
            'dos@ejemplo.test',
        ]);

        $this->assertSame(['uno@ejemplo.test', 'dos@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * El mensaje de la excepción es SIEMPRE el motivo fijo, aunque haya varias casillas rechazadas, y
     * las casillas quedan en `rechazadas()` para quien las quiera loguear. La dirección entera ya está
     * en la ficha del lead, al lado del error: repetirla en cada mensaje es regar la casilla de una
     * persona por logs, respuestas de API y pantallas.
     *
     * @return void
     */
    public function test_la_excepcion_lleva_el_motivo_y_guarda_las_casillas_aparte(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn(['uno@ejemplo.test', 'dos@ejemplo.test']);

        try {
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            $this->fail('Tenía que tirar.');
        } catch (MailRechazadoPorElServidorException $excepcion) {
            $this->assertInstanceOf(\RuntimeException::class, $excepcion);
            $this->assertSame(MailRechazadoPorElServidorException::MOTIVO, $excepcion->getMessage());
            $this->assertStringNotContainsString('ejemplo.test', $excepcion->getMessage());
            $this->assertSame(['uno@ejemplo.test', 'dos@ejemplo.test'], $excepcion->rechazadas());
        }
    }

    /**
     * El motivo le habla a quien opera el panel: dice qué pasó y qué hacer, sin jerga de SMTP.
     *
     * @return void
     */
    public function test_el_motivo_dice_que_paso_y_que_hacer(): void
    {
        $this->assertStringContainsString('el servidor de correo rechazó la casilla', MailRechazadoPorElServidorException::MOTIVO);
        // No afirma que la casilla "no existe": un 451 de greylisting o un 452 de buzón lleno también entran a `failures()`.
        $this->assertStringContainsString('no existe, está llena o no acepta mensajes por ahora', MailRechazadoPorElServidorException::MOTIVO);
        $this->assertStringContainsString('Revisá el email en la ficha y volvé a enviar', MailRechazadoPorElServidorException::MOTIVO);
    }

    /**
     * Con `failures()` vacío no tira.
     *
     * @return void
     */
    public function test_no_tira_si_el_mailer_no_rechazo_nada(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn([]);

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * Bajo `Mail::fake()` el falso también responde `failures()` y devuelve vacío: los tests que
     * verifican "qué mail sale y a quién" con el fake no tienen que cambiar.
     *
     * @return void
     */
    public function test_no_tira_bajo_mail_fake(): void
    {
        Mail::fake();

        $this->mandar_un_mail_de_prueba('lead@ejemplo.test');

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * Un mailer que no responde `failures()` (un mock sin esa expectativa, un transporte raro): no hay
     * con qué decir que el servidor rechazó nada, y no se inventa un fallo sobre un mail que pudo haber
     * salido bien. Es la conducta que ya tenía el helper y se conserva.
     *
     * @return void
     */
    public function test_no_tira_si_el_mailer_no_sabe_decir_los_rechazos(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * Y si `failures()` devuelve algo que no es una lista, tampoco.
     *
     * @return void
     */
    public function test_no_tira_si_failures_no_devuelve_una_lista(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn(null);

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }
}
