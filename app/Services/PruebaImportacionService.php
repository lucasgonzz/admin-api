<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lleva el Excel (y las imágenes) que llegaron al ticket de soporte de un negocio en PRUEBA GRATUITA a su
 * instancia, por C4 (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * 🔴 ES EL «SERVICIO PROPIO EN EL ADMIN» que `ClientAdminSyncPuenteService` reserva para las rutas que
 * escriben datos del negocio: `ai-excel-import/*` no entra al puente genérico. Acá el admin lee el adjunto
 * de SU disco (el archivo ya está acá, no viaja a la PC de Lucas) y lo reenvía a
 * `POST admin-sync/ai-excel-import/analyze` del cliente con la clave del cliente, pidiendo el análisis en
 * segundo plano (`segundo_plano=1`) y el aviso por WhatsApp (`avisar_por_whatsapp=1`). Del otro lado
 * queda una corrida del dueño: entra al sistema y tiene la importación lista para confirmar.
 *
 * 🔴 SOLO PARA CLIENTES EN PRUEBA (`PruebaDelClienteService`), y solo desde un ticket ABIERTO de ese
 * cliente. El adjunto tiene que ser de ese ticket; el archivo que arma la tarea del PDF viaja en base64
 * (`file`), igual que los adjuntos salientes de `reply`.
 *
 * 🔴 COMPATIBLE CON UN EMPRESA VIEJO: si la instancia no conoce `segundo_plano` contesta 200 con el
 * análisis síncrono y sin corrida (`estado: analizado_sin_corrida`): Martín se entera y le pide al
 * dueño que lo suba él. Un 404 en `imagenes` es `version_vieja`. Nunca se loguea ni devuelve la clave.
 *
 * PHP 7.4.
 */
class PruebaImportacionService
{
    use TapaLaClaveDelCliente;

    const RUTA_ANALYZE  = 'api/admin-sync/ai-excel-import/analyze';
    const RUTA_IMAGENES = 'api/admin-sync/ai-excel-import/imagenes';

    /** Peso máximo del Excel que se reenvía (bytes). */
    const MAX_BYTES_EXCEL = 10 * 1024 * 1024;

    /** Peso máximo de cada imagen (bytes) e imágenes por pedido. */
    const MAX_BYTES_IMAGEN = 4 * 1024 * 1024;
    const MAX_IMAGENES = 25;

    /** Modelos del importador que se aceptan. */
    const MODELOS = ['article', 'client', 'provider'];

    /** Extensiones de Excel y de imagen, con el mime que se declara al reenviar. */
    const EXCEL = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
    ];
    const IMAGENES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /** @var PruebaDelClienteService */
    protected $pruebas;

    /** @var ClientEmpresaApiUrlResolver */
    protected $urls;

    /**
     * @param PruebaDelClienteService     $pruebas
     * @param ClientEmpresaApiUrlResolver $urls
     */
    public function __construct(PruebaDelClienteService $pruebas, ClientEmpresaApiUrlResolver $urls)
    {
        $this->pruebas = $pruebas;
        $this->urls    = $urls;
    }

    /**
     * Reenvía un Excel del ticket (o uno en base64) a la instancia del cliente en prueba.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $input `attachment_id` | `file:{filename, content_base64}`, `model`, `provider_id`, `origen`.
     *
     * @return array<string, mixed> `estado`: aceptado | analizado_sin_corrida | rechazado | no_es_prueba |
     *                              ticket_cerrado | sin_fuente | archivo_invalido | sin_api_key | sin_url | no_responde.
     */
    public function importar_excel(SupportTicket $ticket, array $input): array
    {
        $puerta = $this->puerta($ticket);
        if ($puerta !== null) {
            return $puerta;
        }

        $archivo = $this->archivo_excel($ticket, $input);
        if (isset($archivo['estado'])) {
            return $archivo;
        }

        $client = $ticket->client;

        $modelo = isset($input['model']) && in_array((string) $input['model'], self::MODELOS, true) ? (string) $input['model'] : 'article';
        $origen = isset($input['origen']) && trim((string) $input['origen']) !== '' ? mb_substr(trim((string) $input['origen']), 0, 40) : 'martin';

        $campos = [
            'user_id'             => (int) $client->user_id,
            'model'               => $modelo,
            'segundo_plano'       => '1',
            'avisar_por_whatsapp' => '1',
            'origen'              => $origen,
        ];
        if (isset($input['provider_id']) && is_numeric($input['provider_id']) && (int) $input['provider_id'] > 0) {
            $campos['provider_id'] = (int) $input['provider_id'];
        }

        $respuesta = $this->reenviar($client, self::RUTA_ANALYZE, $campos, [
            ['nombre' => 'excel_file', 'bytes' => $archivo['bytes'], 'filename' => $archivo['filename']],
        ], 180);

        if (isset($respuesta['estado'])) {
            return $respuesta;
        }

        $status = $respuesta['status'];
        $cuerpo = $respuesta['cuerpo'];

        if ($status === 202 && is_array($cuerpo) && isset($cuerpo['analysis_uuid'])) {
            Log::channel('daily')->info('PruebaImportacion: Excel del ticket reenviado a la instancia, corrida encolada.', [
                'ticket_id' => (int) $ticket->id,
                'client_id' => (int) $client->id,
                'uuid'      => (string) $cuerpo['analysis_uuid'],
                'origen'    => $origen,
            ]);

            return [
                'estado'        => 'aceptado',
                'analysis_uuid' => (string) $cuerpo['analysis_uuid'],
                'http'          => $status,
                'cuerpo'        => $cuerpo,
                'filename'      => $archivo['filename'],
            ];
        }

        if ($status === 200 && is_array($cuerpo) && isset($cuerpo['column_mapping'])) {
            /* Un empresa anterior a C4: analizó en el request y no dejó corrida. */
            return [
                'estado'        => 'analizado_sin_corrida',
                'analysis_uuid' => null,
                'http'          => $status,
                'cuerpo'        => ['row_count' => isset($cuerpo['row_count']) ? $cuerpo['row_count'] : null, 'provider_id' => isset($cuerpo['provider_id']) ? $cuerpo['provider_id'] : null],
                'filename'      => $archivo['filename'],
            ];
        }

        return [
            'estado'        => 'rechazado',
            'analysis_uuid' => null,
            'http'          => $status,
            'cuerpo'        => $cuerpo,
            'filename'      => $archivo['filename'],
        ];
    }

    /**
     * Reenvía un lote de imágenes (en base64) a la instancia del cliente en prueba.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $input `imagenes:[{filename, content_base64}]`, `provider_id`, `reemplazar`.
     *
     * @return array<string, mixed>
     */
    public function importar_imagenes(SupportTicket $ticket, array $input): array
    {
        $puerta = $this->puerta($ticket);
        if ($puerta !== null) {
            return $puerta;
        }

        $lista = isset($input['imagenes']) && is_array($input['imagenes']) ? $input['imagenes'] : [];
        if (count($lista) === 0) {
            return ['estado' => 'sin_fuente', 'mensaje' => 'Mandá al menos una imagen en imagenes[].'];
        }
        if (count($lista) > self::MAX_IMAGENES) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'Hasta ' . self::MAX_IMAGENES . ' imágenes por pedido.'];
        }

        $archivos   = [];
        $rechazadas = [];
        foreach ($lista as $imagen) {
            $leida = $this->archivo_en_base64($imagen, self::IMAGENES, self::MAX_BYTES_IMAGEN);
            if (isset($leida['estado'])) {
                $rechazadas[] = ['filename' => is_array($imagen) && isset($imagen['filename']) ? (string) $imagen['filename'] : '', 'motivo' => $leida['mensaje']];
                continue;
            }
            $archivos[] = ['nombre' => 'imagenes[]', 'bytes' => $leida['bytes'], 'filename' => $leida['filename']];
        }

        if (count($archivos) === 0) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'Ninguna imagen es válida.', 'rechazadas' => $rechazadas];
        }

        $client = $ticket->client;
        $campos = ['user_id' => (int) $client->user_id];
        if (isset($input['provider_id']) && is_numeric($input['provider_id']) && (int) $input['provider_id'] > 0) {
            $campos['provider_id'] = (int) $input['provider_id'];
        }
        if (isset($input['reemplazar']) && $this->bandera($input['reemplazar'])) {
            $campos['reemplazar'] = '1';
        }

        $respuesta = $this->reenviar($client, self::RUTA_IMAGENES, $campos, $archivos, 180);
        if (isset($respuesta['estado'])) {
            return array_merge($respuesta, ['rechazadas_antes_de_enviar' => $rechazadas]);
        }

        $status = $respuesta['status'];

        return [
            'estado'                     => $status === 200 ? 'aceptado' : ($status === 404 ? 'version_vieja' : 'rechazado'),
            'http'                       => $status,
            'cuerpo'                     => $respuesta['cuerpo'],
            'enviadas'                   => count($archivos),
            'rechazadas_antes_de_enviar' => $rechazadas,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Internos
     |----------------------------------------------------------------------------------------- */

    /**
     * Las dos puertas: ticket abierto y cliente en prueba.
     *
     * @param SupportTicket $ticket
     *
     * @return array<string, mixed>|null null = pasa.
     */
    protected function puerta(SupportTicket $ticket): ?array
    {
        $ticket->loadMissing('client');

        if ($ticket->status !== SupportTicket::STATUS_ABIERTO) {
            return ['estado' => 'ticket_cerrado', 'mensaje' => 'El ticket no está abierto.'];
        }

        if (! $this->pruebas->es_cliente_en_prueba($ticket->client)) {
            return ['estado' => 'no_es_prueba', 'mensaje' => 'El cliente del ticket no está en prueba gratuita: la importación desde el ticket es solo para la prueba.'];
        }

        return null;
    }

    /**
     * El Excel a reenviar: del adjunto del ticket o del `file` en base64.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $input
     *
     * @return array{bytes: string, filename: string}|array{estado: string, mensaje: string}
     */
    protected function archivo_excel(SupportTicket $ticket, array $input): array
    {
        if (isset($input['attachment_id']) && is_numeric($input['attachment_id'])) {
            $attachment = SupportMessageAttachment::where('id', (int) $input['attachment_id'])
                ->whereHas('message', function ($query) use ($ticket) {
                    $query->where('support_ticket_id', (int) $ticket->id);
                })
                ->first();

            if ($attachment === null) {
                return ['estado' => 'sin_fuente', 'mensaje' => 'Ese adjunto no es de este ticket.'];
            }

            $extension = $this->extension_del_adjunto($attachment);
            if (! isset(self::EXCEL[$extension])) {
                return ['estado' => 'archivo_invalido', 'mensaje' => 'El adjunto no es un Excel (.xlsx o .xls).'];
            }

            try {
                $disk = Storage::disk($attachment->disk);
                if (! $disk->exists($attachment->path)) {
                    return ['estado' => 'sin_fuente', 'mensaje' => 'El archivo del adjunto ya no está en el disco del admin.'];
                }
                $bytes = (string) $disk->get($attachment->path);
            } catch (\Throwable $e) {
                return ['estado' => 'sin_fuente', 'mensaje' => 'No se pudo leer el adjunto del disco del admin.'];
            }

            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES_EXCEL) {
                return ['estado' => 'archivo_invalido', 'mensaje' => 'El Excel tiene que pesar entre 1 byte y 10 MiB.'];
            }

            return ['bytes' => $bytes, 'filename' => 'adjunto-' . (int) $attachment->id . '.' . $extension];
        }

        if (isset($input['file']) && is_array($input['file'])) {
            return $this->archivo_en_base64($input['file'], self::EXCEL, self::MAX_BYTES_EXCEL);
        }

        return ['estado' => 'sin_fuente', 'mensaje' => 'Indicá attachment_id (un adjunto del ticket) o file {filename, content_base64}.'];
    }

    /**
     * Un archivo `{filename, content_base64}` validado por extensión y peso.
     *
     * @param mixed                 $entrada
     * @param array<string, string> $extensiones
     * @param int                   $max_bytes
     *
     * @return array{bytes: string, filename: string}|array{estado: string, mensaje: string}
     */
    protected function archivo_en_base64($entrada, array $extensiones, int $max_bytes): array
    {
        if (! is_array($entrada) || ! isset($entrada['filename'], $entrada['content_base64'])) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'Cada archivo lleva filename y content_base64.'];
        }

        $filename = trim((string) $entrada['filename']);
        if ($filename === '' || preg_match('/[\\\\\/:*?"<>|\x00-\x1f\x7f]/', $filename) === 1 || mb_strlen($filename) > 160) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'El nombre del archivo no es válido.'];
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (! isset($extensiones[$extension])) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'Extensión no admitida: ' . $extension . '. Admitidas: ' . implode(', ', array_keys($extensiones)) . '.'];
        }

        $bytes = base64_decode((string) $entrada['content_base64'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > $max_bytes) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'El archivo tiene que ser base64 válido y pesar hasta ' . (int) ($max_bytes / 1048576) . ' MiB.'];
        }

        return ['bytes' => $bytes, 'filename' => $filename];
    }

    /**
     * La extensión de un adjunto de soporte, por su mime o por su path.
     *
     * @param SupportMessageAttachment $attachment
     *
     * @return string
     */
    protected function extension_del_adjunto(SupportMessageAttachment $attachment): string
    {
        $mime = strtolower(trim((string) $attachment->mime));
        foreach (self::EXCEL as $extension => $esperado) {
            if ($mime === $esperado) {
                return $extension;
            }
        }

        return strtolower((string) pathinfo((string) $attachment->path, PATHINFO_EXTENSION));
    }

    /**
     * El POST multipart a la instancia del cliente, con su clave. Devuelve `{status, cuerpo}` o `{estado, mensaje}`
     * si el admin no llegó al cliente.
     *
     * @param Client                            $client
     * @param string                            $ruta
     * @param array<string, mixed>              $campos
     * @param array<int, array<string, string>> $archivos `[{nombre, bytes, filename}]`.
     * @param int                               $timeout
     *
     * @return array<string, mixed>
     */
    protected function reenviar(Client $client, string $ruta, array $campos, array $archivos, int $timeout): array
    {
        $clave = trim((string) $client->api_key);
        if ($clave === '') {
            return ['estado' => 'sin_api_key', 'mensaje' => 'El cliente no tiene api_key en el admin: no hay con qué autenticar el pedido a su sistema.'];
        }

        $url = $this->urls->admin_sync_url($client, $ruta);
        if ($url === '') {
            return ['estado' => 'sin_url', 'mensaje' => 'No se pudo resolver la URL del sistema del cliente.'];
        }

        try {
            $pedido = Http::withHeaders(['X-Admin-Api-Key' => $clave, 'Accept' => 'application/json'])
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false]);

            foreach ($archivos as $archivo) {
                $pedido = $pedido->attach($archivo['nombre'], $archivo['bytes'], $archivo['filename']);
            }

            $respuesta = $pedido->post($url, $campos);
        } catch (ConnectionException $e) {
            Log::channel('daily')->warning('PruebaImportacion: el sistema del cliente no respondió.', [
                'client_id' => (int) $client->id,
                'ruta'      => $ruta,
                'error'     => $this->tapar_clave($e->getMessage(), $clave),
            ]);

            return ['estado' => 'no_responde', 'mensaje' => 'El sistema del cliente no respondió: ' . $this->tapar_clave($e->getMessage(), $clave)];
        } catch (\Throwable $e) {
            Log::channel('daily')->error('PruebaImportacion: excepción reenviando al cliente.', [
                'client_id' => (int) $client->id,
                'ruta'      => $ruta,
                'error'     => $this->tapar_clave($e->getMessage(), $clave),
            ]);

            return ['estado' => 'no_responde', 'mensaje' => $this->tapar_clave($e->getMessage(), $clave)];
        }

        $crudo  = $this->tapar_clave((string) $respuesta->body(), $clave);
        $cuerpo = json_decode($crudo, true);

        return [
            'status' => (int) $respuesta->status(),
            'cuerpo' => is_array($cuerpo) ? $cuerpo : ['cuerpo_crudo' => mb_substr($crudo, 0, 2000)],
        ];
    }

    /**
     * @param mixed $valor
     *
     * @return bool
     */
    protected function bandera($valor): bool
    {
        if ($valor === true) {
            return true;
        }
        if ($valor === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí'], true);
    }
}
