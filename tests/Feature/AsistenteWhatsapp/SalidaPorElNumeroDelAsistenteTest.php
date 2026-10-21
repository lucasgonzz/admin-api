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
use App\Services\WhatsappSendService;
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
        $fila = $this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE);

        $this->correr_la_respuesta($fila, $espia);

        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, $saliente->phone_number_id);
    }

    /**
     * 🔴 La respuesta sale por el MISMO número por el que entró el mensaje: un mensaje que el dueño mandó al
     * número de leads y clientes —en vuelo el día del corte, o de un dueño al que el número del asistente
     * no reconoce— se contesta por ahí aunque el del asistente ya esté listo. Por el número nuevo sería
     * texto libre fuera de ventana, que Meta rechaza.
     *
     * @return void
     */
    public function test_un_mensaje_que_entro_por_el_principal_se_contesta_por_el_principal(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_config_asistente();

        $this->correr_la_respuesta($this->fila_entrante($client, self::PHONE_NUMBER_ID_PRINCIPAL), $espia);

        $this->assertSame([self::PHONE_NUMBER_ID_PRINCIPAL], $espia->destinos);
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
     * Un mensaje que entró por el dedicado no se traslada al comercial si el canal se incompletó.
     *
     * @return void
     */
    public function test_con_el_dedicado_a_medias_la_respuesta_se_detiene_sin_fallback(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_config_asistente(['webhook_secret' => null]);
        $fila = $this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE);

        $this->correr_la_respuesta($fila, $espia);

        $this->assertSame([], $espia->destinos);
        $this->assertSame([], $espia->textos);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_el_canal_que_cambia_mientras_se_consulta_la_respuesta_no_envia_por_otro_numero(): void
    {
        $espia = $this->espiar_sender();
        $client = $this->crear_cliente();
        $config = $this->crear_config_asistente();
        $fila = $this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE);
        $fila->ai_message_id = 305;
        $fila->estado = ClientAssistantMessage::ESTADO_ENVIADO;
        $fila->save();
        $this->fakear_http(['*/asistente/mensajes/305' => function () use ($config) {
            $config->display_phone = null;
            $config->save();
            return Http::response(['estado' => 'listo', 'contenido' => 'Listo.', 'ai_conversation_id' => 91], 200);
        }]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $this->assertSame([], $espia->destinos);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->fresh()->estado);
    }

    public function test_el_job_dedicado_desactivado_borrado_o_con_otro_id_tampoco_envia_disculpa_desde_principal(): void
    {
        $espia = $this->espiar_sender();
        $client = $this->crear_cliente();
        foreach (['is_active', 'phone_number_id', 'borrado'] as $cambio) {
            $config = $this->crear_config_asistente();
            $fila = $this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE);
            $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
            if ($cambio === 'borrado') {
                $config->delete();
            } else {
                $config->{$cambio} = $cambio === 'is_active' ? false : 'otro-id-dedicado';
                $config->save();
            }
            $job->failed(new \RuntimeException('Falló la tarea en espera.'));
            $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->fresh()->estado);
            if ($cambio !== 'borrado') { $config->delete(); }
        }
        $this->assertSame([], $espia->textos);
        $this->assertSame([], $espia->destinos);
        Http::assertNothingSent();
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
    private function fila_entrante($client, ?string $phone_number_id = null): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->phone_number_id     = $phone_number_id;
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
        $this->crear_config_asistente();

        /* Una instancia nueva por consulta: el servicio cachea las filas de cada canal por instancia. */
        $this->assertFalse((new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE));

        $this->abrir_ventana_del_asistente($client);

        $this->assertTrue((new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE));

        $this->assertFalse(
            (new WhatsappSessionWindowService())->is_open((string) $client->phone),
            'Sin propósito se miran las tres tablas de siempre, no el hilo del asistente.'
        );
    }

    /**
     * 🔴 Lo que el dueño le escribió al asistente por el número de LEADS Y CLIENTES (antes de que existiera
     * el número nuevo) no abre la ventana del número nuevo: Meta la abre por par de números. Contarlo daría
     * la ventana por abierta el día del corte y el informe saldría como texto libre, que Meta rechaza.
     *
     * @return void
     */
    public function test_lo_que_entro_por_el_principal_no_abre_la_ventana_del_asistente(): void
    {
        $client = $this->crear_cliente();
        $this->crear_config_asistente();

        foreach ([self::PHONE_NUMBER_ID_PRINCIPAL, null] as $numero) {
            $fila                      = new ClientAssistantMessage();
            $fila->client_id           = $client->id;
            $fila->telefono            = (string) $client->phone;
            $fila->phone_number_id     = $numero;
            $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
            $fila->whatsapp_message_id = 'wamid.VIEJO.' . ($numero === null ? 'null' : 'principal');
            $fila->tipo                = 'text';
            $fila->texto               = 'Hola';
            $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
            $fila->save();
        }

        $this->assertFalse(
            (new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE),
            'Ni el que entró por el principal ni el que no sabe por dónde entró abren la ventana del asistente.'
        );

        $this->abrir_ventana_del_asistente($client);

        $this->assertTrue((new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE));
    }

    /**
     * Sin fila del asistente no hay ventana del asistente que medir, tenga lo que tenga el hilo.
     *
     * @return void
     */
    public function test_sin_fila_del_asistente_la_ventana_del_asistente_esta_cerrada(): void
    {
        $client = $this->crear_cliente();
        $this->abrir_ventana_del_asistente($client);

        $this->assertFalse((new WhatsappSessionWindowService())->is_open((string) $client->phone, WhatsappConfig::PROPOSITO_ASISTENTE));
    }

    /**
     * 🔴 El modo de prueba de un número simula LOS ENVÍOS DE ESE NÚMERO y no los del otro. Antes, el corte
     * simulado de `send_text()` miraba siempre «la config activa» (la principal): con el asistente en
     * prueba, el envío no se simulaba y fallaba sin motivo.
     *
     * @return void
     */
    public function test_el_modo_de_prueba_es_de_cada_numero(): void
    {
        $this->crear_config_asistente(['test_mode' => true]);

        Http::fake();

        $id = (new WhatsappSendService())
            ->paraProposito(WhatsappConfig::PROPOSITO_ASISTENTE)
            ->send_text('+5493411234567', 'Hola');

        $this->assertNotNull($id, 'En modo de prueba el envío del asistente se simula y devuelve un id.');
        $this->assertStringStartsWith('test-', (string) $id);
        Http::assertNothingSent();

        // Y el principal, que NO está en prueba, sale de verdad por SU número.
        $this->fakear_http([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.REAL']]], 200),
        ]);

        $real = (new WhatsappSendService())->send_text('+5493411234567', 'Hola');

        $this->assertSame('wamid.REAL', $real);
        Http::assertSent(function ($request) {
            return strpos($request->url(), self::PHONE_NUMBER_ID_PRINCIPAL . '/messages') !== false;
        });
    }

    /**
     * Al revés: el principal en prueba no simula lo que sale por el asistente.
     *
     * @return void
     */
    public function test_el_principal_en_prueba_no_simula_los_envios_del_asistente(): void
    {
        \App\Models\WhatsappConfig::where('phone_number_id', self::PHONE_NUMBER_ID_PRINCIPAL)->update(['test_mode' => true]);
        $this->crear_config_asistente();

        $this->fakear_http([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.DEL.ASISTENTE']]], 200),
        ]);

        $id = (new WhatsappSendService())
            ->paraProposito(WhatsappConfig::PROPOSITO_ASISTENTE)
            ->send_text('+5493411234567', 'Hola');

        $this->assertSame('wamid.DEL.ASISTENTE', $id);
        Http::assertSent(function ($request) {
            return strpos($request->url(), self::PHONE_NUMBER_ID_ASISTENTE . '/messages') !== false;
        });
    }
}
