<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\AdminSetting;
use App\Models\ClientUpgradeNotice;
use App\Models\ClientVersionUpgrade;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El WhatsApp del aviso: cuándo sale por texto libre, cuándo por plantilla, y cuándo no sale.
 */
class WhatsappDelAvisoTest extends BaseDelAviso
{
    /**
     * 🔴 Sin la fila del `AdminSetting` de la plantilla y con la ventana de 24 hs cerrada, el
     * WhatsApp NO se manda — y el mail sale igual.
     *
     * La plantilla `cc_sistema_actualizado` se crea a mano en Meta y hasta que exista, un
     * `send_template` con ese nombre lo rechaza Meta. El contenido del aviso lo lleva el mail; el
     * WhatsApp es el golpecito en el hombro y puede esperar.
     *
     * @return void
     */
    public function test_sin_plantilla_cargada_no_sale_el_whatsapp_pero_el_mail_si()
    {
        Mail::fake();
        Http::fake();

        $this->assertSame('', AsistenteWhatsappSettings::plantilla_de_actualizaciones(), 'precondición: no hay fila');

        $this->ventana->abierta = false;

        $aviso = $this->avisar_a_un_cliente_con_novedades();

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->mail_enviado_at, 'el mail salió');
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);

        $this->assertNull($aviso->whatsapp_enviado_at, 'el WhatsApp quedó pendiente');
        $this->assertNull($aviso->whatsapp_message_id);
        $this->assertSame(0, $this->whatsapp->cuantos_envios(), 'no se le pidió nada a Meta');

        $this->assertNotNull($aviso->error, 'y la fila dice por qué el WhatsApp no salió');
        $this->assertStringContainsString(
            AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME,
            $aviso->error
        );
    }

    /**
     * Con la plantilla cargada y la ventana cerrada, sale por plantilla, con una sola variable: el
     * nombre del negocio.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_sale_por_plantilla()
    {
        Mail::fake();
        Http::fake();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, 'cc_sistema_actualizado');

        $this->ventana->abierta = false;

        $aviso = $this->avisar_a_un_cliente_con_novedades();

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->whatsapp_enviado_at);
        $this->assertSame('wamid.DEPRUEBA', $aviso->whatsapp_message_id);
        $this->assertNull($aviso->error, 'salió todo: no queda nota pendiente');

        $this->assertCount(1, $this->whatsapp->plantillas);
        $this->assertCount(0, $this->whatsapp->textos);

        $enviada = $this->whatsapp->plantillas[0];

        $this->assertSame('cc_sistema_actualizado', $enviada['template']);
        $this->assertSame('es_AR', $enviada['language']);
        $this->assertSame(['Negocio de prueba'], $enviada['variables'], 'una sola variable: el negocio');
    }

    /**
     * Con la ventana abierta sale texto libre, y el texto nombra la casilla a la que fue el mail.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_sale_texto_libre()
    {
        Mail::fake();
        Http::fake();

        $this->ventana->abierta = true;

        $aviso = $this->avisar_a_un_cliente_con_novedades();

        $this->assertNotNull($aviso->whatsapp_enviado_at);
        $this->assertCount(1, $this->whatsapp->textos);
        $this->assertCount(0, $this->whatsapp->plantillas);

        $this->assertStringContainsString('dueno@ejemplo.test', $this->whatsapp->textos[0]['body']);
        $this->assertStringContainsString('mail', $this->whatsapp->textos[0]['body']);
    }

    /**
     * Un cliente sin teléfono no recibe WhatsApp, y eso no ensucia el estado del aviso: el mail
     * salió, que es lo que el estado dice.
     *
     * @return void
     */
    public function test_sin_telefono_no_hay_whatsapp_y_el_aviso_sigue_enviado()
    {
        Mail::fake();
        Http::fake();

        $this->ventana->abierta = true;

        $aviso = $this->avisar_a_un_cliente_con_novedades(['phone' => '']);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertNull($aviso->whatsapp_enviado_at);
        $this->assertSame(0, $this->whatsapp->cuantos_envios());
    }

    /**
     * Si Meta rechaza el WhatsApp, el aviso sigue `enviado` (el mail salió y no se desmanda) y el
     * motivo queda escrito.
     *
     * @return void
     */
    public function test_un_rechazo_de_meta_no_baja_el_aviso_a_error()
    {
        Mail::fake();
        Http::fake();

        $this->ventana->abierta   = true;
        $this->whatsapp->respuesta = null;

        $aviso = $this->avisar_a_un_cliente_con_novedades();

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertNull($aviso->whatsapp_enviado_at);
        $this->assertStringContainsString('rechazo simulado', (string) $aviso->error);
    }

    /**
     * Atajo: cliente con casilla y una novedad, upgrade cerrado y aviso mandado.
     *
     * @param array<string, mixed> $atributos_del_cliente
     *
     * @return ClientUpgradeNotice
     */
    private function avisar_a_un_cliente_con_novedades(array $atributos_del_cliente = []): ClientUpgradeNotice
    {
        $client = $this->crear_cliente(array_merge(['email' => 'dueno@ejemplo.test'], $atributos_del_cliente));

        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        /** @var ClientVersionUpgrade $fresco */
        $fresco = $upgrade->fresh();

        return $this->servicio()->avisar($fresco);
    }
}
