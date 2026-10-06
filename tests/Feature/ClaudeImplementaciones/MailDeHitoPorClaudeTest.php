<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Mail\ImplementacionMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * El mail de cada hito por `claude/*`: `POST claude/implementations/{id}/mail`.
 *
 * Esta ruta es la cáscara de `ImplementacionMailService`: pone los frenos y contesta. Por eso estos
 * tests corren contra el servicio REAL (con `Mail::fake()`, que es como se prueba todo lo que manda
 * mails) y no contra un doble: lo que se prueba es la costura entre los dos. Lo que se protege, en orden
 * de importancia:
 *
 *  1. 🔴 `dry_run` por defecto: no manda ni escribe NADA (ni la tabla de mails ni la ficha del cliente) y
 *     no devuelve la casilla entera, solo enmascarada.
 *  2. 🔴 Un solo envío por (implementación, hito) y un lock contra el doble envío: un mail a un cliente
 *     real no se deshace.
 *  3. Que cada excepción de negocio del servicio se conteste con su status y su `motivo` estable
 *     (`sin_mail`, `ya_enviado`, `faltan_datos`, `envio_en_curso`), y que un envío que falla (SMTP,
 *     credencial) NO tire: vuelve `estado: error` y se puede reintentar.
 *  4. Que los datos de cada hito se validen antes de armar nada.
 *  5. Que la casilla pasada pise a la de la ficha y se guarde solo si el mail salió.
 *  6. Los frenos de siempre: lista blanca, nombre sin revelar el correcto, tipos en español.
 */
class MailDeHitoPorClaudeTest extends BaseDeImplementaciones
{
    /** Casilla del dueño en la ficha. */
    const CASILLA = 'dueno@ejemplo.test';

    /**
     * Sin mails reales, con la URL del formulario cargada y la tabla de mails limpia.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion');
        DB::table('implementation_mails')->delete();
    }

    /* ------------------------------------------------------------------------------------------
     | Escenario y ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Un cliente con casilla, sus dos APIs (la dirección de su sistema) y su implementación.
     *
     * @param array<string, mixed> $opciones `email`: la casilla de la ficha (false = ninguna);
     *                                       `apis`: false = sin ClientApi.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(array $opciones = []): array
    {
        $atributos = [];
        if (! array_key_exists('email', $opciones) || $opciones['email'] !== false) {
            $atributos['email'] = isset($opciones['email']) ? $opciones['email'] : self::CASILLA;
        }

        $cliente = $this->crear_cliente('Panchito Gómez', $atributos);

        if (! array_key_exists('apis', $opciones) || $opciones['apis'] !== false) {
            $cliente = $this->crear_las_dos_apis($cliente, 'panchito');
        }

        return ['cliente' => $cliente, 'implementacion' => $this->crear_implementacion($cliente)];
    }

    /**
     * El POST del mail.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function mail(Implementation $implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/mail', $cuerpo, $this->headers());
    }

    /**
     * El cuerpo de un mail REAL (dry_run=false) con su confirmación.
     *
     * @param string               $hito  El hito.
     * @param array<string, mixed> $extra Lo que se suma al cuerpo.
     *
     * @return array<string, mixed>
     */
    private function real(string $hito, array $extra = []): array
    {
        return array_merge(['hito' => $hito, 'dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'], $extra);
    }

    /**
     * Cuántas filas hay en el registro de mails.
     *
     * @return int
     */
    private function filas_de_mails(): int
    {
        return (int) DB::table('implementation_mails')->count();
    }

    /**
     * Datos válidos de cada hito (los mínimos que acepta el servicio).
     *
     * @return array<string, array<string, mixed>>
     */
    private function datos_validos(): array
    {
        $opcion = function (string $nombre) {
            return ['nombre' => $nombre, 'base' => 'En qué se basa ' . $nombre, 'categorias' => 12, 'ejemplos' => ['Gaseosas', 'Aguas']];
        };

        return [
            'bienvenida' => [],
            'instalado'  => [],
            'acceso'     => ['articulos' => 1280],
            'imagenes'   => ['con_foto' => 900, 'total' => 1280, 'a_revisar' => 40],
            'categorias' => ['opciones' => [$opcion('Por rubro'), $opcion('Por marca'), $opcion('Mixta')]],
            'listo'      => ['resumen' => ['articulos' => 1280, 'clientes' => 300]],
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos del pedido
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin la clave, 401.
     *
     * @return void
     */
    public function test_sin_clave_devuelve_401(): void
    {
        $this->postJson('/api/claude/implementations/1/mail', ['hito' => 'bienvenida'])->assertStatus(401);
    }

    /**
     * 6. Un parámetro desconocido es 422 y no manda nada: un `para`, un `asunto` o un `html` suelen ser
     * alguien esperando armar el mail a mano.
     *
     * @return void
     */
    public function test_un_parametro_desconocido_es_422_y_no_manda_nada(): void
    {
        $e = $this->escenario();

        foreach (['para' => 'otro@ejemplo.test', 'asunto' => 'Hola', 'html' => '<b>x</b>', 'from' => 'x@y.z'] as $campo => $valor) {
            $respuesta = $this->mail($e['implementacion'], $this->real('bienvenida', [$campo => $valor]));

            $respuesta->assertStatus(422);
            $this->assertStringContainsString($campo, $this->cuerpo($respuesta));
        }

        Mail::assertNothingSent();
        $this->assertSame(0, $this->filas_de_mails());
    }

    /**
     * 6. Los tipos de los parámetros se validan en español: el hito obligatorio y de una lista cerrada,
     * `datos` como lista, la casilla como casilla de mail (y de hasta 150 caracteres, que es lo que
     * entra en `clients.email`), `reenviar` y `dry_run` booleanos.
     *
     * @return void
     */
    public function test_los_tipos_de_los_parametros(): void
    {
        $e = $this->escenario();

        $this->mail($e['implementacion'], [])->assertStatus(422);
        $this->mail($e['implementacion'], ['hito' => 'inventado'])->assertStatus(422);
        $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'datos' => 'texto'])->assertStatus(422);
        $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'email' => 'no es un mail'])->assertStatus(422);
        $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'reenviar' => 'quizás'])->assertStatus(422);
        $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'dry_run' => 'quizás'])->assertStatus(422);

        $largo = str_repeat('a', 140) . '@ejemplo.test';
        $this->assertGreaterThan(150, strlen($largo));
        $respuesta = $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'email' => $largo]);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('supera el máximo', $this->cuerpo($respuesta));

        $hito = $this->mail($e['implementacion'], ['hito' => 'inventado']);
        $this->assertStringContainsString('valor que no está permitido', $this->cuerpo($hito));

        $sin_nombre = $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'dry_run' => false]);
        $sin_nombre->assertStatus(422);
        $this->assertStringContainsString('es obligatorio cuando', $this->cuerpo($sin_nombre));

        Mail::assertNothingSent();
    }

    /**
     * Una implementación inexistente es 404.
     *
     * @return void
     */
    public function test_una_implementacion_inexistente_es_404(): void
    {
        $this->postJson('/api/claude/implementations/99999999/mail', ['hito' => 'bienvenida'], $this->headers())->assertStatus(404);
    }

    /**
     * 4. Los datos de cada hito se validan ANTES de armar nada: 422 con el motivo `faltan_datos`, los
     * `errores` por campo, y los dos textos (`error` y `message`) idénticos.
     *
     * @return void
     */
    public function test_los_datos_del_hito_se_validan_antes_de_armar_nada(): void
    {
        $e = $this->escenario();

        /* acceso sin la cantidad de artículos. */
        $respuesta = $this->mail($e['implementacion'], $this->real('acceso'));
        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'faltan_datos');
        $this->assertArrayHasKey('articulos', $respuesta->json('errores'));
        $this->assertSame(['articulos'], $respuesta->json('faltan'));
        $this->assertSame($respuesta->json('error'), $respuesta->json('message'));

        /* una clave que el hito no conoce. */
        $extra = $this->mail($e['implementacion'], $this->real('bienvenida', ['datos' => ['articulos' => 5]]));
        $extra->assertStatus(422);
        $this->assertArrayHasKey('articulos', $extra->json('errores'));

        /* más fotos que artículos: saldría "más del 100 %". */
        $fotos = $this->mail($e['implementacion'], $this->real('imagenes', ['datos' => ['con_foto' => 50, 'total' => 10]]));
        $fotos->assertStatus(422);
        $this->assertArrayHasKey('con_foto', $fotos->json('errores'));

        /* categorías: dos o tres (desde el 6/10/2026, D4 de `implementacion-dos-sistemas`; antes eran EXACTAMENTE tres). Una sola no sirve. */
        $una = $this->datos_validos()['categorias'];
        $una['opciones'] = array_slice($una['opciones'], 0, 1);
        $categorias = $this->mail($e['implementacion'], $this->real('categorias', ['datos' => $una]));
        $categorias->assertStatus(422);
        $this->assertArrayHasKey('opciones', $categorias->json('errores'));

        /* una nota de más de 600 caracteres. */
        $nota = $this->mail($e['implementacion'], $this->real('bienvenida', ['datos' => ['nota' => str_repeat('x', 601)]]));
        $nota->assertStatus(422);
        $this->assertArrayHasKey('nota', $nota->json('errores'));

        /* Lo mismo en el dry-run: la validación no depende de que sea de verdad. */
        $this->mail($e['implementacion'], ['hito' => 'acceso'])->assertStatus(422)->assertJsonPath('motivo', 'faltan_datos');

        Mail::assertNothingSent();
        $this->assertSame(0, $this->filas_de_mails());
    }

    /* ------------------------------------------------------------------------------------------
     | Dry-run
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Sin `dry_run` explícito NO se manda nada ni se escribe nada: ni la tabla de mails ni la
     * casilla de la ficha. Devuelve el mail armado, con la casilla ENMASCARADA y sin la entera.
     *
     * @return void
     */
    public function test_por_defecto_es_dry_run_y_no_manda_ni_escribe_nada(): void
    {
        $e = $this->escenario(['email' => false]);

        $respuesta = $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'email' => 'lucas@gmail.com']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('hito', 'bienvenida');
        $respuesta->assertJsonPath('listo', true);
        $respuesta->assertJsonPath('asunto', 'Arrancamos con la implementación de tu sistema');
        $respuesta->assertJsonPath('para_enmascarado', 'l***@gmail.com');
        $respuesta->assertJsonPath('faltan', []);
        $respuesta->assertJsonPath('ya_enviado', null);

        $this->assertStringContainsString('Completar el formulario', $respuesta->json('html'));
        $this->assertStringContainsString('https://admin.ejemplo.test/configuracion/' . $e['implementacion']->form_token, $respuesta->json('html'));
        $this->assertStringNotContainsString('{{', $respuesta->json('html'), 'Quedó una variable sin reemplazar en el mail.');

        $this->assertStringNotContainsString('lucas@gmail.com', $this->cuerpo($respuesta), 'La casilla salió entera.');
        $this->assertArrayNotHasKey('para', $respuesta->json());

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, $this->filas_de_mails());
        $this->assertNull($e['cliente']->refresh()->email, 'El dry-run guardó la casilla en la ficha.');
    }

    /**
     * Los seis hitos se arman en el dry-run con datos válidos, cada uno con su asunto.
     *
     * @return void
     */
    public function test_los_seis_hitos_se_arman_en_el_dry_run(): void
    {
        $e        = $this->escenario();
        $asuntos  = [
            'bienvenida' => 'Arrancamos con la implementación de tu sistema',
            'instalado'  => 'Tu sistema ya está instalado',
            'acceso'     => 'Ya podés entrar a tu sistema',
            'imagenes'   => 'Las fotos de tu catálogo',
            'categorias' => 'Tres formas de ordenar tu catálogo',
            'listo'      => 'Tu sistema está listo',
        ];
        $datos = $this->datos_validos();

        foreach ($asuntos as $hito => $asunto) {
            $respuesta = $this->mail($e['implementacion'], ['hito' => $hito, 'datos' => $datos[$hito]]);

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('asunto', $asunto);
            $respuesta->assertJsonPath('listo', true);
            $this->assertNotSame('', trim($respuesta->json('html')), $hito);
            $this->assertStringNotContainsString('{{', $respuesta->json('html'), $hito);
        }

        Mail::assertNothingSent();
    }

    /**
     * El dry-run dice lo que le FALTA al mail, tal cual lo devuelve el servicio: sin casilla (`email`),
     * sin link de formulario (`form_link`, solo la bienvenida) y sin dirección del sistema
     * (`url_sistema`, en acceso, fotos y listo). No tira: Lucas ve el mail aunque todavía no se pueda
     * mandar.
     *
     * @return void
     */
    public function test_el_dry_run_dice_lo_que_falta_sin_tirar(): void
    {
        $sin_casilla = $this->escenario(['email' => false]);
        $r1          = $this->mail($sin_casilla['implementacion'], ['hito' => 'bienvenida']);
        $r1->assertStatus(200);
        $r1->assertJsonPath('listo', false);
        $r1->assertJsonPath('para_enmascarado', null);
        $this->assertContains('email', $r1->json('faltan'));

        AdminSetting::set('implementation_form_url', '');
        $sin_link = $this->escenario();
        $r2       = $this->mail($sin_link['implementacion'], ['hito' => 'bienvenida']);
        $r2->assertStatus(200);
        $this->assertContains('form_link', $r2->json('faltan'));

        $sin_sistema = $this->escenario(['apis' => false]);
        $r3          = $this->mail($sin_sistema['implementacion'], ['hito' => 'acceso', 'datos' => ['articulos' => 10]]);
        $r3->assertStatus(200);
        $r3->assertJsonPath('listo', false);
        $this->assertContains('url_sistema', $r3->json('faltan'));
    }

    /* ------------------------------------------------------------------------------------------
     | El envío real
     |----------------------------------------------------------------------------------------- */

    /**
     * 6. Un `confirm_client_name` equivocado es 422, no manda nada y no revela el nombre correcto.
     *
     * @return void
     */
    public function test_el_nombre_equivocado_no_manda_nada_ni_revela_el_nombre(): void
    {
        $e = $this->escenario();

        $respuesta = $this->mail($e['implementacion'], $this->real('bienvenida', ['confirm_client_name' => 'Otro Negocio']));

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Panchito Gómez', $this->cuerpo($respuesta));
        Mail::assertNothingSent();
        $this->assertSame(0, $this->filas_de_mails());
    }

    /**
     * El camino feliz: el mail sale UNA vez, al `to` de la casilla de la ficha, por el mailer admin, y
     * queda registrado como enviado.
     *
     * @return void
     */
    public function test_el_camino_feliz_manda_un_mail_y_lo_registra(): void
    {
        $e = $this->escenario();

        $respuesta = $this->mail($e['implementacion'], $this->real('bienvenida'));

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('hito', 'bienvenida');
        $respuesta->assertJsonPath('enviado', true);
        $respuesta->assertJsonPath('estado', 'enviado');
        $respuesta->assertJsonPath('para_enmascarado', 'd***@ejemplo.test');
        $respuesta->assertJsonPath('reenvios', 0);
        $respuesta->assertJsonPath('error', null);
        $this->assertNotNull($respuesta->json('enviado_at'));
        $this->assertStringNotContainsString(self::CASILLA, $this->cuerpo($respuesta), 'La respuesta no tiene que traer la casilla entera.');

        Mail::assertSent(ImplementacionMail::class, 1);
        Mail::assertSent(ImplementacionMail::class, function ($mailable) {
            return $mailable->hasTo(self::CASILLA);
        });

        $fila = DB::table('implementation_mails')->where('implementation_id', $e['implementacion']->id)->first();
        $this->assertNotNull($fila);
        $this->assertSame('bienvenida', $fila->hito);
        $this->assertSame('enviado', $fila->estado);
        $this->assertSame(self::CASILLA, $fila->email);
        $this->assertSame('Arrancamos con la implementación de tu sistema', $fila->asunto);
        $this->assertSame(0, (int) $fila->reenvios);
        $this->assertNull($fila->error);
    }

    /**
     * Los hitos son independientes: que salga la bienvenida no impide mandar el de sistema instalado.
     *
     * @return void
     */
    public function test_cada_hito_es_independiente(): void
    {
        $e = $this->escenario();

        $this->mail($e['implementacion'], $this->real('bienvenida'))->assertStatus(200)->assertJsonPath('enviado', true);
        $this->mail($e['implementacion'], $this->real('instalado'))->assertStatus(200)->assertJsonPath('enviado', true);

        Mail::assertSent(ImplementacionMail::class, 2);
        $this->assertSame(2, $this->filas_de_mails());
    }

    /**
     * 2. 🔴 Un solo envío por hito: el segundo es 422 con el motivo `ya_enviado` (dice cuándo y a quién,
     * enmascarado) y NO manda un segundo mail.
     *
     * @return void
     */
    public function test_un_hito_que_ya_salio_es_422_ya_enviado(): void
    {
        $e = $this->escenario();
        $this->mail($e['implementacion'], $this->real('bienvenida'))->assertStatus(200);

        $segundo = $this->mail($e['implementacion'], $this->real('bienvenida'));

        $segundo->assertStatus(422);
        $segundo->assertJsonPath('motivo', 'ya_enviado');
        $this->assertStringContainsString('reenviar=true', $segundo->json('message'));
        $this->assertStringContainsString('d***@ejemplo.test', $segundo->json('message'));
        $this->assertStringNotContainsString(self::CASILLA, $this->cuerpo($segundo));
        $this->assertSame($segundo->json('error'), $segundo->json('message'));

        Mail::assertSent(ImplementacionMail::class, 1);
    }

    /**
     * 2. Con `reenviar=true` se manda de nuevo y se suma `reenvios`; y el dry-run de un hito que ya salió
     * lo avisa (`ya_enviado`) y queda `listo: false` hasta que se pida el reenvío.
     *
     * @return void
     */
    public function test_reenviar_manda_de_nuevo_y_suma_reenvios(): void
    {
        $e = $this->escenario();
        $this->mail($e['implementacion'], $this->real('bienvenida'))->assertStatus(200);

        $simulacion = $this->mail($e['implementacion'], ['hito' => 'bienvenida']);
        $simulacion->assertJsonPath('ya_enviado.estado', 'enviado');
        $simulacion->assertJsonPath('ya_enviado.reenvios', 0);
        $simulacion->assertJsonPath('ya_enviado.para_enmascarado', 'd***@ejemplo.test');
        $simulacion->assertJsonPath('listo', false);
        $this->assertStringContainsString('YA salió', $simulacion->json('nota'));

        $this->mail($e['implementacion'], ['hito' => 'bienvenida', 'reenviar' => true])->assertJsonPath('listo', true);

        $reenvio = $this->mail($e['implementacion'], $this->real('bienvenida', ['reenviar' => true]));
        $reenvio->assertStatus(200);
        $reenvio->assertJsonPath('enviado', true);
        $reenvio->assertJsonPath('reenvios', 1);

        Mail::assertSent(ImplementacionMail::class, 2);
        $this->assertSame(1, $this->filas_de_mails(), 'Un hito es UNA fila: el reenvío no agrega otra.');
        $this->assertSame(1, (int) DB::table('implementation_mails')->value('reenvios'));
    }

    /**
     * 3. Sin casilla en ninguna parte: 422 con el motivo `sin_mail` y `faltan: ["email"]`, y no manda
     * nada.
     *
     * @return void
     */
    public function test_sin_casilla_es_422_sin_mail(): void
    {
        $e = $this->escenario(['email' => false]);

        $respuesta = $this->mail($e['implementacion'], $this->real('bienvenida'));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'sin_mail');
        $respuesta->assertJsonPath('faltan', ['email']);
        $this->assertStringContainsString('Pasá `email`', $respuesta->json('message'));
        Mail::assertNothingSent();
        $this->assertSame(0, $this->filas_de_mails());
    }

    /**
     * 5. La casilla pasada en `email` se usa, y si el mail salió y la ficha no tenía casilla, se guarda
     * en `clients.email`.
     *
     * @return void
     */
    public function test_la_casilla_pasada_se_usa_y_se_guarda_si_la_ficha_no_tenia(): void
    {
        $e = $this->escenario(['email' => false]);

        $this->mail($e['implementacion'], $this->real('bienvenida', ['email' => 'nueva@ejemplo.test']))->assertStatus(200)->assertJsonPath('enviado', true);

        Mail::assertSent(ImplementacionMail::class, function ($mailable) {
            return $mailable->hasTo('nueva@ejemplo.test');
        });
        $this->assertSame('nueva@ejemplo.test', $e['cliente']->refresh()->email);
    }

    /**
     * 5. La casilla pasada PISA a la de la ficha (el mail sale a la nueva) y, como salió, la reemplaza.
     *
     * @return void
     */
    public function test_la_casilla_pasada_pisa_a_la_de_la_ficha(): void
    {
        $e = $this->escenario();

        $this->mail($e['implementacion'], $this->real('bienvenida', ['email' => 'otra@ejemplo.test']))->assertStatus(200);

        Mail::assertSent(ImplementacionMail::class, function ($mailable) {
            return $mailable->hasTo('otra@ejemplo.test') && ! $mailable->hasTo(self::CASILLA);
        });
        $this->assertSame('otra@ejemplo.test', $e['cliente']->refresh()->email);
    }

    /**
     * 5. Si el mail NO salió, no se toca la ficha: una casilla que nunca recibió nada no se guarda.
     *
     * @return void
     */
    public function test_si_el_mail_no_sale_no_se_toca_la_ficha(): void
    {
        $e = $this->escenario(['email' => false]);
        config(['mail.mailers.admin.transport' => 'smtp', 'mail.mailers.admin.username' => '', 'mail.mailers.admin.password' => '']);

        $this->mail($e['implementacion'], $this->real('bienvenida', ['email' => 'nueva@ejemplo.test']))->assertStatus(200)->assertJsonPath('enviado', false);

        $this->assertNull($e['cliente']->refresh()->email);
    }

    /**
     * 3. Sin el link del formulario la bienvenida no se puede mandar: 422 `faltan_datos` con
     * `faltan: ["form_link"]`.
     *
     * @return void
     */
    public function test_sin_link_de_formulario_la_bienvenida_es_422(): void
    {
        AdminSetting::set('implementation_form_url', '');
        $e = $this->escenario();

        $respuesta = $this->mail($e['implementacion'], $this->real('bienvenida'));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('motivo', 'faltan_datos');
        $this->assertContains('form_link', $respuesta->json('faltan'));
        Mail::assertNothingSent();
    }

    /**
     * 3. 🔴 Un envío que FALLA no tira: sin credencial del mailer vuelve 200 con `enviado: false`,
     * `estado: error` y el motivo (el mismo texto que usa el aviso de actualización); queda registrado y
     * NO manda nada. Y un hito en `error` se puede reintentar SIN `reenviar`, porque no hay nada que
     * "re"-enviar.
     *
     * @return void
     */
    public function test_un_envio_que_falla_vuelve_estado_error_y_se_puede_reintentar(): void
    {
        $e = $this->escenario();
        config(['mail.mailers.admin.transport' => 'smtp', 'mail.mailers.admin.username' => '', 'mail.mailers.admin.password' => '']);

        $falla = $this->mail($e['implementacion'], $this->real('bienvenida'));

        $falla->assertStatus(200);
        $falla->assertJsonPath('enviado', false);
        $falla->assertJsonPath('estado', 'error');
        $this->assertStringContainsString('MAIL_ADMIN_USERNAME', $falla->json('error'));
        $this->assertNull($falla->json('enviado_at'));
        Mail::assertNothingSent();

        $fila = DB::table('implementation_mails')->where('implementation_id', $e['implementacion']->id)->first();
        $this->assertSame('error', $fila->estado);
        $this->assertStringContainsString('MAIL_ADMIN_USERNAME', (string) $fila->error);

        /* El estado lo muestra con su motivo. */
        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('mails.0.estado', 'error');

        /* Se arregla la credencial y se reintenta, sin reenviar. */
        config(['mail.mailers.admin.transport' => 'array']);

        $reintento = $this->mail($e['implementacion'], $this->real('bienvenida'));
        $reintento->assertStatus(200);
        $reintento->assertJsonPath('enviado', true);
        Mail::assertSent(ImplementacionMail::class, 1);
    }

    /**
     * 2. 🔴 Con otro envío del MISMO hito en vuelo (el lock tomado), la ruta contesta 409 con el motivo
     * `envio_en_curso` y no manda nada: es el caso de una llamada cortada por timeout y reintentada.
     *
     * @return void
     */
    public function test_otro_envio_en_vuelo_es_409_envio_en_curso(): void
    {
        $e    = $this->escenario();
        $lock = Cache::lock('implementacion-mail-' . (int) $e['implementacion']->id . '-bienvenida', 60);
        $this->assertTrue($lock->get(), 'El test no pudo tomar el lock.');

        try {
            $respuesta = $this->mail($e['implementacion'], $this->real('bienvenida'));

            $respuesta->assertStatus(409);
            $respuesta->assertJsonPath('motivo', 'envio_en_curso');
            $this->assertSame($respuesta->json('error'), $respuesta->json('message'));
            Mail::assertNothingSent();
            $this->assertSame(0, $this->filas_de_mails());

            /* Otro hito no está bloqueado: el lock es por (implementación, hito). */
            $this->mail($e['implementacion'], $this->real('instalado'))->assertStatus(200)->assertJsonPath('enviado', true);
        } finally {
            $lock->release();
        }
    }

    /**
     * Cada uno de los seis hitos se manda de verdad con datos válidos y deja su fila.
     *
     * @return void
     */
    public function test_los_seis_hitos_se_mandan_de_verdad(): void
    {
        $e     = $this->escenario();
        $datos = $this->datos_validos();

        foreach ($datos as $hito => $d) {
            $this->mail($e['implementacion'], $this->real($hito, ['datos' => $d]))->assertStatus(200)->assertJsonPath('enviado', true);
        }

        Mail::assertSent(ImplementacionMail::class, 6);
        $this->assertSame(6, $this->filas_de_mails());
    }

    /* ------------------------------------------------------------------------------------------
     | Integración con el estado
     |----------------------------------------------------------------------------------------- */

    /**
     * El estado por `GET` lista los mails con la casilla enmascarada, el estado y los reenvíos.
     *
     * @return void
     */
    public function test_el_estado_muestra_los_mails_enviados(): void
    {
        $e = $this->escenario();
        $this->mail($e['implementacion'], $this->real('bienvenida'))->assertStatus(200);
        $this->mail($e['implementacion'], $this->real('bienvenida', ['reenviar' => true]))->assertStatus(200);

        $estado = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $estado->assertJsonPath('mails.0.hito', 'bienvenida');
        $estado->assertJsonPath('mails.0.estado', 'enviado');
        $estado->assertJsonPath('mails.0.reenvios', 1);
        $estado->assertJsonPath('mails.0.para_enmascarado', 'd***@ejemplo.test');
        $this->assertStringNotContainsString(self::CASILLA, $this->cuerpo($estado));
    }
}
