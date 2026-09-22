<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteFotoSalienteService;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;

/**
 * Que la foto del asistente LLEGUE, que es distinto de que se mande.
 *
 * El caso lo reportó Lucas el 22/9/2026: *"las fotos que me manda el agente las veo en el chat del
 * sistema pero no me llegan a whatsapp"*. Y el `laravel.log` de producción decía
 * `{"entregada":true,"imagenes_enviadas":1,"imagenes_fallidas":0}` — o sea que el admin la mandó y
 * Kapso/Meta aceptó el pedido. La causa es el formato: la Cloud API acepta en un mensaje `image`
 * solo **jpeg y png**, el webp lo reserva para stickers, y **valida el link de forma asíncrona**,
 * después de haber contestado que sí. Por eso el envío figura exitoso y la foto nunca aparece, sin
 * un error en ningún log. En demo3 las 2.663 fotos de artículos son webp: el 100 %.
 *
 * Lo que estas pruebas fijan:
 *
 *   1. **Un webp sale convertido a JPEG y por media_id**, no por link.
 *   2. **Un jpeg o un png siguen saliendo por link, sin bajar nada.** Es el camino barato y no se
 *      rompe: si se convirtiera todo, cada foto costaría dos viajes de red y una pasada de GD por
 *      un archivo que Meta baja sola y gratis.
 *   3. **La conversión no se repite por un 409.** Bajar y convertir están fuera del bucle de
 *      reintentos; el reintento existe por la conversación tomada de Kapso, no por los bytes.
 *   4. **Nada de esto puede voltear el turno.** El texto ya salió: una descarga que falla, un link
 *      que devuelve HTML o una foto gigante son una línea de log y nada más.
 *
 * El envío se mide con el espía de `BaseDelCanal`, que guarda los bytes que habrían viajado. Lo que
 * sale de verdad a Kapso por este camino lo mide `EnvioDeImagenPorMediaIdTest`.
 */
class ConversionDeFotosDelAsistenteTest extends BaseDelCanal
{
    /**
     * Host de las fotos del catálogo del cliente en estas pruebas.
     */
    const HOST_DE_FOTOS = 'https://api-ferreteria-de-prueba.test/storage/';

    /**
     * Microsegundos que el job habría dormido, en orden.
     *
     * @var array<int, int>
     */
    private $pausas = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pausas = [];

        /* Los dominios `.test` de estas pruebas no resuelven a nada, y el control de destino
         * rechazaría todo antes de bajar. El control tiene sus propias pruebas más abajo. */
        $this->fotos_que_resuelven_a('190.2.3.4');
    }

    /**
     * El job con la pausa anulada: registra cuánto habría esperado en vez de dormir.
     *
     * @param ClientAssistantMessage $fila
     *
     * @return EnviarMensajeAlAsistenteJob
     */
    private function job_sin_dormir(ClientAssistantMessage $fila): EnviarMensajeAlAsistenteJob
    {
        return new class((int) $fila->id, $this->pausas) extends EnviarMensajeAlAsistenteJob {
            /** @var array<int, int> */
            private $registro;

            public function __construct(int $mensaje_id, array &$registro)
            {
                parent::__construct($mensaje_id);

                $this->registro = &$registro;
            }

            protected function pausar(int $microsegundos): void
            {
                $this->registro[] = $microsegundos;
            }
        };
    }

    /**
     * Deja la fila entrante como la deja el webhook.
     *
     * @param Client $client Cliente dueño del hilo.
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(Client $client): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Mostrame la foto de los precintos';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * Un adjunto de imagen como lo devuelve el `empresa-api`.
     *
     * @param string      $archivo Nombre con extensión, tal como vive en el hosting del cliente.
     * @param string|null $texto   Epígrafe.
     *
     * @return array<string, mixed>
     */
    private function adjunto(string $archivo, ?string $texto = 'Precintos 03 200 mm x 4,8 mm'): array
    {
        $adjunto = [
            'tipo' => 'imagen',
            'url'  => self::HOST_DE_FOTOS . $archivo,
        ];

        if ($texto !== null) {
            $adjunto['texto'] = $texto;
        }

        return $adjunto;
    }

    /**
     * Fakea la ida (202), la vuelta (`listo`) y lo que devuelve el link de la foto.
     *
     * @param array<int, mixed>                  $adjuntos Lo que va en `adjuntos`.
     * @param array<string, \GuzzleHttp\Promise\PromiseInterface> $fotos Stubs por patrón para las fotos.
     *
     * @return void
     */
    private function fakear(array $adjuntos, array $fotos = []): void
    {
        $respuesta = [
            'estado'             => 'listo',
            'contenido'          => 'Acá va la foto de los Precintos 03.',
            'error_mensaje'      => null,
            'ai_conversation_id' => 91,
            'adjuntos'           => $adjuntos,
        ];

        /* El orden importa: gana el primer patrón que matchea, y los de las fotos son más
         * específicos que los del endpoint del asistente. */
        $this->fakear_http(array_merge($fotos, [
            '*/asistente/mensajes'     => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
            '*/asistente/mensajes/305' => Http::response($respuesta, 200),
        ]));
    }

    /**
     * La ida y la vuelta, sobre la misma instancia del job.
     *
     * @param ClientAssistantMessage $fila
     * @param WhatsappSendService    $espia
     *
     * @return void
     */
    private function tramitar(ClientAssistantMessage $fila, WhatsappSendService $espia): void
    {
        $job = $this->job_sin_dormir($fila);

        $this->correr_job($job, $espia);
        $this->correr_job($job, $espia);
    }

    /**
     * Bytes de una imagen armada con GD, del tamaño y el formato que se pidan.
     *
     * @param string $formato  webp | jpeg | png
     * @param int    $ancho
     * @param int    $alto
     * @param bool   $con_alfa true deja la imagen entera transparente (el fondo recortado de una
     *                         foto de producto, que es el caso real del catálogo).
     *
     * @return string
     */
    private function imagen(string $formato, int $ancho = 800, int $alto = 600, bool $con_alfa = false): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);

        if ($con_alfa) {
            imagealphablending($imagen, false);
            imagesavealpha($imagen, true);
            $transparente = imagecolorallocatealpha($imagen, 0, 0, 0, 127);
            imagefilledrectangle($imagen, 0, 0, $ancho - 1, $alto - 1, $transparente);
        } else {
            $color = imagecolorallocate($imagen, 30, 120, 200);
            imagefilledrectangle($imagen, 0, 0, $ancho - 1, $alto - 1, $color);
        }

        ob_start();

        if ($formato === 'webp') {
            imagewebp($imagen, null, 90);
        } elseif ($formato === 'png') {
            imagepng($imagen);
        } else {
            imagejpeg($imagen, null, 90);
        }

        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /**
     * Corta la prueba si este PHP no puede ESCRIBIR webp.
     *
     * Solo hace falta para armar el original de la prueba: lo que producción necesita es LEERLO, y
     * eso está verificado contra el PHP 7.4 del VPS (GD con WebP, JPEG y PNG, 22/9/2026).
     *
     * @return void
     */
    private function requiere_webp(): void
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('Este PHP no tiene GD con WebP: no se puede armar el original de la prueba.');
        }
    }

    /**
     * Cuántas veces se pidió una URL a través del fake.
     *
     * @param string $url
     *
     * @return int
     */
    private function veces_que_se_pidio(string $url): int
    {
        $veces = 0;

        foreach (Http::recorded() as $par) {
            if ($par[0]->url() === $url) {
                $veces++;
            }
        }

        return $veces;
    }

    /**
     * Una foto webp se baja, se convierte a JPEG y sale por media_id, no por link.
     *
     * 🔴 Es el bug entero: por link Meta contesta 200 y descarta el webp después, en silencio.
     *
     * @return void
     */
    public function test_una_foto_webp_se_convierte_a_jpeg_y_sale_por_media_id(): void
    {
        $this->requiere_webp();

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('precinto-1.webp', 'Precintos 03 200 mm x 4,8 mm')],
            ['*/storage/*' => Http::response($this->imagen('webp'), 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);

        $this->assertCount(0, $espia->imagenes, 'Un webp NO puede salir por link: Meta lo descarta.');
        $this->assertCount(1, $espia->imagenes_subidas);
        $this->assertSame(['texto', 'imagen'], $espia->orden, 'Primero la respuesta, después la foto.');

        $subida = $espia->imagenes_subidas[0];

        $this->assertSame((string) $client->phone, $subida['to']);
        $this->assertSame('image/jpeg', $subida['mime']);
        $this->assertSame('precinto-1.jpg', $subida['nombre'], 'La extensión tiene que coincidir con el mime que se declara.');
        $this->assertSame('Precintos 03 200 mm x 4,8 mm', $subida['caption']);
        $this->assertStringContainsString('cliente #' . $client->id, (string) $subida['context']);

        /* Lo que viaja son BYTES DE JPEG, no el webp con otro nombre. */
        $info = getimagesizefromstring($subida['bytes']);
        $this->assertNotFalse($info);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(800, $info[0]);
        $this->assertSame(600, $info[1]);
    }

    /**
     * Un jpeg sigue saliendo por link y nadie baja el archivo.
     *
     * 🔴 El camino barato no se rompe: Meta baja sola una URL que ya es de un tipo que acepta, y
     * convertirla igual serían dos viajes de red y una pasada de GD por nada.
     *
     * @return void
     */
    public function test_una_foto_jpg_sigue_saliendo_por_link_sin_bajar_nada(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear([$this->adjunto('precinto-1.jpg')]);

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes);
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(self::HOST_DE_FOTOS . 'precinto-1.jpg', $espia->imagenes[0]['url']);

        $this->assertSame(
            0,
            $this->veces_que_se_pidio(self::HOST_DE_FOTOS . 'precinto-1.jpg'),
            'Por link el admin no baja el archivo: lo baja Meta.'
        );
    }

    /**
     * Un png también sale por link: Meta lo acepta igual que el jpeg.
     *
     * @return void
     */
    public function test_una_foto_png_sale_por_link(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear([$this->adjunto('precinto-1.PNG')]);

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes, 'La extensión se mira en minúsculas.');
        $this->assertCount(0, $espia->imagenes_subidas);
    }

    /**
     * Una URL sin extensión se baja y se decide por los bytes; si ya es jpeg, se sube tal cual.
     *
     * Sin extensión no se puede afirmar nada, así que va por el camino largo — pero un jpeg no se
     * reencodea: eso sería perder calidad para llegar al mismo lugar.
     *
     * @return void
     */
    public function test_una_url_sin_extension_se_baja_y_se_sube_sin_reencodear_si_ya_es_jpeg(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $original = $this->imagen('jpeg', 400, 300);

        $this->fakear(
            [$this->adjunto('178960416348922')],
            ['*/storage/*' => Http::response($original, 200, ['Content-Type' => 'image/jpeg'])]
        );

        $this->tramitar($fila, $espia);

        $this->assertCount(0, $espia->imagenes);
        $this->assertCount(1, $espia->imagenes_subidas);

        $subida = $espia->imagenes_subidas[0];
        $this->assertSame('image/jpeg', $subida['mime']);
        $this->assertSame('178960416348922.jpg', $subida['nombre']);
        $this->assertSame($original, $subida['bytes'], 'Ya era jpeg: se sube igual, sin pasar por GD.');
    }

    /**
     * Un webp con transparencia sale aplanado sobre BLANCO, no sobre negro.
     *
     * 🔴 JPEG no tiene canal alfa: sin aplanar, el fondo recortado de una foto de producto —que es
     * el caso normal del catálogo— le llega al dueño en negro.
     *
     * @return void
     */
    public function test_un_webp_con_transparencia_sale_aplanado_sobre_blanco(): void
    {
        $this->requiere_webp();

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('recorte.webp')],
            ['*/storage/*' => Http::response($this->imagen('webp', 200, 200, true), 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes_subidas);

        $jpeg = imagecreatefromstring($espia->imagenes_subidas[0]['bytes']);
        $this->assertNotFalse($jpeg);

        $color = imagecolorat($jpeg, 5, 5);
        $rojo  = ($color >> 16) & 0xFF;
        $verde = ($color >> 8) & 0xFF;
        $azul  = $color & 0xFF;
        imagedestroy($jpeg);

        /* El umbral y no el 255 exacto porque el JPEG es con pérdida; lo que se descarta es el
         * negro (0,0,0) que sale de encodear el alfa sin aplanarlo. */
        $this->assertGreaterThanOrEqual(240, $rojo, 'El fondo transparente tiene que quedar blanco, no negro.');
        $this->assertGreaterThanOrEqual(240, $verde);
        $this->assertGreaterThanOrEqual(240, $azul);
    }

    /**
     * Una foto más grande que el lado máximo se achica, y el resultado entra en el tope de Meta.
     *
     * @return void
     */
    public function test_una_foto_mas_grande_que_el_lado_maximo_se_achica(): void
    {
        $this->requiere_webp();

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('gigante.webp')],
            ['*/storage/*' => Http::response($this->imagen('webp', 2400, 1200), 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes_subidas);

        $bytes = $espia->imagenes_subidas[0]['bytes'];
        $info  = getimagesizefromstring($bytes);

        $this->assertNotFalse($info);
        $this->assertSame(AsistenteFotoSalienteService::LADO_MAXIMO, $info[0], 'El lado mayor se lleva al máximo.');
        $this->assertSame(800, $info[1], 'Y la proporción se conserva.');
        $this->assertLessThanOrEqual(
            AsistenteFotoSalienteService::MAXIMO_DE_BYTES_PARA_META,
            strlen($bytes),
            'Meta topea la imagen en 5 MB.'
        );
    }

    /**
     * Una descarga que falla no voltea el turno: el texto ya salió.
     *
     * @return void
     */
    public function test_si_no_se_puede_bajar_la_foto_el_turno_no_se_cae(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('precinto-1.webp')],
            ['*/storage/*' => Http::response('no existe', 404)]
        );

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error, 'Una foto que no sale no es un error del turno.');
        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->imagenes);
        $this->assertCount(0, $espia->imagenes_subidas, 'Sin bytes no hay nada que subir.');

        $salientes = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->get();

        $this->assertCount(1, $salientes);
        $this->assertSame('wamid.saliente.1', $salientes[0]->whatsapp_message_id);
    }

    /**
     * Un link que devuelve cualquier cosa que no sea una imagen se descarta sin mandar nada.
     *
     * Es el caso del hosting que contesta una página de error con status 200.
     *
     * @return void
     */
    public function test_lo_que_no_es_una_imagen_no_se_manda(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('precinto-1.webp')],
            ['*/storage/*' => Http::response('<html><body>404 Not Found</body></html>', 200, ['Content-Type' => 'text/html'])]
        );

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertCount(1, $espia->textos);
    }

    /**
     * Una foto que pasa el tope de descarga se descarta y el turno sigue igual.
     *
     * @return void
     */
    public function test_una_foto_que_pasa_el_tope_de_descarga_se_descarta(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $enorme = str_repeat('x', AsistenteFotoSalienteService::MAXIMO_DE_BYTES_DE_DESCARGA + 1024);

        $this->fakear(
            [$this->adjunto('precinto-1.webp')],
            ['*/storage/*' => Http::response($enorme, 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertCount(0, $espia->imagenes_subidas);
    }

    /**
     * El 409 de Kapso reintenta el envío, pero NO vuelve a bajar ni a convertir la foto.
     *
     * 🔴 Es lo que fija que bajar y convertir estén fuera del bucle de reintentos: el 409 es la
     * conversación tomada, no tiene nada que ver con los bytes, y volver a pedirle el archivo al
     * hosting del cliente para llegar al mismo JPEG es gasto puro adentro de un job con timeout 60.
     *
     * @return void
     */
    public function test_el_409_reintenta_el_envio_sin_volver_a_bajar_ni_convertir(): void
    {
        $this->requiere_webp();

        $espia = $this->espiar_sender();
        $espia->falla_transitoria_veces = 1;

        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('precinto-1.webp')],
            ['*/storage/*' => Http::response($this->imagen('webp', 300, 300), 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $this->assertCount(2, $espia->imagenes_subidas, 'El 409 se reintenta una vez.');
        $this->assertSame(
            $espia->imagenes_subidas[0]['bytes'],
            $espia->imagenes_subidas[1]['bytes'],
            'El segundo intento manda exactamente los mismos bytes.'
        );

        $this->assertSame(
            1,
            $this->veces_que_se_pidio(self::HOST_DE_FOTOS . 'precinto-1.webp'),
            'La foto se baja UNA vez, aunque el envío se reintente.'
        );

        $this->assertSame(
            [
                EnviarMensajeAlAsistenteJob::PAUSA_ANTES_DE_CADA_FOTO_US,
                EnviarMensajeAlAsistenteJob::ESPERA_ANTES_DE_REINTENTAR_FOTO_US,
            ],
            $this->pausas
        );
    }

    /**
     * Una excepción subiendo la foto tampoco voltea el turno.
     *
     * @return void
     */
    public function test_una_excepcion_subiendo_la_foto_no_voltea_el_turno(): void
    {
        $this->requiere_webp();

        $espia = $this->espiar_sender();
        $espia->explota_imagenes = true;

        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [$this->adjunto('precinto-1.webp')],
            ['*/storage/*' => Http::response($this->imagen('webp', 300, 300), 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error);
        $this->assertCount(1, $espia->textos);
    }

    /**
     * Una respuesta con un jpeg y un webp usa un camino distinto para cada uno, en orden.
     *
     * Es lo observable de los contadores nuevos del log (`imagenes_por_link` e
     * `imagenes_convertidas`): el que lee esa línea tiene que poder saber por dónde fue cada foto,
     * porque un envío por link se cuenta como exitoso apenas Meta contesta y Meta valida después.
     *
     * @return void
     */
    public function test_cada_foto_toma_el_camino_que_le_corresponde(): void
    {
        $this->requiere_webp();

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear(
            [
                $this->adjunto('precinto-1.jpg', 'El que ya es jpeg'),
                $this->adjunto('precinto-2.webp', 'El que hay que convertir'),
            ],
            ['*/storage/precinto-2.webp' => Http::response($this->imagen('webp', 300, 300), 200, ['Content-Type' => 'image/webp'])]
        );

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes, 'El jpeg va por link.');
        $this->assertSame('El que ya es jpeg', $espia->imagenes[0]['caption']);

        $this->assertCount(1, $espia->imagenes_subidas, 'El webp va convertido, por media_id.');
        $this->assertSame('El que hay que convertir', $espia->imagenes_subidas[0]['caption']);
        $this->assertSame('image/jpeg', $espia->imagenes_subidas[0]['mime']);

        $this->assertSame(['texto', 'imagen', 'imagen'], $espia->orden);
        $this->assertSame(0, $this->veces_que_se_pidio(self::HOST_DE_FOTOS . 'precinto-1.jpg'));
        $this->assertSame(1, $this->veces_que_se_pidio(self::HOST_DE_FOTOS . 'precinto-2.webp'));
    }
}
