<?php

namespace Tests\Feature\PanelAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Misión admin-control-total — el token de un Admin marcado como agente entra al panel (`api/admin/*`)
 * con la misma puerta que admin-spa, y queda auditado; el admin humano no cambia en nada.
 *
 * Cubre CA1, CA2, CA3, CA13, CA14 y CA16 del PLAN.
 */
class TokenDeAgenteEnElPanelTest extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /**
     * CA1 — El agente lee el panel con su token y cada pedido deja una fila de auditoría.
     *
     * @return void
     */
    public function test_el_token_de_agente_lee_el_panel_y_queda_auditado(): void
    {
        $agente = $this->crear_agente('Agente Claude Code de prueba');
        $token  = $this->token_de($agente);
        $headers = ['X-Agente-Cliente' => 'claude-code-test'];

        $lista = $this->pedir_como($token, 'GET', '/api/admin/client', [], $headers);
        $lista->assertOk();
        $this->assertArrayHasKey('models', $lista->json(), 'La lista de clientes del panel trae la clave models.');

        $yo = $this->pedir_como($token, 'GET', '/api/admin/me', [], $headers);
        $yo->assertOk()->assertJsonPath('admin.name', 'Agente Claude Code de prueba');

        $filas = $this->auditoria_de($agente);
        $this->assertCount(2, $filas, 'Un pedido del agente = una fila de auditoría.');

        foreach ($filas as $fila) {
            $this->assertSame('GET', $fila->method);
            $this->assertSame('lectura', $fila->category);
            $this->assertSame('ejecutada', $fila->result);
            $this->assertSame(200, (int) $fila->status_code);
            $this->assertSame((int) $agente->id, (int) $fila->admin_id);
            $this->assertSame('claude-code-test', $fila->agent_client);
            $this->assertSame(0, (int) $fila->braked);
        }

        $this->assertSame('client', $filas[0]->route_uri);
        $this->assertSame('api/admin/client', $filas[0]->path);
        $this->assertSame('me', $filas[1]->route_uri);
    }

    /**
     * CA2 — El caso que originó el pedido: desactivar al cliente 83 con la misma ruta del panel.
     *
     * @return void
     */
    public function test_el_agente_desactiva_al_cliente_83_por_la_ruta_del_panel(): void
    {
        $cliente = $this->crear_cliente(83);
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);

        $antes = DB::table('clients')->where('id', $cliente->id)->first();

        $respuesta = $this->pedir_como(
            $token,
            'PUT',
            '/api/admin/client/' . $cliente->id,
            ['is_active' => false],
            ['X-Agente-Motivo' => 'baja pedida por Lucas', 'X-Agente-Cliente' => 'claude-code-test']
        );
        $respuesta->assertOk();

        $despues = DB::table('clients')->where('id', $cliente->id)->first();
        $this->assertSame(0, (int) $despues->is_active, 'El cliente quedó desactivado.');
        $this->assertSame($antes->name, $despues->name, 'Lo demás no se toca: name.');
        $this->assertSame($antes->api_url, $despues->api_url, 'Lo demás no se toca: api_url.');
        $this->assertSame($antes->phone, $despues->phone, 'Lo demás no se toca: phone.');

        $fila = $this->ultima_auditoria_de($agente);
        $this->assertNotNull($fila, 'El pedido del agente quedó auditado.');
        $this->assertSame('PUT', $fila->method);
        $this->assertSame('client/{id}', $fila->route_uri);
        $this->assertSame('api/admin/client/' . $cliente->id, $fila->path);
        $this->assertSame('escritura', $fila->category);
        $this->assertSame(0, (int) $fila->braked);
        $this->assertSame('ejecutada', $fila->result);
        $this->assertSame(200, (int) $fila->status_code);
        $this->assertSame('baja pedida por Lucas', $fila->reason);
        $this->assertTrue($this->contiene($fila->request_payload, '"is_active":false'), 'El payload auditado muestra la baja.');
    }

    /**
     * CA3 — Un admin humano no cambia en nada: ni auditoría ni 428, mande o no los headers del agente.
     *
     * @return void
     */
    public function test_un_admin_humano_no_es_auditado_ni_frenado(): void
    {
        $humano  = $this->crear_humano();
        $token   = $this->token_de($humano);
        $cliente = $this->crear_cliente();
        $filas_antes = DB::table('panel_agent_audit_logs')->count();

        // Un DELETE sin ningún header del agente: se ejecuta como siempre (el controlador responde 204).
        $variante_a = $this->crear_variante();
        $this->pedir_como($token, 'DELETE', '/api/admin/message-variant/' . $variante_a->id)->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $variante_a->id]);

        // Aunque mande los headers X-Agente-*, el humano no es frenado ni «dry run»: el borrado ocurre.
        $variante_b = $this->crear_variante();
        $this->pedir_como($token, 'DELETE', '/api/admin/message-variant/' . $variante_b->id, [], [
            'X-Agente-Dry-Run' => 'si',
            'X-Agente-Cliente' => 'no-soy-agente',
        ])->assertStatus(204);
        $this->assertDatabaseMissing('message_variants', ['id' => $variante_b->id]);

        $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['is_active' => false])->assertOk();
        $this->assertSame(0, (int) DB::table('clients')->where('id', $cliente->id)->value('is_active'));

        $this->assertSame($filas_antes, DB::table('panel_agent_audit_logs')->count(), 'El humano no deja filas de auditoría.');
        $this->assertSame(0, DB::table('panel_agent_audit_logs')->where('admin_id', $humano->id)->count());
    }

    /**
     * CA14 — El agente no aparece en el selector de asignación (`GET admin`) pero sí en Usuarios (`GET admin-user`).
     *
     * @return void
     */
    public function test_el_agente_no_sale_en_el_selector_de_asignacion(): void
    {
        $agente = $this->crear_agente('Agente fuera del selector');
        $humano = $this->crear_humano('Humano en el selector');
        $token  = $this->token_de($humano);

        $selector = $this->pedir_como($token, 'GET', '/api/admin/admin');
        $selector->assertOk();
        $emails_selector = collect($selector->json('admins'))->pluck('email')->all();
        $this->assertNotContains($agente->email, $emails_selector, 'A un agente no se le asignan tareas humanas.');
        $this->assertContains($humano->email, $emails_selector, 'Los humanos siguen en el selector.');

        $usuarios = $this->pedir_como($token, 'GET', '/api/admin/admin-user');
        $usuarios->assertOk();
        $emails_usuarios = collect($usuarios->json('models'))->pluck('email')->all();
        $this->assertContains($agente->email, $emails_usuarios, 'En Usuarios el agente se ve (y un humano puede borrarlo).');
    }

    /**
     * CA13 — El agente tiene su propia cubeta de throttle; el humano no la comparte ni la gasta.
     *
     * @return void
     */
    public function test_el_agente_tiene_su_propia_cubeta_de_throttle(): void
    {
        config(['panel_agent.rate_limit_per_minute' => 2]);

        $agente = $this->crear_agente();
        $humano = $this->crear_humano();
        $token_agente = $this->token_de($agente);
        $token_humano = $this->token_de($humano);

        $this->pedir_como($token_agente, 'GET', '/api/admin/me')->assertOk();
        $this->pedir_como($token_agente, 'GET', '/api/admin/me')->assertOk();

        $tercero = $this->pedir_como($token_agente, 'GET', '/api/admin/me');
        $tercero->assertStatus(429);
        $tercero->assertJsonPath('code', 'agente_throttle');
        $this->assertArrayHasKey('reintentar_en', $tercero->json());

        $fila = $this->ultima_auditoria_de($agente);
        $this->assertNotNull($fila, 'El pedido cortado por el throttle también queda auditado.');
        $this->assertSame(429, (int) $fila->status_code);
        $this->assertSame('frenada', $fila->result);

        // El humano, en el mismo minuto y con el mismo tope configurado, sigue de largo.
        for ($pedido = 1; $pedido <= 4; $pedido++) {
            $this->pedir_como($token_humano, 'GET', '/api/admin/me')->assertOk();
        }
    }

    /**
     * CA16 — Las rutas `agente/*` exigen Sanctum (y el perfil «Sólo Leads» si ese alias existe).
     *
     * Se compatibiliza con la misión embudo-whatsapp-precios sin depender del orden de merge:
     * el alias `admin.solo_leads` se exige sólo si ya está registrado en el router.
     *
     * @return void
     */
    public function test_las_rutas_del_agente_exigen_sanctum_y_el_perfil_si_existe(): void
    {
        $existe_el_perfil = array_key_exists('admin.solo_leads', $this->app['router']->getMiddleware());
        $encontradas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (in_array($ruta->uri(), ['api/admin/agente/operaciones', 'api/admin/agente/auditoria'], true)) {
                $encontradas[] = $ruta->uri();
                $middlewares = $ruta->gatherMiddleware();
                $this->assertContains('auth:sanctum', $middlewares, $ruta->uri() . ' tiene que pedir auth:sanctum.');
                if ($existe_el_perfil) {
                    $this->assertContains('admin.solo_leads', $middlewares, $ruta->uri() . ' tiene que pasar por el perfil Sólo Leads.');
                }
            }
        }

        sort($encontradas);
        $this->assertSame(
            ['api/admin/agente/auditoria', 'api/admin/agente/operaciones'],
            $encontradas,
            'Las dos rutas del agente tienen que estar registradas.'
        );

        // Sin token → 401.
        $this->pedir_como(null, 'GET', '/api/admin/agente/operaciones')->assertStatus(401);
        $this->pedir_como(null, 'GET', '/api/admin/agente/auditoria')->assertStatus(401);
    }
}
