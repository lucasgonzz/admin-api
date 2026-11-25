<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\AdminSetting;

/**
 * Los interruptores del motor de compromisos en la pantalla de Cuenta (lado API): criterio CA18 de la etapa E2 de la misión
 * leads-referidos-compromisos.
 *
 * `GET/PUT admin/settings/lead-whatsapp-onboarding` suman cinco campos, todos con el motor APAGADO por defecto (el motor
 * cambia lo que le pasa a los leads existentes: lo prende Lucas cuando quiere):
 *
 *  | campo                          | clave de `admin_settings`            | default                  |
 *  |--------------------------------|--------------------------------------|--------------------------|
 *  | `compromisos_activo`           | `lead_compromisos_activo`            | false                    |
 *  | `compromisos_deteccion_ia`     | `lead_compromisos_deteccion_ia`      | false                    |
 *  | `compromisos_envio_automatico` | `lead_compromisos_envio_automatico`  | false                    |
 *  | `compromiso_plantilla`         | `lead_compromiso_plantilla`          | `cc_coord_dia_acordado`  |
 *  | `referido_plantilla`           | `lead_referido_plantilla`            | '' (vacío)               |
 *
 * Son OPCIONALES en el PUT: el SPA que ya está en producción no los manda y un PUT sin ellos no puede apagar el motor ni
 * borrar las plantillas (mismo criterio que `flujo_entrada_activo`). El lado de la pantalla (que la sección de Cuenta
 * muestre las cinco claves) lo prueba `pruebas/leads-referidos-compromisos.cjs` en admin-spa.
 *
 * Hoy están EN ROJO: el GET no trae los campos nuevos y el PUT no los guarda.
 */
class InterruptoresDeCompromisosTest extends BaseDeReferidosYCompromisos
{
    /** Endpoint de la pantalla de Cuenta. */
    private const ENDPOINT = '/api/admin/settings/lead-whatsapp-onboarding';

    /** Los cinco campos nuevos y su clave de `admin_settings`. */
    private const CAMPOS = [
        'compromisos_activo'           => 'lead_compromisos_activo',
        'compromisos_deteccion_ia'     => 'lead_compromisos_deteccion_ia',
        'compromisos_envio_automatico' => 'lead_compromisos_envio_automatico',
        'compromiso_plantilla'         => 'lead_compromiso_plantilla',
        'referido_plantilla'           => 'lead_referido_plantilla',
    ];

    /**
     * Un admin de Cuenta sin roles de asignación y sin ninguna de las cinco claves guardadas (una base nueva).
     *
     * @return \App\Models\Admin
     */
    private function admin_de_cuenta_con_la_base_nueva()
    {
        AdminSetting::query()->whereIn('key', array_values(self::CAMPOS))->delete();

        return $this->crear_admin_con_roles('Admin de Cuenta', []);
    }

    /**
     * El payload que el SPA de hoy manda al PUT de Cuenta (sin los campos nuevos).
     *
     * @return array<string, mixed>
     */
    private function payload_de_hoy(): array
    {
        return [
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes'     => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => true,
        ];
    }

    /**
     * CA18. En una base nueva, el GET trae los tres interruptores en false, la plantilla de compromisos por defecto
     * `cc_coord_dia_acordado` y la plantilla de referido vacía. El GET solo no activa nada.
     *
     * @return void
     */
    public function test_defaults_apagados(): void
    {
        $admin = $this->admin_de_cuenta_con_la_base_nueva();

        $respuesta = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('compromisos_activo'), 'El motor nace apagado.');
        $this->assertFalse($respuesta->json('compromisos_deteccion_ia'), 'La detección con IA nace apagada.');
        $this->assertFalse($respuesta->json('compromisos_envio_automatico'), 'El envío automático nace apagado.');
        $this->assertSame('cc_coord_dia_acordado', $respuesta->json('compromiso_plantilla'));
        $this->assertSame('', (string) $respuesta->json('referido_plantilla'));
        $this->assertNotSame('1', (string) AdminSetting::get('lead_compromisos_activo', '0'), 'Leer la pantalla no prende el motor.');
    }

    /**
     * CA18. El PUT guarda los cinco campos en sus claves de `admin_settings` y el GET los devuelve; un PUT que NO los
     * manda no cambia nada (compatible con el SPA viejo); y se pueden volver a apagar.
     *
     * @return void
     */
    public function test_put_y_get(): void
    {
        $admin = $this->admin_de_cuenta_con_la_base_nueva();

        $put = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), [
            'compromisos_activo'           => true,
            'compromisos_deteccion_ia'     => true,
            'compromisos_envio_automatico' => true,
            'compromiso_plantilla'         => 'cc_mi_dia_acordado',
            'referido_plantilla'           => 'cc_referido_v1',
        ]));
        $put->assertStatus(200);
        $this->assertSame('1', AdminSetting::get('lead_compromisos_activo'));
        $this->assertSame('1', AdminSetting::get('lead_compromisos_deteccion_ia'));
        $this->assertSame('1', AdminSetting::get('lead_compromisos_envio_automatico'));
        $this->assertSame('cc_mi_dia_acordado', AdminSetting::get('lead_compromiso_plantilla'));
        $this->assertSame('cc_referido_v1', AdminSetting::get('lead_referido_plantilla'));
        $this->assertTrue($put->json('compromisos_activo'), 'El PUT devuelve lo guardado.');

        $get = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT);
        $this->assertTrue($get->json('compromisos_activo'));
        $this->assertTrue($get->json('compromisos_deteccion_ia'));
        $this->assertTrue($get->json('compromisos_envio_automatico'));
        $this->assertSame('cc_mi_dia_acordado', $get->json('compromiso_plantilla'));
        $this->assertSame('cc_referido_v1', $get->json('referido_plantilla'));

        /* Un PUT de hoy (sin los campos nuevos) no toca nada. */
        $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, $this->payload_de_hoy())->assertStatus(200);
        foreach (['lead_compromisos_activo' => '1', 'lead_compromisos_deteccion_ia' => '1', 'lead_compromisos_envio_automatico' => '1'] as $clave => $valor) {
            $this->assertSame($valor, AdminSetting::get($clave), 'Un PUT sin la clave no puede apagar ' . $clave . '.');
        }
        $this->assertSame('cc_mi_dia_acordado', AdminSetting::get('lead_compromiso_plantilla'), 'Ni borrar la plantilla.');
        $this->assertSame('cc_referido_v1', AdminSetting::get('lead_referido_plantilla'));

        /* Apagarlos explícitamente. */
        $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), [
            'compromisos_activo' => false, 'compromisos_deteccion_ia' => false, 'compromisos_envio_automatico' => false,
        ]))->assertStatus(200);
        $this->assertSame('0', AdminSetting::get('lead_compromisos_activo'));
        $this->assertSame('0', AdminSetting::get('lead_compromisos_deteccion_ia'));
        $this->assertSame('0', AdminSetting::get('lead_compromisos_envio_automatico'));
        $this->assertFalse($this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT)->json('compromisos_activo'));
    }
}
