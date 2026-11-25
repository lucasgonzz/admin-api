<?php

namespace Tests\Feature\MarcasDeActualizacion;

use App\Models\Client;
use App\Models\SupportCodexMission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SoporteMartin2511\SoporteMartinHelpers;
use Tests\TestCase;

/**
 * CA7 — Alta automática de la marca «requiere actualización» cuando la corrección de una misión de soporte
 * queda verificada con commits de empresa (lo que se instala en el cliente). Una misión que sólo tocó el
 * admin no marca nada. El backfill crea las que faltan y es idempotente.
 */
class MarcaDesdeMisionDeSoporteTest extends TestCase
{
    use DatabaseTransactions;
    use SoporteMartinHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparar_puente();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_evidencia_con_commits_de_empresa_marca_al_cliente(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['name' => 'Factura B con redondeo mal']);
        $mission = $this->mision_con_evidencia($ticket, ['empresa-api' => str_repeat('a', 40), 'admin-api' => str_repeat('f', 40)]);

        $marcas = DB::table('client_upgrade_marks')->where('client_id', $ticket->client_id)->get();
        $this->assertCount(1, $marcas);
        $marca = $marcas->first();
        $this->assertSame('mission:' . $mission->id, $marca->external_key);
        $this->assertSame('soporte', $marca->source);
        $this->assertSame('bug', $marca->kind);
        $this->assertSame('sistema', $marca->target);
        $this->assertSame((int) $ticket->id, (int) $marca->support_ticket_id);
        $this->assertSame((int) $mission->id, (int) $marca->support_codex_mission_id);
        $this->assertSame('Factura B con redondeo mal', $marca->title);
        $this->assertSame(['empresa-api' => str_repeat('a', 40)], json_decode($marca->commits, true));
        $this->assertNotNull($marca->merged_at);
        $this->assertTrue((bool) Client::findOrFail($ticket->client_id)->requires_upgrade);
    }

    public function test_evidencia_solo_del_admin_no_marca(): void
    {
        $ticket = $this->ticket();
        $this->mision_con_evidencia($ticket, ['admin-api' => str_repeat('f', 40)]);
        $this->assertSame(0, DB::table('client_upgrade_marks')->where('client_id', $ticket->client_id)->count());
        $this->assertFalse((bool) Client::findOrFail($ticket->client_id)->requires_upgrade);
    }

    public function test_backfill_crea_la_marca_que_falta_y_es_idempotente(): void
    {
        $ticket = $this->ticket();
        $mission = $this->mision_con_evidencia($ticket);
        DB::table('client_upgrade_marks')->where('client_id', $ticket->client_id)->delete();
        DB::table('clients')->where('id', $ticket->client_id)->update(['requires_upgrade' => false]);
        $this->assertNull($mission->fresh()->installed_at);

        $this->artisan('client-upgrade-marks:backfill')->assertExitCode(0);
        $this->assertSame(1, DB::table('client_upgrade_marks')->where('external_key', 'mission:' . $mission->id)->count());
        $this->assertTrue((bool) Client::findOrFail($ticket->client_id)->requires_upgrade);

        $this->artisan('client-upgrade-marks:backfill')->assertExitCode(0);
        $this->assertSame(1, DB::table('client_upgrade_marks')->where('client_id', $ticket->client_id)->count());

        // Una misión ya instalada no entra al backfill.
        $otro = $this->ticket(['phone' => '+5493417774444']);
        $instalada = $this->mision_con_evidencia($otro);
        DB::table('client_upgrade_marks')->where('client_id', $otro->client_id)->delete();
        SupportCodexMission::whereKey($instalada->id)->update(['installed_at' => now()]);
        $this->artisan('client-upgrade-marks:backfill')->assertExitCode(0);
        $this->assertSame(0, DB::table('client_upgrade_marks')->where('client_id', $otro->client_id)->count());
    }
}
