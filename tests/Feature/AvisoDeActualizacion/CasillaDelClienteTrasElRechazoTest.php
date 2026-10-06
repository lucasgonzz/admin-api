<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use App\Services\ClientContactEmailResolver;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * La casilla que el aviso de actualización TRAE del sistema del cliente se guarda en la ficha recién cuando
 * el servidor de correo ACEPTÓ el mail (misión mails-a-leads-rechazados-por-smtp, 6/10/2026, punto 2).
 *
 * `ClientContactEmailResolver::resolve()` hacía dos cosas: devolvía la casilla y, si la había traído del
 * `empresa-api` del cliente, la ESCRIBÍA en `clients.email` ahí mismo, antes de que nadie mandara nada.
 * Si esa casilla era justo la que el servidor SMTP rechaza (un 550 en el RCPT TO, que SwiftMailer no
 * convierte en excepción), quedaba guardada igual: la ficha pasaba a decir que el dueño se escribe a una
 * dirección que no existe, y todo envío siguiente la leía de ahí sin volver a preguntar.
 *
 * Con el arreglo:
 *   - `resolve()` solo LEE (ficha → cliente → null);
 *   - `recordar()` guarda la casilla, y es `AvisoDeActualizacionService::trabajar()` quien lo llama DESPUÉS
 *     del envío aceptado. Si el mail no salió —rechazo, excepción, mailer sin credencial— la ficha queda como
 *     estaba y el reintento le vuelve a preguntar al cliente (una llamada HTTP más: el precio de no guardar una
 *     casilla que nunca funcionó);
 *   - `recordar()` no pisa una casilla válida que la ficha ya tenga EN LA BASE. Entre el `resolve()` y el
 *     fin del `send()` pasan segundos, y alguien pudo cargarla a mano en ese rato.
 *
 * No se toca `aviso-actualizacion:reintentar --email=<casilla> --aplicar`: ahí la casilla la dicta el operador
 * por consola, que ve en la misma salida si el mail salió.
 *
 * El rechazo del servidor es REAL (`ServidorSmtpFake`, un proceso aparte que contesta 550) y va por el mailer
 * `admin`, que es el de este servicio.
 */
class CasillaDelClienteTrasElRechazoTest extends BaseDelAviso
{
    /** La casilla que trae el sistema del cliente, y la que el servidor "rechaza" o "acepta". */
    const CASILLA_TRAIDA = 'traido.privado@ejemplo.test';

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
     * Levanta un servidor SMTP de verdad y apunta el mailer `admin` a él. Si en este entorno no se puede
     * lanzar un proceso, el test se saltea.
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
     * El `empresa-api` del cliente contesta con la casilla de su dueño (lo que hace con la versión nueva).
     *
     * @param string $casilla
     *
     * @return void
     */
    private function el_cliente_trae_la_casilla(string $casilla = self::CASILLA_TRAIDA): void
    {
        Http::fake([
            '*contacto-dueno*' => Http::response([
                'contacto' => [
                    'email'        => $casilla,
                    'name'         => 'Juan Pérez',
                    'company_name' => 'Ferretería Pérez',
                    'phone'        => '3444999888',
                ],
            ], 200),
        ]);
    }

    /**
     * Un upgrade cerrado con una novedad, para un cliente SIN casilla en la ficha: la única forma de tener una
     * casilla es la que trae su sistema. La ventana de 24 hs de Meta queda ABIERTA: si el mail saliera, el
     * WhatsApp saldría, y es lo que se mira.
     *
     * @return array{0: Client, 1: \App\Models\ClientVersionUpgrade}
     */
    private function upgrade_de_un_cliente_sin_casilla(): array
    {
        $this->ventana->abierta = true;

        $client  = $this->crear_cliente(['email' => null]);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Escaneo de facturas', 'Subís la factura y el sistema carga los artículos solo.', 1);

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        return [$client, $upgrade->fresh()];
    }

    /**
     * (a) El centro del defecto: el servidor contesta 550 a la casilla que trajo el cliente. La casilla NO queda
     * en la ficha (la rechazaron: no existe), el aviso queda en `error` y no sale el WhatsApp. La casilla
     * rechazada sí queda en la fila del aviso, para saber cuál fue.
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_que_trajo_el_cliente_no_queda_en_la_ficha(): void
    {
        $this->el_cliente_trae_la_casilla();
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA);

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        $aviso = $this->servicio()->avisar($upgrade);

        // Primero lo que importa: la ficha. Una casilla que el servidor rechaza no puede quedar guardada como la del dueño.
        $this->assertNull($client->fresh()->email, 'El servidor rechazó la casilla: no se guarda en la ficha del cliente.');

        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado);
        $this->assertNull($aviso->mail_enviado_at);
        $this->assertSame(self::CASILLA_TRAIDA, $aviso->email, 'La casilla rechazada queda en la fila del aviso, para saber cuál fue.');
        $this->assertSame(0, $this->whatsapp->cuantos_envios(), 'Sin mail no hay WhatsApp que diga que se mandó.');
    }

    /**
     * (a) Lo mismo si el mail no sale por una excepción (el SMTP no responde, la conexión se cae): la casilla
     * que trajo el cliente no se guarda, porque nunca se pudo probar que sirve.
     *
     * @return void
     */
    public function test_si_el_envio_tira_una_excepcion_la_casilla_traida_no_queda_en_la_ficha(): void
    {
        $this->el_cliente_trae_la_casilla();

        Mail::shouldReceive('mailer')->with('admin')->andReturnSelf();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('Connection could not be established with host smtp.ejemplo.test'));

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertNull($client->fresh()->email, 'El mail no salió: la casilla traída no se guarda.');
        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado);
    }

    /**
     * (a) Lo mismo si el mailer `admin` no tiene credencial: el aviso ni siquiera intenta mandar, y la casilla
     * traída no se guarda (la ficha queda como estaba).
     *
     * @return void
     */
    public function test_sin_credencial_en_el_mailer_la_casilla_traida_no_queda_en_la_ficha(): void
    {
        $this->el_cliente_trae_la_casilla();

        config(['mail.mailers.admin' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'username' => '', 'password' => '']]);

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertNull($client->fresh()->email, 'No se mandó nada: la casilla traída no se guarda.');
        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado);
        $this->assertStringContainsString('MAIL_ADMIN_USERNAME', (string) $aviso->error);
    }

    /**
     * (a) Y si el mail no se manda porque no hay novedades que contar (a propósito: un mail vacío es peor que
     * ninguno), tampoco se guarda la casilla: el mail no salió, y la casilla nunca se probó. Es la consecuencia
     * consciente de guardarla recién después de un envío aceptado.
     *
     * @return void
     */
    public function test_un_aviso_sin_novedades_no_guarda_la_casilla_que_trajo_el_cliente(): void
    {
        Mail::fake();
        $this->el_cliente_trae_la_casilla();

        $this->ventana->abierta = true;

        $client  = $this->crear_cliente(['email' => null]);
        $version = $this->crear_version();
        // Sin ninguna novedad cargada para esta versión.
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_NOVEDADES, $aviso->estado);
        $this->assertNull($client->fresh()->email, 'No se mandó ningún mail: la casilla traída no se guarda.');
        Mail::assertNothingSent();
    }

    /**
     * (b) El control: con un servidor que ACEPTA a todos, la casilla que trajo el cliente SÍ queda guardada en la
     * ficha (para no volver a preguntar), el aviso sale `enviado` y avisa por WhatsApp. Sin esto, un arreglo que
     * no guardara nunca la casilla pasaría el test de arriba.
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_la_casilla_que_trajo_el_cliente_si_queda_en_la_ficha(): void
    {
        $this->el_cliente_trae_la_casilla();
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado, (string) $aviso->error);
        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertSame(self::CASILLA_TRAIDA, $client->fresh()->email, 'Salió bien: la casilla queda en la ficha para no volver a preguntar.');
        $this->assertSame(1, $this->whatsapp->cuantos_envios());
    }

    /**
     * (c) El control de "qué sale y a quién": con `Mail::fake()` sale exactamente UN mail, de la clase del aviso,
     * a la casilla que trajo el cliente, y esa casilla queda guardada. Fija que el arreglo no cambia a quién se
     * le escribe ni qué se le manda.
     *
     * @return void
     */
    public function test_sale_exactamente_un_mail_a_la_casilla_traida_y_queda_guardada(): void
    {
        Mail::fake();
        $this->el_cliente_trae_la_casilla();

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
        Mail::assertSent(ClientVersionUpgradeMail::class, function (Mailable $mail) {
            return $mail->hasTo(self::CASILLA_TRAIDA) && count($mail->to) === 1;
        });
        $this->assertCount(1, Mail::sent(Mailable::class), 'No sale ningún otro mail.');
        $this->assertSame(self::CASILLA_TRAIDA, $client->fresh()->email);
    }

    /**
     * (d) Tras un rechazo, el reintento le VUELVE A PREGUNTAR al cliente (la casilla no quedó guardada, así que
     * no hay de dónde leerla) y, cuando el mail sale, recién ahí la guarda y deja el aviso limpio. Es el costo
     * asumido: una llamada HTTP más a cambio de no persistir una casilla que nunca funcionó.
     *
     * @return void
     */
    public function test_el_reintento_tras_un_rechazo_vuelve_a_preguntarle_al_cliente_y_recien_ahi_guarda(): void
    {
        $this->el_cliente_trae_la_casilla();
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA);

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertNull($client->fresh()->email);
        $this->assertSame(ClientUpgradeNotice::ESTADO_ERROR, $aviso->estado);
        Http::assertSentCount(1);

        // Esta vez el servidor acepta (con el falso, que no rechaza nada).
        Mail::fake();

        $reintentado = $this->servicio()->reintentar($aviso->fresh());

        Http::assertSentCount(2);
        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $reintentado->estado);
        $this->assertNotNull($reintentado->mail_enviado_at);
        $this->assertNull($reintentado->fresh()->error, 'El envío que salió bien limpia el error del intento rechazado.');
        $this->assertSame(self::CASILLA_TRAIDA, $client->fresh()->email, 'Recién ahora, con el mail aceptado, la casilla queda en la ficha.');
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * Una casilla válida que alguien carga a mano en la ficha A MITAD del envío no se pisa. Entre el `resolve()` y
     * el fin del `send()` pasan segundos (la consulta de novedades, el SMTP), y lo que está escrito en la ficha le
     * gana a lo que trajo el cliente: es la fuente de verdad. Se simula con un oyente del evento de "mail enviado",
     * que carga la casilla justo cuando el servidor acaba de aceptar el mail.
     *
     * Este test pasa verde también contra el código anterior (que guardaba ANTES de mandar y no tenía nada que
     * pisar): protege contra una regresión del diseño nuevo, donde guardar DESPUÉS del envío sin la guarda
     * pisaría la casilla cargada a mano.
     *
     * @return void
     */
    public function test_una_casilla_valida_cargada_a_mano_a_mitad_del_envio_no_se_pisa(): void
    {
        $this->el_cliente_trae_la_casilla();
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);

        list($client, $upgrade) = $this->upgrade_de_un_cliente_sin_casilla();

        // Alguien carga la casilla correcta en la ficha mientras el mail está saliendo.
        Event::listen(MessageSent::class, function () use ($client) {
            Client::where('id', $client->id)->update(['email' => 'cargada.a.mano@ejemplo.test']);
        });

        $aviso = $this->servicio()->avisar($upgrade);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado, (string) $aviso->error);
        $this->assertSame('cargada.a.mano@ejemplo.test', $client->fresh()->email, 'Lo que está cargado a mano le gana a lo que trajo el cliente.');
    }

    /**
     * `resolve()` por sí solo ya NO escribe en la ficha: solo lee (ficha → cliente → null). Escribir es de
     * `recordar()`, y se llama recién cuando el mail salió.
     *
     * @return void
     */
    public function test_resolve_por_si_solo_no_escribe_en_la_ficha(): void
    {
        $this->el_cliente_trae_la_casilla();

        $client = $this->crear_cliente(['email' => null]);

        $casilla = (new ClientContactEmailResolver())->resolve($client);

        $this->assertSame(self::CASILLA_TRAIDA, $casilla, 'La casilla se devuelve igual.');
        $this->assertNull($client->fresh()->email, 'Pero resolve() no la escribe en la ficha.');
        $this->assertNull($client->email, 'Ni cambia el atributo en memoria.');
    }

    /**
     * `recordar()` guarda la casilla cuando la ficha no tiene una válida, y deja el atributo en memoria sincronizado.
     *
     * @return void
     */
    public function test_recordar_guarda_la_casilla_si_la_ficha_esta_vacia(): void
    {
        $client = $this->crear_cliente(['email' => null]);

        (new ClientContactEmailResolver())->recordar($client, self::CASILLA_TRAIDA);

        $this->assertSame(self::CASILLA_TRAIDA, $client->fresh()->email);
        $this->assertSame(self::CASILLA_TRAIDA, $client->email);
        $this->assertFalse($client->isDirty('email'), 'El atributo en memoria quedó sincronizado: no queda pendiente de guardar.');
    }

    /**
     * Una casilla inválida en la ficha (mal tipeada) tampoco cuenta como "tener una": se reemplaza por la
     * válida que trajo el cliente, igual que antes. Es la misma regla que `resolve()` ya usa para ignorarla.
     *
     * @return void
     */
    public function test_recordar_reemplaza_una_casilla_invalida_de_la_ficha(): void
    {
        $client = $this->crear_cliente(['email' => 'esto-no-es-un-mail']);

        (new ClientContactEmailResolver())->recordar($client, self::CASILLA_TRAIDA);

        $this->assertSame(self::CASILLA_TRAIDA, $client->fresh()->email);
    }

    /**
     * 🔴 `recordar()` no pisa una casilla válida que la ficha tiene EN LA BASE, aunque la instancia que recibe
     * todavía diga que está vacía: entre que el llamador leyó el cliente y llegó acá, alguien pudo cargarla a
     * mano. Por eso relee la base y no se fía de la instancia en memoria.
     *
     * @return void
     */
    public function test_recordar_no_pisa_una_casilla_valida_que_la_ficha_tiene_en_la_base(): void
    {
        $client = $this->crear_cliente(['email' => null]);

        // La instancia que tiene el llamador: vieja, con la casilla vacía.
        $instancia_vieja = Client::find($client->id);

        // Mientras tanto, alguien carga la casilla en la ficha.
        Client::where('id', $client->id)->update(['email' => 'cargada.a.mano@ejemplo.test']);

        (new ClientContactEmailResolver())->recordar($instancia_vieja, self::CASILLA_TRAIDA);

        $this->assertSame('cargada.a.mano@ejemplo.test', $client->fresh()->email, 'La casilla cargada a mano no se pisa.');
    }
}
