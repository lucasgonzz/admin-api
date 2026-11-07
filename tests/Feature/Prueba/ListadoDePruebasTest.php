<?php

namespace Tests\Feature\Prueba;

use App\Models\TrialInstance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/** El listado conserva la historia sin convertir nombres reciclados en accesos a otro negocio. */
class ListadoDePruebasTest extends BaseDePruebaGratuita
{
    public function test_el_listado_exige_autenticacion(): void
    {
        $this->getJson('/api/admin/prueba/instancias')->assertStatus(401);
    }

    public function test_el_pool_vacio_devuelve_el_listado_y_su_resumen(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/prueba/instancias')
            ->assertStatus(200)
            ->assertExactJson([
                'instancias' => [],
                'resumen' => [
                    'libres' => 0, 'creando' => 0, 'activas' => 0, 'dormidas' => 0, 'fallidas' => 0,
                    'pool_minimo' => 2, 'max_activas' => 25, 'dias_default' => 7,
                ],
            ]);
    }

    /** @dataProvider borradas_con_y_sin_alias */
    public function test_una_borrada_conserva_su_historia_sin_url_ni_datos_privados(string $nombre, ?string $alias): void
    {
        $instancia = $this->crear_instancia_libre($nombre);
        $instancia->update([
            'estado' => TrialInstance::ESTADO_BORRADA,
            'alias_slug' => $alias,
            'borrada_at' => now(),
            'respaldo_ruta' => '/respaldo-sintetico/prueba-01',
            'contexto_meta' => ['fbp' => 'contexto-privado-del-test', 'ip' => '192.0.2.1'],
            'password_cifrada' => 'password-privada-del-test',
        ]);
        $antes = $instancia->fresh()->getAttributes();

        $respuesta = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/prueba/instancias')
            ->assertStatus(200)->assertJsonCount(1, 'instancias')
            ->assertJsonPath('instancias.0.id', $instancia->id)
            ->assertJsonPath('instancias.0.nombre', $nombre)
            ->assertJsonPath('instancias.0.estado', TrialInstance::ESTADO_BORRADA)
            ->assertJsonPath('instancias.0.alias_slug', $alias)
            ->assertJsonPath('instancias.0.url', null)
            ->assertJsonPath('instancias.0.borrada_at', $instancia->borrada_at->toIso8601String())
            ->assertJsonPath('instancias.0.respaldo_ruta', '/respaldo-sintetico/prueba-01')
            ->assertJsonPath('resumen.libres', 0)->assertJsonPath('resumen.activas', 0);

        $fila = $respuesta->json('instancias.0');
        $this->assertArrayHasKey('url', $fila, 'La URL queda nula, no se elimina del contrato.');
        $this->assertArrayNotHasKey('contexto_meta', $fila);
        $this->assertArrayNotHasKey('password_cifrada', $fila);
        $this->assertStringNotContainsString('contexto-privado-del-test', $respuesta->getContent());
        $this->assertStringNotContainsString('password-privada-del-test', $respuesta->getContent());
        $this->assertSame($antes, $instancia->fresh()->getAttributes(), 'Listar no modifica la fila historica.');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertSame([], $this->vps->comandos);
        $this->assertSame([], $this->hostinger->llamadas_de('GET'));
    }

    public function borradas_con_y_sin_alias(): array
    {
        return [
            'nombre original sin alias' => ['prueba-01', null],
            'nombre reciclado sin alias' => ['prueba-01~abcdef', null],
            'nombre reciclado con alias vacio' => ['prueba-01~abcdef', ''],
            'nombre reciclado con alias valido' => ['prueba-01~abcdef', 'negocio-anterior'],
            'nombre reciclado con alias invalido' => ['prueba-01~abcdef', 'alias invalido'],
        ];
    }

    public function test_las_no_borradas_conservan_sus_urls_y_los_contadores(): void
    {
        $estados = [
            TrialInstance::ESTADO_CREANDO, TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_ASIGNANDO,
            TrialInstance::ESTADO_ASIGNADA, TrialInstance::ESTADO_CLIENTE, TrialInstance::ESTADO_BORRANDO,
            TrialInstance::ESTADO_FALLIDA,
        ];
        foreach ($estados as $indice => $estado) {
            $instancia = $this->crear_instancia_libre('prueba-0' . ($indice + 1), 900100 + $indice * 100);
            $instancia->update([
                'estado' => $estado,
                'alias_slug' => $estado === TrialInstance::ESTADO_ASIGNADA ? 'negocio-actual' : null,
                'contexto_meta' => ['fbp' => 'contexto-activo-privado'],
            ]);
        }
        $dormida = $this->crear_instancia_libre('prueba-08', 900800);
        $dormida->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'dormida_at' => now()]);

        $respuesta = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/prueba/instancias')
            ->assertStatus(200)->assertJsonCount(8, 'instancias')
            ->assertJsonPath('resumen', [
                'libres' => 1, 'creando' => 1, 'activas' => 3, 'dormidas' => 1, 'fallidas' => 1,
                'pool_minimo' => 2, 'max_activas' => 25, 'dias_default' => 7,
            ]);

        foreach ($respuesta->json('instancias') as $fila) {
            $host = $fila['alias_slug'] ?: $fila['nombre'];
            $this->assertSame('https://' . $host . '.comerciocity.com', $fila['url']);
            $this->assertArrayNotHasKey('contexto_meta', $fila);
        }
        $this->assertStringNotContainsString('contexto-activo-privado', $respuesta->getContent());
    }

    /** @dataProvider identificadores_invalidos_activos */
    public function test_las_instancias_no_borradas_siguen_validando_sus_identificadores(string $nombre, ?string $alias, string $mensaje): void
    {
        $instancia = $this->crear_instancia_libre($nombre);
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'alias_slug' => $alias]);
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($mensaje);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/prueba/instancias');
    }

    public function identificadores_invalidos_activos(): array
    {
        return [
            'nombre historico no se normaliza' => ['prueba-01~abcdef', null, 'Nombre de instancia'],
            'nombre incompleto no se acepta' => ['prueba-1', null, 'Nombre de instancia'],
            'alias con espacios no se acepta' => ['prueba-01', 'alias invalido', 'Slug de prueba'],
            'alias reservado no se acepta' => ['prueba-01', 'admin', 'Slug de prueba'],
            'alias con prefijo ajeno no se acepta' => ['prueba-01', 'api-negocio', 'Slug de prueba'],
        ];
    }
}