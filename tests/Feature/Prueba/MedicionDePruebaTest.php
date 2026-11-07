<?php

namespace Tests\Feature\Prueba;

use App\Jobs\EnviarEventoMetaJob;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\LeadEventoMeta;
use App\Models\TrialInstance;
use App\Services\MetaConversionesService;
use Carbon\Carbon;
use Tests\Fakes\HttpFactorySinSalida;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Support\Str;

/** El alta mide Lead; únicamente el primer ingreso auténtico mide StartTrial. */
class MedicionDePruebaTest extends BaseDePruebaGratuita
{
    private function alta(): Lead
    {
        $this->crear_instancia_libre();
        $respuesta = $this->postear_alta(['fbc' => 'fb.1.1761130000000.click-original']);
        $respuesta->assertStatus(201);
        $this->assertSame(['Lead'], array_column($respuesta->json('meta_eventos'), 'nombre'));

        return $this->lead_del_alta($respuesta->json('uuid'));
    }

    private function ingresar(Lead $lead, string $fecha = '2026-11-03T14:00:00-03:00', ?string $uuid = null): void
    {
        $this->postJson('/api/demo-eventos', ['eventos' => [[
            'uuid' => $uuid ?: (string) Str::uuid(), 'nombre' => 'prueba.ingreso', 'ocurrido_at' => $fecha,
        ]]], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token, 'User-Agent' => 'Servidor ERP'])
            ->assertStatus(200);
    }

    private function inicio(Lead $lead): ?LeadEventoMeta
    {
        return LeadEventoMeta::where('lead_id', $lead->id)->where('evento', 'StartTrial')->first();
    }

    public function test_alta_instancia_lista_y_fallida_no_son_ingresos(): void
    {
        $lead = $this->alta();
        $meta = app(MetaConversionesService::class);
        foreach ([TrialInstance::ESTADO_ASIGNADA, TrialInstance::ESTADO_FALLIDA] as $estado) {
            TrialInstance::where('lead_id', $lead->id)->update(['estado' => $estado]);
            $meta->registrar_alcanzados($lead);
            $meta->conciliar();
            $this->assertNull($this->inicio($lead));
        }
        $this->assertSame(['Lead'], LeadEventoMeta::where('lead_id', $lead->id)->pluck('evento')->all());
        Http::assertNothingSent();
    }

    public function test_primer_ingreso_repetido_conserva_id_fecha_y_contexto_del_alta(): void
    {
        $lead = $this->alta();
        $uuid = (string) Str::uuid();
        $this->ingresar($lead, '2026-11-03T14:00:00-03:00', $uuid);
        $inicio = $this->inicio($lead);
        $this->assertNotNull($inicio);
        $this->ingresar($lead, '2026-11-03T14:00:00-03:00', $uuid);
        $this->ingresar($lead, '2026-11-03T14:30:00-03:00');
        $inicio->refresh();
        $this->assertSame(1, LeadEventoMeta::where('lead_id', $lead->id)->where('evento', 'StartTrial')->count());
        $this->assertSame('2026-11-03 14:00:00', $inicio->event_time->format('Y-m-d H:i:s'));
        $this->assertSame(MetaConversionesService::event_id_para($lead->id, 'StartTrial', 'web'), $inicio->event_id);
        $alta = LeadEventoMeta::where('lead_id', $lead->id)->where('evento', 'Lead')->first();
        $this->assertSame($alta->contexto_web, $inicio->contexto_web);
        $payload = app(MetaConversionesService::class)->armar_payload($inicio, $lead)['data'][0];
        $this->assertSame($inicio->event_time->timestamp, $payload['event_time']);
        $this->assertSame('website', $payload['action_source']);
        $this->assertSame('Mozilla/5.0 (prueba)', $payload['user_data']['client_user_agent']);
        $this->assertSame('fb.1.1761130000000.click-original', $payload['user_data']['fbc']);
        $this->assertSame($alta->contexto_web['fbp'], $payload['user_data']['fbp']);
        $this->assertSame('https://comerciocity.com/prueba', $payload['event_source_url']);
        $this->assertSame(['Lead', 'StartTrial'], LeadEventoMeta::where('lead_id', $lead->id)->orderBy('id')->pluck('evento')->all());
    }

    public function test_conciliacion_recupera_hito_aunque_el_lead_no_cambio_recientemente(): void
    {
        $lead = $this->alta();
        $this->guardar_hito($lead, '2026-11-02 12:00:00');
        Lead::whereKey($lead->id)->update(['updated_at' => '2026-10-01 12:00:00']);
        app(MetaConversionesService::class)->conciliar();
        $this->assertSame('2026-11-02 12:00:00', $this->inicio($lead)->event_time->format('Y-m-d H:i:s'));
        $this->assertSame(['Lead', 'StartTrial'], LeadEventoMeta::where('lead_id', $lead->id)->orderBy('id')->pluck('evento')->all());
    }

    private function guardar_hito(Lead $lead, string $fecha, array $cambios = []): void
    {
        LeadDemoHito::create(array_merge([
            'lead_id' => $lead->id, 'orden' => 1, 'tipo' => 'prueba', 'clip_id' => 'ingreso',
            'titulo' => 'Ingreso de prueba', 'evento_esperado' => 'prueba.ingreso',
            'estado' => 'completo', 'accion_hecha_at' => $fecha,
        ], $cambios));
    }

    public function test_replay_recupera_registro_faltante_sin_mover_el_hito(): void
    {
        $lead = $this->alta();
        $this->guardar_hito($lead, '2026-11-02 12:00:00');
        $this->ingresar($lead);
        $this->assertSame('2026-11-02 12:00:00', $this->inicio($lead)->event_time->format('Y-m-d H:i:s'));
    }

    public function test_hito_parcial_o_de_demo_no_habilita_starttrial(): void
    {
        $lead = $this->alta();
        $this->guardar_hito($lead, '2026-11-02 12:00:00', ['estado' => 'parcial']);
        $meta = app(MetaConversionesService::class);
        $this->assertNull($meta->registrar_inicio_prueba($lead));
        LeadDemoHito::where('lead_id', $lead->id)->update(['estado' => 'completo', 'tipo' => 'ingreso']);
        $meta->registrar_alcanzados($lead);
        $this->assertNull($this->inicio($lead));
    }

    public function test_sin_configuracion_y_capi_fallida_se_recuperan_con_mismo_id_y_fecha(): void
    {
        $lead = $this->alta();
        config(['services.meta_capi.web_token' => '']);
        $this->ingresar($lead);
        $inicio = $this->inicio($lead);
        $this->assertSame('sin_configurar', $inicio->estado);
        config(['services.meta_capi.web_token' => 'token-falso']);
        $meta = app(MetaConversionesService::class);
        $meta->conciliar();
        $this->assertSame('pendiente', $inicio->fresh()->estado);
        Http::swap(new HttpFactorySinSalida());
        Http::fakeSequence()->push(['error' => ['message' => 'Fallo simulado']], 500)->push(['events_received' => 1], 200);
        $this->assertSame('error', $meta->enviar($inicio));
        Carbon::setTestNow(Carbon::parse('2026-11-03 15:30:00', 'America/Argentina/Buenos_Aires'));
        $meta->conciliar();
        $this->assertSame('enviado', $meta->enviar($inicio->fresh()));
        $this->assertSame('enviado', $meta->enviar($inicio->fresh()));
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($inicio) {
            return $request['data'][0]['event_id'] === $inicio->event_id
                && $request['data'][0]['event_time'] === $inicio->event_time->timestamp;
        });
    }

    public function test_hito_historico_conserva_fecha_y_no_sale_como_conversion_actual(): void
    {
        $lead = $this->alta();
        $this->guardar_hito($lead, '2026-10-01 12:00:00');
        app(MetaConversionesService::class)->registrar_alcanzados($lead);
        $inicio = $this->inicio($lead);
        $this->assertSame('2026-10-01 12:00:00', $inicio->event_time->format('Y-m-d H:i:s'));
        $this->assertSame('omitido', app(MetaConversionesService::class)->enviar($inicio));
        Http::assertNothingSent();
    }

    public function test_fila_antigua_pendiente_sin_evidencia_no_se_envia(): void
    {
        $lead = $this->alta();
        $fila = LeadEventoMeta::create([
            'lead_id' => $lead->id, 'canal' => 'web', 'evento' => 'StartTrial', 'estado' => 'pendiente',
            'event_id' => MetaConversionesService::event_id_para($lead->id, 'StartTrial', 'web'),
            'event_time' => Carbon::now(), 'intentos' => 0,
        ]);
        $this->assertSame('omitido', app(MetaConversionesService::class)->enviar($fila));
        Http::assertNothingSent();
    }

    public function test_legacy_omitido_antes_del_ingreso_se_recupera_una_sola_vez(): void
    {
        $lead = $this->alta();
        $fila = LeadEventoMeta::create([
            'lead_id' => $lead->id, 'canal' => 'web', 'evento' => 'StartTrial', 'estado' => 'pendiente',
            'event_id' => MetaConversionesService::event_id_para($lead->id, 'StartTrial', 'web'),
            'event_time' => Carbon::now(), 'intentos' => 0,
        ]);
        $meta = app(MetaConversionesService::class);
        $this->assertSame('omitido', $meta->enviar($fila));
        $this->ingresar($lead);
        $fila->refresh();
        $this->assertSame('pendiente', $fila->estado);
        $this->assertSame('2026-11-03 14:00:00', $fila->event_time->format('Y-m-d H:i:s'));
        $this->assertSame('enviado', $meta->enviar($fila));
        $this->ingresar($lead);
        $this->assertSame('enviado', $meta->enviar($fila->fresh()));
        Http::assertSentCount(1);
    }

    public function test_lead_enviado_antes_del_ingreso_no_pierde_contexto_y_reintento_lo_conserva(): void
    {
        $lead = $this->alta();
        $meta = app(MetaConversionesService::class);
        $alta = LeadEventoMeta::where('lead_id', $lead->id)->where('evento', 'Lead')->first();
        $contexto = $alta->contexto_web;
        $this->assertSame('enviado', $meta->enviar($alta));
        $this->assertNull($alta->fresh()->contexto_web, 'La privacidad del evento Lead no cambia.');
        $this->ingresar($lead);
        $inicio = $this->inicio($lead);
        $this->assertSame($contexto, $inicio->contexto_web);
        Http::swap(new HttpFactorySinSalida());
        Http::fakeSequence()->push(['error' => ['message' => 'Fallo simulado']], 500)->push(['events_received' => 1], 200);
        $this->assertSame('error', $meta->enviar($inicio));
        $this->assertSame($contexto, $inicio->fresh()->contexto_web);
        $this->assertSame('enviado', $meta->enviar($inicio->fresh()));
        $this->assertNull($inicio->fresh()->contexto_web);
        Http::assertSentCount(2);
        foreach (Http::recorded() as $pedido) {
            $this->assertSame($contexto['fbp'], $pedido[0]['data'][0]['user_data']['fbp']);
            $this->assertSame($contexto['fbc'], $pedido[0]['data'][0]['user_data']['fbc']);
        }
    }

    public function test_alta_con_lead_previo_no_reemite_lead_y_usa_contexto_de_esta_prueba(): void
    {
        $lead = Lead::withoutEvents(function () {
            return Lead::create(['uuid' => (string) Str::uuid(), 'phone' => self::TELEFONO_E164, 'contact_name' => 'Contacto previo',
                'origen' => 'landing', 'status' => 'calificado']);
        });
        $previo = LeadEventoMeta::create([
            'lead_id' => $lead->id, 'canal' => 'web', 'evento' => 'Lead', 'estado' => 'enviado',
            'event_id' => MetaConversionesService::event_id_para($lead->id, 'Lead', 'web'),
            'event_time' => '2026-09-01 12:00:00', 'intentos' => 1,
            'contexto_web' => ['client_user_agent' => 'Navegador viejo', 'event_source_url' => 'https://comerciocity.com/demo',
                'fbp' => 'fb.1.1760000000000.anterior'],
        ]);
        $this->crear_instancia_libre();
        $respuesta = $this->postear_alta();
        $respuesta->assertStatus(201);
        $this->assertSame([], $respuesta->json('meta_eventos'));
        $this->assertSame('enviado', $previo->fresh()->estado);
        $this->ingresar($lead->fresh());
        $this->assertSame('Mozilla/5.0 (prueba)', $this->inicio($lead)->contexto_web['client_user_agent']);
        $this->assertSame('https://comerciocity.com/prueba', $this->inicio($lead)->contexto_web['event_source_url']);
    }

    public function test_snapshot_privado_se_consume_y_rollback_no_lo_pierde(): void
    {
        $lead = $this->alta();
        $instancia = TrialInstance::where('lead_id', $lead->id)->first();
        $contexto = $instancia->contexto_meta;
        $this->assertNotNull($contexto);
        $this->assertArrayNotHasKey('contexto_meta', $instancia->toArray());
        $this->guardar_hito($lead, '2026-11-03 14:00:00');
        try {
            DB::transaction(function () use ($lead) {
                app(MetaConversionesService::class)->registrar_inicio_prueba($lead, false);
                throw new \RuntimeException('Rollback simulado');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback simulado', $e->getMessage());
        }
        $this->assertNull($this->inicio($lead));
        $this->assertSame($contexto, $instancia->fresh()->contexto_meta);
        app(MetaConversionesService::class)->registrar_inicio_prueba($lead, false);
        $this->assertSame($contexto, $this->inicio($lead)->contexto_web);
        $this->assertNull($instancia->fresh()->contexto_meta);
    }

    public function test_snapshot_sin_ingreso_se_purga_a_los_siete_dias(): void
    {
        $lead = $this->alta();
        $instancia = TrialInstance::where('lead_id', $lead->id)->first();
        Carbon::setTestNow(Carbon::parse('2026-11-11 15:00:00', 'America/Argentina/Buenos_Aires'));
        app(MetaConversionesService::class)->conciliar();
        $this->assertNull($instancia->fresh()->contexto_meta);
        $this->assertNull($this->inicio($lead));
    }

    public function test_ingreso_no_reactiva_un_descarte_por_otro_motivo(): void
    {
        $lead = $this->alta();
        $fila = LeadEventoMeta::create([
            'lead_id' => $lead->id, 'canal' => 'web', 'evento' => 'StartTrial', 'estado' => 'omitido',
            'event_id' => MetaConversionesService::event_id_para($lead->id, 'StartTrial', 'web'),
            'event_time' => Carbon::now(), 'intentos' => 0, 'ultimo_error' => 'Cambio de canal',
        ]);
        $this->ingresar($lead);
        $this->assertSame('omitido', $fila->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_pendiente_legacy_con_fecha_vieja_se_corrige_al_ingresar(): void
    {
        $lead = $this->alta();
        $fila = LeadEventoMeta::create([
            'lead_id' => $lead->id, 'canal' => 'web', 'evento' => 'StartTrial', 'estado' => 'pendiente',
            'event_id' => MetaConversionesService::event_id_para($lead->id, 'StartTrial', 'web'),
            'event_time' => '2026-10-01 12:00:00', 'intentos' => 0,
        ]);
        $this->ingresar($lead);
        $this->assertSame('2026-11-03 14:00:00', $fila->fresh()->event_time->format('Y-m-d H:i:s'));
        $this->assertSame('enviado', app(MetaConversionesService::class)->enviar($fila->fresh()));
        Http::assertSentCount(1);
    }

    public function test_envio_inmediato_ve_fbclid_del_contacto_sin_cookie_fbc(): void
    {
        $cola = new class($this->app) extends QueueFake {
            public $contactos_al_enviar = [];

            public function push($job, $data = '', $queue = null)
            {
                parent::push($job, $data, $queue);
                if ($job instanceof EnviarEventoMetaJob) {
                    $this->contactos_al_enviar[] = DB::table('lead_atribucion_contactos')->count();
                    // Solo Meta corre inmediatamente y su HTTP está falsificado; los demás jobs no corren.
                    $job->handle(app(MetaConversionesService::class));
                }
            }
        };
        Queue::swap($cola);
        $this->crear_instancia_libre();
        $respuesta = $this->postear_alta(['atribucion' => ['fbclid' => 'click-sin-cookie']]);
        $respuesta->assertStatus(201);
        $lead = $this->lead_del_alta($respuesta->json('uuid'));
        $this->assertSame([1], $cola->contactos_al_enviar, 'El contacto existe incluso antes del envío inmediato.');
        $this->assertSame(['Lead'], LeadEventoMeta::where('lead_id', $lead->id)->pluck('evento')->all());
        $this->assertSame('enviado', LeadEventoMeta::where('lead_id', $lead->id)->value('estado'));
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $evento = $request['data'][0];
            return $evento['event_name'] === 'Lead'
                && substr($evento['user_data']['fbc'], -strlen('.click-sin-cookie')) === '.click-sin-cookie'
                && $evento['user_data']['client_user_agent'] === 'Mozilla/5.0 (prueba)';
        });
    }

    public function test_atribucion_con_ids_nombres_utm_y_click_llega_al_contacto(): void
    {
        $this->crear_instancia_libre();
        $atribucion = [
            'campaign_id' => '120000000000001', 'adset_id' => '120000000000002', 'ad_id' => '120000000000003',
            'campaign_name' => 'Campaña prueba', 'adset_name' => 'Comercios', 'ad_name' => 'Video ingreso',
            'utm_source' => 'instagram', 'utm_medium' => 'paid_social', 'utm_campaign' => 'campana-nueva',
            'utm_content' => 'video-1', 'utm_term' => 'comercios', 'fbclid' => 'click-original',
        ];
        $respuesta = $this->postear_alta(['atribucion' => array_merge($atribucion, ['campo_ajeno' => 'ignorar'])]);
        $respuesta->assertStatus(201);
        $lead = $this->lead_del_alta($respuesta->json('uuid'));
        $contacto = DB::table('lead_atribucion_contactos')->where('lead_id', $lead->id)->first();
        $datos = json_decode($contacto->datos, true);
        foreach ($atribucion as $campo => $valor) {
            $this->assertSame($valor, $datos[$campo], $campo);
        }
        $this->assertArrayNotHasKey('campo_ajeno', $datos);
    }

    public function test_atribucion_invalida_se_rechaza_sin_alta(): void
    {
        $this->postear_alta(['atribucion' => ['ad_id' => 'no-es-un-id']])->assertStatus(422);
        $this->assertSame(0, TrialInstance::whereNotNull('lead_id')->count());
    }
}
