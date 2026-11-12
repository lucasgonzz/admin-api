<?php

namespace Tests\Feature\Prueba;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaContextoSoporteService;
use App\Services\Prueba\PruebaSettings;
use App\Services\SupportClientContextService;

/**
 * Las altas de ensayo (misión cruzada prueba-e2e-campana-imperio, 12/11/2026): un alta cuyo correo está en
 * `prueba_ensayo_correos` nace con `clients.es_ensayo`, antes de que se arme la instancia y salga la bienvenida. Y dos
 * datos del contexto de Martín: el WhatsApp de soporte y la versión real de la instancia.
 */
class AltaDeEnsayoTest extends BaseDePruebaGratuita
{
    public function test_un_alta_con_un_correo_de_la_lista_nace_marcada_como_ensayo(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $correo = (string) $this->cuerpo()['email'];
        AdminSetting::set(PruebaSettings::KEY_ENSAYO_CORREOS, ' otro@test.local ,  ' . strtoupper($correo) . ' ');

        $lead   = $this->lead_del_alta($this->postear_alta()->assertStatus(201)->json('uuid'));
        $client = Client::findOrFail($lead->promoted_client_id);

        $this->assertTrue((bool) $client->es_ensayo, 'Nace marcada: se compara sin espacios y sin mayúsculas.');
        $this->assertTrue(PruebaSettings::novedades_para($client), 'Su bienvenida ya sale con las reglas del alcance nuevo.');
    }

    public function test_con_la_lista_vacia_o_con_otro_correo_el_alta_nace_como_siempre(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->assertFalse(PruebaSettings::es_correo_de_ensayo((string) $this->cuerpo()['email']));
        $this->assertFalse(PruebaSettings::es_correo_de_ensayo(''));

        AdminSetting::set(PruebaSettings::KEY_ENSAYO_CORREOS, 'otro@test.local');
        $lead   = $this->lead_del_alta($this->postear_alta()->assertStatus(201)->json('uuid'));
        $client = Client::findOrFail($lead->promoted_client_id);

        $this->assertFalse((bool) $client->es_ensayo);
        $this->assertFalse(PruebaSettings::novedades_para($client));
    }

    public function test_el_contexto_de_martin_trae_el_whatsapp_de_soporte_y_la_version_real_de_la_instancia(): void
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        AdminSetting::set(PruebaSettings::KEY_WHATSAPP_SOPORTE, '+54 9 341 555-0000');
        $lead   = $this->lead_del_alta($this->postear_alta()->assertStatus(201)->json('uuid'));
        $client = Client::findOrFail($lead->promoted_client_id);
        /* La instancia quedó en una versión anterior a la última publicada que figura en la ficha del cliente. */
        $instancia->refresh();
        $instancia->version = '4.3.50';
        $instancia->save();

        $prueba = app(PruebaContextoSoporteService::class)->bloque_para_el_cliente($client);
        $this->assertSame('+54 9 341 555-0000', $prueba['whatsapp_soporte']);
        $this->assertSame('4.3.50', $prueba['version_instancia']);

        $this->assertSame('4.3.50', app(SupportClientContextService::class)->datos_calculados($client->id)['version'],
            'El contexto del cliente dice la versión que corre su instancia, no la última publicada.');
    }
}
