<?php

namespace Tests\Feature\Mcp;

use App\Models\Admin;
use App\Services\Agenda\AgendaService;
use Carbon\Carbon;
use Tests\Feature\Local\ReporteV2Fixture;

/**
 * Herramientas compartidas por los tests del conector MCP de sólo lectura (misión dots-openclaw, 26/11/2026).
 *
 * Arma lo que los siete archivos de tests necesitan: la configuración de `config/mcp.php` (se fija entera acá para
 * que los tests no dependan de defaults ni del `.env`), un admin con contraseña conocida, el registro de un cliente
 * OAuth, el par PKCE, el pedido de autorización, el canje del código, un helper de punta a punta que devuelve un
 * token, las llamadas JSON-RPC a `POST /api/mcp` y el reporte v2 del CA6 (reutiliza `ReporteV2Fixture` sin
 * modificarlo).
 *
 * Todo corre adentro de la transacción del test (`DatabaseTransactions` en cada clase): nada queda escrito.
 */
trait McpFixture
{
    use ReporteV2Fixture;

    /** Issuer del servidor de autorización en los tests (sin path). */
    private static $issuer_de_prueba = 'https://api-admin.test';

    /** Recurso protegido (el endpoint MCP) en los tests. */
    private static $recurso_de_prueba = 'https://api-admin.test/api/mcp';

    /** Redirect que registra ChatGPT. */
    private static $redirect_de_chatgpt = 'https://chatgpt.com/connector_platform_oauth_redirect';

    /** Contraseña conocida de los admins de prueba. */
    private static $clave_del_admin = 'clave-segura-mcp-1';

    /** Pedido vinculado que usa el fixture del CA6. */
    private static $pedido_de_prueba = '20261126-0930-precios';

    /**
     * Fija la configuración del conector y limpia lo que es del reporte v2. Se llama desde `setUp()`.
     *
     * @return void
     */
    private function preparar_mcp()
    {
        config([
            'mcp.enabled'         => true,
            'mcp.issuer'          => self::$issuer_de_prueba,
            'mcp.resource'        => self::$recurso_de_prueba,
            'mcp.redirect_hosts'  => ['chatgpt.com'],
            'mcp.admin_emails'    => '',
            'mcp.scope'           => 'comerciocity.read',
            'mcp.ttl_code_seg'    => 300,
            'mcp.ttl_access_seg'  => 3600,
            'mcp.ttl_refresh_dias' => 30,
            'mcp.max_body_bytes'  => 262144,
            'mcp.allowed_origins' => ['https://chatgpt.com', 'https://chat.openai.com'],
        ]);

        $this->preparar_v2(true);
    }

    /**
     * Un admin nuevo con email único y la contraseña conocida; `$cambios` pisa columnas (ej. `solo_leads`).
     *
     * @param array<string, mixed> $cambios Columnas a pisar.
     *
     * @return Admin
     */
    private function admin_de_mcp(array $cambios = [])
    {
        return Admin::create(array_merge([
            'name'     => 'Lucas MCP',
            'email'    => 'mcp-' . uniqid('', true) . '@test.local',
            'password' => bcrypt(self::$clave_del_admin),
        ], $cambios));
    }

    /**
     * Registra un cliente OAuth por DCR (como lo hace ChatGPT) y devuelve su `client_id`.
     *
     * @return string
     */
    private function registrar_cliente_de_chatgpt()
    {
        $respuesta = $this->postJson('/api/mcp/oauth/register', [
            'redirect_uris'              => [self::$redirect_de_chatgpt],
            'client_name'                => 'ChatGPT de prueba',
            'token_endpoint_auth_method' => 'none',
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
        ]);
        $respuesta->assertStatus(201);

        return (string) $respuesta->json('client_id');
    }

    /**
     * Un par PKCE: verifier aleatorio de 64 caracteres y su challenge S256 (base64url sin relleno).
     *
     * @return array{0: string, 1: string} [verifier, challenge]
     */
    private function par_pkce()
    {
        $verifier  = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    /**
     * Los parámetros de un pedido de autorización válido; `$cambios` pisa (o saca, con null) lo que haga falta.
     *
     * @param string               $client_id El cliente registrado.
     * @param string               $challenge El `code_challenge` S256.
     * @param array<string, mixed> $cambios   Cambios (un valor null saca el parámetro).
     *
     * @return array<string, string>
     */
    private function parametros_de_autorizacion($client_id, $challenge, array $cambios = [])
    {
        $parametros = array_merge([
            'response_type'         => 'code',
            'client_id'             => $client_id,
            'redirect_uri'          => self::$redirect_de_chatgpt,
            'state'                 => 'estado-abc-123',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'scope'                 => 'comerciocity.read',
            'resource'              => self::$recurso_de_prueba,
        ], $cambios);

        return array_filter($parametros, function ($valor) {
            return $valor !== null;
        });
    }

    /**
     * Manda el formulario de la pantalla de consentimiento (login + decisión) como lo haría el navegador.
     *
     * El botón "Permitir" manda `decision=allow` y "No permitir" `decision=deny`; el email y la contraseña van
     * en los campos `email` y `password`.
     *
     * @param array<string, string> $parametros Lo de {@see parametros_de_autorizacion()}.
     * @param string                $email      Email del admin.
     * @param string                $clave      Contraseña del admin.
     * @param string                $decision   `allow` o `deny`.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_consentimiento(array $parametros, $email, $clave, $decision = 'allow')
    {
        return $this->post('/mcp/oauth/authorize', array_merge($parametros, [
            'email' => $email, 'password' => $clave, 'decision' => $decision,
        ]));
    }

    /**
     * Los parámetros de la query del `Location` de una respuesta 302.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta con redirección.
     *
     * @return array<string, string>
     */
    private function consulta_del_redirect($respuesta)
    {
        $ubicacion = (string) $respuesta->headers->get('Location');
        $consulta  = (string) parse_url($ubicacion, PHP_URL_QUERY);
        parse_str($consulta, $parametros);

        return $parametros;
    }

    /**
     * Corre el consentimiento con credenciales buenas y devuelve el `code` emitido (afirmando el 302).
     *
     * @param Admin  $admin     Admin que autoriza.
     * @param string $client_id Cliente registrado.
     * @param string $challenge `code_challenge` S256.
     *
     * @return string El código de autorización.
     */
    private function emitir_codigo(Admin $admin, $client_id, $challenge)
    {
        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge),
            $admin->email,
            self::$clave_del_admin
        );
        $respuesta->assertStatus(302);
        $consulta = $this->consulta_del_redirect($respuesta);
        $this->assertArrayHasKey('code', $consulta, 'El 302 de "Permitir" tiene que traer ?code=.');

        return (string) $consulta['code'];
    }

    /**
     * Canjea un código en `POST /api/mcp/oauth/token` (form-urlencoded, como ChatGPT).
     *
     * @param string               $client_id Cliente.
     * @param string               $code      Código de autorización.
     * @param string               $verifier  `code_verifier` PKCE.
     * @param array<string, mixed> $cambios   Cambios sobre el cuerpo (un valor null saca el parámetro).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function canjear_codigo($client_id, $code, $verifier, array $cambios = [])
    {
        $cuerpo = array_filter(array_merge([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $client_id,
            'redirect_uri'  => self::$redirect_de_chatgpt,
            'code_verifier' => $verifier,
        ], $cambios), function ($valor) {
            return $valor !== null;
        });

        return $this->post('/api/mcp/oauth/token', $cuerpo);
    }

    /**
     * El flujo completo (registro, consentimiento, canje) para un admin. Devuelve el cuerpo del token.
     *
     * @param Admin $admin Admin que autoriza (sin `solo_leads` ni `es_agente`).
     *
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    private function token_para(Admin $admin)
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list($verifier, $challenge) = $this->par_pkce();
        $code = $this->emitir_codigo($admin, $client_id, $challenge);

        $respuesta = $this->canjear_codigo($client_id, $code, $verifier);
        $respuesta->assertStatus(200);

        return [
            'access_token'  => (string) $respuesta->json('access_token'),
            'refresh_token' => (string) $respuesta->json('refresh_token'),
            'client_id'     => $client_id,
        ];
    }

    /**
     * Un POST JSON-RPC a `/api/mcp`, con o sin bearer.
     *
     * @param string|null          $access_token Token de acceso (null = sin Authorization).
     * @param mixed                $cuerpo       Mensaje JSON-RPC (array).
     * @param array<string, string> $encabezados Encabezados extra.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function mcp_post($access_token, $cuerpo, array $encabezados = [])
    {
        $base = ['Accept' => 'application/json, text/event-stream'];
        if ($access_token !== null) {
            $base['Authorization'] = 'Bearer ' . $access_token;
        }

        return $this->postJson('/api/mcp', $cuerpo, array_merge($base, $encabezados));
    }

    /**
     * Arma un mensaje JSON-RPC de pedido.
     *
     * @param string               $metodo Método.
     * @param array<string, mixed> $params Parámetros.
     * @param int                  $id     Id del mensaje.
     *
     * @return array<string, mixed>
     */
    private function rpc($metodo, array $params = [], $id = 1)
    {
        $mensaje = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo];
        if ($params !== []) {
            $mensaje['params'] = $params;
        }

        return $mensaje;
    }

    /**
     * Llama una herramienta (`tools/call`) y devuelve el `result` (afirmando HTTP 200).
     *
     * @param string               $access_token Token de acceso.
     * @param string               $nombre       Nombre de la herramienta.
     * @param array<string, mixed> $argumentos   Argumentos.
     *
     * @return array<string, mixed> El `result` JSON-RPC.
     */
    private function llamar_herramienta($access_token, $nombre, array $argumentos = [])
    {
        $argumentos = $argumentos === [] ? new \stdClass() : $argumentos;
        $respuesta  = $this->mcp_post($access_token, $this->rpc('tools/call', ['name' => $nombre, 'arguments' => $argumentos]));
        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('error'), 'tools/call no debería dar error JSON-RPC: ' . $respuesta->getContent());

        return (array) $respuesta->json('result');
    }

    /**
     * El `structuredContent` de una herramienta (afirmando que no es un error de herramienta).
     *
     * @param string               $access_token Token de acceso.
     * @param string               $nombre       Herramienta.
     * @param array<string, mixed> $argumentos   Argumentos.
     *
     * @return array<string, mixed>
     */
    private function datos_de_herramienta($access_token, $nombre, array $argumentos = [])
    {
        $resultado = $this->llamar_herramienta($access_token, $nombre, $argumentos);
        $this->assertNotTrue($resultado['isError'] ?? false, 'La herramienta ' . $nombre . ' dio isError: ' . json_encode($resultado));
        $this->assertArrayHasKey('structuredContent', $resultado);

        return (array) $resultado['structuredContent'];
    }

    /**
     * Una marca ISO de Buenos Aires.
     *
     * @param string $fecha_hora `Y-m-d H:i:s` en hora argentina.
     *
     * @return string
     */
    private function iso_ar($fecha_hora)
    {
        return Carbon::parse($fecha_hora, 'America/Argentina/Buenos_Aires')->toIso8601String();
    }

    /**
     * Una misión del CA6: estado, etapa y desde cuándo está en ella, con su historial y su pedido.
     *
     * @param string               $slug    Slug (el id queda `cc:<slug>`).
     * @param string               $estado  Estado de la misión.
     * @param string               $etapa   Etapa actual.
     * @param string               $desde   `Y-m-d H:i:s` ART desde que está en la etapa.
     * @param string|null          $pedido  `pedido_id` vinculado.
     * @param array<string, mixed> $cambios Cambios extra.
     *
     * @return array<string, mixed>
     */
    private function mision_con_etapa($slug, $estado, $etapa, $desde, $pedido = null, array $cambios = [])
    {
        $desde_iso = $this->iso_ar($desde);

        return $this->mision_v2('cc:' . $slug, array_merge([
            'estado'              => $estado,
            'etapa'               => $etapa,
            'fase'                => $etapa,
            'fase_desde'          => $desde_iso,
            'fase_historial'      => [['fase' => $etapa, 'etapa' => $etapa, 'desde' => $desde_iso, 'hasta' => null, 'segundos' => null, 'inferida' => false]],
            'ultima_actividad_at' => $this->iso_ar('2026-11-26 11:59:00'),
            'pedido_id'           => $pedido,
        ], $cambios));
    }

    /**
     * El reporte v2 del CA6 (reloj en 26/11/2026 12:00 ART): cuatro misiones, una cola con una en espera, el
     * Workboard con cuatro tarjetas y dos tareas programadas.
     *
     * - `cc:a` trabajando en construcción desde las 11:00 (60 min, no demorada).
     * - `cc:b` trabajando en construcción desde las 07:00 (300 min contra 240: demorada).
     * - `cc:c` esperando a Lucas, del pedido de prueba.
     * - `cc:d` terminada, del pedido de prueba.
     * - Cola: `e` en espera, del pedido de prueba, por memoria.
     *
     * @return array<string, mixed>
     */
    private function reporte_del_ca6()
    {
        $pedido = self::$pedido_de_prueba;
        $ahora  = $this->iso_ar('2026-11-26 12:00:00');

        return $this->reporte_v2([
            'reporter'  => ['version' => '2.0.0', 'host' => 'lucas-pc', 'plataforma' => 'linux', 'enviado_at' => $ahora, 'slots_total' => 0],
            'misiones'  => [
                $this->mision_con_etapa('a', 'trabajando', 'construccion', '2026-11-26 11:00:00'),
                $this->mision_con_etapa('b', 'trabajando', 'construccion', '2026-11-26 07:00:00'),
                $this->mision_con_etapa('c', 'esperando_lucas', 'plan', '2026-11-26 10:00:00', $pedido, ['origen' => 'dots']),
                $this->mision_con_etapa('d', 'terminada', 'cierre', '2026-11-26 09:00:00', $pedido, ['origen' => 'dots']),
            ],
            'openclaw'  => [
                'gateway'   => ['estado' => 'ok', 'version' => '2026.11.5', 'verificado_at' => $ahora],
                'workboard' => [
                    'ok' => true, 'total' => 4, 'conteo' => ['running' => 1, 'ready' => 2, 'done' => 1],
                    'tarjetas' => [
                        $this->tarjeta_v2('t-1', 'running'),
                        $this->tarjeta_v2('t-2', 'ready'),
                        $this->tarjeta_v2('t-3', 'ready'),
                        $this->tarjeta_v2('t-4', 'done'),
                    ],
                ],
                'tareas'    => ['ok' => true, 'items' => [$this->tarea_v2('c-1'), $this->tarea_v2('c-2', ['nombre' => 'Tarea de comando', 'tipo' => 'comando', 'agente' => null, 'condicion' => true])]],
            ],
            'cola'      => [
                'ok'        => true,
                'fallidas'  => 0,
                'en_espera' => [[
                    'slug' => 'e', 'titulo' => 'Misión en cola', 'prioridad' => 'normal', 'origen' => 'dots',
                    'encolada_at' => $this->iso_ar('2026-11-26 11:30:00'), 'espera_min' => 30, 'motivos' => ['memoria'], 'intentos' => 1,
                    'pedido_id' => $pedido,
                ]],
                'ultimo_chequeo' => [
                    'at' => $ahora, 'ram_disponible_gib' => 12.5, 'ram_total_gib' => 31.2, 'swap_libre_gib' => 7.9, 'swap_total_gib' => 8.0,
                    'load1' => 0.2, 'load5' => 0.4, 'nproc' => 24, 'activas_total' => 2, 'activas_sin_pantalla' => 1, 'largo_cola' => 1,
                    'pesados_ocupados' => 0, 'pesados_cupos' => 2, 'lanzadas' => [], 'retenidas' => 1,
                    'umbral_ram_gib' => 6, 'umbral_carga_por_nucleo' => 0.8,
                ],
                'chequeos'  => [],
            ],
        ]);
    }

    /**
     * Congela el reloj en el 26/11/2026 12:00 ART, manda el reporte del CA6 por el endpoint real y arma la Agenda:
     * "Llamar a Juan" (hoy, del admin del token, con `pedido:<id>` en las notas) y "Secreto ajeno" (hoy, de otro admin).
     *
     * @param Admin $admin       Admin dueño del token.
     * @param Admin $otro_admin  Otro admin con una tarea que no se tiene que filtrar.
     *
     * @return void
     */
    private function armar_escena_del_ca6(Admin $admin, Admin $otro_admin)
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'America/Argentina/Buenos_Aires'));

        $this->enviar_v2($this->reporte_del_ca6())->assertStatus(200);

        $agenda = app(AgendaService::class);
        $agenda->crear($admin->id, ['title' => 'Llamar a Juan', 'date' => '2026-11-26', 'notes' => 'pedido:' . self::$pedido_de_prueba], 'panel');
        $agenda->crear($otro_admin->id, ['title' => 'Secreto ajeno', 'date' => '2026-11-26'], 'panel');
    }
}
