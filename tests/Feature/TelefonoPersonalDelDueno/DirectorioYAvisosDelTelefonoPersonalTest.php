<?php

namespace Tests\Feature\TelefonoPersonalDelDueno;

use App\Models\Client;
use App\Models\ClientEmployee;
use App\Services\ClientPhoneDirectory;
use App\Services\ImplementationSupportWhatsappService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\AvisoDeActualizacion\BaseDelAviso;

/**
 * El teléfono PERSONAL del dueño en el directorio de contactos, en los destinatarios de las implementaciones y en
 * el aviso de actualización (misión telefono-personal-del-dueno, 2/11/2026).
 *
 * Se apoya en `BaseDelAviso` (cliente + versión + upgrade + WhatsApp y ventana de mentira) para no armar de nuevo el
 * andamiaje del aviso.
 *
 * Los teléfonos son distintos en los últimos ocho dígitos de cualquier ficha que haya quedado en la base del slot.
 */
class DirectorioYAvisosDelTelefonoPersonalTest extends BaseDelAviso
{
    use DatabaseTransactions;

    const NEGOCIO  = '+5493445667788';
    const PERSONAL = '+5493415883322';

    /**
     * Cliente con los dos teléfonos.
     *
     * @param string|null $personal Teléfono personal (null = no tiene).
     *
     * @return Client
     */
    private function cliente(?string $personal = self::PERSONAL): Client
    {
        return $this->crear_cliente(['phone' => self::NEGOCIO, 'asistente_phone' => $personal, 'email' => 'dueno@ejemplo.test']);
    }

    // ---------------------------------------------------------------------------------------------
    // Directorio
    // ---------------------------------------------------------------------------------------------

    /**
     * El teléfono personal es un contacto del cliente con el mismo carácter de dueño que el de la ficha.
     *
     * @return void
     */
    public function test_el_telefono_personal_es_un_contacto_de_tipo_client(): void
    {
        $client = $this->cliente();

        $filas = (new ClientPhoneDirectory())->phones_for_client($client);

        $this->assertCount(2, $filas);
        $this->assertSame(self::NEGOCIO, $filas[0]['phone']);
        $this->assertSame('client', $filas[0]['origin']);
        $this->assertSame(self::PERSONAL, $filas[1]['phone']);
        $this->assertSame('client', $filas[1]['origin']);
        $this->assertNull($filas[1]['client_employee_id']);
    }

    /**
     * Sin duplicar filas cuando el personal es el mismo número que el de la ficha, aunque esté escrito distinto.
     *
     * @return void
     */
    public function test_no_duplica_la_fila_si_el_personal_es_igual_al_de_la_ficha(): void
    {
        $client = $this->cliente('0344 566-7788');

        $filas = (new ClientPhoneDirectory())->phones_for_client($client);

        $this->assertCount(1, $filas);
        $this->assertSame(self::NEGOCIO, $filas[0]['phone']);
    }

    /**
     * 🔴 Regresión: sin teléfono personal el directorio es el de siempre (una sola fila, la de la ficha).
     *
     * @return void
     */
    public function test_sin_telefono_personal_el_directorio_no_cambia(): void
    {
        $client = $this->cliente(null);

        $filas = (new ClientPhoneDirectory())->phones_for_client($client);

        $this->assertCount(1, $filas);
        $this->assertSame(self::NEGOCIO, $filas[0]['phone']);
    }

    /**
     * Un empleado con el número personal gana la fila (como siempre que un número está en los dos lados) y no se
     * duplica.
     *
     * @return void
     */
    public function test_el_empleado_con_el_mismo_numero_no_se_duplica(): void
    {
        $client = $this->cliente();
        $empleado            = new ClientEmployee();
        $empleado->client_id = $client->id;
        $empleado->name      = 'Brisa';
        $empleado->phone     = self::PERSONAL;
        $empleado->save();

        $filas = (new ClientPhoneDirectory())->phones_for_client($client->fresh());

        $personales = array_values(array_filter($filas, function ($fila) {
            return $fila['phone'] === self::PERSONAL;
        }));

        $this->assertCount(1, $personales);
        $this->assertSame('employee', $personales[0]['origin']);
    }

    /**
     * Un lead con el número personal del dueño es «de un cliente»: no recibe seguimientos automáticos.
     *
     * @return void
     */
    public function test_el_telefono_personal_cuenta_como_telefono_de_cliente_para_los_seguimientos(): void
    {
        $this->cliente();

        $directorio = new ClientPhoneDirectory();

        $this->assertTrue($directorio->es_telefono_de_cliente(self::PERSONAL));
        $this->assertTrue($directorio->es_telefono_de_cliente(self::NEGOCIO));
        $this->assertFalse($directorio->es_telefono_de_cliente('+5493414777000'));
    }

    // ---------------------------------------------------------------------------------------------
    // Implementaciones: a quién se le escribe por defecto
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin `telefono` pedido, el mensaje de la implementación va al teléfono personal del dueño, que está en el
     * directorio y por lo tanto el webhook reconoce su respuesta.
     *
     * @return void
     */
    public function test_el_contacto_por_defecto_de_una_implementacion_es_el_personal(): void
    {
        $client = $this->cliente();

        $resuelto = app(ImplementationSupportWhatsappService::class)->resolver_contacto($client, null);

        $this->assertNull($resuelto['motivo']);
        $this->assertSame(self::PERSONAL, $resuelto['contacto']['phone']);
    }

    /**
     * 🔴 Regresión: sin teléfono personal el contacto por defecto es el de la ficha.
     *
     * @return void
     */
    public function test_sin_telefono_personal_el_contacto_por_defecto_es_el_de_la_ficha(): void
    {
        $client = $this->cliente(null);

        $resuelto = app(ImplementationSupportWhatsappService::class)->resolver_contacto($client, null);

        $this->assertSame(self::NEGOCIO, $resuelto['contacto']['phone']);
    }

    /**
     * El teléfono del negocio sigue siendo un contacto válido si se pide a mano.
     *
     * @return void
     */
    public function test_el_telefono_del_negocio_se_puede_pedir_a_mano(): void
    {
        $client = $this->cliente();

        $resuelto = app(ImplementationSupportWhatsappService::class)->resolver_contacto($client, self::NEGOCIO);

        $this->assertSame(self::NEGOCIO, $resuelto['contacto']['phone']);
    }

    // ---------------------------------------------------------------------------------------------
    // Aviso de actualización
    // ---------------------------------------------------------------------------------------------

    /**
     * El WhatsApp del aviso de actualización va al teléfono personal del dueño.
     *
     * @return void
     */
    public function test_el_whatsapp_del_aviso_sale_al_telefono_personal(): void
    {
        Mail::fake();
        Http::fake();
        $this->ventana->abierta = true;

        $this->avisar_a($this->cliente());

        $this->assertCount(1, $this->whatsapp->textos);
        $this->assertSame(self::PERSONAL, $this->whatsapp->textos[0]['to']);
    }

    /**
     * 🔴 Regresión: sin teléfono personal el WhatsApp del aviso va al de la ficha.
     *
     * @return void
     */
    public function test_el_whatsapp_del_aviso_sin_telefono_personal_sale_al_de_la_ficha(): void
    {
        Mail::fake();
        Http::fake();
        $this->ventana->abierta = true;

        $this->avisar_a($this->cliente(null));

        $this->assertCount(1, $this->whatsapp->textos);
        $this->assertSame(self::NEGOCIO, $this->whatsapp->textos[0]['to']);
    }

    /**
     * Cliente con una novedad y un upgrade cerrado, y el aviso mandado.
     *
     * @param Client $client Cliente.
     *
     * @return \App\Models\ClientUpgradeNotice
     */
    private function avisar_a(Client $client)
    {
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        return $this->servicio()->avisar($upgrade->fresh());
    }
}
