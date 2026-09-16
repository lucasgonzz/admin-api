<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteImagenesService;
use Illuminate\Support\Facades\Http;

/**
 * Las fotos que el dueño le manda al asistente.
 *
 * El caso lo dictó Lucas con un ejemplo concreto: la foto de la factura de un proveedor, para que el
 * asistente dé de alta la compra y cuelgue la imagen para que la IA la lea. De este lado hay tres
 * cosas que cuidar y ninguna es "que la foto llegue", que es la fácil:
 *
 *   1. **Que una descarga fallida no se lleve puesto el mensaje.** El dueño escribió algo y espera
 *      respuesta; perder el mensaje entero por un adjunto es peor que perder el adjunto. Y el
 *      asistente tiene que ENTERARSE de que faltó una foto, si no contesta como si nunca hubiera
 *      existido y el dueño no entiende por qué le preguntan de nuevo.
 *   2. **Que no se le mande al `empresa-api` nada que él vaya a rechazar.** Cuatro fotos, un PDF o
 *      una imagen de 20 MB tienen que quedar afuera ACÁ: si las rechaza el otro lado, lo que se
 *      pierde es el mensaje completo.
 *   3. **Que lo descartado quede escrito.** Es lo que se va a mirar el día que el dueño diga "te
 *      mandé la factura y no la viste".
 *
 * Los bytes son PNG de verdad, generados con GD, porque el tipo real se saca de los bytes y no del
 * `mime` que declaró el payload — un archivo de mentira pasaría la validación de metadata y moriría
 * en la de contenido, que es justo lo que hay que poder distinguir.
 */
class FotosDelDuenoTest extends BaseDelCanal
{
    /**
     * Bytes de una imagen PNG real, chiquita.
     *
     * @param int $lado Lado en píxeles.
     *
     * @return string
     */
    private function png(int $lado = 4): string
    {
        $imagen = imagecreatetruecolor($lado, $lado);
        ob_start();
        imagepng($imagen);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /**
     * Metadata de una foto, tal como la deja `extract_inbound_media()`.
     *
     * @param string $sufijo Para que cada una tenga su URL.
     * @param string $mime   Mime declarado por el payload.
     *
     * @return array<string, mixed>
     */
    private function media(string $sufijo = '1', string $mime = 'image/jpeg'): array
    {
        return [
            'url'               => 'https://api.kapso.ai/media/foto-' . $sufijo . '?firma=secreta',
            'mime'              => $mime,
            'filename'          => 'factura-' . $sufijo . '.jpg',
            'whatsapp_media_id' => 'wamid.media.' . $sufijo,
        ];
    }

    /**
     * Fila entrante de una foto, como la deja el webhook.
     *
     * @param Client $client  Cliente dueño del hilo.
     * @param string $caption Texto que escribió el dueño junto a la foto.
     *
     * @return ClientAssistantMessage
     */
    private function fila_de_foto(Client $client, string $caption = ''): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.CON-FOTO';
        $fila->tipo                = 'image';
        $fila->texto               = $caption !== '' ? $caption : null;
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * Las partes del último POST multipart que salió.
     *
     * @return array<int, array<string, mixed>>
     */
    private function partes_del_post(): array
    {
        $partes = [];

        Http::assertSent(function ($request) use (&$partes) {
            if ($request->method() === 'POST') {
                $partes = $request->data();
            }

            return true;
        });

        return $partes;
    }

    /**
     * Cuántas partes `imagenes[]` viajaron.
     *
     * @param array<int, array<string, mixed>> $partes
     *
     * @return int
     */
    private function contar_imagenes(array $partes): int
    {
        $cuenta = 0;
        foreach ($partes as $parte) {
            if (isset($parte['name']) && $parte['name'] === 'imagenes[]') {
                $cuenta++;
            }
        }

        return $cuenta;
    }

    /**
     * El valor de una parte por nombre.
     *
     * @param array<int, array<string, mixed>> $partes
     * @param string                           $nombre
     *
     * @return string
     */
    private function parte(array $partes, string $nombre): string
    {
        foreach ($partes as $parte) {
            if (isset($parte['name']) && $parte['name'] === $nombre) {
                return (string) $parte['contents'];
            }
        }

        return '';
    }

    /**
     * Foto con caption: viaja la imagen y el caption va como texto.
     *
     * @return void
     */
    public function test_una_foto_con_caption_viaja_con_su_texto(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client, 'Esto es la compra de Distribuidora Norte');

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media()]), $espia);

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);

        $partes = $this->partes_del_post();

        $this->assertSame(1, $this->contar_imagenes($partes), 'La foto tenía que viajar en el multipart.');
        $this->assertSame('Esto es la compra de Distribuidora Norte', $this->parte($partes, 'texto'));
        $this->assertSame('imagen', $this->parte($partes, 'tipo'));
    }

    /**
     * Foto sin caption: viaja igual, con el texto vacío.
     *
     * 🔴 El texto vacío es válido y es el caso normal, no un borde: el dueño manda la foto sola y
     * dice el proveedor en el mensaje siguiente. El asistente las junta del otro lado. Inventarle un
     * texto —"[Foto]", o lo que fuera— sería meterle al hilo palabras que el dueño no dijo.
     *
     * @return void
     */
    public function test_una_foto_sin_caption_viaja_con_texto_vacio(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media()]), $espia);

        $partes = $this->partes_del_post();

        $this->assertSame(1, $this->contar_imagenes($partes));
        $this->assertSame('', $this->parte($partes, 'texto'));
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->refresh()->estado);
    }

    /**
     * 🔴 Si la descarga falla, el mensaje SALE IGUAL, con texto y sin foto.
     *
     * Y el asistente se entera: la nota se le pega al texto para que pueda pedir la foto de nuevo en
     * vez de contestar como si nunca hubiera habido ninguna.
     *
     * @return void
     */
    public function test_si_la_descarga_falla_el_mensaje_sale_igual(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client, 'Esto es la compra de Distribuidora Norte');

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response('', 500),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media()]), $espia);

        $fila->refresh();

        $this->assertSame(
            ClientAssistantMessage::ESTADO_ENVIADO,
            $fila->estado,
            'Una foto que no se pudo bajar no puede voltear el mensaje del dueño.'
        );

        $partes = $this->partes_del_post();

        $this->assertSame(0, $this->contar_imagenes($partes), 'No viaja ninguna foto si no se pudo bajar.');

        $texto = $this->parte($partes, 'texto');
        $this->assertStringContainsString('Esto es la compra de Distribuidora Norte', $texto);
        $this->assertStringContainsString('no se pudo recibir', $texto, 'El asistente tiene que enterarse de que faltó la foto.');

        $this->assertStringContainsString('No se pudo descargar', (string) $fila->error);
        $this->assertCount(0, $espia->textos, 'Al dueño no se le manda ninguna disculpa: su mensaje salió.');
    }

    /**
     * Una foto sin caption que tampoco se pudo bajar deja un texto que igual dice algo.
     *
     * Sin esto el mensaje viajaría con el texto vacío Y sin imagen, o sea sin absolutamente nada
     * adentro: el asistente no tendría qué contestar y el dueño se quedaría esperando.
     *
     * @return void
     */
    public function test_una_foto_sin_caption_que_falla_deja_la_nota_como_texto(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response('', 500),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media()]), $espia);

        $texto = $this->parte($this->partes_del_post(), 'texto');

        $this->assertNotSame('', $texto);
        $this->assertStringContainsString('no se pudo recibir', $texto);
    }

    /**
     * Más de tres fotos: viajan tres y el descarte queda escrito.
     *
     * Hoy el webhook trae una por mensaje, así que esto es la red para el día que eso cambie o para
     * una corrida a mano del job. Se valida de ESTE lado porque el `empresa-api` acepta hasta tres:
     * mandarle cuatro convierte un mensaje con una foto de más en un mensaje perdido.
     *
     * @return void
     */
    public function test_mas_de_tres_fotos_viajan_solo_tres(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client, 'Cinco fotos de la misma factura');

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $cinco = [];
        for ($i = 1; $i <= 5; $i++) {
            $cinco[] = $this->media((string) $i);
        }

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id, $cinco), $espia);

        $fila->refresh();

        $this->assertSame(
            AsistenteImagenesService::MAXIMO_DE_IMAGENES,
            $this->contar_imagenes($this->partes_del_post())
        );

        $this->assertStringContainsString('Se descartaron 2 foto(s)', (string) $fila->error);
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);
    }

    /**
     * Un adjunto que no es una foto no se baja siquiera.
     *
     * @return void
     */
    public function test_un_adjunto_que_no_es_foto_se_descarta_sin_bajarlo(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client, 'Te mando el remito');

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
        ]);

        $this->correr_job(
            new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media('1', 'application/pdf')]),
            $espia
        );

        $fila->refresh();

        $this->assertSame(0, $this->contar_imagenes($this->partes_del_post()));
        $this->assertStringContainsString('application/pdf', (string) $fila->error);
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.kapso.ai/media') !== false;
        });
    }

    /**
     * Un archivo que se dice imagen pero no lo es, se descarta por sus bytes.
     *
     * 🔴 El tipo real sale de los BYTES y no del `mime` del payload. Un `mime` mentido termina en un
     * `Content-Type` que no coincide con el contenido, y del otro lado eso es una imagen que el
     * modelo no puede leer — un fallo mudo, que es el peor.
     *
     * @return void
     */
    public function test_un_archivo_que_dice_ser_foto_pero_no_lo_es_se_descarta(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client, 'Mirá esto');

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response('esto no es una imagen, es texto plano', 200),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media()]), $espia);

        $fila->refresh();

        $this->assertSame(0, $this->contar_imagenes($this->partes_del_post()));
        $this->assertStringContainsString('no es una imagen válida', (string) $fila->error);
        $this->assertStringContainsString('no se pudo recibir', $this->parte($this->partes_del_post(), 'texto'));
    }

    /**
     * El `Content-Type` de cada parte sale de los bytes, no del payload.
     *
     * @return void
     */
    public function test_el_content_type_de_la_parte_sale_de_los_bytes(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $fila   = $this->fila_de_foto($client, 'Factura');

        /* El payload DECLARA jpeg y los bytes son png: tiene que ganar el png. */
        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200),
        ]);

        $this->correr_job(
            new EnviarMensajeAlAsistenteJob((int) $fila->id, [$this->media('1', 'image/jpeg')]),
            $espia
        );

        $encontrada = false;
        foreach ($this->partes_del_post() as $parte) {
            if (! isset($parte['name']) || $parte['name'] !== 'imagenes[]') {
                continue;
            }

            $encontrada = true;
            $this->assertSame('image/png', $parte['headers']['Content-Type']);
            $this->assertStringEndsWith('.png', (string) $parte['filename']);
        }

        $this->assertTrue($encontrada, 'Tenía que viajar una foto.');
    }

    /**
     * Un mensaje sin fotos no cambia en nada: ni parte de imagen, ni nota, ni `error`.
     *
     * @return void
     */
    public function test_un_mensaje_sin_fotos_sigue_igual_que_antes(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.SIN-FOTO';
        $fila->tipo                = 'text';
        $fila->texto               = 'Cuánto vendí ayer?';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();
        $partes = $this->partes_del_post();

        $this->assertSame(0, $this->contar_imagenes($partes));
        $this->assertSame('Cuánto vendí ayer?', $this->parte($partes, 'texto'));
        $this->assertSame('texto', $this->parte($partes, 'tipo'));
        $this->assertNull($fila->error);
    }

    /**
     * El webhook arma la lista de fotos solo para un mensaje de tipo imagen.
     *
     * @return void
     */
    public function test_el_webhook_solo_manda_fotos_de_un_mensaje_de_imagen(): void
    {
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();

        $servicio  = app(\App\Services\AsistenteWhatsappService::class);
        $reflexion = new \ReflectionMethod($servicio, 'imagenes_del_mensaje');
        $reflexion->setAccessible(true);

        $media = $this->media();

        $this->assertSame(
            [$media],
            $reflexion->invoke($servicio, ['type' => 'image', 'inbound_media' => $media])
        );

        /* Un audio llega transcripto en el texto y un documento no es algo que el asistente pueda
         * mirar: ninguno de los dos se intenta bajar. */
        $this->assertSame([], $reflexion->invoke($servicio, ['type' => 'audio', 'inbound_media' => $media]));
        $this->assertSame([], $reflexion->invoke($servicio, ['type' => 'document', 'inbound_media' => $media]));
        $this->assertSame([], $reflexion->invoke($servicio, ['type' => 'image', 'inbound_media' => null]));
        $this->assertSame([], $reflexion->invoke($servicio, ['type' => 'text', 'body' => 'hola']));
    }

    /**
     * La URL firmada de Kapso nunca se recorta a medias en un log.
     *
     * @return void
     */
    public function test_la_url_firmada_no_se_loguea_entera(): void
    {
        $servicio  = app(\App\Services\WhatsappInboundMediaService::class);
        $reflexion = new \ReflectionMethod($servicio, 'url_para_log');
        $reflexion->setAccessible(true);

        $segura = $reflexion->invoke($servicio, 'https://api.kapso.ai/media/foto-1?firma=secreta&token=abc');

        $this->assertStringNotContainsString('secreta', $segura);
        $this->assertStringNotContainsString('abc', $segura);
        $this->assertStringContainsString('api.kapso.ai/media/foto-1', $segura);
    }
}
