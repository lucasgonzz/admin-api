<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\ModelProperties\ClientProperties;
use App\Models\Admin;

/**
 * La casilla "Habla con su asistente por WhatsApp" en la ficha del cliente.
 *
 * 🔴 **La casilla no se dibuja en `admin-spa`: se declara acá.** Lo que el operador ve en la ficha
 * sale del meta que publica `GET /api/meta/client`, o sea de `ClientProperties`, y el formulario
 * genérico de `common-vue` lo renderiza solo. Por eso esta misión no toca una línea de
 * `admin-spa` — y por eso la prueba de que la casilla existe y persiste vive del lado del API.
 *
 * Lo que se cuida es que siga llegando entera hasta la columna: el meta la declara, el PUT de la
 * ficha la persiste, y el default es APAGADO. Si mañana alguien la marca `not_persisted_on_model`
 * o le borra la clave, la casilla se sigue viendo en la pantalla y deja de guardar nada — un
 * defecto que a ojo no se nota.
 */
class CasillaEnLaFichaDelClienteTest extends BaseDelCanal
{
    /**
     * La casilla está declarada, es checkbox y nace apagada.
     *
     * @return void
     */
    public function test_la_casilla_esta_declarada_y_nace_apagada(): void
    {
        $casilla = null;
        foreach (ClientProperties::all() as $propiedad) {
            if (isset($propiedad['key']) && $propiedad['key'] === 'asistente_whatsapp_activo') {
                $casilla = $propiedad;
                break;
            }
        }

        $this->assertNotNull($casilla, 'La ficha del cliente tiene que declarar la casilla del asistente.');
        $this->assertSame('checkbox', $casilla['type']);
        $this->assertFalse($casilla['value'], 'La casilla nace apagada: se prende cliente por cliente y a mano.');
        $this->assertSame('Habla con su asistente por WhatsApp', $casilla['text']);

        /* Ninguna de estas tres la sacaría del formulario o del guardado. */
        $this->assertArrayNotHasKey('only_show', $casilla);
        $this->assertArrayNotHasKey('exclude_on_update', $casilla);
        $this->assertArrayNotHasKey('not_persisted_on_model', $casilla);
    }

    /**
     * El meta que consume el SPA la publica.
     *
     * @return void
     */
    public function test_el_meta_del_cliente_publica_la_casilla(): void
    {
        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/meta/client');

        $respuesta->assertStatus(200);

        $claves = array_map(function (array $propiedad) {
            return isset($propiedad['key']) ? $propiedad['key'] : null;
        }, $respuesta->json('properties'));

        $this->assertContains('asistente_whatsapp_activo', $claves);
    }

    /**
     * Marcarla desde la ficha la persiste, y desmarcarla la apaga.
     *
     * @return void
     */
    public function test_la_casilla_se_guarda_desde_la_ficha(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente('+5493411234567', false);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/client/' . $client->id, ['asistente_whatsapp_activo' => true])
            ->assertStatus(200);

        $client->refresh();
        $this->assertTrue((bool) $client->asistente_whatsapp_activo);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/client/' . $client->id, ['asistente_whatsapp_activo' => false])
            ->assertStatus(200);

        $client->refresh();
        $this->assertFalse((bool) $client->asistente_whatsapp_activo);
    }

    /**
     * Admin operador, para los endpoints de la ficha.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'lucas+' . uniqid() . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }
}
