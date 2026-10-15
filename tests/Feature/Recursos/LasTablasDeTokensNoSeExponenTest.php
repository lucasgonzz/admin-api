<?php

namespace Tests\Feature\Recursos;

use App\Models\RecursosLink;
use App\Models\RecursosSesion;
use App\Services\RecursosLinkService;

/**
 * 🔴 Las tablas que guardan los hashes de los tokens del centro de recursos (`recursos_sesiones` y
 * `recursos_links`) no se pueden leer ni escribir por las rutas genéricas del panel, que resuelven
 * CUALQUIER modelo de `App\Models` por el nombre que viene en la URL (`search/{model}`,
 * `search-from-modal/{model}`, `mass-update/{model}`, `meta/{model}`). Con ellas un admin podría
 * leer o pisar el hash y entrar al centro como cualquier usuario de cualquier cliente.
 *
 * `GET claude/query` no hace falta probarlo acá: es una lista blanca (`config/claude_query.php`) y
 * estas tablas no están en ella.
 */
class LasTablasDeTokensNoSeExponenTest extends BaseDelCentroDeRecursos
{
    public function test_las_rutas_genericas_no_resuelven_las_tablas_de_tokens(): void
    {
        $this->admin_logueado();

        // El admin está logueado de verdad: lo que sigue es 404 por el modelo, no 401.
        $this->getJson('/api/admin/me')->assertStatus(200);

        $client = $this->crear_cliente('api-a.test');
        list(, $token) = $this->usuario_con_sesion($client);
        (new RecursosLinkService())->link_del_dueno($client);

        $sesion = RecursosSesion::where('token_hash', hash('sha256', $token))->first();
        $hash   = $sesion->token_hash;
        $link   = RecursosLink::first();

        $nombres = ['recursos_sesion', 'recursos-sesion', 'recursosSesion', 'RECURSOS_SESION', 'recursos_link', 'recursos-link', 'RecursosLink'];

        foreach ($nombres as $nombre) {
            $this->postJson('/api/admin/search/' . $nombre . '/null/1', ['filters' => []])->assertStatus(404);
            $this->postJson('/api/admin/search-from-modal/' . $nombre, ['query_value' => 'a', 'props_to_filter' => ['token_hash']])->assertStatus(404);
            $this->getJson('/api/admin/meta/' . $nombre)->assertStatus(404);
            $this->postJson('/api/admin/mass-update/' . $nombre, [
                'models_id'   => [$sesion->id, $link->id],
                'update_form' => [['type' => 'text', 'key' => 'token_hash', 'value' => hash('sha256', 'del-atacante')]],
            ])->assertStatus(404);
        }

        $this->assertSame($hash, $sesion->fresh()->token_hash);
        $this->assertSame($link->token_hash, $link->fresh()->token_hash);

        // Y la sesión sigue andando con su token de siempre.
        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(200);
    }

    public function test_el_hash_no_sale_serializado(): void
    {
        $client = $this->crear_cliente('api-a.test');
        list(, $token) = $this->usuario_con_sesion($client);
        (new RecursosLinkService())->link_del_dueno($client);

        $sesion = RecursosSesion::where('token_hash', hash('sha256', $token))->first();

        $this->assertArrayNotHasKey('token_hash', $sesion->toArray());
        $this->assertArrayNotHasKey('token_hash', RecursosLink::first()->toArray());
        $this->assertArrayNotHasKey('token_cifrado', RecursosLink::first()->toArray());
    }
}
