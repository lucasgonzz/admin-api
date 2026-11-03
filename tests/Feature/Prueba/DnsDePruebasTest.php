<?php

namespace Tests\Feature\Prueba;

use App\Services\Prueba\PruebaDnsService;

/**
 * D9: la lista blanca, el interruptor propio y las guardas del DNS de las pruebas.
 */
class DnsDePruebasTest extends BaseDePruebaGratuita
{
    public function test_la_lista_blanca_son_los_cuatro_labels_del_slug_o_del_nombre_del_pool(): void
    {
        $dns = app(PruebaDnsService::class);

        $this->assertSame(['api-lacava', 'lacava', 'tienda-lacava', 'api-tienda-lacava'], $dns->labels_permitidos('lacava'));
        $this->assertSame(['api-prueba-07', 'prueba-07', 'tienda-prueba-07', 'api-tienda-prueba-07'], $dns->labels_permitidos('prueba-07'));

        foreach (['', '@', '*', 'www', 'demo3', 'Lacava', 'la cava', 'un-slug-demasiado-largo-de-verdad', '-lacava', 'api'] as $invalido) {
            try {
                $dns->labels_permitidos($invalido);
                $this->fail('"' . $invalido . '" tendría que ser inválido.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('inválida', $e->getMessage());
            }
        }
    }

    public function test_con_el_interruptor_apagado_no_se_lee_ni_se_escribe_nada(): void
    {
        config(['services.prueba.dns_write_enabled' => false]);

        try {
            app(PruebaDnsService::class)->crear_registros('lacava');
            $this->fail('Tendría que frenar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PRUEBA_DNS_WRITE_ENABLED', $e->getMessage());
        }

        $this->assertSame([], $this->hostinger->llamadas);
    }

    public function test_crear_escribe_solo_los_que_faltan_y_no_repunta_un_nombre_ajeno(): void
    {
        /* `lacava` ya apunta al VPS; `api-lacava` falta; los de la tienda faltan. */
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            $this->zona(['lacava']),
            $this->zona(['lacava', 'api-lacava', 'tienda-lacava', 'api-tienda-lacava']),
        ], 'GET');
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'PUT');

        $escritos = app(PruebaDnsService::class)->crear_registros('lacava');

        $this->assertSame(['api-lacava', 'tienda-lacava', 'api-tienda-lacava'], $escritos);
        $puts = $this->hostinger->llamadas_de('PUT');
        $this->assertCount(1, $puts);
        $this->assertFalse($puts[0]['body']['overwrite']);
        $this->assertSame(['api-lacava', 'tienda-lacava', 'api-tienda-lacava'], array_column($puts[0]['body']['zone'], 'name'));

        /* Un nombre que apunta a OTRA IP frena sin escribir. */
        $this->hostinger->limpiar();
        $this->hostinger->responder('/api/dns/v1/zones/', $this->zona([], [['name' => 'api-otro', 'type' => 'A', 'content' => '190.0.0.9']]), 'GET');
        try {
            app(PruebaDnsService::class)->crear_registros('otro');
            $this->fail('Tendría que frenar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('190.0.0.9', $e->getMessage());
        }
        $this->assertSame([], $this->hostinger->escrituras());
    }

    public function test_si_la_zona_perdio_un_registro_al_escribir_se_denuncia_y_no_se_restaura_solo(): void
    {
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            $this->zona([]),
            /* Después del PUT desapareció `otrocliente`. */
            array_slice($this->zona(['lacava', 'api-lacava', 'tienda-lacava', 'api-tienda-lacava']), 1),
        ], 'GET');
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'PUT');

        try {
            app(PruebaDnsService::class)->crear_registros('lacava');
            $this->fail('Tendría que denunciar la pérdida.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PERDIÓ REGISTROS', $e->getMessage());
            $this->assertStringContainsString('otrocliente|A', $e->getMessage());
        }

        $this->assertCount(1, $this->hostinger->llamadas_de('PUT'), 'No hay un segundo PUT de restauración.');
    }

    public function test_borrar_manda_los_filtros_de_los_que_apuntan_al_vps_y_respeta_a_los_ajenos(): void
    {
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            $this->zona(['lacava', 'api-lacava'], [['name' => 'tienda-lacava', 'type' => 'A', 'content' => '190.0.0.9']]),
            $this->zona([], [['name' => 'tienda-lacava', 'type' => 'A', 'content' => '190.0.0.9']]),
        ], 'GET');
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'DELETE');

        $borrados = app(PruebaDnsService::class)->borrar_registros('lacava');

        $this->assertSame(['api-lacava', 'lacava'], $borrados);
        $deletes = $this->hostinger->llamadas_de('DELETE');
        $this->assertCount(1, $deletes);
        $this->assertSame([['name' => 'api-lacava', 'type' => 'A'], ['name' => 'lacava', 'type' => 'A']], $deletes[0]['body']['filters']);
    }

    public function test_delete_dns_records_exige_filtros_con_name_y_type(): void
    {
        try {
            $this->hostinger->delete_dns_records([]);
            $this->fail('Sin filtros no se manda.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sin filtros', $e->getMessage());
        }
        try {
            $this->hostinger->delete_dns_records([['name' => 'x']]);
            $this->fail('Sin type no se manda.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('name y type', $e->getMessage());
        }

        $this->assertSame([], $this->hostinger->llamadas);
    }
}
