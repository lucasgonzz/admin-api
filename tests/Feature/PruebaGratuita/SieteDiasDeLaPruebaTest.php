<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Los siete días de la prueba, de punta a punta, con el reloj movido día por día (misión cruzada
 * prueba-e2e-campana-imperio, 11/11/2026, ajuste final del recorrido): qué WhatsApp sale cada mañana a las 10:00
 * para un negocio que cumple un paso por día (tiene que llegar a la tienda) y para uno que no entra nunca.
 *
 * Alta a las 15:00 (después del empujón de las 10:00), prueba de 7 días, todo dentro del alcance de las novedades.
 */
class SieteDiasDeLaPruebaTest extends BaseDeLaPrueba
{
    /** @var Carbon El alta: un lunes a las 15:00 de Argentina. */
    private $alta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alta = Carbon::parse('2026-11-02 15:00:00', PruebaWhatsappService::ZONA)->setTimezone((string) config('app.timezone'));
        Carbon::setTestNow($this->alta);

        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        foreach (PruebaVideoCatalogoService::URLS_APROBADAS as $codigo => $url) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), $url);
        }
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * El negocio recién dado de alta, con todas las plantillas aprobadas y la bienvenida enviada.
     *
     * @return array{0: Client, 1: Lead, 2: \App\Services\WhatsappSendService}
     */
    private function alta_de_la_prueba(): array
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        foreach (PruebaPlantillasService::todas() as $nombre => $definicion) {
            FollowupTemplate::create([
                'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
                'body_template' => $definicion['body'], 'language_code' => 'es_AR', 'activa' => true,
                'meta_status' => 'APPROVED', 'meta_category' => 'UTILITY', 'uso' => $definicion['uso'],
                'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
            ]);
        }

        $client = $this->cliente_en_prueba();
        $client->email         = 'duena@test.local';
        $client->trial_days    = 7;
        $client->trial_ends_at = $this->alta->copy()->addDays(7);
        $client->save();
        $lead = $this->lead_en_prueba($client);

        $this->la_instancia_contesta([]);
        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);

        return [$client, $lead, $espia];
    }

    /**
     * @param array<string> $hechos
     */
    private function la_instancia_contesta(array $hechos): void
    {
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia($hechos), 200)]);
    }

    /**
     * El empujón de las 10:00 del día N de la prueba (el día 1 es la mañana siguiente al alta).
     *
     * @param array<string> $hechos Lo que el negocio ya hizo.
     *
     * @return array{estado: string, detalle: string|null}
     */
    private function manana_del_dia(int $dia, Client $client, Lead $lead, array $hechos): array
    {
        Carbon::setTestNow($this->alta->copy()->setTimezone(PruebaWhatsappService::ZONA)->addDays($dia)->setTime(10, 0)->setTimezone((string) config('app.timezone')));
        $this->la_instancia_contesta($hechos);

        $client = $client->fresh();
        $this->assertSame($dia, app(PruebaDelClienteService::class)->datos($client)['dia_actual'], 'A las 10:00 de esa mañana va por el día ' . $dia . '.');

        return app(PruebaWhatsappService::class)->empujar($client, $lead);
    }

    /**
     * Las plantillas que salieron, en orden.
     *
     * @param \App\Services\WhatsappSendService $espia
     *
     * @return array<int, string>
     */
    private function secuencia($espia): array
    {
        return array_column($espia->plantillas, 'template_name');
    }

    /** @test */
    public function un_negocio_que_cumple_un_paso_por_dia_llega_a_la_tienda_en_los_siete_dias(): void
    {
        [$client, $lead, $espia] = $this->alta_de_la_prueba();

        /* Entra el mismo día del alta; después hace cada mañana lo que se le pidió el día anterior. */
        $hechos   = ['ingreso'];
        $pedidos  = [];
        $esperado = [
            1 => 'importo', 2 => 'articulo_creado', 3 => 'articulo_actualizado', 4 => 'venta',
            5 => null, 6 => 'agente', 7 => 'tienda_pedido',
        ];

        foreach ($esperado as $dia => $paso) {
            $resultado = $this->manana_del_dia($dia, $client, $lead, $hechos);
            $this->assertSame('enviado', $resultado['estado'], 'Día ' . $dia . ': ' . (string) $resultado['detalle']);

            $ultimo    = PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_WHATSAPP)->orderByDesc('id')->first();
            $pedidos[] = $ultimo->plantilla;
            if ($paso !== null) {
                $this->assertSame($paso, $ultimo->hito, 'Día ' . $dia);
                $hechos[] = $paso;
            }
        }

        $this->assertSame([
            'cc_prueba_bienvenida',
            'cc_prueba_paso_importo',
            'cc_prueba_paso_articulo_creado_v2',
            'cc_prueba_paso_articulo_actualizado',
            'cc_prueba_paso_venta',
            'cc_prueba_dia_5',
            'cc_prueba_paso_agente',
            'cc_prueba_paso_tienda_pedido',
        ], $this->secuencia($espia), 'Un WhatsApp por día, en este orden exacto.');

        /* El día 5 el WhatsApp es solo el aviso; el correo lleva el aviso y, debajo, el paso que le tocaba con su video. */
        $this->assertSame(['Marcela', '3 días'], $espia->plantillas[5]['variables']);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->payload->subject === 'Tu prueba de ComercioCity termina en 3 días'
                && strpos($html, 'Mientras tanto, lo próximo para probar:') !== false
                && strpos($html, 'preguntale a tu Asistente personal') !== false
                && strpos($html, PruebaVideoCatalogoService::URLS_APROBADAS['09']) !== false;
        });
        /* Ese paso no contó como enviado: salió por WhatsApp al día siguiente, una sola vez. */
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_WHATSAPP)->where('hito', 'agente')->count());

        /* Los videos de los correos por paso: 05, ninguno, 06, 08, 09 y ninguno. */
        $correos = PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)->orderBy('id')->pluck('plantilla')->all();
        $this->assertSame([
            'correo_paso_importo', 'correo_paso_articulo_creado', 'correo_paso_articulo_actualizado', 'correo_paso_venta',
            'correo_dia_5', 'correo_paso_agente', 'correo_paso_tienda_pedido',
        ], $correos);

        /* A las 15:00 del día que vence, la encuesta. */
        Carbon::setTestNow($this->alta->copy()->addDays(7)->addMinutes(5));
        $this->assertSame(0, Artisan::call('pruebas:vencer'));
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, $client->fresh()->estado);
        $this->assertSame('cc_prueba_fin_encuesta', $this->secuencia($espia)[8]);
        $this->assertCount(9, $espia->plantillas);
        $this->assertSame(1, PruebaEncuesta::where('client_id', $client->id)->count());
    }

    /** @test */
    public function un_negocio_lento_que_solo_crea_un_articulo_igual_escucha_de_los_agentes_y_de_la_tienda(): void
    {
        [$client, $lead, $espia] = $this->alta_de_la_prueba();

        /* Entra el día del alta, crea un artículo el día 2 y no hace nada más. Ningún paso se le repite. */
        $esperado = [
            1 => 'importo', 2 => 'articulo_creado', 3 => 'articulo_actualizado', 4 => 'venta',
            5 => null, 6 => 'agente', 7 => 'tienda_pedido',
        ];
        foreach ($esperado as $dia => $paso) {
            $hechos    = $dia <= 2 ? ['ingreso'] : ['ingreso', 'articulo_creado'];
            $resultado = $this->manana_del_dia($dia, $client, $lead, $hechos);
            $this->assertSame('enviado', $resultado['estado'], 'Día ' . $dia . ': ' . (string) $resultado['detalle']);
        }

        Carbon::setTestNow($this->alta->copy()->addDays(7)->addMinutes(5));
        Artisan::call('pruebas:vencer');

        $this->assertSame([
            'cc_prueba_bienvenida',
            'cc_prueba_paso_importo',
            'cc_prueba_paso_articulo_creado_v2',
            'cc_prueba_paso_articulo_actualizado',
            'cc_prueba_paso_venta',
            'cc_prueba_dia_5',
            'cc_prueba_paso_agente',
            'cc_prueba_paso_tienda_pedido',
            'cc_prueba_fin_encuesta',
        ], $this->secuencia($espia), 'Sin ningún mensaje repetido, y nombrando los tres sistemas.');

        /* El correo del aviso llevó el paso del agente con su video. */
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return strpos((string) $mail->payload->subject, 'termina en') !== false
                && strpos($html, 'Mientras tanto, lo próximo para probar:') !== false
                && strpos($html, PruebaVideoCatalogoService::URLS_APROBADAS['09']) !== false;
        });
    }

    /** @test */
    public function un_negocio_que_no_entra_nunca_recibe_dos_recordatorios_el_aviso_y_la_encuesta(): void
    {
        [$client, $lead, $espia] = $this->alta_de_la_prueba();

        $estados = [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $dia) {
            $estados[$dia] = $this->manana_del_dia($dia, $client, $lead, [])['estado'];
        }

        $this->assertSame([
            1 => 'enviado', 2 => 'enviado', 3 => 'tope_de_ingreso', 4 => 'tope_de_ingreso',
            5 => 'enviado', 6 => 'tope_de_ingreso', 7 => 'tope_de_ingreso',
        ], $estados);

        Carbon::setTestNow($this->alta->copy()->addDays(7)->addMinutes(5));
        Artisan::call('pruebas:vencer');

        $this->assertSame([
            'cc_prueba_bienvenida',
            'cc_prueba_paso_ingreso',
            'cc_prueba_paso_ingreso',
            'cc_prueba_dia_5',
            'cc_prueba_fin_encuesta',
        ], $this->secuencia($espia));

        /* El correo del aviso va sin paso debajo: no hay ninguno que pedirle. */
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->payload->subject === 'Tu prueba de ComercioCity termina en 3 días' && strpos($html, 'Mientras tanto') === false;
        });
    }

    /** @test */
    public function a_menos_de_tres_horas_de_vencer_no_sale_paso_y_el_resumen_lo_dice(): void
    {
        [$client, $lead, $espia] = $this->alta_de_la_prueba();
        PruebaWhatsappEnvio::create(['client_id' => $client->id, 'plantilla' => PruebaPlantillasService::DIA_5, 'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia' => '2026-11-07', 'enviado_at' => $this->alta->copy()->addDays(5)]);

        /* El día que vence, a las 10:00 (faltan 5 horas): el resumen anuncia el paso y el paso sale. */
        Carbon::setTestNow($this->alta->copy()->addDays(7)->subHours(5));
        $this->la_instancia_contesta(['ingreso']);
        $hoy = $this->getJson('/api/claude/prueba/resumen?consultar_instancia=1&client_id=' . $client->id, $this->headers_de_claude())->assertOk()->json('pruebas.0.hoy');
        $this->assertTrue($hoy['vence_hoy']);
        $this->assertTrue($hoy['encuesta_pendiente']);
        $this->assertSame('importo', $hoy['paso']);
        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client->fresh(), $lead)['estado']);
        $this->assertSame('cc_prueba_paso_importo', $this->secuencia($espia)[1]);

        /* Si a esa hora faltaran menos de tres horas, no. */
        PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'cc_prueba_paso_importo')->delete();
        Carbon::setTestNow($this->alta->copy()->addDays(7)->subHours(2));
        $hoy = $this->getJson('/api/claude/prueba/resumen?consultar_instancia=1&client_id=' . $client->id, $this->headers_de_claude())->assertOk()->json('pruebas.0.hoy');
        $this->assertNull($hoy['paso']);
        $this->assertSame(PruebaWhatsappService::MOTIVO_ULTIMO_DIA, $hoy['motivo_sin_paso']);
        $this->assertSame('ultimo_dia', app(PruebaWhatsappService::class)->empujar($client->fresh(), $lead)['estado']);
        $this->assertCount(2, $espia->plantillas);
    }

    /** @test */
    public function el_dia_del_aviso_el_resumen_dice_que_paso_va_en_el_correo(): void
    {
        [$client, $lead] = $this->alta_de_la_prueba();

        Carbon::setTestNow($this->alta->copy()->setTimezone(PruebaWhatsappService::ZONA)->addDays(5)->setTime(10, 0)->setTimezone((string) config('app.timezone')));
        $this->la_instancia_contesta(['ingreso', 'importo', 'articulo_creado', 'articulo_actualizado', 'venta']);

        $hoy = $this->getJson('/api/claude/prueba/resumen?consultar_instancia=1&client_id=' . $client->id, $this->headers_de_claude())->assertOk()->json('pruebas.0.hoy');

        $this->assertTrue($hoy['aviso_dia_5']);
        $this->assertNull($hoy['paso'], 'Por WhatsApp hoy sale solo el aviso.');
        $this->assertSame('agente', $hoy['paso_en_el_correo']);
        $this->assertSame('09', $hoy['video_codigo']);
        $this->assertSame(PruebaPlantillasService::DIA_5, $hoy['plantilla']);
    }
}
