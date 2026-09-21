<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Services\SystemErrorWhatsappService;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;

/**
 * `WhatsappSendService::send_image_by_link()`: lo que sale hacia Kapso/Meta cuando se manda una
 * foto por link.
 *
 * Las pruebas del job (`FotosDelAsistenteTest`) miden con el espía, que reemplaza este método
 * entero. Estas miden el método REAL contra un `Http::fake()`, que es lo único que puede decir qué
 * payload viaja: `type: image` con `image: {link, caption}`, al endpoint de mensajes del número
 * configurado, con la clave de Kapso en el header y sin pasar por `/media`.
 *
 * Y miden lo que NO hace, que es lo que lo distingue de `send_text()`: por defecto un fallo no le
 * avisa a los admins. El aviso está throttleado a uno cada 10 minutos de forma global, y gastarlo en
 * una foto del catálogo deja mudo un fallo de envío real.
 */
class EnvioDeImagenPorLinkTest extends BaseDelCanal
{
    /**
     * Respuesta con la que Kapso confirma un envío.
     *
     * @param string $wamid
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function meta_confirma(string $wamid = 'wamid.IMAGEN.1')
    {
        return Http::response(['messages' => [['id' => $wamid]]], 200);
    }

    /**
     * El payload a Meta es una imagen por link con su caption, al endpoint del número configurado.
     *
     * @return void
     */
    public function test_el_payload_a_meta_es_una_imagen_por_link_con_caption(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => $this->meta_confirma('wamid.IMAGEN.1')]);

        $wamid = app(WhatsappSendService::class)->send_image_by_link(
            '+5493411234567',
            'https://r2.comerciocity.com/ferreteria/articulos/precinto-1.jpg',
            'Precintos 03 200 mm x 4,8 mm',
            'Asistente por WhatsApp - foto - cliente #7'
        );

        $this->assertSame('wamid.IMAGEN.1', $wamid);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.kapso.ai/meta/whatsapp/v24.0/1234567890/messages'
                && $request->method() === 'POST'
                && $request->hasHeader('X-API-Key', 'clave-kapso-de-prueba')
                && $request->data() === [
                    'messaging_product' => 'whatsapp',
                    'to'                => '5493411234567',
                    'type'              => 'image',
                    'image'             => [
                        'link'    => 'https://r2.comerciocity.com/ferreteria/articulos/precinto-1.jpg',
                        'caption' => 'Precintos 03 200 mm x 4,8 mm',
                    ],
                ];
        });

        /* Por link, no por media_id: nada pasa por el endpoint de subida. */
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/media') !== false;
        });
    }

    /**
     * Sin caption (null o en blanco) el payload no lleva la clave `caption`.
     *
     * Una clave `caption` vacía no es "sin epígrafe" para Meta: es un parámetro inválido.
     *
     * @return void
     */
    public function test_sin_caption_el_payload_no_lleva_la_clave_caption(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => $this->meta_confirma()]);

        $sender = app(WhatsappSendService::class);

        $this->assertNotNull($sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/a.jpg', null));
        $this->assertNotNull($sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/b.jpg', '   '));

        Http::assertSentCount(2);
        Http::assertNotSent(function ($request) {
            return array_key_exists('caption', (array) ($request->data()['image'] ?? []));
        });
    }

    /**
     * El número se normaliza igual que en `send_text()`: un local sin código de país sale como +549….
     *
     * @return void
     */
    public function test_el_numero_se_normaliza_como_en_send_text(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => $this->meta_confirma()]);

        app(WhatsappSendService::class)->send_image_by_link('341 123-4567', 'https://r2.comerciocity.com/a.jpg');

        Http::assertSent(function ($request) {
            return ($request->data()['to'] ?? null) === '5493411234567';
        });
    }

    /**
     * Un link que no es `http(s)` no sale a la red, y el motivo queda escrito.
     *
     * @return void
     */
    public function test_un_link_que_no_es_http_no_sale_a_la_red(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => $this->meta_confirma()]);

        $sender = app(WhatsappSendService::class);

        foreach (['ftp://r2.comerciocity.com/a.jpg', '/storage/a.jpg', '', '   ', 'httpx://nada'] as $link) {
            $this->assertNull($sender->send_image_by_link('+5493411234567', $link), 'No tenía que salir: ' . $link);
            $this->assertStringContainsString('http', (string) $sender->last_send_error);
        }

        Http::assertNothingSent();
    }

    /**
     * Un número inválido no sale a la red.
     *
     * @return void
     */
    public function test_un_numero_invalido_no_sale_a_la_red(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => $this->meta_confirma()]);

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_link('sin-numero', 'https://r2.comerciocity.com/a.jpg'));
        $this->assertStringContainsString('inválido', (string) $sender->last_send_error);

        Http::assertNothingSent();
    }

    /**
     * 🔴 Un rechazo de Meta devuelve null con el motivo y el status, y NO avisa a los admins.
     *
     * Es la diferencia con `send_text()`: `$skip_failure_notification` va en true por defecto. El
     * texto ya salió; una foto que no sale es una línea de log del llamador, no un incidente.
     *
     * @return void
     */
    public function test_un_rechazo_de_meta_devuelve_null_con_el_motivo_y_sin_avisar_a_los_admins(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http([
            '*' => Http::response(['error' => ['message' => 'Media download failed', 'code' => 131053]], 400),
        ]);

        $this->mock(SystemErrorWhatsappService::class, function ($mock) {
            $mock->shouldNotReceive('notify_send_error');
        });

        $sender = app(WhatsappSendService::class);

        $wamid = $sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/caida.jpg', 'Foto');

        $this->assertNull($wamid);
        $this->assertNotNull($sender->last_send_error, 'El motivo tiene que quedar para que el llamador lo loguee.');
        $this->assertSame(400, $sender->last_send_status_code);

        /* Salió solo el intento (con sus reintentos) al endpoint de mensajes: ninguna notificación. */
        Http::assertNotSent(function ($request) {
            return ($request->data()['type'] ?? null) !== 'image';
        });
    }

    /**
     * Con `$skip_failure_notification` en false explícito, el fallo SÍ avisa a los admins.
     *
     * Pinza el valor por defecto: si alguien lo diera vuelta, esta y la anterior no pueden estar
     * verdes a la vez.
     *
     * @return void
     */
    public function test_pidiendolo_explicitamente_el_fallo_si_avisa_a_los_admins(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => Http::response(['error' => ['message' => 'Boom']], 500)]);

        $this->mock(SystemErrorWhatsappService::class, function ($mock) {
            $mock->shouldReceive('notify_send_error')->once();
        });

        $this->assertNull(
            app(WhatsappSendService::class)->send_image_by_link(
                '+5493411234567',
                'https://r2.comerciocity.com/a.jpg',
                null,
                'Contexto explícito',
                false
            )
        );
    }

    /**
     * Una respuesta 200 sin `messages[0].id` es un fallo: null y motivo.
     *
     * @return void
     */
    public function test_una_respuesta_sin_message_id_devuelve_null(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => Http::response(['ok' => true], 200)]);

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/a.jpg'));
        $this->assertStringContainsString('message_id', (string) $sender->last_send_error);
    }

    /**
     * Sin configuración activa de WhatsApp no sale nada, y el motivo queda igual.
     *
     * @return void
     */
    public function test_sin_configuracion_activa_devuelve_null_sin_salir_a_la_red(): void
    {
        $this->fakear_http(['*' => $this->meta_confirma()]);

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/a.jpg'));
        $this->assertNotNull($sender->last_send_error);

        Http::assertNothingSent();
    }

    /**
     * En `test_mode` devuelve un id simulado sin salir a la red, igual que `send_text()`.
     *
     * Sin esto el llamador loguearía "la foto no salió" sin motivo por algo que no es un fallo.
     *
     * @return void
     */
    public function test_en_test_mode_devuelve_un_id_simulado_sin_salir_a_la_red(): void
    {
        $config            = $this->crear_config_whatsapp();
        $config->test_mode = true;
        $config->save();

        $this->fakear_http(['*' => $this->meta_confirma()]);

        $sender = app(WhatsappSendService::class);
        $wamid  = $sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/a.jpg', 'Foto');

        $this->assertNotNull($wamid);
        $this->assertStringStartsWith('test-', $wamid);
        $this->assertNull($sender->last_send_error);

        Http::assertNothingSent();
    }

    /**
     * Cada llamada arranca con el motivo del fallo anterior en blanco.
     *
     * @return void
     */
    public function test_cada_envio_resetea_el_motivo_del_fallo_anterior(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http(['*' => $this->meta_confirma()]);

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_link('+5493411234567', 'no-es-un-link'));
        $this->assertNotNull($sender->last_send_error);

        $this->assertNotNull($sender->send_image_by_link('+5493411234567', 'https://r2.comerciocity.com/a.jpg'));
        $this->assertNull($sender->last_send_error);
        $this->assertNull($sender->last_send_status_code);
    }
}
