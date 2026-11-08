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

    public const AVISO = 'Te mandé a Robotito a tu sistema para que te lo muestre. Tocá su aviso en la barra de navegación para empezar.';

    public const TEMAS = [
        'crear_ticket' => 3,
        'buscar_articulo_nombre' => 3,
        'buscar_articulo_codigo' => 1,
        'centro_recursos' => 2,
    ];

    public function __construct(ClientEmpresaApiUrlResolver $urls)
    {
        $this->urls = $urls;
    }

    private $urls;

    /** Solo se admite el guion textual de un recorrido que ya existe en Empresa. */
    public static function guion($datos): ?array
    {
        if (! is_array($datos) || ! is_string($datos['tema'] ?? null) || ! isset(self::TEMAS[$datos['tema']])
            || ! is_string($datos['titulo'] ?? null)) {
            return null;
        }

        $titulo = trim($datos['titulo']);
        if ($titulo === '' || mb_strlen($titulo) > 120) {
            return null;
        }

        $guion = ['tema' => $datos['tema'], 'titulo' => $titulo];
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
