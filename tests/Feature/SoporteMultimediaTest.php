<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use App\Services\AudioContainerSniffer;
use App\Services\FfmpegLocator;
use App\Services\WhatsappSendService;
use App\Services\WhatsappInboundMediaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Fakes\BytesDeAudioSinteticos;
use Tests\TestCase;

/**
 * Multimedia en la conversación de soporte (misión soporte-multimedia-en-conversacion, 26/10/2026).
 *
 * Cubre los dos sentidos:
 *  - ENTRADA: lo que el cliente manda por WhatsApp (nota de voz, video, imagen, PDF) se guarda con la
 *    extensión y el mime correctos, y se sirve por una ruta firmada con soporte de `Range` (206), que es
 *    lo que iOS Safari exige para reproducir audio y video.
 *  - SALIDA: el audio que el operador manda al cliente tiene que ser un contenedor que WhatsApp acepte
 *    de verdad (la clase de bug del error 131053 de Meta): se mira el contenedor por los bytes, no por
 *    la extensión ni por el mime que declara el navegador.
 *
 * Los bytes de prueba se arman sintéticos acá adentro (no hay binarios en el repo) y ningún test sale a
 * internet: Kapso/Meta se falsean con Http::fake() y el disco público es un Storage::fake().
 */
class SoporteMultimediaTest extends TestCase
{
    use DatabaseTransactions;
    use BytesDeAudioSinteticos;

    /**
     * Usa un disco de mentira y registra "sin ffmpeg" por defecto (la salida a internet ya la corta TestCase).
     *
     * ffmpeg se anula por defecto para que los resultados no dependan de la máquina donde corre la suite
     * (en desarrollo puede estar instalado y en el hosting no); los tests que lo necesitan lo reponen.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Sin Http::fake() global a propósito: un fake genérico registrado acá taparía los de cada test
        // (Laravel usa el primero que matchea) y TestCase ya corta toda salida a internet no falseada.
        Storage::fake('public');
        $this->registrar_ffmpeg(null);
    }

    // ------------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------------

    /**
     * Registra en el contenedor un localizador de ffmpeg controlado por el test.
     *
     * @param string|null $ruta Ruta falsa de ffmpeg, o null para simular que no está instalado.
     *
     * @return void
     */
    private function registrar_ffmpeg(?string $ruta): void
    {
        $this->app->instance(FfmpegLocator::class, new class($ruta) extends FfmpegLocator {
            /** @var string|null Ruta que "encuentra" el localizador. */
            private $ruta_falsa;

            public function __construct(?string $ruta)
            {
                $this->ruta_falsa = $ruta;
            }

            public function path(): ?string
            {
                return $this->ruta_falsa;
            }
        });
    }

    /**
     * Admin operador de soporte.
     *
     * @param string $email Email único.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente activo con teléfono.
     *
     * @param string $phone Teléfono de la ficha.
     *
     * @return Client
     */
    private function crear_cliente(string $phone): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = $phone;
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket abierto por el canal indicado, con la ventana de 24hs de WhatsApp abierta.
     *
     * @param string $source `whatsapp` o `erp`.
     * @param string $phone  Teléfono del hilo.
     *
     * @return SupportTicket
     */
    private function crear_ticket(string $source, string $phone): SupportTicket
    {
        $client = $this->crear_cliente($phone);

        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => $source,
            'whatsapp_phone'   => $source === 'whatsapp' ? $phone : null,
            'opened_at'        => now()->subHours(2),
        ]);

        // El entrante reciente es lo que abre la ventana de 24hs: sin él el envío saldría por plantilla.
        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Hola, necesito ayuda',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Configuración de WhatsApp activa y fuera de test_mode, para que los envíos lleguen a Kapso (falseado).
     *
     * @return WhatsappConfig
     */
    private function crear_config_whatsapp(): WhatsappConfig
    {
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-kapso-de-prueba';
        $config->phone_number_id = '123456789';
        $config->webhook_secret  = 'secreto-de-prueba';
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();

        return $config;
    }

    /**
     * Crea un mensaje entrante del cliente con un adjunto ya guardado en el disco falso.
     *
     * @param SupportTicket $ticket    Ticket dueño.
     * @param string        $nombre    Nombre del archivo guardado.
     * @param string        $bytes     Contenido.
     * @param string|null   $mime      Mime guardado en la fila.
     *
     * @return SupportMessageAttachment
     */
    private function crear_adjunto(SupportTicket $ticket, string $nombre, string $bytes, ?string $mime): SupportMessageAttachment
    {
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => null,
            'delivered_at'      => now(),
        ]);

        $ruta = 'support_messages/' . $ticket->id . '/' . $nombre;
        Storage::disk('public')->put($ruta, $bytes);

        return SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $ruta,
            'mime'               => $mime,
            'size'               => strlen($bytes),
        ]);
    }

    // ------------------------------------------------------------------------------------------------
    // 1. Entrada: extensión y mime guardados
    // ------------------------------------------------------------------------------------------------

    /**
     * Mensaje de WhatsApp (formato Meta/Kapso) con un nodo multimedia.
     *
     * @param string               $tipo      image, audio, video o document.
     * @param array<string, mixed> $nodo      Contenido del nodo (id, mime_type, filename…).
     * @param string               $url       URL de descarga que da Kapso.
     *
     * @return array<string, mixed>
     */
    private function mensaje_entrante(string $tipo, array $nodo, string $url): array
    {
        return [
            'id'    => 'wamid.' . $tipo . '.' . uniqid(),
            'type'  => $tipo,
            $tipo   => $nodo,
            'kapso' => ['media_url' => $url],
        ];
    }

    /**
     * Baja y guarda un adjunto entrante con el servicio real, con la descarga falseada.
     *
     * @param string               $tipo  Tipo de WhatsApp.
     * @param array<string, mixed> $nodo  Nodo del tipo con el mime que declara Meta.
     * @param string               $bytes Bytes que "devuelve" Kapso.
     *
     * @return SupportMessageAttachment
     */
    private function guardar_entrante(string $tipo, array $nodo, string $bytes): SupportMessageAttachment
    {
        $this->crear_config_whatsapp();
        $ticket = $this->crear_ticket('whatsapp', '+5493417790001');

        $url = 'https://media.kapso.test/' . uniqid() . '/archivo';
        Http::fake([$url => Http::response($bytes, 200)]);

        $servicio = new WhatsappInboundMediaService();
        $media    = $servicio->extract_inbound_media($this->mensaje_entrante($tipo, $nodo, $url), $tipo);
        $this->assertNotNull($media, 'extract_inbound_media no reconoció el adjunto.');

        $mensaje = SupportMessage::create([
            'support_ticket_id'   => $ticket->id,
            'sender_type'         => 'user',
            'kind'                => $tipo,
            'whatsapp_message_id' => 'wamid.entrada.' . uniqid(),
            'delivered_at'        => now(),
        ]);

        $this->assertTrue($servicio->persist_support_attachment($mensaje, (int) $ticket->id, $media));

        return SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
    }

    /**
     * Una nota de voz con `audio/ogg; codecs=opus` se guarda como .ogg y con el mime sin parámetros.
     *
     * @return void
     */
    public function test_la_nota_de_voz_con_codecs_se_guarda_como_ogg()
    {
        $adjunto = $this->guardar_entrante('audio', ['id' => 'm1', 'mime_type' => 'audio/ogg; codecs=opus'], $this->bytes_ogg_opus());

        $this->assertSame('ogg', pathinfo($adjunto->path, PATHINFO_EXTENSION), 'La nota de voz no quedó como .ogg: ' . $adjunto->path);
        $this->assertSame('audio/ogg', $adjunto->mime);
        Storage::disk('public')->assertExists($adjunto->path);
    }

    /**
     * Un video entrante se guarda como .mp4 (antes no había ningún video en el mapa y salía .bin).
     *
     * @return void
     */
    public function test_el_video_entrante_se_guarda_como_mp4()
    {
        $adjunto = $this->guardar_entrante('video', ['id' => 'm2', 'mime_type' => 'video/mp4'], "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 40));

        $this->assertSame('mp4', pathinfo($adjunto->path, PATHINFO_EXTENSION));
        $this->assertSame('video/mp4', $adjunto->mime);
    }

    /**
     * Una imagen entrante se guarda como .jpg.
     *
     * @return void
     */
    public function test_la_imagen_entrante_se_guarda_como_jpg()
    {
        $adjunto = $this->guardar_entrante('image', ['id' => 'm3', 'mime_type' => 'image/jpeg'], "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 40));

        $this->assertSame('jpg', pathinfo($adjunto->path, PATHINFO_EXTENSION));
        $this->assertSame('image/jpeg', $adjunto->mime);
    }

    /**
     * Un PDF entrante se guarda como .pdf aunque Meta no mande el nombre del archivo.
     *
     * @return void
     */
    public function test_el_pdf_entrante_se_guarda_como_pdf()
    {
        $adjunto = $this->guardar_entrante('document', ['id' => 'm4', 'mime_type' => 'application/pdf'], "%PDF-1.4\n" . str_repeat('x', 40));

        $this->assertSame('pdf', pathinfo($adjunto->path, PATHINFO_EXTENSION));
        $this->assertSame('application/pdf', $adjunto->mime);
    }

    /**
     * Un mime desconocido de la familia audio cae en .ogg y uno de video en .mp4, nunca en .bin.
     *
     * @return void
     */
    public function test_un_mime_desconocido_cae_por_familia_y_no_en_bin()
    {
        $audio = $this->guardar_entrante('audio', ['id' => 'm5', 'mime_type' => 'audio/x-raro'], 'abc' . str_repeat('x', 30));
        $this->assertSame('ogg', pathinfo($audio->path, PATHINFO_EXTENSION));

        $video = $this->guardar_entrante('video', ['id' => 'm6', 'mime_type' => 'video/x-raro'], 'abc' . str_repeat('x', 30));
        $this->assertSame('mp4', pathinfo($video->path, PATHINFO_EXTENSION));
    }

    // ------------------------------------------------------------------------------------------------
    // 2. Serialización del contrato
    // ------------------------------------------------------------------------------------------------

    /**
     * Cada adjunto serializado trae las URLs firmadas del contrato y el nombre legible.
     *
     * @return void
     */
    public function test_los_adjuntos_serializan_las_urls_firmadas_del_contrato()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417790002');
        $audio   = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        $imagen  = $this->crear_adjunto($ticket, 'wa_foto.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20), 'image/jpeg');

        $json_audio = $audio->fresh()->toArray();

        $this->assertArrayHasKey('public_url', $json_audio);
        $this->assertArrayHasKey('download_url', $json_audio);
        $this->assertArrayHasKey('playback_url', $json_audio);
        $this->assertSame('wa_voz.ogg', $json_audio['display_filename']);

        // Las tres están firmadas y apuntan a la ruta nueva.
        foreach (['public_url', 'download_url', 'playback_url'] as $campo) {
            $this->assertStringContainsString('/support-message-attachment/' . $audio->id . '/file', $json_audio[$campo]);
            $this->assertStringContainsString('signature=', $json_audio[$campo]);
        }
        $this->assertStringContainsString('disposition=attachment', $json_audio['download_url']);
        $this->assertStringContainsString('playable=1', $json_audio['playback_url']);
        $this->assertStringNotContainsString('playable=1', $json_audio['public_url']);

        // La URL de una imagen no tiene reproducción: playback_url es solo de audio.
        $this->assertNull($imagen->fresh()->toArray()['playback_url']);

        // Misma URL en dos serializaciones seguidas (expiración anclada al día): el navegador no re-descarga.
        $this->assertSame($json_audio['public_url'], $audio->fresh()->toArray()['public_url']);
    }

    /**
     * El mensaje que devuelve la API de la bandeja lleva los campos nuevos dentro de attachments[].
     *
     * @return void
     */
    public function test_el_mensaje_serializado_lleva_los_campos_nuevos_y_conserva_los_viejos()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417790003');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');

        $mensaje = SupportMessage::where('id', $adjunto->support_message_id)->withAll()->first()->toArray();
        $item    = $mensaje['attachments'][0];

        // Campos de siempre (el SPA viejo los usa para armar /storage/<path>).
        $this->assertSame('support_messages/' . $ticket->id . '/wa_voz.ogg', $item['path']);
        $this->assertSame('audio/ogg', $item['mime']);
        $this->assertSame('public', $item['disk']);
        // Campos nuevos.
        $this->assertNotEmpty($item['public_url']);
        $this->assertNotEmpty($item['playback_url']);
    }

    // ------------------------------------------------------------------------------------------------
    // 3. Ruta firmada de archivo
    // ------------------------------------------------------------------------------------------------

    /**
     * Con firma válida la ruta devuelve el archivo con Content-Type sin parámetros y nosniff.
     *
     * @return void
     */
    public function test_la_ruta_firmada_sirve_el_archivo_con_el_mime_sin_parametros()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417790004');
        // Fila vieja: el mime se guardó crudo, con los codecs.
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.bin', $this->bytes_ogg_opus(), 'audio/ogg; codecs=opus');

        $respuesta = $this->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(200);
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertSame('bytes', $respuesta->headers->get('Accept-Ranges'));
        $this->assertStringStartsWith('inline', (string) $respuesta->headers->get('Content-Disposition'));
    }

    /**
     * Un pedido con Range contesta 206 con Content-Range y solo los bytes pedidos (lo que exige iOS).
     *
     * @return void
     */
    public function test_la_ruta_firmada_responde_206_con_range()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417790005');
        $bytes   = $this->bytes_ogg_opus();
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $bytes, 'audio/ogg');

        $respuesta = $this->withHeaders(['Range' => 'bytes=0-9'])->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(206);
        $this->assertSame('bytes 0-9/' . strlen($bytes), $respuesta->headers->get('Content-Range'));
        $this->assertSame('10', $respuesta->headers->get('Content-Length'));
    }

    /**
     * Sin firma o con la firma adulterada no se sirve nada (403), y un adjunto inexistente da 404.
     *
     * @return void
     */
    public function test_la_ruta_rechaza_sin_firma_y_da_404_si_no_existe()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417790006');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');

        // Sin firma.
        $this->get('/api/admin/support-message-attachment/' . $adjunto->id . '/file')->assertStatus(403);

        // Firma adulterada.
        $this->get($adjunto->fresh()->public_url . 'x')->assertStatus(403);

        // Adjunto que no existe (con firma válida para ese id).
        $inexistente = URL::temporarySignedRoute('support.message.attachment.file', now()->addHour(), ['id' => 99999999]);
        $this->get($inexistente)->assertStatus(404);

        // Registro que existe pero cuyo archivo se borró del disco.
        Storage::disk('public')->delete($adjunto->path);
        $this->get($adjunto->fresh()->public_url)->assertStatus(404);
    }

    /**
     * Un .html o un .svg subido por un tercero se fuerza como descarga: nunca corre inline en el origen del admin.
     *
     * @return void
     */
    public function test_los_archivos_peligrosos_se_fuerzan_como_descarga()
    {
        $ticket = $this->crear_ticket('whatsapp', '+5493417790007');

        $html = $this->crear_adjunto($ticket, 'wa_pagina.html', '<script>alert(1)</script>', 'text/html');
        $svg  = $this->crear_adjunto($ticket, 'wa_dibujo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');

        foreach ([$html, $svg] as $adjunto) {
            $respuesta = $this->get($adjunto->fresh()->public_url);
            $respuesta->assertStatus(200);
            $this->assertStringStartsWith('attachment', (string) $respuesta->headers->get('Content-Disposition'), 'Se serviría inline: ' . $adjunto->path);
            $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        }
    }

    /**
     * Una imagen, un audio, un video y un PDF se ven inline; el download_url fuerza la descarga de cualquiera.
     *
     * @return void
     */
    public function test_los_tipos_seguros_van_inline_y_download_url_fuerza_la_descarga()
    {
        $ticket = $this->crear_ticket('whatsapp', '+5493417790008');

        $casos = [
            ['wa_a.jpg', 'image/jpeg'],
            ['wa_b.ogg', 'audio/ogg'],
            ['wa_c.mp4', 'video/mp4'],
            ['wa_d.pdf', 'application/pdf'],
        ];

        foreach ($casos as $caso) {
            $adjunto = $this->crear_adjunto($ticket, $caso[0], str_repeat('a', 64), $caso[1]);
            $adjunto = $adjunto->fresh();

            $inline = $this->get($adjunto->public_url);
            $this->assertStringStartsWith('inline', (string) $inline->headers->get('Content-Disposition'), $caso[1]);

            $descarga = $this->get($adjunto->download_url);
            $this->assertStringStartsWith('attachment', (string) $descarga->headers->get('Content-Disposition'), $caso[1]);
        }
    }

    /**
     * Pidiendo la versión reproducible sin ffmpeg, se sirve el original (nunca un 500).
     *
     * @return void
     */
    public function test_playable_sin_ffmpeg_sirve_el_original()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417790009');
        $bytes   = $this->bytes_ogg_opus();
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $bytes, 'audio/ogg');

        $respuesta = $this->get($adjunto->fresh()->playback_url);

        $respuesta->assertStatus(200);
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'));
        $this->assertSame((string) strlen($bytes), $respuesta->headers->get('Content-Length'));
        Storage::disk('public')->assertMissing('support_messages/' . $ticket->id . '/playback/' . $adjunto->id . '.m4a');
    }

    // ------------------------------------------------------------------------------------------------
    // 4. Salida de audio e imagen (operador -> cliente por WhatsApp)
    // ------------------------------------------------------------------------------------------------

    /**
     * Arma un archivo subido real (con bytes en disco) para que PHP lo analice por contenido.
     *
     * @param string $nombre        Nombre original del archivo.
     * @param string $bytes         Contenido.
     * @param string $mime_cliente  Mime que declara el navegador.
     *
     * @return UploadedFile
     */
    private function archivo_subido(string $nombre, string $bytes, string $mime_cliente): UploadedFile
    {
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sm_' . uniqid('', true) . '_' . $nombre;
        file_put_contents($ruta, $bytes);

        // test=true: Laravel lo trata como una subida válida aunque no venga de un POST real.
        return new UploadedFile($ruta, $nombre, $mime_cliente, null, true);
    }

    /**
     * Falsea Kapso: la subida de media devuelve un id y el envío del mensaje devuelve un wamid.
     *
     * @return void
     */
    private function falsear_kapso(): void
    {
        Http::fake([
            'https://api.kapso.ai/meta/whatsapp/v24.0/123456789/media'    => Http::response(['id' => 'media-1'], 200),
            // Un wamid distinto por envío: la columna whatsapp_message_id es única.
            'https://api.kapso.ai/meta/whatsapp/v24.0/123456789/messages' => function () {
                return Http::response(['messages' => [['id' => 'wamid.salida.' . uniqid()]]], 200);
            },
        ]);
    }

    /**
     * El operador manda un adjunto al ticket.
     *
     * @param Admin         $admin   Operador.
     * @param SupportTicket $ticket  Ticket destino.
     * @param UploadedFile  $archivo Archivo a adjuntar.
     * @param string        $kind    `kind` que manda el SPA (puede ser incorrecto a propósito).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_adjunto(Admin $admin, SupportTicket $ticket, UploadedFile $archivo, string $kind)
    {
        return $this->actingAs($admin, 'sanctum')->post('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind'       => $kind,
            'body'       => '',
            'attachment' => $archivo,
        ], ['Accept' => 'application/json']);
    }

    /**
     * Mensajes del operador de un ticket.
     *
     * @param SupportTicket $ticket Ticket.
     *
     * @return \Illuminate\Support\Collection
     */
    private function mensajes_del_operador(SupportTicket $ticket)
    {
        return SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->orderBy('id')->get();
    }

    /**
     * Pedidos HTTP que salieron hacia Kapso/Meta durante el test.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_a_kapso(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            $pedidos[] = $par[0];
        }

        return $pedidos;
    }

    /**
     * Separa de los pedidos a Kapso la subida de media y el envío del mensaje (el último de cada uno).
     *
     * @return array{0: \Illuminate\Http\Client\Request|null, 1: \Illuminate\Http\Client\Request|null}
     */
    private function subida_y_envio(): array
    {
        $subida = null;
        $envio  = null;
        foreach ($this->pedidos_a_kapso() as $pedido) {
            if (substr($pedido->url(), -6) === '/media') {
                $subida = $pedido;
            }
            if (substr($pedido->url(), -9) === '/messages') {
                $envio = $pedido;
            }
        }

        return [$subida, $envio];
    }

    /**
     * Un Ogg/Opus real se sube como `audio/ogg`, con nombre .ogg y se manda como nota de voz.
     *
     * Se manda con extensión `.oga`, mime genérico del navegador y `kind = text`: el servidor tiene que
     * ignorar las tres cosas y fiarse de los bytes.
     *
     * @return void
     */
    public function test_el_audio_ogg_opus_sale_como_nota_de_voz_aunque_el_navegador_declare_otra_cosa()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('audio-opus@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791001');

        $archivo = $this->archivo_subido('nota.oga', $this->bytes_ogg_opus(), 'application/octet-stream');
        $respuesta = $this->enviar_adjunto($admin, $ticket, $archivo, 'text');

        $respuesta->assertStatus(201);

        // El mensaje quedó con el kind real y enviado.
        $mensaje = $this->mensajes_del_operador($ticket)->first();
        $this->assertSame('audio', $mensaje->kind, 'El kind no se derivó del contenido.');
        $this->assertStringStartsWith('wamid.salida.', (string) $mensaje->whatsapp_message_id);
        $this->assertNotSame('not_received', $mensaje->remote_delivery_status);

        // El adjunto quedó guardado con nombre out_*, extensión .ogg y mime normalizado.
        $adjunto = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
        $this->assertSame('audio/ogg', $adjunto->mime);
        $this->assertSame('public', $adjunto->disk);
        $this->assertMatchesRegularExpression('#^support_messages/' . $ticket->id . '/out_\d{8}_\d{6}_[0-9a-f]{8}\.ogg$#', $adjunto->path);
        Storage::disk('public')->assertExists($adjunto->path);

        // A Meta se le subió como audio/ogg con nombre .ogg, y el mensaje salió con voice=true.
        list($subida, $envio) = $this->subida_y_envio();
        $this->assertNotNull($subida, 'No hubo subida de media a Kapso.');
        $this->assertNotNull($envio, 'No hubo envío del mensaje a Kapso.');
        $this->assertStringContainsString('Content-Type: audio/ogg', $subida->body());
        $this->assertMatchesRegularExpression('/filename="audio_\d+\.ogg"/', $subida->body());
        $datos = $envio->data();
        $this->assertSame('audio', $datos['type']);
        $this->assertSame('media-1', $datos['audio']['id']);
        $this->assertTrue($datos['audio']['voice'], 'No salió como nota de voz.');
    }

    /**
     * Un archivo `.ogg` con códec Vorbis (que WhatsApp no acepta) sin ffmpeg se rechaza ANTES de crear nada.
     *
     * Es el caso que antes se mandaba declarado como audio/ogg y Meta descartaba en silencio.
     *
     * @return void
     */
    public function test_ogg_vorbis_sin_ffmpeg_da_422_y_no_crea_el_mensaje()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('audio-vorbis@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791002');

        $archivo = $this->archivo_subido('nota.ogg', $this->bytes_ogg_vorbis(), 'audio/ogg');
        $respuesta = $this->enviar_adjunto($admin, $ticket, $archivo, 'audio');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('OGG/Vorbis', (string) $respuesta->json('error'));
        $this->assertStringContainsString('ffmpeg', (string) $respuesta->json('error'));
        $this->assertCount(0, $this->mensajes_del_operador($ticket), 'Se creó un mensaje para un audio rechazado.');
        $this->assertCount(0, $this->pedidos_a_kapso(), 'Salió algo a Kapso con un audio rechazado.');
    }

    /**
     * Un WebM real (lo que graba Chrome) sin ffmpeg da 422 claro antes de crear el mensaje.
     *
     * @return void
     */
    public function test_webm_real_sin_ffmpeg_da_422_claro()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('audio-webm@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791003');

        // Chrome lo declara audio/webm y el operador lo ve como un audio normal.
        $archivo = $this->archivo_subido('grabacion.webm', $this->bytes_webm(), 'audio/webm');
        $respuesta = $this->enviar_adjunto($admin, $ticket, $archivo, 'audio');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('WebM', (string) $respuesta->json('error'));
        $this->assertCount(0, $this->mensajes_del_operador($ticket));
        $this->assertSame([], Storage::disk('public')->allFiles('support_messages/' . $ticket->id), 'Quedó un archivo huérfano en el disco.');
    }

    /**
     * Un audio de más de 16 MB se rechaza con 422 (tope de la Cloud API de WhatsApp).
     *
     * @return void
     */
    public function test_audio_de_mas_de_16_mb_da_422()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('audio-grande@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791004');

        $bytes = $this->bytes_ogg_opus() . str_repeat('a', 16 * 1024 * 1024 + 1);
        $respuesta = $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('largo.ogg', $bytes, 'audio/ogg'), 'audio');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('16 MB', (string) $respuesta->json('error'));
        $this->assertCount(0, $this->mensajes_del_operador($ticket));
    }

    /**
     * Un MP3 y un M4A salen tal cual, con su mime real y sin nota de voz.
     *
     * @return void
     */
    public function test_mp3_y_m4a_salen_con_su_mime_real()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('audio-mp3-m4a@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791005');

        // MP3 con el mime equivocado a propósito.
        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('cancion.bin', $this->bytes_mp3_id3(), 'application/octet-stream'), 'audio')->assertStatus(201);
        // M4A como lo manda el iPhone: video/mp4 y kind audio. Sin ffmpeg sale como audio/mp4.
        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('voz.m4a', $this->bytes_m4a(), 'video/mp4'), 'audio')->assertStatus(201);

        $mimes = SupportMessageAttachment::whereIn('support_message_id', $this->mensajes_del_operador($ticket)->pluck('id'))->orderBy('id')->pluck('mime')->all();
        $this->assertSame(['audio/mpeg', 'audio/mp4'], $mimes);

        $subidas = [];
        $voces   = [];
        foreach ($this->pedidos_a_kapso() as $pedido) {
            if (substr($pedido->url(), -6) === '/media') {
                $subidas[] = $pedido->body();
            }
            if (substr($pedido->url(), -9) === '/messages') {
                $voces[] = isset($pedido->data()['audio']['voice']);
            }
        }
        $this->assertCount(2, $subidas);
        $this->assertStringContainsString('Content-Type: audio/mpeg', $subidas[0]);
        $this->assertStringContainsString('Content-Type: audio/mp4', $subidas[1]);
        $this->assertSame([false, false], $voces, 'Un MP3/M4A no es nota de voz.');
    }

    /**
     * Una imagen con `kind = text` se corrige a `image` por los bytes, y un tipo o tamaño que WhatsApp no acepta da 422.
     *
     * @return void
     */
    public function test_la_imagen_deriva_su_kind_y_se_valida_el_tipo_y_el_tamano()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('imagen-salida@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791006');

        // PNG válido de 1x1.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('foto.png', $png, 'application/octet-stream'), 'text')->assertStatus(201);

        $mensaje = $this->mensajes_del_operador($ticket)->first();
        $this->assertSame('image', $mensaje->kind);
        $adjunto = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
        $this->assertSame('image/png', $adjunto->mime);
        $this->assertSame('png', pathinfo($adjunto->path, PATHINFO_EXTENSION));

        // GIF: WhatsApp no lo acepta como imagen.
        $gif = 'GIF89a' . str_repeat("\x00", 30);
        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('animado.gif', $gif, 'image/gif'), 'image')->assertStatus(422);

        // PNG de más de 5 MB.
        $grande = $png . str_repeat("\x00", 5 * 1024 * 1024 + 1);
        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('enorme.png', $grande, 'image/png'), 'image')->assertStatus(422);

        $this->assertCount(1, $this->mensajes_del_operador($ticket), 'Se crearon mensajes para imágenes rechazadas.');
    }

    /**
     * Bytes que no son un audio, mandados como audio a un ticket de WhatsApp, se rechazan con 422.
     *
     * @return void
     */
    public function test_algo_que_no_es_audio_mandado_como_audio_da_422()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('no-es-audio@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791007');

        $respuesta = $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('nota.ogg', 'esto no es un audio, es texto', 'audio/ogg'), 'audio');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('formato del audio', (string) $respuesta->json('error'));
        $this->assertCount(0, $this->mensajes_del_operador($ticket));
    }

    /**
     * En un ticket del ERP no se rechaza nada nuevo: un WebM se guarda, con extensión y mime coherentes.
     *
     * @return void
     */
    public function test_en_un_ticket_erp_no_se_rechaza_pero_se_guarda_con_mime_y_extension_coherentes()
    {
        // El sync al ERP del cliente es HTTP: se falsea todo para que no salga nada.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $admin  = $this->crear_admin('audio-erp@test.local');
        $ticket = $this->crear_ticket('erp', '+5493417791008');

        $archivo = $this->archivo_subido('grabacion.bin', $this->bytes_webm(), 'audio/webm');
        $respuesta = $this->enviar_adjunto($admin, $ticket, $archivo, 'audio');

        $respuesta->assertStatus(201);
        $mensaje = $this->mensajes_del_operador($ticket)->first();
        $this->assertSame('audio', $mensaje->kind, 'En el ERP el kind se respeta tal cual lo mandó el SPA.');
        $adjunto = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
        $this->assertSame('audio/webm', $adjunto->mime);
        $this->assertSame('webm', pathinfo($adjunto->path, PATHINFO_EXTENSION));
    }

    /**
     * Un archivo con extensión peligrosa no conserva esa extensión en el disco público.
     *
     * @return void
     */
    public function test_un_archivo_con_extension_peligrosa_no_la_conserva()
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $admin  = $this->crear_admin('extension-peligrosa@test.local');
        $ticket = $this->crear_ticket('erp', '+5493417791009');

        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('shell.php', '<?php echo 1;', 'text/x-php'), 'text')->assertStatus(201);

        $mensaje = $this->mensajes_del_operador($ticket)->first();
        $adjunto = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
        $this->assertNotSame('php', pathinfo($adjunto->path, PATHINFO_EXTENSION), 'Se guardó un .php en el disco público.');
    }

    // ------------------------------------------------------------------------------------------------
    // 5. Servicio de envío: fallos legibles
    // ------------------------------------------------------------------------------------------------

    /**
     * Si el audio guardado necesita conversión y no hay ffmpeg, el envío devuelve null con un motivo legible.
     *
     * El opener de soporte lee `last_send_error` para marcar el mensaje como no entregado; si quedara en
     * null, el operador vería "no recibido" sin saber por qué.
     *
     * @return void
     */
    public function test_el_envio_de_un_audio_webm_sin_ffmpeg_deja_el_motivo_legible()
    {
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $ticket  = $this->crear_ticket('whatsapp', '+5493417791010');
        // Guardado antes de esta misión: con extensión .ogg y mime audio/ogg, pero por dentro es WebM.
        $adjunto = $this->crear_adjunto($ticket, 'out_viejo.ogg', $this->bytes_webm(), 'audio/ogg');

        $servicio = app(WhatsappSendService::class);
        $resultado = $servicio->send_audio_attachment('+5493417791010', $adjunto);

        $this->assertNull($resultado);
        $this->assertNotNull($servicio->last_send_error, 'No quedó el motivo del fallo.');
        $this->assertStringContainsString('WebM', $servicio->last_send_error);
        $this->assertCount(0, $this->pedidos_a_kapso(), 'Se le subió a Meta un WebM declarado como audio/ogg.');
    }

    /**
     * Si Meta rechaza la subida del audio, el motivo queda en last_send_error y el mensaje se marca no entregado.
     *
     * @return void
     */
    public function test_si_kapso_rechaza_el_audio_queda_el_motivo_y_el_mensaje_no_entregado()
    {
        $this->crear_config_whatsapp();
        Http::fake([
            'https://api.kapso.ai/meta/whatsapp/v24.0/123456789/media' => Http::response(['error' => 'unsupported'], 400),
        ]);
        $admin  = $this->crear_admin('kapso-rechaza@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791011');

        $respuesta = $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('nota.ogg', $this->bytes_ogg_opus(), 'audio/ogg'), 'audio');

        $respuesta->assertStatus(201);
        $mensaje = $this->mensajes_del_operador($ticket)->first();
        $this->assertSame('not_received', $mensaje->remote_delivery_status, 'El mensaje no quedó como no entregado.');
        $this->assertNull($mensaje->whatsapp_message_id);

        $servicio = app(WhatsappSendService::class);
        $adjunto  = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
        $this->assertNull($servicio->send_audio_attachment('+5493417791011', $adjunto));
        $this->assertNotEmpty($servicio->last_send_error);
        $this->assertSame(400, $servicio->last_send_status_code);
    }

    // ------------------------------------------------------------------------------------------------
    // 6. Con ffmpeg de verdad (se saltean si la máquina no lo tiene)
    // ------------------------------------------------------------------------------------------------

    /**
     * Crea un audio real con ffmpeg (tono de un segundo) en el contenedor pedido.
     *
     * @param string $ffmpeg    Ruta de ffmpeg.
     * @param string $extension Extensión (y contenedor) de salida: webm, ogg…
     * @param string $codec     Argumentos de códec de audio.
     *
     * @return string Bytes del archivo generado.
     */
    private function generar_audio_real(string $ffmpeg, string $extension, string $codec): string
    {
        $salida = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sm_gen_' . uniqid('', true) . '.' . $extension;
        $comando = escapeshellarg($ffmpeg) . ' -y -loglevel error -f lavfi -i sine=frequency=440:duration=1 ' . $codec . ' ' . escapeshellarg($salida) . ' 2>&1';
        $lineas = [];
        $codigo = 1;
        exec($comando, $lineas, $codigo);
        $this->assertSame(0, $codigo, 'ffmpeg no generó el audio de prueba: ' . implode(' ', $lineas));
        $bytes = (string) file_get_contents($salida);
        @unlink($salida);

        return $bytes;
    }

    /**
     * Ruta real de ffmpeg en esta máquina; si no está, el test se saltea.
     *
     * @return string
     */
    private function ffmpeg_real_o_saltear(): string
    {
        $ruta = (new FfmpegLocator())->path();
        if ($ruta === null) {
            $this->markTestSkipped('ffmpeg no está instalado en esta máquina.');
        }

        return $ruta;
    }

    /**
     * Con ffmpeg, un WebM real se convierte a Ogg/Opus, sale como nota de voz y no deja temporales.
     *
     * @return void
     */
    public function test_con_ffmpeg_un_webm_real_se_convierte_y_sale_como_nota_de_voz()
    {
        $ffmpeg = $this->ffmpeg_real_o_saltear();
        $webm   = $this->generar_audio_real($ffmpeg, 'webm', '-c:a libopus');
        $this->assertSame(AudioContainerSniffer::WEBM, AudioContainerSniffer::sniff($webm), 'El audio de prueba no es WebM.');

        $this->registrar_ffmpeg($ffmpeg);
        $this->crear_config_whatsapp();
        $this->falsear_kapso();
        $admin  = $this->crear_admin('webm-con-ffmpeg@test.local');
        $ticket = $this->crear_ticket('whatsapp', '+5493417791012');

        $antes = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_audio_*');

        $this->enviar_adjunto($admin, $ticket, $this->archivo_subido('grabacion.webm', $webm, 'audio/webm'), 'audio')->assertStatus(201);

        $despues = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_audio_*');
        $this->assertSame(count($antes), count($despues), 'Quedó un temporal de la conversión sin borrar.');

        list($subida, $envio) = $this->subida_y_envio();
        $this->assertNotNull($subida);
        $this->assertStringContainsString('Content-Type: audio/ogg', $subida->body());
        $this->assertTrue($envio->data()['audio']['voice']);
        // Lo que se subió es Ogg/Opus de verdad.
        $this->assertStringContainsString('OpusHead', $subida->body());
    }

    /**
     * Con ffmpeg, un audio Ogg/Opus entrante se transcodifica UNA vez a m4a y la copia se sirve con 206.
     *
     * @return void
     */
    public function test_con_ffmpeg_el_playback_url_sirve_una_copia_m4a_cacheada_con_range()
    {
        $ffmpeg = $this->ffmpeg_real_o_saltear();
        $ogg    = $this->generar_audio_real($ffmpeg, 'ogg', '-c:a libopus');

        $this->registrar_ffmpeg($ffmpeg);
        $ticket  = $this->crear_ticket('whatsapp', '+5493417791013');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $ogg, 'audio/ogg');
        $url     = $adjunto->fresh()->playback_url;

        $respuesta = $this->get($url);
        $respuesta->assertStatus(200);
        $this->assertSame('audio/mp4', $respuesta->headers->get('Content-Type'));

        $copia = 'support_messages/' . $ticket->id . '/playback/' . $adjunto->id . '.m4a';
        Storage::disk('public')->assertExists($copia);
        $tamano_copia = Storage::disk('public')->size($copia);
        $this->assertSame('ftyp', substr(Storage::disk('public')->get($copia), 4, 4), 'La copia no es un MP4/M4A.');

        // Con Range responde 206 sobre la copia.
        $parcial = $this->withHeaders(['Range' => 'bytes=0-15'])->get($url);
        $parcial->assertStatus(206);
        $this->assertSame('bytes 0-15/' . $tamano_copia, $parcial->headers->get('Content-Range'));
        // withHeaders() persiste en el resto del test: se limpia para que el pedido siguiente no lleve Range.
        $this->flushHeaders();

        // Un segundo pedido reutiliza la copia (sin ffmpeg ya no podría regenerarla).
        $this->registrar_ffmpeg(null);
        $this->get($url)->assertStatus(200);
    }

    // ------------------------------------------------------------------------------------------------
    // 7. Seguridad de la ruta de archivos: el disco y la ruta no se toman de la base
    // ------------------------------------------------------------------------------------------------

    /**
     * Crea un adjunto con `disk` y `path` arbitrarios, como los que puede escribir la metadata remota del ERP.
     *
     * @param SupportTicket $ticket Ticket dueño.
     * @param string        $disk   Valor de la columna `disk`.
     * @param string        $path   Valor de la columna `path`.
     * @param string|null   $mime   Valor de la columna `mime`.
     *
     * @return SupportMessageAttachment
     */
    private function crear_adjunto_con_disco_y_ruta(SupportTicket $ticket, string $disk, string $path, ?string $mime = 'text/plain'): SupportMessageAttachment
    {
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'delivered_at'      => now(),
        ]);

        return SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => $disk,
            'path'               => $path,
            'mime'               => $mime,
            'size'               => 10,
        ]);
    }

    /**
     * Un adjunto con `disk = local` NO sirve archivos de `storage/app` (privado): el disco es siempre `public`.
     *
     * Reproducido por el verificador: `disk='local'`, `path='x.txt'` devolvía 200 con contenido privado.
     *
     * @return void
     */
    public function test_el_disco_de_la_fila_se_ignora_y_no_se_sirve_storage_privado()
    {
        Storage::fake('local');
        Storage::disk('local')->put('x.txt', 'contrato privado');
        $ticket  = $this->crear_ticket('whatsapp', '+5493417792001');
        $adjunto = $this->crear_adjunto_con_disco_y_ruta($ticket, 'local', 'x.txt');

        $this->get($adjunto->fresh()->public_url)->assertStatus(404);
    }

    /**
     * Si el mismo path existe en `public`, se sirve el de `public` aunque la fila diga otro disco (o uno inexistente).
     *
     * @return void
     */
    public function test_un_disco_raro_en_la_fila_no_rompe_y_se_sirve_el_de_public()
    {
        $ticket = $this->crear_ticket('whatsapp', '+5493417792002');
        Storage::disk('public')->put('support_messages/' . $ticket->id . '/wa_foto.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20));

        foreach (['s3', 'disco-que-no-existe', ''] as $disco) {
            $adjunto = $this->crear_adjunto_con_disco_y_ruta($ticket, $disco, 'support_messages/' . $ticket->id . '/wa_foto.jpg', 'image/jpeg');
            $this->get($adjunto->fresh()->public_url)->assertStatus(200);
        }
    }

    /**
     * Rutas con `..`, absolutas, de un directorio o inexistentes dan 404 y no 500.
     *
     * El archivo secreto existe de verdad (en otro disco falso, hermano del `public`), así que un 200 sería una fuga.
     *
     * @return void
     */
    public function test_rutas_con_traversal_absolutas_o_de_directorio_dan_404()
    {
        Storage::fake('local');
        Storage::disk('local')->put('secreto.txt', 'privado');
        $ticket = $this->crear_ticket('whatsapp', '+5493417792003');
        Storage::disk('public')->put('support_messages/' . $ticket->id . '/ok.jpg', 'jpg');

        $rutas = [
            '../local/secreto.txt',
            'support_messages/../../local/secreto.txt',
            Storage::disk('local')->path('secreto.txt'),
            '/etc/passwd',
            'C:\\Windows\\win.ini',
            'support_messages/' . $ticket->id,
            'support_messages',
            '.',
            '..',
            'no/existe.bin',
        ];

        foreach ($rutas as $ruta) {
            $adjunto = $this->crear_adjunto_con_disco_y_ruta($ticket, 'public', $ruta);
            $respuesta = $this->get($adjunto->fresh()->public_url);
            $this->assertSame(404, $respuesta->getStatusCode(), 'La ruta "' . $ruta . '" no dio 404.');
        }
    }

    /**
     * El resolvedor no lanza con bytes nulos o rutas vacías: devuelve null.
     *
     * @return void
     */
    public function test_el_resolvedor_devuelve_null_ante_rutas_invalidas()
    {
        $resolvedor = new \App\Services\SupportAttachmentFileResolver();

        $this->assertNull($resolvedor->resolve(''));
        $this->assertNull($resolvedor->resolve("a\0b.txt"));
        $this->assertNull($resolvedor->resolve('../../.env'));

        Storage::disk('public')->put('dentro.txt', 'x');
        $this->assertSame(realpath(Storage::disk('public')->path('dentro.txt')), $resolvedor->resolve('dentro.txt'));
        $this->assertSame(realpath(Storage::disk('public')->path('dentro.txt')), $resolvedor->resolve('/dentro.txt'));
    }

    /**
     * La respuesta es `private`, lleva CSP y nosniff.
     *
     * @return void
     */
    public function test_la_respuesta_es_private_con_csp_y_nosniff()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417792004');
        $adjunto = $this->crear_adjunto($ticket, 'wa_foto.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20), 'image/jpeg');

        $respuesta = $this->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(200);
        $cache = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringContainsString('max-age=172800', $cache);
        $this->assertSame("sandbox; default-src 'none'", $respuesta->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
    }

    /**
     * Un mime guardado con forma inválida (inyección de cabeceras, basura) no se refleja: octet-stream y descarga.
     *
     * @return void
     */
    public function test_un_mime_invalido_se_trata_como_binario_y_se_descarga()
    {
        $ticket = $this->crear_ticket('whatsapp', '+5493417792005');

        foreach (['text/html<script>', 'image', 'audio/ ogg', '/ogg', 'x y/z'] as $mime) {
            $adjunto = $this->crear_adjunto($ticket, 'wa_raro_' . md5($mime) . '.bin', 'abc', $mime);
            $respuesta = $this->get($adjunto->fresh()->public_url);

            $respuesta->assertStatus(200);
            $this->assertSame('application/octet-stream', $respuesta->headers->get('Content-Type'), 'Mime "' . $mime . '" se reflejó.');
            $this->assertStringStartsWith('attachment', (string) $respuesta->headers->get('Content-Disposition'), 'Mime "' . $mime . '" salió inline.');
        }
    }

    // ------------------------------------------------------------------------------------------------
    // 8. Copia reproducible: atomicidad, lock, marca negativa y tope de tiempo
    // ------------------------------------------------------------------------------------------------

    /**
     * Registra un "ffmpeg" de mentira que no ejecuta nada: cuenta las conversiones y escribe bytes de juguete.
     *
     * @param string        $modo        `ok` escribe una copia; `fallar` devuelve un error sin escribir nada.
     * @param callable|null $al_convertir Se llama con la ruta de salida justo antes de "convertir".
     *
     * @return object El localizador registrado (con `llamadas` y `modo` públicos).
     */
    private function registrar_ffmpeg_de_mentira(string $modo = 'ok', ?callable $al_convertir = null)
    {
        $falso = new class($modo, $al_convertir) extends FfmpegLocator {
            /** @var int Conversiones pedidas. */
            public $llamadas = 0;

            /** @var string `ok` o `fallar`. */
            public $modo;

            /** @var callable|null Gancho previo a la conversión. */
            private $gancho;

            public function __construct(string $modo, ?callable $gancho)
            {
                $this->modo   = $modo;
                $this->gancho = $gancho;
            }

            public function path(): ?string
            {
                return 'ffmpeg-de-mentira';
            }

            public function convert_audio(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments, int $timeout_seconds = 30): ?string
            {
                $this->llamadas++;
                if ($this->gancho !== null) {
                    call_user_func($this->gancho, $output_path);
                }
                if ($this->modo === 'fallar') {
                    return 'ffmpeg de mentira falló';
                }
                file_put_contents($output_path, 'M4A-ENTERO-DE-MENTIRA');

                return null;
            }
        };

        $this->app->instance(FfmpegLocator::class, $falso);

        return $falso;
    }

    /**
     * Ruta absoluta de un archivo de la carpeta `playback` de un adjunto (final, .lock, .fail).
     *
     * @param SupportTicket            $ticket  Ticket dueño.
     * @param SupportMessageAttachment $adjunto Adjunto.
     * @param string                   $sufijo  Sufijo después de `<id>.m4a` (vacío para la copia final).
     *
     * @return string
     */
    private function ruta_de_playback(SupportTicket $ticket, SupportMessageAttachment $adjunto, string $sufijo = ''): string
    {
        $ruta = Storage::disk('public')->path('support_messages/' . $ticket->id . '/playback/' . $adjunto->id . '.m4a') . $sufijo;

        // Mismo separador que arma el servicio (en Windows el path() del disco mezcla / y \).
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);
    }

    /**
     * Normaliza los separadores de una ruta al del sistema (en Windows el disco falso mezcla / y \).
     *
     * @param string|null $ruta Ruta a normalizar.
     *
     * @return string|null
     */
    private function con_separador_del_so(?string $ruta): ?string
    {
        return $ruta === null ? null : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);
    }

    /**
     * La copia se escribe en un temporal de la MISMA carpeta y se publica con rename: nadie ve una copia parcial.
     *
     * @return void
     */
    public function test_la_copia_se_escribe_en_un_temporal_y_se_publica_con_rename()
    {
        $ticket  = $this->crear_ticket('whatsapp', '+5493417793001');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        $final   = $this->ruta_de_playback($ticket, $adjunto);
        $vistos  = [];

        $this->registrar_ffmpeg_de_mentira('ok', function ($salida) use ($final, &$vistos) {
            // Mientras ffmpeg "escribe", la copia final NO existe y el temporal vive junto a ella.
            $vistos['final_existia'] = file_exists($final);
            $vistos['misma_carpeta'] = $this->con_separador_del_so(dirname($salida)) === dirname($final);
            $vistos['es_temporal']   = substr($salida, -9) === '.part.m4a';
        });

        $ruta = (new \App\Services\SupportAttachmentPlaybackService())->playable_absolute_path($adjunto->fresh(), Storage::disk('public')->path($adjunto->path));

        $this->assertSame($final, $this->con_separador_del_so($ruta));
        $this->assertFalse($vistos['final_existia'], 'La copia final ya existía mientras ffmpeg escribía.');
        $this->assertTrue($vistos['misma_carpeta'], 'El temporal no está en la carpeta de destino (rename no sería atómico).');
        $this->assertTrue($vistos['es_temporal']);
        $this->assertSame('M4A-ENTERO-DE-MENTIRA', file_get_contents($final));
        $this->assertSame([basename($final)], array_map('basename', glob(dirname($final) . DIRECTORY_SEPARATOR . '*')), 'Quedaron temporales o locks.');
    }

    /**
     * Con otro proceso transcodificando (lock tomado), este sirve el original sin esperar ni correr ffmpeg.
     *
     * @return void
     */
    public function test_con_el_lock_tomado_no_se_transcodifica_y_se_sirve_el_original()
    {
        $falso   = $this->registrar_ffmpeg_de_mentira();
        $ticket  = $this->crear_ticket('whatsapp', '+5493417793002');
        $bytes   = $this->bytes_ogg_opus();
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $bytes, 'audio/ogg');
        $lock    = $this->ruta_de_playback($ticket, $adjunto, '.lock');

        // "Otro proceso" con el lock tomado.
        if (! is_dir(dirname($lock))) {
            mkdir(dirname($lock), 0775, true);
        }
        $otro = fopen($lock, 'c');
        $this->assertTrue(flock($otro, LOCK_EX | LOCK_NB));

        try {
            $inicio    = microtime(true);
            $respuesta = $this->get($adjunto->fresh()->playback_url);
            $duracion  = microtime(true) - $inicio;
        } finally {
            flock($otro, LOCK_UN);
            fclose($otro);
        }

        $respuesta->assertStatus(200);
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'), 'No se sirvió el original.');
        $this->assertSame((string) strlen($bytes), $respuesta->headers->get('Content-Length'));
        $this->assertSame(0, $falso->llamadas, 'Se transcodificó con el lock tomado por otro proceso.');
        $this->assertLessThan(5, $duracion, 'El pedido esperó al otro proceso en vez de servir el original.');
        $this->assertFileDoesNotExist($this->ruta_de_playback($ticket, $adjunto));
    }

    /**
     * Si ffmpeg falla se deja una marca `.fail`: los pedidos siguientes no reintentan hasta que venza.
     *
     * @return void
     */
    public function test_si_ffmpeg_falla_la_marca_negativa_evita_reintentar_hasta_que_vence()
    {
        $falso   = $this->registrar_ffmpeg_de_mentira('fallar');
        $ticket  = $this->crear_ticket('whatsapp', '+5493417793003');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        $original = Storage::disk('public')->path($adjunto->path);
        $servicio = new \App\Services\SupportAttachmentPlaybackService();

        $this->assertNull($servicio->playable_absolute_path($adjunto->fresh(), $original));
        $this->assertSame(1, $falso->llamadas);
        $marca = $this->ruta_de_playback($ticket, $adjunto, '.fail');
        $this->assertFileExists($marca, 'No se dejó la marca negativa.');

        // Otro Range, un instante después: no se reintenta.
        $this->assertNull($servicio->playable_absolute_path($adjunto->fresh(), $original));
        $this->assertSame(1, $falso->llamadas, 'Se reintentó con la marca vigente.');

        // La marca vence por mtime: se reintenta (y ahora anda).
        touch($marca, time() - 1000);
        $falso->modo = 'ok';
        $ruta = $servicio->playable_absolute_path($adjunto->fresh(), $original);
        $this->assertSame(2, $falso->llamadas);
        $this->assertSame($this->ruta_de_playback($ticket, $adjunto), $this->con_separador_del_so($ruta));
        $this->assertFileDoesNotExist($marca, 'La marca vencida no se limpió.');
    }

    /**
     * Una copia cacheada de 0 bytes está rota: se regenera en vez de servirse vacía.
     *
     * @return void
     */
    public function test_una_copia_cacheada_de_cero_bytes_se_regenera()
    {
        $falso   = $this->registrar_ffmpeg_de_mentira();
        $ticket  = $this->crear_ticket('whatsapp', '+5493417793004');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        $final   = $this->ruta_de_playback($ticket, $adjunto);
        mkdir(dirname($final), 0775, true);
        file_put_contents($final, '');

        $respuesta = $this->get($adjunto->fresh()->playback_url);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $falso->llamadas, 'No se regeneró la copia vacía.');
        $this->assertSame('M4A-ENTERO-DE-MENTIRA', file_get_contents($final));
        $this->assertSame('audio/mp4', $respuesta->headers->get('Content-Type'));
    }

    /**
     * La copia vive siempre en el disco `public`, aunque la fila diga otro disco.
     *
     * @return void
     */
    public function test_el_servicio_de_reproduccion_ignora_el_disco_de_la_fila()
    {
        $this->registrar_ffmpeg_de_mentira();
        $ticket  = $this->crear_ticket('whatsapp', '+5493417793005');
        $adjunto = $this->crear_adjunto($ticket, 'wa_voz.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        $adjunto->disk = 'local';
        $adjunto->save();

        $ruta = (new \App\Services\SupportAttachmentPlaybackService())->playable_absolute_path($adjunto->fresh(), Storage::disk('public')->path($adjunto->path));

        $this->assertSame($this->ruta_de_playback($ticket, $adjunto), $this->con_separador_del_so($ruta));
        Storage::disk('public')->assertExists('support_messages/' . $ticket->id . '/playback/' . $adjunto->id . '.m4a');
    }

    /**
     * Un `FfmpegLocator` que se cuelga se mata al vencer el tope y falla con gracia (sin dejar la salida).
     *
     * El proceso es PHP durmiendo 20 segundos, sustituido por ffmpeg a través de `build_arguments()`.
     *
     * @return void
     */
    public function test_convert_audio_mata_el_proceso_al_vencer_el_tope()
    {
        $colgado = new class extends FfmpegLocator {
            protected function build_arguments(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments): array
            {
                return [PHP_BINARY, '-r', 'sleep(20);'];
            }
        };
        $salida = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sm_timeout_' . uniqid('', true) . '.m4a';
        file_put_contents($salida, 'parcial');

        $inicio = microtime(true);
        $error  = $colgado->convert_audio('ffmpeg', 'entrada', $salida, '-c:a aac', 1);
        $tardo  = microtime(true) - $inicio;

        $this->assertNotNull($error);
        $this->assertStringContainsString('tardó más de 1 segundos', $error);
        $this->assertLessThan(10, $tardo, 'No se mató al proceso colgado.');
        $this->assertFileDoesNotExist($salida, 'Quedó la salida parcial.');
    }

    /**
     * `convert_audio` devuelve null si el proceso termina bien y deja la salida, y un motivo si sale con error.
     *
     * @return void
     */
    public function test_convert_audio_distingue_exito_y_fallo_del_proceso()
    {
        $bien = new class extends FfmpegLocator {
            protected function build_arguments(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments): array
            {
                return [PHP_BINARY, '-r', 'file_put_contents($argv[1], "audio");', '--', $output_path];
            }
        };
        $mal = new class extends FfmpegLocator {
            protected function build_arguments(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments): array
            {
                return [PHP_BINARY, '-r', 'exit(3);'];
            }
        };
        $salida = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sm_proc_' . uniqid('', true) . '.m4a';

        try {
            $this->assertNull($bien->convert_audio('ffmpeg', 'entrada', $salida, '-c:a aac', 20));
            $this->assertSame('audio', file_get_contents($salida));
        } finally {
            @unlink($salida);
        }

        $error = $mal->convert_audio('ffmpeg', 'entrada', $salida, '-c:a aac', 20);
        $this->assertStringContainsString('código 3', (string) $error);
    }

    /**
     * La búsqueda de ffmpeg se hace una sola vez por proceso.
     *
     * @return void
     */
    public function test_la_ruta_de_ffmpeg_se_cachea()
    {
        FfmpegLocator::forget_cached_path();

        $buscador = new class extends FfmpegLocator {
            /** @var int Veces que se buscó de verdad. */
            public $busquedas = 0;

            protected function lookup_path(): ?string
            {
                $this->busquedas++;

                return '/usr/bin/ffmpeg';
            }
        };

        try {
            $this->assertSame('/usr/bin/ffmpeg', $buscador->path());
            $this->assertSame('/usr/bin/ffmpeg', $buscador->path());
            $this->assertSame(1, $buscador->busquedas, 'Se buscó ffmpeg más de una vez.');
        } finally {
            FfmpegLocator::forget_cached_path();
        }
    }
}
