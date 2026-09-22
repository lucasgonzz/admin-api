<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Services\SystemErrorWhatsappService;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;

/**
 * `WhatsappSendService::send_image_by_bytes()`: lo que sale hacia Kapso/Meta cuando la foto va
 * subida en vez de por link.
 *
 * Es el gemelo de `EnvioDeImagenPorLinkTest`, y mide el método REAL contra un `Http::fake()`. Lo
 * que lo distingue del otro camino son **dos** pedidos y no uno: primero un multipart a `/media`
 * que devuelve un `media_id`, y recién después el mensaje con `image: {id}`. Esa subida es lo que
 * hace que el rechazo sea sincrónico —y por lo tanto contable—, que es todo el motivo por el que
 * este camino existe: por link Meta contesta que sí y descarta el webp después, en silencio.
 *
 * Lo que comparte con el de link, y se mide igual: un fallo **no** le avisa a los admins salvo que
 * se lo pidan explícitamente (el aviso está throttleado a uno cada 10 minutos de forma global), y
 * `test_mode` no sale a la red.
 */
class EnvioDeImagenPorMediaIdTest extends BaseDelCanal
{
    /**
     * Endpoint de subida de media del número configurado en `BaseDelCanal`.
     */
    const ENDPOINT_MEDIA = 'https://api.kapso.ai/meta/whatsapp/v24.0/1234567890/media';

    /**
     * Endpoint de mensajes del mismo número.
     */
    const ENDPOINT_MENSAJES = 'https://api.kapso.ai/meta/whatsapp/v24.0/1234567890/messages';

    /**
     * Bytes de un JPEG chiquito, armado con GD.
     *
     * @return string
     */
    private function jpeg(): string
    {
        $imagen = imagecreatetruecolor(40, 30);
        $color  = imagecolorallocate($imagen, 30, 120, 200);
        imagefilledrectangle($imagen, 0, 0, 39, 29, $color);

        ob_start();
        imagejpeg($imagen, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /**
     * Fakea la subida a `/media` y la confirmación del mensaje.
     *
     * @param string $media_id Lo que devuelve `/media`.
     * @param string $wamid    Lo que devuelve el endpoint de mensajes.
     *
     * @return void
     */
    private function fakear_los_dos_pasos(string $media_id = 'media-123', string $wamid = 'wamid.SUBIDA.1'): void
    {
        $this->fakear_http([
            self::ENDPOINT_MEDIA    => Http::response(['id' => $media_id], 200),
            self::ENDPOINT_MENSAJES => Http::response(['messages' => [['id' => $wamid]]], 200),
        ]);
    }

    /**
     * Primero sube el archivo a `/media` y después manda el mensaje con ese media_id.
     *
     * @return void
     */
    public function test_sube_a_media_y_despues_manda_el_mensaje_con_el_media_id(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_los_dos_pasos('media-123', 'wamid.SUBIDA.1');

        $bytes = $this->jpeg();

        $wamid = app(WhatsappSendService::class)->send_image_by_bytes(
            '+5493411234567',
            $bytes,
            'image/jpeg',
            'precinto-1.jpg',
            'Precintos 03 200 mm x 4,8 mm',
            'Asistente por WhatsApp - foto - cliente #7'
        );

        $this->assertSame('wamid.SUBIDA.1', $wamid);

        Http::assertSentCount(2);

        /* La subida: multipart al endpoint de media, con la clave de Kapso y los bytes adentro. */
        Http::assertSent(function ($request) use ($bytes) {
            return $request->url() === self::ENDPOINT_MEDIA
                && $request->method() === 'POST'
                && $request->isMultipart()
                && $request->hasHeader('X-API-Key', 'clave-kapso-de-prueba')
                && strpos($request->body(), 'precinto-1.jpg') !== false
                && strpos($request->body(), $bytes) !== false;
        });

        /* El mensaje: `image: {id}`, nunca `link`. */
        Http::assertSent(function ($request) {
            return $request->url() === self::ENDPOINT_MENSAJES
                && $request->data() === [
                    'messaging_product' => 'whatsapp',
                    'to'                => '5493411234567',
                    'type'              => 'image',
                    'image'             => [
                        'id'      => 'media-123',
                        'caption' => 'Precintos 03 200 mm x 4,8 mm',
                    ],
                ];
        });
    }

    /**
     * Sin caption el payload no lleva la clave: para Meta un `caption` vacío es un parámetro inválido.
     *
     * @return void
     */
    public function test_sin_caption_el_payload_no_lleva_la_clave_caption(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_los_dos_pasos();

        $sender = app(WhatsappSendService::class);

        $this->assertNotNull($sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg', null));
        $this->assertNotNull($sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'b.jpg', '   '));

        Http::assertNotSent(function ($request) {
            return $request->url() === self::ENDPOINT_MENSAJES
                && array_key_exists('caption', (array) ($request->data()['image'] ?? []));
        });
    }

    /**
     * El caption se recorta a los 1024 de Meta, que es el tope del epígrafe de una imagen.
     *
     * Lo escribe el asistente del sistema de un cliente: de este lado no hay nadie garantizando el
     * largo, y pasarse rechaza el mensaje entero.
     *
     * @return void
     */
    public function test_el_caption_se_recorta_al_tope_de_meta(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_los_dos_pasos();

        app(WhatsappSendService::class)->send_image_by_bytes(
            '+5493411234567',
            $this->jpeg(),
            'image/jpeg',
            'a.jpg',
            str_repeat('a', 2000)
        );

        Http::assertSent(function ($request) {
            if ($request->url() !== self::ENDPOINT_MENSAJES) {
                return false;
            }

            $caption = (string) ($request->data()['image']['caption'] ?? '');

            return mb_strlen($caption) === 1024;
        });
    }

    /**
     * 🔴 Si la subida falla, el mensaje NO sale y el motivo con el status quedan para el llamador.
     *
     * Es la diferencia que justifica este camino: acá el rechazo llega en el acto, con status, y se
     * puede contar como fallido de verdad.
     *
     * @return void
     */
    public function test_si_la_subida_falla_no_se_manda_el_mensaje_y_queda_el_motivo(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http([
            self::ENDPOINT_MEDIA    => Http::response(['error' => ['message' => 'Unsupported media type']], 400),
            self::ENDPOINT_MENSAJES => Http::response(['messages' => [['id' => 'wamid.NO.DEBERIA']]], 200),
        ]);

        $sender = app(WhatsappSendService::class);

        $wamid = $sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg', 'Foto');

        $this->assertNull($wamid);
        $this->assertNotNull($sender->last_send_error, 'El motivo tiene que quedar para que el llamador lo loguee.');
        $this->assertSame(400, $sender->last_send_status_code);

        Http::assertNotSent(function ($request) {
            return $request->url() === self::ENDPOINT_MENSAJES;
        });
    }

    /**
     * Un 409 de Kapso en la subida se marca como transitorio, que es lo que dispara el reintento.
     *
     * @return void
     */
    public function test_un_409_en_la_subida_queda_marcado_como_transitorio(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http([
            self::ENDPOINT_MEDIA => Http::response(['error' => 'Another message is already in-flight'], 409),
        ]);

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg'));
        $this->assertSame(409, $sender->last_send_status_code);
        $this->assertTrue($sender->last_send_was_transient(), 'El 409 es el caso que el job reintenta.');
    }

    /**
     * Un rechazo al mandar el mensaje devuelve null con el motivo y NO avisa a los admins.
     *
     * @return void
     */
    public function test_un_rechazo_del_mensaje_devuelve_null_sin_avisar_a_los_admins(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http([
            self::ENDPOINT_MEDIA    => Http::response(['id' => 'media-123'], 200),
            self::ENDPOINT_MENSAJES => Http::response(['error' => ['message' => 'Rechazado', 'code' => 131053]], 400),
        ]);

        $this->mock(SystemErrorWhatsappService::class, function ($mock) {
            $mock->shouldNotReceive('notify_send_error');
        });

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg', 'Foto'));
        $this->assertNotNull($sender->last_send_error);
        $this->assertSame(400, $sender->last_send_status_code);
    }

    /**
     * Pidiéndolo explícitamente, el fallo SÍ avisa a los admins.
     *
     * Pinza el valor por defecto: si alguien lo diera vuelta, esta y la anterior no pueden estar
     * verdes a la vez.
     *
     * @return void
     */
    public function test_pidiendolo_explicitamente_el_fallo_si_avisa_a_los_admins(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_http([
            self::ENDPOINT_MEDIA => Http::response(['error' => ['message' => 'Boom']], 500),
        ]);

        $this->mock(SystemErrorWhatsappService::class, function ($mock) {
            $mock->shouldReceive('notify_send_error')->once();
        });

        $this->assertNull(
            app(WhatsappSendService::class)->send_image_by_bytes(
                '+5493411234567',
                $this->jpeg(),
                'image/jpeg',
                'a.jpg',
                null,
                'Contexto explícito',
                false
            )
        );
    }

    /**
     * Bytes vacíos no salen a la red, y el motivo queda escrito.
     *
     * @return void
     */
    public function test_bytes_vacios_no_salen_a_la_red(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_los_dos_pasos();

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_bytes('+5493411234567', '', 'image/jpeg', 'a.jpg'));
        $this->assertNotNull($sender->last_send_error);

        Http::assertNothingSent();
    }

    /**
     * Un número inválido no sube nada.
     *
     * 🔴 El orden importa: si el chequeo del número fuera después de la subida, un teléfono roto
     * dejaría un archivo cargado en Meta que nadie va a usar nunca.
     *
     * @return void
     */
    public function test_un_numero_invalido_no_sube_nada(): void
    {
        $this->crear_config_whatsapp();
        $this->fakear_los_dos_pasos();

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_bytes('sin-numero', $this->jpeg(), 'image/jpeg', 'a.jpg'));
        $this->assertStringContainsString('inválido', (string) $sender->last_send_error);

        Http::assertNothingSent();
    }

    /**
     * Sin configuración activa no sale nada y el motivo queda igual.
     *
     * @return void
     */
    public function test_sin_configuracion_activa_no_sale_nada(): void
    {
        $this->fakear_los_dos_pasos();

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg'));
        $this->assertNotNull($sender->last_send_error);

        Http::assertNothingSent();
    }

    /**
     * En `test_mode` devuelve un id simulado sin subir ni mandar nada.
     *
     * @return void
     */
    public function test_en_test_mode_no_sube_ni_manda_nada(): void
    {
        $config            = $this->crear_config_whatsapp();
        $config->test_mode = true;
        $config->save();

        $this->fakear_los_dos_pasos();

        $sender = app(WhatsappSendService::class);
        $wamid  = $sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg', 'Foto');

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
        $this->fakear_los_dos_pasos();

        $sender = app(WhatsappSendService::class);

        $this->assertNull($sender->send_image_by_bytes('+5493411234567', '', 'image/jpeg', 'a.jpg'));
        $this->assertNotNull($sender->last_send_error);

        $this->assertNotNull($sender->send_image_by_bytes('+5493411234567', $this->jpeg(), 'image/jpeg', 'a.jpg'));
        $this->assertNull($sender->last_send_error);
        $this->assertNull($sender->last_send_status_code);
    }
}
