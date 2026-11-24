<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Models\Client;
use App\Models\Lead;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * CA3 a CA7 — Lo que entra por el webhook al número personal: se guarda en tablas propias y NO toca
 * leads, tickets, asistente, notificaciones, colas ni red.
 */
class EntranteDelNumeroPersonalTest extends BaseDelNumeroPersonal
{
    /**
     * Principal y personal listos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_principal();
        $this->crear_personal();
    }

    /**
     * Filas de mensajes de un wamid.
     *
     * @param string $wamid Id del mensaje.
     *
     * @return \Illuminate\Support\Collection
     */
    private function filas_de(string $wamid)
    {
        return DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', $wamid)->get();
    }

    /**
     * CA3 — Un entrante firmado con el secreto personal se guarda y no toca NINGUNA otra tabla.
     *
     * @return void
     */
    public function test_entrante_se_guarda_y_no_toca_leads_ni_tickets(): void
    {
        $antes = $this->foto_de_la_base();

        $this->postear_al_webhook($this->entrante(self::TEL_CLIENTE_WA, 'Hola, ¿tenés stock?', 'wamid.P1', null, 'Marcela Pérez'))
            ->assertStatus(200);

        $fila = DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', 'wamid.P1')->first();
        $this->assertNotNull($fila);
        $this->assertSame('in', $fila->direccion);
        $this->assertSame('Hola, ¿tenés stock?', $fila->cuerpo);
        $this->assertSame(self::TEL_CLIENTE, $fila->chat_telefono);
        $this->assertSame('Marcela Pérez', $fila->contact_name);
        $this->assertSame(self::PNI_PERSONAL, $fila->phone_number_id);

        $chat = DB::table('whatsapp_personal_chats')->where('chat_telefono', self::TEL_CLIENTE)->first();
        $this->assertNotNull($chat);
        $this->assertNotNull($chat->last_message_at);
        $this->assertNotNull($chat->last_inbound_at);
        $this->assertSame('Marcela Pérez', $chat->contact_name);

        $this->assertSame($antes, $this->foto_de_la_base(), 'Ninguna otra tabla puede cambiar.');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /**
     * CA3 — Aunque el teléfono sea el de un lead o el de un cliente existente, no se abre nada.
     *
     * @return void
     */
    public function test_entrante_de_un_cliente_existente_no_abre_ticket(): void
    {
        $lead    = $this->crear_lead();
        $cliente = $this->crear_cliente(null, ['phone' => '+5493415550202']);
        $antes   = $this->foto_de_la_base();

        $this->postear_al_webhook($this->entrante('5493415550101', 'Soy el lead', 'wamid.P2'))->assertStatus(200);
        $this->postear_al_webhook($this->entrante('5493415550202', 'Soy el cliente, tengo un problema', 'wamid.P3'))->assertStatus(200);

        $this->assertCount(1, $this->filas_de('wamid.P2'));
        $this->assertCount(1, $this->filas_de('wamid.P3'));
        $this->assertSame($antes, $this->foto_de_la_base());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /**
     * CA4 — Un eco (lo que Lucas escribió desde la app) va al chat del `to`, saliente, sin enviar nada.
     *
     * @return void
     */
    public function test_echo_business_app_se_guarda_en_el_chat_del_destinatario(): void
    {
        $antes = $this->foto_de_la_base();

        // Por `sent` y por `received` con direction outbound: la doc no dice cuál transporta el eco.
        $this->postear_al_webhook($this->eco(self::TEL_CLIENTE_WA, 'Ya te lo preparo', 'wamid.E1'))->assertStatus(200);
        $this->postear_al_webhook($this->eco(self::TEL_CLIENTE_WA, 'Pasá cuando quieras', 'wamid.E2', 'business_app', null, 'whatsapp.message.received'))
            ->assertStatus(200);

        foreach (['wamid.E1', 'wamid.E2'] as $wamid) {
            $fila = DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', $wamid)->first();
            $this->assertNotNull($fila, $wamid);
            $this->assertSame('out', $fila->direccion);
            $this->assertSame('business_app', $fila->origen);
            $this->assertSame(self::TEL_CLIENTE, $fila->chat_telefono, 'El chat es el del destinatario, no el del negocio.');
        }
        $this->assertSame(0, DB::table('whatsapp_personal_chats')->where('chat_telefono', '+5493444622139')->count());

        $this->assertSame($antes, $this->foto_de_la_base());
        Http::assertNothingSent();
    }

    /**
     * CA4 — Un `history_sync` viejo se guarda marcado, no abre ventana ni cuenta como entrante reciente.
     *
     * @return void
     */
    public function test_history_sync_no_suma_no_leidos(): void
    {
        $hace_dos_dias = time() - 48 * 3600;
        $payload       = $this->entrante(self::TEL_CLIENTE_WA, 'Mensaje viejo del historial', 'wamid.H1', $hace_dos_dias);
        $payload['message']['kapso']['origin'] = 'history_sync';

        $this->postear_al_webhook($payload)->assertStatus(200);

        $fila = DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', 'wamid.H1')->first();
        $this->assertNotNull($fila);
        $this->assertSame('history_sync', $fila->origen);

        $this->assertFalse(
            (new WhatsappSessionWindowService())->window_state(self::TEL_CLIENTE, 'personal')['open'],
            'Un historial de hace 48 h no abre la ventana.'
        );

        // Y por la lectura: ese chat no tiene no leídos.
        $respuesta = $this->como_agente('GET', '/api/admin/whatsapp-personal/chats')->assertOk();
        $this->assertSame(0, (int) $respuesta->json('chats.0.no_leidos'));
    }

    /**
     * CA5 — El mismo wamid dos veces deja una sola fila.
     *
     * @return void
     */
    public function test_mismo_wamid_dos_veces_deja_una_fila(): void
    {
        $payload = $this->entrante(self::TEL_CLIENTE_WA, 'Repetido', 'wamid.D1');

        $this->postear_al_webhook($payload)->assertStatus(200);
        $this->postear_al_webhook($payload)->assertStatus(200);

        $this->assertCount(1, $this->filas_de('wamid.D1'));
    }

    /**
     * CA5 — `delivered` y `read` suben el estado; uno posterior no lo baja; `failed` guarda el motivo
     * pero no pisa un entregado/leído.
     *
     * @return void
     */
    public function test_estados_suben_y_no_bajan(): void
    {
        $this->postear_al_webhook($this->eco(self::TEL_CLIENTE_WA, 'Hola', 'wamid.S1', 'cloud_api'))->assertStatus(200);
        $this->assertSame('enviado', $this->filas_de('wamid.S1')->first()->estado);

        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.delivered', 'wamid.S1'))->assertStatus(200);
        $this->assertSame('entregado', $this->filas_de('wamid.S1')->first()->estado);

        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.read', 'wamid.S1'))->assertStatus(200);
        $this->assertSame('leido', $this->filas_de('wamid.S1')->first()->estado);

        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.delivered', 'wamid.S1'))->assertStatus(200);
        $this->assertSame('leido', $this->filas_de('wamid.S1')->first()->estado, 'Un delivered tardío no baja un leído.');

        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.failed', 'wamid.S1'))->assertStatus(200);
        $this->assertSame('leido', $this->filas_de('wamid.S1')->first()->estado, 'Un failed no pisa un leído.');

        // Un saliente que falla sin haber sido entregado guarda el motivo.
        $this->postear_al_webhook($this->eco(self::TEL_CLIENTE_WA, 'Otro', 'wamid.S2', 'cloud_api'))->assertStatus(200);
        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.failed', 'wamid.S2', [
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]))->assertStatus(200);
        $fila = $this->filas_de('wamid.S2')->first();
        $this->assertSame('fallido', $fila->estado);
        $this->assertStringContainsString('Message undeliverable', (string) $fila->estado_error);
    }

    /**
     * CA5 — Un status de un wamid que no conocemos responde 200 y no crea nada.
     *
     * @return void
     */
    public function test_status_de_wamid_desconocido_no_falla(): void
    {
        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.delivered', 'wamid.NOEXISTE'))->assertStatus(200);
        $this->postear_al_webhook($this->estado_de_entrega('whatsapp.message.read', 'wamid.NOEXISTE'))->assertStatus(200);

        $this->assertSame(0, DB::table('whatsapp_personal_messages')->count());
    }

    /**
     * CA6 — Firma inválida: 401 y no se guarda nada.
     *
     * @return void
     */
    public function test_firma_invalida_da_401(): void
    {
        $this->postear_al_webhook($this->entrante(self::TEL_CLIENTE_WA, 'Intruso', 'wamid.X1'), 'secreto-equivocado')
            ->assertStatus(401);
        // La firma del principal tampoco vale en el personal.
        $this->postear_al_webhook($this->entrante(self::TEL_CLIENTE_WA, 'Intruso', 'wamid.X2'), self::SECRETO_PRINCIPAL)
            ->assertStatus(401);

        $this->assertSame(0, DB::table('whatsapp_personal_messages')->count());
    }

    /**
     * CA6 🔴 — Fila personal INACTIVA o sin secreto: el payload se ignora con 200 y NO cae al principal
     * (no se crea un lead por cada chat de la familia de Lucas).
     *
     * @return void
     */
    public function test_fila_personal_inactiva_no_cae_al_principal(): void
    {
        $leads_antes = Lead::count();

        foreach ([['is_active' => false], ['webhook_secret' => '']] as $i => $cambio) {
            \App\Models\WhatsappConfig::where('phone_number_id', self::PNI_PERSONAL)
                ->update(['is_active' => true, 'webhook_secret' => self::SECRETO_PERSONAL]);
            \App\Models\WhatsappConfig::where('phone_number_id', self::PNI_PERSONAL)->update($cambio);

            $respuesta = $this->postear_al_webhook(
                $this->entrante('5493415558888', 'Hola mamá, ¿venís a cenar?', 'wamid.INA' . $i),
                self::SECRETO_PERSONAL
            );

            $respuesta->assertStatus(200);
            $this->assertTrue((bool) $respuesta->json('ignorado'), 'Responde ignorado:true.');
            $this->assertSame($leads_antes, Lead::count(), 'No se crea ningún lead.');
            $this->assertSame(0, DB::table('whatsapp_personal_messages')->count(), 'Y tampoco se guarda.');
        }
    }

    /**
     * CA7 — Payloads raros: 200 y sin excepción, y no se guarda nada.
     *
     * @return void
     */
    public function test_payloads_raros_se_ignoran_con_200(): void
    {
        $sin_id = $this->entrante(self::TEL_CLIENTE_WA, 'Sin id', 'x');
        unset($sin_id['message']['id']);

        $sin_contraparte = $this->entrante('', 'Sin remitente', 'wamid.R2');
        unset($sin_contraparte['message']['from'], $sin_contraparte['conversation']['phone_number']);

        $grupo  = $this->entrante('120363025246125486@g.us', 'Mensaje de grupo', 'wamid.R3');
        $corto  = $this->entrante('1234567', 'Número corto', 'wamid.R4');
        $thread = ['event' => 'whatsapp.thread.standby', 'phone_number_id' => self::PNI_PERSONAL, 'thread' => ['id' => 'abc']];

        foreach ([$sin_id, $sin_contraparte, $grupo, $corto, $thread] as $payload) {
            $this->postear_al_webhook($payload)->assertStatus(200);
        }
        $this->assertSame(0, DB::table('whatsapp_personal_messages')->count());

        // Tipo desconocido con id válido: se guarda como `otro`, sin cuerpo.
        $raro = $this->entrante(self::TEL_CLIENTE_WA, '', 'wamid.R6');
        $raro['message']['type'] = 'tipo_que_inventa_meta';
        unset($raro['message']['text']);
        $this->postear_al_webhook($raro)->assertStatus(200);

        $fila = $this->filas_de('wamid.R6')->first();
        $this->assertNotNull($fila);
        $this->assertSame('otro', $fila->tipo);
        $this->assertTrue($fila->cuerpo === null || $fila->cuerpo === '');
    }

    /**
     * CA7 — Imagen, audio y documento guardan los metadatos de media y NO bajan el binario.
     *
     * @return void
     */
    public function test_media_guarda_metadatos_sin_binario(): void
    {
        $imagen = $this->entrante(self::TEL_CLIENTE_WA, '', 'wamid.M1');
        $imagen['message']['type']  = 'image';
        $imagen['message']['image'] = ['id' => 'MEDIA-IMG-1', 'mime_type' => 'image/jpeg', 'caption' => 'Mirá esta pieza'];
        unset($imagen['message']['text']);
        $imagen['message']['kapso']['has_media'] = true;

        $audio = $this->entrante(self::TEL_CLIENTE_WA, '', 'wamid.M2');
        $audio['message']['type']  = 'audio';
        $audio['message']['audio'] = ['id' => 'MEDIA-AUD-1', 'mime_type' => 'audio/ogg; codecs=opus'];
        unset($audio['message']['text']);

        $documento = $this->entrante(self::TEL_CLIENTE_WA, '', 'wamid.M3');
        $documento['message']['type']     = 'document';
        $documento['message']['document'] = ['id' => 'MEDIA-DOC-1', 'mime_type' => 'application/pdf', 'filename' => 'lista.pdf'];
        unset($documento['message']['text']);

        foreach ([$imagen, $audio, $documento] as $payload) {
            $this->postear_al_webhook($payload)->assertStatus(200);
        }

        $fila_imagen = $this->filas_de('wamid.M1')->first();
        $this->assertSame('image', $fila_imagen->tipo);
        $this->assertSame('MEDIA-IMG-1', $fila_imagen->media_id);
        $this->assertSame('image/jpeg', $fila_imagen->media_mime);
        $this->assertSame('Mirá esta pieza', $fila_imagen->cuerpo);

        $fila_audio = $this->filas_de('wamid.M2')->first();
        $this->assertSame('MEDIA-AUD-1', $fila_audio->media_id);

        $fila_doc = $this->filas_de('wamid.M3')->first();
        $this->assertSame('MEDIA-DOC-1', $fila_doc->media_id);
        $this->assertSame('lista.pdf', $fila_doc->media_filename);

        Http::assertNothingSent();
    }
}
