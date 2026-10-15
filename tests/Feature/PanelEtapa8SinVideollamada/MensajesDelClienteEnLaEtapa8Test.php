<?php

namespace Tests\Feature\PanelEtapa8SinVideollamada;

/**
 * Lo que el cliente escribe cuando la implementación está en la etapa 8 (modo auto: `handle()` → `handle_stage_8()`).
 *
 * 🔴 Antes del 14/10/2026 la etapa 8 era una conversación por la videollamada: sin apertura previa mandaba la oferta como fallback, ante
 * un mensaje sin texto repreguntaba «¿Cuándo tenés disponibilidad para la videollamada?» y a todo lo demás lo tomaba por disponibilidad
 * (o, con la IA, por «no la quiero»). Ahora lo que escribe el cliente va a una persona: el admin asignado recibe el texto y el cliente un
 * acuse. Si pide la videollamada, la coordina el admin.
 *
 * Y las implementaciones que ya estaban en el flujo viejo (`current_question = availability`, al cliente ya se le había preguntado la
 * disponibilidad) siguen tomando su respuesta como antes: no se les pierde una conversación en curso.
 */
class MensajesDelClienteEnLaEtapa8Test extends BaseDeLaEtapa8DelPanel
{
    /**
     * El texto del único aviso que recibió el admin (se busca por destinatario: en el flujo viejo primero sale la respuesta al cliente y
     * después el aviso, y en el nuevo al revés).
     *
     * @param \App\Services\WhatsappSendService $whatsapp El doble que anota los envíos.
     *
     * @return string
     */
    private function aviso_al_admin($whatsapp): string
    {
        $avisos = array_values(array_filter($whatsapp->enviados, function ($enviado) {
            return $enviado['to'] === self::TELEFONO_DEL_ADMIN;
        }));

        $this->assertCount(1, $avisos, 'El admin tenía que recibir un solo aviso.');

        return $avisos[0]['body'];
    }

    /**
     * (a) 🔴 Un mensaje cualquiera en la etapa 8 recién abierta (sin `current_question`): el admin recibe el texto y el cliente recibe el
     * acuse. NO se llama a la IA, no se guarda ninguna disponibilidad y no se nombra la videollamada.
     *
     * @return void
     */
    public function test_lo_que_escribe_el_cliente_va_a_una_persona(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $whatsapp  = $this->whatsapp_que_anota();
        $interprete = $this->interprete_que_contesta(true);

        $this->servicio($whatsapp, $interprete)->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'Hola, no me sale el reporte de ventas del mes'));

        $this->assertSame([], $interprete->llamadas, 'La etapa 8 nueva le preguntó a la IA si el cliente quiere la videollamada.');

        // Al admin: el texto del cliente.
        $this->assertCount(2, $whatsapp->enviados, 'Tenían que salir dos mensajes: el aviso al admin y el acuse al cliente.');
        $aviso = $whatsapp->enviados[0];
        $this->assertSame(self::TELEFONO_DEL_ADMIN, $aviso['to']);
        $this->assertStringContainsString('Panchito S.A.', $aviso['body']);
        $this->assertStringContainsString('Etapa 8', $aviso['body']);
        $this->assertStringContainsString('"Hola, no me sale el reporte de ventas del mes"', $aviso['body']);

        // Al cliente: el acuse, y nada más.
        $this->assertSame([
            'Recibí tu mensaje. ' . self::NOMBRE_DEL_ADMIN . ' te va a responder en breve. 🙏',
        ], $this->lo_que_recibio_el_cliente($implementacion));
        $this->assertSame((string) $implementacion->client->phone, $whatsapp->enviados[1]['to']);

        // Nada que el SPA vieja interprete como «disponibilidad confirmada»: el data de la etapa queda como estaba.
        $this->assertSame([], $this->data_de_la_etapa($implementacion, 8));
    }

    /**
     * (b) 🔴 Si el cliente PIDE la videollamada, no se le agenda ni se le ofrece nada: el admin recibe el pedido y el cliente el mismo
     * acuse. La coordina una persona.
     *
     * @return void
     */
    public function test_si_el_cliente_pide_la_videollamada_la_coordina_una_persona(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, '¿Podemos hacer una videollamada con mi equipo?'));

        $this->assertStringContainsString('"¿Podemos hacer una videollamada con mi equipo?"', $whatsapp->enviados[0]['body']);
        $this->assertStringContainsString('coordinala vos', $whatsapp->enviados[0]['body']);

        $recibido = $this->lo_que_recibio_el_cliente($implementacion);
        $this->assertCount(1, $recibido);
        $this->assertStringNotContainsStringIgnoringCase('videollamada', $recibido[0]);
        $this->assertStringNotContainsStringIgnoringCase('disponibilidad', $recibido[0]);
        $this->assertSame([], $this->data_de_la_etapa($implementacion, 8));
    }

    /**
     * (c) 🔴 Un mensaje sin texto (un audio, una imagen) tampoco dispara la repregunta vieja: el admin lo ve y el cliente recibe el acuse.
     *
     * @return void
     */
    public function test_un_mensaje_sin_texto_no_repregunta_la_disponibilidad(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle($implementacion->refresh(), ['from' => (string) $implementacion->client->phone, 'type' => 'audio', 'body' => '']);

        $this->assertStringContainsString('sin texto', $whatsapp->enviados[0]['body']);

        $recibido = $this->lo_que_recibio_el_cliente($implementacion);
        $this->assertCount(1, $recibido);
        $this->assertStringNotContainsStringIgnoringCase('videollamada', $recibido[0]);
        $this->assertStringNotContainsStringIgnoringCase('disponibilidad', $recibido[0]);
    }

    /**
     * (d) Un cliente que escribe seguido recibe su acuse cada vez y el admin cada texto: no hay estado que se «complete» y deje de
     * atender (en el flujo viejo, después de la primera respuesta la etapa se cerraba y todo lo demás se ignoraba).
     *
     * @return void
     */
    public function test_cada_mensaje_llega_al_admin(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $whatsapp = $this->whatsapp_que_anota();
        $servicio = $this->servicio($whatsapp);

        $servicio->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'primera duda'));
        $servicio->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'segunda duda'));

        $avisos = array_values(array_filter($whatsapp->enviados, function ($enviado) {
            return $enviado['to'] === self::TELEFONO_DEL_ADMIN;
        }));

        $this->assertCount(2, $avisos);
        $this->assertStringContainsString('primera duda', $avisos[0]['body']);
        $this->assertStringContainsString('segunda duda', $avisos[1]['body']);
        $this->assertCount(2, $this->lo_que_recibio_el_cliente($implementacion));
    }

    /**
     * (e) Sin admin asignado el cliente igual recibe el acuse, con el nombre institucional, y no se rompe nada.
     *
     * @return void
     */
    public function test_sin_admin_asignado_el_acuse_dice_el_equipo(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $whatsapp       = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'hola'));

        $this->assertSame(['Recibí tu mensaje. el equipo de ComercioCity te va a responder en breve. 🙏'], $this->lo_que_recibio_el_cliente($implementacion));
    }

    /**
     * (f) 🔴 COMPATIBILIDAD: una implementación a la que ya se le preguntó la disponibilidad con el texto viejo (`current_question =
     * availability`) sigue tomando su respuesta como antes: guarda `availability`, cierra la etapa, le contesta que se la pasa al admin y
     * avisa al admin.
     *
     * @return void
     */
    public function test_la_respuesta_a_la_pregunta_vieja_se_sigue_tomando_como_disponibilidad(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $this->escribir_data_de_la_etapa($implementacion, 8, ['current_question' => 'availability']);
        $whatsapp   = $this->whatsapp_que_anota();
        $interprete = $this->interprete_que_contesta(false);

        $this->servicio($whatsapp, $interprete)->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'el viernes a la tarde'));

        $this->assertCount(1, $interprete->llamadas);
        $this->assertSame('skip_videocall', $interprete->llamadas[0]['clave']);

        $data = $this->data_de_la_etapa($implementacion, 8);
        $this->assertSame('el viernes a la tarde', $data['availability']);
        $this->assertSame('completed', $data['current_question']);
        $this->assertTrue($data['completed']);

        $this->assertSame(['Perfecto, le paso tu disponibilidad a ' . self::NOMBRE_DEL_ADMIN . ' para que confirme el horario. Te avisamos a la brevedad.'], $this->lo_que_recibio_el_cliente($implementacion));
        $this->assertStringContainsString('disponibilidad para videollamada: el viernes a la tarde', $this->aviso_al_admin($whatsapp));
    }

    /**
     * (g) COMPATIBILIDAD: en el flujo viejo, un cliente que dice que no quiere la videollamada la saltea: `skip_videocall`, etapa cerrada,
     * respuesta de siempre y aviso al admin.
     *
     * @return void
     */
    public function test_en_el_flujo_viejo_el_cliente_puede_decir_que_no_la_quiere(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $this->escribir_data_de_la_etapa($implementacion, 8, ['current_question' => 'availability']);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp, $this->interprete_que_contesta(true))->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'no hace falta, gracias'));

        $data = $this->data_de_la_etapa($implementacion, 8);
        $this->assertTrue($data['skip_videocall']);
        $this->assertSame('completed', $data['current_question']);
        $this->assertArrayNotHasKey('availability', $data);

        $this->assertSame(['¡Perfecto, sin problema! Si en algún momento tenés dudas, escribinos por acá. ¡Mucho éxito con el sistema! 🚀'], $this->lo_que_recibio_el_cliente($implementacion));
        $this->assertStringContainsString('decidió no hacer la videollamada', $this->aviso_al_admin($whatsapp));
    }

    /**
     * (h) 🔴 Lo único que se sacó del flujo viejo: ante un mensaje SIN TEXTO ya no se repregunta «¿Cuándo tenés disponibilidad para la
     * videollamada?». Al cliente no se le manda nada; lo ve el admin, y la pregunta sigue pendiente (su próxima respuesta con texto se
     * toma como disponibilidad).
     *
     * @return void
     */
    public function test_en_el_flujo_viejo_un_mensaje_sin_texto_ya_no_se_repregunta(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $this->escribir_data_de_la_etapa($implementacion, 8, ['current_question' => 'availability']);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle($implementacion->refresh(), ['from' => (string) $implementacion->client->phone, 'type' => 'image', 'body' => '']);

        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion), 'Se le repreguntó la videollamada al cliente.');
        $this->assertCount(1, $whatsapp->enviados);
        $this->assertSame(self::TELEFONO_DEL_ADMIN, $whatsapp->enviados[0]['to']);
        $this->assertSame('availability', $this->data_de_la_etapa($implementacion, 8)['current_question']);
    }

    /**
     * (i) COMPATIBILIDAD: una etapa 8 ya completada en el flujo viejo sigue ignorando los mensajes posteriores, como siempre.
     *
     * @return void
     */
    public function test_una_etapa_8_ya_completada_sigue_ignorando_los_mensajes(): void
    {
        $implementacion = $this->implementacion_en(8, 'auto');
        $this->con_admin_asignado($implementacion);
        $this->escribir_data_de_la_etapa($implementacion, 8, ['current_question' => 'completed', 'completed' => true, 'availability' => 'el lunes']);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'gracias!'));

        $this->assertSame([], $whatsapp->enviados);
        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion));
    }

    /**
     * (j) En modo manual no se auto-responde nada, en la etapa 8 tampoco (el mensaje queda en el hilo del panel y lo ve una persona).
     *
     * @return void
     */
    public function test_en_modo_manual_no_se_responde_nada(): void
    {
        $implementacion = $this->implementacion_en(8, 'manual');
        $this->con_admin_asignado($implementacion);
        $whatsapp = $this->whatsapp_que_anota();

        $this->servicio($whatsapp)->handle($implementacion->refresh(), $this->mensaje_del_cliente($implementacion, 'hola'));

        $this->assertSame([], $whatsapp->enviados);
        $this->assertSame([], $this->lo_que_recibio_el_cliente($implementacion));
    }
}
