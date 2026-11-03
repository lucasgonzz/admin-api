<?php

namespace Tests\Feature\Local;

use App\Models\AdminSetting;
use App\Models\LocalOficinaSlot;
use App\Services\Local\OficinaIngestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `POST api/claude/local/oficina` (misión el-local, 3/11/2026, contrato A): el reporter local de la PC de
 * Lucas manda cada minuto cómo está cada slot del pool.
 *
 * Lo que se fija acá es el contrato con `reporter.py`: la clave, la respuesta 200 exacta, el upsert por slot
 * (reenviar no duplica, cambia), el 422 con `error` + `errors` ante un enum inválido sin escribir nada, y el
 * pulso que queda en `admin_settings` para que la escena sepa si el reporter está vivo.
 *
 * Todo corre adentro de la transacción del test: la tabla `local_oficina_slots` se vacía al empezar (es de
 * esta misión y nadie más escribe ahí) y vuelve a como estaba al terminar.
 */
class OficinaIngestPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de Claude que se configura para el test. */
    const CLAVE = 'clave-de-claude-para-el-local';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        LocalOficinaSlot::query()->delete();
        AdminSetting::where('key', OficinaIngestService::PULSO_KEY)->delete();
    }

    /**
     * Headers del reporter.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Un reporte como el que arma `reporter.py`: los 35 slots, todos libres salvo el 15, que trabaja en la
     * misión el-local con un orquestador y un constructor.
     *
     * @param array<string, mixed> $slot_15 Cambios sobre el slot 15.
     *
     * @return array<string, mixed>
     */
    private function reporte(array $slot_15 = []): array
    {
        $slots = [];
        for ($n = 1; $n <= 35; $n++) {
            $slots[] = [
                'slot' => $n, 'proyecto' => $n <= 12 ? 'empresa' : 'admin', 'estado' => 'libre',
                'rama' => null, 'mision' => null, 'fase' => null, 'claim_iniciado_at' => null,
                'ultima_actividad_at' => null, 'sucios' => 0, 'agentes' => [],
            ];
        }

        $slots[14] = array_merge([
            'slot'                => 15,
            'proyecto'            => 'admin',
            'estado'              => 'trabajando',
            'rama'                => 'el-local',
            'mision'              => 'el-local',
            'fase'                => 'construccion',
            'claim_iniciado_at'   => '2026-11-03T10:05:00-03:00',
            // En UTC, como sale de un transcript: se tiene que guardar en la hora del admin.
            'ultima_actividad_at' => '2026-11-03T13:40:12Z',
            'sucios'              => 3,
            'agentes'             => [
                ['rol' => 'orquestador', 'runtime' => 'claude', 'estado' => 'busy', 'desde_at' => '2026-11-03T10:05:00-03:00', 'nombre' => null],
                ['rol' => 'constructor', 'runtime' => 'codex', 'estado' => 'activo', 'desde_at' => '2026-11-03T13:30:00Z', 'nombre' => 'admin-api'],
            ],
        ], $slot_15);

        return [
            'reporter' => ['version' => '1.0.0', 'host' => 'ASUS', 'enviado_at' => '2026-11-03T10:40:12-03:00', 'slots_total' => 35],
            'sesiones' => ['claude_busy' => 2, 'claude_idle' => 13, 'codex_activos' => 1],
            'slots'    => $slots,
        ];
    }

    /**
     * Sin clave (o con una equivocada) el middleware corta con 401 y no se escribe nada.
     *
     * @return void
     */
    public function test_sin_clave_devuelve_401_y_no_guarda_nada(): void
    {
        $this->postJson('/api/claude/local/oficina', $this->reporte())->assertStatus(401);
        $this->postJson('/api/claude/local/oficina', $this->reporte(), ['X-Claude-Task-Key' => 'otra'])->assertStatus(401);

        $this->assertSame(0, LocalOficinaSlot::query()->count());
        $this->assertNull(AdminSetting::get(OficinaIngestService::PULSO_KEY));
    }

    /**
     * Con clave: 200 con exactamente `{ok, recibido_at, slots}`, una fila por slot, y los campos del slot 15
     * guardados tal cual (las horas pasadas a la hora del admin).
     *
     * @return void
     */
    public function test_con_clave_guarda_un_slot_por_fila_y_responde_el_contrato(): void
    {
        $respuesta = $this->postJson('/api/claude/local/oficina', $this->reporte(), $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame(['ok', 'recibido_at', 'slots'], array_keys($respuesta->json()));
        $this->assertTrue($respuesta->json('ok'));
        $this->assertSame(35, $respuesta->json('slots'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $respuesta->json('recibido_at'));

        $this->assertSame(35, LocalOficinaSlot::query()->count());

        $quince = LocalOficinaSlot::query()->where('slot', 15)->firstOrFail();
        $this->assertSame('trabajando', $quince->estado);
        $this->assertSame('admin', $quince->proyecto);
        $this->assertSame('el-local', $quince->rama);
        $this->assertSame('el-local', $quince->mision);
        $this->assertSame('construccion', $quince->fase);
        $this->assertSame(3, $quince->sucios);
        $this->assertSame('2026-11-03 10:05:00', $quince->claim_iniciado_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-11-03 10:40:12',
            $quince->ultima_actividad_at->format('Y-m-d H:i:s'),
            'La hora en UTC del transcript se guarda en la hora del admin (-03:00).'
        );
        $this->assertNotNull($quince->reportado_at);

        $this->assertCount(2, $quince->agentes);
        $this->assertSame('orquestador', $quince->agentes[0]['rol']);
        $this->assertSame('claude', $quince->agentes[0]['runtime']);
        $this->assertSame('busy', $quince->agentes[0]['estado']);
        $this->assertSame('constructor', $quince->agentes[1]['rol']);
        $this->assertSame('admin-api', $quince->agentes[1]['nombre']);
        $this->assertSame('2026-11-03T10:30:00-03:00', $quince->agentes[1]['desde_at']);

        $libre = LocalOficinaSlot::query()->where('slot', 3)->firstOrFail();
        $this->assertSame('libre', $libre->estado);
        $this->assertSame([], $libre->agentes);
    }

    /**
     * Reenviar es un upsert por slot: no aparecen filas nuevas y el slot que cambió queda con lo nuevo.
     *
     * @return void
     */
    public function test_reenviar_actualiza_por_slot_sin_duplicar(): void
    {
        $this->postJson('/api/claude/local/oficina', $this->reporte(), $this->headers())->assertStatus(200);

        $this->postJson('/api/claude/local/oficina', $this->reporte([
            'estado' => 'libre', 'rama' => 'slot/admin-api-s15', 'mision' => null, 'fase' => null,
            'claim_iniciado_at' => null, 'sucios' => 0, 'agentes' => [],
        ]), $this->headers())->assertStatus(200);

        $this->assertSame(35, LocalOficinaSlot::query()->count());
        $this->assertSame(1, LocalOficinaSlot::query()->where('slot', 15)->count());

        $quince = LocalOficinaSlot::query()->where('slot', 15)->firstOrFail();
        $this->assertSame('libre', $quince->estado);
        $this->assertSame('slot/admin-api-s15', $quince->rama);
        $this->assertNull($quince->mision);
        $this->assertNull($quince->fase);
        $this->assertNull($quince->claim_iniciado_at);
        $this->assertSame([], $quince->agentes);
    }

    /**
     * Un `estado` fuera del enum es 422 con `error` y `errors` por campo, y no se escribe NADA: ni los otros
     * 34 slots ni el pulso.
     *
     * @return void
     */
    public function test_estado_invalido_es_422_y_no_guarda_nada(): void
    {
        $respuesta = $this->postJson('/api/claude/local/oficina', $this->reporte(['estado' => 'durmiendo']), $this->headers());

        $respuesta->assertStatus(422);
        $this->assertIsString($respuesta->json('error'));
        $this->assertNotSame('', $respuesta->json('error'));
        $this->assertArrayHasKey('slots.14.estado', $respuesta->json('errors'));

        $this->assertSame(0, LocalOficinaSlot::query()->count());
        $this->assertNull(AdminSetting::get(OficinaIngestService::PULSO_KEY));
    }

    /**
     * Los otros enums del contrato también se validan: un rol, una fase o un runtime inventados, y un slot
     * repetido, son 422.
     *
     * @return void
     */
    public function test_los_demas_enums_y_el_slot_repetido_son_422(): void
    {
        $con_rol_inventado = $this->reporte(['agentes' => [['rol' => 'gerente', 'runtime' => 'claude', 'estado' => 'busy']]]);
        $this->postJson('/api/claude/local/oficina', $con_rol_inventado, $this->headers())
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['slots.14.agentes.0.rol']]);

        $this->postJson('/api/claude/local/oficina', $this->reporte(['fase' => 'siesta']), $this->headers())
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['slots.14.fase']]);

        $con_runtime_inventado = $this->reporte(['agentes' => [['rol' => 'revisor', 'runtime' => 'gpt', 'estado' => 'idle']]]);
        $this->postJson('/api/claude/local/oficina', $con_runtime_inventado, $this->headers())
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['slots.14.agentes.0.runtime']]);

        $repetido = $this->reporte();
        $repetido['slots'][0]['slot'] = 15;
        $this->postJson('/api/claude/local/oficina', $repetido, $this->headers())->assertStatus(422);

        $this->assertSame(0, LocalOficinaSlot::query()->count());
    }

    /**
     * Las claves que el contrato no conoce se ignoran (un reporter más nuevo que el admin no se queda afuera)
     * y no se guardan en ningún lado.
     *
     * @return void
     */
    public function test_las_claves_desconocidas_se_ignoran(): void
    {
        $reporte = $this->reporte(['nombre_del_chat' => 'no tendría que llegar']);
        $reporte['algo_nuevo'] = ['x' => 1];

        $this->postJson('/api/claude/local/oficina', $reporte, $this->headers())->assertStatus(200);

        $quince = LocalOficinaSlot::query()->where('slot', 15)->firstOrFail();
        $this->assertArrayNotHasKey('nombre_del_chat', $quince->getAttributes());
    }

    /**
     * El pulso queda en `admin_settings.local_reporter_pulso` con exactamente las claves del contrato.
     *
     * @return void
     */
    public function test_el_pulso_queda_en_admin_settings(): void
    {
        $this->postJson('/api/claude/local/oficina', $this->reporte(), $this->headers())->assertStatus(200);

        $pulso = json_decode((string) AdminSetting::get(OficinaIngestService::PULSO_KEY), true);

        $this->assertIsArray($pulso);
        $this->assertSame(['last_seen_at', 'version', 'host', 'slots_total', 'sesiones'], array_keys($pulso));
        $this->assertSame('1.0.0', $pulso['version']);
        $this->assertSame('ASUS', $pulso['host']);
        $this->assertSame(35, $pulso['slots_total']);
        $this->assertSame(['claude_busy' => 2, 'claude_idle' => 13, 'codex_activos' => 1], $pulso['sesiones']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $pulso['last_seen_at']);
    }
}
