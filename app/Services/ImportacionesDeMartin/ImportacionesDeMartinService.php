<?php

namespace App\Services\ImportacionesDeMartin;

use App\Models\SupportCodexCase;
use App\Models\SupportCodexTaskGrant;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ClientCatalogoPuenteService;
use App\Services\PruebaImportacionService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Las importaciones de Martín para clientes ACTIVOS y en implementación (misión cruzada `importaciones-de-martin`,
 * 11/11/2026). Es el servicio propio de las rutas nuevas del monitor; lo de siempre (el Excel de artículos, la prueba)
 * sigue en `PruebaImportacionService`, que le delega acá el Excel de clientes y proveedores.
 *
 *  B. Clientes y proveedores con código y deshacer: `iniciar()` reenvía el Excel al servicio nuevo de empresa
 *     (`admin-sync/importacion-soporte/{modelo}/iniciar`), `estado()` lo lee, `confirmar()` exige que el dueño haya
 *     escrito `CONFIRMAR <código>` después del aviso que salió con ese código, y `revertir()` deshace la corrida.
 *  C. Imágenes desde la web del proveedor: `catalogo_articulos()` (puente a `admin-sync/catalogo/articulos`, con
 *     `con_imagenes=1`) e `importar_imagenes_proveedor()` (multipart a `admin-sync/catalogo/imagenes/proveedor`).
 *  El permiso delegado: `emitir_permiso()` da un token de 24 h acotado al ticket y a un alcance (`pdf` | `web`), que la
 *     tarea nativa usa EN LUGAR de la reserva del ticket solo para el catálogo (los dos alcances) y para subir las
 *     imágenes (solo `web`). Se guarda el sha256; el token en claro sale una sola vez.
 *
 * Puertas: el ticket abierto, atendido por Codex y del dueño verificado de un negocio activo
 * (`PruebaImportacionService::puerta_activo`). El cliente sale SIEMPRE del ticket, nunca del body.
 *
 * Compatibilidad: un 404 de la instancia es `version_vieja` (su versión no tiene la ruta) y no se reintenta. La clave del
 * cliente viaja en el header y se tapa en la respuesta y en el log (lo hace `PruebaImportacionService::reenviar` y el
 * puente del catálogo).
 *
 * PHP 7.4.
 */
class ImportacionesDeMartinService
{
    /** Rutas del contrato admin ↔ empresa. */
    const RUTA_SOPORTE = 'api/admin-sync/importacion-soporte/';
    const RUTA_IMAGENES_PROVEEDOR = 'api/admin-sync/catalogo/imagenes/proveedor';
    const RUTA_ARTICULOS = '/articulos';

    /** Modelos del servicio de clientes y proveedores. */
    const MODELOS = ['client', 'provider'];

    /** Imágenes por pedido y peso de cada una. */
    const MAX_ITEMS = 25;
    const MAX_BYTES_IMAGEN = 4 * 1024 * 1024;
    const TIPOS = ['exacto', 'dudosa'];

    /** Tope de artículos por página del catálogo (el de empresa). */
    const LIMITE_CATALOGO = 1000;

    /** Qué alcances del permiso delegado abre cada ruta. */
    const RUTA_CATALOGO = 'catalogo';
    const RUTA_IMAGENES = 'imagenes';
    const ALCANCES_POR_RUTA = [
        self::RUTA_CATALOGO => [SupportCodexTaskGrant::ALCANCE_PDF, SupportCodexTaskGrant::ALCANCE_WEB],
        self::RUTA_IMAGENES => [SupportCodexTaskGrant::ALCANCE_WEB],
    ];

    /** Formatos de imagen por sus primeros bytes. */
    const FIRMAS = [
        'jpg' => "\xFF\xD8\xFF",
        'png' => "\x89PNG\r\n\x1A\n",
    ];

    /** @var PruebaImportacionService */
    protected $importacion;

    /** @var SupportCodexService */
    protected $soporte;

    /** @var ClientCatalogoPuenteService */
    protected $catalogo;

    /**
     * @param PruebaImportacionService    $importacion
     * @param SupportCodexService         $soporte
     * @param ClientCatalogoPuenteService $catalogo
     */
    public function __construct(PruebaImportacionService $importacion, SupportCodexService $soporte, ClientCatalogoPuenteService $catalogo)
    {
        $this->importacion = $importacion;
        $this->soporte = $soporte;
        $this->catalogo = $catalogo;
    }

    /* ==========================================================================================
     | Permiso delegado
     |========================================================================================= */

    /**
     * Emite el permiso de una tarea nativa con la reserva vigente del ticket.
     *
     * @param int    $ticket_id
     * @param string $lease_token
     * @param string $alcance     pdf | web.
     *
     * @return array{tarea_token: string, ticket_id: int, alcance: string, vence_at: string}
     */
    public function emitir_permiso(int $ticket_id, string $lease_token, string $alcance): array
    {
        return DB::transaction(function () use ($ticket_id, $lease_token, $alcance) {
            $ticket = SupportTicket::with('client')->lockForUpdate()->find($ticket_id);
            $ticket = $this->ticket_que_existe($ticket, $ticket_id);
            $this->asegurar_reserva($ticket, $lease_token);

            $token = Str::random(64);
            $permiso = SupportCodexTaskGrant::create([
                'support_ticket_id' => (int) $ticket->id,
                'client_id' => (int) $ticket->client_id,
                'alcance' => $alcance,
                'token_hash' => hash('sha256', $token),
                'vence_at' => now()->addHours(SupportCodexTaskGrant::HORAS),
            ]);

            Log::channel('daily')->info('ImportacionesDeMartin: permiso delegado emitido.', [
                'ticket_id' => (int) $ticket->id, 'grant_id' => (int) $permiso->id, 'alcance' => $alcance,
            ]);

            return [
                'tarea_token' => $token,
                'ticket_id' => (int) $ticket->id,
                'alcance' => $alcance,
                'vence_at' => $permiso->vence_at->toIso8601String(),
            ];
        });
    }

    /**
     * El ticket de una ruta que acepta la reserva del ticket O el permiso de la tarea (uno de los dos).
     *
     * @param int         $ticket_id
     * @param string|null $lease_token
     * @param string|null $tarea_token
     * @param string      $ruta        self::RUTA_CATALOGO | self::RUTA_IMAGENES.
     *
     * @return SupportTicket
     */
    public function ticket_autorizado(int $ticket_id, ?string $lease_token, ?string $tarea_token, string $ruta): SupportTicket
    {
        $ticket = $this->ticket_que_existe(SupportTicket::with('client')->find($ticket_id), $ticket_id);
        $lease = trim((string) $lease_token);
        $tarea = trim((string) $tarea_token);

        if (($lease === '') === ($tarea === '')) {
            throw new ImportacionesDeMartinException(422, 'sin_permiso', 'Mandá lease_token (la reserva del ticket) o tarea_token (el permiso de la tarea): uno de los dos.');
        }

        if ($lease !== '') {
            $this->asegurar_reserva($ticket, $lease);
        } else {
            $this->asegurar_tarea($ticket, $tarea, $ruta);
        }

        return $ticket;
    }

    /**
     * El ticket de una ruta que solo acepta la reserva (sin `expected_message_id`).
     *
     * @param int    $ticket_id
     * @param string $lease_token
     *
     * @return SupportTicket
     */
    public function ticket_con_reserva(int $ticket_id, string $lease_token): SupportTicket
    {
        $ticket = $this->ticket_que_existe(SupportTicket::with('client')->find($ticket_id), $ticket_id);
        $this->asegurar_reserva($ticket, $lease_token);

        return $ticket;
    }

    private function ticket_que_existe(?SupportTicket $ticket, int $ticket_id): SupportTicket
    {
        if ($ticket === null) {
            throw new ImportacionesDeMartinException(404, 'sin_ticket', 'No existe el ticket ' . $ticket_id . '.');
        }

        return $ticket;
    }

    private function asegurar_reserva(SupportTicket $ticket, string $lease_token): void
    {
        $this->asegurar_atendible($ticket);
        $this->soporte->assert_lease(SupportCodexCase::where('support_ticket_id', $ticket->id)->first(), $lease_token);
        $this->asegurar_dueno_activo($ticket);
    }

    private function asegurar_tarea(SupportTicket $ticket, string $tarea_token, string $ruta): void
    {
        $permiso = SupportCodexTaskGrant::where('token_hash', hash('sha256', $tarea_token))->first();
        if ($permiso === null) {
            throw new ImportacionesDeMartinException(403, 'tarea_invalida', 'El permiso de la tarea no existe.');
        }
        if ((int) $permiso->support_ticket_id !== (int) $ticket->id) {
            throw new ImportacionesDeMartinException(403, 'tarea_de_otro_ticket', 'El permiso de la tarea es de otro ticket.');
        }
        if ($permiso->revocado_at !== null || $permiso->vence_at === null || ! $permiso->vence_at->isFuture()) {
            throw new ImportacionesDeMartinException(403, 'tarea_vencida', 'El permiso de la tarea venció: Martín tiene que pedir otro.');
        }
        if ((int) $permiso->client_id !== (int) $ticket->client_id) {
            throw new ImportacionesDeMartinException(403, 'tarea_invalida', 'El ticket cambió de cliente después de emitir el permiso.');
        }
        if (! in_array($permiso->alcance, self::ALCANCES_POR_RUTA[$ruta], true)) {
            throw new ImportacionesDeMartinException(403, 'alcance_no_permitido', 'El permiso de alcance ' . $permiso->alcance . ' no habilita esta ruta.');
        }

        $this->asegurar_atendible($ticket);
        $this->asegurar_dueno_activo($ticket);

        SupportCodexTaskGrant::whereKey($permiso->id)->update(['usos' => DB::raw('usos + 1'), 'usado_at' => now()]);
    }

    private function asegurar_atendible(SupportTicket $ticket): void
    {
        if ($ticket->status !== SupportTicket::STATUS_ABIERTO) {
            throw new ImportacionesDeMartinException(409, 'ticket_cerrado', 'El ticket no está abierto.');
        }
        if (! SupportCodexSettings::manages($ticket) || ! (bool) $ticket->claude_auto_reply) {
            throw new ImportacionesDeMartinException(409, 'disabled_or_ineligible', 'Este ticket no está habilitado para atención automática en Codex.');
        }
    }

    private function asegurar_dueno_activo(SupportTicket $ticket): void
    {
        $puerta = $this->importacion->puerta_activo($ticket);
        if ($puerta !== null) {
            throw new ImportacionesDeMartinException($puerta['estado'] === 'ticket_cerrado' ? 409 : 403, $puerta['estado'], $puerta['mensaje']);
        }
    }

    /* ==========================================================================================
     | B. Clientes y proveedores
     |========================================================================================= */

    /**
     * Reenvía el Excel de clientes o proveedores del dueño al servicio de soporte de empresa. Lo llama
     * `PruebaImportacionService::importar_excel` en modo `soporte_activo`, con el archivo ya validado (adjunto
     * entrante del dueño) y con las puertas ya pasadas.
     *
     * @param SupportTicket                        $ticket
     * @param string                               $modelo  client | provider.
     * @param array{bytes: string, filename: string} $archivo
     * @param string                               $clave   Huella del archivo (idempotencia del lado de empresa).
     *
     * @return array<string, mixed> `estado`: aceptado | version_vieja | rechazado | sin_api_key | sin_url | no_responde.
     */
    public function iniciar(SupportTicket $ticket, string $modelo, array $archivo, string $clave): array
    {
        $antes = $ticket->importacion_soporte_iniciada_at;
        // Antes de reenviar: el aviso con el código puede salir mientras empresa contesta, y el admin solo lo manda
        // por el ticket con el número exacto del dueño cuando la importación de soporte figura iniciada.
        $ticket->forceFill(['importacion_soporte_iniciada_at' => now()])->save();

        $respuesta = $this->importacion->reenviar($ticket->client, self::RUTA_SOPORTE . $modelo . '/iniciar', $this->contexto($ticket) + [
            'importacion_clave' => $clave,
            'avisar_por_whatsapp' => '1',
        ], [
            ['nombre' => 'excel_file', 'bytes' => $archivo['bytes'], 'filename' => $archivo['filename']],
        ], 60);

        $resultado = $this->resultado_de_corrida($respuesta, $modelo, 'No pude iniciar la importación de ' . $this->nombre($modelo) . '.');
        if ($resultado['estado'] !== 'aceptado') {
            $ticket->forceFill(['importacion_soporte_iniciada_at' => $antes])->save();
        } else {
            Log::channel('daily')->info('ImportacionesDeMartin: Excel de ' . $modelo . ' reenviado al servicio de soporte.', [
                'ticket_id' => (int) $ticket->id, 'client_id' => (int) $ticket->client_id, 'corrida_id' => $resultado['corrida_id'],
            ]);
        }

        return $resultado + ['filename' => $archivo['filename']];
    }

    /**
     * El estado y el resumen de una corrida de clientes o proveedores del ticket.
     *
     * @param SupportTicket $ticket
     * @param string        $modelo
     * @param string        $corrida_id El uuid de la corrida.
     *
     * @return array<string, mixed>
     */
    public function estado(SupportTicket $ticket, string $modelo, string $corrida_id): array
    {
        $respuesta = $this->importacion->reenviar($ticket->client, self::RUTA_SOPORTE . $corrida_id, $this->contexto($ticket) + ['modelo' => $modelo], [], 12, 'GET');

        return $this->resultado_de_corrida($respuesta, $modelo, 'No pude leer la importación.');
    }

    /**
     * Confirma (o cancela) una corrida con el código que escribió el dueño. El mensaje tiene que ser un texto entrante
     * del dueño verificado, decir exactamente `CONFIRMAR <código>` (o `CANCELAR <código>`) con el código vigente de la
     * corrida, y llegar después de un aviso enviado por el ticket que llevaba ese mismo texto.
     *
     * @param SupportTicket  $ticket
     * @param SupportMessage $mensaje El `expected_message_id` de la reserva (ya validado por `assert_current`).
     * @param string         $modelo
     * @param string         $corrida_id El uuid de la corrida.
     * @param string         $decision   confirmar | cancelar.
     *
     * @return array<string, mixed>
     */
    public function confirmar(SupportTicket $ticket, SupportMessage $mensaje, string $modelo, string $corrida_id, string $decision = 'confirmar'): array
    {
        if ($mensaje->kind !== 'text' || ! $this->importacion->es_entrante_del_dueno($ticket, $mensaje)) {
            return ['estado' => 'confirmacion_invalida', 'mensaje' => 'Hace falta una respuesta de texto del dueño por WhatsApp.'];
        }

        $estado = $this->estado($ticket, $modelo, $corrida_id);
        if ($estado['estado'] !== 'aceptado') {
            return $estado;
        }

        $codigo = $estado['codigo'];
        $texto = ($decision === 'cancelar' ? 'CANCELAR ' : 'CONFIRMAR ') . $codigo;
        if ($codigo === null || strtoupper(trim((string) $mensaje->body)) !== $texto
            || ! SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')
                ->where('is_trial_import_notice', true)->whereNotNull('whatsapp_message_id')
                ->where('id', '<', $mensaje->id)->where('body', 'like', '%' . $texto . '%')->exists()) {
            return ['estado' => 'confirmacion_invalida', 'mensaje' => 'Hace falta el código vigente de la corrida, escrito por el dueño después del aviso.'];
        }

        $respuesta = $this->importacion->reenviar($ticket->client, self::RUTA_SOPORTE . $corrida_id . '/confirmar', $this->contexto($ticket) + [
            'modelo' => $modelo,
            'codigo' => $codigo,
            'decision' => $decision,
        ], [], 60);

        return $this->resultado_de_corrida($respuesta, $modelo, 'No pude confirmar la importación.');
    }

    /**
     * Deshace una corrida de clientes o proveedores del ticket, a pedido del dueño.
     *
     * @param SupportTicket $ticket
     * @param string        $modelo
     * @param string        $corrida_id El uuid de la corrida.
     *
     * @return array<string, mixed>
     */
    public function revertir(SupportTicket $ticket, string $modelo, string $corrida_id): array
    {
        $respuesta = $this->importacion->reenviar($ticket->client, self::RUTA_SOPORTE . $corrida_id . '/revertir', $this->contexto($ticket) + [
            'modelo' => $modelo,
        ], [], 60);

        $resultado = $this->resultado_de_corrida($respuesta, $modelo, 'No pude revertir la importación.');
        if ($resultado['estado'] === 'aceptado') {
            Log::channel('daily')->info('ImportacionesDeMartin: corrida revertida.', [
                'ticket_id' => (int) $ticket->id, 'client_id' => (int) $ticket->client_id, 'corrida_id' => $corrida_id, 'modelo' => $modelo,
            ]);
        }

        return $resultado;
    }

    /**
     * Traduce la respuesta del servicio de soporte de empresa a la del monitor.
     *
     * @param array<string, mixed> $respuesta {status, cuerpo} | {estado, mensaje}.
     * @param string               $modelo
     * @param string               $falla     Mensaje para lo que no es 2xx ni 404.
     *
     * @return array<string, mixed>
     */
    private function resultado_de_corrida(array $respuesta, string $modelo, string $falla): array
    {
        if (isset($respuesta['estado'])) {
            return $respuesta;
        }

        $status = (int) $respuesta['status'];
        $cuerpo = is_array($respuesta['cuerpo']) ? $respuesta['cuerpo'] : [];

        if (self::es_version_vieja($status, $cuerpo)) {
            return ['estado' => 'version_vieja', 'http' => 404, 'mensaje' => 'El sistema del negocio todavía no admite importar ' . $this->nombre($modelo) . ' desde soporte.'];
        }
        // Empresa contesta `{corrida: {...}}` (también en sus 409, con la corrida como quedó).
        $corrida = isset($cuerpo['corrida']) && is_array($cuerpo['corrida']) ? $cuerpo['corrida'] : [];
        if ($status < 200 || $status >= 300 || ! isset($corrida['corrida_id'])) {
            return ['estado' => 'rechazado', 'http' => $status, 'motivo_instancia' => isset($cuerpo['motivo']) ? (string) $cuerpo['motivo'] : null,
                'cuerpo' => $cuerpo, 'mensaje' => isset($cuerpo['message']) ? (string) $cuerpo['message'] : $falla];
        }

        return [
            'estado' => 'aceptado',
            'http' => $status,
            'modelo' => isset($corrida['modelo']) ? (string) $corrida['modelo'] : $modelo,
            'corrida_id' => (string) $corrida['corrida_id'],
            'estado_corrida' => isset($corrida['estado']) ? (string) $corrida['estado'] : null,
            'resumen' => isset($corrida['resumen']) && is_array($corrida['resumen']) ? $corrida['resumen'] : null,
            'codigo_requerido' => ($corrida['codigo_requerido'] ?? false) === true,
            'codigo' => $this->codigo_de($corrida),
            'texto_confirmacion' => isset($corrida['texto_confirmacion']) ? (string) $corrida['texto_confirmacion'] : null,
            'resultado' => $corrida['resultado'] ?? null,
            'error_corrida' => $corrida['error'] ?? null,
            'revertible' => ($corrida['revertible'] ?? false) === true,
            'corrida_existente' => ($cuerpo['corrida_existente'] ?? false) === true,
            'automatizar_soporte' => true,
        ];
    }

    /**
     * 🔴 Un 404 SIN `motivo` es una instancia sin la ruta (su versión es anterior). Uno CON `motivo` es un rechazo de
     * una instancia que sí la tiene (`corrida_no_encontrada`, por ejemplo): no es `version_vieja`.
     *
     * @param int                  $status
     * @param array<string, mixed> $cuerpo
     *
     * @return bool
     */
    public static function es_version_vieja(int $status, array $cuerpo): bool
    {
        return $status === 404 && (! isset($cuerpo['motivo']) || trim((string) $cuerpo['motivo']) === '');
    }

    /**
     * El código de confirmación vigente de la corrida (`codigo_confirmacion`, solo mientras espera al dueño).
     *
     * @param array<string, mixed> $corrida
     *
     * @return string|null
     */
    private function codigo_de(array $corrida): ?string
    {
        $codigo = $corrida['codigo_confirmacion'] ?? null;

        return is_string($codigo) && preg_match('/^[A-Z0-9]{8}$/D', $codigo) === 1 ? $codigo : null;
    }

    private function nombre(string $modelo): string
    {
        return $modelo === 'provider' ? 'proveedores' : 'clientes';
    }

    /**
     * @return array{user_id: int, support_ticket_id: int}
     */
    private function contexto(SupportTicket $ticket): array
    {
        return ['user_id' => (int) $ticket->client->user_id, 'support_ticket_id' => (int) $ticket->id];
    }

    /* ==========================================================================================
     | C. Imágenes desde la web del proveedor
     |========================================================================================= */

    /**
     * Una página de los artículos del dueño, con lo que la tarea necesita para saber qué buscar y qué saltear.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $filtros `despues_de`, `limite`, `con_imagenes`.
     *
     * @return array<string, mixed>
     */
    public function catalogo_articulos(SupportTicket $ticket, array $filtros): array
    {
        $con_imagenes = ! empty($filtros['con_imagenes']);
        $query = [
            'despues_de' => max(0, (int) ($filtros['despues_de'] ?? 0)),
            'limite' => min(self::LIMITE_CATALOGO, max(1, (int) ($filtros['limite'] ?? 500))),
        ] + ($con_imagenes ? ['con_imagenes' => 1] : []) + ['user_id' => (int) $ticket->client->user_id];

        $respuesta = $this->catalogo->reenviar($ticket->client, 'GET', self::RUTA_ARTICULOS . '?' . http_build_query($query));
        if ($respuesta['estado'] !== ClientCatalogoPuenteService::ESTADO_RESPONDIO) {
            return ['estado' => $respuesta['estado'], 'mensaje' => (string) $respuesta['mensaje']];
        }

        $status = (int) $respuesta['status'];
        $cuerpo = json_decode((string) json_encode($respuesta['cuerpo']), true);
        if (self::es_version_vieja($status, is_array($cuerpo) ? $cuerpo : [])) {
            return ['estado' => 'version_vieja', 'http' => 404, 'mensaje' => 'El sistema del negocio todavía no tiene el catálogo para soporte.'];
        }
        if ($status !== 200 || ! is_array($cuerpo) || ! isset($cuerpo['articulos']) || ! is_array($cuerpo['articulos'])) {
            return ['estado' => 'rechazado', 'http' => $status, 'cuerpo' => is_array($cuerpo) ? $cuerpo : null, 'mensaje' => 'El sistema del negocio no devolvió el catálogo.'];
        }
        if ($con_imagenes && $cuerpo['articulos'] !== [] && ! array_key_exists('tiene_imagen', (array) $cuerpo['articulos'][0])) {
            // Una versión anterior ignora `con_imagenes`: sin saber qué artículo ya tiene foto, la tarea no puede cruzar.
            return ['estado' => 'version_vieja', 'http' => 200, 'mensaje' => 'El sistema del negocio todavía no informa las imágenes de cada artículo.'];
        }

        return ['estado' => 'aceptado', 'http' => 200, 'cuerpo' => $cuerpo];
    }

    /**
     * Sube a la instancia del dueño una tanda de fotos de la web del proveedor (hasta 25). Cada item ya trae el
     * `article_id` que cruzó la tarea y si el cruce es `exacto` (se asigna solo si el artículo no tiene foto) o
     * `dudosa` (queda para que el dueño la apruebe en Alertas → Catálogo → Imágenes). Empresa decide y es
     * idempotente por ticket, fuente y artículo.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $input `fuente`, `ultima_tanda` (deja el aviso al dueño), `items:[{article_id, tipo,
     *                                    content_base64, url_origen, codigo_web}]`.
     *
     * @return array<string, mixed>
     */
    public function importar_imagenes_proveedor(SupportTicket $ticket, array $input): array
    {
        $campos = $this->contexto($ticket) + ['fuente' => strtolower(trim((string) $input['fuente']))]
            + (! empty($input['ultima_tanda']) ? ['ultima_tanda' => '1'] : []);
        $archivos = [];
        $rechazadas = [];
        $vistos = [];
        $i = 0;
        foreach ($input['items'] as $item) {
            $article_id = (int) $item['article_id'];
            if (isset($vistos[$article_id])) {
                $rechazadas[] = ['article_id' => $article_id, 'motivo' => 'El artículo viene dos veces en la tanda.'];
                continue;
            }
            $imagen = $this->imagen($item);
            if (isset($imagen['motivo'])) {
                $rechazadas[] = ['article_id' => $article_id, 'motivo' => $imagen['motivo']];
                continue;
            }

            $vistos[$article_id] = true;
            $prefijo = 'items[' . $i . ']';
            $campos[$prefijo . '[article_id]'] = (string) $article_id;
            $campos[$prefijo . '[tipo]'] = (string) $item['tipo'];
            foreach (['url_origen', 'codigo_web'] as $opcional) {
                if (isset($item[$opcional]) && trim((string) $item[$opcional]) !== '') {
                    $campos[$prefijo . '[' . $opcional . ']'] = trim((string) $item[$opcional]);
                }
            }
            $archivos[] = ['nombre' => $prefijo . '[imagen]', 'bytes' => $imagen['bytes'], 'filename' => 'articulo-' . $article_id . '.' . $imagen['extension']];
            $i++;
        }

        if ($archivos === []) {
            return ['estado' => 'archivo_invalido', 'mensaje' => 'Ninguna imagen de la tanda es válida.', 'rechazadas_antes_de_enviar' => $rechazadas];
        }

        $respuesta = $this->importacion->reenviar($ticket->client, self::RUTA_IMAGENES_PROVEEDOR, $campos, $archivos, 180);
        if (isset($respuesta['estado'])) {
            return $respuesta + ['rechazadas_antes_de_enviar' => $rechazadas];
        }

        $status = (int) $respuesta['status'];
        if (self::es_version_vieja($status, is_array($respuesta['cuerpo']) ? $respuesta['cuerpo'] : [])) {
            return ['estado' => 'version_vieja', 'http' => 404, 'mensaje' => 'El sistema del negocio todavía no admite las imágenes de la web del proveedor.',
                'rechazadas_antes_de_enviar' => $rechazadas];
        }

        if ($status >= 200 && $status < 300 && isset($campos['ultima_tanda']) && $ticket->importacion_soporte_iniciada_at === null) {
            // El aviso de la última tanda («asigné N fotos…») lo deja empresa como `importacion_lista` del ticket, y el
            // barrido de `AsistenteAvisosService` solo mira los tickets de activos con la importación de soporte iniciada.
            $ticket->forceFill(['importacion_soporte_iniciada_at' => now()])->save();
        }

        Log::channel('daily')->info('ImportacionesDeMartin: tanda de imágenes del proveedor reenviada.', [
            'ticket_id' => (int) $ticket->id, 'client_id' => (int) $ticket->client_id, 'fuente' => $campos['fuente'],
            'enviadas' => count($archivos), 'status' => $status,
        ]);

        return [
            'estado' => $status >= 200 && $status < 300 ? 'aceptado' : 'rechazado',
            'http' => $status,
            'cuerpo' => $respuesta['cuerpo'],
            'enviadas' => count($archivos),
            'rechazadas_antes_de_enviar' => $rechazadas,
        ];
    }

    /**
     * Decodifica y valida la imagen de un item: base64 válido, hasta 4 MiB y JPEG, PNG o WebP por sus bytes (el
     * nombre que mandó la tarea no decide nada).
     *
     * @param array<string, mixed> $item
     *
     * @return array{bytes: string, extension: string}|array{motivo: string}
     */
    private function imagen(array $item): array
    {
        $bytes = base64_decode((string) $item['content_base64'], true);
        if ($bytes === false || $bytes === '') {
            return ['motivo' => 'La imagen no es base64 válido.'];
        }
        if (strlen($bytes) > self::MAX_BYTES_IMAGEN) {
            return ['motivo' => 'La imagen pesa más de 4 MiB.'];
        }

        foreach (self::FIRMAS as $extension => $firma) {
            if (strncmp($bytes, $firma, strlen($firma)) === 0) {
                return ['bytes' => $bytes, 'extension' => $extension];
            }
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return ['bytes' => $bytes, 'extension' => 'webp'];
        }

        return ['motivo' => 'La imagen tiene que ser JPEG, PNG o WebP.'];
    }
}
