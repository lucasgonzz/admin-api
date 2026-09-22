<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientEmployee;
use App\Models\WhatsappConfig;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lo compartido por las pruebas del canal del asistente por WhatsApp.
 *
 * Dos decisiones que valen para todas:
 *
 * 1. **`Http::fake()` en el `setUp()`, siempre.** Este canal le pega al `empresa-api` de un cliente
 *    real y le manda WhatsApps a un número real. Una prueba que se olvide del fake no falla: sale a
 *    la red de verdad. El fake sin argumentos devuelve 200 vacío a todo, y cada prueba lo pisa con
 *    lo que necesita.
 * 2. **El envío se espía a nivel `WhatsappSendService`.** No se usa el `test_mode` de
 *    `WhatsappConfig` porque ese camino devuelve un id simulado pero no deja ver QUÉ texto salió, y
 *    el texto es justamente lo que estas pruebas tienen que mirar: el del dueño cuyo sistema no
 *    tiene el endpoint no es el mismo que el de un error.
 */
abstract class BaseDelCanal extends TestCase
{
    use DatabaseTransactions;

    /**
     * Secreto con el que se firma el body del webhook, igual que lo firma Kapso.
     */
    const SECRETO = 'secreto-del-canal-del-asistente';

    /**
     * Nada sale a la red en estas pruebas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakear_http();
    }

    /**
     * Fakea las llamadas al `empresa-api` del cliente, descartando lo que hubiera de antes.
     *
     * 🔴 El `Http::swap()` de adelante NO es adorno, y sacarlo deja pruebas que pasan por el motivo
     * equivocado. `Http::fake()` ACUMULA: los stubs de una llamada se suman a los de la anterior, y
     * gana el PRIMERO que matchea. O sea que el comodín que el `setUp()` registra para que ninguna
     * prueba salga a la red de verdad se le adelantaría a todos los stubs específicos, y cada
     * prueba mediría la respuesta 200 vacía del comodín en vez de la que quiso poner. Cambiar la
     * fábrica entera es la forma de empezar limpio.
     *
     * El comodín va SIEMPRE y va último: una prueba que se olvide de fakear una ruta recibe 200
     * vacío y no un pedido real al aire.
     *
     * @param array<string, mixed> $stubs Stubs por patrón de URL, en orden de prioridad.
     *
     * @return void
     */
    protected function fakear_http(array $stubs = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());

        /* Solo si la prueba no puso el suyo: una prueba que fakea `'*'` a propósito —para medir un
         * 404 en cualquier ruta, por ejemplo— tiene que ganarle al comodín, no que se lo pisen. */
        if (! array_key_exists('*', $stubs)) {
            $stubs['*'] = Http::response([], 200);
        }

        Http::fake($stubs);
    }

    /**
     * Configuración de WhatsApp activa, para que el webhook acepte el evento.
     *
     * @return WhatsappConfig
     */
    protected function crear_config_whatsapp(): WhatsappConfig
    {
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-kapso-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        /* En false a propósito: con `test_mode` prendido, `send_text()` corta antes de llegar al
         * espía y devuelve un id simulado. Acá el que corta es el espía. */
        $config->test_mode       = false;
        $config->save();

        return $config;
    }

    /**
     * Cliente activo con el canal del asistente prendido.
     *
     * @param string $phone                     Teléfono del dueño (el de la ficha).
     * @param bool   $asistente_whatsapp_activo Estado del interruptor.
     * @param string $api_key                   Clave contra su `empresa-api`. Vacía = sin clave.
     *
     * @return Client
     */
    protected function crear_cliente(
        string $phone = '+5493411234567',
        bool $asistente_whatsapp_activo = true,
        string $api_key = 'clave-del-cliente'
    ): Client {
        $client                            = new Client();
        $client->name                      = 'Ferretería de prueba';
        $client->company_name              = 'Ferretería de prueba S.R.L.';
        $client->phone                     = $phone;
        $client->is_active                 = true;
        $client->api_url                   = 'https://api-ferreteria-de-prueba.test';
        $client->api_key                   = $api_key;
        $client->asistente_whatsapp_activo = $asistente_whatsapp_activo;
        $client->save();

        return $client;
    }

    /**
     * Corre un ingreso del job sobre la misma instancia, para simular el polling.
     *
     * Se llama a `handle()` derecho y no se pasa por la cola: `release()` no reencola nada cuando no
     * hay job de cola detrás, así que cada llamada es exactamente una consulta y la prueba controla
     * cuántas hubo, sin que el reloj participe.
     *
     * Vive en la base y no en cada archivo de prueba porque la firma de `handle()` crece cuando el
     * job necesita una dependencia nueva, y con la llamada repetida en dos lugares eso son dos
     * archivos que se rompen por algo que no tiene nada que ver con lo que están probando.
     *
     * @param \App\Jobs\EnviarMensajeAlAsistenteJob $job
     * @param WhatsappSendService                   $sender Espía del envío a Meta.
     *
     * @return void
     */
    protected function correr_job($job, WhatsappSendService $sender): void
    {
        $job->handle(
            app(\App\Services\AsistenteWhatsappService::class),
            app(\App\Services\ClientEmpresaApiUrlResolver::class),
            $sender,
            app(\App\Services\AsistenteImagenesService::class),
            app(\App\Services\AsistenteFotoSalienteService::class)
        );
    }

    /**
     * `ClientApi` activa del cliente, con su tipo de hosting.
     *
     * Existe porque la regla de `/public` depende de `client_apis.hosting_type` y NO de
     * `clients.api_url`: ese último es un valor histórico que el resolver trata como VPS a
     * propósito. Un cliente de prueba sin `ClientApi` mide el camino legacy, no el real.
     *
     * @param Client $client       Cliente dueño.
     * @param string $url          URL de su `empresa-api`, sin `/public`.
     * @param string $hosting_type shared_hosting | vps
     *
     * @return ClientApi
     */
    protected function crear_client_api(
        Client $client,
        string $url = 'https://api-ferreteria-de-prueba.test',
        string $hosting_type = 'shared_hosting'
    ): ClientApi {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = $url;
        $api->path         = 'ferreteria/api';
        $api->hosting_type = $hosting_type;
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        return $api;
    }

    /**
     * Empleado del cliente, con teléfono propio.
     *
     * @param Client $client Cliente dueño.
     * @param string $phone  Teléfono del empleado.
     *
     * @return ClientEmployee
     */
    protected function crear_empleado(Client $client, string $phone): ClientEmployee
    {
        $employee            = new ClientEmployee();
        $employee->client_id = $client->id;
        $employee->name      = 'Brisa';
        $employee->phone     = $phone;
        $employee->save();

        return $employee;
    }

    /**
     * Espía de `WhatsappSendService` que registra los envíos en vez de mandarlos.
     *
     * @param bool $confirma True: Meta confirma con un wamid. False: lo rechaza.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    protected function espiar_sender(bool $confirma = true): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            /** @var array<int, array<string, mixed>> Envíos de imagen por link (las fotos del asistente). */
            public $imagenes = [];

            /**
             * @var array<int, array<string, mixed>> Envíos de imagen por media_id: los que pasaron por
             *                                       la descarga y la conversión. Guarda los BYTES que
             *                                       habrían viajado, que es lo único que puede decir
             *                                       que el webp salió convertido a JPEG.
             */
            public $imagenes_subidas = [];

            /**
             * @var array<int, string> Qué salió y en qué orden (`texto` | `imagen`). Es lo único que
             *                        puede decir que la respuesta salió ANTES que sus fotos.
             */
            public $orden = [];

            /** @var bool Si Meta confirma el envío (texto y plantilla). */
            public $confirma = true;

            /**
             * @var bool Si Meta confirma las fotos. Separado de `$confirma` a propósito: la prueba de
             *           "la foto falla" necesita el texto bien y la foto mal, que es el caso real.
             */
            public $confirma_imagenes = true;

            /** @var bool Si mandar una foto revienta con una excepción en vez de devolver null. */
            public $explota_imagenes = false;

            /**
             * @var int Cuántos envíos de foto seguidos fallan como TRANSITORIOS (el 409 "otro mensaje
             *          en vuelo" de Kapso) antes de empezar a confirmar. Es lo que dispara el
             *          segundo intento del job; un rechazo definitivo (`$confirma_imagenes` en
             *          false) no lo dispara.
             */
            public $falla_transitoria_veces = 0;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body, 'context' => $context];
                $this->orden[]  = 'texto';

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en la prueba).';

                    return null;
                }

                return 'wamid.saliente.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                    'language_code' => $language_code,
                ];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó la plantilla (simulado en la prueba).';

                    return null;
                }

                return 'wamid.plantilla.' . count($this->plantillas);
            }

            public function send_image_by_link(string $to, string $url, ?string $caption = null, ?string $context = null, bool $skip_failure_notification = true): ?string
            {
                $this->imagenes[] = ['to' => $to, 'url' => $url, 'caption' => $caption, 'context' => $context];
                $this->orden[]    = 'imagen';

                /* Como el método real: cada llamada arranca sin el motivo del fallo anterior. */
                $this->last_send_error       = null;
                $this->last_send_status_code = null;

                if ($this->explota_imagenes) {
                    throw new \RuntimeException('Kapso reventó mandando la foto (simulado en la prueba).');
                }

                if ($this->falla_transitoria_veces > 0) {
                    $this->falla_transitoria_veces--;
                    $this->last_send_error       = 'Kapso: otro mensaje en vuelo para esta conversación (409, simulado en la prueba).';
                    $this->last_send_status_code = 409;

                    return null;
                }

                if (! $this->confirma_imagenes) {
                    $this->last_send_error = 'Meta rechazó la foto (simulado en la prueba).';

                    return null;
                }

                return 'wamid.imagen.' . count($this->imagenes);
            }

            public function send_image_by_bytes(
                string $to,
                string $contents,
                string $mime,
                string $filename,
                ?string $caption = null,
                ?string $context = null,
                bool $skip_failure_notification = true
            ): ?string {
                $this->imagenes_subidas[] = [
                    'to'      => $to,
                    'bytes'   => $contents,
                    'mime'    => $mime,
                    'nombre'  => $filename,
                    'caption' => $caption,
                    'context' => $context,
                ];
                $this->orden[] = 'imagen';

                $this->last_send_error       = null;
                $this->last_send_status_code = null;

                if ($this->explota_imagenes) {
                    throw new \RuntimeException('Kapso reventó subiendo la foto (simulado en la prueba).');
                }

                if ($this->falla_transitoria_veces > 0) {
                    $this->falla_transitoria_veces--;
                    $this->last_send_error       = 'Kapso: otro mensaje en vuelo para esta conversación (409, simulado en la prueba).';
                    $this->last_send_status_code = 409;

                    return null;
                }

                if (! $this->confirma_imagenes) {
                    $this->last_send_error = 'Meta rechazó la foto subida (simulado en la prueba).';

                    return null;
                }

                return 'wamid.imagen-subida.' . count($this->imagenes_subidas);
            }
        };

        $espia->confirma = $confirma;

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Pega al webhook real con el body crudo firmado igual que lo firma Kapso.
     *
     * Se usa `call()` y no `postJson()` a propósito: la firma es HMAC sobre el body EXACTO, así que
     * la prueba tiene que controlar el string que viaja y no dejar que lo arme el framework.
     *
     * @param array<string, mixed> $payload Cuerpo del webhook.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_webhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body);
    }

    /**
     * Payload de un mensaje de texto entrante, con cita opcional.
     *
     * @param string      $telefono Remitente.
     * @param string      $texto    Cuerpo del mensaje.
     * @param string      $wamid    ID del mensaje (idempotencia del webhook).
     * @param string|null $cita     wamid citado, si el mensaje cita alguno.
     *
     * @return array<string, mixed>
     */
    protected function payload_de_texto(
        string $telefono,
        string $texto,
        string $wamid = 'wamid.ENTRANTE1',
        ?string $cita = null
    ): array {
        $message = [
            'id'        => $wamid,
            'from'      => $telefono,
            'type'      => 'text',
            'text'      => ['body' => $texto],
            'timestamp' => (string) time(),
        ];

        if ($cita !== null) {
            $message['context'] = ['id' => $cita];
        }

        return [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Dueño de prueba'],
            'message'      => $message,
        ];
    }
}
