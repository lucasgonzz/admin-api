<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * CA17 y CA18 — Quién puede llegar al número personal (sólo un agente) y qué queda en la auditoría del
 * guard del agente (nunca el texto de los chats ni el teléfono completo).
 */
class PrivacidadDelNumeroPersonalTest extends BaseDelNumeroPersonal
{
    /** Base de las rutas. */
    const BASE = '/api/admin/whatsapp-personal';

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
     * Las siete rutas, con un teléfono y un id de ejemplo.
     *
     * @return array<int, array<int, string>>
     */
    private function las_siete_rutas(): array
    {
        $tel = rawurlencode(self::TEL_CLIENTE);

        return [
            ['GET', self::BASE . '/estado'],
            ['GET', self::BASE . '/plantillas'],
            ['GET', self::BASE . '/chats'],
            ['GET', self::BASE . '/chats/' . $tel . '/mensajes'],
            ['POST', self::BASE . '/chats/' . $tel . '/leer'],
            ['GET', self::BASE . '/mensajes/1/media'],
            ['POST', self::BASE . '/enviar'],
        ];
    }

    /**
     * CA17 — Sin token 401; humano, `solo_leads` y admin sin el flag de agente reciben 404 (no se admite que
     * la ruta existe); sólo un agente con `es_agente = true` entra.
     *
     * @return void
     */
    public function test_rutas_404_para_no_agentes(): void
    {
        $humano = $this->crear_humano();
        $solo   = $this->crear_humano('Vendedor');
        $solo->forceFill(['solo_leads' => true])->save();
        $ex_agente = $this->crear_agente('Ex agente');
        $ex_agente->forceFill(['es_agente' => false])->save();

        // El agente SÍ entra (esto es lo que falla mientras las rutas no existan).
        $agente = $this->crear_agente();
        $this->pedir_como($this->token_de($agente), 'GET', self::BASE . '/estado')->assertOk();
        $this->pedir_como($this->token_de($agente), 'GET', self::BASE . '/chats')->assertOk();

        foreach ($this->las_siete_rutas() as $ruta) {
            list($metodo, $uri) = $ruta;

            $this->pedir_como(null, $metodo, $uri)->assertStatus(401);

            foreach ([$humano, $solo, $ex_agente] as $admin) {
                $this->pedir_como($this->token_de($admin), $metodo, $uri)->assertStatus(404);
            }
        }

        $this->assertSame(0, $this->pedidos_http());
    }

    /**
     * CA17 — `whatsapp-config` sigue devolviendo sólo principal y asistente: el panel no ve ni edita el
     * personal.
     *
     * @return void
     */
    public function test_whatsapp_config_no_expone_el_personal(): void
    {
        $this->assertSame('personal', WhatsappConfig::PROPOSITO_PERSONAL);

        $humano = $this->crear_humano();
        $token  = $this->token_de($humano);

        $respuesta = $this->pedir_como($token, 'GET', '/api/admin/whatsapp-config')->assertOk();

        $claves = array_keys($respuesta->json());
        sort($claves);
        $this->assertSame(['asistente', 'estado', 'principal'], $claves);
        $this->assertSame(self::PNI_PRINCIPAL, $respuesta->json('principal.phone_number_id'));
        $this->assertNull($respuesta->json('asistente'));
        $this->assertStringNotContainsString(self::PNI_PERSONAL, $respuesta->getContent());
        $this->assertStringNotContainsString(self::SECRETO_PERSONAL, $respuesta->getContent());

        // Intentar escribir el propósito personal desde el panel no es válido.
        $this->pedir_como($token, 'PUT', '/api/admin/whatsapp-config', [
            'proposito' => 'personal', 'kapso_api_key' => 'otra', 'phone_number_id' => '777', 'display_phone' => '+5493444000000',
        ])->assertStatus(422);

        $fila = WhatsappConfig::where('proposito', 'personal')->first();
        $this->assertSame(self::PNI_PERSONAL, $fila->phone_number_id);
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
    }

    /**
     * CA17 — Las siete rutas existen únicamente bajo `api/admin/whatsapp-personal/`, con los middlewares de
     * autenticación, perfil y agente; no hay nada del personal bajo `claude/*`.
     *
     * @return void
     */
    public function test_no_hay_rutas_claude_del_personal(): void
    {
        $del_personal = [];

        foreach (Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (stripos($uri, 'whatsapp-personal') === false && stripos($uri, 'whatsapp_personal') === false) {
                continue;
            }

            $this->assertStringStartsWith('api/admin/whatsapp-personal/', $uri);
            $this->assertStringNotContainsString('claude', $uri);
            $del_personal[] = $uri;

            $middlewares = $ruta->gatherMiddleware();
            foreach (['auth:sanctum', 'admin.solo_leads', 'admin.solo_agente'] as $requerido) {
                $this->assertContains($requerido, $middlewares, $uri . ' tiene que pasar por ' . $requerido);
            }
        }

        $this->assertCount(7, $del_personal, 'Siete rutas, ni una más.');
    }

    /**
     * CA18 🔴 — Tras leer un chat y enviar, ninguna fila de auditoría del agente guarda el texto de los
     * mensajes, el teléfono completo ni el cuerpo de la respuesta; sí queda método, ruta, estado y
     * `[omitido: chat privado]`.
     *
     * @return void
     */
    public function test_la_auditoria_no_guarda_contenido(): void
    {
        $this->iniciar_agente();
        $this->sembrar_mensaje(['cuerpo' => 'TEXTO-SECRETO-ENTRANTE-ABC', 'sent_at' => now()->subMinutes(10)]);
        $this->fakear_http([$this->url_de_envio(self::PNI_PERSONAL) => Http::response(['messages' => [['id' => 'wamid.AUD1']]], 200)]);

        $this->como_agente('GET', self::BASE . '/chats/' . rawurlencode(self::TEL_CLIENTE) . '/mensajes')->assertOk();
        $this->como_agente('GET', self::BASE . '/chats')->assertOk();
        $this->enviar($this->cuerpo_de_envio(['texto' => 'TEXTO-SECRETO-SALIENTE-XYZ']))->assertOk();

        $filas = $this->auditoria_de($this->agente);
        $this->assertGreaterThanOrEqual(4, $filas->count(), 'Dos GET y el POST con su 428.');

        $todo = '';
        foreach ($filas as $fila) {
            $todo .= json_encode((array) $fila) . "\n";
        }

        foreach (['TEXTO-SECRETO-ENTRANTE-ABC', 'TEXTO-SECRETO-SALIENTE-XYZ', '3412345678', '5493412345678', 'wamid.AUD1'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $todo, $prohibido . ' no puede quedar en la auditoría.');
        }
        $this->assertStringContainsString('[omitido: chat privado]', $todo);

        // Lo que sí queda: quién, qué ruta y cómo terminó.
        $envio = $filas->filter(function ($fila) {
            return $fila->method === 'POST' && strpos((string) $fila->route_uri, 'whatsapp-personal/enviar') !== false;
        })->last();
        $this->assertNotNull($envio);
        $this->assertSame(200, (int) $envio->status_code);
    }

    /**
     * CA18 — `POST enviar` está frenado como `terceros` (428 sin código de confirmación) y las lecturas son
     * `lectura`.
     *
     * @return void
     */
    public function test_enviar_esta_frenado_como_terceros(): void
    {
        $this->iniciar_agente();
        $this->sembrar_mensaje(['sent_at' => now()->subMinutes(10)]);

        $respuesta = $this->como_agente('POST', self::BASE . '/enviar', $this->cuerpo_de_envio());

        $respuesta->assertStatus(428);
        $this->assertSame('terceros', $respuesta->json('categoria'));
        $this->assertSame(0, $this->pedidos_http(), 'Sin código no sale nada.');

        $this->como_agente('GET', self::BASE . '/estado')->assertOk();
        $this->como_agente('GET', self::BASE . '/chats')->assertOk();

        $categorias = DB::table('panel_agent_audit_logs')->where('admin_id', $this->agente->id)->orderBy('id')->pluck('category')->all();
        $this->assertSame(['terceros', 'lectura', 'lectura'], $categorias);
    }
}
