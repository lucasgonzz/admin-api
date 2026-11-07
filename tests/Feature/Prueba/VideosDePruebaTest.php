<?php

namespace Tests\Feature\Prueba;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaDemoSetupService;
use App\Services\Prueba\PruebaSettings;

class VideosDePruebaTest extends BaseDePruebaGratuita
{
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::whereIn('key', [
            PruebaSettings::KEY_VIDEO_ESPERA_URL,
            PruebaSettings::KEY_VIDEO_BIENVENIDA_URL,
        ])->delete();
    }

    /** @dataProvider configuraciones_de_video */
    public function test_cada_destino_vuelve_al_video_anterior_sin_depender_del_otro($espera, $bienvenida, string $esperada, string $bienvenida_esperada): void
    {
        $this->guardar_si_existe(PruebaSettings::KEY_VIDEO_ESPERA_URL, $espera);
        $this->guardar_si_existe(PruebaSettings::KEY_VIDEO_BIENVENIDA_URL, $bienvenida);

        $this->assertSame($esperada, PruebaSettings::video_espera_url());
        $this->assertSame($bienvenida_esperada, PruebaSettings::video_bienvenida_url());
        $this->assertSame('https://videos.test/bienvenida.mp4', PruebaSettings::video_url());
    }

    public function configuraciones_de_video(): array
    {
        $anterior = 'https://videos.test/bienvenida.mp4';
        $historia = 'https://videos.test/historia.mp4';
        $corta = 'https://videos.test/bienvenida-corta.mp4';

        return [
            'sin claves nuevas' => [false, false, $anterior, $anterior],
            'vacías' => ['', '', $anterior, $anterior],
            'nulas normalizadas por el setter' => [null, null, $anterior, $anterior],
            'solo espacios' => ['   ', "\t\n", $anterior, $anterior],
            'solo espera' => [$historia, false, $historia, $anterior],
            'solo bienvenida' => [false, $corta, $anterior, $corta],
            'ambas con espacios exteriores' => ['  ' . $historia . ' ', "\t" . $corta . "\n", $historia, $corta],
        ];
    }

    public function test_sin_ningun_video_los_dos_destinos_conservan_el_string_vacio(): void
    {
        AdminSetting::where('key', PruebaSettings::KEY_VIDEO_URL)->delete();

        $this->assertSame('', PruebaSettings::video_espera_url());
        $this->assertSame('', PruebaSettings::video_bienvenida_url());
        $this->assertSame('', PruebaSettings::video_url());
    }

    public function test_el_estado_publico_y_el_setup_envian_sus_videos_sin_cambiar_el_contrato(): void
    {
        AdminSetting::set(PruebaSettings::KEY_VIDEO_ESPERA_URL, 'https://videos.test/historia.mp4');
        AdminSetting::set(PruebaSettings::KEY_VIDEO_BIENVENIDA_URL, 'https://videos.test/bienvenida-corta.mp4');
        $instancia = $this->crear_instancia_libre();
        $alta = $this->postear_alta()->assertCreated();
        $instancia->refresh();
        $lead = Lead::findOrFail($instancia->lead_id);
        $client = Client::findOrFail($instancia->client_id);
        $url_estado = '/api/landing/prueba/' . $alta->json('uuid') . '/estado';

        $this->getJson($url_estado)->assertOk()->assertJson([
            'estado' => 'creando', 'video_url' => 'https://videos.test/historia.mp4',
        ])->assertJsonPath('url_ingreso', null);

        $resultado = app(PruebaDemoSetupService::class)->disparar($instancia, $lead, $client, 'clave-sintetica');
        $this->assertSame(PruebaDemoSetupService::RESULTADO_OK, $resultado['resultado']);
        $pedidos = $this->pedidos_al_erp_con('admin-sync/demo-setup');
        $this->assertCount(1, $pedidos);
        $this->assertSame('POST', $pedidos[0]['metodo']);
        $this->assertSame([$client->api_key], $pedidos[0]['headers']['X-Admin-Api-Key']);
        $this->assertSame('https://videos.test/bienvenida-corta.mp4', $pedidos[0]['body']['prueba']['video_url']);
        $this->assertSame(['dias', 'vence_at', 'video_url', 'whatsapp_soporte'], array_keys($pedidos[0]['body']['prueba']));

        // Cuando termina el armado, la landing no cambia el video que el negocio estaba mirando.
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista']);
        $this->getJson($url_estado)->assertOk()->assertJson([
            'estado' => 'lista', 'video_url' => 'https://videos.test/historia.mp4',
            'url_ingreso' => 'https://prueba-test.comerciocity.com/demo/ingreso?t=' . $lead->demo_ingreso_token,
        ]);

        // C6 lee la configuración en cada consulta, sin tocar instancias ya creadas ni repetir C1.
        AdminSetting::set(PruebaSettings::KEY_VIDEO_ESPERA_URL, 'https://videos.test/historia-nueva.mp4');
        $this->getJson($url_estado)->assertOk()->assertJsonPath('video_url', 'https://videos.test/historia-nueva.mp4');
        $this->assertCount(1, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
    }

    private function guardar_si_existe(string $key, $value): void
    {
        if ($value !== false) {
            AdminSetting::set($key, $value);
        }
    }
}
