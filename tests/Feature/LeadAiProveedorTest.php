<?php

namespace Tests\Feature;

use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiProveedor;
use App\Services\LeadAiService;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El agente de WhatsApp de los leads corre contra DeepSeek (misión leads-agente-deepseek,
 * 16/10/2026) y, sin la clave de DeepSeek, sigue corriendo contra Claude exactamente como antes.
 *
 * Lo que se mide acá es lo que decide a cuál de los dos le llega cada llamada: la URL, la clave
 * que viaja en `x-api-key`, el modelo y la clave `thinking` del cuerpo. Los fakes contestan en el
 * formato de Anthropic, que es el que habla el endpoint compatible de DeepSeek.
 */
class LeadAiProveedorTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de Claude de los tests. */
    const CLAVE_CLAUDE = 'clave-claude-de-prueba';

    /** Clave de DeepSeek de los tests. */
    const CLAVE_DEEPSEEK = 'clave-deepseek-de-prueba';

    /**
     * Cada test arranca sin DeepSeek y con Claude cargado: el estado de un admin que todavía no
     * recibió la clave nueva. Los tests que quieren DeepSeek la cargan ellos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'  => self::CLAVE_CLAUDE,
            'services.anthropic.model'    => 'claude-sonnet-4-20250514',
            'services.deepseek.api_key'   => '',
            'services.deepseek.base_url'  => 'https://api.deepseek.com/anthropic',
            'services.deepseek.model_leads' => 'deepseek-flash',
            'services.lead_ai.proveedor'  => 'deepseek',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 — LA DECISIÓN: cuál de los dos corre
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sin clave de DeepSeek el agente se queda en Claude aunque el proveedor configurado sea
     * DeepSeek (que es el default): desplegar el código no cambia lo que contesta nadie.
     *
     * @return void
     */
    public function test_sin_clave_de_deepseek_corre_claude()
    {
        $this->assertFalse(LeadAiProveedor::es_deepseek());
        $this->assertSame('anthropic', LeadAiProveedor::nombre());
        $this->assertSame('Anthropic', LeadAiProveedor::etiqueta());
        $this->assertSame(self::CLAVE_CLAUDE, LeadAiProveedor::api_key());
        $this->assertSame('claude-sonnet-4-20250514', LeadAiProveedor::modelo());
        $this->assertSame('https://api.anthropic.com/v1/messages', LeadAiProveedor::url_messages());
    }

    /**
     * Una clave en blanco (espacios, un .env mal pegado) cuenta como sin clave.
     *
     * @return void
     */
    public function test_una_clave_de_deepseek_en_blanco_no_prende_deepseek()
    {
        config(['services.deepseek.api_key' => '   ']);

        $this->assertFalse(LeadAiProveedor::es_deepseek());
        $this->assertSame('anthropic', LeadAiProveedor::nombre());
    }

    /**
     * Con la clave cargada corre DeepSeek: otra URL, otra clave, otro modelo.
     *
     * @return void
     */
    public function test_con_clave_de_deepseek_corre_deepseek()
    {
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        $this->assertTrue(LeadAiProveedor::es_deepseek());
        $this->assertSame('deepseek', LeadAiProveedor::nombre());
        $this->assertSame('DeepSeek', LeadAiProveedor::etiqueta());
        $this->assertSame(self::CLAVE_DEEPSEEK, LeadAiProveedor::api_key());
        $this->assertSame('deepseek-flash', LeadAiProveedor::modelo());
        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', LeadAiProveedor::url_messages());
    }

    /**
     * `LEAD_AI_PROVEEDOR=anthropic` es la vuelta atrás: con la clave de DeepSeek todavía cargada,
     * el agente vuelve a Claude.
     *
     * @return void
     */
    public function test_el_interruptor_a_anthropic_gana_sobre_la_clave_cargada()
    {
        config([
            'services.deepseek.api_key'  => self::CLAVE_DEEPSEEK,
            'services.lead_ai.proveedor' => 'anthropic',
        ]);

        $this->assertFalse(LeadAiProveedor::es_deepseek());
        $this->assertSame(self::CLAVE_CLAUDE, LeadAiProveedor::api_key());
        $this->assertSame('https://api.anthropic.com/v1/messages', LeadAiProveedor::url_messages());
    }

    /**
     * El modelo y la base del endpoint se cambian por config, sin tocar código; una base_url con
     * barra final no duplica la barra; una variable en blanco en el .env (que llega como '', no
     * como "no definida") cae al default en vez de dar una URL relativa o un modelo vacío.
     *
     * @return void
     */
    public function test_modelo_y_base_url_salen_de_la_config_y_lo_vacio_cae_al_default()
    {
        config([
            'services.deepseek.api_key'     => self::CLAVE_DEEPSEEK,
            'services.deepseek.model_leads' => 'deepseek-v4-pro',
            'services.deepseek.base_url'    => 'https://proxy.local/anthropic/',
        ]);

        $this->assertSame('deepseek-v4-pro', LeadAiProveedor::modelo());
        $this->assertSame('https://proxy.local/anthropic/v1/messages', LeadAiProveedor::url_messages());

        config(['services.deepseek.model_leads' => '', 'services.deepseek.base_url' => '  ']);

        $this->assertSame('deepseek-flash', LeadAiProveedor::modelo());
        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', LeadAiProveedor::url_messages());
    }

    /**
     * El razonamiento de DeepSeek va SIEMPRE apagado en el agente de leads, aunque alguien deje una
     * config vieja `thinking_leads` o intente prenderlo: con `enabled` los tokens de razonamiento
     * se comerían el `max_tokens` (3000 / 400) y la respuesta saldría cortada.
     *
     * @return void
     */
    public function test_el_razonamiento_de_deepseek_va_siempre_apagado()
    {
        config([
            'services.deepseek.api_key'        => self::CLAVE_DEEPSEEK,
            'services.deepseek.thinking_leads' => 'enabled',
        ]);

        $this->assertSame(['type' => 'disabled'], LeadAiProveedor::cuerpo(['model' => 'x'])['thinking']);
    }

    /**
     * Con Claude el cuerpo sale idéntico al de antes de la misión: ni una clave de más.
     *
     * @return void
     */
    public function test_con_claude_el_cuerpo_no_cambia()
    {
        $cuerpo = ['model' => 'm', 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'hola']]];

        $this->assertSame($cuerpo, LeadAiProveedor::cuerpo($cuerpo));
    }

    /**
     * La verificación TLS y el cacert salen de la config del proveedor que corre: cada uno con la
     * suya, sin mezclar (el de DeepSeek hereda el de Anthropic si no tiene uno propio).
     *
     * @return void
     */
    public function test_el_tls_sale_de_la_config_del_proveedor_que_corre()
    {
        config([
            'services.anthropic.verify_ssl' => true,
            'services.anthropic.ca_bundle'  => 'C:/certs/anthropic.pem',
            'services.deepseek.verify_ssl'  => false,
            'services.deepseek.ca_bundle'   => 'C:/certs/deepseek.pem',
        ]);

        $this->assertTrue(LeadAiProveedor::verify_ssl());
        $this->assertSame('C:/certs/anthropic.pem', LeadAiProveedor::ca_bundle());

        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        $this->assertFalse(LeadAiProveedor::verify_ssl());
        $this->assertSame('C:/certs/deepseek.pem', LeadAiProveedor::ca_bundle());

        config(['services.deepseek.ca_bundle' => '']);

        $this->assertNull(LeadAiProveedor::ca_bundle());
    }

    /**
     * 🔴 Guarda contra la regresión que ningún test de comportamiento ve: que alguno de los sitios
     * de LeadAiService vuelva a escribir a Anthropic a mano. El caso concreto es la SEGUNDA llamada
     * (`generate_suggestion_with_availability`, la del turno de disponibilidad): armarla de punta a
     * punta pide agenda, demos y calendarios, y si alguien le devuelve el modelo o la URL de Claude
     * mientras DeepSeek está prendido, DeepSeek recibe un id de modelo ajeno (lo mapea a flash en
     * silencio) o la clave de un proveedor contra el endpoint del otro. Por eso se mira el archivo:
     * todo lo que sea proveedor tiene que pasar por LeadAiProveedor.
     *
     * @return void
     */
    public function test_ningun_sitio_del_agente_escribe_a_anthropic_a_mano()
    {
        $fuente = (string) file_get_contents(base_path('app/Services/LeadAiService.php'));

        $this->assertNotSame('', $fuente);
        $this->assertStringNotContainsString('api.anthropic.com', $fuente, 'Una URL de Anthropic escrita a mano en LeadAiService.');
        $this->assertStringNotContainsString("config('services.anthropic", $fuente, 'Una lectura de services.anthropic en LeadAiService: tiene que pasar por LeadAiProveedor.');
        $this->assertStringNotContainsString('config("services.anthropic', $fuente);
        $this->assertSame(
            4,
            substr_count($fuente, '= LeadAiProveedor::modelo();'),
            'Los cuatro sitios que fijan el modelo (primera llamada, segunda con disponibilidad, reagendado y correctiva) tienen que pedírselo a LeadAiProveedor.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — LA SUGERENCIA DE PUNTA A PUNTA, contra el endpoint que corresponde
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sin clave de DeepSeek la sugerencia sale por Anthropic, con la clave y el modelo de Claude y
     * SIN `thinking`: es la llamada de siempre.
     *
     * @return void
     */
    public function test_la_sugerencia_sin_clave_de_deepseek_va_a_anthropic_como_siempre()
    {
        $this->sembrar_protocolo_de_leads();

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_final($this->paquete('Hola, te cuento por Claude.')), 200),
            '*'                   => Http::response(['ok' => true], 200),
        ]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);

        $this->assertStringContainsString('por Claude', (string) $mensaje->content);

        Http::assertSent(function (Request $request) {
            $cuerpo = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key') === [self::CLAVE_CLAUDE]
                && $cuerpo['model'] === 'claude-sonnet-4-20250514'
                && ! array_key_exists('thinking', $cuerpo);
        });
        Http::assertNotSent(function (Request $request) {
            return strpos($request->url(), 'deepseek') !== false;
        });
    }

    /**
     * Con la clave cargada la sugerencia sale por DeepSeek: su URL, su clave, `deepseek-flash` y el
     * razonamiento apagado. Y no sale ni una llamada a Anthropic.
     *
     * @return void
     */
    public function test_la_sugerencia_con_clave_de_deepseek_va_a_deepseek()
    {
        $this->sembrar_protocolo_de_leads();
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->respuesta_final($this->paquete('Hola, te cuento por DeepSeek.')), 200),
            '*'                  => Http::response(['ok' => true], 200),
        ]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);

        $this->assertStringContainsString('por DeepSeek', (string) $mensaje->content);

        Http::assertSent(function (Request $request) {
            $cuerpo = $request->data();

            return $request->url() === 'https://api.deepseek.com/anthropic/v1/messages'
                && $request->header('x-api-key') === [self::CLAVE_DEEPSEEK]
                && $cuerpo['model'] === 'deepseek-flash'
                && $cuerpo['thinking'] === ['type' => 'disabled']
                && ! empty($cuerpo['tools'])
                && is_array($cuerpo['system']);
        });
        Http::assertNotSent(function (Request $request) {
            return strpos($request->url(), 'anthropic.com') !== false;
        });
    }

    /**
     * El loop de tool use completa por DeepSeek: un `tool_use`, el resultado devuelto en un
     * `tool_result`, y la respuesta final. Las dos llamadas llevan el mismo modelo y el mismo
     * razonamiento apagado.
     *
     * @return void
     */
    public function test_el_loop_de_tool_use_completa_por_deepseek()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['precios']);
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->respuesta_tool_use('precios'))
                ->push($this->respuesta_final($this->paquete('Con el precio a la vista, dale.'))),
            '*'                  => Http::response(['ok' => true], 200),
        ]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);

        $this->assertStringContainsString('Con el precio a la vista', (string) $mensaje->content);

        $a_deepseek = [];
        Http::assertSent(function (Request $request) use (&$a_deepseek) {
            if (strpos($request->url(), 'api.deepseek.com') !== false) {
                $a_deepseek[] = $request->data();
            }

            return true;
        });

        $this->assertCount(2, $a_deepseek, 'Una vuelta por el tool_use y otra por la respuesta final.');
        foreach ($a_deepseek as $cuerpo) {
            $this->assertSame('deepseek-flash', $cuerpo['model']);
            $this->assertSame(['type' => 'disabled'], $cuerpo['thinking']);
        }

        /* La segunda vuelta devuelve el contenido real del recurso como tool_result. */
        $ultimo = end($a_deepseek)['messages'];
        $resultado = end($ultimo)['content'][0];
        $this->assertSame('tool_result', $resultado['type']);
        $this->assertStringContainsString('Contenido de prueba del recurso precios', (string) $resultado['content']);
    }

    /**
     * Si un endpoint compatible cierra con `end_turn` llevando un bloque `tool_use` (Anthropic no lo
     * hace; DeepSeek es una hipótesis sin confirmar), el loop NO cambia -- no ejecuta la tool --, pero
     * deja un warning con el proveedor para que la sugerencia vacía tenga una causa a la vista.
     *
     * @return void
     */
    public function test_un_tool_use_con_end_turn_deja_un_warning_con_el_proveedor()
    {
        $this->sembrar_protocolo_de_leads();
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'get_protocolo_recurso', 'input' => ['nombre' => 'precios']],
                    ['type' => 'text', 'text' => json_encode($this->paquete('Dale, seguimos.'), JSON_UNESCAPED_UNICODE)],
                ],
            ], 200),
            '*'                  => Http::response(['ok' => true], 200),
        ]);

        /* Un canal de log en memoria como default: Log::spy() rompería los Log::channel('x')->... que
         * el agente usa en otros tramos. */
        config([
            'logging.default'                   => 'memoria_de_prueba',
            'logging.channels.memoria_de_prueba' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Log::forgetChannel('memoria_de_prueba');

        $mensaje = app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);

        $this->assertStringContainsString('Dale, seguimos', (string) $mensaje->content);

        $avisos = [];
        foreach (Log::channel('memoria_de_prueba')->getLogger()->getHandlers() as $handler) {
            foreach ($handler->getRecords() as $registro) {
                if ($registro['level_name'] === 'WARNING' && strpos($registro['message'], 'tool_use pero el stop_reason es end_turn') !== false) {
                    $avisos[] = $registro['context'];
                }
            }
        }

        $this->assertCount(1, $avisos, 'Tiene que quedar exactamente un warning.');
        $this->assertSame('deepseek', $avisos[0]['proveedor']);
        $this->assertSame('get_protocolo_recurso', $avisos[0]['tool']);
    }

    /**
     * Un error de DeepSeek (el 402 de saldo, que ya pasó en empresa el 10/10/2026) sale con el
     * nombre del proveedor en la excepción: no puede leerse como un problema de Claude.
     *
     * @return void
     */
    public function test_el_error_de_deepseek_dice_deepseek()
    {
        $this->sembrar_protocolo_de_leads();
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => ['message' => 'Insufficient Balance']], 402),
            '*'                  => Http::response(['ok' => true], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error DeepSeek HTTP 402');

        app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);
    }

    /**
     * Sin ninguna de las dos claves la sugerencia sigue frenando con el mensaje de siempre.
     *
     * @return void
     */
    public function test_sin_ninguna_clave_sigue_el_mensaje_de_antes()
    {
        $this->sembrar_protocolo_de_leads();
        config(['services.anthropic.api_key' => '', 'services.deepseek.api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY no está configurada.');

        app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — LAS DOS LLAMADAS DIRECTAS (reagendado y correctiva), mismo proveedor
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * La llamada del reagendado no era el loop de tool use: tenía su propia URL escrita a mano.
     * Con la clave de DeepSeek tiene que salir por DeepSeek, no por Anthropic.
     *
     * @return void
     */
    public function test_la_llamada_del_reagendado_va_a_deepseek()
    {
        $this->sembrar_protocolo_de_leads();
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->respuesta_texto('Te lo corrí a las 17:30.'), 200),
            '*'                  => Http::response(['ok' => true], 200),
        ]);

        $texto = $this->invocar_privado('call_reagendado_al_proximo_slot_response', [$this->crear_lead(), '17:00', '17:30', date('Y-m-d')]);

        $this->assertSame('Te lo corrí a las 17:30.', $texto);
        $this->assertSentSoloADeepseek();
    }

    /**
     * Ídem para la tercera llamada correctiva (slot inválido).
     *
     * @return void
     */
    public function test_la_llamada_correctiva_va_a_deepseek()
    {
        $this->sembrar_protocolo_de_leads();
        config(['services.deepseek.api_key' => self::CLAVE_DEEPSEEK]);

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->respuesta_texto('Ese horario no figura, ¿te sirve el de las 11:00?'), 200),
            '*'                  => Http::response(['ok' => true], 200),
        ]);

        $texto = $this->invocar_privado('call_corrective_availability_response', [$this->crear_lead(), '10:00', date('Y-m-d'), ['11:00'], '']);

        $this->assertStringContainsString('11:00', $texto);
        $this->assertSentSoloADeepseek();
    }

    /**
     * Las dos llamadas directas, sin clave de DeepSeek, siguen yendo a Anthropic y sin `thinking`.
     *
     * @return void
     */
    public function test_las_llamadas_directas_sin_clave_de_deepseek_siguen_en_anthropic()
    {
        $this->sembrar_protocolo_de_leads();

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_texto('Listo.'), 200),
            '*'                   => Http::response(['ok' => true], 200),
        ]);

        $this->assertSame('Listo.', $this->invocar_privado('call_reagendado_al_proximo_slot_response', [$this->crear_lead(), '17:00', '17:30', date('Y-m-d')]));
        $this->assertSame('Listo.', $this->invocar_privado('call_corrective_availability_response', [$this->crear_lead(), '10:00', date('Y-m-d'), ['11:00'], '']));

        /* Solo se cuentan los pedidos a un proveedor de IA: el armado del prompt puede salir a otros
         * servicios y eso no es lo que este test mide. */
        $a_proveedores = Http::recorded(function ($request) {
            return strpos($request->url(), 'anthropic.com') !== false || strpos($request->url(), 'deepseek.com') !== false;
        });
        $this->assertCount(2, $a_proveedores);

        foreach ($a_proveedores as $par) {
            $request = $par[0];

            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertSame([self::CLAVE_CLAUDE], $request->header('x-api-key'));
            $this->assertArrayNotHasKey('thinking', $request->data());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ayudas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Afirma que todo lo que salió fue a DeepSeek, con su clave, su modelo y el razonamiento apagado.
     *
     * @return void
     */
    private function assertSentSoloADeepseek(): void
    {
        Http::assertSent(function (Request $request) {
            $cuerpo = $request->data();

            return $request->url() === 'https://api.deepseek.com/anthropic/v1/messages'
                && $request->header('x-api-key') === [self::CLAVE_DEEPSEEK]
                && $cuerpo['model'] === 'deepseek-flash'
                && $cuerpo['thinking'] === ['type' => 'disabled'];
        });
        Http::assertNotSent(function (Request $request) {
            return strpos($request->url(), 'anthropic.com') !== false;
        });
    }

    /**
     * Llama a un método privado de LeadAiService.
     *
     * @param string               $metodo Nombre del método.
     * @param array<int, mixed>    $args   Argumentos.
     *
     * @return mixed
     */
    private function invocar_privado(string $metodo, array $args)
    {
        $reflexion = new ReflectionMethod(LeadAiService::class, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs(app(LeadAiService::class), $args);
    }

    /**
     * Paquete JSON mínimo que devuelve el agente.
     *
     * @param string $mensaje Texto del mensaje sugerido.
     *
     * @return array<string, mixed>
     */
    private function paquete(string $mensaje): array
    {
        return [
            'mensaje_sugerido' => $mensaje,
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
            'tipo_respuesta'   => 'conversacional',
            'fuentes_kb'       => [],
        ];
    }

    /**
     * Respuesta final en el formato de Anthropic (el que habla también el endpoint de DeepSeek).
     *
     * @param array<string, mixed> $paquete JSON que devuelve el modelo.
     *
     * @return array<string, mixed>
     */
    private function respuesta_final(array $paquete): array
    {
        return $this->respuesta_texto(json_encode($paquete, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Respuesta de texto plano que cierra el turno.
     *
     * @param string $texto Texto de la respuesta.
     *
     * @return array<string, mixed>
     */
    private function respuesta_texto(string $texto): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
        ];
    }

    /**
     * Respuesta que pausa para pedir un recurso del protocolo via tool use.
     *
     * @param string $recurso Nombre del recurso pedido.
     *
     * @return array<string, mixed>
     */
    private function respuesta_tool_use(string $recurso): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'tool_' . Str::random(8),
                'name'  => 'get_protocolo_recurso',
                'input' => ['nombre' => $recurso],
            ]],
        ];
    }

    /**
     * Deja en base lo mínimo que build_system_prompt() exige.
     *
     * @return void
     */
    private function sembrar_protocolo_de_leads(): void
    {
        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);

        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => 'System base de prueba (el contrato de fuentes no es lo que este test mide).',
            'synced_at' => now(),
        ]);
    }

    /**
     * Deja en base el contenido de los recursos que el test pide via tool use.
     *
     * @param array<int, string> $recursos Nombres de los recursos a sembrar.
     *
     * @return void
     */
    private function sembrar_recursos(array $recursos): void
    {
        foreach ($recursos as $nombre) {
            SyncedGithubFile::create([
                'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX . $nombre,
                'repo_path' => "agentes/lead/recursos/{$nombre}.md",
                'content'   => "Contenido de prueba del recurso {$nombre}.",
                'synced_at' => now(),
            ]);
        }
    }

    /**
     * Lead mínimo.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'calificado';
        $lead->save();

        return $lead->refresh();
    }
}
