<?php

namespace Tests\Feature\WhatsappPersonal;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CA15 y CA16 — La lectura del número personal: chats, mensajes, marcar leído, media y estado.
 */
class LecturaDelNumeroPersonalTest extends BaseDelNumeroPersonal
{
    /** Base de las rutas. */
    const BASE = '/api/admin/whatsapp-personal';

    /**
     * Principal y personal listos, y un agente.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_principal();
        $this->crear_personal();
        $this->iniciar_agente();
    }

    /**
     * CA15 — `chats` ordena por último mensaje descendente, recorta el último mensaje a 200, cuenta los no
     * leídos (entrantes posteriores a `leido_hasta`, sin history_sync) y respeta `solo_no_leidos` y `limite`.
     *
     * @return void
     */
    public function test_chats_ordenados_con_no_leidos(): void
    {
        // A: tres entrantes, el último hace 10 min, con un texto larguísimo.
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000001', 'sent_at' => now()->subHours(2)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000001', 'sent_at' => now()->subHours(1)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000001', 'sent_at' => now()->subMinutes(10), 'cuerpo' => str_repeat('á', 500)]);
        // B: un entrante hace 5 min y ya leído.
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000002', 'sent_at' => now()->subMinutes(5)]);
        DB::table('whatsapp_personal_chats')->where('chat_telefono', '+5491100000002')->update(['leido_hasta' => now()->subMinutes(1)->toDateTimeString()]);
        // C: sólo historial (history_sync) hace 3 h: no cuenta como no leído.
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000003', 'origen' => 'history_sync', 'sent_at' => now()->subHours(3)]);
        // D: un entrante de hace un día y un saliente nuestro más nuevo.
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000004', 'sent_at' => now()->subHours(26)]);

        $respuesta = $this->como_agente('GET', self::BASE . '/chats')->assertOk();
        $telefonos = array_column($respuesta->json('chats'), 'telefono');

        $this->assertSame(['+5491100000002', '+5491100000001', '+5491100000003', '+5491100000004'], $telefonos);

        $por_telefono = [];
        foreach ($respuesta->json('chats') as $chat) {
            $por_telefono[$chat['telefono']] = $chat;
        }
        $this->assertSame(3, (int) $por_telefono['+5491100000001']['no_leidos']);
        $this->assertSame(0, (int) $por_telefono['+5491100000002']['no_leidos']);
        $this->assertSame(0, (int) $por_telefono['+5491100000003']['no_leidos']);
        $this->assertSame(1, (int) $por_telefono['+5491100000004']['no_leidos']);
        $this->assertLessThanOrEqual(200, mb_strlen($por_telefono['+5491100000001']['ultimo_mensaje']));

        $solo = $this->como_agente('GET', self::BASE . '/chats', ['solo_no_leidos' => 1])->assertOk();
        $this->assertSame(['+5491100000001', '+5491100000004'], array_column($solo->json('chats'), 'telefono'));

        $dos = $this->como_agente('GET', self::BASE . '/chats', ['limite' => 2])->assertOk();
        $this->assertCount(2, $dos->json('chats'));
    }

    /**
     * CA15 — `mensajes` pagina con `antes_de_id`, `desde` y `limite` (tope 100), en orden cronológico y sin
     * payload crudo.
     *
     * @return void
     */
    public function test_mensajes_paginados_desde_y_antes_de(): void
    {
        // Reloj congelado: `desde` se calcula con la misma hora con la que se sembró.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now());

        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            $ids[] = $this->sembrar_mensaje([
                'cuerpo'  => 'mensaje ' . $i,
                'sent_at' => now()->subMinutes(200 - $i),
            ]);
        }
        $uri = self::BASE . '/chats/' . rawurlencode(self::TEL_CLIENTE) . '/mensajes';

        $ultimos = $this->como_agente('GET', $uri, ['limite' => 10])->assertOk();
        $mensajes = $ultimos->json('mensajes');
        $this->assertCount(10, $mensajes);
        $this->assertSame('mensaje 95', $mensajes[0]['cuerpo'], 'Orden cronológico: el más viejo de la página primero.');
        $this->assertSame('mensaje 104', $mensajes[9]['cuerpo']);
        foreach (['payload', 'raw', 'payload_crudo'] as $prohibida) {
            $this->assertArrayNotHasKey($prohibida, $mensajes[0]);
        }

        $anteriores = $this->como_agente('GET', $uri, ['limite' => 10, 'antes_de_id' => $mensajes[0]['id']])->assertOk();
        $this->assertSame('mensaje 85', $anteriores->json('mensajes.0.cuerpo'));
        $this->assertSame('mensaje 94', $anteriores->json('mensajes.9.cuerpo'));

        $tope = $this->como_agente('GET', $uri, ['limite' => 500])->assertOk();
        $this->assertCount(100, $tope->json('mensajes'));

        $desde = now()->subMinutes(200 - 100)->toIso8601String();
        $recientes = $this->como_agente('GET', $uri, ['desde' => $desde, 'limite' => 100])->assertOk();
        $this->assertSame('mensaje 100', $recientes->json('mensajes.0.cuerpo'));
        $this->assertCount(5, $recientes->json('mensajes'));
    }

    /**
     * CA15 🔴 — `leer` sólo mueve `leido_hasta`: NO manda acuse de lectura a Meta (Lucas ve los tildes
     * azules de su propia app).
     *
     * @return void
     */
    public function test_leer_no_manda_acuse_a_meta(): void
    {
        $this->sembrar_mensaje(['sent_at' => now()->subMinutes(20)]);
        $this->sembrar_mensaje(['sent_at' => now()->subMinutes(10)]);

        $uri = self::BASE . '/chats/' . rawurlencode(self::TEL_CLIENTE);

        $this->assertSame(2, (int) $this->como_agente('GET', self::BASE . '/chats')->json('chats.0.no_leidos'));

        $this->como_agente('POST', $uri . '/leer')->assertOk();

        $chat = DB::table('whatsapp_personal_chats')->where('chat_telefono', self::TEL_CLIENTE)->first();
        $this->assertNotNull($chat->leido_hasta);
        $this->assertSame(0, (int) $this->como_agente('GET', self::BASE . '/chats')->json('chats.0.no_leidos'));

        $this->assertSame(0, $this->pedidos_http(), 'Ninguna llamada a Kapso ni a Meta.');
    }

    /**
     * CA15 — `media` baja el binario con la clave y el `phone_number_id` PERSONALES, con `no-store`.
     *
     * @return void
     */
    public function test_media_baja_con_la_clave_personal(): void
    {
        $bytes = str_repeat('B', 500);
        $this->fakear_http(['https://api.kapso.ai/meta/whatsapp/v24.0/MEDIA-XYZ*' => Http::response($bytes, 200)]);
        $id = $this->sembrar_mensaje([
            'tipo' => 'image', 'media_id' => 'MEDIA-XYZ', 'media_mime' => 'image/jpeg', 'media_filename' => 'foto.jpg', 'cuerpo' => null,
        ]);

        $respuesta = $this->como_agente('GET', self::BASE . '/mensajes/' . $id . '/media')->assertOk();

        $this->assertSame($bytes, $respuesta->getContent());
        $this->assertStringContainsString('image/jpeg', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));

        Http::assertSent(function (Request $request) {
            return strpos($request->url(), 'MEDIA-XYZ') !== false
                && strpos($request->url(), 'phone_number_id=' . self::PNI_PERSONAL) !== false
                && $request->hasHeader('X-API-Key', self::CLAVE_KAPSO);
        });
        $this->assertSame(0, count(Http::recorded(function (Request $request) {
            return strpos($request->url(), self::PNI_PRINCIPAL) !== false;
        })), 'Nada con el número principal.');
    }

    /**
     * CA15 — Un adjunto que supera los 16 MB es 413.
     *
     * @return void
     */
    public function test_media_muy_grande_da_413(): void
    {
        $this->fakear_http(['https://api.kapso.ai/meta/whatsapp/v24.0/MEDIA-GRANDE*' => Http::response(str_repeat('G', 16 * 1024 * 1024 + 1), 200)]);
        $id = $this->sembrar_mensaje(['tipo' => 'document', 'media_id' => 'MEDIA-GRANDE', 'media_mime' => 'application/pdf']);

        $this->como_agente('GET', self::BASE . '/mensajes/' . $id . '/media')->assertStatus(413);
    }

    /**
     * CA15 — Un mensaje sin adjunto es 404.
     *
     * @return void
     */
    public function test_media_sin_adjunto_404(): void
    {
        $id = $this->sembrar_mensaje();

        $this->como_agente('GET', self::BASE . '/mensajes/' . $id . '/media')->assertStatus(404);
        $this->assertSame(0, $this->pedidos_http());
    }

    /**
     * CA16 — `estado` informa todo sin la clave ni el secreto, y avisa si hace más de 12 días que no llega
     * ningún evento (la app tiene que abrirse en el teléfono).
     *
     * @return void
     */
    public function test_estado_sin_secretos(): void
    {
        $this->sembrar_mensaje(['sent_at' => now()->subMinutes(5)]);

        $respuesta = $this->como_agente('GET', self::BASE . '/estado')->assertOk();

        $this->assertTrue($respuesta->json('existe'));
        $this->assertTrue($respuesta->json('activo'));
        $this->assertTrue($respuesta->json('listo'));
        $this->assertSame([], $respuesta->json('faltan'));
        $this->assertTrue($respuesta->json('webhook_registrado'));
        $this->assertFalse($respuesta->json('test_mode'));
        $this->assertNotNull($respuesta->json('ultimo_evento_at'));
        $this->assertNull($respuesta->json('aviso_app'));

        $cuerpo = $respuesta->getContent();
        $this->assertStringNotContainsString(self::CLAVE_KAPSO, $cuerpo);
        $this->assertStringNotContainsString(self::SECRETO_PERSONAL, $cuerpo);

        // Hace 13 días que no llega nada.
        $viejo = now()->subDays(13)->toDateTimeString();
        DB::table('whatsapp_personal_messages')->update(['sent_at' => $viejo, 'created_at' => $viejo, 'updated_at' => $viejo]);
        $con_aviso = $this->como_agente('GET', self::BASE . '/estado')->assertOk();
        $this->assertNotEmpty($con_aviso->json('aviso_app'));

        // Sin fila.
        \App\Models\WhatsappConfig::where('phone_number_id', self::PNI_PERSONAL)->delete();
        $sin_fila = $this->como_agente('GET', self::BASE . '/estado')->assertOk();
        $this->assertFalse($sin_fila->json('existe'));
        $this->assertFalse($sin_fila->json('listo'));
        $this->assertNotEmpty($sin_fila->json('faltan'));
    }

    /**
     * CA16 — Con `telefono`, `estado` informa también la ventana de 24 h de ese chat.
     *
     * @return void
     */
    public function test_estado_con_telefono_informa_la_ventana(): void
    {
        $this->sembrar_mensaje(['sent_at' => now()->subHours(2)]);

        $abierta = $this->como_agente('GET', self::BASE . '/estado', ['telefono' => '3412345678'])->assertOk();
        $this->assertTrue($abierta->json('ventana.open'));
        $this->assertNotNull($abierta->json('ventana.last_inbound_at'));
        $this->assertNotNull($abierta->json('ventana.expires_at'));

        $cerrada = $this->como_agente('GET', self::BASE . '/estado', ['telefono' => '3419999999'])->assertOk();
        $this->assertFalse($cerrada->json('ventana.open'));
    }
}
