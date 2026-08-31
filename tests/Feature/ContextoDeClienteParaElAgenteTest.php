<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientSupportContext;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSuggestionService;
use App\Services\SupportClientContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El canal de contexto por cliente: la carga de las fichas y su inyección en el prompt del agente.
 *
 * Lo que más importa acá, en orden:
 *
 *  1. 🔴 QUE `notas_internas` NUNCA LLEGUE AL PROMPT. Es la razón de ser de que sean dos columnas
 *     y no una. Si una nota del tipo "es de trato difícil" se filtra, condiciona el tono de la
 *     respuesta que se le manda a esa misma persona, y nadie lo va a notar leyendo la respuesta.
 *     Es una falla silenciosa y por eso tiene un test propio con un marcador único.
 *  2. Que la ficha NO se presente como fuente sobre el sistema. Sin la aclaración explícita, el
 *     agente cita el contexto para afirmar cosas de ComercioCity y con eso esquiva el gate de
 *     `fuentes_kb`, que es lo único que hoy impide que invente.
 *  3. Que lo calculable se calcule. Un "3 tickets abiertos" guardado a mano es correcto el día que
 *     se escribe y mentira una semana después.
 *  4. Que la idempotencia por `client_id` aguante el reenvío del lote completo, que es como se
 *     carga esto: se corrige una ficha y se vuelven a mandar las treinta y una.
 */
class ContextoDeClienteParaElAgenteTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-contexto-cliente';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        // Ninguna salida HTTP real: el servicio del agente habla con Anthropic y con GitHub.
        Http::fake();
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Cliente de prueba.
     *
     * @param string $nombre Nombre del cliente.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre = 'Distribuidora de prueba'): Client
    {
        $client            = new Client();
        $client->name      = $nombre;
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto con un mensaje del cliente.
     *
     * @param Client $client Cliente dueño.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => '+5493417770001',
            'opened_at'        => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Me tira un error al facturar',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Arma el prompt user del agente para un ticket, atravesando el método protected.
     *
     * @param SupportTicket $ticket Ticket a resolver.
     *
     * @return string
     */
    private function prompt_del_agente(SupportTicket $ticket): string
    {
        $metodo = new \ReflectionMethod(SupportAiSuggestionService::class, 'build_user_content');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(new SupportAiSuggestionService(), $ticket);
    }

    /**
     * 🔴 EL TEST QUE MÁS IMPORTA: las notas internas no llegan al prompt, la ficha sí.
     *
     * El marcador es único a propósito: si alguien "simplifica" el acceso a la tabla a un
     * `->first()` y de paso mete las dos columnas en el bloque, esto se pone rojo.
     *
     * @return void
     */
    public function test_las_notas_internas_no_se_inyectan_nunca_en_el_prompt()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        ClientSupportContext::create([
            'client_id'       => $client->id,
            'ficha_operativa' => 'Habla casi siempre por audio. Usa Compras y Facturación.',
            'notas_internas'  => 'MARCADOR-QUE-NO-PUEDE-SALIR-AL-PROMPT-8471',
            'created_via'     => ClientSupportContext::CREATED_VIA_CLAUDE,
        ]);

        $prompt = $this->prompt_del_agente($ticket);

        $this->assertStringNotContainsString('MARCADOR-QUE-NO-PUEDE-SALIR-AL-PROMPT-8471', $prompt);
        $this->assertStringContainsString('Habla casi siempre por audio', $prompt);
    }

    /**
     * El prompt lleva el encabezado con la aclaración de que la ficha no habla del sistema, y la
     * regla que le prohíbe al agente usarla como fuente.
     *
     * @return void
     */
    public function test_el_prompt_aclara_que_la_ficha_no_es_fuente_sobre_el_sistema()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        ClientSupportContext::create([
            'client_id'       => $client->id,
            'ficha_operativa' => 'Viene de tres regresiones seguidas.',
        ]);

        $prompt = $this->prompt_del_agente($ticket);

        $this->assertStringContainsString(SupportClientContextService::ENCABEZADO, $prompt);
        $this->assertStringContainsString('no es fuente sobre cómo funciona el sistema', $prompt);
        $this->assertStringContainsString('NO es una fuente y nunca va en fuentes_kb', $prompt);
    }

    /**
     * El bloque calculado sale de la base y no del payload: se cuentan los tickets, los mensajes y
     * los escalados de ese cliente.
     *
     * @return void
     */
    public function test_los_datos_calculados_salen_de_la_base_al_armar_el_prompt()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        // Un segundo ticket, cerrado y escalado en su momento.
        $viejo = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'closed',
            'source'         => 'whatsapp',
            'opened_at'      => now()->subMonths(2),
            'closed_at'      => now()->subMonths(2),
            'escalated_at'   => now()->subMonths(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $viejo->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Consulta anterior',
        ]);

        $prompt = $this->prompt_del_agente($ticket);

        $this->assertStringContainsString('1 abiertos y 2 en total', $prompt);
        $this->assertStringContainsString('en todos sus tickets: 2', $prompt);
        $this->assertStringContainsString('se escaló a un humano: 1', $prompt);
    }

    /**
     * Un cliente sin ficha cargada no rompe nada: el prompt se arma igual, lo dice, y sigue
     * trayendo el bloque calculado.
     *
     * @return void
     */
    public function test_un_cliente_sin_ficha_no_rompe_el_prompt()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $prompt = $this->prompt_del_agente($ticket);

        $this->assertStringContainsString('todavía no tiene ficha cargada', $prompt);
        $this->assertStringContainsString('Historial de la conversación:', $prompt);
        $this->assertStringContainsString('Me tira un error al facturar', $prompt);
    }

    /**
     * 🔴 El bloque JSON que `append_title_suggestion_to_user_content()` reemplaza tiene que seguir
     * coincidiendo carácter por carácter después de meterle el contexto al prompt.
     *
     * Si se desincroniza, el `str_replace` no encuentra nada, NO falla, y un ticket sin nombre deja
     * de pedir `suggested_title` para siempre y en silencio.
     *
     * @return void
     */
    public function test_el_pedido_de_titulo_sigue_enganchando_despues_del_cambio()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $service = new SupportAiSuggestionService();

        $base = new \ReflectionMethod(SupportAiSuggestionService::class, 'build_user_content');
        $base->setAccessible(true);
        $prompt = (string) $base->invoke($service, $ticket);

        $con_titulo = new \ReflectionMethod(SupportAiSuggestionService::class, 'append_title_suggestion_to_user_content');
        $con_titulo->setAccessible(true);
        $resultado = (string) $con_titulo->invoke($service, $prompt);

        $this->assertStringContainsString('"suggested_title": "..."', $resultado);
        $this->assertNotSame($prompt, $resultado);
    }

    /**
     * Alta y reenvío: el segundo POST del mismo cliente actualiza y no duplica.
     *
     * @return void
     */
    public function test_la_carga_es_idempotente_por_client_id()
    {
        $client = $this->crear_cliente();

        $primera = $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id, 'ficha_operativa' => 'Primera versión.'],
            ],
        ], $this->headers());

        $primera->assertStatus(200);
        $primera->assertJsonPath('resultados.creadas', 1);
        $primera->assertJsonPath('resultados.actualizadas', 0);

        $segunda = $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id, 'ficha_operativa' => 'Segunda versión, corregida.'],
            ],
        ], $this->headers());

        $segunda->assertStatus(200);
        $segunda->assertJsonPath('resultados.creadas', 0);
        $segunda->assertJsonPath('resultados.actualizadas', 1);

        $this->assertSame(1, ClientSupportContext::where('client_id', $client->id)->count());
        $this->assertSame(
            'Segunda versión, corregida.',
            ClientSupportContext::where('client_id', $client->id)->value('ficha_operativa')
        );
    }

    /**
     * Un campo ausente no borra lo que ya estaba; un null explícito sí.
     *
     * @return void
     */
    public function test_un_campo_ausente_no_pisa_y_un_null_explicito_borra()
    {
        $client = $this->crear_cliente();

        $this->postJson('/api/claude/client-context', [
            'entries' => [[
                'client_id'       => $client->id,
                'ficha_operativa' => 'Ficha original.',
                'notas_internas'  => 'Nota original.',
            ]],
        ], $this->headers())->assertStatus(200);

        // Corrección de la ficha sin repetir la nota: la nota tiene que sobrevivir.
        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id, 'ficha_operativa' => 'Ficha corregida.'],
            ],
        ], $this->headers())->assertStatus(200);

        $fila = ClientSupportContext::where('client_id', $client->id)->first();
        $this->assertSame('Ficha corregida.', $fila->ficha_operativa);
        $this->assertSame('Nota original.', $fila->notas_internas);

        // Null explícito: eso sí la borra.
        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id, 'notas_internas' => null],
            ],
        ], $this->headers())->assertStatus(200);

        $this->assertNull(ClientSupportContext::where('client_id', $client->id)->value('notas_internas'));
        $this->assertSame('Ficha corregida.', ClientSupportContext::where('client_id', $client->id)->value('ficha_operativa'));
    }

    /**
     * `created_via` queda en 'claude' al crear y no se pisa al actualizar.
     *
     * @return void
     */
    public function test_created_via_se_estampa_solo_en_el_alta()
    {
        $client = $this->crear_cliente();

        ClientSupportContext::create([
            'client_id'       => $client->id,
            'ficha_operativa' => 'Cargada a mano por otro camino.',
            'created_via'     => 'admin',
        ]);

        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id, 'ficha_operativa' => 'Corregida por Claude.'],
            ],
        ], $this->headers())->assertStatus(200);

        $this->assertSame('admin', ClientSupportContext::where('client_id', $client->id)->value('created_via'));

        $otro = $this->crear_cliente('Otra distribuidora');

        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $otro->id, 'ficha_operativa' => 'Nueva.'],
            ],
        ], $this->headers())->assertStatus(200);

        $this->assertSame(
            ClientSupportContext::CREATED_VIA_CLAUDE,
            ClientSupportContext::where('client_id', $otro->id)->value('created_via')
        );
    }

    /**
     * Una entrada que no nombra ninguno de los dos campos es 422 y no escribe nada.
     *
     * @return void
     */
    public function test_una_entrada_que_no_nombra_ningun_campo_es_422_y_no_escribe_nada()
    {
        $client = $this->crear_cliente();

        $respuesta = $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id],
            ],
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame(0, ClientSupportContext::where('client_id', $client->id)->count());
    }

    /**
     * 🔴 El mismo payload de puros null es 422 sobre un cliente SIN ficha (crearía una fila vacía)
     * y válido sobre uno que ya la tiene (es un borrado).
     *
     * Esta asimetría es la que hizo separar los dos rechazos del controlador: un solo chequeo
     * dejaba sin forma de vaciar un campo.
     *
     * @return void
     */
    public function test_los_null_crean_ficha_vacia_solo_si_no_habia_ficha()
    {
        $sin_ficha = $this->crear_cliente('Sin ficha');

        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $sin_ficha->id, 'ficha_operativa' => null, 'notas_internas' => null],
            ],
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, ClientSupportContext::where('client_id', $sin_ficha->id)->count());

        $con_ficha = $this->crear_cliente('Con ficha');
        ClientSupportContext::create([
            'client_id'       => $con_ficha->id,
            'ficha_operativa' => 'Algo escrito.',
            'notas_internas'  => 'Una nota.',
        ]);

        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $con_ficha->id, 'ficha_operativa' => null, 'notas_internas' => null],
            ],
        ], $this->headers())->assertStatus(200);

        $this->assertSame(1, ClientSupportContext::where('client_id', $con_ficha->id)->count());
        $this->assertNull(ClientSupportContext::where('client_id', $con_ficha->id)->value('ficha_operativa'));
        $this->assertNull(ClientSupportContext::where('client_id', $con_ficha->id)->value('notas_internas'));
    }

    /**
     * Un client_id repetido en el mismo lote es 422, porque el resultado dependería del orden.
     *
     * @return void
     */
    public function test_un_client_id_repetido_en_el_lote_es_422()
    {
        $client = $this->crear_cliente();

        $respuesta = $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $client->id, 'ficha_operativa' => 'Una.'],
                ['client_id' => $client->id, 'ficha_operativa' => 'Otra.'],
            ],
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame(0, ClientSupportContext::where('client_id', $client->id)->count());
    }

    /**
     * Un client_id inexistente contesta 422 en castellano, no en inglés.
     *
     * La lista de `mensajes_de_validacion()` del trait cubre `exists`: una regla que no está en esa
     * lista no falla, contesta en inglés, y ese es el único síntoma.
     *
     * @return void
     */
    public function test_un_client_id_inexistente_contesta_422_en_castellano()
    {
        $respuesta = $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => 99999999, 'ficha_operativa' => 'Ficha de un cliente que no existe.'],
            ],
        ], $this->headers());

        $respuesta->assertStatus(422);
        $cuerpo = json_decode($respuesta->getContent(), true);
        $this->assertSame('parámetros inválidos', $cuerpo['error']);
        $this->assertStringContainsString('no existe', $cuerpo['errores']['entries.0.client_id'][0]);
    }

    /**
     * El GET devuelve lo cargado —las dos columnas— y filtra por cliente.
     *
     * @return void
     */
    public function test_el_get_devuelve_las_dos_columnas_y_filtra_por_cliente()
    {
        $uno = $this->crear_cliente('Cliente uno');
        $dos = $this->crear_cliente('Cliente dos');

        $this->postJson('/api/claude/client-context', [
            'entries' => [
                ['client_id' => $uno->id, 'ficha_operativa' => 'Ficha del uno.', 'notas_internas' => 'Nota del uno.'],
                ['client_id' => $dos->id, 'ficha_operativa' => 'Ficha del dos.'],
            ],
        ], $this->headers())->assertStatus(200);

        $todas = $this->getJson('/api/claude/client-context', $this->headers());
        $todas->assertStatus(200);
        $todas->assertJsonPath('total', 2);

        $filtrada = $this->getJson('/api/claude/client-context?client_id=' . $uno->id, $this->headers());
        $filtrada->assertStatus(200);
        $filtrada->assertJsonPath('total', 1);
        $filtrada->assertJsonPath('fichas.0.ficha_operativa', 'Ficha del uno.');
        $filtrada->assertJsonPath('fichas.0.notas_internas', 'Nota del uno.');
    }

    /**
     * Sin la clave del header, los dos endpoints dan 401: el middleware es fail-closed.
     *
     * @return void
     */
    public function test_sin_la_clave_del_header_los_dos_endpoints_dan_401()
    {
        $this->getJson('/api/claude/client-context', ['Accept' => 'application/json'])->assertStatus(401);

        $this->postJson('/api/claude/client-context', [
            'entries' => [['client_id' => 1, 'ficha_operativa' => 'x']],
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }
}
