<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\AsistenteAvisosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El Excel o el PDF que un negocio en prueba manda por Soporte DE SU SISTEMA (misión cruzada
 * prueba-e2e-campana-imperio, 11/11/2026, ítem L): tiene que guardarse, verse y poder importarse igual que el
 * que llega por WhatsApp, y los avisos de la importación tienen que volver por ese mismo canal.
 *
 * Lo que protege:
 *  1. El entrante del sistema (`POST api/inbound/support/messages`, multipart) guarda un xlsx, xls, csv o pdf con
 *     SU extensión, su mime y su nombre original, aunque el servidor lo detecte como zip o binario; un archivo que
 *     dice ser Excel y no lo es no pasa por documento.
 *  2. Martín lo ve en el contexto del ticket (`messages[].attachments`: mime, size, download_path, filename) y lo
 *     puede bajar.
 *  3. `import-excel` lo acepta desde un ticket del canal sistema y pide el flujo automático de la prueba, con el
 *     ticket de origen.
 *  4. El aviso «ya leí tu Excel» de ese ticket se sincroniza al sistema del negocio y no sale por WhatsApp.
 */
class DocumentosPorSoporteDelSistemaTest extends BaseDeLaPrueba
{
    const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Primeros bytes de un .xlsx (un zip) y de un .xls (OLE). */
    const BYTES_XLSX = "PK\x03\x04\x14\x00\x06\x00contenido-del-excel";
    const BYTES_XLS  = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1contenido-del-xls";
    const BYTES_PDF  = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n";

    /** @var Client */
    private $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();

        $this->client = $this->cliente_en_prueba();
        $this->client->inbound_api_key = 'firma-del-sistema-de-la-prueba';
        $this->client->save();
    }

    /**
     * Manda un mensaje con adjuntos por el canal sistema, como lo reenvía empresa-api (multipart).
     *
     * @param array<int, UploadedFile>         $archivos
     * @param array<int, array<string, mixed>> $metadata La metadata `attachments`, en el mismo orden.
     * @param string|null                      $ticket_uuid
     *
     * @return SupportMessage
     */
    private function mandar_por_el_sistema(array $archivos, array $metadata = [], ?string $ticket_uuid = null): SupportMessage
    {
        $respuesta = $this->post('/api/inbound/support/messages', [
            'agentes_version'   => 1,
            'es_dueno'          => 1,
            'client_uuid'       => $this->client->uuid,
            'ticket_uuid'       => $ticket_uuid !== null ? $ticket_uuid : (string) Str::uuid(),
            'client_user_id'    => $this->client->user_id,
            'sender_user_id'    => $this->client->user_id,
            'sender_type'       => 'user',
            'message_uuid'      => (string) Str::uuid(),
            'kind'              => 'file',
            'body'              => 'Te mando la lista',
            'attachments'       => json_encode($metadata),
            'attachments_files' => $archivos,
        ], ['X-Admin-Api-Key' => (string) $this->client->inbound_api_key, 'Accept' => 'application/json']);

        $respuesta->assertCreated();

        return SupportMessage::with('attachments', 'ticket')->findOrFail($respuesta->json('model.id'));
    }

    /** @test */
    public function un_excel_que_llega_por_el_sistema_se_guarda_con_su_extension_su_mime_y_su_nombre(): void
    {
        /* Como lo reenvía empresa: el nombre del multipart es el de SU disco y el original viaja en la metadata. */
        $mensaje = $this->mandar_por_el_sistema(
            [UploadedFile::fake()->createWithContent('k3J9xQ.zip', self::BYTES_XLSX)],
            [['disk' => 'public', 'path' => 'support_messages/9/k3J9xQ.zip', 'mime' => 'application/zip', 'size' => 27, 'original_name' => 'Lista de precios marzo.xlsx']]
        );

        $this->assertSame('erp', $mensaje->ticket->source);
        $this->assertCount(1, $mensaje->attachments);
        $adjunto = $mensaje->attachments->first();

        $this->assertSame(self::XLSX, $adjunto->mime, 'El mime es el de un Excel, no el zip que detecta el servidor.');
        $this->assertSame('xlsx', pathinfo($adjunto->path, PATHINFO_EXTENSION));
        $this->assertSame('Lista de precios marzo.xlsx', $adjunto->original_name);
        $this->assertSame('Lista de precios marzo.xlsx', $adjunto->display_filename, 'En la bandeja se ve con su nombre.');
        $this->assertSame(strlen(self::BYTES_XLSX), (int) $adjunto->size);
        Storage::disk('public')->assertExists($adjunto->path);
        $this->assertSame(self::BYTES_XLSX, Storage::disk('public')->get($adjunto->path));
    }

    /** @test */
    public function pdf_xls_y_csv_tambien_y_un_archivo_que_miente_no_pasa_por_documento(): void
    {
        /* Un archivo de verdad en disco (no el falso de Laravel, que deduce el mime del nombre): así la detección
         * del servidor es la real. */
        $ruta_trampa = tempnam(sys_get_temp_dir(), 'trampa');
        file_put_contents($ruta_trampa, '<html><body>no soy un excel</body></html>');
        $trampa = new UploadedFile($ruta_trampa, 'trampa.xlsx', self::XLSX, null, true);

        $mensaje = $this->mandar_por_el_sistema([
            UploadedFile::fake()->createWithContent('catalogo proveedor.pdf', self::BYTES_PDF),
            UploadedFile::fake()->createWithContent('lista.xls', self::BYTES_XLS),
            UploadedFile::fake()->createWithContent('clientes.csv', "nombre;telefono\nJuan;341555\n"),
            $trampa,
        ]);

        $adjuntos = $mensaje->attachments->sortBy('id')->values();
        $this->assertCount(4, $adjuntos);

        $this->assertSame('application/pdf', $adjuntos[0]->mime);
        $this->assertSame('pdf', pathinfo($adjuntos[0]->path, PATHINFO_EXTENSION));
        $this->assertSame('catalogo proveedor.pdf', $adjuntos[0]->original_name);

        $this->assertSame('application/vnd.ms-excel', $adjuntos[1]->mime);
        $this->assertSame('xls', pathinfo($adjuntos[1]->path, PATHINFO_EXTENSION));

        $this->assertSame('text/csv', $adjuntos[2]->mime);
        $this->assertSame('csv', pathinfo($adjuntos[2]->path, PATHINFO_EXTENSION));

        $this->assertNotSame(self::XLSX, $adjuntos[3]->mime, 'Dice ser un Excel y no lo es: queda con lo que detecta el servidor.');
        $this->assertNotSame('xlsx', pathinfo($adjuntos[3]->path, PATHINFO_EXTENSION));
        $this->assertSame('trampa.xlsx', $adjuntos[3]->original_name);
    }

    /** @test */
    public function una_imagen_sigue_guardandose_como_siempre(): void
    {
        $mensaje = $this->mandar_por_el_sistema([UploadedFile::fake()->image('foto.png', 20, 20)]);

        $adjunto = $mensaje->attachments->first();
        $this->assertSame('image/png', $adjunto->mime);
        $this->assertSame('png', pathinfo($adjunto->path, PATHINFO_EXTENSION));
    }

    /** @test */
    public function martin_ve_el_excel_en_el_contexto_del_ticket_y_lo_puede_bajar(): void
    {
        $mensaje = $this->mandar_por_el_sistema(
            [UploadedFile::fake()->createWithContent('a1b2.bin', self::BYTES_XLSX)],
            [['mime' => self::XLSX, 'original_name' => 'articulos.xlsx']]
        );
        $adjunto = $mensaje->attachments->first();
        $ruta    = '/api/claude/support-codex/tickets/' . $mensaje->support_ticket_id;

        $contexto = $this->getJson($ruta, $this->headers_de_claude())->assertOk();

        $del_mensaje = collect($contexto->json('ticket.messages') ?: $contexto->json('messages'))->firstWhere('id', (int) $mensaje->id);
        $this->assertNotNull($del_mensaje, 'El mensaje del sistema viaja en el contexto.');
        $this->assertSame([[
            'id'            => (int) $adjunto->id,
            'mime'          => self::XLSX,
            'size'          => strlen(self::BYTES_XLSX),
            'download_path' => $ruta . '/attachments/' . $adjunto->id,
            'filename'      => 'articulos.xlsx',
        ]], $del_mensaje['attachments']);

        $descarga = $this->get($del_mensaje['attachments'][0]['download_path'], $this->headers_de_claude())->assertOk();
        $this->assertStringContainsString(self::XLSX, (string) $descarga->headers->get('Content-Type'));
    }

    /** @test */
    public function import_excel_acepta_el_adjunto_del_sistema_y_pide_el_flujo_automatico_con_su_ticket(): void
    {
        $mensaje = $this->mandar_por_el_sistema(
            [UploadedFile::fake()->createWithContent('a1b2.zip', self::BYTES_XLSX)],
            [['original_name' => 'articulos.xlsx']]
        );
        $adjunto = $mensaje->attachments->first();
        $ticket  = $mensaje->ticket;

        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response([
            'analysis_uuid' => 'uuid-de-la-corrida', 'automatizar_prueba' => true,
        ], 202)]);

        $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/import-excel', ['attachment_id' => $adjunto->id], $this->headers_de_claude())
            ->assertOk()->assertJson(['estado' => 'aceptado', 'automatizar_prueba' => true, 'filename' => 'adjunto-' . $adjunto->id . '.xlsx']);

        Http::assertSent(function ($request) use ($ticket, $adjunto) {
            if (strpos($request->url(), '/admin-sync/ai-excel-import/analyze') === false) {
                return false;
            }
            $campos = [];
            foreach ($request->data() as $parte) {
                if (is_array($parte) && isset($parte['name']) && ! isset($parte['filename'])) {
                    $campos[$parte['name']] = (string) $parte['contents'];
                }
            }

            return $request->hasFile('excel_file', self::BYTES_XLSX, 'adjunto-' . $adjunto->id . '.xlsx')
                && ($campos['automatizar_prueba'] ?? null) === '1'
                && ($campos['support_ticket_id'] ?? null) === (string) $ticket->id
                && ($campos['user_id'] ?? null) === (string) $this->client->user_id;
        });
    }

    /** @test */
    public function el_ticket_del_sistema_de_un_empleado_no_pide_el_flujo_automatico(): void
    {
        $ticket = SupportTicket::create([
            'client_id' => $this->client->id, 'client_user_id' => (int) $this->client->user_id + 1, 'status' => 'open',
            'source' => 'erp', 'opened_at' => now(), 'agentes_erp' => true,
        ]);
        $mensaje = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'file', 'body' => 'lista', 'delivered_at' => now()]);
        Storage::disk('public')->put('support_messages/' . $ticket->id . '/lista.xlsx', self::BYTES_XLSX);
        $adjunto = SupportMessageAttachment::create(['support_message_id' => $mensaje->id, 'disk' => 'public',
            'path' => 'support_messages/' . $ticket->id . '/lista.xlsx', 'mime' => self::XLSX, 'size' => 27]);

        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-x'], 202)]);

        $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/import-excel', ['attachment_id' => $adjunto->id], $this->headers_de_claude())
            ->assertOk()->assertJsonPath('automatizar_prueba', false);

        Http::assertSent(function ($request) {
            if (strpos($request->url(), '/admin-sync/ai-excel-import/analyze') === false) {
                return false;
            }
            foreach ($request->data() as $parte) {
                if (is_array($parte) && isset($parte['name']) && $parte['name'] === 'automatizar_prueba') {
                    return false;
                }
            }

            return true;
        });
    }

    /** @test */
    public function el_aviso_de_la_importacion_vuelve_por_la_conversacion_del_sistema_y_no_por_whatsapp(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        /* Aunque tenga la ventana de WhatsApp abierta, el aviso de ESTE ticket va al sistema. */
        $this->ticket_del_cliente($this->client, true);

        $mensaje = $this->mandar_por_el_sistema([UploadedFile::fake()->createWithContent('articulos.xlsx', self::BYTES_XLSX)]);
        $ticket  = $mensaje->ticket;
        $texto   = 'Ya leí "articulos.xlsx". Encontré 412 filas. Entrá al sistema y te aparece la importación lista para que la termines.';

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response(['avisos' => [[
                'id' => 'importacion_lista:9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70', 'tipo' => 'importacion_lista',
                'uuid' => '9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70', 'ai_conversation_id' => null, 'texto' => $texto, 'resumen' => $texto,
                'ruta' => '/listado-de-articulos', 'url' => 'https://ferreteria.comerciocity.com/listado-de-articulos',
                'support_ticket_id' => (int) $ticket->id,
            ]]], 200),
            '*/asistente/avisos/avisado'       => Http::response(['ok' => true], 200),
            '*/api/admin-sync/support/messages' => Http::response(['model' => ['id' => 1]], 201),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($this->client->fresh());

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertCount(0, $espia->textos, 'Nada por WhatsApp: el archivo llegó por el sistema.');
        $this->assertCount(0, $espia->plantillas);

        $aviso = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->sole();
        $this->assertSame($texto, $aviso->body);
        $this->assertTrue($aviso->is_trial_import_notice, 'Queda en el hilo sin tapar el entrante que espera respuesta.');
        $this->assertNotNull($aviso->synced_to_client_at);
        $this->assertNull($aviso->whatsapp_message_id);

        Http::assertSent(function ($request) use ($ticket, $texto) {
            return strpos($request->url(), '/api/admin-sync/support/messages') !== false
                && $request['ticket_uuid'] === $ticket->uuid && $request['body'] === $texto;
        });
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/asistente/avisos/avisado') !== false;
        });
    }

    /** @test */
    public function martin_ve_las_preguntas_de_la_importacion_de_un_ticket_del_sistema_y_las_resuelve_igual_que_por_whatsapp(): void
    {
        $this->espiar_sender();
        $this->admin();
        $mensaje = $this->mandar_por_el_sistema([UploadedFile::fake()->createWithContent('articulos.xlsx', self::BYTES_XLSX)]);
        $ticket  = $mensaje->ticket;
        $uuid    = '9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70';
        $pendiente = ['analysis_uuid' => $uuid, 'filename' => 'articulos.xlsx', 'revision' => 2, 'tipo' => 'columnas', 'preguntas' => [
            ['clave' => 'pregunta-1', 'texto' => '¿La columna Importe es costo o precio?', 'opciones' => [['valor' => 'costo', 'etiqueta' => 'Costo']]],
        ]];

        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*'            => Http::response(['pendientes' => [$pendiente]], 200),
            '*/admin-sync/ai-excel-import/prueba-resolver/' . $uuid       => Http::response(['estado' => 'encolada'], 202),
            '*/api/admin-sync/support/messages'                          => Http::response(['model' => ['id' => 1]], 201),
        ]);
        \App\Models\AdminSetting::set(\App\Services\SupportCodexSettings::KEY_ENABLED, '1');
        \App\Models\AdminSetting::set(\App\Services\SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        \App\Models\AdminSetting::set(\App\Services\SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);

        /* El aviso de la importación llega a la conversación del sistema y NO tapa el mensaje que espera respuesta. */
        $aviso = app(\App\Services\PruebaWhatsappService::class)->mandar_texto_por_ticket($this->client->fresh(), 'Ya leí "articulos.xlsx". Me falta un dato para terminar.', (int) $ticket->id);
        $this->assertTrue($aviso['enviado']);

        $base = '/api/claude/support-codex/tickets/' . $ticket->id;
        $pendientes = $this->getJson('/api/claude/support-codex/pending', $this->headers_de_claude())->assertOk();
        $this->assertContains((int) $ticket->id, array_column($pendientes->json('tickets'), 'id'), 'El ticket del sistema sigue pendiente para Martín.');

        $reserva = $this->postJson($base . '/claim', ['worker_id' => 'martin', 'expected_message_id' => (int) $mensaje->id], $this->headers_de_claude())
            ->assertOk()
            ->assertJsonPath('ticket.canal', 'sistema')
            ->assertJsonPath('ticket.expected_message_id', (int) $mensaje->id)
            ->assertJsonPath('ticket.capabilities.prueba_import', true)
            ->assertJsonPath('ticket.capabilities.prueba_import_resolver', true)
            ->assertJsonPath('ticket.importaciones_prueba_pendientes', [$pendiente]);
        $avisos = collect($reserva->json('ticket.messages'))->where('is_trial_import_notice', true);
        $this->assertCount(1, $avisos, 'Martín ve el aviso en el hilo, marcado como aviso.');

        /* La consulta de corridas pendientes va con el ticket del sistema y el dueño. */
        $consulta = Http::recorded(function ($request) {
            return strpos($request->url(), '/ai-excel-import/prueba-pendientes') !== false;
        })->first()[0];
        parse_str((string) parse_url($consulta->url(), PHP_URL_QUERY), $query);
        $this->assertSame((string) $ticket->id, $query['support_ticket_id']);
        $this->assertSame((string) $this->client->user_id, $query['user_id']);

        /* Y la respuesta del negocio se resuelve por la misma ruta que en WhatsApp. */
        $this->postJson($base . '/import-prueba-resolver', [
            'analysis_uuid' => $uuid, 'revision' => 2, 'respuestas' => ['pregunta-1' => 'costo'],
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => (int) $mensaje->id,
        ], $this->headers_de_claude())->assertStatus(202)->assertJsonPath('estado', 'encolada');
    }

    /** @test */
    public function las_capacidades_de_importacion_de_la_prueba_dicen_lo_que_el_ticket_puede_usar(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['pendientes' => []], 200)]);
        $leer = function (SupportTicket $ticket): array {
            $capacidades = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude())->assertOk()->json('ticket.capabilities');

            return [$capacidades['prueba_import'], $capacidades['prueba_import_resolver']];
        };
        $ticket_de = function (int $usuario, array $extra = []) : SupportTicket {
            $ticket = SupportTicket::create($extra + [
                'client_id' => $this->client->id, 'client_user_id' => $usuario, 'status' => 'open',
                'source' => 'erp', 'opened_at' => now(), 'agentes_erp' => true,
            ]);
            SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Hola', 'delivered_at' => now(),
                'erp_auto_reply' => [SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX => true]]);

            return $ticket;
        };

        /* El dueño en su sistema y el dueño por WhatsApp: el circuito entero. */
        $this->assertSame([true, true], $leer($ticket_de((int) $this->client->user_id)));
        $this->assertSame([true, true], $leer($this->ticket_del_cliente($this->client, true)));

        /* Un empleado en el sistema: puede mandarse un Excel a mano, pero no hay circuito automático que resolver. */
        $this->assertSame([true, false], $leer($ticket_de((int) $this->client->user_id + 1)));

        /* Con la prueba vencida tampoco. */
        $del_dueno = $ticket_de((int) $this->client->user_id);
        $this->client->estado = \App\Services\Prueba\PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $this->client->save();
        $this->assertSame([true, false], $leer($del_dueno));

        /* Un cliente que no está en prueba: como siempre (el monitor decide por client.estado). */
        $this->client->estado = \App\Services\Prueba\PruebaCicloService::CLIENTE_ACTIVO;
        $this->client->save();
        $this->assertSame([true, true], $leer($del_dueno));
    }

    /** @test */
    public function si_el_sistema_no_confirma_el_aviso_no_queda_una_copia_en_el_hilo(): void
    {
        $this->espiar_sender();
        $this->admin();
        $mensaje = $this->mandar_por_el_sistema([UploadedFile::fake()->createWithContent('articulos.xlsx', self::BYTES_XLSX)]);
        $ticket  = $mensaje->ticket;

        $this->fakear_http(['*/api/admin-sync/support/messages' => Http::response(['error' => 'x'], 500)]);
        config(['services.client_api.retries' => 1]);

        $salida = app(\App\Services\PruebaWhatsappService::class)->mandar_texto_por_ticket($this->client->fresh(), 'Ya leí tu Excel.', (int) $ticket->id);

        $this->assertFalse($salida['enviado']);
        $this->assertSame(0, SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->count());
    }
}
