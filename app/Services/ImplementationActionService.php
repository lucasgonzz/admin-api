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
     * acción se bloquea por etapa, salvo `user_setup` que además pasa por el candado
     * (`UserSetupCandadoService::evaluar_para_el_panel()`). `progreso` no tiene etapa fija: siempre disponible.
     */
    private const TYPICAL_STAGE = [
        'presentacion'      => 1,
        'form_link'         => 1,
        'user_setup'        => 2,
        'crear_instalacion' => 2,
        'pedir_archivos'    => 3,
        'entrega'           => 5,
    ];

    /** Acciones que no envían WhatsApp (efecto interno): no llevan botones Copiar/Enviar en el panel. */
    private const SIDE_EFFECT_ACTIONS = ['user_setup', 'crear_instalacion'];

    /** Única plantilla de WhatsApp aprobada hoy para el flujo manual. */
    private const WELCOME_TEMPLATE_NAME = 'cc_implementacion_bienvenida';

    /**
     * Campos del payload del UserSetup que el preview del panel muestra TAPADOS: claves de
     * servicios pagos.
     *
     * Hoy viaja solo la de Serper. La de Google está en la lista por si algún día se suma a este
     * camino: así el preview ya nace tapándola y no depende de que alguien se acuerde.
     */
    private const CAMPOS_TAPADOS_EN_EL_PREVIEW = ['serper_api_key', 'google_custom_search_api_key'];

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
     * @var UserSetupCandadoService El candado del user setup: qué se puede forzar, qué no, y la confirmación por nombre.
     */
    private $candado;

    /**
     * @param ImplementationConversationService|null $conversation_service  Inyección opcional para tests.
     * @param ImplementationUserSetupService|null    $user_setup_service    Inyección opcional para tests.
     * @param WhatsappSendService|null               $whatsapp_send_service Inyección opcional para tests.
     * @param UserSetupCandadoService|null           $candado               Inyección opcional para tests.
     */
    public function __construct(
        ?ImplementationConversationService $conversation_service = null,
        ?ImplementationUserSetupService $user_setup_service = null,
        ?WhatsappSendService $whatsapp_send_service = null,
        ?UserSetupCandadoService $candado = null
    ) {
        $this->conversation_service  = $conversation_service ?? new ImplementationConversationService();
        $this->user_setup_service    = $user_setup_service ?? new ImplementationUserSetupService();
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
        $this->candado               = $candado ?? new UserSetupCandadoService();
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

            // Las claves de la confirmación fuerte del re-aplicado: SOLO las lleva `user_setup` (ver estado_del_boton_user_setup()).
            $confirmacion = [];

            if ($action === 'user_setup') {
                $executed_at = $implementation->user_setup_executed_at !== null
                    ? $implementation->user_setup_executed_at->toISOString()
                    : null;

                // Lo que dice el candado: si se puede aplicar, si se puede forzar y qué hay que confirmar.
                $boton = $this->estado_del_boton_user_setup($implementation);

                $blocked        = $boton['blocked'];
                $blocked_reason = $boton['blocked_reason'];
                $can_force      = $boton['can_force'];
                $confirmacion   = $boton['confirmacion'];
            }

            // 🔴 Las claves de `$confirmacion` van al FINAL y son ADITIVAS: un SPA viejo las ignora y las que ya existían conservan nombre y tipo.
            $actions[] = array_merge([
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
                'typical_stage'    => self::TYPICAL_STAGE[$action] ?? null,
                'kind'             => in_array($action, self::SIDE_EFFECT_ACTIONS, true) ? 'side_effect' : 'message',
            ], $confirmacion);
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
     * @param bool           $force   Override del candado de 'user_setup' (re-aplicar aunque el sistema ya se haya configurado o ya opere).
     *                                Solo sirve con la confirmación fuerte: ver `$confirm_client_name` y `$confirm_live_system`.
     * @param string|null    $confirm_client_name El nombre del cliente que escribió la persona (solo 'user_setup' con `$force`).
     * @param bool           $confirm_live_system true = la persona reconoció que el sistema está en uso y que se van a perder todos sus
     *                                datos (solo 'user_setup' con `$force`, y solo hace falta si hay señales de que el sistema está en uso).
     *
     * @return array{ok: bool, message: string, codigo?: string} `codigo` aparece solo en los dos casos de la confirmación fuerte:
     *         `confirmacion_requerida` y `falta_confirmar_sistema_en_uso`.
     */
    public function execute(
        Implementation $implementation,
        string $action,
        ?string $content = null,
        ?int $stage = null,
        bool $force = false,
        ?string $confirm_client_name = null,
        bool $confirm_live_system = false
    ): array {
        $this->assert_valid_action($action);

        $implementation->loadMissing(['client', 'stages']);

        // 'user_setup' no manda WhatsApp: pasa por el candado (los duros, lo forzable y la confirmación) y delega en el servicio de setup remoto.
        if ($action === 'user_setup') {
            return $this->execute_user_setup($implementation, $force, $confirm_client_name, $confirm_live_system);
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

        /* 🔴 El preview se muestra en el panel: las claves de servicios pagos van TAPADAS. Taparlas
         * acá no cambia lo que viaja de verdad: execute() no usa este body, llama a
         * trigger_user_setup(), que arma su propio payload con la clave entera. */
        $payload = $this->tapar_claves_del_payload($payload);

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
     * Devuelve el payload del UserSetup con las claves de servicios pagos tapadas, para mostrarlo
     * en el panel.
     *
     * Cada clave presente se reemplaza por "cargada, termina en XXXX" (sus últimos cuatro
     * caracteres): alcanza para reconocer cuál está cargada sin exponerla. Si fuera tan corta que
     * esos cuatro serían una parte grande de la clave, se muestra solo "cargada".
     *
     * Solo toca los campos de CAMPOS_TAPADOS_EN_EL_PREVIEW: el resto del payload se muestra tal
     * cual, que es para lo que existe el preview.
     *
     * @param array<string, mixed> $payload Payload real del UserSetup.
     *
     * @return array<string, mixed> El mismo payload, con las claves tapadas.
     */
    private function tapar_claves_del_payload(array $payload): array
    {
        foreach (self::CAMPOS_TAPADOS_EN_EL_PREVIEW as $campo) {
            if (! array_key_exists($campo, $payload)) {
                continue;
            }

            // Solo un string puede ser una clave; cualquier otra cosa se tapa igual, sin leerla.
            $clave = is_string($payload[$campo]) ? $payload[$campo] : '';

            $payload[$campo] = strlen($clave) >= 16
                ? 'cargada, termina en ' . substr($clave, -4)
                : 'cargada';
        }

        return $payload;
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
     * Aplica la acción `user_setup` del panel: pasa por el candado y, si corresponde, llama al sistema del cliente.
     *
     * 🔴 El user setup hace `migrate:fresh` del otro lado: re-aplicarlo sobre un negocio que opera le BORRA TODO. Antes el `force` de
     * este botón saltaba el candado de "ya se aplicó" y NADA más (y sin `force` con el candado vacío no se miraba si el sistema ya
     * operaba). Ahora, en este orden (misión `puertas-del-user-setup`, 6/10/2026):
     *  1. Los DUROS (formulario, etapa 2 o más, última instalación completada, nada en curso) no se saltean ni con `force`.
     *  2. Si no falla ninguna protección, es el camino de siempre: se llama y, si salió bien, se cierra el candado.
     *  3. Si alguna falla y no hay `force`, no se llama: el mensaje de siempre si lo único que falla es el candado, o uno que dice qué.
     *  4. Con `force`: hace falta el nombre del cliente (`confirmacion_requerida`) y, si hay señales de que el sistema está EN USO,
     *     reconocerlo (`falta_confirmar_sistema_en_uso`). Recién ahí se llama con `$confirmado_por_una_persona = true`: el único lugar
     *     del admin que lo pasa.
     *
     * @param Implementation $implementation      La implementación (con su cliente y sus etapas).
     * @param bool           $force               El panel pidió re-aplicar.
     * @param string|null    $confirm_client_name Lo que escribió la persona como nombre del cliente.
     * @param bool           $confirm_live_system La persona reconoció que el sistema está en uso.
     *
     * @return array{ok: bool, message: string, codigo?: string}
     */
    private function execute_user_setup(Implementation $implementation, bool $force, ?string $confirm_client_name, bool $confirm_live_system): array
    {
        // Lo que dice el candado: qué falla y de qué clase.
        $evaluacion = $this->candado->evaluar_para_el_panel($implementation);

        // 1. 🔴 Los duros se exigen SIEMPRE, incluso con force: forzar saltea una protección, no la condición de que la API tiene que responder.
        if (count($evaluacion['duros']) > 0) {
            return ['ok' => false, 'message' => $evaluacion['duros'][0]['detalle']];
        }

        // 2. Nada que forzar: el camino de siempre.
        if (count($evaluacion['forzables']) === 0) {
            return $this->llamar_y_cerrar_el_candado($implementation, false);
        }

        // 3. Algo frena y no se pidió forzar.
        if (! $force) {
            return ['ok' => false, 'message' => $this->mensaje_sin_forzar($implementation, $evaluacion['forzables'])];
        }

        // El cliente (existe: sin cliente falla el duro de arriba) y su nombre.
        $client = $implementation->client ?? Client::find($implementation->client_id);
        $nombre = $this->candado->nombre_para_confirmar($client);

        // 4a. 🔴 La confirmación por nombre. Se pide en la API y no solo en la pantalla: un panel viejo (una pestaña sin recargar) que
        // fuerza sin nombre recibe este 422 en el mismo modal, que lo manda a recargar. Es el freno funcionando, no una ruptura.
        $escrito = trim((string) $confirm_client_name);

        if ($escrito === '') {
            return [
                'ok'      => false,
                'codigo'  => 'confirmacion_requerida',
                'message' => 'Falta la confirmación: volver a aplicar la configuración le BORRA toda la base de datos a «' . $nombre . '» y la arma de nuevo con '
                    . 'los datos del formulario. Para hacerlo hay que escribir el nombre del cliente. Si el panel no te lo pidió, recargá el panel: la versión '
                    . 'nueva pide la confirmación. No se aplicó nada.',
            ];
        }

        if (! $this->candado->confirma_el_nombre($client, $confirm_client_name)) {
            return [
                'ok'      => false,
                'codigo'  => 'confirmacion_requerida',
                'message' => 'El nombre que escribiste no coincide con el del cliente («' . $nombre . '»). Volver a aplicar la configuración le BORRA toda la base de '
                    . 'datos: tiene que escribirse el nombre exacto. No se aplicó nada.',
            ];
        }

        // 4b. Con señales de que el sistema está EN USO, además del nombre hay que reconocerlo.
        if (count($evaluacion['senales_de_uso']) > 0 && ! $confirm_live_system) {
            return [
                'ok'      => false,
                'codigo'  => 'falta_confirmar_sistema_en_uso',
                'message' => '«' . $nombre . '» tiene un sistema en uso: ' . implode(' ', $evaluacion['senales_de_uso']) . ' Para volver a aplicar la configuración hay '
                    . 'que reconocer, además de escribir el nombre, que se van a perder todos sus datos (confirm_live_system). No se aplicó nada.',
            ];
        }

        // Confirmado por una persona: el candado del punto de llamada no frena, y lo salteado queda en el log.
        return $this->llamar_y_cerrar_el_candado($implementation, true);
    }

    /**
     * Llama al sistema del cliente y, si salió bien, cierra el candado (`user_setup_executed_at`) y registra la acción para los checklists.
     *
     * @param Implementation $implementation              La implementación.
     * @param bool           $confirmado_por_una_persona  true = una persona confirmó el re-aplicado (ver `trigger_user_setup()`).
     *
     * @return array{ok: bool, message: string}
     */
    private function llamar_y_cerrar_el_candado(Implementation $implementation, bool $confirmado_por_una_persona): array
    {
        $result = $this->user_setup_service->trigger_user_setup($implementation, null, $confirmado_por_una_persona);

        if ($result['ok']) {
            // Registrar el momento de aplicación (lock) y la acción para los checklists.
            $this->candado->marcar_aplicado($implementation, 'panel');
        }

        return $result;
    }

    /**
     * El estado del botón `user_setup` para `state()`: si está bloqueado, por qué, si se puede forzar y los datos de la confirmación fuerte.
     *
     *  - Falla un DURO: `blocked`, el `detalle` del primero (el texto de siempre del gate) y `can_force` en false.
     *  - Fallan solo FORZABLES: `blocked` y `can_force` en true; la razón es el texto de siempre si lo único que falla es el candado
     *    (compatibilidad con el panel viejo) o una frase corta con la cantidad de motivos.
     *  - No falla nada: no está bloqueado.
     *
     * 🔴 Las claves de `confirmacion` son el CONTRATO con el SPA y son aditivas (un SPA viejo las ignora): `force_requires_confirmation`
     * (= `can_force`), `force_confirm_name` (lo que hay que escribir), `force_reasons` (el `detalle` de cada forzable), `live_system`
     * (hay señales de que el sistema está en uso: hay que reconocerlo) y `live_system_reasons` (esas señales).
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array{blocked: bool, blocked_reason: string|null, can_force: bool, confirmacion: array<string, mixed>}
     */
    private function estado_del_boton_user_setup(Implementation $implementation): array
    {
        // Lo que dice el candado.
        $evaluacion = $this->candado->evaluar_para_el_panel($implementation);

        // El cliente y el nombre que hay que escribir para confirmar (vacío si el cliente ya no existe: falla el duro).
        $client = $implementation->client ?? Client::find($implementation->client_id);

        // Por qué el aplicado normal está frenado: el detalle de cada forzable.
        $force_reasons = [];
        foreach ($evaluacion['forzables'] as $forzable) {
            $force_reasons[] = $forzable['detalle'];
        }

        $blocked        = false;
        $blocked_reason = null;
        $can_force      = false;

        if (count($evaluacion['duros']) > 0) {
            // No se puede aplicar todavía, ni forzando.
            $blocked        = true;
            $blocked_reason = $evaluacion['duros'][0]['detalle'];
        } elseif (count($evaluacion['forzables']) > 0) {
            // Se puede forzar, con la confirmación fuerte.
            $blocked        = true;
            $can_force      = true;
            $blocked_reason = $this->motivo_del_bloqueo($implementation, $evaluacion['forzables']);
        }

        return [
            'blocked'        => $blocked,
            'blocked_reason' => $blocked_reason,
            'can_force'      => $can_force,
            'confirmacion'   => [
                'force_requires_confirmation' => $can_force,
                'force_confirm_name'          => $client !== null ? $this->candado->nombre_para_confirmar($client) : '',
                'force_reasons'               => $force_reasons,
                'live_system'                 => count($evaluacion['senales_de_uso']) > 0,
                'live_system_reasons'         => $evaluacion['senales_de_uso'],
            ],
        ];
    }

    /**
     * ¿Lo ÚNICO que frena es el candado de "ya se aplicó" (y a lo sumo que la implementación ya pasó de la etapa 2)? Es el "re-aplicar"
     * clásico de siempre, sin ninguna señal de que el sistema esté en uso.
     *
     * @param array<int, array<string, mixed>> $forzables Las protecciones que fallaron.
     *
     * @return bool
     */
    private function solo_frena_el_candado(array $forzables): bool
    {
        // Los nombres de lo que falla.
        $nombres = [];
        foreach ($forzables as $forzable) {
            $nombres[] = $forzable['chequeo'];
        }

        return in_array('sin_aplicar_antes', $nombres, true) && count(array_diff($nombres, ['sin_aplicar_antes', 'etapa_2'])) === 0;
    }

    /**
     * La razón con la que el panel muestra el botón bloqueado cuando solo fallan protecciones (se puede forzar).
     *
     * @param Implementation                   $implementation La implementación.
     * @param array<int, array<string, mixed>> $forzables      Las protecciones que fallaron.
     *
     * @return string
     */
    private function motivo_del_bloqueo(Implementation $implementation, array $forzables): string
    {
        // El de siempre: lo único que falla es el candado, y el panel viejo ya lo mostraba así.
        if ($this->solo_frena_el_candado($forzables)) {
            return 'El UserSetup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i') . '. Usá "Forzar" para volver a aplicarlo.';
        }

        // Cuántos motivos hay.
        $cantidad = count($forzables);

        return 'El sistema del cliente ya se configuró o ya opera (' . $cantidad . ($cantidad === 1 ? ' motivo' : ' motivos')
            . '): para aplicarlo de nuevo hay que forzarlo y confirmar.';
    }

    /**
     * El mensaje cuando algo frena y NO se pidió forzar: el de siempre si lo único que falla es el candado, o uno que dice qué.
     *
     * @param Implementation                   $implementation La implementación.
     * @param array<int, array<string, mixed>> $forzables      Las protecciones que fallaron.
     *
     * @return string
     */
    private function mensaje_sin_forzar(Implementation $implementation, array $forzables): string
    {
        // El de siempre: lo único que falla es el candado.
        if ($this->solo_frena_el_candado($forzables)) {
            return 'El UserSetup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i')
                . ". Reintentá con \"Forzar\" si necesitás re-aplicarlo.";
        }

        // Lo que falló, con su detalle.
        $detalles = [];
        foreach ($forzables as $forzable) {
            $detalles[] = $forzable['detalle'];
        }

        return 'No se aplicó la configuración: el sistema del cliente ya se configuró o ya opera (' . $this->candado->frase_de_bloqueos($forzables)
            . '). Para aplicarla de nuevo hay que forzarla y confirmar el nombre del cliente. ' . implode(' ', $detalles);
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
