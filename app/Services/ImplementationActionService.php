<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInstallation;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;

/**
 * Orquesta las acciones manuales del panel de implementación (modo `manual`).
 *
 * Cada acción es un botón que abre un preview editable y se envía con un clic, en
 * lugar de esperar a que el flujo automático hable por sí solo. Resuelve además la
 * ventana de 24 h de WhatsApp por teléfono (el dueño y el responsable de migración
 * pueden ser personas distintas, cada una con su propia ventana).
 *
 * Los textos de cada acción no se redactan acá: se reutilizan los `build_*_body()`
 * de ImplementationConversationService (un solo lugar por texto, compartido con el
 * flujo automático en modo `auto`).
 */
class ImplementationActionService
{
    /** Acciones válidas del flujo manual. */
    private const ACTIONS = ['presentacion', 'form_link', 'progreso', 'pedir_archivos', 'entrega', 'user_setup', 'crear_instalacion'];

    /** Nombre legible de cada acción para el panel. */
    private const LABELS = [
        'presentacion'      => 'Presentación',
        'form_link'         => 'Link del formulario',
        'progreso'          => 'Progreso',
        'pedir_archivos'    => 'Pedido de archivos',
        'entrega'           => 'Entrega del sistema',
        'user_setup'        => 'Configuración del sistema (UserSetup)',
        'crear_instalacion' => 'Crear instalación',
    ];

    /**
     * Etapa típica de cada acción (solo sugerencia para la UI vía `available`; ninguna
     * acción se bloquea por etapa, salvo `user_setup` que además tiene el gate real de
     * `user_setup_gate()`). `progreso` no tiene etapa fija: siempre disponible.
     */
    private const TYPICAL_STAGE = [
        'presentacion'      => 1,
        'form_link'         => 1,
        'user_setup'        => 2,
        'crear_instalacion' => 2,
        'pedir_archivos'    => 3,
        'entrega'           => 5,
    ];

    /** Única plantilla de WhatsApp aprobada hoy para el flujo manual. */
    private const WELCOME_TEMPLATE_NAME = 'cc_implementacion_bienvenida';

    /**
     * @var ImplementationConversationService Fuente de los textos (build_*_body()) y del envío/persistencia.
     */
    private $conversation_service;

    /**
     * @var ImplementationUserSetupService Ejecuta y arma el payload de la acción 'user_setup'.
     */
    private $user_setup_service;

    /**
     * @var WhatsappSendService Envío directo de la plantilla de bienvenida cuando la ventana está cerrada.
     */
    private $whatsapp_send_service;

    /**
     * @param ImplementationConversationService|null $conversation_service  Inyección opcional para tests.
     * @param ImplementationUserSetupService|null    $user_setup_service    Inyección opcional para tests.
     * @param WhatsappSendService|null               $whatsapp_send_service Inyección opcional para tests.
     */
    public function __construct(
        ?ImplementationConversationService $conversation_service = null,
        ?ImplementationUserSetupService $user_setup_service = null,
        ?WhatsappSendService $whatsapp_send_service = null
    ) {
        $this->conversation_service  = $conversation_service ?? new ImplementationConversationService();
        $this->user_setup_service    = $user_setup_service ?? new ImplementationUserSetupService();
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Estado completo del panel de acciones de una implementación.
     *
     * @param Implementation $implementation
     *
     * @return array{
     *   automation_mode: string,
     *   recipients: array,
     *   windows: array,
     *   actions: array<int, array>
     * }
     */
    public function state(Implementation $implementation): array
    {
        $implementation->loadMissing(['client', 'stages']);

        // Teléfonos destino de cada rol involucrado en el flujo.
        $owner_phone     = $this->resolve_owner_phone($implementation);
        $migration_phone = $this->resolve_migration_phone($implementation);

        $recipients = [
            'owner'     => $owner_phone,
            'migration' => $migration_phone,
        ];

        // Ventana de 24 h de cada teléfono, calculada una sola vez y reutilizada por acción.
        $windows = [
            'owner'     => $owner_phone !== '' ? $this->window_state($implementation, $owner_phone) : $this->closed_window(),
            'migration' => $migration_phone !== '' ? $this->window_state($implementation, $migration_phone) : $this->closed_window(),
        ];

        // Historial de acciones ya ejecutadas (para last_executed_at), leído de todas las etapas.
        $actions_log = $this->read_actions_log($implementation);

        $current_stage = (int) $implementation->current_stage;

        $actions = [];
        foreach (self::ACTIONS as $action) {
            $recipient_key = $action === 'pedir_archivos' ? 'migration' : 'owner';

            // Estado de bloqueo (solo user_setup lo usa hoy; el resto queda en false/null).
            $blocked        = false;
            $blocked_reason = null;
            $can_force      = false;
            $executed_at    = null;

            if ($action === 'user_setup') {
                $executed_at = $implementation->user_setup_executed_at !== null
                    ? $implementation->user_setup_executed_at->toISOString()
                    : null;

                if ($executed_at !== null) {
                    // Ya se aplicó con éxito: bloqueado, pero se puede re-aplicar con force (el front confirma).
                    $blocked        = true;
                    $blocked_reason = 'El UserSetup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i') . '. Usá "Forzar" para volver a aplicarlo.';
                    $can_force      = true;
                } else {
                    $gate = $this->user_setup_gate($implementation);
                    if (! $gate['enabled']) {
                        $blocked        = true;
                        $blocked_reason = $gate['reason'];
                        $can_force      = false;
                    }
                }
            }

            $actions[] = [
                'key'              => $action,
                'label'            => self::LABELS[$action],
                'available'        => $this->is_available_for_stage($action, $current_stage),
                'recipient_label'  => $this->resolve_recipient_label($action),
                // 'user_setup' y 'crear_instalacion' no dependen de la ventana de WhatsApp: no envían mensaje.
                'window_open'      => in_array($action, ['user_setup', 'crear_instalacion'], true) ? true : $windows[$recipient_key]['open'],
                'last_executed_at' => $this->last_executed_at($actions_log, $action),
                'blocked'          => $blocked,
                'blocked_reason'   => $blocked_reason,
                'can_force'        => $can_force,
                'executed_at'      => $executed_at,
            ];
        }

        return [
            'automation_mode' => $this->resolve_automation_mode($implementation),
            'recipients'      => $recipients,
            'windows'         => $windows,
            'actions'         => $actions,
        ];
    }

    /**
     * Preview de una acción: qué se va a enviar, a quién, y si hace falta plantilla.
     *
     * @param Implementation $implementation
     * @param string         $action
     * @param int|null       $stage Solo para 'progreso' (default: current_stage).
     *
     * @return array{
     *   action: string,
     *   body: string,
     *   recipient_phone: string,
     *   recipient_label: string,
     *   window_open: bool,
     *   requires_template: bool,
     *   template_name: string|null,
     *   editable: bool
     * }
     */
    public function preview(Implementation $implementation, string $action, ?int $stage = null): array
    {
        $this->assert_valid_action($action);

        $implementation->loadMissing(['client', 'stages']);

        // 'user_setup' no manda WhatsApp: el body es el payload real a revisar.
        if ($action === 'user_setup') {
            return $this->preview_user_setup($implementation);
        }

        // 'crear_instalacion' no manda WhatsApp: el body describe qué se va a crear.
        if ($action === 'crear_instalacion') {
            return $this->preview_crear_instalacion($implementation);
        }

        $recipient_phone = $this->resolve_recipient_phone($implementation, $action);
        $recipient_label = $this->resolve_recipient_label($action);

        $window      = $recipient_phone !== '' ? $this->window_state($implementation, $recipient_phone) : $this->closed_window();
        $window_open = $window['open'];

        $body = $this->build_body_for_action($implementation, $action, $stage);

        // Solo 'presentacion' tiene plantilla aprobada hoy: con ventana cerrada se manda igual,
        // vía plantilla (no editable). El resto de las acciones, con ventana cerrada, todavía
        // no tienen forma de llegar (Meta rechazaría un texto libre fuera de ventana).
        $will_use_template = $action === 'presentacion' && ! $window_open;
        $requires_template = ! $window_open && ! $will_use_template;

        return [
            'action'            => $action,
            'body'              => $body,
            'recipient_phone'   => $recipient_phone,
            'recipient_label'   => $recipient_label,
            'window_open'       => $window_open,
            'requires_template' => $requires_template,
            'template_name'     => $will_use_template ? self::WELCOME_TEMPLATE_NAME : null,
            'editable'          => ! $will_use_template,
        ];
    }

    /**
     * Ejecuta la acción: envía el mensaje (o corre el UserSetup) y registra la ejecución.
     *
     * @param Implementation $implementation
     * @param string         $action
     * @param string|null    $content Texto editado por el admin; si es null se usa el del preview.
     * @param int|null       $stage   Solo para 'progreso'.
     * @param bool           $force   Override del lock de 'user_setup' (re-aplicar aunque ya se haya aplicado).
     *
     * @return array{ok: bool, message: string}
     */
    public function execute(Implementation $implementation, string $action, ?string $content = null, ?int $stage = null, bool $force = false): array
    {
        $this->assert_valid_action($action);

        $implementation->loadMissing(['client', 'stages']);

        // 'user_setup' no manda WhatsApp: valida gate + lock y delega en el servicio de setup remoto.
        if ($action === 'user_setup') {
            // El gate (formulario + Etapa 2 + instalación completada) se exige SIEMPRE, incluso con force:
            // forzar saltea el lock de "ya se aplicó", no la condición de que la API tiene que responder.
            $gate = $this->user_setup_gate($implementation);
            if (! $gate['enabled']) {
                return ['ok' => false, 'message' => $gate['reason']];
            }

            // Lock: si ya se aplicó con éxito y no viene force, no se re-ejecuta.
            if ($implementation->user_setup_executed_at !== null && ! $force) {
                return [
                    'ok'      => false,
                    'message' => 'El UserSetup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i')
                        . ". Reintentá con \"Forzar\" si necesitás re-aplicarlo.",
                ];
            }

            $result = $this->user_setup_service->trigger_user_setup($implementation);

            if ($result['ok']) {
                // Registrar el momento de aplicación (lock) y la acción para los checklists.
                $implementation->user_setup_executed_at = now();
                $implementation->save();
                $this->register_action($implementation, $action);
            }

            return $result;
        }

        // 'crear_instalacion' no manda WhatsApp: crea la ClientInstallation de forma idempotente.
        if ($action === 'crear_instalacion') {
            $outcome = $this->conversation_service->ensure_client_installation($implementation);

            if ($outcome['created']) {
                $this->register_action($implementation, $action);
                return ['ok' => true, 'message' => 'Instalación creada. Ya aparece en el módulo de Instalaciones.'];
            }

            return ['ok' => true, 'message' => 'La instalación de este cliente ya existía; no se creó una nueva.'];
        }

        $preview = $this->preview($implementation, $action, $stage);

        if ($preview['recipient_phone'] === '') {
            return ['ok' => false, 'message' => 'No hay un teléfono de destino cargado para esta acción.'];
        }

        // Ventana cerrada y sin plantilla disponible: no se intenta el envío (fallaría en Meta,
        // pero silenciosamente, así que se corta acá con un mensaje claro para el admin).
        if ($preview['requires_template']) {
            return [
                'ok'      => false,
                'message' => "La ventana de 24 h con {$preview['recipient_label']} está cerrada y todavía no hay "
                    . 'plantilla aprobada para esta acción. Pedile al cliente que escriba algo, o usá la '
                    . 'plantilla de presentación.',
            ];
        }

        // Texto final: el editado por el admin (si la acción lo permite) o el del preview.
        $body = ($content !== null && trim($content) !== '' && $preview['editable'])
            ? $content
            : $preview['body'];

        // Etapa a registrar en el hilo: la seleccionada explícitamente para 'progreso', si no la actual.
        $stage_number = $action === 'progreso'
            ? ($stage ?? (int) $implementation->current_stage)
            : (int) $implementation->current_stage;

        if ($preview['template_name'] !== null) {
            // Envío vía plantilla aprobada: Meta no permite modificar su cuerpo.
            $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                $preview['recipient_phone'],
                $preview['template_name'],
                [$this->resolve_client_name($implementation)]
            );

            // Se persiste siempre el texto equivalente de build_welcome_body(), nunca el editado.
            $this->conversation_service->send_manual_outbound(
                $implementation,
                $stage_number,
                $preview['recipient_phone'],
                $preview['body'],
                $whatsapp_message_id
            );
        } else {
            $this->conversation_service->send_manual_outbound(
                $implementation,
                $stage_number,
                $preview['recipient_phone'],
                $body
            );
        }

        $this->register_action($implementation, $action, $stage_number);

        return ['ok' => true, 'message' => 'Mensaje enviado correctamente.'];
    }

    /**
     * Estado de la ventana de 24 h de WhatsApp para un teléfono concreto.
     *
     * Se calcula desde el último ImplementationMessage inbound de ese teléfono.
     * Si no hay ningún inbound registrado con ese teléfono (o los mensajes viejos
     * tienen phone = null), la ventana se considera CERRADA (conservador).
     *
     * @param Implementation $implementation
     * @param string         $phone
     *
     * @return array{open: bool, last_inbound_at: string|null, expires_at: string|null}
     */
    public function window_state(Implementation $implementation, string $phone): array
    {
        $phone = trim($phone);

        if ($phone === '') {
            return $this->closed_window();
        }

        // Último mensaje entrante de esa persona concreta (por teléfono).
        $last_inbound = ImplementationMessage::where('implementation_id', $implementation->id)
            ->where('direction', 'inbound')
            ->where('phone', $phone)
            ->orderByDesc('sent_at')
            ->first();

        if ($last_inbound === null || $last_inbound->sent_at === null) {
            // Sin inbound registrado con ese teléfono: postura conservadora, ventana cerrada.
            return $this->closed_window();
        }

        // La ventana de WhatsApp dura 24 h desde el último mensaje entrante de esa persona.
        $expires_at = $last_inbound->sent_at->copy()->addHours(24);

        return [
            'open'             => now()->lt($expires_at),
            'last_inbound_at'  => $last_inbound->sent_at->toISOString(),
            'expires_at'       => $expires_at->toISOString(),
        ];
    }

    // -------------------------------------------------------------------------
    // Preview por acción
    // -------------------------------------------------------------------------

    /**
     * Construye el texto de una acción de mensaje delegando en el builder correspondiente
     * de ImplementationConversationService.
     *
     * @param Implementation $implementation
     * @param string         $action
     * @param int|null       $stage Solo relevante para 'progreso'.
     *
     * @return string
     */
    private function build_body_for_action(Implementation $implementation, string $action, ?int $stage): string
    {
        // PHP 7.4: switch en lugar de match().
        switch ($action) {
            case 'presentacion':
                return $this->conversation_service->build_welcome_body($implementation);
            case 'form_link':
                return $this->conversation_service->build_form_link_body($implementation);
            case 'progreso':
                $target_stage = $stage ?? (int) $implementation->current_stage;
                return $this->conversation_service->build_progress_body($implementation, $target_stage);
            case 'pedir_archivos':
                return $this->conversation_service->build_files_request_body($implementation);
            case 'entrega':
                return $this->conversation_service->build_delivery_body($implementation);
            default:
                return '';
        }
    }

    /**
     * Preview de la acción 'user_setup': no manda WhatsApp, el body es el payload real
     * (con los datos ya mapeados desde el formulario) que se enviará a la client_api.
     *
     * @param Implementation $implementation
     *
     * @return array{action: string, body: string, recipient_phone: string, recipient_label: string, window_open: bool, requires_template: bool, template_name: string|null, editable: bool}
     */
    private function preview_user_setup(Implementation $implementation): array
    {
        $client  = $implementation->client ?? Client::find($implementation->client_id);
        $payload = $client !== null ? $this->user_setup_service->build_payload($client) : [];

        return [
            'action'            => 'user_setup',
            'body'              => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'recipient_phone'   => '',
            'recipient_label'   => '—',
            // No depende de WhatsApp: no hay ventana que respetar.
            'window_open'       => true,
            'requires_template' => false,
            'template_name'     => null,
            // No es un mensaje: no tiene sentido editarlo desde el panel.
            'editable'          => false,
        ];
    }

    /**
     * Preview de la acción 'crear_instalacion': no manda WhatsApp. El body describe si se va a
     * crear la ClientInstallation del cliente o si ya existe (la acción es idempotente).
     *
     * @param Implementation $implementation
     *
     * @return array{action: string, body: string, recipient_phone: string, recipient_label: string, window_open: bool, requires_template: bool, template_name: string|null, editable: bool}
     */
    private function preview_crear_instalacion(Implementation $implementation): array
    {
        $existing = ClientInstallation::where('client_id', $implementation->client_id)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $body = 'Este cliente ya tiene una instalación creada (estado actual: ' . $existing->status . '). '
                . 'Crear de nuevo no hace nada: la acción es idempotente.';
        } else {
            $body = 'Se va a crear la instalación del cliente en estado "pendiente" para que aparezca en el '
                . 'módulo de Instalaciones y el equipo pueda instalar el sistema. No se envía ningún mensaje al cliente.';
        }

        return [
            'action'            => 'crear_instalacion',
            'body'              => $body,
            'recipient_phone'   => '',
            'recipient_label'   => '—',
            'window_open'       => true,
            'requires_template' => false,
            'template_name'     => null,
            'editable'          => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Destinatarios
    // -------------------------------------------------------------------------

    /**
     * Teléfono del dueño del negocio (cliente de la implementación).
     *
     * @param Implementation $implementation
     *
     * @return string
     */
    private function resolve_owner_phone(Implementation $implementation): string
    {
        $client = $implementation->client ?? Client::find($implementation->client_id);

        return trim((string) ($client->phone ?? ''));
    }

    /**
     * Teléfono del responsable de migración; si no está cargado, cae al dueño.
     *
     * @param Implementation $implementation
     *
     * @return string
     */
    private function resolve_migration_phone(Implementation $implementation): string
    {
        $migration_phone = trim((string) ($implementation->migration_contact_phone ?? ''));

        return $migration_phone !== '' ? $migration_phone : $this->resolve_owner_phone($implementation);
    }

    /**
     * Teléfono destino según la acción: 'pedir_archivos' va al responsable de migración,
     * todas las demás acciones van al dueño.
     *
     * @param Implementation $implementation
     * @param string         $action
     *
     * @return string
     */
    private function resolve_recipient_phone(Implementation $implementation, string $action): string
    {
        return $action === 'pedir_archivos'
            ? $this->resolve_migration_phone($implementation)
            : $this->resolve_owner_phone($implementation);
    }

    /**
     * Etiqueta legible del destinatario de una acción.
     *
     * @param string $action
     *
     * @return string
     */
    private function resolve_recipient_label(string $action): string
    {
        return $action === 'pedir_archivos' ? 'Responsable de migración' : 'Dueño';
    }

    /**
     * Nombre del cliente para personalizar la plantilla de WhatsApp ({{1}}).
     *
     * @param Implementation $implementation
     *
     * @return string
     */
    private function resolve_client_name(Implementation $implementation): string
    {
        $client = $implementation->client ?? Client::find($implementation->client_id);
        $name   = $client ? $client->resolve_display_name() : '';

        return $name !== '' ? $name : 'cliente';
    }

    // -------------------------------------------------------------------------
    // Disponibilidad, modo de automatización y registro de ejecuciones
    // -------------------------------------------------------------------------

    /**
     * Condiciones para poder aplicar el UserSetup.
     *
     * El UserSetup le pega a la client_api del cliente con la config del formulario, así que
     * exige TRES cosas (el gate real; el lock por user_setup_executed_at se evalúa aparte):
     *   1) El formulario de la Etapa 1 ya se completó (si no, el payload no tiene datos reales).
     *   2) La implementación avanzó a la Etapa 2 (current_stage >= 2) — el UserSetup corre DENTRO
     *      de la Etapa 2, después de instalar; no requiere que la Etapa 2 esté "completada"
     *      (eso sería contradictorio: la Etapa 2 se cierra recién después de aplicar el UserSetup).
     *   3) La ClientInstallation del cliente está en 'completada' (la API ya responde).
     *
     * Devuelve el primer motivo de bloqueo encontrado, o enabled=true si se cumplen las tres.
     *
     * @param Implementation $implementation
     *
     * @return array{enabled: bool, reason: string|null}
     */
    private function user_setup_gate(Implementation $implementation): array
    {
        // 1) Formulario de la Etapa 1 completo.
        $form_done = $implementation->form_submitted_at !== null;
        if (! $form_done) {
            $stage_1 = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', 1)
                ->first();
            $form_done = $stage_1 !== null && $stage_1->status === 'completed';
        }
        if (! $form_done) {
            return ['enabled' => false, 'reason' => 'Todavía no se completó el formulario (Etapa 1).'];
        }

        // 2) La implementación llegó a la Etapa 2.
        if ((int) $implementation->current_stage < 2) {
            return ['enabled' => false, 'reason' => 'La implementación todavía no avanzó a la Etapa 2.'];
        }

        // 3) La instalación del cliente terminó (la API responde).
        $installation = ClientInstallation::where('client_id', $implementation->client_id)
            ->orderByDesc('id')
            ->first();

        if ($installation === null || $installation->status !== 'completada') {
            return ['enabled' => false, 'reason' => "El sistema todavía no terminó de instalarse (la instalación no está en 'completada')."];
        }

        return ['enabled' => true, 'reason' => null];
    }

    /**
     * Indica si una acción corresponde a la etapa actual (solo sugerencia para la UI:
     * ninguna acción se bloquea por esto, el endpoint las acepta igual).
     *
     * @param string $action
     * @param int    $current_stage
     *
     * @return bool
     */
    private function is_available_for_stage(string $action, int $current_stage): bool
    {
        // 'progreso' siempre está disponible: no tiene una etapa típica fija.
        if ($action === 'progreso') {
            return true;
        }

        return (self::TYPICAL_STAGE[$action] ?? null) === $current_stage;
    }

    /**
     * Modo de automatización de la implementación ('manual' | 'auto').
     *
     * La columna `automation_mode` la introduce el prompt 342; si todavía no corrió esa
     * migración, el acceso al atributo devuelve null sin error (Eloquent) y se asume
     * 'manual' como default conservador, coherente con el pivot hacia orquestación asistida.
     *
     * @param Implementation $implementation
     *
     * @return string
     */
    private function resolve_automation_mode(Implementation $implementation): string
    {
        $mode = $implementation->automation_mode ?? null;

        return is_string($mode) && $mode !== '' ? $mode : 'manual';
    }

    /**
     * Registra la ejecución exitosa de una acción en el `data` de la etapa activa
     * (implementation.current_stage), para alimentar los checklists del panel.
     *
     * @param Implementation $implementation
     * @param string         $action
     * @param int|null       $stage_number Etapa a registrar en la entrada (default: current_stage).
     *
     * @return void
     */
    private function register_action(Implementation $implementation, string $action, ?int $stage_number = null): void
    {
        $stage_number = $stage_number ?? (int) $implementation->current_stage;

        // Etapa activa del proceso: ahí se asienta el registro, independientemente
        // de a qué etapa se refiera el mensaje enviado (relevante para 'progreso').
        $stage_record = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', $implementation->current_stage)
            ->first();

        if ($stage_record === null) {
            return;
        }

        $data             = is_array($stage_record->data) ? $stage_record->data : [];
        $data['actions']  = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        $data['actions'][] = [
            'action' => $action,
            'stage'  => $stage_number,
            'at'     => now()->toISOString(),
        ];

        $stage_record->data = $data;
        $stage_record->save();
    }

    /**
     * Recolecta todas las entradas de `data['actions']` de todas las etapas de la implementación.
     *
     * @param Implementation $implementation
     *
     * @return array<int, array<string, mixed>>
     */
    private function read_actions_log(Implementation $implementation): array
    {
        $entries = [];

        $implementation->stages->each(function ($stage) use (&$entries) {
            $data    = is_array($stage->data) ? $stage->data : [];
            $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];

            foreach ($actions as $entry) {
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        });

        return $entries;
    }

    /**
     * Última fecha (ISO 8601) en que se ejecutó una acción concreta, según el registro leído.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param string                            $action
     *
     * @return string|null
     */
    private function last_executed_at(array $entries, string $action): ?string
    {
        $last = null;

        foreach ($entries as $entry) {
            if (($entry['action'] ?? null) !== $action) {
                continue;
            }

            $at = (string) ($entry['at'] ?? '');

            if ($at === '') {
                continue;
            }

            // Comparación lexicográfica válida: las fechas están en formato ISO 8601.
            if ($last === null || $at > $last) {
                $last = $at;
            }
        }

        return $last;
    }

    /**
     * Ventana cerrada sin datos de inbound: valor por defecto conservador.
     *
     * @return array{open: bool, last_inbound_at: string|null, expires_at: string|null}
     */
    private function closed_window(): array
    {
        return ['open' => false, 'last_inbound_at' => null, 'expires_at' => null];
    }

    /**
     * Valida que la acción solicitada exista en el catálogo de acciones manuales.
     *
     * @param string $action
     *
     * @return void
     *
     * @throws \InvalidArgumentException Si la acción no está en ACTIONS.
     */
    private function assert_valid_action(string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Acción de implementación desconocida: {$action}");
        }
    }
}
