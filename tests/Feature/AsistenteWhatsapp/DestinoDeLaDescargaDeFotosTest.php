<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteFotoSalienteService;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;

/**
 * A dónde puede —y a dónde NO puede— ir el admin a buscar una foto del asistente.
 *
 * 🔴 **Este control lo abrió el arreglo del webp, y por eso se prueba con él.** Hasta el
 * 22/9/2026 el link de la foto viajaba adentro del mensaje y la que iba a buscarlo era **Meta**,
 * desde afuera de la red de ComercioCity: a dónde apuntara era problema de Meta. Desde que existe
 * el camino por media_id, el que hace el GET es el **job del admin**, corriendo adentro del VPS
 * donde también viven su MySQL, su Redis y los ~40 clientes migrados.
 *
 * Y la URL no la elige nadie de este lado: la manda el `empresa-api` de un cliente, en la clave
 * `adjuntos` de su respuesta. O sea que sin control de destino, un `empresa-api` comprometido —o
 * con un bug— convierte al admin en su proxy hacia la red interna. `169.254.169.254` es el
 * endpoint de metadata del cloud y es el premio gordo de cualquier SSRF.
 *
 * Lo que estas pruebas fijan:
 *
 *   1. **El control es sobre la IP, no sobre el nombre.** Un dominio perfectamente normal que
 *      resuelve a `10.x.x.x` se rechaza igual.
 *   2. **Los saltos no se siguen.** Un `302` hacia una IP interna saltearía cualquier chequeo hecho
 *      solo sobre la URL original.
 *   3. **Una URL pública sigue andando**, por el camino que le toque. El control no puede volverse
 *      una lista blanca encubierta: los clientes sirven sus fotos desde R2, desde el shared y desde
 *      dominios propios.
 *   4. **Un rechazo no voltea el turno.** Misma regla que el resto: el texto ya salió.
 *
 * Ninguna prueba consulta DNS: la resolución se fija con `fotos_que_resuelven_a()`, y los casos de
 * IP literal ni siquiera pasan por ahí.
 */
class DestinoDeLaDescargaDeFotosTest extends BaseDelCanal
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
     * El job con la pausa anulada.
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
     * @param Client $client
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
        $fila->texto               = 'Mostrame la foto';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * Fakea la ida, la vuelta con un adjunto en la URL que se pida, y lo que devuelva esa URL.
     *
     * @param string                                             $url   URL del adjunto.
     * @param array<string, \GuzzleHttp\Promise\PromiseInterface> $fotos Stubs de la foto.
     *
     * @return void
     */
    private function fakear(string $url, array $fotos = []): void
    {
        $respuesta = [
            'estado'             => 'listo',
            'contenido'          => 'Acá va la foto.',
            'error_mensaje'      => null,
            'ai_conversation_id' => 91,
            'adjuntos'           => [['tipo' => 'imagen', 'url' => $url, 'texto' => 'Una foto']],
        ];

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
     * Bytes de un webp chiquito.
     *
     * @return string
     */
    private function webp(): string
    {
        $imagen = imagecreatetruecolor(120, 90);
        $color  = imagecolorallocate($imagen, 30, 120, 200);
        imagefilledrectangle($imagen, 0, 0, 119, 89, $color);

        ob_start();
        imagewebp($imagen, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /**
     * Cuántos pedidos salieron hacia un host.
     *
     * @param string $fragmento Pedazo de URL a buscar.
     *
     * @return int
     */
    private function pedidos_hacia(string $fragmento): int
    {
        $veces = 0;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), $fragmento) !== false) {
                $veces++;
            }
        }

        return $veces;
    }

    /**
     * Un link a `127.0.0.1` no se baja, y el turno sigue intacto.
     *
     * @return void
     */
    public function test_un_link_a_localhost_no_se_baja(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('http://127.0.0.1:6379/foto.webp', [
            'http://127.0.0.1*' => Http::response($this->webp(), 200),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error, 'Un destino rechazado no es un error del turno.');
        $this->assertCount(1, $espia->textos, 'El texto sale igual.');
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(0, $this->pedidos_hacia('127.0.0.1'), 'Ni siquiera se intenta el pedido.');
    }

    /**
     * Un link al endpoint de metadata del cloud no se baja.
     *
     * 🔴 `169.254.169.254` es el premio gordo de un SSRF: de ahí salen las credenciales de la
     * instancia.
     *
     * @return void
     */
    public function test_un_link_al_endpoint_de_metadata_no_se_baja(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('http://169.254.169.254/latest/meta-data/iam/security-credentials/', [
            'http://169.254.169.254*' => Http::response('credenciales', 200),
        ]);

        $this->tramitar($fila, $espia);

        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(0, $this->pedidos_hacia('169.254.169.254'));
    }

    /**
     * La tabla de direcciones, evaluada derecho contra el control.
     *
     * 🔴 **`::ffff:127.0.0.1` es el caso que más importa y el que no se puede probar por HTTP**: es
     * `127.0.0.1` escrito como IPv6, y `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` lo da
     * por **bueno** (medido contra el PHP 7.4.33 el 22/9/2026) — por eso el rango `::ffff:0:0/96`
     * está a mano en la lista. Armar la URL `http://[::ffff:127.0.0.1]/…` no sirve para probarlo:
     * Guzzle no parsea esa URI y tira `MalformedUriException` **antes** de llegar al control, así
     * que la prueba pasaría con el candado abierto. Donde el caso llega de verdad es por DNS —una
     * `AAAA` que devuelve una IPv4 mapeada—, y eso está en la prueba de abajo.
     *
     * Las públicas están para que el control no se vuelva una lista blanca encubierta.
     *
     * @return void
     */
    public function test_la_tabla_de_direcciones_separa_lo_ruteable_de_lo_que_no(): void
    {
        $servicio = new class extends AsistenteFotoSalienteService {
            public function evaluar(string $host): ?string
            {
                return $this->motivo_para_no_bajar($host);
            }
        };

        $prohibidas = [
            '127.0.0.1', '127.5.5.5', '10.0.0.5', '172.16.3.4', '172.31.255.255',
            '192.168.1.10', '169.254.169.254', '0.0.0.0', '100.64.0.7', '192.0.0.1',
            '198.18.0.1', '224.0.0.1', '240.0.0.1',
            '::1', 'fc00::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.5',
        ];

        foreach ($prohibidas as $ip) {
            $this->assertNotNull($servicio->evaluar($ip), 'Tenía que rechazarse: ' . $ip);
        }

        $publicas = ['8.8.8.8', '190.2.3.4', '172.32.0.1', '2001:4860:4860::8888'];

        foreach ($publicas as $ip) {
            $this->assertNull($servicio->evaluar($ip), 'Tenía que pasar: ' . $ip);
        }
    }

    /**
     * Un dominio cuya `AAAA` devuelve una IPv4 mapeada en IPv6 tampoco se baja.
     *
     * Es el camino por el que `::ffff:127.0.0.1` llega de verdad: acá el host de la URL es un
     * dominio normal —Guzzle no tiene nada que objetar— y la dirección aparece recién al resolver.
     *
     * @return void
     */
    public function test_un_dominio_que_resuelve_a_una_ipv4_mapeada_no_se_baja(): void
    {
        $this->fotos_que_resuelven_a('::ffff:127.0.0.1');

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('https://fotos.cliente-de-prueba.test/articulos/1.webp', [
            '*cliente-de-prueba.test*' => Http::response($this->webp(), 200),
        ]);

        $this->tramitar($fila, $espia);

        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(0, $this->pedidos_hacia('fotos.cliente-de-prueba.test'));
    }

    /**
     * Las direcciones no ruteables escritas derecho en la URL tampoco se bajan.
     *
     * @return void
     */
    public function test_ninguna_direccion_no_ruteable_se_baja(): void
    {
        $destinos = [
            'http://10.0.0.5/foto.webp',
            'http://172.16.3.4/foto.webp',
            'http://192.168.1.10/foto.webp',
            'http://100.64.0.7/foto.webp',
            'http://0.0.0.0/foto.webp',
            'http://[::1]/foto.webp',
            'http://[fd00::1]/foto.webp',
            'http://[fe80::1]/foto.webp',
        ];

        foreach ($destinos as $indice => $destino) {
            $espia  = $this->espiar_sender();
            $client = $this->crear_cliente('+549341123456' . $indice);
            $fila   = $this->fila_entrante($client);

            $this->fakear($destino, ['*foto.webp' => Http::response($this->webp(), 200)]);

            $this->tramitar($fila, $espia);

            $this->assertCount(0, $espia->imagenes_subidas, 'No tenía que bajarse: ' . $destino);
            $this->assertCount(0, $espia->imagenes, 'Ni salir por link: ' . $destino);
            $this->assertSame(0, $this->pedidos_hacia('foto.webp'), 'Ni pedirse: ' . $destino);
        }
    }

    /**
     * Un dominio normal que RESUELVE a una IP privada tampoco se baja.
     *
     * 🔴 Es la razón por la que el control mira la IP y no el string del host: una lista negra de
     * nombres no ve nada acá. `fotos.cliente-de-prueba.test` no tiene nada de sospechoso.
     *
     * @return void
     */
    public function test_un_dominio_que_resuelve_a_una_ip_privada_no_se_baja(): void
    {
        $this->fotos_que_resuelven_a('10.0.0.5');

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('https://fotos.cliente-de-prueba.test/articulos/1.webp', [
            '*cliente-de-prueba.test*' => Http::response($this->webp(), 200),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(0, $this->pedidos_hacia('fotos.cliente-de-prueba.test'));
    }

    /**
     * Un dominio público que REDIRIGE a una IP interna no llega a ninguna parte.
     *
     * 🔴 El salto es lo que saltea un chequeo hecho solo sobre la URL original: la que se controla
     * es una y la que se baja es otra. Los redirects van apagados (`allow_redirects => false`) y
     * además un 3xx se corta con su propio motivo.
     *
     * @return void
     */
    public function test_un_redirect_hacia_una_ip_interna_no_se_sigue(): void
    {
        $this->fotos_que_resuelven_a('190.2.3.4');

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('https://fotos.cliente-de-prueba.test/articulos/1.webp', [
            '*cliente-de-prueba.test*' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
            'http://169.254.169.254*'  => Http::response('credenciales', 200),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertCount(1, $espia->textos);

        $this->assertSame(1, $this->pedidos_hacia('cliente-de-prueba.test'), 'Se pide una vez, la original.');
        $this->assertSame(0, $this->pedidos_hacia('169.254.169.254'), 'Y el salto no se sigue.');
    }

    /**
     * Un servicio que resuelve a la IP que se le diga y deja llamar a `descargar()` derecho.
     *
     * ⚠️ **Por qué estas pruebas no van de punta a punta:** con `Http::fake()` el handler de Guzzle
     * está reemplazado, así que `allow_redirects` no participa —los saltos los sigue este código a
     * mano, que es justamente lo que hay que medir— y el motivo del rechazo no es observable desde
     * el espía del envío. Llamando a `descargar()` se ve el motivo exacto de cada caso.
     *
     * @param string $ip A qué resuelve cualquier dominio.
     *
     * @return AsistenteFotoSalienteService
     */
    private function servicio_que_baja(string $ip = '190.2.3.4'): AsistenteFotoSalienteService
    {
        $servicio = new class extends AsistenteFotoSalienteService {
            /** @var string */
            public $ip_fija = '190.2.3.4';

            protected function ips_del_host(string $host): ?array
            {
                return [$this->ip_fija];
            }

            public function bajar(string $url): array
            {
                return $this->descargar($url);
            }
        };

        $servicio->ip_fija = $ip;

        return $servicio;
    }

    /**
     * Un salto hacia una IP interna se rechaza al revalidar el destino, no antes.
     *
     * 🔴 Es la razón de ser del bucle de saltos: el control corre sobre **cada** URL de la cadena,
     * no sobre la primera. Acá el dominio original es impecable y el `Location` es el que apunta al
     * endpoint de metadata; el motivo tiene que nombrar esa dirección, que es la prueba de que el
     * salto se evaluó de verdad.
     *
     * @return void
     */
    public function test_un_salto_hacia_una_ip_interna_se_rechaza_al_revalidar(): void
    {
        $this->fakear_http([
            '*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $resultado = $this->servicio_que_baja()->bajar('https://fotos.cliente-de-prueba.test/1.webp');

        $this->assertNull($resultado['binario']);
        $this->assertStringContainsString('169.254.169.254', (string) $resultado['motivo']);
        $this->assertStringContainsString('no ruteable', (string) $resultado['motivo']);
    }

    /**
     * Un salto hacia un destino público SÍ se sigue, y la foto llega.
     *
     * 🔴 Este es el caso que hace que cortar todo 3xx esté mal: el `empresa-api` devuelve la
     * `hosting_url` tal cual está guardada, así que **puede venir en `http://`**, y un hosting que
     * la normaliza a `https://` con un 301 dejaría la foto muerta por un salto perfectamente
     * legítimo. Antes ese salto lo seguía Meta y nadie se enteraba.
     *
     * @return void
     */
    public function test_un_salto_hacia_un_destino_publico_se_sigue(): void
    {
        $this->fakear_http([
            'http://fotos.cliente-de-prueba.test/*'  => Http::response('', 301, [
                'Location' => 'https://fotos.cliente-de-prueba.test/articulos/1.webp',
            ]),
            'https://fotos.cliente-de-prueba.test/*' => Http::response($this->webp(), 200),
        ]);

        $resultado = $this->servicio_que_baja()->bajar('http://fotos.cliente-de-prueba.test/articulos/1.webp');

        $this->assertNotNull($resultado['binario'], 'El salto es legítimo: la foto tiene que llegar.');
        $this->assertNull($resultado['motivo']);
    }

    /**
     * Un `Location` relativo se resuelve contra la URL que lo devolvió.
     *
     * @return void
     */
    public function test_un_salto_relativo_se_resuelve_contra_la_url_que_lo_devolvio(): void
    {
        $this->fakear_http([
            '*/articulos/1.webp'      => Http::response('', 302, ['Location' => 'final.webp']),
            '*/articulos/final.webp'  => Http::response($this->webp(), 200),
        ]);

        $resultado = $this->servicio_que_baja()->bajar('https://fotos.cliente-de-prueba.test/articulos/1.webp');

        $this->assertNotNull($resultado['binario']);
        $this->assertSame(
            1,
            $this->pedidos_hacia('/articulos/final.webp'),
            'El relativo se resolvió contra la carpeta de la URL original.'
        );
    }

    /**
     * Una cadena de saltos más larga que el tope se corta.
     *
     * @return void
     */
    public function test_una_cadena_de_saltos_demasiado_larga_se_corta(): void
    {
        $this->fakear_http([
            '*' => Http::response('', 302, ['Location' => 'https://fotos.cliente-de-prueba.test/otra.webp']),
        ]);

        $resultado = $this->servicio_que_baja()->bajar('https://fotos.cliente-de-prueba.test/1.webp');

        $this->assertNull($resultado['binario']);
        $this->assertStringContainsString('redirige más de', (string) $resultado['motivo']);
        $this->assertSame(
            AsistenteFotoSalienteService::MAXIMO_DE_SALTOS + 1,
            $this->pedidos_hacia('cliente-de-prueba.test'),
            'Se piden la original y los saltos permitidos, ni uno más.'
        );
    }

    /**
     * Un 3xx sin `Location` se corta con su motivo en vez de quedar como descarga vacía.
     *
     * @return void
     */
    public function test_un_redirect_sin_location_se_corta(): void
    {
        $this->fakear_http(['*' => Http::response('', 302)]);

        $resultado = $this->servicio_que_baja()->bajar('https://fotos.cliente-de-prueba.test/1.webp');

        $this->assertNull($resultado['binario']);
        $this->assertStringContainsString('no dice a dónde', (string) $resultado['motivo']);
    }

    /**
     * Una URL pública normal sigue bajándose y saliendo convertida.

    /**
     * Una URL pública normal sigue bajándose y saliendo convertida.
     *
     * 🔴 El control no puede volverse una lista blanca encubierta: los clientes sirven sus fotos
     * desde R2, desde el shared y desde dominios propios, y ninguno está enumerado en ningún lado.
     *
     * @return void
     */
    public function test_una_url_publica_sigue_andando(): void
    {
        $this->fotos_que_resuelven_a('190.2.3.4');

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('https://r2.comerciocity.com/ferreteria/articulos/1.webp', [
            '*r2.comerciocity.com*' => Http::response($this->webp(), 200, ['Content-Type' => 'image/webp']),
        ]);

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes_subidas);
        $this->assertSame('image/jpeg', $espia->imagenes_subidas[0]['mime']);
        $this->assertSame(1, $this->pedidos_hacia('r2.comerciocity.com'));
    }

    /**
     * 🔴 Si la resolución del dominio FALLA, se falla cerrado: no se baja.
     *
     * Es distinto de "el dominio no tiene direcciones", y la diferencia es el agujero: Guzzle
     * resuelve por su cuenta con `getaddrinfo` cuando conecta, así que seguir adelante con una
     * lista incompleta —porque la consulta `AAAA` se cayó, por ejemplo— significa evaluar las `A` y
     * salir por una `AAAA` que nadie miró. No hace falta un atacante con control del DNS: alcanza
     * con una resolución parcial.
     *
     * ⚠️ Lo que esta prueba cubre es el **trato** del fallo, que es donde se decide. Que
     * `ips_del_host()` devuelva null cuando `gethostbynamel()` o `dns_get_record()` contestan
     * `false` no se ejercita acá: pedirlo sería salir a consultar DNS de verdad, y ninguna prueba
     * de esta suite sale a la red.
     *
     * @return void
     */
    public function test_si_la_resolucion_falla_no_se_baja(): void
    {
        $servicio = new class extends AsistenteFotoSalienteService {
            protected function ips_del_host(string $host): ?array
            {
                return null;
            }

            public function evaluar(string $host): ?string
            {
                return $this->motivo_para_no_bajar($host);
            }
        };

        $motivo = $servicio->evaluar('fotos.cliente-de-prueba.test');

        $this->assertNotNull($motivo, 'Una resolución fallida no puede tratarse como un dominio sin direcciones.');
        $this->assertStringContainsString('del todo', (string) $motivo);
    }

    /**
     * Un dominio que no resuelve a nada tampoco se baja, y tampoco voltea el turno.
     *
     * @return void
     */
    public function test_un_dominio_que_no_resuelve_no_se_baja(): void
    {
        /* La lista vacía es lo que devuelve el servicio real cuando el DNS no contesta. */
        $this->app->instance(AsistenteFotoSalienteService::class, new class extends AsistenteFotoSalienteService {
            protected function ips_del_host(string $host): ?array
            {
                return [];
            }
        });

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('https://dominio-que-no-existe.test/1.webp', [
            '*dominio-que-no-existe.test*' => Http::response($this->webp(), 200),
        ]);

        $this->tramitar($fila, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(0, $this->pedidos_hacia('dominio-que-no-existe.test'));
    }

    /**
     * Un link que ya salía por link (jpg) no pasa por el control, porque el admin no lo visita.
     *
     * Es a propósito y conviene tenerlo escrito: por link el que va a buscar el archivo sigue
     * siendo Meta, desde afuera, que es exactamente la situación anterior al arreglo. El control
     * existe por el GET que hace el admin, y donde no hay GET no hay nada que controlar.
     *
     * @return void
     */
    public function test_el_camino_por_link_no_pasa_por_el_control_de_destino(): void
    {
        $this->fotos_que_resuelven_a('10.0.0.5');

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear('https://fotos.cliente-de-prueba.test/articulos/1.jpg');

        $this->tramitar($fila, $espia);

        $this->assertCount(1, $espia->imagenes, 'Sigue saliendo por link, como antes del arreglo.');
        $this->assertCount(0, $espia->imagenes_subidas);
        $this->assertSame(0, $this->pedidos_hacia('fotos.cliente-de-prueba.test'), 'El admin no la visita.');
    }
}
