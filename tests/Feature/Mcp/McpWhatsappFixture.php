<?php

namespace Tests\Feature\Mcp;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Herramientas compartidas por los tests de acceso directo a WhatsApp desde Dotin/ChatGPT (misión
 * dotin-whatsapp-admin, 26/11/2026).
 *
 * Se usa junto a {@see McpFixture} (OAuth y JSON-RPC del conector) y sobre
 * `Tests\Feature\WhatsappPersonal\BaseDelNumeroPersonal` (números, Kapso falso, mensajes del personal), que
 * ninguno de los dos se edita. Agrega lo propio de los tres recursos del conector:
 *
 *   - `consulta`         -> `/api/mcp`                (scope `comerciocity.read`, ya existía);
 *   - `whatsapp_lectura` -> `/api/mcp/whatsapp`       (scope `whatsapp.read`);
 *   - `whatsapp_envio`   -> `/api/mcp/whatsapp-envio` (scope `whatsapp.send`).
 *
 * Reglas de todos los tests que lo usan:
 *   1. Nada sale a la red: `Http::fake` (viene de la base) y el Kapso falso del envío se arma con
 *      {@see kapso_que_acepta()}.
 *   2. Los textos de prueba son sintéticos y ninguno es de un cliente real.
 *   3. Todo corre dentro de la transacción del test.
 */
trait McpWhatsappFixture
{
    /** Recurso (audiencia) del endpoint de lectura de WhatsApp en los tests. */
    private static $recurso_whatsapp_lectura = 'https://api-admin.test/api/mcp/whatsapp';

    /** Recurso (audiencia) del endpoint de envío de WhatsApp en los tests. */
    private static $recurso_whatsapp_envio = 'https://api-admin.test/api/mcp/whatsapp-envio';

    /** Ruta del endpoint de lectura. */
    private static $ruta_whatsapp_lectura = '/api/mcp/whatsapp';

    /** Ruta del endpoint de envío. */
    private static $ruta_whatsapp_envio = '/api/mcp/whatsapp-envio';

    /** Texto que dejó el cliente en el chat personal. */
    private static $texto_personal = 'Prueba Dotin PERSONAL 4821';

    /** Texto que dejó el lead en el número principal. */
    private static $texto_principal = 'Prueba Dotin PRINCIPAL 7310';

    /** Texto que dejó el cliente en el soporte por WhatsApp. */
    private static $texto_soporte = 'Prueba Dotin SOPORTE 5592';

    /** Texto del envío de prueba. */
    private static $texto_de_envio = 'Hola, te confirmo el pedido';

    /** Pedido literal de Lucas del envío de prueba. */
    private static $pedido_de_lucas = 'mandale a Juan que le confirmo el pedido';

    /** `op` del envío de prueba. */
    private static $op_de_prueba = 'dotin-prueba-0001';

    /** @var Admin|null Admin autorizado a conectar WhatsApp (está en `mcp.whatsapp_admin_emails`). */
    private $admin_de_whatsapp = null;

    /** @var array<int, array<string, mixed>> Entradas de log capturadas por {@see capturar_logs()}. */
    private $entradas_de_log = [];

    // ---------------------------------------------------------------------------------------------
    // Configuración y escena
    // ---------------------------------------------------------------------------------------------

    /**
     * Fija la configuración del conector (la de consulta + la de WhatsApp) y deja un admin autorizado.
     *
     * Se fija todo `mcp.whatsapp_*` a mano (en vez de confiar en los defaults) salvo en el test del CA1, que
     * justamente mide el default sin llamar a esto.
     *
     * @param bool $prendido Valor de `mcp.whatsapp_enabled`.
     *
     * @return void
     */
    private function preparar_whatsapp_mcp($prendido = true)
    {
        $this->preparar_mcp();

        $this->admin_de_whatsapp = $this->admin_de_mcp();

        config([
            'mcp.whatsapp_enabled'        => (bool) $prendido,
            'mcp.whatsapp_read_resource'  => self::$recurso_whatsapp_lectura,
            'mcp.whatsapp_send_resource'  => self::$recurso_whatsapp_envio,
            'mcp.whatsapp_admin_emails'   => $this->admin_de_whatsapp->email,
            'mcp.whatsapp_confirm_ttl_seg' => 600,
            'mcp.whatsapp_cuentas_lectura' => ['personal', 'principal'],
            'mcp.whatsapp_cuentas_envio'  => ['personal'],
        ]);
    }

    /**
     * Un segundo admin humano, también autorizado a conectar WhatsApp (se suma a la lista de la config).
     *
     * @return Admin
     */
    private function otro_admin_de_whatsapp()
    {
        $otro = $this->admin_de_mcp();
        config(['mcp.whatsapp_admin_emails' => $this->admin_de_whatsapp->email . ',' . $otro->email]);

        return $otro;
    }

    /**
     * Deja las cuentas listas (personal, principal y una del asistente que NO debe exponerse) y el mensaje
     * de prueba del chat personal, de hace 30 minutos.
     *
     * @return void
     */
    private function sembrar_cuentas_y_chat_personal()
    {
        $this->crear_personal();
        $this->crear_principal();

        $asistente = new WhatsappConfig();
        $asistente->forceFill([
            'proposito'       => 'asistente',
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'phone_number_id' => '5550000111222',
            'waba_id'         => self::WABA_ID,
            'webhook_secret'  => 'secreto-del-asistente-de-prueba',
            'is_active'       => true,
            'test_mode'       => false,
        ]);
        $asistente->save();

        $this->sembrar_mensaje([
            'chat_telefono' => self::TEL_CLIENTE,
            'direccion'     => 'in',
            'cuerpo'        => self::$texto_personal,
            'sent_at'       => now()->subMinutes(30),
            'contact_name'  => 'Juan de prueba',
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Siembra del número principal
    // ---------------------------------------------------------------------------------------------

    /**
     * Un lead con ese teléfono y un mensaje suyo entrante (sender `lead`) de hace 20 minutos.
     *
     * @param string $telefono Teléfono del lead.
     * @param string $texto    Cuerpo del mensaje.
     *
     * @return Lead
     */
    private function sembrar_lead_con_mensaje($telefono, $texto)
    {
        $lead = Lead::create([
            'uuid'         => (string) Str::uuid(),
            'contact_name' => 'Lead de prueba Dotin',
            'phone'        => $telefono,
        ]);

        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'lead',
            'content'             => $texto,
            'status'              => 'enviado',
            'whatsapp_message_id' => 'wamid.LEADDOTIN' . random_int(100000, 999999),
            'sent_at'             => now()->subMinutes(20),
        ]);

        return $lead;
    }

    /**
     * Un ticket de soporte por WhatsApp de ese teléfono con un mensaje del cliente (sender_type `user`)
     * sin leer, de hace 10 minutos.
     *
     * @param string $telefono Teléfono del cliente (formato WhatsApp, sin `+`).
     * @param string $texto    Cuerpo del mensaje.
     *
     * @return SupportTicket
     */
    private function sembrar_ticket_whatsapp($telefono, $texto)
    {
        $cliente = Client::create(['name' => 'Cliente de soporte Dotin', 'phone' => '+5493410000099', 'is_active' => true]);

        $ticket = SupportTicket::create([
            'client_id'        => $cliente->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Cliente',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => $telefono,
            'opened_at'        => now()->subMinutes(15),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'body'              => $texto,
            'created_at'        => now()->subMinutes(10),
        ]);

        return $ticket;
    }

    // ---------------------------------------------------------------------------------------------
    // Tokens y pedidos al conector
    // ---------------------------------------------------------------------------------------------

    /**
     * El flujo completo (registro, consentimiento, canje) para un perfil de WhatsApp.
     *
     * @param Admin                $admin   Admin que autoriza.
     * @param string               $perfil  `lectura` o `envio`.
     * @param array<string, mixed> $cambios Cambios sobre el pedido de autorización (un valor null saca el
     *                                      parámetro, p. ej. `['resource' => null]`).
     *
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    private function token_whatsapp(Admin $admin, $perfil, array $cambios = [])
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list($verifier, $challenge) = $this->par_pkce();

        $propios = $perfil === 'envio'
            ? ['resource' => self::$recurso_whatsapp_envio, 'scope' => 'whatsapp.send']
            : ['resource' => self::$recurso_whatsapp_lectura, 'scope' => 'whatsapp.read'];

        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge, array_merge($propios, $cambios)),
            $admin->email,
            self::$clave_del_admin
        );
        $respuesta->assertStatus(302);
        $consulta = $this->consulta_del_redirect($respuesta);
        $this->assertArrayHasKey('code', $consulta, 'El consentimiento de ' . $perfil . ' tendría que emitir un código: ' . json_encode($consulta));

        $canje = $this->canjear_codigo($client_id, (string) $consulta['code'], $verifier);
        $canje->assertStatus(200);

        return [
            'access_token'  => (string) $canje->json('access_token'),
            'refresh_token' => (string) $canje->json('refresh_token'),
            'client_id'     => $client_id,
        ];
    }

    /**
     * Un POST JSON-RPC a la ruta que se indica (lectura, envío o consulta), con o sin bearer.
     *
     * @param string      $ruta         Ruta del endpoint.
     * @param string|null $access_token Token (null = sin Authorization).
     * @param mixed       $cuerpo       Mensaje JSON-RPC.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function mcp_post_a($ruta, $access_token, $cuerpo)
    {
        $encabezados = ['Accept' => 'application/json, text/event-stream'];
        if ($access_token !== null) {
            $encabezados['Authorization'] = 'Bearer ' . $access_token;
        }

        return $this->postJson($ruta, $cuerpo, $encabezados);
    }

    /**
     * Llama una herramienta en el endpoint indicado y devuelve el `result` (afirmando HTTP 200 y sin error
     * JSON-RPC). Un `isError` de la herramienta NO falla acá: lo revisa cada test.
     *
     * @param string               $ruta         Ruta del endpoint.
     * @param string               $access_token Token.
     * @param string               $nombre       Herramienta.
     * @param array<string, mixed> $argumentos   Argumentos.
     *
     * @return array<string, mixed>
     */
    private function herramienta_en($ruta, $access_token, $nombre, array $argumentos = [])
    {
        $argumentos = $argumentos === [] ? new \stdClass() : $argumentos;
        $respuesta  = $this->mcp_post_a($ruta, $access_token, $this->rpc('tools/call', ['name' => $nombre, 'arguments' => $argumentos]));
        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('error'), 'tools/call ' . $nombre . ' no debería dar error JSON-RPC: ' . $respuesta->getContent());

        return (array) $respuesta->json('result');
    }

    /**
     * El `structuredContent` de una herramienta que tiene que salir bien (afirma que no es `isError`).
     *
     * @param string               $ruta         Ruta del endpoint.
     * @param string               $access_token Token.
     * @param string               $nombre       Herramienta.
     * @param array<string, mixed> $argumentos   Argumentos.
     *
     * @return array<string, mixed>
     */
    private function datos_en($ruta, $access_token, $nombre, array $argumentos = [])
    {
        $resultado = $this->herramienta_en($ruta, $access_token, $nombre, $argumentos);
        $this->assertNotTrue($resultado['isError'] ?? false, $nombre . ' dio isError: ' . json_encode($resultado));
        $this->assertArrayHasKey('structuredContent', $resultado, $nombre . ' sin structuredContent: ' . json_encode($resultado));

        return (array) $resultado['structuredContent'];
    }

    /**
     * Afirma que un resultado de herramienta es un error y que nombra el código esperado (en el texto o en
     * el contenido estructurado: el plan fija el código, no el lugar exacto donde viaja).
     *
     * @param array<string, mixed> $resultado Resultado de {@see herramienta_en()}.
     * @param string               $codigo    Código de error esperado (p. ej. `codigo_ya_usado`).
     *
     * @return void
     */
    private function assert_error_con(array $resultado, $codigo)
    {
        $this->assertTrue($resultado['isError'] ?? false, 'Se esperaba isError con ' . $codigo . ': ' . json_encode($resultado));
        $this->assertStringContainsString($codigo, json_encode($resultado, JSON_UNESCAPED_UNICODE), 'El error no nombra ' . $codigo);
    }

    /**
     * Las herramientas que anuncia un endpoint, indexadas por nombre.
     *
     * @param string $ruta         Ruta del endpoint.
     * @param string $access_token Token.
     *
     * @return array<string, array<string, mixed>>
     */
    private function herramientas_de($ruta, $access_token)
    {
        $respuesta = $this->mcp_post_a($ruta, $access_token, $this->rpc('tools/list'));
        $respuesta->assertStatus(200);

        $por_nombre = [];
        foreach ((array) $respuesta->json('result.tools') as $herramienta) {
            $por_nombre[(string) $herramienta['name']] = $herramienta;
        }

        return $por_nombre;
    }

    /**
     * Los nombres de las herramientas que anuncia un endpoint, ordenados.
     *
     * @param string $ruta         Ruta del endpoint.
     * @param string $access_token Token.
     *
     * @return array<int, string>
     */
    private function nombres_de_herramientas($ruta, $access_token)
    {
        $nombres = array_keys($this->herramientas_de($ruta, $access_token));
        sort($nombres);

        return $nombres;
    }

    // ---------------------------------------------------------------------------------------------
    // Envío: Kapso falso y pedidos
    // ---------------------------------------------------------------------------------------------

    /**
     * Deja un Kapso falso que acepta el envío del número personal y devuelve `wamid.DOTIN1`. Descarta lo
     * registrado antes (cuenta de cero los pedidos HTTP).
     *
     * @return void
     */
    private function kapso_que_acepta()
    {
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => Http::response(['messages' => [['id' => 'wamid.DOTIN1']]], 200),
        ]);
    }

    /**
     * Cuántos POST salieron hacia el envío del número personal.
     *
     * @return int
     */
    private function posts_de_envio()
    {
        $url = $this->url_de_envio(self::PNI_PERSONAL);

        return count(Http::recorded(function (Request $pedido) use ($url) {
            return $pedido->method() === 'POST' && $pedido->url() === $url;
        }));
    }

    /**
     * El cuerpo del envío de prueba (preparación y confirmación comparten estos datos).
     *
     * @param array<string, mixed> $cambios Cambios (un valor null saca la clave).
     *
     * @return array<string, mixed>
     */
    private function envio_de_prueba(array $cambios = [])
    {
        $base = [
            'account'               => 'personal',
            'phone'                 => '3412345678',
            'text'                  => self::$texto_de_envio,
            'lucas_request_literal' => self::$pedido_de_lucas,
            'op'                    => self::$op_de_prueba,
        ];

        return array_filter(array_merge($base, $cambios), function ($valor) {
            return $valor !== null;
        });
    }

    /**
     * Los argumentos de `whatsapp_confirm_send` a partir de los de la preparación y el código emitido (los
     * mismos datos, menos el literal de Lucas, que no se repite).
     *
     * @param array<string, mixed> $preparacion Argumentos de la preparación.
     * @param string               $codigo      Código de confirmación.
     *
     * @return array<string, mixed>
     */
    private function confirmacion_de($preparacion, $codigo)
    {
        unset($preparacion['lucas_request_literal']);

        return array_merge($preparacion, ['confirmation_code' => $codigo]);
    }

    /**
     * Prepara un envío con el token de envío y devuelve el código emitido (afirma que salió bien).
     *
     * @param string               $access_token Token de envío.
     * @param array<string, mixed> $cambios      Cambios sobre {@see envio_de_prueba()}.
     *
     * @return string
     */
    private function preparar_y_obtener_codigo($access_token, array $cambios = [])
    {
        $datos = $this->datos_en(self::$ruta_whatsapp_envio, $access_token, 'whatsapp_prepare_send', $this->envio_de_prueba($cambios));
        $this->assertArrayHasKey('confirmation_code', $datos);

        return (string) $datos['confirmation_code'];
    }

    // ---------------------------------------------------------------------------------------------
    // Logs
    // ---------------------------------------------------------------------------------------------

    /**
     * Empieza a capturar todo lo que se loguea (mensaje y contexto) desde ahora.
     *
     * @return void
     */
    private function capturar_logs()
    {
        $this->entradas_de_log = [];
        $entradas = &$this->entradas_de_log;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$entradas) {
            $entradas[] = ['message' => (string) $evento->message, 'context' => $evento->context];
        });
    }

    /**
     * Todo lo capturado, como un solo texto (mensajes y contextos en JSON) para buscar adentro.
     *
     * @return string
     */
    private function logs_capturados()
    {
        return json_encode($this->entradas_de_log, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
