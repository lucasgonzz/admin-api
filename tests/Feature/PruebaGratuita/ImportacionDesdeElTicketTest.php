<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaCicloService;
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

    private function importar_stock(array $body)
    {
        return $this->postJson('/api/claude/support-codex/tickets/' . $this->ticket->id . '/import-excel-stock-existente',
            $body, $this->headers_de_claude());
    }

    private function pasar_a_activo(): void
    {
        $this->client->estado = PruebaCicloService::CLIENTE_ACTIVO;
        $this->client->save();
        SupportMessage::whereKey($this->adjunto->support_message_id)
            ->update(['whatsapp_remitente_crudo' => ltrim((string) $this->client->phone, '+')]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
    }

    private function reservar_turno(int $message_id): array
    {
        $reserva = $this->postJson('/api/claude/support-codex/tickets/' . $this->ticket->id . '/claim', [
            'worker_id' => 'martin',
            'expected_message_id' => $message_id,
        ], $this->headers_de_claude())->assertOk()->assertJsonPath('ticket.capabilities.soporte_import', true)
            ->assertJsonPath('ticket.capabilities.soporte_import_stock_existente', true);

        return ['lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $message_id];
    }

    /** @test */
    public function manda_el_excel_del_dueno_activo_solo_si_la_instancia_admite_el_modo_soporte(): void
    {
        $this->pasar_a_activo();
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['automatizar_soporte' => true], 200),
            '*/admin-sync/ai-excel-import/analyze' => Http::response([
                'analysis_uuid' => 'uuid-de-soporte', 'automatizar_soporte' => true,
            ], 202),
        ]);

        $reserva = $this->reservar_turno((int) $this->adjunto->support_message_id);
        $this->importar(['attachment_id' => $this->adjunto->id])->assertStatus(404)->assertJsonPath('estado', 'no_es_prueba');
        $this->importar(['attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true])->assertStatus(422);
        $otro = $this->adjuntar($this->ticket_del_cliente($this->client), 'soporte/otro.xlsx', 'application/vnd.ms-excel');
        $this->importar(['attachment_id' => $otro->id, 'automatizar_soporte' => true] + $reserva)
            ->assertStatus(422)->assertJsonPath('estado', 'sin_fuente');

        $this->importar(['attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('automatizar_soporte', true)
            ->assertJsonPath('automatizar_prueba', false);
        $this->assertNotNull($this->ticket->fresh()->importacion_soporte_iniciada_at);

        $consultas = Http::recorded(function ($request) {
            return strpos($request->url(), '/ai-excel-import/') !== false;
        });
        $this->assertCount(2, $consultas);
        $this->assertSame('GET', $consultas[0][0]->method());
        $this->assertStringContainsString('automatizar_soporte=1', $consultas[0][0]->url());
        $upload = $consultas[1][0];
        $this->assertSame('POST', $upload->method());
        $this->assertTrue($upload->hasFile('excel_file', 'contenido-del-excel'));
        $this->assertSame('1', $this->campo($upload, 'automatizar_soporte'));
        $this->assertNull($this->campo($upload, 'automatizar_prueba'));
        $this->assertSame((string) $this->ticket->id, $this->campo($upload, 'support_ticket_id'));
        $this->assertSame(hash('sha256', 'contenido-del-excel'), $this->campo($upload, 'importacion_clave'));
    }

    /** @test */
    public function una_instancia_anterior_que_ignora_la_bandera_no_recibe_el_archivo(): void
    {
        $this->pasar_a_activo();
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['en_proceso' => false], 200),
        ]);
        $reserva = $this->reservar_turno((int) $this->adjunto->support_message_id);

        $this->importar(['attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'version_vieja');
        $this->assertNull($this->ticket->fresh()->importacion_soporte_iniciada_at);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false;
        });
    }

    /** @test */
    public function un_excel_de_stock_solo_se_reenvia_si_empresa_anuncia_el_alcance(): void
    {
        $this->pasar_a_activo();
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['automatizar_soporte' => true], 200),
        ]);
        $reserva = $this->reservar_turno((int) $this->adjunto->support_message_id);

        $this->importar_stock([
            'attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true, 'alcance' => 'stock_existente',
        ] + $reserva)->assertOk()->assertJsonPath('estado', 'version_vieja');

        $this->assertNull($this->ticket->fresh()->importacion_soporte_iniciada_at);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false;
        });
    }

    /**
     * @test
     * @dataProvider respuestas_de_empresa_para_stock
     */
    public function usa_solo_la_ruta_de_stock_y_exige_el_alcance_en_la_respuesta(int $status, array $cuerpo, string $estado): void
    {
        $this->pasar_a_activo();
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response([
                'automatizar_soporte' => true, 'alcances_soporte' => ['stock_existente'],
            ], 200),
            '*/admin-sync/ai-excel-import/analyze-stock-existente' => Http::response($cuerpo, $status),
        ]);
        $reserva = $this->reservar_turno((int) $this->adjunto->support_message_id);

        $respuesta = $this->importar_stock([
            'attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true, 'alcance' => 'stock_existente',
        ] + $reserva)->assertOk()->assertJsonPath('estado', $estado);
        if ($estado === 'aceptado') {
            $respuesta->assertJsonPath('alcance', 'stock_existente')
                ->assertJsonPath('analysis_uuid', 'uuid-de-stock');
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && strpos($request->url(), '/ai-excel-import/analyze-stock-existente') !== false
                && $request->hasFile('excel_file', 'contenido-del-excel')
                && $this->campo($request, 'alcance') === 'stock_existente'
                && $this->campo($request, 'model') === 'article'
                && $this->campo($request, 'automatizar_soporte') === '1'
                && $this->campo($request, 'support_ticket_id') === (string) $this->ticket->id;
        });
        Http::assertNotSent(function ($request) {
            return substr($request->url(), -strlen('/ai-excel-import/analyze')) === '/ai-excel-import/analyze';
        });
    }

    public function respuestas_de_empresa_para_stock(): array
    {
        return [
            'acepta solo stock' => [202, [
                'automatizar_soporte' => true, 'alcance' => 'stock_existente', 'analysis_uuid' => 'uuid-de-stock',
            ], 'aceptado'],
            'falta la confirmación de alcance' => [202, [
                'automatizar_soporte' => true, 'analysis_uuid' => 'uuid-sin-alcance',
            ], 'version_vieja'],
            'la ruta específica no existe' => [404, [], 'version_vieja'],
        ];
    }

    /** @test */
    public function el_alcance_de_stock_no_pasa_por_la_ruta_general_ni_por_una_prueba(): void
    {
        $datos = ['attachment_id' => $this->adjunto->id, 'alcance' => 'stock_existente'];
        $this->importar($datos)->assertStatus(422);
        $this->importar_stock($datos)->assertStatus(422);
        $this->importar_stock($datos + [
            'automatizar_soporte' => true,
            'lease_token' => str_repeat('a', 64),
            'expected_message_id' => $this->adjunto->support_message_id,
        ])->assertForbidden()->assertJsonPath('estado', 'contacto_no_habilitado');
        Http::assertNothingSent();
    }

    /** @test */
    public function conserva_el_alcance_y_reenvia_el_codigo_que_el_dueno_aclaro_en_el_ticket(): void
    {
        $this->pasar_a_activo();
        $this->ticket->update(['importacion_soporte_iniciada_at' => now()]);
        $uuid = '9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70';
        $clave = 'stock_codigo_0_1002';
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response([
                'automatizar_soporte' => true, 'en_proceso' => true, 'pendientes' => [[
                    'analysis_uuid' => $uuid, 'revision' => 3, 'alcance' => 'stock_existente', 'preguntas' => [[
                        'clave' => $clave, 'acepta_codigo_articulo' => true,
                    ]],
                ]],
            ], 200),
            '*/admin-sync/ai-excel-import/prueba-resolver/' . $uuid => Http::response(['estado' => 'encolada'], 202),
        ]);
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'En la fila 1002 el código correcto es A9024 7.',
            'whatsapp_message_id' => 'wamid.codigo-del-dueno', 'delivered_at' => now(),
            'whatsapp_remitente_crudo' => ltrim((string) $this->client->phone, '+'),
        ]);
        $ruta = '/api/claude/support-codex/tickets/' . $this->ticket->id;
        $reserva = $this->postJson($ruta . '/claim', [
            'worker_id' => 'martin', 'expected_message_id' => $mensaje->id,
        ], $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.importaciones_soporte_pendientes.0.alcance', 'stock_existente')
            ->assertJsonPath('ticket.importaciones_soporte_pendientes.0.preguntas.0.acepta_codigo_articulo', true);

        $this->postJson($ruta . '/import-prueba-resolver', [
            'analysis_uuid' => $uuid, 'revision' => 3, 'respuestas' => [$clave => 'codigo:A9024 7'],
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $mensaje->id,
        ], $this->headers_de_claude())->assertStatus(202);

        Http::assertSent(function ($request) use ($uuid, $clave) {
            return strpos($request->url(), '/ai-excel-import/prueba-resolver/' . $uuid) !== false
                && $request['respuestas'] === [$clave => 'codigo:A9024 7']
                && $request['automatizar_soporte'] === '1';
        });
    }

    /** @test */
    public function no_reconoce_como_dueno_a_un_numero_de_otra_area_con_el_mismo_sufijo(): void
    {
        $this->pasar_a_activo();
        $this->ticket->update(['whatsapp_phone' => '+5493511234567']);
        $this->importar([
            'attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true,
            'lease_token' => str_repeat('a', 64), 'expected_message_id' => $this->adjunto->support_message_id,
        ])->assertForbidden()->assertJsonPath('estado', 'contacto_no_habilitado');
        Http::assertNothingSent();
    }

    /** @test */
    public function el_ticket_normalizado_no_alcanza_para_autenticar_el_excel(): void
    {
        $this->pasar_a_activo();
        SupportMessage::whereKey($this->adjunto->support_message_id)->update(['whatsapp_remitente_crudo' => null]);
        $reserva = $this->reservar_turno((int) $this->adjunto->support_message_id);
        $this->importar(['attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true] + $reserva)
            ->assertStatus(422)->assertJsonPath('estado', 'archivo_sin_remitente_verificado');
        Http::assertNothingSent();
    }

    /** @test */
    public function acredita_el_remitente_de_cada_mensaje_sin_exponer_el_telefono(): void
    {
        $this->pasar_a_activo();
        $mensaje_real = SupportMessage::findOrFail($this->adjunto->support_message_id);
        $sin_evidencia = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'No hay remitente original', 'whatsapp_message_id' => 'wamid.legado',
        ]);
        $otro_numero = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'Coincide solo el sufijo', 'whatsapp_message_id' => 'wamid.otro',
            'whatsapp_remitente_crudo' => '5493511234567',
        ]);
        $respuesta = $this->getJson('/api/claude/support-codex/tickets/' . $this->ticket->id, $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.capabilities.soporte_import', true);

        $mensajes = collect($respuesta->json('ticket.messages'))->keyBy('id');
        $this->assertTrue($mensajes[$mensaje_real->id]['remitente_dueno_verificado']);
        $this->assertFalse($mensajes[$sin_evidencia->id]['remitente_dueno_verificado']);
        $this->assertFalse($mensajes[$otro_numero->id]['remitente_dueno_verificado']);
        $this->assertStringNotContainsString('whatsapp_remitente_crudo', (string) $respuesta->getContent());
        $this->assertStringNotContainsString('5493511234567', (string) $respuesta->getContent());
    }

    /**
     * @test
     * @dataProvider confirmaciones_de_soporte
     */
    public function la_confirmacion_exige_el_codigo_del_aviso_enviado_y_la_respuesta_del_dueno(string $body, bool $enviado, int $http): void
    {
        $this->pasar_a_activo();
        $this->ticket->update(['importacion_soporte_iniciada_at' => now()]);
        $uuid = '9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70';
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response([
                'automatizar_soporte' => true, 'en_proceso' => true, 'pendientes' => [[
                    'analysis_uuid' => $uuid, 'revision' => 3, 'preguntas' => [[
                        'clave' => 'confirmacion', 'codigo_confirmacion' => 'ABCDEF12',
                    ]],
                ]],
            ], 200),
            '*/admin-sync/ai-excel-import/prueba-resolver/' . $uuid => Http::response(['estado' => 'encolada'], 202),
        ]);
        SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Para importar respondé CONFIRMAR ABCDEF12.', 'is_trial_import_notice' => true,
            'whatsapp_message_id' => $enviado ? 'wamid.aviso' : null, 'delivered_at' => now(),
        ]);
        $respuesta = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => $body, 'whatsapp_message_id' => 'wamid.dueno', 'delivered_at' => now(),
            'whatsapp_remitente_crudo' => ltrim((string) $this->client->phone, '+'),
        ]);
        $reserva = $this->reservar_turno((int) $respuesta->id);

        $this->postJson('/api/claude/support-codex/tickets/' . $this->ticket->id . '/import-prueba-resolver', [
            'analysis_uuid' => $uuid, 'revision' => 3, 'respuestas' => ['confirmacion' => 'confirmar'],
        ] + $reserva, $this->headers_de_claude())->assertStatus($http);
        $resoluciones = Http::recorded(function ($request) {
            return strpos($request->url(), '/ai-excel-import/prueba-resolver/') !== false;
        });
        $this->assertCount($http === 202 ? 1 : 0, $resoluciones);
        if ($http === 202) {
            $this->assertSame('1', $resoluciones->first()[0]['automatizar_soporte']);
        }
    }

    public function confirmaciones_de_soporte(): array
    {
        return [
            'código correcto' => ['CONFIRMAR ABCDEF12', true, 202],
            'respuesta ambigua' => ['Gracias, dale', true, 409],
            'código de otra revisión' => ['CONFIRMAR ABCDEF13', true, 409],
            'aviso sin envío confirmado' => ['CONFIRMAR ABCDEF12', false, 409],
        ];
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

    /** @test @dataProvider conversaciones_durante_la_carga */
    public function martin_deja_una_cortesia_aislada_al_aviso_de_la_importacion(
        string $texto, bool $recibio_excel, bool $otro_entrante, bool $cita, bool $monitor_nuevo, bool $en_proceso, bool $silencio,
        bool $mime_generico = false, bool $soporte_activo = false
    ): void {
        if ($soporte_activo) {
            $this->pasar_a_activo();
            $this->ticket->update(['importacion_soporte_iniciada_at' => now()]);
        }
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response([
                'en_proceso' => $en_proceso, 'automatizar_soporte' => $soporte_activo,
            ], 200),
        ]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
        if ($mime_generico) {
            $this->adjunto->update(['mime' => 'application/octet-stream']);
        }
        $hilo = ['support_ticket_id' => $this->ticket->id, 'kind' => 'text'];
        $anterior = $recibio_excel ? $this->adjunto->support_message_id : SupportMessage::create($hilo + [
            'sender_type' => 'user', 'body' => 'Cómo entro al sistema?',
        ])->id;
        $recibo = SupportMessage::create($hilo + ['sender_type' => 'admin', 'body' => $recibio_excel
            ? 'Recibí el Excel y ya está en preparación.' : 'Querés que te mande un enlace de ingreso?']);
        $case = SupportCodexCase::create([
            'support_ticket_id' => $this->ticket->id, 'state' => 'ready', 'last_reply_id' => $recibo->id,
            'handled_message_id' => $anterior,
        ]);
        if ($otro_entrante) {
            SupportMessage::create($hilo + ['sender_type' => 'user', 'body' => 'La columna de precio es costo.']);
        }
        $mensaje = SupportMessage::create($hilo + [
            'sender_type' => 'user', 'body' => $texto, 'reply_to_message_id' => $cita ? $recibo->id : null,
        ]);
        SupportMessage::create($hilo + [
            'sender_type' => 'admin', 'body' => 'En el Excel, el precio es de venta o es el costo?', 'is_trial_import_notice' => true,
        ]);
        $url = '/api/claude/support-codex/tickets/' . $this->ticket->id . '/claim';
        $datos = ['worker_id' => 'martin', 'expected_message_id' => $mensaje->id];
        if ($monitor_nuevo) {
            $datos['supports_trial_import_quiet'] = true;
        }

        $resultado = $this->postJson($url, $datos, $this->headers_de_claude())->assertOk();

        $case->refresh();
        $this->assertSame('open', $this->ticket->fresh()->status);
        $this->assertSame(2, SupportMessage::where('support_ticket_id', $this->ticket->id)->where('sender_type', 'admin')->count());
        if ($silencio) {
            $resultado->assertExactJson(['trial_import_quiet' => true]);
            $this->assertSame((int) $mensaje->id, (int) $case->handled_message_id);
            $this->assertNull($case->lease_expires_at);
            $this->postJson($url, $datos, $this->headers_de_claude())->assertStatus(409);
        } else {
            $resultado->assertJsonStructure(['lease_token'])->assertJsonPath('ticket.expected_message_id', (int) $mensaje->id);
            $this->assertSame((int) $anterior, (int) $case->handled_message_id);
        }
    }

    public function conversaciones_durante_la_carga(): array
    {
        return [
            'cortesía aislada' => ['Dale gracias', true, false, false, true, true, true],
            'Excel con MIME genérico' => ['Dale gracias', true, false, false, true, true, true, true],
            'respuesta y cortesía en dos mensajes' => ['Gracias', true, true, false, true, true, false],
            'otro tema' => ['Gracias, cómo cargo una venta?', true, false, false, true, true, false],
            'contestación a otra consulta' => ['Dale gracias', false, false, false, true, true, false],
            'mensaje citado' => ['Gracias', true, false, true, true, true, false],
            'monitor anterior' => ['Gracias', true, false, false, false, true, false],
            'sin carga pendiente' => ['Gracias', true, false, false, true, false, false],
            'activo con carga pendiente' => ['Gracias', true, false, false, true, true, true, false, true],
        ];
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

    /** @test @dataProvider clientes_con_importacion */
    public function martin_mantiene_abierto_el_ticket_mientras_queda_un_archivo_procesando(bool $soporte_activo): void
    {
        if ($soporte_activo) {
            $this->pasar_a_activo();
            $this->ticket->update(['importacion_soporte_iniciada_at' => now()]);
        }
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response([
                'pendientes' => [], 'en_proceso' => true, 'automatizar_soporte' => $soporte_activo,
            ], 200),
        ]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);

        $agradecimiento = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Gracias',
        ]);
        $base = '/api/claude/support-codex/tickets/' . $this->ticket->id;
        $mensaje = (int) $agradecimiento->id;
        $prefijo = $soporte_activo ? 'ticket.importaciones_soporte_' : 'ticket.importaciones_prueba_';
        $reserva = $this->postJson($base . '/claim', ['worker_id' => 'martin', 'expected_message_id' => $mensaje], $this->headers_de_claude())
            ->assertOk()->assertJsonPath($prefijo . 'en_proceso', true)
            ->assertJsonPath($prefijo . 'pendientes', []);

        $this->postJson($base . '/reply', [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $mensaje,
            'operation_id' => 'cerrar-gracias', 'action' => 'cerrar', 'body' => 'De nada.',
        ], $this->headers_de_claude())->assertStatus(409)
            ->assertJsonPath('message', 'trial_import_pending: Hay una importación en curso en este ticket. Dejá abierta la conversación.');
        $this->assertSame('open', $this->ticket->fresh()->status);
        $this->assertDatabaseMissing('support_codex_operations', ['operation_id' => 'cerrar-gracias']);
    }

    public function clientes_con_importacion(): array
    {
        return ['prueba' => [false], 'activo' => [true]];
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
