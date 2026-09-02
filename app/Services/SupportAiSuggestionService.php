<?php

namespace App\Services;

use App\Models\AgentIdentity;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera sugerencias de respuesta de soporte vía Anthropic (Claude) con tool use.
 *
 * Claude puede consultar el manual de ComercioCity usando la tool `get_manual_file` antes de
 * formular su respuesta. La lista de archivos disponibles se inyecta en el system prompt en
 * cada request.
 *
 * 🔴 Corregido el 2/9/2026: hasta hoy este docblock decía que el manual se lee de
 * `lucasgonzz/comerciocity-manual-sistema` y ERA FALSO. La constante `GITHUB_REPO` (que ahora
 * vive en `ManualRepositoryService`) es `lucasgonzz/claude-comerciocity`, y el filtro del árbol
 * exige el prefijo `manual_sistema/`. O sea: el agente lee la FUENTE del manual, no el repo
 * publicado. Eso importa para entender qué ve el agente y cuándo lo ve — lo que está escrito y
 * commiteado en la fuente ya le llega, sin esperar el paso de publicación.
 *
 * La lectura del repositorio se delega en `ManualRepositoryService`: el agente de leads lee el
 * mismo manual desde el 2/9/2026 y no puede haber dos copias de la configuración de acceso.
 */
class SupportAiSuggestionService
{
    /**
     * Máximo de iteraciones del agentic loop para evitar bucles infinitos.
     */
    private const MAX_TOOL_ITERATIONS = 5;

    /**
     * Solicita a Claude una respuesta sugerida para el operador usando tool use para
     * consultar el manual de ComercioCity. Si el ticket no tiene nombre, puede incluir
     * suggested_title en la respuesta.
     *
     * Además de la respuesta, Claude puede indicar:
     * - should_close: el caso está resuelto y el ticket puede cerrarse.
     * - should_escalate: Claude no puede resolver el caso y requiere revisión humana.
     * - escalation_reason: motivo corto del escalado (solo cuando should_escalate es true).
     *
     * @param SupportTicket $ticket Ticket abierto con relación client cargada si es posible.
     *
     * @return array{suggested_message: string, reasoning: string, should_close: bool, should_escalate: bool, escalation_reason: string|null, suggested_title?: string}
     */
    public function generate(SupportTicket $ticket): array
    {
        // Ticket sin título: Claude debe proponer un nombre corto además del mensaje.
        $ticket_needs_title = trim((string) ($ticket->name ?? '')) === '';

        try {
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                return [
                    'suggested_message' => '',
                    'reasoning'         => 'ANTHROPIC_API_KEY no está configurada.',
                    'should_close'      => false,
                    'should_escalate'   => false,
                    'escalation_reason' => null,
                ];
            }

            /* Qué partes del repositorio de conocimiento no se pudieron cargar. Antes las dos
             * fallaban en silencio y el agente quedaba sin índice y sin protocolo de escalado,
             * sin enterarse de que le faltaban — justo el escenario donde más improvisa. Es el
             * hallazgo fuera de alcance #1 del informe del 25/8/2026. */
            $fallos_repositorio = [];

            $system_prompt = $this->build_system_prompt($fallos_repositorio);
            $user_content  = $this->build_user_content($ticket);

            /* Sin manual no hay respuesta posible: se escala sin consultar a Claude. Consultarlo
             * sería pagar una llamada para después no poder verificar nada de lo que conteste.
             * Vale con protocolo viejo o nuevo: el agente no puede afirmar nada del sistema si no
             * pudo abrir el sistema. */
            if (! empty($fallos_repositorio)) {
                $detalle = implode(' y ', $fallos_repositorio);

                Log::channel('daily')->error('SupportAiSuggestionService: el repositorio de conocimiento no cargó, se escala sin consultar a Claude.', [
                    'ticket_id' => $ticket->id,
                    'fallos'    => $fallos_repositorio,
                ]);

                $veredicto = app(KnowledgeGroundingGate::class)->escalar_por_repositorio_caido($detalle);

                return [
                    'suggested_message' => '',
                    'reasoning'         => $veredicto['motivo'],
                    'should_close'      => false,
                    'should_escalate'   => true,
                    'escalation_reason' => $veredicto['motivo'],
                    'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_ESCALADO,
                    'fuentes_kb'        => [],
                    'gate_permitido'    => false,
                    'gate_motivo'       => $veredicto['motivo'],
                ];
            }

            if ($ticket_needs_title) {
                $user_content = $this->append_title_suggestion_to_user_content($user_content);
            }

            $model   = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http    = $this->build_http_client();
            $tools   = $this->build_github_tools();
            $messages = [
                ['role' => 'user', 'content' => $this->build_user_blocks($ticket, $user_content)],
            ];

            // Agentic loop: repite hasta end_turn o hasta el límite de iteraciones.
            $iterations = 0;
            $final_text = '';

            /* Archivos del manual que el agente leyó CON ÉXITO en esta consulta. Es contra esta
             * lista —armada por el código que ejecuta las tools, nunca por lo que declare el
             * modelo— que el gate verifica las citas.
             *
             * 🔴 Variable local del método, jamás propiedad de instancia: el servicio se resuelve
             * del contenedor y una propiedad sobreviviría de un ticket al siguiente dentro del
             * mismo worker de cola, dejando que un ticket cite lo que leyó otro. El mismo riesgo
             * ya está documentado en LeadAiService::execute_tool(). */
            $leidas = [];

            while ($iterations < self::MAX_TOOL_ITERATIONS) {
                $iterations++;

                $response = $http->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 2000,
                    'system'     => [
                        [
                            'type'          => 'text',
                            'text'          => $system_prompt,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'tools'      => $tools,
                    'messages'   => $messages,
                ]);

                if ($response->failed()) {
                    $error_message = $this->extract_anthropic_error_message($response->json(), $response->status());

                    Log::error('SupportAiSuggestionService Anthropic error', [
                        'ticket_id'  => $ticket->id,
                        'iteration'  => $iterations,
                        'status'     => $response->status(),
                        'body'       => substr($response->body(), 0, 500),
                    ]);

                    return [
                        'suggested_message' => '',
                        'reasoning'         => $error_message,
                        'should_close'      => false,
                        'should_escalate'   => false,
                        'escalation_reason' => null,
                    ];
                }

                $response_body = $response->json();
                $stop_reason   = (string) ($response_body['stop_reason'] ?? '');
                $content_blocks = $response_body['content'] ?? [];

                if ($stop_reason === 'end_turn') {
                    $final_text = $this->extract_response_text($response_body);
                    break;
                }

                if ($stop_reason === 'tool_use') {
                    // Normalizar bloques antes de reenviarlos: PHP decodifica input:{} como [] y Anthropic exige object.
                    $normalized_content = $this->normalize_assistant_content_for_api($content_blocks);

                    // Agregar el mensaje del asistente (con los bloques tool_use) al historial.
                    $messages[] = [
                        'role'    => 'assistant',
                        'content' => $normalized_content,
                    ];

                    // Procesar cada tool_use y construir los tool_result.
                    $tool_results = $this->execute_tool_calls($content_blocks, $ticket->id, $leidas);

                    // Agregar los resultados como mensaje de user.
                    $messages[] = [
                        'role'    => 'user',
                        'content' => $tool_results,
                    ];

                    continue;
                }

                // stop_reason desconocido: extraer texto si existe y salir.
                $final_text = $this->extract_response_text($response_body);
                break;
            }

            if ($final_text === '') {
                Log::warning('SupportAiSuggestionService: loop terminó sin texto final.', [
                    'ticket_id'  => $ticket->id,
                    'iterations' => $iterations,
                ]);

                return [
                    'suggested_message' => '',
                    'reasoning'         => 'Claude no generó respuesta después de '.$iterations.' iteraciones.',
                    'should_close'      => false,
                    'should_escalate'   => false,
                    'escalation_reason' => null,
                ];
            }

            $parsed = $this->parse_json_response($final_text);

            /* Valores de escalado y cierre con fallback seguro. */
            $should_close    = (bool) ($parsed['should_close'] ?? false);
            $should_escalate = (bool) ($parsed['should_escalate'] ?? false);

            /* Mutua exclusión: si Claude devuelve ambos en true, escalar tiene prioridad. */
            if ($should_escalate) {
                $should_close = false;
            }

            /* Motivo del escalado: solo relevante cuando should_escalate es true. */
            $escalation_reason = $should_escalate
                ? trim((string) ($parsed['escalation_reason'] ?? ''))
                : null;

            /* --- Gate de respaldo documental ---
             *
             * Cruza lo que el agente dice que leyó contra lo que de verdad leyó. Si afirma algo
             * sobre el sistema sin un archivo del manual que lo respalde, el veredicto es escalar
             * y el texto no sale. Ver KnowledgeGroundingGate. */
            $gate = app(KnowledgeGroundingGate::class);

            $veredicto = $gate->evaluar(
                $gate->esta_activo($system_prompt),
                isset($parsed['tipo_respuesta']) ? $parsed['tipo_respuesta'] : null,
                isset($parsed['fuentes_kb']) ? $parsed['fuentes_kb'] : null,
                $leidas
            );

            if (! $veredicto['permitido']) {
                Log::channel('daily')->warning('SupportAiSuggestionService: el gate frenó la respuesta por falta de respaldo.', [
                    'ticket_id'      => $ticket->id,
                    'motivo'         => $veredicto['motivo'],
                    'tipo_respuesta' => isset($parsed['tipo_respuesta']) ? $parsed['tipo_respuesta'] : null,
                    'fuentes_kb'     => isset($parsed['fuentes_kb']) ? $parsed['fuentes_kb'] : null,
                    'leidas'         => $leidas,
                ]);
            }

            $result = [
                'suggested_message' => trim((string) ($parsed['suggested_message'] ?? '')),
                'reasoning'         => trim((string) ($parsed['reasoning'] ?? '')),
                'should_close'      => $should_close,
                'should_escalate'   => $should_escalate,
                'escalation_reason' => $escalation_reason,
                'tipo_respuesta'    => isset($parsed['tipo_respuesta']) ? (string) $parsed['tipo_respuesta'] : '',
                'fuentes_kb'        => isset($parsed['fuentes_kb']) && is_array($parsed['fuentes_kb']) ? $parsed['fuentes_kb'] : [],
                'gate_permitido'    => $veredicto['permitido'],
                'gate_motivo'       => $veredicto['motivo'],
            ];

            if ($ticket_needs_title) {
                $suggested_title = trim((string) ($parsed['suggested_title'] ?? ''));
                if ($suggested_title !== '') {
                    $result['suggested_title'] = $suggested_title;
                }
            }

            return $result;
        } catch (\Throwable $exception) {
            Log::error('SupportAiSuggestionService error', [
                'ticket_id' => $ticket->id,
                'error'     => $exception->getMessage(),
            ]);

            return [
                'suggested_message' => '',
                'reasoning'         => $exception->getMessage(),
                'should_close'      => false,
                'should_escalate'   => false,
                'escalation_reason' => null,
            ];
        }
    }

    /**
     * Ajusta el prompt user para pedir suggested_title cuando el ticket aún no tiene nombre.
     * Solo se invoca desde generate(); no altera build_user_content ni otros flujos.
     *
     * @param string $user_content Texto base de build_user_content.
     *
     * @return string
     */
    protected function append_title_suggestion_to_user_content(string $user_content): string
    {
        /* 🔴 Este bloque tiene que coincidir CARÁCTER POR CARÁCTER con el de build_user_content():
         * si los dos se desincronizan, el str_replace de abajo no encuentra nada, no falla, y el
         * ticket sin nombre deja de pedir suggested_title para siempre y sin ruido. Al tocar el
         * formato de salida, se tocan los dos juntos. */
        $standard_json_block = 'Generá una respuesta sugerida para el operador y explicá brevemente tu razonamiento. Respondé SOLO en JSON con este formato exacto:
{
  "suggested_message": "...",
  "reasoning": "...",
  "tipo_respuesta": "afirmacion_del_sistema|aclaracion|conversacional|escalado",
  "fuentes_kb": [],
  "should_close": false,
  "should_escalate": false,
  "escalation_reason": null
}';

        $title_json_block = 'Generá una respuesta sugerida para el operador y explicá brevemente tu razonamiento.

Este es el primer mensaje del ticket y aún no tiene título. Además de la respuesta sugerida, generá un título corto y descriptivo (máximo 6 palabras) que resuma el problema o consulta del cliente.

Respondé SOLO en JSON con este formato exacto:
{
  "suggested_message": "...",
  "suggested_title": "...",
  "reasoning": "...",
  "tipo_respuesta": "afirmacion_del_sistema|aclaracion|conversacional|escalado",
  "fuentes_kb": [],
  "should_close": false,
  "should_escalate": false,
  "escalation_reason": null
}';

        return str_replace($standard_json_block, $title_json_block, $user_content);
    }

    /**
     * Arma el system prompt indicando a Claude cómo usar las tools del repositorio.
     * Incluye la lista de archivos .md obtenida de GitHub en cada request.
     *
     * @param array<int, string> $fallos_repositorio Se llena con las partes del repositorio que
     *                                               no se pudieron cargar. El llamador escala en
     *                                               vez de responder: un agente sin índice ni
     *                                               protocolo de escalado no puede afirmar nada
     *                                               del sistema, y hasta ahora eso pasaba en
     *                                               silencio.
     *
     * @return string
     */
    protected function build_system_prompt(array &$fallos_repositorio = []): string
    {
        // Lista de archivos del manual inyectada en el prompt (no como tool).
        $file_list = $this->fetch_manual_file_list();

        if (trim($file_list) === '' || strpos($file_list, '(') === 0) {
            /* fetch_manual_file_list() devuelve su fallback entre paréntesis cuando la API falla
             * o el repositorio no tiene archivos. En los dos casos el agente se queda sin saber
             * qué puede consultar. */
            $fallos_repositorio[] = 'no se pudo leer el índice de archivos del manual';
        }

        // Protocolo de escalado y cierre leído directamente desde el repositorio.
        $escalation_rules = $this->fetch_escalation_rules();

        if (trim($escalation_rules) === '') {
            $fallos_repositorio[] = 'no se pudo leer el protocolo de escalado';
        }

        // Identidad compartida con el agente de leads: es la MISMA persona para el cliente,
        // antes y después de comprar. Se comparte solo quién es, no cómo trabaja: las
        // instrucciones operativas de leads traen calificación, agenda de demo y post-demo,
        // que no tienen nada que hacer en una conversación con alguien que ya es cliente.
        $identidad = $this->build_identity_block();

        return <<<SYSTEM
{$identidad}Tu tarea es sugerir al operador la respuesta más útil para el cliente.
Respondé siempre en español rioplatense.

ESTILO:
- Escribí como una persona real escribiría por WhatsApp: texto plano, sin asteriscos,
  sin guiones como viñetas, sin negritas, sin ningún símbolo de formato markdown.
- Sé claro y directo. No uses frases de relleno ni cierres genéricos del tipo
  "¿hay algo más en lo que te pueda ayudar?" o similares.
- Respondé lo que el cliente preguntó, sin agregar información que no pidió.
- No uses la palabra "toggle". Reemplazala siempre por "check".

CUÁNDO HACER UNA PREGUNTA:
- Si el mensaje del cliente es ambiguo o incompleto y necesitás más contexto
  para dar una respuesta útil, sugerí una pregunta como respuesta en lugar de
  asumir. Esto es preferible a dar una respuesta genérica que no resuelve nada.
- No hagas preguntas de cortesía ni de cierre. Solo preguntás cuando la respuesta
  depende de información que el cliente no dio.

VARIOS MENSAJES:
- Cuando el contenido lo permite, partí la respuesta en dos o tres mensajes cortos en vez de un
  bloque largo. Separalos con una línea que tenga solamente tres guiones. El sistema los manda uno
  tras otro y la conversación se siente como la de una persona, no como la de un formulario.
- Cada parte tiene que poder leerse sola. No partas una oración al medio ni dejes una parte que
  solo se entienda con la siguiente.
- Si la respuesta es corta, mandala en un solo mensaje. Partir de más también queda raro.

IMÁGENES:
- Cuando el cliente manda una captura de pantalla, mirala antes de contestar: leé el mensaje de
  error tal como está escrito, fijate en qué pantalla del sistema está y qué botones se ven.
- Si en la imagen hay un mensaje de error, citalo textual en tu respuesta. Al cliente le confirma
  que lo estás mirando de verdad, y evita que contestes sobre otra cosa.
- La imagen no reemplaza al manual: seguí necesitando get_manual_file para saber qué hacer con lo
  que viste. Reconocer un error no es lo mismo que saber cómo se resuelve.
- Si la imagen está borrosa, cortada o no se entiende, pedile otra en vez de adivinar.

Tenés acceso a la herramienta get_manual_file para leer archivos del repositorio de documentación de ComercioCity.
Antes de responder cualquier duda técnica o funcional del cliente, leé el archivo relevante usando get_manual_file.
Si no sabés cuál leer, empezá por README.md que contiene el índice y casos de uso frecuentes.

Archivos disponibles en el repositorio:
{$file_list}

{$escalation_rules}
SYSTEM;
    }

    /**
     * Arma el contenido del primer mensaje: las imágenes del cliente y después el texto.
     *
     * La doc de la API es explícita en que las imágenes rinden mejor ANTES del texto, y en que
     * conviene rotularlas para poder referirse a ellas. Sin imágenes devuelve el string pelado,
     * que es exactamente lo que había antes: un ticket sin fotos no cambia en nada.
     *
     * El `cache_control` del system prompt no se toca y sigue funcionando: la caché es por
     * prefijo y el orden de render es tools → system → messages, así que meter bloques en el
     * primer mensaje del usuario no invalida nada de lo que está antes.
     *
     * @param SupportTicket $ticket       Ticket en curso.
     * @param string        $user_content Texto ya armado con el historial y el formato de salida.
     *
     * @return array<int, array<string, mixed>>|string
     */
    protected function build_user_blocks(SupportTicket $ticket, string $user_content)
    {
        try {
            $collector = app(SupportAiImageCollector::class);
            $imagenes = $collector->collect((int) $ticket->id);
            $descartadas = $collector->descartadas();
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('SupportAiSuggestionService: no se pudieron juntar las imágenes.', [
                'ticket_id' => $ticket->id,
                'error'     => $exception->getMessage(),
            ]);

            return $user_content;
        }

        // Aviso de descarte: el historial le va a mostrar igual que hubo una imagen. Si no se
        // le dice que no la recibió, lo más probable es que invente qué decía.
        $aviso = '';
        if ($descartadas > 0) {
            $aviso = "\n\nAVISO: el cliente mandó " . $descartadas . " imagen(es) más que NO estás viendo en "
                . "esta consulta. Puede ser por el formato, por el tamaño, o simplemente porque solo se adjuntan "
                . "las últimas. NO supongas qué decían ni las des por vistas. Si tu respuesta depende de ellas, "
                . "pedile al cliente que te mande de nuevo la que importa, sacada como foto y no como archivo.";
        }

        if (empty($imagenes)) {
            return $aviso !== '' ? $user_content . $aviso : $user_content;
        }

        $bloques = [];
        $numero = 0;

        foreach ($imagenes as $imagen) {
            $numero++;
            $bloques[] = [
                'type' => 'text',
                'text' => 'Imagen ' . $numero . ', que mandó el cliente:',
            ];
            $bloques[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $imagen['media_type'],
                    'data'       => $imagen['data'],
                ],
            ];
        }

        $bloques[] = [
            'type' => 'text',
            'text' => $user_content . $aviso,
        ];

        return $bloques;
    }

    /**
     * Encabezado de identidad del agente, compartido con el agente de leads.
     *
     * Sale de `agent_identities`, que `AgentPromptSyncService` sincroniza desde
     * `agentes/lead/identidad.md` del repo de conocimiento cada diez minutos. O sea: cambiar
     * la personalidad no requiere deploy, y el agente de soporte y el de leads no se pueden
     * desincronizar entre sí, que es justo lo que pasaría con dos textos separados.
     *
     * Si no hay identidad activa se cae al encabezado de siempre: quedarse sin agente porque
     * nadie sincronizó un .md sería mucho peor que quedarse sin personalidad.
     *
     * @return string Bloque listo para encabezar el system prompt, terminado en salto de línea.
     */
    protected function build_identity_block(): string
    {
        $generico = "Sos un asistente de soporte técnico de ComercioCity, una plataforma de operación comercial para distribuidoras y comercios argentinos.
";

        try {
            $identidad = AgentIdentity::obtener_activo();
        } catch (\Throwable $exception) {
            Log::warning('SupportAiSuggestionService: no se pudo leer la identidad del agente.', [
                'error' => $exception->getMessage(),
            ]);

            return $generico;
        }

        if ($identidad === null) {
            return $generico;
        }

        $descripcion = trim((string) ($identidad->description ?? ''));
        if ($descripcion === '') {
            return $generico;
        }

        return $descripcion . "

"
            . "Eso es quién sos. Lo que sigue es tu trabajo en soporte: acá hablás con clientes que YA compraron
"
            . "y usan el sistema todos los días, no con alguien a quien le estás vendiendo. Nada de calificar, de
"
            . "ofrecer una demo ni de coordinar agenda: eso es de la otra punta de tu trabajo y acá no va.
";
    }

    /**
     * Lee el archivo escalation_rules.md del repositorio para inyectarlo en el system prompt.
     * Si la lectura falla, retorna una cadena vacía (fallback silencioso).
     *
     * @return string Bloque "PROTOCOLO DE ESCALADO Y CIERRE:" con el contenido del archivo,
     *                o cadena vacía si no está disponible.
     */
    protected function fetch_escalation_rules(): string
    {
        try {
            $content = $this->github_get_file('manual_sistema/escalation_rules.md');

            if (trim($content) === '') {
                return '';
            }

            return "PROTOCOLO DE ESCALADO Y CIERRE:\n".$content;
        } catch (\Throwable $e) {
            Log::warning('SupportAiSuggestionService: no se pudo leer escalation_rules.md.', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Obtiene la lista de archivos .md del manual, formateada como texto para inyectar en el
     * system prompt.
     *
     * Delega en `ManualRepositoryService` desde el 2/9/2026. Se conserva el método —y su
     * visibilidad `protected`— porque `build_system_prompt()` lo llama y porque los tests de
     * calidad del agente lo sustituyen por herencia para no salir a la red.
     *
     * Los dos textos de fallback se mantienen idénticos: el que arranca con paréntesis es lo que
     * `build_system_prompt()` detecta para llenar `$fallos_repositorio`, que a su vez dispara
     * `KnowledgeGroundingGate::escalar_por_repositorio_caido()`.
     *
     * @return string Lista con prefijo "- " por línea, o mensaje de fallback si falla la API.
     */
    protected function fetch_manual_file_list(): string
    {
        return app(ManualRepositoryService::class)->file_list();
    }

    /**
     * Construye el mensaje user con datos del cliente e historial completo del ticket.
     *
     * @param SupportTicket $ticket
     *
     * @return string
     */
    protected function build_user_content(SupportTicket $ticket): string
    {
        $client_name = $ticket->resolve_contact_display_name();

        $client_email = trim((string) ($ticket->client_user_email ?? ''));
        if ($client_email === '') {
            $client_email = 'sin email';
        }

        $historial = $this->format_conversation_history($ticket->id);

        /* Ficha operativa del cliente + los datos que se calculan leyendo la base al momento.
         * Hasta acá el agente atendía a los cuarenta clientes sabiendo sólo el nombre, el mail y
         * el historial de ESTE ticket.
         *
         * 🔴 De este bloque NO sale `client_support_contexts.notas_internas`: ese campo es para el
         * operador humano y nunca entra al prompt. La garantía es estructural y está en
         * ClientSupportContext::ficha_operativa_de_cliente() — un SELECT de una sola columna.
         *
         * 🔴 El servicio no lanza nunca: si una consulta falla, el bloque lo dice y el agente
         * contesta igual. Una excepción acá caería en el catch de generate() y dejaría al cliente
         * sin respuesta por no haber podido contar sus tickets. */
        $contexto_del_cliente = (new SupportClientContextService())->bloque_para_el_prompt($ticket);

        return <<<USER
Cliente: {$client_name} ({$client_email})

{$contexto_del_cliente}

Historial de la conversación:
{$historial}

Generá una respuesta sugerida para el operador y explicá brevemente tu razonamiento. Respondé SOLO en JSON con este formato exacto:
{
  "suggested_message": "...",
  "reasoning": "...",
  "tipo_respuesta": "afirmacion_del_sistema|aclaracion|conversacional|escalado",
  "fuentes_kb": [],
  "should_close": false,
  "should_escalate": false,
  "escalation_reason": null
}

Reglas para tipo_respuesta y fuentes_kb:
- tipo_respuesta describe qué clase de respuesta estás dando:
  - "afirmacion_del_sistema": decís qué hace, qué no hace o cómo se usa ComercioCity.
  - "aclaracion": le preguntás al cliente un dato SUYO que te falta para poder responder.
  - "conversacional": saludo, agradecimiento o cortesía, sin afirmar nada del sistema.
  - "escalado": no podés resolverlo y pedís revisión humana.
- fuentes_kb lleva las rutas EXACTAS de los archivos que leíste con get_manual_file en esta misma
  consulta y que respaldan lo que afirmás. Ejemplo: ["listado/precios.md"].
- Si tipo_respuesta es "afirmacion_del_sistema", fuentes_kb no puede estar vacío, y cada ruta tiene
  que ser una que hayas leído recién acá. El sistema lo verifica: si citás algo que no leíste, o no
  citás nada, tu respuesta NO se le manda al cliente y el caso se escala igual.
- No cites de memoria ni por el nombre del archivo en el índice: leelo primero con get_manual_file.
- El bloque "Contexto de este cliente" NO es una fuente y nunca va en fuentes_kb. Sirve para saber
  a quién le estás hablando, ajustar el tono y no volver a pedir datos que ya tenés. No lo uses
  para respaldar una afirmacion_del_sistema: para eso está el manual, sin excepción. Si lo único
  que tenés es la ficha, lo que estás por decir todavía no está verificado.

Reglas para should_close y should_escalate:
- should_close y should_escalate son mutuamente excluyentes: nunca ambos en true al mismo tiempo.
- Usá should_close: true solo cuando el caso está completamente resuelto y el cliente no necesita más ayuda.
- Usá should_escalate: true solo cuando no podés resolver el caso con la información disponible y es necesaria la intervención de un operador humano.
- Si should_escalate es true, completá escalation_reason con un texto corto explicando por qué escalás.
- Si should_escalate es true, usá como suggested_message el mensaje de espera definido en el protocolo de escalado.
USER;
    }

    /**
     * Formatea todos los mensajes del ticket para el prompt en orden cronológico.
     *
     * @param int $ticket_id
     *
     * @return string
     */
    protected function format_conversation_history(int $ticket_id): string
    {
        $messages = SupportMessage::where('support_ticket_id', $ticket_id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return '(Sin mensajes todavía.)';
        }

        $lines = [];
        foreach ($messages as $message) {
            // Mensajes de voz: el body ya es la transcripción; el prefijo aclara el contexto a Claude.
            if ($message->sender_type === 'admin') {
                $label = 'Operador';
            } elseif ($message->kind === 'audio') {
                $label = 'Cliente (audio transcripto)';
            } else {
                $label = 'Cliente';
            }

            $body = trim((string) ($message->body ?? ''));
            if ($body === '') {
                $body = '['.strtoupper((string) $message->kind).']';
            }

            /* Si el operador corrigió lo que había propuesto el agente, se marca y se manda el
             * texto que de verdad salió. Es la señal más barata que tiene el agente para
             * aprender en qué se equivoca: mismo criterio que el historial de leads. */
            if (trim((string) ($message->ai_original_body ?? '')) !== '') {
                $label .= ' (corrigió la sugerencia del agente)';
            }

            $lines[] = $label.': '.$body;
        }

        return implode("\n", $lines);
    }

    /**
     * Devuelve el array de tools para la API de Anthropic (tool use / function calling).
     * Solo expone get_manual_file; la lista de archivos va en el system prompt.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function build_github_tools(): array
    {
        return [
            [
                'name'        => 'get_manual_file',
                'description' => 'Lee el contenido de un archivo del repositorio de documentación de ComercioCity. Usá esta herramienta para consultar el manual antes de responder dudas del cliente. El repositorio está organizado en carpetas por módulo y cada archivo tiene un frontmatter con modulo, tema y keywords para que puedas inferir cuál leer.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Ruta del archivo dentro del repo. Ejemplo: "listado/precios.md" o "general/interfaz-tablas-y-formularios.md".',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ];
    }

    /**
     * Ejecuta las tool calls de un bloque de contenido del asistente y retorna los tool_result.
     *
     * @param array<int, mixed>  $content_blocks Bloques content devueltos por Claude.
     * @param int|string         $ticket_id      Para logging.
     * @param array<int, string> $leidas         Se le agregan los paths leídos CON ÉXITO. Es la
     *                                           evidencia contra la que el gate verifica las
     *                                           citas del agente: un path cuya lectura falló no
     *                                           entra acá, aunque el agente después lo cite.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function execute_tool_calls(array $content_blocks, $ticket_id, array &$leidas = []): array
    {
        $tool_results = [];

        foreach ($content_blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $tool_id   = (string) ($block['id'] ?? '');
            $tool_name = (string) ($block['name'] ?? '');
            $tool_input = $block['input'] ?? [];

            try {
                if ($tool_name === 'get_manual_file') {
                    $path = (string) ($tool_input['path'] ?? '');
                    $content = $this->github_get_file($path);

                    /* Lectura efectiva: se anota recién acá, después de que github_get_file()
                     * devolvió sin lanzar. Si tira, la ejecución salta al catch y este path no
                     * queda registrado — que es justamente lo que hace verificable la cita. */
                    $leidas[] = $path;
                } else {
                    $content = 'Tool desconocida: '.$tool_name;
                }

                $tool_results[] = [
                    'type'       => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'content'    => $content,
                ];
            } catch (\Throwable $exception) {
                Log::warning('SupportAiSuggestionService: error en tool call.', [
                    'ticket_id' => $ticket_id,
                    'tool'      => $tool_name,
                    'error'     => $exception->getMessage(),
                ]);

                // Devuelve error a Claude para que pueda continuar sin romper el flujo.
                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'is_error'    => true,
                    'content'     => 'Error al ejecutar '.$tool_name.': '.$exception->getMessage(),
                ];
            }
        }

        return $tool_results;
    }

    /**
     * Normaliza bloques content del asistente para reenviarlos a Anthropic en el agentic loop.
     * json_decode convierte input:{} en array vacío []; la API exige object en tool_use.input.
     * También elimina campos de respuesta (p. ej. caller) que no acepta el request.
     *
     * @param array<int, mixed> $content_blocks Bloques devueltos por Claude.
     *
     * @return array<int, mixed>
     */
    protected function normalize_assistant_content_for_api(array $content_blocks): array
    {
        $normalized = [];

        foreach ($content_blocks as $block) {
            if (! is_array($block)) {
                $normalized[] = $block;
                continue;
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $input = $block['input'] ?? [];
                // json_decode convierte {} en array vacío; Anthropic exige object en tool_use.input.
                if (! is_array($input) || $input === []) {
                    $block['input'] = new \stdClass();
                } else {
                    $block['input'] = (object) $input;
                }
                unset($block['caller']);
            }

            $normalized[] = $block;
        }

        return $normalized;
    }

    /**
     * Extrae mensaje legible del cuerpo de error de Anthropic.
     *
     * @param array<string, mixed>|null $body   JSON de error.
     * @param int                       $status Código HTTP.
     *
     * @return string
     */
    protected function extract_anthropic_error_message($body, int $status): string
    {
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $message = trim((string) ($body['error']['message'] ?? ''));
            if ($message !== '') {
                return 'Error Anthropic HTTP '.$status.': '.$message;
            }
        }

        return 'Error Anthropic HTTP '.$status.'.';
    }

    /**
     * Descarga y decodifica el contenido de un archivo del manual.
     *
     * Delega en `ManualRepositoryService` desde el 2/9/2026, con la misma firma y la misma
     * conducta: lanza si no pudo leer, para que `execute_tool_calls()` no anote el path en
     * `$leidas` y la cita del agente no quede respaldada por una lectura que nunca ocurrió.
     *
     * Cambia un detalle sin efecto práctico: una ruta fuera de `manual_sistema/` ahora la rechaza
     * la guarda del servicio en vez de morir en un 404 de GitHub. Los dos casos ya terminaban en
     * la misma excepción y en el mismo `catch`.
     *
     * @param string $path Ruta dentro del repo, con el prefijo `manual_sistema/` incluido.
     *
     * @return string Contenido del archivo en texto plano.
     *
     * @throws \RuntimeException Si la ruta está vacía, cae fuera del manual, o la API responde
     *                           con error.
     */
    protected function github_get_file(string $path): string
    {
        return app(ManualRepositoryService::class)->get_file($path);
    }

    /**
     * Cliente HTTP hacia Anthropic con la misma configuración TLS que leads.
     *
     * @return PendingRequest
     */
    protected function build_http_client(): PendingRequest
    {
        $api_key = (string) config('services.anthropic.api_key');

        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'anthropic-beta'    => 'prompt-caching-2024-07-31',
            'content-type'      => 'application/json',
        ])->timeout(120);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Extrae el texto de los bloques text del último mensaje del asistente.
     *
     * @param array<string, mixed> $body Respuesta JSON de Anthropic.
     *
     * @return string
     */
    protected function extract_response_text(array $body): string
    {
        $text = '';

        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text']) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) $block['text'];
                }
            }
        }

        return $text;
    }

    /**
     * Decodifica el JSON embebido en la respuesta de Claude.
     *
     * @param string $raw
     *
     * @return array<string, mixed>
     */
    protected function parse_json_response(string $raw): array
    {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('Claude no devolvió JSON válido.');
        }

        $json = substr($raw, $start, $end - $start + 1);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \RuntimeException('JSON inválido: '.json_last_error_msg());
        }

        return $data;
    }
}
