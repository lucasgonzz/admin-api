<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\Admin;
use App\Services\LeadAiService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaWhatsappService;
use Tests\Feature\PruebaGratuita\BaseDeLaPrueba;

/**
 * CA34 de la etapa E3 (misión embudo-juan-pipeline, P4): el closer comercial pasa a ser Juan.
 *
 *  - `LeadAiService::NOMBRE_DEL_CLOSER` es «Juan».
 *  - El aviso del día 5 de la prueba va al teléfono de un admin del perfil «Sólo Leads» (o vendedor) con teléfono, y NO al `is_closer`;
 *    sin ninguno con teléfono, va al `is_closer` como hasta ahora.
 * Los identificadores (`aviso_tomas`, `TOMAS_*`, las rutas `avisar-a-tomas`) no cambian: son contrato con el SPA y con lo guardado.
 *
 * Hoy están EN ROJO: la constante es «Thomás» y el aviso busca siempre al closer.
 */
class JuanEnLugarDeTomasTest extends BaseDeLaPrueba
{
    /**
     * @return void
     */
    public function test_el_closer_se_llama_juan(): void
    {
        $this->assertSame('Juan', LeadAiService::NOMBRE_DEL_CLOSER);
    }

    /**
     * El aviso del día 5 con un negocio en su día 5.
     *
     * @return array<string, mixed> `avisar_a_tomas_si_es_el_dia_5()`.
     */
    private function avisar_el_dia_5(): array
    {
        $client = $this->cliente_en_prueba(2, 7);
        $lead   = $this->lead_en_prueba($client);
        $datos  = app(PruebaDelClienteService::class)->datos($client->fresh());

        return app(PruebaWhatsappService::class)->avisar_a_tomas_si_es_el_dia_5($client->fresh(), $lead, $datos);
    }

    /**
     * CA34. Con un admin `solo_leads` con teléfono, el aviso va a él y no al closer.
     *
     * @return void
     */
    public function test_el_aviso_del_dia_5_va_al_telefono_de_juan(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        Admin::query()->update(['solo_leads' => false, 'es_vendedor' => false, 'is_closer' => false]);
        $this->admin(true);
        $juan = Admin::create(['name' => 'Juan', 'email' => 'juan-textos@test.local', 'password' => bcrypt('secret'), 'solo_leads' => true, 'phone_number' => '+5493416660000']);

        $resultado = $this->avisar_el_dia_5();

        $this->assertSame('avisado', $resultado['estado']);
        $this->assertCount(1, $espia->textos);
        $this->assertSame('+5493416660000', $espia->textos[0]['to'], 'Va al teléfono de Juan, no al del closer (+5493415550000).');
        $this->assertSame((int) $juan->id, (int) Admin::query()->where('phone_number', '+5493416660000')->value('id'));
    }

    /**
     * CA34. Sin un admin de ventas con teléfono, el aviso va al closer como hoy.
     *
     * @return void
     */
    public function test_sin_juan_con_telefono_va_al_closer(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        Admin::query()->update(['solo_leads' => false, 'es_vendedor' => false, 'is_closer' => false]);
        $this->admin(true);
        Admin::create(['name' => 'Juan', 'email' => 'juan-sin-tel@test.local', 'password' => bcrypt('secret'), 'solo_leads' => true]);

        $resultado = $this->avisar_el_dia_5();

        $this->assertSame('avisado', $resultado['estado']);
        $this->assertSame('+5493415550000', $espia->textos[0]['to'], 'Al closer, como hasta ahora.');
    }
}
