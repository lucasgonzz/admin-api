<?php

namespace Tests\Feature\ImportacionesDeMartin;

use App\Models\SupportMessage;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Http;

/**
 * A y B de la misión cruzada `importaciones-de-martin` (11/11/2026), del lado del admin:
 *
 *  A. 🔴 La planilla derivada del PDF de un cliente ACTIVO entra por `import-excel` con `file` + `pdf_attachment_id`
 *     SOLO si el PDF es un adjunto entrante del dueño verificado en ESE ticket (uno de un empleado o de otro ticket
 *     no llega a la instancia). La clave es `sha256('pdf:'.id)` y el origen `pdf`, aunque Martín la suba en una
 *     atención posterior.
 *  B. El Excel de clientes y proveedores del dueño va al servicio de soporte de empresa
 *     (`importacion-soporte/{modelo}/iniciar`), no al importador general; la confirmación exige el
 *     `CONFIRMAR <código>` del dueño después del aviso; la reversión, la reserva. 404 de la instancia = `version_vieja`.
 *  Y las capacidades del contexto.
 */
class ExcelPdfClientesYProveedoresDeActivosTest extends BaseDeImportacionesDeMartin
{
    const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Las corridas de empresa se identifican por uuid. */
    const CORRIDA = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    const OTRA_CORRIDA = '9b2f1f40-1111-4a2b-8c3d-1234567890ab';
    const AJENA = '0f0e0d0c-2222-4b3a-9c8d-abcdefabcdef';

    private function importar(array $body)
    {
        return $this->postJson($this->ruta('import-excel'), $body, $this->headers_de_claude());
    }

    private function fakear_soporte_de_articulos(): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['automatizar_soporte' => true], 200),
            '*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-del-pdf', 'automatizar_soporte' => true], 202),
        ]);
    }

    /** Lo que contesta el servicio de soporte de empresa sobre una corrida (`{corrida: payload}`). */
    private function corrida(string $estado = 'confirmacion', bool $revertible = false, string $modelo = 'client'): array
    {
        return ['corrida' => [
            'corrida_id' => self::CORRIDA,
            'modelo' => $modelo,
            'estado' => $estado,
            'resumen' => ['crear' => 3, 'actualizar' => 1, 'saldos' => [['nombre' => 'Juan', 'saldo_actual' => 0, 'saldo_resultante' => 1500, 'nota' => 'debito']]],
            'codigo_requerido' => $estado === 'confirmacion',
            'codigo_confirmacion' => $estado === 'confirmacion' ? 'ABCD1234' : null,
            'texto_confirmacion' => $estado === 'confirmacion' ? 'Respondé CONFIRMAR ABCD1234.' : null,
            'revertible' => $revertible,
        ], 'corrida_existente' => false];
    }

    private function no_llego_al_analisis(): void
    {
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false;
        });
    }

    /* ------------------------------------------------------------------------------------- Capacidades */

    /** @test */
    public function el_contexto_anuncia_las_tres_capacidades_nuevas_solo_para_el_dueno_activo(): void
    {
        $this->fakear_http();
        $this->reservar();
        $contexto = $this->getJson($this->ruta(''), $this->headers_de_claude())->assertOk();
        $contexto->assertJsonPath('ticket.capabilities.soporte_import_pdf', true)
            ->assertJsonPath('ticket.capabilities.soporte_import_clientes', true)
            ->assertJsonPath('ticket.capabilities.soporte_import_web', true);

        $this->client->estado = PruebaCicloService::CLIENTE_PRUEBA;
        $this->client->save();
        $this->getJson($this->ruta(''), $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.capabilities.soporte_import_pdf', false)
            ->assertJsonPath('ticket.capabilities.soporte_import_clientes', false)
            ->assertJsonPath('ticket.capabilities.soporte_import_web', false);
    }

    /* ------------------------------------------------------------------------------------- A. PDF */

    /** @test */
    public function la_planilla_del_pdf_del_dueno_entra_aunque_martin_la_suba_en_otra_atencion(): void
    {
        $this->fakear_soporte_de_articulos();
        $pdf = $this->adjuntar($this->ticket, 'soporte/catalogo.pdf', 'application/pdf');
        // Otra atención: el último mensaje ya no es el del PDF ni está acreditado como del dueño.
        $this->entrante($this->ticket, '¿Ya está?', false);
        $reserva = $this->reservar();

        $this->importar([
            'file' => ['filename' => 'catalogo-normalizado.xlsx', 'content_base64' => base64_encode('xlsx-derivado')],
            'pdf_attachment_id' => $pdf->id, 'automatizar_soporte' => true, 'origen' => 'martin',
        ] + $reserva)->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('automatizar_soporte', true);

        Http::assertSent(function ($request) use ($pdf) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false
                && $request->hasFile('excel_file', 'xlsx-derivado')
                && $this->campo($request, 'importacion_clave') === hash('sha256', 'pdf:' . $pdf->id)
                && $this->campo($request, 'origen') === 'pdf'
                && $this->campo($request, 'automatizar_soporte') === '1'
                && $this->campo($request, 'model') === 'article';
        });
    }

    /** @test */
    public function el_pdf_de_un_empleado_o_de_otro_ticket_no_llega_a_la_instancia(): void
    {
        $this->fakear_soporte_de_articulos();
        $de_empleado = $this->entrante($this->ticket, 'te paso el catálogo', false, '+5493419999999');
        $pdf_ajeno = $this->adjuntar($this->ticket, 'soporte/del-empleado.pdf', 'application/pdf', $de_empleado);
        $otro_ticket = $this->ticket_del_cliente($this->client);
        $this->acreditar($this->ultimo_mensaje($otro_ticket));
        $pdf_de_otro = $this->adjuntar($otro_ticket, 'soporte/otro.pdf', 'application/pdf');
        $reserva = $this->reservar();
        $planilla = ['filename' => 'catalogo-normalizado.xlsx', 'content_base64' => base64_encode('xlsx')];

        $this->importar(['file' => $planilla, 'pdf_attachment_id' => $pdf_ajeno->id, 'automatizar_soporte' => true] + $reserva)
            ->assertStatus(422)->assertJsonPath('estado', 'archivo_sin_remitente_verificado');
        $this->importar(['file' => $planilla, 'pdf_attachment_id' => $pdf_de_otro->id, 'automatizar_soporte' => true] + $reserva)
            ->assertStatus(422)->assertJsonPath('estado', 'sin_fuente');
        // La planilla del PDF es solo de artículos: con el PDF del dueño, pero para clientes, no entra.
        $pdf = $this->adjuntar($this->ticket, 'soporte/bueno.pdf', 'application/pdf', SupportMessage::where('support_ticket_id', $this->ticket->id)->orderBy('id')->first());
        $this->importar(['file' => $planilla, 'pdf_attachment_id' => $pdf->id, 'model' => 'client', 'automatizar_soporte' => true] + $reserva)
            ->assertStatus(422)->assertJsonPath('estado', 'archivo_invalido');

        $this->no_llego_al_analisis();
    }

    /* ------------------------------------------------------------------------------------- B. Clientes y proveedores */

    /**
     * @test
     * @dataProvider modelos
     */
    public function el_excel_de_clientes_o_proveedores_va_al_servicio_de_soporte_con_su_resumen(string $modelo): void
    {
        $this->fakear_http([
            '*/admin-sync/importacion-soporte/' . $modelo . '/iniciar' => Http::response($this->corrida('confirmacion', false, $modelo), 202),
        ]);
        $excel = $this->adjuntar($this->ticket, 'soporte/' . $modelo . '.xlsx', self::XLSX, null, 'excel-de-' . $modelo);
        $reserva = $this->reservar();

        $this->importar(['attachment_id' => $excel->id, 'model' => $modelo, 'automatizar_soporte' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('modelo', $modelo)
            ->assertJsonPath('corrida_id', self::CORRIDA)->assertJsonPath('estado_corrida', 'confirmacion')
            ->assertJsonPath('resumen.crear', 3)->assertJsonPath('resumen.saldos.0.saldo_resultante', 1500)
            ->assertJsonPath('codigo_requerido', true)->assertJsonPath('codigo', 'ABCD1234')->assertJsonPath('revertible', false)
            ->assertJsonPath('texto_confirmacion', 'Respondé CONFIRMAR ABCD1234.')->assertJsonPath('corrida_existente', false);

        $this->assertNotNull($this->ticket->fresh()->importacion_soporte_iniciada_at);
        Http::assertSent(function ($request) use ($modelo) {
            return strpos($request->url(), '/api/admin-sync/importacion-soporte/' . $modelo . '/iniciar') !== false
                && $request->method() === 'POST'
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $request->hasFile('excel_file', 'excel-de-' . $modelo)
                && $this->campo($request, 'user_id') === (string) $this->client->user_id
                && $this->campo($request, 'support_ticket_id') === (string) $this->ticket->id
                && $this->campo($request, 'importacion_clave') === hash('sha256', 'excel-de-' . $modelo);
        });
        $this->no_llego_al_analisis();
    }

    public function modelos(): array
    {
        return ['clientes' => ['client'], 'proveedores' => ['provider']];
    }

    /** @test */
    public function una_instancia_sin_el_servicio_de_soporte_es_version_vieja_y_no_deja_la_importacion_iniciada(): void
    {
        $this->fakear_http(['*/admin-sync/importacion-soporte/*' => Http::response(['message' => 'Not Found'], 404)]);
        $excel = $this->adjuntar($this->ticket, 'soporte/clientes.xlsx', self::XLSX);
        $reserva = $this->reservar();

        $this->importar(['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_soporte' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'version_vieja');
        $this->assertNull($this->ticket->fresh()->importacion_soporte_iniciada_at);
        $this->no_llego_al_analisis();
    }

    /** @test */
    public function el_excel_de_clientes_de_un_numero_que_no_es_el_del_dueno_no_se_reenvia(): void
    {
        $this->fakear_http(['*/admin-sync/importacion-soporte/*' => Http::response($this->corrida(), 202)]);
        $ajeno = $this->entrante($this->ticket, 'acá va', false, '+5493419999999');
        $excel = $this->adjuntar($this->ticket, 'soporte/clientes.xlsx', self::XLSX, $ajeno);
        $reserva = $this->reservar();

        $this->importar(['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_soporte' => true] + $reserva)
            ->assertStatus(422)->assertJsonPath('estado', 'archivo_sin_remitente_verificado');
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/importacion-soporte/') !== false;
        });
    }

    /** Un aviso del sistema con el código, enviado por el ticket antes de la respuesta del dueño. */
    private function aviso_con_codigo(string $codigo = 'ABCD1234', bool $enviado = true): void
    {
        SupportMessage::create([
            'support_ticket_id' => $this->ticket->id,
            'sender_type' => 'admin',
            'kind' => 'text',
            'body' => 'Voy a crear 3 clientes y actualizar 1. Para aprobar la carga respondé CONFIRMAR ' . $codigo
                . '. Para cancelarla, respondé CANCELAR ' . $codigo . '.',
            'delivered_at' => now(),
            'is_trial_import_notice' => true,
            'whatsapp_message_id' => $enviado ? 'wamid.aviso.1' : null,
        ]);
    }

    private function confirmar(array $reserva)
    {
        return $this->postJson($this->ruta('import-confirm'), ['modelo' => 'client', 'corrida_id' => self::CORRIDA] + $reserva, $this->headers_de_claude());
    }

    private function fakear_la_corrida(): void
    {
        $this->fakear_http([
            '*/admin-sync/importacion-soporte/' . self::CORRIDA . '/confirmar' => Http::response($this->corrida('importar'), 202),
            '*/admin-sync/importacion-soporte/' . self::CORRIDA . '?*' => Http::response($this->corrida(), 200),
        ]);
    }

    private function no_confirmo(): void
    {
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/confirmar') !== false;
        });
    }

    /** @test */
    public function confirma_con_el_codigo_que_escribio_el_dueno_despues_del_aviso(): void
    {
        $this->fakear_la_corrida();
        $this->aviso_con_codigo();
        $this->entrante($this->ticket, 'confirmar abcd1234');
        $reserva = $this->reservar();

        $this->confirmar($reserva)->assertOk()->assertJsonPath('estado', 'aceptado')
            ->assertJsonPath('estado_corrida', 'importar');
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/importacion-soporte/' . self::CORRIDA . '/confirmar') !== false
                && $request['codigo'] === 'ABCD1234' && $request['modelo'] === 'client' && $request['decision'] === 'confirmar'
                && (int) $request['support_ticket_id'] === (int) $this->ticket->id;
        });
    }

    /** @test */
    public function cancela_con_el_cancelar_del_dueno_y_no_con_un_confirmar_si_se_pidio_cancelar(): void
    {
        $this->fakear_la_corrida();
        $this->aviso_con_codigo();
        $this->entrante($this->ticket, 'CONFIRMAR ABCD1234');
        $reserva = $this->reservar();
        $this->confirmar($reserva + ['decision' => 'cancelar'])->assertStatus(409)->assertJsonPath('estado', 'confirmacion_invalida');
        $this->no_confirmo();

        $this->entrante($this->ticket, 'CANCELAR ABCD1234');
        \App\Models\SupportCodexCase::where('support_ticket_id', $this->ticket->id)->update(['lease_expires_at' => now()->subMinute()]);
        $reserva = $this->reservar();
        $this->confirmar($reserva + ['decision' => 'cancelar'])->assertOk()->assertJsonPath('estado', 'aceptado');
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/confirmar') !== false && $request['decision'] === 'cancelar' && $request['codigo'] === 'ABCD1234';
        });
    }

    /**
     * @test
     * @dataProvider confirmaciones_invalidas
     */
    public function no_confirma_sin_el_codigo_vigente_del_dueno_despues_del_aviso(string $body, bool $hay_aviso, bool $del_dueno): void
    {
        $this->fakear_la_corrida();
        if ($hay_aviso) {
            $this->aviso_con_codigo();
        }
        $this->entrante($this->ticket, $body, $del_dueno);
        $reserva = $this->reservar();

        $this->confirmar($reserva)->assertStatus(409)->assertJsonPath('estado', 'confirmacion_invalida')
            ->assertJsonPath('motivo', 'confirmacion_invalida');
        $this->no_confirmo();
    }

    public function confirmaciones_invalidas(): array
    {
        return [
            'otro código' => ['CONFIRMAR ZZZZ9999', true, true],
            'sin aviso enviado' => ['CONFIRMAR ABCD1234', false, true],
            'no es el dueño' => ['CONFIRMAR ABCD1234', true, false],
            'texto de más' => ['dale, CONFIRMAR ABCD1234', true, true],
        ];
    }

    /** @test */
    public function confirmar_exige_la_reserva_y_el_mensaje_vigente(): void
    {
        $this->fakear_la_corrida();
        $this->aviso_con_codigo();
        $this->entrante($this->ticket, 'CONFIRMAR ABCD1234');
        $reserva = $this->reservar();

        $this->confirmar(['lease_token' => str_repeat('x', 64)] + $reserva)->assertStatus(423)->assertJsonPath('motivo', 'invalid_lease');
        $this->entrante($this->ticket, 'otra cosa');
        $this->confirmar($reserva)->assertStatus(409)->assertJsonPath('motivo', 'stale_message');
        $this->no_confirmo();
    }

    /** @test */
    public function revierte_con_la_reserva_y_un_sistema_viejo_es_version_vieja(): void
    {
        $this->fakear_http([
            '*/admin-sync/importacion-soporte/' . self::CORRIDA . '/revertir' => Http::response($this->corrida('revertida', false), 200),
            '*/admin-sync/importacion-soporte/' . self::OTRA_CORRIDA . '/revertir' => Http::response(['message' => 'Not Found'], 404),
            '*/admin-sync/importacion-soporte/' . self::AJENA . '/revertir' => Http::response(['message' => 'No se encontró.', 'motivo' => 'corrida_no_encontrada'], 404),
        ]);
        $reserva = $this->reservar();
        $revertir = function (array $body) {
            return $this->postJson($this->ruta('import-revert'), $body, $this->headers_de_claude());
        };

        $revertir(['lease_token' => str_repeat('x', 64), 'modelo' => 'client', 'corrida_id' => self::CORRIDA])
            ->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
        $revertir(['lease_token' => $reserva['lease_token'], 'modelo' => 'otro', 'corrida_id' => self::CORRIDA])
            ->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos');
        $revertir(['lease_token' => $reserva['lease_token'], 'modelo' => 'provider', 'corrida_id' => self::CORRIDA])
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('estado_corrida', 'revertida');
        $revertir(['lease_token' => $reserva['lease_token'], 'modelo' => 'client', 'corrida_id' => self::OTRA_CORRIDA])
            ->assertOk()->assertJsonPath('estado', 'version_vieja');
        // Un 404 CON motivo es de una instancia que tiene la ruta: la corrida no es de este dueño y ticket.
        $revertir(['lease_token' => $reserva['lease_token'], 'modelo' => 'client', 'corrida_id' => self::AJENA])
            ->assertOk()->assertJsonPath('estado', 'rechazado')->assertJsonPath('motivo_instancia', 'corrida_no_encontrada');

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/importacion-soporte/' . self::CORRIDA . '/revertir') !== false
                && $request['modelo'] === 'provider' && (int) $request['user_id'] === (int) $this->client->user_id;
        });
    }

    /**
     * 🔴 Con una corrida de clientes o proveedores abierta, `prueba-pendientes` de empresa trae
     * `importaciones_de_contactos` y `en_proceso: true`: Martín no puede cerrar el ticket a mitad de camino.
     *
     * @test
     */
    public function una_corrida_de_contactos_abierta_no_deja_cerrar_el_ticket(): void
    {
        $this->ticket->update(['importacion_soporte_iniciada_at' => now()]);
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response([
                'automatizar_soporte' => true, 'pendientes' => [], 'en_proceso' => true,
                'importaciones_de_contactos' => [['corrida_id' => self::CORRIDA, 'modelo' => 'client', 'estado' => 'confirmacion',
                    'revision' => 1, 'codigo_requerido' => true]],
            ], 200),
        ]);
        $gracias = $this->entrante($this->ticket, 'Gracias');
        $reserva = $this->reservar();

        $this->postJson($this->ruta('reply'), [
            'lease_token' => $reserva['lease_token'], 'expected_message_id' => (int) $gracias->id,
            'operation_id' => 'cerrar-con-contactos', 'action' => 'cerrar', 'body' => 'De nada.',
        ], $this->headers_de_claude())->assertStatus(409)
            ->assertJsonPath('message', 'trial_import_pending: Hay una importación en curso en este ticket. Dejá abierta la conversación.');
        $this->assertSame('open', $this->ticket->fresh()->status);
        $this->assertDatabaseMissing('support_codex_operations', ['operation_id' => 'cerrar-con-contactos']);
    }

    /** @test */
    public function el_estado_de_la_corrida_se_lee_con_la_reserva(): void
    {
        $this->fakear_la_corrida();
        $reserva = $this->reservar();

        $this->getJson($this->ruta('import-status') . '?modelo=client&corrida_id=' . self::CORRIDA, $this->headers_de_claude())
            ->assertStatus(423);
        $this->getJson($this->ruta('import-status') . '?modelo=client&corrida_id=' . self::CORRIDA,
            $this->headers_de_claude() + ['X-Support-Lease-Token' => $reserva['lease_token']])
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('resumen.actualizar', 1)
            ->assertJsonPath('codigo', 'ABCD1234');
    }
}
