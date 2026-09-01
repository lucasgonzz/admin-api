<?php

namespace Tests\Feature;

use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El loop de tool use de LeadAiService no puede cortar antes de que Claude termine de
 * consultar los recursos que necesita.
 *
 * Hasta el 1/9/2026 el tope era 3 iteraciones: un lead que mandó varios audios seguidos y
 * necesitaba más de tres recursos del protocolo en la misma llamada hacía que
 * run_with_tools() tirara "se superaron las iteraciones de tool use" antes de llegar a una
 * respuesta final, y el setter se quedaba sin sugerencia (caso real: lead #577, 31/8/2026).
 */
class LimiteDeIteracionesDeToolUseTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Con 5 rondas de tool_use (más de las 3 que el tope viejo permitía) la llamada igual
     * llega a una respuesta final.
     *
     * @return void
     */
    public function test_el_loop_tolera_mas_de_tres_rondas_de_tool_use()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['precios', 'reglas', 'demo_agenda', 'posicionamiento', 'calificacion']);

        $paquete = [
            'mensaje_sugerido' => 'Dale, te cuento...',
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
            'tipo_respuesta'   => 'conversacional',
            'fuentes_kb'       => [],
        ];

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respuesta_tool_use('precios'))
                ->push($this->respuesta_tool_use('reglas'))
                ->push($this->respuesta_tool_use('demo_agenda'))
                ->push($this->respuesta_tool_use('posicionamiento'))
                ->push($this->respuesta_tool_use('calificacion'))
                ->push($this->respuesta_final($paquete)),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Dale, te cuento', (string) $mensaje->content);
    }

    /**
     * Superado el nuevo tope (10 rondas sin respuesta final), el loop sigue frenando con la
     * misma excepción, con el número actualizado en el mensaje.
     *
     * @return void
     */
    public function test_el_loop_sigue_frenando_si_de_verdad_se_supera_el_tope()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['precios']);

        $secuencia = Http::sequence();
        for ($i = 0; $i < 11; $i++) {
            $secuencia->push($this->respuesta_tool_use('precios'));
        }

        Http::fake([
            'api.anthropic.com/*' => $secuencia,
            '*' => Http::response(['ok' => true], 200),
        ]);

        $lead = $this->crear_lead();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('se superaron las iteraciones de tool use (10)');

        app(LeadAiService::class)->generate_suggestion($lead, false);
    }

    /**
     * Respuesta de Claude que pausa para pedir un recurso via tool use.
     *
     * @param string $recurso Nombre del recurso pedido.
     *
     * @return array<string, mixed>
     */
    private function respuesta_tool_use(string $recurso): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'tool_' . Str::random(8),
                'name'  => 'get_protocolo_recurso',
                'input' => ['nombre' => $recurso],
            ]],
        ];
    }

    /**
     * Respuesta final de Claude, sin más tool use.
     *
     * @param array<string, mixed> $paquete JSON que devuelve el modelo.
     *
     * @return array<string, mixed>
     */
    private function respuesta_final(array $paquete): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [[
                'type' => 'text',
                'text' => json_encode($paquete, JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    /**
     * Deja en base lo mínimo que build_system_prompt() exige.
     *
     * @return void
     */
    private function sembrar_protocolo_de_leads(): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);

        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => 'System base de prueba (el contrato de fuentes no es lo que este test mide).',
            'synced_at' => now(),
        ]);
    }

    /**
     * Deja en base el contenido de los recursos que el test va a pedir via tool use, para
     * que execute_tool() los sirva de verdad en vez de devolver el aviso de "no disponible".
     *
     * @param array<int, string> $recursos Nombres de los recursos a sembrar.
     *
     * @return void
     */
    private function sembrar_recursos(array $recursos): void
    {
        foreach ($recursos as $nombre) {
            SyncedGithubFile::create([
                'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX . $nombre,
                'repo_path' => "agentes/lead/recursos/{$nombre}.md",
                'content'   => "Contenido de prueba del recurso {$nombre}.",
                'synced_at' => now(),
            ]);
        }
    }

    /**
     * Lead mínimo.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'calificado';
        $lead->save();

        return $lead->refresh();
    }
}
