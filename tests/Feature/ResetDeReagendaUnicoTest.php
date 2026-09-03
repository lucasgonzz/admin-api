<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Services\LeadRescheduleFlagsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔴 UNA SOLA DEFINICIÓN DEL RESET DE REAGENDA: el camino del PANEL y el camino de CLAUDE dejan
 * exactamente los mismos flags.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * ----------------------------
 * Cuando se reagenda una demo hay que apagar `recordatorio_demo_enviado` y
 * `recordatorio_manana_enviado` y limpiar `demo_fin_check_reprogramado_para`; si no, la demo nueva
 * no recibe su recordatorio (el latch ya está marcado como enviado) y el check de fin queda trabado
 * en un timestamp del horario viejo que nunca más cae en la ventana de ±2 minutos.
 *
 * Ese bloque estaba escrito DOS veces adentro de `LeadController` —en `update()` y en
 * `update_json()`— y `PATCH claude/leads/{id}` iba a ser la TERCERA. Tres copias de la misma regla
 * garantizan que se separen: el día que aparezca un cuarto flag, alguien lo agrega en la copia que
 * estaba mirando y los otros caminos reagendan dejando el flag viejo puesto, sin que nada lo
 * denuncie. Por eso el bloque se extrajo a {@see LeadRescheduleFlagsService} y por eso este test
 * compara los DOS caminos entre sí en vez de verificar cada uno por separado: un test por camino se
 * puede satisfacer con dos implementaciones distintas; éste, no.
 *
 * ⚠️ El test se apoya en `LeadRescheduleFlagsService::flags_reseteados()` como lista de qué
 * comparar, así que agregar un cuarto flag al servicio lo mete solo en la comparación de los dos
 * caminos. Ésa es la propiedad que hace que este test siga sirviendo cuando el reset crezca.
 */
class ResetDeReagendaUnicoTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-reset-de-reagenda';

    /**
     * Setea la clave de ingesta: en el `.env.testing` del slot está vacía y el middleware es
     * fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Lead con la demo agendada y los tres flags puestos, o sea el estado real de un lead al que ya
     * le salieron los recordatorios del horario viejo.
     *
     * @return Lead
     */
    private function crear_lead_con_los_flags_puestos(): Lead
    {
        $lead                                   = new Lead();
        $lead->uuid                             = (string) Str::uuid();
        $lead->contact_name                     = 'Juana Pérez';
        $lead->status                           = 'demo_agendada';
        $lead->demo_date                        = '2026-09-10';
        $lead->demo_start_time                  = '18:00';
        $lead->demo_end_time                    = '19:00';
        $lead->recordatorio_demo_enviado        = true;
        $lead->recordatorio_manana_enviado      = true;
        $lead->demo_fin_check_reprogramado_para = '2026-09-10 19:15:00';
        $lead->save();

        return $lead;
    }

    /**
     * Los flags del lead, leídos por los nombres que declara el servicio.
     *
     * @param Lead $lead Lead a fotografiar.
     *
     * @return array<string, mixed>
     */
    private function flags_de(Lead $lead): array
    {
        $fresco = $lead->fresh();
        $foto   = [];

        foreach (array_keys(app(LeadRescheduleFlagsService::class)->flags_reseteados()) as $flag) {
            $valor = $fresco->{$flag};
            /* `demo_fin_check_reprogramado_para` está casteada a datetime: se compara el string. */
            $foto[$flag] = is_bool($valor) || $valor === null ? $valor : (string) $valor;
        }

        return $foto;
    }

    /**
     * 🔴 EL TEST DE LA EXTRACCIÓN: reagendar por el panel y reagendar por Claude dejan los MISMOS
     * flags, comparados entre sí y no contra una constante escrita a mano.
     *
     * @return void
     */
    public function test_el_panel_y_claude_dejan_los_mismos_flags_al_reagendar(): void
    {
        /* --- Camino 1: el panel (PUT admin/lead/{id}, autenticado por Sanctum). --- */
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        Sanctum::actingAs($admin);

        $lead_panel = $this->crear_lead_con_los_flags_puestos();

        $this->putJson('/api/admin/lead/' . $lead_panel->id, [
            'demo_date' => '2026-09-17',
        ])->assertStatus(200);

        $flags_panel = $this->flags_de($lead_panel);

        /* --- Camino 2: Claude (PATCH claude/leads/{id}, con la clave del header). --- */
        $lead_claude = $this->crear_lead_con_los_flags_puestos();

        $simulacion = $this->withHeaders([
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ])->patchJson('/api/claude/leads/' . $lead_claude->id, [
            'campos' => ['demo_date' => '2026-09-17'],
        ]);
        $simulacion->assertStatus(200);

        $this->withHeaders([
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ])->patchJson('/api/claude/leads/' . $lead_claude->id, [
            'campos'        => ['demo_date' => '2026-09-17'],
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
        ])->assertJsonPath('reagendado', true)->assertStatus(200);

        $flags_claude = $this->flags_de($lead_claude);

        /* --- La comparación que fija la extracción. --- */
        $this->assertSame(
            $flags_panel,
            $flags_claude,
            'Reagendar por el panel y por Claude dejó flags distintos: se separaron las dos definiciones del reset.'
        );

        /* Y que el reset efectivamente pasó por los dos lados (si no, comparar dos "no hizo nada"
           también daría igual y el test no probaría nada). */
        $this->assertFalse($flags_panel['recordatorio_demo_enviado']);
        $this->assertFalse($flags_panel['recordatorio_manana_enviado']);
        $this->assertNull($flags_panel['demo_fin_check_reprogramado_para']);

        $this->assertSame('2026-09-17', $lead_panel->fresh()->getRawOriginal('demo_date'));
        $this->assertSame('2026-09-17', $lead_claude->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * Guardar por el panel SIN mover la fecha no toca los flags — el reset es de la reagenda, no
     * del guardado. Es la mitad de la regla que un `always reset` rompería sin que ningún test de
     * "reagendar resetea" lo notara.
     *
     * @return void
     */
    public function test_guardar_sin_mover_la_fecha_no_resetea_nada_por_ninguno_de_los_dos_caminos(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        Sanctum::actingAs($admin);

        $lead_panel = $this->crear_lead_con_los_flags_puestos();
        $this->putJson('/api/admin/lead/' . $lead_panel->id, [
            'contact_name' => 'Juana Pérez Gómez',
        ])->assertStatus(200);

        $lead_claude = $this->crear_lead_con_los_flags_puestos();
        $this->withHeaders([
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ])->patchJson('/api/claude/leads/' . $lead_claude->id, [
            'campos'        => ['contact_name' => 'Juana Pérez Gómez'],
            'dry_run'       => false,
            'confirm_count' => 1,
        ])->assertStatus(200)->assertJsonPath('reagendado', false);

        $this->assertSame($this->flags_de($lead_panel), $this->flags_de($lead_claude));
        $this->assertTrue($this->flags_de($lead_claude)['recordatorio_demo_enviado'], 'Se reseteó un flag sin reagendar.');
    }
}
