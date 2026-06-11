<?php

namespace App\Services;

use App\Events\EcommerceImplementationStageCompleted;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationMessage;
use App\Models\EcommerceImplementationStage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de conversación WhatsApp para el flujo de implementación de la tienda online.
 *
 * Sigue la misma estructura que ImplementationConversationService: la Etapa 1 se
 * resuelve de a una pregunta por vez usando `data['current_question']` como tracking.
 * Las Etapas 2 a 5 son mayormente manuales y solo envían mensajes de apertura/cierre.
 *
 * La Etapa 1 incluye dos pasos automáticos: la sugerencia de dominio (al abrir la etapa)
 * y el análisis del logo para sugerir colores (tras recolectar las redes sociales).
 */
class EcommerceImplementationConversationService
{
    /**
     * @var WhatsappSendService Envío saliente vía Kapso.
     */
    private $whatsapp_send_service;

    /**
     * @var ImplementationAiInterpreter Intérprete semántico de respuestas del cliente via Claude.
     */
    private $ai_interpreter;

    /**
     * @param WhatsappSendService|null         $whatsapp_send_service Inyección opcional para tests.
     * @param ImplementationAiInterpreter|null $ai_interpreter        Inyección opcional para tests.
     */
    public function __construct(
        ?WhatsappSendService $whatsapp_send_service = null,
        ?ImplementationAiInterpreter $ai_interpreter = null
    ) {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
        $this->ai_interpreter        = $ai_interpreter ?? new ImplementationAiInterpreter();
    }

    /**
     * Punto de entrada: enruta según la etapa actual de la implementación de ecommerce.
     *
     * @param EcommerceImplementation $implementation Implementación activa.
     * @param array<string, mixed>    $parsed         Mensaje entrante parseado (from, type, body…).
     *
     * @return void
     */
    public function handle(EcommerceImplementation $implementation, array $parsed): void
    {
        $current_stage = (int) $implementation->current_stage;

        if ($current_stage === 1) {
            $this->handle_stage_1($implementation, $parsed);
            return;
        }

        // Etapas 2 a 5: el trabajo es manual. Responder con un acuse de espera.
        $phone = (string) $parsed['from'];

        if ($current_stage === 2) {
            $this->send_outbound($implementation, 2, $phone, 'Estamos comprando y delegando tu dominio. Te avisamos apenas esté listo. 🙌');
            return;
        }

        if ($current_stage === 3 || $current_stage === 4) {
            $this->send_outbound($implementation, $current_stage, $phone, 'Estamos terminando de instalar tu tienda online. En breve te avisamos. 🛠️');
            return;
        }

        if ($current_stage === 5) {
            $this->send_outbound($implementation, 5, $phone, 'Tu tienda online ya está activa. Cualquier duda, escribinos por acá. 😊');
            return;
        }

        Log::channel('daily')->info('EcommerceImplementationConversationService: etapa fuera del rango implementado.', [
            'ecommerce_implementation_id' => $implementation->id,
            'current_stage'               => $current_stage,
        ]);
    }

    // -------------------------------------------------------------------------
    // Apertura de etapas
    // -------------------------------------------------------------------------

    /**
     * Envía el mensaje inicial de la etapa indicada (apertura proactiva).
     *
     * - Etapa 1: sugerencia de dominio al cliente.
     * - Etapa 2: aviso de compra/delegación del dominio al cliente.
     * - Etapa 3: aviso al admin para instalar tienda-api.
     * - Etapa 4: aviso al admin para compilar y subir tienda-spa.
     * - Etapa 5: aviso al cliente con el link de la tienda activa.
     *
     * @param EcommerceImplementation $implementation
     * @param int                     $stage
     *
     * @return void
     */
    public function send_stage_opening_message(EcommerceImplementation $implementation, int $stage): void
    {
        if ($stage === 1) {
            $this->send_stage_1_opening($implementation);
            return;
        }

        if ($stage === 2) {
            $this->send_stage_2_opening($implementation);
            return;
        }

        if ($stage === 3) {
            $client_name = $this->resolve_client_name($implementation);
            $this->notify_assigned_admin($implementation, "🛠️ {$client_name} — Ecommerce Etapa 3: instalá tienda-api en el servidor.");
            return;
        }

        if ($stage === 4) {
            $client_name = $this->resolve_client_name($implementation);
            $this->notify_assigned_admin($implementation, "🛠️ {$client_name} — Ecommerce Etapa 4: compilá y subí tienda-spa.");
            return;
        }

        if ($stage === 5) {
            $this->send_stage_5_opening($implementation);
            return;
        }

        Log::channel('daily')->warning('EcommerceImplementationConversationService: apertura no implementada para esta etapa.', [
            'ecommerce_implementation_id' => $implementation->id,
            'stage'                       => $stage,
        ]);
    }

    /**
     * Ejecuta las acciones automáticas al avanzar a una nueva etapa desde el controller.
     *
     * @param EcommerceImplementation $implementation
     * @param int                     $new_stage
     *
     * @return void
     */
    public function handle_stage_advance(EcommerceImplementation $implementation, int $new_stage): void
    {
        if ($new_stage >= 2 && $new_stage <= 5) {
            $this->send_stage_opening_message($implementation, $new_stage);
        }
    }

    /**
     * Apertura de la Etapa 1: sugiere un dominio basado en el nombre del negocio.
     *
     * Es idempotente: si ya se registró current_question, no reenvía.
     *
     * @param EcommerceImplementation $implementation
     *
     * @return void
     */
    private function send_stage_1_opening(EcommerceImplementation $implementation): void
    {
        $stage = $this->stage_record($implementation, 1);

        if ($stage === null) {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: stage 1 no encontrado para apertura.', [
                'ecommerce_implementation_id' => $implementation->id,
            ]);
            return;
        }

        $data = is_array($stage->data) ? $stage->data : [];

        // Idempotente: si ya se abrió la etapa, no reenviar.
        if (array_key_exists('current_question', $data)) {
            return;
        }

        $client = $this->resolve_client($implementation);
        $phone  = trim((string) ($client->phone ?? ''));

        if ($phone === '') {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: cliente sin teléfono para apertura de Etapa 1.', [
                'ecommerce_implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Dominio sugerido a partir del nombre del negocio.
        $suggested_domain                = $this->build_domain_suggestion($client);
        $data['suggested_domain']        = $suggested_domain;
        $data['current_question']        = 'domain_confirmed';
        $stage->data                     = $data;
        $stage->save();

        $message = "Arrancamos con la configuración de tu tienda online. Para el dominio, te sugiero {$suggested_domain} — ¿te parece bien o preferís otro nombre?";
        $this->send_outbound($implementation, 1, $phone, $message);
    }

    /**
     * Apertura de la Etapa 2: avisa al cliente que se comprará y delegará el dominio.
     *
     * @param EcommerceImplementation $implementation
     *
     * @return void
     */
    private function send_stage_2_opening(EcommerceImplementation $implementation): void
    {
        $client = $this->resolve_client($implementation);
        $phone  = trim((string) ($client->phone ?? ''));

        if ($phone === '') {
            return;
        }

        // Dominio final elegido en la Etapa 1.
        $domain = $this->resolve_ecommerce_domain($implementation);

        $message = "Perfecto, ya tenemos todo para arrancar. El próximo paso es comprar el dominio {$domain} en NIC.ar y delegarlo a Hostinger. Te avisamos cuando esté listo.";
        $this->send_outbound($implementation, 2, $phone, $message);
    }

    /**
     * Apertura de la Etapa 5: avisa al cliente que su tienda ya está activa.
     *
     * @param EcommerceImplementation $implementation
     *
     * @return void
     */
    private function send_stage_5_opening(EcommerceImplementation $implementation): void
    {
        $client = $this->resolve_client($implementation);
        $phone  = trim((string) ($client->phone ?? ''));

        if ($phone === '') {
            return;
        }

        // URL pública de la tienda (spa_url del client_ecommerce).
        $client_ecommerce = $this->resolve_client_ecommerce($implementation);
        $spa_url          = $client_ecommerce ? trim((string) ($client_ecommerce->spa_url ?? '')) : '';
        $spa_url          = $spa_url !== '' ? $spa_url : $this->resolve_ecommerce_domain($implementation);

        $message = "¡Tu tienda online ya está lista! Podés verla en {$spa_url}. Cualquier duda escribinos por acá.";
        $this->send_outbound($implementation, 5, $phone, $message);
    }

    // -------------------------------------------------------------------------
    // Etapa 1 — Configuración de la tienda
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 1 de configuración de la tienda.
     *
     * @param EcommerceImplementation $implementation
     * @param array<string, mixed>    $parsed
     *
     * @return void
     */
    private function handle_stage_1(EcommerceImplementation $implementation, array $parsed): void
    {
        $stage = $this->stage_record($implementation, 1);

        if ($stage === null) {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: stage 1 no encontrado.', [
                'ecommerce_implementation_id' => $implementation->id,
            ]);
            return;
        }

        $data   = is_array($stage->data) ? $stage->data : [];
        $phone  = (string) $parsed['from'];
        $body   = trim((string) ($parsed['body'] ?? ''));
        $client = $this->resolve_client($implementation);

        // Si la etapa no fue abierta aún (no debería ocurrir), abrirla.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_1_opening($implementation);
            return;
        }

        $current_question = (string) $data['current_question'];

        if ($current_question === 'completed') {
            return;
        }

        // --- Confirmación / elección de dominio ---
        if ($current_question === 'domain_confirmed') {
            $suggested = (string) ($data['suggested_domain'] ?? '');
            $question_text = "Para el dominio, te sugiero {$suggested} — ¿te parece bien o preferís otro nombre?";

            $interpretation = $this->ai_interpreter->interpret('domain_confirmation', $question_text, $body);
            $chosen_domain  = $interpretation['value'] ?? null;

            // Si Claude no pudo resolver, usar el dominio sugerido como fallback al confirmar.
            $final_domain = is_string($chosen_domain) && trim($chosen_domain) !== ''
                ? $this->normalize_domain($chosen_domain)
                : $suggested;

            $data['domain'] = $final_domain;
            $this->advance_stage_1($stage, $data, 'online_price_type', $phone, $implementation, $client);
            return;
        }

        // --- Tipo de visibilidad de precios online ---
        if ($current_question === 'online_price_type') {
            $question_text = $this->build_stage1_question_text('online_price_type');
            $interpretation = $this->ai_interpreter->interpret('online_price_type', $question_text, $body);
            $value          = $interpretation['value'] ?? null;

            if (! in_array($value, [1, 2, 3], true)) {
                $this->send_outbound($implementation, 1, $phone, 'No entendí bien tu respuesta. ' . $question_text);
                return;
            }

            $data['online_price_type_id'] = (int) $value;
            $this->advance_stage_1($stage, $data, 'register_to_buy', $phone, $implementation, $client);
            return;
        }

        // --- Preguntas booleanas ---
        $bool_questions = [
            'register_to_buy'   => 'has_delivery',
            'has_delivery'      => 'retiro_por_local',
            'retiro_por_local'  => 'notify_whatsapp',
            'notify_whatsapp'   => 'social_networks',
        ];

        if (array_key_exists($current_question, $bool_questions)) {
            $question_text = $this->build_stage1_question_text($current_question);
            $result        = $this->ai_interpreter->interpret('yes_no', $question_text, $body);
            $value         = $result['value'] ?? null;

            if ($value !== true && $value !== false) {
                $this->send_outbound($implementation, 1, $phone, 'No entendí bien tu respuesta. ' . $question_text);
                return;
            }

            // Guardar con la clave de negocio correspondiente.
            if ($current_question === 'notify_whatsapp') {
                $data['enviar_whatsapp_al_terminar_pedido'] = $value;
            } else {
                $data[$current_question] = $value;
            }

            $this->advance_stage_1($stage, $data, $bool_questions[$current_question], $phone, $implementation, $client);
            return;
        }

        // --- Redes sociales (misma lógica que la Etapa 1 del sistema) ---
        if ($current_question === 'social_networks') {
            $this->handle_stage_1_social_networks($stage, $data, $body, $phone, $implementation, $client);
            return;
        }

        // --- Confirmación de colores ---
        if ($current_question === 'colors_confirmed') {
            $this->handle_stage_1_colors_confirmed($stage, $data, $body, $phone, $implementation, $client);
            return;
        }

        // --- Quiénes somos ---
        if ($current_question === 'quienes_somos') {
            // Detectar si el cliente no quiere agregar la sección.
            $result   = $this->ai_interpreter->interpret('yes_no', '¿Querés agregar una sección Quiénes somos?', $body);
            $negative = ($result['value'] ?? null) === false;

            if ($negative) {
                $data['quienes_somos'] = null;
            } else {
                // Cualquier texto provisto se publica en la sección.
                $data['quienes_somos'] = $body !== '' ? $body : null;
            }

            // Última pregunta: finalizar la etapa.
            $data['current_question'] = 'completed';
            $data['completed']        = true;
            $stage->data              = $data;
            $stage->save();

            $this->finish_stage_1($implementation, $phone, $client);
            return;
        }
    }

    /**
     * Maneja la pregunta de redes sociales en el flujo de ecommerce.
     *
     * Tras guardarlas, ejecuta automáticamente el análisis del logo para sugerir colores.
     *
     * @param EcommerceImplementationStage $stage
     * @param array<string, mixed>         $data
     * @param string                       $body
     * @param string                       $phone
     * @param EcommerceImplementation      $implementation
     * @param Client|null                  $client
     *
     * @return void
     */
    private function handle_stage_1_social_networks(
        EcommerceImplementationStage $stage,
        array $data,
        string $body,
        string $phone,
        EcommerceImplementation $implementation,
        ?Client $client
    ): void {
        $question_text  = $this->build_stage1_question_text('social_networks');
        $interpretation = $this->ai_interpreter->interpret('social_networks', $question_text, $body);
        $value          = $interpretation['value'] ?? null;

        if (! is_array($value)) {
            $this->send_outbound($implementation, 1, $phone, 'No entendí bien tu respuesta. ' . $question_text);
            return;
        }

        $instagram = trim((string) ($value['instagram'] ?? ''));
        $facebook  = trim((string) ($value['facebook'] ?? ''));
        $is_none   = ($value['none'] ?? false) === true;

        if ($is_none || ($instagram === '' && $facebook === '')) {
            $data['social_networks'] = 'none';
        } else {
            $data['social_networks'] = 'provided';
            if ($instagram !== '') {
                $data['instagram'] = $instagram;
            }
            if ($facebook !== '') {
                $data['facebook'] = $facebook;
            }
        }

        // Paso automático: análisis del logo para sugerir colores.
        $this->run_logo_colors_step($stage, $data, $phone, $implementation, $client);
    }

    /**
     * Paso automático: analiza el logo del cliente y sugiere una paleta de colores.
     *
     * Envía la sugerencia al cliente y deja la conversación en 'colors_confirmed'.
     *
     * @param EcommerceImplementationStage $stage
     * @param array<string, mixed>         $data
     * @param string                       $phone
     * @param EcommerceImplementation      $implementation
     * @param Client|null                  $client
     *
     * @return void
     */
    private function run_logo_colors_step(
        EcommerceImplementationStage $stage,
        array $data,
        string $phone,
        EcommerceImplementation $implementation,
        ?Client $client
    ): void {
        // URL del logo: primero la guardada en la implementación de sistema (setup_data),
        // luego la del stage 1 del sistema como fallback.
        $logo_url = $this->resolve_logo_url($client);

        // Paleta por defecto si no se puede analizar el logo.
        $default_colors = [
            'primary_color'    => '#0d6efd',
            'secondary_color'  => '#6c757d',
            'text_color'       => '#ffffff',
            'hover_text_color' => '#e9ecef',
        ];

        $suggested = $logo_url !== '' ? $this->analyze_logo_colors($logo_url) : null;
        $suggested = is_array($suggested) ? $suggested : $default_colors;

        $data['suggested_colors'] = $suggested;
        $data['current_question'] = 'colors_confirmed';
        $stage->data              = $data;
        $stage->save();

        $message = $this->build_colors_message($suggested);
        $this->send_outbound($implementation, 1, $phone, $message);
    }

    /**
     * Procesa la confirmación de colores: acepta la paleta o ajusta según el pedido del cliente.
     *
     * @param EcommerceImplementationStage $stage
     * @param array<string, mixed>         $data
     * @param string                       $body
     * @param string                       $phone
     * @param EcommerceImplementation      $implementation
     * @param Client|null                  $client
     *
     * @return void
     */
    private function handle_stage_1_colors_confirmed(
        EcommerceImplementationStage $stage,
        array $data,
        string $body,
        string $phone,
        EcommerceImplementation $implementation,
        ?Client $client
    ): void {
        // Paleta actualmente sugerida.
        $suggested = is_array($data['suggested_colors'] ?? null) ? $data['suggested_colors'] : [];

        // Interpretar si el cliente acepta o pide cambios.
        $result = $this->ai_interpreter->interpret('colors_change', $this->build_colors_message($suggested), $body);
        $accepts = ($result['value'] ?? null) === true;

        if ($accepts) {
            // Colores finales confirmados.
            $data['colors']           = $suggested;
            $data['current_question'] = 'quienes_somos';
            $stage->data              = $data;
            $stage->save();

            $next_text = $this->build_stage1_question_text('quienes_somos');
            $this->send_outbound($implementation, 1, $phone, 'Genial, dejamos esos colores. ' . $next_text);
            return;
        }

        // El cliente pidió cambios: ajustar la paleta con Claude según su pedido.
        $adjusted  = $this->adjust_logo_colors($suggested, $body);
        $adjusted  = is_array($adjusted) ? $adjusted : $suggested;

        $data['suggested_colors'] = $adjusted;
        $stage->data              = $data;
        $stage->save();

        $message = 'Ajusté la paleta. ' . $this->build_colors_message($adjusted);
        $this->send_outbound($implementation, 1, $phone, $message);
    }

    /**
     * Avanza la Etapa 1 a la siguiente pregunta (o ejecuta el paso de colores si corresponde).
     *
     * @param EcommerceImplementationStage $stage
     * @param array<string, mixed>         $data
     * @param string                       $next_question
     * @param string                       $phone
     * @param EcommerceImplementation      $implementation
     * @param Client|null                  $client
     *
     * @return void
     */
    private function advance_stage_1(
        EcommerceImplementationStage $stage,
        array $data,
        string $next_question,
        string $phone,
        EcommerceImplementation $implementation,
        ?Client $client
    ): void {
        $data['current_question'] = $next_question;
        $stage->data              = $data;
        $stage->save();

        $question_text = $this->build_stage1_question_text($next_question);
        $this->send_outbound($implementation, 1, $phone, $question_text);
    }

    /**
     * Cierra la Etapa 1: guarda la configuración en client_ecommerce, notifica al admin
     * y dispara el evento Pusher.
     *
     * @param EcommerceImplementation $implementation
     * @param string                  $phone
     * @param Client|null             $client
     *
     * @return void
     */
    private function finish_stage_1(EcommerceImplementation $implementation, string $phone, ?Client $client): void
    {
        $stage = $this->stage_record($implementation, 1);
        $data  = ($stage !== null && is_array($stage->data)) ? $stage->data : [];

        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Persistir dominio y configuración recolectada en la tienda del cliente.
        $client_ecommerce = $this->resolve_client_ecommerce($implementation);

        if ($client_ecommerce !== null) {
            $client_ecommerce->domain               = (string) ($data['domain'] ?? $client_ecommerce->domain);
            $client_ecommerce->ecommerce_setup_data = $data;
            $client_ecommerce->save();
        }

        // Marcar la etapa 1 como completada.
        if ($stage !== null) {
            $stage->status       = 'completed';
            $stage->completed_at = now();
            $stage->save();
        }

        // Confirmación al cliente.
        $this->send_outbound($implementation, 1, $phone, "¡Listo {$client_name}! Ya tengo todo para armar tu tienda online. Te aviso cuando esté el próximo paso. 🛍️");

        // Notificación al admin asignado.
        $this->notify_assigned_admin($implementation, "✅ {$client_name} completó la Etapa 1 del ecommerce (configuración de la tienda).");

        // Evento Pusher para el panel admin.
        event(new EcommerceImplementationStageCompleted($implementation->id, 1, $client_name));
    }

    // -------------------------------------------------------------------------
    // Textos de preguntas y mensajes
    // -------------------------------------------------------------------------

    /**
     * Retorna el texto de cada pregunta textual de la Etapa 1.
     *
     * @param string $key Clave de la pregunta.
     *
     * @return string
     */
    private function build_stage1_question_text(string $key): string
    {
        switch ($key) {
            case 'online_price_type':
                return "¿Quién puede ver los precios de tu tienda? Tenés tres opciones: 1. Cualquier persona que entre / 2. Solo usuarios registrados / 3. Solo clientes que ya tengas cargados en el sistema";
            case 'register_to_buy':
                return "¿Necesitan registrarse para comprar, o cualquiera puede hacer un pedido directamente?";
            case 'has_delivery':
                return "¿Hacés entregas a domicilio?";
            case 'retiro_por_local':
                return "¿Se puede retirar en el local?";
            case 'notify_whatsapp':
                return "¿Querés recibir un WhatsApp cada vez que te entre un pedido?";
            case 'social_networks':
                return "¿Tenés Instagram o Facebook del negocio? Los podemos mostrar en la tienda.";
            case 'quienes_somos':
                return "¿Querés agregar una sección 'Quiénes somos' en la tienda? Si querés, contame brevemente tu negocio y lo publicamos ahí.";
            default:
                return '';
        }
    }

    /**
     * Construye el mensaje de sugerencia de colores a partir de la paleta.
     *
     * @param array<string, mixed> $colors
     *
     * @return string
     */
    private function build_colors_message(array $colors): string
    {
        $primary   = (string) ($colors['primary_color'] ?? '');
        $secondary = (string) ($colors['secondary_color'] ?? '');
        $text      = (string) ($colors['text_color'] ?? '');

        return "Basándome en tu logo, te propongo estos colores para la tienda: Principal {$primary}, Secundario {$secondary}, Texto {$text}. ¿Te gustan o querés cambiar alguno?";
    }

    // -------------------------------------------------------------------------
    // Análisis del logo con Claude (sugerencia y ajuste de colores)
    // -------------------------------------------------------------------------

    /**
     * Llama a Claude pasando la URL del logo como imagen y devuelve una paleta de colores en hex.
     *
     * @param string $logo_url URL pública del logo.
     *
     * @return array<string, string>|null Paleta {primary_color, secondary_color, text_color, hover_text_color} o null.
     */
    private function analyze_logo_colors(string $logo_url): ?array
    {
        // Prompt de análisis del logo.
        $prompt = 'Analizá el logo y sugerí 4 colores en hex para una tienda online: '
            . 'primary_color (color dominante), secondary_color (color complementario), '
            . 'text_color (para texto sobre fondo de color), hover_text_color (para texto en hover). '
            . 'Respondé solo JSON: {primary_color, secondary_color, text_color, hover_text_color}';

        // Bloques de contenido: imagen (source url) + texto del prompt.
        $content = [
            [
                'type'   => 'image',
                'source' => [
                    'type' => 'url',
                    'url'  => $logo_url,
                ],
            ],
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];

        $raw = $this->claude_messages_request($content);

        return $this->parse_colors_json($raw);
    }

    /**
     * Ajusta la paleta de colores actual según el pedido en lenguaje natural del cliente.
     *
     * @param array<string, mixed> $current Paleta actual.
     * @param string               $request Pedido del cliente (ej: "quiero más verde").
     *
     * @return array<string, string>|null Paleta ajustada o null si falla.
     */
    private function adjust_logo_colors(array $current, string $request): ?array
    {
        $current_json = json_encode($current);

        $prompt = "Esta es la paleta actual de una tienda online en JSON: {$current_json}. "
            . "El cliente pide el siguiente cambio: \"{$request}\". "
            . 'Devolvé la paleta ajustada respetando el pedido. '
            . 'Respondé solo JSON: {primary_color, secondary_color, text_color, hover_text_color}';

        $content = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];

        $raw = $this->claude_messages_request($content);

        return $this->parse_colors_json($raw);
    }

    /**
     * Realiza una llamada a la API de mensajes de Claude y devuelve el texto generado.
     *
     * @param array<int, array<string, mixed>> $content Bloques de contenido del mensaje del usuario.
     *
     * @return string|null Texto crudo de la respuesta, o null ante error.
     */
    private function claude_messages_request(array $content): ?string
    {
        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: api_key de Anthropic no configurada.');
            return null;
        }

        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');

        try {
            $http = Http::withHeaders([
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(20);

            // Manejo de SSL configurable (igual que ImplementationAiInterpreter).
            $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
            $ca_bundle  = config('services.anthropic.ca_bundle');

            if (! $verify_ssl) {
                $http = $http->withoutVerifying();
            } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
                $http = $http->withOptions(['verify' => $ca_bundle]);
            }

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 256,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $content,
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::channel('daily')->warning('EcommerceImplementationConversationService: error HTTP de Anthropic.', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            // Concatenar el texto de los bloques de contenido devueltos.
            $raw_text       = '';
            $content_blocks = $response->json('content', []);

            if (is_array($content_blocks)) {
                foreach ($content_blocks as $block) {
                    if (is_array($block) && isset($block['text'])) {
                        $raw_text .= (string) $block['text'];
                    }
                }
            }

            return trim($raw_text);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: excepción al llamar a Anthropic.', [
                'message' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrae y valida una paleta de colores desde el texto JSON devuelto por Claude.
     *
     * @param string|null $raw Texto crudo de la respuesta.
     *
     * @return array<string, string>|null Paleta normalizada o null si inválida.
     */
    private function parse_colors_json(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Extraer el bloque JSON aunque venga rodeado de texto.
        if (preg_match('/\{.*\}/s', $raw, $matches)) {
            $raw = $matches[0];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Validar las claves esperadas; devolver solo strings.
        $keys   = ['primary_color', 'secondary_color', 'text_color', 'hover_text_color'];
        $colors = [];

        foreach ($keys as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                $colors[$key] = trim($decoded[$key]);
            }
        }

        // Requerir al menos el color primario para considerar válida la paleta.
        return isset($colors['primary_color']) ? $colors : null;
    }

    // -------------------------------------------------------------------------
    // Helpers de dominio, modelos y normalización
    // -------------------------------------------------------------------------

    /**
     * Construye un dominio sugerido a partir del nombre del negocio.
     *
     * @param Client|null $client
     *
     * @return string Dominio en formato slug + .com.ar.
     */
    private function build_domain_suggestion(?Client $client): string
    {
        // Base: company_name o name del cliente.
        $base = '';
        if ($client !== null) {
            $base = trim((string) ($client->company_name ?? ''));
            if ($base === '') {
                $base = trim((string) ($client->name ?? ''));
            }
        }

        if ($base === '') {
            $base = 'mitienda';
        }

        return $this->slugify($base) . '.com.ar';
    }

    /**
     * Normaliza un dominio escrito por el cliente: minúsculas, sin protocolo ni espacios,
     * y con extensión .com.ar por defecto si no incluye una.
     *
     * @param string $domain
     *
     * @return string
     */
    private function normalize_domain(string $domain): string
    {
        $clean = strtolower(trim($domain));

        // Quitar protocolo y www.
        $clean = preg_replace('#^https?://#', '', $clean) ?? $clean;
        $clean = preg_replace('#^www\.#', '', $clean) ?? $clean;
        // Quitar cualquier path posterior al dominio.
        $clean = preg_split('#[/\s]+#', $clean)[0] ?? $clean;
        $clean = trim($clean);

        if ($clean === '') {
            return '';
        }

        // Agregar extensión por defecto si el cliente solo escribió el nombre.
        if (strpos($clean, '.') === false) {
            $clean = $this->slugify($clean) . '.com.ar';
        }

        return $clean;
    }

    /**
     * Convierte un texto a slug apto para dominio (minúsculas, sin tildes, con guiones).
     *
     * @param string $text
     *
     * @return string
     */
    private function slugify(string $text): string
    {
        // Quitar tildes.
        $search  = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'à', 'è', 'ì', 'ò', 'ù'];
        $replace = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u'];
        $slug    = str_replace($search, $replace, mb_strtolower(trim($text)));

        // Reemplazar lo no alfanumérico por guiones y compactar.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'mitienda';
    }

    /**
     * Resuelve la URL del logo del cliente desde setup_data o la implementación de sistema.
     *
     * @param Client|null $client
     *
     * @return string URL del logo o cadena vacía si no hay.
     */
    private function resolve_logo_url(?Client $client): string
    {
        if ($client === null) {
            return '';
        }

        // 1) Desde setup_data del cliente (recolectado en la Etapa 1 del sistema).
        $setup_data = is_array($client->setup_data) ? $client->setup_data : [];
        $logo_url   = trim((string) ($setup_data['logo_url'] ?? ''));

        if ($logo_url !== '') {
            return $logo_url;
        }

        // 2) Fallback: desde el stage 1 de la implementación de sistema.
        $client->loadMissing('implementation');
        $implementation = $client->implementation;

        if ($implementation !== null) {
            $stage_1 = \App\Models\ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', 1)
                ->first();

            if ($stage_1 !== null && is_array($stage_1->data)) {
                $logo_url = trim((string) ($stage_1->data['logo_url'] ?? ''));
            }
        }

        return $logo_url;
    }

    /**
     * Devuelve el registro de la etapa indicada de la implementación.
     *
     * @param EcommerceImplementation $implementation
     * @param int                     $stage_number
     *
     * @return EcommerceImplementationStage|null
     */
    private function stage_record(EcommerceImplementation $implementation, int $stage_number): ?EcommerceImplementationStage
    {
        return EcommerceImplementationStage::where('ecommerce_implementation_id', $implementation->id)
            ->where('stage_number', $stage_number)
            ->first();
    }

    /**
     * Cliente dueño de la implementación.
     *
     * @param EcommerceImplementation $implementation
     *
     * @return Client|null
     */
    private function resolve_client(EcommerceImplementation $implementation): ?Client
    {
        return $implementation->client ?? Client::find($implementation->client_id);
    }

    /**
     * Nombre legible del cliente para mensajes.
     *
     * @param EcommerceImplementation $implementation
     *
     * @return string
     */
    private function resolve_client_name(EcommerceImplementation $implementation): string
    {
        $client = $this->resolve_client($implementation);

        return $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";
    }

    /**
     * Tienda online asociada a la implementación.
     *
     * @param EcommerceImplementation $implementation
     *
     * @return ClientEcommerce|null
     */
    private function resolve_client_ecommerce(EcommerceImplementation $implementation): ?ClientEcommerce
    {
        if ($implementation->client_ecommerce_id) {
            $client_ecommerce = ClientEcommerce::find($implementation->client_ecommerce_id);
            if ($client_ecommerce !== null) {
                return $client_ecommerce;
            }
        }

        return ClientEcommerce::where('client_id', $implementation->client_id)->first();
    }

    /**
     * Dominio elegido para la tienda (de client_ecommerce o del data de la Etapa 1).
     *
     * @param EcommerceImplementation $implementation
     *
     * @return string
     */
    private function resolve_ecommerce_domain(EcommerceImplementation $implementation): string
    {
        $client_ecommerce = $this->resolve_client_ecommerce($implementation);
        $domain           = $client_ecommerce ? trim((string) ($client_ecommerce->domain ?? '')) : '';

        if ($domain !== '') {
            return $domain;
        }

        // Fallback: leer del data del stage 1.
        $stage = $this->stage_record($implementation, 1);
        if ($stage !== null && is_array($stage->data)) {
            $domain = trim((string) ($stage->data['domain'] ?? ''));
        }

        return $domain !== '' ? $domain : 'tu dominio';
    }

    /**
     * Envía una notificación por WhatsApp al admin asignado a la implementación.
     *
     * @param EcommerceImplementation $implementation
     * @param string                  $message
     *
     * @return void
     */
    private function notify_assigned_admin(EcommerceImplementation $implementation, string $message): void
    {
        $assigned_admin = $implementation->assigned_admin_id
            ? Admin::find($implementation->assigned_admin_id)
            : null;

        if ($assigned_admin === null) {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: admin asignado no encontrado.', [
                'ecommerce_implementation_id' => $implementation->id,
                'assigned_admin_id'           => $implementation->assigned_admin_id,
            ]);
            return;
        }

        $admin_phone = trim((string) ($assigned_admin->phone ?? ''));

        if ($admin_phone === '') {
            Log::channel('daily')->warning('EcommerceImplementationConversationService: admin asignado sin teléfono.', [
                'ecommerce_implementation_id' => $implementation->id,
                'admin_id'                    => $assigned_admin->id,
            ]);
            return;
        }

        $this->whatsapp_send_service->send_text($admin_phone, $message);
    }

    /**
     * Envía un mensaje de texto por WhatsApp y lo persiste en ecommerce_implementation_messages.
     *
     * @param EcommerceImplementation $implementation
     * @param int                     $stage_number
     * @param string                  $phone
     * @param string                  $body
     *
     * @return void
     */
    private function send_outbound(
        EcommerceImplementation $implementation,
        int $stage_number,
        string $phone,
        string $body
    ): void {
        // Enviar por Kapso y obtener el ID de Meta para trazabilidad.
        $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $body);

        // Persistir siempre, aunque el envío falle (para auditoría y re-envío manual).
        $outbound_message = EcommerceImplementationMessage::create([
            'ecommerce_implementation_id' => $implementation->id,
            'stage_number'                => $stage_number,
            'direction'                   => 'outbound',
            'body'                        => $body,
            'whatsapp_message_id'         => $whatsapp_message_id,
            'sent_at'                     => now(),
        ]);

        EcommerceImplementationBroadcastService::emit_message_received(
            (int) $implementation->id,
            (int) $outbound_message->id
        );
    }
}
