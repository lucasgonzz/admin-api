<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de conversación WhatsApp para el flujo de implementación de clientes.
 *
 * Maneja la Etapa 1 de a una pregunta por vez: lee el `data` del stage, determina
 * la próxima clave pendiente, procesa la respuesta entrante y envía la siguiente
 * pregunta al cliente. Cada mensaje saliente se persiste en implementation_messages.
 *
 * Tracking interno: usa `data['current_question']` para saber qué pregunta está
 * pendiente de respuesta en cada momento.
 */
class ImplementationConversationService
{
    /**
     * Valor especial de retorno: respuesta en proceso de acumulación (campo employees).
     */
    private const RESPONSE_ACCUMULATING = '__ACCUMULATING__';

    /**
     * @var WhatsappSendService Envío saliente vía Kapso.
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service Inyección opcional para tests.
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Punto de entrada: enruta según la etapa actual de la implementación.
     *
     * @param Implementation       $implementation Implementación activa.
     * @param array<string, mixed> $parsed         Mensaje entrante parseado (from, type, body…).
     *
     * @return void
     */
    public function handle(Implementation $implementation, array $parsed): void
    {
        // Etapa actual: determina qué handler ejecutar.
        $current_stage = (int) $implementation->current_stage;

        if ($current_stage === 1) {
            $this->handle_stage_1($implementation, $parsed);
            return;
        }

        // Etapas 2–7: aún no implementadas; registrar para depuración.
        Log::channel('daily')->info('ImplementationConversationService: etapa no implementada aún.', [
            'implementation_id' => $implementation->id,
            'current_stage'     => $current_stage,
        ]);
    }

    // -------------------------------------------------------------------------
    // Etapa 1 — Recolección de datos de configuración inicial
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 1.
     *
     * Flujo:
     * 1. Cargar stage 1 y su data actual.
     * 2. Si no hay `current_question` en data → enviar la primera pregunta.
     * 3. Procesar la respuesta del cliente para `current_question`.
     * 4. Si válida → guardar, avanzar a la siguiente pregunta.
     * 5. Si inválida → reenviar la misma pregunta con mensaje de aclaración.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed
     *
     * @return void
     */
    private function handle_stage_1(Implementation $implementation, array $parsed): void
    {
        // Stage 1 de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 1 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual: array con respuestas acumuladas y `current_question`.
        $data = is_array($stage->data) ? $stage->data : [];

        // Teléfono del remitente: destino de todos los mensajes salientes de esta etapa.
        $phone = (string) $parsed['from'];

        // Cliente dueño de la implementación.
        $client = $implementation->client;
        if ($client === null) {
            $client = Client::find($implementation->client_id);
        }

        // Si aún no se envió ninguna pregunta → enviar la primera y registrar el estado.
        if (! array_key_exists('current_question', $data)) {
            $first_question = $this->build_question_use_price_lists($client);
            $this->send_outbound($implementation, 1, $phone, $first_question);
            $data['current_question'] = 'use_price_lists';
            $stage->data = $data;
            $stage->save();
            return;
        }

        // Pregunta actualmente pendiente de respuesta.
        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        // Campos con lógica especial de acumulación/auto-detección.
        if ($current_question === 'employees') {
            $this->handle_stage_1_employees($stage, $data, $parsed, $phone, $implementation);
            return;
        }

        if ($current_question === 'logo_received') {
            $this->handle_stage_1_logo($stage, $data, $parsed, $phone, $implementation, $client);
            return;
        }

        // Procesamiento genérico para el resto de preguntas.
        $response_value = $this->process_stage1_response($current_question, $parsed, $data);

        if ($response_value === null) {
            // Respuesta ambigua: reenviar la misma pregunta con aclaración.
            $retry_text = $this->build_question_text($current_question, $data, $client);
            $this->send_outbound($implementation, 1, $phone, 'No entendí bien tu respuesta. ' . $retry_text);
            return;
        }

        // Respuesta válida: guardar y avanzar.
        $data[$current_question] = $response_value;

        $next_question = $this->get_next_stage1_key($current_question, $data);

        if ($next_question === null) {
            // Todas las preguntas respondidas: finalizar etapa 1.
            $data['current_question'] = 'completed';
            $data['completed']        = true;
            $stage->data              = $data;
            $stage->save();
            $this->finish_stage_1($implementation, $phone);
            return;
        }

        // Persistir la respuesta y enviar la siguiente pregunta.
        $data['current_question'] = $next_question;
        $stage->data              = $data;
        $stage->save();

        $next_question_text = $this->build_question_text($next_question, $data, $client);
        $this->send_outbound($implementation, 1, $phone, $next_question_text);
    }

    /**
     * Maneja la pregunta de empleados: acumula mensajes hasta detectar señal de fin.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data           Data actual del stage (por referencia implícita en save).
     * @param array<string, mixed> $parsed         Mensaje entrante.
     * @param string               $phone          Teléfono destino.
     * @param Implementation       $implementation
     *
     * @return void
     */
    private function handle_stage_1_employees(
        ImplementationStage $stage,
        array $data,
        array $parsed,
        string $phone,
        Implementation $implementation
    ): void {
        $body = trim((string) ($parsed['body'] ?? ''));
        $client = $implementation->client;
        if ($client === null) {
            $client = Client::find($implementation->client_id);
        }

        if ($this->is_employees_done_signal($body)) {
            // Verificar que haya algo acumulado antes de avanzar.
            $accumulated = trim((string) ($data['employees'] ?? ''));
            if ($accumulated === '') {
                $this->send_outbound(
                    $implementation,
                    1,
                    $phone,
                    'Todavía no recibí ningún dato. ' . $this->build_question_text('employees', $data, $client)
                );
                return;
            }

            // Avanzar a la siguiente pregunta.
            $next_question = $this->get_next_stage1_key('employees', $data);

            if ($next_question === null) {
                $data['current_question'] = 'completed';
                $data['completed']        = true;
                $stage->data              = $data;
                $stage->save();
                $this->finish_stage_1($implementation, $phone);
                return;
            }

            $data['current_question'] = $next_question;
            $stage->data              = $data;
            $stage->save();

            $next_question_text = $this->build_question_text($next_question, $data, $client);
            $this->send_outbound($implementation, 1, $phone, $next_question_text);
            return;
        }

        // Acumular el texto en el campo employees.
        $existing           = trim((string) ($data['employees'] ?? ''));
        $data['employees']  = $existing !== '' ? $existing . "\n" . $body : $body;
        $stage->data        = $data;
        $stage->save();

        // Acuse de recibo para que el cliente sepa que se recibió el mensaje.
        $this->send_outbound(
            $implementation,
            1,
            $phone,
            "Anotado 👍 Seguí mandando los datos o escribí *listo* cuando hayas terminado."
        );
    }

    /**
     * Maneja la pregunta del logo: se completa automáticamente al recibir una imagen.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data
     * @param array<string, mixed> $parsed
     * @param string               $phone
     * @param Implementation       $implementation
     * @param Client|null          $client
     *
     * @return void
     */
    private function handle_stage_1_logo(
        ImplementationStage $stage,
        array $data,
        array $parsed,
        string $phone,
        Implementation $implementation,
        ?Client $client
    ): void {
        $message_type = (string) ($parsed['type'] ?? 'text');

        if ($message_type !== 'image') {
            // No es imagen: reenviar la pregunta.
            $retry_text = $this->build_question_text('logo_received', $data, $client);
            $this->send_outbound($implementation, 1, $phone, 'No entendí bien tu respuesta. ' . $retry_text);
            return;
        }

        // Imagen recibida: marcar logo_received y avanzar.
        $data['logo_received'] = true;
        $next_question = $this->get_next_stage1_key('logo_received', $data);

        if ($next_question === null) {
            $data['current_question'] = 'completed';
            $data['completed']        = true;
            $stage->data              = $data;
            $stage->save();
            $this->finish_stage_1($implementation, $phone);
            return;
        }

        $data['current_question'] = $next_question;
        $stage->data              = $data;
        $stage->save();

        $next_question_text = $this->build_question_text($next_question, $data, $client);
        $this->send_outbound($implementation, 1, $phone, $next_question_text);
    }

    // -------------------------------------------------------------------------
    // Procesamiento de respuestas
    // -------------------------------------------------------------------------

    /**
     * Procesa la respuesta del cliente para la pregunta actual.
     *
     * Retorna el valor a guardar en data, null si la respuesta es inválida,
     * o RESPONSE_ACCUMULATING para el campo employees en modo acumulación.
     *
     * @param string               $question Clave de la pregunta actual.
     * @param array<string, mixed> $parsed   Mensaje entrante.
     * @param array<string, mixed> $data     Data actual del stage.
     *
     * @return mixed null = inválida | self::RESPONSE_ACCUMULATING = acumulando | valor a guardar.
     */
    private function process_stage1_response(string $question, array $parsed, array $data)
    {
        $body = trim((string) ($parsed['body'] ?? ''));

        switch ($question) {
            // Preguntas booleanas: Sí/No o variantes de opciones.
            case 'use_price_lists':
            case 'use_deposits':
            case 'ask_amount_in_vender':
            case 'default_cuenta_corriente':
                return $this->parse_bool_response($question, $body);

            // Preguntas de texto libre: cualquier respuesta no vacía es válida.
            case 'price_lists':
            case 'deposit_names':
            case 'payment_discounts':
            case 'company_name':
                return $body !== '' ? $body : null;

            // employees se maneja en handle_stage_1_employees; no debería llegar aquí.
            case 'employees':
                return self::RESPONSE_ACCUMULATING;

            // logo_received se maneja en handle_stage_1_logo; no debería llegar aquí.
            case 'logo_received':
                return null;

            default:
                return null;
        }
    }

    /**
     * Normaliza y parsea una respuesta de tipo booleano según la pregunta.
     *
     * Acepta variantes comunes en español para no requerir texto exacto.
     *
     * @param string $question Clave de la pregunta.
     * @param string $body     Texto de la respuesta normalizado.
     *
     * @return bool|null true/false si reconocida, null si ambigua.
     */
    private function parse_bool_response(string $question, string $body): ?bool
    {
        // Normalizar: minúsculas, sin tildes, sin puntuación final.
        $normalized = strtolower(trim($body));
        $normalized = $this->remove_accents($normalized);
        $normalized = rtrim($normalized, '.!?');

        if ($question === 'ask_amount_in_vender') {
            // "Preguntar cantidad" → true | "Agregar 1 unidad" → false.
            if (str_contains($normalized, 'preguntar')) {
                return true;
            }
            if (str_contains($normalized, 'agregar') || str_contains($normalized, '1 unidad')) {
                return false;
            }
            // Fallback sí/no por si el cliente responde directamente.
            return $this->parse_yes_no($normalized);
        }

        if ($question === 'default_cuenta_corriente') {
            // "Por defecto sí" → true | "Por defecto no" → false.
            if (str_contains($normalized, 'por defecto si') || $normalized === 'si' || $normalized === 's') {
                return true;
            }
            if (str_contains($normalized, 'por defecto no') || $normalized === 'no' || $normalized === 'n') {
                return false;
            }
            return $this->parse_yes_no($normalized);
        }

        if ($question === 'use_price_lists') {
            // Opciones: "Precio único" → false | "Listas de precios" → true.
            if (str_contains($normalized, 'precio unico') || str_contains($normalized, 'unico')) {
                return false;
            }
            if (str_contains($normalized, 'lista') || str_contains($normalized, 'varios')) {
                return true;
            }
            return $this->parse_yes_no($normalized);
        }

        if ($question === 'use_deposits') {
            // Opciones: "Un lugar" → false | "Varias sucursales" → true.
            if (str_contains($normalized, 'un lugar') || str_contains($normalized, 'unico')) {
                return false;
            }
            if (str_contains($normalized, 'varias') || str_contains($normalized, 'sucursal') || str_contains($normalized, 'deposito')) {
                return true;
            }
            return $this->parse_yes_no($normalized);
        }

        // Caso genérico.
        return $this->parse_yes_no($normalized);
    }

    /**
     * Parsea una respuesta genérica Sí/No.
     *
     * @param string $normalized Texto ya normalizado (sin tildes, minúsculas).
     *
     * @return bool|null
     */
    private function parse_yes_no(string $normalized): ?bool
    {
        // Acepta variantes comunes: "sí", "si", "s", "yes" → true.
        if (in_array($normalized, ['si', 's', 'yes', 'sip', 'dale', 'claro', 'correcto', 'exacto', 'afirmativo'], true)) {
            return true;
        }
        // "no", "n", "nop", "nope" → false.
        if (in_array($normalized, ['no', 'n', 'nop', 'nope', 'negativo'], true)) {
            return false;
        }
        return null;
    }

    /**
     * Detecta si el mensaje es una señal de fin para la acumulación de empleados.
     *
     * @param string $body Texto del mensaje (sin normalizar).
     *
     * @return bool
     */
    private function is_employees_done_signal(string $body): bool
    {
        // Texto normalizado para comparar frases de cierre comunes.
        $normalized = strtolower(trim($this->remove_accents($body)));
        $done_signals = ['listo', 'ya esta', 'ya listo', 'eso es todo', 'termine', 'es todo', 'fin', 'finalice', 'listo ya'];
        foreach ($done_signals as $signal) {
            if ($normalized === $signal || str_contains($normalized, $signal)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Secuencia y navegación de preguntas
    // -------------------------------------------------------------------------

    /**
     * Resuelve la secuencia de claves de la Etapa 1 según el data actual.
     *
     * Las claves `price_lists` y `deposit_names` son condicionales:
     * - price_lists: solo si use_price_lists === true.
     * - deposit_names: solo si use_deposits === true.
     *
     * @param array<string, mixed> $data Data actual del stage.
     *
     * @return array<int, string>
     */
    private function resolve_stage1_sequence(array $data): array
    {
        // Secuencia base de preguntas.
        $keys = ['use_price_lists'];

        // Preguntar por nombres de listas solo si el cliente usará listas de precios.
        if (isset($data['use_price_lists']) && $data['use_price_lists'] === true) {
            $keys[] = 'price_lists';
        }

        $keys[] = 'use_deposits';

        // Preguntar por nombres de depósitos solo si el cliente usará múltiples depósitos.
        if (isset($data['use_deposits']) && $data['use_deposits'] === true) {
            $keys[] = 'deposit_names';
        }

        $keys[] = 'payment_discounts';
        $keys[] = 'company_name';
        $keys[] = 'employees';
        $keys[] = 'logo_received';
        $keys[] = 'ask_amount_in_vender';
        $keys[] = 'default_cuenta_corriente';

        return $keys;
    }

    /**
     * Devuelve la clave siguiente en la secuencia de la Etapa 1, o null si no hay más.
     *
     * @param string               $current_key Clave que acaba de responderse.
     * @param array<string, mixed> $data        Data ya actualizada con la respuesta de current_key.
     *
     * @return string|null
     */
    private function get_next_stage1_key(string $current_key, array $data): ?string
    {
        // Recalcular la secuencia con el data ya actualizado (puede incluir price_lists o deposit_names).
        $sequence      = $this->resolve_stage1_sequence($data);
        $current_index = array_search($current_key, $sequence, true);

        if ($current_index === false) {
            return null;
        }

        // Índice siguiente; null si es el último.
        $next_index = (int) $current_index + 1;
        if ($next_index >= count($sequence)) {
            return null;
        }

        return $sequence[$next_index];
    }

    // -------------------------------------------------------------------------
    // Construcción de textos de preguntas
    // -------------------------------------------------------------------------

    /**
     * Retorna el texto de la pregunta para la clave dada.
     *
     * @param string               $key    Clave de la pregunta.
     * @param array<string, mixed> $data   Data actual del stage (puede ser necesaria para contexto).
     * @param Client|null          $client Cliente para personalizar saludos.
     *
     * @return string
     */
    private function build_question_text(string $key, array $data, ?Client $client): string
    {
        switch ($key) {
            case 'use_price_lists':
                return $this->build_question_use_price_lists($client);
            case 'price_lists':
                return "Perfecto. Indicame los nombres de tus listas de precios y el margen de ganancia por defecto de cada una. Ejemplo:\n1. Minorista 30%\n2. Mayorista 20%\n(Si no tenés margen fijo, decime solo los nombres)";
            case 'use_deposits':
                return $this->build_question_use_deposits($client);
            case 'deposit_names':
                return "Indicame los nombres de cada sucursal o depósito tal como querés que aparezcan en el sistema.";
            case 'payment_discounts':
                return "¿Aplicás descuentos o recargos según el método de pago? Si es así, indicame los porcentajes. Ejemplo:\n- Efectivo: 10% descuento\n- Transferencia: 10% recargo\nSi cobrás igual independientemente del método, respondé No.";
            case 'company_name':
                return "¿Cuál es el nombre de tu empresa tal como debe figurar en los comprobantes?";
            case 'employees':
                return "Necesito los datos de vos y de todos los empleados que van a usar el sistema. Por cada persona indicame nombre completo, número de documento y de qué área se va a encargar (por ejemplo: ventas, stock, administración). Podés mandarlo en varios mensajes si querés.";
            case 'logo_received':
                return "Por último, enviame el logo de tu empresa en formato cuadrado. Lo vamos a usar en los comprobantes.";
            case 'ask_amount_in_vender':
                return "Cuando cargás una venta, ¿preferís que el sistema te pregunte la cantidad a vender de cada producto, o que agregue 1 unidad automáticamente y vos la modificás si hace falta? (respondé Preguntar cantidad o Agregar 1 unidad)";
            case 'default_cuenta_corriente':
                return "Por último: cuando asignás un cliente a una venta, ¿querés que por defecto vaya a cuenta corriente, o preferís indicarlo manualmente cada vez? (respondé Por defecto sí o Por defecto no)";
            default:
                return '';
        }
    }

    /**
     * Construye el texto de la pregunta sobre listas de precios.
     *
     * Si el lead de origen tiene `use_price_lists` definido → confirmar con variante A.
     * Si no hay dato previo → variante B (opción abierta).
     *
     * @param Client|null $client
     *
     * @return string
     */
    private function build_question_use_price_lists(?Client $client): string
    {
        // Nombre del cliente para el saludo.
        $display_name = $client ? $client->resolve_display_name() : 'cliente';

        // Buscar el lead promovido para leer la preferencia del proceso de venta.
        $promoted_lead = $client ? $this->find_promoted_lead($client) : null;

        if ($promoted_lead !== null && isset($promoted_lead->use_price_lists) && $promoted_lead->use_price_lists === true) {
            return "Hola {$display_name}! Para arrancar con la configuración, te confirmo que en la demo usaste listas de precios. ¿Es así como vas a trabajar? (respondé Sí o No)";
        }

        return "Hola {$display_name}! Para arrancar con la configuración: ¿vas a manejar un único precio de venta por producto, o necesitás varias listas de precios con distintos márgenes? (respondé Precio único o Listas de precios)";
    }

    /**
     * Construye el texto de la pregunta sobre depósitos o sucursales.
     *
     * Variante A (confirmación) si el lead tiene `use_deposits = true`.
     * Variante B (opción abierta) en caso contrario.
     *
     * @param Client|null $client
     *
     * @return string
     */
    private function build_question_use_deposits(?Client $client): string
    {
        // Buscar preferencia del lead promovido.
        $promoted_lead = $client ? $this->find_promoted_lead($client) : null;

        if ($promoted_lead !== null && isset($promoted_lead->use_deposits) && $promoted_lead->use_deposits === true) {
            return "¿Confirmás que vas a dividir el stock en depósitos o sucursales? (Sí o No)";
        }

        return "¿Cómo querés manejar el stock? ¿Todo en un único lugar, o tenés más de una sucursal o depósito? (respondé Un lugar o Varias sucursales)";
    }

    // -------------------------------------------------------------------------
    // Finalización de Etapa 1
    // -------------------------------------------------------------------------

    /**
     * Cierra la Etapa 1: envía confirmación al cliente y avisa a Martín.
     *
     * NO avanza el current_stage de la implementación; eso lo hace Martín desde el admin.
     *
     * @param Implementation $implementation
     * @param string         $phone Teléfono del cliente para la confirmación.
     *
     * @return void
     */
    private function finish_stage_1(Implementation $implementation, string $phone): void
    {
        // Mensaje de confirmación para el cliente.
        $client_message = "¡Perfecto, tenemos todo! Martín va a revisar la información y te avisa cuando el sistema esté listo para el siguiente paso.";
        $this->send_outbound($implementation, 1, $phone, $client_message);

        // Notificación a Martín.
        $this->notify_martin_stage1_complete($implementation);
    }

    /**
     * Envía notificación a Martín indicando que el cliente completó la Etapa 1.
     *
     * Si el admin "Martin" no existe o no tiene campo phone, registra un aviso en logs.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function notify_martin_stage1_complete(Implementation $implementation): void
    {
        // Buscar el admin por nombre.
        $martin = Admin::where('name', 'Martin')->first();

        if ($martin === null) {
            Log::channel('daily')->warning('ImplementationConversationService: admin Martin no encontrado; no se envió notificación.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // El campo `phone` no existe en admins aún; se obtiene con acceso dinámico.
        $martin_phone = trim((string) ($martin->phone ?? ''));

        if ($martin_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: admin Martin sin campo phone; no se envió notificación.', [
                'implementation_id' => $implementation->id,
                'admin_id'          => $martin->id,
            ]);
            return;
        }

        // Nombre del cliente para el mensaje.
        $client       = $implementation->client;
        $client_name  = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        $body = "✅ {$client_name} completó la Etapa 1 de implementación. Podés revisar los datos en el admin.";
        $this->whatsapp_send_service->send_text($martin_phone, $body);
    }

    // -------------------------------------------------------------------------
    // Helpers de envío y persistencia
    // -------------------------------------------------------------------------

    /**
     * Envía un mensaje de texto por WhatsApp y lo persiste en implementation_messages.
     *
     * @param Implementation $implementation Implementación asociada al mensaje.
     * @param int            $stage_number   Número de etapa del mensaje.
     * @param string         $phone          Teléfono destino E.164.
     * @param string         $body           Texto del mensaje.
     *
     * @return void
     */
    private function send_outbound(
        Implementation $implementation,
        int $stage_number,
        string $phone,
        string $body
    ): void {
        // Enviar por Kapso y obtener el ID de Meta para trazabilidad.
        $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $body);

        // Persistir siempre, aunque el envío falle (para auditoría y re-envío manual).
        ImplementationMessage::create([
            'implementation_id'   => $implementation->id,
            'stage_number'        => $stage_number,
            'direction'           => 'outbound',
            'body'                => $body,
            'whatsapp_message_id' => $whatsapp_message_id,
            'sent_at'             => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers de modelos y normalización
    // -------------------------------------------------------------------------

    /**
     * Busca el Lead desde el cual fue promovido el cliente dado.
     *
     * @param Client $client
     *
     * @return Lead|null
     */
    private function find_promoted_lead(Client $client): ?Lead
    {
        return Lead::where('promoted_client_id', $client->id)->first();
    }

    /**
     * Elimina tildes y caracteres especiales de una cadena para comparaciones.
     *
     * @param string $text
     *
     * @return string
     */
    private function remove_accents(string $text): string
    {
        // Tabla de reemplazo: vocales acentuadas y ñ → equivalente sin tilde.
        $search  = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'à', 'è', 'ì', 'ò', 'ù'];
        $replace = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u'];
        return str_replace($search, $replace, $text);
    }
}
