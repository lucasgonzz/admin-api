<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\AdminSetting;
use App\Models\ClientAssistantMessage;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Queue;

/**
 * A dónde va cada mensaje que entra por el número de ComercioCity.
 *
 * Esta es la prueba que cuida lo que ningún camino feliz mira: que meter una rama nueva en el medio
 * del webhook no se haya llevado puestas las que ya estaban. El orden es el que fija el plan y cada
 * posición es una decisión:
 *
 *   implementación en curso  →  implementación   (INTACTA: hay un flujo a medio terminar)
 *   dueño + canal prendido   →  asistente        (lo nuevo)
 *   todo lo demás            →  soporte          (que ahora está desconectado)
 *
 * Un empleado de un cliente con el canal prendido NO entra al asistente: es la decisión de Lucas de
 * que hable solo el dueño, y como el soporte está apagado, su mensaje queda registrado y nada más.
 */
class RuteoDelWebhookTest extends BaseDelCanal
{
    /**
     * Configuración activa en todas: sin ella el webhook contesta 503 y no rutea nada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
    }

    /**
     * El dueño de un cliente con el canal prendido va al asistente.
     *
     * @return void
     */
    public function test_el_dueno_con_el_canal_prendido_va_al_asistente(): void
    {
        Queue::fake();

        $client = $this->crear_cliente('+5493411234567', true);

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Cuánto vendí ayer?')
        )->assertStatus(200);

        $fila = ClientAssistantMessage::where('client_id', $client->id)->first();

        $this->assertNotNull($fila, 'El mensaje del dueño tenía que dejar fila en el hilo del asistente.');
        $this->assertSame(ClientAssistantMessage::DIRECCION_ENTRANTE, $fila->direccion);
        $this->assertSame('Cuánto vendí ayer?', $fila->texto);
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $fila->estado);
        $this->assertSame('wamid.ENTRANTE1', $fila->whatsapp_message_id);

        /* Y no abrió ningún ticket: el canal del asistente reemplaza a soporte, no lo duplica. */
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());

        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * Con el canal apagado, el dueño NO va al asistente.
     *
     * Es el estado en el que nacen los cuarenta y pico de clientes, así que es el camino real
     * durante semanas.
     *
     * @return void
     */
    public function test_con_el_canal_apagado_el_dueno_no_va_al_asistente(): void
    {
        Queue::fake();

        $client = $this->crear_cliente('+5493411234567', false);

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Cuánto vendí ayer?')
        )->assertStatus(200);

        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());

        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * Un EMPLEADO de un cliente con el canal prendido no entra al asistente.
     *
     * @return void
     */
    public function test_un_empleado_no_entra_al_asistente(): void
    {
        Queue::fake();

        $client = $this->crear_cliente('+5493411234567', true);
        $this->crear_empleado($client, '+5493419999999');

        $this->postear_webhook(
            $this->payload_de_texto('+5493419999999', 'Hola, tengo una duda', 'wamid.DEL-EMPLEADO')
        )->assertStatus(200);

        $this->assertSame(
            0,
            ClientAssistantMessage::where('client_id', $client->id)->count(),
            'El asistente es solo del dueño: un empleado no puede abrirle conversación.'
        );

        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * Una implementación en curso se queda con el mensaje aunque el canal esté prendido.
     *
     * 🔴 Es la rama que NO se toca. Un cliente en onboarding tiene un flujo a medio terminar con
     * pasos que esperan una respuesta concreta; meterle el asistente en el medio lo dejaría colgado
     * sin que nada lo denuncie.
     *
     * @return void
     */
    public function test_una_implementacion_en_curso_se_queda_con_el_mensaje(): void
    {
        Queue::fake();

        $client = $this->crear_cliente('+5493411234567', true);

        $implementation             = new Implementation();
        $implementation->client_id  = $client->id;
        $implementation->status     = 'in_progress';
        $implementation->save();

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Ya mandé los datos', 'wamid.EN-IMPLEMENTACION')
        )->assertStatus(200);

        $this->assertSame(
            0,
            ClientAssistantMessage::where('client_id', $client->id)->count(),
            'Con una implementación en curso el mensaje no puede irse al asistente.'
        );

        $this->assertSame(
            1,
            ImplementationMessage::where('implementation_id', $implementation->id)
                ->where('whatsapp_message_id', 'wamid.EN-IMPLEMENTACION')
                ->count(),
            'El mensaje tenía que quedar en el hilo de la implementación, como antes de esta misión.'
        );

        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * Con los tickets desconectados, un mensaje de soporte no crea ticket ni mensaje.
     *
     * @return void
     */
    public function test_con_los_tickets_desconectados_no_se_crea_ticket(): void
    {
        Queue::fake();

        AdminSetting::where('key', AsistenteWhatsappSettings::KEY_TICKETS_ENABLED)->delete();

        $client = $this->crear_cliente('+5493411234567', false);

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Se me traba el sistema', 'wamid.DE-SOPORTE')
        )->assertStatus(200);

        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame(0, SupportMessage::where('whatsapp_message_id', 'wamid.DE-SOPORTE')->count());
    }

    /**
     * Con los tickets prendidos vuelve a nacer el ticket, sin tocar una línea de código.
     *
     * 🔴 Esto es lo que prueba que NO se borró nada. El día que Lucas consiga el otro número, todo
     * el camino de soporte —ticket, mensaje, bandeja, sugerencias— tiene que volver a funcionar
     * escribiendo una fila en `admin_settings` y nada más.
     *
     * @return void
     */
    public function test_con_los_tickets_prendidos_el_soporte_vuelve_a_funcionar(): void
    {
        Queue::fake();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, '1');

        $client = $this->crear_cliente('+5493411234567', false);

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Se me traba el sistema', 'wamid.DE-SOPORTE')
        )->assertStatus(200);

        $ticket = SupportTicket::where('client_id', $client->id)->first();

        $this->assertNotNull($ticket, 'Con el interruptor prendido el ticket tiene que volver a nacer.');
        $this->assertSame('whatsapp', $ticket->source);
        $this->assertSame(
            1,
            SupportMessage::where('whatsapp_message_id', 'wamid.DE-SOPORTE')->count()
        );
    }

    /**
     * Con el soporte desconectado y un texto cargado, se contesta ese texto.
     *
     * @return void
     */
    public function test_el_texto_de_cortesia_sale_solo_si_alguien_lo_cargo(): void
    {
        Queue::fake();

        AdminSetting::set(
            AsistenteWhatsappSettings::KEY_DESCONECTADO_TEXTO,
            'Por soporte escribinos al 341 555-1234.'
        );

        $espia = $this->espiar_sender();

        $this->crear_cliente('+5493411234567', false);

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Se me traba el sistema', 'wamid.DE-SOPORTE')
        )->assertStatus(200);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('Por soporte escribinos al 341 555-1234.', $espia->textos[0]['body']);
    }

    /**
     * El wamid citado se guarda, y con él la conversación que el dueño quiere continuar.
     *
     * @return void
     */
    public function test_la_cita_del_payload_resuelve_la_conversacion(): void
    {
        Queue::fake();

        $client = $this->crear_cliente('+5493411234567', true);

        /* El turno anterior: una respuesta del asistente que salió con su wamid. */
        $anterior                      = new ClientAssistantMessage();
        $anterior->client_id           = $client->id;
        $anterior->telefono            = '+5493411234567';
        $anterior->direccion           = ClientAssistantMessage::DIRECCION_SALIENTE;
        $anterior->whatsapp_message_id = 'wamid.RESPUESTA-VIEJA';
        $anterior->ai_conversation_id  = 412;
        $anterior->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $anterior->save();

        $this->postear_webhook(
            $this->payload_de_texto(
                '+5493411234567',
                'Sí, dale',
                'wamid.CITANDO',
                'wamid.RESPUESTA-VIEJA'
            )
        )->assertStatus(200);

        $fila = ClientAssistantMessage::where('whatsapp_message_id', 'wamid.CITANDO')->first();

        $this->assertNotNull($fila);
        $this->assertSame('wamid.RESPUESTA-VIEJA', $fila->reply_to_whatsapp_message_id);
        $this->assertSame(
            412,
            (int) $fila->ai_conversation_id,
            'Citar la respuesta del asistente tiene que reabrir esa conversación, aunque sea vieja.'
        );
    }

    /**
     * El mismo wamid dos veces deja UNA sola fila.
     *
     * 🔴 Kapso reintenta cuando el webhook tarda en contestar, y del otro lado de este canal hay un
     * asistente que CARGA COSAS: un "sí, dale" duplicado es una compra duplicada.
     *
     * @return void
     */
    public function test_el_mismo_mensaje_dos_veces_no_se_manda_dos_veces(): void
    {
        Queue::fake();

        $client  = $this->crear_cliente('+5493411234567', true);
        $payload = $this->payload_de_texto('+5493411234567', 'Sí, dale', 'wamid.REINTENTADO');

        $this->postear_webhook($payload)->assertStatus(200);
        $this->postear_webhook($payload)->assertStatus(200);

        $this->assertSame(
            1,
            ClientAssistantMessage::where('client_id', $client->id)
                ->where('whatsapp_message_id', 'wamid.REINTENTADO')
                ->count()
        );

        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class, 1);
    }
}
