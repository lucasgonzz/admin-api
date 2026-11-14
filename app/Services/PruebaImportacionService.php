<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\Concerns\TapaLaClaveDelCliente;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lleva el Excel de un ticket de soporte a la instancia del negocio. Durante la prueba gratuita
 * también acepta Excel derivados de PDF e imágenes.
 *
 * 🔴 ES EL «SERVICIO PROPIO EN EL ADMIN» que `ClientAdminSyncPuenteService` reserva para las rutas que
 * escriben datos del negocio: `ai-excel-import/*` no entra al puente genérico. Acá el admin lee el adjunto
 * de SU disco (el archivo ya está acá, no viaja a la PC de Lucas) y lo reenvía a
 * `POST admin-sync/ai-excel-import/analyze` del cliente con la clave del cliente, pidiendo el análisis en
 * segundo plano (`segundo_plano=1`) y el aviso por WhatsApp (`avisar_por_whatsapp=1`). Del otro lado
 * queda una corrida del dueño. Para artículos de la prueba vigente, la instancia puede continuar
 * automáticamente y reservar el ticket para las preguntas que aparezcan.
 *
 * Para clientes activos el controlador exige un turno vigente del dueño. El servicio exige un Excel
 * de ese ticket y confirma la capacidad de la instancia antes de reenviar el archivo.
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
    const RUTA_STOCK_EXISTENTE = 'api/admin-sync/ai-excel-import/analyze-stock-existente';
    const RUTA_IMAGENES = 'api/admin-sync/ai-excel-import/imagenes';
    const RUTA_PENDIENTES = 'api/admin-sync/ai-excel-import/prueba-pendientes';
    const RUTA_RESOLVER = 'api/admin-sync/ai-excel-import/prueba-resolver/';

    /** Peso máximo del Excel que se reenvía (bytes). */
    const MAX_BYTES_EXCEL = 10 * 1024 * 1024;

    /** Peso máximo de cada imagen (bytes) e imágenes por pedido. */
    const MAX_BYTES_IMAGEN = 4 * 1024 * 1024;
    const MAX_IMAGENES = 25;

    /** Modelos del importador que se aceptan. */
    const MODELOS = ['article', 'client', 'provider'];
    const ALCANCE_STOCK_EXISTENTE = 'stock_existente';

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
     * @param array<string, mixed> $input `attachment_id` | `file:{filename, content_base64}`, `pdf_attachment_id` opcional, `model`, `provider_id`, `origen`,
     *                                    `columnas_previas` opcional (`[{hoja?, columna, campo}]`, solo artículos y sin `alcance`).
     *
     * @return array<string, mixed> `estado`: aceptado | analizado_sin_corrida | rechazado | no_es_prueba |
     *                              ticket_cerrado | sin_fuente | archivo_invalido | sin_api_key | sin_url | no_responde.
     */
    public function importar_excel(SupportTicket $ticket, array $input, bool $soporte_activo = false): array
    {
        $alcance = $input['alcance'] ?? null;
        if ($alcance !== null && (!$soporte_activo || $alcance !== self::ALCANCE_STOCK_EXISTENTE)) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'El alcance de stock requiere una importación de soporte de un cliente activo.'];
        }

        $puerta = $soporte_activo ? $this->puerta_activo($ticket) : $this->puerta($ticket);
        if ($puerta !== null) {
            return $puerta;
        }

        // Modo soporte (misión cruzada importaciones-de-martin, 11/11/2026): el Excel adjunto del dueño (artículos,
        // clientes o proveedores) o la planilla de artículos derivada de un PDF adjunto del dueño (`file` +
        // `pdf_attachment_id`) o catálogo público de WhatsApp acreditado por un mensaje del dueño. El alcance de stock sigue siendo solo para el Excel de artículos.
        $modelo_pedido = isset($input['model']) ? (string) $input['model'] : 'article';
        if ($soporte_activo && ((isset($input['pdf_attachment_id']) || isset($input['whatsapp_catalog_url']))
                ? (!isset($input['file']) || isset($input['attachment_id']) || $modelo_pedido !== 'article' || $alcance !== null)
                : (!isset($input['attachment_id']) || isset($input['file']) || !in_array($modelo_pedido, self::MODELOS, true)
                    || ($alcance !== null && $modelo_pedido !== 'article')))) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'Adjuntá al ticket el Excel del negocio (artículos, clientes o proveedores), o mandá la planilla de artículos derivada del PDF o catálogo de WhatsApp que mandó el dueño.'];
        }

        $archivo = $this->archivo_excel($ticket, $input, $soporte_activo);
        if (isset($archivo['estado'])) {
            return $archivo;
        }

        $client = $ticket->client;

        $modelo = isset($input['model']) && in_array((string) $input['model'], self::MODELOS, true) ? (string) $input['model'] : 'article';
        $origen = isset($input['origen']) && trim((string) $input['origen']) !== '' ? mb_substr(trim((string) $input['origen']), 0, 40) : 'martin';
        $clave = hash('sha256', $archivo['bytes']);
        if (isset($input['pdf_attachment_id'])) {
            if (isset($input['attachment_id'])) {
                return ['estado' => 'archivo_invalido', 'mensaje' => 'El PDF original se indica junto con el Excel derivado.'];
            }

            $pdf = $this->adjunto_del_cliente($ticket, (int) $input['pdf_attachment_id']);
            if ($pdf === null || ($pdf->mime !== 'application/pdf' && self::extension_del_adjunto($pdf) !== 'pdf')) {
                return ['estado' => 'sin_fuente', 'mensaje' => 'El PDF original tiene que ser un adjunto del cliente en este ticket.'];
            }
            if ($soporte_activo && !$this->es_entrante_del_dueno($ticket, $pdf->message)) {
                return ['estado' => 'archivo_sin_remitente_verificado', 'mensaje' => 'El PDF tiene que haber llegado desde el WhatsApp registrado del dueño.'];
            }

            // El XLSX derivado puede cambiar sus metadatos al regenerarse para el mismo PDF.
            $clave = hash('sha256', 'pdf:' . $pdf->id);
            if ($soporte_activo) {
                $origen = 'pdf';
            }
        }

        if (isset($input['whatsapp_catalog_url']) || isset($input['whatsapp_catalog_message_id'])) {
            $url = $this->normalizar_catalogo_whatsapp((string) ($input['whatsapp_catalog_url'] ?? ''));
            $mensaje = SupportMessage::whereKey($input['whatsapp_catalog_message_id'] ?? 0)
                ->where('support_ticket_id', $ticket->id)->where('sender_type', 'user')->first();
            if (isset($input['pdf_attachment_id']) || isset($input['attachment_id']) || !isset($input['file'])
                || $modelo !== 'article' || $alcance !== null
                || $url === null || $mensaje === null || !$this->mensaje_contiene_catalogo($mensaje, $url)) {
                return ['estado' => 'sin_fuente', 'mensaje' => 'El catálogo de WhatsApp debe ser un enlace enviado por el negocio en este ticket.'];
            }
            if ($soporte_activo && !$this->es_entrante_del_dueno($ticket, $mensaje)) {
                return ['estado' => 'archivo_sin_remitente_verificado', 'mensaje' => 'El enlace debe llegar desde el WhatsApp registrado del dueño.'];
            }
            $origen = 'catalogo_whatsapp';
            // Regenerar el XLSX de este pedido no duplica la migración; un nuevo pedido puede actualizarlo.
            $clave = hash('sha256', 'catalogo_whatsapp:' . $ticket->id . ':' . $mensaje->id . ':' . $url);
        }

        if ($soporte_activo && $modelo !== 'article') {
            // Clientes y proveedores van al servicio de soporte nuevo de empresa (con resumen, código y deshacer):
            // una instancia sin esa ruta contesta 404 y es `version_vieja`, sin que nadie lea el archivo.
            return app(\App\Services\ImportacionesDeMartin\ImportacionesDeMartinService::class)->iniciar($ticket, $modelo, $archivo, $clave);
        }

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
        if ($soporte_activo) {
            $capacidad = $this->consultar_corridas($ticket, true);
            if ($capacidad['disponible'] !== true) {
                return ['estado' => $capacidad['en_proceso'] === null ? 'no_responde' : 'version_vieja',
                    'mensaje' => 'No pude confirmar que el sistema pueda importar desde soporte.'];
            }
            if ($alcance !== null && !in_array($alcance, $capacidad['alcances_soporte'], true)) {
                return ['estado' => 'version_vieja',
                    'mensaje' => 'El sistema todavía no admite importar solo el stock de artículos existentes desde soporte.'];
            }

            $ticket->forceFill(['importacion_soporte_iniciada_at' => now()])->save();
            $campos['automatizar_soporte'] = '1';
            $campos['support_ticket_id'] = (int) $ticket->id;
            $campos['importacion_clave'] = $clave;
            if ($alcance !== null) {
                $campos['alcance'] = $alcance;
            }
        } elseif ($modelo === 'article' && $this->automatizacion_disponible($ticket)) {
            $campos['automatizar_prueba'] = '1';
            $campos['support_ticket_id'] = (int) $ticket->id;
            $campos['importacion_clave'] = $clave;
        }

        if ($modelo === 'article' && $alcance === null) {
            $previas = $this->columnas_previas($input['columnas_previas'] ?? null);
            if ($previas !== []) {
                // Viaja como un string JSON: el multipart no lleva listas anidadas.
                $campos['columnas_previas'] = json_encode($previas, JSON_UNESCAPED_UNICODE);
            }
        }

        // Si la instancia vuelve a una versión anterior después de la consulta, la ruta
        // específica rechaza el pedido antes de que un importador general lea el archivo.
        $ruta = $alcance === null ? self::RUTA_ANALYZE : self::RUTA_STOCK_EXISTENTE;
        $respuesta = $this->reenviar($client, $ruta, $campos, [
            ['nombre' => 'excel_file', 'bytes' => $archivo['bytes'], 'filename' => $archivo['filename']],
        ], $soporte_activo ? 25 : 180);

        if (isset($respuesta['estado'])) {
            return $respuesta;
        }

        $status = $respuesta['status'];
        $cuerpo = $respuesta['cuerpo'];

        if ($soporte_activo && in_array($status, [200, 202], true) && ($cuerpo['automatizar_soporte'] ?? false) !== true) {
            return ['estado' => 'version_vieja', 'mensaje' => 'El sistema no aceptó una corrida de soporte.'];
        }
        if ($alcance !== null && ($status === 404
            || ($status >= 200 && $status < 300 && ($cuerpo['alcance'] ?? null) !== $alcance))) {
            return ['estado' => 'version_vieja', 'mensaje' => 'El sistema no confirmó la importación de solo stock.'];
        }

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
                'automatizar_prueba' => ($cuerpo['automatizar_prueba'] ?? false) === true,
            ] + ($soporte_activo ? ['automatizar_soporte' => true] : [])
                + ($alcance !== null ? ['alcance' => $alcance] : []);
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

    private function normalizar_catalogo_whatsapp(string $url): ?string
    {
        if (strlen($url) > 500) { return null; }
        $partes = parse_url(trim($url));
        if (!is_array($partes) || ($partes['scheme'] ?? '') !== 'https'
            || isset($partes['user']) || isset($partes['pass']) || isset($partes['port']) || isset($partes['fragment'])) {
            return null;
        }
        $host = strtolower($partes['host'] ?? '');
        if (!in_array($host, ['wa.me', 'www.whatsapp.com'], true)) { return null; }
        $patron = $host === 'wa.me' ? '~\A/c/([1-9][0-9]{6,14})/?\z~' : '~\A/catalog/([1-9][0-9]{6,14})/?\z~';
        return preg_match($patron, $partes['path'] ?? '', $m) === 1 ? 'https://wa.me/c/' . $m[1] : null;
    }

    private function mensaje_contiene_catalogo(SupportMessage $mensaje, string $url): bool
    {
        preg_match_all('~https://[^\s<>]+~u', (string) $mensaje->body, $enlaces);
        foreach ($enlaces[0] as $enlace) {
            if ($this->normalizar_catalogo_whatsapp(rtrim($enlace, '.,;)')) === $url) { return true; }
        }
        return false;
    }

    /**
     * El mapeo previo ya validado, con solo `hoja` (si vino), `columna` (en mayúsculas) y `campo`. Lo que
     * no es una lista o queda vacío no se reenvía: el sistema pregunta como siempre.
     *
     * @param mixed $entrada
     *
     * @return array<int, array<string, string>>
     */
    private function columnas_previas($entrada): array
    {
        if (!is_array($entrada)) {
            return [];
        }

        $previas = [];
        foreach ($entrada as $item) {
            if (!is_array($item) || !isset($item['columna'], $item['campo'])) {
                continue;
            }

            $previa = [];
            if (isset($item['hoja']) && trim((string) $item['hoja']) !== '') {
                $previa['hoja'] = (string) $item['hoja'];
            }
            $previa['columna'] = strtoupper(trim((string) $item['columna']));
            $previa['campo'] = (string) $item['campo'];
            $previas[] = $previa;
        }

        return array_values($previas);
    }

    /** Corridas de esta conversación. Una instancia anterior conserva el contexto de siempre. */
    public function estado_en_ticket(SupportTicket $ticket): array
    {
        if ($ticket->importacion_soporte_iniciada_at !== null && $this->puerta_activo($ticket) === null) {
            $estado = $this->consultar_corridas($ticket, true);
            if ($estado['disponible'] !== true) {
                $estado['en_proceso'] = null;
            }
            return $estado;
        }
        if (! $this->automatizacion_disponible($ticket)) {
            return ['pendientes' => [], 'en_proceso' => false, 'disponible' => false];
        }

        return $this->consultar_corridas($ticket, false);
    }

    private function consultar_corridas(SupportTicket $ticket, bool $soporte_activo): array
    {
        $respuesta = $this->reenviar($ticket->client, self::RUTA_PENDIENTES, $this->contexto_del_ticket($ticket, $soporte_activo), [], 4, 'GET');
        $status = $respuesta['status'] ?? null;
        if (in_array($status, $soporte_activo ? [404, 409] : [404], true)
            || ($soporte_activo && $status === 200 && ($respuesta['cuerpo']['automatizar_soporte'] ?? false) !== true)) {
            return ['pendientes' => [], 'en_proceso' => false, 'disponible' => false];
        }
        if ($status !== 200) {
            return ['pendientes' => [], 'en_proceso' => null, 'disponible' => false];
        }

        $pendientes = $respuesta['cuerpo']['pendientes'] ?? [];
        $pendientes = is_array($pendientes) ? $pendientes : [];
        $alcances = $respuesta['cuerpo']['alcances_soporte'] ?? [];

        return ['pendientes' => $pendientes, 'en_proceso' => $pendientes !== [] || ($respuesta['cuerpo']['en_proceso'] ?? false) === true,
            'disponible' => true, 'alcances_soporte' => is_array($alcances) ? $alcances : [],
            'importaciones_de_contactos' => $soporte_activo ? self::contactos_abiertos($respuesta['cuerpo']['importaciones_de_contactos'] ?? []) : []];
    }

    /**
     * Las corridas de clientes o proveedores abiertas del ticket que informa empresa en `prueba-pendientes` (misión
     * cruzada importaciones-de-martin, 11/11/2026: analizar, confirmacion, importar o revirtiendo), tal cual llegan
     * (`motivo`, `tandas` y lo que sume empresa), menos el código de confirmación, que el contexto no lleva. Una
     * instancia anterior no manda la clave: lista vacía.
     *
     * @param mixed $entrada
     *
     * @return array<int, array<string, mixed>> Al menos `{corrida_id, modelo, estado, revision, codigo_requerido}`.
     */
    private static function contactos_abiertos($entrada): array
    {
        if (!is_array($entrada)) {
            return [];
        }

        $corridas = [];
        foreach ($entrada as $corrida) {
            if (!is_array($corrida) || !isset($corrida['corrida_id'], $corrida['modelo']) || !is_string($corrida['corrida_id'])) {
                continue;
            }
            unset($corrida['codigo_confirmacion'], $corrida['texto_confirmacion']);
            $corridas[] = [
                'corrida_id' => $corrida['corrida_id'],
                'modelo' => (string) $corrida['modelo'],
                'estado' => isset($corrida['estado']) ? (string) $corrida['estado'] : '',
                'revision' => (int) ($corrida['revision'] ?? 0),
                'codigo_requerido' => ($corrida['codigo_requerido'] ?? false) === true,
            ] + $corrida;
        }

        return $corridas;
    }

    public function resolver(SupportTicket $ticket, array $datos, SupportMessage $mensaje): array
    {
        $soporte_activo = $ticket->importacion_soporte_iniciada_at !== null && $this->puerta_activo($ticket) === null;
        if (!$soporte_activo && !$this->automatizacion_disponible($ticket)) {
            return ['status' => 409, 'cuerpo' => ['message' => 'El ticket no tiene una importación automática habilitada.']];
        }
        if ($soporte_activo) {
            $validacion = $this->validar_respuesta_activo($ticket, $datos, $mensaje);
            if ($validacion !== null) {
                return $validacion;
            }
        }

        $respuesta = $this->reenviar($ticket->client, self::RUTA_RESOLVER . $datos['analysis_uuid'], [
            'revision' => (int) $datos['revision'],
            'respuestas' => $datos['respuestas'],
        ] + $this->contexto_del_ticket($ticket, $soporte_activo), [], 12);

        if (! isset($respuesta['status'])) {
            return ['status' => 502, 'cuerpo' => $respuesta];
        }

        if (! in_array($respuesta['status'], [200, 202, 404, 409, 422], true)) {
            return ['status' => 502, 'cuerpo' => ['message' => 'La instancia no pudo resolver la importación.']];
        }

        return $respuesta;
    }

    private function validar_respuesta_activo(SupportTicket $ticket, array $datos, SupportMessage $mensaje): ?array
    {
        if ($mensaje->kind !== 'text' || !$this->es_entrante_del_dueno($ticket, $mensaje)) {
            return ['status' => 409, 'cuerpo' => ['message' => 'Hace falta una respuesta de texto del dueño por WhatsApp.']];
        }
        if (!array_key_exists('confirmacion', $datos['respuestas'])) {
            return null;
        }

        $estado = $this->consultar_corridas($ticket, true);
        $codigo = '';
        foreach ($estado['pendientes'] as $corrida) {
            if (($corrida['analysis_uuid'] ?? null) !== $datos['analysis_uuid']
                || (int) ($corrida['revision'] ?? 0) !== (int) $datos['revision']) {
                continue;
            }
            foreach ($corrida['preguntas'] as $pregunta) {
                if (($pregunta['clave'] ?? null) === 'confirmacion') {
                    $codigo = (string) ($pregunta['codigo_confirmacion'] ?? '');
                }
            }
        }

        $opciones = ['confirmar' => 'CONFIRMAR ', 'cancelar' => 'CANCELAR '];
        $prefijo = $opciones[$datos['respuestas']['confirmacion']] ?? '';
        if (count($datos['respuestas']) !== 1 || $prefijo === '' || preg_match('/^[A-Z0-9]{8}$/D', $codigo) !== 1
            || strtoupper(trim((string) $mensaje->body)) !== $prefijo . $codigo
            || !SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')
                ->where('is_trial_import_notice', true)->whereNotNull('whatsapp_message_id')
                ->where('id', '<', $mensaje->id)->where('body', 'like', '%' . $prefijo . $codigo . '%')->exists()) {
            return ['status' => 409, 'cuerpo' => ['message' => 'Hace falta el código de la revisión vigente, escrito por el dueño después del aviso.']];
        }

        return null;
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

    public function puerta_activo(SupportTicket $ticket): ?array
    {
        $ticket->loadMissing('client');
        $client = $ticket->client;

        if ($ticket->status !== SupportTicket::STATUS_ABIERTO) {
            return ['estado' => 'ticket_cerrado', 'mensaje' => 'El ticket no está abierto.'];
        }

        if ($client === null || !$client->is_active || $client->estado !== PruebaCicloService::CLIENTE_ACTIVO
            || (int) $client->user_id < 1 || $ticket->source !== 'whatsapp' || $ticket->client_employee_id !== null) {
            return ['estado' => 'contacto_no_habilitado', 'mensaje' => 'La importación requiere un ticket de WhatsApp del dueño de un negocio activo.'];
        }

        $contacto = app(ClientPhoneDirectory::class)->resolve_for_client($client, (string) $ticket->whatsapp_phone);
        if ($contacto === null || $contacto['client_employee_id'] !== null
            || !WhatsappNormalizer::equals_estricto((string) $contacto['raw_phone'], (string) $ticket->whatsapp_phone)) {
            return ['estado' => 'contacto_no_habilitado', 'mensaje' => 'El número del ticket no está registrado como contacto del dueño.'];
        }

        return null;
    }

    public function es_entrante_del_dueno(SupportTicket $ticket, ?SupportMessage $mensaje): bool
    {
        return $mensaje !== null && isset($this->entrantes_del_dueno($ticket, [$mensaje])[(int) $mensaje->id]);
    }

    /** Índice de entrantes acreditados. El directorio se consulta una sola vez por contexto. */
    public function entrantes_del_dueno(SupportTicket $ticket, iterable $mensajes): array
    {
        $telefono = (string) $ticket->whatsapp_phone;
        $contacto = $ticket->client === null ? null : app(ClientPhoneDirectory::class)->resolve_for_client($ticket->client, $telefono);
        if ($contacto === null || $contacto['client_employee_id'] !== null) {
            return [];
        }

        $verificados = [];
        foreach ($mensajes as $mensaje) {
            $remitente = (string) $mensaje->whatsapp_remitente_crudo;
            if ($mensaje->sender_type === 'user' && (int) $mensaje->support_ticket_id === (int) $ticket->id
                && trim((string) $mensaje->whatsapp_message_id) !== ''
                && WhatsappNormalizer::equals_estricto($telefono, $remitente)
                && WhatsappNormalizer::equals_estricto((string) $contacto['raw_phone'], $remitente)) {
                $verificados[(int) $mensaje->id] = true;
            }
        }

        return $verificados;
    }

    private function automatizacion_disponible(SupportTicket $ticket): bool
    {
        return ($ticket->source === 'whatsapp' || $this->es_soporte_del_sistema_del_dueno($ticket))
            && $ticket->client_employee_id === null
            && $this->puerta($ticket) === null && ! $this->pruebas->datos($ticket->client)['vencida'];
    }

    /**
     * Las capacidades `prueba_import` y `prueba_import_resolver` del contexto de Martín, según lo que ESTE ticket
     * puede usar de verdad (misión prueba-e2e-campana-imperio, 11/11/2026). Antes iban en `true` fijo.
     *
     *  - Cliente en prueba: `prueba_import` = la puerta de `import-excel` (ticket abierto); `prueba_import_resolver`
     *    = el circuito automático (ticket de WhatsApp o conversación del sistema DEL DUEÑO, prueba sin vencer).
     *  - Cliente que no está en prueba: quedan en `true`, como siempre. El monitor las lee como «el admin tiene la
     *    ruta» y decide por `client.estado`; apagarlas ahí le haría informar un bloqueo que no existe.
     *
     * @param SupportTicket $ticket
     *
     * @return array{prueba_import: bool, prueba_import_resolver: bool}
     */
    public function capacidades_de_prueba(SupportTicket $ticket): array
    {
        $ticket->loadMissing('client');

        if (! $this->pruebas->es_cliente_en_prueba($ticket->client)) {
            return ['prueba_import' => true, 'prueba_import_resolver' => true];
        }

        return [
            'prueba_import'          => $this->puerta($ticket) === null,
            'prueba_import_resolver' => $this->automatizacion_disponible($ticket),
        ];
    }

    /**
     * Si el ticket es la conversación de Soporte DEL SISTEMA del dueño (misión prueba-e2e-campana-imperio,
     * 11/11/2026): el negocio en prueba manda su Excel desde Agentes, Soporte, y la importación automática tiene
     * que andar igual que por WhatsApp. Mismo criterio que `PruebaRespaldoSoporteService`: canal sistema y el
     * usuario del ticket es el dueño (`clients.user_id`).
     *
     * @param SupportTicket $ticket
     *
     * @return bool
     */
    public function es_soporte_del_sistema_del_dueno(SupportTicket $ticket): bool
    {
        $ticket->loadMissing('client');

        return $ticket->source === 'erp' && $ticket->client !== null && $ticket->client_employee_id === null
            && (int) $ticket->client_user_id > 0 && (int) $ticket->client_user_id === (int) $ticket->client->user_id;
    }

    private function contexto_del_ticket(SupportTicket $ticket, bool $soporte_activo = false): array
    {
        return ['user_id' => (int) $ticket->client->user_id, 'support_ticket_id' => (int) $ticket->id]
            + ($soporte_activo ? ['automatizar_soporte' => '1'] : []);
    }

    private function adjunto_del_cliente(SupportTicket $ticket, int $id): ?SupportMessageAttachment
    {
        return SupportMessageAttachment::where('id', $id)->whereHas('message', function ($query) use ($ticket) {
            $query->where('support_ticket_id', (int) $ticket->id)->where('sender_type', 'user');
        })->first();
    }

    /**
     * El Excel a reenviar: del adjunto del ticket o del `file` en base64.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $input
     *
     * @return array{bytes: string, filename: string}|array{estado: string, mensaje: string}
     */
    protected function archivo_excel(SupportTicket $ticket, array $input, bool $soporte_activo = false): array
    {
        if (isset($input['attachment_id']) && is_numeric($input['attachment_id'])) {
            $attachment = $this->adjunto_del_cliente($ticket, (int) $input['attachment_id']);

            if ($attachment === null) {
                return ['estado' => 'sin_fuente', 'mensaje' => 'Ese adjunto no es de este ticket.'];
            }
            if ($soporte_activo && !$this->es_entrante_del_dueno($ticket, $attachment->message)) {
                return ['estado' => 'archivo_sin_remitente_verificado', 'mensaje' => 'Reenviá el Excel desde el WhatsApp registrado del dueño para poder usarlo.'];
            }

            $extension = self::extension_del_adjunto($attachment);
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
    public static function extension_del_adjunto(SupportMessageAttachment $attachment): string
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
     * @param string                            $metodo
     *
     * @return array<string, mixed>
     */
    public function reenviar(Client $client, string $ruta, array $campos, array $archivos, int $timeout, string $metodo = 'POST'): array
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
            // A6.1: negocio en prueba → IP del VPS sin DNS; cualquier otro cliente, el pedido intacto.
            $pedido = app(\App\Services\Prueba\PruebaResolucionLocal::class)->aplicar($pedido, $client, $url);

            foreach ($archivos as $archivo) {
                $pedido = $pedido->attach($archivo['nombre'], $archivo['bytes'], $archivo['filename']);
            }

            $respuesta = $metodo === 'GET' ? $pedido->get($url, $campos) : $pedido->post($url, $campos);
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
