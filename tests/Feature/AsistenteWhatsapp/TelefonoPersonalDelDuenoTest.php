<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteInformesService;
use App\Services\AsistenteRecordatoriosService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * El teléfono PERSONAL del dueño, separado del teléfono del negocio (misión telefono-personal-del-dueno,
 * 2/11/2026).
 *
 * `clients.phone` es el WhatsApp del NEGOCIO (el que atiende Kapso) y `clients.asistente_phone` el del DUEÑO como
 * persona. Lo que se cuida acá:
 *
 *   1. 🔴 Compatibilidad hacia atrás: un cliente SIN `asistente_phone` se reconoce y recibe todo exactamente como
 *      antes (el teléfono de la ficha).
 *   2. Un cliente CON `asistente_phone` se reconoce ante el agente personal SOLO por ese número; el del negocio
 *      deja de identificarlo, y un empleado con el número personal sigue excluido.
 *   3. Los mensajes al dueño como persona (informes, recordatorios) salen al personal y, sin él, a `phone`.
 *
 * Los teléfonos de las pruebas son a propósito distintos en los últimos ocho dígitos de cualquier cosa que haya
 * quedado en la base del slot: `phones_match()` compara por sufijo y un teléfono parecido a una ficha ajena
 * mediría esa ficha.
 */
class TelefonoPersonalDelDuenoTest extends BaseDelCanal
{
    /** Teléfono del NEGOCIO (el de la ficha). */
    const NEGOCIO = '+5493445667788';

    /** Teléfono PERSONAL del dueño. */
    const PERSONAL = '+5493415883322';

    /**
     * Configuración principal activa: sin ella el webhook contesta 503.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
    }

    /**
     * Cliente con los dos teléfonos y el canal prendido.
     *
     * @param string|null $personal Teléfono personal (null = no tiene).
     *
     * @return Client
     */
    private function cliente_con_personal(?string $personal = self::PERSONAL): Client
    {
        $client                  = $this->crear_cliente(self::NEGOCIO, true);
        $client->asistente_phone = $personal;
        $client->save();

        return $client->fresh();
    }

    /**
     * Cuántas filas del hilo del asistente tiene el cliente.
     *
     * @param Client $client Cliente.
     *
     * @return int
     */
    private function filas_del_asistente(Client $client): int
    {
        return ClientAssistantMessage::where('client_id', $client->id)->count();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Sin teléfono personal, todo como hoy
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Regresión: sin `asistente_phone`, el dueño escribe desde el teléfono de la ficha y va al asistente.
     *
     * @return void
     */
    public function test_sin_telefono_personal_el_dueno_se_reconoce_por_el_de_la_ficha(): void
    {
        Queue::fake();
        $client = $this->cliente_con_personal(null);

        $this->postear_webhook($this->payload_de_texto(self::NEGOCIO, 'Cuánto vendí ayer?'))->assertStatus(200);

        $this->assertSame(1, $this->filas_del_asistente($client));
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * 🔴 Regresión: un `asistente_phone` vacío ("") cuenta como no tener ninguno.
     *
     * @return void
     */
    public function test_un_telefono_personal_vacio_cuenta_como_no_tener(): void
    {
        Queue::fake();
        $client = $this->cliente_con_personal('');

        $this->assertFalse($client->tiene_telefono_personal_propio());
        $this->assertSame(self::NEGOCIO, $client->telefono_personal());

        $this->postear_webhook($this->payload_de_texto(self::NEGOCIO, 'Hola'))->assertStatus(200);

        $this->assertSame(1, $this->filas_del_asistente($client));
    }

    /**
     * 🔴 Regresión: sin `asistente_phone` y con `phone` vacío, el repliegue por lead promovido sigue reconociendo
     * al dueño por el teléfono del lead.
     *
     * @return void
     */
    public function test_sin_telefono_personal_el_lead_promovido_sigue_reconociendo_al_dueno(): void
    {
        Queue::fake();
        $this->crear_config_asistente();
        $client = $this->cliente_con_personal(null);
        $client->phone = '';
        $client->save();

        $lead                       = new Lead();
        $lead->contact_name         = 'Dueño promovido';
        $lead->phone                = self::NEGOCIO;
        $lead->promoted_client_id   = $client->id;
        $lead->save();

        $this->postear_webhook(
            $this->payload_de_texto(self::NEGOCIO, 'Hola', 'wamid.LEAD-PROMOVIDO', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(1, $this->filas_del_asistente($client));
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Con teléfono personal, se reconoce SOLO por ese número
    // ---------------------------------------------------------------------------------------------

    /**
     * El dueño que escribe desde su teléfono PERSONAL al número principal habla con su asistente.
     *
     * @return void
     */
    public function test_con_telefono_personal_el_dueno_escribe_desde_el_personal_y_va_al_asistente(): void
    {
        Queue::fake();
        $client = $this->cliente_con_personal();

        $this->postear_webhook($this->payload_de_texto(self::PERSONAL, 'Cuánto vendí ayer?'))->assertStatus(200);

        $this->assertSame(1, $this->filas_del_asistente($client));
        $this->assertSame(self::PERSONAL, ClientAssistantMessage::where('client_id', $client->id)->first()->telefono);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * 🔴 El teléfono del NEGOCIO, con teléfono personal cargado, ya no habla con el asistente: cae a soporte como
     * cualquier otro contacto del cliente.
     *
     * @return void
     */
    public function test_con_telefono_personal_el_del_negocio_ya_no_habla_con_el_asistente(): void
    {
        Queue::fake();
        $client = $this->cliente_con_personal();

        $this->postear_webhook($this->payload_de_texto(self::NEGOCIO, 'Hola'))->assertStatus(200);

        $this->assertSame(0, $this->filas_del_asistente($client), 'El del negocio no es el dueño ante el asistente.');
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }

    /**
     * En el número del ASISTENTE pasa lo mismo: el personal entra, el del negocio es un remitente ajeno.
     *
     * @return void
     */
    public function test_en_el_numero_del_asistente_entra_el_personal_y_no_el_del_negocio(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $this->crear_config_asistente();
        $client = $this->cliente_con_personal();

        $this->postear_webhook(
            $this->payload_de_texto(self::NEGOCIO, 'Hola desde el del negocio', 'wamid.NEGOCIO', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(0, $this->filas_del_asistente($client));

        $this->postear_webhook(
            $this->payload_de_texto(self::PERSONAL, 'Hola desde el personal', 'wamid.PERSONAL', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(1, $this->filas_del_asistente($client));
    }

    /**
     * Un empleado con el número personal del dueño sigue excluido: el asistente es del dueño y de nadie más.
     *
     * @return void
     */
    public function test_un_empleado_con_el_numero_personal_sigue_excluido(): void
    {
        Queue::fake();
        $this->crear_config_asistente();
        $client = $this->cliente_con_personal();
        $this->crear_empleado($client, self::PERSONAL);

        $this->postear_webhook(
            $this->payload_de_texto(self::PERSONAL, 'Hola', 'wamid.EMPLEADO', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(0, $this->filas_del_asistente($client));
    }

    /**
     * Con teléfono personal, el lead promovido (que suele traer el número del negocio) no reconoce al dueño.
     *
     * @return void
     */
    public function test_con_telefono_personal_el_lead_promovido_no_reconoce_al_dueno(): void
    {
        Queue::fake();
        $this->crear_config_asistente();
        $client = $this->cliente_con_personal();
        $client->phone = '';
        $client->save();

        $lead                     = new Lead();
        $lead->contact_name       = 'Dueño promovido';
        $lead->phone              = self::NEGOCIO;
        $lead->promoted_client_id = $client->id;
        $lead->save();

        $this->postear_webhook(
            $this->payload_de_texto(self::NEGOCIO, 'Hola', 'wamid.LEAD-PROMOVIDO-2', null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        $this->assertSame(0, $this->filas_del_asistente($client));
    }

    /**
     * Con la casilla apagada, el personal tampoco habla con el asistente (el interruptor sigue mandando).
     *
     * @return void
     */
    public function test_con_la_casilla_apagada_el_personal_no_habla_con_el_asistente(): void
    {
        Queue::fake();
        $client                            = $this->cliente_con_personal();
        $client->asistente_whatsapp_activo = false;
        $client->save();

        $this->postear_webhook($this->payload_de_texto(self::PERSONAL, 'Hola'))->assertStatus(200);

        $this->assertSame(0, $this->filas_del_asistente($client));
    }

    // ---------------------------------------------------------------------------------------------
    // 3. Los mensajes al dueño como persona
    // ---------------------------------------------------------------------------------------------

    /**
     * Un informe del mostrador sale al teléfono PERSONAL cuando el cliente lo tiene.
     *
     * @return void
     */
    public function test_el_informe_sale_al_telefono_personal(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->cliente_con_personal();
        $this->abrir_la_ventana_de_los_informes(self::PERSONAL);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(self::PERSONAL, $espia->textos[0]['to']);
    }

    /**
     * 🔴 Regresión: sin teléfono personal el informe sale al de la ficha, como siempre.
     *
     * @return void
     */
    public function test_el_informe_sin_telefono_personal_sale_al_de_la_ficha(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->cliente_con_personal(null);
        $this->abrir_la_ventana_de_los_informes(self::NEGOCIO);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertSame(self::NEGOCIO, $espia->textos[0]['to']);
    }

    /**
     * Un recordatorio sale al teléfono PERSONAL cuando el cliente lo tiene.
     *
     * @return void
     */
    public function test_el_recordatorio_sale_al_telefono_personal(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->cliente_con_personal();
        $this->abrir_la_ventana_del_asistente($client, self::PERSONAL);

        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response($this->recordatorios_pendientes(), 200),
            '*/asistente/recordatorios/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(self::PERSONAL, $espia->textos[0]['to']);
    }

    /**
     * 🔴 Regresión: sin teléfono personal el recordatorio sale al de la ficha, como siempre.
     *
     * @return void
     */
    public function test_el_recordatorio_sin_telefono_personal_sale_al_de_la_ficha(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->cliente_con_personal(null);
        $this->abrir_la_ventana_del_asistente($client, self::NEGOCIO);

        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response($this->recordatorios_pendientes(), 200),
            '*/asistente/recordatorios/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame(self::NEGOCIO, $espia->textos[0]['to']);
    }

    /**
     * El barrido de recordatorios incluye a un cliente que solo tiene el teléfono personal cargado.
     *
     * @return void
     */
    public function test_un_cliente_con_solo_el_telefono_personal_recibe_recordatorios(): void
    {
        $client                  = $this->cliente_con_personal();
        $client->phone           = '';
        $client->save();

        $this->assertTrue(AsistenteRecordatoriosService::cliente_recibe_recordatorios($client->fresh()));
    }

    // ---------------------------------------------------------------------------------------------
    // Armado
    // ---------------------------------------------------------------------------------------------

    /**
     * Fila entrante del dueño al asistente, para abrir la ventana de 24 hs de ese teléfono.
     *
     * @param Client $client   Cliente.
     * @param string $telefono Teléfono desde el que "escribió".
     *
     * @return void
     */
    private function abrir_la_ventana_del_asistente(Client $client, string $telefono): void
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = $telefono;
        $fila->phone_number_id     = self::PHONE_NUMBER_ID_PRINCIPAL;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ABRE-VENTANA-' . md5($telefono);
        $fila->tipo                = 'text';
        $fila->texto               = 'Buen día';
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->ai_conversation_id  = 77;
        $fila->save();
    }

    /**
     * Abre la ventana de 24 hs que mide el servicio de informes (soporte, leads e implementación del número
     * principal) metiendo un entrante reciente de un lead con ese teléfono: es el mismo atajo que usa
     * `InformesDeLaMananaTest`.
     *
     * @param string $telefono Teléfono del dueño.
     *
     * @return void
     */
    private function abrir_la_ventana_de_los_informes(string $telefono): void
    {
        $lead               = new Lead();
        $lead->contact_name = 'Dueño de prueba';
        $lead->phone        = $telefono;
        $lead->save();

        $mensaje                      = new LeadMessage();
        $mensaje->lead_id             = $lead->id;
        $mensaje->sender              = 'lead';
        $mensaje->content             = 'Buen día';
        $mensaje->whatsapp_message_id = 'wamid.ABRE-LA-VENTANA-' . md5($telefono);
        $mensaje->save();
    }

    /**
     * Respuesta de `informes-pendientes` con un informe.
     *
     * @return array<string, mixed>
     */
    private function informes_del_dia(): array
    {
        return [
            'informes' => [[
                'id'      => 11,
                'tipo'    => 'rendimiento',
                'titulo'  => 'Rendimiento de ayer',
                'resumen' => 'Vendiste $184.500 en 23 ventas.',
                'url'     => 'https://ferreteria.comerciocity.com/informe/abc123',
            ]],
        ];
    }

    /**
     * Respuesta de `recordatorios-pendientes` con un recordatorio de hoy.
     *
     * @return array<string, mixed>
     */
    private function recordatorios_pendientes(): array
    {
        return [
            'recordatorios' => [[
                'id'                 => 'tarea-57:' . now()->toDateString() . ':1000',
                'tarea_id'           => 57,
                'fecha'              => now()->toDateString(),
                'hora'               => '10:00',
                'detalle'            => 'Llamar a Juan',
                'texto'              => 'Te recuerdo: llamar a Juan.',
                'ai_conversation_id' => 77,
                'ruta'               => '/agenda',
            ]],
        ];
    }
}
