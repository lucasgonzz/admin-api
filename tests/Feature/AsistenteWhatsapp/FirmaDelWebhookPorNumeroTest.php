<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\ClientAssistantMessage;
use Illuminate\Support\Facades\Queue;

/**
 * Cada número valida la firma con SU secreto, y un número sin secreto no recibe nada.
 *
 * Es lo que impide que, con dos números llegando al mismo endpoint, la firma de uno sirva para meter
 * mensajes por el otro; y que una fila a medio cargar abra el webhook en vez de cerrarlo.
 */
class FirmaDelWebhookPorNumeroTest extends BaseDelCanal
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
        Queue::fake();
        $this->espiar_sender();
    }

    /**
     * La firma del asistente vale en el asistente.
     *
     * @return void
     */
    public function test_la_firma_del_asistente_vale_en_el_asistente(): void
    {
        $client = $this->crear_cliente('+5493411234567', true);
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->payload_de_texto('5493411234567', 'Hola', 'wamid.F1', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(1, ClientAssistantMessage::where('client_id', $client->id)->count());
    }

    /**
     * 🔴 La firma del principal NO vale para un payload del asistente.
     *
     * @return void
     */
    public function test_la_firma_del_principal_no_vale_en_el_asistente(): void
    {
        $client = $this->crear_cliente('+5493411234567', true);
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->payload_de_texto('5493411234567', 'Hola', 'wamid.F2', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO
        )->assertStatus(401);

        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());
    }

    /**
     * 🔴 Y al revés: la firma del asistente no vale para un payload del principal.
     *
     * @return void
     */
    public function test_la_firma_del_asistente_no_vale_en_el_principal(): void
    {
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->payload_de_texto('5493414445566', 'Hola', 'wamid.F3', null, self::PHONE_NUMBER_ID_PRINCIPAL),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(401);
    }

    /**
     * 🔴 Un número con el secreto vacío rechaza todo, aunque la firma se calcule con la clave vacía.
     *
     * Hasta el 13/10/2026, `hash_hmac()` con la columna en null firmaba con la clave `""` y cualquiera
     * que supiera la URL podía meter mensajes.
     *
     * @return void
     */
    public function test_un_numero_sin_secreto_rechaza_todo(): void
    {
        $this->crear_config_asistente(['webhook_secret' => '']);

        $this->postear_webhook(
            $this->payload_de_texto('5493414445566', 'Hola', 'wamid.F4', null, self::PHONE_NUMBER_ID_ASISTENTE),
            ''
        )->assertStatus(401);
    }

    /**
     * El principal sin secreto tampoco acepta nada.
     *
     * @return void
     */
    public function test_el_principal_sin_secreto_rechaza_todo(): void
    {
        \App\Models\WhatsappConfig::where('phone_number_id', self::PHONE_NUMBER_ID_PRINCIPAL)
            ->update(['webhook_secret' => '']);

        $this->postear_webhook(
            $this->payload_de_texto('5493414445566', 'Hola', 'wamid.F5', null, self::PHONE_NUMBER_ID_PRINCIPAL),
            ''
        )->assertStatus(401);
    }
}
