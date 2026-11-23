<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\Client;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * CA15 de la etapa E2: una prueba nueva por `POST landing/prueba/alta` nace SIN implementación (`no`), con el VPS y el ERP
 * falsificados como en los tests de alta existentes. (Los clientes que ya existen al desplegar quedan `incluida`: P7.)
 *
 * Hoy está EN ROJO: la columna no existe.
 */
class AltaDePruebaImplementacionTest extends BaseDelTableroDeJuan
{
    /**
     * @return void
     */
    public function test_la_prueba_nueva_nace_sin_implementacion(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);

        $respuesta = $this->postear_alta(['empresa' => 'Ferretería Norte', 'telefono' => '3444000001']);
        $respuesta->assertStatus(201);

        $lead = $this->lead_del_alta((string) $respuesta->json('uuid'));
        $client = Client::query()->findOrFail((int) $lead->promoted_client_id);

        $this->assertSame('no', (string) $client->implementacion_contratada);
    }
}
