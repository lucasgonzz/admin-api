<?php

namespace Tests\Feature;

use App\Models\LeadMessageAttachment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Fakes\BytesDeAudioSinteticos;
use Tests\TestCase;

/**
 * Archivo de los adjuntos de leads por URL firmada (misión leads-adjuntos-con-range, 26/10/2026).
 *
 * La ruta `lead.message.attachment.file` servía el archivo con `Storage::response()`, un StreamedResponse de
 * Laravel 8 sin `Accept-Ranges` ni 206: iOS Safari no reproduce audio/video sin rangos y Chrome informa
 * `duration=Infinity`. Ahora responde con BinaryFileResponse y la misma política segura que soporte
 * ({@see \App\Services\SignedAttachmentResponder}).
 *
 * Los bytes de prueba son sintéticos (no hay binarios en el repo), el disco `public` es un Storage::fake() y
 * ningún test sale a internet.
 */
class LeadAdjuntosConRangeTest extends TestCase
{
    use DatabaseTransactions;
    use BytesDeAudioSinteticos;

    /**
     * Usa un disco público de mentira (la salida a internet ya la corta TestCase).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // ------------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------------

    /**
     * Crea el archivo en el disco `public` y la fila del adjunto de lead que lo apunta.
     *
     * lead_message_id no tiene foreign key (convención del repo) y la ruta de archivos no usa el mensaje,
     * así que alcanza con un id cualquiera.
     *
     * @param string      $nombre             Nombre del archivo guardado en disco.
     * @param string      $bytes              Contenido del archivo.
     * @param string|null $mime               Mime guardado en la fila.
     * @param string|null $nombre_original    Nombre original que mandó el lead (original_filename).
     *
     * @return LeadMessageAttachment
     */
    private function crear_adjunto(string $nombre, string $bytes, ?string $mime, ?string $nombre_original = null): LeadMessageAttachment
    {
        $ruta = 'lead_messages/7001/' . $nombre;
        Storage::disk('public')->put($ruta, $bytes);

        return $this->crear_adjunto_con_disco_y_ruta('public', $ruta, $mime, $nombre_original);
    }

    /**
     * Crea una fila de adjunto de lead con `disk` y `path` arbitrarios (sin tocar el disco).
     *
     * @param string      $disk            Valor de la columna `disk`.
     * @param string      $path            Valor de la columna `path`.
     * @param string|null $mime            Valor de la columna `mime`.
     * @param string|null $nombre_original Valor de la columna `original_filename`.
     *
     * @return LeadMessageAttachment
     */
    private function crear_adjunto_con_disco_y_ruta(string $disk, string $path, ?string $mime, ?string $nombre_original = null): LeadMessageAttachment
    {
        return LeadMessageAttachment::create([
            'lead_message_id'   => 7001,
            'disk'              => $disk,
            'path'              => $path,
            'mime'              => $mime,
            'size'              => 10,
            'original_filename' => $nombre_original,
        ]);
    }

    /**
     * Bytes mínimos de un MP4 (caja `ftyp`), suficientes para servir un archivo de video.
     *
     * @return string
     */
    private function bytes_mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64);
    }

    // ------------------------------------------------------------------------------------------------
    // 1. Éxito, Range y mime
    // ------------------------------------------------------------------------------------------------

    /**
     * Con firma válida responde 200 con el mismo cuerpo que el archivo, `Accept-Ranges` y el mime sin parámetros.
     *
     * @return void
     */
    public function test_responde_200_con_el_mismo_cuerpo_y_accept_ranges()
    {
        $bytes   = $this->bytes_ogg_opus();
        $adjunto = $this->crear_adjunto('nota.ogg', $bytes, 'audio/ogg');

        $respuesta = $this->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(200);
        $this->assertSame($bytes, $respuesta->baseResponse->getFile()->getContent());
        $this->assertSame('bytes', $respuesta->headers->get('Accept-Ranges'));
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'));
        $this->assertSame((string) strlen($bytes), $respuesta->headers->get('Content-Length'));
        $this->assertStringStartsWith('inline', (string) $respuesta->headers->get('Content-Disposition'));
    }

    /**
     * Un audio ogg pedido con Range contesta 206 con Content-Range, Accept-Ranges y el Content-Type correcto.
     *
     * @return void
     */
    public function test_audio_con_range_responde_206()
    {
        $bytes   = $this->bytes_ogg_opus();
        $adjunto = $this->crear_adjunto('nota.ogg', $bytes, 'audio/ogg');

        $respuesta = $this->withHeaders(['Range' => 'bytes=0-1'])->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(206);
        $this->assertSame('bytes 0-1/' . strlen($bytes), $respuesta->headers->get('Content-Range'));
        $this->assertSame('bytes', $respuesta->headers->get('Accept-Ranges'));
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'));
        $this->assertSame('2', $respuesta->headers->get('Content-Length'));
    }

    /**
     * Un video mp4 pedido con Range contesta 206 con Content-Range, Accept-Ranges y video/mp4.
     *
     * @return void
     */
    public function test_video_con_range_responde_206()
    {
        $bytes   = $this->bytes_mp4();
        $adjunto = $this->crear_adjunto('clip.mp4', $bytes, 'video/mp4');

        $respuesta = $this->withHeaders(['Range' => 'bytes=0-1'])->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(206);
        $this->assertSame('bytes 0-1/' . strlen($bytes), $respuesta->headers->get('Content-Range'));
        $this->assertSame('bytes', $respuesta->headers->get('Accept-Ranges'));
        $this->assertSame('video/mp4', $respuesta->headers->get('Content-Type'));
    }

    /**
     * El Content-Type sale sin parámetros: `audio/ogg; codecs=opus` guardado se sirve como `audio/ogg`.
     *
     * @return void
     */
    public function test_el_content_type_sale_sin_parametros()
    {
        $adjunto = $this->crear_adjunto('nota.ogg', $this->bytes_ogg_opus(), 'audio/ogg; codecs=opus');

        $respuesta = $this->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(200);
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------------------------------------
    // 2. Firma
    // ------------------------------------------------------------------------------------------------

    /**
     * Sin firma, con la firma alterada o con `disposition` agregado después de firmar: 403.
     *
     * @return void
     */
    public function test_sin_firma_o_con_firma_alterada_responde_403()
    {
        $adjunto = $this->crear_adjunto('nota.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        $firmada = $adjunto->fresh()->public_url;

        // Sin firma.
        $this->get('/api/admin/lead-message-attachment/' . $adjunto->id . '/file')->assertStatus(403);

        // Firma alterada (se le agrega un carácter al final).
        $this->get($firmada . 'x')->assertStatus(403);

        // Parámetro agregado después de firmar: la firma cubre toda la query.
        $this->get($firmada . '&disposition=attachment')->assertStatus(403);
    }

    // ------------------------------------------------------------------------------------------------
    // 3. 404: id, archivo y rutas raras
    // ------------------------------------------------------------------------------------------------

    /**
     * Un id inexistente y un archivo borrado del disco dan 404.
     *
     * @return void
     */
    public function test_id_inexistente_o_archivo_borrado_responde_404()
    {
        $inexistente = URL::temporarySignedRoute('lead.message.attachment.file', now()->addHour(), ['id' => 99999999]);
        $this->get($inexistente)->assertStatus(404);

        $adjunto = $this->crear_adjunto('nota.ogg', $this->bytes_ogg_opus(), 'audio/ogg');
        Storage::disk('public')->delete($adjunto->path);
        $this->get($adjunto->fresh()->public_url)->assertStatus(404);
    }

    /**
     * Rutas con `..`, absolutas, de un directorio o inexistentes dan 404 y no 500.
     *
     * El archivo secreto existe de verdad (en otro disco falso, hermano del `public`), así que un 200 sería una fuga.
     *
     * @return void
     */
    public function test_rutas_con_punto_punto_absolutas_o_directorio_responden_404()
    {
        Storage::fake('local');
        Storage::disk('local')->put('secreto.txt', 'privado');
        Storage::disk('public')->put('lead_messages/7001/ok.jpg', 'jpg');

        $rutas = [
            '../local/secreto.txt',
            'lead_messages/../../local/secreto.txt',
            Storage::disk('local')->path('secreto.txt'),
            '/etc/passwd',
            'C:\\Windows\\win.ini',
            'lead_messages/7001',
            'lead_messages',
            '.',
            '..',
            'no/existe.bin',
        ];

        foreach ($rutas as $ruta) {
            $adjunto   = $this->crear_adjunto_con_disco_y_ruta('public', $ruta, 'text/plain');
            $respuesta = $this->get($adjunto->fresh()->public_url);
            $this->assertSame(404, $respuesta->getStatusCode(), 'La ruta "' . $ruta . '" no dio 404.');
        }
    }

    // ------------------------------------------------------------------------------------------------
    // 4. El disco de la fila se ignora
    // ------------------------------------------------------------------------------------------------

    /**
     * Un adjunto con `disk = local` NO sirve archivos de `storage/app` (privado): el disco es siempre `public`.
     *
     * @return void
     */
    public function test_el_disco_de_la_fila_se_ignora_y_no_se_sirve_storage_privado()
    {
        Storage::fake('local');
        Storage::disk('local')->put('x.txt', 'contrato privado');
        $adjunto = $this->crear_adjunto_con_disco_y_ruta('local', 'x.txt', 'text/plain');

        $this->get($adjunto->fresh()->public_url)->assertStatus(404);
    }

    /**
     * Si el path existe en `public`, se sirve el de `public` aunque la fila diga otro disco (o uno inexistente).
     *
     * @return void
     */
    public function test_un_disco_raro_en_la_fila_no_rompe_y_se_sirve_el_de_public()
    {
        Storage::disk('public')->put('lead_messages/7001/foto.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20));

        foreach (['s3', 'disco-que-no-existe', ''] as $disco) {
            $adjunto = $this->crear_adjunto_con_disco_y_ruta($disco, 'lead_messages/7001/foto.jpg', 'image/jpeg');
            $this->get($adjunto->fresh()->public_url)->assertStatus(200);
        }
    }

    // ------------------------------------------------------------------------------------------------
    // 5. Política de inline / descarga y cabeceras de seguridad
    // ------------------------------------------------------------------------------------------------

    /**
     * Html, svg y xml (y cualquier tipo que no sea imagen, audio, video o pdf) se fuerzan a descarga, aun en `public_url`.
     *
     * @return void
     */
    public function test_html_svg_y_xml_se_fuerzan_a_descarga()
    {
        $casos = [
            ['pagina.html', 'text/html', '<script>alert(1)</script>'],
            ['dibujo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['datos.xml', 'application/xml', '<?xml version="1.0"?><a/>'],
            ['datos2.xml', 'text/xml', '<?xml version="1.0"?><a/>'],
            ['app.js', 'application/javascript', 'alert(1)'],
        ];

        foreach ($casos as $caso) {
            $adjunto   = $this->crear_adjunto($caso[0], $caso[2], $caso[1]);
            $respuesta = $this->get($adjunto->fresh()->public_url);

            $respuesta->assertStatus(200);
            $this->assertStringStartsWith('attachment', (string) $respuesta->headers->get('Content-Disposition'), 'Se serviría inline: ' . $caso[1]);
            $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
            $this->assertSame("sandbox; default-src 'none'", $respuesta->headers->get('Content-Security-Policy'));
        }
    }

    /**
     * Un mime sin forma de mime nunca se refleja en el Content-Type: sale octet-stream y se descarga.
     *
     * @return void
     */
    public function test_un_mime_invalido_sale_octet_stream_y_se_descarga()
    {
        foreach (["text/html\r\nX-Evil: 1", 'no es un mime'] as $mime) {
            $adjunto   = $this->crear_adjunto('raro.bin', 'xx', $mime);
            $respuesta = $this->get($adjunto->fresh()->public_url);

            $respuesta->assertStatus(200);
            $this->assertSame('application/octet-stream', $respuesta->headers->get('Content-Type'), 'Mime "' . $mime . '" se reflejó.');
            $this->assertStringStartsWith('attachment', (string) $respuesta->headers->get('Content-Disposition'));
            $this->assertNull($respuesta->headers->get('X-Evil'));
        }
    }

    /**
     * Imagen, audio, video y pdf se muestran inline en `public_url`; el `download_url` fuerza la descarga.
     *
     * @return void
     */
    public function test_imagen_audio_video_y_pdf_van_inline_y_download_url_fuerza_descarga()
    {
        $casos = [
            ['foto.jpg', 'image/jpeg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20)],
            ['nota.ogg', 'audio/ogg', $this->bytes_ogg_opus()],
            ['clip.mp4', 'video/mp4', $this->bytes_mp4()],
            ['doc.pdf', 'application/pdf', '%PDF-1.4 sintetico'],
        ];

        foreach ($casos as $caso) {
            $adjunto = $this->crear_adjunto($caso[0], $caso[2], $caso[1]);

            $inline = $this->get($adjunto->fresh()->public_url);
            $inline->assertStatus(200);
            $this->assertStringStartsWith('inline', (string) $inline->headers->get('Content-Disposition'), $caso[1]);

            $descarga = $this->get($adjunto->fresh()->download_url);
            $descarga->assertStatus(200);
            $this->assertStringStartsWith('attachment', (string) $descarga->headers->get('Content-Disposition'), $caso[1]);
        }
    }

    /**
     * El `download_url` baja el archivo con el nombre original que mandó el lead (y no con el nombre en disco).
     *
     * @return void
     */
    public function test_la_descarga_usa_el_nombre_original_del_lead()
    {
        $adjunto = $this->crear_adjunto('wa_8f3a1c.pdf', '%PDF-1.4 sintetico', 'application/pdf', 'Presupuesto ferretería.pdf');

        $respuesta = $this->get($adjunto->fresh()->download_url);

        $respuesta->assertStatus(200);
        $disposicion = (string) $respuesta->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment', $disposicion);
        $this->assertStringContainsString('filename=', $disposicion);
        // El nombre con tilde viaja en la forma RFC 5987 (filename*) y en un respaldo ASCII (la í ocupa dos
        // bytes en UTF-8 y el reemplazo es byte a byte: por eso salen dos guiones bajos).
        $this->assertStringContainsString("filename*=utf-8''Presupuesto%20ferreter%C3%ADa.pdf", $disposicion);
        $this->assertStringContainsString('filename=Presupuesto ferreter__a.pdf', str_replace('"', '', $disposicion));
        $this->assertStringNotContainsString('wa_8f3a1c', $disposicion);
    }

    /**
     * Sin nombre original la descarga cae al basename del archivo en disco.
     *
     * @return void
     */
    public function test_sin_nombre_original_la_descarga_usa_el_basename()
    {
        $adjunto = $this->crear_adjunto('wa_8f3a1c.pdf', '%PDF-1.4 sintetico', 'application/pdf');

        $respuesta = $this->get($adjunto->fresh()->download_url);

        $respuesta->assertStatus(200);
        $this->assertStringContainsString('wa_8f3a1c.pdf', (string) $respuesta->headers->get('Content-Disposition'));
    }

    /**
     * La respuesta es `private` con la misma ventana que la firma, y lleva CSP y nosniff.
     *
     * @return void
     */
    public function test_la_respuesta_es_private_con_csp_y_nosniff()
    {
        $adjunto = $this->crear_adjunto('foto.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20), 'image/jpeg');

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
     * Sin mime guardado se lo deduce de los bytes (un PNG real se sirve como image/png).
     *
     * @return void
     */
    public function test_sin_mime_guardado_se_deduce_de_los_bytes()
    {
        // PNG mínimo de 1x1 armado en base64 dentro del test (no hay binarios en el repo).
        $png     = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $adjunto = $this->crear_adjunto('sinmime.png', $png, null);

        $respuesta = $this->get($adjunto->fresh()->public_url);

        $respuesta->assertStatus(200);
        $this->assertSame('image/png', $respuesta->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------------------------------------
    // 6. Contrato del modelo
    // ------------------------------------------------------------------------------------------------

    /**
     * El `public_url` y el `download_url` del modelo siguen firmados y apuntando a la misma ruta
     * (el SPA no cambia: el contrato es el mismo).
     *
     * @return void
     */
    public function test_las_urls_del_modelo_siguen_firmadas_y_apuntan_a_la_misma_ruta()
    {
        $adjunto = $this->crear_adjunto('nota.ogg', $this->bytes_ogg_opus(), 'audio/ogg', 'Audio del lead.ogg');
        $adjunto = $adjunto->fresh();

        $ruta = '/api/admin/lead-message-attachment/' . $adjunto->id . '/file';

        $this->assertStringContainsString($ruta, $adjunto->public_url);
        $this->assertStringContainsString('signature=', $adjunto->public_url);
        $this->assertStringNotContainsString('disposition=', $adjunto->public_url);

        $this->assertStringContainsString($ruta, $adjunto->download_url);
        $this->assertStringContainsString('signature=', $adjunto->download_url);
        $this->assertStringContainsString('disposition=attachment', $adjunto->download_url);

        // Los campos serializados que lee el SPA siguen estando.
        $json = $adjunto->toArray();
        $this->assertArrayHasKey('public_url', $json);
        $this->assertArrayHasKey('download_url', $json);
        $this->assertSame('Audio del lead.ogg', $json['display_filename']);
    }
}
