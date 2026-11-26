<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\DB;
use Tests\Feature\WhatsappPersonal\BaseDelNumeroPersonal;

/**
 * CA4 y CA5 de la misión dotin-whatsapp-admin: la lectura de WhatsApp desde Dotin/ChatGPT.
 *
 * Fixtures: el número personal y el principal listos; el MISMO teléfono (+5493412345678) escribió al personal
 * ("Prueba Dotin PERSONAL 4821"), al número principal como lead ("Prueba Dotin PRINCIPAL 7310") y al soporte por
 * WhatsApp ("Prueba Dotin SOPORTE 5592"). Hay que ver cada mensaje SÓLO en su cuenta, y que leer no modifica nada
 * (ni marca leído en el admin ni manda recibos a Meta).
 */
class McpWhatsappLecturaTest extends BaseDelNumeroPersonal
{
    use McpFixture, McpWhatsappFixture;

    /** @var string Token del conector de lectura de WhatsApp. */
    private $token_de_lectura;

    /**
     * Siembra las tres conversaciones de prueba y conecta el conector de lectura.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_whatsapp_mcp(true);
        $this->sembrar_cuentas_y_chat_personal();
        $this->sembrar_lead_con_mensaje('+5493412345678', 'Prueba Dotin PRINCIPAL 7310');
        $this->sembrar_ticket_whatsapp('5493412345678', 'Prueba Dotin SOPORTE 5592');

        $this->token_de_lectura = $this->token_whatsapp($this->admin_de_whatsapp, 'lectura')['access_token'];
    }

    /**
     * Llama una herramienta de lectura y devuelve su contenido estructurado.
     *
     * @param string               $nombre     Herramienta.
     * @param array<string, mixed> $argumentos Argumentos.
     *
     * @return array<string, mixed>
     */
    private function leer($nombre, array $argumentos = [])
    {
        return $this->datos_en('/api/mcp/whatsapp', $this->token_de_lectura, $nombre, $argumentos);
    }

    /**
     * Los mensajes de una respuesta de `whatsapp_get_messages` indexados por el texto que contienen.
     *
     * @param array<string, mixed> $datos Contenido estructurado.
     *
     * @return array<string, array<string, mixed>> Fragmento de texto => mensaje (o vacío si no está).
     */
    private function mensaje_con($datos, $fragmento)
    {
        foreach ((array) ($datos['mensajes'] ?? []) as $mensaje) {
            if (strpos((string) ($mensaje['texto'] ?? ''), $fragmento) !== false) {
                return $mensaje;
            }
        }

        return [];
    }

    /**
     * CA4: el mensaje de prueba aparece en la cuenta y conversación correctas (personal) y sólo ahí.
     *
     * @return void
     */
    public function test_mensaje_de_prueba_en_la_cuenta_y_conversacion_correctas(): void
    {
        // El teléfono viene en un formato "humano" y sale normalizado a E.164.
        $personal = $this->leer('whatsapp_get_messages', ['account' => 'personal', 'phone' => '0341 15-234-5678']);
        $this->assertSame('personal', $personal['cuenta']);
        $this->assertSame('+5493412345678', $personal['telefono']);
        $json = json_encode($personal, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Prueba Dotin PERSONAL 4821', $json);
        $this->assertStringNotContainsString('PRINCIPAL 7310', $json);
        $this->assertStringNotContainsString('SOPORTE 5592', $json);

        $mensaje = $this->mensaje_con($personal, 'PERSONAL 4821');
        $this->assertSame('in', $mensaje['direccion'] ?? null);
        $this->assertSame('personal', $mensaje['canal'] ?? null);

        // Los chats no leídos del personal: el de prueba, con un mensaje sin leer.
        $chats = $this->leer('whatsapp_list_chats', ['account' => 'personal', 'unread_only' => true]);
        $this->assertSame('personal', $chats['cuenta']);
        $encontrados = array_values(array_filter((array) $chats['chats'], function ($chat) {
            return ($chat['telefono'] ?? null) === '+5493412345678';
        }));
        $this->assertCount(1, $encontrados, 'El chat de prueba tiene que estar en la lista: ' . json_encode($chats));
        $this->assertSame(1, $encontrados[0]['no_leidos']);
        $this->assertStringNotContainsString('PRINCIPAL 7310', json_encode($chats));
    }

    /**
     * CA4: el número principal trae lead y soporte del mismo teléfono y NO el chat personal.
     *
     * @return void
     */
    public function test_principal_trae_lead_y_soporte_y_no_el_personal(): void
    {
        $principal = $this->leer('whatsapp_get_messages', ['account' => 'principal', 'phone' => '0341 15-234-5678']);

        $this->assertSame('principal', $principal['cuenta']);
        $this->assertSame('+5493412345678', $principal['telefono']);
        $json = json_encode($principal, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('PERSONAL 4821', $json);

        $del_lead = $this->mensaje_con($principal, 'PRINCIPAL 7310');
        $this->assertNotEmpty($del_lead, 'Falta el mensaje del lead: ' . $json);
        $this->assertSame('lead', $del_lead['canal'] ?? null);
        $this->assertSame('in', $del_lead['direccion'] ?? null);

        $del_soporte = $this->mensaje_con($principal, 'SOPORTE 5592');
        $this->assertNotEmpty($del_soporte, 'Falta el mensaje de soporte: ' . $json);
        $this->assertSame('soporte', $del_soporte['canal'] ?? null);
        $this->assertSame('in', $del_soporte['direccion'] ?? null);

        // El chat del principal aparece en su lista de chats.
        $chats = $this->leer('whatsapp_list_chats', ['account' => 'principal']);
        $this->assertSame('principal', $chats['cuenta']);
        $this->assertStringContainsString('+5493412345678', json_encode($chats));
        $this->assertStringNotContainsString('PERSONAL 4821', json_encode($chats));
    }

    /**
     * CA4: la lista de cuentas muestra personal (con envío) y principal (sólo lectura), no expone la del
     * asistente y no filtra ni una clave. Una cuenta fuera de la lista o un teléfono inválido son errores claros.
     *
     * @return void
     */
    public function test_cuentas_sin_secretos(): void
    {
        $cuentas = $this->leer('whatsapp_list_accounts');
        $por_cuenta = [];
        foreach ((array) $cuentas['cuentas'] as $cuenta) {
            $por_cuenta[$cuenta['cuenta']] = $cuenta;
        }

        $claves = array_keys($por_cuenta);
        sort($claves);
        $this->assertSame(['personal', 'principal'], $claves, 'Sólo personal y principal: ' . json_encode($cuentas));
        $this->assertArrayNotHasKey('asistente', $por_cuenta);
        $this->assertTrue($por_cuenta['personal']['envio']);
        $this->assertFalse($por_cuenta['principal']['envio']);
        $this->assertTrue($por_cuenta['personal']['lectura']);
        $this->assertTrue($por_cuenta['principal']['lectura']);

        // Ni una clave, ni un secreto, ni un id de Kapso en el JSON completo (contenido textual incluido).
        $completo = json_encode($this->herramienta_en('/api/mcp/whatsapp', $this->token_de_lectura, 'whatsapp_list_accounts'), JSON_UNESCAPED_UNICODE);
        foreach (['kapso_api_key', 'webhook_secret', 'phone_number_id', self::CLAVE_KAPSO, self::SECRETO_PERSONAL, self::SECRETO_PRINCIPAL, self::PNI_PERSONAL, self::PNI_PRINCIPAL, self::WABA_ID, '5550000111222'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $completo, 'La lista de cuentas filtra: ' . $secreto);
        }

        // Errores: cuenta no expuesta (asistente) y teléfono inválido.
        $asistente = $this->herramienta_en('/api/mcp/whatsapp', $this->token_de_lectura, 'whatsapp_get_messages', ['account' => 'asistente', 'phone' => '3412345678']);
        $this->assert_error_con($asistente, 'cuenta_no_disponible');
        $invalido = $this->herramienta_en('/api/mcp/whatsapp', $this->token_de_lectura, 'whatsapp_get_messages', ['account' => 'personal', 'phone' => 'abc']);
        $this->assert_error_con($invalido, 'telefono_invalido');
    }

    /**
     * CA5: leer (listar chats y traer mensajes, personal y principal, dos veces) no cambia NADA: ni "leído" en
     * el personal ni en leads/soporte, ni una fila nueva en ninguna tabla, ni un pedido a Kapso/Meta.
     *
     * @return void
     */
    public function test_leer_no_marca_leido_ni_sale_a_la_red(): void
    {
        $chats_antes   = DB::table('whatsapp_personal_chats')->orderBy('id')->get()->toArray();
        $mensajes_antes = DB::table('whatsapp_personal_messages')->orderBy('id')->get()->toArray();
        $foto_antes    = $this->foto_de_la_base();
        $this->fakear_http();

        for ($vuelta = 0; $vuelta < 2; $vuelta++) {
            foreach (['personal', 'principal'] as $cuenta) {
                $this->leer('whatsapp_list_accounts');
                $this->leer('whatsapp_list_chats', ['account' => $cuenta]);
                $this->leer('whatsapp_list_chats', ['account' => $cuenta, 'unread_only' => true]);
                $this->leer('whatsapp_get_messages', ['account' => $cuenta, 'phone' => '+5493412345678']);
            }
        }

        // Nada nuevo en ninguna tabla (incluye lead_message_reads y mcp_whatsapp_confirmations).
        $this->assertSame($foto_antes, $this->foto_de_la_base());
        // El personal quedó byte a byte igual (incluidos `leido_hasta` y `updated_at`).
        $this->assertEquals($chats_antes, DB::table('whatsapp_personal_chats')->orderBy('id')->get()->toArray());
        $this->assertEquals($mensajes_antes, DB::table('whatsapp_personal_messages')->orderBy('id')->get()->toArray());
        $this->assertNull(DB::table('whatsapp_personal_chats')->where('chat_telefono', self::TEL_CLIENTE)->value('leido_hasta'));

        // El lead y el soporte siguen sin leer.
        $this->assertSame(0, DB::table('lead_message_reads')
            ->join('lead_messages', 'lead_messages.id', '=', 'lead_message_reads.lead_message_id')
            ->where('lead_messages.content', 'Prueba Dotin PRINCIPAL 7310')
            ->count());
        $this->assertSame(0, DB::table('support_messages')->where('body', 'Prueba Dotin SOPORTE 5592')->whereNotNull('read_at')->count());

        // Y el "no leído" del personal sigue en 1 después de leer cuatro veces.
        $chats = $this->leer('whatsapp_list_chats', ['account' => 'personal', 'unread_only' => true]);
        $this->assertSame(1, $chats['chats'][0]['no_leidos']);

        // Ni un pedido a Kapso/Meta (nada de recibos de lectura, media ni plantillas).
        $this->assertSame(0, $this->pedidos_http(), 'Leer no puede salir a la red.');
    }
}
