<?php

namespace App\Services;

use App\Events\ImplementationStageCompleted;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\ClientInstallation;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\Lead;
use App\Models\Version;
use Illuminate\Support\Facades\Http;
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
     * @var ImplementationAiInterpreter Intérprete semántico de respuestas del cliente via Claude.
     */
    private $ai_interpreter;

    /**
     * @param WhatsappSendService|null        $whatsapp_send_service Inyección opcional para tests.
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
            // Etapa 1: formulario de configuración - reenviar link si aún no fue completado.
            $this->handle_stage_1($implementation, $parsed);
            return;
        }

        if ($current_stage === 2) {
            // Etapa 2: instalación manual del sistema - ignorar mensajes del cliente.
            $this->handle_stage_2($implementation, $parsed);
            return;
        }

        if ($current_stage === 3) {
            // Etapa 3: recolección de archivos Excel del cliente (responsable de migración).
            $this->handle_stage_3($implementation, $parsed);
            return;
        }

        if ($current_stage === 4) {
            // Etapa 4: migración de datos — el cliente confirma o corrige el mapeo de columnas.
            $this->handle_stage_4($implementation, $parsed);
            return;
        }

        if ($current_stage === 5) {
            // Etapa 5: entrega del sistema al cliente.
            $this->handle_stage_5($implementation, $parsed);
            return;
        }

        if ($current_stage === 6) {
            // Etapa 6: capacitación de empleados.
            $this->handle_stage_6($implementation, $parsed);
            return;
        }

        if ($current_stage === 7) {
            // Etapa 7: vinculación AFIP/ARCA.
            $this->handle_stage_7($implementation, $parsed);
            return;
        }

        if ($current_stage === 8) {
            // Etapa 8: videollamada de capacitación.
            $this->handle_stage_8($implementation, $parsed);
            return;
        }

        // Etapas fuera de rango esperado: registrar para depuración.
        Log::channel('daily')->info('ImplementationConversationService: etapa fuera del rango implementado.', [
            'implementation_id' => $implementation->id,
            'current_stage'     => $current_stage,
        ]);
    }

    // -------------------------------------------------------------------------
    // Etapa 1 — Formulario de configuración inicial (web)
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 1 (formulario web).
     *
     * La Etapa 1 ya no recopila datos por WhatsApp: el cliente completa un formulario web.
     * Este método solo responde al cliente con el link del formulario si aún no lo completó.
     * Si ya completó el formulario (form_submitted_at no es null), se ignoran los mensajes.
     *
     * Nota: la lógica anterior de preguntas por WhatsApp de la Etapa 1 se preserva en
     * handle_stage_1_legacy_questions() para referencia y compatibilidad con implementaciones
     * en curso iniciadas antes del rediseño.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed
     *
     * @return void
     */
    private function handle_stage_1(Implementation $implementation, array $parsed): void
    {
        // Si el formulario ya fue enviado, ignorar mensajes en esta etapa.
        if ($implementation->form_submitted_at !== null) {
            return;
        }

        // Teléfono del remitente para enviar el link del formulario.
        $phone = (string) $parsed['from'];

        // Construir el link personalizado del formulario usando el token de esta implementación.
        $form_base_url = ImplementationSettings::get_form_url();
        $form_token    = (string) ($implementation->form_token ?? '');
        $form_url      = rtrim($form_base_url, '/') . '/' . $form_token;

        // Mensaje con el link al formulario - no distingue entre señal positiva y preguntas.
        $message = "Bien, vamos con el primer paso.\n\n"
            . "Completá este formulario con la información de tu empresa. "
            . "No te va a llevar más de 5 minutos y podés hacerlo cuando puedas 🙂\n\n"
            . $form_url . "\n\n"
            . "Avisame cuando lo hayas enviado.";

        $this->send_outbound($implementation, 1, $phone, $message);
    }

    /**
     * Flujo legacy de la Etapa 1: preguntas de configuración por WhatsApp.
     *
     * Método preservado para compatibilidad con implementaciones en curso iniciadas
     * antes del rediseño al esquema de formulario web. No se invoca desde el flujo
     * principal (handle()); solo queda disponible como referencia.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed
     *
     * @return void
     */
    private function handle_stage_1_legacy_questions(Implementation $implementation, array $parsed): void
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
            // Verificar si el lead ya confirmó uso de listas de precios. Si es así,
            // pre-configurar use_price_lists=true para saltar esa pregunta más adelante.
            $promoted_lead               = $client ? $this->find_promoted_lead($client) : null;
            $lead_confirmed_price_lists  = $promoted_lead !== null
                && isset($promoted_lead->use_price_lists)
                && $promoted_lead->use_price_lists === true;

            if ($lead_confirmed_price_lists) {
                $data['use_price_lists'] = true;
            }

            // La primera pregunta arranca por listas de precios (business_type eliminado).
            $data['current_question'] = 'use_price_lists';

            // Mensaje 1: presentación del admin (se envía siempre antes de la primera pregunta).
            $greeting = $this->build_stage_1_greeting($implementation, $client);
            $this->send_outbound($implementation, 1, $phone, $greeting);

            // Mensaje 2: primera pregunta de configuración (sin saludo incluido).
            $first_question = $this->build_question_text('use_price_lists', $data, $client, $implementation);
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

        // Campos con lógica especial de acumulación y confirmación de empleados.
        // Ambos estados ('employees' y 'employees_confirm') se manejan en el mismo método.
        if ($current_question === 'employees' || $current_question === 'employees_confirm') {
            $this->handle_stage_1_employees($stage, $data, $parsed, $phone, $implementation);
            return;
        }

        if ($current_question === 'logo_received') {
            $this->handle_stage_1_logo($stage, $data, $parsed, $phone, $implementation, $client);
            return;
        }

        if ($current_question === 'social_networks') {
            $this->handle_stage_1_social_networks($stage, $data, $parsed, $phone, $implementation, $client);
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
                $deposits_value = $this->process_stage1_response('use_deposits', $parsed, $data, $client, $implementation);

                if ($deposits_value === true) {
                    // Guardar ambos campos y saltar deposit_names → ir directo a payment_discounts.
                    $data['use_deposits']     = true;
                    $data['deposit_names']    = $extracted_names;
                    $data['current_question'] = 'payment_discounts';
                    $stage->data              = $data;
                    $stage->save();

                    // Acuse de recibo + siguiente pregunta (sin repetir los nombres que el cliente acaba de decir).
                    $next_question_text = $this->build_question_text('payment_discounts', $data, $client, $implementation);
                    $outbound_text      = $this->build_acknowledgement() . ' ' . $next_question_text;
                    $this->send_outbound($implementation, 1, $phone, $outbound_text);
                    return;
                }
            }
        }

        // Procesamiento genérico para el resto de preguntas.
        $response_value = $this->process_stage1_response($current_question, $parsed, $data, $client, $implementation);

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
    /**
     * Maneja los mensajes de la pregunta 'employees' con sistema de debounce y confirmación explícita.
     *
     * Dos ramas según el valor de current_question:
     * - 'employees':         acumulación → acusar recibo breve y reiniciar timer de debounce.
     * - 'employees_confirm': el cliente responde si terminó → avanzar, esperar o repreguntar.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data
     * @param array<string, mixed> $parsed
     * @param string               $phone
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
        // Texto del mensaje entrante y cliente asociado.
        $body           = trim((string) ($parsed['body'] ?? ''));
        $normalized     = strtolower(trim($this->remove_accents($body)));
        $current_question = (string) ($data['current_question'] ?? 'employees');

        $client = $implementation->client;
        if ($client === null) {
            $client = Client::find($implementation->client_id);
        }

        // --- Rama de confirmación: el cliente responde si terminó la lista ---
        if ($current_question === 'employees_confirm') {
            // Texto de la pregunta que se le envió al cliente al pasar a este estado.
            $employees_confirm_question = '¿Terminaste de pasar la lista de empleados?';

            // Interpretar la respuesta usando Claude con la clave específica employees_confirm.
            $interpretation = $this->ai_interpreter->interpret(
                'employees_confirm',
                $employees_confirm_question,
                $body
            );

            // Valor interpretado: true = terminó, false = no terminó, null = mandó otro empleado.
            $confirm_value = $interpretation['value'] ?? null;

            if ($confirm_value === true) {
                // El cliente confirmó que terminó: avanzar a la siguiente pregunta.
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

                // Persistir la siguiente pregunta y enviar acuse + pregunta.
                $data['current_question'] = $next_question;
                $stage->data              = $data;
                $stage->save();

                $next_question_text = $this->build_question_text($next_question, $data, $client, $implementation);

                // Acuse de recibo antes de la siguiente pregunta al confirmar employees.
                $outbound_text = $this->build_acknowledgement() . ' ' . $next_question_text;
                $this->send_outbound($implementation, 1, $phone, $outbound_text);
                return;
            }

            if ($confirm_value === false) {
                // El cliente indicó que aún no terminó: volver a modo acumulación y esperar.
                $data['current_question'] = 'employees';
                $stage->data              = $data;
                $stage->save();

                $this->send_outbound(
                    $implementation,
                    1,
                    $phone,
                    'Ok, espero a que termines y avisame cuando estés listo.'
                );
                return;
            }

            // null: el cliente probablemente mandó otro empleado en lugar de responder sí/no.
            // Acumular el texto sin enviar ningún mensaje (el acuse lo maneja la rama de acumulación
            // normal — aquí no se envía nada para no duplicar el acuse de recibo).
            $existing          = trim((string) ($data['employees'] ?? ''));
            $data['employees'] = $existing !== '' ? $existing . "\n" . $body : $body;
            $data['current_question'] = 'employees';
            $stage->data              = $data;
            $stage->save();

            // Reiniciar el timer de debounce para que el scheduler vuelva a preguntar después.
            (new ImplementationStage1EmployeesScheduler())->schedule_after_employee_message($implementation->id);
            return;
        }

        // --- Rama de acumulación: guardar el mensaje y reiniciar timer de debounce ---

        // Acumular el texto recibido en el campo employees.
        $existing          = trim((string) ($data['employees'] ?? ''));
        $data['employees'] = $existing !== '' ? $existing . "\n" . $body : $body;
        $stage->data       = $data;
        $stage->save();

        // Reiniciar el timer de debounce: cuando expire preguntará si terminó la lista.
        (new ImplementationStage1EmployeesScheduler())->schedule_after_employee_message($implementation->id);
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

        // Guardar la URL del logo recibido (si Kapso la incluyó en el media entrante)
        // para reutilizarla en el setup del sistema y en la sugerencia de colores de la tienda.
        $logo_url = '';
        if (isset($parsed['inbound_media']) && is_array($parsed['inbound_media'])) {
            $logo_url = (string) ($parsed['inbound_media']['url'] ?? '');
        }
        $data['logo_url'] = $logo_url;

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

    /**
     * Maneja la pregunta de redes sociales: detecta negativa o extrae instagram/facebook.
     *
     * Delega la interpretación a ImplementationAiInterpreter con la clave 'social_networks'.
     * - Negativa o sin redes → guarda data['social_networks'] = 'none'.
     * - Links provistos → guarda data['social_networks'] = 'provided' y, por separado,
     *   data['instagram'] y/o data['facebook'].
     * - Ambiguo → reenvía la pregunta.
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
    private function handle_stage_1_social_networks(
        ImplementationStage $stage,
        array $data,
        array $parsed,
        string $phone,
        Implementation $implementation,
        ?Client $client
    ): void {
        $body = trim((string) ($parsed['body'] ?? ''));

        // Texto exacto de la pregunta que se le envió al cliente.
        $question_text = $this->build_question_text('social_networks', $data, $client, $implementation);

        // Interpretar la respuesta: objeto {instagram, facebook, none} o null si ambiguo.
        $interpretation = $this->ai_interpreter->interpret('social_networks', $question_text, $body);
        $value          = $interpretation['value'] ?? null;

        if (! is_array($value)) {
            // Respuesta ambigua: reenviar la misma pregunta con aclaración.
            $this->send_outbound($implementation, 1, $phone, 'No entendí bien tu respuesta. ' . $question_text);
            return;
        }

        // Extraer cada red social interpretada (string vacío si no se mencionó).
        $instagram = trim((string) ($value['instagram'] ?? ''));
        $facebook  = trim((string) ($value['facebook'] ?? ''));
        $is_none   = ($value['none'] ?? false) === true;

        if ($is_none || ($instagram === '' && $facebook === '')) {
            // El cliente no tiene o prefiere no compartir redes.
            $data['social_networks'] = 'none';
        } else {
            // Guardar las redes provistas por separado.
            $data['social_networks'] = 'provided';
            if ($instagram !== '') {
                $data['instagram'] = $instagram;
            }
            if ($facebook !== '') {
                $data['facebook'] = $facebook;
            }
        }

        // Avanzar a la siguiente pregunta de la secuencia.
        $next_question = $this->get_next_stage1_key('social_networks', $data);

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
        $outbound_text      = $this->build_acknowledgement() . ' ' . $next_question_text;
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
        if ($stage === 3) {
            // Etapa 3: recolección de archivos — envía solicitud de Excels y logo al responsable de migración.
            $this->send_stage_3_opening($implementation);
            return;
        }

        // Etapa 4 (migración): no tiene apertura vía WhatsApp; process_files() se encarga vía job.

        if ($stage === 5) {
            // Etapa 5: entrega del sistema — envía acceso al cliente.
            $this->send_stage_5_opening($implementation);
            return;
        }

        if ($stage === 6) {
            // Etapa 6: capacitación — envía credenciales y recursos a empleados.
            $this->send_stage_6_opening($implementation);
            return;
        }

        if ($stage === 7) {
            // Etapa 7: vinculación AFIP/ARCA.
            $this->send_stage_7_opening($implementation);
            return;
        }

        if ($stage === 8) {
            // Etapa 8: videollamada de capacitación.
            $this->send_stage_8_opening($implementation);
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
     * - Etapa 4: envía el primer mensaje al responsable de migración (recolección de archivos).
     * - Etapa 5: dispara el análisis IA en background (migración de datos).
     * - Etapa 6: envía credenciales a empleados y mensaje al dueño (capacitación).
     * - Etapa 7: envía la pregunta sobre acceso AFIP al dueño.
     * - Etapa 8: envía la pregunta de disponibilidad para videollamada al dueño.
     *
     * @param Implementation $implementation Implementación que acaba de avanzar de etapa.
     * @param int            $new_stage      Número de la nueva etapa activa.
     *
     * @return void
     */
    public function handle_stage_advance(Implementation $implementation, int $new_stage): void
    {
        if ($new_stage === 2) {
            // Etapa 2: instalación manual. Notificar al admin + disparar UserSetup.
            $client      = $implementation->client ?? Client::find($implementation->client_id);
            $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

            $admin_message = "🛠️ {$client_name} lista para instalar. Etapa 2: instalación del sistema.";
            $this->notify_assigned_admin($implementation, $admin_message);

            // Disparar el UserSetup remoto en empresa-api con los datos del formulario de la Etapa 1.
            // No bloquea el flujo si falla (el servicio captura y loguea cualquier error).
            (new ImplementationUserSetupService())->trigger_user_setup($implementation);
            return;
        }

        if ($new_stage === 3) {
            // Etapa 3: recolección de archivos — enviar primer mensaje al responsable de migración.
            $this->send_stage_opening_message($implementation, 3);
            return;
        }

        if ($new_stage === 4) {
            // Etapa 4: migración de datos — disparar análisis IA en background.
            // El job copia los archivos recolectados en etapa 3 al data de etapa 4 y llama a process_files().
            \App\Jobs\ProcessImplementationStage4Import::dispatch($implementation->id);
            return;
        }

        if ($new_stage === 5) {
            // Etapa 5: entrega del sistema — enviar acceso al cliente con los datos ya cargados.
            $this->send_stage_opening_message($implementation, 5);
            return;
        }

        if ($new_stage === 6) {
            // Etapa 6: capacitación — enviar credenciales y recursos a empleados.
            $this->send_stage_opening_message($implementation, 6);
            return;
        }

        if ($new_stage === 7) {
            // Etapa 7: vinculación AFIP/ARCA.
            $this->send_stage_opening_message($implementation, 7);
            return;
        }

        if ($new_stage === 8) {
            // Etapa 8: videollamada de capacitación.
            $this->send_stage_opening_message($implementation, 8);
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

        // Cargar los empleados del cliente para ofrecer selección desde la lista ya cargada en etapa 1.
        $employees = ClientEmployee::where('client_id', $client->id)->get();

        if ($employees->isNotEmpty()) {
            // Construir listado numerado de empleados más la opción "Yo mismo" al final.
            $employee_lines = '';
            // Array de IDs en el orden en que aparecen en el listado (base-0, indexado por posición).
            $employee_ids   = [];
            $index          = 1;
            foreach ($employees as $emp) {
                $employee_lines .= "{$index}. {$emp->name}\n";
                $employee_ids[]  = $emp->id;
                $index++;
            }
            // Opción adicional al final: el dueño mismo.
            $employee_lines .= "{$index}. Yo mismo";

            // Registrar la pregunta de selección y persistir los IDs para resolver la elección después.
            $data['current_question'] = 'migration_responsible_choice';
            $data['employee_ids']     = $employee_ids;
            $stage->data              = $data;
            $stage->save();

            $question_text = "¿Quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores)?\n\n{$employee_lines}";
            $this->send_outbound($implementation, 2, $phone, $question_text);
        } else {
            // Sin empleados cargados: preguntar en texto libre como flujo original.
            $data['current_question'] = 'migration_responsible_name';
            $stage->data              = $data;
            $stage->save();

            $question_text = "Ahora necesito saber quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores). ¿Lo vas a hacer vos o hay otra persona del equipo? Indicame el nombre.";
            $this->send_outbound($implementation, 2, $phone, $question_text);
        }
    }

    /**
     * Envía el primer mensaje de la Etapa 3 al responsable de migración (migration_contact_phone).
     *
     * Incluye en el mensaje la lista completa de archivos necesarios: artículos, clientes,
     * proveedores y logo. Registra `current_question = 'collecting_files'` en el data del
     * stage 3. Es idempotente: si ya se envió la apertura, no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_3_opening(Implementation $implementation): void
    {
        // Stage 3 (recolección de archivos) de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 3)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 3 no encontrado para apertura.', [
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

        // Teléfono del responsable de migración (puede ser distinto al dueño del negocio).
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: migration_contact_phone vacío para apertura de Etapa 3.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Nombre del cliente y del responsable para personalizar el mensaje.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Nombre del responsable de migración guardado en el data del stage 1 (si existe).
        $stage_1 = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();
        $stage_1_data = $stage_1 !== null && is_array($stage_1->data) ? $stage_1->data : [];
        $responsable  = trim((string) ($stage_1_data['migration_responsible_name'] ?? ''));

        // Generar la lista de progreso para incluir en el mensaje de apertura.
        $progress_list = $this->build_progress_list($implementation);

        // Registrar estado de acumulación activa y persistir antes de enviar el mensaje.
        $data['current_question'] = 'collecting_files';
        $stage->data              = $data;
        $stage->save();

        // Construir el mensaje de apertura incluyendo la lista de archivos requeridos.
        $responsable_saludo = $responsable !== '' ? $responsable : 'Hola';
        $question_text = "{$progress_list}\n\n"
            . "{$responsable_saludo}, ¡el sistema ya está instalado con la configuración de {$client_name}! 🎉\n\n"
            . "Ahora necesito que nos envíes los archivos con la información del negocio:\n\n"
            . "📦 Artículos o productos (Excel)\n"
            . "👥 Clientes (Excel)\n"
            . "🏭 Proveedores (Excel)\n"
            . "🖼️ Logo de la empresa (imagen cuadrada)\n\n"
            . "Podés enviarlos directamente por acá, de a uno o todos juntos. Si no tenés alguno, avisame y arrancamos igual.";

        $this->send_outbound($implementation, 3, $contact_phone, $question_text);
    }

    // -------------------------------------------------------------------------
    // Etapa 2 — Instalación del sistema (manual)
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 2 (instalación del sistema).
     *
     * La Etapa 2 es manual: el equipo técnico instala empresa-api y empresa-spa.
     * El cliente no necesita hacer nada por WhatsApp en esta etapa, por lo que se
     * ignoran todos los mensajes entrantes sin enviar respuesta.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante (ignorado).
     *
     * @return void
     */
    private function handle_stage_2(Implementation $implementation, array $parsed): void
    {
        // Etapa de instalación manual: no se requiere interacción del cliente por WhatsApp.
        // El avance de esta etapa lo hace el admin manualmente desde el panel.
        Log::channel('daily')->debug('ImplementationConversationService: mensaje recibido en etapa 2 (instalación manual) - ignorado.', [
            'implementation_id' => $implementation->id,
            'from'              => $parsed['from'] ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Etapa 2 legacy — Definir responsable de migración (flujo anterior)
    // -------------------------------------------------------------------------

    /**
     * Flujo legacy de la Etapa 2: definir responsable de migración por WhatsApp.
     *
     * Preservado para referencia y compatibilidad con implementaciones en curso
     * iniciadas antes del rediseño. No se invoca desde el flujo principal.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_2_legacy_migration_responsible(Implementation $implementation, array $parsed): void
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
            // Mismo criterio que send_stage_2_opening: ofrecer lista si hay empleados cargados.
            $employees = ClientEmployee::where('client_id', $client->id)->get();

            if ($employees->isNotEmpty()) {
                // Construir listado numerado con opción "Yo mismo" al final.
                $employee_lines = '';
                $employee_ids   = [];
                $index          = 1;
                foreach ($employees as $emp) {
                    $employee_lines .= "{$index}. {$emp->name}\n";
                    $employee_ids[]  = $emp->id;
                    $index++;
                }
                $employee_lines .= "{$index}. Yo mismo";

                $data['current_question'] = 'migration_responsible_choice';
                $data['employee_ids']     = $employee_ids;
                $stage->data              = $data;
                $stage->save();

                $question_text = "¿Quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores)?\n\n{$employee_lines}";
                $this->send_outbound($implementation, 2, $phone, $question_text);
            } else {
                $data['current_question'] = 'migration_responsible_name';
                $stage->data              = $data;
                $stage->save();

                $question_text = "Ahora necesito saber quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores). ¿Lo vas a hacer vos o hay otra persona del equipo? Indicame el nombre.";
                $this->send_outbound($implementation, 2, $phone, $question_text);
            }
            return;
        }

        // Pregunta actualmente pendiente de respuesta.
        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        if ($current_question === 'migration_responsible_choice') {
            // IDs de los empleados listados, en el mismo orden del mensaje enviado al cliente.
            $employee_ids = (array) ($data['employee_ids'] ?? []);

            // Reconstruir el listado para reenviarlo si la respuesta es ambigua.
            $employees_for_list = ClientEmployee::whereIn('id', $employee_ids)->get()->keyBy('id');
            $list_lines         = '';
            foreach ($employee_ids as $idx => $emp_id) {
                // Nombre del empleado en esa posición, con fallback genérico si no se encuentra.
                $emp_name    = $employees_for_list->has($emp_id) ? $employees_for_list->get($emp_id)->name : 'Empleado #' . ($idx + 1);
                $list_lines .= ($idx + 1) . ". {$emp_name}\n";
            }
            // Opción "Yo mismo" siempre al final, con índice N+1.
            $self_index  = count($employee_ids) + 1;
            $list_lines .= "{$self_index}. Yo mismo";

            // Texto original de la pregunta para pasarle al intérprete IA.
            $question_text = "¿Quién va a encargarse de enviarnos los archivos con la información del negocio (productos, clientes, proveedores)?\n\n{$list_lines}";

            if ($body === '') {
                $this->send_outbound($implementation, 2, $phone, $question_text);
                return;
            }

            // Interpretar la respuesta del cliente: índice base-1 del elegido, 0 = yo mismo, null = ambiguo.
            $interpreted  = $this->ai_interpreter->interpret('employee_choice', $question_text, $body);
            $chosen_index = $interpreted['value'];

            if ($chosen_index === null) {
                // Respuesta ambigua: reenviar el listado con aclaración.
                $this->send_outbound($implementation, 2, $phone, "No entendí bien tu respuesta. ¿Quién va a encargarse de enviarnos los archivos?\n\n{$list_lines}");
                return;
            }

            if ($chosen_index === 0 || $chosen_index === $self_index) {
                // El dueño mismo se encarga: usar su teléfono como contacto de migración.
                $responsible_name  = $client ? $client->resolve_display_name() : 'el responsable';
                $responsible_phone = trim((string) ($client->phone ?? $phone));

                $data['migration_responsible_name']  = $responsible_name;
                $data['migration_responsible_phone'] = $responsible_phone;
                $data['current_question']            = 'completed';
                $data['completed']                   = true;
                $stage->data                         = $data;
                $stage->save();

                $this->finish_stage_2($implementation, $phone, $client, $responsible_name, $responsible_phone, true);
                return;
            }

            // Buscar el empleado elegido por índice base-1 en el listado.
            $emp_id   = $employee_ids[$chosen_index - 1] ?? null;
            $employee = ($emp_id !== null && $employees_for_list->has($emp_id))
                ? $employees_for_list->get($emp_id)
                : null;

            if ($employee === null || $chosen_index < 1 || $chosen_index > count($employee_ids)) {
                // Índice fuera de rango o empleado no encontrado: reenviar el listado.
                $this->send_outbound($implementation, 2, $phone, "El número que indicaste no corresponde a ninguna opción. ¿Quién va a encargarse de enviarnos los archivos?\n\n{$list_lines}");
                return;
            }

            // Empleado seleccionado: usar su nombre y teléfono como contacto de migración.
            $responsible_name  = (string) $employee->name;
            $responsible_phone = trim((string) $employee->phone);

            $data['migration_responsible_name']  = $responsible_name;
            $data['migration_responsible_phone'] = $responsible_phone;
            $data['current_question']            = 'completed';
            $data['completed']                   = true;
            $stage->data                         = $data;
            $stage->save();

            $this->finish_stage_2($implementation, $phone, $client, $responsible_name, $responsible_phone);
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

            // Normalizar el teléfono argentino antes de persistirlo en el stage y en la implementación.
            $responsible_phone = \App\Services\ArgentinePhoneNormalizer::normalize($responsible_phone);

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
        // Normalizar el teléfono del responsable de migración al formato E.164 antes de persistirlo.
        $responsible_phone = \App\Services\ArgentinePhoneNormalizer::normalize($responsible_phone);

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

        // Marcar la etapa 2 como completada en la base de datos.
        $stage_2_record = \App\Models\ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 2)
            ->first();

        if ($stage_2_record !== null) {
            $stage_2_record->status       = 'completed';
            $stage_2_record->completed_at = now();
            $stage_2_record->save();
        }

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
    private function send_stage_6_opening(Implementation $implementation): void
    {
        // Stage 6 (capacitación) de esta implementación.
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

        // Idempotente: si ya se ejecutó la apertura, no repetir.
        if (array_key_exists('employees_notified', $data)) {
            return;
        }

        // Cargar cliente con sus empleados y la api activa para obtener la url del sistema.
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if ($client === null) {
            Log::channel('daily')->warning('ImplementationConversationService: cliente no encontrado para apertura de Etapa 6.', [
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

            $this->send_outbound($implementation, 6, $employee_phone, $employee_message);

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
                6,
                $owner_phone,
                'Ya le enviamos las credenciales a tu equipo. Cuando hayan podido ingresar y recorrido el sistema, avanzamos con el siguiente paso.'
            );
        }
    }

    /**
     * Maneja un mensaje entrante durante la Etapa 6 (capacitación).
     *
     * Esta etapa avanza manualmente desde el admin. Cualquier mensaje del cliente
     * recibe una respuesta indicando que espere a que el equipo ingrese al sistema.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_6(Implementation $implementation, array $parsed): void
    {
        // Teléfono del remitente para enviar la respuesta de espera.
        $phone = (string) $parsed['from'];

        $this->send_outbound(
            $implementation,
            6,
            $phone,
            '¡Perfecto! Cuando tu equipo haya podido ingresar al sistema y lo haya recorrido un poco, avisanos para avanzar al siguiente paso.'
        );
    }

    // -------------------------------------------------------------------------
    // Etapa 5 — Migración de datos (análisis IA + importación)
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 5 (migración de datos).
     *
     * El cliente está respondiendo al resumen de columnas detectadas por la IA.
     * Flujo esperado: current_question = 'confirm_analysis' → el cliente confirma o corrige.
     * Si confirma → se ejecuta la importación. Si corrige → se notifica al admin.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    /**
     * Maneja un mensaje entrante durante la Etapa 4 (migración de datos con IA).
     *
     * El responsable de migración puede recibir el resumen de columnas detectadas y
     * confirmarlo o solicitar correcciones. Si el análisis aún está en proceso,
     * se responde con un mensaje de espera.
     *
     * @param Implementation       $implementation Implementación activa.
     * @param array<string, mixed> $parsed         Mensaje entrante parseado.
     *
     * @return void
     */
    private function handle_stage_4(Implementation $implementation, array $parsed): void
    {
        // Stage 4 (migración de datos) de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 4 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage con resultados de análisis y current_question.
        $data             = is_array($stage->data) ? $stage->data : [];
        $current_question = trim((string) ($data['current_question'] ?? ''));

        // Si aún no hay current_question, el análisis IA está en proceso: responder con espera.
        if ($current_question === '' || $current_question === 'analyzing') {
            $phone = (string) $parsed['from'];
            $this->send_outbound(
                $implementation,
                4,
                $phone,
                'Estamos analizando los archivos con IA. En breve te enviamos el resumen de columnas detectadas.'
            );
            return;
        }

        // Etapa ya completada: ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        // El responsable está respondiendo al mapeo de columnas.
        if ($current_question === 'confirm_analysis') {
            $body   = trim((string) ($parsed['body'] ?? ''));
            $client = $implementation->client ?? Client::find($implementation->client_id);
            $this->handle_stage_4_confirm_analysis($stage, $data, $body, $implementation, $client);
            return;
        }

        // Estado desconocido: responder con espera genérica.
        $phone = (string) $parsed['from'];
        $this->send_outbound(
            $implementation,
            4,
            $phone,
            'Recibí tu mensaje. En breve te respondemos.'
        );
    }

    /**
     * Procesa la respuesta del cliente al pedido de confirmación del mapeo de columnas (Etapa 5).
     *
     * - Afirmativo: ejecuta la importación vía ImplementationImportService::execute_import().
     * - Corrección / negación: notifica al admin asignado con el mensaje recibido.
     * - Ambiguo: re-pregunta brevemente.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data
     * @param string               $body
     * @param Implementation       $implementation
     * @param Client|null          $client
     *
     * @return void
     */
    /**
     * Procesa la respuesta del responsable cuando `current_question` es confirm_analysis (Etapa 4).
     *
     * - Afirmativo: ejecuta la importación vía ImplementationImportService::execute_import().
     * - Corrección / negación: notifica al admin asignado con el mensaje recibido.
     * - Ambiguo: re-pregunta brevemente.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data
     * @param string               $body
     * @param Implementation       $implementation
     * @param Client|null          $client
     *
     * @return void
     */
    private function handle_stage_4_confirm_analysis(
        ImplementationStage $stage,
        array $data,
        string $body,
        Implementation $implementation,
        ?Client $client
    ): void {
        $normalized = strtolower($this->remove_accents(trim($body)));

        // Confirmación afirmativa: ejecutar importación en empresa-api.
        $yes_no = $this->parse_yes_no($normalized);
        if ($yes_no === true) {
            $import_service = new ImplementationImportService(null, $this);
            $import_service->execute_import($implementation);
            return;
        }

        // Corrección de columnas o duda: derivar al admin asignado.
        if ($this->is_stage_4_analysis_correction_message($normalized)) {
            $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

            if ($contact_phone !== '') {
                $admin_name = $this->resolve_assigned_admin_name($implementation);
                $this->send_outbound(
                    $implementation,
                    4,
                    $contact_phone,
                    "Entendido. {$admin_name} va a revisar el mapeo de columnas y te avisamos cuando esté listo."
                );
            }

            $client_name = $client
                ? $client->resolve_display_name()
                : "Cliente #{$implementation->client_id}";

            $this->notify_assigned_admin(
                $implementation,
                "⚠️ {$client_name} solicitó corrección del mapeo de columnas en Etapa 4. Mensaje: \"{$body}\""
            );

            return;
        }

        // Respuesta ambigua: re-preguntar de forma breve.
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        if ($contact_phone !== '') {
            $this->send_outbound(
                $implementation,
                4,
                $contact_phone,
                '¿Confirmás que el mapeo de columnas es correcto? Respondé sí para continuar o indicá qué columna hay que corregir.'
            );
        }
    }

    /**
     * Envía un mensaje outbound al responsable de migración durante la Etapa 4 (migración).
     *
     * Usado por ImplementationImportService para el resumen de análisis IA y el mensaje
     * de éxito de importación, que ocurren durante la etapa 4.
     *
     * @param Implementation $implementation
     * @param string         $body
     *
     * @return void
     */
    public function send_stage_4_outbound(Implementation $implementation, string $body): void
    {
        // Teléfono del responsable de migración (destino de los mensajes de importación).
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService::send_stage_4_outbound: teléfono vacío.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        $this->send_outbound($implementation, 4, $contact_phone, $body);
    }

    /**
     * Cierra la Etapa 4 (migración) tras una importación exitosa (Pusher + aviso al admin).
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $data
     *
     * @return void
     */
    public function finish_stage_4_after_import(Implementation $implementation, array $data): void
    {
        // Teléfono del responsable de migración y cliente para el cierre de etapa.
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        $client        = $implementation->client ?? Client::find($implementation->client_id);

        $this->finish_stage_4($implementation, $contact_phone, $client, $data);
    }

    /**
     * Cierra la Etapa 4 (migración): notifica al admin con resumen de importación y dispara evento Pusher.
     *
     * @param Implementation       $implementation
     * @param string               $contact_phone  Teléfono del responsable de migración.
     * @param Client|null          $client
     * @param array<string, mixed> $data           Data final del stage 4.
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

        // Iconos de estado por categoría.
        $articles_icon  = $this->is_stage4_category_resolved($data, 'articles') ? '✅' : '—';
        $clients_icon   = $this->is_stage4_category_resolved($data, 'clients') ? '✅' : '—';
        $suppliers_icon = $this->is_stage4_category_resolved($data, 'suppliers') ? '✅' : '—';

        // Mensaje al admin con resumen de la importación.
        $admin_message = "✅ {$client_name} completó la Etapa 4 con importación IA. Artículos {$articles_icon} | clientes {$clients_icon} | proveedores {$suppliers_icon}.";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Marcar la etapa 4 como completada en la base de datos.
        $stage_4_record = \App\Models\ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage_4_record !== null) {
            $stage_4_record->status       = 'completed';
            $stage_4_record->completed_at = now();
            $stage_4_record->save();
        }

        // Evento Pusher para notificar al panel admin en tiempo real.
        event(new ImplementationStageCompleted($implementation->id, 4, $client_name));
    }

    // -------------------------------------------------------------------------
    // Etapa 7 — Vinculación AFIP/ARCA
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la Etapa 7 al dueño del cliente.
     *
     * Pregunta quién tiene los datos de acceso al AFIP de la empresa para coordinar
     * la vinculación con ARCA. Es idempotente: si data ya tiene 'current_question', no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_7_opening(Implementation $implementation): void
    {
        // Stage 7 (AFIP/ARCA) de esta implementación.
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
        $data['current_question'] = 'afip_contact_name';
        $stage->data              = $data;
        $stage->save();

        $this->send_outbound(
            $implementation,
            7,
            $owner_phone,
            'Para poder emitir facturas electrónicas, necesitamos vincular el sistema con ARCA (antes AFIP). ¿Quién tiene los datos de acceso al AFIP de la empresa? ¿Lo manejás vos o hay un contador/encargado?'
        );
    }

    /**
     * Maneja un mensaje entrante durante la Etapa 7 (vinculación AFIP/ARCA).
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
    private function handle_stage_7(Implementation $implementation, array $parsed): void
    {
        // Stage 7 (AFIP/ARCA) de esta implementación concreta.
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

        // Cliente dueño de la implementación.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $owner_phone = trim((string) ($client->phone ?? ''));

        // Nombre del admin asignado para los mensajes.
        $admin_name  = $this->resolve_assigned_admin_name($implementation);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Si no hay current_question → enviar la apertura como fallback.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_7_opening($implementation);
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
                    7,
                    $phone,
                    "Gracias, {$admin_name} va a revisar los archivos y te avisa."
                );
            }
            return;
        }

        if ($current_question === 'afip_contact_name') {
            if ($body === '') {
                $this->send_outbound($implementation, 7, $phone, '¿Quién tiene los datos de acceso al AFIP de la empresa?');
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
                7,
                $phone,
                "¿Cuál es el número de WhatsApp de {$body} para coordinar esto?"
            );
            return;
        }

        if ($current_question === 'afip_contact_phone') {
            if ($body === '') {
                $afip_name = (string) ($data['afip_contact_name'] ?? 'el responsable');
                $this->send_outbound($implementation, 7, $phone, "¿Cuál es el número de WhatsApp de {$afip_name}?");
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
     * Separa esta lógica de handle_stage_7 para evitar duplicación entre el flujo de "yo mismo"
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
                7,
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
        $admin_message = "📋 {$client_name} — Etapa 7: pasos de AFIP enviados a {$afip_name} ({$afip_phone}). Cuando el cliente complete los pasos, entrá al ARCA y hacé la vinculación.";
        $this->notify_assigned_admin($implementation, $admin_message);

        // Mensaje al dueño informando que se enviaron los pasos.
        if ($owner_phone !== '') {
            $this->send_outbound(
                $implementation,
                7,
                $owner_phone,
                "Le envié los pasos a {$afip_name}. Cuando los complete, nos encargamos de la vinculación desde nuestro lado y te avisamos cuando esté lista."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Etapa 8 — Videollamada de capacitación
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la Etapa 8 al dueño del cliente.
     *
     * Coordina disponibilidad para la videollamada de cierre de implementación.
     * Es idempotente: si data ya tiene 'current_question', no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_8_opening(Implementation $implementation): void
    {
        // Stage 8 (videollamada) de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 8)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 8 no encontrado para apertura.', [
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
            Log::channel('daily')->warning('ImplementationConversationService: cliente sin teléfono para apertura de Etapa 8.', [
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
            8,
            $owner_phone,
            '¡Ya estamos en la última etapa! Para cerrar la implementación, nos gustaría hacer una videollamada corta (20-30 minutos) con vos y tu equipo para despejar cualquier duda del sistema. ¿Tenés disponibilidad esta semana? Indicame días y horarios que te vengan bien.'
        );
    }

    // -------------------------------------------------------------------------
    // Etapa 5 — Entrega del sistema al cliente
    // -------------------------------------------------------------------------

    /**
     * Envía el primer mensaje de la Etapa 5 al dueño del cliente con el acceso al sistema.
     *
     * Incluye el link al sistema, usuario y contraseña provisional leídos del data de la
     * etapa 2 (instalación). Si no están disponibles todavía, envía el mensaje sin esos datos
     * y loguea un warning. Es idempotente: si ya se envió la apertura, no reenvía.
     *
     * @param Implementation $implementation
     *
     * @return void
     */
    private function send_stage_5_opening(Implementation $implementation): void
    {
        // Stage 5 (entrega del sistema) de esta implementación.
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

        // Idempotente: si ya se registró current_question, no reenviar la apertura.
        if (array_key_exists('current_question', $data)) {
            return;
        }

        // Teléfono del dueño del negocio (cliente).
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $owner_phone = trim((string) ($client->phone ?? ''));

        if ($owner_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: cliente sin teléfono para apertura de Etapa 5.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $implementation->client_id,
            ]);
            return;
        }

        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";

        // Leer datos de acceso del stage 2 (instalación): link, usuario y contraseña provisional.
        $stage_2 = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 2)
            ->first();
        $stage_2_data = $stage_2 !== null && is_array($stage_2->data) ? $stage_2->data : [];

        // Link del sistema, usuario y contraseña; pueden estar vacíos si instalación no completó datos.
        $link_sistema   = trim((string) ($stage_2_data['system_url'] ?? ''));
        $usuario        = trim((string) ($stage_2_data['admin_username'] ?? ''));
        $contrasena     = trim((string) ($stage_2_data['admin_password'] ?? ''));

        if ($link_sistema === '' || $usuario === '' || $contrasena === '') {
            Log::channel('daily')->warning('ImplementationConversationService: datos de acceso incompletos en stage 2 para apertura de Etapa 5.', [
                'implementation_id' => $implementation->id,
                'link'              => $link_sistema,
                'usuario'           => $usuario,
            ]);
        }

        // Generar la lista de progreso para incluir en el mensaje de apertura.
        $progress_list = $this->build_progress_list($implementation);

        // Registrar la primera pregunta pendiente y persistir.
        $data['current_question'] = 'system_delivered';
        $stage->data              = $data;
        $stage->save();

        // Construir el mensaje con los datos de acceso (si están disponibles).
        if ($link_sistema !== '' && $usuario !== '' && $contrasena !== '') {
            $mensaje = "{$progress_list}\n\n"
                . "{$client_name}, ¡tu sistema ya está listo con toda tu información cargada! 🎉\n\n"
                . "Podés ingresar desde acá: {$link_sistema}\n"
                . "Usuario: {$usuario}\n"
                . "Contraseña: {$contrasena}\n\n"
                . "Una cosa importante: no es necesario que abandones tu sistema actual de golpe. "
                . "Podés trabajar en paralelo con ambos durante el tiempo que necesites. "
                . "Cuando estés listo para hacer el pase definitivo, nos avisás y hacemos una migración final con toda la información actualizada.\n\n"
                . "¿Alguna duda antes de empezar a explorar?";
        } else {
            $mensaje = "{$progress_list}\n\n"
                . "{$client_name}, ¡tu sistema ya está listo con toda tu información cargada! 🎉\n\n"
                . "En breve te enviamos los datos de acceso para que puedas ingresar.\n\n"
                . "¿Alguna duda antes de empezar?";
        }

        $this->send_outbound($implementation, 5, $owner_phone, $mensaje);
    }

    /**
     * Maneja un mensaje entrante durante la Etapa 5 (entrega del sistema).
     *
     * En esta etapa el dueño puede hacer preguntas sobre el acceso. El agente responde
     * con un mensaje de transición y notifica al admin asignado para seguimiento.
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $parsed         Mensaje entrante.
     *
     * @return void
     */
    private function handle_stage_5(Implementation $implementation, array $parsed): void
    {
        // Teléfono del remitente para responder.
        $phone = (string) $parsed['from'];

        // Stage 5 (entrega del sistema) de esta implementación.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 5)
            ->first();

        // Si la etapa ya fue completada, ignorar mensajes.
        $current_question = $stage !== null && is_array($stage->data)
            ? trim((string) ($stage->data['current_question'] ?? ''))
            : '';

        if ($current_question === 'completed') {
            return;
        }

        // Si la apertura no fue enviada todavía, enviarla como fallback.
        if ($current_question === '') {
            $this->send_stage_5_opening($implementation);
            return;
        }

        // Notificar al admin asignado que el cliente escribió en la etapa de entrega.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";
        $body        = trim((string) ($parsed['body'] ?? ''));

        $this->notify_assigned_admin(
            $implementation,
            "💬 {$client_name} escribió en Etapa 5 (entrega del sistema): \"{$body}\""
        );

        // Responder al cliente indicando que recibirá atención.
        $admin_name = $this->resolve_assigned_admin_name($implementation);
        $this->send_outbound(
            $implementation,
            5,
            $phone,
            "Recibí tu mensaje. {$admin_name} te va a responder en breve. 🙏"
        );
    }

    /**
     * Genera la lista de progreso de etapas en formato de texto para WhatsApp.
     *
     * Usa ✅ para etapas completadas y ⬜ para etapas pendientes o en progreso.
     * Se incluye al inicio de los mensajes de apertura de cada etapa para que el
     * cliente vea en qué punto del proceso se encuentra.
     *
     * @param Implementation $implementation Implementación con relación stages cargable.
     *
     * @return string Texto multilínea con las 8 etapas y sus estados.
     */
    private function build_progress_list(Implementation $implementation): string
    {
        // Nombres definitivos de las 8 etapas para mostrar en la lista de progreso.
        $etapas = [
            1 => 'Información de la empresa',
            2 => 'Instalación del sistema',
            3 => 'Recolección de archivos',
            4 => 'Migración de datos',
            5 => 'Entrega del sistema',
            6 => 'Capacitación',
            7 => 'Vinculación con ARCA/AFIP',
            8 => 'Videollamada de capacitación',
        ];

        // Cargar las etapas si no están ya en memoria.
        $implementation->loadMissing('stages');

        // Mapear número de etapa → estado para consulta O(1).
        $stages_map = [];
        $implementation->stages->each(function ($s) use (&$stages_map) {
            $stages_map[(int) $s->stage_number] = (string) $s->status;
        });

        // Construir línea por etapa con ícono según estado.
        $lines = [];
        foreach ($etapas as $number => $label) {
            $icon    = ($stages_map[$number] ?? 'pending') === 'completed' ? '✅' : '⬜';
            $lines[] = "{$icon} {$number}. {$label}";
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Etapa 8 — Videollamada de capacitación
    // -------------------------------------------------------------------------

    /**
     * Maneja un mensaje entrante durante la Etapa 8 (videollamada de capacitación).
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
    private function handle_stage_8(Implementation $implementation, array $parsed): void
    {
        // Stage 8 (videollamada) de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 8)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 8 no encontrado.', [
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
            $this->send_stage_8_opening($implementation);
            return;
        }

        $current_question = (string) $data['current_question'];

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        if ($current_question === 'completed') {
            return;
        }

        if ($current_question === 'availability') {
            if ($body === '') {
                $this->send_outbound($implementation, 8, $phone, '¿Cuándo tenés disponibilidad para la videollamada?');
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
                    8,
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
                8,
                $phone,
                "Perfecto, le paso tu disponibilidad a {$admin_name} para que confirme el horario. Te avisamos a la brevedad."
            );

            // Notificar al admin asignado con la disponibilidad del cliente.
            $this->notify_assigned_admin(
                $implementation,
                "📅 {$client_name} — Etapa 8: disponibilidad para videollamada: {$body}. Confirmá el horario y avisale al cliente."
            );
            return;
        }
    }

    /**
     * Detecta si el mensaje indica que el cliente no quiere hacer la videollamada.
     *
     * Delega a Claude para cubrir variantes naturales como "no hace falta",
     * "no quiero", "no es necesario", "me la salteo", etc.
     *
     * @param string $body Texto del mensaje recibido.
     *
     * @return bool true si el cliente quiere omitir la videollamada.
     */
    private function is_skip_videocall_response(string $body): bool
    {
        // Texto de la pregunta enviada al cliente en la etapa 7.
        $question_text = '¿Cuándo tenés disponibilidad para la videollamada?';

        // Delegar la interpretación semántica a Claude.
        $result = $this->ai_interpreter->interpret('skip_videocall', $question_text, $body);

        return $result['value'] === true;
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
    /**
     * Maneja un mensaje entrante durante la Etapa 4 (recepción de archivos de migración).
     *
     * Principio: acumulación libre. Todo mensaje (archivo o texto) se acumula en silencio
     * en `pending_files` o `pending_texts`, y se reinicia el timer de debounce. Cuando el
     * timer expira, Claude analiza el lote completo y genera la respuesta al cliente.
     *
     * @param Implementation       $implementation Implementación activa.
     * @param array<string, mixed> $parsed         Mensaje entrante parseado.
     *
     * @return void
     */
    /**
     * Maneja un mensaje entrante durante la Etapa 3 (recolección de archivos Excel y logo).
     *
     * Acumula archivos y textos enviados por el responsable de migración en `pending_files`
     * o `pending_texts`, y reinicia el timer de debounce. Cuando el timer expira, Claude
     * analiza el lote completo y genera la respuesta al responsable de migración.
     *
     * @param Implementation       $implementation Implementación activa.
     * @param array<string, mixed> $parsed         Mensaje entrante parseado.
     *
     * @return void
     */
    private function handle_stage_3(Implementation $implementation, array $parsed): void
    {
        // Stage 3 (recolección de archivos) de esta implementación concreta.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 3)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('ImplementationConversationService: stage 3 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);
            return;
        }

        // Data actual del stage con archivos acumulados y estado de categorías.
        $data = is_array($stage->data) ? $stage->data : [];

        // Si no hay current_question → la apertura no fue enviada aún; enviarla como fallback.
        if (! array_key_exists('current_question', $data)) {
            $this->send_stage_3_opening($implementation);
            return;
        }

        // Si la etapa ya fue completada, ignorar mensajes adicionales.
        $current_question = (string) $data['current_question'];
        if ($current_question === 'completed') {
            return;
        }

        // Esperando confirmación del mapeo de columnas: delegar al handler correspondiente.
        if ($current_question === 'confirm_analysis') {
            $body   = trim((string) ($parsed['body'] ?? ''));
            $client = $implementation->client ?? Client::find($implementation->client_id);
            $this->handle_stage_3_confirm_analysis($stage, $data, $body, $implementation, $client);
            return;
        }

        // Tipo de mensaje entrante: 'document', 'image' o texto libre.
        $message_type = (string) ($parsed['type'] ?? 'text');

        if ($message_type === 'document' || $message_type === 'image') {
            // Construir registro del archivo recibido con metadatos disponibles.
            $file_record = [
                'filename'    => (string) ($parsed['inbound_media']['filename'] ?? ''),
                'type'        => (string) ($parsed['inbound_media']['mime'] ?? ''),
                'url'         => (string) ($parsed['inbound_media']['url'] ?? ''),
                'received_at' => now()->toISOString(),
            ];

            // Acumular en pending_files para que Claude los clasifique al expirar el timer.
            $pending_files   = is_array($data['pending_files'] ?? null) ? $data['pending_files'] : [];
            $pending_files[] = $file_record;
            $data['pending_files'] = $pending_files;
        } else {
            // Texto libre: acumular en pending_texts como contexto para Claude.
            $body = trim((string) ($parsed['body'] ?? ''));
            if ($body !== '') {
                $pending_texts   = is_array($data['pending_texts'] ?? null) ? $data['pending_texts'] : [];
                $pending_texts[] = ['body' => $body, 'received_at' => now()->toISOString()];
                $data['pending_texts'] = $pending_texts;
            }
        }

        // Marcar estado de acumulación activa.
        $data['current_question'] = 'collecting_files';

        // Persistir el estado acumulado antes de reiniciar el timer.
        $stage->data = $data;
        $stage->save();

        // Reiniciar el timer de debounce: cada mensaje nuevo posterga el procesamiento.
        (new ImplementationStage4Scheduler())->schedule_after_file_received($implementation->id);
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
     * Cierra la Etapa 3 (archivos): envía confirmación al responsable de migración, notifica
     * al admin con el resumen de archivos recibidos y dispara el evento Pusher.
     *
     * @param Implementation       $implementation
     * @param string               $contact_phone  Teléfono del responsable de migración.
     * @param Client|null          $client
     * @param array<string, mixed> $data           Data final del stage 3 con el estado de cada archivo.
     *
     * @return void
     */
    private function finish_stage_3(
        Implementation $implementation,
        string $contact_phone,
        ?Client $client,
        array $data
    ): void {
        $client_name = $client
            ? $client->resolve_display_name()
            : "Cliente #{$implementation->client_id}";

        // Mensaje de cierre al responsable (omitido si execute_import ya notificó el éxito).
        if (empty($data['import_success_notified'])) {
            $this->send_outbound(
                $implementation,
                3,
                $contact_phone,
                '¡Listo, recibí todo! Vamos a procesar los archivos y te avisamos cuando estén cargados en el sistema.'
            );
        }

        // Icono de estado por tipo de archivo: ✅ si se recibió o enviaron varios, — si fue omitido.
        $articles_icon  = $this->is_stage4_category_resolved($data, 'articles') ? '✅' : '—';
        $clients_icon   = $this->is_stage4_category_resolved($data, 'clients') ? '✅' : '—';
        $suppliers_icon = $this->is_stage4_category_resolved($data, 'suppliers') ? '✅' : '—';

        if (! empty($data['import_success_notified'])) {
            $admin_message = "✅ {$client_name} completó la Etapa 3 con importación IA. Artículos {$articles_icon} | clientes {$clients_icon} | proveedores {$suppliers_icon}.";
        } else {
            $admin_message = "✅ {$client_name} completó la Etapa 3. Archivos recibidos: artículos {$articles_icon} | clientes {$clients_icon} | proveedores {$suppliers_icon}. Podés proceder con la importación.";
        }
        $this->notify_assigned_admin($implementation, $admin_message);

        // Marcar la etapa 3 como completada en la base de datos.
        $stage_3_record = \App\Models\ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 3)
            ->first();

        if ($stage_3_record !== null) {
            $stage_3_record->status       = 'completed';
            $stage_3_record->completed_at = now();
            $stage_3_record->save();
        }

        // Evento Pusher para notificar al panel en tiempo real.
        event(new ImplementationStageCompleted($implementation->id, 3, $client_name));
    }

    /**
     * Procesa la respuesta del responsable cuando `current_question` es confirm_analysis (Etapa 3).
     *
     * Verifica si el responsable confirma los archivos recibidos. Si confirma, cierra la etapa.
     * Si solicita corrección, notifica al admin. Si es ambiguo, re-pregunta.
     *
     * @param ImplementationStage  $stage
     * @param array<string, mixed> $data
     * @param string               $body
     * @param Implementation       $implementation
     * @param Client|null          $client
     *
     * @return void
     */
    private function handle_stage_3_confirm_analysis(
        ImplementationStage $stage,
        array $data,
        string $body,
        Implementation $implementation,
        ?Client $client
    ): void {
        $normalized = strtolower($this->remove_accents(trim($body)));

        // Confirmación afirmativa: ejecutar importación en empresa-api.
        $yes_no = $this->parse_yes_no($normalized);
        if ($yes_no === true) {
            $import_service = new ImplementationImportService(null, $this);
            $import_service->execute_import($implementation);

            return;
        }

        // Corrección de columnas o duda: derivar al admin asignado.
        if ($this->is_stage_4_analysis_correction_message($normalized)) {
            $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

            if ($contact_phone !== '') {
                $admin_name = $this->resolve_assigned_admin_name($implementation);
                $this->send_outbound(
                    $implementation,
                    3,
                    $contact_phone,
                    "Entendido. {$admin_name} va a revisar el mapeo de columnas y te avisamos cuando esté listo."
                );
            }

            $client_name = $client
                ? $client->resolve_display_name()
                : "Cliente #{$implementation->client_id}";

            $this->notify_assigned_admin(
                $implementation,
                "⚠️ {$client_name} solicitó corrección del mapeo de columnas en Etapa 3. Mensaje: \"{$body}\""
            );

            return;
        }

        // Respuesta ambigua: re-preguntar de forma breve.
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        if ($contact_phone !== '') {
            $this->send_outbound(
                $implementation,
                3,
                $contact_phone,
                '¿Confirmás que el mapeo de columnas es correcto? Respondé sí para continuar o indicá qué columna hay que corregir.'
            );
        }
    }

    /**
     * Detecta si el mensaje indica correcciones al mapeo de columnas (no es un sí simple).
     *
     * @param string $normalized_body Texto sin tildes y en minúsculas.
     *
     * @return bool
     */
    private function is_stage_4_analysis_correction_message(string $normalized_body): bool
    {
        if ($normalized_body === '') {
            return false;
        }

        $correction_signals = [
            'columna',
            'mal',
            'incorrect',
            'correg',
            'cambiar',
            'no es',
            'equivoc',
            'error',
            'falta',
            'otra',
        ];

        foreach ($correction_signals as $signal) {
            if (str_contains($normalized_body, $signal)) {
                return true;
            }
        }

        // "no" explícito sin señales de corrección: rechazo directo del mapeo propuesto.
        // Se usa comparación directa para evitar una llamada AI en este check secundario.
        return in_array($normalized_body, ['no', 'n', 'nop', 'nope', 'negativo'], true);
    }

    /**
     * Envía un mensaje outbound al responsable de migración en la Etapa 3 (recolección de archivos).
     *
     * @param Implementation $implementation
     * @param string         $body
     *
     * @return void
     */
    public function send_stage_3_outbound(Implementation $implementation, string $body): void
    {
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService::send_stage_3_outbound: teléfono vacío.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        $this->send_outbound($implementation, 3, $contact_phone, $body);
    }

    /**
     * Notifica al admin asignado (público para servicios auxiliares como importación IA).
     *
     * @param Implementation $implementation
     * @param string         $message
     *
     * @return void
     */
    public function notify_assigned_admin_for_implementation(Implementation $implementation, string $message): void
    {
        $this->notify_assigned_admin($implementation, $message);
    }

    /**
     * Cierra la Etapa 3 (archivos) tras una importación exitosa (Pusher + aviso al admin).
     *
     * @param Implementation       $implementation
     * @param array<string, mixed> $data
     *
     * @return void
     */
    public function finish_stage_3_after_import(Implementation $implementation, array $data): void
    {
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));
        $client        = $implementation->client ?? Client::find($implementation->client_id);

        $this->finish_stage_3($implementation, $contact_phone, $client, $data);
    }

    // -------------------------------------------------------------------------
    // Etapa 1 — Parseo de empleados con Claude (inteligencia del flujo)
    // -------------------------------------------------------------------------

    /**
     * Parsea el texto libre de empleados acumulado en la Etapa 1 usando Claude.
     *
     * Envía el texto completo a la API de Anthropic con un system prompt especializado
     * para extraer empleados estructurados. Claude devuelve un JSON con un array de
     * objetos, cada uno con name, document, role y phone.
     *
     * En caso de error de API, JSON inválido o respuesta vacía, devuelve array vacío
     * sin bloquear el flujo principal.
     *
     * @param string $employees_text Texto libre con datos de empleados del stage data['employees'].
     *
     * @return array<int, array{name: string, document: string, role: string, phone: string}>
     */
    private function parse_employees_text_with_claude(string $employees_text): array
    {
        /* Clave de API de Anthropic configurada en services.anthropic.api_key. */
        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            Log::channel('daily')->warning('parse_employees_text_with_claude: api_key de Anthropic no configurada.');
            return [];
        }

        /* Modelo a usar, configurable por entorno. */
        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');

        /* System prompt: instruye a Claude como parser de empleados argentinos. */
        $system_prompt = 'Sos un parser de datos de empleados para un sistema de gestión argentino. '
            . 'El texto que recibirás es texto libre escrito por un cliente argentino por WhatsApp, '
            . 'con datos de sus empleados. '
            . 'Extraé cada empleado y devolvé ÚNICAMENTE un JSON válido de una línea sin markdown.';

        /* User prompt: el texto completo acumulado en data['employees'] de la Etapa 1. */
        $user_prompt = $employees_text . "\n\n"
            . 'Formato de respuesta esperado (una línea, sin texto adicional ni markdown):'
            . "\n"
            . '[{"name":"Juan García","document":"28123456","role":"ventas","phone":"1155554444"},...]'
            . "\n\n"
            . 'Si algún campo no está presente para un empleado, devolvé "" (string vacío). '
            . 'name, document, role y phone son los únicos campos permitidos en cada objeto.';

        try {
            /* Configurar cliente HTTP con headers de Anthropic. */
            $http = Http::withHeaders([
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30);

            /* Verificación SSL: configurable por entorno (dev puede deshabilitar). */
            $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
            $ca_bundle  = config('services.anthropic.ca_bundle');

            if (! $verify_ssl) {
                $http = $http->withoutVerifying();
            } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
                $http = $http->withOptions(['verify' => $ca_bundle]);
            }

            /* Llamada a la API de Anthropic con el lote de empleados. */
            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 1024,
                'system'     => $system_prompt,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $user_prompt,
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::channel('daily')->warning('parse_employees_text_with_claude: error Anthropic.', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            /* Extraer el texto de la respuesta de Claude desde los content blocks. */
            $raw_text       = '';
            $content_blocks = $response->json('content', []);

            if (is_array($content_blocks)) {
                foreach ($content_blocks as $block) {
                    if (is_array($block) && isset($block['text'])) {
                        $raw_text .= (string) $block['text'];
                    }
                }
            }

            $raw_text = trim($raw_text);

            if ($raw_text === '') {
                return [];
            }

            /* Extraer JSON aunque Claude agregue texto envolvente o markdown. */
            if (preg_match('/\[.*\]/s', $raw_text, $matches)) {
                $raw_text = $matches[0];
            }

            /* Decodificar el array JSON de empleados. */
            $parsed = json_decode($raw_text, true);

            if (! is_array($parsed)) {
                Log::channel('daily')->warning('parse_employees_text_with_claude: JSON inválido en respuesta de Claude.', [
                    'raw' => $raw_text,
                ]);
                return [];
            }

            return $parsed;

        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('parse_employees_text_with_claude: excepción al llamar Anthropic.', [
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Etapa 4 — Clasificación de lote con Claude (inteligencia del flujo)
    // -------------------------------------------------------------------------

    /**
     * Llama a Claude con el lote de archivos y textos acumulados para clasificarlos y generar
     * el mensaje de respuesta al cliente.
     *
     * Construye un prompt dinámico con:
     * - Estado actual de categorías ya resueltas y descartadas.
     * - Lista de archivos recibidos (nombre, tipo, url).
     * - Lista de textos recibidos en orden cronológico (contexto para asociar a archivos).
     *
     * Claude responde con un JSON que incluye:
     * - classified: archivos clasificados por categoría (articles, clients, suppliers).
     * - skipped: categorías que el cliente indicó no tener.
     * - message_to_client: mensaje natural en español rioplatense para enviar al cliente.
     * - all_resolved: bool, true si no falta ninguna categoría.
     *
     * En caso de error de la API, loggea y devuelve un fallback seguro sin bloquear el flujo.
     *
     * @param array<int, array<string, string>> $pending_files  Archivos acumulados del lote actual.
     * @param array<int, array<string, string>> $pending_texts  Textos acumulados del lote actual.
     * @param array<string, mixed>              $current_data   Data actual del stage (para contexto de estado).
     *
     * @return array{
     *   classified: array{articles: list<array<string,string>>, clients: list<array<string,string>>, suppliers: list<array<string,string>>},
     *   skipped: list<string>,
     *   message_to_client: string,
     *   all_resolved: bool
     * }
     */
    private function classify_stage4_batch_with_claude(
        array $pending_files,
        array $pending_texts,
        array $current_data
    ): array {
        // Fallback seguro: se devuelve si la API falla o el JSON es inválido.
        $fallback = [
            'classified'        => ['articles' => [], 'clients' => [], 'suppliers' => []],
            'skipped'           => [],
            'message_to_client' => 'Recibí los archivos. ¿Tenés algo más para pasarme o arrancamos con la migración?',
            'all_resolved'      => false,
        ];

        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            return $fallback;
        }

        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');

        // System prompt: instruye a Claude sobre su rol y el formato de respuesta esperado.
        $system_prompt = 'Sos un asistente de implementación de software para PyMEs argentinas. '
            . 'Estás procesando archivos Excel que un cliente envió por WhatsApp para migrar sus datos al sistema. '
            . 'Las categorías posibles son: articles (artículos/productos), clients (clientes), suppliers (proveedores). '
            . 'Respondé ÚNICAMENTE con un JSON válido de una línea, sin texto adicional ni markdown.';

        // Construir la descripción del estado actual de categorías ya resueltas.
        $classified_now = is_array($current_data['classified_files'] ?? null) ? $current_data['classified_files'] : [];
        $skipped_now    = is_array($current_data['skipped_categories'] ?? null) ? $current_data['skipped_categories'] : [];

        // Generar resumen legible del estado actual para incluir en el prompt.
        $state_parts = [];
        foreach (['articles', 'clients', 'suppliers'] as $cat) {
            if (in_array($cat, $skipped_now, true)) {
                // La categoría fue descartada explícitamente por el cliente.
                $state_parts[] = "{$cat}: descartado";
            } elseif (! empty($classified_now[$cat])) {
                // La categoría ya tiene archivos clasificados de rondas anteriores.
                $count         = count($classified_now[$cat]);
                $label         = $count === 1 ? '1 archivo' : "{$count} archivos";
                $state_parts[] = "{$cat}: {$label}";
            } else {
                // La categoría aún no tiene nada.
                $state_parts[] = "{$cat}: pendiente";
            }
        }
        $state_summary = implode(', ', $state_parts);

        // Construir la lista de archivos recibidos en el lote actual.
        $files_lines = [];
        foreach ($pending_files as $file) {
            $filename = (string) ($file['filename'] ?? '');
            $mime     = (string) ($file['type'] ?? '');
            // Incluir nombre y mime para que Claude tenga máxima info de clasificación.
            $files_lines[] = "- \"{$filename}\" ({$mime})";
        }
        $files_block = ! empty($files_lines)
            ? implode("\n", $files_lines)
            : '(ninguno)';

        // Construir la lista de textos recibidos en orden cronológico.
        $texts_lines = [];
        foreach ($pending_texts as $text_entry) {
            $body          = (string) ($text_entry['body'] ?? '');
            $texts_lines[] = "- \"{$body}\"";
        }
        $texts_block = ! empty($texts_lines)
            ? implode("\n", $texts_lines)
            : '(ninguno)';

        // User prompt construido dinámicamente con todo el contexto del lote.
        $user_prompt = "Estado actual de categorías ya resueltas: {$state_summary}\n\n"
            . "Nuevos archivos recibidos:\n{$files_block}\n\n"
            . "Mensajes de texto del cliente (en orden cronológico):\n{$texts_block}\n\n"
            . "Tareas:\n"
            . "1. Clasificá cada archivo en articles, clients, suppliers o unknown. "
            . "Usá el nombre del archivo y los mensajes de texto como contexto. "
            . "Si un texto va justo después de un archivo, es muy probable que describa ese archivo.\n"
            . "2. Si algún mensaje de texto indica que el cliente no tiene cierta categoría "
            . "(ej: \"No\", \"no tengo proveedores\", \"no manejamos clientes\"), marcá esa categoría como skipped.\n"
            . "3. Identificá qué categorías faltan (no tienen archivos y no fueron descartadas).\n"
            . "4. Generá un mensaje natural en español rioplatense informal para enviarle al cliente. "
            . "Si faltan categorías, preguntá por ellas de forma natural. "
            . "Si todo está completo, confirmá y avisale que vas a analizar los archivos.\n"
            . "5. Determiná si all_resolved es true (no falta ninguna categoría entre las ya resueltas + las de este lote).\n\n"
            . "Respondé con este JSON:\n"
            . '{"classified":{"articles":[{"filename":"...","url":"..."}],"clients":[...],"suppliers":[...]},'
            . '"skipped":["suppliers"],"message_to_client":"...","all_resolved":false}';

        try {
            // Configurar cliente HTTP con la API key de Anthropic.
            $http = Http::withHeaders([
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30);

            // Verificación SSL: configurable por entorno (dev puede deshabilitar).
            $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
            $ca_bundle  = config('services.anthropic.ca_bundle');

            if (! $verify_ssl) {
                $http = $http->withoutVerifying();
            } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
                $http = $http->withOptions(['verify' => $ca_bundle]);
            }

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 512,
                'system'     => $system_prompt,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $user_prompt,
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::channel('daily')->warning('classify_stage4_batch_with_claude: error Anthropic.', [
                    'status'            => $response->status(),
                    'implementation_id' => $current_data['implementation_id'] ?? null,
                ]);
                return $fallback;
            }

            // Extraer el texto de la respuesta de Claude desde los content blocks.
            $raw_text       = '';
            $content_blocks = $response->json('content', []);

            if (is_array($content_blocks)) {
                foreach ($content_blocks as $block) {
                    if (is_array($block) && isset($block['text'])) {
                        $raw_text .= (string) $block['text'];
                    }
                }
            }

            $raw_text = trim($raw_text);

            if ($raw_text === '') {
                return $fallback;
            }

            // Extraer JSON aunque Claude agregue texto envolvente o markdown.
            if (preg_match('/\{.*\}/s', $raw_text, $matches)) {
                $raw_text = $matches[0];
            }

            $parsed_result = json_decode($raw_text, true);

            if (! is_array($parsed_result)) {
                Log::channel('daily')->warning('classify_stage4_batch_with_claude: JSON inválido en respuesta de Claude.', [
                    'raw' => $raw_text,
                ]);
                return $fallback;
            }

            // Normalizar y validar la estructura esperada del JSON de Claude.
            $classified_result = is_array($parsed_result['classified'] ?? null) ? $parsed_result['classified'] : [];
            $skipped_result    = is_array($parsed_result['skipped'] ?? null) ? $parsed_result['skipped'] : [];
            $message_result    = trim((string) ($parsed_result['message_to_client'] ?? ''));
            $all_resolved      = ! empty($parsed_result['all_resolved']);

            // Asegurar que las tres claves de categorías existan aunque Claude las omita.
            foreach (['articles', 'clients', 'suppliers'] as $cat) {
                if (! is_array($classified_result[$cat] ?? null)) {
                    $classified_result[$cat] = [];
                }
            }

            // Usar fallback de mensaje si Claude devolvió vacío.
            if ($message_result === '') {
                $message_result = $fallback['message_to_client'];
            }

            return [
                'classified'        => $classified_result,
                'skipped'           => $skipped_result,
                'message_to_client' => $message_result,
                'all_resolved'      => $all_resolved,
            ];
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('classify_stage4_batch_with_claude: excepción al llamar Anthropic.', [
                'message' => $exception->getMessage(),
            ]);
            return $fallback;
        }
    }

    // -------------------------------------------------------------------------
    // Etapa 4 — Procesamiento diferido de archivos acumulados (llamado desde Job)
    // -------------------------------------------------------------------------

    /**
     * Procesa el lote de archivos y textos acumulados en la Etapa 4 cuando expira el timer de debounce.
     *
     * Llamado desde ProcessImplementationStage4Files cuando el token de programación sigue siendo
     * vigente (el cliente no envió más mensajes dentro del período de espera configurado).
     *
     * Toda la inteligencia de clasificación y generación del mensaje al cliente está delegada a
     * Claude via classify_stage4_batch_with_claude(). Este método solo orquesta: carga estado,
     * llama a Claude, persiste resultados, envía mensaje al cliente y avanza la etapa si corresponde.
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

        // Data actual con archivos/textos pendientes y clasificaciones previas acumuladas.
        $data = is_array($stage->data) ? $stage->data : [];

        // Teléfono del responsable de migración: necesario para enviar mensajes.
        $contact_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('process_stage4_pending_files: contact_phone vacío, no se puede enviar mensaje.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Si la etapa ya fue completada o está esperando confirmación, no reprocesar.
        $current_question = trim((string) ($data['current_question'] ?? ''));
        if ($current_question === 'completed' || $current_question === 'confirm_analysis') {
            return;
        }

        // Pendientes del lote actual: archivos y textos acumulados desde el último procesamiento.
        $pending_files = is_array($data['pending_files'] ?? null) ? $data['pending_files'] : [];
        $pending_texts = is_array($data['pending_texts'] ?? null) ? $data['pending_texts'] : [];

        // Sin pendientes: el timer se activó por algún mensaje que no generó acumulación; salir silencioso.
        if (empty($pending_files) && empty($pending_texts)) {
            return;
        }

        // Delegar toda la inteligencia a Claude: clasificar archivos, detectar skips y generar mensaje.
        $result = $this->classify_stage4_batch_with_claude($pending_files, $pending_texts, $data);

        // Índice de pending_files por filename para recuperar la URL real del webhook.
        // Claude solo recibe el nombre del archivo — no la URL — así que la URL que
        // devuelve Claude es inventada. Hay que sustituirla por la URL real del pending_file.
        $pending_files_by_name = [];
        foreach ($pending_files as $pf) {
            $fname = (string) ($pf['filename'] ?? '');
            if ($fname !== '') {
                $pending_files_by_name[$fname] = $pf;
            }
        }

        // Merge de los archivos clasificados en classified_files acumulado,
        // reemplazando la URL devuelta por Claude con la URL real del webhook.
        $classified = is_array($data['classified_files'] ?? null) ? $data['classified_files'] : [];
        foreach (['articles', 'clients', 'suppliers'] as $cat) {
            if (! empty($result['classified'][$cat])) {
                $hydrated = [];
                foreach ($result['classified'][$cat] as $claude_file) {
                    $fname = (string) ($claude_file['filename'] ?? '');
                    // Buscar el pending_file original por nombre para recuperar url, type, etc.
                    if ($fname !== '' && isset($pending_files_by_name[$fname])) {
                        $hydrated[] = $pending_files_by_name[$fname];
                    } else {
                        // Fallback: usar lo que devolvió Claude (puede no tener URL válida).
                        $hydrated[] = $claude_file;
                    }
                }
                $existing       = is_array($classified[$cat] ?? null) ? $classified[$cat] : [];
                $classified[$cat] = array_merge($existing, $hydrated);
            }
        }
        $data['classified_files'] = $classified;

        // Merge de categorías descartadas (el cliente indicó que no las tiene).
        $skipped = is_array($data['skipped_categories'] ?? null) ? $data['skipped_categories'] : [];
        foreach ((array) ($result['skipped'] ?? []) as $cat) {
            if (! in_array($cat, $skipped, true)) {
                $skipped[] = $cat;
            }
        }
        $data['skipped_categories'] = $skipped;

        // Limpiar el lote ya procesado; el próximo mensaje iniciará un nuevo lote.
        $data['pending_files'] = [];
        $data['pending_texts'] = [];

        // Evaluar si Claude determinó que todas las categorías están resueltas.
        $all_resolved = ! empty($result['all_resolved']);

        if (! $all_resolved) {
            // Aún faltan categorías: enviar pregunta generada por Claude y esperar más mensajes.
            $stage->data = $data;
            $stage->save();

            $this->send_outbound($implementation, 4, $contact_phone, (string) ($result['message_to_client'] ?? ''));
            return;
        }

        // Todas las categorías resueltas: reconstruir los campos legacy que espera ImplementationImportService.
        // process_files() lee articles_files, clients_files, suppliers_files del data del stage.
        foreach (['articles', 'clients', 'suppliers'] as $cat) {
            // Si fue descartado por el cliente → 'skipped'; si tiene archivos → array de registros.
            if (in_array($cat, $skipped, true)) {
                $data[$cat . '_files'] = 'skipped';
            } else {
                $data[$cat . '_files'] = ! empty($classified[$cat]) ? $classified[$cat] : [];
            }
        }

        // Marcar como archivos confirmados completos para compatibilidad con el resto del flujo.
        $data['files_confirmed_complete'] = true;
        $stage->data                      = $data;
        $stage->save();

        // Enviar mensaje de cierre generado por Claude: archivos recibidos, análisis pendiente.
        $this->send_outbound($implementation, 4, $contact_phone, (string) ($result['message_to_client'] ?? ''));

        // Notificar al admin asignado y disparar evento Pusher de etapa completada.
        // El análisis IA se dispara cuando el admin avanza manualmente a la Etapa 5.
        $client      = $implementation->client ?? Client::find($implementation->client_id);
        $client_name = $client ? $client->resolve_display_name() : "Cliente #{$implementation->client_id}";
        // Marcar la etapa 4 como completada en la base de datos.
        $stage_4b_record = \App\Models\ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage_4b_record !== null) {
            $stage_4b_record->status       = 'completed';
            $stage_4b_record->completed_at = now();
            $stage_4b_record->save();
        }

        event(new ImplementationStageCompleted($implementation->id, 4, $client_name));
    }

    /**
     * Invocado por el job de debounce cuando expiró la espera tras el último mensaje de empleados.
     *
     * Si la implementación aún está esperando más empleados (current_question === 'employees')
     * y hay datos acumulados, cambia el estado a 'employees_confirm' y envía la pregunta
     * de confirmación al cliente. Si ya avanzó o no hay datos, no hace nada.
     *
     * @param int $implementation_id ID de la implementación a procesar.
     *
     * @return void
     */
    public function process_stage1_employees_debounce(int $implementation_id): void
    {
        // Cargar la implementación.
        $implementation = Implementation::find($implementation_id);

        if ($implementation === null) {
            Log::channel('daily')->warning('process_stage1_employees_debounce: implementación no encontrada.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Cargar el stage 1.
        $stage = ImplementationStage::where('implementation_id', $implementation_id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->warning('process_stage1_employees_debounce: stage 1 no encontrado.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Data actual del stage 1.
        $data = is_array($stage->data) ? $stage->data : [];

        // Solo actuar si la pregunta sigue siendo 'employees': si ya avanzó o está en
        // 'employees_confirm' (otro debounce ya disparó), ignorar.
        $current_question = (string) ($data['current_question'] ?? '');

        if ($current_question !== 'employees') {
            Log::channel('daily')->debug('process_stage1_employees_debounce: ignorado (current_question no es employees).', [
                'implementation_id' => $implementation_id,
                'current_question'  => $current_question,
            ]);
            return;
        }

        // Verificar que haya datos acumulados; si está vacío no hay nada que confirmar.
        $accumulated = trim((string) ($data['employees'] ?? ''));

        if ($accumulated === '') {
            Log::channel('daily')->debug('process_stage1_employees_debounce: ignorado (employees vacío).', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Teléfono del cliente (dueño del negocio) para enviar el mensaje de confirmación.
        $client        = $implementation->client ?? Client::find($implementation->client_id);
        $contact_phone = trim((string) ($client->phone ?? ''));

        if ($contact_phone === '') {
            Log::channel('daily')->warning('process_stage1_employees_debounce: cliente sin teléfono, no se puede enviar mensaje.', [
                'implementation_id' => $implementation_id,
            ]);
            return;
        }

        // Cambiar el estado a 'employees_confirm' para que el próximo mensaje
        // del cliente sea procesado como respuesta a la confirmación.
        $data['current_question'] = 'employees_confirm';
        $stage->data              = $data;
        $stage->save();

        // Preguntar al cliente si terminó de pasar la lista.
        $this->send_outbound(
            $implementation,
            1,
            $contact_phone,
            '¿Terminaste de pasar la lista de empleados?'
        );
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
     * @param string               $question       Clave de la pregunta actual.
     * @param array<string, mixed> $parsed         Mensaje entrante.
     * @param array<string, mixed> $data           Data actual del stage.
     * @param Client|null          $client         Cliente para personalizar el texto de la pregunta.
     * @param Implementation|null  $implementation Implementación activa (para resolver admin y lead).
     *
     * @return mixed null = inválida | self::RESPONSE_ACCUMULATING = acumulando | valor a guardar.
     */
    private function process_stage1_response(
        string $question,
        array $parsed,
        array $data,
        ?Client $client = null,
        ?Implementation $implementation = null
    ) {
        $body = trim((string) ($parsed['body'] ?? ''));

        switch ($question) {
            // Preguntas booleanas: interpretadas por Claude en lugar de palabras clave.
            case 'use_price_lists':
            case 'use_deposits':
            case 'ask_amount_in_vender':
            case 'default_cuenta_corriente':
            case 'dollar_prices':
                return $this->parse_bool_response($question, $body, $data, $client, $implementation);

            // Preguntas de texto libre: cualquier respuesta no vacía es válida.
            case 'price_lists':
            case 'deposit_names':
            case 'payment_discounts':
            case 'company_name':
            case 'address_company':
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
     * Interpreta una respuesta booleana del cliente usando Claude.
     *
     * Delega al `ImplementationAiInterpreter` para que comprenda el lenguaje natural
     * del cliente en lugar de comparar contra palabras clave hardcodeadas.
     *
     * @param string               $question       Clave de la pregunta (ej: 'use_price_lists').
     * @param string               $body           Texto crudo de la respuesta del cliente.
     * @param array<string, mixed> $data           Data actual del stage (para construir el texto de la pregunta).
     * @param Client|null          $client         Cliente (para personalizar el texto de la pregunta).
     * @param Implementation|null  $implementation Implementación activa (para resolver admin y lead).
     *
     * @return bool|null true/false según la interpretación, null si ambigua o no interpretable.
     */
    private function parse_bool_response(
        string $question,
        string $body,
        array $data = [],
        ?Client $client = null,
        ?Implementation $implementation = null
    ): ?bool {
        // Obtener el texto exacto que se le envió al cliente para esta pregunta.
        $question_text = $this->build_question_text($question, $data, $client, $implementation);

        // Delegar la interpretación semántica a Claude.
        $result = $this->ai_interpreter->interpret($question, $question_text, $body);

        // El valor puede ser true, false o null; convertir a ?bool.
        $value = $result['value'] ?? null;

        if ($value === true) {
            return true;
        }

        if ($value === false) {
            return false;
        }

        return null;
    }

    /**
     * Interpreta una respuesta genérica de Sí/No usando Claude.
     *
     * Recibe el texto ya normalizado (o no) del cliente y delega la interpretación
     * semántica al `ImplementationAiInterpreter` con la clave genérica 'yes_no'.
     *
     * @param string $normalized Texto del cliente (puede estar ya normalizado o no).
     *
     * @return bool|null true = sí, false = no, null = ambiguo.
     */
    private function parse_yes_no(string $normalized): ?bool
    {
        // Delegar al intérprete con la clave genérica yes_no.
        // No se pasa question_text ya que el contexto genérico en el intérprete es suficiente.
        $result = $this->ai_interpreter->interpret('yes_no', '', $normalized);

        $value = $result['value'] ?? null;

        if ($value === true) {
            return true;
        }

        if ($value === false) {
            return false;
        }

        return null;
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
        // Secuencia base de preguntas. Arranca directamente por listas de precios.
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
        // Dirección del negocio: se usa en los comprobantes.
        $keys[] = 'address_company';
        $keys[] = 'employees';
        $keys[] = 'logo_received';
        // Redes sociales para mostrar en la tienda online.
        $keys[] = 'social_networks';
        // Manejo de precios en dólares además de pesos.
        $keys[] = 'dollar_prices';
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

        // Devolver la primera clave posterior que aún no fue respondida.
        // Esto permite saltar preguntas pre-configuradas (ej: use_price_lists confirmado
        // desde el lead) sin volver a preguntarlas.
        for ($next_index = (int) $current_index + 1; $next_index < count($sequence); $next_index++) {
            $next_key = $sequence[$next_index];

            if (! array_key_exists($next_key, $data)) {
                return $next_key;
            }
        }

        return null;
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
            case 'address_company':
                return "¿Cuál es la dirección del negocio? La usamos en los comprobantes.";
            case 'employees':
                // Sin instrucción de "escribí listo": el sistema detecta el fin de forma inteligente.
                return "Necesito los datos de vos y de todos los empleados que van a usar el sistema. Por cada persona indicame nombre completo, número de documento, área (ventas, stock, administración, etc.) y número de WhatsApp.\nEsa info nos sirve para asignarle permisos iniciales a cada uno — los permisos se pueden ajustar más adelante cuando el sistema esté en marcha.";
            case 'logo_received':
                return "Por último, enviame el logo de tu empresa en formato cuadrado. Lo vamos a usar en los comprobantes.";
            case 'social_networks':
                return "¿Tenés Instagram o Facebook del negocio? Si querés que aparezcan en tu tienda online, mandame los links. Si no tenés o preferís no ponerlos, avisame.";
            case 'dollar_prices':
                return "¿Manejás precios en dólares además de pesos?";
            case 'ask_amount_in_vender':
                return "Cuando cargás una venta, ¿preferís que el sistema te pregunte cuántas unidades querés vender de cada producto, o que agregue una unidad automáticamente y vos la cambiás si hace falta?";
            case 'default_cuenta_corriente':
                return "Cuando asignás un cliente a una venta, ¿querés que quede en cuenta corriente automáticamente, o preferís indicarlo manualmente cada vez?";
            default:
                return '';
        }
    }

    /**
     * Construye el mensaje de presentación inicial de la Etapa 1.
     *
     * Se envía como primer mensaje separado, antes de la primera pregunta de
     * configuración. Presenta al admin asignado y explica el propósito del flujo.
     *
     * @param Implementation|null $implementation Implementación activa para resolver el admin asignado.
     * @param Client|null         $client         Cliente para personalizar el saludo.
     *
     * @return string
     */
    private function build_stage_1_greeting(?Implementation $implementation, ?Client $client): string
    {
        // Nombre del cliente para el saludo personalizado.
        $display_name = $client ? $client->resolve_display_name() : 'cliente';

        // Nombre del admin asignado para presentarse en primera persona.
        $admin_name = $implementation ? $this->resolve_assigned_admin_name($implementation) : 'el equipo de ComercioCity';

        return "Hola {$display_name}! Soy {$admin_name}, te voy a hacer unas preguntas para dar de alta tu perfil en la plataforma y comenzar con la migración de tu información.";
    }

    /**
     * Construye el texto de la primera pregunta de configuración (listas de precios).
     *
     * No incluye saludo — ese se envía por separado mediante `build_stage_1_greeting()`.
     *
     * Si el lead de origen tiene `use_price_lists = true` → pedir directamente los
     * nombres de las listas (saltar la confirmación sí/no).
     * Si no hay dato previo → preguntar la opción (Precio único / Listas de precios).
     *
     * @param Implementation|null $implementation Implementación activa para resolver el lead promovido.
     * @param Client|null         $client         Cliente para buscar el lead promovido.
     *
     * @return string
     */
    private function build_question_use_price_lists(?Implementation $implementation, ?Client $client): string
    {
        // Buscar el lead promovido para leer la preferencia del proceso de venta.
        $promoted_lead = $client ? $this->find_promoted_lead($client) : null;

        if ($promoted_lead !== null && isset($promoted_lead->use_price_lists) && $promoted_lead->use_price_lists === true) {
            // Lead confirmado con listas de precios: pedir directamente los nombres.
            return "Para arrancar con la configuración: en la demo trabajaste con listas de precios. Indicame los nombres de tus listas y el margen de ganancia por defecto de cada una. Ejemplo:\n\nMinorista 30%\nMayorista 20%\n(Si no tenés margen fijo, decime solo los nombres)";
        }

        return "Para arrancar con la configuración: ¿vas a manejar un único precio de venta por producto, o necesitás varias listas de precios con distintos márgenes? (respondé Precio único o Listas de precios)";
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

        // Marcar la etapa 1 como completada en la base de datos.
        $stage_1 = \App\Models\ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage_1 !== null) {
            $stage_1->status       = 'completed';
            $stage_1->completed_at = now();
            $stage_1->save();
        }

        // Parsear y crear los registros de ClientEmployee desde el texto acumulado en data['employees'].
        // Solo se ejecuta si hay texto de empleados y el cliente está vinculado.
        $employees_text = '';

        if ($stage_1 !== null && is_array($stage_1->data)) {
            /* Obtener el texto libre de empleados acumulado durante la conversación de la Etapa 1. */
            $employees_text = trim((string) ($stage_1->data['employees'] ?? ''));
        }

        if ($employees_text !== '' && $client !== null) {
            /* Llamar a Claude para extraer empleados estructurados del texto libre. */
            $parsed_employees = $this->parse_employees_text_with_claude($employees_text);

            foreach ($parsed_employees as $emp) {
                /* Extraer y limpiar cada campo del empleado parseado por Claude. */
                $name  = trim((string) ($emp['name'] ?? ''));
                $phone = trim((string) ($emp['phone'] ?? ''));
                $notes = trim((string) ($emp['role'] ?? ''));

                /* Omitir empleados sin nombre: sin nombre no se puede crear el registro. */
                if ($name === '') {
                    continue;
                }

                /* Normalizar el teléfono argentino al formato E.164 si fue proporcionado. */
                $normalized_phone = $phone !== ''
                    ? \App\Services\ArgentinePhoneNormalizer::normalize($phone)
                    : '';

                /* Construir las notas combinando área/rol y DNI si están disponibles. */
                $document      = trim((string) ($emp['document'] ?? ''));
                $notes_for_db  = '';

                if ($notes !== '') {
                    $notes_for_db = "Área: {$notes}";
                    if ($document !== '') {
                        $notes_for_db .= " — DNI: {$document}";
                    }
                } elseif ($document !== '') {
                    $notes_for_db = "DNI: {$document}";
                }

                /* Crear el ClientEmployee sin verificar duplicados para no pisar la
                 * sincronización de empresa-api (esa lógica es responsabilidad de otro flujo). */
                ClientEmployee::create([
                    'client_id' => $client->id,
                    'name'      => $name,
                    'phone'     => $normalized_phone !== '' ? $normalized_phone : $phone,
                    'notes'     => $notes_for_db,
                ]);
            }
        }

        // Copiar los datos de configuración recolectados al cliente para usarlos en el UserSetup.
        if ($stage_1 !== null && $client !== null) {
            $this->save_setup_data_to_client($implementation, $stage_1);
        }

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
     * Copia los datos de configuración recolectados en la Etapa 1 al campo setup_data
     * del cliente, normalizados para que el UserSetup de empresa-api los consuma luego.
     *
     * @param Implementation      $implementation Implementación dueña de la etapa.
     * @param ImplementationStage $stage_1        Etapa 1 con los datos recolectados.
     *
     * @return void
     */
    private function save_setup_data_to_client(Implementation $implementation, ImplementationStage $stage_1): void
    {
        // Cliente destino: dueño de la implementación.
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if ($client === null) {
            return;
        }

        // Data recolectada durante la conversación de la Etapa 1.
        $stage_data = is_array($stage_1->data) ? $stage_1->data : [];

        // Construir el array de configuración con los campos relevantes para el setup del sistema.
        $setup_data = [
            // Tipo de negocio (texto libre): condiciona el preset del sistema en empresa-api.
            'business_type'                       => (string) ($stage_data['business_type'] ?? ''),
            // Listas de precios: bandera y detalle en texto.
            'use_price_lists'                     => ($stage_data['use_price_lists'] ?? false) === true,
            'price_lists'                         => (string) ($stage_data['price_lists'] ?? ''),
            // Depósitos / sucursales: bandera y nombres en texto.
            'use_deposits'                        => ($stage_data['use_deposits'] ?? false) === true,
            'deposit_names'                       => (string) ($stage_data['deposit_names'] ?? ''),
            // IVA incluido en los precios (no se pregunta en Etapa 1; se deja por defecto en true).
            'iva_included'                        => true,
            // Cantidad al cargar una venta: preguntar (true) o agregar 1 automáticamente (false).
            'ask_amount_in_vender'                => ($stage_data['ask_amount_in_vender'] ?? false) === true,
            // Omitir cuenta corriente por defecto: se deriva de default_cuenta_corriente.
            'siempre_omitir_en_cuenta_corriente'  => ! (($stage_data['default_cuenta_corriente'] ?? false) === true),
            // Logo y redes sociales para online_configurations de la tienda.
            'logo_url'                            => (string) ($stage_data['logo_url'] ?? ''),
            'instagram'                           => (string) ($stage_data['instagram'] ?? ''),
            'facebook'                            => (string) ($stage_data['facebook'] ?? ''),
            // Tipo de precio online: no se pregunta en el flujo de sistema (se define en el ecommerce).
            'online_price_type_id'                => $stage_data['online_price_type'] ?? null,
            // Preferencias de entrega/retiro (se completan en el flujo de ecommerce si aplica).
            'has_delivery'                        => ($stage_data['has_delivery'] ?? false) === true,
            'retiro_por_local'                    => ($stage_data['retiro_por_local'] ?? false) === true,
            'enviar_whatsapp_al_terminar_pedido'  => ($stage_data['enviar_whatsapp_al_terminar_pedido'] ?? false) === true,
            // Dirección del negocio para los comprobantes.
            'address_company'                     => (string) ($stage_data['address_company'] ?? ''),
            // Cotización de precios en dólares: se deriva de dollar_prices.
            'cotizar_precios_en_dolares'          => ($stage_data['dollar_prices'] ?? false) === true,
        ];

        // Persistir la configuración en el cliente (cast 'array' serializa a JSON).
        $client->setup_data = $setup_data;
        $client->save();
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

    /**
     * Envía la plantilla de bienvenida `cc_implementacion_bienvenida` al iniciar la implementación.
     *
     * Best-effort: si falta teléfono o falla Kapso, se loguea y no se interrumpe el inicio.
     * El cuerpo reconstruido queda en implementation_messages para la vista de conversación.
     *
     * @param Implementation $implementation Implementación recién creada.
     *
     * @return void
     */
    public function send_welcome_template(Implementation $implementation): void
    {
        try {
            $implementation->loadMissing('client');
            $client = $implementation->client;

            if ($client === null) {
                Log::channel('daily')->warning('ImplementationConversationService: send_welcome_template sin cliente.', [
                    'implementation_id' => $implementation->id,
                ]);

                return;
            }

            // Teléfono del cliente: destino de la plantilla de bienvenida.
            $phone = trim((string) ($client->phone ?? ''));
            if ($phone === '') {
                Log::channel('daily')->warning('ImplementationConversationService: send_welcome_template sin teléfono de cliente.', [
                    'implementation_id' => $implementation->id,
                    'client_id'         => $client->id,
                ]);

                return;
            }

            // Nombre para personalizar {{1}} de la plantilla Meta.
            $client_name = $client->resolve_display_name();
            if ($client_name === '') {
                $client_name = 'cliente';
            }

            // Envío vía plantilla aprobada (variables en orden {{1}}, {{2}}…).
            $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                $phone,
                'cc_implementacion_bienvenida',
                [$client_name]
            );

            // Texto plano equivalente para mostrar en el hilo del admin.
            $body = "Hola {$client_name}, ¿cómo estás?\n\n"
                . "Soy Martín, te escribo porque voy a ser el encargado de tu implementación en ComercioCity. "
                . "Mi trabajo es acompañarte en todo el proceso: cargar la información de tu negocio, migrar tus datos y dejarte operando en la plataforma.\n\n"
                . "Vamos a ir paso a paso, y cualquier duda que tengas en el camino me la podés consultar acá mismo por este chat.\n\n"
                . "¿Arrancamos?";

            // Persistir sin reenviar texto: el ID proviene del send_template anterior.
            $this->send_outbound($implementation, 1, $phone, $body, $whatsapp_message_id);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('ImplementationConversationService: error en send_welcome_template.', [
                'implementation_id' => $implementation->id,
                'error'             => $exception->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Formulario público de configuración (Stage 1 via link)
    // -------------------------------------------------------------------------

    /**
     * Procesa el formulario de configuración enviado por el cliente.
     *
     * Se invoca desde ProcessImplementationFormSubmit después del delay configurado.
     *
     * Flujo:
     * 1. Envía mensaje de confirmación de recepción al cliente por WhatsApp.
     * 2. Marca el stage 1 como completed.
     * 3. Avanza a la Etapa 2 disparando handle_stage_advance.
     *
     * @param Implementation $implementation Implementación cuyo formulario fue enviado.
     *
     * @return void
     */
    public function handle_form_submitted(Implementation $implementation): void
    {
        // Cargar el cliente y las etapas si no están cargadas.
        $implementation->loadMissing(['client', 'stages']);

        $client = $implementation->client;

        if ($client === null) {
            Log::channel('daily')->warning('ImplementationConversationService: handle_form_submitted sin cliente.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        // Teléfono del cliente para enviar el mensaje de confirmación.
        $phone = trim((string) ($client->phone ?? ''));

        if ($phone === '') {
            Log::channel('daily')->warning('ImplementationConversationService: handle_form_submitted sin teléfono de cliente.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $client->id,
            ]);

            return;
        }

        // Nombre del cliente para personalizar el mensaje.
        $client_name = $client->resolve_display_name();
        if ($client_name === '') {
            $client_name = 'cliente';
        }

        // Generar lista de progreso de las 8 etapas; etapa 1 ya completada (✅).
        $etapas = [
            1 => 'Información de la empresa',
            2 => 'Instalación del sistema',
            3 => 'Recolección de archivos',
            4 => 'Migración de datos',
            5 => 'Entrega del sistema',
            6 => 'Capacitación',
            7 => 'Vinculación con ARCA/AFIP',
            8 => 'Videollamada de capacitación',
        ];

        // Cargar las etapas reales para marcar cuáles están completadas.
        $stages_map = [];
        $implementation->stages->each(function ($stage) use (&$stages_map) {
            $stages_map[(int) $stage->stage_number] = $stage->status;
        });

        // Construir líneas del resumen de progreso con íconos de estado.
        $progress_lines = [];
        foreach ($etapas as $number => $label) {
            $status = $stages_map[$number] ?? 'pending';
            // Etapa 1 se considera completed porque acaba de enviarse el formulario.
            $icon = ($number === 1 || $status === 'completed') ? '✅' : '⬜';
            $progress_lines[] = "{$icon} {$number}. {$label}";
        }

        // Mensaje de confirmación de recepción del formulario.
        $progress_text = implode("\n", $progress_lines);
        $body          = "¡Perfecto, {$client_name}! Ya recibimos toda la información de tu empresa 🎉\n\n"
            . "Tu progreso:\n{$progress_text}\n\n"
            . "Nuestro equipo ya está trabajando en la instalación. Te aviso cuando esté lista.";

        // Enviar el mensaje por WhatsApp.
        $this->send_outbound($implementation, 1, $phone, $body, null);

        // Marcar el stage 1 como completado.
        $stage_1 = $implementation->stages->first(function ($s) {
            return (int) $s->stage_number === 1;
        });

        if ($stage_1 !== null && $stage_1->status !== 'completed') {
            $stage_1->status       = 'completed';
            $stage_1->completed_at = now();
            $stage_1->save();
        }

        // Actualizar current_stage a 2 en la implementación.
        $implementation->current_stage = 2;
        $implementation->save();

        // Activar stage 2 en la tabla de etapas.
        $stage_2 = $implementation->stages->first(function ($s) {
            return (int) $s->stage_number === 2;
        });

        if ($stage_2 !== null && $stage_2->status !== 'completed') {
            $stage_2->status     = 'in_progress';
            $stage_2->started_at = now();
            $stage_2->save();
        }

        // Disparar acciones automáticas al avanzar a la Etapa 2 (envío de mensaje de instalación).
        $this->handle_stage_advance($implementation, 2);

        Log::channel('daily')->info('ImplementationConversationService: handle_form_submitted completado.', [
            'implementation_id' => $implementation->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers de envío y persistencia
    // -------------------------------------------------------------------------

    /**
     * Envía un mensaje de texto por WhatsApp y lo persiste en implementation_messages.
     *
     * @param Implementation $implementation      Implementación asociada al mensaje.
     * @param int            $stage_number        Número de etapa del mensaje.
     * @param string         $phone               Teléfono destino E.164.
     * @param string         $body                Texto del mensaje.
     * @param string|null    $whatsapp_message_id ID de Meta si el envío ya ocurrió (p. ej. plantilla).
     *
     * @return void
     */
    private function send_outbound(
        Implementation $implementation,
        int $stage_number,
        string $phone,
        string $body,
        ?string $whatsapp_message_id = null
    ): void {
        // Enviar por Kapso salvo que ya exista un ID (p. ej. mensaje de plantilla).
        if ($whatsapp_message_id === null) {
            $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $body);
        }

        // Persistir siempre, aunque el envío falle (para auditoría y re-envío manual).
        $outbound_message = ImplementationMessage::create([
            'implementation_id'   => $implementation->id,
            'stage_number'        => $stage_number,
            'direction'           => 'outbound',
            'body'                => $body,
            'whatsapp_message_id' => $whatsapp_message_id,
            'sent_at'             => now(),
        ]);

        ImplementationBroadcastService::emit_message_received(
            (int) $implementation->id,
            (int) $outbound_message->id
        );
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
     * Determina si la respuesta del dueño indica que él mismo se encargará de la tarea.
     *
     * Delega la interpretación a Claude para cubrir variantes naturales como
     * "yo", "yo mismo", "yo me encargo", o el propio nombre del cliente.
     *
     * @param string      $body   Texto de la respuesta recibida.
     * @param Client|null $client Cliente asociado (se incluye el nombre en el contexto si está disponible).
     *
     * @return bool true si el dueño se designó a sí mismo.
     */
    private function is_self_referential_response(string $body, ?Client $client): bool
    {
        // Construir contexto de respuesta: incluir el nombre del cliente si está disponible
        // para que Claude pueda detectar auto-referencia en tercera persona ("lo hago Juan").
        $context = $body;
        if ($client !== null) {
            $display_name = $client->resolve_display_name();
            $context .= " [nombre del cliente: {$display_name}]";
        }

        // Texto genérico que representa la pregunta de responsabilidad.
        $question_text = '¿Quién se va a encargar de esta tarea?';

        // Delegar la interpretación semántica a Claude.
        $result = $this->ai_interpreter->interpret('is_self', $question_text, $context);

        return $result['value'] === true;
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

    /**
     * Garantiza que exista un ClientInstallation pendiente para la etapa 3 de la implementación.
     *
     * Es idempotente: si ya existe una instalación pendiente para el cliente, no crea otra.
     * Si no existe, crea una con la active_client_api y la versión publicada más reciente.
     *
     * @param  Implementation  $implementation  Implementación activa en etapa 3.
     * @return void
     */
    private function ensure_installation_for_stage_3(Implementation $implementation): void
    {
        // Solo actúa si la implementación tiene un cliente asociado.
        if (! $implementation->client_id) {
            return;
        }

        // Verifica si ya existe una instalación pendiente para evitar duplicados.
        $existing = ClientInstallation::where('client_id', $implementation->client_id)
            ->where('status', 'pendiente')
            ->first();

        if ($existing !== null) {
            // Ya existe: no se crea una nueva (comportamiento idempotente).
            return;
        }

        // Obtiene el cliente para tomar su active_client_api_id.
        $client = Client::find($implementation->client_id);
        if ($client === null) {
            return;
        }

        // Versión publicada más reciente disponible.
        $latest_version = Version::where('status', 'publicada')
            ->orderByDesc('id')
            ->first();

        // Crea la instalación pendiente lista para que el operador cargue las variables manuales.
        ClientInstallation::create([
            'client_id'     => $client->id,
            'client_api_id' => $client->active_client_api_id,
            'version_id'    => $latest_version ? $latest_version->id : null,
            'status'        => 'pendiente',
        ]);
    }
}

