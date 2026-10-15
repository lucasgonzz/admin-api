<?php

namespace Tests\Feature\PanelEtapa8SinVideollamada;

use App\Models\ImplementationStage;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Entrar a la etapa 8 desde el panel («Avanzar etapa»): al cliente NO le llega nada y el admin asignado recibe el aviso.
 *
 * Camino real: `POST /api/admin/implementation/{id}/advance-stage` → `ImplementationController::advance_stage()` →
 * `ImplementationConversationService::handle_stage_advance()` → `send_stage_opening_message(8)` → `send_stage_8_opening()`. Hasta el
 * 14/10/2026 ese último le mandaba al dueño «nos gustaría hacer una videollamada corta (20-30 minutos)…» y le preguntaba disponibilidad.
 *
 * Solo el modo `auto` manda cosas solo: en `manual` `handle_stage_advance()` no manda nada (eso no cambió y se fija igual).
 */
class AperturaDeLaEtapa8DelPanelTest extends BaseDeLaEtapa8DelPanel
{
    /**
     * (a) 🔴 El camino del panel de punta a punta, en modo auto: avanzar de la 7 a la 8 deja la etapa en curso y al cliente sin ningún
     * mensaje. El servicio del controller es el real (con el WhatsApp real): lo que se afirma es lo que quedó persistido y que no salió
     * ningún pedido HTTP, ni siquiera uno frenado.
     *
     * @return void
     */
    public function test_avanzar_a_la_etapa_8_desde_el_panel_no_le_manda_nada_al_cliente(): void
    {
        $implementacion = $this->implementacion_en(7, 'auto');

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $implementacion->id . '/advance-stage');

        $respuesta->assertStatus(200);

        $implementacion->refresh();
        $this->assertSame(8, (int) $implementacion->current_stage);
        $this->assertSame('in_progress', ImplementationStage::where('implementation_id', $implementacion->id)->where('stage_number', 8)->first()->status);

        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion), 'Al entrar a la etapa 8 el cliente recibió un mensaje.');
        $this->assertSame([], HttpFactorySinSalida::frenados(), 'Al entrar a la etapa 8 salió un pedido HTTP.');
    }

    /**
     * (b) 🔴 El mismo avance por el servicio, con el WhatsApp a la vista: el único envío es el aviso al admin asignado, a su teléfono, y
     * dice que no se le mandó nada al cliente. Ninguno va al teléfono del cliente.
     *
     * @return void
     */
    public function test_el_unico_envio_es_el_aviso_al_admin_asignado(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle_stage_advance($implementacion->refresh(), 8);

        $this->assertCount(1, $whatsapp->enviados, 'Tenía que salir un solo mensaje: el aviso al admin.');
        $this->assertSame(self::TELEFONO_DEL_ADMIN, $whatsapp->enviados[0]['to']);
        $this->assertStringContainsString('Panchito S.A.', $whatsapp->enviados[0]['body']);
        $this->assertStringContainsString('Etapa 8', $whatsapp->enviados[0]['body']);
        $this->assertStringContainsString('seguimos con soporte', $whatsapp->enviados[0]['body']);
        $this->assertStringContainsString('No se le mandó nada al cliente', $whatsapp->enviados[0]['body']);

        foreach ($whatsapp->enviados as $enviado) {
            $this->assertNotSame((string) $implementacion->client->phone, $enviado['to'], 'Algo salió al teléfono del cliente.');
        }

        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion));
    }

    /**
     * (c) La apertura no deja marcas en la etapa 8: ni `current_question` ni nada. Esa ausencia es lo que manda lo que el cliente escriba
     * después al camino «a una persona» (ver `MensajesDelClienteEnLaEtapa8Test`).
     *
     * @return void
     */
    public function test_la_apertura_no_toca_el_data_de_la_etapa(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');

        $this->servicio()->handle_stage_advance($implementacion->refresh(), 8);

        $this->assertSame([], $this->data_de_la_etapa($implementacion, 8));
    }

    /**
     * (d) Sin admin asignado, o sin teléfono, o con un cliente sin teléfono: no se rompe nada y al cliente tampoco le llega algo.
     *
     * @return void
     */
    public function test_sin_admin_asignado_ni_telefono_del_cliente_no_se_rompe(): void
    {
        $cliente        = $this->crear_cliente('Sin Telefono', ['phone' => '']);
        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente, ['automation_mode' => 'auto']), 8);
        $whatsapp       = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle_stage_advance($implementacion->refresh(), 8);

        $this->assertSame([], $whatsapp->enviados);
        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion));
    }

    /**
     * (e) Modo manual: avanzar a la 8 no manda nada a nadie, como siempre (en `manual` cada paso lo dispara una persona desde el panel).
     *
     * @return void
     */
    public function test_en_modo_manual_avanzar_a_la_etapa_8_no_manda_nada_a_nadie(): void
    {
        $implementacion = $this->implementacion_en(8, 'manual');
        $this->con_admin_asignado($implementacion);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle_stage_advance($implementacion->refresh(), 8);

        $this->assertSame([], $whatsapp->enviados);
        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion));
    }

    /**
     * (f) El cambio es solo de la etapa 8: en modo auto la apertura de la etapa 7 sigue saliendo al cliente, con su pregunta y su
     * `current_question`.
     *
     * @return void
     */
    public function test_la_apertura_de_la_etapa_7_no_cambio(): void
    {
        $implementacion = $this->implementacion_en(7, 'auto');

        $this->servicio()->handle_stage_advance($implementacion->refresh(), 7);

        $recibido = $this->lo_que_recibio_el_cliente($implementacion);

        $this->assertCount(1, $recibido, 'La apertura de la etapa 7 dejó de salir: este cambio es solo de la etapa 8.');
        $this->assertStringContainsString('vincular el sistema con ARCA', $recibido[0]);
        $this->assertSame('afip_contact_name', $this->data_de_la_etapa($implementacion, 7)['current_question']);
    }
}
