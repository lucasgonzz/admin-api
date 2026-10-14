<?php

namespace Tests\Feature\ClaudeImplementaciones;

/**
 * `GET claude/implementations/{id}` dice qué sistemas contrató el cliente y si la tienda online está
 * entre ellos (misión mails-implementacion-orden-nuevo, 14/10/2026): así la skill `/implementar` sabe
 * sola si le toca el paso de la tienda, sin preguntárselo a Lucas.
 *
 * Lo que se protege:
 *
 *  1. 🔴 Que un "no" sea un no de verdad: `tienda_contratada` es false solo con la lista del contrato
 *     cargada y sin `ecommerce`. Sin lista (un contrato anterior a la modalidad, o sin cargar) es null:
 *     no se sabe, y la skill pregunta. Un false por falta de dato dejaría a un cliente sin la tienda
 *     que pagó.
 *  2. Que los campos se SUMEN al bloque `cliente` sin tocar los que ya estaban (compatible hacia atrás).
 */
class TiendaContratadaEnElEstadoPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * El bloque `cliente` del estado de una implementación cuyo cliente tiene esos sistemas contratados.
     *
     * @param array<int, string>|null $sistemas `contract_sistemas` del cliente (null = sin cargar).
     *
     * @return array<string, mixed>
     */
    private function cliente_con(?array $sistemas): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['contract_sistemas' => $sistemas]);
        $cliente = $this->crear_las_dos_apis($cliente, 'panchito');
        $impl    = $this->crear_implementacion($cliente);

        $respuesta = $this->getJson('/api/claude/implementations/' . $impl->id, $this->headers());
        $respuesta->assertStatus(200);

        return $respuesta->json('cliente');
    }

    /**
     * Con `ecommerce` en el contrato, la tienda está contratada; y la lista viaja tal cual.
     *
     * @return void
     */
    public function test_con_ecommerce_en_el_contrato_la_tienda_esta_contratada(): void
    {
        $cliente = $this->cliente_con(['gestion', 'ecommerce']);

        $this->assertSame(['gestion', 'ecommerce'], $cliente['contract_sistemas']);
        $this->assertTrue($cliente['tienda_contratada']);
    }

    /**
     * Con la lista cargada y sin `ecommerce`, la tienda NO está contratada.
     *
     * @return void
     */
    public function test_sin_ecommerce_en_el_contrato_la_tienda_no_esta_contratada(): void
    {
        $cliente = $this->cliente_con(['gestion', 'agentes']);

        $this->assertSame(['gestion', 'agentes'], $cliente['contract_sistemas']);
        $this->assertFalse($cliente['tienda_contratada']);
    }

    /**
     * 🔴 Sin la lista (null o vacía) no se sabe: los dos campos son null, nunca false.
     *
     * @return void
     */
    public function test_sin_la_lista_del_contrato_no_se_sabe(): void
    {
        foreach ([null, []] as $sistemas) {
            $cliente = $this->cliente_con($sistemas);

            $this->assertArrayHasKey('tienda_contratada', $cliente);
            $this->assertNull($cliente['contract_sistemas'], json_encode($sistemas));
            $this->assertNull($cliente['tienda_contratada'], json_encode($sistemas));
        }
    }

    /**
     * Los campos de antes siguen todos, con los mismos valores: los nuevos se suman.
     *
     * @return void
     */
    public function test_los_campos_de_antes_siguen_igual(): void
    {
        $cliente = $this->cliente_con(['ecommerce']);

        foreach (['id', 'name', 'company_name', 'slug', 'user_id', 'is_active', 'sistema'] as $campo) {
            $this->assertArrayHasKey($campo, $cliente, $campo);
        }

        $this->assertSame('Panchito Gómez', $cliente['name']);
        $this->assertSame('shared_hosting', $cliente['sistema']['hosting_type']);
    }
}
