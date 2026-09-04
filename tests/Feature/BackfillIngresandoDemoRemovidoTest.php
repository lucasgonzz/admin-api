<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `leads:backfill-ingresando-demo-removido`: uso único para mover, en producción, los leads que
 * quedaron en `ingresando_demo` cuando el estado se sacó del catálogo (misión
 * demo-v2-estados-automaticos, 4/9/2026).
 */
class BackfillIngresandoDemoRemovidoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param string $status
     * @param bool   $ingreso_confirmado
     *
     * @return Lead
     */
    private function crear_lead(string $status, bool $ingreso_confirmado): Lead
    {
        $lead                           = new Lead();
        $lead->uuid                     = (string) Str::uuid();
        $lead->contact_name             = 'Lead de prueba';
        $lead->status                   = $status;
        $lead->demo_ingreso_confirmado  = $ingreso_confirmado;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Con ingreso ya confirmado, el lead termina en demo_en_curso.
     */
    public function test_lead_con_ingreso_confirmado_termina_en_demo_en_curso(): void
    {
        $lead = $this->crear_lead('ingresando_demo', true);

        $this->artisan('leads:backfill-ingresando-demo-removido')->assertExitCode(0);

        $this->assertSame('demo_en_curso', $lead->refresh()->status);
    }

    /**
     * Sin ingreso confirmado, el lead termina en demo_pendiente_de_ingreso.
     */
    public function test_lead_sin_ingreso_confirmado_termina_en_demo_pendiente_de_ingreso(): void
    {
        $lead = $this->crear_lead('ingresando_demo', false);

        $this->artisan('leads:backfill-ingresando-demo-removido')->assertExitCode(0);

        $this->assertSame('demo_pendiente_de_ingreso', $lead->refresh()->status);
    }

    /**
     * La fila del catálogo desaparece después de correrlo.
     */
    public function test_borra_la_fila_del_catalogo(): void
    {
        LeadPipelineStatus::ensure_exists('ingresando_demo', 'Ingresando a demo');
        $this->assertSame(1, LeadPipelineStatus::where('slug', 'ingresando_demo')->count());

        $this->crear_lead('ingresando_demo', false);

        $this->artisan('leads:backfill-ingresando-demo-removido')->assertExitCode(0);

        $this->assertSame(0, LeadPipelineStatus::where('slug', 'ingresando_demo')->count());
    }

    /**
     * Correrlo dos veces no rompe nada: la segunda vez no hay leads ni fila que mover.
     */
    public function test_correrlo_dos_veces_es_idempotente(): void
    {
        $lead = $this->crear_lead('ingresando_demo', true);

        $this->artisan('leads:backfill-ingresando-demo-removido')->assertExitCode(0);
        $this->assertSame('demo_en_curso', $lead->refresh()->status);

        $this->artisan('leads:backfill-ingresando-demo-removido')->assertExitCode(0);
        $this->assertSame('demo_en_curso', $lead->refresh()->status);
        $this->assertSame(0, LeadPipelineStatus::where('slug', 'ingresando_demo')->count());
    }
}
