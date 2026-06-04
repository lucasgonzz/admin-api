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

        // Etapas 5–7: aún no implementadas; registrar para depuración.
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

        if ($this->is_employees_done_signal($body)) {
            // Verificar que haya algo acumulado antes de avanzar.
            $accumulated = trim((string) ($data['employees'] ?? ''));
            if ($accumulated === '') {
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

            // Acuse de recibo antes de la siguiente pregunta al completar employees.
            $outbound_text = $this->build_acknowledgement() . ' ' . $next_question_text;
            $this->send_outbound($implementation, 1, $phone, $outbound_text);
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
     * iniciar la conversación de forma proactiva (etapas 2 y 4).
     *
     * @param Implementation $implementation Implementación activa.
     * @param int            $stage          Número de etapa a abrir (2 o 4).
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

        // Nombre del responsable: leerlo del data de la Etapa 2.
        $stage_2      = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 2)
            ->first();
        $stage_2_data     = ($stage_2 !== null && is_array($stage_2->data)) ? $stage_2->data : [];
        $responsible_name = (string) ($stage_2_data['migration_responsible_name'] ?? 'responsable');

        // Nombre del cliente y del admin para personalizar el saludo.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";
        $admin_name  = $this->resolve_assigned_admin_name($implementation);

        // Registrar la primera pregunta pendiente y persistir.
        $data['current_question'] = 'articles_excel';
        $stage->data              = $data;
        $stage->save();

        $question_text = "Hola {$responsible_name}! Soy {$admin_name} de ComercioCity. Arrancamos con la migración de datos para {$client_name}. Primero necesito el archivo Excel con los *productos o artículos*. Podés enviarlo directamente por acá.";
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

                $this->finish_stage_2($implementation, $phone, $client, $responsible_name, $responsible_phone);
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
     *
     * @return void
     */
    private function finish_stage_2(
        Implementation $implementation,
        string $owner_phone,
        ?Client $client,
        string $responsible_name,
        string $responsible_phone
    ): void {
        // Persistir el teléfono de contacto en la implementación para uso en etapas posteriores.
        $implementation->migration_contact_phone = $responsible_phone;
        $implementation->save();

        $client_name = $client
            ? $client->resolve_display_name()
            : "Cliente #{$implementation->client_id}";

        // Mensaje de cierre al dueño indicando que se contactará al responsable.
        $this->send_outbound(
            $implementation,
            2,
            $owner_phone,
            "¡Perfecto! Le voy a escribir a {$responsible_name} para coordinar el envío de los archivos."
        );

        // Notificación al admin asignado con los datos del responsable de migración.
        $admin_message = "✅ {$client_name} completó la Etapa 2. Responsable de migración: {$responsible_name} ({$responsible_phone}).";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Evento Pusher para notificar al panel en tiempo real.
        event(new ImplementationStageCompleted($implementation->id, 2, $client_name));
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
     * Flujo de preguntas en `data['current_question']`:
     * 1. articles_excel: solicitar Excel de artículos (type = document).
     * 2. clients_excel: solicitar Excel de clientes (document o "no tengo").
     * 3. suppliers_excel: solicitar Excel de proveedores (document o "no tengo").
     * 4. Al completar los tres → mensaje de cierre, notificar admin, disparar evento.
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

        $data = is_array($stage->data) ? $stage->data : [];

        // Teléfono destino: el responsable de migración (puede ser distinto al dueño).
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        $body          = trim((string) ($parsed['body'] ?? ''));
        $message_type  = (string) ($parsed['type'] ?? 'text');

        $client = $implementation->client ?? Client::find($implementation->client_id);

        // Si no hay current_question → la apertura no fue enviada aún; enviarla como fallback.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_4_opening($implementation);
            return;
        }

        // Pregunta actualmente pendiente.
        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        if ($current_question === 'articles_excel') {
            if ($message_type === 'document') {
                // Archivo de artículos recibido: avanzar a clientes.
                $data['articles_excel']   = true;
                $data['current_question'] = 'clients_excel';
                $stage->data              = $data;
                $stage->save();

                $next_text = "Perfecto, recibí el archivo de productos. Ahora necesito el Excel con los *clientes* (nombre, teléfono, deuda si tienen cuenta corriente). Si no tenés ese archivo o no manejás cuentas corrientes, respondé *no tengo*.";
                $this->send_outbound($implementation, 4, $contact_phone, $this->build_acknowledgement() . ' ' . $next_text);
                return;
            }

            // No es un archivo adjunto: pedir el Excel.
            $this->send_outbound($implementation, 4, $contact_phone, 'Necesito el archivo Excel adjunto, no el texto. ¿Podés enviarlo como archivo?');
            return;
        }

        if ($current_question === 'clients_excel') {
            if ($message_type === 'document') {
                // Archivo de clientes recibido: avanzar a proveedores.
                $data['clients_excel']    = true;
                $data['current_question'] = 'suppliers_excel';
                $stage->data              = $data;
                $stage->save();

                $next_text = "Bien. Por último, el Excel de *proveedores* (nombre, contacto, productos que proveen). Si no tenés ese archivo, respondé *no tengo*.";
                $this->send_outbound($implementation, 4, $contact_phone, $this->build_acknowledgement() . ' ' . $next_text);
                return;
            }

            // Verificar si respondió "no tengo" o equivalente.
            $normalized_body = strtolower($this->remove_accents($body));
            if (str_contains($normalized_body, 'no tengo') || $normalized_body === 'no') {
                $data['clients_excel']    = 'skipped';
                $data['current_question'] = 'suppliers_excel';
                $stage->data              = $data;
                $stage->save();

                $next_text = "Anotado. Por último, el Excel de *proveedores* (nombre, contacto, productos que proveen). Si no tenés ese archivo, respondé *no tengo*.";
                $this->send_outbound($implementation, 4, $contact_phone, $next_text);
                return;
            }

            // Respuesta no reconocida: pedir el archivo o confirmación de ausencia.
            $this->send_outbound($implementation, 4, $contact_phone, 'Necesito el archivo Excel de clientes adjunto, o respondé *no tengo* si no lo tenés.');
            return;
        }

        if ($current_question === 'suppliers_excel') {
            if ($message_type === 'document') {
                // Archivo de proveedores recibido: completar etapa.
                $data['suppliers_excel']  = true;
                $data['current_question'] = 'completed';
                $data['completed']        = true;
                $stage->data              = $data;
                $stage->save();

                $this->finish_stage_4($implementation, $contact_phone, $client, $data);
                return;
            }

            // Verificar si respondió "no tengo" o equivalente.
            $normalized_body = strtolower($this->remove_accents($body));
            if (str_contains($normalized_body, 'no tengo') || $normalized_body === 'no') {
                $data['suppliers_excel']  = 'skipped';
                $data['current_question'] = 'completed';
                $data['completed']        = true;
                $stage->data              = $data;
                $stage->save();

                $this->finish_stage_4($implementation, $contact_phone, $client, $data);
                return;
            }

            // Respuesta no reconocida: pedir el archivo o confirmación de ausencia.
            $this->send_outbound($implementation, 4, $contact_phone, 'Necesito el archivo Excel de proveedores adjunto, o respondé *no tengo* si no lo tenés.');
            return;
        }
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

        // Icono de estado por tipo de archivo: ✅ si se recibió, — si fue omitido.
        $articles_icon  = '✅';
        $clients_icon   = ($data['clients_excel'] ?? '') === true ? '✅' : '—';
        $suppliers_icon = ($data['suppliers_excel'] ?? '') === true ? '✅' : '—';

        $admin_message = "✅ {$client_name} completó la Etapa 4. Archivos recibidos: artículos {$articles_icon} | clientes {$clients_icon} | proveedores {$suppliers_icon}. Podés proceder con la importación.";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Evento Pusher para notificar al panel en tiempo real.
        event(new ImplementationStageCompleted($implementation->id, 4, $client_name));
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
                return "¿Aplicás descuentos o recargos según el método de pago? Si es así, indicame los porcentajes. Ejemplo:\n- Efectivo: 10% descuento\n- Transferencia: 10% recargo\nSi cobrás igual independientemente del método, respondé No.";
            case 'company_name':
                return "¿Cuál es el nombre de tu empresa tal como debe figurar en los comprobantes?";
            case 'employees':
                // Texto actualizado con aclaración de permisos iniciales.
                return "Necesito los datos de vos y de todos los empleados que van a usar el sistema. Por cada persona indicame nombre completo, número de documento y de qué área se va a encargar (por ejemplo: ventas, stock, administración).\nEsa info nos sirve para asignarle permisos iniciales a cada uno — los permisos se pueden ajustar más adelante cuando el sistema esté en marcha.\nPodés mandarlo en varios mensajes si querés, y cuando termines escribí listo.";
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
