<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\AdminSetting;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\AsistenteInformesService;
use App\Services\AsistenteWhatsappSettings;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Support\Facades\Http;

/**
 * Por cuál número sale cada cosa del canal del asistente (misión asistente-segundo-numero).
 *
 * La regla, que es la que estas pruebas miden: **lo que es del asistente sale por el número del
 * asistente SOLO si ese número está listo** (fila activa con clave, secreto del webhook y número
 * visible); en cualquier otro estado sale por el de leads y clientes, igual que antes de la misión. Y
 * la ventana de 24 hs de Meta se mide sobre el MISMO número por el que sale, porque es por par de
 * números.
 */
class SalidaPorElNumeroDelAsistenteTest extends BaseDelCanal
{
    /**
     * El principal activo en todas: es el que existe en producción desde siempre.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
    }

    /**
     * Payload que devuelve el `empresa-api` con los informes del día.
     *
     * @return array<string, mixed>
     */
    private function informes_del_dia(): array
    {
        return [
            'informes' => [
                [
                    'id'      => 11,
                    'tipo'    => 'rendimiento',
                    'titulo'  => 'Rendimiento de ayer',
                    'resumen' => 'Vendiste $184.500 en 23 ventas.',
                    'url'     => 'https://ferreteria.comerciocity.com/informe/abc123',
                ],
            ],
        ];
    }

    /**
     * Plantilla de informes cargada y activa.
     *
     * @return void
     */
    private function cargar_plantilla_de_informes(): void
    {
        AdminSetting::set(AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME, 'informes_del_dia');

        $plantilla                = new ClientTemplate();
        $plantilla->template_name = 'informes_del_dia';
        $plantilla->language_code = 'es_AR';
        $plantilla->categoria     = 'informes';
        $plantilla->titulo        = 'Informes del día';
        $plantilla->body_template = 'Buen día. Te dejo tus informes de hoy: {{1}}';
        $plantilla->activa        = true;
        $plantilla->save();
    }

    /**
     * Abre la ventana por el pipeline de leads (el canal del número de leads y clientes).
     *
     * @param string $telefono
     *
     * @return void
     */
    private function abrir_ventana_del_principal(string $telefono): void
    {
        $lead = Lead::create(['contact_name' => 'Dueño de prueba', 'phone' => $telefono, 'status' => 'calificado']);

        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'lead',
            'content'             => 'Buen día',
            'whatsapp_message_id' => 'wamid.ABRE-EL-PRINCIPAL',
            'status'              => 'enviado',
            'kind'                => 'text',
            'is_status_event'     => false,
            'is_error'            => false,
            'sent_at'             => now(),
        ]);
    }

    /**
     * Abre la ventana por el canal del asistente: un entrante del dueño guardado en su hilo.
     *
     * @param \App\Models\Client $client
     *
     * @return void
     */
    private function abrir_ventana_del_asistente($client): void
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->phone_number_id     = self::PHONE_NUMBER_ID_ASISTENTE;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ABRE-EL-ASISTENTE';
        $fila->tipo                = 'text';
        $fila->texto               = 'Buen día';
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->save();
    }

    // ---------------------------------------------------------------------------------------------
    // La decisión «¿está listo?»
    // ---------------------------------------------------------------------------------------------

    /**
     * Solo un número con los CUATRO datos cargados está listo; cada faltante tiene su nombre.
     *
     * @return void
     */
    public function test_el_numero_del_asistente_esta_listo_solo_con_los_cuatro_datos(): void
    {
        $this->assertNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE), 'Sin fila no hay número.');
        $this->assertSame(WhatsappConfig::PROPOSITO_PRINCIPAL, WhatsappConfig::propositoEfectivoDelAsistente());

        foreach (['kapso_api_key', 'webhook_secret', 'display_phone', 'phone_number_id'] as $campo) {
            $config = $this->crear_config_asistente([$campo => '']);

            $this->assertNull(
                WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE),
                'Sin ' . $campo . ' el número no está listo.'
            );
            $this->assertSame([$campo], $config->loQueLeFaltaParaAtender());
            $this->assertSame(WhatsappConfig::PROPOSITO_PRINCIPAL, WhatsappConfig::propositoEfectivoDelAsistente());

            $config->delete();
        }

        $this->crear_config_asistente();
        $this->assertNotNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE));
        $this->assertSame(WhatsappConfig::PROPOSITO_ASISTENTE, WhatsappConfig::propositoEfectivoDelAsistente());
    }

    /**
     * `getActive()` sigue devolviendo el número de leads y clientes aunque exista el del asistente: lo
     * llaman ~20 lugares y ninguno se mudó.
     *
     * @return void
     */
    public function test_get_active_sigue_siendo_el_numero_de_leads_y_clientes(): void
    {
        $this->crear_config_asistente();

        $activa = WhatsappConfig::getActive();

        $this->assertNotNull($activa);
        $this->assertSame(self::PHONE_NUMBER_ID_PRINCIPAL, $activa->phone_number_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Respuestas del asistente
    // ---------------------------------------------------------------------------------------------

    /**
     * Con el número listo, la respuesta del asistente sale por él y la fila lo deja escrito.
     *
     * @return void
     */
    public function test_la_respuesta_sale_por_el_numero_del_asistente(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_config_asistente();
        $fila = $this->fila_entrante($client);

        $this->correr_la_respuesta($fila, $espia);

        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, $saliente->phone_number_id);
    }

    /**
     * 🔴 Sin número del asistente listo, la respuesta sale por el de leads y clientes, como siempre.
     *
     * @return void
     */
    public function test_sin_numero_del_asistente_la_respuesta_sale_por_el_principal(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->correr_la_respuesta($fila, $espia);

        $this->assertSame([self::PHONE_NUMBER_ID_PRINCIPAL], $espia->destinos);
    }

    /**
     * Con la fila a medias (sin secreto de webhook) la respuesta tampoco se muda: el dueño no podría
     * contestarle a ese número.
     *
     * @return void
     */
    public function test_con_el_numero_a_medias_la_respuesta_sale_por_el_principal(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_config_asistente(['webhook_secret' => null]);
        $fila = $this->fila_entrante($client);

        $this->correr_la_respuesta($fila, $espia);

        $this->assertSame([self::PHONE_NUMBER_ID_PRINCIPAL], $espia->destinos);
    }

    /**
     * Corre el job completo (ida + vuelta) sobre una fila entrante.
     *
     * @param ClientAssistantMessage $fila
     * @param \App\Services\WhatsappSendService $espia
     *
     * @return void
     */
    private function correr_la_respuesta(ClientAssistantMessage $fila, $espia): void
    {
        $this->fakear_http([
            '*/asistente/mensajes'     => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
            '*/asistente/mensajes/305' => Http::response(['estado' => 'listo', 'contenido' => 'Listo.', 'ai_conversation_id' => 91], 200),
        ]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr_job($job, $espia);
        $this->correr_job($job, $espia);
    }

    /**
     * Fila entrante tal como la deja el webhook.
     *
     * @param \App\Models\Client $client
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante($client): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Cuánto vendí ayer?';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    // ---------------------------------------------------------------------------------------------
    // Informes de la mañana y su ventana
    // ---------------------------------------------------------------------------------------------

    /**
     * Con el número listo y la ventana del asistente abierta, el informe sale como texto libre por él.
     *
     * @return void
     */
    public function test_el_informe_sale_como_texto_por_el_numero_del_asistente_con_su_ventana_abierta(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_config_asistente();
        $this->abrir_ventana_del_asistente($client);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);
    }

    /**
     * 🔴 Con el número listo, que el dueño le haya escrito al número de LEADS no abre la ventana del
     * asistente: el informe sale por plantilla, no como texto libre que Meta rechazaría.
     *
     * @return void
     */
    public function test_la_ventana_del_principal_no_abre_la_del_asistente(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_config_asistente();
        $this->cargar_plantilla_de_informes();
        $this->abrir_ventana_del_principal((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_plantilla', $resultado['estado']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);
    }

    /**
     * 🔴 Sin número del asistente listo, el informe se sigue midiendo y mandando como antes: la
     * ventana del pipeline de leads abre el texto libre, por el número de leads y clientes.
     *
     * @return void
     */
    public function test_sin_numero_del_asistente_el_informe_se_mide_como_siempre(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_ventana_del_principal((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertSame([self::PHONE_NUMBER_ID_PRINCIPAL], $espia->destinos);
    }

    // ---------------------------------------------------------------------------------------------
    // La ventana por número
    // ---------------------------------------------------------------------------------------------

    /**
     * La ventana del asistente mira SU hilo, y la de siempre no mira el hilo del asistente.
     *
     * @return void
     */
    public function test_la_ventana_se_mide_por_numero(): void
    {
        $client = $this->crear_cliente();

        /* Una instancia nueva por consulta: el servicio cachea las filas de cada canal por instancia. */
        $this->assertFalse((new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE));

        $this->abrir_ventana_del_asistente($client);

        $this->assertTrue((new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE));

        $this->assertFalse(
            (new WhatsappSessionWindowService())->is_open((string) $client->phone),
            'Sin propósito se miran las tres tablas de siempre, no el hilo del asistente.'
        );
    }
}
