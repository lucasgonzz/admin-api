<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\Admin;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaWhatsappService;
use Tests\Feature\PruebaGratuita\BaseDeLaPrueba;

/**
 * Enmienda 4 de la ronda 2 de E3: a quién va el aviso del día 5. Primero los admins `solo_leads` con teléfono; si no hay, un
 * `es_vendedor` que NO sea `is_closer`; si no hay, el closer como hoy. El caso que lo rompía: el closer marcado como vendedor
 * (`VentasMigrarLicenciasCommand` marca `es_vendedor` a los `is_closer`) y con un id menor que el de Juan.
 */
class DestinatarioDelAvisoDeJuanTest extends BaseDeLaPrueba
{
    /**
     * Prepara el espía y el closer: marcado también como vendedor y creado ANTES que los demás.
     *
     * @return \App\Models\Admin El closer (con teléfono +5493415550000).
     */
    private function preparar(): Admin
    {
        $this->crear_config_whatsapp();
        Admin::query()->update(['solo_leads' => false, 'es_vendedor' => false, 'is_closer' => false]);
        $closer = $this->admin(true);
        $closer->es_vendedor = true;
        $closer->save();

        return $closer;
    }

    /**
     * El aviso del día 5 de un negocio en su día 5.
     *
     * @return array<string, mixed>
     */
    private function avisar(): array
    {
        $client = $this->cliente_en_prueba(2, 7);
        $lead   = $this->lead_en_prueba($client);

        return app(PruebaWhatsappService::class)->avisar_a_tomas_si_es_el_dia_5($client->fresh(), $lead, app(PruebaDelClienteService::class)->datos($client->fresh()));
    }

    /**
     * El closer es vendedor y más viejo que Juan: igual va a Juan.
     *
     * @return void
     */
    public function test_el_closer_vendedor_mas_viejo_no_le_gana_a_juan(): void
    {
        $espia = $this->espiar_sender();
        $this->preparar();
        Admin::create(['name' => 'Juan', 'email' => 'juan-dest@test.local', 'password' => bcrypt('secret'), 'solo_leads' => true, 'phone_number' => '+5493416660000']);

        $this->assertSame('avisado', $this->avisar()['estado']);

        $this->assertSame('+5493416660000', $espia->textos[0]['to']);
    }

    /**
     * Sin `solo_leads` con teléfono: un vendedor que no es closer le gana al closer (que también es vendedor y es más viejo).
     *
     * @return void
     */
    public function test_sin_juan_gana_un_vendedor_que_no_es_closer(): void
    {
        $espia = $this->espiar_sender();
        $this->preparar();
        Admin::create(['name' => 'Juan sin teléfono', 'email' => 'juan-sin@test.local', 'password' => bcrypt('secret'), 'solo_leads' => true]);
        Admin::create(['name' => 'Vendedor', 'email' => 'vendedor-dest@test.local', 'password' => bcrypt('secret'), 'es_vendedor' => true, 'phone_number' => '+5493417770000']);

        $this->assertSame('avisado', $this->avisar()['estado']);

        $this->assertSame('+5493417770000', $espia->textos[0]['to']);
    }

    /**
     * Sin Juan ni otro vendedor con teléfono: el closer, como hoy.
     *
     * @return void
     */
    public function test_sin_nadie_mas_va_al_closer(): void
    {
        $espia = $this->espiar_sender();
        $this->preparar();

        $this->assertSame('avisado', $this->avisar()['estado']);

        $this->assertSame('+5493415550000', $espia->textos[0]['to']);
    }
}
