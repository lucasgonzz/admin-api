<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\Admin;
use App\Models\WhatsappConfig;

/**
 * `GET|PUT /api/admin/whatsapp-config` con DOS números: el de leads y clientes (`principal`) y el del
 * agente personal (`asistente`).
 *
 * Lo que más pesa acá es lo que NO puede pasar: que guardar el número del asistente pise las
 * credenciales del de leads (el bug de la versión anterior, que ignoraba el propósito), o que un typo
 * en el propósito escriba el número equivocado.
 */
class ConfiguracionDeLosNumerosTest extends BaseDelCanal
{
    /**
     * Admin autenticado con Sanctum para pegarle al panel.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin-whatsapp-' . uniqid() . '@ejemplo.test';
        $admin->password = bcrypt('secret');
        $admin->save();

        $this->actingAs($admin, 'sanctum');
    }

    /**
     * Cuerpo válido de un número.
     *
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return array<string, mixed>
     */
    private function cuerpo(array $campos = []): array
    {
        return array_merge([
            'kapso_api_key'   => 'clave-nueva',
            'phone_number_id' => '777000111222',
            'webhook_secret'  => 'secreto-nuevo',
            'display_phone'   => '+54 9 3444 54-4199',
            'nombre'          => 'ComercioCity',
            'test_mode'       => false,
        ], $campos);
    }

    /**
     * Sin ninguna fila, el panel recibe las dos tarjetas vacías y qué le falta a cada una.
     *
     * @return void
     */
    public function test_sin_filas_el_panel_recibe_el_estado_vacio(): void
    {
        $this->getJson('/api/admin/whatsapp-config')
            ->assertStatus(200)
            ->assertJsonPath('principal', null)
            ->assertJsonPath('asistente', null)
            ->assertJsonPath('estado.principal.listo', false)
            ->assertJsonPath('estado.asistente.listo', false)
            ->assertJsonPath('estado.asistente.faltan', ['fila']);
    }

    /**
     * 🔴 Un PUT sin `proposito` escribe el número de leads y clientes, como antes de la misión.
     *
     * @return void
     */
    public function test_un_put_sin_proposito_escribe_el_principal(): void
    {
        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo())->assertStatus(200);

        $principal = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
        $this->assertNotNull($principal);
        $this->assertSame('777000111222', $principal->phone_number_id);
        $this->assertNull(WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE));
    }

    /**
     * 🔴 Guardar el asistente crea SU fila y no toca la del principal.
     *
     * @return void
     */
    public function test_guardar_el_asistente_no_pisa_el_principal(): void
    {
        $this->crear_config_whatsapp();

        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo([
            'proposito'       => 'asistente',
            'phone_number_id' => self::PHONE_NUMBER_ID_ASISTENTE,
            'display_phone'   => '+1 201-586-6682',
        ]))->assertStatus(200);

        $principal = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
        $this->assertSame(self::PHONE_NUMBER_ID_PRINCIPAL, $principal->phone_number_id, 'El número de leads no se tocó.');
        $this->assertSame('clave-kapso-de-prueba', $principal->kapso_api_key);

        $asistente = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE);
        $this->assertNotNull($asistente);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, $asistente->phone_number_id);
    }

    /**
     * 🔴 Un propósito inventado corta con 422 y no escribe NADA (antes escribía el principal).
     *
     * @return void
     */
    public function test_un_proposito_inventado_no_escribe_nada(): void
    {
        $this->crear_config_whatsapp();

        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo(['proposito' => 'asistnete']))
            ->assertStatus(422);

        $this->assertSame(
            self::PHONE_NUMBER_ID_PRINCIPAL,
            WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL)->phone_number_id
        );
    }

    /**
     * Con `usar_clave_del_principal` el servidor copia la clave del principal: no hace falta pegarla.
     *
     * @return void
     */
    public function test_la_clave_se_puede_copiar_del_principal(): void
    {
        $this->crear_config_whatsapp();

        $cuerpo = $this->cuerpo([
            'proposito'                => 'asistente',
            'phone_number_id'          => self::PHONE_NUMBER_ID_ASISTENTE,
            'display_phone'            => '+1 201-586-6682',
            'usar_clave_del_principal' => true,
        ]);
        unset($cuerpo['kapso_api_key']);

        $this->putJson('/api/admin/whatsapp-config', $cuerpo)->assertStatus(200);

        $this->assertSame(
            'clave-kapso-de-prueba',
            WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE)->kapso_api_key
        );
    }

    /**
     * Si el principal no tiene clave para compartir, es un 422 claro y no una fila sin clave.
     *
     * @return void
     */
    public function test_sin_clave_en_el_principal_no_hay_nada_que_copiar(): void
    {
        $principal = $this->crear_config_whatsapp();
        $principal->kapso_api_key = '';
        $principal->save();

        $cuerpo = $this->cuerpo([
            'proposito'                => 'asistente',
            'usar_clave_del_principal' => true,
        ]);
        unset($cuerpo['kapso_api_key']);

        $this->putJson('/api/admin/whatsapp-config', $cuerpo)->assertStatus(422);

        $this->assertNull(WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE));
    }

    /**
     * Una clave explícita gana sobre la casilla: es el caso del número que vive en otro proyecto.
     *
     * @return void
     */
    public function test_una_clave_explicita_gana_sobre_la_copia(): void
    {
        $this->crear_config_whatsapp();

        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo([
            'proposito'                => 'asistente',
            'kapso_api_key'            => 'clave-propia',
            'usar_clave_del_principal' => true,
        ]))->assertStatus(200);

        $this->assertSame(
            'clave-propia',
            WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE)->kapso_api_key
        );
    }

    /**
     * El número visible es obligatorio para el asistente (y con al menos ocho dígitos), y opcional para
     * el principal.
     *
     * @return void
     */
    public function test_el_numero_visible_es_obligatorio_solo_para_el_asistente(): void
    {
        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo(['proposito' => 'asistente', 'display_phone' => '']))
            ->assertStatus(422);

        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo(['proposito' => 'asistente', 'display_phone' => '12345']))
            ->assertStatus(422);

        $this->putJson('/api/admin/whatsapp-config', $this->cuerpo(['display_phone' => '']))
            ->assertStatus(200);
    }

    /**
     * 🔴 Un PUT que no trae el secreto (o lo trae vacío) no le borra el que ya tiene la fila: lo
     * escribió el comando de alta del webhook.
     *
     * @return void
     */
    public function test_un_put_sin_secreto_no_borra_el_secreto_del_asistente(): void
    {
        $this->crear_config_asistente();

        $cuerpo = $this->cuerpo([
            'proposito'       => 'asistente',
            'phone_number_id' => self::PHONE_NUMBER_ID_ASISTENTE,
            'nombre'          => 'Agente personal',
        ]);
        unset($cuerpo['webhook_secret']);

        $this->putJson('/api/admin/whatsapp-config', $cuerpo)->assertStatus(200);
        $this->putJson('/api/admin/whatsapp-config', array_merge($cuerpo, ['webhook_secret' => '']))->assertStatus(200);

        $asistente = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE);
        $this->assertSame(self::SECRETO_DEL_ASISTENTE, $asistente->webhook_secret);
        $this->assertSame('Agente personal', $asistente->nombre);
    }

    /**
     * El estado del GET dice qué falta con NOMBRES de campos, y pasa a «listo» al completarse.
     *
     * @return void
     */
    public function test_el_estado_dice_que_falta_con_nombres_de_campos(): void
    {
        $this->crear_config_whatsapp();
        $this->crear_config_asistente(['webhook_secret' => null]);

        $respuesta = $this->getJson('/api/admin/whatsapp-config')->assertStatus(200);

        $respuesta->assertJsonPath('estado.principal.listo', true)
            ->assertJsonPath('estado.asistente.listo', false)
            ->assertJsonPath('estado.asistente.faltan', ['webhook_secret']);

        $this->crear_config_asistente_completa_y_ver_listo();
    }

    /**
     * Completa el secreto y verifica que el estado pase a «listo».
     *
     * @return void
     */
    private function crear_config_asistente_completa_y_ver_listo(): void
    {
        WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE)
            ->forceFill(['webhook_secret' => self::SECRETO_DEL_ASISTENTE])
            ->save();

        $this->getJson('/api/admin/whatsapp-config')
            ->assertJsonPath('estado.asistente.listo', true)
            ->assertJsonPath('estado.asistente.faltan', []);
    }
}
