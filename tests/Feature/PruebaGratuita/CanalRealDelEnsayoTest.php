<?php

namespace Tests\Feature\PruebaGratuita;

use App\Console\Commands\PruebasEnsayoCommand;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\Prueba\PruebaVideoSoporteService;
use App\Services\PruebaPlantillasService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El canal real de un cliente de ENSAYO (12/11/2026):
 *
 *  - C1. `pruebas:ensayo` rotula el canal por donde salió el mensaje (`Soporte del sistema`), no «WhatsApp».
 *  - C2. Ningún envío del ciclo de un ensayo sale por WhatsApp: la bienvenida del primer ingreso, el video 04, los
 *    pasos, el aviso del día 5, el vencimiento y la encuesta quedan en la conversación del sistema (más el correo).
 */
class CanalRealDelEnsayoTest extends BaseDeLaPrueba
{
    /** @var \App\Services\WhatsappSendService */
    private $espia;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'ensayo');
        Mail::fake();
        $this->espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->admin(true);
        $this->plantillas_de_la_prueba(true);
        foreach (PruebaVideoCatalogoService::URLS_APROBADAS as $codigo => $url) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), $url);
        }
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);
    }

    /**
     * Un negocio de ensayo recién dado de alta (día 1 de 7), sin ningún envío todavía.
     *
     * @return array{0: Client, 1: Lead}
     */
    private function ensayo(): array
    {
        $client = $this->cliente_en_prueba(7, 7);
        $client->email     = 'ensayo-' . $client->id . '@test.local';
        $client->es_ensayo = true;
        $client->save();

        return [$client, $this->lead_en_prueba($client)];
    }

    /** @test */
    public function la_tabla_del_ensayo_rotula_la_encuesta_por_el_sistema_y_no_por_whatsapp(): void
    {
        [$client] = $this->ensayo();

        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '8', '--correr' => 'vencer']));
        $salida = Artisan::output();

        $this->assertStringContainsString('Soporte del sistema: enviado · correo: enviado', $salida, $salida);
        $this->assertStringNotContainsString('WhatsApp: enviado', $salida);
        $this->assertSame([], $this->espia->plantillas);
    }

    /** @test */
    public function el_rotulo_lee_el_canal_registrado_y_a_un_cliente_comun_lo_sigue_llamando_whatsapp(): void
    {
        [$client] = $this->ensayo();
        $comun = $this->cliente_en_prueba(5, 7, '+5493415550099');

        $this->assertSame('Soporte del sistema', PruebasEnsayoCommand::rotulo_del_canal($client, PruebaPlantillasService::ENCUESTAS), 'Sin envío: por donde saldría.');
        $this->assertSame('WhatsApp', PruebasEnsayoCommand::rotulo_del_canal($comun, PruebaPlantillasService::ENCUESTAS));

        /* El registro manda: un correo no cuenta, el último envío de mensaje sí. */
        foreach ([[$comun, PruebaWhatsappEnvio::CANAL_WHATSAPP], [$comun, PruebaWhatsappEnvio::CANAL_CORREO]] as $fila) {
            PruebaWhatsappEnvio::create([
                'client_id' => $fila[0]->id, 'canal' => $fila[1], 'plantilla' => PruebaPlantillasService::FIN_ENCUESTA,
                'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => now()->toDateString(), 'enviado_at' => now(),
            ]);
        }
        $this->assertSame('WhatsApp', PruebasEnsayoCommand::rotulo_del_canal($comun, PruebaPlantillasService::ENCUESTAS));
    }

    /** @test */
    public function ningun_envio_del_ciclo_de_un_ensayo_sale_por_whatsapp_desde_el_primer_ingreso_hasta_la_encuesta(): void
    {
        [$client, $lead] = $this->ensayo();

        /* El primer ingreso dispara la bienvenida (el camino del alta) y, detrás, el video 04. */
        $this->evento($lead, 'prueba.ingreso')->assertStatus(200);
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->whereIn('plantilla', PruebaPlantillasService::BIENVENIDAS)
            ->where('canal', PruebaWhatsappEnvio::CANAL_SISTEMA)->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->count(), 'La bienvenida, por el sistema.');
        $this->assertSame(PruebaWhatsappEnvio::CANAL_SISTEMA, PruebaWhatsappEnvio::where('client_id', $client->id)
            ->where('plantilla', PruebaVideoSoporteService::plantilla(PruebaVideoSoporteService::CODIGO_BIENVENIDA))->value('canal'), 'El video 04, por el sistema.');

        /* Los días del ciclo: pasos, aviso del día 5 (con el closer pedido: a él sí, al negocio no) y el vencimiento. */
        foreach (['2' => 'empujar', '3' => 'empujar', '5' => 'empujar', '8' => 'vencer'] as $dia => $correr) {
            $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => (string) $dia, '--correr' => $correr]), Artisan::output());
        }
        $this->evento($lead, 'prueba.vencida');

        $this->assertSame([], $this->espia->plantillas, 'Ninguna plantilla de WhatsApp en todo el ciclo.');
        $this->assertSame([], $this->espia->textos, 'Ningún texto de WhatsApp en todo el ciclo (tampoco al closer).');
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->count(), 'No se abre ticket de WhatsApp.');

        $canales = PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', '!=', PruebaWhatsappEnvio::CANAL_CORREO)
            ->pluck('canal')->unique()->values()->all();
        $this->assertSame([PruebaWhatsappEnvio::CANAL_SISTEMA], $canales);
        $this->assertTrue(PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', PruebaPlantillasService::DIA_5)->exists(), 'El ciclo llegó al día 5.');
        $this->assertTrue(PruebaWhatsappEnvio::where('client_id', $client->id)->whereIn('plantilla', PruebaPlantillasService::ENCUESTAS)->exists(), 'Y a la encuesta.');
    }
}
