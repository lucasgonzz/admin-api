<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\ClientUpgradeNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * Cuando el servidor de correo RECHAZA la casilla del dueño, el aviso NO está enviado.
 *
 * El defecto (hallazgo ALTO-4 del revisor independiente, de la misma clase que el de los mails de la
 * implementación): si el SMTP contesta 550 en el RCPT TO, SwiftMailer NO tira excepción. `send()`
 * vuelve normal y las casillas rechazadas quedan en `Mail::mailer('admin')->failures()`. El aviso solo
 * miraba si `send()` tiraba, así que quedaba `enviado` y, peor, mandaba el WhatsApp que dice "te
 * mandamos un mail" sobre un mail que nunca salió. Este servicio YA está en producción.
 *
 * Dos clases de test, y las dos hacen falta:
 *   - con un Mockery de la fachada (`failures()` devuelve la casilla): rápido, y fija los caminos
 *     del registro (error, reintento, control sin rechazos);
 *   - con un servidor SMTP de verdad (`ServidorSmtpFake`, un proceso aparte que contesta 550): es
 *     lo único que prueba que SwiftMailer realmente se porta así, y que el servicio lo atrapa.
 */
class RechazoDelServidorTest extends BaseDelAviso
{
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
     * Levanta un servidor SMTP de verdad y apunta el mailer `admin` a él. Si en este entorno no se
     * puede lanzar un proceso, el test se saltea.
     *
     * @param string $modo ServidorSmtpFake::MODO_RECHAZA | MODO_ACEPTA.
     *
     * @return ServidorSmtpFake
     */
    private function levantar_un_smtp(string $modo): ServidorSmtpFake
    {
        $servidor = ServidorSmtpFake::levantar($modo);

        if ($servidor === null) {
            $this->markTestSkipped('No se pudo lanzar el servidor SMTP de prueba en este entorno.');
        }

        $this->servidores_smtp[] = $servidor;

        $servidor->apuntar_el_mailer('admin');

        return $servidor;
    }

    /**
     * Un upgrade cerrado con una novedad para el cliente, listo para avisar. La ventana de 24 hs de
     * Meta queda ABIERTA: si el mail saliera, el WhatsApp saldría, y es lo que se mira.
     *
     * @param array<string, mixed> $atributos_del_cliente
     *
     * @return array{0: \App\Models\Client, 1: \App\Models\ClientVersionUpgrade}
     */
    private function upgrade_cerrado_con_novedades(array $atributos_del_cliente = ['email' => 'dueno@ejemplo.test']): array
    {
        Http::fake();

        $this->ventana->abierta = true;

        $client  = $this->crear_cliente($atributos_del_cliente);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Escaneo de facturas', 'Subís la factura y el sistema carga los artículos solo.', 1);

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        return [$client, $upgrade->fresh()];
    }

    /**
     * Hace que el servidor "acepte" el envío sin tirar (como SwiftMailer con un 550) y deje las
     * casillas dadas en `failures()`.
     *
     * @param array<int, string> $rechazadas
     *
     * @return void
     */
    private function hacer_que_el_servidor_rechace(array $rechazadas): void
    {
        Mail::shouldReceive('mailer')->with('admin')->andReturnSelf();
        Mail::shouldReceive('to')->once()->with('dueno@ejemplo.test')->andReturnSelf();
        Mail::shouldReceive('send')->once();
        Mail::shouldReceive('failures')->once()->andReturn($rechazadas);
    }

    /**
     * El centro del defecto: `send()` vuelve normal pero `failures()` trae la casilla. El aviso queda
     * en `error` con el motivo, SIN fecha de mail y SIN WhatsApp: el WhatsApp dice "te mandamos un
     * mail", y mentirle al dueño es peor que no avisarle.
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_el_aviso_queda_en_error_y_no_sale_el_whatsapp()
    {
        list(, $upgrade) = $this->upgrade_cerrado_con_novedades();

        $this->hacer_que_el_servidor_rechace(['dueno@ejemplo.test']);

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado);
        $this->assertNull($aviso->mail_enviado_at, 'El mail no salió.');
        $this->assertNull($aviso->whatsapp_enviado_at);
        $this->assertStringContainsString('el servidor de correo rechazó la casilla', (string) $aviso->error);
        $this->assertSame('dueno@ejemplo.test', $aviso->email, 'La casilla rechazada queda en la fila, para saber cuál fue.');

        $this->assertSame(0, $this->whatsapp->cuantos_envios(), 'Sin mail no hay WhatsApp que diga que se mandó.');
    }

    /**
     * Un aviso rechazado se puede reintentar: como `mail_enviado_at` quedó vacío, el reintento manda
     * el mail cuando la casilla se corrige, y recién ahí sale el WhatsApp.
     *
     * @return void
     */
    public function test_un_aviso_rechazado_se_reintenta_cuando_se_corrige_la_casilla()
    {
        list($client, $upgrade) = $this->upgrade_cerrado_con_novedades();

        $this->hacer_que_el_servidor_rechace(['dueno@ejemplo.test']);
        $aviso = $this->servicio()->avisar($upgrade);
        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado);

        // Lucas corrige la casilla en la ficha y el servidor ahora sí la acepta.
        $client->update(['email' => 'corregida@ejemplo.test']);
        Mail::fake();

        $reintentado = $this->servicio()->reintentar($aviso->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $reintentado->estado);
        $this->assertNotNull($reintentado->mail_enviado_at);
        $this->assertSame('corregida@ejemplo.test', $reintentado->email);
        $this->assertNotNull($reintentado->whatsapp_enviado_at);
        $this->assertSame(1, $this->whatsapp->cuantos_envios());

        Mail::assertSent(ClientVersionUpgradeMail::class, function ($mail) {
            return $mail->hasTo('corregida@ejemplo.test');
        });
    }

    /**
     * El control del arreglo: si el servidor acepta a todos (`failures()` vacío), el aviso sale
     * `enviado` y el WhatsApp también. Sin esto, un arreglo que marcara todo como rechazado pasaría
     * el test del rechazo.
     *
     * @return void
     */
    public function test_sin_rechazos_el_aviso_sale_y_avisa_por_whatsapp()
    {
        list(, $upgrade) = $this->upgrade_cerrado_con_novedades();

        Mail::shouldReceive('mailer')->with('admin')->andReturnSelf();
        Mail::shouldReceive('to')->once()->with('dueno@ejemplo.test')->andReturnSelf();
        Mail::shouldReceive('send')->once();
        Mail::shouldReceive('failures')->once()->andReturn([]);

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertNotNull($aviso->whatsapp_enviado_at);
        $this->assertSame(1, $this->whatsapp->cuantos_envios());
    }

    /**
     * El defecto contra un SMTP de verdad: el servidor contesta 550 al RCPT TO, SwiftMailer no tira
     * y el servicio tiene que verlo en `failures()`. Es el escenario exacto que armó el revisor.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_contesta_550_el_aviso_queda_en_error()
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA);

        list(, $upgrade) = $this->upgrade_cerrado_con_novedades();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado, 'El servidor dijo 550: el mail no salió.');
        $this->assertNull($aviso->mail_enviado_at);
        $this->assertStringContainsString('el servidor de correo rechazó la casilla', (string) $aviso->error);
        $this->assertSame(0, $this->whatsapp->cuantos_envios(), 'Sin mail no hay WhatsApp.');
    }

    /**
     * El control contra un SMTP de verdad que ACEPTA: el mismo camino, sin ningún 550, sale
     * `enviado` y avisa por WhatsApp.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_acepta_el_aviso_sale_y_avisa_por_whatsapp()
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);

        list(, $upgrade) = $this->upgrade_cerrado_con_novedades();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado, (string) $aviso->error);
        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertNotNull($aviso->whatsapp_enviado_at);
        $this->assertSame(1, $this->whatsapp->cuantos_envios());
    }
}
