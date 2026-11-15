<?php

namespace Tests\Feature\ImportacionesDeMartin;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteAvisosService;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ronda 8 (misión prueba-e2e-campana-imperio, 15/11/2026), lado del admin. Contrato:
 * `_cruzado/misiones/20261111-prueba-e2e-campana-imperio/contrato-ronda-8.md` §1, §2 y §4.2.
 *
 * Lo que protege:
 *  1. D1: el Excel de clientes o proveedores del dueño de un negocio EN PRUEBA (`automatizar_prueba`) va al motor de
 *     soporte de empresa con `modo_prueba=1`, sin código y sin marcar `importacion_soporte_iniciada_at`.
 *  2. 🔴 Compatibilidad: sin `contactos` en `capacidades_prueba` no se reenvía nada; un empresa anterior que contesta
 *     404 sin motivo, `409 dueno_no_activo` o una corrida sin `modo_prueba` es `version_vieja`.
 *  3. La puerta: ticket abierto, en prueba, del dueño, con la reserva vigente; los artículos no entran por esta rama.
 *  4. `import-status` e `import-revert` se abren a la prueba; `import-confirm` no.
 *  5. D2: `margen_de_ganancia` se valida, viaja solo en la prueba de artículos con automatización y vuelve
 *     `margen_previo`.
 *  6. El aviso del resultado llega por el canal del ticket (sistema, ensayo o WhatsApp) y el ticket no se cierra con
 *     una carga de contactos abierta.
 */
class ContactosDeLaPruebaTest extends BaseDeImportacionesDeMartin
{
    const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    const CORRIDA = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    /** @var SupportTicket La conversación de Soporte del sistema del dueño (canal `erp`). */
    private $sistema;

    protected function setUp(): void
    {
        parent::setUp();

        // La base arma un activo: acá es un negocio en prueba vigente.
        $this->fijar_en_prueba($this->client);
        $this->sistema = $this->ticket_del_sistema();
    }

    /* ------------------------------------------------------------------------------------- Armado */

    private function ticket_del_sistema(array $extra = []): SupportTicket
    {
        $ticket = SupportTicket::create(array_merge([
            'client_id' => $this->client->id, 'client_user_id' => (int) $this->client->user_id, 'client_user_name' => 'Marcela',
            'name' => 'Te mando mis clientes', 'status' => 'open', 'source' => 'erp', 'agentes_erp' => true, 'opened_at' => now(),
        ], $extra));
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'file',
            'body' => 'Te mando la lista de clientes', 'delivered_at' => now(),
        ]);
        $ticket->last_client_message_at = $mensaje->delivered_at;
        $ticket->save();

        return $ticket;
    }

    private function canal(string $canal): SupportTicket
    {
        return $canal === 'whatsapp' ? $this->ticket : $this->sistema;
    }

    private function pendientes(array $capacidades = ['margen_previo', 'contactos'], array $extra = []): array
    {
        return array_merge(['pendientes' => [], 'en_proceso' => false], $capacidades === [] ? [] : ['capacidades_prueba' => $capacidades], $extra);
    }

    private function corrida(string $modelo = 'client', bool $modo_prueba = true, string $estado = 'analizar'): array
    {
        return ['corrida' => [
            'corrida_id' => self::CORRIDA, 'modelo' => $modelo, 'estado' => $estado, 'motivo' => null,
            'archivo' => 'clientes cuenta corriente nov.xlsx', 'support_ticket_id' => (int) $this->sistema->id,
            'revision' => 0, 'resumen' => null, 'codigo_requerido' => false, 'codigo_confirmacion' => null,
            'texto_confirmacion' => null, 'resultado' => null, 'error' => null, 'revertible' => $estado === 'terminada',
            'tandas' => ['total' => 0, 'hechas' => 0, 'revertidas' => 0], 'confirmada_at' => null,
            'terminada_at' => null, 'revertida_at' => null,
        ] + ($modo_prueba ? ['modo_prueba' => true] : []), 'corrida_existente' => false];
    }

    /**
     * @param array<string, mixed>|null $iniciar Respuesta de `iniciar` (null = la corrida de la prueba con 202).
     */
    private function fakear(array $pendientes, ?\GuzzleHttp\Promise\PromiseInterface $iniciar = null, string $modelo = 'client'): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response($pendientes, 200),
            '*/admin-sync/importacion-soporte/' . $modelo . '/iniciar' => $iniciar !== null ? $iniciar : Http::response($this->corrida($modelo), 202),
        ]);
    }

    private function excel(SupportTicket $ticket, string $contenido = 'excel-de-clientes')
    {
        return $this->adjuntar($ticket, 'soporte/' . Str::random(6) . '.xlsx', self::XLSX, null, $contenido);
    }

    private function importar(SupportTicket $ticket, array $body)
    {
        return $this->postJson($this->ruta('import-excel', $ticket), $body, $this->headers_de_claude());
    }

    private function no_inicio(): void
    {
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/importacion-soporte/') !== false;
        });
    }

    private function no_analizo(): void
    {
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false;
        });
    }

    /* ------------------------------------------------------------------------------------- 1. El reenvío */

    /**
     * @test
     * @dataProvider modelos_y_canales
     */
    public function el_excel_de_contactos_de_la_prueba_va_al_motor_de_soporte_en_modo_prueba(string $modelo, string $canal): void
    {
        $ticket = $this->canal($canal);
        $this->fakear($this->pendientes(), null, $modelo);
        $excel = $this->excel($ticket, 'excel-de-' . $modelo);
        $reserva = $this->reservar($ticket);

        $respuesta = $this->importar($ticket, ['attachment_id' => $excel->id, 'model' => $modelo, 'origen' => 'martin', 'automatizar_prueba' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('http', 202)->assertJsonPath('modelo', $modelo)
            ->assertJsonPath('corrida_id', self::CORRIDA)->assertJsonPath('estado_corrida', 'analizar')
            ->assertJsonPath('codigo_requerido', false)->assertJsonPath('codigo', null)
            ->assertJsonPath('automatizar_prueba', true)->assertJsonPath('modo_prueba', true)
            ->assertJsonPath('corrida.modo_prueba', true)->assertJsonPath('corrida_existente', false)
            ->assertJsonPath('filename', 'adjunto-' . $excel->id . '.xlsx');
        $this->assertArrayNotHasKey('automatizar_soporte', $respuesta->json(), 'El helper no tiene que entrar a la rama de activos.');

        $this->assertNull($ticket->fresh()->importacion_soporte_iniciada_at, 'La prueba no marca la importación de soporte de activos.');
        Http::assertSent(function ($request) use ($modelo, $ticket) {
            return strpos($request->url(), '/api/admin-sync/importacion-soporte/' . $modelo . '/iniciar') !== false
                && $request->method() === 'POST'
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $request->hasFile('excel_file', 'excel-de-' . $modelo)
                && $this->campo($request, 'modo_prueba') === '1'
                && $this->campo($request, 'user_id') === (string) $this->client->user_id
                && $this->campo($request, 'support_ticket_id') === (string) $ticket->id
                && $this->campo($request, 'importacion_clave') === hash('sha256', 'excel-de-' . $modelo)
                && $this->campo($request, 'avisar_por_whatsapp') === '1'
                && $this->campo($request, 'automatizar_soporte') === null;
        });
        $this->no_analizo();
    }

    public function modelos_y_canales(): array
    {
        return [
            'clientes por el sistema' => ['client', 'sistema'],
            'proveedores por el sistema' => ['provider', 'sistema'],
            'clientes por WhatsApp' => ['client', 'whatsapp'],
        ];
    }

    /* ------------------------------------------------------------------------------------- 2 y 3. Compatibilidad */

    /**
     * @test
     * @dataProvider sistemas_sin_la_capacidad
     */
    public function sin_la_capacidad_contactos_es_version_vieja_y_no_se_reenvia_nada(array $pendientes): void
    {
        $this->fakear($pendientes);
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'version_vieja')->assertJsonPath('disponible', false);
        $this->no_inicio();
        $this->no_analizo();
    }

    public function sistemas_sin_la_capacidad(): array
    {
        return [
            '4.3.56: sin capacidades_prueba' => [['pendientes' => [], 'en_proceso' => false]],
            'solo margen_previo' => [['pendientes' => [], 'en_proceso' => false, 'capacidades_prueba' => ['margen_previo']]],
            'capacidades mal formadas' => [['pendientes' => [], 'en_proceso' => false, 'capacidades_prueba' => 'contactos']],
        ];
    }

    /** @test */
    public function si_no_se_puede_confirmar_la_capacidad_no_responde_y_no_se_reenvia_nada(): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['message' => 'Server Error'], 500),
        ]);
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true] + $reserva)
            ->assertStatus(502)->assertJsonPath('estado', 'no_responde');
        $this->no_inicio();
    }

    /**
     * `no_responde` es solo lo transitorio; `sin_api_key` y `sin_url` salen como en la carga de artículos de la prueba
     * (409), y otro status del sistema es `rechazado`. En ningún caso se reenvía el Excel.
     *
     * @test
     * @dataProvider causas_de_la_consulta
     */
    public function cada_causa_de_no_poder_consultar_la_capacidad_sale_como_lo_que_es(string $causa, int $http, string $estado): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response($this->pendientes(), 200)]);
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);

        if ($causa === 'sin_api_key') {
            $this->client->api_key = '';
            $this->client->save();
        } elseif ($causa === 'sin_url') {
            $this->client->api_url = '';
            $this->client->save();
            $this->client->client_apis()->delete();
        } elseif ($causa === 'sin_conexion') {
            $this->fakear_http(['*/admin-sync/ai-excel-import/prueba-pendientes*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout');
            }]);
        } else {
            $this->fakear_http(['*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(
                ['message' => 'No.', 'motivo' => 'otra_cosa'], $causa === 'status_409' ? 409 : 503)]);
        }

        $respuesta = $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true] + $reserva)
            ->assertStatus($http)->assertJsonPath('estado', $estado);
        if ($causa === 'status_409') {
            $respuesta->assertJsonPath('http', 409)->assertJsonPath('motivo_instancia', 'otra_cosa');
        }
        $this->no_inicio();
    }

    public function causas_de_la_consulta(): array
    {
        return [
            'sin api_key' => ['sin_api_key', 409, 'sin_api_key'],
            'sin url' => ['sin_url', 409, 'sin_url'],
            'sin conexión' => ['sin_conexion', 502, 'no_responde'],
            '5xx del sistema' => ['status_503', 502, 'no_responde'],
            'un 409 del sistema' => ['status_409', 200, 'rechazado'],
        ];
    }

    /**
     * @test
     * @dataProvider empresas_viejas
     */
    public function un_empresa_viejo_que_recibe_modo_prueba_es_version_vieja(int $status, array $cuerpo): void
    {
        $this->fakear($this->pendientes(), Http::response($cuerpo, $status));
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'version_vieja')->assertJsonPath('disponible', false);
        $this->assertNull($this->sistema->fresh()->importacion_soporte_iniciada_at);
    }

    public function empresas_viejas(): array
    {
        return [
            '404 sin motivo (no tiene la ruta)' => [404, ['message' => 'Not Found']],
            '409 dueno_no_activo' => [409, ['message' => 'El dueño no es activo.', 'motivo' => 'dueno_no_activo']],
            '202 sin modo_prueba' => [202, ['corrida' => ['corrida_id' => self::CORRIDA, 'modelo' => 'client', 'estado' => 'analizar',
                'codigo_requerido' => false], 'corrida_existente' => false]],
            '202 con modo_prueba en false' => [202, ['corrida' => ['corrida_id' => self::CORRIDA, 'modelo' => 'client', 'estado' => 'analizar',
                'codigo_requerido' => false, 'modo_prueba' => false], 'corrida_existente' => false]],
        ];
    }

    /** @test */
    public function el_rechazo_dueno_no_en_prueba_pasa_tal_cual(): void
    {
        $this->fakear($this->pendientes(), Http::response(['message' => 'No está en prueba.', 'motivo' => 'dueno_no_en_prueba'], 409));
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'rechazado')->assertJsonPath('http', 409)
            ->assertJsonPath('motivo_instancia', 'dueno_no_en_prueba');
    }

    /* ------------------------------------------------------------------------------------- 4. La puerta */

    /** @test */
    public function la_rama_de_la_prueba_exige_la_reserva_vigente(): void
    {
        $this->fakear($this->pendientes());
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);
        $pedido = ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true];

        $vencida = $this->importar($this->sistema, ['lease_token' => str_repeat('x', 64)] + $reserva + $pedido)->assertStatus(423);
        $this->assertStringStartsWith('invalid_lease', (string) $vencida->json('message'));
        $this->importar($this->sistema, $pedido)->assertStatus(422);
        $this->entrante($this->sistema, 'otra cosa');
        $vieja = $this->importar($this->sistema, $reserva + $pedido)->assertStatus(409);
        $this->assertStringStartsWith('stale_message', (string) $vieja->json('message'));
        $this->no_inicio();
    }

    /**
     * @test
     * @dataProvider tickets_sin_la_prueba
     */
    public function solo_el_dueno_de_una_prueba_vigente_con_el_ticket_abierto(string $caso, int $http, string $estado): void
    {
        $this->fakear($this->pendientes());
        $ticket = $this->sistema;
        if ($caso === 'empleado') {
            $ticket = $this->ticket_del_sistema(['client_employee_id' => 77]);
        } elseif ($caso === 'otro_usuario') {
            $ticket = $this->ticket_del_sistema(['client_user_id' => (int) $this->client->user_id + 1]);
        } elseif ($caso === 'activo') {
            $this->client->estado = PruebaCicloService::CLIENTE_ACTIVO;
            $this->client->save();
        } elseif ($caso === 'cerrado') {
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        } elseif ($caso === 'vencida') {
            $this->fijar_en_prueba($this->client, -1);
        }
        $excel = $this->excel($ticket);

        $this->importar($ticket, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_prueba' => true,
            'lease_token' => str_repeat('a', 64), 'expected_message_id' => (int) $this->ultimo_mensaje($ticket)->id])
            ->assertStatus($http)->assertJsonPath('estado', $estado);
        $this->no_inicio();
    }

    public function tickets_sin_la_prueba(): array
    {
        return [
            'un empleado' => ['empleado', 403, 'contacto_no_habilitado'],
            'otro usuario del sistema' => ['otro_usuario', 403, 'contacto_no_habilitado'],
            'la prueba vencida' => ['vencida', 403, 'contacto_no_habilitado'],
            'un cliente activo' => ['activo', 404, 'no_es_prueba'],
            'el ticket cerrado' => ['cerrado', 409, 'ticket_cerrado'],
        ];
    }

    /**
     * @test
     * @dataProvider combinaciones_invalidas
     */
    public function combinaciones_que_la_rama_de_la_prueba_no_admite_son_422(array $pedido): void
    {
        $this->fakear($this->pendientes());
        $excel = $this->excel($this->sistema);
        $reserva = $this->reservar($this->sistema);

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'automatizar_prueba' => true] + $pedido + $reserva)->assertStatus(422);
        $this->no_inicio();
        $this->no_analizo();
    }

    public function combinaciones_invalidas(): array
    {
        return [
            'artículos' => [['model' => 'article']],
            'sin modelo (= artículos)' => [[]],
            'con automatizar_soporte' => [['model' => 'client', 'automatizar_soporte' => true]],
            'con la ganancia' => [['model' => 'client', 'margen_de_ganancia' => '40']],
        ];
    }

    /** @test */
    public function un_activo_con_automatizar_soporte_sigue_por_su_camino_con_codigo(): void
    {
        // Regresión: el activo de la base, con su ticket de WhatsApp, no cambia nada.
        $this->client->estado = PruebaCicloService::CLIENTE_ACTIVO;
        $this->client->save();
        $activo = $this->corrida('client', false, 'confirmacion');
        $activo['corrida']['codigo_requerido'] = true;
        $activo['corrida']['codigo_confirmacion'] = 'ABCD1234';
        $this->fakear_http(['*/admin-sync/importacion-soporte/client/iniciar' => Http::response($activo, 202)]);
        $excel = $this->adjuntar($this->ticket, 'soporte/clientes.xlsx', self::XLSX);
        $reserva = $this->reservar();

        $respuesta = $this->importar($this->ticket, ['attachment_id' => $excel->id, 'model' => 'client', 'automatizar_soporte' => true] + $reserva)
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('automatizar_soporte', true)
            ->assertJsonPath('codigo', 'ABCD1234');
        $this->assertArrayNotHasKey('modo_prueba', $respuesta->json());
        $this->assertArrayNotHasKey('automatizar_prueba', $respuesta->json());
        $this->assertNotNull($this->ticket->fresh()->importacion_soporte_iniciada_at);
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/importacion-soporte/client/iniciar') !== false && $this->campo($request, 'modo_prueba') === null;
        });
    }

    /* ------------------------------------------------------------------------------------- 5. Estado, deshacer y confirmar */

    /** @test */
    public function en_la_prueba_se_lee_el_estado_y_se_deshace_pero_no_se_confirma(): void
    {
        $terminada = $this->corrida('client', true, 'terminada');
        $revirtiendo = $this->corrida('client', true, 'revirtiendo');
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response($this->pendientes(), 200),
            '*/admin-sync/importacion-soporte/' . self::CORRIDA . '/revertir' => Http::response($revirtiendo, 202),
            '*/admin-sync/importacion-soporte/' . self::CORRIDA . '?*' => Http::response($terminada, 200),
        ]);
        $reserva = $this->reservar($this->sistema);

        $this->getJson($this->ruta('import-status', $this->sistema) . '?modelo=client&corrida_id=' . self::CORRIDA,
            $this->headers_de_claude() + ['X-Support-Lease-Token' => $reserva['lease_token']])
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('estado_corrida', 'terminada')
            ->assertJsonPath('corrida.modo_prueba', true);
        $this->postJson($this->ruta('import-revert', $this->sistema), ['lease_token' => $reserva['lease_token'], 'modelo' => 'client', 'corrida_id' => self::CORRIDA],
            $this->headers_de_claude())->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('estado_corrida', 'revirtiendo');

        // Sin la reserva, nada.
        $this->postJson($this->ruta('import-revert', $this->sistema), ['lease_token' => str_repeat('x', 64), 'modelo' => 'client', 'corrida_id' => self::CORRIDA],
            $this->headers_de_claude())->assertStatus(423);

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/importacion-soporte/' . self::CORRIDA . '/revertir') !== false
                && (int) $request['user_id'] === (int) $this->client->user_id
                && (int) $request['support_ticket_id'] === (int) $this->sistema->id;
        });

        // `import-confirm` sigue solo para activos: en la prueba no hay código.
        $this->postJson($this->ruta('import-confirm', $this->sistema), $reserva + ['modelo' => 'client', 'corrida_id' => self::CORRIDA],
            $this->headers_de_claude())->assertStatus(403)->assertJsonPath('estado', 'contacto_no_habilitado');
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/confirmar') !== false;
        });
    }

    /** @test */
    public function el_estado_y_el_deshacer_de_la_prueba_no_se_abren_a_un_empleado_ni_a_una_prueba_vencida(): void
    {
        $this->fakear_http(['*/admin-sync/importacion-soporte/*' => Http::response($this->corrida('client', true, 'terminada'), 200)]);
        $empleado = $this->ticket_del_sistema(['client_employee_id' => 77]);

        // Con la reserva VÁLIDA de su propio ticket: lo que lo frena es la guarda de la prueba, no la reserva.
        $propia = $this->reservar($empleado);
        $this->getJson($this->ruta('import-status', $empleado) . '?modelo=client&corrida_id=' . self::CORRIDA,
            $this->headers_de_claude() + ['X-Support-Lease-Token' => $propia['lease_token']])
            ->assertStatus(403)->assertJsonPath('error', 'contacto_no_habilitado');
        $this->postJson($this->ruta('import-revert', $empleado), ['lease_token' => $propia['lease_token'], 'modelo' => 'client', 'corrida_id' => self::CORRIDA],
            $this->headers_de_claude())->assertStatus(403)->assertJsonPath('error', 'contacto_no_habilitado');

        // La reserva de otro ticket tampoco vale.
        $reserva = $this->reservar($this->sistema);
        $this->getJson($this->ruta('import-status', $empleado) . '?modelo=client&corrida_id=' . self::CORRIDA,
            $this->headers_de_claude() + ['X-Support-Lease-Token' => $reserva['lease_token']])->assertStatus(423);

        $this->fijar_en_prueba($this->client, -1);
        $this->getJson($this->ruta('import-status', $this->sistema) . '?modelo=client&corrida_id=' . self::CORRIDA,
            $this->headers_de_claude() + ['X-Support-Lease-Token' => $reserva['lease_token']])
            ->assertStatus(403)->assertJsonPath('error', 'contacto_no_habilitado');
        $this->no_inicio();
    }

    /* ------------------------------------------------------------------------------------- 7. La ganancia */

    /**
     * @test
     * @dataProvider margenes_validos
     */
    public function la_ganancia_valida_viaja_solo_en_la_prueba_de_articulos_y_vuelve_margen_previo(string $margen): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/analyze' => Http::response([
                'analysis_uuid' => 'uuid-con-margen', 'automatizar_prueba' => true, 'margen_previo' => $margen,
            ], 202),
        ]);
        $excel = $this->excel($this->sistema, 'lista-de-articulos');

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'article', 'origen' => 'martin', 'margen_de_ganancia' => $margen,
            'columnas_previas' => [['hoja' => 'LISTA', 'columna' => 'D', 'campo' => 'costo']]])
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('automatizar_prueba', true)
            ->assertJsonPath('margen_previo', $margen);

        Http::assertSent(function ($request) use ($margen) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false
                && $this->campo($request, 'margen_de_ganancia') === $margen
                && $this->campo($request, 'automatizar_prueba') === '1';
        });
    }

    public function margenes_validos(): array
    {
        return ['porcentaje' => ['40'], 'decimal' => ['35.5'], 'mil' => ['1000'], 'después' => ['despues'],
            'precio' => ['precio:1300'], 'precio con centavos' => ['precio:1300.5']];
    }

    /**
     * @test
     * @dataProvider margenes_invalidos
     */
    public function la_ganancia_invalida_es_422_sin_reenviar(string $margen): void
    {
        $excel = $this->excel($this->sistema, 'lista-de-articulos');

        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'article', 'margen_de_ganancia' => $margen])
            ->assertStatus(422)->assertJsonValidationErrors('margen_de_ganancia');
        $this->no_analizo();
    }

    public function margenes_invalidos(): array
    {
        return ['texto' => ['abc'], 'cero' => ['0'], 'más de mil' => ['1001'], 'precio cero' => ['precio:0'],
            'con coma (sin normalizar)' => ['35,5'], 'con tilde (sin normalizar)' => ['después'], 'tres decimales' => ['10.123'],
            'precio negativo' => ['precio:-5']];
    }

    /**
     * La regex lleva `D`: sin él, `$` acepta un salto de línea final. Por HTTP el middleware `TrimStrings` ya lo saca
     * antes de validar, así que acá se fija la regla misma (la del controlador usa la misma constante) y que por HTTP
     * nunca viaje con el salto.
     *
     * @test
     */
    public function la_ganancia_con_un_salto_de_linea_final_no_pasa_la_regla_ni_viaja_con_el_salto(): void
    {
        $this->assertNull(\App\Services\PruebaImportacionService::margen_valido("40\n"));
        $this->assertNull(\App\Services\PruebaImportacionService::margen_valido("precio:1300\n"));
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['m' => "40\n"],
            ['m' => ['string', 'regex:' . \App\Services\PruebaImportacionService::REGEX_MARGEN]])->fails(), 'La regla del controlador da 422.');

        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'u', 'automatizar_prueba' => true, 'margen_previo' => '40'], 202)]);
        $excel = $this->excel($this->sistema, 'lista-de-articulos');
        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'article', 'margen_de_ganancia' => "40\n"])->assertOk();
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false && $this->campo($request, 'margen_de_ganancia') === "40\n";
        });
    }

    /** @test */
    public function la_ganancia_con_el_modo_soporte_de_un_activo_es_422(): void
    {
        $this->client->estado = PruebaCicloService::CLIENTE_ACTIVO;
        $this->client->save();
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['automatizar_soporte' => true], 200),
            '*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'u', 'automatizar_soporte' => true], 202),
        ]);
        $excel = $this->adjuntar($this->ticket, 'soporte/lista.xlsx', self::XLSX);
        $reserva = $this->reservar();

        $this->importar($this->ticket, ['attachment_id' => $excel->id, 'model' => 'article', 'automatizar_soporte' => true,
            'margen_de_ganancia' => '40'] + $reserva)->assertStatus(422);
        $this->no_analizo();
    }

    /** @test */
    public function sin_automatizacion_o_con_un_sistema_que_la_ignora_margen_previo_vuelve_en_null(): void
    {
        // Un sistema que ignora el campo no devuelve `margen_previo`.
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'u1', 'automatizar_prueba' => true], 202)]);
        $excel = $this->excel($this->sistema, 'lista-de-articulos');
        $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'article', 'margen_de_ganancia' => 'precio:1300'])
            ->assertOk()->assertJsonPath('estado', 'aceptado')->assertJsonPath('margen_previo', null);

        // Un empleado: la carga va sin automatización y la ganancia no viaja (aunque empresa devolviera algo).
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'u2', 'automatizar_prueba' => false, 'margen_previo' => '40'], 202)]);
        $empleado = $this->ticket_del_sistema(['client_employee_id' => 77]);
        $excel = $this->excel($empleado, 'lista-del-empleado');
        $this->importar($empleado, ['attachment_id' => $excel->id, 'model' => 'article', 'margen_de_ganancia' => '40'])
            ->assertOk()->assertJsonPath('margen_previo', null);
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/ai-excel-import/analyze') !== false && $this->campo($request, 'margen_de_ganancia') === null;
        });

        // Sin ganancia, la respuesta no trae la clave: lo de hoy.
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'u3', 'automatizar_prueba' => true], 202)]);
        $excel = $this->excel($this->sistema, 'otra-lista');
        $respuesta = $this->importar($this->sistema, ['attachment_id' => $excel->id, 'model' => 'article'])->assertOk();
        $this->assertArrayNotHasKey('margen_previo', $respuesta->json());
    }

    /* ------------------------------------------------------------------------------------- 8. El aviso del resultado */

    private function aviso_del_resultado(int $ticket_id): array
    {
        // Como lo arma `formato_de_importacion` de empresa para una corrida de soporte: con el ticket y sin link.
        return [
            'id' => 'importacion_lista:' . self::CORRIDA, 'tipo' => 'importacion_lista', 'uuid' => self::CORRIDA,
            'ai_conversation_id' => null, 'support_ticket_id' => $ticket_id,
            'texto' => 'Cargué 9 clientes de «clientes cuenta corriente nov.xlsx». Si algo no coincide con tu lista, decímelo y lo deshago.',
        ];
    }

    private function empresa_ofrece(array $aviso): void
    {
        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response(['avisos' => [$aviso]], 200),
            '*/asistente/avisos/avisado' => Http::response(['ok' => true], 200),
            '*/api/admin-sync/support/messages' => Http::response(['model' => ['id' => 1]], 201),
        ]);
    }

    private function syncs(): int
    {
        $cuantos = 0;
        foreach (Http::recorded() as $par) {
            if ($par[0]->method() === 'POST' && strpos($par[0]->url(), '/api/admin-sync/support/messages') !== false) {
                $cuantos++;
            }
        }

        return $cuantos;
    }

    /**
     * @test
     * @dataProvider canales_del_aviso
     */
    public function el_resumen_de_la_carga_llega_por_el_canal_del_ticket(string $canal): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        if ($canal === 'ensayo') {
            $this->client->es_ensayo = true;
            $this->client->save();
        }
        $ticket = $canal === 'whatsapp' ? $this->ticket : $this->sistema;
        $aviso = $this->aviso_del_resultado((int) $ticket->id);
        $this->empresa_ofrece($aviso);

        $resultado = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($this->client);

        $this->assertSame(1, $resultado['enviados'], $resultado['detalle']);
        $salientes = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->get();
        $this->assertCount(1, $salientes);
        $this->assertSame($aviso['texto'], $salientes[0]->body, 'Sin link: el resumen tal cual.');
        $this->assertTrue((bool) $salientes[0]->is_trial_import_notice);

        if ($canal === 'whatsapp') {
            $this->assertCount(1, $espia->textos);
            $this->assertSame((string) $this->ticket->whatsapp_phone, $espia->textos[0]['to']);
            $this->assertSame($aviso['texto'], $espia->textos[0]['body']);
            $this->assertSame(0, $this->syncs());
        } else {
            $this->assertSame([], $espia->textos, 'Por el sistema no sale WhatsApp.');
            $this->assertSame([], $espia->plantillas);
            $this->assertSame(1, $this->syncs(), 'Se sincroniza al chat del sistema del negocio.');
            $this->assertCount(0, SupportMessage::where('support_ticket_id', $this->ticket->id)->where('sender_type', 'admin')->get());
        }
    }

    public function canales_del_aviso(): array
    {
        return ['ticket del sistema' => ['sistema'], 'cliente de ensayo' => ['ensayo'], 'ticket de WhatsApp con la ventana abierta' => ['whatsapp']];
    }

    /* ------------------------------------------------------------------------------------- 9. No se cierra a mitad de camino */

    /** @test */
    public function con_una_carga_de_contactos_abierta_el_ticket_de_la_prueba_no_se_cierra(): void
    {
        $abierta = ['corrida_id' => self::CORRIDA, 'modelo' => 'client', 'estado' => 'importar', 'revision' => 1,
            'codigo_requerido' => false, 'modo_prueba' => true];
        // `en_proceso: false` a propósito: la corrida abierta sola alcanza para mantener la conversación.
        $this->fakear_http(['*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(
            $this->pendientes(['margen_previo', 'contactos'], ['importaciones_de_contactos' => [$abierta]]), 200)]);
        $gracias = $this->entrante($this->sistema, 'Gracias', false);

        $reserva = $this->postJson($this->ruta('claim', $this->sistema), ['worker_id' => 'martin', 'expected_message_id' => (int) $gracias->id],
            $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.importaciones_de_contactos.0.corrida_id', self::CORRIDA)
            ->assertJsonPath('ticket.importaciones_de_contactos.0.modo_prueba', true)
            ->assertJsonPath('ticket.importaciones_prueba_en_proceso', true)
            ->assertJsonPath('ticket.capabilities.prueba_import_clientes', true);

        $this->postJson($this->ruta('reply', $this->sistema), [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => (int) $gracias->id,
            'operation_id' => 'cerrar-con-contactos-de-prueba', 'action' => 'cerrar', 'body' => 'De nada.',
        ], $this->headers_de_claude())->assertStatus(409)
            ->assertJsonPath('message', 'trial_import_pending: Hay una importación en curso en este ticket. Dejá abierta la conversación.');
        $this->assertSame('open', $this->sistema->fresh()->status);
    }

    /** @test */
    public function con_un_sistema_viejo_las_corridas_de_contactos_no_entran_al_contexto_de_la_prueba(): void
    {
        $abierta = ['corrida_id' => self::CORRIDA, 'modelo' => 'client', 'estado' => 'confirmacion', 'revision' => 1, 'codigo_requerido' => true];
        $this->fakear_http(['*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(
            ['pendientes' => [], 'en_proceso' => false, 'importaciones_de_contactos' => [$abierta]], 200)]);

        $this->getJson($this->ruta('', $this->sistema), $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.importaciones_de_contactos', [])
            ->assertJsonPath('ticket.importaciones_prueba_en_proceso', false)
            ->assertJsonPath('ticket.capabilities.prueba_import_clientes', false)
            ->assertJsonPath('ticket.capabilities.prueba_import_margen', false);
    }
}
