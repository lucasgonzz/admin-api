<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El link corto de la página de experiencia (misión demo-agendado-directo, 10/9/2026): la clave de
 * la URL son los dígitos del teléfono del lead, y los links con uuid ya enviados siguen resolviendo.
 */
class ExperienciaPorTelefonoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * (1) El accessor arma el link con los dígitos del teléfono (sin `+`), y sin teléfono cae al uuid.
     *
     * @return void
     */
    public function test_el_link_lleva_los_digitos_del_telefono_y_sin_telefono_el_uuid(): void
    {
        config(['services.admin_spa.url' => 'https://admin.test/']);

        $con_telefono = $this->crear_lead('Con teléfono', '+54 9 351 123-4567');
        $this->assertSame('https://admin.test/experiencia/5493511234567', $con_telefono->demo_experiencia_url);

        $sin_telefono = $this->crear_lead('Sin teléfono', null);
        $this->assertSame('https://admin.test/experiencia/' . $sin_telefono->uuid, $sin_telefono->demo_experiencia_url);
    }

    /**
     * (2) La página pública resuelve por teléfono (con y sin prefijo, con formato) y por uuid.
     *
     * @return void
     */
    public function test_la_pagina_resuelve_por_telefono_y_por_uuid(): void
    {
        $lead = $this->crear_lead('Guillermo González', '5493511234567');

        $this->getJson('/api/demo-experiencia/5493511234567')
            ->assertStatus(200)
            ->assertJsonPath('lead.contact_name', 'Guillermo González');

        /* Sin el 9 de celular y con formato local: WhatsappNormalizer tolera las dos cosas. */
        $this->getJson('/api/demo-experiencia/3511234567')
            ->assertStatus(200)
            ->assertJsonPath('lead.contact_name', 'Guillermo González');

        $this->getJson('/api/demo-experiencia/' . $lead->uuid)
            ->assertStatus(200)
            ->assertJsonPath('lead.contact_name', 'Guillermo González');

        $this->getJson('/api/demo-experiencia/5493510000000')->assertStatus(404);
        $this->getJson('/api/demo-experiencia/12')->assertStatus(404);
    }

    /**
     * (3) Dos leads con el mismo teléfono (re-ingreso): gana el más reciente, igual que el webhook
     *     de WhatsApp al enrutar un mensaje entrante.
     *
     * @return void
     */
    public function test_con_dos_leads_del_mismo_telefono_gana_el_mas_reciente(): void
    {
        $this->crear_lead('El viejo', '5493511234567');
        $nuevo = $this->crear_lead('El nuevo', '+5493511234567');

        $this->getJson('/api/demo-experiencia/5493511234567')
            ->assertStatus(200)
            ->assertJsonPath('lead.contact_name', 'El nuevo');

        $this->assertSame($nuevo->id, Lead::resolver_por_clave_de_experiencia('5493511234567')->id);
    }

    /**
     * (4) Un teléfono guardado con espacios y guiones también resuelve (la normalización va en SQL).
     *
     * @return void
     */
    public function test_un_telefono_guardado_con_formato_tambien_resuelve(): void
    {
        $lead = $this->crear_lead('Con formato', '+54 9 351 123-4567');

        $this->assertSame($lead->id, Lead::resolver_por_clave_de_experiencia('5493511234567')->id);
    }

    /**
     * (5) 🔴 Dos teléfonos de áreas distintas que comparten los últimos ocho dígitos NO se
     *     confunden: la comparación es por igualdad del número normalizado, no por sufijo (con la
     *     tolerancia del webhook, el link de Córdoba abría la demo de Mendoza).
     *
     * @return void
     */
    public function test_un_sufijo_compartido_no_resuelve_al_lead_equivocado(): void
    {
        $cordoba = $this->crear_lead('Córdoba', '+5493515551234');
        $mendoza = $this->crear_lead('Mendoza', '+5492615551234');

        $this->assertSame($cordoba->id, Lead::resolver_por_clave_de_experiencia('5493515551234')->id);
        $this->assertSame($mendoza->id, Lead::resolver_por_clave_de_experiencia('5492615551234')->id);
        $this->assertNull(Lead::resolver_por_clave_de_experiencia('5491155551234'));
    }

    /**
     * @param string      $nombre
     * @param string|null $telefono
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, ?string $telefono): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = $nombre;
        $lead->company_name = 'Empresa de prueba';
        $lead->phone        = $telefono;
        $lead->status       = 'calificado';
        $lead->save();

        return $lead->refresh();
    }
}
