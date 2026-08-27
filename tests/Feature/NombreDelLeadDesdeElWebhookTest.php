<?php

namespace Tests\Feature;

use App\Helpers\WhatsappNormalizer;
use App\Models\Lead;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El nombre del contacto que manda Kapso tiene que llegar al lead.
 *
 * Hasta el 27/8/2026 `parse_inbound_message()` leía el nombre de
 * `conversation.kapso.contact_name`, una ruta que NO EXISTE: la doc de Kapso dice textual que
 * `conversation.kapso` trae solo métricas de resumen y «never contains contact_name». El nombre
 * llegaba en cada mensaje entrante y se tiraba. Resultado medido: el 61% de los leads de un año
 * quedó sin `contact_name`, y de ahí salió la cadena que rompió los seguimientos.
 *
 * Lo que se verifica acá es el endpoint real, con firma real y base de por medio: que la ruta
 * buena se lea, que la vieja SIGA leyéndose (el arreglo es aditivo, no un reemplazo) y que un
 * payload sin nombre por ninguna de las dos no rompa el alta del lead.
 */
class NombreDelLeadDesdeElWebhookTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Secreto del webhook con el que se firman los payloads de las pruebas.
     */
    private const SECRETO = 'secreto-de-prueba-del-webhook';

    /**
     * Teléfono del lead de las pruebas, en el formato en que lo manda Kapso.
     */
    private const TELEFONO = '+5493416665544';

    /**
     * Deja el entorno sin red y con una configuración de WhatsApp activa en modo prueba.
     *
     * `test_mode` evita que la bienvenida del onboarding salga de verdad; `Queue::fake()` frena el
     * job de presentación, que no es lo que se está midiendo acá.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();
    }

    /**
     * Pega al webhook real con el body crudo firmado igual que lo firma Kapso.
     *
     * Se usa `call()` y no `postJson()` a propósito: la firma es HMAC sobre el body EXACTO, así
     * que el test tiene que controlar el string que viaja, no dejar que lo arme el framework.
     *
     * @param array<string, mixed> $payload Cuerpo del webhook.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_webhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body);
    }

    /**
     * Payload de mensaje entrante con el formato procesado de Kapso.
     *
     * @param array<string, mixed> $conversation Bloque `conversation` a usar.
     * @param string               $wamid        ID del mensaje (idempotencia del webhook).
     *
     * @return array<string, mixed>
     */
    private function payload_entrante(array $conversation, string $wamid = 'wamid.NOMBRE1'): array
    {
        $conversation['phone_number'] = self::TELEFONO;

        return [
            'event'        => 'whatsapp.message.received',
            'conversation' => $conversation,
            'message'      => [
                'id'        => $wamid,
                'from'      => self::TELEFONO,
                'type'      => 'text',
                'text'      => ['body' => 'Hola, quiero ver el sistema'],
                'timestamp' => (string) time(),
            ],
        ];
    }

    /**
     * Lead recién creado para el teléfono de las pruebas.
     *
     * @return Lead|null
     */
    private function lead_del_telefono(): ?Lead
    {
        return Lead::query()
            ->where('phone', WhatsappNormalizer::normalize(self::TELEFONO))
            ->first();
    }

    /**
     * La ruta real de Kapso (`conversation.contact_name`) llega al lead.
     *
     * @return void
     */
    public function test_el_nombre_de_conversation_contact_name_queda_en_el_lead()
    {
        $response = $this->postear_webhook($this->payload_entrante([
            'contact_name' => 'Marina López',
        ]));

        $response->assertStatus(200);

        $lead = $this->lead_del_telefono();
        $this->assertNotNull($lead, 'El webhook tenía que crear el lead.');
        $this->assertSame('Marina López', $lead->contact_name);
    }

    /**
     * La ruta histórica no se sacó: si algún payload la trajera, se sigue leyendo.
     *
     * @return void
     */
    public function test_la_ruta_vieja_de_kapso_sigue_funcionando()
    {
        $response = $this->postear_webhook($this->payload_entrante([
            'kapso' => ['contact_name' => 'Nombre de la ruta vieja'],
        ]));

        $response->assertStatus(200);

        $lead = $this->lead_del_telefono();
        $this->assertNotNull($lead, 'El webhook tenía que crear el lead.');
        $this->assertSame('Nombre de la ruta vieja', $lead->contact_name);
    }

    /**
     * Con las dos rutas presentes gana la real, que es la que Kapso puebla de verdad.
     *
     * @return void
     */
    public function test_con_las_dos_rutas_gana_la_de_conversation()
    {
        $response = $this->postear_webhook($this->payload_entrante([
            'contact_name' => 'Marina López',
            'kapso'        => ['contact_name' => 'Nombre de la ruta vieja'],
        ]));

        $response->assertStatus(200);

        $lead = $this->lead_del_telefono();
        $this->assertNotNull($lead, 'El webhook tenía que crear el lead.');
        $this->assertSame('Marina López', $lead->contact_name);
    }

    /**
     * Un nombre en blanco es AUSENCIA de nombre, no un nombre vacío: se sigue con la ruta siguiente.
     *
     * @return void
     */
    public function test_un_nombre_en_blanco_no_tapa_la_ruta_vieja()
    {
        $response = $this->postear_webhook($this->payload_entrante([
            'contact_name' => '   ',
            'kapso'        => ['contact_name' => 'Nombre de la ruta vieja'],
        ]));

        $response->assertStatus(200);

        $lead = $this->lead_del_telefono();
        $this->assertNotNull($lead, 'El webhook tenía que crear el lead.');
        $this->assertSame('Nombre de la ruta vieja', $lead->contact_name);
    }

    /**
     * Sin nombre por ninguna de las dos rutas el lead se crea igual y el webhook no rompe.
     *
     * @return void
     */
    public function test_sin_nombre_el_lead_se_crea_igual()
    {
        $response = $this->postear_webhook($this->payload_entrante([]));

        $response->assertStatus(200);

        $lead = $this->lead_del_telefono();
        $this->assertNotNull($lead, 'El webhook tenía que crear el lead aunque no venga el nombre.');
        $this->assertSame('', trim((string) $lead->contact_name));
    }
}
