<?php

namespace Tests\Feature\Prueba;

use App\Jobs\EnviarEventoMetaJob;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Services\MetaConversionesService;
use App\Services\Prueba\PruebaSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Los ensayos de la prueba gratuita no mandan eventos a Meta (misión ensayos-sin-eventos-meta, 13/11/2026).
 *
 * El 11 y 12/11 los ensayos (clientes `es_ensayo`, correo de `prueba_ensayo_correos`) mandaron 4 `Lead` y 4 `StartTrial`
 * al píxel, que es por lo que optimiza la campaña, con 0 pruebas reales. Desde esta misión cada evento de un ensayo se
 * registra `omitido` con su motivo y no sale; el alta real sigue mandando lo mismo, con los mismos `event_id`.
 */
class EnsayoSinEventosMetaTest extends BaseDePruebaGratuita
{
    /** Marca el correo del alta como correo de ensayo. */
    private function con_correo_de_ensayo(): void
    {
        AdminSetting::set(PruebaSettings::KEY_ENSAYO_CORREOS, (string) $this->cuerpo()['email']);
    }

    /** @return array{0: Lead, 1: \Illuminate\Testing\TestResponse} */
    private function alta(): array
    {
        $this->crear_instancia_libre();
        $respuesta = $this->postear_alta(['fbc' => 'fb.1.1761130000000.click-original']);
        $respuesta->assertStatus(201);

        return [$this->lead_del_alta($respuesta->json('uuid')), $respuesta];
    }

    private function ingresar(Lead $lead): void
    {
        $this->postJson('/api/demo-eventos', ['eventos' => [[
            'uuid' => (string) Str::uuid(), 'nombre' => 'prueba.ingreso', 'ocurrido_at' => '2026-11-03T14:00:00-03:00',
        ]]], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token, 'User-Agent' => 'Servidor ERP'])
            ->assertStatus(200);
    }

    private function fila(Lead $lead, string $evento): ?LeadEventoMeta
    {
        return LeadEventoMeta::where('lead_id', $lead->id)->where('evento', $evento)->first();
    }

    /** Los pedidos que salieron (falsos) hacia la Graph API de Meta. */
    private function pedidos_a_meta(): array
    {
        return Http::recorded(function ($request) {
            return strpos($request->url(), 'graph.facebook.com') !== false;
        })->all();
    }

    private function assert_omitido_por_ensayo(?LeadEventoMeta $fila): void
    {
        $this->assertNotNull($fila);
        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $fila->estado);
        $this->assertSame(LeadEventoMeta::MOTIVO_ENSAYO, $fila->ultimo_error);
        $this->assertNull($fila->contexto_web, 'Un evento que no sale no guarda la IP ni el user agent.');
    }

    public function test_el_alta_de_un_ensayo_registra_el_lead_omitido_y_no_encola_nada(): void
    {
        $this->con_correo_de_ensayo();
        [$lead, $respuesta] = $this->alta();

        $this->assertTrue($respuesta->json('ensayo'), 'La landing se entera de que es un ensayo y no dispara el píxel.');
        /* La lista se sigue mandando igual: una landing vieja, con la lista vacía, caería al id derivado del uuid. */
        $this->assertSame(
            [['nombre' => 'Lead', 'event_id' => MetaConversionesService::event_id_para($lead->id, 'Lead', 'web')]],
            $respuesta->json('meta_eventos')
        );
        $this->assert_omitido_por_ensayo($this->fila($lead, 'Lead'));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
        $this->assertSame([], $this->pedidos_a_meta());
    }

    public function test_el_ingreso_de_un_ensayo_registra_el_starttrial_omitido_y_el_conciliador_no_lo_revive(): void
    {
        $this->con_correo_de_ensayo();
        [$lead] = $this->alta();

        $this->ingresar($lead);
        $this->assert_omitido_por_ensayo($this->fila($lead, 'StartTrial'));

        $meta = app(MetaConversionesService::class);
        $meta->conciliar();
        $this->assertNull($meta->registrar_inicio_prueba($lead));
        $meta->registrar_alcanzados($lead);

        $this->assert_omitido_por_ensayo($this->fila($lead, 'StartTrial'));
        $this->assert_omitido_por_ensayo($this->fila($lead, 'Lead'));
        $this->assertSame(1, LeadEventoMeta::where('lead_id', $lead->id)->where('evento', 'StartTrial')->count());
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
        $this->assertSame([], $this->pedidos_a_meta());
    }

    public function test_un_pendiente_de_antes_de_la_mision_no_sale_y_queda_omitido(): void
    {
        $this->con_correo_de_ensayo();
        [$lead] = $this->alta();
        /* Como quedaron las filas de los ensayos del 11 y 12/11 que todavía no habían salido. */
        $fila = $this->fila($lead, 'Lead');
        $fila->update(['estado' => LeadEventoMeta::ESTADO_PENDIENTE, 'ultimo_error' => null]);

        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, app(MetaConversionesService::class)->enviar($fila->fresh()));
        $this->assert_omitido_por_ensayo($fila);
        $this->assertSame([], $this->pedidos_a_meta());
    }

    public function test_un_cliente_marcado_como_ensayo_despues_del_alta_tampoco_manda_lo_pendiente(): void
    {
        /* Sin la lista: el alta nace real y su Lead queda pendiente, como siempre. */
        [$lead, $respuesta] = $this->alta();
        $this->assertFalse($respuesta->json('ensayo'));
        $fila = $this->fila($lead, 'Lead');
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $fila->estado);

        /* `pruebas:ensayo --marcar` llega antes que el worker. */
        Client::whereKey($lead->promoted_client_id)->update(['es_ensayo' => true]);

        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, app(MetaConversionesService::class)->enviar($fila->fresh()));
        $this->assert_omitido_por_ensayo($fila);
        $this->assertSame([], $this->pedidos_a_meta());
    }

    public function test_la_compra_de_un_ensayo_queda_omitida(): void
    {
        $this->con_correo_de_ensayo();
        $client = Client::create([
            'name' => 'Ensayo que contrató', 'user_id' => 900500, 'slug' => 'ensayo-contrato', 'api_key' => Str::random(40),
            'inbound_api_key' => Str::random(40), 'is_active' => true, 'estado' => 'activo', 'es_ensayo' => true,
        ]);
        $lead = Lead::create([
            'contact_name' => 'Raúl Benítez', 'email' => (string) $this->cuerpo()['email'], 'phone' => '+549351' . random_int(1000000, 9999999),
            'status' => 'cerrado_ganado', 'origen' => 'landing', 'promoted_client_id' => $client->id,
        ]);

        $meta = app(MetaConversionesService::class);
        $meta->registrar_alcanzados($lead);
        $meta->registrar_compra($lead);

        $this->assertNotEmpty(LeadEventoMeta::where('lead_id', $lead->id)->get()->all());
        foreach (LeadEventoMeta::where('lead_id', $lead->id)->get() as $fila) {
            $this->assert_omitido_por_ensayo($fila);
        }
        $this->assert_omitido_por_ensayo($this->fila($lead, 'Purchase'));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
        $this->assertSame([], $this->pedidos_a_meta());
    }

    public function test_un_lead_sin_cliente_con_el_correo_de_ensayo_tampoco_manda_nada(): void
    {
        $this->con_correo_de_ensayo();
        /* Por ejemplo, una reserva de demo de ensayo desde la landing, o el lead que quedó sin cliente. */
        $lead = Lead::create([
            'contact_name' => 'Raúl Benítez', 'email' => '  ' . strtoupper((string) $this->cuerpo()['email']), 'phone' => '+549351' . random_int(1000000, 9999999),
            'status' => 'demo_agendada', 'origen' => 'landing',
        ]);

        app(MetaConversionesService::class)->registrar_alcanzados($lead);

        $this->assertSame(['Lead', 'Schedule'], LeadEventoMeta::where('lead_id', $lead->id)->orderBy('id')->pluck('evento')->all());
        foreach (LeadEventoMeta::where('lead_id', $lead->id)->get() as $fila) {
            $this->assert_omitido_por_ensayo($fila);
        }
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
        $this->assertSame([], $this->pedidos_a_meta());
    }

    public function test_el_alta_real_sigue_mandando_lead_y_starttrial_con_los_mismos_event_id(): void
    {
        /* Otro correo en la lista: el alta real no se confunde con un ensayo. */
        AdminSetting::set(PruebaSettings::KEY_ENSAYO_CORREOS, 'otro@test.local');
        [$lead, $respuesta] = $this->alta();

        $this->assertFalse($respuesta->json('ensayo'));
        $this->assertFalse(MetaConversionesService::es_de_ensayo($lead));
        $id_lead = 'cc-lead-' . $lead->id . '-web-lead';
        $this->assertSame([['nombre' => 'Lead', 'event_id' => $id_lead]], $respuesta->json('meta_eventos'));

        $alta = $this->fila($lead, 'Lead');
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $alta->estado);
        Queue::assertPushed(EnviarEventoMetaJob::class, 1);

        $meta = app(MetaConversionesService::class);
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $meta->enviar($alta));

        $this->ingresar($lead);
        $inicio = $this->fila($lead, 'StartTrial');
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $inicio->estado);
        $this->assertSame(MetaConversionesService::event_id_para($lead->id, 'StartTrial', 'web'), $inicio->event_id);
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $meta->enviar($inicio));

        $enviados = array_map(function ($par) {
            return [$par[0]['data'][0]['event_name'], $par[0]['data'][0]['event_id']];
        }, $this->pedidos_a_meta());
        $this->assertSame([['Lead', $id_lead], ['StartTrial', 'cc-lead-' . $lead->id . '-web-starttrial']], $enviados);
    }
}
