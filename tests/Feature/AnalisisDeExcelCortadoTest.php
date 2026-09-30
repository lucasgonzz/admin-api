<?php

namespace Tests\Feature;

use App\Events\ImplementationImportStatusUpdated;
use App\Jobs\ProcessImplementationStage4Import;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Services\ImplementationConversationService;
use App\Services\ImplementationImportService;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Un análisis de Excel que se corta no deja la Etapa 4 colgada en `analyzing` (misión
 * modelos-ia-por-cliente, 30/9/2026).
 *
 * Dos caminos llevaban a lo mismo: el worker mata el job por tiempo (o `handle()` lanza) y
 * `ProcessImplementationStage4Import` no tenía `failed()`, o todos los archivos fallan y
 * `process_files()` volvía sin tocar nada. En los dos, el responsable de la migración recibía
 * "estamos analizando" a cada mensaje y el panel no mostraba ningún error.
 */
class AnalisisDeExcelCortadoTest extends BaseDelCanal
{
    /** @var ImplementationConversationService Espía de los avisos al admin asignado. */
    private $espia;

    /**
     * Registra en el contenedor un `ImplementationImportService` con el espía de avisos, que es el
     * que resuelve `failed()` del job.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ImplementationImportStatusUpdated::class]);

        $this->espia = new class extends ImplementationConversationService {
            /** @var array<int, string> Avisos que habrían salido al admin asignado. */
            public $avisos = [];

            public function notify_assigned_admin_for_implementation(Implementation $implementation, string $message): void
            {
                $this->avisos[] = $message;
            }

            public function send_stage_4_outbound(Implementation $implementation, string $body): void
            {
                // En estas pruebas no sale nada por WhatsApp.
            }
        };

        $this->app->instance(ImplementationImportService::class, new ImplementationImportService(null, $this->espia));
    }

    /**
     * Implementación con su Etapa 4 en `analyzing`: artículos con archivo y pendientes, clientes con
     * archivo pero YA importados, proveedores sin archivos.
     *
     * @return array{0: Implementation, 1: ImplementationStage}
     */
    private function implementacion_analizando(): array
    {
        $client          = $this->crear_cliente();
        $client->user_id = 5;
        $client->save();

        $implementation                          = new Implementation();
        $implementation->client_id               = $client->id;
        $implementation->status                  = 'in_progress';
        $implementation->migration_contact_phone = '+5493411112222';
        $implementation->save();

        $stage                    = new ImplementationStage();
        $stage->implementation_id = $implementation->id;
        $stage->stage_number      = 4;
        $stage->data              = [
            'current_question' => 'analyzing',
            'articles_files'   => [['url' => 'https://kapso.test/media/lista.xlsx', 'filename' => 'lista.xlsx']],
            'clients_files'    => [['url' => 'https://kapso.test/media/clientes.xlsx', 'filename' => 'clientes.xlsx']],
            'import_status'    => [
                'articles' => ['status' => 'pending', 'error' => null, 'imported_at' => null],
                'clients'  => ['status' => 'success', 'error' => null, 'imported_at' => '2026-09-30T10:00:00-03:00'],
            ],
        ];
        $stage->save();

        return [$implementation, $stage];
    }

    /**
     * 🔴 El corte por tiempo del worker deja la etapa en error visible, con el techo nombrado.
     *
     * @return void
     */
    public function test_el_corte_por_tiempo_deja_la_etapa_en_error_visible(): void
    {
        list($implementation, $stage) = $this->implementacion_analizando();

        $job = new ProcessImplementationStage4Import($implementation->id);
        $job->failed(new MaxAttemptsExceededException('App\Jobs\ProcessImplementationStage4Import has been attempted too many times or run too long.'));

        $data = $stage->fresh()->data;

        $this->assertSame('analysis_failed', $data['current_question']);
        $this->assertStringContainsString('1800 s', $data['analysis_error']);

        // La categoría pendiente queda en `failed` con el motivo (el badge "❌ Error" del panel)...
        $this->assertSame('failed', $data['import_status']['articles']['status']);
        $this->assertStringContainsString('1800 s', $data['import_status']['articles']['error']);
        // ...y la que ya se había importado NO se pisa.
        $this->assertSame('success', $data['import_status']['clients']['status']);
        // Sin archivos de proveedores, no se inventa un estado.
        $this->assertArrayNotHasKey('suppliers', $data['import_status']);

        Event::assertDispatched(ImplementationImportStatusUpdated::class, 1);

        $this->assertCount(1, $this->espia->avisos);
        $this->assertStringContainsString('Se cortó el análisis', $this->espia->avisos[0]);
        $this->assertStringContainsString('1800 s', $this->espia->avisos[0]);
    }

    /**
     * Un error cualquiera de `handle()` también saca la etapa de `analyzing`, con su mensaje.
     *
     * @return void
     */
    public function test_un_error_de_handle_tambien_la_saca_de_analyzing(): void
    {
        list($implementation, $stage) = $this->implementacion_analizando();

        (new ProcessImplementationStage4Import($implementation->id))
            ->failed(new \RuntimeException('Se cayó la base a mitad del análisis.'));

        $data = $stage->fresh()->data;

        $this->assertSame('analysis_failed', $data['current_question']);
        $this->assertStringContainsString('Se cayó la base a mitad del análisis.', $data['analysis_error']);
        $this->assertSame('failed', $data['import_status']['articles']['status']);
        $this->assertCount(1, $this->espia->avisos);
    }

    /**
     * 🔴 Si todos los archivos fallan, `process_files()` ya no deja la etapa en `analyzing`, y no
     * repite el aviso que cada archivo ya mandó.
     *
     * @return void
     */
    public function test_si_fallan_todos_los_archivos_la_etapa_queda_en_error(): void
    {
        list($implementation, $stage) = $this->implementacion_analizando();

        $this->fakear_http([
            '*kapso.test*'              => Http::response('contenido-del-excel', 200),
            '*ai-excel-import/analyze*' => Http::response(['message' => 'La IA no está disponible.'], 500),
        ]);

        app(ImplementationImportService::class)->process_files($implementation->fresh());

        $data = $stage->fresh()->data;

        $this->assertSame('analysis_failed', $data['current_question']);
        $this->assertSame('failed', $data['import_status']['articles']['status']);

        // Un aviso por archivo (con su motivo) y ninguno más de "se cortó el análisis".
        $this->assertCount(2, $this->espia->avisos);
        foreach ($this->espia->avisos as $aviso) {
            $this->assertStringContainsString('La IA no está disponible.', $aviso);
            $this->assertStringNotContainsString('Se cortó el análisis', $aviso);
        }
    }
}
