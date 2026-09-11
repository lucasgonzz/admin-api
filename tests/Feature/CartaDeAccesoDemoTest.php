<?php

namespace Tests\Feature;

use App\Mail\Helpers\LeadDemoAccesoMailHelper;
use App\Mail\LeadDemoAccesoMail;
use App\Mail\LeadDemoMail;
use App\Models\Demo;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La "carta de acceso" a la demo (misión demo-agendado-directo): el mail con las dos llaves que
 * el lead se lleva a la computadora cuando acepta la demo por WhatsApp.
 *
 * Lo que estos tests protegen:
 *
 *  1. Que las dos llaves sean las del lead y no otras: la página de experiencia sale de
 *     `Lead::demo_experiencia_url` y la tienda de `demos.ecommerce_spa_url` de SU demo. Un mail
 *     lindo con el link de otra instancia es peor que ningún mail.
 *  2. Que sin tienda no aparezca un botón a ninguna parte: la llave 2 se oculta entera.
 *  3. Que `LeadDemoAccesoMailHelper::build()` arme un `LeadDemoAccesoMail` y no el `LeadDemoMail`
 *     viejo (el de credenciales y videos, que esta dinámica no usa).
 */
class CartaDeAccesoDemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Demo de prueba con (o sin) tienda online.
     *
     * `ecommerce_spa_url` no es nullable en la tabla, así que "sin tienda" es cadena vacía: es
     * exactamente lo que queda guardado cuando una instancia no tiene ecommerce.
     *
     * @param string $ecommerce_spa_url URL de la tienda, o '' para una instancia sin tienda.
     *
     * @return Demo
     */
    private function crear_demo(string $ecommerce_spa_url): Demo
    {
        $demo                    = new Demo();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = $ecommerce_spa_url;
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead de prueba con su demo asignada. La clave de la URL de experiencia la resuelve el propio
     * accessor del modelo (hoy los dígitos del teléfono, con el uuid de respaldo): el test compara
     * contra lo que devuelve `demo_experiencia_url`, no contra un valor armado a mano.
     *
     * @param Demo        $demo         Demo asignada al lead.
     * @param string|null $contact_name Nombre completo del contacto.
     *
     * @return Lead
     */
    private function crear_lead(Demo $demo, ?string $contact_name = 'Guillermo González'): Lead
    {
        $lead               = new Lead();
        $lead->phone        = '+5493417778899';
        $lead->email        = 'guillermo@ejemplo.test';
        $lead->contact_name = $contact_name;
        $lead->status       = 'demo_agendada';
        $lead->demo_id      = $demo->id;
        $lead->save();

        return $lead;
    }

    /**
     * (a) El mail renderizado lleva el primer nombre, la URL de experiencia del lead y la URL de
     * la tienda de su demo; el asunto lleva el nombre.
     *
     * @return void
     */
    public function test_el_mail_lleva_el_nombre_y_las_dos_llaves_del_lead()
    {
        $demo = $this->crear_demo('https://demo-tienda.test');
        $lead = $this->crear_lead($demo);

        $mail = LeadDemoAccesoMailHelper::build($lead);
        $html = $mail->render();

        // Primer nombre, no el completo con apellido.
        $this->assertStringContainsString('Hola Guillermo.', $html);
        $this->assertStringNotContainsString('González', $html);

        // Llave 1: la página de experiencia de ESTE lead.
        $url_experiencia = (string) $lead->demo_experiencia_url;
        $this->assertNotSame('', $url_experiencia, 'El lead de prueba tenía que tener URL de experiencia (teléfono o uuid + services.admin_spa.url).');
        $this->assertStringContainsString('href="' . $url_experiencia . '"', $html);
        $this->assertStringContainsString('Entrar a la demo', $html);

        // Llave 2: la tienda de SU demo.
        $this->assertStringContainsString('href="https://demo-tienda.test"', $html);
        $this->assertStringContainsString('Ver la tienda', $html);

        // El asunto, ya resuelto por build() dentro de render().
        $this->assertStringContainsString('Guillermo', (string) $mail->subject);
        $this->assertStringNotContainsString('González', (string) $mail->subject);
    }

    /**
     * (b) Instancia sin tienda: la llave 2 no se muestra, ni el botón ni su título.
     *
     * @return void
     */
    public function test_sin_tienda_no_aparece_el_boton_de_la_tienda()
    {
        $demo = $this->crear_demo('');
        $lead = $this->crear_lead($demo);

        $html = LeadDemoAccesoMailHelper::build($lead)->render();

        $this->assertStringNotContainsString('Ver la tienda', $html);
        $this->assertStringNotContainsString('Tienda online', $html);

        // La llave 1 sigue estando: sin tienda igual hay demo.
        $this->assertStringContainsString('Entrar a la demo', $html);
        $this->assertStringContainsString('href="' . $lead->demo_experiencia_url . '"', $html);
    }

    /**
     * (c) Mandar lo que arma el helper despacha exactamente un `LeadDemoAccesoMail` al lead, y
     * ningún `LeadDemoMail` (el mail viejo de credenciales).
     *
     * @return void
     */
    public function test_el_helper_manda_exactamente_una_carta_de_acceso()
    {
        Mail::fake();

        $demo = $this->crear_demo('https://demo-tienda.test');
        $lead = $this->crear_lead($demo);

        Mail::to($lead->email)->send(LeadDemoAccesoMailHelper::build($lead));

        Mail::assertSent(LeadDemoAccesoMail::class, 1);
        Mail::assertSent(LeadDemoAccesoMail::class, function (LeadDemoAccesoMail $mail) use ($lead) {
            return $mail->hasTo($lead->email);
        });
        Mail::assertNotSent(LeadDemoMail::class);
    }

    /**
     * Sin nombre usable el saludo es "Hola." a secas y el asunto no queda con una coma colgando:
     * acá no se inventa un "Cliente".
     *
     * @return void
     */
    public function test_sin_nombre_saluda_a_secas_y_el_asunto_no_queda_cortado()
    {
        $demo = $this->crear_demo('https://demo-tienda.test');
        $lead = $this->crear_lead($demo, null);

        $mail = LeadDemoAccesoMailHelper::build($lead);
        $html = $mail->render();

        $this->assertStringContainsString('Hola.', $html);
        $this->assertSame('Tus llaves de acceso a ComercioCity', (string) $mail->subject);
    }
}
