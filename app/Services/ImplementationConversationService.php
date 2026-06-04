<?php

namespace App\Services;

use App\Events\ImplementationStageCompleted;
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

        if ($current_stage === 2) {
            $this->handle_stage_2($implementation, $parsed);
            return;
        }

        // Etapa 3 es manual (instalación del sistema): responder al cliente con mensaje de espera.
        if ($current_stage === 3) {
            $phone = (string) $parsed['from'];
            $this->send_outbound($implementation, 3, $phone, 'Estamos preparando tu sistema, en breve te avisamos. ¡Gracias por la paciencia! 🙏');
            return;
        }

        if ($current_stage === 4) {
            $this->handle_stage_4($implementation, $parsed);
            return;
        }

        if ($current_stage === 5) {
            $this->handle_stage_5($implementation, $parsed);
            return;
        }

        if ($current_stage === 6) {
            $this->handle_stage_6($implementation, $parsed);
            return;
        }

        if ($current_stage === 7) {
            $this->handle_stage_7($implementation, $parsed);
            return;
        }

        // Etapas fuera de rango esperado: registrar para depuración.
        Log::channel('daily')->info('ImplementationConversationService: etapa fuera del rango implementado.', [
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
     * 4. Si válida → guardar, enviar confirmación breve + siguiente pregunta.
     * 5. Si inválida → reenviar la misma pregunta con mensaje de aclaración.
     *
     * Caso especial de arranque: si el lead ya confirmó `use_price_lists = true`,
     * se pre-configura ese valor y la primera pregunta pide directamente los nombres
     * de las listas, saltando la pregunta de confirmación sí/no.
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
            // Verificar si el lead ya confirmó uso de listas de precios.
            $promoted_lead               = $client ? $this->find_promoted_lead($client) : null;
            $lead_confirmed_price_lists  = $promoted_lead !== null
                && isset($promoted_lead->use_price_lists)
                && $promoted_lead->use_price_lists === true;

            if ($lead_confirmed_price_lists) {
                /**
                 * Lead confirmado con listas de precios: pre-configurar use_price_lists=true
                 * y saltar directamente a recolectar los nombres de listas (price_lists).
                 * La primera pregunta incluye el saludo y pide los nombres directamente.
                 */
                $data['use_price_lists']    = true;
                $data['current_question']   = 'price_lists';
            } else {
                // Flujo normal: preguntar primero si usa listas de precios.
                $data['current_question'] = 'use_price_lists';
            }

            $first_question = $this->build_question_use_price_lists($implementation, $client);
            $this->send_outbound($implementation, 1, $phone, $first_question);
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

        // Caso especial: cuando se responde use_deposits, intentar extraer los nombres en el mismo mensaje.
        // Si el cliente confirma el uso de depósitos Y menciona al menos dos nombres en el mismo mensaje,
        // guardamos ambos datos y saltamos directamente a payment_discounts.
        if ($current_question === 'use_deposits') {
            $body_raw        = trim((string) ($parsed['body'] ?? ''));
            $extracted_names = $this->try_extract_deposit_names_from_message($body_raw);

            if ($extracted_names !== null) {
                // Verificar que el mensaje también confirme el uso de depósitos.
                $deposits_value = $this->process_stage1_response('use_deposits', $parsed, $data);

                if ($deposits_value === true) {
                    // Guardar ambos campos y saltar deposit_names → ir directo a payment_discounts.
                    $data['use_deposits']     = true;
                    $data['deposit_names']    = $extracted_names;
                    $data['current_question'] = 'payment_discounts';
                    $stage->data              = $data;
                    $stage->save();

                    // Mensaje combinado: confirma los depósitos recibidos + hace la siguiente pregunta.
                    $next_question_text = $this->build_question_text('payment_discounts', $data, $client, $implementation);
                    $outbound_text      = $this->build_acknowledgement() . " Anotado que vas a trabajar con {$extracted_names}. " . $next_question_text;
                    $this->send_outbound($implementation, 1, $phone, $outbound_text);
                    return;
                }
            }
        }

        // Procesamiento genérico para el resto de preguntas.
        $response_value = $this->process_stage1_response($current_question, $parsed, $data);

        if ($response_value === null) {
            // Respuesta ambigua: reenviar la misma pregunta con aclaración.
            $retry_text = $this->build_question_text($current_question, $data, $client, $implementation);
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
            $this->finish_stage_1($implementation, $phone, $client);
            return;
        }

        // Persistir la respuesta y enviar confirmación breve + siguiente pregunta.
        $data['current_question'] = $next_question;
        $stage->data              = $data;
        $stage->save();

        $next_question_text = $this->build_question_text($next_question, $data, $client, $implementation);

        // Anteponer acuse de recibo antes de la siguiente pregunta.
        $outbound_text = $this->build_acknowledgement() . ' ' . $next_question_text;
        $this->send_outbound($implementation, 1, $phone, $outbound_text);
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

        // Rama 1: señal de fin detectada → intentar avanzar.
        // Rama 2: acumulación → guardar y acusar recibo.
        // Las ramas son explícitamente excluyentes: cada una termina con return.
        if ($this->is_employees_done_signal($body, $data)) {
            // Verificar que haya algo acumulado antes de avanzar.
            $accumulated = trim((string) ($data['employees'] ?? ''));

            if ($accumulated === '') {
                // Sin datos acumulados: no se puede avanzar; pedir el primero.
                $this->send_outbound(
                    $implementation,
                    1,
                    $phone,
                    'Todavía no recibí ningún dato. ' . $this->build_question_text('employees', $data, $client, $implementation)
                );
                return;
            }

            // Avanzar a la siguiente pregunta.
            $next_question = $this->get_next_stage1_key('employees', $data);

            if ($next_question === null) {
                // No hay más preguntas: finalizar etapa 1.
                $data['current_question'] = 'completed';
                $data['completed']        = true;
                $stage->data              = $data;
                $stage->save();
                $this->finish_stage_1($implementation, $phone, $client);
                return;
            }

            // Persistir la pregunta siguiente y enviar acuse + pregunta.
            $data['current_question'] = $next_question;
            $stage->data              = $data;
            $stage->save();

            $next_question_text = $this->build_question_text($next_question, $data, $client, $implementation);

            // Acuse de recibo antes de la siguiente pregunta al completar employees.
            $outbound_text = $this->build_acknowledgement() . ' ' . $next_question_text;
            $this->send_outbound($implementation, 1, $phone, $outbound_text);
            return;
        }

        // Rama 2: acumular el texto recibido en el campo employees.
        $existing          = trim((string) ($data['employees'] ?? ''));
        $data['employees'] = $existing !== '' ? $existing . "\n" . $body : $body;
        $stage->data       = $data;
        $stage->save();

        // Acuse de recibo breve y aleatorio para que el cliente sepa que se recibió el mensaje.
        $this->send_outbound($implementation, 1, $phone, $this->build_acknowledgement());
        return;
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
            $retry_text = $this->build_question_text('logo_received', $data, $client, $implementation);
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
            $this->finish_stage_1($implementation, $phone, $client);
            return;
        }

        $data['current_question'] = $next_question;
        $stage->data              = $data;
        $stage->save();

        $next_question_text = $this->build_question_text($next_question, $data, $client, $implementation);

        // Acuse de recibo al recibir el logo y avanzar a la siguiente pregunta.
        $outbound_text = $this->build_acknowledgement() . ' ' . $next_question_text;
        $this->send_outbound($implementation, 1, $phone, $outbound_text);
    }

    // -------------------------------------------------------------------------
    // Apertura de etapas (mensaje inicial sin respuesta entrante previa)
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la etapa indicada sin esperar un mensaje entrante del cliente.
     *
     * Usado desde el controller al avanzar manualmente a una etapa que necesita
     * iniciar la conversación de forma proactiva (etapas 2, 4, 5, 6 y 7).
     *
     * @param Implementation $implementation Implementación activa.
     * @param int            $stage          Número de etapa a abrir.
     *
     * @return void
     */
    public function send_stage_opening_message(Implementation $implementation, int $stage): void
    {
        if ($stage === 2) {
            $this->send_stage_2_opening($implementation);
            return;
        }

        if ($stage === 4) {
            $this->send_stage_4_opening($implementation);
            return;
        }

        if ($stage === 5) {
            $this->send_stage_5_opening($implementation);
            return;
        }

        if ($stage === 6) {
            $this->send_stage_6_opening($implementation);
            return;
        }

        if ($stage === 7) {
            $this->send_stage_7_opening($implementation);
            return;
        }

        Log::channel('daily')->warning('ImplementationConversationService: send_stage_opening_message sin apertura implementada para esta etapa.', [
            'implementation_id' => $implementation->id,
            'stage'             => $stage,
        ]);
    }

    /**
     * Ejecuta las acciones automáticas al avanzar a una nueva etapa desde el controller.
     *
     * - Etapa 2: envía el primer mensaje al cliente (dueño).
     * - Etapa 3: notifica al admin asignado que debe ejecutar la instalación.
     * - Etapa 4: envía el primer mensaje al responsable de migración.
     * - Etapa 5: envía credenciales a empleados y mensaje al dueño.
     * - Etapa 6: envía la pregunta sobre acceso AFIP al dueño.
     * - Etapa 7: envía la pregunta de disponibilidad para videollamada al dueño.
     *
     * @param Implementation $implementation Implementación que acaba de avanzar de etapa.
     * @param int            $new_stage      Número de la nueva etapa activa.
     *
     * @return void
     */
    public function handle_stage_advance(Implementation $implementation, int $new_stage): void
    {
        if ($new_stage === 2) {
            $this->send_stage_opening_message($implementation, 2);
            return;
        }

        if ($new_stage === 3) {
            // Etapa manual: solo notificar al admin que debe ejecutar la instalación.
            $client      = $implementation->client ?? Client::find($implementation->client_id);
            $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

            $admin_message = "🛠️ {$client_name} está lista para instalar. Etapa 3: instalación del sistema y creación de empleados.";
            $this->notify_assigned_admin($implementation, $admin_message);
            return;
        }

        if ($new_stage === 4) {
            $this->send_stage_opening_message($implementation, 4);
            return;
        }

        if ($new_stage === 5) {
            $this->send_stage_opening_message($implementation, 5);
            return;
        }

        if ($new_stage === 6) {
            $this->send_stage_opening_message($implementation, 6);
            return;
        }

        if ($new_stage === 7) {
            $this->send_stage_opening_message($implementation, 7);
            return;
        }
    }

    /**
     * Envía el primer mensaje de la Etapa 2 al teléfono del cliente (dueño).
     *
     * Registra `current_question = 'migration_responsible_name'` en el data del stage 2
     * e inicializa la conversación. Es idempotente: si ya se envió la apertura, no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_2_opening(Implementation $implementation): void
    {
        // Stage 2 de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 2)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 2 no encontrado para apertura.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage.
        $data = is_array($stage->data) ? $stage->data : [];

        // Idempotente: si ya se registró current_question, no reenviar la apertura.
        if (array_key_exists('current_question', $data)) {
            return;
        }

        // Teléfono del dueño del negocio (cliente).
        $client = $implementation->client ?? Client::find($implementation->client_id);
        $phone  = trim((string) ($client->phone ?? ''));

        if ($phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: cliente sin teléfono para apertura de Etapa 2.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $implementation->client_id,
            ]);
            return;
        }

        // Registrar la primera pregunta pendiente y persistir.
        $data['current_question'] = 'migration_responsible_name';
        $stage->data              = $data;
        $stage->save();

        $question_text = "Ahora necesito saber quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores). ¿Lo vas a hacer vos o hay otra persona del equipo? Indicame el nombre.";
        $this->send_outbound($implementation, 2, $phone, $question_text);
    }

    /**
     * Envía el primer mensaje de la Etapa 4 al responsable de migración (migration_contact_phone).
     *
     * Registra `current_question = 'articles_excel'` en el data del stage 4 e inicializa
     * la solicitud de archivos. Es idempotente: si ya se envió la apertura, no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_4_opening(Implementation $implementation): void
    {
        // Stage 4 de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 4 no encontrado para apertura.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage.
        $data = is_array($stage->data) ? $stage->data : [];

        // Idempotente: si ya se registró current_question, no reenviar la apertura.
        if (array_key_exists('current_question', $data)) {
            return;
        }

        // Teléfono del responsable de migración (puede ser distinto al dueño).
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: migration_contact_phone vacío para apertura de Etapa 4.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Nombre del cliente para personalizar el mensaje de apertura.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Registrar la primera pregunta pendiente y persistir.
        $data['current_question'] = 'articles_excel';
        $stage->data              = $data;
        $stage->save();

        // Mensaje directo al punto, sin presentación del agente.
        $question_text = "Arrancamos con la migración de datos para {$client_name}. Primero necesito los archivos Excel con los productos o artículos. Podés enviarlos directamente por acá.";
        $this->send_outbound($implementation, 4, $contact_phone, $question_text);
    }

    // -------------------------------------------------------------------------
    // Etapa 2 — Definir responsable de migración
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 2.
     *
     * Flujo:
     * 1. Cargar stage 2 y su data actual.
     * 2. Procesar respuesta según `current_question`:
     *    - migration_responsible_name: detectar si es el dueño mismo (saltar teléfono) o tercero.
     *    - migration_responsible_phone: guardar teléfono y completar.
     * 3. Al completar: persistir migration_contact_phone, notificar admin, disparar evento.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_2(Implementation $implementation, array $parsed): void
    {
        // Stage 2 de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 2)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 2 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual: array con respuestas acumuladas y `current_question`.
        $data  = is_array($stage->data) ? $stage->data : [];

        // Teléfono del remitente (dueño del negocio en esta etapa).
        $phone = (string) $parsed['from'];
        $body  = trim((string) ($parsed['body'] ?? ''));

        // Cliente dueño de la implementación.
        $client = $implementation->client ?? Client::find($implementation->client_id);

        // Si no hay current_question → la apertura no se envió aún; inicializar como fallback.
        if (! array_key_exists('current_question', $data)) {
            $data['current_question'] = 'migration_responsible_name';
            $stage->data              = $data;
            $stage->save();

            $question_text = "Ahora necesito saber quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores). ¿Lo vas a hacer vos o hay otra persona del equipo? Indicame el nombre.";
            $this->send_outbound($implementation, 2, $phone, $question_text);
            return;
        }

        // Pregunta actualmente pendiente de respuesta.
        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        if ($current_question === 'migration_responsible_name') {
            if ($body === '') {
                $this->send_outbound($implementation, 2, $phone, 'No entendí bien tu respuesta. Indicame el nombre de quien va a encargarse del envío de archivos.');
                return;
            }

            // Determinar si el dueño indicó que se encargará él mismo.
            $is_self = $this->is_self_referential_response($body, $client);

            if ($is_self) {
                // El dueño se encarga: usar su nombre real y su teléfono como contacto; saltar pregunta de teléfono.
                $responsible_name  = $client ? $client->resolve_display_name() : $body;
                $responsible_phone = trim((string) ($client->phone ?? $phone));

                $data['migration_responsible_name']  = $responsible_name;
                $data['migration_responsible_phone'] = $responsible_phone;
                $data['current_question']            = 'completed';
                $data['completed']                   = true;
                $stage->data                         = $data;
                $stage->save();

                // El responsable es el mismo dueño: pasar is_self=true para ajustar el mensaje de cierre.
                $this->finish_stage_2($implementation, $phone, $client, $responsible_name, $responsible_phone, true);
                return;
            }

            // Tercera persona: guardar nombre y preguntar su teléfono de WhatsApp.
            $data['migration_responsible_name'] = $body;
            $data['current_question']           = 'migration_responsible_phone';
            $stage->data                        = $data;
            $stage->save();

            $question_text = "¿Y cuál es el número de WhatsApp de {$body}? Lo necesito para coordinar el envío de archivos por este medio.";
            $outbound_text = $this->build_acknowledgement() . ' ' . $question_text;
            $this->send_outbound($implementation, 2, $phone, $outbound_text);
            return;
        }

        if ($current_question === 'migration_responsible_phone') {
            if ($body === '') {
                // Respuesta vacía: reenviar la pregunta con el nombre del responsable.
                $responsible_name = (string) ($data['migration_responsible_name'] ?? 'el responsable');
                $this->send_outbound($implementation, 2, $phone, "¿Y cuál es el número de WhatsApp de {$responsible_name}? Lo necesito para coordinar el envío de archivos por este medio.");
                return;
            }

            // Guardar teléfono y completar etapa.
            $responsible_name  = (string) ($data['migration_responsible_name'] ?? 'el responsable');
            $responsible_phone = $body;

            $data['migration_responsible_phone'] = $responsible_phone;
            $data['current_question']            = 'completed';
            $data['completed']                   = true;
            $stage->data                         = $data;
            $stage->save();

            $this->finish_stage_2($implementation, $phone, $client, $responsible_name, $responsible_phone);
            return;
        }
    }

    /**
     * Cierra la Etapa 2: guarda migration_contact_phone en la implementación, envía
     * confirmación al dueño, notifica al admin asignado y dispara el evento Pusher.
     *
     * @param Implementation $implementation
     * @param string         $owner_phone       Teléfono del dueño para el mensaje de cierre.
     * @param Client|null    $client
     * @param string         $responsible_name  Nombre del responsable de migración resuelto.
     * @param string         $responsible_phone Teléfono del responsable de migración.
     * @param bool           $is_self           true si el responsable es el mismo dueño del negocio.
     *
     * @return void
     */
    private function finish_stage_2(
        Implementation $implementation,
        string $owner_phone,
        ?Client $client,
        string $responsible_name,
        string $responsible_phone,
        bool $is_self = false
    ): void {
        // Persistir el teléfono de contacto en la implementación para uso en etapas posteriores.
        $implementation->migration_contact_phone = $responsible_phone;
        $implementation->save();

        $client_name = $client
            ? $client->resolve_display_name()
            : "Cliente #{$implementation->client_id}";

        // Mensaje de cierre según si el responsable es el mismo dueño o una tercera persona.
        // Si es el mismo dueño, no tiene sentido decirle "le voy a escribir a vos mismo".
        $closing_message = $is_self
            ? 'Perfecto, entonces te voy a contactar por acá cuando arranquemos con la migración de archivos.'
            : "Perfecto, le voy a escribir a {$responsible_name} para coordinar el envío de los archivos.";

        $this->send_outbound($implementation, 2, $owner_phone, $closing_message);

        // Notificación al admin asignado con los datos del responsable de migración.
        $admin_message = "✅ {$client_name} completó la Etapa 2. Responsable de migración: {$responsible_name} ({$responsible_phone}).";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Evento Pusher para notificar al panel en tiempo real.
        event(new ImplementationStageCompleted($implementation->id, 2, $client_name));
    }

    // -------------------------------------------------------------------------
    // Etapa 5 — Capacitación: envío de credenciales a empleados
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la Etapa 5 al dueño del cliente y notifica a cada empleado.
     *
     * Acciones:
     * 1. Enviar a cada empleado con teléfono cargado sus credenciales de acceso y link al centro
     *    de recursos de ComercioCity.
     * 2. Guardar en data['employees_notified'] la lista de nombres que recibieron el mensaje.
     * 3. Enviar al dueño un mensaje informando que las credenciales ya fueron enviadas al equipo.
     *
     * Es idempotente: si data ya contiene 'employees_notified', no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_5_opening(Implementation $implementation): void
    {
        // Stage 5 de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 5)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 5 no encontrado para apertura.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage.
        $data = is_array($stage->data) ? $stage->data : [];

        // Idempotente: si ya se ejecutó la apertura, no repetir.
        if (array_key_exists('employees_notified', $data)) {
            return;
        }

        // Cargar cliente con sus empleados y la api activa para obtener la url del sistema.
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if ($client === null) {
            Log::channel('daily')->warning('ImplementationConversationService: cliente no encontrado para apertura de Etapa 5.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Nombre del cliente para los mensajes.
        $client_name = $client->resolve_display_name();

        // Nombre del admin asignado para el saludo en primera persona.
        $admin_name = $this->resolve_assigned_admin_name($implementation);

        // Teléfono del dueño para el mensaje de cierre.
        $owner_phone = trim((string) ($client->phone ?? ''));

        // URL del sistema del cliente: intentar spa_url primero, luego url.
        $client->loadMissing('active_client_api');
        $client_api  = $client->active_client_api;
        $system_url  = '';
        if ($client_api !== null) {
            $system_url = trim((string) ($client_api->spa_url ?? $client_api->url ?? ''));
        }

        // URL del centro de recursos (hardcodeada por ahora).
        $resources_url = 'https://recursos.comerciocity.com';

        // Cargar empleados del cliente.
        $client->loadMissing('client_employees');
        $employees = $client->client_employees;

        // Lista de nombres de empleados a los que se les envió el mensaje.
        $notified_names = [];

        // Enviar a cada empleado que tenga teléfono cargado.
        foreach ($employees as $employee) {
            $employee_phone = trim((string) ($employee->phone ?? ''));
            if ($employee_phone === '') {
                // Sin teléfono: omitir este empleado.
                continue;
            }

            // Nombre del empleado para el saludo personalizado.
            $employee_name = trim((string) ($employee->name ?? 'empleado'));

            // Construir el mensaje de credenciales para el empleado.
            $employee_message = "Hola {$employee_name}! Soy {$admin_name} de ComercioCity. Tu acceso al sistema de {$client_name} ya está listo 🎉\n\n";
            if ($system_url !== '') {
                $employee_message .= "Podés ingresar desde: {$system_url}\n\n";
            }
            $employee_message .= "Para aprender a usarlo, te compartimos el centro de recursos con videos por módulo: {$resources_url}\n\n";
            $employee_message .= "Cualquier duda escribinos por acá. ¡Éxitos!";

            $this->send_outbound($implementation, 5, $employee_phone, $employee_message);

            $notified_names[] = $employee_name;
        }

        // Persistir la lista de empleados notificados en el data del stage.
        $data['employees_notified'] = $notified_names;
        $stage->data                = $data;
        $stage->save();

        // Mensaje al dueño informando que las credenciales fueron enviadas al equipo.
        if ($owner_phone !== '') {
            $this->send_outbound(
                $implementation,
                5,
                $owner_phone,
                'Ya le enviamos las credenciales a tu equipo. Cuando hayan podido ingresar y recorrido el sistema, avanzamos con el siguiente paso.'
            );
        }
    }

    /**
     * Maneja un mensaje entrante durante la Etapa 5.
     *
     * Esta etapa avanza manualmente desde el admin. Cualquier mensaje del cliente
     * recibe una respuesta indicando que espere a que el equipo ingrese al sistema.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_5(Implementation $implementation, array $parsed): void
    {
        // Teléfono del remitente para enviar la respuesta de espera.
        $phone = (string) $parsed['from'];

        $this->send_outbound(
            $implementation,
            5,
            $phone,
            '¡Perfecto! Cuando tu equipo haya podido ingresar al sistema y lo haya recorrido un poco, avisanos para avanzar al siguiente paso.'
        );
    }

    // -------------------------------------------------------------------------
    // Etapa 6 — Vinculación AFIP/ARCA
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la Etapa 6 al dueño del cliente.
     *
     * Pregunta quién tiene los datos de acceso al AFIP de la empresa para coordinar
     * la vinculación con ARCA. Es idempotente: si data ya tiene 'current_question', no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_6_opening(Implementation $implementation): void
    {
        // Stage 6 de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 6)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 6 no encontrado para apertura.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage.
        $data = is_array($stage->data) ? $stage->data : [];

        // Idempotente: si ya se registró current_question, no reenviar la apertura.
        if (array_key_exists('current_question', $data)) {
            return;
        }

        // Teléfono del dueño del negocio (cliente).
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $owner_phone = trim((string) ($client->phone ?? ''));

        if ($owner_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: cliente sin teléfono para apertura de Etapa 6.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $implementation->client_id,
            ]);
            return;
        }

        // Registrar la primera pregunta pendiente y persistir.
        $data['current_question'] = 'afip_contact_name';
        $stage->data              = $data;
        $stage->save();

        $this->send_outbound(
            $implementation,
            6,
            $owner_phone,
            'Para poder emitir facturas electrónicas, necesitamos vincular el sistema con ARCA (antes AFIP). ¿Quién tiene los datos de acceso al AFIP de la empresa? ¿Lo manejás vos o hay un contador/encargado?'
        );
    }

    /**
     * Maneja un mensaje entrante durante la Etapa 6.
     *
     * Secuencia en data['current_question']:
     * 1. afip_contact_name  — recibe el nombre del responsable de AFIP.
     * 2. afip_contact_phone — solo si es otra persona; pide el teléfono de WhatsApp.
     * 3. afip_steps_sent    — al tener el teléfono, envía los pasos al responsable y notifica.
     *
     * También maneja mensajes del responsable de AFIP (si es distinto al dueño) mientras
     * la etapa está activa pero ya completada para el flujo bot.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_6(Implementation $implementation, array $parsed): void
    {
        // Stage 6 de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 6)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 6 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual: array con respuestas acumuladas y current_question.
        $data  = is_array($stage->data) ? $stage->data : [];
        $phone = (string) $parsed['from'];
        $body  = trim((string) ($parsed['body'] ?? ''));

        // Cliente dueño de la implementación.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $owner_phone = trim((string) ($client->phone ?? ''));

        // Nombre del admin asignado para los mensajes.
        $admin_name  = $this->resolve_assigned_admin_name($implementation);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Si no hay current_question → enviar la apertura como fallback.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_6_opening($implementation);
            return;
        }

        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada bot-side, verificar si el mensaje viene del responsable AFIP.
        if ($current_question === 'completed') {
            $afip_phone = trim((string) ($data['afip_contact_phone'] ?? ''));

            // Si el mensaje viene del responsable de AFIP (y es distinto al dueño), acusar recibo.
            if ($afip_phone !== '' && $phone === $afip_phone) {
                $this->send_outbound(
                    $implementation,
                    6,
                    $phone,
                    "Gracias, {$admin_name} va a revisar los archivos y te avisa."
                );
            }
            return;
        }

        if ($current_question === 'afip_contact_name') {
            if ($body === '') {
                $this->send_outbound($implementation, 6, $phone, '¿Quién tiene los datos de acceso al AFIP de la empresa?');
                return;
            }

            // Detectar si el dueño indicó que él mismo maneja el AFIP.
            $is_self = $this->is_self_referential_response($body, $client);

            if ($is_self) {
                // El dueño se encarga: usar su nombre y teléfono directamente.
                $afip_name  = $client ? $client->resolve_display_name() : $body;
                $afip_phone = $owner_phone;

                $data['afip_contact_name']  = $afip_name;
                $data['afip_contact_phone'] = $afip_phone;
                $data['current_question']   = 'afip_steps_sent';
                $stage->data                = $data;
                $stage->save();

                // Avanzar directamente al envío de pasos.
                $this->execute_afip_steps_sent($implementation, $stage, $data, $phone, $client_name, $admin_name);
                return;
            }

            // Tercera persona: guardar nombre y preguntar teléfono.
            $data['afip_contact_name'] = $body;
            $data['current_question']  = 'afip_contact_phone';
            $stage->data               = $data;
            $stage->save();

            $this->send_outbound(
                $implementation,
                6,
                $phone,
                "¿Cuál es el número de WhatsApp de {$body} para coordinar esto?"
            );
            return;
        }

        if ($current_question === 'afip_contact_phone') {
            if ($body === '') {
                $afip_name = (string) ($data['afip_contact_name'] ?? 'el responsable');
                $this->send_outbound($implementation, 6, $phone, "¿Cuál es el número de WhatsApp de {$afip_name}?");
                return;
            }

            // Guardar teléfono del responsable y avanzar al paso de envío.
            $data['afip_contact_phone'] = $body;
            $data['current_question']   = 'afip_steps_sent';
            $stage->data                = $data;
            $stage->save();

            $this->execute_afip_steps_sent($implementation, $stage, $data, $owner_phone, $client_name, $admin_name);
            return;
        }
    }

    /**
     * Ejecuta el paso de envío de pasos AFIP al responsable y notificaciones al admin y dueño.
     *
     * Separa esta lógica de handle_stage_6 para evitar duplicación entre el flujo de "yo mismo"
     * y el flujo de "tercero con teléfono ya cargado".
     *
     * @param Implementation       $implementation
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data           Data ya actualizada con afip_contact_phone.
     * @param string               $owner_phone    Teléfono del dueño para el mensaje final.
     * @param string               $client_name    Nombre resuelto del cliente.
     * @param string               $admin_name     Nombre del admin asignado.
     *
     * @return void
     */
    private function execute_afip_steps_sent(
        Implementation $implementation,
        ImplementationStage $stage,
        array $data,
        string $owner_phone,
        string $client_name,
        string $admin_name
    ): void {
        // Nombre y teléfono del responsable de AFIP.
        $afip_name  = (string) ($data['afip_contact_name'] ?? 'responsable');
        $afip_phone = (string) ($data['afip_contact_phone'] ?? '');

        // Link a la guía de AFIP (hardcodeado por ahora).
        $afip_guide_url = 'https://recursos.comerciocity.com/afip';

        // Mensaje al responsable de AFIP con los pasos a seguir.
        if ($afip_phone !== '') {
            $this->send_outbound(
                $implementation,
                6,
                $afip_phone,
                "Hola {$afip_name}! Soy {$admin_name} de ComercioCity. Para vincular el sistema de {$client_name} con AFIP necesitamos que completes estos pasos: {$afip_guide_url}. Cuando los tengas listos, avisanos por acá."
            );
        }

        // Marcar pasos enviados y completar el lado bot de la etapa.
        $data['afip_steps_sent'] = true;
        $data['completed']       = true;
        $data['current_question'] = 'completed';
        $stage->data              = $data;
        $stage->save();

        // Notificación al admin asignado para que haga la vinculación manualmente en ARCA.
        $admin_message = "📋 {$client_name} — Etapa 6: pasos de AFIP enviados a {$afip_name} ({$afip_phone}). Cuando el cliente complete los pasos, entrá al ARCA y hacé la vinculación.";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Mensaje al dueño informando que se enviaron los pasos.
        if ($owner_phone !== '') {
            $this->send_outbound(
                $implementation,
                6,
                $owner_phone,
                "Le envié los pasos a {$afip_name}. Cuando los complete, nos encargamos de la vinculación desde nuestro lado y te avisamos cuando esté lista."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Etapa 7 — Videollamada de capacitación
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la Etapa 7 al dueño del cliente.
     *
     * Coordina disponibilidad para la videollamada de cierre de implementación.
     * Es idempotente: si data ya tiene 'current_question', no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_7_opening(Implementation $implementation): void
    {
        // Stage 7 de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 7)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 7 no encontrado para apertura.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage.
        $data = is_array($stage->data) ? $stage->data : [];

        // Idempotente: si ya se registró current_question, no reenviar la apertura.
        if (array_key_exists('current_question', $data)) {
            return;
        }

        // Teléfono del dueño del negocio (cliente).
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $owner_phone = trim((string) ($client->phone ?? ''));

        if ($owner_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: cliente sin teléfono para apertura de Etapa 7.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $implementation->client_id,
            ]);
            return;
        }

        // Registrar la primera pregunta pendiente y persistir.
        $data['current_question'] = 'availability';
        $stage->data              = $data;
        $stage->save();

        $this->send_outbound(
            $implementation,
            7,
            $owner_phone,
            '¡Ya estamos en la última etapa! Para cerrar la implementación, nos gustaría hacer una videollamada corta (20-30 minutos) con vos y tu equipo para despejar cualquier duda del sistema. ¿Tenés disponibilidad esta semana? Indicame días y horarios que te vengan bien.'
        );
    }

    /**
     * Maneja un mensaje entrante durante la Etapa 7.
     *
     * Secuencia en data['current_question']:
     * 1. availability — espera texto libre con la disponibilidad del cliente.
     *    - Si el cliente quiere omitir la videollamada (detección por frases negativas), se marca skip.
     *    - Cualquier otro texto no vacío se acepta como disponibilidad.
     * 2. completed — ignorar mensajes posteriores.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_7(Implementation $implementation, array $parsed): void
    {
        // Stage 7 de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 7)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 7 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual: array con respuestas acumuladas y current_question.
        $data  = is_array($stage->data) ? $stage->data : [];
        $phone = (string) $parsed['from'];
        $body  = trim((string) ($parsed['body'] ?? ''));

        // Nombre del admin asignado y del cliente para los mensajes.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $admin_name  = $this->resolve_assigned_admin_name($implementation);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Si no hay current_question → enviar la apertura como fallback.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_7_opening($implementation);
            return;
        }

        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        if ($current_question === 'availability') {
            if ($body === '') {
                $this->send_outbound($implementation, 7, $phone, '¿Cuándo tenés disponibilidad para la videollamada?');
                return;
            }

            // Detectar si el cliente quiere omitir la videollamada.
            if ($this->is_skip_videocall_response($body)) {
                $data['skip_videocall']   = true;
                $data['completed']        = true;
                $data['current_question'] = 'completed';
                $stage->data              = $data;
                $stage->save();

                $this->send_outbound(
                    $implementation,
                    7,
                    $phone,
                    '¡Perfecto, sin problema! Si en algún momento tenés dudas, escribinos por acá. ¡Mucho éxito con el sistema! 🚀'
                );

                // Avisar al admin que el cliente no quiere la videollamada.
                $this->notify_assigned_admin(
                    $implementation,
                    "ℹ️ {$client_name} decidió no hacer la videollamada. Podés marcar la implementación como completada."
                );
                return;
            }

            // El cliente envió su disponibilidad: guardar y notificar al admin.
            $data['availability']     = $body;
            $data['completed']        = true;
            $data['current_question'] = 'completed';
            $stage->data              = $data;
            $stage->save();

            $this->send_outbound(
                $implementation,
                7,
                $phone,
                "Perfecto, le paso tu disponibilidad a {$admin_name} para que confirme el horario. Te avisamos a la brevedad."
            );

            // Notificar al admin asignado con la disponibilidad del cliente.
            $this->notify_assigned_admin(
                $implementation,
                "📅 {$client_name} — Etapa 7: disponibilidad para videollamada: {$body}. Confirmá el horario y avisale al cliente."
            );
            return;
        }
    }

    /**
     * Detecta si el mensaje indica que el cliente no quiere hacer la videollamada.
     *
     * @param string $body Texto del mensaje recibido.
     *
     * @return bool true si el cliente quiere omitir la videollamada.
     */
    private function is_skip_videocall_response(string $body): bool
    {
        // Normalizar: minúsculas y sin tildes para comparación robusta.
        $normalized = strtolower(trim($this->remove_accents($body)));

        // Frases que indican rechazo o innecesidad de la videollamada.
        $skip_signals = ['no quiero', 'no hace falta', 'no es necesario', 'no necesito', 'no gracias', 'no thank'];
        foreach ($skip_signals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Etapa 4 — Migración de datos (archivos Excel)
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 4.
     *
     * Los mensajes van dirigidos al responsable de migración (migration_contact_phone),
     * que puede ser distinto al dueño del negocio.
     *
     * Modelo de acumulación con debounce:
     * - Al recibir un documento: inferir categoría (artículos / clientes / proveedores) por
     *   contexto textual o nombre de archivo, acumular en el array correspondiente en `data`,
     *   y programar el procesamiento diferido via ImplementationStage4Scheduler.
     * - Al recibir texto: si es "no tengo" aplicar a la categoría esperada (waiting_for_category)
     *   o inferirla por palabras clave; si es otro texto, guardarlo como contexto futuro.
     * - El procesamiento efectivo ocurre en process_stage4_pending_files() una vez que
     *   el timer del scheduler expira sin recibir más mensajes.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_4(Implementation $implementation, array $parsed): void
    {
        // Stage 4 de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 4 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage con archivos acumulados y estado de categorías.
        $data = is_array($stage->data) ? $stage->data : [];

        // Teléfono destino: el responsable de migración (puede ser distinto al dueño).
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        $message_type  = (string) ($parsed['type'] ?? 'text');

        $client = $implementation->client ?? Client::find($implementation->client_id);

        // Si no hay current_question → la apertura no fue enviada aún; enviarla como fallback.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_4_opening($implementation);
            return;
        }

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        $current_question = (string) $data['current_question'];
        if ($current_question === 'completed') {
            return;
        }

        if ($message_type === 'document') {
            // Documento recibido: acumular en la categoría inferida y programar debounce.
            $this->handle_stage_4_document($stage, $data, $parsed, $implementation);
            return;
        }

        // Texto recibido: procesar como "no tengo" o guardar como contexto futuro.
        $body = trim((string) ($parsed['body'] ?? ''));
        $this->handle_stage_4_text($stage, $data, $body, $contact_phone, $implementation, $client);
    }

    /**
     * Acumula un documento recibido en la Etapa 4 y programa el procesamiento diferido.
     *
     * Infiere la categoría del archivo usando el texto del mensaje actual, el contexto
     * guardado del último texto recibido, y el nombre del archivo (en ese orden de prioridad).
     * Si no se puede inferir, el archivo queda en 'unclassified_files' y se pedirá aclaración.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data           Data actual del stage.
     * @param array<string, mixed> $parsed         Mensaje entrante.
     * @param Implementation       $implementation
     *
     * @return void
     */
    private function handle_stage_4_document(
        ImplementationStage $stage,
        array $data,
        array $parsed,
        Implementation $implementation
    ): void {
        // Datos del archivo recibido.
        $filename  = (string) ($parsed['inbound_media']['filename'] ?? '');
        $mime_type = (string) ($parsed['inbound_media']['mime'] ?? '');

        // Contextos para inferir la categoría: texto del mensaje actual y contexto previo guardado.
        $body_context = trim((string) ($parsed['body'] ?? ''));
        $last_context = trim((string) ($data['last_text_context'] ?? ''));

        // Registro del archivo para guardar en el data del stage.
        $file_record = [
            'filename' => $filename,
            'type'     => $mime_type,
        ];

        // Inferir categoría por contexto textual o nombre del archivo.
        $category = $this->infer_file_category($body_context, $last_context, $filename);

        if ($category !== null) {
            // Agregar el archivo al array de la categoría inferida.
            $category_key         = $category . '_files';
            $existing_files       = is_array($data[$category_key] ?? null) ? $data[$category_key] : [];
            $existing_files[]     = $file_record;
            $data[$category_key]  = $existing_files;

            // Limpiar el contexto de texto anterior ya utilizado para la inferencia.
            $data['last_text_context'] = '';
        } else {
            // Sin contexto suficiente: acumular en no clasificados para pedir aclaración.
            $unclassified     = is_array($data['unclassified_files'] ?? null) ? $data['unclassified_files'] : [];
            $unclassified[]   = $file_record;
            $data['unclassified_files'] = $unclassified;
        }

        // Marcar que estamos en modo acumulación (no secuencial como antes).
        $data['current_question'] = 'collecting_files';

        // Persistir el estado acumulado.
        $stage->data = $data;
        $stage->save();

        // Programar el procesamiento diferido con debounce: si llegan más archivos
        // antes de que expire el timer, el token anterior queda obsoleto y solo
        // el último job encolado ejecutará el procesamiento efectivo.
        $scheduler = new ImplementationStage4Scheduler();
        $scheduler->schedule_after_file_received($implementation->id);
    }

    /**
     * Procesa un mensaje de texto recibido en la Etapa 4.
     *
     * Si el texto es "no tengo" (o equivalente), aplica el skip a la categoría
     * correspondiente (esperada o inferida por palabras clave) y programa el scheduler.
     * Si no es "no tengo", guarda el texto como contexto para el próximo archivo recibido.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data           Data actual del stage.
     * @param string               $body           Texto del mensaje recibido.
     * @param string               $contact_phone  Teléfono del responsable de migración.
     * @param Implementation       $implementation
     * @param Client|null          $client
     *
     * @return void
     */
    private function handle_stage_4_text(
        ImplementationStage $stage,
        array $data,
        string $body,
        string $contact_phone,
        Implementation $implementation,
        ?Client $client
    ): void {
        if ($body === '') {
            return;
        }

        // Normalizar para detectar variantes de "no tengo".
        $normalized = strtolower($this->remove_accents($body));

        // Detectar si el cliente indica que no tiene un archivo específico.
        $is_no_tengo = str_contains($normalized, 'no tengo')
            || str_contains($normalized, 'no tenemos')
            || str_contains($normalized, 'no tenemos ese')
            || $normalized === 'no';

        if ($is_no_tengo) {
            // Determinar a qué categoría aplica el "no tengo": usar la categoría
            // que el sistema estaba esperando (waiting_for_category) o inferirla por palabras clave.
            $waiting_category = trim((string) ($data['waiting_for_category'] ?? ''));

            if ($waiting_category !== '') {
                // Hay una categoría esperada explícita: aplicar el skip a esa categoría.
                $data[$waiting_category . '_files'] = 'skipped';
                $data['waiting_for_category']       = '';
                $data['current_question']           = 'collecting_files';
                $stage->data                        = $data;
                $stage->save();

                // Programar el procesamiento diferido para evaluar el estado completo.
                $scheduler = new ImplementationStage4Scheduler();
                $scheduler->schedule_after_file_received($implementation->id);
                return;
            }

            // Sin categoría esperada: intentar inferirla por palabras clave en el texto.
            $inferred_category = $this->infer_no_tengo_category($normalized);

            if ($inferred_category !== null) {
                $data[$inferred_category . '_files'] = 'skipped';
                $data['current_question']            = 'collecting_files';
                $stage->data                         = $data;
                $stage->save();

                $scheduler = new ImplementationStage4Scheduler();
                $scheduler->schedule_after_file_received($implementation->id);
                return;
            }
        }

        // No es "no tengo" reconocible: guardar como contexto para inferir la categoría
        // del próximo archivo que llegue. No responder nada al cliente.
        $data['last_text_context'] = $body;
        $stage->data               = $data;
        $stage->save();
    }

    /**
     * Infiere la categoría de un archivo recibido en la Etapa 4.
     *
     * Orden de prioridad:
     * 1. Texto del mensaje actual (body) — el cliente puede indicar de qué es el archivo.
     * 2. Contexto previo guardado (último texto recibido antes del documento).
     * 3. Nombre del archivo.
     *
     * @param string $body         Texto del mensaje actual.
     * @param string $last_context Último texto recibido (guardado en data['last_text_context']).
     * @param string $filename     Nombre del archivo recibido.
     *
     * @return string|null 'articles' | 'clients' | 'suppliers' | null si no se puede inferir.
     */
    private function infer_file_category(string $body, string $last_context, string $filename): ?string
    {
        // Revisar los contextos disponibles en orden de prioridad.
        // El cuerpo del mensaje actual tiene más peso que el contexto guardado.
        $contexts = [];
        if ($body !== '') {
            $contexts[] = $body;
        }
        if ($last_context !== '') {
            $contexts[] = $last_context;
        }
        if ($filename !== '') {
            $contexts[] = $filename;
        }

        foreach ($contexts as $ctx) {
            $normalized = strtolower($this->remove_accents($ctx));

            // Palabras clave de artículos/productos.
            if (str_contains($normalized, 'articulo') || str_contains($normalized, 'producto')) {
                return 'articles';
            }

            // Palabras clave de clientes.
            if (str_contains($normalized, 'cliente')) {
                return 'clients';
            }

            // Palabras clave de proveedores.
            if (str_contains($normalized, 'proveedor') || str_contains($normalized, 'prov')) {
                return 'suppliers';
            }
        }

        return null;
    }

    /**
     * Infiere a qué categoría pertenece un mensaje de tipo "no tengo" basándose en palabras clave.
     *
     * @param string $normalized_body Texto ya normalizado (sin tildes, minúsculas).
     *
     * @return string|null 'articles' | 'clients' | 'suppliers' | null si no se puede inferir.
     */
    private function infer_no_tengo_category(string $normalized_body): ?string
    {
        if (str_contains($normalized_body, 'articulo') || str_contains($normalized_body, 'producto')) {
            return 'articles';
        }

        if (str_contains($normalized_body, 'cliente')) {
            return 'clients';
        }

        if (str_contains($normalized_body, 'proveedor')) {
            return 'suppliers';
        }

        return null;
    }

    /**
     * Verifica si una categoría de la Etapa 4 está resuelta (tiene archivos o fue omitida).
     *
     * Soporta tanto la nueva estructura (articles_files array / 'skipped') como la
     * estructura anterior (articles_excel true / 'skipped') para compatibilidad con
     * implementaciones que comenzaron con la versión previa del flujo.
     *
     * @param array<string, mixed> $data     Data del stage 4.
     * @param string               $category 'articles' | 'clients' | 'suppliers'.
     *
     * @return bool true si la categoría tiene archivos o fue marcada como omitida.
     */
    private function is_stage4_category_resolved(array $data, string $category): bool
    {
        // Nueva estructura: array de archivos recibidos o string 'skipped'.
        $new_key   = $category . '_files';
        $new_value = $data[$new_key] ?? null;

        if ($new_value === 'skipped') {
            return true;
        }
        if (is_array($new_value) && count($new_value) > 0) {
            return true;
        }

        // Estructura anterior (compatibilidad): articles_excel / clients_excel / suppliers_excel.
        $old_key   = $category . '_excel';
        $old_value = $data[$old_key] ?? null;

        if ($old_value === true || $old_value === 'skipped') {
            return true;
        }

        return false;
    }

    /**
     * Cierra la Etapa 4: envía confirmación al responsable de migración, notifica al admin
     * con el resumen de archivos recibidos y dispara el evento Pusher.
     *
     * @param Implementation       $implementation
     * @param string               $contact_phone  Teléfono del responsable de migración.
     * @param Client|null          $client
     * @param array<string, mixed> $data           Data final del stage 4 con el estado de cada archivo.
     *
     * @return void
     */
    private function finish_stage_4(
        Implementation $implementation,
        string $contact_phone,
        ?Client $client,
        array $data
    ): void {
        $client_name = $client
            ? $client->resolve_display_name()
            : "Cliente #{$implementation->client_id}";

        // Mensaje de cierre al responsable de migración.
        $this->send_outbound(
            $implementation,
            4,
            $contact_phone,
            '¡Listo, recibí todo! Vamos a procesar los archivos y te avisamos cuando estén cargados en el sistema.'
        );

        // Icono de estado por tipo de archivo: ✅ si se recibió o enviaron varios, — si fue omitido.
        $articles_icon  = $this->is_stage4_category_resolved($data, 'articles') ? '✅' : '—';
        $clients_icon   = $this->is_stage4_category_resolved($data, 'clients') ? '✅' : '—';
        $suppliers_icon = $this->is_stage4_category_resolved($data, 'suppliers') ? '✅' : '—';

        $admin_message = "✅ {$client_name} completó la Etapa 4. Archivos recibidos: artículos {$articles_icon} | clientes {$clients_icon} | proveedores {$suppliers_icon}. Podés proceder con la importación.";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Evento Pusher para notificar al panel en tiempo real.
        event(new ImplementationStageCompleted($implementation->id, 4, $client_name));
    }

    // -------------------------------------------------------------------------
    // Etapa 4 — Procesamiento diferido de archivos acumulados (llamado desde Job)
    // -------------------------------------------------------------------------

    /**
     * Procesa los archivos acumulados en la Etapa 4 una vez que el timer de debounce expira.
     *
     * Llamado desde ProcessImplementationStage4Files cuando el token de programación
     * sigue siendo vigente, es decir, el cliente no envió más archivos dentro del período
     * de espera configurado.
     *
     * Lógica:
     * 1. Si hay archivos sin clasificar: preguntar de qué son.
     * 2. Si todas las categorías están resueltas: llamar a finish_stage_4().
     * 3. Si solo artículos están resueltos: preguntar por clientes.
     * 4. Si artículos y clientes están resueltos: preguntar por proveedores.
     * 5. Si artículos no están resueltos y no hay nada: solo esperar (el timer solo
     *    se activa cuando llega algo, así que en este caso hubo texto sin archivos).
     *
     * @param int $implementation_id ID de la implementación a procesar.
     *
     * @return void
     */
    public function process_stage4_pending_files(int $implementation_id): void
    {
        // Cargar la implementación y su stage 4.
        $implementation = Implementation::find($implementation_id);

        if ($implementation === null) {
            Log::channel('daily')->warning('process_stage4_pending_files: implementación no encontrada.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        $stage = ImplementationStage::where('implementation_id', $implementation_id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('process_stage4_pending_files: stage 4 no encontrado.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Data actual con el estado de archivos acumulados por categoría.
        $data = is_array($stage->data) ? $stage->data : [];

        // Teléfono del responsable de migración y cliente para mensajes.
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        $client        = $implementation->client ?? Client::find($implementation->client_id);

        if ($contact_phone === '') {
            Log::channel('daily')->warning('process_stage4_pending_files: contact_phone vacío, no se puede enviar mensaje.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Verificar si hay archivos sin clasificar: pedir aclaración antes de seguir.
        $unclassified = is_array($data['unclassified_files'] ?? null) ? $data['unclassified_files'] : [];

        if (count($unclassified) > 0) {
            // Mensaje natural indicando que se recibieron archivos pero se necesita saber de qué son.
            $count = count($unclassified);
            $label = $count === 1 ? 'un archivo' : "{$count} archivos";

            $this->send_outbound(
                $implementation,
                4,
                $contact_phone,
                "Recibí {$label} pero no quedó claro si son de productos, clientes o proveedores. ¿Me podés aclarar?"
            );
            return;
        }

        // Evaluar el estado de resolución de cada categoría.
        $articles_done  = $this->is_stage4_category_resolved($data, 'articles');
        $clients_done   = $this->is_stage4_category_resolved($data, 'clients');
        $suppliers_done = $this->is_stage4_category_resolved($data, 'suppliers');

        // Todas las categorías resueltas: completar la etapa.
        if ($articles_done && $clients_done && $suppliers_done) {
            $data['current_question'] = 'completed';
            $data['completed']        = true;
            $stage->data              = $data;
            $stage->save();

            $this->finish_stage_4($implementation, $contact_phone, $client, $data);
            return;
        }

        // Artículos no resueltos: aún no llegó el archivo principal; solo esperar.
        // (El timer solo se activa cuando llega algo, así que puede haber llegado un texto
        // de contexto antes del archivo. En ese caso, no hay nada que preguntar todavía.)
        if (! $articles_done) {
            return;
        }

        // Artículos recibidos, falta clientes: preguntar de forma natural.
        if (! $clients_done) {
            // Registrar que el sistema está esperando la respuesta sobre clientes.
            $data['waiting_for_category'] = 'clients';
            $stage->data                  = $data;
            $stage->save();

            $this->send_outbound(
                $implementation,
                4,
                $contact_phone,
                '¿También tenés los datos de tus clientes para migrar?'
            );
            return;
        }

        // Artículos y clientes resueltos, falta proveedores: preguntar de forma natural.
        if (! $suppliers_done) {
            // Registrar que el sistema está esperando la respuesta sobre proveedores.
            $data['waiting_for_category'] = 'suppliers';
            $stage->data                  = $data;
            $stage->save();

            $this->send_outbound(
                $implementation,
                4,
                $contact_phone,
                '¿Manejás proveedores registrados? ¿Tenés un Excel con esa info?'
            );
            return;
        }
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
     * Dos modos de detección:
     * 1. Señal explícita: el cuerpo contiene "listo", "terminé", "es todo", etc.
     * 2. Señal implícita: el cliente ya envió más de 3 mensajes acumulados y el
     *    último es muy corto (menos de 4 palabras) y no contiene números → se interpreta
     *    como fin de la carga. Si hay duda → NO avanzar (acumular). Solo avanzar cuando
     *    la señal es explícita o cuando la heurística es confiable.
     *
     * @param string               $body Texto del mensaje (sin normalizar).
     * @param array<string, mixed> $data Data actual del stage (para contar mensajes acumulados).
     *
     * @return bool
     */
    private function is_employees_done_signal(string $body, array $data = []): bool
    {
        // Texto normalizado para comparar frases de cierre comunes.
        $normalized = strtolower(trim($this->remove_accents($body)));

        // Señales explícitas de finalización.
        $done_signals = ['listo', 'ya esta', 'ya listo', 'eso es todo', 'termine', 'es todo', 'fin', 'finalice', 'listo ya'];
        foreach ($done_signals as $signal) {
            if ($normalized === $signal || str_contains($normalized, $signal)) {
                return true;
            }
        }

        // Detección implícita: solo si hay más de 3 mensajes acumulados.
        // Se cuenta por la cantidad de líneas en el campo employees.
        $accumulated = trim((string) ($data['employees'] ?? ''));
        if ($accumulated !== '') {
            // Cada mensaje acumulado ocupa al menos una línea.
            $message_count = substr_count($accumulated, "\n") + 1;

            if ($message_count > 3) {
                // Contar palabras del mensaje entrante.
                $words      = preg_split('/\s+/', $body) ?: [];
                $word_count = count(array_filter($words, fn($w) => $w !== ''));

                // Sin números y con menos de 4 palabras: interpretarlo como señal de fin.
                $has_numbers = (bool) preg_match('/\d/', $body);

                if ($word_count < 4 && ! $has_numbers) {
                    return true;
                }
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
     * @param string               $key            Clave de la pregunta.
     * @param array<string, mixed> $data           Data actual del stage (puede ser necesaria para contexto).
     * @param Client|null          $client         Cliente para personalizar saludos.
     * @param Implementation|null  $implementation Implementación activa (para resolver admin asignado).
     *
     * @return string
     */
    private function build_question_text(string $key, array $data, ?Client $client, ?Implementation $implementation = null): string
    {
        switch ($key) {
            case 'use_price_lists':
                return $this->build_question_use_price_lists($implementation, $client);
            case 'price_lists':
                return "Perfecto. Indicame los nombres de tus listas de precios y el margen de ganancia por defecto de cada una. Ejemplo:\n1. Minorista 30%\n2. Mayorista 20%\n(Si no tenés margen fijo, decime solo los nombres)";
            case 'use_deposits':
                return $this->build_question_use_deposits($client);
            case 'deposit_names':
                return "Indicame los nombres de cada sucursal o depósito tal como querés que aparezcan en el sistema.";
            case 'payment_discounts':
                return "¿Manejás algún descuento o recargo según cómo te pagan? Por ejemplo efectivo con descuento, transferencia con recargo, o algo por el estilo.";
            case 'company_name':
                return "¿Cuál es el nombre de tu empresa tal como debe figurar en los comprobantes?";
            case 'employees':
                // Sin instrucción de "escribí listo": el sistema detecta el fin de forma inteligente.
                return "Necesito los datos de vos y de todos los empleados que van a usar el sistema. Por cada persona indicame nombre completo, número de documento y de qué área se va a encargar (por ejemplo: ventas, stock, administración).\nEsa info nos sirve para asignarle permisos iniciales a cada uno — los permisos se pueden ajustar más adelante cuando el sistema esté en marcha.";
            case 'logo_received':
                return "Por último, enviame el logo de tu empresa en formato cuadrado. Lo vamos a usar en los comprobantes.";
            case 'ask_amount_in_vender':
                return "Cuando cargás una venta, ¿preferís que el sistema te pregunte cuántas unidades querés vender de cada producto, o que agregue una unidad automáticamente y vos la cambiás si hace falta?";
            case 'default_cuenta_corriente':
                return "Cuando asignás un cliente a una venta, ¿querés que quede en cuenta corriente automáticamente, o preferís indicarlo manualmente cada vez?";
            default:
                return '';
        }
    }

    /**
     * Construye el texto de la primera pregunta (listas de precios / arranque).
     *
     * Si el lead de origen tiene `use_price_lists = true` → enviar saludo y pedir
     * directamente los nombres de las listas (saltar la confirmación sí/no).
     * Si no hay dato previo → preguntar la opción (Precio único / Listas de precios).
     *
     * El admin asignado a la implementación se usa para personalizar el saludo en
     * primera persona; fallback: "el equipo de ComercioCity".
     *
     * @param Implementation|null $implementation Implementación activa para resolver el admin asignado.
     * @param Client|null         $client         Cliente para personalizar el saludo.
     *
     * @return string
     */
    private function build_question_use_price_lists(?Implementation $implementation, ?Client $client): string
    {
        // Nombre del cliente para el saludo.
        $display_name = $client ? $client->resolve_display_name() : 'cliente';

        // Nombre del admin asignado para presentarse en primera persona.
        $admin_name = $implementation ? $this->resolve_assigned_admin_name($implementation) : 'el equipo de ComercioCity';

        // Buscar el lead promovido para leer la preferencia del proceso de venta.
        $promoted_lead = $client ? $this->find_promoted_lead($client) : null;

        if ($promoted_lead !== null && isset($promoted_lead->use_price_lists) && $promoted_lead->use_price_lists === true) {
            // Lead confirmado con listas de precios: pedir directamente los nombres.
            return "Hola {$display_name}! Soy {$admin_name}. Para arrancar con la configuración: en la demo trabajaste con listas de precios. Indicame los nombres de tus listas y el margen de ganancia por defecto de cada una. Ejemplo:\n\nMinorista 30%\nMayorista 20%\n(Si no tenés margen fijo, decime solo los nombres)";
        }

        return "Hola {$display_name}! Soy {$admin_name}. Para arrancar con la configuración: ¿vas a manejar un único precio de venta por producto, o necesitás varias listas de precios con distintos márgenes? (respondé Precio único o Listas de precios)";
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

    /**
     * Genera un acuse de recibo breve y aleatorio para anteponer a la siguiente pregunta.
     *
     * Las variantes evitan que el flujo suene repetitivo al confirmar cada respuesta.
     *
     * @return string
     */
    private function build_acknowledgement(): string
    {
        // Opciones de confirmación corta variadas para naturalidad conversacional.
        $options = ['Ok, anotado.', 'Perfecto.', 'Genial, gracias.', 'Listo.', 'Anotado 👍'];
        return $options[array_rand($options)];
    }

    // -------------------------------------------------------------------------
    // Finalización de Etapa 1
    // -------------------------------------------------------------------------

    /**
     * Cierra la Etapa 1: envía confirmación al cliente, dispara evento Pusher
     * y notifica al admin asignado por WhatsApp.
     *
     * NO avanza el current_stage de la implementación; eso lo hace el admin desde el panel.
     *
     * @param Implementation $implementation
     * @param string         $phone   Teléfono del cliente para la confirmación.
     * @param Client|null    $client  Cliente para personalizar el cierre.
     *
     * @return void
     */
    private function finish_stage_1(Implementation $implementation, string $phone, ?Client $client = null): void
    {
        // Nombre del cliente para personalizar el mensaje de cierre.
        $client_name = $client
            ? $client->resolve_display_name()
            : "Cliente #{$implementation->client_id}";

        // Mensaje de confirmación en primera persona (firmado implícitamente por el admin asignado).
        $client_message = "¡Perfecto, tenemos todo lo que necesito! Voy a revisar la información y te aviso cuando el sistema esté listo para el siguiente paso. ¡Gracias {$client_name}!";
        $this->send_outbound($implementation, 1, $phone, $client_message);

        // Evento Pusher al canal private-admin para notificar en tiempo real al panel.
        event(new ImplementationStageCompleted(
            $implementation->id,
            1,
            $client_name
        ));

        // Notificación WhatsApp al admin asignado.
        $this->notify_assigned_admin_stage1_complete($implementation, $client_name);
    }

    /**
     * Envía notificación WhatsApp al admin asignado indicando que el cliente completó la Etapa 1.
     *
     * Delegado a `notify_assigned_admin` para reutilizar la lógica de resolución del admin.
     *
     * @param Implementation $implementation
     * @param string         $client_name Nombre ya resuelto del cliente para el mensaje.
     *
     * @return void
     */
    private function notify_assigned_admin_stage1_complete(Implementation $implementation, string $client_name): void
    {
        $body = "✅ {$client_name} completó la Etapa 1 de implementación. Podés revisar los datos en el admin.";
        $this->notify_assigned_admin($implementation, $body);
    }

    /**
     * Envía una notificación por WhatsApp al admin asignado a la implementación.
     *
     * Resuelve el admin por `assigned_admin_id` y envía el texto indicado.
     * Si el admin no existe o no tiene campo phone, registra el aviso en logs sin lanzar excepción.
     *
     * @param Implementation $implementation
     * @param string         $message Texto completo a enviar al admin.
     *
     * @return void
     */
    private function notify_assigned_admin(Implementation $implementation, string $message): void
    {
        // Buscar el admin asignado por su ID registrado en la implementación.
        $assigned_admin = $implementation->assigned_admin_id
            ? Admin::find($implementation->assigned_admin_id)
            : null;

        if ($assigned_admin === null) {
            Log::channel('daily')->warning('ImplementationConversationService: admin asignado no encontrado; no se envió notificación.', [
                'implementation_id'  => $implementation->id,
                'assigned_admin_id'  => $implementation->assigned_admin_id,
            ]);
            return;
        }

        // El campo `phone` puede no existir en todos los admins; acceso dinámico seguro.
        $admin_phone = trim((string) ($assigned_admin->phone ?? ''));

        if ($admin_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: admin asignado sin campo phone; no se envió notificación.', [
                'implementation_id' => $implementation->id,
                'admin_id'          => $assigned_admin->id,
            ]);
            return;
        }

        $this->whatsapp_send_service->send_text($admin_phone, $message);
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
     * Resuelve el nombre del admin asignado a la implementación.
     *
     * Busca el Admin por assigned_admin_id y retorna su nombre.
     * Si no hay asignado o no se encuentra, retorna el fallback institucional.
     *
     * @param Implementation $implementation
     *
     * @return string Nombre del admin o fallback "el equipo de ComercioCity".
     */
    private function resolve_assigned_admin_name(Implementation $implementation): string
    {
        if (! $implementation->assigned_admin_id) {
            return 'el equipo de ComercioCity';
        }

        $admin = Admin::find($implementation->assigned_admin_id);

        if ($admin === null || empty($admin->name)) {
            return 'el equipo de ComercioCity';
        }

        return $admin->name;
    }

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
     * Determina si la respuesta del dueño indica que él mismo se encargará de la migración.
     *
     * Compara el texto recibido con frases de primera persona ("yo", "yo mismo", etc.)
     * y con el nombre propio del cliente. Usado en la Etapa 2 para decidir si
     * saltar la pregunta del teléfono del responsable.
     *
     * @param string      $body   Texto de la respuesta recibida.
     * @param Client|null $client Cliente para comparar con su nombre propio.
     *
     * @return bool true si el dueño se designó a sí mismo.
     */
    private function is_self_referential_response(string $body, ?Client $client): bool
    {
        // Normalizar: minúsculas y sin tildes para comparación robusta.
        $normalized = strtolower(trim($this->remove_accents($body)));

        // Frases que indican que el propio dueño se hará cargo.
        $self_signals = ['yo', 'yo mismo', 'yo misma', 'yo lo hago', 'lo hago yo', 'soy yo', 'yo me encargo', 'me encargo yo'];
        foreach ($self_signals as $signal) {
            if ($normalized === $signal || str_contains($normalized, $signal)) {
                return true;
            }
        }

        // Comparar con el nombre de display del cliente para detectar auto-referencia en tercera persona.
        if ($client !== null) {
            $display_name_normalized = strtolower(trim($this->remove_accents($client->resolve_display_name())));
            if ($display_name_normalized !== '' && str_contains($normalized, $display_name_normalized)) {
                return true;
            }

            // También comparar con el campo name si es distinto al display name.
            $client_name_normalized = strtolower(trim($this->remove_accents((string) ($client->name ?? ''))));
            if ($client_name_normalized !== '' && str_contains($normalized, $client_name_normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Intenta extraer nombres de depósitos/sucursales desde el mismo mensaje de confirmación.
     *
     * Solo retorna nombres si el cuerpo contiene al menos dos segmentos que parecen
     * nombres de lugares (separados por "y" o ",") y que no sean palabras de confirmación.
     * Si hay duda o menos de 2 nombres reales → retorna null para seguir el flujo normal.
     *
     * Ejemplo: "sí, entre ríos y santa fe" → "Entre Ríos, Santa Fe"
     *
     * @param string $body Texto del mensaje tal como llegó (sin normalizar).
     *
     * @return string|null Nombres formateados (ucwords), separados por ", ", o null si no se pudo extraer.
     */
    private function try_extract_deposit_names_from_message(string $body): ?string
    {
        // Versión normalizada para detectar si hay confirmación en el mensaje.
        $normalized = strtolower(trim($this->remove_accents($body)));

        // Palabras que son señales de confirmación, NO son nombres de lugares.
        $confirmation_only_words = [
            'si', 'sí', 'claro', 'dale', 'ok', 'yes', 'exacto', 'correcto',
            'afirmativo', 'varias', 'sucursales', 'depositos', 'depósitos',
            'varios depositos', 'varias sucursales',
        ];

        // Dividir el mensaje por "y" (con espacios) y por comas.
        $raw_parts = preg_split('/\s+y\s+|,/', $body) ?: [];

        // Acumular partes que parecen nombres reales de lugares.
        $names = [];

        foreach ($raw_parts as $part) {
            // Limpiar espacios y puntuación circundante.
            $clean = trim($part, " \t\n\r.,!?");

            if ($clean === '') {
                continue;
            }

            // Versión normalizada de esta parte para comparar contra palabras de confirmación.
            $clean_normalized = strtolower(trim($this->remove_accents($clean)));

            // Descartar si es solo una palabra de confirmación.
            if (in_array($clean_normalized, $confirmation_only_words, true)) {
                continue;
            }

            // Descartar si es demasiado corto (menos de 3 caracteres).
            if (mb_strlen($clean) < 3) {
                continue;
            }

            // Capitalizar cada palabra del nombre y agregar a la lista.
            $names[] = ucwords(strtolower($clean));
        }

        // Retornar solo si se encontraron al menos 2 nombres distintos.
        if (count($names) < 2) {
            return null;
        }

        return implode(', ', $names);
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
