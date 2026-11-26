<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use Illuminate\Support\Facades\Http;

/**
 * Control del guardián de red de `BaseDeReferidosYCompromisos` (misión leads-referidos-compromisos, E2).
 *
 * El guardián denuncia en el `tearDown` todo pedido HTTP que ningún stub atendió. Se reescribió para no anotar desde el callback
 * del stub `'*'` (Laravel 8 invoca todos los stubs y lo anotaba también cuando atendía la IA simulada). Estos dos tests prueban que
 * no se aflojó: un pedido a un host sin stub lo hace fallar, y uno que atiende un stub, no.
 */
class GuardianDeRedDeLaBaseTest extends BaseDeReferidosYCompromisos
{
    /**
     * Un pedido a un host que nadie previó queda denunciado por el guardián (y el 500 con la marca llega al código que llamó).
     *
     * @return void
     */
    public function test_un_pedido_a_un_host_sin_stub_lo_denuncia(): void
    {
        $respuesta = Http::post('https://host-que-nadie-previo.example.com/algo', ['x' => 1]);

        $this->assertSame(500, $respuesta->status());
        $this->assertSame(['POST https://host-que-nadie-previo.example.com/algo'], $this->pedidos_sin_stub());

        /* Este test provocó el pedido a propósito: el guardián ya demostró que lo ve y no lo vuelve a denunciar en el tearDown. */
        $this->guardian_comprobado = true;
    }

    /**
     * Un pedido que atiende un stub (la IA simulada) NO se denuncia.
     *
     * @return void
     */
    public function test_un_pedido_que_atiende_un_stub_no_se_denuncia(): void
    {
        $this->fakear_la_ia_de_compromisos([['ok' => true]]);

        $respuesta = Http::post('https://api.anthropic.com/v1/messages', ['x' => 1]);

        $this->assertSame(200, $respuesta->status());
        $this->assertSame([], $this->pedidos_sin_stub());
    }
}
