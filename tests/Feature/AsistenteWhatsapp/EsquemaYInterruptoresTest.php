<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Schema;

/**
 * El esquema nuevo y los interruptores que gobiernan el canal.
 *
 * Es la prueba más aburrida de las cinco y la que más rápido detecta una migración que no corrió:
 * sin la columna del interruptor, el webhook rutea al asistente a nadie; sin la tabla del hilo, una
 * cita no se puede resolver y cada mensaje del dueño abre una conversación nueva.
 *
 * Lo que se cuida con los interruptores es el sentido de sus defaults, que es al revés del reflejo
 * habitual en los dos casos y por motivos opuestos:
 *
 *   - `clients.asistente_whatsapp_activo` nace APAGADO porque prenderlo sobre un cliente sin
 *     actualizar no rompe nada pero tampoco sirve de nada.
 *   - `support_whatsapp_tickets_enabled` también se lee como APAGADO cuando la fila no existe, y eso
 *     sí es la decisión de esta misión: el soporte por este número se desconecta, y si dependiera de
 *     que alguien escriba una fila, no se desconectaría nunca.
 */
class EsquemaYInterruptoresTest extends BaseDelCanal
{
    /**
     * La columna del interruptor existe y nace apagada.
     *
     * @return void
     */
    public function test_el_interruptor_del_cliente_existe_y_nace_apagado(): void
    {
        $this->assertTrue(
            Schema::hasColumn('clients', 'asistente_whatsapp_activo'),
            'Falta clients.asistente_whatsapp_activo: no corrió la migración del interruptor.'
        );

        /* Se crea sin tocar la columna a propósito: lo que se mide es el default de la base, no lo
         * que escriba el helper. */
        $client            = new \App\Models\Client();
        $client->name      = 'Cliente recién dado de alta';
        $client->is_active = true;
        $client->save();

        $client->refresh();

        $this->assertFalse(
            (bool) $client->asistente_whatsapp_activo,
            'Un cliente nuevo no puede nacer con el canal del asistente prendido.'
        );
    }

    /**
     * La tabla del hilo existe con las columnas que hacen falta para la cita y la traza.
     *
     * @return void
     */
    public function test_la_tabla_del_hilo_tiene_las_columnas_del_contrato(): void
    {
        $this->assertTrue(
            Schema::hasTable('client_assistant_messages'),
            'Falta la tabla client_assistant_messages.'
        );

        $columnas = [
            'client_id',
            'telefono',
            'direccion',
            'whatsapp_message_id',
            'reply_to_whatsapp_message_id',
            'ai_conversation_id',
            'ai_message_id',
            'tipo',
            'texto',
            'estado',
            'error',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('client_assistant_messages', $columna),
                'Falta client_assistant_messages.' . $columna . '.'
            );
        }
    }

    /**
     * Sin fila en admin_settings, los tickets de soporte están desconectados.
     *
     * @return void
     */
    public function test_sin_fila_los_tickets_de_soporte_estan_desconectados(): void
    {
        AdminSetting::where('key', AsistenteWhatsappSettings::KEY_TICKETS_ENABLED)->delete();

        $this->assertFalse(
            AsistenteWhatsappSettings::tickets_habilitados(),
            'La ausencia de la fila tiene que leerse como "desconectado", que es la decisión de esta misión.'
        );
    }

    /**
     * El interruptor se prende explícitamente y con valores sin ambigüedad.
     *
     * @return void
     */
    public function test_los_tickets_se_prenden_con_un_valor_explicito(): void
    {
        foreach (['1', 'true', 'on', 'si'] as $valor) {
            AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, $valor);
            $this->assertTrue(
                AsistenteWhatsappSettings::tickets_habilitados(),
                'El valor "' . $valor . '" tiene que prender el canal de soporte.'
            );
        }

        foreach (['0', 'false', 'off', '', 'cualquier cosa'] as $valor) {
            AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, $valor);
            $this->assertFalse(
                AsistenteWhatsappSettings::tickets_habilitados(),
                'El valor "' . $valor . '" no puede prender el canal de soporte.'
            );
        }
    }

    /**
     * El texto de cortesía nace vacío: con el soporte apagado no se contesta nada.
     *
     * @return void
     */
    public function test_el_texto_de_soporte_desconectado_nace_vacio(): void
    {
        AdminSetting::where('key', AsistenteWhatsappSettings::KEY_DESCONECTADO_TEXTO)->delete();

        $this->assertSame('', AsistenteWhatsappSettings::texto_de_soporte_desconectado());
    }

    /**
     * No hay plantilla de informes por defecto, y no puede haberla: una inventada la rechaza Meta.
     *
     * @return void
     */
    public function test_no_hay_plantilla_de_informes_por_defecto(): void
    {
        AdminSetting::where('key', AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME)->delete();

        $this->assertSame('', AsistenteWhatsappSettings::plantilla_de_informes());
        $this->assertSame('es_AR', AsistenteWhatsappSettings::idioma_de_la_plantilla_de_informes());
    }

    /**
     * La cita se resuelve contra el hilo del MISMO cliente y nunca contra el de otro.
     *
     * Es el aislamiento que hace que este canal se pueda prender en cuarenta clientes a la vez: un
     * wamid es único para Meta, pero la consulta igual scopea por cliente, porque el día que dos
     * filas compartan wamid —un reenvío, una carga a mano, una migración de datos— el error sería
     * mandarle a un dueño la conversación de otro negocio.
     *
     * @return void
     */
    public function test_la_cita_no_cruza_de_un_cliente_a_otro(): void
    {
        $uno  = $this->crear_cliente('+5493411111111');
        $otro = $this->crear_cliente('+5493412222222');

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $uno->id;
        $fila->telefono            = '+5493411111111';
        $fila->direccion           = ClientAssistantMessage::DIRECCION_SALIENTE;
        $fila->whatsapp_message_id = 'wamid.COMPARTIDO';
        $fila->ai_conversation_id  = 77;
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->save();

        $this->assertSame(
            77,
            ClientAssistantMessage::conversacion_por_cita((int) $uno->id, 'wamid.COMPARTIDO')
        );

        $this->assertNull(
            ClientAssistantMessage::conversacion_por_cita((int) $otro->id, 'wamid.COMPARTIDO'),
            'La cita de un cliente no puede resolver una conversación de otro.'
        );
    }

    /**
     * Sin cita, o con una que no es de este canal, no se deduce ninguna conversación.
     *
     * Devolver null acá no es un error: significa "no hay cita que resolver", y en ese caso la
     * conversación la decide el `empresa-api` con su corte por tiempo. Si esto devolviera algo
     * inventado, el dueño seguiría una conversación que no pidió.
     *
     * @return void
     */
    public function test_sin_cita_conocida_no_se_deduce_ninguna_conversacion(): void
    {
        $client = $this->crear_cliente();

        $this->assertNull(ClientAssistantMessage::conversacion_por_cita((int) $client->id, null));
        $this->assertNull(ClientAssistantMessage::conversacion_por_cita((int) $client->id, ''));
        $this->assertNull(ClientAssistantMessage::conversacion_por_cita((int) $client->id, 'wamid.DE-SOPORTE'));
    }
}
