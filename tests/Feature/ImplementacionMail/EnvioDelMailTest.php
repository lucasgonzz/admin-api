<?php

namespace Tests\Feature\ImplementacionMail;

use App\Exceptions\ImplementacionMailException;
use App\Mail\ImplementacionMail;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Services\AvisoDeActualizacionService;
use App\Services\ImplementacionMailService;
use App\Services\WhatsappSendService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * El envío: lo que sale, lo que queda registrado y cada freno.
 *
 * Todos los tests de envío corren con `Mail::fake()` (no sale nada a ningún lado) salvo los que
 * simulan un SMTP que se cae, que reemplazan la fachada con un Mockery.
 */
class EnvioDelMailTest extends BaseDelMailDeImplementacion
{
    /**
     * Cliente con su implementación en el punto del hito "instalado".
     *
     * @param array<string, mixed> $atributos Atributos del cliente.
     *
     * @return array{0: Client, 1: Implementation}
     */
    private function cliente_e_implementacion(array $atributos = ['email' => 'lucas@gmail.com']): array
    {
        $client = $this->crear_cliente($atributos);
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        return [$client, $impl];
    }

    /**
     * Deja al mailer `admin` sin credencial, como está el .env de producción hasta que Lucas la cargue.
     *
     * @return void
     */
    private function sin_credencial_del_mailer(): void
    {
        config([
            'mail.mailers.admin.transport' => 'smtp',
            'mail.mailers.admin.username'  => null,
            'mail.mailers.admin.password'  => null,
        ]);
    }

    /**
     * Hace que el próximo `Mail::mailer('admin')->to(...)->send(...)` se caiga con el mensaje dado.
     *
     * @param string $mensaje Lo que dice el SMTP.
     *
     * @return void
     */
    private function hacer_que_el_smtp_se_caiga(string $mensaje): void
    {
        Mail::shouldReceive('mailer')->once()->with('admin')->andReturnSelf();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException($mensaje));
    }

    /**
     * El camino feliz: sale por el mailer `admin` a la casilla de la ficha y queda la fila.
     *
     * @return void
     */
    public function test_enviar_manda_por_el_mailer_admin_y_registra_la_fila()
    {
        Mail::fake();

        list($client, $impl) = $this->cliente_e_implementacion();

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame('l***@gmail.com', $resultado['para_enmascarado']);
        $this->assertNotNull($resultado['enviado_at']);
        $this->assertSame(0, $resultado['reenvios']);
        $this->assertNull($resultado['error']);
        $this->assertSame(['estado', 'para_enmascarado', 'enviado_at', 'reenvios', 'error'], array_keys($resultado));

        Mail::assertSent(ImplementacionMail::class, 1);
        Mail::assertSent(ImplementacionMail::class, function ($mail) {
            return $mail->hasTo('lucas@gmail.com')
                // Por el mailer de `admin@comerciocity.com`, no por el default (el de leads).
                && $mail->mailer === 'admin'
                && $mail->hito === 'instalado'
                && $mail->asunto === 'Tu sistema ya está instalado';
        });

        $fila = ImplementationMail::where('implementation_id', $impl->id)->first();

        $this->assertNotNull($fila);
        $this->assertSame('instalado', $fila->hito);
        $this->assertSame('lucas@gmail.com', $fila->email);
        $this->assertSame('Tu sistema ya está instalado', $fila->asunto);
        $this->assertSame('enviado', $fila->estado);
        $this->assertNotNull($fila->enviado_at);
        $this->assertSame(0, $fila->reenvios);
        $this->assertNull($fila->error);
        $this->assertTrue($fila->esta_enviado());
        $this->assertSame($fila->enviado_at->toIso8601String(), $resultado['enviado_at']);
    }

    /**
     * Cada hito sale con su asunto y queda en su propia fila.
     *
     * @return void
     */
    public function test_cada_hito_deja_su_propia_fila()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => 'lucas@gmail.com']);
        $impl   = $this->crear_implementacion($client);

        foreach (ImplementacionMailService::HITOS as $hito) {
            $this->poner_las_etapas($impl, $this->estados_del_hito($hito));

            $resultado = ImplementacionMailService::enviar($impl->fresh(), $hito, $this->datos_de_ejemplo($hito), null, false);

            $this->assertSame('enviado', $resultado['estado'], $hito);
        }

        Mail::assertSent(ImplementacionMail::class, 6);

        $this->assertEqualsCanonicalizing(
            ImplementacionMailService::HITOS,
            ImplementationMail::where('implementation_id', $impl->id)->pluck('hito')->all()
        );
    }

    /**
     * Repetir el mismo hito sin pedir reenvío es `ya_enviado`: 422, con la fecha y a quién salió, y
     * no sale otro mail.
     *
     * @return void
     */
    public function test_repetir_sin_reenviar_es_ya_enviado_y_no_manda_nada()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        try {
            ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
            $this->fail('Tendría que haber tirado ya_enviado.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(ImplementacionMailException::MOTIVO_YA_ENVIADO, $excepcion->motivo);
            $this->assertSame(422, $excepcion->getCode());
            $this->assertStringContainsString('"instalado"', $excepcion->getMessage());
            $this->assertStringContainsString('l***@gmail.com', $excepcion->getMessage());
            $this->assertStringContainsString('reenviar=true', $excepcion->getMessage());
            $this->assertMatchesRegularExpression('#\d{2}/\d{2}/\d{4} \d{2}:\d{2}#', $excepcion->getMessage(), 'Dice cuándo salió.');
        }

        Mail::assertSent(ImplementacionMail::class, 1);
        $this->assertSame(1, ImplementationMail::count());
        $this->assertSame(0, ImplementationMail::first()->reenvios);
    }

    /**
     * Con `reenviar` sale de nuevo, y cada reenvío suma uno.
     *
     * @return void
     */
    public function test_reenviar_manda_de_nuevo_y_suma_un_reenvio()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $segundo = ImplementacionMailService::enviar($impl, 'instalado', [], null, true);
        $this->assertSame('enviado', $segundo['estado']);
        $this->assertSame(1, $segundo['reenvios']);

        $tercero = ImplementacionMailService::enviar($impl, 'instalado', [], null, true);
        $this->assertSame(2, $tercero['reenvios']);

        Mail::assertSent(ImplementacionMail::class, 3);
        $this->assertSame(1, ImplementationMail::count(), 'Sigue habiendo una sola fila por hito.');
        $this->assertSame(2, ImplementationMail::first()->reenvios);
    }

    /**
     * `reenviar` sobre un hito que nunca salió es simplemente el primer envío: no cuenta como reenvío.
     *
     * @return void
     */
    public function test_reenviar_un_hito_que_nunca_salio_no_suma_reenvios()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, true);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame(0, $resultado['reenvios']);
    }

    /**
     * Sin credencial del mailer `admin` no se intenta nada: el hito queda en `error` con el mensaje
     * que dice qué cargar y dónde, y no se manda ningún mail.
     *
     * @return void
     */
    public function test_sin_la_credencial_del_mailer_no_se_intenta_y_queda_escrito_que_falta()
    {
        Mail::fake();
        $this->sin_credencial_del_mailer();

        list(, $impl) = $this->cliente_e_implementacion();

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('error', $resultado['estado']);
        $this->assertNull($resultado['enviado_at']);
        $this->assertSame(0, $resultado['reenvios']);
        $this->assertSame('l***@gmail.com', $resultado['para_enmascarado']);
        $this->assertStringStartsWith('No se pudo mandar el mail: el mailer `admin` no tiene credencial', $resultado['error']);
        $this->assertStringContainsString('MAIL_ADMIN_USERNAME y MAIL_ADMIN_PASSWORD', $resultado['error']);

        Mail::assertNothingSent();

        $fila = ImplementationMail::first();
        $this->assertSame('error', $fila->estado);
        $this->assertSame($resultado['error'], $fila->error);
        $this->assertNull($fila->enviado_at);
        $this->assertSame('lucas@gmail.com', $fila->email);
    }

    /**
     * El mensaje de "falta la credencial" es EL MISMO que escribe el aviso de actualización: lo
     * que lee Lucas no cambia según el canal. Se compara con el método privado del aviso por
     * reflexión, para que las dos copias no se separen sin que algo lo avise.
     *
     * @return void
     */
    public function test_el_mensaje_de_la_credencial_es_el_mismo_que_el_del_aviso_de_actualizacion()
    {
        $aviso = new AvisoDeActualizacionService(
            null,
            $this->createMock(WhatsappSendService::class),
            $this->createMock(WhatsappSessionWindowService::class)
        );

        $metodo = new \ReflectionMethod(AvisoDeActualizacionService::class, 'que_le_falta_al_mailer');
        $metodo->setAccessible(true);

        $configuraciones = [
            'smtp sin usuario' => ['transport' => 'smtp', 'username' => null, 'password' => 'secreta'],
            'smtp sin clave'   => ['transport' => 'smtp', 'username' => 'admin@comerciocity.com', 'password' => ''],
            'smtp completo'    => ['transport' => 'smtp', 'username' => 'admin@comerciocity.com', 'password' => 'secreta'],
            'transporte array' => ['transport' => 'array', 'username' => null, 'password' => null],
            'sin definir'      => [],
        ];

        foreach ($configuraciones as $nombre => $mailer) {
            config(['mail.mailers.admin' => $mailer]);

            $esperado = $metodo->invoke($aviso);

            $this->assertSame($esperado, ImplementacionMailService::que_le_falta_al_mailer(), $nombre);
        }

        $this->assertSame(AvisoDeActualizacionService::MAILER, ImplementacionMailService::MAILER);
    }

    /**
     * Un hito en `error` se reintenta sin pedir reenvío: cuando se carga la credencial, el mismo
     * pedido sale. No cuenta como reenvío, porque nunca había salido.
     *
     * @return void
     */
    public function test_un_hito_en_error_se_reintenta_sin_pedir_reenvio()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        $this->sin_credencial_del_mailer();
        $falla = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        $this->assertSame('error', $falla['estado']);

        // Se carga la credencial (acá, el transporte vuelve a `array`).
        config(['mail.mailers.admin.transport' => 'array']);

        $bien = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $bien['estado']);
        $this->assertSame(0, $bien['reenvios']);
        $this->assertNull($bien['error']);

        Mail::assertSent(ImplementacionMail::class, 1);

        $fila = ImplementationMail::first();
        $this->assertSame('enviado', $fila->estado);
        $this->assertNull($fila->error, 'El error anterior se limpia al salir.');
        $this->assertNotNull($fila->enviado_at);
        $this->assertSame(1, ImplementationMail::count());
    }

    /**
     * Un SMTP que se cae no tira excepción: el hito queda en `error` con lo que dijo el servidor, y
     * ni la ficha del cliente ni nada más se toca. Después se puede reintentar.
     *
     * @return void
     */
    public function test_un_fallo_del_smtp_queda_escrito_y_se_puede_reintentar()
    {
        list($client, $impl) = $this->cliente_e_implementacion(['email' => null, 'setup_data' => ['email' => 'formulario@ejemplo.test']]);

        $this->hacer_que_el_smtp_se_caiga('Connection could not be established with host smtp.hostinger.com');

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('error', $resultado['estado']);
        $this->assertSame('No se pudo mandar el mail: Connection could not be established with host smtp.hostinger.com', $resultado['error']);
        $this->assertNull($resultado['enviado_at']);

        $fila = ImplementationMail::first();
        $this->assertSame('error', $fila->estado);
        $this->assertSame($resultado['error'], $fila->error);

        $this->assertNull($client->fresh()->email, 'Si el mail no salió, la casilla no se guarda en la ficha.');

        // Reintento, ahora sí: sale y la fila pasa a enviado.
        Mail::fake();

        $bien = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $bien['estado']);
        $this->assertSame('enviado', ImplementationMail::first()->estado);
        $this->assertSame('formulario@ejemplo.test', $client->fresh()->email);
    }

    /**
     * Un reenvío que falla no pisa el hito: el mail original sí salió, así que el hito sigue
     * `enviado` con su fecha y sus reenvíos, y el error queda anotado aclarando eso. La respuesta
     * sí es `error`, porque lo que se pidió ahora no salió.
     *
     * @return void
     */
    public function test_un_reenvio_que_falla_no_pisa_el_enviado()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        $primero  = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        $original = ImplementationMail::first();

        $this->hacer_que_el_smtp_se_caiga('Timeout');

        $segundo = ImplementacionMailService::enviar($impl, 'instalado', [], null, true);

        $this->assertSame('error', $segundo['estado']);
        $this->assertSame($primero['enviado_at'], $segundo['enviado_at'], 'Sigue siendo la fecha del envío que sí salió.');
        $this->assertSame(0, $segundo['reenvios']);
        $this->assertStringContainsString('No se pudo reenviar el mail: Timeout', $segundo['error']);
        $this->assertStringContainsString('El mail original sí salió el', $segundo['error']);

        $fila = ImplementationMail::first();
        $this->assertSame('enviado', $fila->estado, 'El hito sigue enviado.');
        $this->assertSame($original->enviado_at->toIso8601String(), $fila->enviado_at->toIso8601String());
        $this->assertSame(0, $fila->reenvios);
        $this->assertSame($segundo['error'], $fila->error);
    }

    /**
     * Si el mail salió y la ficha del cliente no tenía casilla, se la guarda: así el aviso de
     * actualización tampoco queda `sin_mail`.
     *
     * @return void
     */
    public function test_si_la_ficha_no_tenia_casilla_se_guarda_la_que_se_uso()
    {
        Mail::fake();

        list($client, $impl) = $this->cliente_e_implementacion(['email' => null, 'setup_data' => ['email' => 'formulario@ejemplo.test']]);

        $this->assertNull($client->email);

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('formulario@ejemplo.test', $client->fresh()->email);
    }

    /**
     * Si la ficha YA tenía casilla y no se pasó otra, no se toca.
     *
     * @return void
     */
    public function test_si_la_ficha_ya_tenia_casilla_no_se_toca()
    {
        Mail::fake();

        list($client, $impl) = $this->cliente_e_implementacion(['email' => 'ficha@ejemplo.test', 'setup_data' => ['email' => 'formulario@ejemplo.test']]);

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('ficha@ejemplo.test', $client->fresh()->email);
    }

    /**
     * El `email` que se pasa se usa y se guarda en la ficha aunque ya hubiera otra: es la corrección.
     *
     * @return void
     */
    public function test_el_email_que_se_pasa_se_usa_y_pisa_la_ficha()
    {
        Mail::fake();

        list($client, $impl) = $this->cliente_e_implementacion(['email' => 'vieja@ejemplo.test']);

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], 'nueva@ejemplo.test', false);

        $this->assertSame('n***@ejemplo.test', $resultado['para_enmascarado']);

        Mail::assertSent(ImplementacionMail::class, function ($mail) {
            return $mail->hasTo('nueva@ejemplo.test') && ! $mail->hasTo('vieja@ejemplo.test');
        });

        $this->assertSame('nueva@ejemplo.test', $client->fresh()->email);
        $this->assertSame('nueva@ejemplo.test', ImplementationMail::first()->email);
    }

    /**
     * Si el mail NO salió, ni la casilla explícita ni la resuelta se guardan en la ficha.
     *
     * @return void
     */
    public function test_si_el_mail_no_salio_no_se_guarda_ninguna_casilla()
    {
        Mail::fake();
        $this->sin_credencial_del_mailer();

        list($client, $impl) = $this->cliente_e_implementacion(['email' => null]);

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], 'nueva@ejemplo.test', false);

        $this->assertSame('error', $resultado['estado']);
        $this->assertNull($client->fresh()->email);
    }

    /**
     * La previa no escribe NADA: ni el registro, ni la casilla en la ficha (aunque se le pase una
     * explícita), ni manda ningún mail.
     *
     * @return void
     */
    public function test_la_previa_no_escribe_nada_ni_manda_nada()
    {
        Mail::fake();

        list($client, $impl) = $this->cliente_e_implementacion(['email' => null]);

        $previa = ImplementacionMailService::previa($impl, 'instalado', [], 'nueva@ejemplo.test');

        $this->assertSame('nueva@ejemplo.test', $previa['para']);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, ImplementationMail::count());
        $this->assertNull($client->fresh()->email);
    }

    /**
     * Si falta algo de la implementación (acá, el link del formulario) el envío se frena con
     * `faltan_datos` y lo que falta, sin mandar ni dejar fila.
     *
     * @return void
     */
    public function test_si_falta_el_link_del_formulario_enviar_tira_faltan_datos()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => 'lucas@gmail.com']);
        $impl   = $this->crear_implementacion($client, $this->estados(0, 1), ['form_token' => null]);

        try {
            ImplementacionMailService::enviar($impl, 'bienvenida', [], null, false);
            $this->fail('Tendría que haber tirado faltan_datos.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(ImplementacionMailException::MOTIVO_FALTAN_DATOS, $excepcion->motivo);
            $this->assertSame(422, $excepcion->getCode());
            $this->assertSame(['form_link'], $excepcion->faltan);
            $this->assertStringContainsString('form_link', $excepcion->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ImplementationMail::count());
    }

    /**
     * Y sin dirección de sistema, los hitos que mandan al cliente a su sistema se frenan igual.
     *
     * @return void
     */
    public function test_si_falta_la_direccion_del_sistema_enviar_tira_faltan_datos()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => 'lucas@gmail.com'], false);
        $impl   = $this->crear_implementacion($client, $this->estados(3, 4));

        try {
            ImplementacionMailService::enviar($impl, 'acceso', ['articulos' => 10], null, false);
            $this->fail('Tendría que haber tirado faltan_datos.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(['url_sistema'], $excepcion->faltan);
        }

        Mail::assertNothingSent();
    }

    /**
     * Un hito inexistente se frena en `previa()` y en `enviar()` con `hito_invalido`.
     *
     * @return void
     */
    public function test_un_hito_invalido_se_frena_en_previa_y_en_enviar()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        foreach (['previa', 'enviar'] as $metodo) {
            try {
                if ($metodo === 'previa') {
                    ImplementacionMailService::previa($impl, 'despedida', [], null);
                } else {
                    ImplementacionMailService::enviar($impl, 'despedida', [], null, false);
                }

                $this->fail($metodo . ' tendría que haber tirado hito_invalido.');
            } catch (ImplementacionMailException $excepcion) {
                $this->assertSame(ImplementacionMailException::MOTIVO_HITO_INVALIDO, $excepcion->motivo, $metodo);
                $this->assertSame(422, $excepcion->getCode(), $metodo);
                $this->assertStringContainsString('despedida', $excepcion->getMessage(), $metodo);
            }
        }

        Mail::assertNothingSent();
    }

    /**
     * El orden de los frenos: un hito que ya salió dice `ya_enviado` aunque la casilla ya no esté,
     * porque es lo que de verdad le importa a quien llama.
     *
     * @return void
     */
    public function test_ya_enviado_va_antes_que_sin_mail()
    {
        Mail::fake();

        list($client, $impl) = $this->cliente_e_implementacion();

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $client->update(['email' => null]);

        try {
            ImplementacionMailService::enviar($impl->fresh(), 'instalado', [], null, false);
            $this->fail('Tendría que haber tirado ya_enviado.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(ImplementacionMailException::MOTIVO_YA_ENVIADO, $excepcion->motivo);
        }
    }

    /**
     * Cada implementación tiene su propio registro del mismo hito: mandar "instalado" a un cliente no
     * frena el "instalado" de otro.
     *
     * @return void
     */
    public function test_cada_implementacion_tiene_su_propio_registro_del_mismo_hito()
    {
        Mail::fake();

        list(, $impl_a) = $this->cliente_e_implementacion(['email' => 'a@ejemplo.test']);
        list(, $impl_b) = $this->cliente_e_implementacion(['email' => 'b@ejemplo.test']);

        $this->assertSame('enviado', ImplementacionMailService::enviar($impl_a, 'instalado', [], null, false)['estado']);
        $this->assertSame('enviado', ImplementacionMailService::enviar($impl_b, 'instalado', [], null, false)['estado']);

        Mail::assertSent(ImplementacionMail::class, 2);
        $this->assertSame(2, ImplementationMail::count());
    }

    /**
     * Con otro envío del mismo hito en vuelo (el lock tomado) no se manda nada y se contesta 409
     * `envio_en_curso`: es el caso del reintento de una llamada que se cortó por timeout.
     *
     * @return void
     */
    public function test_con_otro_envio_en_vuelo_no_se_manda_nada_y_se_contesta_409()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        $en_vuelo = Cache::lock('implementacion-mail-' . $impl->id . '-instalado', 60);
        $this->assertTrue($en_vuelo->get(), 'El otro envío toma el lock.');

        try {
            ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
            $this->fail('Tendría que haber tirado envio_en_curso.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(ImplementacionMailException::MOTIVO_ENVIO_EN_CURSO, $excepcion->motivo);
            $this->assertSame(409, $excepcion->getCode());
            $this->assertStringContainsString('en curso', $excepcion->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ImplementationMail::count());

        // Otro hito de la misma implementación no está bloqueado.
        $this->poner_las_etapas($impl, $this->estados(3, 4));
        $otro = ImplementacionMailService::enviar($impl->fresh(), 'acceso', ['articulos' => 5], null, false);
        $this->assertSame('enviado', $otro['estado']);

        // Y cuando el otro envío termina, el mismo hito vuelve a poder mandarse.
        $en_vuelo->release();
        $this->assertSame('enviado', ImplementacionMailService::enviar($impl, 'instalado', [], null, false)['estado']);
    }

    /**
     * El lock se suelta siempre: después de un envío exitoso, de uno que falló y de uno frenado por
     * `ya_enviado`. Si quedara tomado, el hito se trabaría hasta que venza.
     *
     * @return void
     */
    public function test_el_lock_se_suelta_en_todos_los_caminos()
    {
        Mail::fake();

        list(, $impl) = $this->cliente_e_implementacion();

        $nombre = 'implementacion-mail-' . $impl->id . '-instalado';

        $this->sin_credencial_del_mailer();
        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        $this->assertTrue(Cache::lock($nombre, 5)->get(), 'Tras un fallo.');
        Cache::lock($nombre)->forceRelease();

        config(['mail.mailers.admin.transport' => 'array']);
        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        $lock = Cache::lock($nombre, 5);
        $this->assertTrue($lock->get(), 'Tras un envío exitoso.');
        $lock->release();

        try {
            ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
        } catch (ImplementacionMailException $excepcion) {
            // ya_enviado: lo esperado.
        }

        $this->assertTrue(Cache::lock($nombre, 5)->get(), 'Tras un ya_enviado.');
    }

    /**
     * El único de la tabla impide dos filas del mismo hito de la misma implementación: es la
     * última red si algo se saltara el lock.
     *
     * @return void
     */
    public function test_la_tabla_no_admite_dos_filas_del_mismo_hito()
    {
        list(, $impl) = $this->cliente_e_implementacion();

        $fila = [
            'implementation_id' => $impl->id,
            'hito'              => 'instalado',
            'email'             => 'lucas@gmail.com',
            'asunto'            => 'Tu sistema ya está instalado',
            'estado'            => 'enviado',
        ];

        ImplementationMail::create($fila);

        $this->expectException(QueryException::class);

        ImplementationMail::create($fila);
    }

    /**
     * Si nadie la atrapa, la excepción se convierte sola en la respuesta JSON con su status: un
     * endpoint que se olvide del try/catch contesta 422 con el motivo, no un 500.
     *
     * @return void
     */
    public function test_la_excepcion_se_convierte_en_json_si_nadie_la_atrapa()
    {
        $excepcion = ImplementacionMailException::sin_mail();
        $respuesta = $excepcion->render(request());

        $this->assertSame(422, $respuesta->getStatusCode());

        $cuerpo = json_decode($respuesta->getContent(), true);
        $this->assertSame('sin_mail', $cuerpo['motivo']);
        $this->assertSame(['email'], $cuerpo['faltan']);
        $this->assertArrayHasKey('message', $cuerpo);
        $this->assertArrayHasKey('errores', $cuerpo);

        $en_curso = ImplementacionMailException::envio_en_curso('acceso')->render(request());
        $this->assertSame(409, $en_curso->getStatusCode());
    }
}
