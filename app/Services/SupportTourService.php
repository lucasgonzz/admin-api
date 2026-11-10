<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupportTourService
{
    private const PATH = 'api/admin-sync/support/tours';
    public const INVITACION = 'Querés que te prepare un tour con Robotito?';

    public const AVISO = 'Te mandé a Robotito a tu sistema para que te lo muestre. Tocá su aviso en la barra de navegación para empezar.';

    public const TEMA_REVERTIR_IMPORTACION = 'revertir_importacion_articulos';
    public const TEMA_DINAMICO = 'guia_dinamica';
    public const MAX_IMPORT_HISTORY_ID = 9007199254740991;

    public const TEMAS = [
        'crear_ticket' => 3,
        'buscar_articulo_nombre' => 3,
        'buscar_articulo_codigo' => 1,
        'centro_recursos' => 2,
        self::TEMA_REVERTIR_IMPORTACION => 3,
    ];

    public function __construct(ClientEmpresaApiUrlResolver $urls)
    {
        $this->urls = $urls;
    }

    private $urls;

    /** Solo se admiten recorridos y parámetros que Empresa conoce. */
    public static function guion($datos): ?array
    {
        if (! is_array($datos) || ! is_string($datos['tema'] ?? null)
            || (! isset(self::TEMAS[$datos['tema']]) && $datos['tema'] !== self::TEMA_DINAMICO)
            || ! is_string($datos['titulo'] ?? null)) {
            return null;
        }

        $titulo = trim($datos['titulo']);
        if ($titulo === '' || mb_strlen($titulo) > 120) {
            return null;
        }

        $guion = ['tema' => $datos['tema'], 'titulo' => $titulo];
        if ($guion['tema'] === self::TEMA_DINAMICO) {
            $pasos = self::pasos_dinamicos($datos);
            return $pasos === null ? null : $guion + ['pasos' => $pasos];
        }
        if ($guion['tema'] === self::TEMA_REVERTIR_IMPORTACION) {
            $id = $datos['import_history_id'] ?? null;
            if (! is_int($id) || $id < 1 || $id > self::MAX_IMPORT_HISTORY_ID) {
                return null;
            }
            $guion['import_history_id'] = $id;
        } elseif (array_key_exists('import_history_id', $datos)) {
            return null;
        }

        if (! array_key_exists('textos', $datos)) {
            return $guion;
        }

        $textos = $datos['textos'];
        $cantidad = self::TEMAS[$guion['tema']];
        if (! is_array($textos) || array_keys($textos) !== range(0, $cantidad - 1)) {
            return null;
        }
        foreach ($textos as $texto) {
            if (! is_string($texto) || trim($texto) === '' || mb_strlen(trim($texto)) > 240) {
                return null;
            }
        }

        $guion['textos'] = array_map('trim', $textos);
        return $guion;
    }

    private static function pasos_dinamicos(array $datos): ?array
    {
        $pasos = $datos['pasos'] ?? null;
        if (array_key_exists('textos', $datos) || array_key_exists('import_history_id', $datos)
            || ! is_array($pasos) || count($pasos) < 1 || count($pasos) > 8 || array_keys($pasos) !== range(0, count($pasos) - 1)) {
            return null;
        }

        $normalizados = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso) || array_diff(array_keys($paso), ['clave', 'texto', 'destino']) !== []
                || ! is_string($paso['clave'] ?? null) || ! preg_match('/^[a-z0-9_.]{1,80}$/D', $paso['clave'])
                || ! is_string($paso['texto'] ?? null) || trim($paso['texto']) === '' || mb_strlen(trim($paso['texto'])) > 240) {
                return null;
            }

            $normalizado = ['clave' => $paso['clave'], 'texto' => trim($paso['texto'])];
            if (array_key_exists('destino', $paso)) {
                if (! is_int($paso['destino']) || $paso['destino'] < 1 || $paso['destino'] > self::MAX_IMPORT_HISTORY_ID) {
                    return null;
                }
                $normalizado['destino'] = $paso['destino'];
            }
            $normalizados[] = $normalizado;
        }
        return $normalizados;
    }

    public static function tiene_invitacion(string $texto): bool
    {
        $lineas = preg_split('/\R/u', trim($texto));
        return ltrim(trim((string) end($lineas)), '¿') === self::INVITACION;
    }

    public static function quitar_invitacion(string $texto): string
    {
        if (! self::tiene_invitacion($texto)) {
            return $texto;
        }

        $lineas = preg_split('/\R/u', trim($texto));
        array_pop($lineas);
        return rtrim(implode("\n", $lineas));
    }

    public function guion_aceptado(SupportTicket $ticket, SupportMessage $entrante): ?array
    {
        $invitacion = $this->invitacion_aceptada($ticket, $entrante);
        return self::guion($invitacion->erp_auto_reply['tour_invitation'] ?? null);
    }

    public function solicitud_aceptada(SupportTicket $ticket, SupportMessage $entrante): ?SupportMessage
    {
        $invitacion = $this->invitacion_aceptada($ticket, $entrante);
        if ($invitacion === $entrante || ($invitacion->erp_auto_reply['tour_requested'] ?? null) === true) {
            return $invitacion;
        }

        return null;
    }

    public static function entrante_admite_robotito(SupportTicket $ticket, ?SupportMessage $entrante): bool
    {
        if ($entrante === null || (int) $entrante->support_ticket_id !== (int) $ticket->id
            || $entrante->sender_type !== 'user' || ! SupportMessage::es_mensaje_real($entrante)) {
            return false;
        }

        // Identidad acreditada y presencia de Robotito son capacidades distintas del ERP.
        return $ticket->source === 'whatsapp' || ($ticket->source === 'erp'
            && ($entrante->erp_auto_reply['inbound_version'] ?? null) === SupportMessage::VERSION_ROBOTITO_ERP);
    }

    private function invitacion_aceptada(SupportTicket $ticket, SupportMessage $entrante): ?SupportMessage
    {
        if (! self::entrante_admite_robotito($ticket, $entrante) || ! $ticket->mensaje_confiable_para_codex($entrante)) {
            return null;
        }

        // La pregunta del formulario de una guía en curso autoriza recalcularla. La marca
        // se guarda solo al recibir el mensaje firmado del titular y no se infiere del texto.
        if ($ticket->source === 'erp' && $ticket->status === 'open' && $entrante->kind === 'text'
            && ($entrante->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] ?? null) === true
            && ($entrante->erp_auto_reply[SupportMessage::MARCA_PREGUNTA_ROBOTITO] ?? null) === true) {
            return $entrante;
        }

        $anteriores = SupportMessage::where('support_ticket_id', $ticket->id)->where('id', '<', $entrante->id);
        SupportMessage::apply_mensaje_real_conditions($anteriores, 'support_messages');
        $invitacion = (clone $anteriores)->latest('id')->first();
        $respuesta = ltrim(Str::ascii(mb_strtolower(trim((string) $entrante->body))), '! ');
        $cita = $entrante->reply_to_message_id;
        if ($invitacion !== null && $invitacion->sender_type === 'user') {
            if ($cita !== null) {
                $invitacion = $anteriores->where('sender_type', 'admin')->latest('id')->first();
            } elseif (preg_match('/^(?:por favor|porfa|por fi)[.!, ]*$/D', $respuesta) && $invitacion->reply_to_message_id === null
                && self::entrante_admite_robotito($ticket, $invitacion) && $ticket->mensaje_confiable_para_codex($invitacion)) {
                $respuesta = Str::ascii(mb_strtolower(trim((string) $invitacion->body))) . ' ' . $respuesta;
                $invitacion = $anteriores->where('id', '<', $invitacion->id)->latest('id')->first();
            }
        }
        if ($invitacion === null || $invitacion->sender_type !== 'admin'
            || ! $ticket->mensaje_confiable_para_codex($invitacion)
            || ($cita !== null && (int) $cita !== (int) $invitacion->id)) {
            return null;
        }

        $entregada = $ticket->source === 'erp' ? $invitacion->synced_to_client_at !== null
            : trim((string) $invitacion->whatsapp_message_id) !== '' && $invitacion->remote_delivery_status !== 'not_received'
                && $invitacion->whatsapp_delivery_status !== 'fallido';
        $guion = self::guion($invitacion->erp_auto_reply['tour_invitation'] ?? null);
        $uuid_usado = $invitacion->erp_auto_reply['tour_acceptance_uuid'] ?? null;
        $solicitud = ($invitacion->erp_auto_reply['tour_requested'] ?? null) === true;
        if (! $entregada || ($guion === null && ! $solicitud) || ($uuid_usado !== null && $uuid_usado !== $entrante->uuid)
            || ! self::tiene_invitacion((string) $invitacion->body)) {
            return null;
        }

        // Una respuesta breve solo es inequívoca si la invitación era la única pregunta.
        if (preg_match('/^(?:(?:si|dale|ok)[,.! ]+)?(?:preparame|mandame|pasame|enviame|quiero(?: hacer)?) (?:el|un) (?:tour|recorrido)(?: de robotito)?(?:[,.! ]+(?:por favor|porfa|gracias))*[.!, ]*$/D', $respuesta)) {
            return $invitacion;
        }

        $afirmativa = ! preg_match('/[?¿]/u', preg_replace('~https?://\S+~u', '', self::quitar_invitacion((string) $invitacion->body)))
            && preg_match('/^(?:si|dale|de una|de acuerdo|(?:ok|bueno)[,.! ]+dale)(?:[,.! ]+(?:si|dale|quiero|mandamelo|preparamelo|pasamelo|enviamelo|por favor|porfa|por fi|gracias|me (?:sirve|vendria bien|gustaria)|quiero que (?:me )?lo prepares))*[.!, ]*$/D', $respuesta);
        return $afirmativa ? $invitacion : null;
    }

    public function objetivo_disponible(SupportTicket $ticket, SupportMessage $entrante, int $import_history_id): bool
    {
        $ticket->loadMissing('client');
        $persona = $ticket->source === 'whatsapp' ? $this->destinatario_whatsapp($ticket, $entrante) : (int) $ticket->client_user_id;
        if ($persona === false) {
            return false;
        }
        return $this->buscar_importaciones($ticket, $persona ?? (int) optional($ticket->client)->user_id, $import_history_id) !== [];
    }

    public function catalogo_dinamico(SupportTicket $ticket, SupportMessage $entrante, ?int $id): array
    {
        $query = $this->query_persona($ticket, $entrante);
        if ($query === null) {
            return [];
        }
        if ($id !== null) {
            $query['import_history_id'] = $id;
        }
        return $this->consultar_empresa($ticket, '/catalogo', $query);
    }

    private function query_persona(SupportTicket $ticket, SupportMessage $entrante): ?array
    {
        if ($ticket->source === 'whatsapp') {
            $persona = $this->destinatario_whatsapp($ticket, $entrante);
        } else {
            $persona = (int) $ticket->client_user_id;
        }
        if ($persona === false || ($persona !== null && $persona < 1)) {
            return null;
        }

        // Un dueño de WhatsApp ya verificado se resuelve en Empresa, como al enviarle el tour.
        return $persona === null ? [] : ['user_id' => $persona];
    }

    private function buscar_importaciones(SupportTicket $ticket, int $persona, ?int $import_history_id): array
    {
        if ($persona < 1) {
            return [];
        }
        $query = ['user_id' => $persona];
        if ($import_history_id !== null) {
            $query['import_history_id'] = $import_history_id;
        }
        $modelos = $this->consultar_empresa($ticket, '/importaciones/candidatas', $query)['model'] ?? null;
        if (! is_array($modelos)) {
            return [];
        }

        $candidatas = [];
        foreach (array_slice($modelos, 0, 30) as $modelo) {
            if (! is_array($modelo) || ! is_int($modelo['id'] ?? null) || $modelo['id'] < 1
                || ! is_string($modelo['created_at'] ?? null)
                || ($import_history_id !== null && $modelo['id'] !== $import_history_id)) {
                continue;
            }
            $candidatas[] = array_intersect_key($modelo, array_flip(['id', 'created_at', 'provider_id', 'provider_name']));
        }
        return $candidatas;
    }

    private function consultar_empresa(SupportTicket $ticket, string $ruta, array $query): array
    {
        $ticket->loadMissing('client');
        $cliente = $ticket->client;
        if ($cliente === null || ! $cliente->is_active || trim((string) $cliente->api_key) === '') {
            return [];
        }
        $url = $this->urls->admin_sync_url($cliente, self::PATH . $ruta);
        if ($url === '') {
            return [];
        }

        try {
            $respuesta = $this->http($cliente)->get($url, $query);
        } catch (ConnectionException $exception) {
            return [];
        }

        $datos = $respuesta->json();
        return $respuesta->successful() && is_array($datos) ? $datos : [];
    }

    /** El mensaje entrante es la clave idempotente y fija el destinatario. */
    public function ofrecer(SupportTicket $ticket, SupportMessage $entrante, array $guion): array
    {
        $ticket->loadMissing('client');
        $cliente = $ticket->client;
        if ($cliente === null || ! $cliente->is_active || ! Str::isUuid((string) $ticket->uuid)
            || ! Str::isUuid((string) $entrante->uuid) || (int) $entrante->support_ticket_id !== (int) $ticket->id
            || $entrante->sender_type !== 'user') {
            return $this->fallo(409, 'tour_recipient_unresolved', 'No se pudo verificar la cuenta de quien hizo la consulta.');
        }

        $invitacion = $this->invitacion_aceptada($ticket, $entrante);
        if ($invitacion === null) {
            return $this->fallo(409, 'tour_consent_required', 'La persona todavía no aceptó una invitación enviada en este ticket.');
        }
        $aceptado = self::guion($invitacion->erp_auto_reply['tour_dynamic_plan'] ?? $invitacion->erp_auto_reply['tour_invitation'] ?? null);
        if ($aceptado !== $guion) {
            return $this->fallo(409, 'tour_invitation_mismatch', 'El recorrido no coincide con el que aceptó la persona.');
        }

        $es_whatsapp = $ticket->source === 'whatsapp';
        $usuario = $es_whatsapp ? $this->destinatario_whatsapp($ticket, $entrante) : (int) $ticket->client_user_id;
        if ($usuario === false || (! $es_whatsapp && $usuario < 1)) {
            return $this->fallo(409, 'tour_recipient_unresolved', 'No se pudo verificar la cuenta de quien hizo la consulta.');
        }

        $body = $guion + [
            'uuid' => $entrante->uuid,
            'ticket_uuid' => $ticket->uuid,
            'source' => $es_whatsapp ? 'whatsapp' : 'sistema',
        ];
        if ($usuario !== null) {
            $body['user_id'] = $usuario;
            if ($es_whatsapp) {
                $body['recipient_phone'] = (string) $entrante->whatsapp_remitente_crudo;
            }
        }

        $url = $this->urls->admin_sync_url($cliente, self::PATH);
        if ($url === '' || trim((string) $cliente->api_key) === '') {
            return $this->fallo(503, 'tour_unavailable', 'El sistema del comercio todavía no está conectado a Robotito.');
        }

        // Las dos entradas mantienen bloqueado el ticket. Si llega otro «sí», no puede
        // recrear la misma invitación con otro UUID, aunque la confirmación HTTP se pierda.
        $invitacion->erp_auto_reply = $invitacion->erp_auto_reply + ['tour_acceptance_uuid' => $entrante->uuid];
        $invitacion->save();

        try {
            $respuesta = $this->http($cliente)->post($url, $body);
        } catch (ConnectionException $exception) {
            return $this->fallo(502, 'tour_unconfirmed', 'El sistema no confirmó que recibió el tour.');
        }

        if ($respuesta->successful() && $respuesta->json('ok') === true && $respuesta->json('uuid') === $entrante->uuid) {
            return ['ok' => true, 'uuid' => $entrante->uuid];
        }
        if (in_array($respuesta->status(), [404, 405], true) && ! $respuesta->json('code')) {
            return $this->fallo(409, 'tour_unsupported', 'El sistema del comercio todavía no admite tours de soporte.');
        }
        if (in_array($respuesta->status(), [404, 409, 422], true)) {
            return $this->fallo(422, 'tour_rejected', 'El sistema del comercio rechazó el destinatario o el recorrido.');
        }

        return $this->fallo(502, 'tour_unconfirmed', 'El sistema no confirmó que recibió el tour.');
    }

    public function seguimiento(SupportTicket $ticket): array
    {
        $ticket->loadMissing('client');
        $cliente = $ticket->client;
        if ($cliente === null || ! Str::isUuid((string) $ticket->uuid)) {
            return ['model' => [], 'available' => false];
        }
        $url = $this->urls->admin_sync_url($cliente, self::PATH . '/' . $ticket->uuid);
        if ($url === '' || trim((string) $cliente->api_key) === '') {
            return ['model' => [], 'available' => false];
        }

        try {
            $respuesta = $this->http($cliente)->get($url);
        } catch (ConnectionException $exception) {
            return $this->fallo(502, 'tour_unavailable', 'No pudimos consultar los tours del comercio.');
        }
        if (in_array($respuesta->status(), [404, 405], true) && ! $respuesta->json('code')) {
            return ['model' => [], 'available' => false];
        }
        $model = $respuesta->json('model');
        if (! $respuesta->successful() || ! is_array($model)) {
            return $this->fallo(502, 'tour_unavailable', 'No pudimos consultar los tours del comercio.');
        }

        return ['model' => $model, 'available' => true];
    }

    private function http(Client $cliente)
    {
        return Http::withHeaders(['X-Admin-Api-Key' => $cliente->api_key])
            ->acceptJson()->timeout(6)->withOptions(['allow_redirects' => false]);
    }

    /** null identifica al dueño verificado. false indica identidad ambigua o sin usuario sincronizado. */
    private function destinatario_whatsapp(SupportTicket $ticket, SupportMessage $entrante)
    {
        $remitente = (string) $entrante->whatsapp_remitente_crudo;
        if (! WhatsappNormalizer::equals_estricto((string) $ticket->whatsapp_phone, $remitente)) {
            return false;
        }

        $empleados = ClientEmployee::whereNotNull('phone')->whereHas('client', function ($consulta) {
            $consulta->where('is_active', true);
        })->get(['id', 'client_id', 'phone', 'empresa_employee_id'])->filter(function (ClientEmployee $empleado) use ($remitente) {
            return WhatsappNormalizer::equals_estricto((string) $empleado->phone, $remitente);
        });
        $duenos = Client::where('is_active', true)->get(['id', 'phone', 'asistente_phone'])->filter(function (Client $cliente) use ($remitente) {
            return WhatsappNormalizer::equals_estricto((string) $cliente->phone, $remitente)
                || WhatsappNormalizer::equals_estricto((string) $cliente->asistente_phone, $remitente);
        });

        if ($ticket->client_employee_id === null) {
            return $empleados->isEmpty() && $duenos->count() === 1
                && (int) $duenos->first()->id === (int) $ticket->client_id ? null : false;
        }

        $empleado = $empleados->first();
        if ($empleados->count() !== 1 || $duenos->isNotEmpty() || (int) $empleado->id !== (int) $ticket->client_employee_id
            || (int) $empleado->client_id !== (int) $ticket->client_id || (int) $empleado->empresa_employee_id < 1) {
            return false;
        }

        return (int) $empleado->empresa_employee_id;
    }

    private function fallo(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
