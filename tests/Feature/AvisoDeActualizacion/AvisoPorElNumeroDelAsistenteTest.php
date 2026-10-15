<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\AdminSetting;
use App\Models\ClientVersionUpgrade;
use App\Models\WhatsappConfig;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El WhatsApp del aviso de actualización sale por el NÚMERO DEL ASISTENTE (decisión de Lucas,
 * 15/10/2026), y la ventana de 24 hs se mide sobre ese mismo número.
 *
 * Mientras el segundo número no esté listo —fila + clave + secreto del webhook + número visible— el
 * aviso sale y se mide por el número de leads y clientes, igual que antes: es lo que permite desplegar
 * sin cambiar nada hasta que Lucas termine el alta.
 */
class AvisoPorElNumeroDelAsistenteTest extends BaseDelAviso
{
    /**
     * Fila de WhatsApp con su propósito.
     *
     * @param string               $proposito
     * @param string               $phone_number_id
     * @param array<string, mixed> $campos          Campos a pisar.
     *
     * @return WhatsappConfig
     */
    private function config(string $proposito, string $phone_number_id, array $campos = []): WhatsappConfig
    {
        $config = new WhatsappConfig();
        $config->forceFill(array_merge([
            'proposito'       => $proposito,
            'kapso_api_key'   => 'clave-de-prueba',
            'phone_number_id' => $phone_number_id,
            'webhook_secret'  => 'secreto-de-prueba',
            'display_phone'   => '+1 201-586-6682',
            'is_active'       => true,
            'test_mode'       => false,
        ], $campos));
        $config->save();

        return $config;
    }

    /**
     * Deja el escenario de siempre: plantilla cargada, mail fakeado.
     *
     * @return void
     */
    private function preparar(): void
    {
        Mail::fake();
        Http::fake();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, 'cc_sistema_actualizado');

        $this->config(WhatsappConfig::PROPOSITO_PRINCIPAL, '1111111111');
    }

    /**
     * Cierra un upgrade con novedades y dispara el aviso.
     *
     * @return \App\Models\ClientUpgradeNotice
     */
    private function avisar()
    {
        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        /** @var ClientVersionUpgrade $fresco */
        $fresco = $upgrade->fresh();

        return $this->servicio()->avisar($fresco);
    }

    /**
     * 🔴 Con el número del asistente listo, el aviso se mide y sale por él: la ventana se pide con el
     * propósito `asistente` y el envío fija como destino el `phone_number_id` del asistente.
     *
     * @return void
     */
    public function test_con_el_numero_del_asistente_listo_el_aviso_sale_por_el_asistente(): void
    {
        $this->preparar();
        $this->config(WhatsappConfig::PROPOSITO_ASISTENTE, '2222222222');
        $this->ventana->abierta = false;

        $aviso = $this->avisar();

        $this->assertNotNull($aviso->whatsapp_enviado_at, 'El aviso salió.');
        $this->assertSame([WhatsappConfig::PROPOSITO_ASISTENTE], $this->ventana->propositos_pedidos, 'La ventana se midió sobre el número del asistente.');
        $this->assertSame([WhatsappConfig::PROPOSITO_ASISTENTE], $this->whatsapp->propositos_pedidos);
        $this->assertSame('2222222222', $this->whatsapp->phone_number_id_de_destino());
        $this->assertCount(1, $this->whatsapp->plantillas, 'Con la ventana del asistente cerrada sale la plantilla.');
        $this->assertCount(0, $this->whatsapp->textos);
    }

    /**
     * Con la ventana del asistente abierta sale texto libre, por el asistente.
     *
     * @return void
     */
    public function test_con_la_ventana_del_asistente_abierta_sale_texto_libre(): void
    {
        $this->preparar();
        $this->config(WhatsappConfig::PROPOSITO_ASISTENTE, '2222222222');
        $this->ventana->abierta = true;

        $aviso = $this->avisar();

        $this->assertNotNull($aviso->whatsapp_enviado_at);
        $this->assertCount(1, $this->whatsapp->textos);
        $this->assertCount(0, $this->whatsapp->plantillas);
        $this->assertSame('2222222222', $this->whatsapp->phone_number_id_de_destino());
    }

    /**
     * 🔴 Sin fila del asistente, el aviso se mide y sale por el principal, como antes de la misión.
     *
     * @return void
     */
    public function test_sin_numero_del_asistente_el_aviso_sale_por_el_principal(): void
    {
        $this->preparar();
        $this->ventana->abierta = true;

        $aviso = $this->avisar();

        $this->assertNotNull($aviso->whatsapp_enviado_at);
        $this->assertSame([WhatsappConfig::PROPOSITO_PRINCIPAL], $this->ventana->propositos_pedidos);
        $this->assertSame('1111111111', $this->whatsapp->phone_number_id_de_destino());
    }

    /**
     * 🔴 Con el número del asistente a medias (sin secreto de webhook), sale por el principal: el dueño
     * no podría contestarle a un número que todavía no recibe.
     *
     * @return void
     */
    public function test_con_el_numero_del_asistente_a_medias_el_aviso_sale_por_el_principal(): void
    {
        $this->preparar();
        $this->config(WhatsappConfig::PROPOSITO_ASISTENTE, '2222222222', ['webhook_secret' => null]);
        $this->ventana->abierta = true;

        $this->avisar();

        $this->assertSame([WhatsappConfig::PROPOSITO_PRINCIPAL], $this->ventana->propositos_pedidos);
        $this->assertSame('1111111111', $this->whatsapp->phone_number_id_de_destino());
    }
}
