<?php

namespace Tests\Feature\Prueba;

use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use Illuminate\Support\Str;

/**
 * Contrato C2 con los tipos `prueba.*`: las dos formas del body, los hitos, la idempotencia y los tipos
 * desconocidos.
 */
class HitosDePruebaTest extends BaseDePruebaGratuita
{
    private function lead_en_prueba(): Lead
    {
        $lead                     = new Lead();
        $lead->contact_name       = 'Negocio en prueba';
        $lead->phone              = '+5493410000123';
        $lead->status             = 'prueba_activa';
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        return $lead;
    }

    private function postear(Lead $lead, array $body)
    {
        return $this->postJson('/api/demo-eventos', $body, ['X-Demo-Eventos-Key' => $lead->demo_eventos_token]);
    }

    public function test_el_lote_de_siempre_con_tipos_de_prueba_marca_los_hitos_una_sola_vez(): void
    {
        $lead = $this->lead_en_prueba();

        $this->postear($lead, ['eventos' => [
            ['uuid' => (string) Str::uuid(), 'nombre' => 'prueba.ingreso', 'ocurrido_at' => '2026-11-03T15:05:00-03:00', 'datos' => []],
            ['uuid' => (string) Str::uuid(), 'nombre' => 'prueba.importo', 'ocurrido_at' => '2026-11-03T15:20:00-03:00', 'datos' => ['origen' => 'excel']],
            ['uuid' => (string) Str::uuid(), 'nombre' => 'prueba.venta', 'ocurrido_at' => '2026-11-03T15:30:00-03:00'],
        ]])->assertStatus(200)->assertJson(['recibidos' => 3, 'guardados' => 3, 'duplicados' => 0, 'hitos' => 3]);

        $hitos = LeadDemoHito::where('lead_id', $lead->id)->orderBy('orden')->get();
        $this->assertSame(['prueba.ingreso', 'prueba.importo', 'prueba.venta'], $hitos->pluck('evento_esperado')->all());
        $this->assertSame(['prueba', 'prueba', 'prueba'], $hitos->pluck('tipo')->all());
        $this->assertSame(['ingreso', 'importo', 'venta'], $hitos->pluck('clip_id')->all());
        $this->assertSame(['completo', 'completo', 'completo'], $hitos->pluck('estado')->all());
        $this->assertSame('Importó su información', $hitos[1]->titulo);
        $this->assertSame('2026-11-03 15:20:00', $hitos[1]->accion_hecha_at->format('Y-m-d H:i:s'));

        /* El crudo se guarda entero, con sus datos. */
        $this->assertSame(['origen' => 'excel'], DemoEventoRecibido::where('lead_id', $lead->id)->where('nombre', 'prueba.importo')->first()->datos);

        /* Un segundo `prueba.venta` con otro uuid no duplica el hito ni corre su fecha. */
        $this->postear($lead, ['eventos' => [['uuid' => (string) Str::uuid(), 'nombre' => 'prueba.venta', 'ocurrido_at' => '2026-11-04T10:00:00-03:00']]])
            ->assertJson(['guardados' => 1, 'hitos' => 0]);
        $this->assertSame(1, LeadDemoHito::where('lead_id', $lead->id)->where('clip_id', 'venta')->count());
        $this->assertSame('2026-11-03 15:30:00', LeadDemoHito::where('lead_id', $lead->id)->where('clip_id', 'venta')->first()->accion_hecha_at->format('Y-m-d H:i:s'));
    }

    public function test_la_forma_plana_del_plan_se_acepta_y_deduplica_por_tipo(): void
    {
        $lead = $this->lead_en_prueba();

        $this->postear($lead, ['tipo' => 'prueba.articulo_creado', 'datos' => ['id' => 5], 'ocurrido_at' => '2026-11-03T16:00:00-03:00'])
            ->assertStatus(200)->assertJson(['recibidos' => 1, 'guardados' => 1, 'hitos' => 1]);

        /* El mismo tipo otra vez, sin uuid: duplicado (no vuelve a guardar ni a mover). */
        $this->postear($lead, ['tipo' => 'prueba.articulo_creado', 'ocurrido_at' => '2026-11-03T17:00:00-03:00'])
            ->assertStatus(200)->assertJson(['guardados' => 0, 'duplicados' => 1, 'hitos' => 0]);

        /* `prueba.ingreso` plano se repite a propósito: cada ingreso es un evento nuevo (el hito, uno solo). */
        $this->postear($lead, ['tipo' => 'prueba.ingreso', 'ocurrido_at' => '2026-11-03T16:10:00-03:00'])->assertJson(['guardados' => 1, 'hitos' => 1]);
        $this->postear($lead, ['tipo' => 'prueba.ingreso', 'ocurrido_at' => '2026-11-03T16:20:00-03:00'])->assertJson(['guardados' => 1, 'hitos' => 0]);
        $this->assertSame(2, DemoEventoRecibido::where('lead_id', $lead->id)->where('nombre', 'prueba.ingreso')->count());
        $this->assertSame(1, LeadDemoHito::where('lead_id', $lead->id)->where('clip_id', 'ingreso')->count());
    }

    public function test_un_tipo_de_prueba_desconocido_se_guarda_y_no_rompe(): void
    {
        $lead = $this->lead_en_prueba();

        $this->postear($lead, ['tipo' => 'prueba.algo_nuevo_del_futuro', 'ocurrido_at' => '2026-11-03T16:00:00-03:00'])
            ->assertStatus(200)->assertJson(['guardados' => 1, 'hitos' => 1]);

        $hito = LeadDemoHito::where('lead_id', $lead->id)->first();
        $this->assertSame('prueba.algo_nuevo_del_futuro', $hito->evento_esperado);
        $this->assertSame('algo_nuevo', $hito->clip_id, 'El clip_id se recorta a 10 (la columna).');
        $this->assertSame('Algo nuevo del futuro', $hito->titulo);
    }

    public function test_los_eventos_de_la_demo_siguen_igual_y_un_body_sin_eventos_ni_tipo_da_422(): void
    {
        $lead = $this->lead_en_prueba();

        $this->postear($lead, ['eventos' => [['uuid' => (string) Str::uuid(), 'nombre' => 'articulo.creado']]])
            ->assertStatus(200)->assertJson(['guardados' => 1, 'hitos' => 0]);
        $this->assertSame(0, LeadDemoHito::where('lead_id', $lead->id)->count(), 'Un evento de la demo no marca hitos de prueba.');

        $this->postear($lead, ['datos' => ['x' => 1]])->assertStatus(422);
        $this->postJson('/api/demo-eventos', ['tipo' => 'prueba.ingreso'])->assertStatus(401);
    }
}
