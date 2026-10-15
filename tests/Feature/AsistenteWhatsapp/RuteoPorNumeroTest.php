<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\AdminSetting;
use App\Models\ClientAssistantMessage;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * A dónde va un mensaje según POR QUÉ NÚMERO entró (misión asistente-segundo-numero, 15/10/2026).
 *
 * Lo que estas pruebas cuidan, en el orden en que importa:
 *
 *   1. **Sin el número del asistente listo, NADA cambia.** El dueño con el canal prendido que escribe al
 *      número de leads y clientes sigue hablando con su asistente ahí, igual que desde el 16/9. Es la
 *      promesa que permite desplegar antes de cargar el número.
 *   2. **Lo que entra por el número del asistente es SOLO del asistente.** Un ajeno no crea lead, ni
 *      ticket, ni entra a implementación: recibe un texto corto.
 *   3. **El dueño que se equivoca de número recibe un aviso**, no un silencio ni un ticket.
 */
class RuteoPorNumeroTest extends BaseDelCanal
{
    /** Teléfono del dueño de prueba, en el formato en que lo manda WhatsApp. */
    const TELEFONO_DUENO = '5493411234567';

    /**
     * El número principal activo en todas: sin él el webhook contesta 503.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
        Cache::flush();
    }

    /**
     * Un payload de texto que entra por el número del asistente.
     *
     * @param string $telefono Remitente.
     * @param string $texto    Cuerpo.
     * @param string $wamid    ID del mensaje.
     *
     * @return array<string, mixed>
     */
    private function entrante_al_asistente(string $telefono, string $texto = 'Hola', string $wamid = 'wamid.AL-ASISTENTE'): array
    {
        return $this->payload_de_texto($telefono, $texto, $wamid, null, self::PHONE_NUMBER_ID_ASISTENTE);
    }

    /**
     * Un payload de texto que entra por el número de leads y clientes.
     *
     * @param string $telefono Remitente.
     * @param string $texto    Cuerpo.
     * @param string $wamid    ID del mensaje.
     *
     * @return array<string, mixed>
     */
    private function entrante_al_principal(string $telefono, string $texto = 'Hola', string $wamid = 'wamid.AL-PRINCIPAL'): array
    {
        return $this->payload_de_texto($telefono, $texto, $wamid, null, self::PHONE_NUMBER_ID_PRINCIPAL);
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Sin el número del asistente listo, todo como hoy
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Sin fila del asistente, el dueño que escribe al número de leads y clientes habla con su
     * asistente, como siempre.
     *
     * @return void
     */
    public function test_sin_numero_del_asistente_el_dueno_sigue_hablando_con_el_asistente_en_el_principal(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);

        $this->postear_webhook($this->entrante_al_principal('+' . self::TELEFONO_DUENO, 'Cuánto vendí ayer?'))
            ->assertStatus(200);

        $this->assertSame(1, ClientAssistantMessage::where('client_id', $client->id)->count());
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertCount(0, $espia->textos, 'No se le manda ningún aviso: no hay número al que mandarlo.');
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
    }

    /**
     * 🔴 Con la fila del asistente a medias (alta hecha en el panel, webhook sin registrar), tampoco
     * cambia nada: el número todavía no recibe, así que no se lo puede ofrecer.
     *
     * @return void
     */
    public function test_con_el_numero_del_asistente_a_medias_no_cambia_nada(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_config_asistente(['webhook_secret' => null]);

        $this->postear_webhook($this->entrante_al_principal('+' . self::TELEFONO_DUENO, 'Hola'))
            ->assertStatus(200);

        $this->assertSame(1, ClientAssistantMessage::where('client_id', $client->id)->count());
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertCount(0, $espia->textos);
    }

    /**
     * Un payload que no trae `phone_number_id` entra por el principal, como antes de que existiera
     * el campo: es la compatibilidad con Kapso.
     *
     * @return void
     */
    public function test_un_payload_sin_phone_number_id_entra_por_el_principal(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_config_asistente();

        $this->postear_webhook($this->payload_de_texto('+' . self::TELEFONO_DUENO, 'Hola'))
            ->assertStatus(200);

        /* Con el asistente listo, el dueño que entra por el principal recibe el aviso: eso prueba que
         * el payload sin el campo se resolvió como principal. */
        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());
        $this->assertCount(1, $espia->textos);
    }

    /**
     * Un `phone_number_id` que no es de ninguna fila cae al principal, con las mismas reglas.
     *
     * @return void
     */
    public function test_un_phone_number_id_desconocido_cae_al_principal(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);

        $this->postear_webhook(
            $this->payload_de_texto('+' . self::TELEFONO_DUENO, 'Hola', 'wamid.X1', null, '999999999999')
        )->assertStatus(200);

        $this->assertSame(1, ClientAssistantMessage::where('client_id', $client->id)->count(), 'Como hoy: sin número del asistente, el asistente contesta por el principal.');
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Lo que entra por el número del asistente
    // ---------------------------------------------------------------------------------------------

    /**
     * El dueño que le escribe al número del asistente habla con el asistente, y la fila queda con el
     * número por el que entró.
     *
     * @return void
     */
    public function test_el_dueno_en_el_numero_del_asistente_habla_con_el_asistente(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->entrante_al_asistente(self::TELEFONO_DUENO, 'Cuánto vendí ayer?'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $fila = ClientAssistantMessage::where('client_id', $client->id)->first();

        $this->assertNotNull($fila);
        $this->assertSame('Cuánto vendí ayer?', $fila->texto);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, $fila->phone_number_id);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * 🔴 Un desconocido que le escribe al número del asistente NO crea un lead, ni un ticket: recibe un
     * texto corto, y sale por el número del asistente.
     *
     * @return void
     */
    public function test_un_desconocido_en_el_numero_del_asistente_no_crea_lead_y_recibe_un_texto(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_config_asistente();

        $leads_antes = Lead::count();

        $this->postear_webhook(
            $this->entrante_al_asistente('+5493414445566', 'Hola, quiero info'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame($leads_antes, Lead::count(), 'No se crea ningún lead por escribirle al asistente.');
        $this->assertSame(0, SupportTicket::count());
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('+5493414445566', $espia->textos[0]['to']);
        $this->assertStringContainsString('asistente de ComercioCity', $espia->textos[0]['body']);
        $this->assertSame(
            [self::PHONE_NUMBER_ID_ASISTENTE],
            $espia->destinos,
            'La respuesta sale por el MISMO número al que escribió: por el otro abriría un chat distinto.'
        );
    }

    /**
     * Un empleado del dueño tampoco entra: el asistente es solo del dueño.
     *
     * @return void
     */
    public function test_un_empleado_en_el_numero_del_asistente_recibe_el_texto_y_no_entra(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_empleado($client, '+5493419999999');
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->entrante_al_asistente('5493419999999', 'Tengo una duda'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertCount(1, $espia->textos);
    }

    /**
     * El dueño con el canal apagado recibe el texto corto, no entra al asistente.
     *
     * @return void
     */
    public function test_el_dueno_con_el_canal_apagado_recibe_el_texto_en_el_numero_del_asistente(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, false);
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->entrante_al_asistente(self::TELEFONO_DUENO),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());
        $this->assertCount(1, $espia->textos);
    }

    /**
     * 🔴 La ficha del dueño sin el prefijo del país (`3411234567`) sigue sirviendo: WhatsApp lo manda
     * como `5493411234567` y es el mismo número.
     *
     * @return void
     */
    public function test_la_ficha_sin_prefijo_del_pais_matchea_al_dueno(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $client = $this->crear_cliente('3411234567', true);
        $this->crear_config_asistente();

        $this->postear_webhook(
            $this->entrante_al_asistente(self::TELEFONO_DUENO),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(1, ClientAssistantMessage::where('client_id', $client->id)->count());
    }

    /**
     * 🔴 Dos números con los mismos últimos dígitos NO son el mismo dueño (Rosario y Córdoba): con el
     * criterio de siempre —últimos ocho dígitos— uno entraría al asistente del otro.
     *
     * @return void
     */
    public function test_un_numero_parecido_no_entra_al_asistente_de_otro_dueno(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+5493411234567', true);
        $this->crear_config_asistente();

        // Mismo abonado, otra característica (Córdoba): coinciden los últimos 7 dígitos.
        $this->postear_webhook(
            $this->entrante_al_asistente('5493511234567'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());
        $this->assertCount(1, $espia->textos, 'Recibe el texto de ajeno, no el asistente del otro.');
    }

    /**
     * A quien escribe desde otro país no se le contesta: el envío normaliza el destino como argentino
     * y el mensaje saldría hacia un número inexistente.
     *
     * @return void
     */
    public function test_un_remitente_de_otro_pais_no_recibe_respuesta(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_config_asistente();

        $leads_antes = Lead::count();

        $this->postear_webhook(
            $this->entrante_al_asistente('+15551234567', 'Hello'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertCount(0, $espia->textos);
        $this->assertSame($leads_antes, Lead::count());
    }

    /**
     * El texto que recibe el ajeno se puede cambiar desde `admin_settings` sin tocar código.
     *
     * @return void
     */
    public function test_el_texto_del_ajeno_se_configura(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_asistente();
        AdminSetting::set(AsistenteWhatsappSettings::KEY_ASISTENTE_TEXTO_AJENO, 'Texto propio de la prueba.');

        $this->postear_webhook(
            $this->entrante_al_asistente('+5493414445566'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame('Texto propio de la prueba.', $espia->textos[0]['body']);
    }

    // ---------------------------------------------------------------------------------------------
    // 3. El dueño que escribe al número equivocado
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Con el número del asistente listo, el dueño que escribe al de leads y clientes recibe el aviso
     * con el número correcto —por el número por el que escribió— y no habla con el asistente ni abre
     * ticket.
     *
     * @return void
     */
    public function test_el_dueno_que_escribe_al_principal_recibe_el_numero_del_asistente(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_config_asistente();

        $this->postear_webhook($this->entrante_al_principal('+' . self::TELEFONO_DUENO, 'Cuánto vendí?'))
            ->assertStatus(200);

        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count(), 'No habla con el asistente por el número equivocado.');
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count(), 'Y no abre ticket.');
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);

        $this->assertCount(1, $espia->textos);
        $aviso = $espia->textos[0]['body'];
        $this->assertStringContainsString('+1 201-586-6682', $aviso);
        $this->assertStringContainsString('https://wa.me/12015866682', $aviso);
        $this->assertSame([self::PHONE_NUMBER_ID_PRINCIPAL], $espia->destinos, 'El aviso sale por el número al que escribió.');
    }

    /**
     * El aviso no se repite en una ráfaga: tres mensajes seguidos reciben UNA sola respuesta.
     *
     * @return void
     */
    public function test_el_aviso_no_se_repite_en_una_rafaga(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_config_asistente();

        foreach (['wamid.R1', 'wamid.R2', 'wamid.R3'] as $wamid) {
            $this->postear_webhook($this->entrante_al_principal('+' . self::TELEFONO_DUENO, 'Hola', $wamid))
                ->assertStatus(200);
        }

        $this->assertCount(1, $espia->textos);
    }

    /**
     * El empleado que escribe al número de leads y clientes NO recibe el aviso del asistente: el
     * asistente es del dueño. Sigue por su camino de siempre (soporte, desconectado).
     *
     * @return void
     */
    public function test_un_empleado_no_recibe_el_aviso_del_numero_del_asistente(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true);
        $this->crear_empleado($client, '+5493419999999');
        $this->crear_config_asistente();

        $this->postear_webhook($this->entrante_al_principal('+5493419999999', 'Tengo una duda', 'wamid.EMP'))
            ->assertStatus(200);

        foreach ($espia->textos as $texto) {
            $this->assertStringNotContainsString('wa.me', $texto['body']);
        }
    }

    /**
     * Un lead desconocido que escribe al número de leads y clientes sigue creando su lead, con el
     * asistente listo: el pipeline comercial no se toca.
     *
     * @return void
     */
    public function test_un_lead_nuevo_en_el_principal_sigue_entrando_al_pipeline(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $this->crear_config_asistente();

        $this->postear_webhook($this->entrante_al_principal('+5493414445566', 'Hola, vi el anuncio', 'wamid.LEAD1'))
            ->assertStatus(200);

        $this->assertSame(1, Lead::where('phone', 'like', '%3414445566')->count(), 'El lead entró por el pipeline de siempre.');
    }

    // ---------------------------------------------------------------------------------------------
    // 4. Reacciones
    // ---------------------------------------------------------------------------------------------

    /**
     * Una reacción que entra por el número del asistente no se aplica a ningún mensaje de lead.
     *
     * @return void
     */
    public function test_una_reaccion_en_el_numero_del_asistente_no_toca_leads(): void
    {
        $this->espiar_sender();
        $this->crear_config_asistente();

        $lead = Lead::create(['contact_name' => 'Lead de prueba', 'phone' => '5493414445566', 'status' => 'calificado']);
        $mensaje = LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'lead',
            'content'             => 'Hola!',
            'status'              => 'enviado',
            'kind'                => 'text',
            'is_status_event'     => false,
            'is_error'            => false,
            'sent_at'             => now(),
            'whatsapp_message_id' => 'wamid.DEL-LEAD',
        ]);

        $payload = $this->entrante_al_asistente('5493414445566', '', 'wamid.REACCION');
        $payload['message']['type']     = 'reaction';
        $payload['message']['reaction'] = ['message_id' => 'wamid.DEL-LEAD', 'emoji' => '👍'];
        unset($payload['message']['text']);

        $this->postear_webhook($payload, self::SECRETO_DEL_ASISTENTE)->assertStatus(200);

        $this->assertNull($mensaje->fresh()->lead_reaction_emoji, 'Por el número del asistente no hay leads que reaccionen.');

        // Y por el número de leads y clientes, la MISMA reacción sí se aplica: la guarda es del número.
        $payload                         = $this->entrante_al_principal('5493414445566', '', 'wamid.REACCION2');
        $payload['message']['type']      = 'reaction';
        $payload['message']['reaction']  = ['message_id' => 'wamid.DEL-LEAD', 'emoji' => '👍'];
        unset($payload['message']['text']);

        $this->postear_webhook($payload)->assertStatus(200);

        $this->assertSame('👍', $mensaje->fresh()->lead_reaction_emoji);
    }
}
