<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\Lead;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Andamiaje común de los tests del bloque `claude/implementations/*` (misión `implementar-cliente`,
 * 5/10/2026): la clave de ingesta, los headers, y los constructores de lo que casi todos los tests
 * necesitan —un cliente con sus dos APIs, su implementación con las ocho etapas, un lead, una versión,
 * mensajes del hilo—.
 *
 * 🔴 Todo lo que construye lo hace con las MISMAS formas que producen los servicios reales
 * (`PromoteLeadToClientService` para las dos APIs, `ImplementationStartService` para las etapas): un
 * test que arma datos con otra forma prueba contra un mundo que no existe.
 *
 * El memo de `admin_settings` es estático y sobrevive al rollback de la transacción: se vacía antes y
 * después de cada test (el `TestCase` base ya lo vacía al arrancar; acá además al terminar).
 */
abstract class BaseDeImplementaciones extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests de los tests. */
    const CLAVE = 'clave-de-prueba-claude-implementaciones';

    /**
     * `versions.version` es UNIQUE: cada versión de cada test necesita su propio código.
     *
     * @var int
     */
    private $contador_de_versiones = 0;

    /**
     * Setea la clave de ingesta (en el .env del slot está vacía y el middleware es fail-closed) y
     * deja los settings de implementación sin valores heredados.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        AdminSetting::whereIn('key', [
            'implementation_form_url',
            'implementation_assigned_admin_id',
            'implementation_automation_mode',
        ])->delete();
        AdminSetting::flush_memo();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminSetting::flush_memo();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Requests
     |----------------------------------------------------------------------------------------- */

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Cuerpo de la respuesta como texto, con los acentos y las barras SIN escapar: `getContent()`
     * escapa los no-ASCII y las barras, y arruina cualquier comparación de texto.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a leer.
     *
     * @return string
     */
    protected function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ------------------------------------------------------------------------------------------
     | Constructores
     |----------------------------------------------------------------------------------------- */

    /**
     * Cliente de un negocio, SIN APIs ni implementación.
     *
     * @param string               $nombre    `clients.name` (el que se confirma en `confirm_client_name`).
     * @param array<string, mixed> $atributos Atributos a sumar o pisar.
     *
     * @return Client
     */
    protected function crear_cliente(string $nombre = 'Panchito Gómez', array $atributos = []): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->company_name    = 'Negocio de ' . $nombre;
        $client->slug            = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url         = '';
        $client->api_key         = 'clave-api-' . Str::random(20);
        $client->inbound_api_key = 'clave-inbound-' . Str::random(20);
        $client->phone           = '+549341' . random_int(1000000, 9999999);
        $client->is_active       = true;
        $client->user_id         = random_int(500000, 900000) * 100;

        foreach ($atributos as $campo => $valor) {
            $client->{$campo} = $valor;
        }

        $client->save();

        return $client->refresh();
    }

    /**
     * Las dos ClientApi estándar de un cliente nuevo (`<sub>` y `<sub>2`), la 1 como activa. Es la
     * misma forma que crea `PromoteLeadToClientService::create_initial_client_apis()`.
     *
     * @param Client $client El cliente.
     * @param string $sub    El subdominio (`panchito`).
     * @param string $hosting `hosting_type` de las dos.
     *
     * @return Client El cliente refrescado, con `active_client_api_id`.
     */
    protected function crear_las_dos_apis(Client $client, string $sub, string $hosting = 'shared_hosting'): Client
    {
        $uno                = new ClientApi();
        $uno->client_id     = $client->id;
        $uno->url           = 'https://api-' . $sub . '.comerciocity.com';
        $uno->path          = $sub . '/api';
        $uno->spa_url       = 'https://' . $sub . '.comerciocity.com';
        $uno->hosting_type  = $hosting;
        $uno->save();

        $dos                = new ClientApi();
        $dos->client_id     = $client->id;
        $dos->url           = 'https://api-' . $sub . '2.comerciocity.com';
        $dos->path          = $sub . '2/api';
        $dos->spa_url       = 'https://' . $sub . '2.comerciocity.com';
        $dos->hosting_type  = $hosting;
        $dos->save();

        $client->active_client_api_id = $uno->id;
        $client->save();

        return $client->refresh();
    }

    /**
     * La implementación de un cliente, con las ocho etapas y el token, tal como la deja
     * `ImplementationStartService::start()`: en curso, etapa 1.
     *
     * @param Client               $client    El cliente.
     * @param array<string, mixed> $atributos Atributos a sumar o pisar de la implementación.
     *
     * @return Implementation
     */
    protected function crear_implementacion(Client $client, array $atributos = []): Implementation
    {
        $implementation                  = new Implementation();
        $implementation->client_id       = $client->id;
        $implementation->status          = 'in_progress';
        $implementation->current_stage   = 1;
        $implementation->started_at      = now();
        $implementation->automation_mode = 'manual';
        $implementation->form_token      = (string) Str::uuid();

        foreach ($atributos as $campo => $valor) {
            $implementation->{$campo} = $valor;
        }

        $implementation->save();

        for ($numero = 1; $numero <= 8; $numero++) {
            $etapa                    = new ImplementationStage();
            $etapa->implementation_id = $implementation->id;
            $etapa->stage_number      = $numero;
            $etapa->status            = $numero === (int) $implementation->current_stage ? 'in_progress' : 'pending';
            $etapa->started_at        = $numero === (int) $implementation->current_stage ? now() : null;
            $etapa->save();
        }

        return $implementation->refresh();
    }

    /**
     * Pone la implementación en la etapa N: las anteriores completadas, la N en curso, las demás
     * pendientes.
     *
     * @param Implementation $implementation La implementación.
     * @param int            $etapa          La etapa a la que se lleva (1 a 8).
     *
     * @return Implementation La implementación refrescada.
     */
    protected function llevar_a_la_etapa(Implementation $implementation, int $etapa): Implementation
    {
        $implementation->current_stage = $etapa;
        $implementation->save();

        for ($numero = 1; $numero <= 8; $numero++) {
            $registro = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', $numero)
                ->first();

            $registro->status       = $numero < $etapa ? 'completed' : ($numero === $etapa ? 'in_progress' : 'pending');
            $registro->started_at   = $numero <= $etapa ? now() : null;
            $registro->completed_at = $numero < $etapa ? now() : null;
            $registro->save();
        }

        return $implementation->refresh();
    }

    /**
     * Escribe el `data` de una etapa (pisa el que hubiera).
     *
     * @param Implementation       $implementation La implementación.
     * @param int                  $etapa          La etapa (1 a 8).
     * @param array<string, mixed> $data           El JSON de la etapa.
     *
     * @return void
     */
    protected function escribir_data_de_la_etapa(Implementation $implementation, int $etapa, array $data): void
    {
        $registro       = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', $etapa)->first();
        $registro->data = $data;
        $registro->save();
    }

    /**
     * Lee el `data` de una etapa.
     *
     * @param Implementation $implementation La implementación.
     * @param int            $etapa          La etapa (1 a 8).
     *
     * @return array<string, mixed>
     */
    protected function data_de_la_etapa(Implementation $implementation, int $etapa): array
    {
        $registro = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', $etapa)->first();

        return is_array($registro->data) ? $registro->data : [];
    }

    /**
     * Un lead, sin promover.
     *
     * @param array<string, mixed> $atributos Atributos a sumar o pisar.
     *
     * @return Lead
     */
    protected function crear_lead(array $atributos = []): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Rosa Fernández';
        $lead->company_name = 'Almacén Rosa';
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->email        = 'rosa+' . Str::random(6) . '@ejemplo.test';
        $lead->status       = 'closer_activo';

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $lead->save();

        return $lead->refresh();
    }

    /**
     * Una versión publicada (`versions.version` es UNIQUE: cada llamada usa un código nuevo).
     *
     * @param string $status published | draft | archived.
     *
     * @return Version
     */
    protected function crear_version(string $status = 'published'): Version
    {
        $this->contador_de_versiones++;

        $version               = new Version();
        $version->version      = '9.' . random_int(100, 899) . '.' . $this->contador_de_versiones;
        $version->status       = $status;
        $version->published_at = $status === 'published' ? now() : null;
        $version->save();

        return $version;
    }

    /**
     * Una instalación del cliente.
     *
     * @param Client               $client    El cliente.
     * @param array<string, mixed> $atributos Atributos a sumar o pisar.
     *
     * @return ClientInstallation
     */
    protected function crear_instalacion(Client $client, array $atributos = []): ClientInstallation
    {
        $instalacion                = new ClientInstallation();
        $instalacion->client_id     = $client->id;
        $instalacion->client_api_id = $client->active_client_api_id;
        $instalacion->status        = 'pendiente';

        foreach ($atributos as $campo => $valor) {
            $instalacion->{$campo} = $valor;
        }

        $instalacion->save();

        return $instalacion->refresh();
    }

    /**
     * Un mensaje del hilo de la implementación.
     *
     * @param Implementation $implementation La implementación.
     * @param string         $direccion      inbound | outbound.
     * @param \DateTimeInterface|string $cuando Cuándo (sent_at).
     * @param string|null    $id_externo     `whatsapp_message_id` (null = envío fallido).
     * @param string         $cuerpo         Texto.
     *
     * @return ImplementationMessage
     */
    protected function crear_mensaje(Implementation $implementation, string $direccion, $cuando, $id_externo = null, string $cuerpo = 'hola'): ImplementationMessage
    {
        $mensaje                      = new ImplementationMessage();
        $mensaje->implementation_id   = $implementation->id;
        $mensaje->stage_number        = (int) $implementation->current_stage;
        $mensaje->direction           = $direccion;
        $mensaje->phone               = '+5493415550000';
        $mensaje->body                = $cuerpo;
        $mensaje->whatsapp_message_id = $id_externo;
        $mensaje->sent_at             = $cuando;
        $mensaje->save();

        return $mensaje;
    }

    /**
     * Un admin (creador de las tareas de la promoción, y el que "está logueado" en el panel).
     *
     * @return Admin
     */
    protected function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas ' . Str::random(4);
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }
}
