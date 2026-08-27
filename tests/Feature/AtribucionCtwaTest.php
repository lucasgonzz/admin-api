<?php

namespace Tests\Feature;

use App\Helpers\WhatsappNormalizer;
use App\Models\Lead;
use App\Models\WhatsappAdReferral;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Atribución Click-to-WhatsApp: qué anuncio de Meta trajo a cada teléfono.
 *
 * El payload procesado de Kapso no trae el bloque `referral` ni el `ctwa_clid` —tiene campos
 * fijos—, así que la atribución entra por el webhook CRUDO de Meta, que Kapso reenvía como
 * modalidad aparte (`kind: meta`) y que convive con el que ya está funcionando.
 *
 * 🔴 Lo que más importa verificar acá no es lo que el endpoint hace, sino lo que NO hace: no crea
 * leads. Los dos webhooks reciben el mismo mensaje; si este camino también diera de alta al lead,
 * cada conversación que entra por un anuncio quedaría duplicada y nada lo denunciaría hasta que
 * el lead conteste dos veces.
 */
class AtribucionCtwaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Secreto del webhook con el que se firman los payloads de las pruebas.
     */
    private const SECRETO = 'secreto-de-prueba-del-webhook';

    /**
     * Teléfono de quien tocó el anuncio, tal como lo manda Meta (sin `+`).
     */
    private const TELEFONO_META = '5493414443322';

    /**
     * Deja el entorno sin red y con una configuración de WhatsApp activa.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

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
     * Pega al endpoint real con el body crudo firmado.
     *
     * @param array<string, mixed> $payload Cuerpo del webhook.
     * @param string|null          $firma   Firma a mandar; null usa la correcta.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_meta_raw(array $payload, ?string $firma = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhook/meta-raw', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => $firma !== null ? $firma : hash_hmac('sha256', $body, self::SECRETO),
        ], $body);
    }

    /**
     * Payload crudo de Meta con un mensaje que trae el bloque `referral`.
     *
     * @param string $wamid ID del mensaje de Meta (clave de idempotencia).
     *
     * @return array<string, mixed>
     */
    private function payload_con_referral(string $wamid = 'wamid.CTWA1'): array
    {
        return $this->payload_crudo([
            'from'      => self::TELEFONO_META,
            'id'        => $wamid,
            'timestamp' => '1756300000',
            'type'      => 'text',
            'text'      => ['body' => 'Hola, vi el anuncio'],
            'referral'  => [
                'source_url'  => 'https://fb.me/anuncio-de-prueba',
                'source_id'   => '120210000000000000',
                'source_type' => 'ad',
                'headline'    => 'Ordená tu negocio',
                'body'        => 'Probá ComercioCity',
                'media_type'  => 'image',
                'image_url'   => 'https://scontent.example/imagen.jpg',
                'ctwa_clid'   => 'ARAbcDEF123456',
            ],
        ]);
    }

    /**
     * Envoltorio crudo de Meta alrededor de un mensaje.
     *
     * @param array<string, mixed> $message Mensaje a envolver.
     *
     * @return array<string, mixed>
     */
    private function payload_crudo(array $message): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => '102030405060708',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata'          => ['display_phone_number' => '5493410000000', 'phone_number_id' => '1234567890'],
                        'contacts'          => [[
                            'profile' => ['name' => 'Marina López'],
                            'wa_id'   => self::TELEFONO_META,
                        ]],
                        'messages'          => [$message],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * Un referral válido se persiste con todos sus campos.
     *
     * @return void
     */
    public function test_un_referral_valido_se_guarda()
    {
        $response = $this->postear_meta_raw($this->payload_con_referral());

        $response->assertStatus(200);

        $referral = WhatsappAdReferral::query()->where('wamid', 'wamid.CTWA1')->first();

        $this->assertNotNull($referral, 'El referral tenía que quedar persistido.');
        $this->assertSame(WhatsappNormalizer::normalize(self::TELEFONO_META), $referral->phone);
        $this->assertSame('ARAbcDEF123456', $referral->ctwa_clid);
        $this->assertSame('120210000000000000', $referral->source_id);
        $this->assertSame('ad', $referral->source_type);
        $this->assertSame('https://fb.me/anuncio-de-prueba', $referral->source_url);
        $this->assertSame('Ordená tu negocio', $referral->headline);
        $this->assertSame('Probá ComercioCity', $referral->body);
        $this->assertSame('image', $referral->media_type);
        $this->assertSame('https://scontent.example/imagen.jpg', $referral->thumbnail_url);
        $this->assertNotNull($referral->received_at);
        $this->assertIsArray($referral->raw);
    }

    /**
     * Firma inválida: 401 y ni una fila escrita.
     *
     * @return void
     */
    public function test_una_firma_invalida_devuelve_401_y_no_escribe_nada()
    {
        $antes = WhatsappAdReferral::query()->count();

        $response = $this->postear_meta_raw($this->payload_con_referral(), 'firma-que-no-es');

        $response->assertStatus(401);
        $this->assertSame($antes, WhatsappAdReferral::query()->count());
    }

    /**
     * Sin ningún header de firma tampoco entra: falla cerrado.
     *
     * @return void
     */
    public function test_sin_firma_devuelve_401()
    {
        $antes = WhatsappAdReferral::query()->count();
        $body  = json_encode($this->payload_con_referral());

        $response = $this->call('POST', '/api/webhook/meta-raw', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
        $this->assertSame($antes, WhatsappAdReferral::query()->count());
    }

    /**
     * El mismo wamid dos veces deja una sola fila: Kapso reintenta y no puede duplicar la medición.
     *
     * @return void
     */
    public function test_el_mismo_wamid_dos_veces_deja_una_sola_fila()
    {
        $this->postear_meta_raw($this->payload_con_referral())->assertStatus(200);
        $this->postear_meta_raw($this->payload_con_referral())->assertStatus(200);

        $this->assertSame(1, WhatsappAdReferral::query()->where('wamid', 'wamid.CTWA1')->count());
    }

    /**
     * Un mensaje sin `referral` se contesta 200 y no escribe nada.
     *
     * El 200 es a propósito: un 4xx haría que Kapso reintente el mismo evento en loop, y no hay
     * nada que reintentar — si el mensaje no trae referral, no lo va a traer en el reintento.
     *
     * @return void
     */
    public function test_un_payload_sin_referral_no_escribe_y_contesta_200()
    {
        $antes = WhatsappAdReferral::query()->count();

        $response = $this->postear_meta_raw($this->payload_crudo([
            'from'      => self::TELEFONO_META,
            'id'        => 'wamid.SINREFERRAL',
            'timestamp' => '1756300000',
            'type'      => 'text',
            'text'      => ['body' => 'Hola, escribo por mi cuenta'],
        ]));

        $response->assertStatus(200);
        $this->assertSame($antes, WhatsappAdReferral::query()->count());
    }

    /**
     * Una forma que no reconocemos tampoco rompe: 200 y cero filas.
     *
     * @return void
     */
    public function test_un_payload_con_forma_desconocida_contesta_200()
    {
        $antes = WhatsappAdReferral::query()->count();

        $response = $this->postear_meta_raw(['algo' => 'que no es un webhook de meta']);

        $response->assertStatus(200);
        $this->assertSame($antes, WhatsappAdReferral::query()->count());
    }

    /**
     * 🔴 El endpoint NO crea leads, en ningún caso.
     *
     * @return void
     */
    public function test_el_endpoint_no_crea_ningun_lead()
    {
        $leads_antes = Lead::query()->count();

        $this->postear_meta_raw($this->payload_con_referral())->assertStatus(200);
        $this->postear_meta_raw($this->payload_con_referral('wamid.CTWA2'))->assertStatus(200);
        $this->postear_meta_raw($this->payload_con_referral(), 'firma-que-no-es')->assertStatus(401);

        $this->assertSame($leads_antes, Lead::query()->count(), 'El webhook de atribución no puede dar de alta leads.');
    }

    /**
     * El lead que aparece después engancha la atribución por teléfono.
     *
     * Es el orden real: el referral llega en el primer mensaje, el lead lo crea el webhook de
     * Kapso más tarde y por otro camino.
     *
     * @return void
     */
    public function test_el_lead_creado_despues_ve_su_atribucion()
    {
        $this->postear_meta_raw($this->payload_con_referral())->assertStatus(200);

        $lead         = new Lead();
        $lead->phone  = WhatsappNormalizer::normalize(self::TELEFONO_META);
        $lead->status = 'nuevo';
        $lead->save();

        $this->assertCount(1, $lead->whatsapp_ad_referrals()->get());
        $this->assertSame('ARAbcDEF123456', $lead->whatsapp_ad_referrals()->first()->ctwa_clid);
    }
}
