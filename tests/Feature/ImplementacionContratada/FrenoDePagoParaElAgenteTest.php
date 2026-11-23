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
 * CA42 de la etapa E2 (misión embudo-juan-pipeline, agregado de los audios de Juan): el freno del agente para «pagó la mitad».
 *
 * Pausar la prueba, avisar al ERP y crear la implementación no pueden salir de la transcripción de un audio sin una mirada humana:
 * `POST lead/{id}/llamadas` con `paquetes` (que sólo viaja con «pagó la mitad») sube a la categoría `plata`, y el agente recibe 428
 * con el preview hasta que llega el código de confirmación. Juan (un humano) no tiene freno. Los demás resultados no se frenan.
 *
 * Hoy está EN ROJO: `config/panel_agent.php` no tiene el freno y `paquetes` no existe.
 */
class FrenoDePagoParaElAgenteTest extends BaseDelTableroDeJuan
{
    use AgentePanelTestHelpers;

    /**
     * Siembra el tablero de Juan.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();
    }

    /**
     * Una prueba real (por el alta de la landing) con su cliente y su lead.
     *
     * @param string $telefono
     *
     * @return array{0: Client, 1: Lead}
     */
    private function prueba_lista(string $telefono = '3444000002'): array
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);
        $alta   = $this->postear_alta(['telefono' => $telefono, 'empresa' => 'Ferretería Norte']);
        $this->assertSame(201, $alta->getStatusCode(), substr((string) $alta->getContent(), 0, 300));
        $uuid   = $alta->json('uuid');
        $lead   = $this->lead_del_alta($uuid);
        $client = Client::find($lead->promoted_client_id);
        TrialInstance::query()->where('client_id', $client->id)->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100]);

        return [$client->fresh(), $lead->fresh()];
    }

    /**
     * El cuerpo de «pagó la mitad».
     *
     * @return array<string, mixed>
     */
    private function cuerpo_de_pago(): array
    {
        return ['atendio' => true, 'resultado' => 'pago_la_mitad', 'paquetes' => ['gestion'], 'origen' => 'audio_juan', 'origen_clave' => 'wa:pago'];
    }

    /**
     * @return void
     */
    public function test_el_clasificador_sube_a_plata_con_paquetes(): void
    {
        $clasificador = app(PanelAgentOperationClassifier::class);

        $this->assertSame('escritura', $clasificador->classify('POST', 'api/admin/lead/{id}/llamadas', []));
        $this->assertSame('plata', $clasificador->classify('POST', 'api/admin/lead/{id}/llamadas', ['paquetes' => ['gestion']]));
    }

    /**
     * Sin código: 428 con preview, sin fila en `lead_llamadas` y la prueba sin pausar; con el código del preview: 201 y los efectos
     * de CA17.
     *
     * @return void
     */
    public function test_el_agente_necesita_el_codigo_para_registrar_el_pago(): void
    {
        list($client, $lead) = $this->prueba_lista();
        $agente = $this->crear_agente('Agente de WhatsApp');
        $token  = $this->token_de($agente);
        $uri    = '/api/admin/lead/' . $lead->id . '/llamadas';

        $preview = $this->pedir_como($token, 'POST', $uri, $this->cuerpo_de_pago());

        $preview->assertStatus(428);
        $this->assertNotEmpty($preview->json('codigo_confirmacion'));
        $this->assertSame('plata', $preview->json('categoria'));
        $this->assertSame(0, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());
        $this->assertNull($client->fresh()->prueba_pausada_at, 'La prueba sigue sin pausar.');

        $this->pedir_como($token, 'POST', $uri, $this->cuerpo_de_pago(), ['X-Agente-Confirmar' => $preview->json('codigo_confirmacion')])->assertStatus(201);

        $client = $client->fresh();
        $this->assertSame('mitad', (string) $client->implementacion_contratada);
        $this->assertNotNull($client->prueba_pausada_at);
        $this->assertSame('en_implementacion', (string) $lead->fresh()->status);
    }

    /**
     * Juan (humano) con el mismo cuerpo: 201 sin código.
     *
     * @return void
     */
    public function test_juan_lo_registra_sin_codigo(): void
    {
        list($client, $lead) = $this->prueba_lista();
        $this->loguear_a($this->juan);

        $r = $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => true, 'resultado' => 'pago_la_mitad', 'paquetes' => ['gestion']]);
        $this->assertSame(201, $r->getStatusCode(), substr((string) $r->getContent(), 0, 500));

        $this->assertSame('mitad', (string) $client->fresh()->implementacion_contratada);
    }

    /**
     * El resto de los resultados del agente (sin `paquetes`) se registra sin freno.
     *
     * @return void
     */
    public function test_los_otros_resultados_no_se_frenan(): void
    {
        list($client, $lead) = $this->prueba_lista();
        $token = $this->token_de($this->crear_agente('Agente de WhatsApp'));

        $this->pedir_como($token, 'POST', '/api/admin/lead/' . $lead->id . '/llamadas', [
            'atendio' => true, 'resultado' => 'sigue_solo', 'origen' => 'audio_juan', 'origen_clave' => 'wa:solo',
        ])->assertStatus(201);
    }
}
