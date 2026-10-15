<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🔴 Misión demo-solo-experiencia-nueva (14/10/2026): la landing pública vieja `/demo/{uuid}`
 * mostraba el link del sistema, el usuario y la contraseña de la demo a cualquiera que tuviera
 * el uuid, sin pasar por la página de experiencia ni por su formulario. Es una puerta que se
 * salteaba el candado, así que ahora solo redirige a la página de experiencia del lead.
 *
 * Los links viejos (con uuid) ya viajaron por mail y WhatsApp: tienen que seguir resolviendo.
 */
class DemoLandingViejaRedirigeALaExperienciaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea un lead con demo y documento cargados: el caso en que la landing vieja entregaba
     * credenciales reales.
     *
     * @return Lead
     */
    private function lead_con_acceso_cargado(): Lead
    {
        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead con acceso';
        $lead->status       = 'demo_agendada';
        $lead->doc_number   = '30111222';
        $lead->demo_id      = 1;
        $lead->save();

        return $lead;
    }

    /**
     * El link viejo con uuid redirige a /experiencia/<clave> y NO muestra la landing ni las
     * credenciales.
     *
     * @return void
     */
    public function test_el_link_viejo_redirige_a_la_pagina_de_experiencia_sin_mostrar_credenciales()
    {
        config(['services.admin_spa.url' => 'https://admin.example.test']);
        $lead = $this->lead_con_acceso_cargado();

        $respuesta = $this->get('/demo/' . $lead->uuid);

        $respuesta->assertRedirect($lead->fresh()->demo_experiencia_url);
        $this->assertStringContainsString('/experiencia/', $respuesta->headers->get('Location'));
        $this->assertStringNotContainsString('30111222', (string) $respuesta->getContent());
    }

    /**
     * Sin ADMIN_SPA_URL no hay URL de experiencia armable: se prefiere un 404 antes que mostrar
     * credenciales.
     *
     * @return void
     */
    public function test_sin_url_de_la_spa_responde_404_y_no_muestra_credenciales()
    {
        config(['services.admin_spa.url' => '']);
        $lead = $this->lead_con_acceso_cargado();

        $respuesta = $this->get('/demo/' . $lead->uuid);

        $respuesta->assertStatus(404);
        $this->assertStringNotContainsString('30111222', (string) $respuesta->getContent());
    }

    /**
     * Un uuid que no existe sigue siendo 404.
     *
     * @return void
     */
    public function test_un_uuid_inexistente_responde_404()
    {
        $this->get('/demo/' . Str::uuid())->assertStatus(404);
    }
}
