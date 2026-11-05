<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * `POST claude/support-codex/tickets/{id}/import-excel` e `import-images` (misión cruzada
 * prueba-martin-importacion, 3/11/2026): el admin reenvía el Excel del ticket (que ya tiene en su disco) a
 * `admin-sync/ai-excel-import/analyze` de la instancia del cliente en prueba, en segundo plano y con aviso
 * por WhatsApp, con la clave del cliente.
 *
 * Lo que protege, en orden:
 *  1. 🔴 Solo para un cliente EN PRUEBA y desde un ticket ABIERTO: a otro cliente es 404 y no se le pega a nadie.
 *  2. El multipart lleva `excel_file`, `user_id`, `segundo_plano=1`, `avisar_por_whatsapp=1` y `origen`, con
 *     `X-Admin-Api-Key` del cliente; la clave no sale en la respuesta.
 *  3. El adjunto tiene que ser de ESTE ticket y ser un Excel; en base64 solo .xlsx/.xls.
 *  4. Un empresa viejo que analiza en el momento se reporta como `analizado_sin_corrida`.
 */
class ImportacionDesdeElTicketTest extends BaseDeLaPrueba
{
    /** @var \App\Models\Client */
    private $client;

    /** @var SupportTicket */
    private $ticket;

    /** @var SupportMessageAttachment */
    private $adjunto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->client  = $this->cliente_en_prueba();
        $this->ticket  = $this->ticket_del_cliente($this->client);
        $this->adjunto = $this->adjuntar($this->ticket, 'soporte/lista.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * Un adjunto del último mensaje del ticket, con su archivo en el disco falso.
     *
     * @param SupportTicket $ticket
     * @param string        $path
     * @param string        $mime
     *
     * @return SupportMessageAttachment
     */
    private function adjuntar(SupportTicket $ticket, string $path, string $mime): SupportMessageAttachment
    {
        Storage::disk('public')->put($path, 'contenido-del-excel');

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();

        return SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $path,
            'mime'               => $mime,
            'size'               => 19,
        ]);
    }

    /**
     * El valor de un campo de un request multipart falso: el cliente HTTP guarda las partes como
     * `[{name, contents, filename?}]`, no como un diccionario.
     *
     * @param \Illuminate\Http\Client\Request $request
     * @param string                          $nombre
     *
     * @return string|null
     */
    private function campo($request, string $nombre): ?string
    {
        foreach ($request->data() as $parte) {
            if (is_array($parte) && isset($parte['name']) && $parte['name'] === $nombre && ! isset($parte['filename'])) {
                return (string) $parte['contents'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @param SupportTicket|null   $ticket
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function importar(array $body, ?SupportTicket $ticket = null)
    {
        $ticket = $ticket !== null ? $ticket : $this->ticket;

        return $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/import-excel', $body, $this->headers_de_claude());
    }

    /** @test */
    public function reenvia_el_excel_del_ticket_en_segundo_plano_con_aviso_y_con_la_clave_del_cliente(): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-de-la-corrida', 'excel_path' => 'imported_files/x.xlsx', 'estado' => 'pendiente'], 202),
        ]);

        $respuesta = $this->importar(['attachment_id' => $this->adjunto->id]);

        $respuesta->assertStatus(200);
        $this->assertSame('aceptado', $respuesta->json('estado'));
        $this->assertSame('uuid-de-la-corrida', $respuesta->json('analysis_uuid'));
        $this->assertSame(202, $respuesta->json('http'));
        $this->assertFalse($respuesta->json('automatizar_prueba'));
        $this->assertStringNotContainsString('clave-del-cliente', (string) $respuesta->getContent());

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/admin-sync/ai-excel-import/analyze') !== false
                && $request->header('X-Admin-Api-Key')[0] === 'clave-del-cliente'
                && $request->isMultipart()
                && $request->hasFile('excel_file', 'contenido-del-excel', 'adjunto-' . $this->adjunto->id . '.xlsx')
                && $this->campo($request, 'user_id') === (string) $this->client->user_id
                && $this->campo($request, 'model') === 'article'
                && $this->campo($request, 'segundo_plano') === '1'
                && $this->campo($request, 'avisar_por_whatsapp') === '1'
                && $this->campo($request, 'origen') === 'martin';
        });
    }

    /** @test */
    public function pide_el_flujo_automatico_y_le_devuelve_al_monitor_si_la_instancia_lo_acepto(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response([
            'analysis_uuid' => 'uuid-de-la-corrida', 'automatizar_prueba' => true,
        ], 202)]);

        $this->importar(['attachment_id' => $this->adjunto->id])->assertOk()->assertJson([
            'estado' => 'aceptado', 'automatizar_prueba' => true,
        ]);

        Http::assertSent(function ($request) {
            return $this->campo($request, 'automatizar_prueba') === '1'
                && $this->campo($request, 'support_ticket_id') === (string) $this->ticket->id
                && $this->campo($request, 'importacion_clave') === hash('sha256', 'contenido-del-excel');
        });
    }

    /** @test */
    public function la_clave_del_pdf_identifica_el_adjunto_original_aunque_cambie_la_planilla_derivada(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response([
            'analysis_uuid' => 'uuid-de-la-corrida', 'automatizar_prueba' => true,
        ], 202)]);
        $pdf = $this->adjuntar($this->ticket, 'soporte/lista.pdf', 'application/pdf');

        foreach (['version-1', 'version-2'] as $contenido) {
            $this->importar([
                'origen' => 'pdf',
                'pdf_attachment_id' => $pdf->id,
                'file' => ['filename' => 'catalogo.xlsx', 'content_base64' => base64_encode($contenido)],
            ])->assertOk();
        }

        $this->importar([
            'origen' => 'pdf',
            'pdf_attachment_id' => $this->adjunto->id,
            'file' => ['filename' => 'catalogo.xlsx', 'content_base64' => base64_encode('contenido')],
        ])->assertStatus(422);

        $pedidos = Http::recorded();
        $this->assertCount(2, $pedidos);
        foreach ($pedidos as [$pedido]) {
            $this->assertSame(hash('sha256', 'pdf:' . $pdf->id), $this->campo($pedido, 'importacion_clave'));
        }
    }

    /** @test */
    public function martin_recibe_las_preguntas_y_resuelve_solo_con_la_reserva_y_mensaje_actuales(): void
    {
        $uuid = '9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70';
        $preguntas = ['analysis_uuid' => $uuid, 'filename' => 'lista.xlsx', 'revision' => 2, 'tipo' => 'columnas', 'preguntas' => [
            ['clave' => 'columna-1', 'texto' => '¿La columna Importe es costo o precio?', 'opciones' => [['valor' => 'costo', 'etiqueta' => 'Costo']]],
        ]];
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['pendientes' => [$preguntas]], 200),
            '*/admin-sync/ai-excel-import/prueba-resolver/' . $uuid => Http::response(['estado' => 'encolada'], 202),
        ]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);

        $base = '/api/claude/support-codex/tickets/' . $this->ticket->id;
        $mensaje = (int) $this->adjunto->support_message_id;
        $aviso = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Ya leí otro archivo que mandaste.', 'is_trial_import_notice' => true,
        ]);
        $reserva = $this->postJson($base . '/claim', ['worker_id' => 'martin', 'expected_message_id' => $mensaje], $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.capabilities.prueba_import_resolver', true)
            ->assertJsonPath('ticket.expected_message_id', $mensaje)
            ->assertJsonPath('ticket.importaciones_prueba_pendientes', [$preguntas]);
        $this->assertSame((int) $aviso->id, $reserva->json('ticket.messages.1.id'));

        $respuestas = ['columna-1' => 'costo'];
        $datos = ['analysis_uuid' => $uuid, 'revision' => 2, 'respuestas' => $respuestas,
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $mensaje];
        $this->postJson($base . '/import-prueba-resolver', ['lease_token' => str_repeat('x', 64)] + $datos, $this->headers_de_claude())->assertStatus(423);
        $this->postJson($base . '/import-prueba-resolver', ['expected_message_id' => $mensaje + 1] + $datos, $this->headers_de_claude())->assertStatus(409);
        $this->postJson($base . '/import-prueba-resolver', $datos, $this->headers_de_claude())->assertStatus(202)->assertJsonPath('estado', 'encolada');

        $consultas = Http::recorded(function ($request) {
            return strpos($request->url(), '/ai-excel-import/prueba-pendientes') !== false;
        });
        $this->assertCount(1, $consultas);
        parse_str(parse_url($consultas->first()[0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame((string) $this->ticket->id, $query['support_ticket_id']);
        $this->assertSame((string) $this->client->user_id, $query['user_id']);

        $resoluciones = Http::recorded(function ($request) {
            return strpos($request->url(), '/ai-excel-import/prueba-resolver') !== false;
        });
        $this->assertCount(1, $resoluciones);
        $pedido = $resoluciones->first()[0];
        $this->assertSame(['clave-del-cliente'], $pedido->header('X-Admin-Api-Key'));
        $this->assertSame([
            'revision' => 2, 'respuestas' => $respuestas,
            'user_id' => (int) $this->client->user_id, 'support_ticket_id' => (int) $this->ticket->id,
        ], $pedido->data());
    }

    /** @test */
    public function martin_mantiene_abierto_el_ticket_mientras_queda_un_archivo_procesando(): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['pendientes' => [], 'en_proceso' => true], 200),
        ]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);

        $agradecimiento = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Gracias',
        ]);
        $base = '/api/claude/support-codex/tickets/' . $this->ticket->id;
        $mensaje = (int) $agradecimiento->id;
        $reserva = $this->postJson($base . '/claim', ['worker_id' => 'martin', 'expected_message_id' => $mensaje], $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.importaciones_prueba_en_proceso', true)
            ->assertJsonPath('ticket.importaciones_prueba_pendientes', []);

        $this->postJson($base . '/reply', [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $mensaje,
            'operation_id' => 'cerrar-gracias', 'action' => 'cerrar', 'body' => 'De nada.',
        ], $this->headers_de_claude())->assertStatus(409)
            ->assertJsonPath('message', 'trial_import_pending: Hay una importación en curso en este ticket. Dejá abierta la conversación.');
        $this->assertSame('open', $this->ticket->fresh()->status);
        $this->assertDatabaseMissing('support_codex_operations', ['operation_id' => 'cerrar-gracias']);
    }

    /** @test */
    public function a_un_cliente_que_no_esta_en_prueba_le_contesta_404_y_no_le_pega_a_nadie(): void
    {
        $otro   = $this->crear_cliente('+5493417770003', false, 'otra-clave');
        $ticket = $this->ticket_del_cliente($otro);
        $adjunto = $this->adjuntar($ticket, 'soporte/otra.xlsx', 'application/vnd.ms-excel');

        $respuesta = $this->importar(['attachment_id' => $adjunto->id], $ticket);

        $respuesta->assertStatus(404);
        $this->assertSame('no_es_prueba', $respuesta->json('estado'));
        Http::assertNothingSent();
    }

    /** @test */
    public function un_adjunto_que_no_es_excel_o_de_otro_ticket_se_rechaza_sin_mandar(): void
    {
        $imagen = $this->adjuntar($this->ticket, 'soporte/foto.jpg', 'image/jpeg');
        $this->importar(['attachment_id' => $imagen->id])->assertStatus(422);

        $otro_ticket  = $this->ticket_del_cliente($this->client);
        $otro_adjunto = $this->adjuntar($otro_ticket, 'soporte/ajena.xlsx', 'application/vnd.ms-excel');
        $respuesta    = $this->importar(['attachment_id' => $otro_adjunto->id]);
        $respuesta->assertStatus(422);
        $this->assertSame('sin_fuente', $respuesta->json('estado'));

        $this->importar([])->assertStatus(422);

        Http::assertNothingSent();
    }

    /** @test */
    public function acepta_un_excel_en_base64_y_rechaza_otra_extension(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-pdf', 'excel_path' => 'imported_files/y.xlsx', 'estado' => 'pendiente'], 202)]);

        $ok = $this->importar(['file' => ['filename' => 'catalogo-normalizado.xlsx', 'content_base64' => base64_encode('xlsx')], 'origen' => 'pdf', 'provider_id' => 12]);
        $ok->assertStatus(200);
        $this->assertSame('aceptado', $ok->json('estado'));

        Http::assertSent(function ($request) {
            return $this->campo($request, 'origen') === 'pdf'
                && $this->campo($request, 'provider_id') === '12'
                && $request->hasFile('excel_file', 'xlsx', 'catalogo-normalizado.xlsx');
        });

        $this->importar(['file' => ['filename' => 'catalogo.txt', 'content_base64' => base64_encode('txt')]])->assertStatus(422);
        $this->importar(['file' => ['filename' => '../x.xlsx', 'content_base64' => base64_encode('x')]])->assertStatus(422);
    }

    /** @test */
    public function un_sistema_viejo_que_analiza_en_el_momento_se_reporta_sin_corrida(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['column_mapping' => ['A' => 'nombre'], 'provider_id' => null, 'provider_confidence' => 0, 'excel_path' => 'x', 'row_count' => 40], 200)]);

        $respuesta = $this->importar(['attachment_id' => $this->adjunto->id]);

        $respuesta->assertStatus(200);
        $this->assertSame('analizado_sin_corrida', $respuesta->json('estado'));
        $this->assertNull($respuesta->json('analysis_uuid'));
        $this->assertSame(40, $respuesta->json('cuerpo.row_count'));
    }

    /** @test */
    public function un_ticket_cerrado_contesta_409(): void
    {
        $this->ticket->status = 'closed';
        $this->ticket->save();

        $respuesta = $this->importar(['attachment_id' => $this->adjunto->id]);
        $respuesta->assertStatus(409);
        $this->assertSame('ticket_cerrado', $respuesta->json('estado'));
        Http::assertNothingSent();
    }

    /** @test */
    public function reenvia_las_imagenes_en_un_multipart_con_la_clave_del_cliente(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/imagenes' => Http::response(['asignadas' => 2, 'ya_tenian' => [], 'sin_articulo' => ['ZZZ'], 'rechazadas' => []], 200)]);

        $respuesta = $this->postJson('/api/claude/support-codex/tickets/' . $this->ticket->id . '/import-images', [
            'imagenes' => [
                ['filename' => 'ABC-1.jpg', 'content_base64' => base64_encode('jpg-1')],
                ['filename' => 'PRV-9.png', 'content_base64' => base64_encode('png-9')],
                ['filename' => 'malo.gif', 'content_base64' => base64_encode('gif')],
            ],
            'provider_id' => 12,
            'reemplazar'  => true,
        ], $this->headers_de_claude());

        $respuesta->assertStatus(200);
        $this->assertSame('aceptado', $respuesta->json('estado'));
        $this->assertSame(2, $respuesta->json('enviadas'));
        $this->assertSame(2, $respuesta->json('cuerpo.asignadas'));
        $this->assertCount(1, $respuesta->json('rechazadas_antes_de_enviar'));
        $this->assertStringNotContainsString('clave-del-cliente', (string) $respuesta->getContent());

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/admin-sync/ai-excel-import/imagenes') !== false
                && $request->header('X-Admin-Api-Key')[0] === 'clave-del-cliente'
                && $request->isMultipart()
                && $request->hasFile('imagenes[]', 'jpg-1', 'ABC-1.jpg')
                && $request->hasFile('imagenes[]', 'png-9', 'PRV-9.png')
                && ! $request->hasFile('imagenes[]', 'gif', 'malo.gif')
                && $this->campo($request, 'provider_id') === '12'
                && $this->campo($request, 'reemplazar') === '1';
        });
    }
}
