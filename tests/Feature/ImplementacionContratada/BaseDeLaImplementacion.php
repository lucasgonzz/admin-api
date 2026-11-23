<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaContextoSoporteService;
use App\Services\SupportPruebaContextService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\PruebaGratuita\BaseDeLaPrueba;
use Tests\Feature\TableroDeJuan\ArmadoDelTableroDeJuan;

/**
 * Base de los tests de la etapa E2 (misión embudo-juan-pipeline, implementación contratada, pausa y compuertas).
 *
 * Hereda de `PruebaGratuita\BaseDeLaPrueba` (nada sale a la red, el envío a Meta se espía, hay cliente en prueba, lead y ticket)
 * y suma el armado del tablero de Juan de E1. Deja:
 *  - el reloj en el lunes 23/11/2026 a las 12:00 de Argentina;
 *  - el tablero sembrado, a Juan (perfil «Sólo Leads») y a Lucas (setter) logueado, como el admin al que le llegan las tareas
 *    de la implementación (`implementation_assigned_admin_id`);
 *  - ayudantes para armar una prueba con lead y tarjeta, leer los pedidos C3 al ERP y el contexto de Soporte.
 *
 * No termina en `Test.php` a propósito.
 */
abstract class BaseDeLaImplementacion extends BaseDeLaPrueba
{
    use ArmadoDelTableroDeJuan;

    /** @var Admin Juan. */
    protected $juan;

    /** @var Admin Lucas: setter y asignado por defecto de las tareas de la implementación. */
    protected $lucas;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clavar_el_reloj(self::$AHORA_DEL_TABLERO);

        $this->sembrar_el_tablero();
        $this->juan  = $this->crear_a_juan();
        $this->lucas = $this->crear_admin_del_tablero('Lucas');
        $this->lucas->es_setter = true;
        $this->lucas->save();
        AdminSetting::set('implementation_assigned_admin_id', (string) $this->lucas->id);

        $this->loguear_a($this->lucas);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Una prueba en curso, con su lead (`prueba_activa`) y su tarjeta en «En prueba por su cuenta». Nace como nacen las pruebas
     * nuevas: sin implementación contratada (`no`). Vence el 27/11/2026 a las 12:00.
     *
     * @param string $telefono
     *
     * @return array{0: Client, 1: Lead}
     */
    protected function prueba_con_lead(string $telefono = '+5493411234567'): array
    {
        $client = $this->cliente_en_prueba(4, 7, $telefono);
        Client::query()->whereKey($client->id)->update([
            'trial_days'               => 7,
            'trial_ends_at'            => Carbon::parse('2026-11-27 12:00:00', self::$ZONA_DEL_TABLERO),
            'implementacion_contratada' => 'no',
        ]);

        $lead = $this->lead_en_prueba($client->fresh());
        Lead::query()->whereKey($lead->id)->update(['status' => 'prueba_activa', 'company_name' => 'Ferretería del Centro']);
        $lead = $lead->fresh();
        $this->dar_de_alta_en($lead, 'en_prueba_sola');

        return [$client->fresh(), $lead];
    }

    /**
     * Los pedidos C3 (`admin-sync/prueba/vencimiento`) que salieron hacia el ERP, con su cuerpo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pedidos_c3(): array
    {
        $salida = [];
        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'admin-sync/prueba/vencimiento') !== false) {
                $salida[] = $par[0]->data();
            }
        }

        return $salida;
    }

    /**
     * El contexto de Soporte (`GET claude/support-codex/tickets/{id}`) del cliente.
     *
     * @param Client $client
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function contexto_de_soporte(Client $client)
    {
        $ticket = $this->ticket_del_cliente($client);

        return $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude());
    }

    /**
     * Fija el estado de la implementación contratada por la ficha del cliente (`PUT client/{id}/implementacion-contratada`).
     *
     * @param Client        $client
     * @param string        $estado
     * @param array<string> $paquetes
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function fijar_implementacion(Client $client, string $estado, array $paquetes = [])
    {
        $this->loguear_a($this->lucas);

        return $this->putJson('/api/admin/client/' . $client->id . '/implementacion-contratada', ['estado' => $estado, 'paquetes' => $paquetes]);
    }
}
