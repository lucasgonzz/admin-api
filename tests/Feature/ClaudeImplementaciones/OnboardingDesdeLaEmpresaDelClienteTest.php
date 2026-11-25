<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use App\Models\Client;
use App\Services\AgentOnboardingSyncService;
use App\Services\IaApp\IaEmpresaCliente;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Misión onboarding-conversacional-whatsapp (D8, contrato C1): el admin le pregunta a la empresa del cliente si el
 * dueño ya contó su negocio (formulario de la app o los temas esenciales por WhatsApp) y marca solo el hito
 * `onboarding_completado` de la etapa 1, que hasta hoy sólo marcaba Claude o Martín a mano.
 *
 * Datos ficticios; nada sale a la red (Http::fake sobre la fábrica sin salida).
 */
class OnboardingDesdeLaEmpresaDelClienteTest extends BaseDeImplementaciones
{
    const RUTA = '/api/claude/agent-implementations';

    /** La ruta del contrato C1 en la empresa del cliente. */
    const RUTA_ESTADO = 'api/admin-sync/agente-contexto/estado';

    /** Una implementación Full abierta, con su cliente y las dos APIs. */
    private function implementacion_abierta(string $nombre = 'Cliente Ficticio'): array
    {
        $client = $this->crear_cliente($nombre);
        $client = $this->crear_las_dos_apis($client, 'ficticio' . random_int(100, 999));

        $respuesta = $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'propio'], $this->headers());
        $respuesta->assertStatus(201);

        return [$client, AgentImplementation::findOrFail((int) $respuesta->json('model.id'))];
    }

    /** La URL del estado en la empresa del cliente, como la arma el resolver real. */
    private function url_de_estado(Client $client): string
    {
        return app(IaEmpresaCliente::class)->base_url($client) . '/' . self::RUTA_ESTADO;
    }

    /** Falsea la respuesta de la empresa (sin comodín: un pedido no previsto rompe el test). */
    private function empresa_responde(Client $client, $respuesta): void
    {
        Http::swap(new HttpFactorySinSalida());
        Http::fake([$this->url_de_estado($client) => $respuesta]);
    }

    private function datos_de_la_etapa_1(AgentImplementation $impl): array
    {
        $etapa = AgentImplementationStage::where('agent_implementation_id', $impl->id)->where('stage_number', 1)->first();

        return $etapa !== null && is_array($etapa->data) ? $etapa->data : [];
    }

    private function eventos_del_sistema(AgentImplementation $impl): int
    {
        return AgentImplementationEvent::where('agent_implementation_id', $impl->id)->where('source', 'sistema')->count();
    }

    private function estado_completo(string $por = 'whatsapp'): array
    {
        return [
            'cuestionario'              => ['completado' => false, 'salteado' => false, 'completado_en' => null],
            'onboarding'                => ['estado' => 'en_curso', 'esenciales_completos' => true],
            'onboarding_completado'     => true,
            'onboarding_completado_en'  => '2026-11-24T10:00:00-03:00',
            'onboarding_completado_por' => $por,
        ];
    }

    public function test_marca_el_hito(): void
    {
        list($client, $impl) = $this->implementacion_abierta();

        $this->empresa_responde($client, Http::response($this->estado_completo('whatsapp'), 200));

        $this->assertSame('marcado', app(AgentOnboardingSyncService::class)->sincronizar($impl));

        $datos = $this->datos_de_la_etapa_1($impl);

        $this->assertTrue($datos['onboarding_completado']);
        $this->assertSame('whatsapp', $datos['onboarding_completado_por']);
        $this->assertSame('2026-11-24T10:00:00-03:00', $datos['onboarding_completado_en']);

        $nota = AgentImplementationEvent::where('agent_implementation_id', $impl->id)->where('source', 'sistema')->where('kind', 'nota')->first();

        $this->assertNotNull($nota, 'Queda una nota en la bitácora con source sistema.');
        $this->assertStringContainsString('WhatsApp', $nota->texto);
        $this->assertSame(1, (int) $nota->stage_number);

        Http::assertSent(function (Request $pedido) use ($client) {
            return $pedido->method() === 'GET'
                && $pedido->url() === $this->url_de_estado($client)
                && $pedido->header('X-Admin-Api-Key')[0] === $client->api_key;
        });
    }

    public function test_idempotente(): void
    {
        list($client, $impl) = $this->implementacion_abierta();

        $this->empresa_responde($client, Http::response($this->estado_completo('app'), 200));

        $this->assertSame('marcado', app(AgentOnboardingSyncService::class)->sincronizar($impl));

        $eventos = AgentImplementationEvent::where('agent_implementation_id', $impl->id)->count();

        // Corrido otra vez (a mano, o por el comando de cada media hora): no agrega nada ni vuelve a preguntar.
        $this->assertSame('ya_estaba', app(AgentOnboardingSyncService::class)->sincronizar($impl->fresh()));
        $this->assertSame($eventos, AgentImplementationEvent::where('agent_implementation_id', $impl->id)->count());

        Artisan::call('agentes:sincronizar-onboarding');

        $this->assertSame($eventos, AgentImplementationEvent::where('agent_implementation_id', $impl->id)->count());
        $this->assertSame(1, $this->eventos_del_sistema($impl));
    }

    public function test_el_comando_recorre_las_abiertas_y_marca_el_hito(): void
    {
        list($client, $impl) = $this->implementacion_abierta();

        $this->empresa_responde($client, Http::response($this->estado_completo('whatsapp'), 200));

        $this->assertSame(0, Artisan::call('agentes:sincronizar-onboarding'));

        $this->assertTrue($this->datos_de_la_etapa_1($impl)['onboarding_completado']);
    }

    public function test_tolerante(): void
    {
        $casos = [
            '404 (empresa con una versión vieja)' => Http::response(['message' => 'no existe'], 404),
            '405'                                 => Http::response([], 405),
            '409 sin extensión'                   => Http::response(['code' => 'sin_extension'], 409),
            '401 clave inválida'                  => Http::response(['code' => 'clave_invalida'], 401),
            '500'                                 => Http::response('boom', 500),
            'el dueño todavía no terminó'         => Http::response(array_merge($this->estado_completo(), ['onboarding_completado' => false, 'onboarding_completado_por' => null]), 200),
            'sin el contrato'                     => Http::response(['otra' => 'cosa'], 200),
            'sin conexión'                        => function () {
                throw new ConnectionException('sin red');
            },
        ];

        foreach ($casos as $nombre => $respuesta) {
            list($client, $impl) = $this->implementacion_abierta('Tolerante ' . random_int(1000, 9999));

            $antes = AgentImplementationEvent::where('agent_implementation_id', $impl->id)->count();

            $this->empresa_responde($client, $respuesta);

            $resultado = app(AgentOnboardingSyncService::class)->sincronizar($impl);

            $this->assertContains($resultado, ['sin_dato', 'error'], $nombre);
            $this->assertArrayNotHasKey('onboarding_completado', $this->datos_de_la_etapa_1($impl), $nombre . ': no escribe nada.');
            $this->assertSame($antes, AgentImplementationEvent::where('agent_implementation_id', $impl->id)->count(), $nombre . ': ni un evento.');
        }

        // El comando entero tampoco lanza con una empresa vieja (404) en cada una de las implementaciones abiertas.
        Http::swap(new HttpFactorySinSalida());
        Http::fake(['*/' . self::RUTA_ESTADO => Http::response(['message' => 'no existe'], 404)]);

        $this->assertSame(0, Artisan::call('agentes:sincronizar-onboarding'));
    }

    public function test_no_consulta_cerradas_ni_flujo_1(): void
    {
        list($client_a, $completada) = $this->implementacion_abierta('Cerrada A');
        list($client_b, $cancelada) = $this->implementacion_abierta('Cerrada B');
        list($client_c, $vieja) = $this->implementacion_abierta('Flujo viejo');

        DB::table('agent_implementations')->where('id', $completada->id)->update(['status' => 'completed']);
        DB::table('agent_implementations')->where('id', $cancelada->id)->update(['status' => 'cancelled']);
        DB::table('agent_implementations')->where('id', $vieja->id)->update(['flujo_version' => 1]);

        Http::swap(new HttpFactorySinSalida());
        Http::fake();

        $servicio = app(AgentOnboardingSyncService::class);

        $this->assertSame('no_aplica', $servicio->sincronizar($completada->fresh()));
        $this->assertSame('no_aplica', $servicio->sincronizar($cancelada->fresh()));
        $this->assertSame('no_aplica', $servicio->sincronizar($vieja->fresh()));

        Http::assertNothingSent();
    }

    public function test_el_hito_marcado_a_mano_no_se_vuelve_a_consultar(): void
    {
        list($client, $impl) = $this->implementacion_abierta();

        $this->postJson(self::RUTA . '/' . $impl->id . '/stages/1', ['status' => 'in_progress', 'data' => ['onboarding_completado' => true]], $this->headers())->assertStatus(200);

        Http::swap(new HttpFactorySinSalida());
        Http::fake();

        $this->assertSame('ya_estaba', app(AgentOnboardingSyncService::class)->sincronizar($impl->fresh()));

        Http::assertNothingSent();
    }

    public function test_source_sistema_fuera_de_sources(): void
    {
        $this->assertSame('sistema', AgentImplementationEvent::SOURCE_SISTEMA);
        $this->assertNotContains('sistema', AgentImplementationEvent::SOURCES, 'La ruta de Claude no acepta `sistema`: sólo lo escribe el admin.');
        $this->assertSame(['martin', 'claude', 'panel'], AgentImplementationEvent::SOURCES);
    }

    public function test_etiqueta(): void
    {
        $this->assertSame(
            'Contexto del negocio contado por el dueño (app o WhatsApp)',
            AgentImplementation::ENTREGA_PERSONAL['onboarding']['hitos']['onboarding_completado']
        );
    }

    public function test_el_comando_corre_cada_media_hora_sin_solaparse(): void
    {
        $eventos = array_filter(app(Schedule::class)->events(), function ($evento) {
            return strpos((string) $evento->command, 'agentes:sincronizar-onboarding') !== false;
        });

        $this->assertCount(1, $eventos);

        $evento = array_values($eventos)[0];

        // Laravel 8 escribe «cada 30 minutos» como `0,30 * * * *` (equivale a `*/30 * * * *`).
        $this->assertContains($evento->expression, ['0,30 * * * *', '*/30 * * * *']);
        $this->assertTrue($evento->withoutOverlapping);
    }
}
