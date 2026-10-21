<?php

namespace Tests\Feature;

use App\Events\LeadConversationUpdated;
use App\Events\LeadSuggestionCreated;
use App\Models\Admin;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use App\Models\WhatsappConfig;
use App\Services\LeadAtribucionService;
use App\Services\LeadOrigenService;
use App\Services\LeadWhatsappOnboardingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AtribucionWhatsappLandingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 15:00:00', config('app.timezone')));
        Http::fake();
        Queue::fake();
        Mail::fake();
        config(['services.meta_capi.token' => '', 'services.meta_capi.dataset_id' => '', 'services.meta_capi.web_token' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emitir(array $datos = []): string
    {
        return $this->postJson('/api/landing/whatsapp-referencias', $datos)->assertStatus(201)->json('reference');
    }

    private function lead(): Lead
    {
        return Lead::create(['phone' => '+54911' . random_int(10000000, 99999999), 'status' => 'nuevo']);
    }

    private function mensaje(Lead $lead, string $contenido): LeadMessage
    {
        return LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'content' => $contenido,
            'is_status_event' => false, 'status' => 'enviado', 'sent_at' => now(),
            'whatsapp_message_id' => 'wamid.atribucion.' . bin2hex(random_bytes(8)),
        ]);
    }

    private function autenticar(): void
    {
        Sanctum::actingAs(Admin::create(['name' => 'Operador de prueba', 'email' => 'atribucion-' . uniqid() . '@test.local', 'password' => 'fixture-no-utilizable']));
    }

    private function demo(): Demo
    {
        return Demo::create(['erp_spa_url' => 'https://atribucion-demo.test', 'erp_api_url' => 'https://atribucion-api.test', 'ecommerce_spa_url' => 'https://atribucion-tienda.test', 'ecommerce_api_url' => 'https://atribucion-tienda-api.test']);
    }

    public function test_emite_referencia_opaca_persiste_solo_hash_y_fecha_con_zona(): void
    {
        $respuesta = $this->postJson('/api/landing/whatsapp-referencias', ['ad_id' => '123456', 'campaign_name' => 'Campaña otoño'])->assertStatus(201);
        $reference = $respuesta->json('reference');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $reference);
        $fila = DB::table('landing_whatsapp_referencias')->where('reference_hash', hash('sha256', $reference))->first();
        $this->assertNotNull($fila);
        $this->assertStringNotContainsString($reference, json_encode($fila));
        $this->assertSame('2026-10-28', Carbon::parse($respuesta->json('expires_at'))->format('Y-m-d'));
        $this->assertSame('123456', json_decode($fila->datos, true)['ad_id']);
    }

    public function test_rechaza_macros_tipos_no_textuales_pii_y_campos_desconocidos(): void
    {
        foreach ([['ad_id' => '{{ad.id}}'], ['ad_name' => '{{ad.name}}'], ['ad_id' => 123], ['utm_source' => []], ['phone' => '5491100000000'], ['lead_id' => 1], ['fbclid' => str_repeat('a', 501)]] as $datos) {
            $this->postJson('/api/landing/whatsapp-referencias', $datos)->assertStatus(422);
        }
    }

    public function test_cors_landing_y_limite_propio_sin_consumir_cubeta_api(): void
    {
        $this->call('OPTIONS', '/api/landing/whatsapp-referencias', [], [], [], [
            'HTTP_ORIGIN' => 'https://comerciocity.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://comerciocity.com');
        $ruta = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/landing/whatsapp-referencias', 'POST'));
        $this->assertContains('throttle:api', $ruta->excludedMiddleware());
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['atribucion.limite_por_minuto' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.83']);
        $this->emitir();
        $this->emitir();
        $this->postJson('/api/landing/whatsapp-referencias')->assertStatus(429);
    }

    public function test_consumo_idempotente_no_reasigna_y_preserva_original_y_cta(): void
    {
        $reference = $this->emitir(['ad_id' => '123']);
        $lead = $this->lead();
        $texto = LeadOrigenService::FRASE_LANDING . ' [CC:' . $reference . ']';
        $mensaje = $this->mensaje($lead, $texto);
        app(LeadAtribucionService::class)->consumir($mensaje);
        $this->assertDatabaseCount('lead_atribucion_contactos', 1);
        $this->assertSame($texto, $mensaje->fresh()->content);
        $this->assertSame(LeadOrigenService::FRASE_LANDING, LeadAtribucionService::contenido_para_agente($mensaje));
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadAtribucionService::contenido_para_agente($mensaje)));
        $this->assertStringContainsString('es un SI a la demo', LeadOrigenService::linea_del_si_explicito($lead));
        $repetido = $this->mensaje($lead, $texto);
        $this->assertSame(LeadOrigenService::FRASE_LANDING, LeadAtribucionService::contenido_para_agente($repetido));
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadAtribucionService::contenido_para_agente($repetido)));
        $otro = $this->lead();
        $replay = $this->mensaje($otro, $texto);
        $this->assertSame(LeadOrigenService::FRASE_LANDING, LeadAtribucionService::contenido_para_agente($replay));
        $this->assertDatabaseCount('lead_atribucion_contactos', 1);
        $this->assertDatabaseHas('landing_whatsapp_referencias', ['reference_hash' => hash('sha256', $reference), 'claimed_lead_id' => $lead->id, 'claimed_message_id' => $mensaje->id]);
    }

    public function test_vencidas_inexistentes_y_dos_codigos_no_acreditan_y_conservan_original(): void
    {
        $vencida = $this->emitir();
        DB::table('landing_whatsapp_referencias')->where('reference_hash', hash('sha256', $vencida))->update(['expires_at' => now()]);
        $a = $this->emitir();
        $b = $this->emitir();
        foreach (['[CC:' . $vencida . ']', '[CC:' . str_repeat('0', 32) . ']', '[CC:' . $a . '] [CC:' . $b . ']'] as $texto) {
            $mensaje = $this->mensaje($this->lead(), $texto);
            $this->assertSame($texto, $mensaje->fresh()->content);
        }
        $this->assertDatabaseCount('lead_atribucion_contactos', 0);
    }

    public function test_dos_procesos_compiten_por_la_misma_referencia_y_solo_uno_la_reclama(): void
    {
        // Fixtures comprometidas en otra conexión: los hijos deben ver la misma BD, no el rollback del test.
        $base_testing = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/^admin_testing_s[0-9]+$/', $base_testing);
        config(['database.connections.atribucion_race' => config('database.connections.mysql')]);
        $db = DB::connection('atribucion_race');
        $reference = bin2hex(random_bytes(16));
        $referencia_id = $db->table('landing_whatsapp_referencias')->insertGetId([
            'reference_hash' => hash('sha256', $reference), 'datos' => json_encode(['ad_id' => '999123']),
            'created_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $leads = [];
        $mensajes = [];
        $script = tempnam(sys_get_temp_dir(), 'cc-atribucion-');
        try {
            for ($i = 0; $i < 2; $i++) {
                $lead = Lead::on('atribucion_race')->create(['status' => 'nuevo', 'phone' => '+54911' . random_int(10000000, 99999999)]);
                $leads[] = $lead->id;
                $mensajes[] = $db->table('lead_messages')->insertGetId([
                    'lead_id' => $lead->id, 'sender' => 'lead', 'status' => 'enviado', 'content' => '[CC:' . $reference . ']',
                    'is_status_event' => false, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            file_put_contents($script, '<?php require $argv[1]."/vendor/autoload.php"; $app = require $argv[1]."/bootstrap/app.php"; $app->loadEnvironmentFrom(".env.testing"); $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (!preg_match("/^admin_testing_s[0-9]+$/", $argv[3]) || Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== $argv[3]) exit(9); app(App\\Services\\LeadAtribucionService::class)->consumir(App\\Models\\LeadMessage::findOrFail($argv[2])); echo "ok";');
            $procesos = [];
            foreach ($mensajes as $id) {
                $pipes = [];
                $proceso = proc_open([PHP_BINARY, $script, base_path(), (string) $id, $base_testing], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($proceso);
                $procesos[] = [$proceso, $pipes];
            }
            foreach ($procesos as $proceso) {
                $salida = stream_get_contents($proceso[1][1]);
                $error = stream_get_contents($proceso[1][2]);
                fclose($proceso[1][1]);
                fclose($proceso[1][2]);
                $this->assertSame(0, proc_close($proceso[0]), $error);
                $this->assertSame('ok', $salida);
            }
            $this->assertSame(1, $db->table('lead_atribucion_contactos')->whereIn('lead_id', $leads)->count());
            $reclamada = $db->table('landing_whatsapp_referencias')->where('id', $referencia_id)->first();
            $this->assertContains((int) $reclamada->claimed_lead_id, $leads);
            $this->assertContains((int) $reclamada->claimed_message_id, $mensajes);
        } finally {
            $db->table('lead_atribucion_contactos')->whereIn('lead_id', $leads)->delete();
            $db->table('lead_messages')->whereIn('id', $mensajes)->delete();
            $db->table('leads')->whereIn('id', $leads)->delete();
            $db->table('landing_whatsapp_referencias')->where('id', $referencia_id)->delete();
            if (is_file($script)) unlink($script);
            DB::purge('atribucion_race');
        }
    }

    public function test_contacto_existente_guarda_recontacto_sin_reescribir_origen(): void
    {
        $lead = $this->lead();
        $this->mensaje($lead, 'Consulta anterior');
        $reference = $this->emitir(['ad_id' => '456']);
        $this->mensaje($lead, LeadOrigenService::FRASE_LANDING . ' [CC:' . $reference . ']');
        $this->assertSame('desconocido', $lead->fresh()->origen);
        $this->assertDatabaseHas('lead_atribucion_contactos', ['lead_id' => $lead->id, 'tipo' => 'recontacto']);
        $this->assertDatabaseMissing('lead_atribucion_contactos', ['lead_id' => $lead->id, 'tipo' => 'adquisicion']);
    }

    /** @dataProvider referencias_del_webhook */
    public function test_webhook_real_conserva_original_y_reconoce_solicitud_de_demo(string $estado): void
    {
        WhatsappConfig::query()->update(['is_active' => false]);
        WhatsappConfig::create(['kapso_api_key' => 'fixture-local', 'phone_number_id' => '1234567890', 'webhook_secret' => 'firma-local-atribucion', 'is_active' => true, 'test_mode' => true]);
        $reference = $this->emitir(['ad_id' => '123']);
        if ($estado === 'vencida') {
            DB::table('landing_whatsapp_referencias')->where('reference_hash', hash('sha256', $reference))->update(['expires_at' => now()]);
        } elseif ($estado === 'inexistente') {
            $reference = str_repeat('0', 32);
        } elseif ($estado === 'otro_lead') {
            $this->mensaje($this->lead(), '[CC:' . $reference . ']');
        }
        $phone = '+54911' . random_int(10000000, 99999999);
        $texto = LeadOrigenService::FRASE_LANDING . ' [CC:' . $reference . ']';
        $body = json_encode([
            'event' => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $phone, 'contact_name' => 'Contacto de prueba'],
            'message' => ['id' => 'wamid.webhook.' . bin2hex(random_bytes(8)), 'from' => $phone, 'type' => 'text', 'text' => ['body' => $texto], 'timestamp' => (string) now()->getTimestamp()],
        ]);
        $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, 'firma-local-atribucion')], $body)->assertStatus(200);
        $lead = Lead::query()->where('phone', $phone)->firstOrFail();
        $mensaje = LeadOrigenService::primer_mensaje_entrante($lead->id);
        $this->assertSame($texto, $mensaje->content);
        $this->assertSame('landing', $lead->origen);
        $this->assertSame(LeadOrigenService::FRASE_LANDING, LeadAtribucionService::contenido_para_agente($mensaje));
        if ($estado === 'vigente') {
            $this->assertDatabaseHas('lead_atribucion_contactos', ['lead_id' => $lead->id, 'evidencia' => 'referencia_landing']);
        } else {
            $this->assertDatabaseMissing('lead_atribucion_contactos', ['lead_id' => $lead->id]);
        }
        // El webhook ya mandó la bienvenida: el "sí explícito" no debe reiterarse después de responder.
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadAtribucionService::contenido_para_agente($mensaje)));
        $historial = (new \App\Services\LeadAiService())->historial_de_la_conversacion($lead->fresh()->load('messages'));
        $this->assertStringNotContainsString($reference, $historial);
    }

    public static function referencias_del_webhook(): array
    {
        return [['vigente'], ['vencida'], ['inexistente'], ['otro_lead']];
    }

    public function test_normalizar_cta_solo_quita_sufijo_tecnico_exacto_sin_quitar_objeciones(): void
    {
        $lead = $this->lead();
        $token = str_repeat('a', 32);
        $mensaje = $this->mensaje($lead, LeadOrigenService::FRASE_LANDING . ', pero hoy no puedo [CC:' . $token . ']');
        $normalizado = LeadAtribucionService::contenido_para_agente($mensaje);
        $this->assertSame(LeadOrigenService::FRASE_LANDING . ', pero hoy no puedo', $normalizado);
        $this->assertFalse(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton($normalizado));
        foreach (['[CC:corto]', '[CC:' . $token . '] mañana', '[cc:' . $token . ']'] as $sufijo) {
            $texto = LeadOrigenService::FRASE_LANDING . ' ' . $sufijo;
            $m = $this->mensaje($lead, $texto);
            $this->assertSame($texto, LeadAtribucionService::contenido_para_agente($m));
        }
    }

    public function test_ctwa_en_ambos_ordenes_y_prioridad_del_referral_del_mismo_mensaje(): void
    {
        foreach (['antes', 'despues'] as $orden) {
            $lead = $this->lead();
            $wamid = 'wamid.ctwa.' . bin2hex(random_bytes(8));
            $datos = ['phone' => $lead->phone, 'wamid' => $wamid, 'source_type' => 'ad', 'source_id' => '789', 'ctwa_clid' => 'clid-prueba', 'received_at' => now()];
            if ($orden === 'antes') WhatsappAdReferral::create($datos);
            $reference = $this->emitir(['ad_id' => '123']);
            $mensaje = LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'status' => 'enviado', 'content' => LeadOrigenService::FRASE_LANDING . ' [CC:' . $reference . ']', 'whatsapp_message_id' => $wamid, 'sent_at' => now(), 'is_status_event' => false]);
            if ($orden === 'despues') WhatsappAdReferral::create($datos);
            $fila = DB::table('lead_atribucion_contactos')->where('message_id', $mensaje->id)->first();
            $this->assertSame('meta_ctwa', $fila->evidencia);
            $this->assertSame('789', json_decode($fila->datos, true)['ad_id']);
            $this->assertSame('clid-prueba', $lead->fresh()->ctwa_clid);
            $this->assertSame(1, DB::table('lead_atribucion_contactos')->where('message_id', $mensaje->id)->count());
        }
    }

    public function test_primera_reserva_real_no_se_duplica_ni_desaparece_al_cancelar(): void
    {
        $lead = $this->lead();
        $reference = $this->emitir(['ad_id' => '123', 'fbclid' => 'clic-privado']);
        $this->mensaje($lead, LeadOrigenService::FRASE_LANDING . ' [CC:' . $reference . ']');
        $demo = $this->demo();
        $lead->refresh()->fill(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-22', 'demo_start_time' => '10:00', 'demo_end_time' => '12:00'])->save();
        $primera = DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->first();
        $this->assertNotNull($primera);
        $lead->fill(['status' => 'en_pausa', 'demo_date' => null, 'demo_start_time' => null, 'demo_id' => null])->save();
        $lead->fill(['status' => 'demo_agendada', 'demo_date' => '2026-10-23', 'demo_start_time' => '14:00', 'demo_id' => $demo->id])->save();
        $this->assertSame(1, DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->count());
        $this->assertSame('2026-10-22', DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->value('demo_date'));
        $this->autenticar();
        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id . '/atribucion')->assertStatus(200);
        $this->assertStringNotContainsString($reference, $respuesta->getContent());
        $this->assertStringNotContainsString('clic-privado', $respuesta->getContent());
        $this->assertSame('123', $respuesta->json('reserva.datos.ad_id'));
    }

    public function test_editar_un_lead_con_turno_historico_no_inventa_reserva(): void
    {
        $lead = $this->lead();
        DB::table('leads')->where('id', $lead->id)->update(['status' => 'demo_agendada', 'demo_id' => $this->demo()->id, 'demo_date' => '2026-10-20', 'demo_start_time' => '10:00']);
        $lead->refresh()->fill(['contact_name' => 'Nombre nuevo'])->save();
        $this->assertDatabaseMissing('lead_atribucion_reservas', ['lead_id' => $lead->id]);
    }

    public function test_estado_se_revalida_bajo_lock_aunque_modelo_en_memoria_siga_agendado(): void
    {
        $lead = $this->lead();
        DB::table('leads')->where('id', $lead->id)->update(['status' => 'demo_agendada', 'demo_id' => $this->demo()->id, 'demo_date' => '2026-10-22', 'demo_start_time' => '10:00']);
        $lead->refresh();
        DB::table('leads')->where('id', $lead->id)->update(['status' => 'en_pausa']);
        app(LeadAtribucionService::class)->registrar_reserva($lead);
        $this->assertDatabaseMissing('lead_atribucion_reservas', ['lead_id' => $lead->id]);
    }

    public function test_detalle_metricas_solo_autenticadas_y_broadcast_sin_datos_crudos(): void
    {
        $lead = $this->lead();
        $reference = $this->emitir(['ad_id' => '123']);
        $mensaje = $this->mensaje($lead, '[CC:' . $reference . ']');
        $this->getJson('/api/admin/lead/' . $lead->id . '/atribucion')->assertStatus(401);
        $this->getJson('/api/admin/lead/atribucion/metricas?desde=2026-10-21&hasta=2026-10-21')->assertStatus(401);
        $payload = (new LeadConversationUpdated($lead->id, $mensaje->id))->broadcastWith();
        $this->assertSame(['lead_id', 'lead_message_id', 'is_status_update', 'delivery_status'], array_keys($payload));
        $this->assertStringNotContainsString($reference, json_encode($payload));
        $this->assertArrayNotHasKey('atribucion', $lead->fresh()->toArray());
    }

    public function test_metricas_conservan_desconocidos_en_denominador_y_separan_recontactos(): void
    {
        // Acotar la cohorte a una fecha sin fixtures históricos.
        Carbon::setTestNow(Carbon::parse('2030-01-15 10:00:00', config('app.timezone')));
        $nuevo = $this->lead();
        $this->mensaje($nuevo, '[CC:' . $this->emitir(['ad_id' => '123']) . ']');
        $otro = $this->lead();
        $this->mensaje($otro, 'Sin publicidad');
        $this->mensaje($otro, '[CC:' . $this->emitir(['ad_id' => '456']) . ']');
        $nuevo->refresh()->fill(['status' => 'demo_agendada', 'demo_id' => $this->demo()->id, 'demo_date' => '2030-01-16', 'demo_start_time' => '10:00'])->save();
        $this->autenticar();
        $r = $this->getJson('/api/admin/lead/atribucion/metricas?desde=2030-01-15&hasta=2030-01-15')->assertStatus(200);
        $this->assertSame(2, $r->json('denominador'));
        $this->assertSame(1, $r->json('recontactos'));
        $por_evidencia = collect($r->json('grupos'))->keyBy('evidencia');
        $this->assertSame(1, $por_evidencia['referencia_landing']['reservas']);
        $this->assertSame(1, $por_evidencia['sin_atribucion']['leads']);
        $this->getJson('/api/admin/lead/atribucion/metricas?desde=2020-01-01&hasta=2030-01-01')->assertStatus(422);
    }

    public function test_dos_referencias_del_mismo_lead_no_multiplican_denominador_ni_reservas(): void
    {
        Carbon::setTestNow(Carbon::parse('2031-02-12 10:00:00', config('app.timezone')));
        $lead = $this->lead();
        $this->mensaje($lead, '[CC:' . $this->emitir(['ad_id' => '123']) . ']');
        $this->mensaje($lead, '[CC:' . $this->emitir(['ad_id' => '456']) . ']');
        $lead->refresh()->fill(['status' => 'demo_agendada', 'demo_id' => $this->demo()->id, 'demo_date' => '2031-02-13', 'demo_start_time' => '10:00'])->save();
        $this->autenticar();
        $r = $this->getJson('/api/admin/lead/atribucion/metricas?desde=2031-02-12&hasta=2031-02-12')->assertStatus(200);
        $this->assertSame(1, $r->json('denominador'));
        $this->assertSame(1, $r->json('recontactos'));
        $this->assertCount(1, $r->json('grupos'));
        $this->assertSame('123', $r->json('grupos.0.ad_id'));
        $this->assertSame(1, $r->json('grupos.0.leads'));
        $this->assertSame(1, $r->json('grupos.0.reservas'));
        $this->assertDatabaseHas('lead_atribucion_contactos', ['lead_id' => $lead->id, 'tipo' => 'adquisicion']);
        $this->assertDatabaseHas('lead_atribucion_contactos', ['lead_id' => $lead->id, 'tipo' => 'recontacto']);
    }
}
