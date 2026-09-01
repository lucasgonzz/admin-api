<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El contrato de paginado del que depende la grilla de leads del admin, en sus dos caminos: el
 * listado base (`GET /admin/lead`) y la búsqueda con filtros de columna
 * (`POST /admin/search/lead/null/1`).
 *
 * Lo que se protege:
 *
 * 1. Que el tamaño de página lo mande el cliente y se respete. La grilla pasó a pedir 25 por
 *    página; antes pedía 500 y el backend clampeaba a 200 sin avisar, así que "cuántos vienen"
 *    nunca había sido una decisión del SPA de verdad.
 * 2. Que las páginas no se pisen ni se repitan: con el orden de la bandeja (atención, fijados,
 *    último mensaje) un desempate flojo hace que la misma fila aparezca en dos páginas.
 * 3. 🔴 Que `GET /admin/lead` **sin** `page` siga devolviendo la colección ENTERA. De eso depende
 *    el panel de demos agendadas del propio módulo (`load_demos_agendadas()` en `Leads.vue` pega
 *    sin `page` ni `per_page` y espera todo). Si alguien "unifica" haciendo que el listado pagine
 *    siempre, ese panel muestra un recorte sin que nada falle.
 */
class PaginadoDelListadoDeLeadsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja la base sin leads: los totales del paginador se chequean con números exactos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LeadMessage::query()->delete();
        Lead::query()->delete();
    }

    /**
     * Admin autenticado para pegarle a los dos endpoints.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        return Admin::create([
            'name'     => 'Operador de prueba',
            'email'    => 'paginado-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Crea N leads del estado pedido, con `created_at` escalonado.
     *
     * 🔴 El escalonado no es cosmético: el desempate final del orden de la bandeja es
     * `COALESCE(last_message_at, created_at) DESC`. Si todos nacen en el mismo segundo, MySQL puede
     * devolverlos en cualquier orden y la misma fila aparecería en dos páginas — un rojo
     * intermitente que después nadie reproduce.
     *
     * @param int    $cantidad Cuántos leads crear.
     * @param string $status   Estado del pipeline.
     * @param string $prefijo  Prefijo del nombre de contacto.
     *
     * @return array<int, int> Ids creados, del más nuevo al más viejo (o sea, el orden esperado).
     */
    private function crear_leads(int $cantidad, string $status = 'calificado', string $prefijo = 'Lead'): array
    {
        $ids = [];
        $i   = 0;

        while ($i < $cantidad) {
            $lead = new Lead();
            $lead->contact_name = $prefijo . ' ' . ($i + 1);
            $lead->phone        = '54911' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT);
            $lead->status       = $status;
            $lead->created_at   = now()->subMinutes($i);
            $lead->updated_at   = now()->subMinutes($i);
            $lead->save();

            $ids[] = (int) $lead->id;
            $i++;
        }

        return $ids;
    }

    /**
     * Pega al listado base.
     *
     * @param array<string, mixed> $params Query string.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function listado(array $params = [])
    {
        $url = '/api/admin/lead' . (empty($params) ? '' : '?' . http_build_query($params));

        return $this->actingAs($this->admin_autenticado(), 'sanctum')->getJson($url);
    }

    /**
     * Pega a la búsqueda con filtros de columna (mismo contrato que usa `run_filter` del SPA).
     *
     * @param array<int, array<string, mixed>> $filters Filtros del cuerpo.
     * @param int                              $page    Página pedida (va por query string).
     * @param int                              $per     Tamaño de página (va por cuerpo).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function busqueda(array $filters, int $page = 1, int $per = 25)
    {
        return $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->postJson('/api/admin/search/lead/null/1?page=' . $page, [
                'filters'  => $filters,
                'per_page' => $per,
            ]);
    }

    /**
     * Ids de una respuesta paginada del listado base.
     *
     * @param \Illuminate\Testing\TestResponse $response
     *
     * @return array<int, int>
     */
    private function ids_del_listado($response): array
    {
        return array_map(function ($fila) {
            return (int) $fila['id'];
        }, $response->json('models.data'));
    }

    /**
     * El listado base respeta el `per_page` que manda la grilla.
     *
     * @return void
     */
    public function test_el_listado_base_devuelve_veinticinco_por_pagina(): void
    {
        $this->crear_leads(30);

        $response = $this->listado(['page' => 1, 'per_page' => 25]);
        $response->assertStatus(200);

        $this->assertCount(25, $response->json('models.data'));
        $this->assertSame(30, (int) $response->json('models.total'));
        $this->assertSame(25, (int) $response->json('models.per_page'));
        $this->assertSame(2, (int) $response->json('models.last_page'));
    }

    /**
     * La segunda página trae el resto y ninguna fila se repite entre páginas.
     *
     * @return void
     */
    public function test_la_segunda_pagina_del_listado_base_trae_el_resto_sin_repetir(): void
    {
        $this->crear_leads(30);

        $primera = $this->ids_del_listado($this->listado(['page' => 1, 'per_page' => 25]));
        $segunda = $this->ids_del_listado($this->listado(['page' => 2, 'per_page' => 25]));

        $this->assertCount(25, $primera);
        $this->assertCount(5, $segunda);
        $this->assertEmpty(array_intersect($primera, $segunda), 'Ninguna fila puede aparecer en dos páginas.');
        $this->assertCount(30, array_unique(array_merge($primera, $segunda)));
    }

    /**
     * La búsqueda filtrada pagina con el `per_page` que viaja en el cuerpo (no por query string).
     *
     * @return void
     */
    public function test_la_busqueda_filtrada_pagina_con_el_per_page_del_cuerpo(): void
    {
        $this->crear_leads(30, 'calificado');
        $this->crear_leads(3, 'demo_agendada', 'Con demo');

        $response = $this->busqueda([
            ['key' => 'status', 'type' => 'select', 'igual_que' => 'calificado'],
        ], 1, 25);

        $response->assertStatus(200);

        $this->assertCount(25, $response->json('data'));
        $this->assertSame(30, (int) $response->json('total'), 'El total es el del filtro, no el global.');
        $this->assertSame(25, (int) $response->json('per_page'));

        // Y la segunda página trae los 5 que faltan, sin repetir.
        $segunda = $this->busqueda([
            ['key' => 'status', 'type' => 'select', 'igual_que' => 'calificado'],
        ], 2, 25);

        $this->assertCount(5, $segunda->json('data'));
    }

    /**
     * El filtro por grupo de estados manda `igual_que` como ARRAY (whereIn) y también pagina.
     *
     * @return void
     */
    public function test_la_busqueda_filtrada_por_grupo_pagina_igual(): void
    {
        $this->crear_leads(20, 'demo_agendada', 'Agendada');
        $this->crear_leads(10, 'demo_en_curso', 'En curso');
        $this->crear_leads(4, 'calificado', 'Calificado');

        $response = $this->busqueda([
            ['key' => 'status', 'type' => 'select', 'igual_que' => ['demo_agendada', 'demo_en_curso']],
        ], 1, 25);

        $response->assertStatus(200);

        $this->assertCount(25, $response->json('data'));
        $this->assertSame(30, (int) $response->json('total'), 'Los dos estados del grupo, y nada más.');

        foreach ($response->json('data') as $fila) {
            $this->assertContains($fila['status'], ['demo_agendada', 'demo_en_curso']);
        }
    }

    /**
     * 🔴 Guarda de regresión del panel de demos agendadas: sin `page`, el listado devuelve la
     * colección entera, no una página.
     *
     * @return void
     */
    public function test_sin_page_el_listado_sigue_devolviendo_la_coleccion_entera(): void
    {
        $this->crear_leads(30, 'demo_agendada', 'Agendada');

        $response = $this->listado(['status' => 'demo_agendada']);
        $response->assertStatus(200);

        $models = $response->json('models');

        $this->assertIsArray($models);
        $this->assertArrayNotHasKey('data', $models, 'Sin page la respuesta es una colección plana, no un paginador.');
        $this->assertCount(30, $models, 'El panel de demos agendadas depende de recibirlos todos.');
    }

    /**
     * El orden de la bandeja (`sort_by=atencion`) manda sobre el paginado: los leads que necesitan
     * atención van en la primera página, no repartidos por ahí.
     *
     * @return void
     */
    public function test_el_orden_de_la_bandeja_se_respeta_entre_paginas(): void
    {
        $ids = $this->crear_leads(30);

        // Tres leads del fondo de la lista (los más viejos) marcados como pendientes de revisión:
        // por creación irían a la última página, por atención tienen que subir a la primera.
        $marcados = array_slice($ids, -3);
        Lead::query()->whereIn('id', $marcados)->update(['pendiente_revision_at' => now()]);

        $primera = $this->ids_del_listado($this->listado(['page' => 1, 'per_page' => 25, 'sort_by' => 'atencion']));
        $segunda = $this->ids_del_listado($this->listado(['page' => 2, 'per_page' => 25, 'sort_by' => 'atencion']));

        foreach ($marcados as $id) {
            $this->assertContains($id, $primera, 'El lead con atención pendiente tiene que estar en la página 1.');
        }

        $this->assertSame(array_slice($primera, 0, 3), $marcados, 'Y arriba de todo, en orden de creación.');
        $this->assertEmpty(array_intersect($primera, $segunda), 'Ninguna fila puede aparecer en dos páginas.');
        $this->assertCount(30, array_unique(array_merge($primera, $segunda)));
    }
}
