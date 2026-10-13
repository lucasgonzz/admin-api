<?php

namespace Tests\Feature\Recursos;

use App\Models\RecursosProgreso;
use App\Models\RecursosVideo;

/**
 * La carga del catálogo del centro de recursos por Claude (`POST claude/recursos/catalogo`) y el
 * resumen (`GET claude/recursos/resumen`), misión centro-de-recursos-clientes (13/10/2026).
 *
 * Lo que más importa:
 *  1. 🔴 Un video que deja de venir queda inactivo y NO se borra (tiene avance de usuarios colgando).
 *  2. 🔴 La carga borra la caché del catálogo público: después de cargar, la página ve lo nuevo.
 *  3. Un payload con un video roto no se carga a medias.
 */
class CatalogoPorClaudeTest extends BaseDelCentroDeRecursos
{
    /**
     * Un video del payload, como lo arma `publicar_centro.py`.
     *
     * @param string               $codigo
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function video(string $codigo, array $cambios = []): array
    {
        return array_merge([
            'codigo'         => $codigo,
            'titulo'         => 'Título de ' . $codigo,
            'carpeta'        => 1,
            'carpeta_nombre' => 'Tus artículos (Listado)',
            'seccion'        => 'A · Lo básico',
            'orden'          => 1,
            'para_que'       => 'Para qué ' . $codigo,
            'cuando_no'      => 'Cuándo no ' . $codigo,
            'relacionados'   => [],
            'transcripcion'  => 'Transcripción de ' . $codigo,
            'duracion'       => 61.4,
            'url'            => 'https://videos.comerciocity.store/recursos/' . $codigo . '-1a2b3c4d.mp4',
            'miniatura_url'  => 'https://videos.comerciocity.store/recursos/' . $codigo . '-1a2b3c4d.jpg',
            'version'        => '1a2b3c4d',
            '_fuente'        => 'C:\\algo\\local.mp4',
        ], $cambios);
    }

    public function test_sin_clave_no_carga_nada(): void
    {
        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1')]])->assertStatus(401);
        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1')]], ['X-Claude-Task-Key' => 'otra'])->assertStatus(401);
        $this->getJson('/api/claude/recursos/resumen')->assertStatus(401);

        $this->assertSame(0, RecursosVideo::count());
    }

    public function test_carga_actualiza_y_no_toca_lo_que_no_cambio(): void
    {
        $this->postJson('/api/claude/recursos/catalogo', [
            'carpetas' => [['nro' => 1, 'nombre' => 'Tus artículos (Listado)'], ['nro' => 2, 'nombre' => 'Ventas']],
            'videos'   => [
                $this->video('T1.1', ['orden' => 1, 'relacionados' => ['T2.1', 'T1.1']]),
                // Sin carpeta_nombre: lo toma de `carpetas`.
                $this->video('T2.1', ['orden' => 2, 'carpeta' => 2, 'carpeta_nombre' => null]),
            ],
        ], $this->de_claude())
            ->assertStatus(200)
            ->assertExactJson(['creados' => 2, 'actualizados' => 0, 'sin_cambios' => 0, 'desactivados' => 0]);

        $t21 = RecursosVideo::where('codigo', 'T2.1')->first();
        $this->assertSame('Ventas', $t21->carpeta_nombre);
        $this->assertSame(61.4, $t21->duracion);
        // El propio código no queda como relacionado de sí mismo.
        $this->assertSame(['T2.1'], RecursosVideo::where('codigo', 'T1.1')->first()->relacionados);

        $this->postJson('/api/claude/recursos/catalogo', [
            'carpetas' => [['nro' => 1, 'nombre' => 'Tus artículos (Listado)'], ['nro' => 2, 'nombre' => 'Ventas']],
            'videos'   => [
                $this->video('T1.1', ['orden' => 1, 'relacionados' => ['T2.1']]),
                $this->video('T2.1', ['orden' => 2, 'carpeta' => 2, 'carpeta_nombre' => null, 'url' => 'https://videos.comerciocity.store/recursos/T2.1-99999999.mp4', 'version' => '99999999']),
                $this->video('T3.1', ['orden' => 3]),
            ],
        ], $this->de_claude())
            ->assertStatus(200)
            ->assertExactJson(['creados' => 1, 'actualizados' => 1, 'sin_cambios' => 1, 'desactivados' => 0]);

        $this->assertSame('99999999', RecursosVideo::where('codigo', 'T2.1')->value('version'));
    }

    /**
     * 🔴 Los que no vienen quedan inactivos, sin borrarse, con su avance intacto; y si vuelven a venir,
     * se reactivan. Sin `desactivar_faltantes` no se desactiva nada.
     */
    public function test_desactivar_faltantes_no_borra_y_un_video_que_vuelve_se_reactiva(): void
    {
        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1'), $this->video('T1.2', ['orden' => 2])]], $this->de_claude())
            ->assertStatus(200);

        $client = $this->crear_cliente('api-a.test');
        list($usuario) = $this->usuario_con_sesion($client);
        RecursosProgreso::create([
            'recursos_usuario_id' => $usuario->id, 'recursos_video_id' => RecursosVideo::where('codigo', 'T1.2')->value('id'),
            'vistos' => str_repeat('1', 62), 'porcentaje' => 100, 'ultima_posicion' => 61, 'terminado_at' => now(),
        ]);

        // Sin el flag, T1.2 sigue activo aunque no venga.
        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1')]], $this->de_claude())
            ->assertJsonPath('desactivados', 0);
        $this->assertTrue(RecursosVideo::where('codigo', 'T1.2')->value('activo'));

        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1')], 'desactivar_faltantes' => true], $this->de_claude())
            ->assertStatus(200)
            ->assertJsonPath('desactivados', 1);

        $this->assertSame(2, RecursosVideo::count());
        $this->assertFalse(RecursosVideo::where('codigo', 'T1.2')->first()->activo);
        $this->assertSame(1, RecursosProgreso::where('recursos_usuario_id', $usuario->id)->count());

        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1'), $this->video('T1.2', ['orden' => 2])]], $this->de_claude())
            ->assertStatus(200)
            ->assertJsonPath('actualizados', 1);
        $this->assertTrue(RecursosVideo::where('codigo', 'T1.2')->first()->activo);
    }

    /**
     * 🔴 El catálogo público está cacheado para siempre: la carga es lo que lo invalida.
     */
    public function test_la_carga_invalida_la_cache_del_catalogo_y_del_buscador(): void
    {
        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1')]], $this->de_claude())->assertStatus(200);

        $this->assertSame(['T1.1'], array_column($this->getJson('/api/recursos/catalogo')->json('videos'), 'codigo'));
        $this->assertSame(['T1.1'], array_keys($this->getJson('/api/recursos/buscar-indice')->json()));

        // Un cambio por fuera de la carga NO se ve: la caché está haciendo su trabajo.
        RecursosVideo::where('codigo', 'T1.1')->update(['titulo' => 'Cambiado a mano']);
        $this->assertSame('Título de T1.1', $this->getJson('/api/recursos/catalogo')->json('videos.0.titulo'));

        $this->postJson('/api/claude/recursos/catalogo', [
            'videos'               => [$this->video('T1.1', ['titulo' => 'Título nuevo']), $this->video('T1.5', ['orden' => 5])],
            'desactivar_faltantes' => true,
        ], $this->de_claude())->assertStatus(200);

        $catalogo = $this->getJson('/api/recursos/catalogo')->json();
        $this->assertSame(['T1.1', 'T1.5'], array_column($catalogo['videos'], 'codigo'));
        $this->assertSame('Título nuevo', $catalogo['videos'][0]['titulo']);
        $this->assertSame(['T1.1', 'T1.5'], array_keys($this->getJson('/api/recursos/buscar-indice')->json()));
    }

    public function test_un_payload_roto_es_422_y_no_escribe_nada(): void
    {
        $casos = [
            'sin videos'           => ['videos' => []],
            'videos no es lista'   => ['videos' => 'hola'],
            'sin código'           => ['videos' => [$this->video('T1.1'), $this->video('', ['orden' => 2])]],
            'código repetido'      => ['videos' => [$this->video('T1.1'), $this->video('T1.1')]],
            'sin url'              => ['videos' => [$this->video('T1.1', ['url' => null])]],
            'url que no es http'   => ['videos' => [$this->video('T1.1', ['url' => 'javascript:alert(1)'])]],
            'duración cero'        => ['videos' => [$this->video('T1.1', ['duracion' => 0])]],
            'carpeta sin nombre'   => ['videos' => [$this->video('T1.1', ['carpeta' => 9, 'carpeta_nombre' => null])]],
            'clave desconocida'    => ['videos' => [$this->video('T1.1')], 'desactivar_faltante' => true],
            'flag que no es bool'  => ['videos' => [$this->video('T1.1')], 'desactivar_faltantes' => 'si'],
            'relacionados raro'    => ['videos' => [$this->video('T1.1', ['relacionados' => 'T1.2'])]],
        ];

        foreach ($casos as $caso => $payload) {
            $respuesta = $this->postJson('/api/claude/recursos/catalogo', $payload, $this->de_claude());
            $respuesta->assertStatus(422);
            $this->assertSame('datos_invalidos', $respuesta->json('error'), $caso);
            $this->assertNotEmpty($respuesta->json('errores'), $caso);
        }

        $this->assertSame(0, RecursosVideo::count());
    }

    public function test_el_resumen_cuenta_lo_del_centro(): void
    {
        $this->postJson('/api/claude/recursos/catalogo', ['videos' => [$this->video('T1.1')]], $this->de_claude())->assertStatus(200);

        $client = $this->crear_cliente('api-a.test');
        list(, $token) = $this->usuario_con_sesion($client, ['ultimo_ingreso_at' => now()]);

        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => -1], $this->con_sesion($token))->assertStatus(200);
        $this->postJson('/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => '¿Y esto?'], $this->con_sesion($token))->assertStatus(201);

        $respuesta = $this->getJson('/api/claude/recursos/resumen', $this->de_claude());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('videos.activos', 1);
        $respuesta->assertJsonPath('usuarios.total', 1);
        $respuesta->assertJsonPath('usuarios.activos_7_dias', 1);
        $respuesta->assertJsonPath('votos.negativos', 1);
        $respuesta->assertJsonPath('preguntas.pendientes', 1);
    }
}
