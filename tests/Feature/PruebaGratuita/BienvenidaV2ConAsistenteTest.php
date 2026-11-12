<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\FollowupTemplate;
use App\Models\SupportMessage;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaAsistenteService;
use App\Services\Prueba\PruebaCorreoService;
use App\Services\Prueba\PruebaSettings;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * La bienvenida v2 que además presenta el WhatsApp del asistente personal (misión cruzada
 * prueba-e2e-campana-imperio, 11/11/2026): lleva TRES variables, y su orden es el que se crea en Meta:
 * `{{1}}` el nombre, `{{2}}` la dirección del sistema y `{{3}}` el número del asistente.
 */
class BienvenidaV2ConAsistenteTest extends BaseDeLaPrueba
{
    private const NUMERO = '+1 201-586-6682';

    /** @test */
    public function si_meta_no_aprobo_la_v2_con_asistente_sale_la_v2_comun_y_el_numero_llega_despues(): void
    {
        $espia = $this->espiar_sender();
        Mail::fake();
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        $this->crear_config_whatsapp();
        $this->crear_config_asistente(['display_phone' => self::NUMERO]);
        $this->admin();
        foreach (PruebaPlantillasService::todas() as $nombre => $definicion) {
            $aprobada = ! in_array($nombre, [PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2, PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V3], true);
            FollowupTemplate::create([
                'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
                'body_template' => $definicion['body'], 'language_code' => 'es_AR', 'activa' => $aprobada,
                'meta_status' => $aprobada ? 'APPROVED' : 'PENDING', 'meta_category' => 'UTILITY', 'uso' => $definicion['uso'],
                'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
            ]);
        }

        $client = $this->cliente_en_prueba();
        $client->email = 'alta@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);
        $lead->demo_setup_status = 'exitoso';
        $lead->save();
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'lead_id' => (int) $lead->id, 'user_id' => (int) $client->user_id,
            'alias_slug' => 'ferreteria-respaldo',
        ]);
        $this->fakear_http(['*/' . PruebaAsistenteService::RUTA_DISPONIBILIDAD => Http::response(['disponible' => true, 'user_id' => (int) $client->user_id], 200)]);
        $plantillas = app(PruebaPlantillasService::class);
        $servicio   = app(PruebaWhatsappService::class);

        /* (2) Con la v2 con asistente PENDING: la v2 común, que explica los agentes y dice cómo entrar. */
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, $plantillas->bienvenida_vigente(true, $client));
        $this->assertSame('enviado', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $url = PruebaCorreoService::url_del_sistema($client);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela', $url], $espia->plantillas[0]['variables']);

        /* El número del asistente llega por el mensaje que ya existe para eso, una sola vez. */
        $servicio->retomar_bienvenidas((int) $client->id);
        $servicio->retomar_bienvenidas((int) $client->id);
        $this->assertCount(2, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::ASISTENTE_LISTO, $espia->plantillas[1]['template_name']);
        $this->assertSame(['Marcela', self::NUMERO], $espia->plantillas[1]['variables']);

        /* La variante corta, si Meta la aprueba, va antes que la v2 común; y la v2 con asistente, antes que todas. */
        FollowupTemplate::where('template_name', PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V3)->update(['activa' => true, 'meta_status' => 'APPROVED']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V3, $plantillas->bienvenida_vigente(true, $client));
        FollowupTemplate::where('template_name', PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2)->update(['activa' => true, 'meta_status' => 'APPROVED']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2, $plantillas->bienvenida_vigente(true, $client));

        /* (3) Sin ninguna v2 aprobada, la de siempre. */
        FollowupTemplate::where('template_name', 'like', 'cc_prueba_bienvenida%v_')->update(['activa' => false, 'meta_status' => 'PENDING']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE, $plantillas->bienvenida_vigente(true, $client));

        /* Fuera del alcance, la de siempre aunque las v2 estén aprobadas. */
        FollowupTemplate::where('template_name', 'like', 'cc_prueba_bienvenida%')->update(['activa' => true, 'meta_status' => 'APPROVED']);
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'ensayo');
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE, $plantillas->bienvenida_vigente(true, $client));
    }

    /** @test */
    public function manda_el_nombre_la_direccion_del_sistema_y_el_numero_del_asistente_en_ese_orden(): void
    {
        $espia = $this->espiar_sender();
        Mail::fake();
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        $this->crear_config_whatsapp();
        $this->crear_config_asistente(['display_phone' => self::NUMERO]);
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
        $client->email = 'alta@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);
        $lead->demo_setup_status = 'exitoso';
        $lead->save();
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'lead_id' => (int) $lead->id, 'user_id' => (int) $client->user_id,
            'alias_slug' => 'ferreteria-asistente',
        ]);
        $this->fakear_http(['*/' . PruebaAsistenteService::RUTA_DISPONIBILIDAD => Http::response(['disponible' => true, 'user_id' => (int) $client->user_id], 200)]);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('enviado', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame('ya_enviada', $servicio->enviar_bienvenida($lead, $client)['estado']);

        $url = PruebaCorreoService::url_del_sistema($client);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela', $url, self::NUMERO], $espia->plantillas[0]['variables']);

        $mensaje = SupportMessage::where('whatsapp_message_id', 'wamid.plantilla.1')->firstOrFail();
        $this->assertStringContainsString('Entrás desde ' . $url . ' con tu correo y la contraseña que elegiste.', $mensaje->body);
        $this->assertStringContainsString('también te atiende por WhatsApp en el ' . self::NUMERO . '.', $mensaje->body);
        $this->assertStringNotContainsString('{{', $mensaje->body);

        /* Y el orden de las variables en el texto que se crea en Meta es ascendente. */
        $corta = PruebaPlantillasService::cuerpo_de(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V3);
        $this->assertTrue(strpos($corta, '{{1}}') < strpos($corta, '{{2}}') && strpos($corta, '{{2}}') < strpos($corta, '{{3}}'));
        $this->assertLessThan(500, mb_strlen($corta));
        $cuerpo = PruebaPlantillasService::cuerpo_de(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2);
        $this->assertTrue(strpos($cuerpo, '{{1}}') < strpos($cuerpo, '{{2}}') && strpos($cuerpo, '{{2}}') < strpos($cuerpo, '{{3}}'));
    }
}
