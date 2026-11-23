<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\PanelAgent\PanelAgentOperationClassifier;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PanelAgent\AgentePanelTestHelpers;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * Enmienda 1 de la ronda 2 de E2 (misión embudo-juan-pipeline): el freno `plata` del agente cubre TODO camino que pause la prueba o
 * abra la implementación.
 *
 *  - `POST lead/{id}/llamadas` con `resultado = pago_la_mitad`, con o sin `paquetes`.
 *  - `PUT client/{id}/implementacion-contratada` con `estado` mitad o completa.
 * Juan y Lucas desde la SPA (humanos) no se frenan, y los demás resultados y estados del agente tampoco.
 */
class FrenoAmpliadoDelAgenteTest extends BaseDelTableroDeJuan
{
    use AgentePanelTestHelpers;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();
    }

    /**
     * Una prueba real (por el alta de la landing).
     *
     * @return array{0: Client, 1: Lead}
     */
    private function prueba_lista(): array
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);
        $alta = $this->postear_alta(['telefono' => '3444000003', 'empresa' => 'Ferretería Norte']);
        $this->assertSame(201, $alta->getStatusCode(), substr((string) $alta->getContent(), 0, 300));
        $lead   = $this->lead_del_alta($alta->json('uuid'));
        $client = Client::find($lead->promoted_client_id);
        TrialInstance::query()->where('client_id', $client->id)->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100]);

        return [$client->fresh(), $lead->fresh()];
    }

    /**
     * @return void
     */
    public function test_el_clasificador_frena_pago_la_mitad_con_o_sin_paquetes_y_el_put_de_la_ficha(): void
    {
        $c = app(PanelAgentOperationClassifier::class);

        $this->assertSame('plata', $c->classify('POST', 'api/admin/lead/{id}/llamadas', ['resultado' => 'pago_la_mitad']));
        $this->assertSame('plata', $c->classify('POST', 'api/admin/lead/{id}/llamadas', ['resultado' => 'pago_la_mitad', 'paquetes' => ['gestion']]));
        $this->assertSame('escritura', $c->classify('POST', 'api/admin/lead/{id}/llamadas', ['resultado' => 'sigue_solo']));
        $this->assertSame('plata', $c->classify('PUT', 'api/admin/client/{id}/implementacion-contratada', ['estado' => 'mitad']));
        $this->assertSame('plata', $c->classify('PUT', 'api/admin/client/{id}/implementacion-contratada', ['estado' => 'completa']));
        $this->assertSame('escritura', $c->classify('PUT', 'api/admin/client/{id}/implementacion-contratada', ['estado' => 'no']));
    }

    /**
     * El agente, sin código, no pausa la prueba por la ficha del cliente.
     *
     * @return void
     */
    public function test_el_agente_no_puede_pagar_la_mitad_por_la_ficha_sin_codigo(): void
    {
        list($client) = $this->prueba_lista();
        $token = $this->token_de($this->crear_agente('Agente de WhatsApp'));

        $respuesta = $this->pedir_como($token, 'PUT', '/api/admin/client/' . $client->id . '/implementacion-contratada', ['estado' => 'mitad', 'paquetes' => ['gestion']]);

        $respuesta->assertStatus(428);
        $this->assertNull($client->fresh()->prueba_pausada_at);
        $this->assertSame('no', (string) $client->fresh()->implementacion_contratada);
    }

    /**
     * El agente, sin código, no registra «pagó la mitad» de un lead sin prueba y sin paquetes.
     *
     * @return void
     */
    public function test_el_agente_no_puede_pagar_la_mitad_sin_paquetes(): void
    {
        $lead  = $this->crear_lead_del_tablero('link_enviado');
        $token = $this->token_de($this->crear_agente('Agente de WhatsApp'));

        $this->pedir_como($token, 'POST', '/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => true, 'resultado' => 'pago_la_mitad'])->assertStatus(428);

        $this->assertSame(0, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());
        $this->assertSame('link_enviado', (string) $lead->fresh()->status);
    }

    /**
     * Lucas (humano) desde la SPA no tiene freno.
     *
     * @return void
     */
    public function test_un_humano_no_tiene_freno_en_la_ficha(): void
    {
        list($client) = $this->prueba_lista();
        $this->loguear_a($this->crear_admin_del_tablero('Lucas'));

        $this->putJson('/api/admin/client/' . $client->id . '/implementacion-contratada', ['estado' => 'mitad', 'paquetes' => ['gestion']])->assertStatus(200);

        $this->assertSame('mitad', (string) $client->fresh()->implementacion_contratada);
    }
}
