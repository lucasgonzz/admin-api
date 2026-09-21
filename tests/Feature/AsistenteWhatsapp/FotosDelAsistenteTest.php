<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;

/**
 * Las fotos que el asistente le manda al dueño junto con su respuesta.
 *
 * Es la otra mitad de `FotosDelDuenoTest`: ahí las fotos van del dueño al asistente; acá vuelven
 * del asistente al dueño. El caso lo dictó Lucas con una pregunta que falló en la demo: *"mostrame
 * la foto"* — el asistente encontraba el artículo y no tenía forma de adjuntar la imagen. Desde la
 * misión `asistente-omnisciente` el `empresa-api` devuelve `adjuntos` en
 * `GET admin-sync/asistente/mensajes/{id}` (contrato §2) y el admin manda cada uno como un mensaje
 * de imagen por link, con el texto del adjunto como epígrafe.
 *
 * Tres cosas que cuidar, y ninguna es "que la foto llegue", que es la fácil:
 *
 *   1. **El texto va primero y las fotos después, cada una con su epígrafe.** Es lo que el dueño
 *      lee: la respuesta, y debajo cada foto con el nombre del artículo.
 *   2. **Una foto que no sale no toca el turno.** El texto ya salió: la fila queda `respondido`, la
 *      fila saliente del texto queda con su wamid, y al dueño no le llega ninguna disculpa. Vale
 *      también si el envío revienta con una excepción.
 *   3. **Compatibilidad con el API viejo.** Sin `adjuntos` no cambia nada, ni una línea.
 *
 * El envío se mide con el espía de `BaseDelCanal`, que registra qué salió y en qué orden. Lo que
 * viaja de verdad a Meta cuando se manda una foto por link lo mide `EnvioDeImagenPorLinkTest`.
 *
 * Las pausas del job (`pausar()`) se anulan con una subclase anónima que las registra en vez de
 * dormir, igual que hacen las pruebas de `SupportWhatsappOpenerService`: así se puede medir que la
 * pausa del 409 se pide antes de cada foto sin que una prueba con seis fotos duerma siete segundos.
 */
class FotosDelAsistenteTest extends BaseDelCanal
{
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
     * @param string      $sufijo Para que cada uno tenga su URL.
     * @param string|null $texto  Epígrafe; null = sin clave `texto`.
     *
     * @return array<string, mixed>
     */
    private function adjunto(string $sufijo = '1', ?string $texto = 'Precintos 03 200 mm x 4,8 mm'): array
    {
        $adjunto = [
            'tipo'        => 'imagen',
            'url'         => 'https://r2.comerciocity.com/ferreteria/articulos/precinto-' . $sufijo . '.jpg',
            'articulo_id' => 8000 + (int) $sufijo,
        ];

        if ($texto !== null) {
            $adjunto['texto'] = $texto;
        }

        return $adjunto;
    }

    /**
     * Fakea la ida (202) y la vuelta (`listo`) con los adjuntos que se pidan.
     *
     * @param array<int, mixed>|null $adjuntos Lo que va en `adjuntos`; null = sin la clave (API viejo).
     * @param string                 $contenido Texto de la respuesta.
     *
     * @return void
     */
    private function fakear_respuesta_lista($adjuntos, string $contenido = 'Acá va la foto de los Precintos 03.'): void
    {
        $respuesta = [
            'estado'             => 'listo',
            'contenido'          => $contenido,
            'error_mensaje'      => null,
            'ai_conversation_id' => 91,
        ];

        if ($adjuntos !== null) {
            $respuesta['adjuntos'] = $adjuntos;
        }

        $this->fakear_http([
            '*/asistente/mensajes'     => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
            '*/asistente/mensajes/305' => Http::response($respuesta, 200),
        ]);
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
     * La fila saliente que dejó el texto de la respuesta.
     *
     * @param Client $client
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ClientAssistantMessage>
     */
    private function salientes(Client $client)
    {
        return ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->orderBy('id')
            ->get();
    }

    /**
     * Dos adjuntos: sale el texto y después las dos fotos por link, con su epígrafe, en ese orden.
     *
     * @return void
     */
    public function test_la_respuesta_con_dos_fotos_manda_el_texto_y_despues_cada_foto_con_su_epigrafe(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1', 'Precintos 03 200 mm x 4,8 mm'),
            $this->adjunto('2', 'Precintos 04 300 mm x 4,8 mm'),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('Acá va la foto de los Precintos 03.', $espia->textos[0]['body']);

        $this->assertCount(2, $espia->imagenes);
        $this->assertSame(
            ['texto', 'imagen', 'imagen'],
            $espia->orden,
            'Primero la respuesta, después las fotos: es lo que el dueño lee.'
        );

        $this->assertSame((string) $client->phone, $espia->imagenes[0]['to']);
        $this->assertSame('https://r2.comerciocity.com/ferreteria/articulos/precinto-1.jpg', $espia->imagenes[0]['url']);
        $this->assertSame('Precintos 03 200 mm x 4,8 mm', $espia->imagenes[0]['caption']);

        $this->assertSame((string) $client->phone, $espia->imagenes[1]['to']);
        $this->assertSame('https://r2.comerciocity.com/ferreteria/articulos/precinto-2.jpg', $espia->imagenes[1]['url']);
        $this->assertSame('Precintos 04 300 mm x 4,8 mm', $espia->imagenes[1]['caption']);

        /* El contexto es lo que se lee en el motivo de un fallo: tiene que decir que es una foto
         * del asistente y de qué cliente. */
        $this->assertStringContainsString('foto', (string) $espia->imagenes[0]['context']);
        $this->assertStringContainsString('cliente #' . $client->id, (string) $espia->imagenes[0]['context']);
    }

    /**
     * Sin `adjuntos` (API viejo) no se manda ninguna foto y todo lo demás queda igual que antes.
     *
     * 🔴 Es la compatibilidad hacia atrás del contrato: los 40+ clientes corren versiones distintas
     * y durante semanas la mayoría va a contestar sin la clave. Ese turno tiene que ser idéntico al
     * de hoy: texto, fila `respondido` y una sola fila saliente con su wamid.
     *
     * @return void
     */
    public function test_sin_adjuntos_no_se_manda_ninguna_foto(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista(null, 'Ayer vendiste $184.500 en 23 ventas.');

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->imagenes, 'Sin la clave no hay nada que mandar.');
        $this->assertSame(['texto'], $espia->orden);

        $salientes = $this->salientes($client);
        $this->assertCount(1, $salientes);
        $this->assertSame('wamid.saliente.1', $salientes[0]->whatsapp_message_id);
        $this->assertSame('Ayer vendiste $184.500 en 23 ventas.', $salientes[0]->texto);
    }

    /**
     * Un `adjuntos` vacío se comporta igual que uno ausente.
     *
     * @return void
     */
    public function test_una_lista_de_adjuntos_vacia_no_manda_ninguna_foto(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([]);

        $this->tramitar($fila, $espia);

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->refresh()->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->imagenes);
    }

    /**
     * Lo que no es una foto con URL `http(s)` se saltea sin error; lo que sí, sale igual.
     *
     * Cada ítem se valida por su cuenta: un adjunto raro —de otro tipo, con una URL relativa, con
     * `ftp://`, sin URL, o que ni siquiera es una lista— no puede voltear a los que están bien.
     *
     * @return void
     */
    public function test_un_adjunto_que_no_es_foto_o_sin_url_http_se_saltea(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            ['tipo' => 'video', 'url' => 'https://r2.comerciocity.com/ferreteria/video.mp4', 'texto' => 'Un video'],
            ['tipo' => 'imagen', 'url' => 'ftp://r2.comerciocity.com/ferreteria/foto.jpg', 'texto' => 'Por ftp'],
            ['tipo' => 'imagen', 'url' => '/storage/articulos/foto.jpg', 'texto' => 'Relativa'],
            ['tipo' => 'imagen', 'url' => 'httpx://nada', 'texto' => 'Empieza con http pero no es http(s)'],
            ['tipo' => 'imagen', 'url' => '', 'texto' => 'Vacía'],
            ['tipo' => 'imagen', 'texto' => 'Sin url'],
            ['tipo' => 'imagen', 'url' => ['no' => 'es string'], 'texto' => 'URL que no es string'],
            ['tipo' => ['imagen'], 'url' => 'https://r2.comerciocity.com/ferreteria/foto.jpg'],
            'esto no es un adjunto',
            42,
            null,
            $this->adjunto('9', 'La única que vale'),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(1, $espia->imagenes, 'De todo eso, una sola era una foto por http(s).');
        $this->assertSame('https://r2.comerciocity.com/ferreteria/articulos/precinto-9.jpg', $espia->imagenes[0]['url']);
        $this->assertSame('La única que vale', $espia->imagenes[0]['caption']);
    }

    /**
     * Un `adjuntos` que no es una lista (un string, un número) se ignora sin romper el turno.
     *
     * @return void
     */
    public function test_un_adjuntos_que_no_es_una_lista_se_ignora(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista('https://r2.comerciocity.com/ferreteria/articulos/precinto-1.jpg');

        $this->tramitar($fila, $espia);

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->refresh()->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->imagenes);
    }

    /**
     * Un adjunto sin `texto` (o con el texto vacío) va sin epígrafe, no con uno inventado.
     *
     * @return void
     */
    public function test_una_foto_sin_texto_va_sin_epigrafe(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1', null),
            $this->adjunto('2', ''),
            $this->adjunto('3', '   '),
        ]);

        $this->tramitar($fila, $espia);

        $this->assertCount(3, $espia->imagenes);
        $this->assertNull($espia->imagenes[0]['caption'], 'Sin clave texto: sin caption.');
        $this->assertNull($espia->imagenes[1]['caption'], 'Texto vacío: sin caption.');
        $this->assertNull($espia->imagenes[2]['caption'], 'Texto en blanco: sin caption.');
    }

    /**
     * 🔴 Una foto que Meta rechaza no cambia el estado `respondido` ni la fila saliente del texto.
     *
     * El texto ya salió y el dueño lo tiene en la mano. Lo que NO puede pasar es que por una foto
     * la fila pase a error, que se le mande la disculpa, o que la fila saliente del texto pierda su
     * wamid —que es lo que hace andar la cita del próximo turno.
     *
     * @return void
     */
    public function test_una_foto_que_meta_rechaza_no_cambia_el_estado_ni_el_texto_registrado(): void
    {
        $espia  = $this->espiar_sender();
        $espia->confirma_imagenes = false;
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1'),
            $this->adjunto('2'),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado, 'El texto salió: el turno está respondido.');
        $this->assertNull($fila->error, 'Una foto que no sale no es un error del turno.');

        /* Se intentaron las dos: la primera que falla no frena a la segunda. */
        $this->assertCount(2, $espia->imagenes);

        /* Y al dueño no le llega ninguna disculpa: un solo texto, el de la respuesta. */
        $this->assertCount(1, $espia->textos);
        $this->assertSame('Acá va la foto de los Precintos 03.', $espia->textos[0]['body']);

        $salientes = $this->salientes($client);
        $this->assertCount(1, $salientes, 'La única fila saliente es la del texto.');
        $this->assertSame('wamid.saliente.1', $salientes[0]->whatsapp_message_id);
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $salientes[0]->estado);
        $this->assertNull($salientes[0]->error);
        $this->assertSame('Acá va la foto de los Precintos 03.', $salientes[0]->texto);
    }

    /**
     * 🔴 Una excepción al mandar una foto tampoco cierra el turno con error.
     *
     * Sin el `try` propio de cada foto, el `Throwable` subiría hasta el `catch` de `handle()`, que
     * cerraría con error —y con la disculpa— un turno que el dueño ya recibió bien. Sería lo peor
     * de los dos mundos: la respuesta buena seguida de "no pude contestarte".
     *
     * @return void
     */
    public function test_una_excepcion_al_mandar_una_foto_no_cierra_el_turno_con_error(): void
    {
        $espia  = $this->espiar_sender();
        $espia->explota_imagenes = true;
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1'),
            $this->adjunto('2'),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error);
        $this->assertCount(2, $espia->imagenes, 'La que reventó no frena a la siguiente.');
        $this->assertCount(1, $espia->textos, 'Ninguna disculpa: el texto ya salió.');

        $salientes = $this->salientes($client);
        $this->assertCount(1, $salientes);
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $salientes[0]->estado);
    }

    /**
     * Más de seis fotos: salen seis, en el orden en que vinieron.
     *
     * El `empresa-api` ya recorta a seis; esto es la red de este lado, porque el que decide cuántos
     * mensajes de WhatsApp salen es el que los manda.
     *
     * @return void
     */
    public function test_mas_de_seis_fotos_viajan_solo_seis(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $ocho = [];
        for ($i = 1; $i <= 8; $i++) {
            $ocho[] = $this->adjunto((string) $i, 'Foto ' . $i);
        }

        $this->fakear_respuesta_lista($ocho);

        $this->tramitar($fila, $espia);

        $this->assertSame(6, EnviarMensajeAlAsistenteJob::MAXIMO_DE_FOTOS_POR_RESPUESTA);
        $this->assertCount(EnviarMensajeAlAsistenteJob::MAXIMO_DE_FOTOS_POR_RESPUESTA, $espia->imagenes);

        $urls = array_column($espia->imagenes, 'url');
        $this->assertSame('https://r2.comerciocity.com/ferreteria/articulos/precinto-1.jpg', $urls[0]);
        $this->assertSame('https://r2.comerciocity.com/ferreteria/articulos/precinto-6.jpg', $urls[5]);
        $this->assertNotContains('https://r2.comerciocity.com/ferreteria/articulos/precinto-7.jpg', $urls);
        $this->assertNotContains('https://r2.comerciocity.com/ferreteria/articulos/precinto-8.jpg', $urls);

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->refresh()->estado);
    }

    /**
     * El tope cuenta fotos válidas, no posiciones: seis buenas detrás de tres malas salen las seis.
     *
     * @return void
     */
    public function test_el_tope_cuenta_fotos_validas_y_no_posiciones(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $adjuntos = [
            ['tipo' => 'video', 'url' => 'https://r2.comerciocity.com/v.mp4'],
            ['tipo' => 'imagen', 'url' => '/relativa.jpg'],
            'basura',
        ];
        for ($i = 1; $i <= 6; $i++) {
            $adjuntos[] = $this->adjunto((string) $i);
        }

        $this->fakear_respuesta_lista($adjuntos);

        $this->tramitar($fila, $espia);

        $this->assertCount(6, $espia->imagenes);
    }

    /**
     * Si el texto no salió, no se manda ninguna foto.
     *
     * Una foto suelta sin la respuesta que la explica no le sirve al dueño, y el turno ya quedó
     * registrado como fallido con el motivo del texto, igual que hoy.
     *
     * @return void
     */
    public function test_si_el_texto_no_salio_no_se_manda_ninguna_foto(): void
    {
        $espia  = $this->espiar_sender(false);
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1'),
            $this->adjunto('2'),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertCount(1, $espia->textos, 'Se intentó el texto.');
        $this->assertCount(0, $espia->imagenes, 'Sin el texto, ninguna foto.');

        /* Y el registro del texto rechazado queda como estaba antes de esta misión. */
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertStringContainsString('Meta rechazó', (string) $fila->error);

        $salientes = $this->salientes($client);
        $this->assertCount(1, $salientes);
        $this->assertNull($salientes[0]->whatsapp_message_id);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $salientes[0]->estado);
    }

    /**
     * 🔴 Antes de CADA foto se hace la pausa del 409, incluida la primera.
     *
     * Kapso devuelve 409 "otro mensaje en vuelo para esta conversación" cuando dos envíos salen
     * pegados (lead #440). El texto acaba de salir por esta misma conversación, así que la primera
     * foto también espera; sin fotos, no se espera nada.
     *
     * @return void
     */
    public function test_antes_de_cada_foto_se_hace_la_pausa_del_409(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1'),
            $this->adjunto('2'),
            $this->adjunto('3'),
        ]);

        $this->tramitar($fila, $espia);

        $this->assertCount(3, $espia->imagenes);
        $this->assertSame(
            array_fill(0, 3, EnviarMensajeAlAsistenteJob::PAUSA_ANTES_DE_CADA_FOTO_US),
            $this->pausas,
            'Una pausa antes de cada foto, la primera incluida.'
        );
        $this->assertGreaterThanOrEqual(1200000, EnviarMensajeAlAsistenteJob::PAUSA_ANTES_DE_CADA_FOTO_US, 'Menos de 1200 ms no le alcanza a Kapso para soltar la conversación.');
    }

    /**
     * Sin fotos no se duerme ni un microsegundo: el turno de texto solo es idéntico al de hoy.
     *
     * @return void
     */
    public function test_sin_fotos_no_se_hace_ninguna_pausa(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista(null);

        $this->tramitar($fila, $espia);

        $this->assertSame([], $this->pausas);
    }

    /**
     * Un 409 de Kapso en una foto se reintenta UNA vez, después de esperar.
     *
     * Es el fallo transitorio por excelencia de este canal: la conversación todavía tomada por el
     * envío anterior. Se suelta en un segundo, y el segundo intento la saca.
     *
     * @return void
     */
    public function test_un_409_en_una_foto_se_reintenta_una_vez_despues_de_esperar(): void
    {
        $espia = $this->espiar_sender();
        $espia->falla_transitoria_veces = 1;
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([$this->adjunto('1', 'Precintos 03')]);

        $this->tramitar($fila, $espia);

        $this->assertCount(2, $espia->imagenes, 'El primer intento dio 409; el segundo salió.');
        $this->assertSame($espia->imagenes[0]['url'], $espia->imagenes[1]['url']);
        $this->assertSame('Precintos 03', $espia->imagenes[1]['caption']);

        $this->assertSame(
            [
                EnviarMensajeAlAsistenteJob::PAUSA_ANTES_DE_CADA_FOTO_US,
                EnviarMensajeAlAsistenteJob::ESPERA_ANTES_DE_REINTENTAR_FOTO_US,
            ],
            $this->pausas,
            'La pausa de siempre antes de la foto, y la espera del reintento después del 409.'
        );

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->refresh()->estado);
        $this->assertCount(1, $espia->textos, 'Ninguna disculpa.');
    }

    /**
     * Si el 409 persiste, se agotan los intentos y la foto queda como fallida sin tocar el turno.
     *
     * @return void
     */
    public function test_si_el_409_persiste_la_foto_queda_fallida_y_la_siguiente_sale_igual(): void
    {
        $espia = $this->espiar_sender();
        $espia->falla_transitoria_veces = EnviarMensajeAlAsistenteJob::INTENTOS_POR_FOTO;
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1'),
            $this->adjunto('2'),
        ]);

        $this->tramitar($fila, $espia);

        /* La primera consumió sus intentos; la segunda salió a la primera. */
        $this->assertCount(EnviarMensajeAlAsistenteJob::INTENTOS_POR_FOTO + 1, $espia->imagenes);
        $this->assertSame('https://r2.comerciocity.com/ferreteria/articulos/precinto-2.jpg', end($espia->imagenes)['url']);

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->refresh()->estado);
        $this->assertNull($fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(1, $this->salientes($client));
    }

    /**
     * Un rechazo definitivo de Meta NO se reintenta: esperar no arregla un link que no pudo bajar.
     *
     * @return void
     */
    public function test_un_rechazo_definitivo_no_se_reintenta(): void
    {
        $espia = $this->espiar_sender();
        $espia->confirma_imagenes = false;
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([$this->adjunto('1')]);

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes, 'Un solo intento: el rechazo no era transitorio.');
        $this->assertSame([EnviarMensajeAlAsistenteJob::PAUSA_ANTES_DE_CADA_FOTO_US], $this->pausas, 'Sin espera de reintento.');
    }

    /**
     * Las fotos no dejan filas salientes propias: la única fila del turno sigue siendo la del texto.
     *
     * Es lo que fija el contrato (§2: "nada más cambia"). Se anota acá para que quede medido y no
     * implícito: citar una foto desde WhatsApp no resuelve conversación, y la elige el `empresa-api`
     * por tiempo.
     *
     * @return void
     */
    public function test_las_fotos_no_dejan_filas_salientes_propias(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_respuesta_lista([
            $this->adjunto('1'),
            $this->adjunto('2'),
            $this->adjunto('3'),
        ]);

        $this->tramitar($fila, $espia);

        $this->assertCount(3, $espia->imagenes);
        $this->assertCount(1, $this->salientes($client));
    }
}
