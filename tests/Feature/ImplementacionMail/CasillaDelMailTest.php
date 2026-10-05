<?php

namespace Tests\Feature\ImplementacionMail;

use App\Exceptions\ImplementacionMailException;
use App\Mail\Helpers\ImplementacionMailHelper;
use App\Models\ImplementationMail;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Mail;

/**
 * A quién le llega el mail: la cascada de casillas, el caso sin ninguna y el enmascarado.
 *
 * El orden es: el `email` que se pasó, `clients.email`, `setup_data.email` (el del formulario) y el
 * email del lead que se promovió a ese cliente. El primero que sirve corta.
 */
class CasillaDelMailTest extends BaseDelMailDeImplementacion
{
    /**
     * A quién le iría el mail de un cliente, según la previa.
     *
     * @param \App\Models\Client $client
     * @param string|null        $explicita
     *
     * @return string|null
     */
    private function para(\App\Models\Client $client, ?string $explicita = null): ?string
    {
        $impl = $this->crear_implementacion($client, $this->estados(2, 3));

        return ImplementacionMailService::previa($impl, 'instalado', [], $explicita)['para'];
    }

    /**
     * Cliente con las tres casillas cargadas, para ver quién gana.
     *
     * @return \App\Models\Client
     */
    private function cliente_con_todas_las_casillas(): \App\Models\Client
    {
        $client = $this->crear_cliente([
            'email'      => 'ficha@ejemplo.test',
            'setup_data' => ['email' => 'formulario@ejemplo.test'],
        ]);

        $this->crear_lead_promovido($client, 'lead@ejemplo.test');

        return $client;
    }

    /**
     * El `email` que se pasa le gana a todas las demás.
     *
     * @return void
     */
    public function test_el_email_que_se_pasa_le_gana_a_todos()
    {
        $client = $this->cliente_con_todas_las_casillas();

        $this->assertSame('explicita@ejemplo.test', $this->para($client, 'explicita@ejemplo.test'));
    }

    /**
     * Sin email explícito, la ficha del cliente va primero.
     *
     * @return void
     */
    public function test_despues_va_la_ficha_del_cliente()
    {
        $this->assertSame('ficha@ejemplo.test', $this->para($this->cliente_con_todas_las_casillas()));
    }

    /**
     * Sin casilla en la ficha, la del formulario de la implementación (`setup_data.email`).
     *
     * @return void
     */
    public function test_despues_va_la_del_formulario()
    {
        $client = $this->cliente_con_todas_las_casillas();
        $client->update(['email' => null]);

        $this->assertSame('formulario@ejemplo.test', $this->para($client->fresh()));
    }

    /**
     * Sin las dos anteriores, el email del lead que se promovió a este cliente.
     *
     * @return void
     */
    public function test_al_final_va_la_del_lead_promovido()
    {
        $client = $this->cliente_con_todas_las_casillas();
        $client->update(['email' => null, 'setup_data' => ['email' => '']]);

        $this->assertSame('lead@ejemplo.test', $this->para($client->fresh()));
    }

    /**
     * El lead que cuenta es el promovido a ESTE cliente: el de otro cliente no sirve.
     *
     * @return void
     */
    public function test_el_lead_de_otro_cliente_no_cuenta()
    {
        $client = $this->crear_cliente(['email' => null]);
        $otro   = $this->crear_cliente(['email' => null]);

        $this->crear_lead_promovido($otro, 'del-otro@ejemplo.test');

        $this->assertNull($this->para($client));
    }

    /**
     * Una casilla mal escrita en la ficha no se usa: se sigue con la siguiente.
     *
     * @return void
     */
    public function test_una_casilla_invalida_se_saltea()
    {
        $client = $this->cliente_con_todas_las_casillas();
        $client->update(['email' => 'esto no es un mail', 'setup_data' => ['email' => 'tampoco@']]);

        $this->assertSame('lead@ejemplo.test', $this->para($client->fresh()));
    }

    /**
     * Las casillas se recortan: un espacio de más al pegar no la vuelve inválida.
     *
     * @return void
     */
    public function test_la_casilla_se_recorta()
    {
        $client = $this->crear_cliente(['email' => '  dueno@ejemplo.test  ']);

        $this->assertSame('dueno@ejemplo.test', $this->para($client));
        $this->assertSame('otra@ejemplo.test', $this->para($client, '  otra@ejemplo.test '));
    }

    /**
     * Con un email explícito inválido NO se cae a las otras fuentes: mandarle a una dirección que
     * nadie pidió es peor que no mandar. La previa queda sin casilla y `enviar` dice por qué.
     *
     * @return void
     */
    public function test_un_email_explicito_invalido_no_cae_a_las_otras_fuentes()
    {
        Mail::fake();

        $client = $this->cliente_con_todas_las_casillas();
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        $previa = ImplementacionMailService::previa($impl, 'instalado', [], 'esto-no-es-un-mail');

        $this->assertNull($previa['para']);
        $this->assertNull($previa['para_enmascarado']);
        $this->assertSame(['email'], $previa['faltan']);

        try {
            ImplementacionMailService::enviar($impl, 'instalado', [], 'esto-no-es-un-mail', false);
            $this->fail('Tendría que haber tirado sin_mail.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(ImplementacionMailException::MOTIVO_SIN_MAIL, $excepcion->motivo);
            $this->assertStringContainsString('La dirección que se pasó en `email` no es una casilla válida.', $excepcion->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ImplementationMail::count());
    }

    /**
     * Una casilla más larga que `clients.email` (150) no se puede guardar en la ficha, así que no
     * se acepta. (Es válida como dirección —parte local de hasta 64 y dominios de hasta 63—; lo que
     * la frena es el largo de la columna.)
     *
     * @return void
     */
    public function test_una_casilla_que_no_entra_en_la_ficha_no_se_acepta()
    {
        $client = $this->crear_cliente(['email' => null]);

        $larga = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.com';
        $this->assertGreaterThan(150, strlen($larga));
        $this->assertNotFalse(filter_var($larga, FILTER_VALIDATE_EMAIL), 'Es una dirección bien formada.');
        $this->assertNull($this->para($client, $larga));

        $justa = str_repeat('a', 40) . '@' . str_repeat('b', 50) . '.com';
        $this->assertSame($justa, $this->para($client, $justa));
    }

    /**
     * Sin casilla en ningún lado, la previa lo dice en `faltan` (sin cortar: se ve el mail igual) y
     * `enviar` tira `sin_mail` con 422, sin escribir nada.
     *
     * @return void
     */
    public function test_sin_casilla_la_previa_lo_avisa_y_enviar_tira_sin_mail()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => null]);
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        $previa = ImplementacionMailService::previa($impl, 'instalado', [], null);

        $this->assertNull($previa['para']);
        $this->assertNull($previa['para_enmascarado']);
        $this->assertSame(['email'], $previa['faltan']);
        $this->assertStringContainsString('Tu sistema ya está instalado.', $previa['html'], 'La previa se arma igual.');

        try {
            ImplementacionMailService::enviar($impl, 'instalado', [], null, false);
            $this->fail('Tendría que haber tirado sin_mail.');
        } catch (ImplementacionMailException $excepcion) {
            $this->assertSame(ImplementacionMailException::MOTIVO_SIN_MAIL, $excepcion->motivo);
            $this->assertSame('sin_mail', $excepcion->getMotivo());
            $this->assertSame(422, $excepcion->getCode());
            $this->assertSame(['email'], $excepcion->faltan);
            $this->assertStringContainsString('Pasá `email`', $excepcion->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ImplementationMail::count(), 'No deja fila: no hubo intento.');
    }

    /**
     * La casilla se enmascara dejando la primera letra y el dominio.
     *
     * @return void
     */
    public function test_la_casilla_se_enmascara_con_la_primera_letra_y_el_dominio()
    {
        $this->assertSame('l***@gmail.com', ImplementacionMailHelper::enmascarar('lucas@gmail.com'));
        $this->assertSame('a***@x.com', ImplementacionMailHelper::enmascarar('a@x.com'));
        $this->assertSame('m***@estudio-perez.com.ar', ImplementacionMailHelper::enmascarar('  maria.perez@estudio-perez.com.ar '));
        $this->assertSame('ñ***@ejemplo.test', ImplementacionMailHelper::enmascarar('ñandu@ejemplo.test'));
        $this->assertSame('***', ImplementacionMailHelper::enmascarar('sin-arroba'));
        $this->assertSame('***', ImplementacionMailHelper::enmascarar('@sin-local.com'));
    }

    /**
     * El mail sale a la casilla que resolvió la cascada (acá, la del formulario).
     *
     * @return void
     */
    public function test_el_envio_sale_a_la_casilla_resuelta()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => null, 'setup_data' => ['email' => 'formulario@ejemplo.test']]);
        $impl   = $this->crear_implementacion($client, $this->estados(2, 3));

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('f***@ejemplo.test', $resultado['para_enmascarado']);

        Mail::assertSent(\App\Mail\ImplementacionMail::class, function ($mail) {
            return $mail->hasTo('formulario@ejemplo.test');
        });
    }
}
