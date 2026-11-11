<?php

namespace Tests\Feature\ImportacionesDeMartin;

use App\Models\SupportCodexTaskGrant;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Http;

/**
 * C de la misión cruzada `importaciones-de-martin` (11/11/2026), del lado del admin: el permiso delegado de la tarea
 * nativa, el catálogo del dueño y las fotos de la web del proveedor.
 *
 * Lo que protege:
 *  1. 🔴 El permiso se emite solo con la reserva, sirve 24 h, solo para SU ticket y solo para las rutas de su alcance
 *     (`pdf`: el catálogo; `web`: el catálogo y las fotos). El token no se guarda en claro.
 *  2. El catálogo y las fotos aceptan la reserva O el permiso (uno de los dos), y el cliente sale del ticket.
 *  3. Las fotos viajan en multipart con `article_id`, `tipo`, `url_origen`, `codigo_web` e `imagen` por item; lo que
 *     no es una imagen no sale. 404 de la instancia (o una que no informa las imágenes) = `version_vieja`.
 */
class PermisoDelegadoEImagenesDelProveedorTest extends BaseDeImportacionesDeMartin
{
    private function catalogo(array $headers = [], string $query = 'con_imagenes=1&despues_de=10&limite=200', ?SupportTicket $ticket = null)
    {
        return $this->getJson($this->ruta('catalogo-articulos', $ticket) . '?' . $query, $this->headers_de_claude() + $headers);
    }

    private function subir(array $body, ?SupportTicket $ticket = null)
    {
        return $this->postJson($this->ruta('import-images-proveedor', $ticket), $body, $this->headers_de_claude());
    }

    private function tanda(array $extra = []): array
    {
        return [
            'fuente' => 'www.proveedor.com.ar',
            'items' => [
                ['article_id' => 11, 'tipo' => 'exacto', 'content_base64' => base64_encode($this->png('uno')),
                    'url_origen' => 'https://www.proveedor.com.ar/p/11', 'codigo_web' => '7790001000011'],
                ['article_id' => 12, 'tipo' => 'dudosa', 'content_base64' => base64_encode($this->png('dos')),
                    'url_origen' => 'https://www.proveedor.com.ar/p/12', 'codigo_web' => 'ABC-12'],
                ['article_id' => 13, 'tipo' => 'exacto', 'content_base64' => base64_encode('<html>no soy una foto</html>')],
            ],
        ] + $extra;
    }

    private function fakear_empresa(): void
    {
        $this->fakear_http([
            '*/admin-sync/catalogo/articulos*' => Http::response(['articulos' => [
                ['id' => 11, 'nombre' => 'Tornillo', 'codigo_de_barras' => '7790001000011', 'tiene_imagen' => false, 'codigos_pivot' => ['T-11'], 'a_revisar' => false],
            ], 'siguiente' => null, 'total' => 1], 200),
            '*/admin-sync/catalogo/imagenes/proveedor' => Http::response([
                'run_id' => 5, 'asignadas' => 1, 'a_revisar' => 1, 'salteadas' => [],
            ], 200),
        ]);
    }

    /* ------------------------------------------------------------------------------------- Permiso delegado */

    /** @test */
    public function emite_el_permiso_con_la_reserva_por_24_horas_y_guarda_solo_el_hash(): void
    {
        $this->fakear_http();
        $reserva = $this->reservar();

        $this->postJson($this->ruta('task-grant'), ['lease_token' => str_repeat('x', 64), 'alcance' => 'web'], $this->headers_de_claude())
            ->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
        $this->postJson($this->ruta('task-grant'), ['lease_token' => $reserva['lease_token'], 'alcance' => 'todo'], $this->headers_de_claude())
            ->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos');

        $respuesta = $this->postJson($this->ruta('task-grant'), ['lease_token' => $reserva['lease_token'], 'alcance' => 'web'], $this->headers_de_claude())
            ->assertStatus(201)->assertJsonPath('ticket_id', (int) $this->ticket->id)->assertJsonPath('alcance', 'web');
        $token = (string) $respuesta->json('tarea_token');
        $this->assertSame(64, strlen($token));

        $permiso = SupportCodexTaskGrant::where('support_ticket_id', $this->ticket->id)->sole();
        $this->assertSame(hash('sha256', $token), $permiso->token_hash);
        $this->assertSame((int) $this->client->id, $permiso->client_id);
        $this->assertTrue($permiso->vence_at->between(now()->addHours(24)->subMinute(), now()->addHours(24)->addMinute()));
        $this->assertStringNotContainsString($token, json_encode(SupportCodexTaskGrant::all()->toArray()));
    }

    /** @test */
    public function un_negocio_que_no_es_activo_no_recibe_permiso(): void
    {
        $this->fakear_http();
        $reserva = $this->reservar();
        $this->client->estado = PruebaCicloService::CLIENTE_PRUEBA;
        $this->client->save();

        $this->postJson($this->ruta('task-grant'), ['lease_token' => $reserva['lease_token'], 'alcance' => 'pdf'], $this->headers_de_claude())
            ->assertStatus(403)->assertJsonPath('motivo', 'contacto_no_habilitado');
        $this->assertSame(0, SupportCodexTaskGrant::count());
    }

    /** @test */
    public function el_permiso_vencido_de_otro_ticket_o_de_otro_alcance_no_abre_nada(): void
    {
        $this->fakear_empresa();
        $reserva = $this->reservar();
        $web = $this->permiso('web', $reserva['lease_token']);
        $pdf = $this->permiso('pdf', $reserva['lease_token']);

        // Alcance: el del PDF lee el catálogo pero no sube fotos.
        $this->catalogo(['X-Support-Task-Token' => $pdf])->assertOk()->assertJsonPath('estado', 'aceptado');
        $this->subir($this->tanda(['tarea_token' => $pdf]))->assertStatus(403)->assertJsonPath('motivo', 'alcance_no_permitido');

        // Otro ticket del mismo dueño: el permiso es de ESTE ticket.
        $otro = $this->ticket_del_cliente($this->client);
        $this->acreditar($this->ultimo_mensaje($otro));
        $this->catalogo(['X-Support-Task-Token' => $web], 'limite=10', $otro)->assertStatus(403)->assertJsonPath('motivo', 'tarea_de_otro_ticket');
        $this->subir($this->tanda(['tarea_token' => $web]), $otro)->assertStatus(403)->assertJsonPath('motivo', 'tarea_de_otro_ticket');

        // Inventado, o los dos tokens a la vez, o ninguno.
        $this->catalogo(['X-Support-Task-Token' => str_repeat('z', 64)])->assertStatus(403)->assertJsonPath('motivo', 'tarea_invalida');
        $this->catalogo(['X-Support-Task-Token' => $web, 'X-Support-Lease-Token' => $reserva['lease_token']])
            ->assertStatus(422)->assertJsonPath('motivo', 'sin_permiso');
        $this->catalogo()->assertStatus(422)->assertJsonPath('motivo', 'sin_permiso');

        // Vencido: a las 24 h deja de servir.
        $this->travel(SupportCodexTaskGrant::HORAS)->hours();
        $this->travel(1)->minutes();
        $this->catalogo(['X-Support-Task-Token' => $web])->assertStatus(403)->assertJsonPath('motivo', 'tarea_vencida');
        $this->subir($this->tanda(['tarea_token' => $web]))->assertStatus(403)->assertJsonPath('motivo', 'tarea_vencida');

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/catalogo/imagenes/proveedor') !== false;
        });
    }

    /** @test */
    public function el_permiso_deja_de_servir_si_el_ticket_se_cierra(): void
    {
        $this->fakear_empresa();
        $web = $this->permiso('web', $this->reservar()['lease_token']);
        $this->ticket->status = 'closed';
        $this->ticket->save();

        $this->catalogo(['X-Support-Task-Token' => $web])->assertStatus(409)->assertJsonPath('motivo', 'ticket_cerrado');
    }

    /* ------------------------------------------------------------------------------------- Catálogo */

    /** @test */
    public function el_catalogo_se_lee_con_la_reserva_o_con_el_permiso_y_del_dueno_del_ticket(): void
    {
        $this->fakear_empresa();
        $reserva = $this->reservar();
        $web = $this->permiso('web', $reserva['lease_token']);

        $this->catalogo(['X-Support-Lease-Token' => $reserva['lease_token']])->assertOk()
            ->assertJsonPath('estado', 'aceptado')->assertJsonPath('cuerpo.articulos.0.codigos_pivot.0', 'T-11')
            ->assertJsonPath('cuerpo.articulos.0.tiene_imagen', false);
        $this->catalogo(['X-Support-Task-Token' => $web])->assertOk()->assertJsonPath('cuerpo.total', 1);
        $this->catalogo(['X-Support-Lease-Token' => str_repeat('x', 64)])->assertStatus(423)->assertJsonPath('motivo', 'invalid_lease');

        $pedidos = Http::recorded(function ($request) {
            return strpos($request->url(), '/catalogo/articulos') !== false;
        });
        $this->assertCount(2, $pedidos);
        $url = $pedidos[0][0]->url();
        $this->assertSame('GET', $pedidos[0][0]->method());
        $this->assertStringContainsString('/api/admin-sync/catalogo/articulos?', $url);
        $this->assertStringContainsString('despues_de=10', $url);
        $this->assertStringContainsString('limite=200', $url);
        $this->assertStringContainsString('con_imagenes=1', $url);
        $this->assertStringContainsString('user_id=' . $this->client->user_id, $url);
        $this->assertTrue($pedidos[0][0]->hasHeader('X-Admin-Api-Key', 'clave-del-cliente'));
        $this->assertSame(1, SupportCodexTaskGrant::where('alcance', 'web')->value('usos'));
    }

    /**
     * @test
     * @dataProvider catalogos_viejos
     */
    public function un_sistema_sin_el_catalogo_de_imagenes_es_version_vieja(int $status, array $cuerpo): void
    {
        $this->fakear_http(['*/admin-sync/catalogo/articulos*' => Http::response($cuerpo, $status)]);
        $reserva = $this->reservar();

        $this->catalogo(['X-Support-Lease-Token' => $reserva['lease_token']])->assertOk()->assertJsonPath('estado', 'version_vieja');
    }

    public function catalogos_viejos(): array
    {
        return [
            'sin la ruta' => [404, ['message' => 'Not Found']],
            'ignora con_imagenes' => [200, ['articulos' => [['id' => 11, 'nombre' => 'Tornillo']], 'siguiente' => null, 'total' => 1]],
        ];
    }

    /* ------------------------------------------------------------------------------------- Imágenes */

    /** @test */
    public function sube_la_tanda_en_multipart_con_el_permiso_web_y_descarta_lo_que_no_es_foto(): void
    {
        $this->fakear_empresa();
        $web = $this->permiso('web', $this->reservar()['lease_token']);

        $this->subir($this->tanda(['tarea_token' => $web]))->assertOk()
            ->assertJsonPath('estado', 'aceptado')->assertJsonPath('enviadas', 2)
            ->assertJsonPath('cuerpo.run_id', 5)->assertJsonPath('cuerpo.a_revisar', 1)
            ->assertJsonPath('rechazadas_antes_de_enviar.0.article_id', 13);

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/catalogo/imagenes/proveedor') !== false
                && $request->method() === 'POST'
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $this->campo($request, 'user_id') === (string) $this->client->user_id
                && $this->campo($request, 'support_ticket_id') === (string) $this->ticket->id
                && $this->campo($request, 'fuente') === 'www.proveedor.com.ar'
                && $this->campo($request, 'items[0][article_id]') === '11'
                && $this->campo($request, 'items[0][tipo]') === 'exacto'
                && $this->campo($request, 'items[0][url_origen]') === 'https://www.proveedor.com.ar/p/11'
                && $this->campo($request, 'items[0][codigo_web]') === '7790001000011'
                && $request->hasFile('items[0][imagen]', $this->png('uno'))
                && $this->campo($request, 'items[1][tipo]') === 'dudosa'
                && $request->hasFile('items[1][imagen]', $this->png('dos'))
                && $this->campo($request, 'items[2][article_id]') === null;
        });
    }

    /** @test */
    public function sube_con_la_reserva_y_un_sistema_viejo_es_version_vieja(): void
    {
        $this->fakear_http(['*/admin-sync/catalogo/imagenes/proveedor' => Http::response(['message' => 'Not Found'], 404)]);
        $reserva = $this->reservar();

        $this->subir($this->tanda(['lease_token' => $reserva['lease_token'], 'ultima_tanda' => true]))->assertOk()->assertJsonPath('estado', 'version_vieja');
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/catalogo/imagenes/proveedor') !== false && $this->campo($request, 'ultima_tanda') === '1';
        });
    }

    /** @test */
    public function un_rechazo_de_la_instancia_con_motivo_no_es_version_vieja(): void
    {
        $this->fakear_http(['*/admin-sync/catalogo/imagenes/proveedor' => Http::response(['message' => 'Otro dueño.', 'motivo' => 'dueno_distinto'], 409)]);
        $reserva = $this->reservar();

        $this->subir($this->tanda(['lease_token' => $reserva['lease_token']]))->assertOk()
            ->assertJsonPath('estado', 'rechazado')->assertJsonPath('http', 409)->assertJsonPath('cuerpo.motivo', 'dueno_distinto');
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/catalogo/imagenes/proveedor') !== false && $this->campo($request, 'ultima_tanda') === null;
        });
    }

    /** @test */
    public function valida_la_tanda_antes_de_mandar_nada(): void
    {
        $this->fakear_empresa();
        $reserva = $this->reservar();

        $this->subir(['fuente' => 'https://x/y', 'items' => [['article_id' => 1, 'tipo' => 'exacto', 'content_base64' => 'a']],
            'lease_token' => $reserva['lease_token']])->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos');
        $this->subir(['fuente' => 'proveedor.com', 'items' => [['article_id' => 1, 'tipo' => 'otro_color', 'content_base64' => 'a']],
            'lease_token' => $reserva['lease_token']])->assertStatus(422);
        $demasiados = array_fill(0, 26, ['article_id' => 1, 'tipo' => 'exacto', 'content_base64' => base64_encode($this->png())]);
        $this->subir(['fuente' => 'proveedor.com', 'items' => $demasiados, 'lease_token' => $reserva['lease_token']])->assertStatus(422);
        $this->subir(['fuente' => 'proveedor.com', 'items' => [['article_id' => 1, 'tipo' => 'exacto', 'content_base64' => base64_encode('texto')]],
            'lease_token' => $reserva['lease_token']])->assertStatus(422)->assertJsonPath('estado', 'archivo_invalido');

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/catalogo/imagenes/proveedor') !== false;
        });
    }
}
