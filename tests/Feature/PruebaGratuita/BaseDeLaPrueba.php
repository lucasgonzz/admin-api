<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\Admin;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaPlantillasService;
use Illuminate\Support\Str;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Lo compartido por las pruebas del programa «Prueba gratuita de 7 días» del lado del admin (misión
 * cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Hereda de `BaseDelCanal` lo que importa: nada sale a la red (`Http::fake()` en el `setUp()`), el envío a
 * Meta se espía a nivel `WhatsappSendService`, y los constructores de cliente, frente y configuración de
 * WhatsApp.
 *
 * 🔴 Los clientes en prueba se fijan con `PruebaDelClienteService::forzar_en_prueba()`: las columnas
 * `clients.estado` / `trial_days` / `trial_ends_at` las crea la misión M2, que se construyó en paralelo,
 * así que estas pruebas no pueden depender del esquema. Se restablece en `tearDown()`.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDeLaPrueba extends BaseDelCanal
{
    /** Clave de ingesta de las requests `claude/*` de los tests. */
    const CLAVE_DE_INGESTA = 'clave-de-prueba-claude-prueba-gratuita';

    /** Token con el que la instancia reporta por C2. */
    const TOKEN_DE_EVENTOS = 'token-de-eventos-de-la-prueba-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE_DE_INGESTA]);
        config(['services.admin_spa.url' => 'https://admin.ejemplo.test']);

        $this->forzados = [];
        PruebaDelClienteService::restablecer();
    }

    protected function tearDown(): void
    {
        PruebaDelClienteService::restablecer();

        parent::tearDown();
    }

    /**
     * Headers de las rutas `claude/*`.
     *
     * @return array<string, string>
     */
    protected function headers_de_claude(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE_DE_INGESTA, 'Accept' => 'application/json'];
    }

    /**
     * Un cliente en prueba, con su frente y fijado como «en prueba» con los días dados.
     *
     * @param int         $dias_restantes Cuántos días le quedan (negativo = vencida).
     * @param int         $dias           Largo de la prueba.
     * @param string      $phone
     *
     * @return Client
     */
    protected function cliente_en_prueba(int $dias_restantes = 5, int $dias = 7, string $phone = '+5493411234567'): Client
    {
        $client = $this->crear_cliente($phone, false, 'clave-del-cliente');
        /* El dueño en su empresa-api: `clients.user_id` es lo que viaja como `user_id` en C4. */
        $client->user_id = random_int(500000, 900000) * 100;
        $client->save();
        $this->crear_client_api($client);

        $this->fijar_en_prueba($client, $dias_restantes, $dias);

        return $client;
    }

    /**
     * Fija (o refija) un cliente como en prueba, sumándolo a los ya forzados.
     *
     * @param Client $client
     * @param int    $dias_restantes
     * @param int    $dias
     *
     * @return void
     */
    protected function fijar_en_prueba(Client $client, int $dias_restantes = 5, int $dias = 7): void
    {
        $this->forzados[(int) $client->id] = [
            'dias'     => $dias,
            'vence_at' => now()->addDays($dias_restantes)->addMinutes(30)->toIso8601String(),
        ];

        PruebaDelClienteService::forzar_en_prueba($this->forzados);
    }

    /** @var array<int, array<string, mixed>> Los clientes fijados en prueba por ESTE test. */
    protected $forzados = [];

    /**
     * El lead de la prueba, promovido al cliente, con la dinámica nueva y su token de eventos (C2).
     *
     * @param Client $client
     * @param string $contact_name
     *
     * @return Lead
     */
    protected function lead_en_prueba(Client $client, string $contact_name = 'Marcela Gómez'): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = $contact_name;
        $lead->company_name       = 'Ferretería de prueba';
        $lead->phone              = (string) $client->phone;
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_eventos_token = Str::random(64);
        $lead->promoted_client_id = $client->id;
        $lead->save();

        return $lead;
    }

    /**
     * El admin que figura como remitente (el primero de la tabla) y, si se pide, el closer.
     *
     * @param bool $closer true = marcado como closer con teléfono.
     *
     * @return Admin
     */
    protected function admin(bool $closer = false): Admin
    {
        $admin           = new Admin();
        $admin->name     = $closer ? 'Tomás' : 'Lucas';
        $admin->email    = ($closer ? 'tomas-' : 'lucas-') . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        if ($closer) {
            $admin->is_closer    = true;
            $admin->phone_number = '+5493415550000';
        }
        $admin->save();

        return $admin;
    }

    /**
     * Las plantillas de la prueba registradas, aprobadas (o no).
     *
     * @param bool          $aprobadas
     * @param array<string>|null $solo Nombres a registrar; null = todas.
     *
     * @return void
     */
    protected function plantillas_de_la_prueba(bool $aprobadas = true, ?array $solo = null): void
    {
        foreach (PruebaPlantillasService::PLANTILLAS as $nombre => $definicion) {
            if ($solo !== null && ! in_array($nombre, $solo, true)) {
                continue;
            }

            FollowupTemplate::create([
                'template_name'              => $nombre,
                'estado'                     => PruebaPlantillasService::ESTADO,
                'dia_numero'                 => 1,
                'body_template'              => $definicion['body'],
                'language_code'              => 'es_AR',
                'activa'                     => $aprobadas,
                'meta_status'                => $aprobadas ? 'APPROVED' : 'PENDING',
                'meta_category'              => 'UTILITY',
                'uso'                        => $definicion['uso'],
                'solo_si_ingreso_confirmado' => false,
                'no_marca_error_de_entrega'  => false,
            ]);
        }
    }

    /**
     * Un ticket de WhatsApp abierto del dueño del cliente, con (o sin) un entrante reciente.
     *
     * @param Client $client
     * @param bool   $con_entrante true = el dueño escribió hace un rato (la ventana está abierta).
     *
     * @return SupportTicket
     */
    protected function ticket_del_cliente(Client $client, bool $con_entrante = true): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'          => $client->id,
            'client_employee_id' => null,
            'client_user_id'     => 0,
            'client_user_name'   => 'Marcela',
            'assigned_admin_id'  => null,
            'name'               => null,
            'status'             => 'open',
            'source'             => 'whatsapp',
            'whatsapp_phone'     => (string) $client->phone,
            'opened_at'          => now(),
        ]);

        if ($con_entrante) {
            $mensaje = SupportMessage::create([
                'support_ticket_id'   => $ticket->id,
                'sender_type'         => 'user',
                'kind'                => 'text',
                'body'                => 'Hola, te mando la lista',
                'delivered_at'        => now(),
                'whatsapp_message_id' => 'wamid.entrante.' . Str::random(6),
            ]);
            $ticket->last_client_message_at = $mensaje->delivered_at;
            $ticket->save();
        }

        return $ticket;
    }

    /**
     * Postea un lote de eventos de C2 con el token del lead.
     *
     * @param Lead                 $lead
     * @param string               $nombre
     * @param array<string, mixed> $datos
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function evento(Lead $lead, string $nombre, array $datos = [])
    {
        return $this->postJson('/api/demo-eventos', ['eventos' => [[
            'uuid'        => (string) Str::uuid(),
            'nombre'      => $nombre,
            'clip_id'     => null,
            'ocurrido_at' => now()->toIso8601String(),
            'datos'       => $datos,
        ]]], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token]);
    }

    /**
     * La respuesta de C3 de la instancia, con los hitos dados hechos.
     *
     * @param array<string> $hechos Tipos ya pasados.
     *
     * @return array<string, mixed>
     */
    protected function estado_de_la_instancia(array $hechos = ['ingreso']): array
    {
        $hitos = [];
        foreach (array_keys(PruebaDelClienteService::HITOS) as $tipo) {
            $hitos[$tipo] = in_array($tipo, $hechos, true) ? '2026-11-03T10:15:00-03:00' : null;
        }

        return [
            'vence_at'         => now()->addDays(5)->toIso8601String(),
            'vencida'          => false,
            'semilla_presente' => true,
            'hitos'            => $hitos,
        ];
    }
}
