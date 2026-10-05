<?php

namespace Tests\Feature\ImplementacionMail;

use App\Mail\ImplementacionMail;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * Cuando el servidor de correo RECHAZA la casilla, el hito NO está enviado.
 *
 * El defecto (hallazgo ALTO-4 del revisor independiente): si el SMTP contesta 550 en el RCPT TO,
 * SwiftMailer NO tira excepción. `send()` vuelve normal y las casillas rechazadas quedan en
 * `Mail::mailer('admin')->failures()`. Como el servicio solo miraba si `send()` tiraba, daba el hito
 * por `enviado`, guardaba en la ficha del cliente la casilla rechazada y, aguas arriba, la skill le
 * avisaba al cliente por WhatsApp que ya le habían mandado un mail que nunca salió.
 *
 * Dos clases de test, y las dos hacen falta:
 *   - con un Mockery de la fachada (`failures()` devuelve la casilla): rápido, y fija todos los
 *     caminos del registro (primer envío, reenvío, reintento);
 *   - con un servidor SMTP de verdad (`ServidorSmtpFake`, un proceso aparte que contesta 550): es
 *     lo único que prueba que SwiftMailer realmente se porta así, y que el servicio lo atrapa.
 */
class RechazoDelServidorTest extends BaseDelMailDeImplementacion
{
    /**
     * Cliente con la casilla de la ficha vacía y la del formulario cargada: es el caso en el que un
     * envío exitoso guardaría la casilla en la ficha, así que es donde se ve si se guardó de más.
     *
     * @return array{0: Client, 1: Implementation}
     */
    private function cliente_con_la_casilla_del_formulario(): array
    {
        $client = $this->crear_cliente(['email' => null, 'setup_data' => ['email' => 'dueno.privado@ejemplo.test']]);
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        return [$client, $impl];
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
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once();
        Mail::shouldReceive('failures')->once()->andReturn($rechazadas);
    }

    /**
     * El centro del defecto: `send()` vuelve normal pero `failures()` trae la casilla. El hito queda
     * en `error`, con el motivo y la casilla ENMASCARADA (no repetida entera), sin fecha de envío y
     * sin guardar esa casilla en la ficha del cliente.
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_el_hito_queda_en_error_y_no_se_guarda_en_la_ficha()
    {
        list($client, $impl) = $this->cliente_con_la_casilla_del_formulario();

        $this->hacer_que_el_servidor_rechace(['dueno.privado@ejemplo.test']);

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('error', $resultado['estado']);
        $this->assertSame('d***@ejemplo.test', $resultado['para_enmascarado']);
        $this->assertNull($resultado['enviado_at']);
        $this->assertSame(0, $resultado['reenvios']);
        $this->assertSame(
            'No se pudo mandar el mail: el servidor de correo rechazó la casilla d***@ejemplo.test.',
            $resultado['error']
        );
        $this->assertStringNotContainsString('dueno.privado@ejemplo.test', $resultado['error'], 'El motivo no repite la casilla entera.');

        $fila = ImplementationMail::first();
        $this->assertSame('error', $fila->estado);
        $this->assertNull($fila->enviado_at);
        $this->assertSame($resultado['error'], $fila->error);
        $this->assertSame(1, ImplementationMail::count());

        $this->assertNull($client->fresh()->email, 'La casilla rechazada no se guarda en la ficha.');
    }

    /**
     * Un `email` explícito que el servidor rechaza tampoco pisa la ficha: era la corrección de
     * Lucas, pero si no existe no sirve de nada y la que había (que tal vez andaba) tiene que quedar.
     *
     * @return void
     */
    public function test_un_email_explicito_rechazado_no_pisa_la_ficha()
    {
        $client = $this->crear_cliente(['email' => 'vieja@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        $this->hacer_que_el_servidor_rechace(['mal-escrita@ejemplo.test']);

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], 'mal-escrita@ejemplo.test', false);

        $this->assertSame('error', $resultado['estado']);
        $this->assertSame('vieja@ejemplo.test', $client->fresh()->email);
    }

    /**
     * Un hito rechazado se puede reintentar sin pedir reenvío (no hay nada que "re"-enviar): cuando
     * la casilla se corrige, el mismo pedido sale, la fila pasa a `enviado` sin sumar un reenvío y
     * recién ahí se guarda la casilla en la ficha.
     *
     * @return void
     */
    public function test_tras_un_rechazo_el_hito_se_reintenta_y_recien_ahi_se_guarda_la_casilla()
    {
        list($client, $impl) = $this->cliente_con_la_casilla_del_formulario();

        $this->hacer_que_el_servidor_rechace(['dueno.privado@ejemplo.test']);
        $falla = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        $this->assertSame('error', $falla['estado']);
        $this->assertNull($client->fresh()->email);

        // Se corrige la casilla y el servidor ahora sí la acepta.
        Mail::fake();

        $bien = ImplementacionMailService::enviar($impl, 'instalado', [], 'corregida@ejemplo.test', false);

        $this->assertSame('enviado', $bien['estado']);
        $this->assertSame(0, $bien['reenvios']);
        $this->assertNull($bien['error']);
        Mail::assertSent(ImplementacionMail::class, 1);

        $fila = ImplementationMail::first();
        $this->assertSame('enviado', $fila->estado);
        $this->assertSame('corregida@ejemplo.test', $fila->email);
        $this->assertNull($fila->error);
        $this->assertSame('corregida@ejemplo.test', $client->fresh()->email);
    }

    /**
     * Un REENVÍO rechazado no pisa el hito: el mail original sí salió, así que el hito sigue
     * `enviado` con su fecha y sus reenvíos, y el motivo aclara eso. La respuesta de este intento sí
     * es `error`.
     *
     * @return void
     */
    public function test_un_reenvio_rechazado_no_pisa_el_hito_enviado()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => 'lucas@gmail.com']);
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        $primero = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        $this->assertSame('enviado', $primero['estado']);

        $this->hacer_que_el_servidor_rechace(['lucas@gmail.com']);

        $segundo = ImplementacionMailService::enviar($impl, 'instalado', [], null, true);

        $this->assertSame('error', $segundo['estado']);
        $this->assertSame($primero['enviado_at'], $segundo['enviado_at'], 'Sigue siendo la fecha del envío que sí salió.');
        $this->assertSame(0, $segundo['reenvios']);
        $this->assertStringStartsWith('No se pudo reenviar el mail: el servidor de correo rechazó la casilla l***@gmail.com.', $segundo['error']);
        $this->assertStringContainsString('El mail original sí salió el', $segundo['error']);
        $this->assertStringNotContainsString('lucas@gmail.com', $segundo['error']);

        $fila = ImplementationMail::first();
        $this->assertSame('enviado', $fila->estado);
        $this->assertSame(0, $fila->reenvios);
        $this->assertSame($segundo['error'], $fila->error);
    }

    /**
     * Si el servidor acepta a todos (`failures()` vacío) nada cambia: el hito queda `enviado`. Es el
     * control del arreglo, para que no se pase de estricto.
     *
     * @return void
     */
    public function test_sin_rechazos_el_hito_queda_enviado()
    {
        list($client, $impl) = $this->cliente_con_la_casilla_del_formulario();

        Mail::shouldReceive('mailer')->with('admin')->andReturnSelf();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once();
        Mail::shouldReceive('failures')->once()->andReturn([]);

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertNull($resultado['error']);
        $this->assertSame('dueno.privado@ejemplo.test', $client->fresh()->email, 'La casilla que salió bien sí se guarda.');
    }

    /**
     * El lock del envío se suelta también cuando el servidor rechazó: si quedara tomado, el hito
     * no se podría reintentar hasta que venza.
     *
     * @return void
     */
    public function test_el_lock_se_suelta_tras_un_rechazo()
    {
        list(, $impl) = $this->cliente_con_la_casilla_del_formulario();

        $this->hacer_que_el_servidor_rechace(['dueno.privado@ejemplo.test']);

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertTrue(Cache::lock('implementacion-mail-' . $impl->id . '-instalado', 5)->get());
    }

    /**
     * El defecto contra un SMTP de verdad: el servidor contesta 550 al RCPT TO, SwiftMailer no tira
     * y el servicio tiene que verlo en `failures()`. Es el escenario exacto que armó el revisor.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_contesta_550_el_hito_queda_en_error()
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA);

        list($client, $impl) = $this->cliente_con_la_casilla_del_formulario();

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('error', $resultado['estado'], 'El servidor dijo 550: el mail no salió.');
        $this->assertSame(
            'No se pudo mandar el mail: el servidor de correo rechazó la casilla d***@ejemplo.test.',
            $resultado['error']
        );
        $this->assertNull($resultado['enviado_at']);

        $this->assertSame('error', ImplementationMail::first()->estado);
        $this->assertNull($client->fresh()->email, 'La casilla rechazada no se guarda en la ficha.');
    }

    /**
     * El control contra un SMTP de verdad que ACEPTA: el mismo camino, sin ningún 550, sale
     * `enviado` y la casilla se guarda en la ficha. Sin esto, un arreglo que marcara todo como
     * rechazado pasaría el test de arriba.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_acepta_el_hito_queda_enviado()
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);

        list($client, $impl) = $this->cliente_con_la_casilla_del_formulario();

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['error']);
        $this->assertNull($resultado['error']);
        $this->assertNotNull($resultado['enviado_at']);

        $this->assertSame('enviado', ImplementationMail::first()->estado);
        $this->assertSame('dueno.privado@ejemplo.test', $client->fresh()->email);
    }
}
