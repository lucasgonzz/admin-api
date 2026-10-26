<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `POST claude/implementations/{id}/whatsapp`: el WhatsApp de un hito de la implementación sale por el TICKET
 * DE SOPORTE del contacto (misión `hitos-de-implementacion-por-soporte`, 26/10/2026).
 *
 * Lo que se verifica es lo que ningún camino feliz mira: que el dry-run no mande ni escriba nada, que la
 * ventana decida entre texto libre y plantilla, que un texto largo con la ventana cerrada se frene antes de
 * que la plantilla lo corte, que Martín atendiendo frene el envío SIN pasar su caso a revisión manual, que la
 * misma `operation_id` no mande dos veces (y con otro contenido sea 409), y que la acción quede en la etapa con
 * el formato que tilda el checklist del panel.
 *
 * El envío se sustituye a nivel `WhatsappSendService` (el mismo espía que `AperturaDeTicketPorWhatsappTest`):
 * no se toca Kapso ni Meta, y todo lo demás —ruta, frenos, opener, ticket, registro— es el camino real.
 */
class WhatsappDeHitoPorSoporteTest extends BaseDeImplementaciones
{
    /** Ruta del endpoint, con el id a completar. */
    const RUTA = '/api/claude/implementations/%d/whatsapp';

    /**
     * El espía del envío a Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $espia;

    /**
     * El admin que figura como remitente (`resolver_creador()` sin sesión).
     *
     * @var Admin
     */
    private $admin;

    /**
     * Sin red hacia los clientes, el espía del envío y un admin remitente fijado por config (la base del slot
     * no tiene admins sembrados).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->admin = $this->crear_admin();
        config(['services.claude_task_ingest.default_creator_admin_id' => $this->admin->id]);

        $this->espia = $this->espiar_el_envio(true);
    }

    /**
     * Sustituye `WhatsappSendService` por un espía que registra los envíos.
     *
     * @param bool $confirma true: devuelve un `whatsapp_message_id`. false: simula un rechazo de Meta.
     *
     * @return WhatsappSendService
     */
    private function espiar_el_envio(bool $confirma): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            /** @var bool Si el envío se confirma. */
            public $confirma = true;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.texto.' . count($this->textos) . '.' . Str::random(6);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                if (! $this->confirma) {
                    $this->last_send_error = 'La plantilla no salió (simulado en el test).';

                    return null;
                }

                return 'wamid.plantilla.' . count($this->plantillas) . '.' . Str::random(6);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Un cliente con su implementación en la etapa 3.
     *
     * @return array{0: Client, 1: Implementation}
     */
    private function escenario(): array
    {
        $client         = $this->crear_cliente('Ferretería Los Peña');
        $implementation = $this->llevar_a_la_etapa($this->crear_implementacion($client), 3);

        return [$client, $implementation];
    }

    /**
     * Abre la ventana de 24 h de ese teléfono con un entrante de soporte reciente (en un ticket cerrado, para que
     * no sea el ticket que el opener reusaría).
     *
     * @param Client $client   El cliente.
     * @param string $telefono El teléfono E.164.
     *
     * @return void
     */
    private function abrir_la_ventana(Client $client, string $telefono): void
    {
        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'closed',
            'source'         => 'whatsapp',
            'whatsapp_phone' => $telefono,
            'opened_at'      => now()->subDay(),
            'closed_at'      => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Hola, ya completé el formulario',
            'delivered_at'      => now()->subHour(),
        ]);
    }

    /**
     * El cuerpo de un envío real, con lo que haga falta pisar.
     *
     * @param Client               $client El cliente (para el nombre).
     * @param array<string, mixed> $extra  Claves a sumar o pisar.
     *
     * @return array<string, mixed>
     */
    private function real(Client $client, array $extra = []): array
    {
        return array_merge([
            'texto'               => 'Hola Ana, ya tenemos el sistema instalado. Mañana te paso el acceso.',
            'accion'              => 'progreso',
            'operation_id'        => 'op-' . Str::random(12),
            'dry_run'             => false,
            'confirm_client_name' => $client->name,
        ], $extra);
    }

    /**
     * Las acciones de la etapa que escribió este endpoint (canal whatsapp).
     *
     * @param Implementation $implementation La implementación.
     * @param int            $etapa          La etapa.
     *
     * @return array<int, array<string, mixed>>
     */
    private function acciones_por_whatsapp(Implementation $implementation, int $etapa): array
    {
        $data = $this->data_de_la_etapa($implementation, $etapa);
        $acciones = isset($data['actions']) ? $data['actions'] : [];

        return array_values(array_filter($acciones, function ($accion) {
            return isset($accion['canal']) && $accion['canal'] === 'whatsapp';
        }));
    }

    /**
     * El dry-run (default) muestra el plan —plantilla con la ventana cerrada, lo que recibiría el cliente, el
     * teléfono enmascarado— y NO manda ni escribe nada: ni ticket, ni mensaje, ni acción, ni operación.
     *
     * @return void
     */
    public function test_el_dry_run_muestra_el_plan_y_no_manda_ni_escribe_nada(): void
    {
        [$client, $implementation] = $this->escenario();
        $tickets_antes = SupportTicket::where('client_id', $client->id)->count();

        $respuesta = $this->postJson(sprintf(self::RUTA, $implementation->id), [
            'texto'  => 'Hola, te paso el link del formulario.',
            'accion' => 'form_link',
        ], $this->headers());

        $respuesta->assertStatus(200)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('listo', true)
            ->assertJsonPath('ventana_abierta', false)
            ->assertJsonPath('uso_plantilla', true)
            ->assertJsonPath('plantilla', 'cc_soporte_apertura')
            ->assertJsonPath('ticket_id', null)
            ->assertJsonPath('reusaria_ticket', false)
            ->assertJsonPath('etapa', 3)
            ->assertJsonPath('destino.rol', 'owner')
            ->assertJsonPath('impedimentos', []);

        $this->assertStringContainsString('Hola, te paso el link del formulario.', (string) $respuesta->json('vista_previa'));
        $this->assertStringContainsString('del equipo de soporte de ComercioCity', (string) $respuesta->json('vista_previa'));
        $this->assertStringStartsWith('***', (string) $respuesta->json('destino.telefono'));
        $this->assertStringNotContainsString((string) $client->phone, $this->cuerpo($respuesta));

        $this->assertCount(0, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
        $this->assertSame($tickets_antes, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame([], $this->data_de_la_etapa($implementation, 3));
    }

    /**
     * Con la ventana abierta sale como TEXTO LIBRE por un ticket de soporte nuevo del dueño, y la acción queda en
     * la etapa con el formato de `actions` más el ticket y el mensaje. No crea `implementation_messages`.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_sale_como_texto_y_registra_la_accion(): void
    {
        [$client, $implementation] = $this->escenario();
        $this->abrir_la_ventana($client, (string) $client->phone);
        $mensajes_del_hilo = ImplementationMessage::where('implementation_id', $implementation->id)->count();

        $cuerpo    = $this->real($client);
        $respuesta = $this->postJson(sprintf(self::RUTA, $implementation->id), $cuerpo, $this->headers());

        $respuesta->assertStatus(200)
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('repetida', false)
            ->assertJsonPath('enviado', true)
            ->assertJsonPath('estado', 'sent')
            ->assertJsonPath('uso_plantilla', false)
            ->assertJsonPath('ventana_abierta', true)
            ->assertJsonPath('reusado', false)
            ->assertJsonPath('error', null)
            ->assertJsonPath('etapa', 3);

        $this->assertCount(1, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
        $this->assertSame($cuerpo['texto'], $this->espia->textos[0]['body']);

        $ticket = SupportTicket::find($respuesta->json('ticket_id'));
        $this->assertNotNull($ticket);
        $this->assertSame('whatsapp', $ticket->source);
        $this->assertSame('open', $ticket->status);
        $this->assertSame((int) $client->id, (int) $ticket->client_id);
        $this->assertNull($ticket->client_employee_id);

        $mensaje = SupportMessage::find($respuesta->json('message_id'));
        $this->assertSame((int) $ticket->id, (int) $mensaje->support_ticket_id);
        $this->assertSame('admin', $mensaje->sender_type);
        $this->assertSame((int) $this->admin->id, (int) $mensaje->sender_admin_id);
        $this->assertSame($cuerpo['texto'], $mensaje->body);
        $this->assertSame($respuesta->json('whatsapp_message_id'), $mensaje->whatsapp_message_id);

        $acciones = $this->acciones_por_whatsapp($implementation, 3);
        $this->assertCount(1, $acciones);
        $this->assertSame('progreso', $acciones[0]['action']);
        $this->assertSame(3, $acciones[0]['stage']);
        $this->assertSame('claude', $acciones[0]['origen']);
        $this->assertSame((int) $ticket->id, $acciones[0]['support_ticket_id']);
        $this->assertSame((int) $mensaje->id, $acciones[0]['support_message_id']);
        $this->assertSame($cuerpo['operation_id'], $acciones[0]['operation_id']);
        $this->assertNotEmpty($acciones[0]['at']);

        $operacion = $this->data_de_la_etapa($implementation, 3)['whatsapp_ops'][$cuerpo['operation_id']];
        $this->assertSame('sent', $operacion['estado']);
        $this->assertArrayNotHasKey('texto', $operacion, 'La operación no guarda el texto, solo su huella.');

        $this->assertSame($mensajes_del_hilo, ImplementationMessage::where('implementation_id', $implementation->id)->count());

        /* El estado de la implementación muestra la acción con su canal. */
        $estado = $this->getJson('/api/claude/implementations/' . $implementation->id, $this->headers())->assertStatus(200);
        $this->assertContains(
            ['accion' => 'progreso', 'at' => $acciones[0]['at'], 'canal' => 'whatsapp', 'origen' => 'claude'],
            $estado->json('etapas.2.acciones')
        );
    }

    /**
     * Con la ventana cerrada sale por la PLANTILLA de apertura con el texto adentro, y un segundo hito reusa el
     * ticket abierto del contacto en vez de abrir otro.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_sale_por_plantilla_y_el_segundo_reusa_el_ticket(): void
    {
        [$client, $implementation] = $this->escenario();

        $primero = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, ['texto' => 'Primer aviso corto.']), $this->headers());
        $primero->assertStatus(200)
            ->assertJsonPath('estado', 'sent')
            ->assertJsonPath('uso_plantilla', true)
            ->assertJsonPath('ventana_abierta', false)
            ->assertJsonPath('reusado', false);

        $this->assertCount(1, $this->espia->plantillas);
        $this->assertCount(0, $this->espia->textos);
        $this->assertSame('cc_soporte_apertura', $this->espia->plantillas[0]['template_name']);
        $this->assertSame('Primer aviso corto.', $this->espia->plantillas[0]['variables'][2]);
        $this->assertSame($this->admin->name, $this->espia->plantillas[0]['variables'][1]);

        $segundo = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, ['texto' => 'Segundo aviso.', 'accion' => 'pedir_archivos']), $this->headers());
        $segundo->assertStatus(200)->assertJsonPath('reusado', true);
        $this->assertSame($primero->json('ticket_id'), $segundo->json('ticket_id'));
        $this->assertCount(2, $this->acciones_por_whatsapp($implementation, 3));
    }

    /**
     * Con la ventana cerrada, un texto de más de 600 caracteres es 422 `texto_largo_para_plantilla` y no se manda
     * ni se escribe nada (la plantilla lo cortaría); el dry-run lo avisa. Con la ventana abierta, el mismo texto
     * sale como texto libre.
     *
     * @return void
     */
    public function test_texto_largo_con_la_ventana_cerrada_es_422_y_con_la_abierta_sale(): void
    {
        [$client, $implementation] = $this->escenario();
        $largo = str_repeat('Texto largo de la entrega. ', 30);

        $simulacro = $this->postJson(sprintf(self::RUTA, $implementation->id), ['texto' => $largo, 'accion' => 'entrega'], $this->headers());
        $simulacro->assertStatus(200)
            ->assertJsonPath('listo', false)
            ->assertJsonPath('impedimentos', ['texto_largo_para_plantilla']);

        $real = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, ['texto' => $largo, 'accion' => 'entrega']), $this->headers());
        $real->assertStatus(422)
            ->assertJsonPath('motivo', 'texto_largo_para_plantilla')
            ->assertJsonPath('tope', 600);

        $this->assertCount(0, $this->espia->plantillas);
        $this->assertCount(0, $this->espia->textos);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame([], $this->data_de_la_etapa($implementation, 3));

        $this->abrir_la_ventana($client, (string) $client->phone);

        $abierta = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, ['texto' => $largo, 'accion' => 'entrega']), $this->headers());
        $abierta->assertStatus(200)->assertJsonPath('estado', 'sent')->assertJsonPath('uso_plantilla', false);
        $this->assertCount(1, $this->espia->textos);
    }

    /**
     * Si Martín tiene la reserva vigente del ticket que se reusaría, es 423 `martin_atendiendo`, no se manda nada
     * y su caso NO pasa a `manual_review`. Con la reserva vencida, sale y el caso tampoco cambia.
     *
     * @return void
     */
    public function test_con_martin_atendiendo_es_423_y_el_caso_no_pasa_a_revision_manual(): void
    {
        [$client, $implementation] = $this->escenario();
        $telefono = (string) $client->phone;

        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => 'whatsapp',
            'whatsapp_phone' => $telefono,
            'opened_at'      => now(),
        ]);
        $caso = SupportCodexCase::create([
            'support_ticket_id' => $ticket->id,
            'state'             => 'ready',
            'worker_id'         => 'pc-lucas',
            'lease_hash'        => hash('sha256', 'token'),
            'lease_expires_at'  => now()->addMinutes(10),
        ]);

        $simulacro = $this->postJson(sprintf(self::RUTA, $implementation->id), ['texto' => 'Hola', 'accion' => 'progreso'], $this->headers());
        $simulacro->assertStatus(200)
            ->assertJsonPath('martin_atendiendo', true)
            ->assertJsonPath('ticket_id', $ticket->id)
            ->assertJsonPath('listo', false);

        $real = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client), $this->headers());
        $real->assertStatus(423)
            ->assertJsonPath('motivo', 'martin_atendiendo')
            ->assertJsonPath('ticket_id', $ticket->id);

        $this->assertCount(0, $this->espia->plantillas);
        $this->assertCount(0, $this->espia->textos);
        $this->assertSame(0, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        $this->assertSame('ready', $caso->fresh()->state);
        $this->assertNotNull($caso->fresh()->lease_hash, 'La reserva de Martín no se toca.');
        $this->assertSame([], $this->acciones_por_whatsapp($implementation, 3));

        /* Reserva vencida: sale por ese mismo ticket y el caso sigue igual. */
        $caso->lease_expires_at = now()->subMinute();
        $caso->save();

        $despues = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client), $this->headers());
        $despues->assertStatus(200)
            ->assertJsonPath('estado', 'sent')
            ->assertJsonPath('ticket_id', $ticket->id)
            ->assertJsonPath('reusado', true);
        $this->assertSame('ready', $caso->fresh()->state);
    }

    /**
     * La misma `operation_id` con el mismo contenido devuelve el resultado guardado sin reenviar ni registrar dos
     * veces; con otro texto o con otra etapa es 409 `operation_id_reusado`; y una operación que quedó mandando es
     * 409 `envio_en_curso`.
     *
     * @return void
     */
    public function test_la_misma_operation_id_no_manda_dos_veces_y_con_otro_contenido_es_409(): void
    {
        [$client, $implementation] = $this->escenario();
        $cuerpo = $this->real($client, ['operation_id' => 'hito-progreso-0001']);

        $primera = $this->postJson(sprintf(self::RUTA, $implementation->id), $cuerpo, $this->headers());
        $primera->assertStatus(200)->assertJsonPath('repetida', false)->assertJsonPath('estado', 'sent');

        $segunda = $this->postJson(sprintf(self::RUTA, $implementation->id), $cuerpo, $this->headers());
        $segunda->assertStatus(200)
            ->assertJsonPath('repetida', true)
            ->assertJsonPath('estado', 'sent')
            ->assertJsonPath('ticket_id', $primera->json('ticket_id'))
            ->assertJsonPath('message_id', $primera->json('message_id'));

        $this->assertCount(1, $this->espia->plantillas);
        $this->assertCount(1, $this->acciones_por_whatsapp($implementation, 3));

        $otro_texto = $this->postJson(sprintf(self::RUTA, $implementation->id), array_merge($cuerpo, ['texto' => 'Otro texto']), $this->headers());
        $otro_texto->assertStatus(409)->assertJsonPath('motivo', 'operation_id_reusado');

        $otra_etapa = $this->postJson(sprintf(self::RUTA, $implementation->id), array_merge($cuerpo, ['etapa' => 2]), $this->headers());
        $otra_etapa->assertStatus(409)->assertJsonPath('motivo', 'operation_id_reusado');

        $this->assertCount(1, $this->espia->plantillas);

        /* Una operación reservada que nunca terminó (el proceso murió en el medio). */
        $data = $this->data_de_la_etapa($implementation, 3);
        $data['whatsapp_ops']['hito-colgado-0001'] = array_merge($data['whatsapp_ops']['hito-progreso-0001'], ['estado' => 'sending']);
        $this->escribir_data_de_la_etapa($implementation, 3, $data);

        $colgada = $this->postJson(sprintf(self::RUTA, $implementation->id), array_merge($cuerpo, ['operation_id' => 'hito-colgado-0001']), $this->headers());
        $colgada->assertStatus(409)->assertJsonPath('motivo', 'envio_en_curso');
        $this->assertCount(1, $this->espia->plantillas);
    }

    /**
     * Un envío que falla vuelve 200 con `estado: failed`, no registra la acción, y la misma clave devuelve el
     * `failed` guardado sin reintentar sola.
     *
     * @return void
     */
    public function test_un_envio_fallido_no_registra_la_accion_ni_se_reintenta_solo(): void
    {
        [$client, $implementation] = $this->escenario();
        $this->espia = $this->espiar_el_envio(false);
        $cuerpo = $this->real($client);

        $respuesta = $this->postJson(sprintf(self::RUTA, $implementation->id), $cuerpo, $this->headers());
        $respuesta->assertStatus(200)
            ->assertJsonPath('enviado', false)
            ->assertJsonPath('estado', 'failed');
        $this->assertNotNull($respuesta->json('error'));
        $this->assertSame([], $this->acciones_por_whatsapp($implementation, 3));

        $repetida = $this->postJson(sprintf(self::RUTA, $implementation->id), $cuerpo, $this->headers());
        $repetida->assertStatus(200)->assertJsonPath('repetida', true)->assertJsonPath('estado', 'failed');
        $this->assertCount(1, $this->espia->plantillas);
    }

    /**
     * El destino: un teléfono que no es contacto del cliente es 422 `telefono_ajeno`; el de un empleado sale por
     * un ticket de ese empleado; sin teléfono en la ficha, 422 `sin_contacto`.
     *
     * @return void
     */
    public function test_telefono_ajeno_empleado_y_cliente_sin_telefono(): void
    {
        [$client, $implementation] = $this->escenario();

        $ajeno = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, ['telefono' => '+5491100000000']), $this->headers());
        $ajeno->assertStatus(422)->assertJsonPath('motivo', 'telefono_ajeno');

        $empleado            = new ClientEmployee();
        $empleado->client_id = $client->id;
        $empleado->name      = 'Brisa';
        $empleado->phone     = '+5493412223333';
        $empleado->save();

        $al_empleado = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, ['telefono' => '+54 9 341 222-3333']), $this->headers());
        $al_empleado->assertStatus(200)->assertJsonPath('estado', 'sent');
        $this->assertSame((int) $empleado->id, (int) SupportTicket::find($al_empleado->json('ticket_id'))->client_employee_id);

        $sin_telefono         = $this->crear_cliente('Sin Teléfono');
        $sin_telefono->phone  = null;
        $sin_telefono->save();
        $otra = $this->llevar_a_la_etapa($this->crear_implementacion($sin_telefono), 2);

        $this->postJson(sprintf(self::RUTA, $otra->id), $this->real($sin_telefono), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'sin_contacto');

        $this->assertCount(1, $this->espia->plantillas);
    }

    /**
     * Los frenos de la entrada: parámetros de más, real sin `operation_id` o con el nombre equivocado, una acción
     * que no existe, implementación inexistente. En ninguno se manda nada.
     *
     * @return void
     */
    public function test_frenos_de_la_entrada(): void
    {
        [$client, $implementation] = $this->escenario();
        $ruta = sprintf(self::RUTA, $implementation->id);

        $this->postJson($ruta, $this->real($client, ['plantilla' => 'otra']), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('parametros_aceptados', ClaudeImplementationOpsController::PARAMETROS_DEL_WHATSAPP);

        $sin_clave = $this->real($client);
        unset($sin_clave['operation_id']);
        $this->postJson($ruta, $sin_clave, $this->headers())->assertStatus(422);

        $this->postJson($ruta, $this->real($client, ['operation_id' => 'corta']), $this->headers())->assertStatus(422);
        $this->postJson($ruta, $this->real($client, ['confirm_client_name' => 'Otro Negocio']), $this->headers())->assertStatus(422);
        $this->postJson($ruta, $this->real($client, ['accion' => 'inventada']), $this->headers())->assertStatus(422);
        $this->postJson($ruta, $this->real($client, ['texto' => '   ']), $this->headers())->assertStatus(422);
        $this->postJson(sprintf(self::RUTA, 99999999), $this->real($client), $this->headers())->assertStatus(404);
        $this->postJson($ruta, $this->real($client), ['Accept' => 'application/json'])->assertStatus(401);

        $this->assertCount(0, $this->espia->plantillas);
        $this->assertCount(0, $this->espia->textos);
        $this->assertSame([], $this->data_de_la_etapa($implementation, 3));
    }

    /**
     * `accion=nota` es un mensaje real al cliente (ARCA en la etapa 7, el centro de recursos en la 6): sale por el
     * ticket y se registra en `data.actions[]` de la etapa pedida igual que las demás, sin copiar el texto a la
     * entrada (vive en el mensaje del ticket).
     *
     * @return void
     */
    public function test_la_nota_se_manda_al_cliente_y_se_registra_en_la_etapa_pedida(): void
    {
        [$client, $implementation] = $this->escenario();
        $implementation = $this->llevar_a_la_etapa($implementation, 7);
        $this->abrir_la_ventana($client, (string) $client->phone);

        $texto     = 'Para facturar desde el sistema necesitamos que nos delegues el servicio en ARCA. Te paso los pasos.';
        $respuesta = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, [
            'texto'  => $texto,
            'accion' => 'nota',
            'etapa'  => 7,
        ]), $this->headers());

        $respuesta->assertStatus(200)
            ->assertJsonPath('accion', 'nota')
            ->assertJsonPath('etapa', 7)
            ->assertJsonPath('estado', 'sent');

        $this->assertCount(1, $this->espia->textos);
        $this->assertSame($texto, $this->espia->textos[0]['body']);
        $this->assertSame($texto, SupportMessage::find($respuesta->json('message_id'))->body);

        $acciones = $this->acciones_por_whatsapp($implementation, 7);
        $this->assertCount(1, $acciones);
        $this->assertSame('nota', $acciones[0]['action']);
        $this->assertSame(7, $acciones[0]['stage']);
        $this->assertSame((int) $respuesta->json('ticket_id'), $acciones[0]['support_ticket_id']);
        $this->assertArrayNotHasKey('texto', $acciones[0]);

        /* La del centro de recursos, en la etapa 6 (explícita, distinta de la actual). */
        $centro = $this->postJson(sprintf(self::RUTA, $implementation->id), $this->real($client, [
            'texto'  => 'Te dejo el centro de recursos con los videos para arrancar.',
            'accion' => 'nota',
            'etapa'  => 6,
        ]), $this->headers());
        $centro->assertStatus(200)->assertJsonPath('etapa', 6)->assertJsonPath('estado', 'sent');
        $this->assertCount(1, $this->acciones_por_whatsapp($implementation, 6));
    }

    /**
     * El catálogo declara exactamente los parámetros de la lista blanca del controlador (el test general de las
     * implementaciones cubre las ocho rutas anteriores; este, la novena).
     *
     * @return void
     */
    public function test_el_catalogo_declara_los_parametros_de_la_lista_blanca(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/implementations/{id}/whatsapp');
        $this->assertIsArray($endpoint);

        $declarados = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') === false) {
                $declarados[] = $parametro['nombre'];
            }
        }

        $aceptados = ClaudeImplementationOpsController::PARAMETROS_DEL_WHATSAPP;
        sort($declarados);
        sort($aceptados);

        $this->assertSame($aceptados, $declarados);
    }
}
