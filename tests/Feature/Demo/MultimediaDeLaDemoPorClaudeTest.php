<?php

namespace Tests\Feature\Demo;

use App\Models\DemoMedia;
use App\Models\SyncedGithubFile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `GET` y `PUT` de `claude/demo-media`: el canal por el que la sesión que produce los clips
 * apunta la URL de un slot sin pasar por la pantalla del admin.
 *
 * Lo que protege, y por qué importa: un clip publicado en R2 cuya URL nadie apuntó es un clip
 * que **no existe para el lead** — el panel le muestra el placeholder. Pasó con el `0.1`, que
 * estuvo publicado y verificado durante un día entero sirviendo el intro viejo de seis minutos.
 *
 * Se verifica que este endpoint tenga exactamente el mismo criterio que la pantalla del admin
 * (delega en `DemoMediaController`, no reimplementa la validación): que un slot que no está en
 * el catálogo se rechace, que una URL vacía borre la fila en vez de guardar cadena vacía, y que
 * sin la clave de ingesta no se pueda tocar nada.
 */
class MultimediaDeLaDemoPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /** Slot que existe en el catálogo sembrado más abajo. */
    const SLOT = '1.2';

    /**
     * Setea la clave de ingesta y siembra el catálogo sincronizado.
     *
     * En el .env del slot la clave está vacía y el middleware es fail-closed; y sin catálogo
     * sincronizado `DemoCatalogoService::slots()` devuelve `[]` y **cualquier** slot_id falla la
     * validación, así que sin esto todos los casos darían 422 por el motivo equivocado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        $catalogo = [
            'orden_secciones' => ['S1 - Listado'],
            'clips'           => [
                [
                    'id'      => '1.1',
                    'seccion' => 'S1 - Listado',
                    'titulo'  => 'Crear un articulo',
                    'tipo'    => 'nucleo',
                ],
                [
                    'id'      => self::SLOT,
                    'seccion' => 'S1 - Listado',
                    'titulo'  => 'Como se forma el precio',
                    'tipo'    => 'nucleo',
                ],
            ],
        ];

        $synced = SyncedGithubFile::where('key', 'demo_catalogo_json')->first();
        if (! $synced) {
            $synced      = new SyncedGithubFile();
            $synced->key = 'demo_catalogo_json';
        }
        // `repo_path` no tiene default en la tabla: sin esto el insert revienta con un 1364 que
        // no dice nada del test.
        $synced->repo_path = 'contexto/demo_catalogo.json';
        $synced->content   = json_encode($catalogo);
        $synced->synced_at = now();
        $synced->save();
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /** @test */
    public function el_get_devuelve_los_slots_del_catalogo_y_las_urls_cargadas()
    {
        DemoMedia::query()->updateOrCreate(
            ['slot_id' => self::SLOT],
            ['url' => 'https://media.comerciocity.store/1.2.mp4']
        );

        $res = $this->withHeaders($this->headers())->getJson('/api/claude/demo-media');

        $res->assertStatus(200);

        // ⚠️ No se usa assertJsonPath: el punto del id de slot ("1.2") lo interpreta como
        // separador de path y termina buscando urls -> 1 -> 2, que siempre da null. Se lee el
        // array y se compara la clave derecho.
        $urls = $res->json('urls');
        $this->assertSame('https://media.comerciocity.store/1.2.mp4', $urls[self::SLOT] ?? null);

        // La estructura sale del catálogo, no de la tabla: el slot 1.1 no tiene URL cargada y
        // tiene que aparecer igual, porque es lo que le dice a la sesión qué slots existen.
        $ids = array_column($res->json('slots'), 'id');
        $this->assertContains('1.1', $ids);
        $this->assertContains(self::SLOT, $ids);
    }

    /** @test */
    public function el_put_guarda_la_url_de_un_slot()
    {
        $url = 'https://media.comerciocity.store/1.2.mp4';

        $res = $this->withHeaders($this->headers())->putJson('/api/claude/demo-media', [
            'slot_id' => self::SLOT,
            'url'     => $url,
        ]);

        $res->assertStatus(200);
        $res->assertJson(['slot_id' => self::SLOT, 'url' => $url]);

        $this->assertDatabaseHas('demo_media', ['slot_id' => self::SLOT, 'url' => $url]);
    }

    /** @test */
    public function volver_a_guardar_el_mismo_slot_actualiza_la_fila_y_no_crea_una_segunda()
    {
        $headers = $this->headers();

        $this->withHeaders($headers)->putJson('/api/claude/demo-media', [
            'slot_id' => self::SLOT,
            'url'     => 'https://media.comerciocity.store/vieja.mp4',
        ])->assertStatus(200);

        $this->withHeaders($headers)->putJson('/api/claude/demo-media', [
            'slot_id' => self::SLOT,
            'url'     => 'https://media.comerciocity.store/1.2.mp4',
        ])->assertStatus(200);

        $this->assertSame(1, DemoMedia::where('slot_id', self::SLOT)->count());
        $this->assertDatabaseHas('demo_media', [
            'slot_id' => self::SLOT,
            'url'     => 'https://media.comerciocity.store/1.2.mp4',
        ]);
    }

    /** @test */
    public function guardar_vacio_borra_la_fila_y_vuelve_al_placeholder()
    {
        DemoMedia::query()->updateOrCreate(
            ['slot_id' => self::SLOT],
            ['url' => 'https://media.comerciocity.store/1.2.mp4']
        );

        $res = $this->withHeaders($this->headers())->putJson('/api/claude/demo-media', [
            'slot_id' => self::SLOT,
            'url'     => '',
        ]);

        $res->assertStatus(200);
        $res->assertJson(['slot_id' => self::SLOT, 'url' => null]);

        // Se borra la fila: "sin cargar" no es una fila con cadena vacía.
        $this->assertSame(0, DemoMedia::where('slot_id', self::SLOT)->count());
    }

    /** @test */
    public function un_slot_que_no_esta_en_el_catalogo_se_rechaza()
    {
        $res = $this->withHeaders($this->headers())->putJson('/api/claude/demo-media', [
            'slot_id' => '9.99',
            'url'     => 'https://media.comerciocity.store/9.99.mp4',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, DemoMedia::where('slot_id', '9.99')->count());
    }

    /** @test */
    public function una_url_con_formato_invalido_se_rechaza()
    {
        $res = $this->withHeaders($this->headers())->putJson('/api/claude/demo-media', [
            'slot_id' => self::SLOT,
            'url'     => 'no-es-una-url',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, DemoMedia::where('slot_id', self::SLOT)->count());
    }

    /** @test */
    public function sin_la_clave_de_ingesta_no_se_puede_leer_ni_escribir()
    {
        $this->getJson('/api/claude/demo-media')->assertStatus(401);

        $this->putJson('/api/claude/demo-media', [
            'slot_id' => self::SLOT,
            'url'     => 'https://media.comerciocity.store/1.2.mp4',
        ])->assertStatus(401);

        $this->assertSame(0, DemoMedia::where('slot_id', self::SLOT)->count());
    }
}
