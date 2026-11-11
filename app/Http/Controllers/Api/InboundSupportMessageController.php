<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportMessageRead;
use App\Events\SupportMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAgentesIdentity;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InboundSupportMessageController extends Controller
{
    /**
     * Documentos que el negocio puede mandar por Soporte de su sistema además de audio e imagen (misión cruzada
     * prueba-e2e-campana-imperio, 11/11/2026): extensión → mime con el que se guardan, el mismo con el que llegan
     * por WhatsApp. Es lo que después mira la importación del ticket (`PruebaImportacionService`) y lo que lee
     * Martín en `messages[].attachments[].mime`.
     *
     * @var array<string, string>
     */
    const DOCUMENTOS = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv',
        'pdf'  => 'application/pdf',
    ];

    /**
     * Recibe mensaje desde empresa-api y lo persiste en admin-api.
     *
     * Si el ticket es nuevo nace SIN operador (`assigned_admin_id = null`): desde el 26/10/2026 los
     * tickets de soporte no pertenecen a nadie.
     */
    public function store(Request $request)
    {
        // Cliente resuelto por middleware admin.inbound.key.
        $client = $request->attributes->get('sync_client');
        if (is_null($client)) {
            return response()->json(['error' => 'client not resolved'], 401);
        }

        [$message, $creado] = $this->guardar_entrante($request, $client);
        $message = SupportMessage::where('id', $message->id)->withAll()->firstOrFail();
        if ($creado) {
            SupportMessageReceived::dispatch_after_commit($message->id);
            /* El respaldo de la prueba gratuita por si Martín está caído (misión prueba-ayuda-unificada-en-soporte,
             * 10/11/2026): el lead en prueba escribe en Soporte del sistema desde «Seguir con Soporte». Solo programa
             * un job que decide al correr; para cualquier otro ticket no hace nada y nunca lanza. */
            if ($message->sender_type === 'user' && $message->ticket !== null) {
                app(\App\Services\Prueba\PruebaRespaldoSoporteService::class)->programar($message->ticket, (int) $message->id);
            }
        }
        if ($message->ticket !== null && ! $message->ticket->agentes_erp
            && $this->entrada_robotito_valida($request, $client, $message->ticket)) {
            $this->programar_robotito($message);
        }
        $datos = ['model' => $message];
        if ($message->ticket !== null && $message->ticket->sistema_exclusivo_at !== null) {
            $datos['sistema_exclusivo_at'] = $message->ticket->sistema_exclusivo_at->toIso8601String();
        }
        return response()->json($datos, $creado ? 201 : 200);
    }

    private function guardar_entrante(Request $request, Client $client): array
    {
        return DB::transaction(function () use ($request, $client) {
            // El entrante compite por la misma fila que la respuesta del agente y la aprobación.
            // support_messages no tiene una clave foránea que bloquee esa inserción por sí sola.
            $ticket_uuid = $request->input('ticket_uuid');
            $ticket = SupportTicket::where('uuid', $ticket_uuid)->lockForUpdate()->first();
            $transicion_desde_legado = $ticket !== null && $ticket->es_erp_sin_identidad_verificada();
            $message_uuid = $request->input('message_uuid');
            $traspaso = $request->boolean('traspasar_a_sistema');
            $version_agentes = in_array($request->input('agentes_version'), [1, '1'], true);
            $usuario = (int) $request->input('client_user_id');
            $protegido = $ticket !== null && ($ticket->agentes_erp || $ticket->sistema_exclusivo_at !== null);
            $requiere_firma = $traspaso || $version_agentes || $protegido;
            if ($requiere_firma) {
                abort_unless(SupportAgentesIdentity::firmada($request, $client), 401);
                $request->validate([
                    'ticket_uuid' => 'required|uuid', 'message_uuid' => 'required|uuid',
                    'client_user_id' => 'required|integer|min:1', 'sender_user_id' => 'required|integer|min:1',
                ]);
                abort_unless($usuario === (int) $request->input('sender_user_id') && $request->input('sender_type') === 'user', 403);
            }
            abort_if($ticket !== null && (int) $ticket->client_id !== (int) $client->id, 404);
            if ($traspaso || ($ticket !== null && $ticket->sistema_exclusivo_at !== null)) {
                $request->validate(['es_dueno' => 'required|boolean']);
                abort_unless($ticket !== null && SupportAgentesIdentity::puede_ver($ticket, $client, $usuario, $request->boolean('es_dueno')), 404);
            } else {
                abort_if($ticket !== null && $ticket->source !== 'erp', 404);
                abort_if(($version_agentes || $protegido) && $ticket !== null && (int) $ticket->client_user_id !== $usuario, 404);
            }

            $existing = SupportMessage::where('uuid', $message_uuid)->first();
            if ($existing !== null) {
                abort_unless($ticket !== null && (int) $existing->support_ticket_id === (int) $ticket->id, 404);
                abort_if(($traspaso || $version_agentes || $protegido) && $existing->sender_type !== 'user', 404);
                return [$existing, false];
            }

            if ($ticket === null) {
                $ticket = new SupportTicket([
                    'uuid' => $ticket_uuid,
                    'client_id' => $client->id,
                    'client_user_id' => (int) $request->input('client_user_id'),
                    'assigned_admin_id' => null,
                    'name' => $request->input('ticket_name'),
                    // El asunto que escribió el cliente en su sistema (misión ficha-del-cliente): Martín no lo pisa.
                    'name_source' => trim((string) $request->input('ticket_name')) !== '' ? 'cliente' : null,
                    'status' => $request->input('ticket_status', 'open'),
                    'source' => 'erp',
                    'opened_at' => now(),
                ]);
            }
            if ($traspaso && $ticket->sistema_exclusivo_at === null) {
                abort_unless($ticket->status === 'open', 409, 'El ticket de WhatsApp ya está cerrado.');
                $ticket->source = 'erp';
                $ticket->client_user_id = $usuario;
                $ticket->sistema_exclusivo_at = now();
                // El monitor conservará su hilo, pero la respuesta reservada por WhatsApp quedó vieja.
                SupportCodexCase::where('support_ticket_id', $ticket->id)->update([
                    'lease_hash' => null,
                    'lease_expires_at' => null,
                ]);
            }
            if ($requiere_firma) {
                abort_unless($ticket->status === 'open', 409, 'El ticket está cerrado.');
                if ($ticket->sistema_exclusivo_at === null && $ticket->client_employee_id === null
                    && $usuario !== (int) $client->user_id) {
                    $ticket->client_employee_id = ClientEmployee::where('client_id', $client->id)
                        ->where('empresa_employee_id', $usuario)->value('id');
                }
                $ticket->agentes_erp = true;
            }
            if ($request->input('sender_type', 'user') === 'user') {
                $ticket->last_client_message_at = now();
            }

            // Actualiza snapshot de usuario remoto para listado lateral.
            $ticket->client_user_name = $request->input('sender_user_name', $ticket->client_user_name);
            $ticket->client_user_email = $request->input('sender_user_email', $ticket->client_user_email);
            $ticket->save();

            // Las marcas se guardan al crear el entrante. Un replay no puede acreditar
            // una fila que ya existe: su texto y sus adjuntos se recibieron en otra llamada.
            $datos = [
                'uuid' => $message_uuid,
                'support_ticket_id' => $ticket->id,
                'sender_type' => $request->input('sender_type', 'user'),
                'kind' => $request->input('kind', 'text'),
                'body' => $request->input('body'),
                'delivered_at' => now(),
                'synced_to_client_at' => now(),
            ];
            $marcas = [];
            if ($this->entrada_robotito_valida($request, $client, $ticket)) {
                $marcas['inbound_version'] = SupportMessage::VERSION_ROBOTITO_ERP;
                if ($request->input('robotito_followup') === true && $datos['kind'] === 'text'
                    && $usuario === (int) $ticket->client_user_id) {
                    $marcas[SupportMessage::MARCA_PREGUNTA_ROBOTITO] = true;
                    $pregunta = $request->input('robotito_question');
                    if (is_string($pregunta) && trim($pregunta) !== '' && mb_strlen($pregunta) <= 1500) {
                        $marcas['robotito_question'] = $pregunta;
                    }
                }
            }
            if ($datos['sender_type'] === 'user' && $usuario > 0 && $usuario === (int) $ticket->client_user_id
                && $usuario === (int) $request->input('sender_user_id')
                && ($requiere_firma || SupportAgentesIdentity::firmada($request, $client))) {
                $marcas[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] = true;
                if ($transicion_desde_legado && $ticket->agentes_erp) {
                    $marcas[SupportMessage::MARCA_TRANSICION_ERP_PARA_CODEX] = true;
                }
            }
            if ($marcas !== []) {
                $datos['erp_auto_reply'] = $marcas;
            }
            $message = SupportMessage::create($datos);
            $this->persistir_adjuntos($request, $message);
            if ($ticket->agentes_erp || SupportCodexSettings::manages($ticket)) {
                app(SupportCodexService::class)->invalidate_stale_approval($ticket);
            }
            return [$message, true];
        });
    }

    private function persistir_adjuntos(Request $request, SupportMessage $message): void
    {
        // Archivos binarios del multipart (prioridad sobre metadata remota).
        $attachments_files = $request->file('attachments_files', []);
        $has_uploaded_files = is_array($attachments_files) && count($attachments_files) > 0;

        if ($has_uploaded_files) {
            /* La metadata que acompaña a cada binario, en el mismo orden (trae `original_name`, opcional). */
            $metadata = array_values($this->parse_attachments_input($request->input('attachments', [])));

            foreach (array_values($attachments_files) as $indice => $uploaded_file) {
                $declarado = isset($metadata[$indice]) && is_array($metadata[$indice]) ? $metadata[$indice] : [];
                $nombre    = $this->nombre_original($uploaded_file, $declarado);
                $documento = $this->documento_reconocido($uploaded_file, $declarado, $nombre);
                $carpeta   = 'support_messages/' . $message->support_ticket_id;

                /* Un documento se guarda con SU extensión y su mime: la detección del servidor suele ver un .xlsx
                 * como un zip (y `store()` lo dejaría como `.zip`), y entonces la importación no lo reconoce. */
                $stored_path = $documento !== null
                    ? $uploaded_file->storeAs($carpeta, Str::random(40) . '.' . $documento, 'public')
                    : $uploaded_file->store($carpeta, 'public');

                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => 'public',
                    'path' => $stored_path,
                    'original_name' => $nombre,
                    'mime' => $documento !== null ? self::DOCUMENTOS[$documento] : $uploaded_file->getMimeType(),
                    'size' => $uploaded_file->getSize(),
                ]);
            }
        } else {
            $attachments = $this->parse_attachments_input($request->input('attachments', []));
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => $attachment['disk'] ?? 'public',
                    'path' => $attachment['path'] ?? '',
                    'original_name' => $this->nombre_saneado($attachment['original_name'] ?? null),
                    'mime' => $attachment['mime'] ?? null,
                    'size' => $attachment['size'] ?? null,
                ]);
            }
        }
    }

    /**
     * El nombre con el que el negocio subió el archivo: el `original_name` de la metadata si viene, o el del
     * multipart. Sin rutas, hasta 191 caracteres.
     *
     * @param \Illuminate\Http\UploadedFile $archivo
     * @param array<string, mixed>          $declarado
     *
     * @return string|null
     */
    private function nombre_original($archivo, array $declarado): ?string
    {
        $nombre = $this->nombre_saneado($declarado['original_name'] ?? null);

        return $nombre !== null ? $nombre : $this->nombre_saneado($archivo->getClientOriginalName());
    }

    /**
     * @param mixed $nombre
     *
     * @return string|null
     */
    private function nombre_saneado($nombre): ?string
    {
        if (! is_string($nombre)) {
            return null;
        }

        $nombre = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $nombre))));

        return $nombre === '' ? null : mb_substr($nombre, 0, 191);
    }

    /**
     * Si el archivo es uno de los {@see self::DOCUMENTOS}, su extensión. Se decide por el nombre original, por
     * el nombre del multipart o por el mime declarado, y se confirma contra los primeros bytes: un archivo que
     * dice ser un Excel y no lo es se guarda como hasta ahora (con lo que detecte el servidor).
     *
     * @param \Illuminate\Http\UploadedFile $archivo
     * @param array<string, mixed>          $declarado Metadata del adjunto (`mime`, `original_name`).
     * @param string|null                   $nombre
     *
     * @return string|null
     */
    private function documento_reconocido($archivo, array $declarado, ?string $nombre): ?string
    {
        $candidatas = [
            strtolower((string) pathinfo((string) $nombre, PATHINFO_EXTENSION)),
            strtolower((string) pathinfo((string) $archivo->getClientOriginalName(), PATHINFO_EXTENSION)),
        ];
        foreach ([$declarado['mime'] ?? null, $archivo->getClientMimeType()] as $mime) {
            $por_mime = array_search(strtolower(trim((string) strtok((string) $mime, ';'))), self::DOCUMENTOS, true);
            if ($por_mime !== false) {
                $candidatas[] = $por_mime;
            }
        }

        $inicio = (string) @file_get_contents((string) $archivo->getRealPath(), false, null, 0, 1024);

        foreach ($candidatas as $extension) {
            if (isset(self::DOCUMENTOS[$extension]) && self::firma_coincide($extension, $inicio)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Si los primeros bytes son los del tipo que dice la extensión.
     *
     * @param string $extension
     * @param string $inicio    Hasta 1 KB del principio del archivo.
     *
     * @return bool
     */
    private static function firma_coincide(string $extension, string $inicio): bool
    {
        if ($inicio === '') {
            return false;
        }
        if ($extension === 'xlsx') {
            return strncmp($inicio, "PK\x03\x04", 4) === 0;
        }
        if ($extension === 'xls') {
            return strncmp($inicio, "\xD0\xCF\x11\xE0", 4) === 0;
        }
        if ($extension === 'pdf') {
            return strpos(substr($inicio, 0, 16), '%PDF') !== false;
        }

        /* csv: texto plano, sin bytes nulos. */
        return strpos($inicio, "\0") === false;
    }

    private function entrada_robotito_valida(Request $request, Client $client, SupportTicket $ticket): bool
    {
        return in_array($request->input('robotito_support_version'), [
            SupportMessage::VERSION_ROBOTITO_ERP, (string) SupportMessage::VERSION_ROBOTITO_ERP,
        ], true)
            && SupportAgentesIdentity::firmada($request, $client)
            && Str::isUuid((string) $request->input('message_uuid')) && Str::isUuid((string) $ticket->uuid)
            && $ticket->source === 'erp' && $ticket->status === 'open' && $request->input('sender_type', 'user') === 'user'
            && (int) $ticket->client_user_id > 0 && (int) $ticket->client_user_id === (int) $request->input('sender_user_id');
    }

    private function programar_robotito(SupportMessage $message): void
    {
        $marca = $message->erp_auto_reply;
        if (($marca['inbound_version'] ?? null) !== SupportMessage::VERSION_ROBOTITO_ERP || isset($marca['scheduled_at'])) {
            return;
        }

        // Serializa un replay con cualquier otro entrante del mismo ticket: la consulta vieja
        // no puede cambiar el token de programación después de que se agendó la nueva.
        try {
            Cache::store('file')->lock('support-erp-inbound-' . $message->support_ticket_id, 900)->block(2, function () use ($message) {
                $message->refresh();
                $marca = $message->erp_auto_reply;
                $ticket = $message->ticket;
                if (isset($marca['scheduled_at']) || $ticket === null || $ticket->status !== 'open'
                    || SupportCodexSettings::manages($ticket) || $message->sender_type !== 'user') {
                    return;
                }
                $hay_mas_nuevo = SupportMessage::where('support_ticket_id', $ticket->id)
                    ->where('id', '>', $message->id)->where('is_ai_suggestion_draft', false)->exists();
                if ($hay_mas_nuevo) {
                    return;
                }

                app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $ticket->id, (int) $message->id);
                $marca['scheduled_at'] = now()->toIso8601String();
                $message->erp_auto_reply = $marca;
                $message->save();
            });
        } catch (\Throwable $exception) {
            if (! $exception instanceof LockTimeoutException) {
                Log::warning('No se pudo programar la atención web de soporte.', ['message_id' => $message->id]);
            }
            abort(503, 'La consulta quedó guardada y falta programar su atención. Reintentá el envío.');
        }
    }

    /**
     * Recibe confirmación de lectura desde empresa-api.
     */
    public function mark_read(Request $request)
    {
        $message = SupportMessage::where('uuid', $request->input('message_uuid'))->first();
        if (is_null($message)) {
            return response()->json(['error' => 'message not found'], 404);
        }

        $ticket = $message->ticket;
        if ($ticket !== null && $ticket->agentes_erp) {
            $this->validar_metadatos_agentes($request, $ticket);
        }
        $message->read_at = $request->input('read_at') ?: now();
        $message->save();

        // Notifica a operadores conectados (doble check / visto en mensajes salientes).
        $updated = SupportMessage::where('id', $message->id)->withAll()->first();
        if (!is_null($updated)) {
            event(new SupportMessageRead($updated->id));
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Recibe typing state de empresa-api.
     */
    public function typing(Request $request)
    {
        $ticket = SupportTicket::where('uuid', $request->input('ticket_uuid'))->first();
        if (is_null($ticket)) {
            return response()->json(['error' => 'ticket not found'], 404);
        }

        if ($ticket->agentes_erp) {
            $this->validar_metadatos_agentes($request, $ticket);
            abort_unless((int) $request->input('actor_id') === (int) $ticket->client_user_id && $request->input('actor_type') === 'user', 403);
        }

        $typing_state = SupportTypingState::firstOrNew([
            'support_ticket_id' => $ticket->id,
            'actor_type' => $request->input('actor_type', 'user'),
            'actor_id' => $request->input('actor_id'),
        ]);
        $typing_state->last_typing_at = now();
        $typing_state->save();

        return response()->json(['ok' => true], 200);
    }

    private function validar_metadatos_agentes(Request $request, SupportTicket $ticket): void
    {
        $client = $request->attributes->get('sync_client');
        abort_unless($client !== null && (int) $client->id === (int) $ticket->client_id
            && SupportAgentesIdentity::firmada($request, $client), 401);
    }

    /**
     * Normaliza el campo attachments del request (array JSON o string JSON desde multipart).
     *
     * @param mixed $raw Valor crudo del request.
     * @return array<int, array<string, mixed>>
     */
    private function parse_attachments_input($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }
}

